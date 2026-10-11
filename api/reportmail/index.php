<?php
/**
 * BFF API — Report sent by e-mail confirmation
 *
 * Ports the last LIVE standalone full-page legacy renderer without a modern
 * twin: gui/templates/dashio/feedback/emailSent.tpl, rendered by
 * lib/results/displayMgr.php:201 (default branch of displayReport() for
 * FORMAT_MAIL_HTML) after every report's "Send by e-mail" action.
 * Modernized screen: gui/templates/results/reportMailSent.html.
 *
 * The legacy page only echoed `$mailCfg->subject` + "- Send Test Report" and
 * the mail feedback message; both travelled in the HTTP request, so the BFF
 * must NOT be the source of that data. This endpoint exists to (a) prove the
 * session is still valid before the screen paints and (b) hand the screen the
 * authenticated identity + the mail defaults it shows as context (sender,
 * default recipient) + basehref — everything it can know server-side without
 * becoming an SRF-able read of an arbitrary report.
 *
 * Routes (GET/HEAD only):
 *   GET|HEAD ?action=context
 *     -> { status, auth:{logged_in,user_id,user_login,email}, from, to,
 *          locale, basehref }
 *
 * Behavioural parity: the legacy page was reached only inside an authenticated
 * report-mail flow, so a missing/expired session is a 401 `session_expired`
 * (the screen bounces to login.php?note=expired), never a 200.
 *
 * Hardening over legacy:
 *   - `from`/`to` are the session user's own credentials ONLY; there is no
 *     parameter that can make the endpoint echo another user's e-mail
 *     (no-IDOR, no reflector);
 *   - `action` is scalar-guarded (array-shaped `?action[]=x` -> 400, the
 *     #1886/#1893 defect class);
 *   - JSON-only, Cache-Control: no-store, X-Content-Type-Options: nosniff.
 *
 * Refs #1901.
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

// Scalar guard: a present-but-array ?action[]=x is a client error.
$action = 'context';
if (isset($_GET['action'])) {
    $raw = $_GET['action'];
    if (!is_string($raw)) {
        http_response_code(400);
        echo json_encode(array('status' => 'error', 'code' => 'INVALID_PARAMETER',
                               'message' => 'Invalid action parameter'));
        exit;
    }
    $action = trim($raw);
}
if ($action !== '' && $action !== 'context') {
    http_response_code(400);
    echo json_encode(array('status' => 'error', 'code' => 'UNKNOWN_ACTION',
                           'message' => 'Unknown action'));
    exit;
}

// Session gate BEFORE any DB work (the #1677 lesson): an anonymous caller
// must never receive a DB error or be treated as logged in.
$db = new database(DB_TYPE);
doDBConnect($db);
bffEnforceSession($db);

$userId = isset($_SESSION['userID']) ? (int)$_SESSION['userID'] : 0;
$userLogin = '';
$email = '';
if (isset($_SESSION['currentUser']) && is_object($_SESSION['currentUser'])) {
    if (isset($_SESSION['currentUser']->login)) {
        $userLogin = (string)$_SESSION['currentUser']->login;
    }
    if (isset($_SESSION['currentUser']->emailAddress)) {
        $email = (string)$_SESSION['currentUser']->emailAddress;
    }
}

$basehref = isset($_SESSION['basehref']) ? (string)$_SESSION['basehref'] : '';
$locale = isset($_SESSION['locale']) ? (string)$_SESSION['locale'] : '';

$payload = array(
    'status'   => 'ok',
    'action'   => 'context',
    'auth'     => array(
        'logged_in'  => $userId > 0,
        'user_id'    => $userId,
        'user_login' => $userLogin,
        'email'      => $email,
    ),
    // generateHtmlEmail() (displayMgr.php:136-145) defaults from = the current
    // user's address and to = from when the report did not set them.
    'from'     => $email,
    'to'       => $email,
    'locale'   => $locale,
    'basehref' => $basehref,
);

echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
