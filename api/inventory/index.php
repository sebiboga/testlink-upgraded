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

$tproject_id = 0;

// ---------------------------------------------------------------------------
// GET /owners?tproject_id=N - users holding the inventory view right,
// same data as lib/ajax/getUsersWithRight.php?right=project_inventory_view
// (incl. the trailing "no owner" empty entry)
// ---------------------------------------------------------------------------
if ($method === 'GET' && count($segments) === 1 && $segments[0] === 'owners') {
    if (!$user->hasRight($db, 'project_inventory_management')) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }
    $tproject_id = needTprojectId();
    // user must have the requested right too (same security rule as legacy ajax)
    if (!$user->hasRight($db, 'project_inventory_view', $tproject_id)) {
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
    if (!$user->hasRight($db, 'project_inventory_view', $tproject_id)) {
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
            $items[] = itemToJSON($row, is_null($users) ? [] : array_column($users, 'login'));
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
            'canManage' => (bool)$user->hasRight($db, 'project_inventory_management', $tproject_id),
            'canView' => true,
        ],
    ]);
}

// ---------------------------------------------------------------------------
// POST / - create device (project_inventory_management)
// body: tproject_id, machineName, machineIp, machineOwner,
//       machinePurpose, machineHw, machineNotes
// ---------------------------------------------------------------------------
if ($method === 'POST' && count($segments) === 0) {
    if (!$user->hasRight($db, 'project_inventory_management')) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission',
             'error_code' => 'NO_RIGHTS']);
    }
    $tproject_id = needTprojectId(getBody());

    $op = saveDevice($db, $userId, $tproject_id, 0);
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
    if (!$user->hasRight($db, 'project_inventory_management')) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission',
             'error_code' => 'NO_RIGHTS']);
    }
    $tproject_id = needTprojectId(getBody());
    $machineId = intval($segments[0]);

    $op = saveDevice($db, $userId, $tproject_id, $machineId);
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
    if (!$user->hasRight($db, 'project_inventory_management')) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission',
             'error_code' => 'NO_RIGHTS']);
    }
    $tproject_id = needTprojectId();
    $tlIs = new tlInventory($tproject_id, $db);
    $result = $tlIs->deleteInventory(intval($segments[0]));
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
 * Create or update through tlInventory::setInventory(), keeping the exact
 * legacy validation chain (empty name -> duplicate name (case-insensitive)
 * -> duplicate IP -> DB write).
 *
 * @return int tl::OK or one of tlInventory error constants
 */
function saveDevice(&$db, $currentUserId, $tproject_id, $machineId) {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    $data = new stdClass();
    $data->machineID = $machineId;
    $data->machineName = trim((string)($body['machineName'] ?? ''));
    $data->machineIp = trim((string)($body['machineIp'] ?? ''));
    $data->machineOwner = intval($body['machineOwner'] ?? $currentUserId);
    $data->machineNotes = (string)($body['machineNotes'] ?? '');
    $data->machinePurpose = (string)($body['machinePurpose'] ?? '');
    $data->machineHw = (string)($body['machineHw'] ?? '');

    $tlIs = new tlInventory($tproject_id, $db);
    return $tlIs->setInventory($data);
}
