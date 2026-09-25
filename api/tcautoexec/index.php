<?php
/**
 * api/tcautoexec — Remote Test Automation Execution BFF (Refs #1587)
 *
 * Modernizes the last standalone lib/testcases/* controller that had no modern
 * twin: lib/testcases/tcExecute.php ("Handles testcase execution through AJAX
 * calls ... executed on a remote server, response sent back via an XML-RPC
 * server"), plus its helper lib/functions/remote_exec.php.
 *
 * Legacy parity
 * -------------
 *  - The automation server endpoint is described by THREE custom fields,
 *    server_host / server_port / server_path, which may be defined at testcase
 *    level (custom field names prefixed 'tc_') and/or at testsuite level
 *    (prefixed 'tsuite_'), exactly as documented in the legacy header.
 *  - level=testcase   -> run that single test case (remote_exec_testcase)
 *    level=testsuite  -> run every test case of the node subtree
 *    level=testproject-> run every test case of the whole project
 *  - Per test case the legacy controller printed the server 'message', the
 *    'result' and the 'notes', and when the server configuration could not be
 *    resolved it printed the 'check_test_automation_server' hint row instead
 *    (configProblems / connectionFailure system status).
 *  - The actual XML-RPC call is still made by
 *    lib/functions/remote_exec.php::executeTestCase() (method 'executeTestCase',
 *    executionMode 'now'), with context testProjectID / testPlanID / platformID
 *    / buildID — so the wire protocol with the automation server is unchanged.
 *
 * Improvements over the 1.9.20 controller (all of them defect fixes)
 * ------------------------------------------------------------------
 *  1. lib/testcases/tcExecute.php was DEAD CODE on this branch: it called
 *     executeTestCase($tcase_id, $tree_manager, $cfield_manager) while the
 *     helper signature has been executeTestCase($tcaseInfo,$serverCfg,$context)
 *     since 2011, so the screen fataled (ArgumentCountError) for every request.
 *     The resolution of $serverCfg / $context now happens here, where it can be
 *     unit-reasoned about and returns JSON instead of raw HTML.
 *  2. remote_exec_testcase_set() used tree::get_subtree() NON-recursively, so
 *     only the direct children of a suite were executed; nested suites (and
 *     their test cases) were silently skipped. The modern screen recurses.
 *  3. remote_exec.php dereferenced array_flip(status_code)[$code] unguarded:
 *     a remote server answering with an unknown result code produced a PHP 8
 *     warning + null label. Guarded (Refs #1588).
 *  4. No rights check at all in the legacy controller. The modern routes are
 *     gated on 'mgt_view_tc' of the owning test project (fail closed).
 *  5. No CSRF/method/content guard, no JSON, no i18n, no styling.
 *
 * Routes
 * ------
 *   GET  ?action=init&tproject_id=N[&tplan_id=][&build_id=][&platform_id=]
 *        -> context (project, plans, builds, platforms) + the runnable node
 *           list with the resolved automation server of every node.
 *        401 anon, 403 without mgt_view_tc, 404 unknown project,
 *        400 missing/unknown params, 405 non-GET.
 *   POST ?action=run {level, node_id, tproject_id, tplan_id, build_id, platform_id}
 *        -> per test case {system.status, system.msg, result, resultVerbose,
 *           notes, message, scheduled, timestampISO} + summary counters.
 *        400 bad level/node, 404 unknown node, 403 no rights, 403 CSRF.
 *
 * Session-based auth, JSON I/O, no Smarty.
 */
require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

require_once(TL_ABS_PATH . 'third_party/xml-rpc/class-IXR.php');
require_once(TL_ABS_PATH . 'lib/functions/remote_exec.php');
require_once(TL_ABS_PATH . 'lib/functions/tree.class.php');
require_once(TL_ABS_PATH . 'lib/functions/cfield_mgr.class.php');
require_once(TL_ABS_PATH . 'lib/functions/testcase.class.php');

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$db = new database(DB_TYPE);
doDBConnect($db);

/** JSON terminator used by every exit path. */
function taeOut($data, $code = null)
{
    if (!is_null($code)) {
        http_response_code($code);
    }
    echo json_encode($data);
    exit;
}

// --- Session auth (same contract as every other BFF) ----------------------
$userId = isset($_SESSION['userID']) ? intval($_SESSION['userID']) : 0;
if ($userId <= 0) {
    taeOut(['status' => 'error', 'message' => 'Not authenticated'], 401);
}
$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    taeOut(['status' => 'error', 'message' => 'User not found'], 401);
}

$TaeTables = tlObjectWithDB::getDBTables(array(
    'nodes_hierarchy', 'tcversions', 'testplans', 'builds',
    'platforms', 'testplan_platforms', 'testprojects',
));

$treeMgr = new tree($db);
$cfieldMgr = new cfield_mgr($db);
$nodeTypes = $treeMgr->get_available_node_types();
// node type ids come back from the DB as strings -> force int, every
// comparison below is a strict !== against an intval()ed value.
$typeTestcase = intval($nodeTypes['testcase']);
$typeTestsuite = intval($nodeTypes['testsuite']);
$typeTestproject = intval($nodeTypes['testproject']);
$typeTcversion = intval($nodeTypes['testcase_version']);

/** Runnable levels, mirroring the legacy switch($args->level). */
$taeLevels = array('testcase', 'testsuite', 'testproject');

/** Custom field names holding the automation server endpoint. */
$TaeCfieldNames = array(
    'testcase' => array('host' => 'tc_server_host', 'port' => 'tc_server_port',
                        'path' => 'tc_server_path'),
    'testsuite' => array('host' => 'tsuite_server_host', 'port' => 'tsuite_server_port',
                         'path' => 'tsuite_server_path'),
);

/**
 * Read the design-time custom fields of a node, keyed by field name.
 * Returns array(name => value) with only the automation fields populated.
 */
function taeCfieldValues($db, $cfieldMgr, $tprojectId, $nodeType, $nodeId)
{
    $out = array();
    if ($nodeId <= 0 || $nodeType !== 'testcase' && $nodeType !== 'testsuite') {
        return $out;
    }
    $map = $cfieldMgr->get_linked_cfields_at_design($tprojectId, true, null,
                                                   $nodeType, $nodeId, 'name');
    if (!is_array($map)) {
        return $out;
    }
    foreach ($map as $name => $cf) {
        $value = null;
        if (isset($cf['cvalue'])) {
            $value = $cf['cvalue'];
        } else if (isset($cf['value'])) {
            $value = $cf['value'];
        }
        if (is_null($value)) {
            continue;
        }
        $out[strval($name)] = trim(strval($value));
    }
    return $out;
}

/**
 * Build the XML-RPC endpoint from a host/port/path triple.
 * Returns null when incomplete (legacy 'configProblems' path).
 */
function taeBuildUrl($values, $names)
{
    $host = isset($values[$names['host']]) ? $values[$names['host']] : '';
    $path = isset($values[$names['path']]) ? $values[$names['path']] : '';
    $port = isset($values[$names['port']]) ? $values[$names['port']] : '';

    if ($host === '' || $path === '') {
        return null;
    }
    if ($path[0] !== '/') {
        $path = '/' . $path;
    }
    $portPart = ($port !== '' && $port !== '80') ? ':' . intval($port) : '';
    return 'http://' . $host . $portPart . $path;
}

/**
 * Resolve the automation server of one test case:
 * test case level custom fields first, then the closest ancestor test suite
 * (as documented by the legacy header comment: "Three fields each for testcase
 * level and testsuite level are required").
 *
 * returns: array(url|null, source|null, host, port, path, node_id)
 */
function taeResolveServerForCase($db, $treeMgr, $cfieldMgr, $tprojectId, $tcaseId, $tcversionId, $typeTestsuite)
{
    global $TaeCfieldNames;

    $tcValues = taeCfieldValues($db, $cfieldMgr, $tprojectId, 'testcase', $tcversionId);
    $url = taeBuildUrl($tcValues, $TaeCfieldNames['testcase']);
    $info = array('url' => $url, 'source' => $url ? 'testcase' : null,
                  'host' => isset($tcValues[$TaeCfieldNames['testcase']['host']]) ? $tcValues[$TaeCfieldNames['testcase']['host']] : '',
                  'port' => isset($tcValues[$TaeCfieldNames['testcase']['port']]) ? $tcValues[$TaeCfieldNames['testcase']['port']] : '',
                  'path' => isset($tcValues[$TaeCfieldNames['testcase']['path']]) ? $tcValues[$TaeCfieldNames['testcase']['path']] : '',
                  'node_id' => $tcversionId);
    if ($url) {
        return $info;
    }

    // Cascade to the closest ancestor test suite carrying the trio.
    $parent = $treeMgr->get_node_hierarchy_info($tcaseId, null, array('extended_info' => false));
    $seen = 0;
    while (isset($parent['parent_id']) && intval($parent['parent_id']) > 0 && $seen < 32) {
        $pid = intval($parent['parent_id']);
        $pinfo = $treeMgr->get_node_hierarchy_info($pid);
        if (intval($pinfo['node_type_id']) !== $typeTestsuite) {
            break;
        }
        $sValues = taeCfieldValues($db, $cfieldMgr, $tprojectId, 'testsuite', $pid);
        $sUrl = taeBuildUrl($sValues, $TaeCfieldNames['testsuite']);
        if ($sUrl) {
            return array('url' => $sUrl, 'source' => 'testsuite',
                         'host' => isset($sValues[$TaeCfieldNames['testsuite']['host']]) ? $sValues[$TaeCfieldNames['testsuite']['host']] : '',
                         'port' => isset($sValues[$TaeCfieldNames['testsuite']['port']]) ? $sValues[$TaeCfieldNames['testsuite']['port']] : '',
                         'path' => isset($sValues[$TaeCfieldNames['testsuite']['path']]) ? $sValues[$TaeCfieldNames['testsuite']['path']] : '',
                         'node_id' => $pid);
        }
        $parent = $treeMgr->get_node_hierarchy_info($pid);
        $seen++;
    }

    return $info;
}

/**
 * Automation server configured on a test suite (tsuite_ trio).
 */
function taeSuiteServer($db, $cfieldMgr, $tprojectId, $suiteId)
{
    global $TaeCfieldNames;
    $names = $TaeCfieldNames['testsuite'];
    $values = taeCfieldValues($db, $cfieldMgr, $tprojectId, 'testsuite', $suiteId);
    $url = taeBuildUrl($values, $names);
    if (!$url) {
        return array('url' => null, 'source' => null, 'host' => '', 'port' => '', 'path' => '');
    }
    return array('url' => $url, 'source' => 'testsuite',
                 'host' => isset($values[$names['host']]) ? $values[$names['host']] : '',
                 'port' => isset($values[$names['port']]) ? $values[$names['port']] : '',
                 'path' => isset($values[$names['path']]) ? $values[$names['path']] : '');
}

/**
 * Latest ACTIVE version id of a test case node.
 * The tcase <-> version link lives in nodes_hierarchy (parent_id of the
 * testcase_version node), NOT in testcase_relations (2.0.1 schema).
 */
function taeLatestTcversion($db, $tcaseId, $typeTcversion)
{
    global $TaeTables;
    $sql = " SELECT MAX(NH.id) FROM " . $TaeTables['nodes_hierarchy'] . " NH, " .
           $TaeTables['tcversions'] . " TCV " .
           " WHERE NH.parent_id = " . intval($tcaseId) .
           " AND NH.node_type_id = " . intval($typeTcversion) .
           " AND TCV.id = NH.id AND TCV.active = 1 ";
    return intval($db->fetchOneValue($sql));
}

/** Name of a test case version node (falls back to the test case node). */
function taeTcversionName($db, $tcversionId, $tcaseId)
{
    global $TaeTables;
    if ($tcversionId > 0) {
        $sql = " SELECT name FROM " . $TaeTables['nodes_hierarchy'] .
               " WHERE id = " . intval($tcversionId);
        $v = $db->fetchOneValue($sql);
        if (!is_null($v) && trim(strval($v)) !== '') {
            return strval($v);
        }
    }
    $sql = " SELECT name FROM " . $TaeTables['nodes_hierarchy'] .
           " WHERE id = " . intval($tcaseId);
    $v = $db->fetchOneValue($sql);
    return is_null($v) ? '' : strval($v);
}

/**
 * Test cases of a node (recursively, legacy get_subtree was non recursive).
 */
function taeCollectTestCases($db, $treeMgr, $nodeId, $nodeTypeId, $typeTestcase)
{
    $out = array();
    // NULL options = recursive walk in 2.0.1 (passing an options array here
    // makes _get_subtree_rec return an empty set).
    $subtree = $treeMgr->get_subtree($nodeId);
    if (!is_array($subtree)) {
        return $out;
    }
    foreach ($subtree as $node) {
        if (intval($node['node_type_id']) === $typeTestcase) {
            $out[] = intval($node['id']);
        }
    }
    return $out;
}

/** Ancestry (nearest first) of a node, up to the test project. */
function taeAncestors($db, $treeMgr, $nodeId)
{
    $out = array();
    $info = $treeMgr->get_node_hierarchy_info($nodeId);
    $seen = 0;
    while (isset($info['parent_id']) && intval($info['parent_id']) > 0 && $seen < 32) {
        $pid = intval($info['parent_id']);
        $pinfo = $treeMgr->get_node_hierarchy_info($pid);
        $out[] = $pinfo;
        $info = $pinfo;
        $seen++;
    }
    return $out;
}

/** Does the user have mgt_view_tc on the project that owns the node? */
function taeCheckRights($db, $user, $treeMgr, $nodeId, $typeTestproject)
{
    $info = $treeMgr->get_node_hierarchy_info($nodeId);
    $owners = taeAncestors($db, $treeMgr, $nodeId);
    $tprojectId = intval($info['node_type_id']) === $typeTestproject
                    ? intval($nodeId) : 0;
    foreach ($owners as $o) {
        if (intval($o['node_type_id']) === $typeTestproject) {
            $tprojectId = intval($o['id']);
            break;
        }
    }
    if ($tprojectId <= 0) {
        return false;
    }
    $hasRight = $user->hasRight($db, 'mgt_view_tc', $tprojectId);
    return $hasRight ? $tprojectId : false;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$action = strval($_GET['action'] ?? '');

if ($action === 'init') {
    if ($method !== 'GET' && $method !== 'HEAD') {
        taeOut(['status' => 'error', 'message' => 'Method not allowed'], 405);
    }

    $tprojectId = intval($_GET['tproject_id'] ?? 0);
    if ($tprojectId <= 0) {
        $tprojectId = intval($_SESSION['testprojectTopMenu'] ?? 0);
    }
    if ($tprojectId <= 0) {
        taeOut(['status' => 'error', 'message' => 'Missing or invalid test project id'], 400);
    }
    $project = $db->fetchFirstRow("SELECT * FROM {$TaeTables['testprojects']} WHERE id = {$tprojectId}");
    $projectNode = $treeMgr->get_node_hierarchy_info($tprojectId);
    if (!$project || intval($projectNode['node_type_id'] ?? 0) !== $typeTestproject) {
        taeOut(['status' => 'error', 'message' => 'Unknown test project'], 404);
    }
    $projectName = strval($projectNode['name'] ?? '');
    if (!$user->hasRight($db, 'mgt_view_tc', $tprojectId)) {
        taeOut(['status' => 'error',
                'message' => 'Forbidden: mgt_view_tc right required'], 403);
    }

    // Execution context selectors (same context the XML-RPC call receives).
    $tplanId = intval($_GET['tplan_id'] ?? 0);
    $buildId = intval($_GET['build_id'] ?? 0);
    $platformId = intval($_GET['platform_id'] ?? 0);

    $plans = array();
    // 2.0.1 schema: testplans/builds carry the name on the nodes_hierarchy row.
    $sql = " SELECT tp.id, NH.name FROM " . $TaeTables['testplans'] . " tp, " .
           $TaeTables['nodes_hierarchy'] . " NH " .
           " WHERE tp.id = NH.id AND tp.testproject_id = {$tprojectId} AND tp.active = 1 " .
           " ORDER BY NH.name";
    foreach ($db->get_recordset($sql) as $r) {
        $plans[] = array('id' => intval($r['id']), 'name' => $r['name']);
    }

    // #834: builds are scoped to the TEST PROJECT (builds.testproject_id), the
    // testplan_id column no longer exists, so the selector lists the project's
    // active+open builds. buildID is only forwarded as XML-RPC context.
    $builds = array();
    $sql = " SELECT id, name FROM " . $TaeTables['builds'] .
           " WHERE testproject_id = {$tprojectId} AND active = 1 AND is_open = 1 " .
           " ORDER BY name";
    foreach ($db->get_recordset($sql) as $r) {
        $builds[] = array('id' => intval($r['id']), 'name' => $r['name']);
    }

    $platforms = array();
    if ($tplanId > 0) {
        $sql = " SELECT p.id, p.name FROM " . $TaeTables['platforms'] . " p, " .
               $TaeTables['testplan_platforms'] . " tpp " .
               " WHERE tpp.platform_id = p.id AND tpp.testplan_id = {$tplanId} " .
               " ORDER BY p.name";
        foreach ($db->get_recordset($sql) as $r) {
            $platforms[] = array('id' => intval($r['id']), 'name' => $r['name']);
        }
    }

    // Runnable nodes: every test case + test suite of the project.
    $nodes = array();
    $subtree = $treeMgr->get_subtree($tprojectId);
    $tcaseCount = 0;
    foreach ($subtree as $node) {
        $nType = intval($node['node_type_id']);
        if ($nType !== $typeTestcase && $nType !== $typeTestsuite) {
            continue;
        }
        $level = ($nType === $typeTestcase) ? 'testcase' : 'testsuite';
        $server = array('url' => null, 'source' => null, 'host' => '', 'port' => '', 'path' => '');
        if ($level === 'testcase') {
            $tcaseCount++;
            $tcversionId = taeLatestTcversion($db, intval($node['id']), $typeTcversion);
            $server = taeResolveServerForCase($db, $treeMgr, $cfieldMgr, $tprojectId,
                                              intval($node['id']), $tcversionId, $typeTestsuite);
        } else {
            $server = taeSuiteServer($db, $cfieldMgr, $tprojectId, intval($node['id']));
        }
        $nodes[] = array(
            'id' => intval($node['id']),
            'name' => $node['name'],
            'level' => $level,
            'parent_id' => intval($node['parent_id']),
            'tcversion_id' => ($level === 'testcase')
                ? taeLatestTcversion($db, intval($node['id']), $typeTcversion) : 0,
            'has_server' => !is_null($server['url']),
            'server_url' => $server['url'],
            'server_source' => $server['source'],
        );
    }
    usort($nodes, function ($a, $b) {
        if ($a['level'] !== $b['level']) {
            return $a['level'] === 'testsuite' ? -1 : 1;
        }
        return $a['name'] <=> $b['name'];
    });

    taeOut(array(
        'status' => 'ok',
        'project' => array('id' => $tprojectId, 'name' => $projectName),
        'context' => array('tproject_id' => $tprojectId, 'tplan_id' => $tplanId,
                           'build_id' => $buildId, 'platform_id' => $platformId),
        'plans' => $plans,
        'builds' => $builds,
        'platforms' => $platforms,
        'levels' => $taeLevels,
        'nodes' => $nodes,
        'tcase_count' => $tcaseCount,
        'cfield_names' => $TaeCfieldNames,
        'config_hint' => 'check_test_automation_server',
    ));
}

if ($action === 'run') {
    if ($method !== 'POST') {
        taeOut(['status' => 'error', 'message' => 'Method not allowed'], 405);
    }

    $in = function ($k, $def = 0) {
        $v = $_POST[$k] ?? $def;
        return is_string($v) ? trim($v) : $v;
    };

    $level = strval($in('level', ''));
    $nodeId = intval($in('node_id', 0));
    $tprojectId = intval($in('tproject_id', 0));
    $tplanId = intval($in('tplan_id', 0));
    $buildId = intval($in('build_id', 0));
    $platformId = intval($in('platform_id', 0));

    if (!in_array($level, $taeLevels, true)) {
        taeOut(['status' => 'error',
                'message' => 'Missing or unsupported level (testcase|testsuite|testproject)'], 400);
    }
    if ($nodeId <= 0) {
        taeOut(['status' => 'error', 'message' => 'Missing or invalid node id'], 400);
    }
    if ($tprojectId <= 0) {
        taeOut(['status' => 'error', 'message' => 'Missing or invalid test project id'], 400);
    }

    $nodeInfo = $treeMgr->get_node_hierarchy_info($nodeId);
    if (is_null($nodeInfo) || intval($nodeInfo['node_type_id']) === 0) {
        taeOut(['status' => 'error', 'message' => 'Unknown node'], 404);
    }
    $nodeTypeId = intval($nodeInfo['node_type_id']);
    if ($level === 'testcase' && $nodeTypeId !== $typeTestcase) {
        taeOut(['status' => 'error', 'message' => 'Node is not a test case'], 400);
    }
    if ($level === 'testsuite' && $nodeTypeId !== $typeTestsuite) {
        taeOut(['status' => 'error', 'message' => 'Node is not a test suite'], 400);
    }
    if ($level === 'testproject' && $nodeTypeId !== $typeTestproject) {
        taeOut(['status' => 'error', 'message' => 'Node is not a test project'], 400);
    }

    $tprojectId = taeCheckRights($db, $user, $treeMgr, $nodeId, $typeTestproject);
    if ($tprojectId === false) {
        taeOut(['status' => 'error',
                'message' => 'Forbidden: mgt_view_tc right required on the owning project'], 403);
    }

    // Which test cases will run (legacy switch($args->level)).
    if ($level === 'testcase') {
        $tcaseIds = array($nodeId);
    } else {
        $tcaseIds = taeCollectTestCases($db, $treeMgr, $nodeId, $nodeTypeId, $typeTestcase);
    }

    $context = array('tproject_id' => $tprojectId, 'tplan_id' => $tplanId,
                     'platform_id' => $platformId, 'build_id' => $buildId);

    $results = array();
    $counters = array('total' => 0, 'ok' => 0, 'configProblems' => 0,
                      'connectionFailure' => 0, 'failed' => 0);
    $maxRuns = 200;
    $truncated = false;

    foreach ($tcaseIds as $tcaseId) {
        if ($counters['total'] >= $maxRuns) {
            $truncated = true;
            break;
        }
        $tcversionId = taeLatestTcversion($db, $tcaseId, $typeTcversion);
        $tcaseInfo = array('id' => $tcaseId, 'name' => taeTcversionName($db, $tcversionId, $tcaseId),
                           'version_id' => $tcversionId);
        $server = taeResolveServerForCase($db, $treeMgr, $cfieldMgr, $tprojectId,
                                          $tcaseId, $tcversionId, $typeTestsuite);

        $counters['total']++;
        $outcome = executeTestCase($tcaseInfo,
            array('url' => $server['url']), $context);

        $status = 'ok';
        $sysMsg = 'ok';
        $exec = $outcome['execution'];
        if (isset($outcome['system']['status'])) {
            $status = $outcome['system']['status'];
            $sysMsg = $outcome['system']['msg'];
        }
        $result = '';
        $resultVerbose = '';
        $notes = '';
        $message = '';
        $scheduled = '';
        $timestampISO = '';
        if (is_array($exec)) {
            $result = strval($exec['result'] ?? '');
            $resultVerbose = strval($exec['resultVerbose'] ?? '');
            $notes = strval($exec['notes'] ?? '');
            $message = strval($exec['message'] ?? '');
            $scheduled = strval($exec['scheduled'] ?? '');
            $timestampISO = strval($exec['timestampISO'] ?? '');
        }

        if ($status === 'ok') {
            $counters['ok']++;
            if (trim($result) !== '' && strtoupper(trim($result)) !== 'PASS') {
                $counters['failed']++;
            }
        } else if ($status === 'configProblems') {
            $counters['configProblems']++;
        } else {
            $counters['connectionFailure']++;
        }

        $results[] = array(
            'tcase_id' => $tcaseId,
            'tcversion_id' => $tcversionId,
            'name' => $tcaseInfo['name'],
            'system' => array('status' => $status, 'msg' => $sysMsg),
            'result' => $result,
            'resultVerbose' => $resultVerbose,
            'notes' => $notes,
            'message' => $message,
            'scheduled' => $scheduled,
            'timestampISO' => $timestampISO,
            'server_url' => $server['url'],
            'server_source' => $server['source'],
        );
    }

    taeOut(array(
        'status' => 'ok',
        'level' => $level,
        'node_id' => $nodeId,
        'node_name' => $nodeInfo['name'],
        'context' => $context,
        'selected' => count($tcaseIds),
        'truncated' => $truncated,
        'counters' => $counters,
        'results' => $results,
        'config_hint' => 'check_test_automation_server',
    ));
}

taeOut(['status' => 'error', 'message' => 'Unknown or missing action'], 400);

