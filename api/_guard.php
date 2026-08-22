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
