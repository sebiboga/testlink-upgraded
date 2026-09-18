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

$path = $_SERVER['PATH_INFO'] ?? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = preg_replace('#^/api/roles(/index\.php)?#', '', $path);
$path = '/' . trim($path, '/');
$method = $_SERVER['REQUEST_METHOD'];
$segments = array_values(array_filter(explode('/', $path)));

function out($data) { echo json_encode($data); exit; }
function getParam($key, $default = null) { return $_GET[$key] ?? $default; }
function getBody() { return json_decode(file_get_contents('php://input'), true) ?? []; }

// Legacy parity: lib/usermanagement/rolesView.php checkRights() and
// rolesEdit.php -> $user->hasRight($db,"role_management"). The role catalog
// list/view/create/edit/duplicate/delete routes require role_management.
function denyRoleManagement(&$currentUser) {
    logAuditEvent(TLS("audit_security_user_right_missing",
                      $currentUser->login,
                      basename($_SERVER['SCRIPT_NAME']),
                      $_SERVER['REQUEST_METHOD']),
                  'AUTH', $currentUser->dbID, 'roles');
    http_response_code(403);
    out(['status' => 'error', 'message' => 'no_permissions_for_action', 'right' => 'role_management']);
}

// Legacy parity: lib/usermanagement/usersAssign.php:201-240 checkRights().
// The role-assignment screens are accessible when the user holds ANY of:
//   role_management, testplan_user_role_assignment (tproject or tplan context),
//   user_role_assignment (global) or (test project target)
//   testproject_user_role_assignment on the target project.
// This is deliberately MORE permissive than role_management alone: a user
// whose only assign right is user_role_assignment must still get in (issue #924).
function userCanAssignRoles(&$db, &$user, $featureType, $featureID, $tprojectID) {
    $user->readTestProjectRoles($db);
    $user->readTestPlanRoles($db);

    if ($user->hasRight($db, 'role_management') === 'yes') return true;
    if ($user->hasRight($db, 'testplan_user_role_assignment', $tprojectID > 0 ? $tprojectID : null, -1) === 'yes') return true;
    if ($featureType === 'testplan' && $featureID > 0 &&
        $user->hasRight($db, 'testplan_user_role_assignment', null, $featureID) === 'yes') return true;
    if ($user->hasRight($db, 'user_role_assignment', null, -1) === 'yes') return true;
    if ($featureType === 'testproject') {
        $feature2check = $featureID > 0 ? $featureID : $tprojectID;
        if ($feature2check > 0 &&
            $user->hasRight($db, 'testproject_user_role_assignment', $feature2check, -1) === 'yes') return true;
    }
    return false;
}

// Legacy parity: lib/usermanagement/usersAssign.php:246-266 checkRightsForUpdate().
function userCanUpdateAssignments(&$db, &$user, $featureType, $featureID, $tprojectID) {
    $user->readTestProjectRoles($db);
    $user->readTestPlanRoles($db);
    if ($featureType === 'testproject') {
        if ($user->hasRight($db, 'user_role_assignment', $featureID) === 'yes') return true;
        if ($user->hasRight($db, 'testproject_user_role_assignment', $featureID, -1, true) === 'yes') return true;
        return false;
    }
    return $user->hasRight($db, 'testplan_user_role_assignment', $tprojectID, $featureID) === 'yes';
}

function denyAssignRights(&$currentUser, $right) {
    logAuditEvent(TLS("audit_security_user_right_missing",
                      $currentUser->login,
                      basename($_SERVER['SCRIPT_NAME']),
                      $right),
                  'AUTH', $currentUser->dbID, 'users');
    http_response_code(403);
    out(['status' => 'error', 'message' => 'no_permissions_for_action', 'right' => $right]);
}

// Legacy parity: lib/usermanagement/usersAssign.php:273-305
// getTestProjectEffectiveRoles(). The Test Project combo lists ONLY projects
// whose caller effective role holds user_role_assignment OR
// testproject_user_role_assignment. A project whose effective role lacks both
// is hidden even if the user can otherwise access it.
function getAssignableProjects(&$db, $userId) {
    $tprojectMgr = new testproject($db);
    $projects = $tprojectMgr->get_accessible_for_user($userId,
        ['output' => 'map_of_map_full', 'order_by' => 'ORDER BY name ASC']);
    $roleCache = [];
    $opts = [];
    if ($projects) {
        foreach ($projects as $pid => $p) {
            $effRoleId = intval($p['effective_role'] ?? 0);
            if (!array_key_exists($effRoleId, $roleCache)) {
                $roleCache[$effRoleId] = tlRole::getByID($db, $effRoleId, tlRole::TLOBJ_O_GET_DETAIL_FULL);
            }
            $role = $roleCache[$effRoleId];
            if ($role && ($role->hasRight('user_role_assignment') || $role->hasRight('testproject_user_role_assignment'))) {
                $opts[] = ['id' => intval($pid), 'name' => $p['name'] ?? ''];
            }
        }
    }
    return $opts;
}

// Legacy parity: lib/functions/roles.inc.php:298-343 get_tproject_effective_role().
// Resolves each user's EFFECTIVE role on a test project plus the inheritance
// nature of that role, using the 3-layer model (user -> test project).
// Returns a map keyed by user id with:
//   effective_role_id - role that applies on the project
//   is_inherited      - 1 when the effective role comes from the global role,
//                       0 when explicitly assigned on the project (or <no rights>)
//   uplayer_role_id   - user's global role id
//   inherited_role_id / inherited_role_name - the role whose name decorates the
//                        "<inherited> X" select option (legacy $ikx, usersAssign.tpl:226-232)
function getTprojectEffectiveRoleMap(&$db, &$users, $tproject_id, $isPublic) {
    $roleNames = [];
    $effective = [];
    foreach ($users as $u) {
        $u->readTestProjectRoles($db, $tproject_id);
        $globalRoleID = intval($u->globalRoleID);
        $effectiveRoleID = $globalRoleID;
        $isInherited = 1;

        // admin exception + private project: non-admins get <no rights>
        if (($globalRoleID != TL_ROLES_ADMIN) && !$isPublic) {
            $isInherited = 0;
            $effectiveRoleID = TL_ROLES_NO_RIGHTS;
        }

        // highest priority: explicit assignment on the project
        if (isset($u->tprojectRoles[$tproject_id])) {
            $isInherited = 0;
            $effectiveRoleID = intval($u->tprojectRoles[$tproject_id]->dbID);
        }

        // legacy $ikx (usersAssign.tpl:226-232): label source for the
        // "<inherited> X" option - effective role when inherited, global role otherwise.
        $inheritedRoleID = $isInherited ? $effectiveRoleID : $globalRoleID;
        if (!array_key_exists($inheritedRoleID, $roleNames)) {
            $roleNames[$inheritedRoleID] = '-';
            if ($inheritedRoleID > 0) {
                $irole = tlRole::getByID($db, $inheritedRoleID, tlRole::TLOBJ_O_GET_DETAIL_MINIMUM);
                $roleNames[$inheritedRoleID] = $irole ? $irole->getDisplayName() : '-';
            }
        }

        $effective[$u->dbID] = [
            'effective_role_id' => $effectiveRoleID,
            'is_inherited' => $isInherited,
            'uplayer_role_id' => $globalRoleID,
            'inherited_role_id' => $inheritedRoleID,
            'inherited_role_name' => $roleNames[$inheritedRoleID],
        ];
    }
    return $effective;
}

// ---------------------------------------------------------------------------
// Route-aware rights enforcement (issues #897 + #924).
// Role catalog routes keep the role_management gate; the role-assignment
// routes use the legacy usersAssign.php checkRights() union / update check.
// ---------------------------------------------------------------------------
$isTprojectRolesMeta = (isset($segments[0]) && $segments[0] === 'meta'
                        && isset($segments[1]) && $segments[1] === 'tproject-roles');
$isTplanRolesMeta = (isset($segments[0]) && $segments[0] === 'meta'
                     && isset($segments[1]) && $segments[1] === 'tplan-roles');
$isTprojectRoles = (isset($segments[0]) && $segments[0] === 'tproject-roles');
$isTplanRoles = (isset($segments[0]) && $segments[0] === 'tplan-roles');
$sessionTprojectID = isset($_SESSION['testprojectID']) ? intval($_SESSION['testprojectID']) : 0;
$sessionTplanID = isset($_SESSION['testplanID']) ? intval($_SESSION['testplanID']) : 0;

if ($isTprojectRolesMeta && $method === 'GET') {
    if (!userCanAssignRoles($db, $currentUser, 'testproject', intval(getParam('tproject_id')), $sessionTprojectID)) {
        denyAssignRights($currentUser, 'user_role_assignment');
    }
} elseif ($isTplanRolesMeta && $method === 'GET') {
    $tprojectID = intval(getParam('tproject_id'));
    if (!userCanAssignRoles($db, $currentUser, 'testplan', intval(getParam('tplan_id')), $tprojectID)) {
        denyAssignRights($currentUser, 'testplan_user_role_assignment');
    }
} elseif ($isTprojectRoles && $method === 'PUT') {
    $tprojectID = intval((getBody()['tproject_id'] ?? 0));
    if (!userCanUpdateAssignments($db, $currentUser, 'testproject', $tprojectID, $tprojectID)) {
        denyAssignRights($currentUser, 'testproject_user_role_assignment');
    }
} elseif ($isTplanRoles && $method === 'PUT') {
    $tplanID = intval((getBody()['tplan_id'] ?? 0));
    $tplanMgr = new testplan($db);
    $planInfo = $tplanID > 0 ? $tplanMgr->get_by_id($tplanID, ['output' => 'minimun']) : null;
    $tprojectID = $planInfo ? intval($planInfo['tproject_id']) : 0;
    if (!userCanUpdateAssignments($db, $currentUser, 'testplan', $tplanID, $tprojectID)) {
        denyAssignRights($currentUser, 'testplan_user_role_assignment');
    }
} elseif (!$currentUser->hasRight($db, 'role_management')) {
    denyRoleManagement($currentUser);
}

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

// Legacy parity: config.inc.php:663-666. $tlCfg->gui->usersAssign->pagination
// holds the enabled flag plus the DataTables length menu as a legacy JS-array
// string ('[20, 40, 60, -1], [20, 40, 60, "All"]'). The BFF parses that string
// into a numeric lengthMenu so the modern screen can initialize DataTables with
// the exact entries-per-page options the legacy template used (issue #930).
function getUsersAssignPaginationConfig() {
    $enabled = true;
    $lengthMenu = [[20, 40, 60, -1], [20, 40, 60, 'All']];
    if (isset($GLOBALS['tlCfg']->gui->usersAssign->pagination)) {
        $pg = $GLOBALS['tlCfg']->gui->usersAssign->pagination;
        $enabled = isset($pg->enabled) ? (bool)$pg->enabled : true;
        if (!empty($pg->length) && preg_match('/^\[([^\]]*)\],\s*\[([^\]]*)\]$/', $pg->length, $m)) {
            $parse = function ($csv) {
                $out = [];
                foreach (explode(',', $csv) as $tok) {
                    $tok = trim($tok);
                    if ($tok === '') continue;
                    if ($tok === '-1') { $out[] = -1; continue; }
                    if (is_numeric($tok)) { $out[] = (int)$tok; continue; }
                    $out[] = trim($tok, '"\'');
                }
                return $out;
            };
            $lengthMenu = [$parse($m[1]), $parse($m[2])];
        }
    }
    return ['enabled' => $enabled, 'lengthMenu' => $lengthMenu];
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

// Legacy parity: usersAssign.tpl:244-247 renders global-admin rows with a
// disabled select that the browser never submits (issue #927) - a global
// admin's project/plan role is locked on these screens. First normalize the
// assignment keys to canonical ints (so malformed JSON keys like " 1" cannot
// dodge the strip), then remove active global-admin ids from the map so
// neither a crafted API call nor a stale client payload can change or erase
// an admin's role. Returns the number of remaining assignments.
function stripGlobalAdminAssignments(&$db, &$assignments) {
    $normalized = [];
    foreach ($assignments as $uid => $rid) {
        $normalized[intval($uid)] = $rid;
    }
    $assignments = $normalized;
    $tables = tlObject::getDBTables('users');
    $adminRows = $db->get_recordset("SELECT id FROM {$tables['users']} WHERE role_id = " . TL_ROLES_ADMIN);
    if ($adminRows) {
        foreach ($adminRows as $ar) {
            unset($assignments[intval($ar['id'])]);
        }
    }
    return count($assignments);
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
            // Legacy parity (rolesEdit.php:102-103): only EXISTING rights may be
            // attached. tlRight::_clean() keeps dbID under TLOBJ_O_SEARCH_BY_ID,
            // so a failed read leaves a truthy dbID on a name-less phantom object
            // (issue #1535) - test the read result, never the dbID property.
            $right = new tlRight(intval($rid));
            if ($right->readFromDB($db) >= tl::OK) { $r->rights[] = $right; }
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
            // Legacy parity (rolesEdit.php:102-103) - attach existing rights only;
            // a failed read must not leave a dangling role_rights row (issue #1535).
            $right = new tlRight(intval($rid));
            if ($right->readFromDB($db) >= tl::OK) { $r->rights[] = $right; }
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

    $roles = tlRole::getAll($db, null, null, null, tlRole::TLOBJ_O_GET_DETAIL_MINIMUM);
    $roleOpts = [];
    foreach ($roles as $r) {
        // Skip the TL_ROLES_INHERITED (id 0) pseudo-role that tlRole::getAll()
        // injects: "inherited" is expressed per user through the "<inherited> X"
        // option, never as a real selectable role (issue #926).
        // Legacy parity: usersAssign.tpl:249-273 iterates $gui->optRights which also
        // contains the id-0 pseudo-role - but usersAssign.tpl renders only ONE value-0
        // option (the inherited one), while the 2.0.1 rewrite rendered two, corrupting
        // the select. Filtering it here restores the legacy single-option behaviour.
        if (intval($r->dbID) == TL_ROLES_INHERITED) continue;
        $roleOpts[] = ['id' => intval($r->dbID), 'name' => $r->getDisplayName()];
    }

    // Legacy parity: usersAssign.php:285-305 getTestProjectEffectiveRoles().
    // Only projects whose caller effective role can assign roles are listed.
    $projectOpts = getAssignableProjects($db, $userId);

    $items = [];
    $isPublic = 1;
    if ($tproject_id) {
        $tprojectMgr = new testproject($db);
        $tprojectInfo = $tprojectMgr->get_by_id($tproject_id);
        $isPublic = ($tprojectInfo && isset($tprojectInfo['is_public'])) ? intval($tprojectInfo['is_public']) : 1;

        $users = tlUser::getAll($db, "WHERE active=1", null, null, tlUser::TLOBJ_O_GET_DETAIL_MINIMUM);
        if ($users) {
            // Legacy parity: usersAssign.php:328-335 readTestProjectRoles() then
            // get_tproject_effective_role() for every user.
            $effectiveMap = getTprojectEffectiveRoleMap($db, $users, $tproject_id, $isPublic);
            foreach ($users as $u) {
                $assignedRoleId = 0;
                if (isset($u->tprojectRoles[$tproject_id])) {
                    $assignedRoleId = intval($u->tprojectRoles[$tproject_id]->dbID);
                }
                $eff = $effectiveMap[$u->dbID];
                $items[] = [
                    'id' => intval($u->dbID),
                    'login' => $u->login,
                    'name' => $u->getDisplayName(),
                    'roleID' => $assignedRoleId,
                    'effectiveRoleID' => $eff['effective_role_id'],
                    'isInherited' => $eff['is_inherited'],
                    'inheritedRoleID' => $eff['inherited_role_id'],
                    'inheritedRoleName' => $eff['inherited_role_name'],
                    'isAdmin' => intval($u->globalRoleID) == TL_ROLES_ADMIN,
                ];
            }
        }
    }

    // Legacy parity: usersAssign.tpl:286-292 - in demoMode the assign form's
    // Save button is replaced by the localized warn_demo note. The UI needs the
    // demo state up front so it can render that note instead of the button
    // (issue #932); mirror of the demoMode block fed by GET /roles (line 364).
    out(['status' => 'ok', 'items' => $items, 'roles' => $roleOpts, 'projects' => $projectOpts, 'isPublic' => $isPublic,
         'demoMode' => (bool)config_get('demoMode'),
         'pagination' => getUsersAssignPaginationConfig()]);
}

// Route: PUT /roles/tproject-roles - update test project role assignments
if ($method === 'PUT' && isset($segments[0]) && $segments[0] === 'tproject-roles') {
    // demoMode: role assignments are management writes; legacy demo deployments
    // forbid role maintenance entirely (usersAssign.tpl:144-147 + :286-292 - the
    // form's submit is blocked with an alert of the localized warn_demo message
    // and the Save button is replaced by that same warn_demo note). Use the
    // warn_demo message explicitly so the BFF rejection matches the legacy text
    // the UI shows instead of the button (issue #932).
    demoModeBlockedWrite('warn_demo', 'assign.demoDisabled');
    $body = getBody();
    $tproject_id = intval($body['tproject_id'] ?? 0);
    if (!$tproject_id) { http_response_code(400); out(['status' => 'error', 'message' => 'Missing tproject_id']); }

    $assignments = $body['assignments'] ?? [];

    // A map is required: reject wrong-typed payloads instead of silently
    // treating them as "no assignments" (array_keys() would fatal on a scalar).
    if (!is_array($assignments)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid assignments']);
    }

    // Legacy parity (usersAssign.php:82-84 + 560-562): an empty assignment map is
    // a no-op ("this can happen when filtering via Javascript" / every row left
    // at "-- no role --"). Legacy EXACTLY shows the localized notice
    // $TLS_no_users_selected = "No users selected - nothing done" in this case
    // (usersAssign.tpl:132-134 through inc_update.tpl:26-35). Short-circuit
    // before any manager call so no delete query is built for an empty user
    // list and no misleading audit event is written - but carry the
    // feedback_key so the modern screen can render the same localized notice
    // (issue #931).
    if (count($assignments) === 0) {
        out(['status' => 'ok', 'feedback_key' => 'no_users_selected']);
    }

    // Legacy parity: usersAssign.tpl:244-247 - a global admin's project role is
    // locked on this screen (issue #927); strip admin ids (and canonicalize keys).
    if (stripGlobalAdminAssignments($db, $assignments) === 0) {
        out(['status' => 'ok', 'feedback_key' => 'no_users_selected']);
    }

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
    // Legacy parity: usersAssign.php:87-89 - after a successful doUpdate() the
    // legacy page shows user_feedback = test_project_user_roles_updated ("User
    // Roles updated"). The feedback_key mirrors that legacy lang key so the
    // modern screen resolves and shows the localized success banner (issue #931).
    out(['status' => 'ok', 'feedback_key' => 'assign_roles_updated']);
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
                    // Legacy parity: usersAssign.tpl:244-247 (same template used
                    // for test plan contexts) locks global-admin selects (issue #927).
                    'isAdmin' => intval($u->globalRoleID) == TL_ROLES_ADMIN,
                ];
            }
        }
    }

    // Legacy parity: usersAssign.tpl:286-292 - the shared usersAssign.tpl
    // replaces the Save button with the warn_demo note in demo mode for test
    // plan contexts too; expose the demo state for the same UI gating (issue #932).
    out(['status' => 'ok', 'items' => $items, 'roles' => $roleOpts, 'plans' => $planOpts, 'projects' => $projectOpts,
         'demoMode' => (bool)config_get('demoMode')]);
}

// Route: PUT /roles/tplan-roles - update test plan role assignments
if ($method === 'PUT' && isset($segments[0]) && $segments[0] === 'tplan-roles') {
    // demoMode: role assignments are management writes; legacy demo deployments
    // forbid role maintenance entirely (usersAssign.tpl:144-147 + :286-292 -
    // the shared usersAssign.tpl also governs test plan contexts).
    // warn_demo message mirrors legacy (issue #932).
    demoModeBlockedWrite('warn_demo', 'assign.demoDisabled');
    $body = getBody();
    $tplan_id = intval($body['tplan_id'] ?? 0);
    if (!$tplan_id) { http_response_code(400); out(['status' => 'error', 'message' => 'Missing tplan_id']); }

    $assignments = $body['assignments'] ?? [];

    // A map is required: reject wrong-typed payloads instead of silently
    // treating them as "no assignments" (array_keys() would fatal on a scalar).
    if (!is_array($assignments)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid assignments']);
    }

    // Legacy parity (usersAssign.php:560-562): an empty assignment map is a
    // no-op. Short-circuit before any manager call so no delete query is built
    // for an empty user list and no misleading audit event is written.
    if (count($assignments) === 0) {
        out(['status' => 'ok']);
    }

    // Legacy parity: usersAssign.tpl:244-247 (shared by testplan contexts) - a
    // global admin's plan-role override is locked (issue #927); strip admin ids.
    if (stripGlobalAdminAssignments($db, $assignments) === 0) {
        out(['status' => 'ok']);
    }

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
