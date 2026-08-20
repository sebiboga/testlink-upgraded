<?php
/**
 * Custom Fields BFF API
 * URL: /api/cfields/
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

if (!$user->hasRight($db, 'cfield_management')) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'No permission']);
    exit;
}

$path = $_SERVER['PATH_INFO'] ?? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = preg_replace('#^/api/cfields(/index\.php)?#', '', $path);
$path = '/' . trim($path, '/');
$method = $_SERVER['REQUEST_METHOD'];
$segments = array_values(array_filter(explode('/', $path)));

function out($data) { echo json_encode($data); exit; }
function getParam($key, $default = null) { return $_GET[$key] ?? $default; }
function getBody() { return json_decode(file_get_contents('php://input'), true) ?? []; }

$cfield_mgr = new cfield_mgr($db);

function cfToJSON($cf) {
    return [
        'id' => intval($cf['id']),
        'name' => $cf['name'],
        'label' => $cf['label'],
        'type' => intval($cf['type']),
        'possible_values' => $cf['possible_values'] ?? '',
        'show_on_design' => intval($cf['show_on_design']),
        'enable_on_design' => intval($cf['enable_on_design']),
        'show_on_execution' => intval($cf['show_on_execution']),
        'enable_on_execution' => intval($cf['enable_on_execution']),
        'show_on_testplan_design' => intval($cf['show_on_testplan_design']),
        'enable_on_testplan_design' => intval($cf['enable_on_testplan_design']),
        'node_type_id' => isset($cf['node_type_id']) ? intval($cf['node_type_id']) : 0,
        'node_description' => $cf['node_description'] ?? '',
        'active' => isset($cf['active']) ? intval($cf['active']) : 1,
        'default_value' => $cf['default_value'] ?? '',
    ];
}

// Route: GET /meta/types - available custom field types
if ($method === 'GET' && isset($segments[0]) && $segments[0] === 'meta' && isset($segments[1]) && $segments[1] === 'types') {
    $types = $cfield_mgr->get_available_types();
    $items = [];
    foreach ($types as $id => $name) {
        $items[] = ['id' => intval($id), 'name' => $name];
    }
    out(['status' => 'ok', 'items' => $items]);
}

// Route: GET /meta/nodes - allowed node types
if ($method === 'GET' && isset($segments[0]) && $segments[0] === 'meta' && isset($segments[1]) && $segments[1] === 'nodes') {
    $nodes = $cfield_mgr->get_allowed_nodes();
    $items = [];
    foreach ($nodes as $verbose => $id) {
        $items[] = ['id' => intval($id), 'name' => $verbose];
    }
    out(['status' => 'ok', 'items' => $items]);
}

// Route: GET / - list all custom fields
if ($method === 'GET' && empty($segments)) {
    $map = $cfield_mgr->get_all();
    $items = [];
    if ($map) {
        foreach ($map as $id => $cf) {
            $items[] = cfToJSON($cf);
        }
    }
    out(['status' => 'ok', 'items' => $items, 'total' => count($items)]);
}

// Route: GET /{id} - get single custom field
if ($method === 'GET' && isset($segments[0]) && is_numeric($segments[0])) {
    $id = intval($segments[0]);
    $map = $cfield_mgr->get_by_id($id);
    if (!$map || !isset($map[$id])) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Custom field not found']);
    }
    out(['status' => 'ok', 'item' => cfToJSON($map[$id])]);
}

// Route: POST / - create custom field
if ($method === 'POST' && empty($segments)) {
    $body = getBody();
    $name = trim($body['name'] ?? '');
    $label = trim($body['label'] ?? '');

    if ($name === '' || $label === '') {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Name and label are required']);
    }

    $existing = $cfield_mgr->get_by_name($name);
    if ($existing) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Custom field name already exists']);
    }

    $nodeTypeMap = $cfield_mgr->get_allowed_nodes();
    $nodeTypeName = $body['node_type'] ?? 'testcase';
    $nodeTypeId = $nodeTypeMap[$nodeTypeName] ?? $nodeTypeMap['testcase'];

    $cf = [
        'name' => $name,
        'label' => $label,
        'type' => intval($body['type'] ?? 0),
        'possible_values' => $body['possible_values'] ?? '',
        'show_on_design' => 1,
        'enable_on_design' => $body['enable_on_design'] ?? 1,
        'show_on_execution' => 0,
        'enable_on_execution' => $body['enable_on_execution'] ?? 0,
        'show_on_testplan_design' => 0,
        'enable_on_testplan_design' => $body['enable_on_testplan_design'] ?? 0,
        'node_type_id' => $nodeTypeId,
    ];

    $result = $cfield_mgr->create($cf);
    if ($result['status_ok']) {
        logAuditEvent("Custom field '$name' created", "CREATE", $result['id'], "custom_fields");
        $map = $cfield_mgr->get_by_id($result['id']);
        out(['status' => 'ok', 'item' => cfToJSON($map[$result['id']])]);
    } else {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Error creating custom field']);
    }
}

// Route: PUT /{id} - update custom field
if ($method === 'PUT' && isset($segments[0]) && is_numeric($segments[0])) {
    $id = intval($segments[0]);
    $map = $cfield_mgr->get_by_id($id);
    if (!$map || !isset($map[$id])) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Custom field not found']);
    }

    $existing = $map[$id];
    $body = getBody();

    $name = trim($body['name'] ?? $existing['name']);
    $label = trim($body['label'] ?? $existing['label']);

    if ($name !== $existing['name']) {
        $dup = $cfield_mgr->get_by_name($name);
        if ($dup) {
            http_response_code(400);
            out(['status' => 'error', 'message' => 'Custom field name already exists']);
        }
    }

    $nodeTypeMap = $cfield_mgr->get_allowed_nodes();
    $nodeTypeName = $body['node_type'] ?? 'testcase';
    $nodeTypeId = $nodeTypeMap[$nodeTypeName] ?? $existing['node_type_id'];

    $cf = [
        'id' => $id,
        'name' => $name,
        'label' => $label,
        'type' => intval($body['type'] ?? $existing['type']),
        'possible_values' => $body['possible_values'] ?? $existing['possible_values'],
        'show_on_design' => intval($body['show_on_design'] ?? $existing['show_on_design']),
        'enable_on_design' => intval($body['enable_on_design'] ?? $existing['enable_on_design']),
        'show_on_execution' => intval($body['show_on_execution'] ?? $existing['show_on_execution']),
        'enable_on_execution' => intval($body['enable_on_execution'] ?? $existing['enable_on_execution']),
        'show_on_testplan_design' => intval($body['show_on_testplan_design'] ?? $existing['show_on_testplan_design']),
        'enable_on_testplan_design' => intval($body['enable_on_testplan_design'] ?? $existing['enable_on_testplan_design']),
        'node_type_id' => $nodeTypeId,
    ];

    $result = $cfield_mgr->update($cf);
    if ($result) {
        logAuditEvent("Custom field '$name' updated", "SAVE", $id, "custom_fields");
        $map = $cfield_mgr->get_by_id($id);
        out(['status' => 'ok', 'item' => cfToJSON($map[$id])]);
    } else {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Error updating custom field']);
    }
}

// Route: DELETE /{id} - delete custom field
if ($method === 'DELETE' && isset($segments[0]) && is_numeric($segments[0])) {
    $id = intval($segments[0]);
    $map = $cfield_mgr->get_by_id($id);
    if (!$map || !isset($map[$id])) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Custom field not found']);
    }

    $cf = $map[$id];
    $result = $cfield_mgr->delete($id);
    if ($result) {
        logAuditEvent("Custom field '{$cf['name']}' deleted", "DELETE", $id, "custom_fields");
        out(['status' => 'ok']);
    } else {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Error deleting custom field']);
    }
}

http_response_code(404);
out(['status' => 'error', 'message' => 'Not found']);
