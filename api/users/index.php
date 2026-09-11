<?php
/**
 * Users BFF API
 * URL: /api/users/
 * Plain PHP, no framework, no compilation
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

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || $userId <= 0) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'User not found']);
    exit;
}

function out($data) { echo json_encode($data); exit; }

// Legacy parity: lib/usermanagement/usersView.php checkRights() ->
// $user->hasRight($db,'mgt_users'); also enforced by usersEdit.php:482,
// usersExport.php:105 and usersAssign.php. Every user-management entry
// point (list/create/edit/disable/export) requires the mgt_users right.
// Without it the BFF refuses to serve ANY route (403), mirroring the
// legacy login redirect for unauthorized users.
if (!$user->hasRight($db, 'mgt_users')) {
    logAuditEvent(TLS("audit_security_user_right_missing",
                      $user->login,
                      basename($_SERVER['SCRIPT_NAME']),
                      $_SERVER['REQUEST_METHOD']),
                  'AUTH', $user->dbID, 'users');
    http_response_code(403);
    out(['status' => 'error', 'message' => 'no_permissions_for_action', 'right' => 'mgt_users']);
}

$path = $_SERVER['PATH_INFO'] ?? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = preg_replace('#^/api/users(/index\.php)?#', '', $path);
$path = '/' . trim($path, '/');
$method = $_SERVER['REQUEST_METHOD'];
$segments = array_values(array_filter(explode('/', $path)));

function getParam($key, $default = null) { return $_GET[$key] ?? $default; }
function getBody() { return json_decode(file_get_contents('php://input'), true) ?? []; }

// Legacy parity: lib/usermanagement/usersEdit.php:462-466 gates the expiration
// date field (expDateEnabled) on config_get('noExpDateUsers') (default
// ['admin']). The BFF re-applies the same rule server-side so noExpDateUsers
// logins can never receive an expiration date via the API.
$noExpDateUsers = (array)config_get('noExpDateUsers');
$isNoExpDateUser = function ($login) use ($noExpDateUsers) {
    $needle = strtolower(trim((string)$login));
    foreach ($noExpDateUsers as $x) {
        if (strtolower(trim((string)$x)) === $needle) { return true; }
    }
    return false;
};

function userToJSON(tlUser $u) {
    $roleName = '';
    if ($u->globalRole) {
        $roleName = $u->globalRole->getDisplayName();
    } elseif ($u->globalRoleID) {
        $role = tlRole::getByID($GLOBALS['db'], $u->globalRoleID, tlRole::TLOBJ_O_GET_DETAIL_MINIMUM);
        if ($role) $roleName = $role->getDisplayName();
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

// Route: GET /users?login=<login> - resolve a login to a user (legacy
// "Manage user" lookup, usersView.tpl manage_user form -> usersEdit.php
// 'edit' case). Mirrors lib/usermanagement/usersEdit.php:403-410 which
// resolves the login via tlUser::doesUserExist() and reports
// login_does_not_exist when it cannot. 404 tells the UI to show the
// localized not-found message.
if ($method === 'GET' && ($path === '/' || $path === '' || $path === '/index.php')) {
    $loginParam = getParam('login');
    if ($loginParam !== null) {
        $loginParam = trim($loginParam);
        if ($loginParam === '') {
            http_response_code(400);
            out(['status' => 'error', 'message' => 'login_required']);
        }
        $uid = tlUser::doesUserExist($db, $loginParam);
        if (!$uid) {
            http_response_code(404);
            out(['status' => 'error', 'message' => 'login_does_not_exist', 'login' => $loginParam]);
        }
        $u = tlUser::getByID($db, $uid);
        out(['status' => 'ok', 'item' => userToJSON($u)]);
    }

    $tables = tlObject::getDBTables(array('users', 'nodes_hierarchy', 'roles'));
    $sql = "SELECT u.id, u.login, u.first, u.last, u.email, u.locale, " .
           "u.active, u.role_id, u.auth_method, u.expiration_date, u.creation_ts, " .
           "nh.name AS fullName, r.description AS roleName " .
           "FROM {$tables['users']} u " .
           "LEFT OUTER JOIN {$tables['nodes_hierarchy']} nh ON nh.id = u.id " .
           "LEFT OUTER JOIN {$tables['roles']} r ON r.id = u.role_id " .
           "ORDER BY u.login ASC";
    $rows = $db->get_recordset($sql);
    $items = [];
    if ($rows) {
        foreach ($rows as $row) {
            // Legacy parity: lib/usermanagement/usersView.php:262-267 localizes
            // the expiration date via localize_dateOrTimeStamp(null, null,
            // 'date_format', $ed) so the grid shows e.g. "31/12/2026". The BFF
            // returns both the raw DB value (for the edit modal / save round-trip)
            // and the localized display string for the grid column.
            $expirationDateFormatted = '';
            $ed = trim($row['expiration_date'] ?? '');
            if ($ed !== '') {
                $expirationDateFormatted = localize_dateOrTimeStamp(null, null, 'date_format', $ed);
            }
            $items[] = [
                'id' => intval($row['id']),
                'login' => $row['login'],
                'firstName' => $row['first'],
                'lastName' => $row['last'],
                'email' => $row['email'],
                'locale' => $row['locale'],
                'active' => intval($row['active']),
                'globalRoleID' => intval($row['role_id']),
                'globalRoleName' => $row['roleName'] ?? '',
                'authentication' => $row['auth_method'] ?? '',
                'expirationDate' => $row['expiration_date'] ?? '',
                'expirationDateFormatted' => $expirationDateFormatted,
                'creation_ts' => $row['creation_ts'] ?? '',
            ];
        }
    }
    out(['status' => 'ok', 'items' => $items, 'total' => count($items)]);
}

// Route: GET /users/meta/roles - list all roles for dropdown
if ($method === 'GET' && isset($segments[0]) && $segments[0] === 'meta' && isset($segments[1]) && $segments[1] === 'roles') {
    $roles = tlRole::getAll($db, null, null, null, tlRole::TLOBJ_O_GET_DETAIL_MINIMUM);
    $items = [];
    foreach ($roles as $r) {
        $items[] = ['id' => intval($r->dbID), 'name' => $r->getDisplayName()];
    }
    out(['status' => 'ok', 'items' => $items]);
}

// Route: GET /users/meta/locales - list locales
if ($method === 'GET' && isset($segments[0]) && $segments[0] === 'meta' && isset($segments[1]) && $segments[1] === 'locales') {
    $locales = config_get('locales');
    $items = [];
    if (is_array($locales)) {
        foreach ($locales as $k => $v) { $items[] = ['code' => $k, 'name' => $v]; }
    }
    out(['status' => 'ok' , 'items' => $items]);
}

// Route: GET /users/meta/authentication - list authentication methods for
// the create/edit modal (legacy parity: lib/usermanagement/usersEdit.php:440-451
// builds auth_method_opt from config_get('authentication')['domain']). The
// first option (value '') means "use the configured default method". Also
// exposes noExpDateUsers (config.inc.php:492, default ['admin']) so the modal
// can hide the expiration date field for those logins exactly like legacy
// usersEdit.php:462-467.
// apiEnabled (config.inc.php:634) is exposed so the UI can gate the
// "Generate API Key" action (legacy usersEdit.tpl:351-355).
if ($method === 'GET' && isset($segments[0]) && $segments[0] === 'meta' && isset($segments[1]) && $segments[1] === 'authentication') {
    $authCfg = config_get('authentication');
    $domain = (isset($authCfg['domain']) && is_array($authCfg['domain'])) ? $authCfg['domain'] : [];
    $items = [];
    foreach ($domain as $code => $cfg) {
        $items[] = [
            'value' => (string)$code,
            'label' => (string)$code,
            'description' => (is_array($cfg) ? ($cfg['description'] ?? $code) : $code),
            'allowPasswordManagement' => (is_array($cfg) && isset($cfg['allowPasswordManagement'])) ? (bool)$cfg['allowPasswordManagement'] : true,
        ];
    }
    $noExp = config_get('noExpDateUsers');
    if (!is_array($noExp)) { $noExp = []; }
    $tlCfg = $GLOBALS['tlCfg'] ?? null;
    $apiEnabled = false;
    if ($tlCfg && isset($tlCfg->api->enabled)) {
        $apiEnabled = (bool)$tlCfg->api->enabled;
    }
    out(['status' => 'ok',
         'configuredMethod' => $authCfg['method'] ?? '',
         'items' => $items,
         'noExpDateUsers' => array_values($noExp),
         'apiEnabled' => $apiEnabled]);
}

// Route: GET /users/meta/grants - user mgmt grant flags (mirror of legacy
// getGrantsForUserMgmt()). Used by the modernized screens (usersView,
// usersExport, ...) to gate the tab bar: role mgmt / assign-project /
// assign-plan tabs only render when the matching grant is 'yes'.
if ($method === 'GET' && isset($segments[0]) && $segments[0] === 'meta' && isset($segments[1]) && $segments[1] === 'grants') {
    $tprojectID = isset($_SESSION['testprojectID']) ? intval($_SESSION['testprojectID']) : 0;
    $tplanID = isset($_SESSION['testplanID']) ? intval($_SESSION['testplanID']) : 0;
    $grants = getGrantsForUserMgmt($db, $user, $tprojectID, $tplanID);
    out(['status' => 'ok', 'grants' => $grants]);
}

// Route: GET /users/{id} - get single user
if ($method === 'GET' && isset($segments[0]) && is_numeric($segments[0])) {
    $u = tlUser::getByID($db, intval($segments[0]));
    if (!$u) { http_response_code(404); out(['status' => 'error', 'message' => 'User not found']); }
    out(['status' => 'ok', 'item' => userToJSON($u)]);
}

// Route: POST /users - create user
if ($method === 'POST' && empty($segments)) {
    $body = getBody();
    $u = new tlUser();
    $u->login = trim($body['login'] ?? '');
    $u->firstName = trim($body['firstName'] ?? '');
    $u->lastName = trim($body['lastName'] ?? '');
    $u->emailAddress = trim($body['email'] ?? '');
    $u->globalRoleID = intval($body['globalRoleID'] ?? 0);
    $u->locale = $body['locale'] ?? config_get('default_language');
    $u->isActive = ($body['active'] ?? true) ? 1 : 0;
    $u->authentication = $body['authentication'] ?? '';
    // setPassword() runs the value through password_hash() itself - hashing
    // here too would store bcrypt(md5(pwd)), which login can never verify.
    $u->setPassword($body['password'] ?? '');

    $result = $u->writeToDB($db);
    if ($result >= tl::OK) {
        // Legacy parity: lib/usermanagement/usersEdit.php:164 persists the
        // expiration date (ISO, via tlUser::setExpirationDate) right after the
        // user row is written. Empty/null clears it to NULL. Logins listed in
        // noExpDateUsers (admin) can never have one set (legacy hides the field).
        if (!$isNoExpDateUser($u->login) && array_key_exists('expirationDate', $body)) {
            $expDate = $body['expirationDate'];
            $expDate = is_string($expDate) ? trim($expDate) : '';
            tlUser::setExpirationDate($db, $u->dbID, ($expDate === '') ? null : $expDate);
            // refresh in-memory object so the JSON response carries the
            // freshly written expiration_date (it is read at construction).
            $u->readFromDB($db);
        }
        logAuditEvent("User '$u->login' created", "CREATE", $u->dbID, "users");
        out(['status' => 'ok', 'item' => userToJSON($u)]);
    } else {
        http_response_code(400);
        $msg = 'Error creating user';
        if ($result == tlUser::E_LOGINLENGTH) $msg = 'Login cannot be empty';
        elseif ($result == tlUser::E_LOGINALREADYEXISTS) $msg = 'Login already exists';
        elseif ($result == tlUser::E_EMAILFORMAT) $msg = 'Invalid email';
        out(['status' => 'error', 'message' => $msg, 'code' => $result]);
    }
}

// Route: PUT /users/{id} - update user
if ($method === 'PUT' && isset($segments[0]) && is_numeric($segments[0])) {
    $id = intval($segments[0]);
    $u = tlUser::getByID($db, $id);
    if (!$u) { http_response_code(404); out(['status' => 'error', 'message' => 'User not found']); }

    $body = getBody();
    if (isset($body['firstName'])) $u->firstName = trim($body['firstName']);
    if (isset($body['lastName'])) $u->lastName = trim($body['lastName']);
    if (isset($body['email'])) $u->emailAddress = trim($body['email']);
    if (isset($body['globalRoleID'])) $u->globalRoleID = intval($body['globalRoleID']);
    if (isset($body['locale'])) $u->locale = $body['locale'];
    if (isset($body['active'])) $u->isActive = $body['active'] ? 1 : 0;
    if (isset($body['authentication'])) $u->authentication = $body['authentication'];
    if (!empty($body['password'])) $u->setPassword($body['password']);

    $result = $u->writeToDB($db);
    if ($result >= tl::OK) {
        // Legacy parity: lib/usermanagement/usersEdit.php:197 persists the
        // expiration date after every update; empty/null clears it to NULL.
        // noExpDateUsers logins (admin) are exempt, matching the hidden field.
        if (!$isNoExpDateUser($u->login) && array_key_exists('expirationDate', $body)) {
            $expDate = $body['expirationDate'];
            $expDate = is_string($expDate) ? trim($expDate) : '';
            tlUser::setExpirationDate($db, $u->dbID, ($expDate === '') ? null : $expDate);
            // refresh in-memory object so the JSON response carries the
            // freshly written expiration_date (it is read at construction).
            $u->readFromDB($db);
        }
        logAuditEvent("User '$u->login' updated", "UPDATE", $u->dbID, "users");
        out(['status' => 'ok', 'item' => userToJSON($u)]);
    } else {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Error updating user', 'code' => $result]);
    }
}

// Route: PUT /users/{id}/active - toggle active
if ($method === 'PUT' && isset($segments[0]) && is_numeric($segments[0]) && isset($segments[1]) && $segments[1] === 'active') {
    $id = intval($segments[0]);
    $u = tlUser::getByID($db, $id);
    if (!$u) { http_response_code(404); out(['status' => 'error', 'message' => 'User not found']); }

    if ($u->dbID == $userId) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Cannot disable yourself']);
    }

    $body = getBody();
    $active = $body['active'] ?? false;
    $newVal = $active ? 1 : 0;
    $db->exec_query("UPDATE users SET active = {$newVal} WHERE id = " . intval($u->dbID));
    logAuditEvent("User '$u->login' " . ($active ? 'enabled' : 'disabled'), "UPDATE", $u->dbID, "users");
    $item = userToJSON($u);
    $item['active'] = $newVal;
    out(['status' => 'ok', 'item' => $item]);
}

// Route: DELETE /users/{id} - soft delete (active=2)
if ($method === 'DELETE' && isset($segments[0]) && is_numeric($segments[0])) {
    $id = intval($segments[0]);
    $u = tlUser::getByID($db, $id);
    if (!$u) { http_response_code(404); out(['status' => 'error', 'message' => 'User not found']); }

    if ($u->dbID == $userId) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Cannot delete yourself']);
    }

    $db->exec_query("UPDATE users SET active = 2 WHERE id = " . intval($u->dbID));
    logAuditEvent("User '$u->login' deleted", "UPDATE", $u->dbID, "users");
    $item = userToJSON($u);
    $item['active'] = 2;
    out(['status' => 'ok', 'item' => $item]);
}

// Route: POST /users/{id}/reset-password - reset/generate a user's password
// (legacy parity: lib/usermanagement/usersEdit.php createNewPassword() +
// lib/functions/users.inc.php resetPassword()). Generates a new random
// password, then either emails it (password_reset_send_method =
// 'send_password_by_mail', requires a valid smtp_host) or returns it to be
// displayed on screen ('display_on_screen'). Respects the auth domain's
// allowPasswordManagement flag (usersEdit.tpl hides the button for external
// password management; the BFF re-checks it server-side).
if ($method === 'POST' && isset($segments[0]) && is_numeric($segments[0]) &&
    isset($segments[1]) && $segments[1] === 'reset-password') {
    $id = intval($segments[0]);
    $u = tlUser::getByID($db, $id);
    if (!$u) { http_response_code(404); out(['status' => 'error', 'message' => 'User not found']); }

    // Legacy parity: usersEdit.tpl:169-178 hides the Reset password form when
    // the user's effective auth method disables password management (e.g.
    // LDAP). resetPassword() re-checks this internally, but we refuse early
    // with a clear message instead of the legacy silent-OK quirk when $doIt
    // is false (resetPassword returns status=OK with empty password).
    $isExternal = tlUser::isPasswordMgtExternal($u->authentication);
    if ($isExternal) {
        http_response_code(400);
        out(['status' => 'error', 'code' => 'password_mgmt_external',
             'message' => lang_get('password_mgmt_is_external')]);
    }
    if (config_get('demoMode')) {
        http_response_code(400);
        out(['status' => 'error', 'code' => 'demo_mode',
             'message' => lang_get('demo_reset_password_disabled')]);
    }

    $sendMethod = config_get('password_reset_send_method');
    $passwordOnScreen = ($sendMethod === 'display_on_screen');

    // Try to validate the mail configuration like legacy createNewPassword()
    // (usersEdit.php:238-242): smtp_host must be a valid hostname unless the
    // new password is displayed on screen.
    $smtpHost = config_get('smtp_host');
    $validator = @new Zend_Validate_Hostname(Zend_Validate_Hostname::ALLOW_ALL);
    $smtpHostValid = @$validator->isValid($smtpHost) || $passwordOnScreen;

    if (!$smtpHostValid) {
        http_response_code(400);
        out(['status' => 'error', 'code' => 'invalid_smtp_hostname',
             'message' => lang_get('password_cannot_be_reseted_invalid_smtp_hostname')]);
    }

    $dummy = resetPassword($db, $id, $sendMethod);
    if ($dummy['status'] >= tl::OK) {
        logAuditEvent(TLS("audit_pwd_reset_requested", $u->login),
                      "PWD_RESET", $id, "users");
        $message = lang_get('password_reseted');
        $code = 'ok_sent';
        if ($passwordOnScreen) {
            $message = lang_get('password_set') . $dummy['password'];
            $code = 'ok_on_screen';
        }
        out(['status' => 'ok',
             'code' => $code,
             'message' => $message,
             'newPassword' => $passwordOnScreen ? $dummy['password'] : '',
             'passwordOnScreen' => $passwordOnScreen]);
    } else {
        http_response_code(400);
        $reason = $dummy['msg'] !== '' ? $dummy['msg'] : getUserErrorMessage($dummy['status']);
        $message = @sprintf(lang_get('password_cannot_be_reseted_reason'), $reason);
        out(['status' => 'error', 'code' => 'reset_failed',
             'message' => $message]);
    }
}

// Route: POST /users/{id}/generate-apikey - generate a new API key for a user
// and email it to them. Legacy parity: lib/usermanagement/usersEdit.php
// createNewAPIKey() (lines 274-318) validates SMTP, calls
// APIKey::addKeyForUser(), emails the key, logs audit_user_apikey_set.
// Gated on $tlCfg->api->enabled (config.inc.php:634), same as legacy
// usersEdit.tpl:351 which checks $tlCfg->api->enabled && $submitEnabled.
if ($method === 'POST' && isset($segments[0]) && is_numeric($segments[0]) &&
    isset($segments[1]) && $segments[1] === 'generate-apikey') {
    $tlCfg = $GLOBALS['tlCfg'] ?? null;
    $apiEnabled = false;
    if ($tlCfg && isset($tlCfg->api->enabled)) {
        $apiEnabled = (bool)$tlCfg->api->enabled;
    }
    if (!$apiEnabled) {
        http_response_code(403);
        out(['status' => 'error', 'code' => 'api_disabled',
             'message' => 'API key management is disabled']);
    }

    $id = intval($segments[0]);
    $u = tlUser::getByID($db, $id);
    if (!$u) { http_response_code(404); out(['status' => 'error', 'message' => 'User not found']); }

    // Validate SMTP hostname (same as legacy createNewAPIKey usersEdit.php:286-290)
    $validator = @new Zend_Validate_Hostname(Zend_Validate_Hostname::ALLOW_ALL);
    $smtp_host = config_get('smtp_host');
    if (!$validator->isValid($smtp_host)) {
        http_response_code(400);
        // Plain message: legacy lang key 'apikey_cannot_be_reseted_invalid_smtp_hostname'
        // is not defined in any strings.txt bundle (lang_get logs a "not localized"
        // WARNING event); the UI already maps code 'invalid_smtp_hostname' to the
        // localized i18n key user.apiKeyInvalidSmtp.
        out(['status' => 'error', 'code' => 'invalid_smtp_hostname',
             'message' => 'API key cannot be generated. Reason: SMTP hostname seems to be invalid.']);
    }

    $APIKey = new APIKey();
    $result = $APIKey->addKeyForUser($u->dbID);
    if ($result >= tl::OK) {
        logAuditEvent(TLS("audit_user_apikey_set", $u->login), "CREATE", $u->login, "users");
        // Email the new key to the user (legacy usersEdit.php:305-310). Legacy
        // silences the send with @email_send(); we mirror that by never letting
        // a mail-delivery failure turn a successfully generated key into a
        // hard error (the operation result stays OK). Delivery problems go to
        // the PHP error log only - never to the Event Viewer (no new WARNING
        // events), matching legacy's silent @ suppression.
        $ak = $APIKey->getAPIKey($u->dbID);
        $msgBody = lang_get('your_apikey_is') . "\n\n" . $ak .
                   "\n\n" . lang_get('contact_admin');
        try {
            @email_send(config_get('from_email'),
                        $u->emailAddress, lang_get('mail_apikey_subject'), $msgBody);
        } catch (Throwable $e) {
            error_log('API key mail delivery failed for user ' . $u->login . ': ' . $e->getMessage());
        }
        out(['status' => 'ok',
             'message' => lang_get('apikey_by_mail')]);
    } else {
        http_response_code(500);
        out(['status' => 'error', 'code' => 'apikey_generation_failed',
             'message' => 'Failed to generate API key']);
    }
}

http_response_code(404);
out(['status' => 'error', 'message' => 'Not found']);
