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
$path = preg_replace('#^/api/codetracker(/index\.php)?#', '', $path);
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
    $serverUrl = '';
    if (!empty($item['cfg'])) {
        $m = [];
        if (preg_match('/<uribase>(.*?)<\/uribase>/', $item['cfg'], $m)) {
            $serverUrl = $m[1];
        }
    }

    // Extract GitHub-native config fields (issue #433).
    $github = ['repository' => '', 'branch' => '', 'token' => ''];
    if ($typeLabel === 'github' && !empty($item['cfg'])) {
        foreach (['repository' => 'repository', 'branch' => 'branch'] as $key => $tag) {
            if (preg_match('/<' . $tag . '>(.*?)<\/' . $tag . '>/s', $item['cfg'], $m)) {
                $github[$key] = $m[1];
            }
        }
        if (preg_match('/<token>(.*?)<\/token>/s', $item['cfg'], $m) && $m[1] !== '') {
            $github['token'] = '********';
        }
    }

    return [
        'id' => intval($item['id']),
        'name' => $item['name'],
        'type' => intval($item['type']),
        'typeLabel' => $typeLabel,
        'typeDescr' => $typeDescr,
        'cfg' => $item['cfg'] ?? '',
        'serverUrl' => $serverUrl,
        'github' => $github,
        'implementation' => $item['implementation'] ?? '',
    ];
}

$mgr = new tlCodeTracker($db);

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

if ($method === 'GET' && isset($segments[0]) && is_numeric($segments[0]) && count($segments) === 1) {
    $item = $mgr->getByID(intval($segments[0]));
    if (!$item) { http_response_code(404); out(['status' => 'error', 'message' => 'Code tracker not found']); }
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

    $ct = new stdClass();
    $ct->name = $name;
    $ct->type = $type;
    $ct->cfg = $cfg;

    $result = $mgr->create($ct);
    if ($result['status_ok']) {
        $item = $mgr->getByID($result['id']);
        out(['status' => 'ok', 'item' => trackerToJSON($item, $mgr)]);
    } else {
        http_response_code(400);
        out(['status' => 'error', 'message' => $result['msg']]);
    }
}

if ($method === 'PUT' && isset($segments[0]) && is_numeric($segments[0]) && count($segments) === 1) {
    $id = intval($segments[0]);
    $existing = $mgr->getByID($id);
    if (!$existing) { http_response_code(404); out(['status' => 'error', 'message' => 'Code tracker not found']); }

    $body = getBody();
    $ct = new stdClass();
    $ct->id = $id;
    $ct->name = isset($body['name']) ? trim($body['name']) : $existing['name'];
    $ct->type = isset($body['type']) ? intval($body['type']) : intval($existing['type']);
    $ct->cfg = isset($body['cfg']) ? $body['cfg'] : ($existing['cfg'] ?? '');

    $result = $mgr->update($ct);
    if ($result['status_ok']) {
        $item = $mgr->getByID($id);
        out(['status' => 'ok', 'item' => trackerToJSON($item, $mgr)]);
    } else {
        http_response_code(400);
        out(['status' => 'error', 'message' => $result['msg']]);
    }
}

// --- GitHub native interface endpoints (issue #433) ------------------------

// Instantiate a code-tracker interface from a stored tracker record.
function githubInterfaceFor($mgr, $id) {
    $tracker = $mgr->getByID($id);
    if (!$tracker) { return [null, 'Code tracker not found']; }
    $impl = $tracker['implementation'];
    if (!class_exists($impl)) { return [null, 'Code tracker implementation not found']; }
    try {
        $iface = new $impl($tracker['type'], $tracker['cfg'], $tracker['name']);
        return [$iface, null];
    } catch (Exception $e) {
        tLog(__METHOD__ . ' ' . $e->getMessage(), 'ERROR');
        return [null, $e->getMessage()];
    }
}

// Test a GitHub connection using inline (unsaved) config, so the create modal
// can verify before saving. Body: { repository, token, branch }.
if ($method === 'POST' && isset($segments[0]) && $segments[0] === 'test_github') {
    $body = getBody();
    $repository = trim($body['repository'] ?? '');
    $token = trim($body['token'] ?? '');
    $branch = trim($body['branch'] ?? '');

    if ($repository === '') {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Repository is required']);
    }

    $cfgObj = new stdClass();
    $cfgObj->repository = $repository;
    $cfgObj->token = $token;
    $cfgObj->branch = $branch;
    $cfgJson = json_encode($cfgObj);

    if (!class_exists('githubrestCodeTrackerInterface')) {
        http_response_code(500);
        out(['status' => 'error', 'message' => 'GitHub interface implementation not available']);
    }

    try {
        $iface = new githubrestCodeTrackerInterface('github', $cfgJson, 'test');
        $connected = $iface->isConnected();
        $branches = $connected ? $iface->getBranches() : false;
        out([
            'status' => $connected ? 'ok' : 'error',
            'connected' => $connected,
            'message' => $connected ? 'Connection OK' : 'Connection failed (check repository and token)',
            'branchCount' => is_array($branches) ? count($branches) : 0,
            'branches' => is_array($branches) ? array_values($branches) : [],
            'defaultBranch' => ($connected && is_array($branches) && count($branches) > 0) ? $branches[0] : '',
        ]);
    } catch (Exception $e) {
        tLog(__METHOD__ . ' ' . $e->getMessage(), 'ERROR');
        http_response_code(502);
        out(['status' => 'error', 'message' => 'Connection test failed']);
    }
}

if (($method === 'GET' || $method === 'POST') && isset($segments[0]) && is_numeric($segments[0]) &&
    isset($segments[1])) {
    $id = intval($segments[0]);
    $action = strtolower($segments[1]);
    $tracker = $mgr->getByID($id);
    if (!$tracker) { http_response_code(404); out(['status' => 'error', 'message' => 'Code tracker not found']); }

    list($iface, $err) = githubInterfaceFor($mgr, $id);
    if (!$iface) { http_response_code(400); out(['status' => 'error', 'message' => $err]); }

    switch ($action) {
        case 'branches':
            $branches = $iface->getBranches();
            if ($branches === false) { http_response_code(502); out(['status' => 'error', 'message' => 'Unable to fetch branches (check repository and token)']); }
            out(['status' => 'ok', 'items' => array_values($branches)]);
        case 'tags':
            $tags = $iface->getTags();
            if ($tags === false) { http_response_code(502); out(['status' => 'error', 'message' => 'Unable to fetch tags (check repository and token)']); }
            out(['status' => 'ok', 'items' => array_values($tags)]);
        case 'commits':
            $branch = $_GET['branch'] ?? null;
            $commits = $iface->getCommits($branch);
            if ($commits === false) { http_response_code(502); out(['status' => 'error', 'message' => 'Unable to fetch commits (check repository and token)']); }
            out(['status' => 'ok', 'items' => $commits]);
        case 'pulls':
            $state = $_GET['state'] ?? 'open';
            $pulls = $iface->getPullRequests($state);
            if ($pulls === false) { http_response_code(502); out(['status' => 'error', 'message' => 'Unable to fetch pull requests (check repository and token)']); }
            out(['status' => 'ok', 'items' => $pulls]);
        case 'test_connection':
            $connected = $iface->isConnected();
            out(['status' => $connected ? 'ok' : 'error',
                 'connected' => $connected,
                 'message' => $connected ? 'Connection OK' : 'Connection failed (check repository, branch and token)']);
        default:
            http_response_code(404);
            out(['status' => 'error', 'message' => 'Unknown action']);
    }
}

if ($method === 'DELETE' && isset($segments[0]) && is_numeric($segments[0])) {
    $id = intval($segments[0]);
    $existing = $mgr->getByID($id);
    if (!$existing) { http_response_code(404); out(['status' => 'error', 'message' => 'Code tracker not found']); }

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
