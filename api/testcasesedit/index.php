<?php
/**
 * Test Case Version Editor BFF API (create/edit/update)
 * URL: /api/testcasesedit/
 * Plain PHP, no framework.
 *
 * Mirrors lib/testcases/tcEdit.php (TestLink 1.9.20 behavior): loads ONE test
 * case VERSION for editing (title, summary, preconditions, steps,
 * expected_results, importance, status, execution type, estimated duration,
 * keywords) and saves the changes (doUpdate) back to the DB via
 * testcase::update(). Also exposes version creation (do_create_new_version).
 *
 * Rights (mirrors the legacy controller): edit requires the test case belongs
 * to the given project and the user holds mgt_modify_tc on it. Editing an
 * executed version requires testproject_edit_executed_testcases (unless the
 * global canEditExecuted config allows it).
 *
 * Routes:
 *   GET  /edit?tcase_id=&tcversion_id=&tproject_id=
 *   POST /update  {tcase_id, tcversion_id, tproject_id, name, summary,
 *                  preconditions, steps:[{id?,step_number,actions,expected_results,
 *                                        execution_type}], importance, status,
 *                  exec_type, estimated_execution_duration, keywords:[ids]}
 *   POST /create_version  {tcase_id, tcversion_id}
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
$path = preg_replace('#^/api/testcasesedit(/index\.php)?#', '', $path);
$path = '/' . trim($path, '/');
$method = $_SERVER['REQUEST_METHOD'];
$segments = array_values(array_filter(explode('/', $path)));

$action = $_GET['action'] ?? ($segments[0] ?? null);

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

$tcaseMgr = new testcase($db);
$tprojectMgr = new testproject($db);
$tcaseCfg = config_get('testcase_cfg');

function getParentChain($dbHandler, $nodesTable, $nodeId) {
    $chain = [];
    $cur = intval($nodeId);
    for ($i = 0; $i < 50 && $cur > 0; $i++) {
        $rs = $dbHandler->get_recordset(
            "SELECT id, name, parent_id, node_type_id FROM {$nodesTable} " .
            "WHERE id = {$cur}");
        if (is_null($rs) || count($rs) == 0) {
            break;
        }
        $chain[] = $rs[0];
        $next = intval($rs[0]['parent_id']);
        if ($next <= 0 || $next === $cur) {
            break;
        }
        $cur = $next;
    }
    return array_reverse($chain);
}

/**
 * Resolve owning test project of a test case (id) via the node hierarchy.
 */
function resolveContext(&$db, &$tcaseMgr, &$tprojectMgr, $tcaseId, $tcversionId, $tprojectId) {
    $tcaseId = intval($tcaseId);
    $tcverId = intval($tcversionId);
    $tprojId = intval($tprojectId);

    if ($tcaseId <= 0 && $tcverId <= 0) {
        out(['status' => 'error', 'message' => 'Missing test case context'], 400);
    }

    // If only a version id given, resolve the parent tcase node.
    if ($tcaseId <= 0 && $tcverId > 0) {
        $nh = tlObjectWithDB::getDBTables(array('nodes_hierarchy'));
        $tcaseId = intval($db->fetchOneValue(
            "SELECT parent_id FROM {$nh['nodes_hierarchy']} WHERE id = {$tcverId}"));
    }

    // Owning project via parent chain.
    $nhTable = tlObjectWithDB::getDBTables(array('nodes_hierarchy'));
    $chain = getParentChain($db, $nhTable['nodes_hierarchy'], $tcaseId);
    $owningProject = null;
    if (!empty($chain) && intval($chain[0]['node_type_id']) == 1) {
        $rootId = intval($chain[0]['id']);
        $owningProject = $tprojectMgr->get_by_id($rootId);
    }
    if (is_null($owningProject) || !isset($owningProject['id'])) {
        out(['status' => 'error', 'message' => 'Test case not found'], 404);
    }
    $owningProjectId = intval($owningProject['id']);

    // If a tproject_id was supplied and differs, the tcase does not belong.
    if ($tprojId > 0 && $tprojId !== $owningProjectId) {
        out(['status' => 'error', 'message' => 'Test case not found in project context'], 404);
    }
    $tprojId = $owningProjectId;

    // Resolve the requested version record.
    $versionData = null;
    if ($tcverId > 0) {
        $verOpt = ['output' => 'full'];
        $verRaw = $tcaseMgr->get_by_id($tcaseId, $tcverId, null, $verOpt);
        if (is_null($verRaw)) {
            out(['status' => 'error', 'message' => 'Test case version not found'], 404);
        }
        $first = reset($verRaw);
        $versionData = $first;
    } else {
        // default to the latest active version (like legacy init_args)
        $latest = $tcaseMgr->get_last_active_version($tcaseId);
        if (is_null($latest) || count($latest) == 0) {
            out(['status' => 'error', 'message' => 'Test case version not found'], 404);
        }
        reset($latest);
        $tcverId = intval(key($latest));
        $verOpt = ['output' => 'full'];
        $verRaw = $tcaseMgr->get_by_id($tcaseId, $tcverId, null, $verOpt);
        if (is_null($verRaw)) {
            out(['status' => 'error', 'message' => 'Test case version not found'], 404);
        }
        $first = reset($verRaw);
        $versionData = $first;
    }

    return [$tcaseMgr, $tprojectMgr, $tcaseId, $tcverId, $tprojId, $versionData, $chain];
}

/**
 * Assemble the view payload for the editor mirroring what the legacy tcEdit
 * form shows for an already-existing version.
 */
function buildEditPayload(&$db, &$tcaseMgr, &$tprojectMgr, &$user, $tcaseId, $tcverId, $tprojId, $versionData, $chain, $projectKeywords = []) {
    $glue = config_get('testcase_cfg')->glue_character;
    $prefix = $tprojectMgr->getTestCasePrefix($tprojId);
    $tcName = strval($versionData['name'] ?? '');
    $identity = $prefix . $glue . strval($versionData['tc_external_id'] ?? '') .
                ':' . $versionData['version'] . ':' . $tcName;

    // steps (output=full normally embeds; fallback to direct SQL)
    $steps = [];
    if (isset($versionData['steps']) && is_array($versionData['steps'])) {
        foreach ($versionData['steps'] as $st) {
            $steps[] = [
                'id' => intval($st['id'] ?? 0),
                'step_number' => intval($st['step_number'] ?? 0),
                'actions' => (string)($st['actions'] ?? ''),
                'expected_results' => (string)($st['expected_results'] ?? ''),
                'execution_type' => intval($st['execution_type'] ?? 1),
            ];
        }
    } else {
        $tvTables = tlObjectWithDB::getDBTables(array('nodes_hierarchy', 'tcsteps'));
        $srs = $db->get_recordset(
            " SELECT TCSTEPS.id, TCSTEPS.step_number, TCSTEPS.actions, " .
            "        TCSTEPS.expected_results, TCSTEPS.execution_type " .
            " FROM {$tvTables['nodes_hierarchy']} NH_ST " .
            " JOIN {$tvTables['tcsteps']} TCSTEPS ON NH_ST.id = TCSTEPS.id " .
            " WHERE NH_ST.parent_id = {$tcverId} ORDER BY TCSTEPS.step_number");
        if (!is_null($srs)) {
            foreach ($srs as $st) {
                $steps[] = [
                    'id' => intval($st['id']),
                    'step_number' => intval($st['step_number']),
                    'actions' => (string)$st['actions'],
                    'expected_results' => (string)$st['expected_results'],
                    'execution_type' => intval($st['execution_type']),
                ];
            }
        }
    }

    // assigned keywords for this version
    $keywords = [];
    $kwMap = $tcaseMgr->getKeywords($tcaseId, $tcverId);
    if (!is_null($kwMap)) {
        foreach ($kwMap as $kwo) {
            $keywords[] = [
                'id' => intval($kwo['keyword_id'] ?? ($kwo['id'] ?? 0)),
                'name' => strval($kwo['keyword'] ?? ($kwo['name'] ?? '')),
            ];
        }
    }

    // parent testsuite (from chain) - second to last
    $testsuiteId = 0;
    $testsuiteName = '';
    if (count($chain) >= 2) {
        $tsuiteNode = $chain[count($chain) - 2];
        $testsuiteId = intval($tsuiteNode['id']);
        $testsuiteName = strval($tsuiteNode['name']);
    }

    // executed flag
    $executed = false;
    $execTable = tlObjectWithDB::getDBTables(array('executions'));
    $execRow = $db->fetchFirstRow(
        "SELECT id FROM {$execTable['executions']} WHERE tcversion_id = {$tcverId} LIMIT 1");
    if (!is_null($execRow) && isset($execRow['id'])) {
        $executed = true;
    }

    // allowed execution types + importance labels
    $execTypes = [
        ['id' => 1, 'code' => 'manual'],
        ['id' => 2, 'code' => 'automated'],
        ['id' => 3, 'code' => 'automated_proceed_on_block'],
        ['id' => 4, 'code' => 'automated_audited'],
    ];
    $importances = [
        ['id' => 1, 'code' => 'low'],
        ['id' => 2, 'code' => 'medium'],
        ['id' => 3, 'code' => 'high'],
    ];

    $userName = '';
    $userFirstLast = trim(($user->firstName ?? '') . ' ' . ($user->lastName ?? ''));
    $userName = $userFirstLast !== '' ? $userFirstLast . ' (' . ($user->login ?? '') . ')' : ($user->login ?? '');
    $userRight = $user->hasRight($db, 'mgt_modify_tc', $tprojId);
    $editExecutedRight = $user->hasRight($db, 'testproject_edit_executed_testcases', $tprojId);

    return [
        'tcase' => [
            'id' => $tcaseId,
            'name' => $tcName,
            'version' => intval($versionData['version'] ?? 1),
            'tcversion_id' => $tcverId,
            'identity' => $identity,
            'testsuite_id' => $testsuiteId,
            'testsuite_name' => $testsuiteName,
            'summary' => (string)($versionData['summary'] ?? ''),
            'preconditions' => (string)($versionData['preconditions'] ?? ''),
            'importance' => intval($versionData['importance'] ?? 2),
            'status' => intval($versionData['status'] ?? 1),
            'execution_type' => intval($versionData['execution_type'] ?? 1),
            'estimated_exec_duration' => strval($versionData['estimated_exec_duration'] ?? ''),
            'steps' => $steps,
            'keywords' => $keywords,
        ],
        'tproject_id' => $tprojId,
        'grants' => [
            'mgt_modify_tc' => $userRight,
            'testproject_edit_executed_testcases' => $editExecutedRight,
            'can_edit_executed' => (intval($tcaseCfg->canEditExecuted ?? 0) > 0) ? true : false,
        ],
        'has_been_executed' => $executed,
        'exec_types' => $execTypes,
        'importances' => $importances,
        'all_keywords' => $projectKeywords,
        'labels' => [
            'title_edit_tc' => lang_get('title_edit_tc'),
            'version' => lang_get('version'),
            'btn_save' => lang_get('btn_save'),
            'btn_cancel' => lang_get('cancel'),
            'tc_title' => lang_get('tc_title'),
            'summary' => lang_get('summary'),
            'preconditions' => lang_get('preconditions'),
            'steps' => lang_get('steps'),
            'expected_results' => lang_get('expected_results'),
            'importance' => lang_get('importance'),
            'status' => lang_get('status'),
            'execution_type' => lang_get('execution_type'),
            'estimated_execution_duration' => lang_get('estimated_execution_duration'),
            'tc_keywords' => lang_get('tc_keywords'),
            'warning_editing_executed_tc' => lang_get('warning_editing_executed_tc'),
            'warning_empty_tc_title' => lang_get('warning_empty_tc_title'),
            'footer' => 'TestLink 2.0.1 - Test Case Editor',
        ],
        'user' => ['name' => $userName],
    ];
}

switch ($action) {
    case 'edit':
        if ($method !== 'GET') { out(['status' => 'error', 'message' => 'Method not allowed'], 405); }
        list($tcaseMgr, $tprojectMgr, $tcaseId, $tcverId, $tprojId, $versionData, $chain) =
            resolveContext($db, $tcaseMgr, $tprojectMgr,
                intval(getParam('tcase_id', 0)),
                intval(getParam('tcversion_id', 0)),
                intval(getParam('tproject_id', 0)));

        if (!$user->hasRight($db, 'mgt_modify_tc', $tprojId)) {
            out(['status' => 'error', 'message' => 'No permission'], 403);
        }

        // project keywords for the assign list
        $projectKeywords = [];
        $kwRows = $tprojectMgr->get_keywords_map($tprojId);
        if (is_array($kwRows)) {
            foreach ($kwRows as $kid => $kname) {
                $projectKeywords[] = ['id' => intval($kid), 'name' => (string)$kname];
            }
        }

        $payload = buildEditPayload($db, $tcaseMgr, $tprojectMgr, $user,
            $tcaseId, $tcverId, $tprojId, $versionData, $chain, $projectKeywords);
        $payload['all_keywords'] = $projectKeywords;
        out($payload);
        break;

    case 'update':
        if ($method !== 'POST') { out(['status' => 'error', 'message' => 'Method not allowed'], 405); }
        list($tcaseMgr, $tprojectMgr, $tcaseId, $tcverId, $tprojId, $versionData, $chain) =
            resolveContext($db, $tcaseMgr, $tprojectMgr,
                intval(getParam('tcase_id', 0)),
                intval(getParam('tcversion_id', 0)),
                intval(getParam('tproject_id', 0)));

        if (!$user->hasRight($db, 'mgt_modify_tc', $tprojId)) {
            out(['status' => 'error', 'message' => 'No permission'], 403);
        }

        $body = bffBody();

        $name = trim(strval($body['name'] ?? ''));
        if ($name === '') {
            out(['status' => 'error', 'message' => 'Name must not be empty',
                 'error_code' => 'EMPTY_NAME'], 400);
        }
        $summary = strval($body['summary'] ?? '');
        $preconds = strval($body['preconditions'] ?? '');
        $execType = intval($body['exec_type'] ?? $versionData['execution_type'] ?? 1);
        $importance = intval($body['importance'] ?? $versionData['importance'] ?? 2);
        $status = intval($body['status'] ?? $versionData['status'] ?? 1);
        $estDur = strval($body['estimated_execution_duration'] ?? '');
        if ($estDur !== '' && !is_numeric($estDur)) {
            out(['status' => 'error', 'message' => 'Invalid estimated duration',
                 'error_code' => 'BAD_DURATION'], 400);
        }

        // steps payload -> step sets (testcase::update expects a structured set)
        $rawSteps = $body['steps'] ?? [];
        $steps = [];
        if (is_array($rawSteps)) {
            foreach ($rawSteps as $i => $st) {
                $steps[] = [
                    'step_number' => intval($st['step_number'] ?? ($i + 1)),
                    'actions' => (string)($st['actions'] ?? ''),
                    'expected_results' => (string)($st['expected_results'] ?? ''),
                    'execution_type' => intval($st['execution_type'] ?? $execType),
                ];
            }
        }

        // only allow update when an execution exists and user lacks edit-executed right
        $execTable = tlObjectWithDB::getDBTables(array('executions'));
        $execRow = $db->fetchFirstRow(
            "SELECT id FROM {$execTable['executions']} WHERE tcversion_id = {$tcverId} LIMIT 1");
        if (!is_null($execRow) && isset($execRow['id'])
            && !$user->hasRight($db, 'testproject_edit_executed_testcases', $tprojId)
            && !(intval($tcaseCfg->canEditExecuted ?? 0) > 0)) {
            out(['status' => 'error', 'message' => 'This version has executions: editing requires special permission',
                 'error_code' => 'EXECUTED_NO_PERM'], 403);
        }

        $kwIds = '';
        if (isset($body['keywords']) && is_array($body['keywords'])) {
            $ids = [];
            foreach ($body['keywords'] as $kid) {
                $kid = intval($kid);
                if ($kid > 0) { $ids[] = $kid; }
            }
            $kwIds = implode(',', $ids);
        }

        // status + estimated duration are extra attributes persisted via the
        // legacy update() $attr path (mirrors tcEdit doUpdate).
        $attr = array('status' => $status,
                      'estimatedExecDuration' => $estDur);

        try {
            $ret = $tcaseMgr->update(
                $tcaseId, $tcverId, $name, $summary, $preconds, $steps,
                intval($user->dbID ?? $userId), $kwIds,
                testcase::DEFAULT_ORDER, $execType, $importance, $attr);
        } catch (Throwable $e) {
            out(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
        if (is_array($ret) && isset($ret['status_ok']) && !$ret['status_ok']) {
            out(['status' => 'error', 'message' => strval($ret['msg'] ?? 'Update failed')], 400);
        }

        out([
            'status' => 'ok',
            'message' => 'Test case saved',
            'tcase_id' => $tcaseId,
            'tcversion_id' => $tcverId,
        ]);
        break;

    case 'create_version':
        if ($method !== 'POST') { out(['status' => 'error', 'message' => 'Method not allowed'], 405); }
        list($tcaseMgr, $tprojectMgr, $tcaseId, $tcverId, $tprojId, $versionData, $chain) =
            resolveContext($db, $tcaseMgr, $tprojectMgr,
                intval(getParam('tcase_id', 0)),
                intval(getParam('tcversion_id', 0)),
                intval(getParam('tproject_id', 0)));
        if (!$user->hasRight($db, 'mgt_modify_tc', $tprojId)) {
            out(['status' => 'error', 'message' => 'No permission'], 403);
        }
        $ret = $tcaseMgr->create_new_version($tcaseId, intval($user->dbID ?? $userId));
        $newTcv = is_array($ret) ? intval($ret['id'] ?? 0) : intval($ret);
        if ($newTcv <= 0) {
            out(['status' => 'error', 'message' => 'Version creation failed'], 500);
        }
        out(['status' => 'ok', 'tcversion_id' => $newTcv, 'tcase_id' => $tcaseId,
             'message' => 'New version created']);
        break;

    default:
        out(['status' => 'error', 'message' => 'Unknown action'], 404);
}
