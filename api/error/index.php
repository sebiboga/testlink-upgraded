<?php
/**
 * BFF API — TestLink Error page
 *
 * Ports the last standalone full-page legacy renderer of the tree,
 * error.php (+ gui/templates/dashio/feedback/error.tpl) — the general
 * purpose error page reached from the CSRF guard (lib/functions/csrf.php
 * redirects to error.php?code=1|2, invoked by csrfguard_start()) and by
 * direct deep-link. Modernized screen: gui/templates/feedback/error.html.
 *
 * Routes (GET/HEAD only; the legacy page required NO session — it was a
 * bare render without testlinkInitPage, so an anonymous deep-link works):
 *   GET ?code=<int>[&locale=xx]
 *     -> { status, code, known, error_code, message_key, auth:{...},
 *          locale, basehref }
 *
 * Behavioural parity with the legacy controller:
 *   - code 1 -> "No CSRFName found, probable invalid request."
 *   - code 2 -> "Invalid CSRF token"
 *   - anything else (including a missing code) -> the legacy default
 *     "Rocket Raccoon is watching You".
 *   - no rights gate: any visitor could open the page.
 *
 * Hardening over legacy:
 *   - a stable machine identifier (error_code) is returned instead of only
 *     a human string, so callers/tests can assert the path;
 *   - the message itself is an i18n key resolved client side (the legacy
 *     page was hardcoded English regardless of the user's locale);
 *   - the code parameter is scalar- and integer-guarded (an array-shaped
 *     ?code[]=x or a non-numeric value is a 400, the same defect class as
 *     #1886 / #1893), never coerced;
 *   - JSON-only, Cache-Control: no-store, X-Content-Type-Options: nosniff.
 *
 * Refs #1895.
 */
require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    if (!headers_sent()) {
        header('Allow: GET, HEAD');
    }
    http_response_code(405);
    echo json_encode(array('status' => 'error', 'code' => 'METHOD_NOT_ALLOWED',
                           'allowed' => array('GET', 'HEAD')));
    exit;
}

// Scalar/integer guard: a present-but-array ?code[]=x (or ?code=1abc) is a
// client error, not silently coerced to 1/0 (same class as #1886 / #1893).
$code = 0;
if (isset($_GET['code'])) {
    $raw = $_GET['code'];
    if (!is_string($raw) || trim($raw) === '' || !preg_match('/^-?\d+$/', trim($raw))) {
        http_response_code(400);
        echo json_encode(array('status' => 'error', 'code' => 'INVALID_CODE',
                               'message' => 'Invalid code parameter'));
        exit;
    }
    $code = (int)$raw;
}

/**
 * Map a legacy numeric code to a stable machine id + i18n message key.
 * Unknown codes fall back to the legacy generic default (TICKET 4977).
 */
function errorResolveCode($code) {
    switch ($code) {
        case 1:
            return array('known' => true, 'error_code' => 'csrf_name_missing',
                         'message_key' => 'err.csrfMissing');
        case 2:
            return array('known' => true, 'error_code' => 'csrf_token_invalid',
                         'message_key' => 'err.csrfInvalid');
        default:
            return array('known' => false, 'error_code' => 'generic',
                         'message_key' => 'err.generic');
    }
}

$resolved = errorResolveCode($code);

$userId = isset($_SESSION['userID']) ? (int)$_SESSION['userID'] : 0;
$userLogin = '';
if ($userId > 0) {
    if (isset($_SESSION['currentUser']) && is_object($_SESSION['currentUser'])
        && isset($_SESSION['currentUser']->login)) {
        $userLogin = (string)$_SESSION['currentUser']->login;
    } elseif (class_exists('tlUser') && isset($db)) {
        $u = tlUser::getByID($db, $userId);
        if (is_object($u) && isset($u->login)) {
            $userLogin = (string)$u->login;
        }
    }
}

$basehref = isset($_SESSION['basehref']) ? (string)$_SESSION['basehref'] : '';
$locale = isset($_SESSION['locale']) ? (string)$_SESSION['locale'] : '';

$payload = array(
    'status'      => 'ok',
    'code'        => $code,
    'known'       => $resolved['known'],
    'error_code'  => $resolved['error_code'],
    'message_key' => $resolved['message_key'],
    'auth'        => array(
        'logged_in'  => $userId > 0,
        'user_id'    => $userId,
        'user_login' => $userLogin,
    ),
    'locale'      => $locale,
    'basehref'    => $basehref,
);

echo json_encode($payload);
