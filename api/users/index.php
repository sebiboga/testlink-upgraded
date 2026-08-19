<?php
/**
 * Users BFF API
 * URL: /api/users/
 * Plain PHP, no framework, no compilation
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
require_once('users.inc.php');

doSessionStart();

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

$path = $_SERVER['PATH_INFO'] ?? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = preg_replace('#^/api/users(/index\.php)?#', '', $path);
$path = '/' . trim($path, '/');
$method = $_SERVER['REQUEST_METHOD'];
$segments = array_values(array_filter(explode('/', $path)));

function out($data) { echo json_encode($data); exit; }
function getParam($key, $default = null) { return $_GET[$key] ?? $default; }
function getBody() { return json_decode(file_get_contents('php://input'), true) ?? []; }

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
        'active' => $u->isActive ? true : false,
        'globalRoleID' => intval($u->globalRoleID),
        'globalRoleName' => $roleName,
        'authentication' => $u->authentication ?? '',
        'expirationDate' => $u->expiration_date ?? '',
        'creation_ts' => $u->creation_ts ?? '',
    ];
}

// Route: GET /users - list all users
if ($method === 'GET' && ($path === '/' || $path === '' || $path === '/index.php')) {
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
            $items[] = [
                'id' => intval($row['id']),
                'login' => $row['login'],
                'firstName' => $row['first'],
                'lastName' => $row['last'],
                'email' => $row['email'],
                'locale' => $row['locale'],
                'active' => intval($row['active']) === 1,
                'globalRoleID' => intval($row['role_id']),
                'globalRoleName' => $row['roleName'] ?? '',
                'authentication' => $row['auth_method'] ?? '',
                'expirationDate' => $row['expiration_date'] ?? '',
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
    $u->setPassword(md5($body['password'] ?? ''));

    $result = $u->writeToDB($db);
    if ($result >= tl::OK) {
        logAuditEvent("User '$u->login' created", "CREATE", $u->dbID, "users");
        out(['status' => 'ok', 'item' => userToJSON($u)]);
    } else {
        http_response_code(400);
        $msg = 'Error creating user';
        if ($result == tlUser::E_LOGINEMPTY) $msg = 'Login cannot be empty';
        elseif ($result == tlUser::E_LOGINALREADYEXISTS) $msg = 'Login already exists';
        elseif ($result == tlUser::E_EMAILINVALID) $msg = 'Invalid email';
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
    if (!empty($body['password'])) $u->setPassword(md5($body['password']));

    $result = $u->writeToDB($db);
    if ($result >= tl::OK) {
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
    $u->setActive($db, $active ? 1 : 0);
    logAuditEvent("User '$u->login' " . ($active ? 'enabled' : 'disabled'), "UPDATE", $u->dbID, "users");
    out(['status' => 'ok', 'item' => userToJSON($u)]);
}

// Route: DELETE /users/{id} - disable user (soft delete)
if ($method === 'DELETE' && isset($segments[0]) && is_numeric($segments[0])) {
    $id = intval($segments[0]);
    $u = tlUser::getByID($db, $id);
    if (!$u) { http_response_code(404); out(['status' => 'error', 'message' => 'User not found']); }

    if ($u->dbID == $userId) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Cannot disable yourself']);
    }

    $u->setActive($db, 0);
    logAuditEvent("User '$u->login' disabled", "UPDATE", $u->dbID, "users");
    out(['status' => 'ok', 'item' => userToJSON($u)]);
}

http_response_code(404);
out(['status' => 'error', 'message' => 'Not found']);
