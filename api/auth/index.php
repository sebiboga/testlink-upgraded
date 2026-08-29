<?php
/**
 * Authentication BFF API
 * URL: /api/auth/
 * Handles: login, logout, public login-page config.
 *
 * This is the only BFF that does NOT require an established session: the
 * login route creates the session (via doAuthorize). It is the entry point
 * for the modernized Dashio login page (gui/templates/auth/login.html).
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
require_once('doAuthorize.php');
require_once('ldap_api.php');
require_once('oauth_api.php');

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$db = new database(DB_TYPE);
doDBConnect($db);

$path = $_SERVER['PATH_INFO'] ?? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = preg_replace('#^/api/auth(/index\.php)?#', '', $path);
$path = '/' . trim($path, '/');
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$segments = array_values(array_filter(explode('/', $path)));

function out($data) { echo json_encode($data); exit; }
function getBody() { return json_decode(file_get_contents('php://input'), true) ?? []; }

/**
 * Build all data the login page needs to render (self-signup, OAuth buttons,
 * lost-password availability, admin login warning, etc.).
 */
function loginPageConfig(&$db) {
    $authCfg = config_get('authentication');
    $selfSignup = config_get('user_self_signup');

    $externalPasswordMgmt = false;
    if (isset($authCfg['sso_only']) && $authCfg['sso_only']) {
        $externalPasswordMgmt = true;
    } else {
        $domain = $authCfg['domain'];
        $mm = $authCfg['method'];
        if (isset($domain[$mm])) {
            $externalPasswordMgmt = !$domain[$mm]['allowPasswordManagement'];
        }
    }

    $loginDisabled = (('LDAP' == $authCfg['method']) && !checkForLDAPExtension()) ? 1 : 0;

    $oauth = array();
    $oau = config_get('OAuthServers');
    if (is_array($oau)) {
        foreach ($oau as $prov) {
            if (!empty($prov['oauth_enabled'])) {
                $name = $prov['oauth_name'];
                $oauth[] = array(
                    'name' => ucfirst($name),
                    'link' => oauth_link($prov),
                    'icon' => $name . '.png',
                );
            }
        }
    }

    return array(
        'status' => 'ok',
        'config' => array(
            'selfSignup' => (bool)$selfSignup,
            'externalPasswordMgmt' => (bool)$externalPasswordMgmt,
            'loginDisabled' => (int)$loginDisabled,
            'oauth' => $oauth,
            'pwdMaxLen' => (int)config_get('loginPagePasswordMaxLenght'),
            'demoMode' => (bool)config_get('demoMode'),
            'ssoEnabled' => !empty($authCfg['SSO_enabled']),
        ),
    );
}

// Route: GET /api/auth/config - public data for the login form
if ($method === 'GET' && (empty($segments) || $segments[0] === 'config' || $segments[0] === 'index.php' || $segments[0] === '')) {
    out(loginPageConfig($db));
}

// Route: GET /api/auth/check - is there a valid session?
if ($method === 'GET' && isset($segments[0]) && $segments[0] === 'check') {
    $valid = false;
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $valid = isset($_SESSION['currentUser']) && !is_null($_SESSION['currentUser']);
    out(array('status' => 'ok', 'validSession' => (bool)$valid, 'login' => $valid ? $_SESSION['currentUser']->login : ''));
}

// Route: POST /api/auth/login - authenticate and create session
if ($method === 'POST' && isset($segments[0]) && $segments[0] === 'login') {
    $body = getBody();
    $login = trim($body['login'] ?? '');
    $pwd = trim($body['password'] ?? '');
    $destination = $body['destination'] ?? '';
    $reqURI = $body['reqURI'] ?? '';

    if ($login === '') {
        out(array('status' => 'error', 'success' => false, 'reason' => 'login.emptyLogin'));
    }

    doSessionStart(true);

    $options = new stdClass();
    $options->doSessionExistsCheck = false;
    $op = doAuthorize($db, $login, $pwd, $options);

    if ($op['status'] === tl::OK) {
        logAuditEvent(TLS("audit_login_succeeded", $login, $_SERVER['REMOTE_ADDR']),
            "LOGIN", $_SESSION['currentUser']->dbID, "users");
        out(array(
            'status' => 'ok',
            'success' => true,
            'destination' => $destination,
        ));
    }

    $note = is_null($op['msg']) ? 'auth.badUserPasswd' : $op['msg'];
    out(array('status' => 'error', 'success' => false, 'reason' => $note));
}

// Route: POST /api/auth/logout - destroy session
if ($method === 'POST' && isset($segments[0]) && $segments[0] === 'logout') {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION = array();
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    out(array('status' => 'ok', 'success' => true));
}

http_response_code(404);
out(array('status' => 'error', 'message' => 'Not found'));
