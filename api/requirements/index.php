<?php
/**
 * Requirements Monitor Overview BFF API
 * URL: /api/requirements/
 * Plain PHP, no framework, no compilation
 *
 * Endpoints:
 *   GET  /api/requirements/monitor-overview?tprojectId=N  -> all project requirements + monitored flag
 *   POST /api/requirements/monitor                        -> {reqId, action: 'on'|'off'}
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
require_once('requirements.inc.php');

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
$path = preg_replace('#^/api/requirements(/index\.php)?#', '', $path);
$path = '/' . trim($path, '/');
$method = $_SERVER['REQUEST_METHOD'];
$segments = array_values(array_filter(explode('/', $path)));

function out($data) { echo json_encode($data); exit; }
function getParam($key, $default = null) { return $_GET[$key] ?? $default; }
function getBody() { return json_decode(file_get_contents('php://input'), true) ?? []; }

$tproject_mgr = new testproject($db);
$req_mgr = new requirement_mgr($db);

/**
 * Resolve target test project: query param wins, falls back to session project.
 */
function resolveTprojectId($tproject_mgr) {
    $tpid = intval(getParam('tprojectId', 0));
    if ($tpid <= 0) {
        $body = getBody();
        $tpid = intval($body['tprojectId'] ?? 0);
    }
    if ($tpid <= 0) {
        $tpid = intval($_SESSION['testprojectID'] ?? 0);
    }
    if ($tpid <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Test project is mandatory']);
    }
    $item = $tproject_mgr->get_by_id($tpid);
    if (!$item) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Test project not found']);
    }
    return [$tpid, $item];
}

// Route: GET /monitor-overview - list all requirements with monitor state
if ($method === 'GET' && isset($segments[0]) && $segments[0] === 'monitor-overview') {
    if (!$user->hasRight($db, 'mgt_view_req')) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }

    list($tpid, $item) = resolveTprojectId($tproject_mgr);

    $reqIDSet = $tproject_mgr->get_all_requirement_ids($tpid);
    $items = [];
    if (!is_null($reqIDSet) && count($reqIDSet) > 0) {
        $reqSet = $req_mgr->getByIDBulkLatestVersionRevision(
            $reqIDSet, ['outputFormat' => 'mapOfArray']);
        $monitoredSet = $req_mgr->getMonitoredByUser($userId, $tpid);

        $pathCache = [];
        foreach ($reqIDSet as $id) {
            if (!isset($reqSet[$id]) || !isset($reqSet[$id][0])) {
                continue;
            }
            $req = $reqSet[$id][0];

            // req spec path (cached per spec)
            $srsId = intval($req['srs_id']);
            if (!isset($pathCache[$srsId])) {
                $pathNames = [];
                $pset = $req_mgr->tree_mgr->get_path($srsId);
                if (is_array($pset)) {
                    foreach ($pset as $p) {
                        $pathNames[] = $p['name'];
                    }
                }
                $pathCache[$srsId] = implode(' / ', $pathNames);
            }

            $items[] = [
                'id' => intval($req['id']),
                'req_doc_id' => $req['req_doc_id'],
                'title' => $req['title'],
                'srs_id' => $srsId,
                'spec_path' => $pathCache[$srsId],
                'version_id' => intval($req['version_id']),
                'version' => intval($req['version']),
                'creation_ts' => $req['creation_ts'],
                'author' => $req['author'],
                'monitored' => isset($monitoredSet[$req['id']]),
            ];
        }
    }

    usort($items, function ($a, $b) {
        return strcmp($a['spec_path'], $b['spec_path']) ?: strcasecmp($a['title'], $b['title']);
    });

    out([
        'status' => 'ok',
        'items' => $items,
        'total' => count($items),
        'tproject_id' => $tpid,
        'tproject_name' => $item['name'],
    ]);
}

// Route: POST /monitor - subscribe/unsubscribe current user on a requirement
if ($method === 'POST' && isset($segments[0]) && $segments[0] === 'monitor') {
    if (!$user->hasRight($db, 'mgt_view_req')) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }

    list($tpid, $dummy) = resolveTprojectId($tproject_mgr);

    $body = getBody();
    $reqId = intval($body['reqId'] ?? 0);
    $action = trim($body['action'] ?? '');

    if ($reqId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'reqId invalid']);
    }
    if (!in_array($action, ['on', 'off'])) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'action must be on|off']);
    }

    // requirement must exist inside this project
    $reqInfo = $req_mgr->get_by_id($reqId);
    if (!$reqInfo || intval($reqInfo['tproject_id']) !== $tpid) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Requirement not found in this project']);
    }

    if ($action === 'on') {
        $req_mgr->monitorOn($reqId, $userId, $tpid);
    } else {
        $req_mgr->monitorOff($reqId, $userId, $tpid);
    }

    $monitoredSet = $req_mgr->getMonitoredByUser($userId, $tpid);
    out([
        'status' => 'ok',
        'reqId' => $reqId,
        'action' => $action,
        'monitored' => isset($monitoredSet[$reqId]),
    ]);
}

http_response_code(404);
out(['status' => 'error', 'message' => 'Unknown endpoint']);
