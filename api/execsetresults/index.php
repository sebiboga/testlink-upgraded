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
        " V.estimated_exec_duration," .
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
 * Direct execution link of the popup header (legacy execSetResults.tpl
 * "execInfo" link/print controls, Refs #1398):
 *   direct_link = <basehref>/ltx.php?item=exec&feature_id=<testplan_tcversions.id>&build_id=<build_id>
 * ltx.php validates feature_id + build_id at request time (ltx.php item=exec
 * -> check_exec/process_exec, exec_remote calls). feature_id is the
 * testplan_tcversions row of (plan, tcversion, platform); the platform row is
 * preferred, else any linked row of the version.
 */
function esrDirectLink($db, $tplanId, $tcversionId, $platformId, $buildId) {
    $featureId = 0;
    $tptcv = tlObjectWithDB::getDBTables(array('testplan_tcversions'))['testplan_tcversions'];
    $pid = $platformId > 0 ? $platformId : 0;
    $rows = $db->get_recordset(
        "SELECT id FROM {$tptcv}" .
        " WHERE testplan_id = {$tplanId} AND tcversion_id = {$tcversionId}" .
        " AND platform_id = {$pid} ORDER BY id ASC LIMIT 1");
    if (is_null($rows) || count($rows) == 0) {
        $rows = $db->get_recordset(
            "SELECT id FROM {$tptcv}" .
            " WHERE testplan_id = {$tplanId} AND tcversion_id = {$tcversionId}" .
            " ORDER BY platform_id ASC, id ASC LIMIT 1");
    }
    if (!is_null($rows) && count($rows) > 0) {
        $featureId = intval($rows[0]['id']);
    }
    $base = rtrim(strval($_SESSION['basehref'] ?? '/'), '/');
    $link = $featureId > 0
        ? $base . '/ltx.php?item=exec&feature_id=' . $featureId .
          '&build_id=' . intval($buildId)
        : '';
    return array('feature_id' => $featureId, 'direct_link' => $link);
}

/**
 * Execution-type label of the executed version (legacy exec_test_spec.inc.tpl
 * "Execution type:" row + testcase::$execution_types, Refs #1398):
 * tcversions.execution_type 1 = manual (EXECUTION_TYPE_MANUAL), 2 = automated.
 */
function esrExecutionTypeLabel($executionType) {
    // mirror testcase::getExecutionTypes() so the DEFAULT (1=manual, 2=auto)
    // vocabulary is rendered; both constants live in cfg/const.inc.php.
    return intval($executionType) === TESTCASE_EXECUTION_TYPE_AUTO
        ? lang_get('automated') : lang_get('manual');
}

/**
 * Collapsible Notes panels payload (legacy exec_show_tc_exec.inc.tpl notes
 * sections + execSetResults.php:1547-1607, Refs #1398): testplan notes +
 * plan design-time custom fields whose field has show_on_execution=1
 * (cfield_testplan_design_values, value keyed by the plan node id), build
 * notes + build design-time CFs (cfield_build_design_values keyed by the
 * build id), platform notes (platforms.notes). Values are raw {label,value}
 * arrays — the same shape the esrTestSuite() suite.cfs block produces, so the
 * screen renders them with Dashio styles instead of legacy HTML tables.
 */
function esrNotesPayload($db, $tplanMgr, $tplanId, $tprojectId, $buildId, $platformId) {
    $out = array(
        'tplan_notes' => '',
        'build_notes' => '',
        'platform_notes' => '',
        'tplan_cfs' => array(),
        'build_cfs' => array(),
    );

    $planRow = $tplanMgr->get_by_id($tplanId);
    $out['tplan_notes'] = strval($planRow['notes'] ?? '');

    $bTbl = tlObjectWithDB::getDBTables(array('builds'))['builds'];
    $bRs = $db->get_recordset(
        "SELECT id, notes FROM {$bTbl} WHERE id = " . intval($buildId));
    if (!is_null($bRs) && count($bRs) > 0) {
        $out['build_notes'] = strval($bRs[0]['notes'] ?? '');
    }

    if ($platformId > 0) {
        $pTbl = tlObjectWithDB::getDBTables(array('platforms'))['platforms'];
        $pRs = $db->get_recordset(
            "SELECT id, notes FROM {$pTbl} WHERE id = " . intval($platformId));
        if (!is_null($pRs) && count($pRs) > 0) {
            $out['platform_notes'] = strval($pRs[0]['notes'] ?? '');
        }
    }

    $showEmpty = config_get('custom_fields')->show_custom_fields_without_value;

    // plan CFs: legacy html_table_of_custom_field_values($tplanId,'design',
    // array('show_on_execution' => 1)) — get_linked_cfields_at_design($id,
    // $parent_id, $show_on_execution) with the execution-scope filter on.
    try {
        $cfMap = $tplanMgr->get_linked_cfields_at_design($tplanId, null, 1);
        if (!is_null($cfMap)) {
            foreach ($cfMap as $cfId => $cfInfo) {
                $hasValue = intval($cfInfo['node_id'] ?? 0);
                if (!$hasValue && !$showEmpty) { continue; }
                $out['tplan_cfs'][] = array(
                    'id' => intval($cfId),
                    'label' => trim(str_replace(TL_LOCALIZE_TAG, '',
                        lang_get($cfInfo['label'], null, true))),
                    'value' => strval($tplanMgr->cfield_mgr
                        ->string_custom_field_value($cfInfo, $tplanId)),
                );
            }
        }
    } catch (\Throwable $e) {
        $out['tplan_cfs'] = array();
    }

    // build CFs: legacy html_table_of_custom_field_values($buildId,
    // $tprojectId, 'design', array('show_on_execution' => 1)).
    try {
        $buildMgr = new build($db);
        $cfMap = $buildMgr->get_linked_cfields_at_design(
            $buildId, $tprojectId, array('show_on_execution' => 1));
        if (!is_null($cfMap)) {
            foreach ($cfMap as $cfId => $cfInfo) {
                $hasValue = intval($cfInfo['node_id'] ?? 0);
                if (!$hasValue && !$showEmpty) { continue; }
                $out['build_cfs'][] = array(
                    'id' => intval($cfId),
                    'label' => trim(str_replace(TL_LOCALIZE_TAG, '',
                        lang_get($cfInfo['label'], null, true))),
                    'value' => strval($buildMgr->cfield_mgr
                        ->string_custom_field_value($cfInfo, $buildId)),
                );
            }
        }
    } catch (\Throwable $e) {
        $out['build_cfs'] = array();
    }

    return $out;
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
 * legacy execSetResults). Default build (#1031): builds became project-scoped
 * (builds.testproject_id, no testplan_id - Refs #503), so "newest active+open
 * build" can land on a project build with zero executions for this plan.
 * Prefer the newest executable build that already has executions for this
 * plan; fall back to newest executable build.
 */
function esrBuilds($tplanMgr, $tplanId, $db) {
    $builds = [];
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
        }
    }
    usort($builds, function ($a, $b) { return $a['id'] < $b['id'] ? 1 : -1; });

    $defaultBuildId = 0;
    $executableIds = [];
    foreach ($builds as $b) {
        if ($b['executable']) {
            $executableIds[] = $b['id'];
        }
    }
    if (count($executableIds) > 0) {
        $execTbl = tlObjectWithDB::getDBTables(['executions']);
        $executedBuildIds = [];
        $erows = $db->get_recordset(
            'SELECT DISTINCT E.build_id FROM ' . $execTbl['executions'] . ' E' .
            " WHERE E.testplan_id = {$tplanId}" .
            ' AND E.build_id IN (' . implode(',', $executableIds) . ')');
        if (!is_null($erows)) {
            foreach ($erows as $r) {
                $executedBuildIds[intval($r['build_id'])] = true;
            }
        }
        foreach ($builds as $b) {
            if ($b['executable'] && isset($executedBuildIds[$b['id']])) {
                $defaultBuildId = $b['id'];
                break;
            }
        }
        if ($defaultBuildId === 0) {
            foreach ($builds as $b) {
                if ($b['executable']) {
                    $defaultBuildId = $b['id'];
                    break;
                }
            }
        }
    }
    return array($builds, $defaultBuildId);
}

/**
 * Execution status vocabulary (source of localize_tc_status()).
 */
function esrStatuses() {
    $resultsCfg = config_get('results');
    $statuses = [];
    foreach ($resultsCfg['code_status'] as $code => $suffix) {
        if ($code === 'a') { continue; } // 'a' (All) is a filter, not a real status
        $statuses[] = [
            'code' => strval($code),
            'suffix' => strval($suffix),
            'label' => lang_get('test_status_' . $suffix),
        ];
    }
    return $statuses;
}

/**
 * save-and-move navigation mode (legacy exec_cfg->exec_mode->save_and_move,
 * config.inc.php:1119 default 'unlimited'). 'unlimited' walks the whole
 * plan-linked chain with cyclic wrap; 'limited' moves only within the
 * current test suite (getTestCaseNextSibling, local scope).
 */
function esrSaveAndMove() {
    $execCfg = config_get('exec_cfg');
    $m = (isset($execCfg->exec_mode)
          && isset($execCfg->exec_mode->save_and_move))
        ? strval($execCfg->exec_mode->save_and_move) : 'unlimited';
    return ($m === 'limited') ? 'limited' : 'unlimited';
}

/**
 * Ordered chain of plan-linked test case versions for navigation ('unlimited'
 * mode). Mirrors the legacy getTestCaseSiblings ordering (node_order then
 * tc_external_id, testplan.class.php:3618-3658) over the whole plan set.
 */
function esrNavChain($db, $tplanId, $platformId, $currentTcversionId) {
    $chain = [];
    $tptcv = tlObjectWithDB::getDBTables(array('testplan_tcversions'))['testplan_tcversions'];
    $nh = tlObjectWithDB::getDBTables(array('nodes_hierarchy'))['nodes_hierarchy'];
    $tcv = tlObjectWithDB::getDBTables(array('tcversions'))['tcversions'];
    $pid = $platformId > 0 ? $platformId : 0;
    $rows = $db->get_recordset(
        "SELECT NHTCV.parent_id AS tcase_id, TPTCV.tcversion_id" .
        " FROM {$tptcv} TPTCV" .
        " JOIN {$nh} NHTCV ON NHTCV.id = TPTCV.tcversion_id" .
        " JOIN {$tcv} TCV ON TCV.id = TPTCV.tcversion_id" .
        " WHERE TPTCV.testplan_id = {$tplanId}" .
        " AND TPTCV.platform_id = {$pid}" .
        " ORDER BY TPTCV.node_order, TCV.tc_external_id");
    $seen = array();
    if (!is_null($rows)) {
        foreach ($rows as $r) {
            $tcversionId = intval($r['tcversion_id']);
            if (isset($seen[$tcversionId])) { continue; }
            $seen[$tcversionId] = 1;
            $chain[] = [
                'tcase_id' => intval($r['tcase_id']),
                'tcversion_id' => $tcversionId,
            ];
        }
    }
    // current version must be in the chain, else navigation is pointless
    $present = false;
    foreach ($chain as $it) {
        if ($it['tcversion_id'] === $currentTcversionId) { $present = true; break; }
    }
    if (!$present) { return []; }
    return $chain;
}

/**
 * Prev/next navigation targets for the popup (legacy execSetResults.php:241-328
 * for save_and_next / move2next / move2previous):
 *  - 'unlimited': whole plan-linked chain, cyclic wrap in both directions
 *    (the modern equivalent of the legacy $_SESSION testcases_to_show walk,
 *    execSetResults.php:258-267).
 *  - 'limited': getTestCaseNextSibling with move=forward/backward (local
 *    scope): previous clamps at the first (legacy treats "same version" as
 *    no-previous), next stops at the last (no cyclic wrap).
 */
function esrNavTargets($db, $tplanMgr, $tplanId, $platformId, $tcaseId, $tcversionId) {
    $mode = esrSaveAndMove();
    $prev = null;
    $next = null;
    try {
        if ($mode === 'limited') {
            $p = $tplanMgr->getTestCaseNextSibling($tplanId, $tcversionId, $platformId, array('move' => 'backward'));
            $n = $tplanMgr->getTestCaseNextSibling($tplanId, $tcversionId, $platformId, array('move' => 'forward'));
            if (is_array($p) && isset($p['tcase_id'])) {
                $p = array('tcase_id' => intval($p['tcase_id']), 'tcversion_id' => intval($p['tcversion_id']));
                // legacy backward on the first sibling clamps to the current
                // version — surface that as "no previous"
                if ($p['tcase_id'] === $tcaseId && $p['tcversion_id'] === $tcversionId) { $p = null; }
                $prev = $p;
            }
            if (is_array($n) && isset($n['tcase_id'])) {
                $next = array('tcase_id' => intval($n['tcase_id']), 'tcversion_id' => intval($n['tcversion_id']));
            }
        } else {
            $chain = esrNavChain($db, $tplanId, $platformId, $tcversionId);
            $pos = -1;
            foreach ($chain as $ix => $it) {
                if ($it['tcversion_id'] === $tcversionId) { $pos = $ix; break; }
            }
            $cnt = count($chain);
            if ($pos >= 0 && $cnt > 0) {
                if ($cnt > 1) {
                    $prev = $chain[($pos - 1 + $cnt) % $cnt];
                    $next = $chain[($pos + 1) % $cnt];
                }
            }
        }
    } catch (\Throwable $e) {
        $prev = null;
        $next = null;
    }
    return array('mode' => $mode, 'prev' => $prev, 'next' => $next);
}

/**
 * Latest execution of THIS version on THIS build(+platform) with recorded
 * step-level results (partial execution feature). Mirrors api/execute
 * tcDetails prior-execution block.
 */
function esrPriorExecution($db, $tplanId, $buildId, $platformId, $tcversionId, $stepsByVersion) {
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
                "SELECT tcstep_id, id AS tcsexe_id, notes, status" .
                " FROM {$execTables['execution_tcsteps']}" .
                " WHERE execution_id = " . intval($er[0]['execution_id']));
            $stepExecIdByStep = [];
            if (!is_null($sr)) {
                foreach ($sr as $srow) {
                    $priorSteps[intval($srow['tcstep_id'])] = [
                        'notes' => strval($srow['notes']),
                        'status' => strval($srow['status']),
                    ];
                    $stepExecIdByStep[intval($srow['tcstep_id'])] =
                        intval($srow['tcsexe_id']);
                }
            }
            // step-level attachments of the latest full execution (legacy
            // attachments on execution_tcsteps rows, written by
            // write_execution() exec.inc.php:255-321). Download links allow
            // reviewing what the previous run attached per step.
            if (count($stepExecIdByStep) > 0) {
                $attTbl = tlObjectWithDB::getDBTables(['attachments'])['attachments'];
                $attRows = $db->get_recordset(
                    "SELECT id, fk_id, title, file_name, file_size" .
                    " FROM {$attTbl}" .
                    " WHERE fk_table = 'execution_tcsteps'" .
                    " AND fk_id IN (" .
                    implode(',', array_values($stepExecIdByStep)) . ")");
                if (!is_null($attRows)) {
                    foreach ($attRows as $ar) {
                        $stepIdOf = array_search(intval($ar['fk_id']),
                            $stepExecIdByStep);
                        if ($stepIdOf === false) { continue; }
                        $priorSteps[$stepIdOf]['attachments'][] = [
                            'id' => intval($ar['id']),
                            'title' => strval($ar['title']),
                            'file_name' => strval($ar['file_name']),
                            'file_size' => intval($ar['file_size']),
                            'download_url' =>
                                '/lib/attachments/attachmentdownload.php?id=' .
                                intval($ar['id']),
                        ];
                    }
                }
            }
        }

        // Steps Work-In-Progress residency (legacy "Save steps partial
        // execution" resume, testcase::getStepsPartialExec()): WIP rows
        // override the recorded step results of the last full execution.
        // NOTE: getStepsPartialExec() expects the version's STEP IDS (int
        // array) in `tcstep_id IN (...)` plus a build_id'd context — legacy
        // execSetResults.php:371-388 builds $stepSet from the version steps.
        $stepIdsOfVersion = array();
        if (is_array($stepsByVersion)) {
            foreach ($stepsByVersion as $sby) {
                if (isset($sby['id'])) { $stepIdsOfVersion[] = intval($sby['id']); }
            }
        }
        $wip = esrStepsForWip($db, $tplanId, $storedPlatform, $buildId,
            $tcversionId, $stepIdsOfVersion);
        if (count($wip) > 0) {
            foreach ($wip as $sid => $sw) {
                $priorSteps[$sid] = $sw;
            }
        }
    }
    return array($prior, $priorSteps);
}

function esrStepsForWip($db, $tplanId, $platformId, $buildId, $tcversionId, $stepIds) {
    $rows = [];
    if (!is_array($stepIds) || count($stepIds) == 0) {
        return $rows;
    }
    $stepIds = array_map('intval', $stepIds);
    try {
        $tcaseMgr = new testcase($db);
        $ctx = new stdClass();
        $ctx->testplan_id = $tplanId;
        $ctx->platform_id = $platformId;
        $ctx->build_id = $buildId;
        $wip = $tcaseMgr->getStepsPartialExec($stepIds, $ctx);
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

/**
 * Active linked requirements of this test case version (legacy
 * requirement_mgr::getActiveForTCVersion, called from execSetResults when the
 * project has requirements enabled). Same shape as api/testcases view.
 */
function esrRequirements($db, $tcaseId, $tcversionId, $tprojectId, $user) {
    $requirements = [];
    $tprojectMgr = new testproject($db);
    $opt = $tprojectMgr->getOptions($tprojectId);
    $opt = is_null($opt) ? new stdClass() : $opt;
    $canViewReq = (!empty($opt->requirementsEnabled))
        && $user->hasRight($db, 'mgt_view_req', $tprojectId);
    if (!$canViewReq) {
        return array($requirements, 0);
    }
    try {
        $rqTables = tlObjectWithDB::getDBTables(
            array('requirements', 'req_versions', 'req_coverage', 'req_specs', 'nodes_hierarchy'));
        $rqSql = " SELECT REQ.id, REQ.req_doc_id, " .
                 "        NHREQ.name AS title, NHRS.name AS req_spec_title, " .
                 "        REQV.version " .
                 " FROM {$rqTables['req_coverage']} RCOV " .
                 " JOIN {$rqTables['requirements']} REQ ON REQ.id = RCOV.req_id " .
                 " JOIN {$rqTables['nodes_hierarchy']} NHREQ ON NHREQ.id = REQ.id " .
                 " JOIN {$rqTables['nodes_hierarchy']} NHRS ON NHRS.id = REQ.srs_id " .
                 " JOIN {$rqTables['req_versions']} REQV ON REQV.id = RCOV.req_version_id " .
                 " WHERE RCOV.tcversion_id = " . intval($tcversionId) .
                 " AND RCOV.is_active = 1 " .
                 " ORDER BY REQ.req_doc_id ASC";
        $rqRows = $db->get_recordset($rqSql);
        if (!is_null($rqRows)) {
            foreach ($rqRows as $rq) {
                $requirements[] = [
                    'id' => intval($rq['id']),
                    'req_doc_id' => strval($rq['req_doc_id']),
                    'title' => strval($rq['title']),
                    'version' => intval($rq['version'] ?? 1),
                    'req_spec_title' => strval($rq['req_spec_title'] ?? ''),
                ];
            }
        }
    } catch (Exception $e) {
        $requirements = [];
    }
    return array($requirements, 1);
}

/**
 * Test case version relations (legacy testcase::getTCVersionRelations, arg
 * idCard = tcase_id + tcversion_id). Returns the related test case info the
 * legacy exec_tc_relations.inc.tpl renders: id/type, related external id+name.
 * NOTE: relation type names are NOT stored in a DB table — the mapping lives
 * in config testcase_cfg->relations->type_labels (relation_type int -> pair
 * of i18n keys) exactly like legacy getRelationLabels() + in_array check.
 */
function esrRelations($db, $tcversionId) {
    $relations = [];
    try {
        $relTables = tlObjectWithDB::getDBTables(
            array('testcase_relations', 'nodes_hierarchy', 'tcversions'));
        $relSql = " SELECT TR.id, TR.source_id, TR.destination_id, " .
                  "        TR.relation_type, TR.link_status " .
                  " FROM {$relTables['testcase_relations']} TR " .
                  " WHERE TR.source_id = " . intval($tcversionId) .
                  "    OR TR.destination_id = " . intval($tcversionId) .
                  " ORDER BY TR.id ASC";

        // label map: relation_type int -> ['source'=>i18n key,'destination'=>i18n key]
        $tcCfg = config_get('testcase_cfg');
        $labels = [];
        if (isset($tcCfg->relations) && isset($tcCfg->relations->type_labels)) {
            $labels = is_object($tcCfg->relations->type_labels)
                ? (array)$tcCfg->relations->type_labels
                : $tcCfg->relations->type_labels;
        }

        $relRows = $db->get_recordset($relSql);
        if (!is_null($relRows)) {
            foreach ($relRows as $rr) {
                $relTypeId = intval($rr['relation_type']);
                if (!isset($labels[$relTypeId])) {
                    continue; // relation type not configured -> legacy drops it
                }
                $isSource = intval($rr['source_id']) === intval($tcversionId);
                $otherTcversionId = $isSource
                    ? intval($rr['destination_id']) : intval($rr['source_id']);
                $labelKey = $isSource
                    ? $labels[$relTypeId]['source'] : $labels[$relTypeId]['destination'];
                $typeLocalized = lang_get($labelKey);

                // related test case version -> its owning test case id
                $oth = $db->fetchFirstRow(
                    "SELECT parent_id FROM {$relTables['nodes_hierarchy']} WHERE id = {$otherTcversionId}");
                if (is_null($oth) || !isset($oth['parent_id'])) {
                    continue;
                }
                $otherTcaseId = intval($oth['parent_id']);
                // external id of the related test case
                $extId = '';
                $vr = $db->fetchFirstRow(
                    "SELECT tc_external_id FROM {$relTables['tcversions']} WHERE id = {$otherTcversionId}");
                if (!is_null($vr) && isset($vr['tc_external_id'])) {
                    $extId = strval($vr['tc_external_id']);
                }
                $nm = $db->fetchFirstRow(
                    "SELECT name FROM {$relTables['nodes_hierarchy']} WHERE id = {$otherTcaseId}");
                $name = (!is_null($nm) && isset($nm['name'])) ? strval($nm['name']) : '';
                $relations[] = [
                    'id' => intval($rr['id']),
                    'relation_type' => $relTypeId,
                    'type_localized' => $typeLocalized,
                    'link_status' => intval($rr['link_status'] ?? 1),
                    'is_source' => $isSource ? 1 : 0,
                    'related_tcase_id' => $otherTcaseId,
                    'related_tcversion_id' => $otherTcversionId,
                    'related_tcase_external_id' => $extId,
                    'related_tcase_name' => $name,
                ];
            }
        }
    } catch (Exception $e) {
        $relations = [];
    }
    return $relations;
}

/**
 * Keywords assigned to this test case version (legacy testcase::
 * getKeywordsByIdCard with output=kwfull). Array of {id, name}.
 */
function esrKeywords($tcaseMgr, $tcaseId, $tcversionId) {
    $keywords = [];
    try {
        $kwMap = $tcaseMgr->getKeywordsByIdCard(
            array('tcase_id' => $tcaseId, 'tcversion_id' => $tcversionId),
            array('output' => 'kwfull'));
        if (!is_null($kwMap)) {
            foreach ($kwMap as $kwo) {
                $keywords[] = [
                    'id' => intval($kwo['keyword_id'] ?? 0),
                    'name' => strval($kwo['keyword'] ?? ''),
                ];
            }
        }
    } catch (Exception $e) {
        $keywords = [];
    }
    return $keywords;
}

/**
 * Test suite block of the exec popup (legacy exec_show_tc_exec.inc.tpl:36-71
 * + execSetResults.php smarty_assign_tsuite_info/get_ts_name_details):
 *  - breadcrumb of CLICKABLE suite links (legacy openTestSuiteWindow(tsuite_id)
 *    per ancestor suite, testlink_library.js:1529) — the modern equivalent is
 *    the suiteView.html viewer (api/suiteview), opened in the 'TestSuite' popup;
 *  - the DIRECT parent suite name + details (testsuites.details);
 *  - design-time suite custom fields (legacy testsuite::get_linked_cfields_at_design
 *    + html_table_of_custom_field_values: cfield_design_values keyed by the
 *    suite node id, label from custom_fields.label, value via
 *    cfield_mgr::string_custom_field_value; BUGID-3989
 *    show_custom_fields_without_value still honoured);
 *  - download-only suite attachments (fk_table 'nodes_hierarchy', legacy
 *    gui->tSuiteAttachments loaded with getAttachmentInfos(...,$suite_id,
 *    'nodes_hierarchy',true,1)).
 * Returns null when the test case has no test-suite parent (TC directly under
 * the test project) — legacy get_ts_name_details() also returns nothing then.
 */
function esrTestSuite($db, $tcaseId, $tprojectId) {
    $tables = tlObjectWithDB::getDBTables(array(
        'nodes_hierarchy', 'testsuites', 'node_types'));
    $nh = $tables['nodes_hierarchy'];

    $par = $db->fetchFirstRow(
        "SELECT parent_id FROM {$nh} WHERE id = " . intval($tcaseId));
    if (is_null($par) || !isset($par['parent_id'])) { return null; }
    $directId = intval($par['parent_id']);
    if ($directId <= 0) { return null; }

    // testsuite node type id (node_types.description='testsuite', default 2)
    $tsType = 2;
    $nt = $db->get_recordset("SELECT id, description FROM {$tables['node_types']}");
    if (!is_null($nt)) {
        foreach ($nt as $r) {
            if (trim($r['description']) === 'testsuite') {
                $tsType = intval($r['id']); break;
            }
        }
    }

    // ancestors of the direct suite that are themselves test suites
    $path = array();
    $nodeId = $directId;
    $seen = array();
    while ($nodeId > 0 && !isset($seen[$nodeId])) {
        $seen[$nodeId] = 1;
        $row = $db->fetchFirstRow(
            "SELECT id, name, parent_id, node_type_id FROM {$nh} WHERE id = {$nodeId}");
        if (is_null($row)) { break; }
        if (intval($row['node_type_id']) !== $tsType) { break; }
        $path[] = array(
            'id' => intval($row['id']),
            'name' => strval($row['name']),
        );
        $parentId = intval($row['parent_id']);
        if ($parentId <= 0) { break; }
        $nodeId = $parentId;
    }
    if (count($path) == 0) { return null; }
    $path = array_reverse($path);

    // direct suite details
    $details = '';
    $tsr = $db->fetchFirstRow(
        "SELECT details FROM {$tables['testsuites']} WHERE id = {$directId}");
    if (!is_null($tsr) && isset($tsr['details'])) {
        $details = strval($tsr['details']);
    }

    // design-time suite custom fields (legacy testsuite::get_linked_cfields_at_design
    // + html_table_of_custom_field_values). Raw rows instead of the legacy HTML
    // table so the modern BFF stays JSON + the screen renders with its own styles.
    $cfs = array();
    try {
        $tsuiteMgr = new testsuite($db);
        $cfMap = $tsuiteMgr->get_linked_cfields_at_design(
            $directId, null, null, $tprojectId);
        if (!is_null($cfMap)) {
            $showEmpty = config_get('custom_fields')->show_custom_fields_without_value;
            foreach ($cfMap as $cfId => $cfInfo) {
                $hasValue = intval($cfInfo['node_id'] ?? 0);
                if (!$hasValue && !$showEmpty) { continue; }
                $cfs[] = array(
                    'id' => intval($cfId),
                    'label' => trim(str_replace(TL_LOCALIZE_TAG, '',
                        lang_get($cfInfo['label'], null, true))),
                    'value' => strval($tsuiteMgr->cfield_mgr
                        ->string_custom_field_value($cfInfo, $directId)),
                );
            }
        }
    } catch (\Throwable $e) {
        $cfs = array();
    }

    // download-only suite attachments (fk_table 'nodes_hierarchy')
    $attachments = array();
    try {
        $attachmentMgr = tlAttachmentRepository::create($db);
        $attItems = getAttachmentInfos($attachmentMgr, $directId,
            'nodes_hierarchy', true, 1);
        if ($attItems) {
            foreach ($attItems as $ai) {
                $attachments[] = array(
                    'id' => intval($ai['id']),
                    'title' => strval($ai['title']),
                    'file_name' => strval($ai['file_name']),
                    'file_size' => intval($ai['file_size']),
                    'download_url' => '/lib/attachments/attachmentdownload.php?id='
                        . intval($ai['id']),
                );
            }
        }
    } catch (Exception $e) {
        $attachments = array();
    }

    return array(
        'id' => $directId,
        'name' => $path[count($path) - 1]['name'],
        'details' => $details,
        'path' => $path,
        'cfs' => $cfs,
        'attachments' => $attachments,
    );
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
    // Refs #1400: legacy execSetResults.php:1425 gates the "edit test case on
    // execution" spec link on mgt_modify_tc at project+plan scope.
    $editTestcase = $user->hasRight($db, 'mgt_modify_tc', $tprojectId, $tplanId);

    list($tcaseMgr, $vinfo, $basic) =
        esrResolveTcVersion($db, $tplanMgr, $tplanId, $tcaseId, $tcversionId);

    $tprojectMgr = new testproject($db);
    $tprojInfo = $tprojectMgr->get_by_id($tprojectId);
    $prefix = !is_null($tprojInfo) ? strval($tprojInfo['prefix']) : '';

    $steps = esrSteps($db, $tcaseMgr, $tcversionId);
    list($builds, $defaultBuildId) = esrBuilds($tplanMgr, $tplanId, $db);
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
        esrPriorExecution($db, $tplanId, $buildId, $platformId, $tcversionId,
            $steps);

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

    // Step-level execution features (legacy $tlCfg->exec_cfg->steps_exec /
    // steps_exec_attachments, config.inc.php:1140-1144): gate the per-step
    // attachment uploader AND the "Save Steps Work In Progress Execution"
    // button+warning below the steps table (exec_test_spec.inc.tpl:53-73).
    $stepsExec = (int)(!isset($execCfg->steps_exec) || !empty($execCfg->steps_exec));
    $stepsExecAttachments =
        (int)(!isset($execCfg->steps_exec_attachments) || !empty($execCfg->steps_exec_attachments));
    $attCfg = config_get('attachments');
    $attachmentsEnabled =
        (int)(!isset($attCfg->enabled) || !empty($attCfg->enabled));

    $tcaseName = isset($basic['name']) ? strval($basic['name']) : '';
    $tcaseExternalId = strval($vinfo['tc_external_id']);

    list($requirements, $requirementsEnabled) =
        esrRequirements($db, $tcaseId, $tcversionId, $tprojectId, $user);
    $relations = esrRelations($db, $tcversionId);
    $keywords = esrKeywords($tcaseMgr, $tcaseId, $tcversionId);
    $suite = esrTestSuite($db, $tcaseId, $tprojectId);

    // Refs #1398: direct execution link + feature_id of the popup header
    // (legacy execSetResults.tpl "execInfo" controls), so the screen can
    // rebuild the shareable link whenever the build selector changes.
    list($featureId, $directLink) = array_values(
        esrDirectLink($db, $tplanId, $tcversionId, $platformId, $buildId));

    // Refs #1398: collapsible Test Plan / Build / Platform notes panels +
    // plan and build design-time custom fields (execution-scope filter).
    $notesPayload = esrNotesPayload($db, $tplanMgr, $tplanId, $tprojectId,
        $buildId, $platformId);

    // Refs #1398: legacy "Execute and Save Results" remote-execution button
    // is gated on exec_cfg->enable_test_automation (default DISABLED).
    $testAutomation = !empty($execCfg->enable_test_automation) ? 1 : 0;

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
            // Refs #1398: RFC-2119 column estimated_exec_duration + the
            // localized type label (legacy exec_test_spec.inc.tpl rows).
            'estimated_exec_duration' => floatval($vinfo['estimated_exec_duration'] ?? 0),
            'execution_type_label' => esrExecutionTypeLabel($vinfo['execution_type']),
        ],
        'steps' => $steps,
        'builds' => $builds,
        'default_build_id' => $defaultBuildId,
        'platforms' => $platforms,
        'platform_feature_enabled' => $platformFeature,
        'statuses' => esrStatuses(),
        'save_and_move' => esrSaveAndMove(),
        'nav' => esrNavTargets($db, $tplanMgr, $tplanId, $platformId, $tcaseId, $tcversionId),
        'grants' => [
            'can_execute' => $canExecute ? 1 : 0,
            'ro_access' => $roAccess ? 1 : 0,
            'edit_testcase' => $editTestcase ? 1 : 0,
        ],
        'exec_duration_enabled' => $execDurationEnabled,
        'steps_exec' => $stepsExec,
        'steps_exec_attachments' => $stepsExecAttachments,
        'attachments_enabled' => $attachmentsEnabled,
        'requirements_enabled' => $requirementsEnabled,
        'requirements' => $requirements,
        'relations' => $relations,
        'keywords' => $keywords,
        'suite' => $suite,
        'prior' => $prior,
        'prior_steps' => $priorSteps,
        // Refs #1398: direct link + remote execution feature flag
        'feature_id' => $featureId,
        'direct_link' => $directLink,
        'test_automation_enabled' => $testAutomation,
        // Refs #1398: collapsible notes panels payload
        'tplan_notes' => $notesPayload['tplan_notes'],
        'build_notes' => $notesPayload['build_notes'],
        'platform_notes' => $notesPayload['platform_notes'],
        'tplan_cfs' => $notesPayload['tplan_cfs'],
        'build_cfs' => $notesPayload['build_cfs'],
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

    // version MUST belong to the test case AND be linked to the plan —
    // same guarantee as init (prevents freezing coverage / auditing the
    // wrong test case via a mismatched tcase_id/tcversion_id pair)
    esrResolveTcVersion($db, $tplanMgr, $tplanId, $tcaseId, $tcversionId);

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

// ---------------------------------------------------------------------------
// POST ?action=save_partial — Steps Work In Progress save (legacy
// execSetResults.php:333-343 saveStepsPartialExec + testcase::
// saveStepsPartialExec()): persist only the per-step statuses/notes into
// execution_tcsteps_wip WITHOUT writing an overall execution and WITHOUT
// touching the overall result. The next full save clears these rows
// (write_execution() -> deleteStepsPartialExec, exec.inc.php:105-118).
// ---------------------------------------------------------------------------
if ($action === 'save_partial') {
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

    // WRITE right required (exec_ro_access is NOT enough), same as save
    if (!$user->hasRight($db, 'testplan_execute', $tprojectId, $tplanId)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'Insufficient rights']);
    }

    $tcaseId = intval($payload['tcase_id'] ?? 0);
    $tcversionId = intval($payload['tcversion_id'] ?? 0);
    $buildId = intval($payload['build_id'] ?? 0);
    $platformId = intval($payload['platform_id'] ?? -1);

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

    // platform_id must be 0 (legacy "no platform" default) or a real
    // platform of the plan - a forged id would write orphan WIP rows
    // that no later init re-reads
    $validPlatform = false;
    if ($platformId < 0) { $platformId = 0; }
    if ($platformId === 0) {
        $validPlatform = true;
    } else {
        $rawPlatforms = $tplanMgr->getPlatforms($tplanId);
        if (!is_null($rawPlatforms)) {
            foreach ($rawPlatforms as $p) {
                if (intval($p['id']) === $platformId) {
                    $validPlatform = true;
                    break;
                }
            }
        }
    }
    if (!$validPlatform) {
        http_response_code(400);
        out(['status' => 'error',
             'message' => 'Invalid platform for this plan']);
    }

    // version MUST belong to the test case AND be linked to the plan
    list($tcaseMgr, ) = esrResolveTcVersion($db, $tplanMgr, $tplanId,
        $tcaseId, $tcversionId);

    // step-level statuses/notes: keys are STEP IDS; every id must belong to
    // THIS version so forged ids cannot touch unrelated WIP rows
    $stepsIn = isset($payload['steps']) && is_array($payload['steps'])
        ? $payload['steps'] : [];
    $partialExec = ['notes' => [], 'status' => []];
    if (count($stepsIn) > 0) {
        $wanted = array_map('intval', array_keys($stepsIn));
        $validStepIds = [];
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
            $ss = strtolower(trim(strval($sv['status'] ?? '')));
            $partialExec['notes'][$sid] = $sn;
            $partialExec['status'][$sid] = $ss;
            // saveStepsPartialExec() itself blanks excluded/not_run/invalid
            // statuses (testcase.class.php:9670-9684) - legacy parity
        }
    }
    if (count($partialExec['notes']) == 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'No step results provided']);
    }

    $ctx = new stdClass();
    $ctx->testplan_id = $tplanId;
    $ctx->platform_id = $platformId > 0 ? $platformId : 0;
    $ctx->build_id = $buildId;
    $ctx->tester_id = $userId;
    $tcaseMgr->saveStepsPartialExec($partialExec, $ctx);

    out(['status' => 'ok', 'saved' => true]);
}

// ---------------------------------------------------------------------------
// POST ?action=remote_exec — "Execute and Save Results" (legacy
// launchRemoteExec -> buildExecContext -> do_remote_execution,
// execSetResults.php:2037,1997,1066-1210, Refs #1398). Gated on
// exec_cfg->enable_test_automation (DISABLED by default). The automation
// server settings travel via design-time custom fields
// (cfield_mgr::getXMLRPCServerParams). On success ('ok', scheduled 'now')
// an execution row is written with execution_type = AUTO, mirroring
// do_remote_execution(); the feedback map keeps the status/notes/scheduled
// info so the popup can surface the automation server result.
// ---------------------------------------------------------------------------
if ($action === 'remote_exec') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        out(['status' => 'error', 'message' => 'POST required']);
    }
    $execCfg = config_get('exec_cfg');
    if (empty($execCfg->enable_test_automation)) {
        http_response_code(403);
        out(['status' => 'error',
             'message' => 'Remote execution is not enabled by configuration']);
    }
    $payload = setResultsPayload();
    if (!is_array($payload) || count($payload) == 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid request body']);
    }
    $tplanId = intval($payload['tplan_id'] ?? 0);
    list($tplanMgr, $tplanId, $tprojectId, ) =
        esrResolvePlan($db, $user, $tplanId);

    // WRITE right required (exec_ro_access is NOT enough), same as save
    if (!$user->hasRight($db, 'testplan_execute', $tprojectId, $tplanId)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'Insufficient rights']);
    }

    $tcaseId = intval($payload['tcase_id'] ?? 0);
    $tcversionId = intval($payload['tcversion_id'] ?? 0);
    $buildId = intval($payload['build_id'] ?? 0);
    $platformId = intval($payload['platform_id'] ?? 0);
    $platformId = $platformId > 0 ? $platformId : 0;

    // closed builds never accept new executions (same guard the save
    // action enforces), even though the remote-exec front-end hides the
    // button when exec_cfg->enable_test_automation is off.
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

    // version MUST belong to the test case AND be linked to the plan
    list($tcaseMgr, ) = esrResolveTcVersion($db, $tplanMgr, $tplanId,
        $tcaseId, $tcversionId);

    // feature_id on testplan_tcversions (needed for the server config
    // custom-field retrieval; legacy buildExecContext -> getFeatureID)
    $featureId = 0;
    list($featureId, ) = array_values(
        esrDirectLink($db, $tplanId, $tcversionId, $platformId, 0));
    if ($featureId <= 0) {
        http_response_code(400);
        out(['status' => 'error',
             'message' => 'Test case is not linked to the test plan']);
    }

    // lib/functions/remote_exec.php defines executeTestCase() and loads the
    // IXR xml-rpc client (TL_ABS_PATH based, safe to require here).
    require_once(TL_ABS_PATH . 'lib/functions/remote_exec.php');

    $basic = $tcaseMgr->tree_manager->get_node_hierarchy_info($tcaseId);
    $tcaseInfo = array_merge((array)$basic,
        array('version_id' => $tcversionId));
    $serverCfg = $tcaseMgr->cfield_mgr->getXMLRPCServerParams(
        $tcversionId, $featureId);

    $context = array(
        'tproject_id' => $tprojectId,
        'tplan_id' => $tplanId,
        'platform_id' => $platformId,
        'build_id' => $buildId,
        'user_id' => $userId,
    );
    $execResult = executeTestCase($tcaseInfo, $serverCfg, $context);

    // mirror do_remote_execution() feedback + row writing for ONE version
    $feedback = array(
        'status' => null,
        'status_verbose' => '',
        'notes' => null,
        'system' => null,
        'scheduled' => null,
        'timestamp' => null,
        'execution_id' => null,
    );
    $systemStatus = strtolower(strval($execResult['system']['status'] ?? ''));
    if ($systemStatus === 'configproblems' || $systemStatus === 'connectionfailure') {
        $feedback['system'] = $execResult['system'];
    } else {
        $trun = isset($execResult['execution']) ? $execResult['execution'] : array();
        if ((strval($trun['scheduled'] ?? '') === 'now')) {
            $resultsCfg = config_get('results');
            $tcStatus = $resultsCfg['status_code'];
            $statusCode = strtolower(strval($trun['result'] ?? ''));
            if ($statusCode != $tcStatus['passed']
                && $statusCode != $tcStatus['failed']
                && $statusCode != $tcStatus['blocked']) {
                $statusCode = $tcStatus['blocked'];
            }
            $notes = trim(strval($trun['notes'] ?? ''));
            $execTables = tlObjectWithDB::getDBTables(array('executions'))['executions'];
            $sql = "INSERT INTO {$execTables} " .
                " (testplan_id, platform_id, build_id, tester_id, execution_type," .
                "  tcversion_id, execution_ts, status, notes)" .
                " VALUES ({$tplanId}, {$platformId}, {$buildId}, {$userId}," .
                TESTCASE_EXECUTION_TYPE_AUTO . ", {$tcversionId}, " .
                $db->db_now() . ", '" .
                $db->prepare_string($statusCode) . "', '" .
                $db->prepare_string($notes) . "')";
            $db->exec_query($sql);
            $feedback['execution_id'] = $db->insert_id();
            $feedback['status'] = $statusCode;
            $feedback['status_verbose'] = strval($trun['resultVerbose'] ?? '');
            $feedback['notes'] = $notes;
        } else {
            $feedback['scheduled'] = strval($trun['scheduled'] ?? '');
            $feedback['timestamp'] = strval($trun['timestampISO'] ?? '');
        }
    }

    out(['status' => 'ok', 'feedback' => $feedback]);
}

http_response_code(404);
out(['status' => 'error', 'message' => 'Unknown action']);