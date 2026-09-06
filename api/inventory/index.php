<?php
/**
 * Inventory (devices) BFF API
 * URL: /api/inventory/
 * Plain PHP, no framework, no compilation
 *
 * Mirrors lib/inventory/inventoryView.php + getInventory.php +
 * setInventory.php + deleteInventory.php + lib/ajax/getUsersWithRight.php
 * (TestLink 1.9.20 behavior).
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

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

$path = $_SERVER['PATH_INFO'] ?? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = preg_replace('#^/api/inventory(/index\.php)?#', '', $path);
$path = '/' . trim($path, '/');
$method = $_SERVER['REQUEST_METHOD'];
$segments = array_values(array_filter(explode('/', $path)));

function out($data) { echo json_encode($data); exit; }
function getBody() { return json_decode(file_get_contents('php://input'), true) ?? []; }

// view needs project_inventory_view, manage needs project_inventory_management
// (same split as the legacy screen).
function needTprojectId($body = []) {
    $id = intval($_GET['tproject_id'] ?? ($body['tproject_id'] ?? 0));
    if ($id <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test project id']);
    }
    return $id;
}

/**
 * Project-role-aware inventory right check (mirrors getGrantSetWithExit,
 * lib/functions/common.php lines 2189-2203). hasRight() strips inventory
 * rights from project roles because they live in $g_propRights_global
 * (roles.inc.php) / propagateRights() (tlUser.class.php:860), so a user
 * granted "project_inventory_view" via a *project role* would otherwise be
 * shown the aside menu link (the legacy grant check reads the project role
 * directly) but hit a 403 dead-end here. Fall back to scanning the project
 * role's right set; keep the 403 only for users holding neither right.
 */
function invRight($user, $db, $right, $tproject_id) {
    if ($user->hasRight($db, $right, $tproject_id) == 'yes') {
        return true;
    }
    if ($tproject_id > 0
        && isset($user->tprojectRoles[$tproject_id])
        && !empty($user->tprojectRoles[$tproject_id]->rights)
        && is_array($user->tprojectRoles[$tproject_id]->rights)) {
        foreach ($user->tprojectRoles[$tproject_id]->rights as $ro) {
            if (isset($ro->name) && $ro->name === $right) {
                return true;
            }
        }
    }
    return false;
}

function itemToJSON($row, $logins) {
    return [
        'id' => intval($row['id']),
        'name' => (string)$row['name'],
        'ipaddress' => (string)$row['ipaddress'],
        'purpose' => isset($row['purpose']) ? (string)$row['purpose'] : '',
        'hardware' => isset($row['hardware']) ? (string)$row['hardware'] : '',
        'notes' => isset($row['notes']) ? (string)$row['notes'] : '',
        'owner_id' => intval($row['owner_id']),
        'owner' => ($row['owner_id'] > 0 && isset($logins[$row['owner_id']]))
            ? $logins[$row['owner_id']] : '',
    ];
}

// ---------------------------------------------------------------------------
// GET /owners?tproject_id=N - users holding the inventory view right,
// same data as lib/ajax/getUsersWithRight.php?right=project_inventory_view
// (incl. the trailing "no owner" empty entry)
// ---------------------------------------------------------------------------
if ($method === 'GET' && count($segments) === 1 && $segments[0] === 'owners') {
    // stricter than the legacy ajax (which only required the requested right):
    // the owner combo is only used inside the manage modal, so management
    // right is required here too (project-role-aware, see invRight)
    if (!invRight($user, $db, 'project_inventory_management', 0)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }
    $tproject_id = needTprojectId();
    // user must hold the requested right on the project as well
    if (!invRight($user, $db, 'project_inventory_view', $tproject_id)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }

    $tlUser = new tlUser($userId);
    $rows = $tlUser->getNamesForProjectRight($db, 'project_inventory_view', $tproject_id);
    $rows[] = ['id' => 0, 'login' => ' ', 'first' => ' ', 'last' => ' ']; // option for no owner
    $clean = [];
    foreach ($rows as $r) {
        $clean[] = [
            'id' => intval($r['id']),
            'login' => trim((string)$r['login']) === '' ? '' : trim($r['login']),
        ];
    }
    out(['status' => 'ok', 'items' => $clean]);
}

// ---------------------------------------------------------------------------
// GET /?tproject_id=N - device list for the project (project_inventory_view)
// ---------------------------------------------------------------------------
if ($method === 'GET' && count($segments) === 0) {
    $tproject_id = needTprojectId();
    if (!invRight($user, $db, 'project_inventory_view', $tproject_id)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }

    $tlIs = new tlInventory($tproject_id, $db);
    $data = $tlIs->getAll();

    $tlUser = new tlUser($userId);
    $users = $tlUser->getNames($db);

    // getOptions() may return false / empty object on corrupt data - guard
    // (same rule as common.php grant computation, issue #533)
    $tprojMgr = new testproject($db);
    $tprojOpt = $tprojMgr->getOptions($tproject_id);
    $invEnabled = (!empty($tprojOpt) && !empty($tprojOpt->inventoryEnabled));

    $items = [];
    if (!is_null($data)) {
        foreach ($data as $row) {
            $items[] = itemToJSON($row, is_null($users) ? [] : array_column($users, 'login', 'id'));
        }
    }

    out([
        'status' => 'ok',
        'items' => $items,
        'tproject' => [
            'id' => $tproject_id,
            'name' => testproject::getName($db, $tproject_id),
            'inventoryEnabled' => $invEnabled,
        ],
        'rights' => [
            'canManage' => invRight($user, $db, 'project_inventory_management', $tproject_id),
            'canView' => true,
        ],
        'user' => ['id' => intval($userId)],
    ]);
}

// ---------------------------------------------------------------------------
// POST / - create device (project_inventory_management)
// body: tproject_id, machineName, machineIp, machineOwner,
//       machinePurpose, machineHw, machineNotes
// ---------------------------------------------------------------------------
if ($method === 'POST' && count($segments) === 0) {
    $body = getBody();
    $tproject_id = needTprojectId($body);
    if (!invRight($user, $db, 'project_inventory_management', $tproject_id)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission',
             'error_code' => 'NO_RIGHTS']);
    }

    $op = saveDevice($db, $userId, $tproject_id, 0, $body);
    if (is_array($op)) {
        // create success - report the new id like the keywords BFF does
        out(['status' => 'ok', 'id' => intval($op['id'])]);
    }
    if ($op >= tl::OK) {
        out(['status' => 'ok']);
    }
    http_response_code(422);
    out(['status' => 'error', 'message' => 'Validation failed', 'error_code' => intval($op)]);
}

// ---------------------------------------------------------------------------
// PUT /{id} - update device (project_inventory_management)
// body: tproject_id + same fields as POST
// ---------------------------------------------------------------------------
if ($method === 'PUT' && isset($segments[0]) && ctype_digit($segments[0])) {
    $body = getBody();
    $tproject_id = needTprojectId($body);
    if (!invRight($user, $db, 'project_inventory_management', $tproject_id)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission',
             'error_code' => 'NO_RIGHTS']);
    }
    $machineId = intval($segments[0]);

    // defense-in-depth: the row must belong to the addressed test project
    // (legacy UPDATE was scoped by id only) and must exist
    $existing = fetchDeviceRow($db, $tproject_id, $machineId);
    if (empty($existing)) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Device not found',
             'error_code' => 'NOT_FOUND']);
    }

    $op = saveDevice($db, $userId, $tproject_id, $machineId, $body, $existing);
    if ($op >= tl::OK) {
        out(['status' => 'ok']);
    }
    http_response_code(422);
    out(['status' => 'error', 'message' => 'Validation failed', 'error_code' => intval($op)]);
}

// ---------------------------------------------------------------------------
// DELETE /{id}?tproject_id=N - delete device (project_inventory_management)
// ---------------------------------------------------------------------------
if ($method === 'DELETE' && isset($segments[0]) && ctype_digit($segments[0])) {
    $tproject_id = needTprojectId();
    if (!invRight($user, $db, 'project_inventory_management', $tproject_id)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission',
             'error_code' => 'NO_RIGHTS']);
    }
    $machineId = intval($segments[0]);

    // pre-check existence/ownership: legacy deleteInventory() hit an undefined
    // variable when the row did not exist in this project (PHP warning +
    // misleading "Delete failed")
    if (empty(fetchDeviceRow($db, $tproject_id, $machineId))) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Device not found',
             'error_code' => 'NOT_FOUND']);
    }

    $tlIs = new tlInventory($tproject_id, $db);
    $result = $tlIs->deleteInventory($machineId);
    if ($result == tl::OK) {
        out(['status' => 'ok']);
    }
    http_response_code(422);
    out(['status' => 'error', 'message' => 'Delete failed', 'error_code' => 'DELETE_FAILED']);
}

http_response_code(404);
out(['status' => 'error', 'message' => 'Not found']);

// ---------------------------------------------------------------------------
// helpers below (file already streamed output above for handled routes)
// ---------------------------------------------------------------------------

/**
 * Fetch one inventory row of a test project (null when missing or foreign).
 * Legacy writeToDB()/deleteFromDB() scoped queries by id only, so this is a
 * cheap defense-in-depth check the class does not provide.
 */
function fetchDeviceRow(&$db, $tproject_id, $machineId) {
    $tables = tlObjectWithDB::getDBTables('inventory');
    $sql = "SELECT id, owner_id FROM {$tables['inventory']}" .
           " WHERE id = " . intval($machineId) .
           " AND testproject_id = " . intval($tproject_id);
    return $db->fetchFirstRow($sql);
}

/**
 * Create or update through tlInventory::setInventory(), keeping the exact
 * legacy validation chain (empty name -> duplicate name (case-insensitive)
 * -> duplicate IP -> DB write).
 *
 * Field length caps mirror legacy setInventory.php init_args()
 * (name 255, ip 50, notes/purpose/hw 2000 - R_PARAMS truncated there).
 *
 * @return int|array tl::OK, or one of tlInventory error constants;
 *         on create success returns ['id' => N]
 */
function saveDevice(&$db, $currentUserId, $tproject_id, $machineId, $body,
                    $existingRow = null) {

    $data = new stdClass();
    $data->machineID = $machineId;
    $data->machineName = mb_substr(trim((string)($body['machineName'] ?? '')), 0, 255);
    $data->machineIp = mb_substr(trim((string)($body['machineIp'] ?? '')), 0, 50);
    if ($machineId > 0 && !array_key_exists('machineOwner', $body)) {
        // update without explicit owner: preserve current ownership
        // (create defaults to the logged-in user, like the legacy form)
        $data->machineOwner = $existingRow ? intval($existingRow['owner_id']) : $currentUserId;
    } else {
        $data->machineOwner = intval($body['machineOwner'] ?? $currentUserId);
    }
    $data->machineNotes = mb_substr((string)($body['machineNotes'] ?? ''), 0, 2000);
    $data->machinePurpose = mb_substr((string)($body['machinePurpose'] ?? ''), 0, 2000);
    $data->machineHw = mb_substr((string)($body['machineHw'] ?? ''), 0, 2000);

    $tlIs = new tlInventory($tproject_id, $db);
    $result = $tlIs->setInventory($data);
    if ($result >= tl::OK && $machineId == 0) {
        $cur = $tlIs->getCurrentData();
        return ['id' => intval($cur->machineID)];
    }
    return $result;
}
