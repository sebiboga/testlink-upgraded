<?php
/**
 * Shared security middleware for all BFF entry points (api/<area>/index.php).
 *
 * bffSameOriginGuard() is the central CSRF defense-in-depth for every
 * non-safe verb (POST/PUT/DELETE/PATCH/...): a request is only accepted
 * when it carries proof of same-origin, evaluated in this order:
 *   1. an Origin / Referer whose host[:port] authority matches HTTP_HOST
 *      (authoritative - set by the browser, cannot be forged by page JS).
 *      A scheme-default port is optional on the Origin/Referer side, so
 *      "localhost" and "localhost:80" compare equal. A present header
 *      that yields no authority at all is rejected;
 *   2. otherwise, and ONLY when the browser sent neither header, an
 *      X-Requested-With: XMLHttpRequest hint (jQuery $.ajax same-origin,
 *      dropzone and fetch() wrappers all send it).
 * A present-but-foreign Origin/Referer is always rejected - the XRW hint
 * never overrides it (issue #1679). Anything else gets 403 JSON.
 * Safe verbs (GET/HEAD/OPTIONS) pass through.
 *
 * Only host[:port] is compared, not the scheme, and a reverse proxy MUST
 * rewrite Host to the value the browser used (HTTP_X_FORWARDED_HOST is
 * deliberately NOT trusted here: an unvalidated forwarding header is
 * client-spoofable and would defeat the whole control).
 *
 * This complements (does not replace) session auth and per-route rights:
 * it blocks cross-site "confused deputy" requests riding the victim's
 * session cookie, including same-site subdomain and top-level form posts.
 *
 * bffEnforceSession() is the BFF counterpart of the legacy
 * checkSessionValid() (lib/functions/common.php:258-286) that
 * testlinkInitPage() ran on EVERY legacy page load (common.php:531-533):
 * it enforces the sessionInactivityTimeout window. A BFF cannot redirect the
 * browser like legacy did, so an invalid session is answered with
 * 401 {"code":"session_expired"} and the modern screen sends the user to
 * login.php?note=expired - the same note the modern login screen already
 * renders (gui/templates/auth/login.html:145-155). Without this, a tab left
 * open past the timeout keeps reading data - and keeps WRITING - through any
 * BFF that only checks $_SESSION['userID'] > 0 (issue #1614).
 */

if (count(get_included_files()) === 1) {
    http_response_code(403);
    exit;
}

function bffRejectForbidden() {
    $json = json_encode(array(
        'status' => 'error',
        'message' => 'Forbidden: missing or mismatched same-origin proof ' .
                     '(CSRF protection)',
    ));
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        http_response_code(403);
    }
    echo $json;
    exit;
}

/**
 * Extract lowercase host[:port] authority from an absolute URL or Host hdr.
 */
function bffAuthority($value) {
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    if (strpos($value, '/') === false) {
        // already a bare authority (HTTP_HOST style)
        return strtolower($value);
    }
    $parts = parse_url($value);
    if (empty($parts['host'])) {
        return '';
    }
    $authority = strtolower($parts['host']);
    if (!empty($parts['port'])) {
        $authority .= ':' . $parts['port'];
    }
    return $authority;
}

/**
 * Drop a scheme-default port from a "host[:port]" authority so that
 * "localhost:80" and "localhost" compare equal. $defaultPort includes the
 * leading colon. Only the DEFAULT port is removed, so a real port mismatch
 * survives the comparison.
 */
function bffStripDefaultPort($authority, $defaultPort) {
    $authority = (string)$authority;
    $len = strlen($defaultPort);
    if ($len === 0 || strlen($authority) <= $len) {
        return $authority;
    }
    if (substr($authority, -$len) === $defaultPort) {
        return substr($authority, 0, -$len);
    }
    return $authority;
}

function bffSameOriginGuard() {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method === 'GET' || $method === 'HEAD' || $method === 'OPTIONS') {
        return;
    }

    // Refs #1679: Origin/Referer are validated FIRST. The X-Requested-With
    // shortcut used to short-circuit before either header was read, so a
    // request carrying a FOREIGN Origin was accepted as same-origin and the
    // authoritative signal was unreachable. XRW is only a hint (any client can
    // set it, browser page JS cannot), Origin is the authoritative signal, so
    // XRW now acts solely as the fallback for the same-origin fetch/XHR case
    // where the browser sent neither header.
    $host = bffAuthority($_SERVER['HTTP_HOST'] ?? '');
    // A URL omits the port when it is the scheme default (Origin/Referer), but
    // HTTP_HOST keeps it, so Host "localhost:80" and Origin "http://localhost"
    // describe the SAME origin and must not be rejected. Strip the scheme's
    // default port from both sides before comparing. Same normalization as
    // api/tcprintlaunch/index.php:247-251. Note this only ever removes the
    // DEFAULT port, so a genuine port mismatch (Origin :80 vs Host :8082) is
    // still rejected.
    $https = strtolower(trim((string)($_SERVER['HTTPS'] ?? '')));
    $defaultPort = ':' . (($https !== '' && $https !== 'off') ? '443' : '80');
    $hostKey = bffStripDefaultPort($host, $defaultPort);
    foreach (array('HTTP_ORIGIN', 'HTTP_REFERER') as $hdr) {
        $val = trim((string)($_SERVER[$hdr] ?? ''));
        if ($val === '') {
            continue;
        }
        $authority = bffAuthority($val);
        if ($authority === '') {
            // A present-but-unparseable Origin/Referer (file:// URL, malformed
            // host, port parse failure) can never be proven same-origin, so it
            // is rejected instead of falling through to the XRW fallback.
            // Browsers only ever send "scheme://host[:port]" or "null", both of
            // which parse, so this cannot reject a genuine browser request.
            bffRejectForbidden();
        }
        if ($hostKey !== '' &&
            strcasecmp(bffStripDefaultPort($authority, $defaultPort), $hostKey) === 0) {
            return;
        }
        bffRejectForbidden();
    }

    // Reached only when the browser sent neither Origin nor Referer: the
    // same-origin fetch/XHR case the XRW hint exists to cover.
    $xrw = trim((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    if (strcasecmp($xrw, 'XMLHttpRequest') === 0) {
        return;
    }

    bffRejectForbidden();
}

/**
 * Enforce the legacy session inactivity timeout on a BFF request.
 *
 * Legacy parity: checkSessionValid() - lib/functions/common.php:258-286 - was
 * invoked by testlinkInitPage() (common.php:531-533) for EVERY legacy page, so
 * both the user list and any role-assignment write were refused once the
 * session went idle longer than config_get("sessionInactivityTimeout") minutes;
 * the user was then redirected to login.php?note=expired&destination=...
 *
 * A BFF must not redirect, so the invalid session is reported as
 * 401 {"status":"error","code":"session_expired"} and the modern screen
 * performs the bounce. When the session IS valid, checkSessionValid() slides
 * $_SESSION['lastActivity'] forward for us, which is what keeps an actively
 * used screen alive.
 *
 * @param object $db  open database handler (checkSessionValid needs it)
 * @return void     exits with 401 JSON when the session is invalid/stale
 */
function bffEnforceSession(&$db) {
    if (checkSessionValid($db, false)) {
        return;
    }

    // Legacy logged the bounce as INFO from the client address - keep the
    // trail so the Event Viewer shows why the screen was thrown back to login.
    tLog('BFF: invalid or expired session from ' .
         ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . ' on ' .
         ($_SERVER['SCRIPT_NAME'] ?? '') . ' ' . ($_SERVER['REQUEST_METHOD'] ?? '') .
         ' - answering 401 session_expired.', 'INFO');

    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        http_response_code(401);
    }
    echo json_encode(array(
        'status' => 'error',
        'code' => 'session_expired',
        'message' => 'session_expired',
    ));
    exit;
}
