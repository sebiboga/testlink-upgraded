<?php
/**
 * BFF API — TestLink generic Info / Notice page
 *
 * Ports the generic full-page feedback renderer workAreaSimple.tpl
 * (gui/templates/dashio/workAreaSimple.tpl) — the last standalone feedback
 * page without a modern twin (sibling of api/error, Refs #1895). It is the
 * page TestLink shows for:
 *   - a fatal DATABASE connection failure —
 *     doDBConnect($db, $onErrorExit=true) in lib/functions/common.php:198-205
 *     assigns title=fatal_page_title and renders the connection-fail screen;
 *   - a REPORT DOCUMENT error — displayInfo() in lib/functions/info.inc.php:29
 *     (called from lib/results/displayMgr.php:76,89,99) rendered the
 *     "error_print_doc_title" + "error_print_doc_missing_testplan" notice;
 *   - a DISABLED SELF-SIGNUP visitor — firstLogin.php:23-30 rendered
 *     fatal_page_title + error_self_signup_disabled + a back-to-login link.
 * Modernized screen: gui/templates/feedback/info.html.
 *
 * Routes (GET/HEAD only; the legacy page required NO session — displayInfo()
 * ran a bare render and the DB-fail page runs before a session exists, so an
 * anonymous deep-link works):
 *   GET ?code=<machine-id>
 *     -> { status, code, known, info_code, title_key, message_key,
 *          auth:{...}, locale, basehref }
 *
 * Behavioural parity with the legacy renderer:
 *   - title + content were passed by the caller; the two stable producers above
 *     are mapped to i18n keys resolved client side.
 *   - anything unknown (or missing) -> the generic notice.
 *   - no rights gate: any visitor could reach the page.
 *
 * Hardening over legacy:
 *   - the legacy content was rendered UN-escaped ({$content}) and, on the DB
 *     failure path, embedded the live DBMS error text; the modern page renders
 *     a fixed localized message instead, removing that injection surface;
 *   - a stable machine identifier (info_code) is returned instead of only a
 *     human string, so callers/tests can assert the path;
 *   - the message is an i18n key resolved client side (the legacy page emitted
 *     whatever lang_get() returned, regardless of the client locale bundle);
 *   - the code parameter is scalar- and format-guarded (an array-shaped
 *     ?code[]=x or a non-token value is a 400, the same defect class as
 *     #1886 / #1893), never coerced;
 *   - JSON-only, Cache-Control: no-store, X-Content-Type-Options: nosniff, and
 *     NO database access (the page must respond even when the DB is down).
 *
 * Refs #1899.
 */
require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

// Headers and request validation FIRST: the page must answer a clean JSON
// payload even when the DB is unreachable (it IS the DB-failure page), so the
// database is never connected here.
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

// Scalar/format guard: a present-but-array ?code[]=x (or a value carrying
// characters outside [a-z0-9_]) is a client error, not silently coerced to the
// generic code (same class as #1886 / #1893).
$code = 'generic';
if (isset($_GET['code'])) {
    $raw = $_GET['code'];
    if (!is_string($raw) || trim($raw) === '' || !preg_match('/^[a-z0-9_]+$/', trim($raw))) {
        http_response_code(400);
        echo json_encode(array('status' => 'error', 'code' => 'INVALID_CODE',
                               'message' => 'Invalid code parameter'));
        exit;
    }
    $code = trim($raw);
}

/**
 * Map a stable machine id to the i18n title + message keys the screen resolves
 * in the active locale bundle. Unknown ids fall back to the generic notice.
 */
function infoResolveCode($code) {
    switch ($code) {
        case 'db_connect_fail':
            return array('known' => true, 'info_code' => 'db_connect_fail',
                         'title_key' => 'info.dbFailTitle',
                         'message_key' => 'info.dbFailMsg');
        case 'missing_testplan':
            return array('known' => true, 'info_code' => 'missing_testplan',
                         'title_key' => 'info.printDocErrorTitle',
                         'message_key' => 'info.printDocMissingTestplan');
        case 'signup_disabled':
            return array('known' => true, 'info_code' => 'signup_disabled',
                         'title_key' => 'info.signupDisabledTitle',
                         'message_key' => 'info.signupDisabledMsg');
        default:
            return array('known' => false, 'info_code' => 'generic',
                         'title_key' => 'info.genericTitle',
                         'message_key' => 'info.genericMsg');
    }
}

$resolved = infoResolveCode($code);

$userId = isset($_SESSION['userID']) ? (int)$_SESSION['userID'] : 0;
$userLogin = '';
if ($userId > 0 && isset($_SESSION['currentUser']) && is_object($_SESSION['currentUser'])
    && isset($_SESSION['currentUser']->login)) {
    $userLogin = (string)$_SESSION['currentUser']->login;
}

$basehref = isset($_SESSION['basehref']) ? (string)$_SESSION['basehref'] : '';
$locale = isset($_SESSION['locale']) ? (string)$_SESSION['locale'] : '';

$payload = array(
    'status'      => 'ok',
    'code'        => $code,
    'known'       => $resolved['known'],
    'info_code'   => $resolved['info_code'],
    'title_key'   => $resolved['title_key'],
    'message_key' => $resolved['message_key'],
    'auth'        => array(
        'logged_in'  => $userId > 0,
        'user_id'    => $userId,
        'user_login' => $userLogin,
    ),
    'locale'      => $locale,
    'basehref'    => $basehref,
);

echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
