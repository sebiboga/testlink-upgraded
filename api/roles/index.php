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

$currentUser = tlUser::getByID($db, $userId);
if (is_null($currentUser)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'User not found']);
    exit;
}

// Legacy parity: lib/usermanagement/rolesView.php checkRights() and
// rolesEdit.php -> $user->hasRight($db,"role_management"). Every role
// management entry point (list/view/create/edit/duplicate/delete/assign)
// requires the role_management right. Without it the BFF refuses ANY route
// (403), mirroring the legacy access-denied behavior for unauthorized users.
if (!$currentUser->hasRight($db, 'role_management')) {
    logAuditEvent(TLS("audit_security_user_right_missing",
                      $currentUser->login,
                      basename($_SERVER['SCRIPT_NAME']),
                      $_SERVER['REQUEST_METHOD']),
                  'AUTH', $currentUser->dbID, 'roles');
    http_response_code(403);
    out(['status' => 'error', 'message' => 'no_permissions_for_action', 'right' => 'role_management']);
}

$path = $_SERVER['PATH_INFO'] ?? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = preg_replace('#^/api/roles(/index\.php)?#', '', $path);
$path = '/' . trim($path, '/');
$method = $_SERVER['REQUEST_METHOD'];
$segments = array_values(array_filter(explode('/', $path)));

function out($data) { echo json_encode($data); exit; }
function getParam($key, $default = null) { return $_GET[$key] ?? $default; }
function getBody() { return json_decode(file_get_contents('php://input'), true) ?? []; }

// demoMode: legacy gates role-management writes in the template only
// (gui/templates/dashio/usermanagement/rolesEdit.tpl:185-205 - on doUpdate the
// Save button is replaced by the demo_update_role_disabled note). Server-side
// enforcement lives here so no BFF write can bypass the button removal; mirror
// of api/users demoModeBlockedWrite() used for User Management (issue #887).
function demoModeBlockedWrite($phpMsgKey = 'demo_update_role_disabled', $jsMsgKey = 'role.demoUpdateDisabled') {
    if (!config_get('demoMode')) {
        return false;
    }
    http_response_code(403);
    out([
        'status' => 'error',
        'code' => 'demo_mode',
        'messageKey' => $jsMsgKey,
        'message' => lang_get($phpMsgKey, 'en_GB'),
    ]);
    exit;
}

// Legacy parity: lib/usermanagement/rolesEdit.php:292-297
function generateUniqueName($s) {
    return substr($s . ' - Copy - ' . substr(sha1(mt_rand()), 0, 50), 0, 100);
}

// Legacy parity: lib/functions/roles.inc.php getRoleErrorMessage() (440-466)
// maps tlRole write error codes to the legacy localized lang keys
// (error_duplicate_rolename / error_role_no_rolename / error_role_no_rights /
// error_role_not_updated). The BFF exposes the client-side i18n key so the UI
// resolves a localized message instead of ad-hoc English (issue #904).
function roleErrorKey($code) {
    switch ($code) {
        case tlRole::E_NAMEALREADYEXISTS:
            return 'role.error.nameExists';
        case tlRole::E_NAMELENGTH:
            return 'role.error.noRoleName';
        case tlRole::E_EMPTYROLE:
            return 'role.error.noRights';
        case tlRole::E_DBERROR:
        default:
            return 'role.error.notUpdated';
    }
}

function roleToJSON(tlRole $r) {
    return [
        'id' => intval($r->dbID),
        'name' => $r->getDisplayName(),
        'description' => $r->description ?? '',
        // Legacy parity: lib/usermanagement/rolesEdit.php:222 roleCanBeEdited =
        // (roleid != TL_ROLES_ADMIN) and rolesEdit.tpl:193 Save rendered only
        // when role->dbID != TL_ROLES_NO_RIGHTS. So admin (8) is fully
        // read-only and <no rights> (3) cannot be saved.
        'canEdit' => intval($r->dbID) != TL_ROLES_ADMIN && intval($r->dbID) != TL_ROLES_NO_RIGHTS,
        'isAdmin' => intval($r->dbID) == TL_ROLES_ADMIN,
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
    foreach ($roles as $r) {
        if ($r->dbID == TL_ROLES_INHERITED) continue;
        $json = roleToJSON($r);
        $json['isSystem'] = intval($r->dbID) <= TL_LAST_SYSTEM_ROLE;
        $items[] = $json;
    }
    // Legacy parity: lib/usermanagement/rolesEdit.php:260 the show-event-history
    // info icon next to the role name is rendered only when the current user
    // holds mgt_view_events (plain user right, no tproject context).
    out([
        'status' => 'ok',
        'items' => $items,
        'total' => count($items),
        'rights' => ['canViewEvents' => (bool)$currentUser->hasRight($db, 'mgt_view_events')],
        // Legacy parity: the whole role edit screen is read-only in demo mode
        // (rolesEdit.tpl:185-205), so the UI must know the demo state to gate
        // the toolbar, the row actions and the modal save button.
        'demoMode' => (bool)config_get('demoMode'),
    ]);
}

// Route: GET /roles/meta/rights - all available rights
if ($method === 'GET' && isset($segments[0]) && $segments[0] === 'meta' && isset($segments[1]) && $segments[1] === 'rights') {
    $rights = getAllRights($db);
    out(['status' => 'ok', 'items' => $rights]);
}

// Route: GET /roles/meta/grants - user mgmt grant flags (mirror of legacy
// getGrantsForUserMgmt()). Used by the modernized rolesView to gate UI
// affordances per user right. Legacy parity: lib/usermanagement/rolesEdit.php:260
// sets grants->mgt_view_events separately so the edit screen can render the
// "Show event history" icon only when the right is granted (rolesEdit.tpl:68).
if ($method === 'GET' && isset($segments[0]) && $segments[0] === 'meta' && isset($segments[1]) && $segments[1] === 'grants') {
    $tprojectID = isset($_SESSION['testprojectID']) ? intval($_SESSION['testprojectID']) : 0;
    $tplanID = isset($_SESSION['testplanID']) ? intval($_SESSION['testplanID']) : 0;
    $grants = getGrantsForUserMgmt($db, $currentUser, $tprojectID, $tplanID);
    $grants->mgt_view_events = ($currentUser->hasRight($db, 'mgt_view_events') === 'yes') ? 'yes' : 'no';
    out(['status' => 'ok', 'grants' => $grants]);
}

// Route: GET /roles/{id} - single role. The count($segments) === 1 guard is
// REQUIRED (Issue #902): without it this route shadows the /roles/{id}/users
// route below (the old code matched any GET with a numeric first segment, so
// GET /roles/5/users returned the role object instead of the user list).
if ($method === 'GET' && isset($segments[0]) && is_numeric($segments[0]) && count($segments) === 1) {
    $r = tlRole::getByID($db, intval($segments[0]));
    if (!$r) { http_response_code(404); out(['status' => 'error', 'message' => 'Role not found']); }
    out([
        'status' => 'ok',
        'item' => roleToJSON($r),
        'rights' => ['canViewEvents' => (bool)$currentUser->hasRight($db, 'mgt_view_events')],
    ]);
}

// Route: POST /roles - create role
if ($method === 'POST' && empty($segments)) {
    demoModeBlockedWrite();
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
        out(['status' => 'ok', 'item' => roleToJSON($r), 'feedback_key' => 'role_created']);
    } else {
        http_response_code(400);
        $msg = 'Error creating role';
        if ($result == tlRole::E_NAMEALREADYEXISTS) $msg = 'Role name already exists';
        elseif ($result == tlRole::E_EMPTYROLE) $msg = 'Role must have at least one right';
        out(['status' => 'error', 'message' => $msg, 'messageKey' => roleErrorKey($result), 'code' => $result]);
    }
}

// Route: PUT /roles/{id} - update role
if ($method === 'PUT' && isset($segments[0]) && is_numeric($segments[0]) && count($segments) === 1) {
    demoModeBlockedWrite();
    $id = intval($segments[0]);

    // Legacy parity: rolesEdit.php:222 roleCanBeEdited = (roleid !=
    // TL_ROLES_ADMIN) and rolesEdit.tpl:193-201 hides the Save button for
    // TL_ROLES_NO_RIGHTS. Admin (8) is read-only and <no rights> (3) can
    // never be saved — reject any write attempt to either.
    if ($id == TL_ROLES_ADMIN || $id == TL_ROLES_NO_RIGHTS) {
        logAuditEvent("Forbidden update attempt on protected role #{$id}", 'AUTH', $id, 'roles');
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Cannot edit system role', 'messageKey' => 'role.editLocked', 'id' => $id]);
    }

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
        out(['status' => 'ok', 'item' => roleToJSON($r), 'feedback_key' => 'role_updated']);
    } else {
        http_response_code(400);
        $msg = 'Error updating role';
        if ($result == tlRole::E_NAMEALREADYEXISTS) $msg = 'Role name already exists';
        elseif ($result == tlRole::E_EMPTYROLE) $msg = 'Role must have at least one right';
        out(['status' => 'error', 'message' => $msg, 'messageKey' => roleErrorKey($result), 'code' => $result]);
    }
}

// Route: DELETE /roles/{id} - delete role
if ($method === 'DELETE' && isset($segments[0]) && is_numeric($segments[0]) && count($segments) === 1) {
    demoModeBlockedWrite();
    $id = intval($segments[0]);
    if ($id <= TL_LAST_SYSTEM_ROLE) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Cannot delete system role', 'messageKey' => 'role.systemDeleteDenied']);
    }

    $r = tlRole::getByID($db, $id);
    if (!$r) { http_response_code(404); out(['status' => 'error', 'message' => 'Role not found']); }

    $result = $r->deleteFromDB($db);
    if ($result >= tl::OK) {
        logAuditEvent("Role '$r->name' deleted", "DELETE", $id, "roles");
        out(['status' => 'ok', 'feedback_key' => 'role_deleted']);
    } else {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Error deleting role', 'messageKey' => 'role.error.deleted', 'code' => $result]);
    }
}

// Route: POST /roles/{id}/duplicate - duplicate a role with a unique copy name
if ($method === 'POST' && isset($segments[0]) && is_numeric($segments[0]) && isset($segments[1]) && $segments[1] === 'duplicate') {
    demoModeBlockedWrite();
    $id = intval($segments[0]);
    $r = tlRole::getByID($db, $id, tlRole::TLOBJ_O_GET_DETAIL_FULL);
    if (!$r) { http_response_code(404); out(['status' => 'error', 'message' => 'Role not found']); }

    // Legacy parity: lib/usermanagement/rolesEdit.php:109-114,125-128,292-297
    // Reset dbID so writeToDB does INSERT; generate unique name.
    $r->dbID = null;
    $r->name = generateUniqueName($r->name);

    $result = $r->writeToDB($db);
    if ($result >= tl::OK) {
        logAuditEvent("Role '{$r->name}' created", 'CREATE', $r->dbID, 'roles');
        out(['status' => 'ok', 'item' => roleToJSON($r), 'feedback_key' => 'role_duplicated']);
    } else {
        http_response_code(400);
        $msg = 'Error duplicating role';
        if ($result == tlRole::E_NAMEALREADYEXISTS) $msg = 'Role name already exists';
        elseif ($result == tlRole::E_EMPTYROLE) $msg = 'Role must have at least one right';
        out(['status' => 'error', 'message' => $msg, 'messageKey' => roleErrorKey($result), 'code' => $result]);
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

    // Legacy parity: dashio rolesView.tpl:61. When a role with assigned users is
    // deleted, deleteFromDB remaps them to the configured replacement role
    // (tlRole.class.php:63 replacementRoleID = config_get('role_replace_for_deleted_roles')).
    // Expose that role here so the confirm modal can render the same "users will
    // be reset to <role>" warning the legacy page shows before the explicit
    // confirmDelete action.
    $replacement = null;
    $replacementId = intval(config_get('role_replace_for_deleted_roles'));
    if ($replacementId > 0) {
        $replacementRole = tlRole::getByID($db, $replacementId, tlRole::TLOBJ_O_GET_DETAIL_MINIMUM);
        if ($replacementRole) {
            $replacement = ['id' => intval($replacementRole->dbID), 'name' => $replacementRole->getDisplayName()];
        }
    }

    usort($items, function($a, $b) { return strcmp($a['login'], $b['login']); });
    out(['status' => 'ok', 'items' => $items, 'replacementRole' => $replacement]);
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
    // demoMode: role assignments are management writes; legacy demo deployments
    // forbid role maintenance entirely (rolesEdit.tpl:185-205).
    demoModeBlockedWrite();
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
    // demoMode: role assignments are management writes; legacy demo deployments
    // forbid role maintenance entirely (rolesEdit.tpl:185-205).
    demoModeBlockedWrite();
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
