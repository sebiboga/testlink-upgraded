<?php
/**
 * Test Case Bulk Operations BFF API
 * URL: /api/tcbulkop/
 * Plain PHP, no framework.
 *
 * Mirrors lib/testcases/tcBulkOp.php (TestLink 1.9.20 behavior): for a single
 * test case, apply one or more of status / importance / execution_type to ALL
 * of its versions at once (where > 0 means "set"), optionally overriding the
 * frozen (is_open=0) versions guard.
 *
 * The legacy controller had no explicit menu right (any authenticated session
 * via testlinkInitPage). The modern screen gates the WRITE on mgt_modify_tc on
 * the OWNING test project (the legacy viewer only rendered the bulk button when
 * edit_enabled && bulk allowed, which comes from mgt_modify_tc); the read
 * (init) route is left to any authenticated session in the project context so
 * a view-only user sees the form but cannot submit (matching the viewer).
 *
 * Routes:
 *   GET  /?action=init&tcase_id=N[&tproject_id=M]
 *   POST /?action=apply  {tcase_id, tproject_id, status, importance,
 *                         execution_type, forceFrozenTestcasesVersions}
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');

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
$path = preg_replace('#^/api/tcbulkop(/index\.php)?#', '', $path);
$path = '/' . trim($path, '/');
$method = $_SERVER['REQUEST_METHOD'];
$segments = array_values(array_filter(explode('/', $path)));

function out($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}
function bffBody() {
    static $body = null;
    if ($body === null) {
        $j = json_decode(file_get_contents('php://input'), true);
        $body = is_array($j) ? $j : [];
    }
    return $body;
}
function getParam($key, $default = null) {
    if (isset($_GET[$key])) { return $_GET[$key]; }
    if (isset($_POST[$key])) { return $_POST[$key]; }
    $body = bffBody();
    if (isset($body[$key])) { return $body[$key]; }
    return $default;
}
function failsafeShutdown() { exit; }
register_shutdown_function('failsafeShutdown');

$action = isset($_GET['action']) ? $_GET['action']
    : ($segments[0] ?? (bffBody()['action'] ?? null));

// Resolve the owning test project for a tcase id by walking nodes_hierarchy,
// falling back to an explicit tproject_id when supplied.
function resolveTproject(&$db, $tcase_id, $tproject_id) {
    $tprojId = intval($tproject_id);
    if ($tprojId <= 0) {
        $walk = intval($tcase_id);
        $guard = 60;
        while ($walk > 0 && $guard-- > 0) {
            $parent = intval($db->fetchFirstRowSingleColumn(
                "SELECT parent_id FROM nodes_hierarchy WHERE id = {$walk}",
                'parent_id'));
            if ($parent == 0) { break; }
            $nt = intval($db->fetchFirstRowSingleColumn(
                "SELECT node_type_id FROM nodes_hierarchy WHERE id = {$parent}",
                'node_type_id'));
            if ($nt == 1) { $tprojId = $parent; break; }
            $walk = $parent;
        }
    }
    return $tprojId;
}

// Build localized label maps for the three bulk domains, matching the legacy
// controller (lib/testcases/tcBulkOp.php init_args/initializeGui).
function buildDomains(&$db) {
    // getConfigAndLabels('testCaseStatus','code') returns ['cfg'=>..,'lbl'=>..]
    // where 'lbl' maps code => lang_get('testCaseStatus_<accessKey>') already
    // localized (exactly what the legacy tcBulkOp.php used for its status
    // dropdown). The 'code' access mode keys the label map by the code (1..7).
    $dummy = getConfigAndLabels('testCaseStatus', 'code');
    $statusMap = $dummy['lbl'];

    $importanceMap = [];
    $impCfg = config_get('importance');
    foreach (($impCfg['code_label'] ?? []) as $code => $label) {
        $importanceMap[$code] = lang_get($label);
    }

    // get_execution_types() already returns *localized* values keyed by code
    // (1 => lang_get('manual'), ...), so use them directly (no re-wrap).
    $tcaseMgr = new testcase($db);
    $execMap = [];
    foreach ($tcaseMgr->get_execution_types() as $code => $localized) {
        $execMap[$code] = $localized;
    }

    return [$statusMap, $importanceMap, $execMap];
}

if ($method !== 'GET' && $method !== 'POST') {
    out(['status' => 'error', 'message' => 'Method not allowed'], 405);
}

if ($action === 'init') {
    $tcase_id = intval(getParam('tcase_id', 0));
    if ($tcase_id <= 0) {
        out(['status' => 'error', 'message' => 'Missing test case id'], 400);
    }
    if ($method !== 'GET') {
        out(['status' => 'error', 'message' => 'Method not allowed'], 405);
    }

    $tprojId = resolveTproject($db, $tcase_id, getParam('tproject_id', 0));
    if ($tprojId <= 0) {
        out(['status' => 'error', 'message' => 'Test case not found'], 404);
    }

    $tcaseMgr = new testcase($db);
    $tprojectMgr = new testproject($db);

    // Owning project name + prefix for the header.
    $projName = '';
    $prefix = '';
    $tprojInfo = $tprojectMgr->get_by_id($tprojId);
    if (!is_null($tprojInfo) && isset($tprojInfo['name'])) {
        $projName = $tprojInfo['name'];
    }
    try {
        $prefix = $tprojectMgr->getTestCasePrefix($tprojId);
    } catch (\Exception $e) {
        $prefix = '';
    }

    // Test case identity (name, external id) from get_by_id ; version/frozen
    // counts come straight from tcversions (is_open) so they are exact even
    // when the 'essential' output omits lifecycle columns.
    $options = ['output' => 'essential'];
    $all = $tcaseMgr->get_by_id($tcase_id, testcase::ALL_VERSIONS, null, $options);
    $name = '';
    $extId = '';
    $prefix2 = ($prefix !== '') ? $prefix : $tprojectMgr->getTestCasePrefix($tprojId);
    $glue = config_get('testcase_cfg')->glue_character;
    if (!is_null($all)) {
        $first = null;
        foreach ($all as $tcvInfo) {
            if ($first === null) { $first = $tcvInfo; }
        }
        if ($first !== null) {
            $name = $first['name'];
            if (isset($first['full_tc_external_id']) && $first['full_tc_external_id'] !== '') {
                $extId = $first['full_tc_external_id'];
            } else {
                $extId = isset($first['tc_external_id']) ? $first['tc_external_id'] : '';
                if ($prefix2 !== '') { $extId = $prefix2 . $glue . $extId; }
            }
        }
    }

    // Version rows (number + open/frozen) via tcversions joined to the tcase's
    // version child nodes (same relationship setIntAttrForAllVersions uses).
    $versionCount = 0;
    $activeCount = 0;
    $frozenCount = 0;
    $versions = [];
    $vSet = $db->get_recordset(
        "SELECT v.id AS tcversion_id, v.version, v.is_open
           FROM tcversions v
           JOIN nodes_hierarchy nh ON nh.id = v.id
          WHERE nh.parent_id = " . intval($tcase_id) .
        " ORDER BY v.version");
    if (!empty($vSet)) {
        $versionCount = count($vSet);
        foreach ($vSet as $vrow) {
            $isOpen = intval($vrow['is_open'] ?? 1) == 1;
            if ($isOpen) { $activeCount++; } else { $frozenCount++; }
            $versions[] = [
                'tcversion_id' => intval($vrow['tcversion_id']),
                'version' => intval($vrow['version']),
                'is_open' => $isOpen,
            ];
        }
    }
    if ($versionCount == 0) {
        out(['status' => 'error', 'message' => 'Test case not found'], 404);
    }

    list($statusMap, $importanceMap, $execMap) = buildDomains($db);

    $canModify = ($user->hasRight($db, 'mgt_modify_tc', $tprojId) === 'yes');

    out([
        'status' => 'ok',
        'context' => [
            'tcase_id' => $tcase_id,
            'tproject_id' => $tprojId,
            'tproject_name' => $projName,
            'external_id' => $extId,
            'name' => $name,
            'version_count' => $versionCount,
            'active_count' => $activeCount,
            'frozen_count' => $frozenCount,
            'versions' => $versions,
        ],
        'domains' => [
            'status' => $statusMap,
            'importance' => $importanceMap,
            'execution_type' => $execMap,
        ],
        'grants' => ['can_modify' => $canModify],
    ]);
}

if ($action === 'apply') {
    if ($method !== 'POST') {
        out(['status' => 'error', 'message' => 'Method not allowed'], 405);
    }
    $tcase_id = intval(getParam('tcase_id', 0));
    if ($tcase_id <= 0) {
        out(['status' => 'error', 'message' => 'Missing test case id'], 400);
    }

    $tprojId = resolveTproject($db, $tcase_id, getParam('tproject_id', 0));
    if ($tprojId <= 0) {
        out(['status' => 'error', 'message' => 'Test case not found'], 404);
    }

    // The bulk op MODIFIES test cases -> require mgt_modify_tc on the owning
    // project. The modern screen hides controls for non-granted users too.
    if ($user->hasRight($db, 'mgt_modify_tc', $tprojId) !== 'yes') {
        out(['status' => 'error', 'message' => 'No permission'], 403);
    }

    $tcaseMgr = new testcase($db);
    $all = $tcaseMgr->get_by_id($tcase_id, testcase::ALL_VERSIONS, null, ['output' => 'essential']);
    if (is_null($all) || count($all) == 0) {
        out(['status' => 'error', 'message' => 'Test case not found'], 404);
    }

    $forceFrozen = intval(getParam('forceFrozenTestcasesVersions', 0)) > 0;

    // Only these three integer attributes may ever be written; the value is
    // whitelisted to > 0 (the -1/0 sentinel from the UI means "no change") and
    // passed through prepare_int via the class method.
    $choices = [
        'status' => intval(getParam('status', -1)),
        'importance' => intval(getParam('importance', -1)),
        'execution_type' => intval(getParam('execution_type', -1)),
    ];

    $applied = [];
    try {
        foreach ($choices as $attr => $value) {
            if ($value > 0) {
                $tcaseMgr->setIntAttrForAllVersions($tcase_id, $attr, $value, $forceFrozen);
                $applied[$attr] = $value;
            }
        }
    } catch (\Throwable $e) {
        out(['status' => 'error', 'message' => 'Apply failed'], 500);
    }

    out([
        'status' => 'ok',
        'message' => 'Bulk operation applied',
        'tcase_id' => $tcase_id,
        'tproject_id' => $tprojId,
        'applied' => $applied,
        'force_frozen' => $forceFrozen,
    ]);
}

out(['status' => 'error', 'message' => 'Unknown action'], 404);
