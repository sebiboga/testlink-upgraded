<?php
/**
 * Set Results BFF API (single test case version execution popup)
 * URL: /api/execsetresults/
 * Plain PHP, no framework, no compilation
 *
 * Mirrors lib/execute/execSetResults.php level=testcase (TestLink 1.9.20):
 * the "execute one test case version" popup reached from report screens
 * (tcasesWithCF, resultsMatrix, assignedTcOverview, tcAssignments). Only
 * the single-test-case path is reimplemented here; the full plan/feature
 * execute flow belongs to api/execute (execTest.html, Refs #662).
 *
 * Routes:
 *   GET  ?action=init
 *        [&tplan_id=N][&tcase_id=N|id=N][&tcversion_id=N|version_id=N]
 *        [&build_id=N|setting_build=N][&platform_id=N|setting_platform=N]
 *        -> { status, tproject, tplan, tcase, tcversion, steps, builds,
 *             default_build_id, platforms, statuses, grants, prior,
 *             feature_flags }
 *        Access: testplan_execute OR exec_ro_access (read-only).
 *   POST ?action=save  (JSON body, same shape as api/execute?action=save)
 *        -> { status, saved, execution_id? }
 *        Access: testplan_execute (WRITE required).
 *
 * The save payload shape mirrors api/execute?action=save exactly so the two
 * modernized screens share write_execution() semantics (exec.inc.php).
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
require_once('exec.inc.php');
require_once('attachments.inc.php');

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

function out($data) { echo json_encode($data); exit; }

function getIntParam($key, $default = 0) {
    $v = $_GET[$key] ?? $default;
    return is_numeric($v) ? intval($v) : $default;
}

/**
 * Read the JSON save payload (same contract as api/execute execTestPayload
 * for application/json bodies; multipart not needed by this popup).
 */
function setResultsPayload() {
    $ct = strtolower(strval($_SERVER['CONTENT_TYPE'] ?? ''));
    if (strpos($ct, 'application/json') !== false) {
        $j = json_decode(file_get_contents('php://input'), true);
        return is_array($j) ? $j : [];
    }
    return $_POST;
}

/**
 * Resolve the execution context (test plan + owning project), verifying the
 * user can access the plan. Mirrors api/execute execTestResolveContext().
 */
function esrResolvePlan($db, $user, $tplanId) {
    if ($tplanId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Missing tplan_id']);
    }
    $tplanMgr = new testplan($db);
    $planInfo = $tplanMgr->get_by_id($tplanId);
    if (is_null($planInfo) || !isset($planInfo['name'])) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Test plan not found']);
    }
    $tables = tlObjectWithDB::getDBTables(array('nodes_hierarchy'));
    $rs = $db->get_recordset(
        "SELECT parent_id FROM {$tables['nodes_hierarchy']} WHERE id = {$tplanId}");
    $tprojectId = (!is_null($rs) && count($rs) > 0)
        ? intval($rs[0]['parent_id']) : 0;
    if ($tprojectId <= 0) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Test project not found']);
    }
    $accessible = (array)$user->getAccessibleTestPlans($db, $tprojectId, null, null);
    $found = false;
    foreach ($accessible as $ap) {
        if (intval($ap['id']) === $tplanId) { $found = true; break; }
    }
    if (!$found) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'Access to this test plan denied']);
    }
    return array($tplanMgr, $tplanId, $tprojectId, strval($planInfo['name']));
}

/**
 * Resolve the test case + version. Accepts both the legacy id/version_id key
 * pair (level=testcase contract) and the modern tcase_id/tcversion_id pair.
 * Verifies the version belongs to the test case AND is linked to the plan
 * (same guarantee legacy execSetResults relies on).
 */
function esrResolveTcVersion($db, $tplanMgr, $tplanId, $tcaseId, $tcversionId) {
    if ($tcaseId <= 0 || $tcversionId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Missing test case / version id']);
    }
    $tcaseMgr = new testcase($db);
    $basic = $tcaseMgr->tree_manager->get_node_hierarchy_info($tcaseId);
    if (is_null($basic)) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Test case not found']);
    }

    $tables = tlObjectWithDB::getDBTables(array(
        'tcversions', 'testplan_tcversions', 'nodes_hierarchy'));
    $vr = $db->get_recordset(
        "SELECT V.id, V.version, V.active, V.summary, V.preconditions," .
        " V.importance, V.execution_type, V.tc_external_id," .
        " NH.parent_id" .
        " FROM {$tables['tcversions']} V" .
        " JOIN {$tables['nodes_hierarchy']} NH ON NH.id = V.id" .
        " WHERE V.id = {$tcversionId}");
    if (is_null($vr) || count($vr) == 0
        || intval($vr[0]['parent_id']) !== $tcaseId) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Version does not belong to test case']);
    }
    $linkRs = $db->get_recordset(
        "SELECT id FROM {$tables['testplan_tcversions']}" .
        " WHERE testplan_id = {$tplanId} AND tcversion_id = {$tcversionId}");
    if (is_null($linkRs) || count($linkRs) == 0) {
        http_response_code(404);
        out(['status' => 'error',
             'message' => 'This version is not linked to the test plan']);
    }
    return array($tcaseMgr, $vr[0], $basic);
}

/**
 * Steps of the version, sorted by step_number.
 */
function esrSteps($db, $tcaseMgr, $tcversionId) {
    $steps = [];
    try {
        $stepRows = $tcaseMgr->get_steps($tcversionId);
        if (!is_null($stepRows)) {
            foreach ($stepRows as $st) {
                $steps[] = [
                    'id' => intval($st['id']),
                    'step_number' => intval($st['step_number']),
                    'actions' => strval($st['actions']),
                    'expected_results' => strval($st['expected_results']),
                ];
            }
            usort($steps, function ($a, $b) {
                return $a['step_number'] - $b['step_number'];
            });
        }
    } catch (Exception $e) {
        $steps = [];
    }
    return $steps;
}

/**
 * Builds of the plan (UI offers active+open only for execution, same as
 * legacy execSetResults); newest active+open build id is the default.
 */
function esrBuilds($tplanMgr, $tplanId) {
    $builds = [];
    $defaultBuildId = 0;
    $rawBuilds = $tplanMgr->get_builds($tplanId);
    if (!is_null($rawBuilds)) {
        foreach ($rawBuilds as $bid => $b) {
            $isActive = intval($b['active']) === 1;
            $isOpen = intval($b['is_open']) === 1;
            $builds[] = [
                'id' => intval($b['id']),
                'name' => strval($b['name']),
                'active' => $isActive,
                'open' => $isOpen,
                'executable' => ($isActive && $isOpen),
                'release_date' => isset($b['release_date'])
                    ? strval($b['release_date']) : '',
            ];
            if ($isActive && $isOpen
                && intval($b['id']) > $defaultBuildId) {
                $defaultBuildId = intval($b['id']);
            }
        }
    }
    usort($builds, function ($a, $b) { return $a['id'] < $b['id'] ? 1 : -1; });
    return array($builds, $defaultBuildId);
}

/**
 * Execution status vocabulary (source of localize_tc_status()).
 */
function esrStatuses() {
    $resultsCfg = config_get('results');
    $statuses = [];
    foreach ($resultsCfg['code_status'] as $code => $suffix) {
        $statuses[] = [
            'code' => strval($code),
            'suffix' => strval($suffix),
            'label' => lang_get('test_status_' . $suffix),
        ];
    }
    return $statuses;
}

/**
 * Latest execution of THIS version on THIS build(+platform) with recorded
 * step-level results (partial execution feature). Mirrors api/execute
 * tcDetails prior-execution block.
 */
function esrPriorExecution($db, $tplanId, $buildId, $platformId, $tcversionId) {
    $prior = null;
    $priorSteps = [];
    if ($buildId > 0) {
        $execTables = tlObjectWithDB::getDBTables(array(
            'executions', 'execution_tcsteps', 'users'));
        $storedPlatform = $platformId > 0 ? $platformId : 0;
        $er = $db->get_recordset(
            "SELECT E.id AS execution_id, E.status, E.notes, E.execution_ts," .
            " E.execution_duration, E.tester_id, U.login AS tester_login" .
            " FROM {$execTables['executions']} E" .
            " LEFT JOIN {$execTables['users']} U ON U.id = E.tester_id" .
            " WHERE E.testplan_id = {$tplanId}" .
            " AND E.build_id = {$buildId}" .
            " AND E.platform_id = {$storedPlatform}" .
            " AND E.tcversion_id = {$tcversionId}" .
            " ORDER BY E.id DESC");
        if (!is_null($er) && count($er) > 0) {
            $prior = [
                'execution_id' => intval($er[0]['execution_id']),
                'status' => strval($er[0]['status']),
                'notes' => strval($er[0]['notes']),
                'execution_ts' => strval($er[0]['execution_ts']),
                'execution_duration' => strval($er[0]['execution_duration'] ?? ''),
                'tester_login' => strval($er[0]['tester_login']),
            ];
            $prior['attachments'] = [];
            try {
                $attachmentMgr = tlAttachmentRepository::create($db);
                $attItems = getAttachmentInfos($attachmentMgr,
                    intval($er[0]['execution_id']), 'executions', true, 1);
                if ($attItems) {
                    foreach ($attItems as $ai) {
                        $prior['attachments'][] = [
                            'id' => intval($ai['id']),
                            'title' => strval($ai['title']),
                            'file_name' => strval($ai['file_name']),
                            'file_size' => intval($ai['file_size']),
                            'download_url' =>
                                '/lib/attachments/attachmentdownload.php?id=' .
                                intval($ai['id']),
                        ];
                    }
                }
            } catch (Exception $e) {
                $prior['attachments'] = [];
            }
            $sr = $db->get_recordset(
                "SELECT tcstep_id, notes, status" .
                " FROM {$execTables['execution_tcsteps']}" .
                " WHERE execution_id = " . intval($er[0]['execution_id']));
            if (!is_null($sr)) {
                foreach ($sr as $srow) {
                    $priorSteps[intval($srow['tcstep_id'])] = [
                        'notes' => strval($srow['notes']),
                        'status' => strval($srow['status']),
                    ];
                }
            }
        }

        // Steps Work-In-Progress residency (legacy "Save steps partial
        // execution" resume, testcase::getStepsPartialExec()): WIP rows
        // override the recorded step results of the last full execution.
        if (count($stepsForWip = esrStepsForWip($db, $tplanId, $storedPlatform,
                $tcversionId)) > 0) {
            foreach ($stepsForWip as $sid => $sw) {
                $priorSteps[$sid] = $sw;
            }
        }
    }
    return array($prior, $priorSteps);
}

function esrStepsForWip($db, $tplanId, $platformId, $tcversionId) {
    $rows = [];
    try {
        $tcaseMgr = new testcase($db);
        $ctx = new stdClass();
        $ctx->testplan_id = $tplanId;
        $ctx->platform_id = $platformId;
        $wip = $tcaseMgr->getStepsPartialExec($tcversionId, $ctx);
        if (is_array($wip)) {
            foreach ($wip as $sid => $w) {
                $rows[intval($sid)] = [
                    'notes' => strval($w['notes'] ?? ''),
                    'status' => strval($w['status'] ?? ''),
                ];
            }
        }
    } catch (\Throwable $e) {
        $rows = [];
    }
    return $rows;
}

$action = $_GET['action'] ?? '';
$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : $action;

// ---------------------------------------------------------------------------
// GET ?action=init — context for one test case version execution
// ---------------------------------------------------------------------------
if ($action === 'init') {
    $tplanId = getIntParam('tplan_id');
    list($tplanMgr, $tplanId, $tprojectId, $tplanName) =
        esrResolvePlan($db, $user, $tplanId);

    // legacy level=testcase contract: id= tcase_id, version_id= tcversion_id
    $tcaseId = getIntParam('tcase_id', getIntParam('id'));
    $tcversionId = getIntParam('tcversion_id', getIntParam('version_id'));
    $buildId = getIntParam('build_id', getIntParam('setting_build'));
    $platformId = getIntParam('platform_id', getIntParam('setting_platform'));

    $canExecute = $user->hasRight($db, 'testplan_execute', $tprojectId, $tplanId);
    $roAccess = $user->hasRight($db, 'exec_ro_access', $tprojectId, $tplanId);
    if (!$canExecute && !$roAccess) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'Insufficient rights']);
    }

    list($tcaseMgr, $vinfo, $basic) =
        esrResolveTcVersion($db, $tplanMgr, $tplanId, $tcaseId, $tcversionId);

    $tprojectMgr = new testproject($db);
    $tprojInfo = $tprojectMgr->get_by_id($tprojectId);
    $prefix = !is_null($tprojInfo) ? strval($tprojInfo['prefix']) : '';

    $steps = esrSteps($db, $tcaseMgr, $tcversionId);
    list($builds, $defaultBuildId) = esrBuilds($tplanMgr, $tplanId);
    if ($buildId <= 0) { $buildId = $defaultBuildId; }
    $platforms = [];
    try {
        $rawPlatforms = $tplanMgr->getPlatforms($tplanId);
        if (!is_null($rawPlatforms)) {
            foreach ($rawPlatforms as $p) {
                $platforms[] = [
                    'id' => intval($p['id']),
                    'name' => strval($p['name']),
                ];
            }
        }
    } catch (Exception $e) {
        $platforms = [];
    }

    list($prior, $priorSteps) =
        esrPriorExecution($db, $tplanId, $buildId, $platformId, $tcversionId);

    $tprojOptions = null;
    if (!is_null($tprojInfo) && !empty($tprojInfo['options'])) {
        $tprojOptions = @unserialize($tprojInfo['options']);
    }
    $platformFeature = is_object($tprojOptions)
        && !empty($tprojOptions->platformsEnabled);

    $execCfg = config_get('exec_cfg');
    $execDurationEnabled =
        (isset($execCfg->features)
         && isset($execCfg->features->exec_duration)
         && !empty($execCfg->features->exec_duration->enabled)) ? 1 : 0;

    $tcaseName = isset($basic['name']) ? strval($basic['name']) : '';
    $tcaseExternalId = strval($vinfo['tc_external_id']);

    out([
        'status' => 'ok',
        'tproject' => ['id' => $tprojectId, 'name' => strval($tprojInfo['name']), 'prefix' => $prefix],
        'tplan' => ['id' => $tplanId, 'name' => $tplanName],
        'tcase' => [
            'id' => $tcaseId,
            'external_id' => $tcaseExternalId,
            'name' => $tcaseName,
        ],
        'tcversion' => [
            'id' => $tcversionId,
            'version' => intval($vinfo['version']),
            'active' => intval($vinfo['active']),
            'summary' => strval($vinfo['summary']),
            'preconditions' => strval($vinfo['preconditions']),
            'importance' => intval($vinfo['importance']),
            'execution_type' => intval($vinfo['execution_type']),
        ],
        'steps' => $steps,
        'builds' => $builds,
        'default_build_id' => $defaultBuildId,
        'platforms' => $platforms,
        'platform_feature_enabled' => $platformFeature,
        'statuses' => esrStatuses(),
        'grants' => [
            'can_execute' => $canExecute ? 1 : 0,
            'ro_access' => $roAccess ? 1 : 0,
        ],
        'exec_duration_enabled' => $execDurationEnabled,
        'prior' => $prior,
        'prior_steps' => $priorSteps,
    ]);
}

// ---------------------------------------------------------------------------
// POST ?action=save — write the execution (same shape as api/execute save)
// ---------------------------------------------------------------------------
if ($action === 'save') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        out(['status' => 'error', 'message' => 'POST required']);
    }
    $payload = setResultsPayload();
    if (!is_array($payload) || count($payload) == 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid request body']);
    }
    $tplanId = intval($payload['tplan_id'] ?? 0);
    list($tplanMgr, $tplanId, $tprojectId, ) =
        esrResolvePlan($db, $user, $tplanId);

    // WRITE right required (exec_ro_access is NOT enough), same as api/execute
    if (!$user->hasRight($db, 'testplan_execute', $tprojectId, $tplanId)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'Insufficient rights']);
    }

    $tcaseId = intval($payload['tcase_id'] ?? 0);
    $tcversionId = intval($payload['tcversion_id'] ?? 0);
    $versionNumber = intval($payload['version_number'] ?? 0);
    $buildId = intval($payload['build_id'] ?? 0);
    $platformId = intval($payload['platform_id'] ?? -1);
    $statusCode = strtolower(trim(strval($payload['status'] ?? '')));
    $notes = strval($payload['notes'] ?? '');
    $executionDuration = strval($payload['execution_duration'] ?? '');

    $resultsCfg = config_get('results');
    if (!isset($resultsCfg['code_status'][$statusCode])) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid status code']);
    }

    $validBuild = false;
    $rawBuilds = $tplanMgr->get_builds($tplanId);
    if (!is_null($rawBuilds)) {
        foreach ($rawBuilds as $bid => $b) {
            if (intval($bid) === $buildId
                && intval($b['active']) === 1 && intval($b['is_open']) === 1) {
                $validBuild = true;
                break;
            }
        }
    }
    if (!$validBuild) {
        http_response_code(400);
        out(['status' => 'error',
             'message' => 'Invalid or non-executable build for this plan']);
    }

    $lt = tlObjectWithDB::getDBTables(array('testplan_tcversions'));
    $lr = $db->get_recordset(
        "SELECT id FROM {$lt['testplan_tcversions']}" .
        " WHERE testplan_id = {$tplanId} AND tcversion_id = {$tcversionId}");
    if (is_null($lr) || count($lr) == 0) {
        http_response_code(400);
        out(['status' => 'error',
             'message' => 'Version is not linked to this test plan']);
    }

    $notRun = $resultsCfg['status_code']['not_run'];
    if ($statusCode === $notRun) {
        // legacy parity: write_execution() ignores not_run results entirely
        out(['status' => 'ok', 'saved' => false, 'reason' => 'not_run']);
    }

    $execSign = new stdClass();
    $execSign->tproject_id = $tprojectId;
    $execSign->tplan_id = $tplanId;
    $execSign->build_id = $buildId;
    $execSign->platform_id = $platformId;
    $execSign->user_id = $userId;

    $execData = array();
    $execData['statusSingle'][$tcversionId] = $statusCode;
    $execData['tc_version'][$tcversionId] = $tcaseId;
    $execData['version_number'][$tcversionId] = $versionNumber;
    $execData['notes'][$tcversionId] = trim($notes);
    $execData['execution_duration'] = $executionDuration;

    // step-level results: keys are STEP IDS; every id must belong to THIS
    // version so forged ids cannot touch unrelated partial-execution rows
    $stepsIn = isset($payload['steps']) && is_array($payload['steps'])
        ? $payload['steps'] : [];
    if (count($stepsIn) > 0) {
        $stepNotes = array();
        $stepStatus = array();
        $wanted = array_map('intval', array_keys($stepsIn));
        $validStepIds = array();
        if (count($wanted) > 0) {
            $nhTables = tlObjectWithDB::getDBTables(array('nodes_hierarchy'));
            $tcstepsTable = DB_TABLE_PREFIX . 'tcsteps';
            $stRs = $db->get_recordset(
                "SELECT S.id FROM {$tcstepsTable} S" .
                " JOIN {$nhTables['nodes_hierarchy']} NH ON NH.id = S.id" .
                " WHERE NH.parent_id = {$tcversionId}" .
                " AND S.id IN (" . implode(',', $wanted) . ")");
            if (!is_null($stRs)) {
                foreach ($stRs as $sr) { $validStepIds[intval($sr['id'])] = 1; }
            }
        }
        foreach ($stepsIn as $sid => $sv) {
            $sid = intval($sid);
            if ($sid <= 0 || !isset($validStepIds[$sid])) { continue; }
            $sn = isset($sv['notes']) ? strval($sv['notes']) : '';
            $ss = isset($sv['status'])
                ? strtolower(trim(strval($sv['status']))) : $notRun;
            if (!isset($resultsCfg['code_status'][$ss])) { $ss = $notRun; }
            $stepNotes[$sid] = $sn;
            $stepStatus[$sid] = $ss;
        }
        if (count($stepNotes) > 0) {
            $execData['step_notes'] = $stepNotes;
            $execData['step_status'] = $stepStatus;
            // deleteStepsPartialExec() reads the request superglobal
            $_REQUEST['step_notes'] = $stepNotes;
        }
    }

    $issueTracker = null;
    write_execution($db, $execSign, $execData, $issueTracker);

    // re-read the fresh row so the popup can close with a confirmed state
    $executionId = 0;
    $er = $db->get_recordset(
        "SELECT id FROM " .
        tlObjectWithDB::getDBTables(array('executions'))['executions'] .
        " WHERE testplan_id = {$tplanId} AND tcversion_id = {$tcversionId}" .
        " AND build_id = {$buildId}" .
        " AND platform_id = " . ($platformId > 0 ? $platformId : 0) .
        " ORDER BY id DESC LIMIT 1");
    if (!is_null($er) && count($er) > 0) {
        $executionId = intval($er[0]['id']);
    }

    out(['status' => 'ok', 'saved' => true, 'execution_id' => $executionId]);
}

http_response_code(404);
out(['status' => 'error', 'message' => 'Unknown action']);