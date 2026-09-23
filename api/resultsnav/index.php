<?php
/**
 * api/resultsnav — Metrics & Reports Navigator BFF
 *
 * Modernizes lib/results/resultsNavigator.php (Refs #1563): the Test Results
 * and Metrics launcher hub. Lists every report available for a test plan
 * (title + modern screen URL + direct-link + format gate), surfaces the two
 * legacy sanity warnings (plan with no test cases / no builds) and returns the
 * format selector + accessible test plans, so the Dashio screen can replicate
 * the 1.9.20 navigator 1:1.
 *
 * Routes:
 *   GET ?action=init[&tplan_id=N][&tproject_id=M][&format=N]
 *     -> context (rights/plans/format/reportTypes) + warning flags + report
 *        items (name, href to the modern screen, directLink, format).
 *        Semantics: testplan_metrics right (legacy checkRights()), plan falls
 *        back to the session testplan, format key -1 as in legacy.
 *        401 anon / 400 no plan / 403 no right / 405 non-GET.
 * Session-based auth, JSON I/O, no Smarty.
 */
require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
require_once('users.inc.php');
require_once(__DIR__ . '/../../lib/functions/reports.class.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

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

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

$bff = function ($action) use ($db, $user) {
    $query = $_GET;

    $tproject_id = isset($query['tproject_id']) ? intval($query['tproject_id']) : 0;
    if ($tproject_id <= 0) {
        $tproject_id = isset($_SESSION['testprojectID']) ? intval($_SESSION['testprojectID']) : 0;
    }
    $tplan_id = isset($query['tplan_id']) ? intval($query['tplan_id']) : 0;
    if ($tplan_id <= 0) {
        $tplan_id = isset($_SESSION['testplanID']) ? intval($_SESSION['testplanID']) : 0;
    }

    // legacy checkRights()
    $hasRight = $user->hasRightOnProj($db, 'testplan_metrics');
    if (!$hasRight) {
        http_response_code(403);
        return ['status' => 'error', 'message' => 'Missing rights: testplan_metrics'];
    }
    if ($tplan_id <= 0) {
        http_response_code(400);
        return ['status' => 'error', 'message' => 'No active test plan in context'];
    }

    // resolve owning project (context project may be per-frame stale)
    $tables = tlObjectWithDB::getDBTables(array('testplans', 'testprojects'));
    $owning = $db->fetchOneValue(
        "SELECT tp.testproject_id FROM {$tables['testplans']} tp WHERE tp.id = " . intval($tplan_id));
    if (!$owning) {
        http_response_code(404);
        return ['status' => 'error', 'message' => 'Unknown test plan'];
    }
    $tproject_id = intval($owning);

    $reports_mgr = new tlReports($db, $tplan_id);

    // report format select (legacy defaults to first reports_formats key)
    $reports_formats = config_get('reports_formats');
    $format = isset($query['format']) ? intval($query['format']) : null;
    if (!array_key_exists($format, $reports_formats)) {
        $format = count($reports_formats) ? key($reports_formats) : null;
    }

    // warnings parity (legacy initializeGui / resultsNavigator.php)
    $do_report = ['status_ok' => 1, 'msg' => ''];
    if ($reports_mgr->get_count_testcase4testplan() == 0) {
        $do_report = ['status_ok' => 0, 'msg' => lang_get('report_tplan_has_no_tcases')];
    } elseif ($reports_mgr->get_count_builds() == 0) {
        $do_report = ['status_ok' => 0, 'msg' => lang_get('report_tplan_has_no_build')];
    }

    if ($action === 'init') {
        // accessible test plans (legacy getAccessibleTestPlans combo, active only)
        $tplans = array();
        $activeAttr = 1;
        $rows = $user->getAccessibleTestPlans($db, $tproject_id, null,
                                              array('output' => 'combo', 'active' => $activeAttr));
        if (is_array($rows)) {
            $tplans = $rows;
        }

        if ($do_report['status_ok']) {
            $tplan_mgr = new testplan($db);
            $dmy = $tplan_mgr->get_by_id($tplan_id);
            $context = new stdClass();
            $context->tproject_id = $tproject_id;
            $context->tplan_id = $tplan_id;
            $context->apikey = is_array($dmy) ? $dmy['api_key'] : '';
            $context->imgSet = array(
                'link_to_report' => '<i class="fa fa-link" aria-hidden="true"></i>',
            );

            $tprojMgr = new testproject($db);
            $tprojOpts = $tprojMgr->getOptions($tproject_id);
            $optReqs = (!empty($tprojOpts) && isset($tprojOpts->requirementsEnabled))
                ? $tprojOpts->requirementsEnabled : false;
            $btsEnabled = $tprojMgr->isIssueTrackerEnabled($tproject_id);

            $items = $reports_mgr->get_list_reports($context, $btsEnabled, $optReqs,
                                                    $reports_formats[$format]);
        } else {
            $items = array();
        }

        // map legacy report URLs onto their modern Dashio screens
        $legacy2modern = array(
            'test_plan' => 'gui/templates/results/reportPrint.html',
            'test_report' => 'gui/templates/results/reportPrint.html',
            'test_report_on_build' => 'gui/templates/results/reportPrint.html',
            'metrics_tp_general' => 'gui/templates/results/metricsDashboard.html',
            'report_by_tsuite' => 'gui/templates/results/resultsByTSuite.html',
            'baseline_l1l2' => 'gui/templates/results/baselineL1L2.html',
            'results_by_tester_per_build' => 'gui/templates/results/resultsByTesterPerBuild.html',
            'assigned_tc_overview' => 'gui/templates/results/tcAssignedToUser.html',
            'results_custom_query' => 'gui/templates/results/resultsMoreBuilds.html',
            'results_matrix' => 'gui/templates/results/resultsMatrix.html',
            'results_flat' => 'gui/templates/results/resultsTCFlat.html',
            'abslatest_results_matrix' => 'gui/templates/results/absoluteLatest.html',
            'list_tc_failed' => 'gui/templates/results/resultsByStatus.html',
            'list_tc_blocked' => 'gui/templates/results/resultsByStatus.html',
            'list_tc_not_run' => 'gui/templates/results/resultsByStatus.html',
            'never_run' => 'gui/templates/results/neverRun.html',
            'tcases_without_tester' => 'gui/templates/results/freeTestCases.html',
            'charts_basic' => 'gui/templates/results/charts.html',
            'results_requirements' => 'gui/templates/results/resultsRequirements.html',
            'uncovered_testcases' => 'gui/templates/results/uncoveredTestCases.html',
            'list_problems' => 'gui/templates/results/resultsBugs.html',
            'issues_all_exec' => 'gui/templates/results/resultsBugs.html',
            'tcases_with_cf' => 'gui/templates/results/tcasesWithCF.html',
            'tplan_with_cf' => 'gui/templates/results/tplanWithCF.html',
            'free_tcases' => 'gui/templates/results/freeTestCases.html',
        );

        $reportList = config_get('reports_list');
        $ctx = "tplan_id={$tplan_id}&tproject_id={$tproject_id}";
        $list = array();
        $n = 0;
        foreach ($reportList as $key => &$rptItem) {
            if ($n >= count($items)) {
                break;
            }
            if (!isset($items[$n])) {
                $n++;
                continue;
            }
            $entry = array(
                'key' => $key,
                'name' => $items[$n]['name'],
                'format' => isset($rptItem['format']) ? $rptItem['format'] : '',
                'href' => $items[$n]['href'],
                'directLink' => isset($items[$n]['directLink']) ? $items[$n]['directLink'] : '',
            );
            if (isset($legacy2modern[$key]) && strpos($entry['href'], 'lib/') === 0) {
                $entry['href'] = '/' . $legacy2modern[$key] . '?' . $ctx;
            }
            $list[] = $entry;
            $n++;
        }

        $reportTypes = array();
        foreach ($reports_formats as $k => $v) {
            $reportTypes[$k] = lang_get($v);
        }

        return array(
            'status' => 'ok',
            'context' => array(
                'tproject_id' => $tproject_id,
                'tplan_id' => $tplan_id,
                'format' => $format,
                'hasRight' => true,
                'tplans' => $tplans,
                'reportTypes' => $reportTypes,
                'warning' => $do_report['status_ok'] ? null : $do_report['msg'],
            ),
            'reports' => $list,
        );
    }

    http_response_code(400);
    return array('status' => 'error', 'message' => 'Unknown action');
};

try {
    $action = isset($_GET['action']) ? $_GET['action'] : '';
    $needle = 'init';
    if (substr($action, 0, strlen($needle)) === $needle) {
        echo json_encode($bff('init'));
    } else {
        http_response_code(400);
        echo json_encode(array('status' => 'error', 'message' => 'Unknown or missing action'));
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(array('status' => 'error', 'message' => 'Server error'));
}