<?php
/**
 * Requirement Monitors popup BFF API
 * URL: /api/reqmonitors/index.php
 * Modern, rights-checked replacement of the legacy AJAX reader
 * lib/ajax/requirements/getreqmonitors.php (Refs #1780)
 */
require_once(__DIR__ . '/../../config.inc.php');
require_once(__DIR__ . '/../../config_db.inc.php');
require_once('common.php');

doSessionStart();
require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$db = new database(DB_TYPE);
doDBConnect($db);

function out($data) { echo json_encode($data); exit; }
function failOut($code, $message, $machine = '')
{
    http_response_code($code);
    $p = array('status' => 'error', 'message' => $message);
    if ($machine !== '') { $p['code'] = $machine; }
    out($p);
}

set_exception_handler(function ($e) {
    error_log('api/reqmonitors: ' . $e->getMessage());
    if (!headers_sent()) { http_response_code(500); }
    echo json_encode(array('status' => 'error', 'code' => 'server_error',
        'message' => 'Internal error'));
    exit;
});

$userId = isset($_SESSION['userID']) ? intval($_SESSION['userID']) : 0;
if ($userId <= 0) {
    http_response_code(401);
    out(array('status' => 'error', 'code' => 'not_authenticated',
        'message' => 'Not authenticated'));
}

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    http_response_code(401);
    out(array('status' => 'error', 'code' => 'not_authenticated',
        'message' => 'User not found'));
}

bffEnforceSession($db);

$reqMgr   = new requirement_mgr($db);
$tprojMgr = new testproject($db);

define('NODE_TYPE_TESTPROJECT',      1);
define('NODE_TYPE_REQUIREMENT_SPEC', 6);
define('NODE_TYPE_REQUIREMENT',      7);
define('NODE_TYPE_REQUIREMENT_VER',  8);

$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
$action = isset($_REQUEST['action']) ? trim((string)$_REQUEST['action']) : 'init';

function param($key, $default = 0)
{
    return array_key_exists($key, $_REQUEST) ? $_REQUEST[$key] : $default;
}

function needTprojectIdForReq($reqId)
{
    global $db, $reqMgr, $user;
    $rid = intval($reqId);
    if ($rid <= 0) {
        failOut(400, 'Invalid requirement id', 'invalid_requirement');
    }
    $rows = $db->get_recordset('SELECT srs_id FROM requirements WHERE id = ' . $rid);
    if (!$rows || !$rows[0]) {
        failOut(404, 'Requirement not found', 'requirement_not_found');
    }
    $srsId = intval($rows[0]['srs_id']);
    $specRows = $db->get_recordset('SELECT testproject_id FROM req_specs WHERE id = ' . $srsId);
    if (!$specRows || !$specRows[0]) {
        failOut(404, 'Requirement not found', 'requirement_not_found');
    }
    $tprojId = intval($specRows[0]['testproject_id']);
    if ($tprojId <= 0) {
        failOut(404, 'Requirement not found', 'requirement_not_found');
    }
    if (!($user->hasRight($db, 'mgt_view_req', $tprojId) ||
          $user->hasRight($db, 'mgt_modify_req', $tprojId))) {
        failOut(403, 'You are not authorized to view requirements', 'no_right');
    }
    $tproj = $GLOBALS['tprojMgr']->get_by_id($tprojId);
    if (is_null($tproj)) {
        failOut(404, 'Test project not found', 'tproject_not_found');
    }
    $reqTprojId = intval(param('tproject_id', 0));
    if ($reqTprojId > 0 && $reqTprojId !== $tprojId) {
        failOut(404, 'Test project mismatch', 'project_mismatch');
    }
    return array('tproject_id' => $tprojId, 'tproject' => $tproj, 'srs_id' => $srsId);
}

if ($action === 'init') {
    if ($method !== 'GET') {
        failOut(405, 'This action only accepts GET', 'wrong_method');
    }
    $reqId = intval(param('req_id', 0));
    if ($reqId <= 0) {
        failOut(400, 'Invalid requirement id', 'invalid_requirement');
    }
    $ctx = needTprojectIdForReq($reqId);
    $reqRows = $db->get_recordset('SELECT R.id, R.req_doc_id, NH.name AS title' .
        ' FROM requirements R' .
        ' JOIN nodes_hierarchy NH ON NH.id = R.id' .
        '   AND NH.node_type_id = ' . NODE_TYPE_REQUIREMENT .
        ' WHERE R.id = ' . $reqId);
    $reqTitle = ($reqRows && $reqRows[0]) ? (string)$reqRows[0]['title'] : '';
    $reqDocId = ($reqRows && $reqRows[0]) ? (string)$reqRows[0]['req_doc_id'] : '';

    // Latest requirement VERSION (node_type_id = 8 child of the requirement).
    // The legacy popup did not show a version at all; the modern popup needs it
    // because the requirement viewer is version-scoped, so an author can see
    // WHICH version the monitor set belongs to. req_versions carries the
    // version number, the status and the is_open flag; a requirement with no
    // version row yet answers nulls instead of a fatal (the #1660 lesson).
    $verRows = $db->get_recordset(
        'SELECT V.version, V.status, V.is_open, V.type' .
        ' FROM nodes_hierarchy VN' .
        ' LEFT JOIN req_versions V ON V.id = VN.id' .
        ' WHERE VN.parent_id = ' . $reqId .
        '   AND VN.node_type_id = ' . NODE_TYPE_REQUIREMENT_VER .
        ' ORDER BY VN.id DESC LIMIT 1');

    $monRaw = $reqMgr->getReqMonitors($reqId, array('output' => 'array'));
    $monitors = array();
    if (!empty($monRaw)) {
        foreach ($monRaw as $m) {
            $monitors[] = array(
                'user_id' => intval($m['user_id']),
                'login'   => (string)$m['login'],
                'is_me'   => (intval($m['user_id']) === $userId),
            );
        }
    }
    // The legacy DataTable had no explicit ordering, so it came back in MySQL
    // order (req_monitor is keyed on (req_id, user_id, testproject_id), i.e.
    // effectively by user id). Sorting by login is a deliberate improvement: the
    // popup is a name list, and a stable alphabetical order is what a reader
    // expects. Documented in docs/ + the test suite.
    usort($monitors, function ($a, $b) { return strcasecmp($a['login'], $b['login']); });

    $monitorSet = (array)$reqMgr->getReqMonitors($reqId);

    out(array(
        'status' => 'ok',
        'context' => array(
            'req_id'          => $reqId,
            'req_doc_id'      => $reqDocId,
            'req_title'       => $reqTitle,
            'tproject_id'     => $ctx['tproject_id'],
            'tproject_name'   => (string)$ctx['tproject']['name'],
            'prefix'          => (string)(isset($ctx['tproject']['prefix']) ? $ctx['tproject']['prefix'] : ''),
            'srs_id'          => $ctx['srs_id'],
            'version'         => ($verRows && $verRows[0] && $verRows[0]['version'] !== null)
                                    ? intval($verRows[0]['version']) : null,
            'version_status'  => ($verRows && $verRows[0] && $verRows[0]['status'] !== null)
                                    ? (string)$verRows[0]['status'] : '',
            'is_open'         => ($verRows && $verRows[0] && $verRows[0]['is_open'] !== null)
                                    ? (intval($verRows[0]['is_open']) === 1 ? 1 : 0) : 0,
            'has_version'     => ($verRows && $verRows[0] && $verRows[0]['version'] !== null) ? 1 : 0,
            'user_id'         => $userId,
            'user_login'      => (string)$user->login,
        ),
        'monitors' => $monitors,
        'total'    => count($monitors),
        // Whether the caller is one of the monitors. The legacy screen showed
        // only the list, but the requirement viewer already offers the
        // start/stop toggle, so the popup has to agree with it.
        'is_monitoring' => isset($monitorSet[$userId]) ? 1 : 0,
        'grant' => array(
            // monitor_requirement is the right that gates the legacy
            // {$gui->grants->monitor_req == "yes"} include of reqMonitors.tpl
            // (reqViewVersions.tpl:436-438). Keep it in the payload so the screen
            // can explain the empty state instead of showing an empty table.
            'monitor' => (bool)$user->hasRight($db, 'monitor_requirement', $ctx['tproject_id']),
            'modify'  => (bool)$user->hasRight($db, 'mgt_modify_req', $ctx['tproject_id']),
        ),
    ));
}
failOut(400, 'Unknown action', 'unknown_action');
