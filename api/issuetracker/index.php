<?php
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

$path = $_SERVER['PATH_INFO'] ?? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = preg_replace('#^/api/issuetracker(/index\.php)?#', '', $path);
$path = '/' . trim($path, '/');
$method = $_SERVER['REQUEST_METHOD'];
$segments = array_values(array_filter(explode('/', $path)));

function out($data) { echo json_encode($data); exit; }
function getBody() { return json_decode(file_get_contents('php://input'), true) ?? []; }

function trackerToJSON($item, $mgr) {
    $typeDescr = '';
    if (isset($mgr->types[$item['type']])) {
        $typeDescr = $mgr->types[$item['type']];
    }
    $typeLabel = '';
    if (isset($mgr->systems[$item['type']])) {
        $spec = $mgr->systems[$item['type']];
        $typeLabel = $spec['type'];
    }
    return [
        'id' => intval($item['id']),
        'name' => $item['name'],
        'type' => intval($item['type']),
        'typeLabel' => $typeLabel,
        'typeDescr' => $typeDescr,
        'cfg' => $item['cfg'] ?? '',
        'implementation' => $item['implementation'] ?? '',
    ];
}

$mgr = new tlIssueTracker($db);

if ($method === 'GET' && ($path === '/' || $path === '' || $path === '/index.php')) {
    $all = $mgr->getAll(['output' => 'add_link_count']);
    $items = [];
    if ($all) {
        foreach ($all as $item) {
            $items[] = trackerToJSON($item, $mgr);
        }
    }
    out(['status' => 'ok', 'items' => $items, 'total' => count($items)]);
}

if ($method === 'GET' && isset($segments[0]) && $segments[0] === 'meta' && isset($segments[1]) && $segments[1] === 'types') {
    $systems = $mgr->getSystems(['status' => 'all']);
    $items = [];
    foreach ($systems as $code => $spec) {
        $items[] = [
            'code' => intval($code),
            'type' => $spec['type'],
            'api' => $spec['api'],
            'enabled' => $spec['enabled'],
            'label' => $spec['type'] . ' (Interface: ' . $spec['api'] . ')',
        ];
    }
    out(['status' => 'ok', 'items' => $items]);
}

if ($method === 'GET' && isset($segments[0]) && is_numeric($segments[0])) {
    $item = $mgr->getByID(intval($segments[0]));
    if (!$item) { http_response_code(404); out(['status' => 'error', 'message' => 'Issue tracker not found']); }
    out(['status' => 'ok', 'item' => trackerToJSON($item, $mgr)]);
}

if ($method === 'POST' && empty($segments)) {
    $body = getBody();
    $name = trim($body['name'] ?? '');
    $type = intval($body['type'] ?? 0);
    $cfg = $body['cfg'] ?? '';

    if (empty($name)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Name is required']);
    }

    $it = new stdClass();
    $it->name = $name;
    $it->type = $type;
    $it->cfg = $cfg;

    $result = $mgr->create($it);
    if ($result['status_ok']) {
        $item = $mgr->getByID($result['id']);
        out(['status' => 'ok', 'item' => trackerToJSON($item, $mgr)]);
    } else {
        http_response_code(400);
        out(['status' => 'error', 'message' => $result['msg']]);
    }
}

if ($method === 'PUT' && isset($segments[0]) && is_numeric($segments[0])) {
    $id = intval($segments[0]);
    $existing = $mgr->getByID($id);
    if (!$existing) { http_response_code(404); out(['status' => 'error', 'message' => 'Issue tracker not found']); }

    $body = getBody();
    $it = new stdClass();
    $it->id = $id;
    $it->name = isset($body['name']) ? trim($body['name']) : $existing['name'];
    $it->type = isset($body['type']) ? intval($body['type']) : intval($existing['type']);
    $it->cfg = isset($body['cfg']) ? $body['cfg'] : ($existing['cfg'] ?? '');

    $result = $mgr->update($it);
    if ($result['status_ok']) {
        $item = $mgr->getByID($id);
        out(['status' => 'ok', 'item' => trackerToJSON($item, $mgr)]);
    } else {
        http_response_code(400);
        out(['status' => 'error', 'message' => $result['msg']]);
    }
}

if ($method === 'DELETE' && isset($segments[0]) && is_numeric($segments[0])) {
    $id = intval($segments[0]);
    $existing = $mgr->getByID($id);
    if (!$existing) { http_response_code(404); out(['status' => 'error', 'message' => 'Issue tracker not found']); }

    $result = $mgr->delete($id);
    if ($result['status_ok']) {
        out(['status' => 'ok', 'item' => trackerToJSON($existing, $mgr)]);
    } else {
        http_response_code(400);
        out(['status' => 'error', 'message' => $result['msg']]);
    }
}

http_response_code(404);
out(['status' => 'error', 'message' => 'Not found']);
