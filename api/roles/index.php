<?php
/**
 * Roles BFF API
 * URL: /api/roles/
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

$currentUser = tlUser::getByID($db, $userId);
if (is_null($currentUser)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'User not found']);
    exit;
}

$path = $_SERVER['PATH_INFO'] ?? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = preg_replace('#^/api/roles(/index\.php)?#', '', $path);
$path = '/' . trim($path, '/');
$method = $_SERVER['REQUEST_METHOD'];
$segments = array_values(array_filter(explode('/', $path)));

function out($data) { echo json_encode($data); exit; }
function getParam($key, $default = null) { return $_GET[$key] ?? $default; }
function getBody() { return json_decode(file_get_contents('php://input'), true) ?? []; }

function roleToJSON(tlRole $r) {
    return [
        'id' => intval($r->dbID),
        'name' => $r->getDisplayName(),
        'description' => $r->description ?? '',
        'rights' => $r->rights ? array_map(function($right) {
            return ['id' => intval($right->dbID), 'name' => $right->name];
        }, $r->rights) : [],
    ];
}

function getAllRights($db) {
    $tables = tlObject::getDBTables('rights');
    $sql = "SELECT id, description FROM {$tables['rights']} ORDER BY id ASC";
    return $db->get_recordset($sql);
}

// Route: GET /roles - list all roles
if ($method === 'GET' && empty($segments)) {
    $roles = tlRole::getAll($db, null, null, null, tlRole::TLOBJ_O_GET_DETAIL_FULL);
    $items = [];
    $systemRoleId = 3;
    foreach ($roles as $r) {
        if ($r->dbID == TL_ROLES_INHERITED) continue;
        $json = roleToJSON($r);
        $json['isSystem'] = intval($r->dbID) <= intval($systemRoleId);
        $items[] = $json;
    }
    out(['status' => 'ok', 'items' => $items, 'total' => count($items)]);
}

// Route: GET /roles/meta/rights - all available rights
if ($method === 'GET' && isset($segments[0]) && $segments[0] === 'meta' && isset($segments[1]) && $segments[1] === 'rights') {
    $rights = getAllRights($db);
    out(['status' => 'ok', 'items' => $rights]);
}

// Route: GET /roles/{id} - single role
if ($method === 'GET' && isset($segments[0]) && is_numeric($segments[0])) {
    $r = tlRole::getByID($db, intval($segments[0]));
    if (!$r) { http_response_code(404); out(['status' => 'error', 'message' => 'Role not found']); }
    out(['status' => 'ok', 'item' => roleToJSON($r)]);
}

// Route: POST /roles - create role
if ($method === 'POST' && empty($segments)) {
    $body = getBody();
    $r = new tlRole();
    $r->name = trim($body['name'] ?? '');
    $r->description = trim($body['description'] ?? '');

    if (!empty($body['rightIDs']) && is_array($body['rightIDs'])) {
        foreach ($body['rightIDs'] as $rid) {
            $right = new tlRight(intval($rid));
            $right->readFromDB($db);
            if ($right->dbID) { $r->rights[] = $right; }
        }
    }

    $result = $r->writeToDB($db);
    if ($result >= tl::OK) {
        logAuditEvent("Role '$r->name' created", "CREATE", $r->dbID, "roles");
        out(['status' => 'ok', 'item' => roleToJSON($r)]);
    } else {
        http_response_code(400);
        $msg = 'Error creating role';
        if ($result == tlRole::E_NAMEALREADYEXISTS) $msg = 'Role name already exists';
        elseif ($result == tlRole::E_EMPTYROLE) $msg = 'Role must have at least one right';
        out(['status' => 'error', 'message' => $msg, 'code' => $result]);
    }
}

// Route: PUT /roles/{id} - update role
if ($method === 'PUT' && isset($segments[0]) && is_numeric($segments[0])) {
    $id = intval($segments[0]);
    $r = tlRole::getByID($db, $id);
    if (!$r) { http_response_code(404); out(['status' => 'error', 'message' => 'Role not found']); }

    $body = getBody();
    if (isset($body['name'])) $r->name = trim($body['name']);
    if (isset($body['description'])) $r->description = trim($body['description']);

    if (isset($body['rightIDs']) && is_array($body['rightIDs'])) {
        $r->rights = [];
        foreach ($body['rightIDs'] as $rid) {
            $right = new tlRight(intval($rid));
            $right->readFromDB($db);
            if ($right->dbID) { $r->rights[] = $right; }
        }
    }

    $result = $r->writeToDB($db);
    if ($result >= tl::OK) {
        logAuditEvent("Role '$r->name' updated", "UPDATE", $r->dbID, "roles");
        out(['status' => 'ok', 'item' => roleToJSON($r)]);
    } else {
        http_response_code(400);
        $msg = 'Error updating role';
        if ($result == tlRole::E_NAMEALREADYEXISTS) $msg = 'Role name already exists';
        elseif ($result == tlRole::E_EMPTYROLE) $msg = 'Role must have at least one right';
        out(['status' => 'error', 'message' => $msg, 'code' => $result]);
    }
}

// Route: DELETE /roles/{id} - delete role
if ($method === 'DELETE' && isset($segments[0]) && is_numeric($segments[0])) {
    $id = intval($segments[0]);
    $systemRoleId = 3;
    if ($id <= $systemRoleId) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Cannot delete system role']);
    }

    $r = tlRole::getByID($db, $id);
    if (!$r) { http_response_code(404); out(['status' => 'error', 'message' => 'Role not found']); }

    $result = $r->deleteFromDB($db);
    if ($result >= tl::OK) {
        logAuditEvent("Role '$r->name' deleted", "DELETE", $id, "roles");
        out(['status' => 'ok']);
    } else {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Error deleting role']);
    }
}

// Route: GET /roles/{id}/users - get users with this role (for delete confirmation)
if ($method === 'GET' && isset($segments[0]) && is_numeric($segments[0]) && isset($segments[1]) && $segments[1] === 'users') {
    $id = intval($segments[0]);
    $r = tlRole::getByID($db, $id, tlRole::TLOBJ_O_GET_DETAIL_MINIMUM);
    if (!$r) { http_response_code(404); out(['status' => 'error', 'message' => 'Role not found']); }

    $items = [];
    try {
        $users = $r->getAllUsersWithRole($db);
        if ($users) {
            foreach ($users as $u) {
                $items[] = ['id' => intval($u->dbID), 'login' => $u->login, 'name' => $u->getDisplayName()];
            }
        }
    } catch (\Throwable $e) {}
    out(['status' => 'ok', 'items' => $items]);
}

// Route: GET /roles/meta/tproject-roles?tproject_id=X - get test project role assignments
if ($method === 'GET' && isset($segments[0]) && $segments[0] === 'meta' && isset($segments[1]) && $segments[1] === 'tproject-roles') {
    $tproject_id = intval(getParam('tproject_id'));
    $tprojectMgr = new testproject($db);

    $roles = tlRole::getAll($db, null, null, null, tlRole::TLOBJ_O_GET_DETAIL_MINIMUM);
    $roleOpts = [];
    foreach ($roles as $r) {
        $roleOpts[] = ['id' => intval($r->dbID), 'name' => $r->getDisplayName()];
    }

    $projects = $tprojectMgr->get_accessible_for_user($userId, ['output' => 'map_of_map', 'order_by' => 'ORDER BY name ASC']);
    $projectOpts = [];
    if ($projects) {
        foreach ($projects as $pId => $p) {
            $pId = intval($pId);
            $pName = $p['name'] ?? $p->name ?? '';
            if ($pId) $projectOpts[] = ['id' => $pId, 'name' => $pName];
        }
    }

    $items = [];
    if ($tproject_id) {
        $users = tlUser::getAll($db, "WHERE active=1", null, null, tlUser::TLOBJ_O_GET_DETAIL_MINIMUM);
        if ($users) {
            foreach ($users as $u) {
                $u->readTestProjectRoles($db, $tproject_id);
                $assignedRoleId = 0;
                if (isset($u->tprojectRoles[$tproject_id])) {
                    $assignedRoleId = intval($u->tprojectRoles[$tproject_id]->dbID);
                }
                $items[] = [
                    'id' => intval($u->dbID),
                    'login' => $u->login,
                    'name' => $u->getDisplayName(),
                    'roleID' => $assignedRoleId,
                ];
            }
        }
    }

    out(['status' => 'ok', 'items' => $items, 'roles' => $roleOpts, 'projects' => $projectOpts]);
}

// Route: PUT /roles/tproject-roles - update test project role assignments
if ($method === 'PUT' && isset($segments[0]) && $segments[0] === 'tproject-roles') {
    $body = getBody();
    $tproject_id = intval($body['tproject_id'] ?? 0);
    if (!$tproject_id) { http_response_code(400); out(['status' => 'error', 'message' => 'Missing tproject_id']); }

    $assignments = $body['assignments'] ?? [];
    $tprojectMgr = new testproject($db);
    $userIds = array_map('intval', array_keys($assignments));
    $tprojectMgr->deleteUserRoles($tproject_id, $userIds);

    foreach ($assignments as $uid => $rid) {
        $uid = intval($uid);
        $rid = intval($rid);
        if ($rid > 0) {
            $tprojectMgr->addUserRole($uid, $tproject_id, $rid);
        }
    }
    logAuditEvent("Test project roles updated for project #{$tproject_id}", "UPDATE", $tproject_id, "testprojects");
    out(['status' => 'ok']);
}

// Route: GET /roles/meta/tplan-roles?tproject_id=X&tplan_id=Y - get test plan role assignments
if ($method === 'GET' && isset($segments[0]) && $segments[0] === 'meta' && isset($segments[1]) && $segments[1] === 'tplan-roles') {
    $tproject_id = intval(getParam('tproject_id'));
    $tplan_id = intval(getParam('tplan_id'));

    $tprojectMgr = new testproject($db);

    $activeTestplans = $tprojectMgr->get_all_testplans($tproject_id, ['plan_status' => 1]);
    $planOpts = [];
    if ($activeTestplans) {
        foreach ($activeTestplans as $tp) {
            $planOpts[] = ['id' => intval($tp['id']), 'name' => $tp['name']];
        }
    }

    $roles = tlRole::getAll($db, null, null, null, tlRole::TLOBJ_O_GET_DETAIL_MINIMUM);
    $roleOpts = [];
    foreach ($roles as $r) {
        $roleOpts[] = ['id' => intval($r->dbID), 'name' => $r->getDisplayName()];
    }

    $projects = $tprojectMgr->get_accessible_for_user($userId, ['output' => 'map_of_map', 'order_by' => 'ORDER BY name ASC']);
    $projectOpts = [];
    if ($projects) {
        foreach ($projects as $pId => $p) {
            $pId = intval($pId);
            $pName = $p['name'] ?? $p->name ?? '';
            if ($pId) $projectOpts[] = ['id' => $pId, 'name' => $pName];
        }
    }

    $items = [];
    if ($tplan_id) {
        $users = tlUser::getAll($db, "WHERE active=1", null, null, tlUser::TLOBJ_O_GET_DETAIL_MINIMUM);
        if ($users) {
            foreach ($users as $u) {
                $u->readTestProjectRoles($db, $tproject_id);
                $u->readTestPlanRoles($db, $tplan_id);
                $assignedRoleId = 0;
                $inheritedRoleId = 0;
                if (isset($u->tplanRoles[$tplan_id])) {
                    $assignedRoleId = intval($u->tplanRoles[$tplan_id]->dbID);
                }
                if (isset($u->tprojectRoles[$tproject_id])) {
                    $inheritedRoleId = intval($u->tprojectRoles[$tproject_id]->dbID);
                }
                $inheritedRoleName = 'No';
                if ($inheritedRoleId > 0 && $inheritedRoleId != TL_ROLES_INHERITED) {
                    $inheritedRole = tlRole::getByID($db, $inheritedRoleId, tlRole::TLOBJ_O_GET_DETAIL_MINIMUM);
                    $inheritedRoleName = $inheritedRole ? $inheritedRole->getDisplayName() : '-';
                }
                $items[] = [
                    'id' => intval($u->dbID),
                    'login' => $u->login,
                    'name' => $u->getDisplayName(),
                    'roleID' => $assignedRoleId,
                    'inheritedRoleID' => $inheritedRoleId,
                    'inheritedRoleName' => $inheritedRoleName,
                ];
            }
        }
    }

    out(['status' => 'ok', 'items' => $items, 'roles' => $roleOpts, 'plans' => $planOpts, 'projects' => $projectOpts]);
}

// Route: PUT /roles/tplan-roles - update test plan role assignments
if ($method === 'PUT' && isset($segments[0]) && $segments[0] === 'tplan-roles') {
    $body = getBody();
    $tplan_id = intval($body['tplan_id'] ?? 0);
    if (!$tplan_id) { http_response_code(400); out(['status' => 'error', 'message' => 'Missing tplan_id']); }

    $assignments = $body['assignments'] ?? [];
    $tplanMgr = new testplan($db);
    $userIds = array_map('intval', array_keys($assignments));
    $tplanMgr->deleteUserRoles($tplan_id, $userIds);

    foreach ($assignments as $uid => $rid) {
        $uid = intval($uid);
        $rid = intval($rid);
        if ($rid > 0) {
            $tplanMgr->addUserRole($uid, $tplan_id, $rid);
        }
    }
    logAuditEvent("Test plan roles updated for plan #{$tplan_id}", "UPDATE", $tplan_id, "testplans");
    out(['status' => 'ok']);
}

http_response_code(404);
out(['status' => 'error', 'message' => 'Not found']);
