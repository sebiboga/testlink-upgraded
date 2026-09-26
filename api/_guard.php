<?php
/**
 * Shared security middleware for all BFF entry points (api/<area>/index.php).
 *
 * bffSameOriginGuard() is the central CSRF defense-in-depth for every
 * non-safe verb (POST/PUT/DELETE/PATCH/...): a request is only accepted
 * when it carries proof of same-origin, i.e. one of:
 *   - header X-Requested-With: XMLHttpRequest (jQuery $.ajax same-origin,
 *     dropzone and fetch() wrappers all send it), or
 *   - an Origin / Referer whose host+port authority matches HTTP_HOST.
 * Anything else gets 403 JSON. Safe verbs (GET/HEAD/OPTIONS) pass through.
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

function bffSameOriginGuard() {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method === 'GET' || $method === 'HEAD' || $method === 'OPTIONS') {
        return;
    }

    $xrw = trim((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    if (strcasecmp($xrw, 'XMLHttpRequest') === 0) {
        return;
    }

    $host = bffAuthority($_SERVER['HTTP_HOST'] ?? '');
    foreach (array('HTTP_ORIGIN', 'HTTP_REFERER') as $hdr) {
        $val = trim((string)($_SERVER[$hdr] ?? ''));
        if ($val === '') {
            continue;
        }
        $authority = bffAuthority($val);
        if ($authority === '') {
            continue;
        }
        if ($host !== '' && strcasecmp($authority, $host) === 0) {
            return;
        }
        bffRejectForbidden();
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
