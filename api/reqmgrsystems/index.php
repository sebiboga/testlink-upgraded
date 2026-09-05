<?php
/**
 * Requirement Management Systems BFF API
 * URL: /api/reqmgrsystems/
 * Plain PHP, no framework, no compilation
 *
 * Mirrors lib/reqmgrsystems/reqMgrSystemView.php + reqMgrSystemEdit.php +
 * lib/ajax/getreqmgrsystemcfgtemplate.php (TestLink 1.9.20 behavior).
 *
 * Rights split (same as the legacy screens):
 *   view    -> reqmgrsystem_view OR reqmgrsystem_management
 *   manage  -> reqmgrsystem_management
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
$path = preg_replace('#^/api/reqmgrsystems(/index\.php)?#', '', $path);
$path = '/' . trim($path, '/');
$method = $_SERVER['REQUEST_METHOD'];
$segments = array_values(array_filter(explode('/', $path)));

function out($data) { echo json_encode($data); exit; }
function getBody() { return json_decode(file_get_contents('php://input'), true) ?? []; }

function denied($msg = 'Forbidden') {
    http_response_code(403);
    out(['status' => 'error', 'message' => $msg]);
}

function canView(&$db, &$user) {
    return $user->hasRight($db, 'reqmgrsystem_view') ||
           $user->hasRight($db, 'reqmgrsystem_management');
}

function canManage(&$db, &$user) {
    return $user->hasRight($db, 'reqmgrsystem_management');
}

$mgr = new tlReqMgrSystem($db);

function systemToJSON($item, $mgr, $withCfg = true) {
    $typeDescr = isset($mgr->types[$item['type']]) ? $mgr->types[$item['type']] : '';
    $typeLabel = '';
    $api = '';
    if (isset($mgr->systems[$item['type']])) {
        $typeLabel = $mgr->systems[$item['type']]['type'];
        $api = $mgr->systems[$item['type']]['api'];
    }
    $ret = [
        'id' => intval($item['id']),
        'name' => $item['name'],
        'type' => intval($item['type']),
        'typeLabel' => $typeLabel,
        'typeApi' => $api,
        'typeDescr' => $typeDescr,
        'link_count' => isset($item['link_count']) ? intval($item['link_count']) : 0,
        'env_check_ok' => isset($item['env_check_ok']) ? (bool)$item['env_check_ok'] : true,
        'env_check_msg' => $item['env_check_msg'] ?? '',
        'connection_status' => $item['connection_status'] ?? '',
        'implementation' => $item['implementation'] ?? '',
    ];
    if ($withCfg) {
        $ret['cfg'] = $item['cfg'] ?? '';
    }
    return $ret;
}

// ----- List all requirement management systems (view) -----------------------
if ($method === 'GET' && ($path === '/' || $path === '' || $path === '/index.php')) {
    if (!canView($db, $user)) { denied(); }
    // Env probe is done here (not via getAll checkEnv=true) so the probe of a
    // missing interface implementation does not autoload-fail and spam the
    // events table with E_WARNING "Failed opening ...class.php".
    $all = $mgr->getAll(['output' => 'add_link_count']);
    $items = [];
    if ($all) {
        foreach ($all as &$item) {
            $impl = $mgr->getImplementationForType($item['type']);
            $item['env_check_ok'] = true;
            $item['env_check_msg'] = '';
            $item['connection_status'] = '';
            if (@class_exists($impl) && method_exists($impl, 'checkEnv')) {
                $dummy = call_user_func([$impl, 'checkEnv']);
                $item['env_check_ok'] = !empty($dummy['status']);
                $item['env_check_msg'] = isset($dummy['msg']) ? $dummy['msg'] : '';
            }
        }
        unset($item);
        foreach ($all as $item) {
            $items[] = systemToJSON($item, $mgr, false);
        }
    }
    out([
        'status' => 'ok',
        'items' => $items,
        'total' => count($items),
        'canManage' => (bool)canManage($db, $user),
    ]);
}

// ----- Types domain (view) --------------------------------------------------
if ($method === 'GET' && isset($segments[0]) && $segments[0] === 'meta' &&
    isset($segments[1]) && $segments[1] === 'types') {
    if (!canView($db, $user)) { denied(); }
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

// ----- Config template example for a type (view) ----------------------------
// Mirrors lib/ajax/getreqmgrsystemcfgtemplate.php. Note: when no interface
// implementation file exists, legacy returns the "interface not implemented"
// message - we do the same.
if ($method === 'GET' && isset($segments[0]) && $segments[0] === 'meta' &&
    isset($segments[1]) && $segments[1] === 'cfg_template') {
    if (!canView($db, $user)) { denied(); }
    $type = intval($_GET['type'] ?? 0);
    $types = $mgr->getTypes();
    if (!isset($types[$type])) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid type']);
    }
    $iname = $mgr->getImplementationForType($type);
    if (@stream_resolve_include_path($iname . '.class.php') === false) {
        http_response_code(200);
        out(['status' => 'ok', 'cfg' => '', 'available' => false,
             'message' => 'Interface ' . $iname . ' not implemented']);
    }
    try {
        $cfg = call_user_func([$iname, 'getCfgTemplate']);
        out(['status' => 'ok', 'cfg' => $cfg, 'available' => true, 'message' => '']);
    } catch (\Throwable $e) {
        tLog('reqmgrsystems cfg_template: ' . $e->getMessage(), 'ERROR');
        out(['status' => 'ok', 'cfg' => '', 'available' => false,
             'message' => 'Interface ' . $iname . ' not implemented']);
    }
}

// ----- Single item + linked test projects (view) ----------------------------
// Dead-link cleanup matches the legacy edit path (initializeGui): stale links
// to deleted test projects are removed before the link list is read.
if ($method === 'GET' && isset($segments[0]) && is_numeric($segments[0]) && count($segments) === 1) {
    if (!canView($db, $user)) { denied(); }
    $id = intval($segments[0]);
    $item = $mgr->getByID($id);
    if (!$item) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Requirement management system not found']);
    }

    $dummy = $mgr->getLinks($id, ['getDeadLinks' => true]);
    if (!is_null($dummy)) {
        foreach ($dummy as $tprojectID => $elem) {
            $mgr->unlink($id, $tprojectID);
        }
    }
    $links = $mgr->getLinks($id);
    $projects = [];
    if (!is_null($links)) {
        foreach ($links as $tprojectID => $elem) {
            $projects[] = [
                'testproject_id' => intval($tprojectID),
                'testproject_name' => $elem['testproject_name'],
            ];
        }
    }
    $json = systemToJSON($item, $mgr, (bool)canManage($db, $user));
    $json['testprojects'] = $projects;
    out(['status' => 'ok', 'item' => $json]);
}

// ----- Create (manage) -------------------------------------------------------
if ($method === 'POST' && empty($segments)) {
    if (!canManage($db, $user)) { denied(); }
    $body = getBody();
    $name = trim($body['name'] ?? '');
    $type = intval($body['type'] ?? 0);
    $cfg = $body['cfg'] ?? '';

    if ($name === '') {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Name is required']);
    }

    if (!isset($mgr->types[$type])) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid type']);
    }

    $system = new stdClass();
    $system->name = $name;
    $system->type = $type;
    $system->cfg = $cfg;

    $result = $mgr->create($system);
    if ($result['status_ok']) {
        $item = $mgr->getByID($result['id']);
        out(['status' => 'ok', 'item' => systemToJSON($item, $mgr)]);
    }
    http_response_code(409);
    out(['status' => 'error', 'message' => $result['msg']]);
}

// ----- Update (manage) -------------------------------------------------------
if ($method === 'PUT' && isset($segments[0]) && is_numeric($segments[0]) && count($segments) === 1) {
    if (!canManage($db, $user)) { denied(); }
    $id = intval($segments[0]);
    $existing = $mgr->getByID($id);
    if (!$existing) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Requirement management system not found']);
    }

    $body = getBody();
    $system = new stdClass();
    $system->id = $id;
    $system->name = isset($body['name']) ? trim($body['name']) : $existing['name'];
    $system->type = isset($body['type']) ? intval($body['type']) : intval($existing['type']);
    $system->cfg = isset($body['cfg']) ? $body['cfg'] : ($existing['cfg'] ?? '');

    if ($system->name === '') {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Name is required']);
    }

    if (!isset($mgr->types[$system->type])) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid type']);
    }

    $result = $mgr->update($system);
    if ($result['status_ok']) {
        $item = $mgr->getByID($id);
        out(['status' => 'ok', 'item' => systemToJSON($item, $mgr)]);
    }
    http_response_code(409);
    out(['status' => 'error', 'message' => $result['msg']]);
}

// ----- Test connection (view) ------------------------------------------------
// Legacy: clicking a system name from the view reloads the page with
// id=<n> and the controller runs checkConnection(). The contour SOAP
// interface class is not shipped, so this may not be instantiable - the
// legacy screen then fatal-errors; we return a clean "not implemented"
// failure instead.
if ($method === 'POST' && isset($segments[0]) && is_numeric($segments[0]) &&
    isset($segments[1]) && $segments[1] === 'check_connection' && count($segments) === 2) {
    if (!canView($db, $user)) { denied(); }
    $id = intval($segments[0]);
    $item = $mgr->getByID($id);
    if (!$item) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Requirement management system not found']);
    }
    $impl = $item['implementation'];
    if (!@class_exists($impl)) {
        http_response_code(200);
        out(['status' => 'error', 'connected' => false,
             'message' => 'Interface ' . $impl . ' not implemented']);
    }
    try {
        $connected = $mgr->checkConnection($id);
        out(['status' => $connected ? 'ok' : 'error',
             'connected' => (bool)$connected,
             'message' => $connected ? 'Connection OK' : 'Connection failed']);
    } catch (\Throwable $e) {
        tLog('reqmgrsystems check_connection id=' . $id . ': ' . $e->getMessage(), 'ERROR');
        http_response_code(200);
        out(['status' => 'error', 'connected' => false, 'message' => $e->getMessage()]);
    }
}

// ----- Delete (manage) -------------------------------------------------------
if ($method === 'DELETE' && isset($segments[0]) && is_numeric($segments[0]) && count($segments) === 1) {
    if (!canManage($db, $user)) { denied(); }
    $id = intval($segments[0]);
    $existing = $mgr->getByID($id);
    if (!$existing) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Requirement management system not found']);
    }

    $result = $mgr->delete($id);
    if ($result['status_ok']) {
        out(['status' => 'ok', 'item' => systemToJSON($existing, $mgr)]);
    }
    http_response_code(409);
    out(['status' => 'error', 'message' => $result['msg']]);
}

http_response_code(404);
out(['status' => 'error', 'message' => 'Not found']);