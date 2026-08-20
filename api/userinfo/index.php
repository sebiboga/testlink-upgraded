<?php
/**
 * User Info BFF API
 * URL: /api/userinfo/
 * Handles: profile data, password change, API key, login history
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
$path = preg_replace('#^/api/userinfo(/index\.php)?#', '', $path);
$path = '/' . trim($path, '/');
$method = $_SERVER['REQUEST_METHOD'];
$segments = array_values(array_filter(explode('/', $path)));

function out($data) { echo json_encode($data); exit; }
function getBody() { return json_decode(file_get_contents('php://input'), true) ?? []; }

function userProfile(tlUser $u, $db) {
    $roleName = '';
    if ($u->globalRole) {
        $roleName = $u->globalRole->getDisplayName();
    } elseif ($u->globalRoleID) {
        $role = tlRole::getByID($db, $u->globalRoleID, tlRole::TLOBJ_O_GET_DETAIL_MINIMUM);
        if ($role) $roleName = $role->getDisplayName();
    }
    return [
        'id' => intval($u->dbID),
        'login' => $u->login,
        'firstName' => $u->firstName,
        'lastName' => $u->lastName,
        'email' => $u->emailAddress,
        'locale' => $u->locale,
        'globalRoleName' => $roleName,
        'apiKey' => $u->userApiKey ?? 'none',
        'authentication' => $u->authentication ?? '',
    ];
}

// Route: GET /userinfo - get current user profile
if ($method === 'GET' && ($path === '/' || $path === '' || $path === '/index.php')) {
    out(['status' => 'ok', 'item' => userProfile($user, $db)]);
}

// Route: GET /userinfo/locales - available locales
//
// Single source of truth for every locale dropdown in the app: the profile
// form (PHP/Smarty pages) and TLi18n's switcher (modern JS pages) both render
// this list, so they can no longer drift apart.
//
// 'short' is the i18n bundle code (en_GB -> en); 'hasBundle'/'hasStrings' say
// whether the modern JS bundle and the PHP strings file actually exist, so a
// caller can tell a fully translated locale from one that falls back.
if ($method === 'GET' && isset($segments[0]) && $segments[0] === 'locales') {
    $locales = config_get('locales');
    $items = [];
    if (is_array($locales)) {
        $root = realpath(dirname(__FILE__) . '/../..');
        foreach ($locales as $k => $v) {
            $short = substr($k, 0, 2);
            $items[] = [
                'code' => $k,
                'name' => html_entity_decode($v, ENT_QUOTES, 'UTF-8'),
                'short' => $short,
                'hasBundle' => is_file($root . '/gui/templates/i18n/' . $short . '.json'),
                'hasStrings' => is_file($root . '/locale/' . $k . '/strings.txt')
            ];
        }
    }
    out(['status' => 'ok', 'items' => $items]);
}

// Route: GET /userinfo/login-history - last 10 successful + failed logins
if ($method === 'GET' && isset($segments[0]) && $segments[0] === 'login-history') {
    global $g_tlLogger;
    $failed = $g_tlLogger->getAuditEventsFor($userId, 'users', 'LOGIN_FAILED', 10);
    $ok = $g_tlLogger->getAuditEventsFor($userId, 'users', 'LOGIN', 10);

    $formatEvents = function($events) {
        $result = [];
        if ($events) {
            foreach ($events as $e) {
                $desc = '';
                if (is_object($e->description) && isset($e->description->helper)) {
                    $label = $e->description->helper->label ?? '';
                    $params = $e->description->helper->params ?? [];
                    $desc = $label;
                    if (!empty($params)) {
                        $desc .= ' (' . implode(', ', $params) . ')';
                    }
                } elseif (is_string($e->description)) {
                    $desc = $e->description;
                }
                $result[] = [
                    'timestamp' => $e->timestamp ?? '',
                    'description' => $desc,
                ];
            }
        }
        return $result;
    };

    out([
        'status' => 'ok',
        'successful' => $formatEvents($ok),
        'failed' => $formatEvents($failed),
    ]);
}

// Route: PUT /userinfo - update profile (firstName, lastName, email, locale)
if ($method === 'PUT' && ($path === '/' || $path === '' || $path === '/index.php')) {
    $body = getBody();
    if (isset($body['firstName'])) $user->firstName = trim($body['firstName']);
    if (isset($body['lastName'])) $user->lastName = trim($body['lastName']);
    if (isset($body['email'])) $user->emailAddress = trim($body['email']);
    if (isset($body['locale'])) $user->locale = $body['locale'];

    $result = $user->writeToDB($db);
    if ($result >= tl::OK) {
        logAuditEvent("User '$user->login' profile updated", "SAVE", $user->dbID, "users");
        $_SESSION['currentUser'] = $user;
        out(['status' => 'ok', 'item' => userProfile($user, $db), 'message' => 'Profile updated']);
    } else {
        http_response_code(400);
        $msg = 'Error updating profile';
        if ($result == tlUser::E_EMAILINVALID) $msg = 'Invalid email address';
        out(['status' => 'error', 'message' => $msg, 'code' => $result]);
    }
}

// Route: PUT /userinfo/password - change password
if ($method === 'PUT' && isset($segments[0]) && $segments[0] === 'password') {
    $body = getBody();
    $oldPassword = $body['oldPassword'] ?? '';
    $newPassword = $body['newPassword'] ?? '';

    if (empty($oldPassword)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Old password is required']);
    }
    if (empty($newPassword)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'New password cannot be empty']);
    }
    if (strlen($newPassword) < 1) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Password too short']);
    }

    $check = $user->comparePassword($db, $oldPassword);
    if ($check !== tl::OK) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Old password is incorrect']);
    }

    $user->setPassword($newPassword, $user->authentication);
    $user->writePasswordToDB($db);
    logAuditEvent("User '$user->login' password changed", "SAVE", $user->dbID, "users");
    out(['status' => 'ok', 'message' => 'Password changed successfully']);
}

// Route: POST /userinfo/apikey - generate new API key
if ($method === 'POST' && isset($segments[0]) && $segments[0] === 'apikey') {
    $APIKey = new APIKey();
    $result = $APIKey->addKeyForUser($userId);
    if ($result < tl::OK) {
        http_response_code(500);
        out(['status' => 'error', 'message' => 'Failed to generate API key']);
    }
    logAuditEvent("User '$user->login' API key regenerated", "CREATE", $user->login, "users");

    $user->readFromDB($db);
    out(['status' => 'ok', 'apiKey' => $user->userApiKey ?? 'none', 'message' => 'API key generated']);
}

http_response_code(404);
out(['status' => 'error', 'message' => 'Not found']);
