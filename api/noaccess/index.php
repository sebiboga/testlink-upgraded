<?php
/**
 * BFF API — TestLink "No access to feature" page
 *
 * Ports the last standalone full-page legacy feedback renderer without a
 * modern twin: gui/templates/dashio/feedback/noaccesstofeature.tpl — the
 * "You do not have enough rights to access the feature" page (Legacy string
 * $TLS_no_access_to_feature) that the 1.9.20 right-check failure path
 * (tlUser::checkGUISecurityClearance(), lib/functions/tlUser.class.php:1490)
 * was meant to show before it silently bounced the user home.
 *
 * Modernized screen: gui/templates/feedback/noAccess.html.
 *
 * Routes (GET/HEAD only):
 *   GET ?feature=<machine-id>[&script=<legacy script name>]
 *     -> { status, feature, message_key, script, auth:{...}, locale, basehref }
 *
 * Behavioural parity:
 *   - no session required: the legacy template was a bare render, so an
 *     anonymous deep-link (e.g. a stale bookmarked URL after logout) works;
 *   - the message key resolves to the same English string the legacy bundle
 *     carried ("You do not have enough rights to access the feature").
 *
 * Hardening over legacy:
 *   - a stable machine identifier (feature) is returned instead of a raw
 *     script path, so callers/tests assert the path without leaking internals;
 *   - the message is an i18n key resolved client side (legacy hardcoded the
 *     active locale at render time and lost the requested feature);
 *   - `feature` / `script` are scalar-guarded (an array-shaped ?feature[]=x is
 *     a 400, the same defect class as #1886 / #1893) and the echoed script is
 *     length-capped, never trusting the caller;
 *   - JSON-only, Cache-Control: no-store, X-Content-Type-Options: nosniff.
 *
 * Refs #1902.
 */
require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

// Headers and request validation FIRST so the page answers a clean JSON even
// when the DB is unreachable; the DB is only connected lazily, below, when the
// login decoration truly needs it.
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

// Scalar guard: a present-but-array ?feature[]=x is a client error, never
// silently coerced (same class as #1886 / #1893).
$feature = 'generic';
if (isset($_GET['feature'])) {
    $raw = $_GET['feature'];
    if (!is_string($raw) || !preg_match('/^[a-z0-9_]{1,64}$/', $raw)) {
        http_response_code(400);
        echo json_encode(array('status' => 'error', 'code' => 'INVALID_FEATURE',
                               'message' => 'Invalid feature parameter'));
        exit;
    }
    $feature = $raw;
}

// Optional human context: the legacy script name that denied access. Never
// echoed unescaped (the screen renders it with .text()); cap the length so a
// hostile caller cannot bloat the payload.
$script = '';
if (isset($_GET['script'])) {
    $raw = $_GET['script'];
    if (!is_string($raw)) {
        http_response_code(400);
        echo json_encode(array('status' => 'error', 'code' => 'INVALID_SCRIPT',
                               'message' => 'Invalid script parameter'));
        exit;
    }
    $script = substr(trim($raw), 0, 128);
}

$userId = isset($_SESSION['userID']) ? (int)$_SESSION['userID'] : 0;
$userLogin = '';
if ($userId > 0) {
    if (isset($_SESSION['currentUser']) && is_object($_SESSION['currentUser'])
        && isset($_SESSION['currentUser']->login)) {
        $userLogin = (string)$_SESSION['currentUser']->login;
    } elseif (class_exists('tlUser')) {
        // Lazy connect: never let a DB failure break the deny page (the login
        // is decorative — the id is still returned).
        try {
            $db = new database(DB_TYPE);
            doDBConnect($db);
            $u = tlUser::getByID($db, $userId);
            if (is_object($u) && isset($u->login)) {
                $userLogin = (string)$u->login;
            }
        } catch (Throwable $e) {
            $userLogin = '';
        }
    }
}

$basehref = isset($_SESSION['basehref']) ? (string)$_SESSION['basehref'] : '';
$locale = isset($_SESSION['locale']) ? (string)$_SESSION['locale'] : '';

$payload = array(
    'status'      => 'ok',
    'feature'     => $feature,
    'message_key' => 'nacc.message',
    'script'      => $script,
    'auth'        => array(
        'logged_in'  => $userId > 0,
        'user_id'    => $userId,
        'user_login' => $userLogin,
    ),
    'locale'      => $locale,
    'basehref'    => $basehref,
);

echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
