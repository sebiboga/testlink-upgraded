<?php
/**
 * User Create/Edit BFF API
 * URL: /api/usersedit/?action=...
 * Plain PHP, no framework, no compilation.
 *
 * Deep-linkable home of the LAST full legacy Smarty renderer still served in
 * the User Management area: lib/usermanagement/usersEdit.php +
 * gui/templates/dashio/usermanagement/usersEdit.tpl (verified on 2026-10-08:
 * GET /lib/usermanagement/usersEdit.php?operation=edit&user_id=1 answers
 * HTTP 200 with the retired 1.9.20 UI and still executes doCreate / doUpdate /
 * resetPassword / genAPIKey from the request with no same-origin proof).
 *
 * The modern User Manager (gui/templates/usermanagement/usersView.html) covers
 * create/edit only as inline modals - there is no addressable usersEdit URL,
 * so a legacy deep link could not be handed over to a modern screen.
 *
 * Rights (legacy parity, lib/usermanagement/usersEdit.php:482 -> checkRights()
 * -> hasRight($db,'mgt_users')): EVERY route of this BFF requires mgt_users.
 *
 * Refs #1880.
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
require_once('users.inc.php');
require_once('email_api.php');
require_once('Zend/Validate/Hostname.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json');

// Session gate BEFORE the DB connect (#1677 / #1780 / #1814 lesson): a DB
// failure must never answer an anonymous caller with a raw dbms_msg.
$userId = $_SESSION['userID'] ?? null;
if (!$userId || $userId <= 0) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated',
                      'error_code' => 'NOT_AUTHENTICATED']);
    exit;
}

$db = new database(DB_TYPE);
doDBConnect($db);

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'User not found',
                      'error_code' => 'NOT_AUTHENTICATED']);
    exit;
}

// The whole BFF writes user rows - a stale session must be refused here, the
// same way api/users/index.php:47 does (issue #1760).
bffEnforceSession($db);

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'HEAD') {
    // HEAD is a safe read: answer it like GET (the Allow header below lists
    // GET, HEAD) instead of falling through to the 405 branch.
    $method = 'GET';
}
$action = (string)($_GET['action'] ?? '');

function out($data) { echo json_encode($data); exit; }
function getBody() { return json_decode(file_get_contents('php://input'), true) ?? []; }

function auditDenied($db, $user, $action) {
    logAuditEvent(
        TLS('audit_security_user_right_missing', $user->login, basename($_SERVER['PHP_SELF']), $action),
        'AUDIT', $user->dbID, 'user'
    );
}

// Legacy parity: lib/usermanagement/usersEdit.php:482 - every entry point of
// the user editor requires the global 'mgt_users' right.
function requireMgtUsers($db, $user, $action) {
    if (!$user->hasRight($db, 'mgt_users')) {
        auditDenied($db, $user, $action !== '' ? $action : $_SERVER['REQUEST_METHOD']);
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission',
             'error_code' => 'NO_RIGHT', 'right' => 'mgt_users']);
    }
}

// Mirror of api/users/index.php demoModeBlockedWrite(): demo mode blocks every
// write server-side (legacy only removed the buttons in the template).
function demoModeBlockedWrite($phpMsgKey = 'demo_update_user_disabled', $jsMsgKey = 'user.demoUpdateDisabled') {
    if (!config_get('demoMode')) {
        return false;
    }
    http_response_code(403);
    out([
        'status' => 'error',
        'error_code' => 'DEMO_MODE',
        'messageKey' => $jsMsgKey,
        'message' => lang_get($phpMsgKey, 'en_GB'),
    ]);
}

function userToJSON(tlUser $u) {
    $roleName = '';
    if ($u->globalRole) {
        $roleName = $u->globalRole->getDisplayName();
    } elseif ($u->globalRoleID) {
        $role = tlRole::getByID($GLOBALS['db'], $u->globalRoleID, tlRole::TLOBJ_O_GET_DETAIL_MINIMUM);
        if ($role) { $roleName = $role->getDisplayName(); }
    }
    return [
        'id' => intval($u->dbID),
        'login' => $u->login,
        'firstName' => $u->firstName,
        'lastName' => $u->lastName,
        'email' => $u->emailAddress,
        'locale' => $u->locale,
        'active' => intval($u->isActive),
        'globalRoleID' => intval($u->globalRoleID),
        'globalRoleName' => $roleName,
        'authentication' => $u->authentication ?? '',
        'expirationDate' => $u->expiration_date ?? '',
        'creation_ts' => $u->creation_ts ?? '',
    ];
}

// Legacy parity: usersEdit.php:462-467 hides the expiration date for logins
// listed in config_get('noExpDateUsers') (default ['admin']).
$noExpDateUsers = (array)config_get('noExpDateUsers');
function isNoExpDateUser($login) {
    global $noExpDateUsers;
    $needle = strtolower(trim((string)$login));
    foreach ($noExpDateUsers as $x) {
        if (strtolower(trim((string)$x)) === $needle) { return true; }
    }
    return false;
}

function loadUserOr404($db, $id) {
    if ($id <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid user id',
             'error_code' => 'INVALID_PARAM']);
    }
    $u = tlUser::getByID($db, $id);
    if (!$u) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'User not found',
             'error_code' => 'USER_NOT_FOUND']);
    }
    return $u;
}

/**
 * Apply the shared edit-form fields of a JSON body onto a tlUser object.
 * Legacy parity: initializeUserProperties() of lib/usermanagement/usersEdit.php
 * (including the legacy name blacklist: usersEdit.php:341-343 strips
 * / \ : * ? < > | from first/last name).
 */
function applyUserFields(tlUser $u, $body) {
    if (isset($body['firstName'])) {
        $u->firstName = trim(str_replace(['/', '\\', ':', '*', '?', '<', '>', '|'], '', (string)$body['firstName']));
    }
    if (isset($body['lastName'])) {
        $u->lastName = trim(str_replace(['/', '\\', ':', '*', '?', '<', '>', '|'], '', (string)$body['lastName']));
    }
    if (isset($body['email']))     { $u->emailAddress = trim((string)$body['email']); }
    if (isset($body['globalRoleID'])) {
        $rid = intval($body['globalRoleID']);
        // Legacy usersEdit.tpl:240-243 treats role 0 as "use default_roleid".
        $u->globalRoleID = $rid > 0 ? $rid : intval(config_get('default_roleid'));
    }
    if (isset($body['locale'])) { $u->locale = (string)$body['locale']; }
    if (isset($body['active'])) { $u->isActive = $body['active'] ? 1 : 0; }
    if (isset($body['authentication'])) { $u->authentication = (string)$body['authentication']; }
    if (!empty($body['password'])) { $u->setPassword($body['password']); }
}

function persistExpirationDate($db, tlUser $u, $body) {
    if (isNoExpDateUser($u->login) || !array_key_exists('expirationDate', $body)) { return; }
    $expDate = $body['expirationDate'];
    if (!is_string($expDate)) { return; }   // rejected by validateExpirationDate()
    $expDate = trim($expDate);
    if ($expDate === '') {
        tlUser::setExpirationDate($db, $u->dbID, null);
        $u->readFromDB($db);
        return;
    }
    tlUser::setExpirationDate($db, $u->dbID, $expDate);
    $u->readFromDB($db);
}

/**
 * Code review (#1880): validate the expiration date BEFORE any row is written,
 * so a bad value can never create/update the user and then fail (partial
 * commit), and a non-string can never be coerced to '' and silently clear a
 * stored expiry. Empty/null means "no expiry".
 */
function validateExpirationDate($body, $login) {
    if (isNoExpDateUser($login) || !array_key_exists('expirationDate', $body)) { return; }
    $expDate = $body['expirationDate'];
    if ($expDate === null || $expDate === '') { return; }
    if (!is_string($expDate) ||
        !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $expDate, $m) ||
        !checkdate(intval($m[2]), intval($m[3]), intval($m[1]))) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid expiration date',
             'error_code' => 'INVALID_PARAM']);
    }
}

// ---------------------------------------------------------------------------
// GET ?action=init&mode=create|edit[&user_id=N][&tproject_id=P][&tplan_id=T]
// ---------------------------------------------------------------------------
if ($method === 'GET' && $action === 'init') {
    requireMgtUsers($db, $user, 'init');

    $mode = (string)($_GET['mode'] ?? 'create');
    if (!in_array($mode, ['create', 'edit'], true)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid mode',
             'error_code' => 'INVALID_PARAM']);
    }
    $user_id = intval($_GET['user_id'] ?? 0);
    if ($mode === 'edit' && $user_id <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'user_id required in edit mode',
             'error_code' => 'INVALID_PARAM']);
    }

    $item = null;
    if ($mode === 'edit') {
        $u = loadUserOr404($db, $user_id);
        $item = userToJSON($u);
    }

    // Roles dropdown - legacy usersEdit.php:77-78 drops the reserved id 0
    // pseudo-role before rendering the options.
    $roles = tlRole::getAll($db, null, null, null, tlRole::TLOBJ_O_GET_DETAIL_MINIMUM);
    $roleItems = [];
    foreach ($roles as $r) {
        if (intval($r->dbID) <= 0) { continue; }
        $roleItems[] = ['id' => intval($r->dbID), 'name' => $r->getDisplayName()];
    }

    $localeItems = [];
    $locales = config_get('locales');
    if (is_array($locales)) {
        foreach ($locales as $k => $v) { $localeItems[] = ['code' => $k, 'name' => $v]; }
    }

    // Authentication methods - legacy usersEdit.php:440-451
    // auth_method_opt from config_get('authentication')['domain'].
    $authCfg = config_get('authentication');
    $domain = (isset($authCfg['domain']) && is_array($authCfg['domain'])) ? $authCfg['domain'] : [];
    $authItems = [];
    foreach ($domain as $code => $cfg) {
        $authItems[] = [
            'value' => (string)$code,
            'label' => (string)$code,
            'description' => (is_array($cfg) ? ($cfg['description'] ?? $code) : $code),
            'allowPasswordManagement' => (is_array($cfg) && isset($cfg['allowPasswordManagement']))
                ? (bool)$cfg['allowPasswordManagement'] : true,
        ];
    }

    // Grants - legacy usersEdit.php:457-466 (getGrantsForUserMgmt + the
    // mgt_view_events flag the edit screen's event-history link needs).
    $tproject_id = intval($_GET['tproject_id'] ?? ($_SESSION['testprojectID'] ?? 0));
    $tplan_id    = intval($_GET['tplan_id'] ?? ($_SESSION['testplanID'] ?? 0));
    $grants = getGrantsForUserMgmt($db, $user, $tproject_id, $tplan_id);
    $grants->mgt_view_events = ($user->hasRight($db, 'mgt_view_events') === 'yes') ? 'yes' : 'no';

    $tlCfg = $GLOBALS['tlCfg'] ?? null;
    $apiEnabled = ($tlCfg && isset($tlCfg->api->enabled)) ? (bool)$tlCfg->api->enabled : false;
    $externalPw = ($item !== null) ? tlUser::isPasswordMgtExternal($item['authentication']) : false;

    out([
        'status' => 'ok',
        'mode' => $mode,
        'user' => $item,
        'roles' => $roleItems,
        'defaultRoleID' => intval(config_get('default_roleid')),
        'locales' => $localeItems,
        'authentication' => [
            'configuredMethod' => $authCfg['method'] ?? '',
            'items' => $authItems,
            'externalPasswordMgmt' => $externalPw,
        ],
        'grants' => $grants,
        'context' => [
            'tproject_id' => $tproject_id,
            'tplan_id' => $tplan_id,
            'session_user_id' => intval($userId),
        ],
        'flags' => [
            'demoMode' => (bool)config_get('demoMode'),
            'apiEnabled' => $apiEnabled,
            'noExpDateUsers' => array_values($noExpDateUsers),
            'expDateEnabled' => ($item === null) ? true : !isNoExpDateUser($item['login']),
        ],
    ]);
}

// ---------------------------------------------------------------------------
// POST ?action=create
// ---------------------------------------------------------------------------
if ($method === 'POST' && $action === 'create') {
    requireMgtUsers($db, $user, $action);
    demoModeBlockedWrite();

    $body = getBody();
    $u = new tlUser();
    $u->login = trim((string)($body['login'] ?? ''));
    applyUserFields($u, $body);
    validateExpirationDate($body, $u->login);

    // Legacy doCreate() refuses an empty password (E_PWDEMPTY) unless the auth
    // domain manages passwords externally (S_PWDMGTEXTERNAL >= tl::OK).
    $pwStatus = $u->setPassword((string)($body['password'] ?? ''));
    if ($pwStatus < tl::OK) {
        http_response_code(400);
        out(['status' => 'error', 'error_code' => 'EMPTY_PASSWORD',
             'message' => getUserErrorMessage($pwStatus)]);
    }

    try {
        $result = $u->writeToDB($db);
    } catch (Throwable $e) {
        http_response_code(422);
        out(['status' => 'error', 'message' => 'Error creating user',
             'error_code' => 'DB_WRITE_FAILED']);
    }

    if ($result >= tl::OK) {
        persistExpirationDate($db, $u, $body);
        logAuditEvent("User '$u->login' created", "CREATE", $u->dbID, "users");
        out(['status' => 'ok', 'item' => userToJSON($u), 'id' => intval($u->dbID),
             'feedback_key' => 'user_created']);
    }

    $code = 'CREATE_FAILED';
    if ($result == tlUser::E_LOGINLENGTH) { $code = 'LOGIN_EMPTY'; }
    elseif ($result == tlUser::E_LOGINALREADYEXISTS) { $code = 'LOGIN_ALREADY_EXISTS'; }
    elseif ($result == tlUser::E_EMAILFORMAT) { $code = 'EMAIL_INVALID'; }
    http_response_code(422);
    out(['status' => 'error', 'message' => getUserErrorMessage($result),
         'error_code' => $code]);
}

// ---------------------------------------------------------------------------
// POST ?action=update   {user_id, ...fields}
// ---------------------------------------------------------------------------
if ($method === 'POST' && $action === 'update') {
    requireMgtUsers($db, $user, $action);
    demoModeBlockedWrite();

    $body = getBody();
    $user_id = intval($body['user_id'] ?? 0);
    $u = loadUserOr404($db, $user_id);
    $login = $u->login;

    applyUserFields($u, $body);
    validateExpirationDate($body, $login);

    try {
        $result = $u->writeToDB($db);
    } catch (Throwable $e) {
        http_response_code(422);
        out(['status' => 'error', 'message' => 'Error updating user',
             'error_code' => 'DB_WRITE_FAILED']);
    }

    if ($result >= tl::OK) {
        persistExpirationDate($db, $u, $body);
        logAuditEvent("User '$u->login' updated", "UPDATE", $u->dbID, "users");

        // Legacy doUpdate() parity (usersEdit.php:203-217): editing YOUR OWN
        // account refreshes the session identity, and deactivating yourself
        // logs you out immediately.
        $selfLogout = false;
        if (intval($userId) === intval($user_id)) {
            $_SESSION['currentUser'] = $u;
            setUserSession($db, $u->login, $user_id, $u->globalRoleID,
                           $u->emailAddress, $u->locale);
            if (empty($body['active'])) { $selfLogout = true; }
        }

        out(['status' => 'ok', 'item' => userToJSON($u),
             'feedback_key' => 'user_updated', 'self_logout' => $selfLogout]);
    }

    http_response_code(422);
    out(['status' => 'error', 'message' => getUserErrorMessage($result),
         'error_code' => 'UPDATE_FAILED']);
}

// ---------------------------------------------------------------------------
// POST ?action=reset_password   {user_id}
// ---------------------------------------------------------------------------
if ($method === 'POST' && $action === 'reset_password') {
    requireMgtUsers($db, $user, $action);

    $body = getBody();
    $user_id = intval($body['user_id'] ?? 0);
    $u = loadUserOr404($db, $user_id);

    // Legacy usersEdit.tpl:169-178 hides the form for external password
    // management; refuse early instead of the legacy silent-OK quirk.
    if (tlUser::isPasswordMgtExternal($u->authentication)) {
        http_response_code(400);
        out(['status' => 'error', 'error_code' => 'PASSWORD_MGMT_EXTERNAL',
             'message' => lang_get('password_mgmt_is_external')]);
    }
    demoModeBlockedWrite('demo_reset_password_disabled', 'user.demoResetPasswordDisabled');

    $sendMethod = config_get('password_reset_send_method');
    $passwordOnScreen = ($sendMethod === 'display_on_screen');

    $smtpHost = config_get('smtp_host');
    $validator = @new Zend_Validate_Hostname(Zend_Validate_Hostname::ALLOW_ALL);
    $smtpHostValid = @$validator->isValid($smtpHost) || $passwordOnScreen;
    if (!$smtpHostValid) {
        http_response_code(400);
        out(['status' => 'error', 'error_code' => 'INVALID_SMTP_HOSTNAME',
             'message' => lang_get('password_cannot_be_reseted_invalid_smtp_hostname')]);
    }

    $dummy = resetPassword($db, $user_id, $sendMethod);
    if ($dummy['status'] >= tl::OK) {
        logAuditEvent(TLS('audit_pwd_reset_requested', $u->login), 'PWD_RESET', $user_id, 'users');
        $message = lang_get('password_reseted');
        $code = 'OK_SENT';
        if ($passwordOnScreen) {
            $message = lang_get('password_set') . $dummy['password'];
            $code = 'OK_ON_SCREEN';
        }
        out(['status' => 'ok', 'code' => $code, 'message' => $message,
             'newPassword' => $passwordOnScreen ? $dummy['password'] : '',
             'passwordOnScreen' => $passwordOnScreen]);
    }
    $reason = ($dummy['msg'] ?? '') !== '' ? $dummy['msg'] : getUserErrorMessage($dummy['status']);
    http_response_code(400);
    out(['status' => 'error', 'error_code' => 'RESET_FAILED',
         'message' => @sprintf(lang_get('password_cannot_be_reseted_reason'), $reason)]);
}

// ---------------------------------------------------------------------------
// POST ?action=gen_apikey   {user_id}
// ---------------------------------------------------------------------------
if ($method === 'POST' && $action === 'gen_apikey') {
    requireMgtUsers($db, $user, $action);
    demoModeBlockedWrite('demo_reset_password_disabled', 'user.demoResetPasswordDisabled');

    $tlCfg = $GLOBALS['tlCfg'] ?? null;
    $apiEnabled = ($tlCfg && isset($tlCfg->api->enabled)) ? (bool)$tlCfg->api->enabled : false;
    if (!$apiEnabled) {
        http_response_code(403);
        out(['status' => 'error', 'error_code' => 'API_DISABLED',
             'message' => 'API key management is disabled']);
    }

    $body = getBody();
    $user_id = intval($body['user_id'] ?? 0);
    $u = loadUserOr404($db, $user_id);

    $validator = @new Zend_Validate_Hostname(Zend_Validate_Hostname::ALLOW_ALL);
    $smtp_host = config_get('smtp_host');
    if (!$validator->isValid($smtp_host)) {
        http_response_code(400);
        out(['status' => 'error', 'error_code' => 'INVALID_SMTP_HOSTNAME',
             'message' => 'API key cannot be generated. Reason: SMTP hostname seems to be invalid.']);
    }

    $APIKey = new APIKey();
    $result = $APIKey->addKeyForUser($u->dbID);
    if ($result >= tl::OK) {
        logAuditEvent(TLS('audit_user_apikey_set', $u->login), 'CREATE', $u->login, 'users');
        $ak = $APIKey->getAPIKey($u->dbID);
        $msgBody = lang_get('your_apikey_is') . "\n\n" . $ak .
                   "\n\n" . lang_get('contact_admin');
        try {
            @email_send(config_get('from_email'), $u->emailAddress,
                        lang_get('mail_apikey_subject'), $msgBody);
        } catch (Throwable $e) {
            error_log('API key mail delivery failed for user ' . $u->login . ': ' . $e->getMessage());
        }
        out(['status' => 'ok', 'message' => lang_get('apikey_by_mail')]);
    }
    http_response_code(500);
    out(['status' => 'error', 'error_code' => 'APIKEY_FAILED',
         'message' => 'Failed to generate API key']);
}

// ---------------------------------------------------------------------------
// Verb / action contract
// ---------------------------------------------------------------------------
$knownActions = ['init', 'create', 'update', 'reset_password', 'gen_apikey'];
if (!in_array($action, $knownActions, true)) {
    http_response_code(400);
    out(['status' => 'error', 'message' => 'Unknown action',
         'error_code' => 'UNKNOWN_ACTION']);
}
// Known action, wrong verb (e.g. GET ?action=create).
header('Allow: GET, HEAD');
http_response_code(405);
out(['status' => 'error', 'message' => 'Method not allowed',
     'error_code' => 'METHOD_NOT_ALLOWED']);
