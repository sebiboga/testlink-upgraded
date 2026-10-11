<?php
/**
 * Roles Edit BFF API (standalone Role Create / Edit / Duplicate screen).
 *
 * Refs #1898 - modernizes lib/usermanagement/rolesEdit.php +
 * gui/templates/dashio/usermanagement/rolesEdit.tpl into a standalone Dashio
 * screen. The screen is a distinct UX from the rolesView modal: it renders the
 * legacy grouped rights matrix (one labelled fieldset per right area), the
 * "possible affected users" list and the demo-mode read-only note.
 *
 * URL: /api/rolesedit/
 *   GET  ?action=init&mode=create|edit|duplicate[&roleid=N]
 *   POST ?action=save     {roleid?:N, name, description, rightNames:[..]}
 *   POST ?action=duplicate{roleid:N}
 *   POST ?action=delete   {roleid:N}
 *
 * Plain PHP, no framework, session auth + same-origin proof, JSON I/O.
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
require_once('users.inc.php');
require_once('roles.inc.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json');

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || $userId <= 0) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated', 'code' => 'not_authenticated']);
    exit;
}

// Session inactivity window (legacy testlinkInitPage -> checkSessionValid).
bffEnforceSession($db);

$currentUser = tlUser::getByID($db, $userId);
if (is_null($currentUser)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'User not found', 'code' => 'not_authenticated']);
    exit;
}

// Legacy parity: rolesEdit.php:24 - the right labels used by the grouped matrix
// come from the global rights maps initialized for the session locale.
init_global_rights_maps();

function out($data) { echo json_encode($data); exit; }
function getBody() { return json_decode(file_get_contents('php://input'), true) ?? []; }

// Legacy parity: rolesEdit.php:310-313 checkRights() -> role_management.
function roleManagementGranted(&$db, &$user) {
    return $user->hasRight($db, 'role_management') === 'yes';
}

function denyRoleManagement(&$db, &$user) {
    logAuditEvent(TLS("audit_security_user_right_missing",
                      $user->login,
                      basename($_SERVER['SCRIPT_NAME']),
                      'role_management'),
                  'AUTH', $user->dbID, 'roles');
    http_response_code(403);
    out(['status' => 'error', 'message' => 'no_permissions_for_action',
         'messageKey' => 'role.noRights', 'right' => 'role_management']);
}

// Legacy parity: rolesEdit.php:194-211 getRightsCfg() preserves the exact
// fieldset order of the legacy template (rolesEdit.tpl:84-162).
function groupedRights() {
    return [
        ['key' => 'tplan',       'labelKey' => 'roe.group.tplan',       'rights' => $GLOBALS['g_rights_tp'] ?? []],
        ['key' => 'tcase',       'labelKey' => 'roe.group.tcase',       'rights' => $GLOBALS['g_rights_mgttc'] ?? []],
        ['key' => 'req',         'labelKey' => 'roe.group.req',         'rights' => $GLOBALS['g_rights_req'] ?? []],
        ['key' => 'tproject',    'labelKey' => 'roe.group.tproject',    'rights' => $GLOBALS['g_rights_product'] ?? []],
        ['key' => 'user',        'labelKey' => 'roe.group.user',        'rights' => $GLOBALS['g_rights_users'] ?? []],
        ['key' => 'keyword',     'labelKey' => 'roe.group.keyword',     'rights' => $GLOBALS['g_rights_kw'] ?? []],
        ['key' => 'cfield',      'labelKey' => 'roe.group.cfield',      'rights' => $GLOBALS['g_rights_cf'] ?? []],
        ['key' => 'system',      'labelKey' => 'roe.group.system',      'rights' => $GLOBALS['g_rights_system'] ?? []],
        ['key' => 'platform',    'labelKey' => 'roe.group.platform',    'rights' => $GLOBALS['g_rights_platforms'] ?? []],
        ['key' => 'issuetracker','labelKey' => 'roe.group.issuetracker','rights' => $GLOBALS['g_rights_issuetrackers'] ?? []],
        ['key' => 'codetracker', 'labelKey' => 'roe.group.codetracker', 'rights' => $GLOBALS['g_rights_codetrackers'] ?? []],
        ['key' => 'execution',   'labelKey' => 'roe.group.execution',   'rights' => $GLOBALS['g_rights_executions'] ?? []],
    ];
}

// Flat allow-list of every known right NAME -> localized label. Used both to
// build the checked matrix and to reject forged/unknown right names on write.
function flattenKnownRights() {
    $known = [];
    foreach (groupedRights() as $g) {
        foreach ($g['rights'] as $name => $label) {
            $known[(string)$name] = $label;
        }
    }
    return $known;
}

function roleRightNames($role) {
    $names = [];
    if ($role && !empty($role->rights)) {
        foreach ($role->rights as $r) {
            $names[] = (string)$r->name;
        }
    }
    return $names;
}

function roleErrorKey($code) {
    switch ($code) {
        case tlRole::E_NAMEALREADYEXISTS: return 'role.error.nameExists';
        case tlRole::E_NAMELENGTH:        return 'role.error.noRoleName';
        case tlRole::E_EMPTYROLE:         return 'role.error.noRights';
        case tlRole::E_DBERROR:
        default:                          return 'role.error.notUpdated';
    }
}

// Legacy parity: rolesEdit.php:292-297 generateUniqueName().
function generateUniqueName($s) {
    return substr($s . ' - Copy - ' . substr(sha1(mt_rand()), 0, 50), 0, 100);
}

// issues the "role was written" audit row, mirror of rolesEdit.php:121-136.
function auditRoleWrite($operation, $roleName, $roleId) {
    switch ($operation) {
        case 'doCreate':
        case 'duplicate':
            logAuditEvent(TLS("audit_role_created", $roleName), 'CREATE', $roleId, 'roles');
            break;
        case 'doUpdate':
            logAuditEvent(TLS("audit_role_saved", $roleName), 'SAVE', $roleId, 'roles');
            break;
    }
}

// Resolve a validated list of right names into tlRight objects (existing rows
// only, exactly like rolesEdit.php:102-103 tlRight::getAll()).
function buildRights($db, $rightNames) {
    $known = flattenKnownRights();
    $wanted = [];
    foreach ((array)$rightNames as $name) {
        if (!is_scalar($name)) { continue; }
        $name = (string)$name;
        if (isset($known[$name])) { $wanted[$name] = true; }
    }
    if (empty($wanted)) { return []; }

    $rows = getAllRights($db); // catalog of {id, description(name)}
    $idByName = [];
    foreach ((array)$rows as $row) {
        $idByName[(string)$row['description']] = intval($row['id']);
    }

    $rights = [];
    foreach (array_keys($wanted) as $name) {
        if (!isset($idByName[$name])) { continue; }
        $right = new tlRight($idByName[$name]);
        if ($right->readFromDB($db) >= tl::OK) { $rights[] = $right; }
    }
    return $rights;
}

function getAllRights(&$db) {
    $tables = tlObject::getDBTables('rights');
    return $db->get_recordset("SELECT id, description FROM {$tables['rights']} ORDER BY id ASC");
}

$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? $_GET['action'] : '';
if (!is_scalar($action)) {
    http_response_code(400);
    out(['status' => 'error', 'message' => 'unknown_action', 'code' => 'unknown_action']);
}

if ($method !== 'GET' && $method !== 'POST') {
    header('Allow: GET, POST');
    http_response_code(405);
    out(['status' => 'error', 'message' => 'wrong_method', 'code' => 'wrong_method']);
}

// Every route requires role_management (rolesEdit.php checkRights()).
if (!roleManagementGranted($db, $currentUser)) {
    denyRoleManagement($db, $currentUser);
}

$demoMode = (bool)config_get('demoMode');
function demoBlocked() {
    http_response_code(403);
    out(['status' => 'error', 'code' => 'demo_mode',
         'messageKey' => 'role.demoUpdateDisabled',
         'message' => lang_get('demo_update_role_disabled', 'en_GB')]);
}

/* ------------------------------------------------------------------ GET */
if ($method === 'GET' && $action === 'init') {
    $mode = isset($_GET['mode']) ? (string)$_GET['mode'] : 'create';
    if (!in_array($mode, ['create', 'edit', 'duplicate'], true)) {
        $mode = 'create';
    }
    $roleid = isset($_GET['roleid']) ? intval($_GET['roleid']) : 0;

    $role = null;
    $canEdit = true;
    $isAdmin = false;
    $affectedUsers = [];

    if ($mode === 'create') {
        $role = ['id' => 0, 'name' => '', 'description' => '', 'rights' => []];
    } else {
        if ($roleid <= 0) {
            http_response_code(400);
            out(['status' => 'error', 'message' => 'missing_roleid', 'code' => 'missing_roleid']);
        }
        $r = tlRole::getByID($db, $roleid, tlRole::TLOBJ_O_GET_DETAIL_FULL);
        if (!$r) {
            http_response_code(404);
            out(['status' => 'error', 'message' => 'role_not_found', 'code' => 'role_not_found']);
        }
        $isAdmin = (intval($r->dbID) == TL_ROLES_ADMIN);
        $canEdit = (intval($r->dbID) != TL_ROLES_ADMIN && intval($r->dbID) != TL_ROLES_NO_RIGHTS);

        if ($mode === 'duplicate') {
            $name = generateUniqueName($r->getDisplayName());
            $role = ['id' => 0, 'name' => $name, 'description' => (string)$r->description,
                     'rights' => roleRightNames($r), 'sourceId' => intval($r->dbID)];
        } else {
            $role = ['id' => intval($r->dbID), 'name' => $r->getDisplayName(),
                     'description' => (string)$r->description,
                     'rights' => roleRightNames($r)];
            // Legacy parity: rolesEdit.php:285 - affected users listed on edit.
            $users = $r->getAllUsersWithRole($db);
            if ($users) {
                foreach ($users as $u) {
                    $affectedUsers[] = ['id' => intval($u->dbID), 'login' => $u->login,
                                        'name' => $u->getDisplayName()];
                }
            }
        }
    }

    $groups = [];
    foreach (groupedRights() as $g) {
        $rows = [];
        foreach ($g['rights'] as $name => $label) {
            $rows[] = ['name' => (string)$name, 'label' => (string)$label];
        }
        $groups[] = ['key' => $g['key'], 'labelKey' => $g['labelKey'], 'rights' => $rows];
    }

    out([
        'status' => 'ok',
        'mode' => $mode,
        'demoMode' => $demoMode,
        'canEdit' => $canEdit,
        'isAdmin' => $isAdmin,
        'canManage' => true,
        'grants' => [
            'role_management' => 'yes',
            'mgt_view_events' => ($currentUser->hasRight($db, 'mgt_view_events') === 'yes') ? 'yes' : 'no',
        ],
        'role' => $role,
        'rightsGroups' => $groups,
        'affectedUsers' => $affectedUsers,
    ]);
}

/* ----------------------------------------------------------------- POST */
if ($method === 'POST' && $action === 'save') {
    if ($demoMode) { demoBlocked(); }
    $body = getBody();
    $roleid = intval($body['roleid'] ?? 0);
    $name = trim((string)($body['name'] ?? ''));
    $description = (string)($body['description'] ?? '');
    $rightNames = $body['rightNames'] ?? [];

    if (!is_array($rightNames)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'invalid_rights', 'messageKey' => 'role.error.noRights', 'code' => 'invalid_rights']);
    }
    if ($name === '') {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'empty_role_name', 'messageKey' => 'role.error.noRoleName', 'code' => 'empty_role_name']);
    }
    if (mb_strlen($name) > 100) { $name = mb_substr($name, 0, 100); }

    $rights = buildRights($db, $rightNames);
    if (count($rights) === 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'no_rights', 'messageKey' => 'role.error.noRights', 'code' => 'no_rights']);
    }

    if ($roleid > 0) {
        // Legacy parity: rolesEdit.php:222 + rolesEdit.tpl:193 - admin role is
        // read-only and <no rights> can never be saved.
        if ($roleid == TL_ROLES_ADMIN || $roleid == TL_ROLES_NO_RIGHTS) {
            logAuditEvent("Forbidden update attempt on protected role #{$roleid}", 'AUTH', $roleid, 'roles');
            http_response_code(400);
            out(['status' => 'error', 'message' => 'Cannot edit system role', 'messageKey' => 'role.editLocked', 'id' => $roleid]);
        }
        $role = tlRole::getByID($db, $roleid);
        if (!$role) {
            http_response_code(404);
            out(['status' => 'error', 'message' => 'role_not_found', 'code' => 'role_not_found']);
        }
        $role->name = $name;
        $role->description = $description;
        $role->rights = $rights;
        $result = $role->writeToDB($db);
        if ($result >= tl::OK) {
            auditRoleWrite('doUpdate', $role->name, $role->dbID);
            out(['status' => 'ok', 'feedback_key' => 'role_updated',
                 'item' => ['id' => intval($role->dbID), 'name' => $name]]);
        }
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Error updating role',
             'messageKey' => roleErrorKey($result), 'code' => $result]);
    }

    // create
    $role = new tlRole();
    $role->name = $name;
    $role->description = $description;
    $role->rights = $rights;
    $result = $role->writeToDB($db);
    if ($result >= tl::OK) {
        auditRoleWrite('doCreate', $role->name, $role->dbID);
        out(['status' => 'ok', 'feedback_key' => 'role_created',
             'item' => ['id' => intval($role->dbID), 'name' => $name]]);
    }
    http_response_code(400);
    out(['status' => 'error', 'message' => 'Error creating role',
         'messageKey' => roleErrorKey($result), 'code' => $result]);
}

if ($method === 'POST' && $action === 'duplicate') {
    if ($demoMode) { demoBlocked(); }
    $body = getBody();
    $roleid = intval($body['roleid'] ?? 0);
    if ($roleid <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'missing_roleid', 'code' => 'missing_roleid']);
    }
    $role = tlRole::getByID($db, $roleid, tlRole::TLOBJ_O_GET_DETAIL_FULL);
    if (!$role) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'role_not_found', 'code' => 'role_not_found']);
    }
    $role->dbID = null;
    $role->name = generateUniqueName($role->getDisplayName());
    $result = $role->writeToDB($db);
    if ($result >= tl::OK) {
        auditRoleWrite('duplicate', $role->name, $role->dbID);
        out(['status' => 'ok', 'feedback_key' => 'role_duplicated',
             'item' => ['id' => intval($role->dbID), 'name' => $role->name]]);
    }
    http_response_code(400);
    out(['status' => 'error', 'message' => 'Error duplicating role',
         'messageKey' => roleErrorKey($result), 'code' => $result]);
}

if ($method === 'POST' && $action === 'delete') {
    if ($demoMode) { demoBlocked(); }
    $body = getBody();
    $roleid = intval($body['roleid'] ?? 0);
    if ($roleid <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'missing_roleid', 'code' => 'missing_roleid']);
    }
    if ($roleid <= TL_LAST_SYSTEM_ROLE) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Cannot delete system role',
             'messageKey' => 'role.systemDeleteDenied', 'code' => 'system_role']);
    }
    $role = tlRole::getByID($db, $roleid, tlRole::TLOBJ_O_GET_DETAIL_MINIMUM);
    if (!$role) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'role_not_found', 'code' => 'role_not_found']);
    }
    $roleName = $role->getDisplayName();
    $result = $role->deleteFromDB($db);
    if ($result >= tl::OK) {
        logAuditEvent("Role '{$roleName}' deleted", 'DELETE', $roleid, 'roles');
        out(['status' => 'ok', 'feedback_key' => 'role_deleted']);
    }
    http_response_code(400);
    out(['status' => 'error', 'message' => 'Error deleting role',
         'messageKey' => 'role.error.deleted', 'code' => $result]);
}

http_response_code(400);
out(['status' => 'error', 'message' => 'unknown_action', 'code' => 'unknown_action']);
