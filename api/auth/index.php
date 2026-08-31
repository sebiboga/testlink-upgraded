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

// Sign-up flow reuses the same helpers as the legacy firstLogin.php.
require_once('email_api.php');
require_once('Zend/Validate/EmailAddress.php');

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
function getBody() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST)) {
        return $_POST;
    }
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    return is_array($json) ? $json : (is_array($_POST) ? $_POST : []);
}

/**
 * Post-login redirect target guard. Only same-app relative URLs are allowed so
 * an attacker cannot turn ?destination= into an open redirect (phishing) or a
 * javascript: URI that executes in the login-page origin after log in (Refs
 * #775, code review BLOCKER). Mirrors the legacy authorizePostProcessing()
 * hard block (login.php) but accepts any root-relative same-app path.
 *
 * Allowed:  /linkto.php?key=val   /lib/foo.json
 * Rejected: https://evil.example  //evil.example  javascript:...  \  @  controls
 */
function safeDestination($dest) {
    $dest = trim((string)$dest);
    if ($dest === '') {
        return '';
    }
    if (strlen($dest) > 4000) {
        return '';
    }
    // Must be a root-relative URL: exactly one leading '/', not '//'.
    if (preg_match('#^/[^/]#', $dest) !== 1) {
        return '';
    }
    // Block any scheme/authority/control chars that could escape the app.
    if (preg_match('#[:\x00-\x1f\x7f\\\\@]#', $dest)) {
        return '';
    }
    return $dest;
}

/**
 * Send mail to administrators (users that have default role = administrator)
 * to warn about a new user created. Ported from legacy firstLogin.php.
 */
function notifyGlobalAdmins(&$dbHandler, &$userObj)
{
    $mail = array();
    $cfg = config_get('notifications');
    if (!is_null($cfg->userSignUp->to->roles)) {
        $opt = array('active' => 1);
        foreach ($cfg->userSignUp->to->roles as $roleID) {
            $roleMgr = new tlRole($roleID);
            $userSet = $roleMgr->getUsersWithGlobalRole($dbHandler, $opt);
            $key2loop = array_keys($userSet);
            foreach ($key2loop as $userID) {
                if (!isset($mail['to'][$userID])) {
                    $mail['to'][$userID] = $userSet[$userID]->emailAddress;
                }
            }
        }
    }
    if (!is_null($cfg->userSignUp->to->users)) {
        $tables = tlObject::getDBTables('users');
        $escapedUsers = array_map(function($u) use ($dbHandler) {
            return $dbHandler->prepare_string($u);
        }, $cfg->userSignUp->to->users);
        $sql = " SELECT id,email FROM {$tables['users']} " .
               " WHERE login IN('" . implode("','", $escapedUsers) . "')";
        $userSet = $dbHandler->fetchRowsIntoMap($sql, 'id');
        if (!is_null($userSet)) {
            foreach ($userSet as $userID => $elem) {
                if (!isset($mail['to'][$userID])) {
                    $mail['to'][$userID] = $elem['email'];
                }
            }
        }
    }

    if (isset($mail['to']) && count($mail['to']) > 0) {
        $dest = array();
        $validator = new Zend_Validate_EmailAddress();
        foreach ($mail['to'] as $mm) {
            $ema = trim($mm);
            if ($ema === '' || !$validator->isValid($ema)) {
                continue;
            }
            $dest[] = $ema;
        }
        if (count($dest) > 0) {
            $mail['to'] = implode(',', $dest);
            $mail['subject'] = lang_get('new_account');
            $mail['body'] = lang_get('new_account') . "\n";
            $mail['body'] .= " user:$userObj->login\n";
            $mail['body'] .= " first name:$userObj->firstName surname:$userObj->lastName\n";
            $mail['body'] .= " email:{$userObj->emailAddress}\n";
            @email_send(config_get('from_email'), $mail['to'], $mail['subject'], $mail['body']);
        }
    }
}

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
    $login = '';
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $valid = isset($_SESSION['currentUser']) && !is_null($_SESSION['currentUser'])
             && !empty($_SESSION['userID']);
    if ($valid) {
        $cu = tlUser::getByID($db, $_SESSION['userID']);
        if ($cu) {
            $login = $cu->login;
        }
    }
    out(array('status' => 'ok', 'validSession' => (bool)$valid, 'login' => $login));
}

// Route: POST /api/auth/login - authenticate and create session
if ($method === 'POST' && isset($segments[0]) && $segments[0] === 'login') {
    $body = getBody();
    $login = trim($body['login'] ?? '');
    $pwd = trim($body['password'] ?? '');
    $destination = safeDestination($body['destination'] ?? '');
    $reqURI = $body['reqURI'] ?? '';

    if ($login === '') {
        out(array('status' => 'error', 'success' => false, 'reason' => 'login.emptyLogin'));
    }

    // Wrong schema must BLOCK any login action (mirrors legacy doBlockingChecks).
    $schemaOK = checkSchemaVersion($db);
    if ($schemaOK['status'] < tl::OK) {
        out(array('status' => 'error', 'success' => false,
                  'reason' => isset($schemaOK['msg']) ? $schemaOK['msg'] : 'auth.schemaBlocked'));
    }

    doSessionStart(true);

    $options = new stdClass();
    $options->doSessionExistsCheck = false;
    $op = doAuthorize($db, $login, $pwd, $options);

    if ($op['status'] === tl::OK) {
        logAuditEvent(TLS("audit_login_succeeded", $login, $_SERVER['REMOTE_ADDR']),
            "LOGIN", $_SESSION['currentUser']->dbID, "users");
        // Fixate-proof: new session id once authentication succeeds.
        if (function_exists('session_regenerate_id') && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        out(array(
            'status' => 'ok',
            'success' => true,
            'destination' => $destination,
        ));
    }

    $note = is_null($op['msg']) ? 'auth.badUserPasswd' : $op['msg'];
    out(array('status' => 'error', 'success' => false, 'reason' => $note));
}

// Route: POST /api/auth/signup - self registration (mirrors firstLogin.php)
if ($method === 'POST' && isset($segments[0]) && $segments[0] === 'signup') {
    if (!config_get('user_self_signup')) {
        out(array('status' => 'error', 'success' => false, 'reason' => 'error_self_signup_disabled'));
    }
    $body = getBody();
    $login = trim($body['login'] ?? '');
    $pwd = trim($body['password'] ?? '');
    $pwd2 = trim($body['password2'] ?? '');
    $firstName = trim($body['firstName'] ?? '');
    $lastName = trim($body['lastName'] ?? '');
    $email = trim($body['email'] ?? '');

    if ($login === '' || $firstName === '' || $lastName === '' || $email === '') {
        out(array('status' => 'error', 'success' => false, 'reason' => 'auth.requiredFields'));
    }

    // External password management (SSO/LDAP): the sign-up page hides the
    // password fields, so a locally set password is neither expected nor
    // required. Skip the local password step rather than fail on E_PWDEMPTY.
    $externalPwd = (bool) config_get('external_password_mgmt', false);
    if (!$externalPwd) {
        if (strcmp($pwd, $pwd2) !== 0) {
            out(array('status' => 'error', 'success' => false, 'reason' => 'passwd_dont_match'));
        }
        $user = new tlUser();
        $rx = $user->checkPasswordQuality($pwd);
        if ($rx['status_ok'] < tl::OK) {
            out(array('status' => 'error', 'success' => false,
                      'reason' => isset($rx['msg']) ? $rx['msg'] : 'auth.weakPassword'));
        }
        $result = $user->setPassword($pwd);
        if ($result < tl::OK) {
            out(array('status' => 'error', 'success' => false, 'reason' => getUserErrorMessage($result)));
        }
    }

    if (!isset($user) || !$user) {
        $user = new tlUser();
    }
    $user->login = $login;
    $user->emailAddress = $email;
    $user->firstName = $firstName;
    $user->lastName = $lastName;
    $result = $user->writeToDB($db);

    if ($result < tl::OK) {
        out(array('status' => 'error', 'success' => false, 'reason' => getUserErrorMessage($result)));
    }

    $cfg = config_get('notifications');
    if ($cfg->userSignUp->enabled) {
        notifyGlobalAdmins($db, $user);
    }
    logAuditEvent(TLS("audit_users_self_signup", $login), "CREATE", $user->dbID, "users");

    out(array('status' => 'ok', 'success' => true));
}

// Route: POST /api/auth/reset - request password reset (mirrors lostPassword.php)
if ($method === 'POST' && isset($segments[0]) && $segments[0] === 'reset') {
    $body = getBody();
    $login = trim($body['login'] ?? '');
    // Legacy init_args() constrains the login to 0..30 chars (STRING_N,0,30).
    // Keep that parity server-side: an overlong input is treated as absent so
    // the generic (enumeration-safe) contract still holds.
    if (strlen($login) > 30) {
        $login = '';
    }

    $userID = false;
    if ($login !== '') {
        $userID = tlUser::doesUserExist($db, $login);
        if ($userID) {
            $user = new tlUser(intval($userID));
            $user->readFromDB($db);
            $external = tlUser::isPasswordMgtExternal($user->authentication);
        } else {
            $external = false;
        }
    }

    // Enumeration-safe contract: every path below returns the SAME generic
    // success body so an unauthenticated caller cannot learn whether a login
    // exists, its auth method, or whether it has a registered email. Internal
    // distinctions are deliberately not leaked; a real reset is processed but
    // reports generically.
    if ($userID && !$external) {
        $result = false;
        try {
            $result = resetPassword($db, $userID);
        } catch (\Throwable $e) {
            // Mail transport misconfigured (e.g. from_email/SMTP unset) must not
            // surface as a 500; handled gracefully below.
            $result = false;
        }
        if ($result !== false && $result['status'] >= tl::OK && $user->readFromDB($db) >= tl::OK) {
            logAuditEvent(TLS("audit_pwd_reset_requested", $user->login), "PWD_RESET", $userID, "users");
        }
    }

    out(array('status' => 'ok', 'success' => true));
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
