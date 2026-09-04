<?php
/**
 * Reports BFF API - Test Plan Report navigator
 * URL: /api/reports/index.php
 * Plain PHP, no framework, no compilation
 *
 * Serves the modernized "Test Plan Report" screen
 * (gui/templates/results/testPlanReport.html), which mirrors
 * lib/results/printDocOptions.php?type=testplan (TestLink 1.9.20):
 *
 *   GET  ?action=tp_report_init&tproject_id=N&tplan_id=M
 *        -> context names + rights (the same right lib/results/printDocument.php
 *           enforces for this document type: 'testplan_metrics') + the print
 *           option checkbox sets (printDocOptions class: doc + testSpec) and
 *           the output formats.
 *   GET  ?action=tp_report_tree&tproject_id=N&tplan_id=M
 *        -> suite tree of the test project annotated with how many test cases
 *           are LINKED to the given test plan in each suite (direct + deep),
 *           so the user sees where the plan has content before printing.
 *
 * Document GENERATION itself stays in the legacy controller
 * lib/results/printDocument.php - the modern screen only navigates:
 *   whole plan: level=testproject&id=<tproject_id>&docTestPlanId=<tplan_id>
 *   one suite : level=testsuite&id=<suite_id>&docTestPlanId=<tplan_id>
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once(__DIR__ . '/../../cfg/reports.cfg.php');
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

// The right lib/results/printDocument.php requires before generating any
// test report document. Legacy checkRights() consults per-project roles
// too (hasRightOnProj()), while a null-context hasRight() call only sees
// the GLOBAL role - so users holding the right ONLY through a project role
// must not be rejected here. Fail closed: reject unless the global right
// exists or the request's own project/plan context grants it; every action
// additionally re-checks contextually once ids are resolved.
if (!$user->hasRight($db, 'testplan_metrics')) {
    $ctxProject = intval(getParam('tproject_id', 0));
    $ctxPlan = intval(getParam('tplan_id', 0));
    $ctxOk = false;
    if ($ctxProject > 0 || $ctxPlan > 0) {
        try {
            $ctxOk = $user->hasRight($db, 'testplan_metrics',
                $ctxProject, $ctxPlan);
        } catch (Exception $e) {
            $ctxOk = false;
        }
    }
    if (!$ctxOk) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'No permission']);
        exit;
    }
}

function out($data) { echo json_encode($data); exit; }
function getParam($key, $default = '') {
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : '';
}

// Exact copy of the legacy resultsByTesterPerBuild.php minutes2HHMMSS()
// algorithm: bcmul() first (decimal minutes lose precision through plain
// float math), integer operators afterwards, HH:MM:SS output.
function minutesToHHMMSS($minutes) {
    $min2sec = bcmul($minutes, 60);
    $hh = floor($min2sec / 3600);
    $mmss = ($min2sec % 3600);
    $mm = floor($mmss / 60);
    $ss = ($mmss % 60);
    return sprintf('%02d:%02d:%02d', $hh, $mm, $ss);
}

$tprojectId = intval(getParam('tproject_id', 0));
$tplanId = intval(getParam('tplan_id', 0));
$action = getParam('action');

// Document types sharing this navigator screen - values are the exact
// strings lib/results/printDocument.php expects in its type argument
// (DOC_TEST_PLAN_DESIGN / _EXECUTION / _EXECUTION_ON_BUILD).
$validTypes = ['testplan', 'testreport', 'testreport_onbuild'];
$docType = getParam('type', 'testplan');
if (!in_array($docType, $validTypes, true)) {
    $docType = 'testplan';
}
// i18n key resolved client-side for the screen/document title
$titleKeys = [
    'testplan' => 'tpr.header',
    'testreport' => 'tpr.docTitle.testreport',
    'testreport_onbuild' => 'tpr.docTitle.testreportOnBuild',
];

$tprojectMgr = new testproject($db);
$tplanMgr = new testplan($db);

if ($action === 'tp_report_init') {
    if ($tprojectId <= 0 || $tplanId <= 0) {
        out(['status' => 'ok', 'hasContext' => false]);
    }

    $proj = $tprojectMgr->get_by_id($tprojectId);
    if (is_null($proj) || !isset($proj['name'])) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test project id']);
    }
    $tplanInfo = $tplanMgr->get_by_id($tplanId);
    if (is_null($tplanInfo)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test plan id']);
    }

    // print options: exact copy of the printDocOptions class sets used by
    // printDocOptions.php?type=testplan (doc + testSpec), labels are i18n
    // keys resolved client side.
    $docOptions = [
        ['value' => 'toc',             'checked' => false],
        ['value' => 'headerNumbering', 'checked' => false],
    ];
    $testSpecOptions = [
        ['value' => 'header',     'checked' => false],
        ['value' => 'summary',    'checked' => true],
        ['value' => 'body',       'checked' => false],
        ['value' => 'author',     'checked' => false],
        ['value' => 'keyword',    'checked' => false],
        ['value' => 'cfields',    'checked' => false],
        ['value' => 'requirement','checked' => false],
    ];

    $formats = [
        ['id' => FORMAT_HTML,   'key' => 'tpr.formatHtml'],
        ['id' => FORMAT_MSWORD, 'key' => 'tpr.formatWord'],
    ];

    // DOC_TEST_PLAN_EXECUTION_ON_BUILD: legacy printDocOptions offers a
    // direct report link per build (lnl.php with the plan api key). Modern
    // re-points to the BFF print popup (Refs #845).
    $builds = [];
    if ($docType === 'testreport_onbuild') {
        $buildInfoSet = $tplanMgr->get_builds($tplanId);
        if (!is_null($buildInfoSet)) {
            foreach ($buildInfoSet as $bid => $binfo) {
                $builds[] = [
                    'id' => intval($bid),
                    'name' => $binfo['name'],
                    'report_url' => '/gui/templates/results/reportPrint.html' .
                        '?type=testreport_onbuild&level=testproject' .
                        '&id=' . $tprojectId .
                        '&tproject_id=' . $tprojectId .
                        '&tplan_id=' . $tplanId .
                        '&build_id=' . intval($bid) .
                        '&format=' . FORMAT_HTML,
                ];
            }
        }
    }

    out([
        'status' => 'ok',
        'hasContext' => true,
        'tproject_id' => $tprojectId,
        'tplan_id' => $tplanId,
        'tproject_name' => $proj['name'],
        'tplan_name' => $tplanInfo['name'],
        'doc_type' => $docType,
        'doc_title_key' => $titleKeys[$docType],
        'canGenerate' => true,
        'formats' => $formats,
        'docOptions' => $docOptions,
        'testSpecOptions' => $testSpecOptions,
        'builds' => $builds,
    ]);
}

if ($action === 'tp_report_tree') {
    if ($tprojectId <= 0 || $tplanId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Missing project or plan id']);
    }

    $proj = $tprojectMgr->get_by_id($tprojectId);
    if (is_null($proj)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test project id']);
    }

    $nt = [ // node type ids from cfield_mgr? use nodes_hierarchy types table
    ];
    $TLT = tlObject::getDBTables();
    $nh = $TLT['nodes_hierarchy'];
    $tpv = $TLT['testplan_tcversions'];
    $ntypes = $TLT['node_types'];
    $nt = [];
    $rows = $db->fetchRowsIntoMap(
        "SELECT id, description FROM {$ntypes}", 'description');
    foreach (['testsuite', 'testcase'] as $d) {
        $nt[$d] = isset($rows[$d]) ? intval($rows[$d]['id']) : 0;
    }

    // all suites of the project, one recursive walk (same pattern as the
    // planaddtc/suites route in api/plans)
    $sql = "WITH RECURSIVE subtree AS ( " .
        " SELECT NH.id AS node_id FROM {$nh} NH WHERE NH.id = {$tprojectId} " .
        " UNION ALL " .
        " SELECT NH.id FROM {$nh} NH " .
        " JOIN subtree S ON NH.parent_id = S.node_id " .
        " WHERE NH.node_type_id = {$nt['testsuite']} ) " .
        " SELECT NH.id, NH.name, NH.parent_id, NH.node_order " .
        " FROM {$nh} NH JOIN subtree S ON NH.id = S.node_id " .
        " WHERE NH.node_type_id = {$nt['testsuite']} " .
        " ORDER BY NH.parent_id, NH.node_order, NH.name";
    $suiteRows = $db->fetchRowsIntoMap($sql, 'id');

    $suites = [];
    $suiteIds = [];
    $childrenMap = [];
    if (!is_null($suiteRows) && count($suiteRows) > 0) {
        foreach ($suiteRows as $sid => $s) {
            $sid = intval($sid);
            $suites[$sid] = [
                'id' => $sid,
                'name' => (string)$s['name'],
                'parent_id' => intval($s['parent_id']),
                'linked_qty' => 0,
            ];
            $childrenMap[intval($s['parent_id'])][] = $sid;
            $suiteIds[] = $sid;
        }
    }

    if (count($suiteIds) > 0) {
        // distinct test cases of THIS plan inside each suite (direct parent)
        $idList = implode(',', $suiteIds);
        $sql = "SELECT COUNT(DISTINCT NHTC.id) AS qty, NHTC.parent_id AS tsuite_id " .
            " FROM {$tpv} TPTCV " .
            " JOIN {$nh} NHTCV ON NHTCV.id = TPTCV.tcversion_id " .
            " JOIN {$nh} NHTC ON NHTC.id = NHTCV.parent_id " .
            " WHERE TPTCV.testplan_id = {$tplanId} " .
            " AND NHTC.node_type_id = {$nt['testcase']} " .
            " AND NHTC.parent_id IN ({$idList}) GROUP BY NHTC.parent_id";
        $rows = $db->fetchRowsIntoMap($sql, 'tsuite_id');
        if (!is_null($rows)) {
            foreach ($rows as $sid => $r) {
                $suites[intval($sid)]['linked_qty'] = intval($r['qty']);
            }
        }
    }

    // build nested structure + deep sums
    $buildDeep = function($sid) use (&$buildDeep, &$childrenMap, &$suites) {
        $tot = $suites[$sid]['linked_qty'];
        foreach ($childrenMap[$sid] ?? [] as $ch) {
            $tot += $buildDeep($ch);
        }
        return $tot;
    };

    $buildNode = function($sid) use (&$buildNode, &$buildDeep, &$childrenMap, &$suites) {
        $kids = [];
        foreach ($childrenMap[$sid] ?? [] as $kidId) {
            $kids[] = $buildNode($kidId);
        }
        return [
            'id' => $sid,
            'name' => $suites[$sid]['name'],
            'linkedQty' => $buildDeep($sid),
            'children' => $kids,
        ];
    };

    $roots = [];
    foreach ($childrenMap[intval($tprojectId)] ?? [] as $kidId) {
        $roots[] = $buildNode($kidId);
    }

    out([
        'status' => 'ok',
        'tproject_id' => $tprojectId,
        'tplan_id' => $tplanId,
        'tproject_name' => $proj['name'],
        'roots' => $roots,
    ]);
}

if ($action === 'metrics_general') {
    // General Test Plan Metrics - mirrors lib/results/resultsGeneral.php
    // (Refs #618). All figures come from the very same tlTestPlanMetrics
    // render methods the legacy controller calls, so numbers stay 1:1.
    // Right gate ('testplan_metrics') already enforced above.
    if ($tplanId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Missing test plan id']);
    }

    $timerOn = microtime(true);
    $metricsMgr = new tlTestPlanMetrics($db);

    $tplanInfo = $tplanMgr->get_by_id($tplanId);
    if (is_null($tplanInfo)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test plan id']);
    }
    $tprojectId = intval($tplanInfo['testproject_id']);
    $proj = $tprojectMgr->get_by_id($tprojectId);
    if (is_null($proj)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test project id']);
    }

    // Contextual re-check: legacy checkRights() uses hasRightOnProj() with
    // the session/project context, so users holding the right ONLY through a
    // per-project role must pass here too (the global check above is just
    // the cheap first gate).
    if (!$user->hasRight($db, 'testplan_metrics', $tprojectId, $tplanId)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }

    // Platform set decides whether every "* on platform" section renders
    // (same rule as initializeGui(): empty set => fakePlatform '' +
    // showPlatforms=false). Legacy array('') carries implicit key 0, matching
    // TPTCV.platform_id = 0 rows of platform-less plans - keep int key 0.
    $platformSet = $tplanMgr->getPlatforms($tplanId, ['outputFormat' => 'map']);
    $showPlatforms = !is_null($platformSet) && count($platformSet) > 0;
    if ($showPlatforms) {
        natsort($platformSet);
    } else {
        $platformSet = [0 => ''];
    }

    $projOpts = $proj['opt'] ?? null;
    $priorityEnabled = is_object($projOpts)
        ? !empty($projOpts->testPriorityEnabled)
        : (is_array($projOpts) ? !empty($projOpts['testPriorityEnabled']) : false);

    // Top Level Suite totals - null means the plan has no linked test cases
    // at all => whole report collapses to the legacy empty-state message
    // ('report_tspec_has_no_tsuites').
    $tsInf = $metricsMgr->getStatusTotalsByTopLevelTestSuiteForRender(
        $tplanId, null, ['groupByPlatform' => 1]);

    $payload = [
        'status' => 'ok',
        'hasContext' => true,
        'hasData' => !is_null($tsInf),
        'tproject_id' => $tprojectId,
        'tplan_id' => $tplanId,
        'tproject_name' => $proj['name'],
        'tplan_name' => $tplanInfo['name'],
        'show_platforms' => $showPlatforms,
        'priority_enabled' => $priorityEnabled,
        // ordered platform list ([id => name]) so the client renders the
        // "* on platform X" sections in the same natsort order as legacy
        'platform_set' => $platformSet,
        // Export endpoints go through the authenticated BFF gateway
        // (api/reportsexport) which validates auth + rights, then proxies
        // to the legacy controller for actual XLS/mail generation.
        'send_mail_url' => '/api/reportsexport/index.php?action=general_metrics_mail&tplan_id=' . $tplanId . '&tproject_id=' . $tprojectId,
        'export_xls_url' => '/api/reportsexport/index.php?action=general_metrics&tplan_id=' . $tplanId . '&tproject_id=' . $tprojectId,
    ];

    if (!is_null($tsInf)) {
        $kwInf = $metricsMgr->getStatusTotalsByKeywordForRender(
            $tplanId, null, ['groupByPlatform' => 1]);

        $section = function($renderObj) {
            if (is_null($renderObj)) {
                return null;
            }
            return [
                'info' => isset($renderObj->info) ? $renderObj->info : null,
                'columns' => isset($renderObj->colDefinition) ? $renderObj->colDefinition : null,
            ];
        };

        $payload['suites'] = $section($tsInf);
        $payload['keywords'] = $section($kwInf);
        $payload['platform'] = $showPlatforms
            ? $section($metricsMgr->getStatusTotalsByPlatformForRender($tplanId))
            : null;

        if ($priorityEnabled) {
            $payload['priorities'] = $section(
                $metricsMgr->getStatusTotalsByPriorityForRender(
                    $tplanId, null,
                    ['getOnlyAssigned' => false, 'groupByPlatform' => 1]));
        }

        // Overall Build Status + Build-per-platform (same calls, same order
        // as the legacy controller).
        $payload['overall_build_status'] = $section(
            $metricsMgr->getOverallBuildStatusForRender($tplanId));
        $payload['build_by_platform'] =
            $section($metricsMgr->getBuildByPlatStatusForRender($tplanId));

        // Milestones & Priority report (only when the plan defines some).
        $milestonesList = $tplanMgr->get_milestones($tplanId);
        if (!empty($milestonesList)) {
            $mm = $metricsMgr->getMilestonesMetrics($tplanId, $milestonesList);
            $payload['milestones'] = is_null($mm) ? null : $mm;
        }
    }

    $payload['elapsed_time'] =
        round(microtime(true) - $timerOn, 2);
    out($payload);
}

if ($action === 'metrics_by_tsuite') {
    // Results by Test Suite - mirrors lib/results/resultsByTSuite.php
    // (Refs #671). Figures come from the very same
    // tlTestPlanMetrics::getStatusTotalsTSuiteDepth2ForRender() call the
    // legacy controller makes, so numbers stay 1:1. Right gate
    // ('testplan_metrics') already enforced above.
    if ($tplanId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Missing test plan id']);
    }

    $timerOn = microtime(true);
    $metricsMgr = new tlTestPlanMetrics($db);

    $tplanInfo = $tplanMgr->get_by_id($tplanId);
    if (is_null($tplanInfo)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test plan id']);
    }
    $tprojectId = intval($tplanInfo['testproject_id']);
    $proj = $tprojectMgr->get_by_id($tprojectId);
    if (is_null($proj)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test project id']);
    }

    // Contextual re-check: legacy checkRights() uses hasRightOnProj() with
    // the session/project context (same pattern as metrics_general above).
    if (!$user->hasRight($db, 'testplan_metrics', $tprojectId, $tplanId)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }

    // Platform set decides whether every "* on platform X" section renders
    // and whether the execution time span groups by platform. Empty set =>
    // fakePlatform '' with implicit key 0 (legacy initializeGui() contract).
    $platformSet = $tplanMgr->getPlatforms($tplanId, ['outputFormat' => 'map']);
    $showPlatforms = !is_null($platformSet) && count($platformSet) > 0;
    if ($showPlatforms) {
        natsort($platformSet);
    } else {
        $platformSet = [0 => ''];
    }
    $hasPlatforms = $showPlatforms;

    // Same metrics call as the legacy controller (groupByPlatform => 1).
    // NULL or an empty infoL2 means there is nothing to report on: a plan
    // with no linked test cases OR a flat test specification whose test
    // cases all sit directly under level-1 suites (no level-2 children).
    $tsInf = $metricsMgr->getStatusTotalsTSuiteDepth2ForRender(
        $tplanId, null, ['groupByPlatform' => 1]);

    $payload = [
        'status' => 'ok',
        'hasContext' => true,
        'hasData' => false,
        'tproject_id' => $tprojectId,
        'tplan_id' => $tplanId,
        'tproject_name' => $proj['name'],
        'tplan_name' => $tplanInfo['name'],
        'show_platforms' => $showPlatforms,
        'platform_set' => $platformSet,
        // same natsort order as platform_set, but as an ordered [{id,name}]
        // list - JS re-sorts integer-like object keys numerically, which
        // would break the legacy natsort(platform names) section order
        'platform_order' => array_map(
            function ($id) use ($platformSet) {
                return ['id' => (string)$id, 'name' => $platformSet[$id]];
            },
            array_keys($platformSet)),
        // legacy lib/results/resultsByTSuite.php endpoints kept for the two
        // Export endpoints go through the authenticated BFF gateway
        'send_mail_url' => '/api/reportsexport/index.php?action=results_by_tsuite_mail&tplan_id=' . $tplanId . '&tproject_id=' . $tprojectId,
        'export_xls_url' => '/api/reportsexport/index.php?action=results_by_tsuite&tplan_id=' . $tplanId . '&tproject_id=' . $tprojectId,
    ];

    if (!is_null($tsInf) && !empty($tsInf->infoL2)) {
        $payload['hasData'] = true;

        // Column definition: exact copy of the legacy loop over the FIRST
        // data row's details - one qty + one [%] column per execution
        // status, labels resolved server-side through lang_get() so the
        // session locale applies (legacy used Smarty lang_get too).
        $firstRow = current(current($tsInf->infoL2));
        $columns = [];
        if (isset($firstRow['details'])) {
            $cfgResults = config_get('results');
            foreach ($firstRow['details'] as $statusVerbose => $value) {
                $lbl = isset($cfgResults['status_label'][$statusVerbose])
                    ? lang_get($cfgResults['status_label'][$statusVerbose])
                    : $statusVerbose;
                $columns[$statusVerbose] = [
                    'qty' => $lbl,
                    'percentage' => '[%]',
                ];
            }
        }
        $payload['columns'] = $columns;

        // Execution time span (First/Latest execution header of every
        // table). getExecTimeSpan() returns NULL when the plan has no
        // executions at all -> guard instead of dereferencing.
        $execContext = ['testplan_id'];
        if ($hasPlatforms) {
            $execContext[] = 'platform_id';
        }
        $span = $metricsMgr->getExecTimeSpan($tplanId, $execContext);

        $spanOut = [];
        // RAW timestamps on purpose: localize_dateOrTimeStamp()/strftime
        // placeholders break under this PHP 8 runtime (see bug #563 - dates
        // rendered literally like "%24/%51/%2026"). The client formats them
        // with toLocaleString() instead.
        if ($hasPlatforms) {
            if (!is_null($span) && isset($span[$tplanId])) {
                foreach ($span[$tplanId] as $platIdS => $sp) {
                    $spanOut[$platIdS] = [
                        'begin' => $sp['begin'],
                        'end' => $sp['end'],
                    ];
                }
            }
        } else {
            $one = (isset($span[$tplanId]) && !is_null($span[$tplanId]))
                ? $span[$tplanId] : null;
            $spanOut[0] = [
                'begin' => is_null($one) ? null : $one['begin'],
                'end' => is_null($one) ? null : $one['end'],
            ];
        }
        $payload['span'] = $spanOut;

        // Rows: reorder every platform's block following the natural case
        // sort of the test suite NAMES (legacy natcasesort(idNameMap)).
        // Emitted as ORDERED LISTS, not id-keyed maps: JSON integer-like
        // keys come back as objects and JS Object.keys() re-sorts them
        // numerically ascending, which would destroy the name ordering
        // this report exists for.
        $nameMap = $tsInf->idNameMap;
        natcasesort($nameMap);
        $sortedKeys = array_keys($nameMap);

        $suites = [];
        foreach ($tsInf->infoL2 as $platIdS => $elem) {
            $rows = [];
            foreach ($sortedKeys as $itemID) {
                if (isset($elem[$itemID])) {
                    $row = $elem[$itemID];
                    $rows[] = [
                        'id' => intval($itemID),
                        'name' => $row['name'],
                        'total_tc' => intval($row['total_tc']),
                        'percentage_completed' =>
                            $row['percentage_completed'],
                        'details' => $row['details'],
                    ];
                }
            }
            $suites[$platIdS] = $rows;
        }
        $payload['suites'] = $suites;
    }

    $payload['elapsed_time'] =
        round(microtime(true) - $timerOn, 2);
    out($payload);
}

if ($action === 'metrics_baseline_l1l2') {
    // Baselines L1 & L2 report - mirrors lib/results/baselinel1l2.php
    // (Refs #673). Reads the SAVED baseline snapshots straight from
    // baseline_l1l2_context / baseline_l1l2_details exactly like the
    // legacy controller's SQL (same joins, same ORDER BY), rebuilds the
    // per-status qty/[%] matrix with the very same results-config status
    // display order and computes percentage_completed the same way
    // ((total - not_run) / total * 100). Right gate ('testplan_metrics')
    // already enforced above.
    if ($tplanId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Missing test plan id']);
    }

    $timerOn = microtime(true);

    $tplanInfo = $tplanMgr->get_by_id($tplanId);
    if (is_null($tplanInfo)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test plan id']);
    }
    $tprojectId = intval($tplanInfo['testproject_id']);
    $proj = $tprojectMgr->get_by_id($tprojectId);
    if (is_null($proj)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test project id']);
    }

    // Contextual re-check: legacy checkRights() uses hasRightOnProj() with
    // the session/project context (same pattern as metrics_general above).
    if (!$user->hasRight($db, 'testplan_metrics', $tprojectId, $tplanId)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }

    // Platform set decides whether every "* on platform X" section renders
    // and whether the platform relationship notice shows. Empty set =>
    // fakePlatform '' with implicit key 0 (legacy initializeGui() contract).
    $platformSet = $tplanMgr->getPlatforms($tplanId, ['outputFormat' => 'map']);
    $showPlatforms = !is_null($platformSet) && count($platformSet) > 0;
    if ($showPlatforms) {
        natsort($platformSet);
    } else {
        $platformSet = [0 => ''];
    }

    // Column definition: exact copy of the legacy loop over the results
    // config display order - one qty + one [%] column per execution status,
    // labels resolved server-side through lang_get() so the session locale
    // applies (legacy did lang_get() in initializeGui flow too).
    $cfgResults = config_get('results');
    $codeToStatus = array_flip($cfgResults['status_code']);
    $columns = [];
    foreach ($cfgResults['status_order'] as $statusCode) {
        $verbose = $codeToStatus[$statusCode];
        $columns[$verbose] = [
            'qty' => lang_get($cfgResults['status_label'][$verbose]),
            'percentage' => '[%]',
        ];
    }

    // Same SQL as the legacy controller (baselinel1l2.php lines ~62-75):
    // newest snapshot first, suites alphabetical inside each snapshot.
    $tbl = tlObject::getDBTables([
        'baseline_l1l2_context', 'baseline_l1l2_details', 'nodes_hierarchy',
    ]);
    $sql = "SELECT context_id,BLDT.id AS detail_id, " .
        "testplan_id,platform_id, " .
        "begin_exec_ts,end_exec_ts,creation_ts, " .
        "top_tsuite_id,child_tsuite_id,status,qty,total_tc, " .
        "TS_TOP.name AS top_name, TS_CHI.name AS child_name " .
        "FROM {$tbl['baseline_l1l2_context']} BLC " .
        "JOIN {$tbl['baseline_l1l2_details']} BLDT " .
        "ON BLDT.context_id = BLC.id " .
        "JOIN {$tbl['nodes_hierarchy']} AS TS_TOP " .
        "ON TS_TOP.id = top_tsuite_id " .
        "JOIN {$tbl['nodes_hierarchy']} AS TS_CHI " .
        "ON TS_CHI.id = child_tsuite_id " .
        "WHERE BLC.testplan_id = {$tplanId} " .
        "ORDER BY BLC.creation_ts DESC, top_name ASC, child_name ASC";
    $rsu = $db->fetchRowsIntoMap4l($sql,
        ['platform_id', 'context_id', 'top_tsuite_id', 'child_tsuite_id'], true);
    if (is_null($rsu)) {
        $rsu = [];
    }

    // Build the report structure. Emitted as ORDERED LISTS everywhere order
    // matters: JS Object.keys() re-sorts integer-like JSON object keys
    // numerically ascending, which would destroy both the natsort platform
    // section order and the creation_ts DESC snapshot order (same lesson as
    // #671).
    $platBlocks = [];
    $hasData = false;
    foreach ($platformSet as $platId => $platName) {
        $snapshotsOut = [];
        if (isset($rsu[$platId])) {
            foreach ($rsu[$platId] as $contextId => $dataByTop) {
                $first = current(current($dataByTop))[0];
                $snap = [
                    // RAW timestamps: client formats with toLocaleString()
                    // (strftime placeholders break under PHP 8, bug #563)
                    'context_id' => intval($contextId),
                    'begin' => $first['begin_exec_ts'],
                    'end' => $first['end_exec_ts'],
                    'baseline_ts' => $first['creation_ts'],
                    'rows' => [],
                ];
                foreach ($dataByTop as $topId => $dataByChild) {
                    foreach ($dataByChild as $childId => $dataX) {
                        $dfx = $dataX[0];
                        $details = [];
                        foreach ($columns as $verbose => $v) {
                            $details[$verbose] =
                                ['qty' => 0, 'percentage' => 0];
                        }
                        foreach ($dataX as $xmen) {
                            $pp = ($dfx['total_tc'] > 0)
                                ? round(($xmen['qty'] / $dfx['total_tc']) * 100, 1)
                                : 0;
                            $details[$codeToStatus[$xmen['status']]] = [
                                'qty' => intval($xmen['qty']),
                                'percentage' => $pp,
                            ];
                        }
                        $percCompleted = -1;
                        if ($dfx['total_tc'] > 0) {
                            $percCompleted = round(
                                (($dfx['total_tc'] -
                                    $details['not_run']['qty']) /
                                    $dfx['total_tc']) * 100, 1);
                        }
                        $snap['rows'][] = [
                            'name' => $dfx['top_name'] . ':' .
                                $dfx['child_name'],
                            'total_tc' => intval($dfx['total_tc']),
                            'percentage_completed' => $percCompleted,
                            'details' => $details,
                        ];
                    }
                }
                $snapshotsOut[] = $snap;
                $hasData = true;
            }
        }
        $platBlocks[] = [
            'id' => (string)$platId,
            'name' => $platName,
            'snapshots' => $snapshotsOut,
        ];
    }

    $payload = [
        'status' => 'ok',
        'hasContext' => true,
        'hasData' => $hasData,
        'tproject_id' => $tprojectId,
        'tplan_id' => $tplanId,
        'tproject_name' => $proj['name'],
        'tplan_name' => $tplanInfo['name'],
        'show_platforms' => $showPlatforms,
        'columns' => $columns,
        'platform_blocks' => $platBlocks,
        // legacy lib/results/baselinel1l2.php endpoints kept for the two
        // Export endpoints go through the authenticated BFF gateway
        'send_mail_url' => '/api/reportsexport/index.php?action=baseline_l1l2_mail&tplan_id=' . $tplanId . '&tproject_id=' . $tprojectId,
        'export_xls_url' => '/api/reportsexport/index.php?action=baseline_l1l2&tplan_id=' . $tplanId . '&tproject_id=' . $tprojectId,
        'elapsed_time' => round(microtime(true) - $timerOn, 2),
    ];
    out($payload);
}

if ($action === 'metrics_by_tester_per_build') {
    // Results by Tester per Build report - mirrors
    // lib/results/resultsByTesterPerBuild.php (Refs #677). Calls the very
    // same tlTestPlanMetrics::getStatusTotalsByBuildUAForRender() the legacy
    // controller uses (identical per-build/user matrix, status config order,
    // number_format() rounding), rebuilds the per-build progress rollup with
    // the exact legacy math and formats durations through the same
    // minutes2HHMMSS() algorithm. Right gate ('testplan_metrics') enforced
    // above + contextual re-check below.
    if ($tplanId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Missing test plan id']);
    }

    $timerOn = microtime(true);

    $tplanInfo = $tplanMgr->get_by_id($tplanId);
    if (is_null($tplanInfo)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test plan id']);
    }
    $tprojectId = intval($tplanInfo['testproject_id']);
    $proj = $tprojectMgr->get_by_id($tprojectId);
    if (is_null($proj)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test project id']);
    }

    // Contextual re-check: legacy checkRights() uses hasRightOnProj() with
    // the session/project context (same pattern as metrics_baseline_l1l2).
    if (!$user->hasRight($db, 'testplan_metrics', $tprojectId, $tplanId)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }

    // show_closed_builds toggle - same session persistence contract as the
    // legacy controller ($_SESSION['reports_show_closed_builds']): value
    // present in the request updates the session, otherwise the stored
    // choice applies.
    $showClosed = false;
    if (isset($_SESSION['reports_show_closed_builds'])) {
        $showClosed = (bool)$_SESSION['reports_show_closed_builds'];
    }
    $scbParam = $_GET['show_closed_builds'] ?? null;
    if ($scbParam !== null) {
        $showClosed = (bool)intval($scbParam);
        $_SESSION['reports_show_closed_builds'] = $showClosed;
    }

    // Legacy short-circuit: no open builds while the closed-builds view is
    // not requested -> warning instead of a table.
    $openBuildsQty = $tplanMgr->getNumberOfBuilds($tplanId, null,
        testplan::OPEN_BUILDS);

    $metricsMgr = new tlTestPlanMetrics($db);
    $statusCfg = $metricsMgr->getStatusConfig();

    $buildsOut = [];
    $warningKey = '';
    if ($openBuildsQty <= 0 && !$showClosed) {
        $warningKey = 'no_open_builds';
    } else {
        $metrics = $metricsMgr->getStatusTotalsByBuildUAForRender($tplanId,
            ['processClosedBuilds' => $showClosed]);
        // issue #634 parity: plan without builds => no metrics to render
        $matrix = is_null($metrics) ? [] : (array)$metrics->info;

        $option = $showClosed ? null : testplan::GET_OPEN_BUILD;
        $buildSet = $metricsMgr->get_builds($tplanId,
            testplan::GET_ACTIVE_BUILD, $option);
        // tlUser::getNames() is an INSTANCE method (legacy calls it on its
        // own tlUser object) - reuse the authenticated $user instance.
        $names = $user->getNames($db);

        foreach ($matrix as $buildId => $buildExecMap) {
            // Per-build rollup: exact copy of the legacy loop
            // (resultsByTesterPerBuild.php lines ~62-87), guarded against
            // division by zero for builds whose only rows have total 0.
            $bTotal = 0;
            $bExecuted = 0;
            $bTime = 0;
            foreach ($buildExecMap as $userId => $statistics) {
                $bTotal += $statistics['total'];
                $bExecuted += ($statistics['total'] -
                    $statistics['not_run']['count']);
                $bTime += $statistics['total_time'];
            }
            $bProgress = ($bTotal > 0)
                ? round(($bExecuted / $bTotal) * 100, 2) : 0;

            $usersOut = [];
            foreach ($buildExecMap as $userId => $statistics) {
                $statuses = [];
                foreach ($statusCfg as $verboseStatus => $code) {
                    $statuses[$verboseStatus] = [
                        'count' => intval($statistics[$verboseStatus]['count']),
                        'percentage' => $statistics[$verboseStatus]['percentage'],
                    ];
                }
                $usersOut[] = [
                    'user_id' => intval($userId),
                    // deleted/renamed users must not render an empty cell
                    'login' => isset($names[$userId]['login'])
                        ? $names[$userId]['login'] : ('user_' . intval($userId)),
                    'total' => intval($statistics['total']),
                    'statuses' => $statuses,
                    'progress' => $statistics['progress'],
                    'total_time' =>
                        minutesToHHMMSS($statistics['total_time']),
                ];
            }

            $buildName = isset($buildSet[$buildId]['name'])
                ? $buildSet[$buildId]['name'] : '';
            $buildsOut[] = [
                'id' => intval($buildId),
                'name' => $buildName,
                'progress' => $bProgress,
                'total_time' => minutesToHHMMSS($bTime),
                'users' => $usersOut,
            ];
        }
        if (count($buildsOut) === 0) {
            $warningKey = 'no_testers_per_build';
        }
    }

    // Column headers: one qty + one [%] column per execution status in the
    // results-config display order, labels resolved server-side via
    // lang_get() so the session locale applies (same approach as the
    // baseline_l1l2 action).
    $cfgResults = config_get('results');
    $columns = [];
    foreach ($statusCfg as $verboseStatus => $code) {
        $label = lang_get($cfgResults['status_label'][$verboseStatus]);
        $columns[] = ['key' => $verboseStatus, 'label' => $label];
    }

    $payload = [
        'status' => 'ok',
        'hasContext' => true,
        'hasData' => count($buildsOut) > 0,
        'warning_key' => $warningKey,
        'tproject_id' => $tprojectId,
        'tplan_id' => $tplanId,
        'tproject_name' => $proj['name'],
        'tplan_name' => $tplanInfo['name'],
        'show_closed_builds' => $showClosed,
        'columns' => $columns,
        'builds' => $buildsOut,
        // user link target -> modernized Test Cases Assigned to User popup
        // (Refs #840); the URL lacks tproject_id which the client injects.
        'assignment_url' => '/gui/templates/results/tcAssignedToUser.html',
        'elapsed_time' => round(microtime(true) - $timerOn, 2),
    ];
    out($payload);
}

if ($action === 'metrics_results_matrix') {
    // Test Results Matrix report - mirrors lib/results/resultsTC.php
    // (Refs #681). Calls the very same tlTestPlanMetrics::getExecStatusMatrix()
    // the legacy controller uses and rebuilds the row/cell structure with the
    // exact buildDataSet() FORMAT_HTML logic (per-build status cells,
    // optional "result on latest created build" + its note column, latest
    // execution + note). Right gate ('testplan_metrics') enforced above +
    // contextual re-check below. XLS/email generation stays on the legacy
    // controller exactly like the other modernized reports.
    if ($tplanId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Missing test plan id']);
    }

    $timerOn = microtime(true);

    $tplanInfo = $tplanMgr->get_by_id($tplanId);
    if (is_null($tplanInfo)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test plan id']);
    }
    $tprojectId = intval($tplanInfo['testproject_id']);
    $proj = $tprojectMgr->get_by_id($tprojectId);
    if (is_null($proj)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test project id']);
    }

    // Contextual re-check: legacy checkRights() uses hasRightOnProj() with
    // the session/project context (same pattern as the other metrics_*).
    if (!$user->hasRight($db, 'testplan_metrics', $tprojectId, $tplanId)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }

    // Legacy initializeGui(): active builds only, ordered by the configured
    // clause, optionally reversed so the newest build lands leftmost.
    $matrixCfg = config_get('resultMatrixReport');
    $buildInfoSet = $tplanMgr->get_builds($tplanId, testplan::ACTIVE_BUILDS,
        null, ['orderBy' => $matrixCfg->buildOrderByClause]);
    if (!empty($matrixCfg->buildColumns['latestBuildOnLeft']) &&
        !is_null($buildInfoSet)) {
        $buildInfoSet = array_reverse($buildInfoSet);
    }
    $activeBuildsQty = is_null($buildInfoSet) ? 0 : count($buildInfoSet);

    // setUpBuilds(): explicit selection wins over "all active builds".
    // do_action=result mirrors the legacy launcher submit contract.
    $doApply = (getParam('do_action', '') === 'result');
    $buildSetParam = [];
    if (isset($_GET['build_set']) && is_array($_GET['build_set'])) {
        foreach ($_GET['build_set'] as $bid) {
            $bid = intval($bid);
            if ($bid > 0) {
                $buildSetParam[] = $bid;
            }
        }
    }
    $filterApplied = count($buildSetParam) > 0;

    $buildsOut = [];
    if (!is_null($buildInfoSet)) {
        foreach ($buildInfoSet as $bid => $binfo) {
            $buildsOut[] = [
                'id' => intval($bid),
                'name' => $binfo['name'],
            ];
        }
    }

    // Legacy guard: ACTIVE builds above the configured qty limit force the
    // launcher page unless the user explicitly submitted a small-enough
    // selection through it.
    $idSet = $filterApplied
        ? array_keys(array_flip($buildSetParam))
        : (is_null($buildInfoSet) ? null : array_keys($buildInfoSet));

    $needSelection = false;
    if (($activeBuildsQty > $matrixCfg->buildQtyLimit) &&
        !($doApply && count($idSet) <= $matrixCfg->buildQtyLimit)) {
        $needSelection = true;
    }

    // "Latest created build" = end() of the id set AFTER any reversal -
    // exact legacy quirk kept verbatim.
    $latestBuildId = null;
    $latestBuildName = '';
    if (is_array($idSet) && !is_null($buildInfoSet)) {
        $lbId = end($idSet);
        $latestBuildId = intval($lbId);
        $latestBuildName = isset($buildInfoSet[$lbId]['name'])
            ? $buildInfoSet[$lbId]['name'] : '';
    }

    $payload = [
        'status' => 'ok',
        'hasContext' => true,
        'tproject_id' => $tprojectId,
        'tplan_id' => $tplanId,
        'tproject_name' => $proj['name'],
        'tplan_name' => $tplanInfo['name'],
        'need_build_selection' => $needSelection,
        'build_qty_limit' => intval($matrixCfg->buildQtyLimit),
        'active_builds_qty' => $activeBuildsQty,
        'filter_applied' => $filterApplied,
        'filter_feedback' => [],
        'show_platforms' => false,
        'test_priority_enabled' =>
            !empty($proj['opt']->testPriorityEnabled),
        'priority_labels' => [],
        'columns' => [],
        'rows' => [],
        'latest_build_id' => $latestBuildId,
        'elapsed_time' => round(microtime(true) - $timerOn, 2),
    ];

    if ($needSelection) {
        $payload['builds'] = $buildsOut;
        // BFF gateway for XLS download + email
        $payload['export_xls_url'] = '/api/reportsexport/index.php?action=results_matrix&tplan_id=' . $tplanId . '&tproject_id=' . $tprojectId;
        $payload['send_mail_url'] = '/api/reportsexport/index.php?action=results_matrix_mail&tplan_id=' . $tplanId . '&tproject_id=' . $tprojectId;
        out($payload);
    }

    $metricsMgr = new tlTestPlanMetrics($db);
    $execStatus = $metricsMgr->getExecStatusMatrix($tplanId,
        ['buildSet' => $idSet], ['getExecutionNotes' => true]);
    $metrics = $execStatus['metrics'];
    $latestExec = $execStatus['latestExec'];

    // Status vocabulary resolved server-side so the session locale applies
    // (legacy did lang_get() in initializeGui()/buildDataSet()).
    // results cfg maps VERBOSE => CODE (passed => p); label lookup is by
    // verbose, cell indexing is by code.
    $cfgResults = config_get('results');
    $statusLabels = [];
    foreach ($cfgResults['status_code'] as $verbose => $scode) {
        $statusLabels[$scode] =
            lang_get($cfgResults['status_label'][$verbose]);
    }
    $notRunCode = $cfgResults['status_code']['not_run'];

    // Priority display labels from the priority config - same source the
    // legacy spreadsheet branch used ($cfg['priority'] built from
    // $tlCfg->priority['code_label']), keyed by numeric level.
    $prioCfg = config_get('priority');
    $prioLabels = [];
    foreach ($prioCfg['code_label'] as $pcode => $plabel) {
        $prioLabels[intval($pcode)] = lang_get($plabel);
    }
    $payload['priority_labels'] = $prioLabels;

    // Platforms decide the optional column - same getPlatforms(map) call as
    // initializeGui().
    $platformMap = $tplanMgr->getPlatforms($tplanId,
        ['outputFormat' => 'map']);
    $showPlatforms = !is_null($platformMap) && count($platformMap) > 0;
    $payload['show_platforms'] = $showPlatforms;
    if ($showPlatforms) {
        natsort($platformMap);
    }

    $tcCfg = config_get('testcase_cfg');
    $fullPrefix = $proj['prefix'] . $tcCfg->glue_character;

    $columns = [
        ['key' => 'suite_name',
         'label' => lang_get('title_test_suite_name')],
        ['key' => 'tcname',
         'label' => lang_get('title_test_case_title')],
    ];
    if ($showPlatforms) {
        $columns[] = ['key' => 'platform_name',
                      'label' => lang_get('platform')];
    }
    if ($payload['test_priority_enabled']) {
        $columns[] = ['key' => 'priority',
                      'label' => lang_get('priority')];
    }
    $colBuildIds = [];
    if (is_array($idSet)) {
        foreach ($idSet as $bid) {
            $colBuildIds[] = intval($bid);
            $columns[] = ['key' => 'build_' . intval($bid),
                          'build_id' => intval($bid),
                          'label' => isset($buildInfoSet[$bid]['name'])
                              ? $buildInfoSet[$bid]['name'] : '',
                          'type' => 'status'];
        }
    }
    if (!empty($matrixCfg->buildColumns['showExecutionResultLatestCreatedBuild'])) {
        $columns[] = ['key' => 'result_on_latest',
                      'label' => lang_get('result_on_last_build') .
                          ' ' . $latestBuildName,
                      'type' => 'status'];
    }
    if (!empty($matrixCfg->buildColumns['showExecutionNoteLatestCreatedBuild'])) {
        $columns[] = ['key' => 'note_on_latest',
                      'label' =>
                          lang_get('test_exec_notes_latest_created_build'),
                      'type' => 'notes'];
    }
    $columns[] = ['key' => 'last_exec', 'label' => lang_get('last_execution'),
                  'type' => 'status'];
    $columns[] = ['key' => 'latest_notes',
                  'label' => lang_get('latest_exec_notes'),
                  'type' => 'notes'];

    // Filter feedback: selected build names, same content the legacy screen
    // passed to inc_result_tproject_tplan.tpl.
    if ($filterApplied) {
        foreach ($idSet as $bid) {
            $payload['filter_feedback'][] =
                isset($buildInfoSet[$bid]['name'])
                    ? $buildInfoSet[$bid]['name'] : ('#' . intval($bid));
        }
    }

    // Row construction: faithful port of buildDataSet() FORMAT_HTML branch.
    // JS Object.keys() re-sorts integer-like keys numerically ascending, so
    // iteration happens SERVER-SIDE into ordered lists (same lesson as #671).
    $rowsOut = [];
    if (!is_null($metrics)) {
        foreach ($metrics as $tsuiteId => $tcaseSet) {
            foreach ($tcaseSet as $tcaseId => $platSet) {
                foreach ($platSet as $platformId => $rf) {
                    $buildIdsHere = array_keys($rf);
                    $top = current($buildIdsHere);
                    $topRow = $rf[$top];

                    $cellsByBuild = [];
                    foreach ($rf as $bid => $erow) {
                        $cellsByBuild[intval($bid)] = [
                            'status_code' => $erow['status'],
                            'status_label' =>
                                isset($statusLabels[$erow['status']])
                                    ? $statusLabels[$erow['status']] : '',
                            'version' => intval($erow['version']),
                            'tcversion_id' => intval($erow['tcversion_id']),
                            'platform_id' => intval($platformId),
                        ];
                    }

                    $orderedCells = [];
                    $resultOnLatest = null;
                    $noteOnLatest = '';
                    $lastExecCell = null;
                    if (is_array($idSet)) {
                        foreach ($idSet as $bid) {
                            $bid = intval($bid);
                            if (!isset($cellsByBuild[$bid])) {
                                continue;
                            }
                            $cell = $cellsByBuild[$bid];
                            $orderedCells[] = $cell;

                            if (!empty($matrixCfg->buildColumns[
                                    'showExecutionResultLatestCreatedBuild']) &&
                                $latestBuildId === $bid) {
                                $resultOnLatest = $cell;
                            }
                            if (!empty($matrixCfg->buildColumns[
                                    'showExecutionNoteLatestCreatedBuild']) &&
                                $latestBuildId === $bid &&
                                !empty($rf[intval($bid)]['execution_notes'])) {
                                $noteOnLatest =
                                    $rf[intval($bid)]['execution_notes'];
                            }
                            // Legacy lexec rule: not-run latest execution
                            // tracks ANY iterated build (so the last one
                            // wins), otherwise only the exact matching
                            // execution id on the matching build.
                            $lex = isset($latestExec[$platformId][$tcaseId])
                                ? $latestExec[$platformId][$tcaseId] : null;
                            if (!is_null($lex) &&
                                (($lex['status'] == $notRunCode) ||
                                 (($lex['build_id'] == $bid) &&
                                  ($lex['id'] ==
                                      $rf[intval($bid)]['executions_id'])))) {
                                $lastExecCell = $cell;
                            }
                        }
                    }

                    $latestNotes = '';
                    $lex2 = isset($latestExec[$platformId][$tcaseId])
                        ? $latestExec[$platformId][$tcaseId] : null;
                    if (!is_null($lex2) &&
                        isset($lex2['execution_notes']) &&
                        !is_null($lex2['execution_notes'])) {
                        $latestNotes = $lex2['execution_notes'];
                    }

                    $row = [
                        'suite_name' => $topRow['suiteName'],
                        'tcase_id' => intval($tcaseId),
                        'external_id' => $fullPrefix . $topRow['external_id'],
                        'tcname' => $topRow['name'],
                        'cells' => $orderedCells,
                        'result_on_latest' => $resultOnLatest,
                        'note_on_latest' => $noteOnLatest,
                        'last_exec' => $lastExecCell,
                        'latest_notes' => $latestNotes,
                    ];
                    if ($showPlatforms) {
                        $row['platform_name'] =
                            isset($platformMap[$platformId])
                                ? $platformMap[$platformId] : '';
                    }
                    if ($payload['test_priority_enabled']) {
                        $row['priority_level'] =
                            isset($topRow['priority_level'])
                                ? intval($topRow['priority_level']) : 0;
                    }
                    $rowsOut[] = $row;
                }
            }
        }
    }

    $payload['columns'] = $columns;
    $payload['hasData'] = count($rowsOut) > 0;
    $payload['rows'] = $rowsOut;

    // BFF gateway for XLS download + email (results matrix)
    $exportUrl = '/api/reportsexport/index.php?action=results_matrix&tplan_id=' . $tplanId . '&tproject_id=' . $tprojectId;
    $mailUrl = '/api/reportsexport/index.php?action=results_matrix_mail&tplan_id=' . $tplanId . '&tproject_id=' . $tprojectId;
    if ($filterApplied) {
        $exportUrl .= '&buildListForExcel=' . implode(',', $idSet);
        $mailUrl .= '&buildListForExcel=' . implode(',', $idSet);
        foreach ($idSet as $bid) {
            $exportUrl .= '&build_set%5B%5D=' . intval($bid);
            $mailUrl .= '&build_set%5B%5D=' . intval($bid);
        }
    }
    $payload['export_xls_url'] = $exportUrl;
    $payload['send_mail_url'] = $mailUrl;

    out($payload);
}

// ---------------------------------------------------------------------------
// Assigned Test Case Overview report (Refs #684)
// Modernizes lib/testcases/tcAssignedToUser.php as reached from the Reports
// sub-menu entry link_assigned_tc_overview
// (?show_all_users=1&show_inactive_and_closed=1): every test case whose
// execution is assigned to a user, grouped per test plan, with last execution
// status on each row's build/platform and the legacy quick-execution icons.
// Right gate: 'testplan_metrics' - the same right the legacy screen is gated
// by through the reports navigator (resultsNavigator.php reports_list).
// ---------------------------------------------------------------------------
if ($action === 'assigned_tc_overview') {
    if ($tprojectId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test project id']);
    }
    $proj = $tprojectMgr->get_by_id($tprojectId);
    if (is_null($proj) || !isset($proj['name'])) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test project id']);
    }
    // Contextual re-check (per-project/per-plan roles), same policy as the
    // other metrics_* actions above.
    if (!$user->hasRight($db, 'testplan_metrics', $tprojectId,
            $tplanId > 0 ? $tplanId : null)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }

    $showAllUsers = intval(getParam('show_all_users', '1')) == 1;
    // The aside link pins this to 1 (legacy parity); still honored as a flag.
    $showInactiveClosed = intval(getParam('show_inactive_and_closed', '0')) != 0;
    $showClosedBuilds = intval(getParam('show_closed_builds',
        isset($_SESSION['ato_show_closed_builds'])
            ? intval($_SESSION['ato_show_closed_builds']) : 0)) ? 1 : 0;
    $_SESSION['ato_show_closed_builds'] = $showClosedBuilds;
    $filterUserId = intval(getParam('user_id', 0));
    $buildId = intval(getParam('build_id', 0));

    // Mirror init_args(): show_all_users switches to TL_USER_ANYBODY (0),
    // otherwise the requested user or the session user.
    $userIdFilter = $showAllUsers
        ? defined('TL_USER_ANYBODY') ? TL_USER_ANYBODY : 0
        : ($filterUserId > 0 ? $filterUserId : intval($userId));

    // Mirror initFilters().
    $filters = [
        'tplan_status' => 'active',
        'build_status' => $showClosedBuilds ? 'all' : 'open',
    ];
    if ($buildId > 0) {
        $filters['build_id'] = $buildId;
        $filters['build_status'] = 'all';
        $filters['tplan_status'] = 'all';
    }

    require_once(__DIR__ . '/../../lib/functions/testcase.class.php');
    $tcaseMgr = new testcase($db);
    $tplan_param = $tplanId > 0 ? [$tplanId] : testcase::ALL_TESTPLANS;
    $resultSet = $tcaseMgr->get_assigned_to_user(
        $userIdFilter, $tprojectId, $tplan_param,
        ['mode' => 'full_path'], $filters);

    $execCfg = config_get('exec_cfg');
    $testerModeRestrict =
        (isset($execCfg->exec_mode->tester)
            && $execCfg->exec_mode->tester == 'assigned_to_me');

    // Status code whitelist for rendering + quick-exec (standard exec status).
    $resultsCfg = config_get('results');
    $statusCodeMap = $resultsCfg['status_code'];   // passed|failed|blocked|not_run => p|f|b|n
    $codeStatusLabel = [];
    foreach ($statusCodeMap as $stName => $stCode) {
        if (isset($resultsCfg['status_label'][$stName])) {
            $codeStatusLabel[$stCode] = $stName; // 'p' => 'passed' (client maps to i18n)
        }
    }

    $payload = [
        'status' => 'ok',
        'hasData' => false,
        'tproject_id' => $tprojectId,
        'tproject_name' => $proj['name'],
        'tplan_id' => $tplanId,
        'glue_char' => config_get('testcase_cfg')->glue_character,
        'show_user_column' => $showAllUsers,
        'show_inactive_and_closed' => $showInactiveClosed,
        'show_closed_builds' => intval($showClosedBuilds),
        'tplans' => [],
    ];

    if (!is_null($resultSet)) {
        $tplanNames = [];
        $nhTable = tlObjectWithDB::getDBTables(['nodes_hierarchy']);
        $sql = 'SELECT name,id FROM ' . $nhTable['nodes_hierarchy'] .
               ' WHERE id IN (' . implode(',', array_map('intval', array_keys($resultSet))) . ')';
        $tplanNames = $db->fetchRowsIntoMap($sql, 'id');

        foreach ($resultSet as $tplan_id => $tcase_set) {
            $hasExecRight = ($user->hasRight(
                $db, 'testplan_execute', $tprojectId, $tplan_id, true) == 'yes');

            $platforms = $tplanMgr->getPlatforms($tplan_id, ['outputFormat' => 'map']);
            $showPlatforms = !is_null($platforms);

            $projOpts = $proj['opt'] ?? null;
            if (is_null($projOpts) && !empty($proj['options'])) {
                $projOpts = json_decode($proj['options']);
            }
            $priorityEnabled = is_object($projOpts)
                ? !empty($projOpts->testPriorityEnabled)
                : (is_array($projOpts) ? !empty($projOpts['testPriorityEnabled']) : false);

            $rowsOut = [];
            foreach ($tcase_set as $tcase_platform) {
                foreach ($tcase_platform as $tcase) {
                    $tcase_id = intval($tcase['testcase_id']);
                    $tcversion_id = intval($tcase['tcversion_id']);

                    $canExec = $hasExecRight;
                    if ($canExec && $testerModeRestrict) {
                        $canExec = (intval($tcase['user_id']) === intval($userId));
                    }

                    // Last execution on THIS build/platform (legacy parity).
                    $lexec = $tcaseMgr->get_last_execution(
                        $tcase_id, $tcversion_id, $tplan_id,
                        $tcase['build_id'], $tcase['platform_id'],
                        ['getSteps' => 0]);
                    $status = isset($lexec[$tcversion_id]['status'])
                        ? $lexec[$tcversion_id]['status'] : '';
                    if (!isset($codeStatusLabel[$status])) {
                        $status = $statusCodeMap['not_run'];
                    }

                    $creationTs = $tcase['creation_ts'];
                    $tsEpoch = is_string($creationTs) ? strtotime($creationTs) : $creationTs;

                    $row = [
                        'user_login' => '',
                        'user_id' => intval($tcase['user_id']),
                        'build_id' => intval($tcase['build_id']),
                        'build_name' => $tcase['build_name'],
                        'suite_path' => $tcase['tcase_full_path'],
                        'tc_id' => $tcase_id,
                        'tcversion_id' => $tcversion_id,
                        'prefix' => $tcase['prefix'],
                        'tc_external_id' => intval($tcase['tc_external_id']),
                        'name' => $tcase['name'],
                        'version' => intval($tcase['version']),
                        'tplan_id' => intval($tcase['testplan_id']),
                        'status' => $status,
                        'status_key' => isset($codeStatusLabel[$status])
                            ? $codeStatusLabel[$status] : 'not_run',
                        'creation_ts_epoch' => $tsEpoch ? intval($tsEpoch) : 0,
                        'age_days' => $tsEpoch
                            ? intval(floor((time() - $tsEpoch) / 86400)) : 0,
                        'can_exec' => $canExec,
                    ];
                    if ($showPlatforms) {
                        $row['platform_id'] = intval($tcase['platform_id']);
                        $row['platform_name'] = $tcase['platform_name'];
                    }
                    if ($priorityEnabled) {
                        $prio = intval($tcase['priority']);
                        // priority_to_level(): >=HIGH is high, >=MEDIUM medium else low
                        $level = ($prio >= HIGH) ? 'high'
                            : (($prio >= MEDIUM) ? 'medium' : 'low');
                        $row['priority'] = $prio;
                        $row['priority_level'] = $level;
                    }
                    if ($showAllUsers && isset($lexec[$tcversion_id]['tester_login'])) {
                        $row['tester_login'] = $lexec[$tcversion_id]['tester_login'];
                    }
                    $rowsOut[] = $row;
                }
            }

            $payload['tplans'][] = [
                'id' => intval($tplan_id),
                'name' => isset($tplanNames[$tplan_id]['name'])
                    ? $tplanNames[$tplan_id]['name'] : '',
                'show_platforms' => $showPlatforms,
                'priority_enabled' => $priorityEnabled,
                'has_exec_right' => $hasExecRight,
                'rows' => $rowsOut,
            ];
        }
        usort($payload['tplans'], function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });
        foreach ($payload['tplans'] as $tp) {
            if (count($tp['rows']) > 0) { $payload['hasData'] = true; break; }
        }
    }
    out($payload);
}

// ---------------------------------------------------------------------------
// Quick execution write-back for the Assigned Test Case Overview (Refs #684).
// Reimplements the legacy controller's inline INSERT INTO executions
// (result_<idx> hidden fields): one click writes a result row for the exact
// tcversion/build/platform triple. Guards: session user, testplan_execute on
// the (project, plan) context and the standard status whitelist. The tester
// recorded is ALWAYS the clicking user (the legacy copy wrote the page's
// filter user which collapses to ANYBODY=0 in the overview variant - writing
// tester_id=0 would corrupt the executions table).
// ---------------------------------------------------------------------------
if ($action === 'quick_exec') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        out(['status' => 'error', 'message' => 'POST required']);
    }
    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) {
        $in = $_POST;
    }
    $qeTplan = isset($in['tplan_id']) ? intval($in['tplan_id']) : 0;
    $qePlatform = isset($in['platform_id']) ? intval($in['platform_id']) : 0;
    $qeBuild = isset($in['build_id']) ? intval($in['build_id']) : 0;
    $qeTcversion = isset($in['tcversion_id']) ? intval($in['tcversion_id']) : 0;
    $qeResult = isset($in['result']) ? trim(strval($in['result'])) : '';

    if ($qeTplan <= 0 || $qeBuild <= 0 || $qeTcversion <= 0 || $qeResult === '') {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Missing parameters']);
    }
    // Resolve the owning project from the plan so hasRight() checks
    // project-level roles correctly (the JS POST has no tproject_id).
    $qePlanInfo = $tplanMgr->get_by_id($qeTplan);
    $qeProjId = intval($qePlanInfo['testproject_id'] ?? 0);
    if (!$user->hasRight($db, 'testplan_execute', $qeProjId, $qeTplan)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }
    $allowedStatus = ['p', 'f', 'b'];
    if (!in_array($qeResult, $allowedStatus, true)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid result status']);
    }

    // exec_mode->tester == assigned_to_me restricts to own assignments.
    $execCfgQe = config_get('exec_cfg');
    if (isset($execCfgQe->exec_mode->tester)
            && $execCfgQe->exec_mode->tester == 'assigned_to_me') {
        $tptcvTable = tlObjectWithDB::getDBTables(['testplan_tcversions']);
        $uaTable = tlObjectWithDB::getDBTables(['user_assignments']);
        $sql = "SELECT COUNT(*) AS cnt FROM " .
               $uaTable['user_assignments'] . " ua " .
               "JOIN " . $tptcvTable['testplan_tcversions'] . " tptcv " .
               "ON ua.feature_id = tptcv.id " .
               "WHERE ua.type = 1 AND ua.user_id = " . intval($userId) .
               " AND tptcv.testplan_id = " . intval($qeTplan) .
               " AND tptcv.tcversion_id = " . intval($qeTcversion) .
               " AND tptcv.platform_id = " . intval($qePlatform) .
               " AND ua.build_id = " . intval($qeBuild);
        $rs = $db->get_recordset($sql);
        if (empty($rs) || intval($rs[0]['cnt']) === 0) {
            http_response_code(403);
            out(['status' => 'error',
                 'message' => 'Execution restricted to assigned tester']);
        }
    }

    $tables = tlObjectWithDB::getDBTables(['nodes_hierarchy', 'executions', 'tcversions']);
    $xx = $db->get_recordset(
        ' SELECT TCV.version FROM ' . $tables['tcversions'] .
        ' TCV WHERE TCV.id = ' . intval($qeTcversion));
    if (empty($xx)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid tcversion id']);
    }
    $versionNumber = intval($xx[0]['version']);
    $sql = 'INSERT INTO ' . $tables['executions'] .
           ' (status,tester_id,execution_ts,tcversion_id,tcversion_number,' .
           '  testplan_id,platform_id,build_id)' .
           ' VALUES (' . "'" . $qeResult . "'" .
           ',' . intval($userId) . ',' . $db->db_now() .
           ',' . intval($qeTcversion) . ',' . $versionNumber .
           ',' . intval($qeTplan) . ',' . intval($qePlatform) .
           ',' . intval($qeBuild) . ')';
    $db->exec_query($sql);
    out([
        'status' => 'ok',
        'execution_id' => intval($db->insert_id($tables['executions'])),
        'result' => $qeResult,
    ]);
}

// ---------------------------------------------------------------------------
// Test Results Flat report (Refs #685)
// Modernizes lib/results/resultsTCFlat.php as reached from the Reports
// sub-menu entry link_report_test_flat: every execution row in the plan
// listed flat (one row per test case × build × platform) with status,
// tester, date, notes, duration and execution type. Right gate:
// 'testplan_metrics' - the same right the legacy screen enforces through
// checkRights() via resultsNavigator.php.
// ---------------------------------------------------------------------------
if ($action === 'results_flat') {
    if ($tplanId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Missing test plan id']);
    }

    $timerOn = microtime(true);

    $tplanInfo = $tplanMgr->get_by_id($tplanId);
    if (is_null($tplanInfo)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test plan id']);
    }
    $tprojectId = intval($tplanInfo['testproject_id']);
    $proj = $tprojectMgr->get_by_id($tprojectId);
    if (is_null($proj)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test project id']);
    }

    // Contextual re-check (per-project/per-plan roles).
    if (!$user->hasRight($db, 'testplan_metrics', $tprojectId, $tplanId)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }

    // Legacy initializeGui() - active builds only, ordered by config.
    $matrixCfg = config_get('resultMatrixReport');
    $buildInfoSet = $tplanMgr->get_builds($tplanId, testplan::ACTIVE_BUILDS,
        null, ['orderBy' => $matrixCfg->buildOrderByClause]);
    $activeBuildsQty = is_null($buildInfoSet) ? 0 : count($buildInfoSet);

    // Legacy guard: ACTIVE builds above the configured qty limit force the
    // launcher page unless the user explicitly submitted through it.
    $doApply = (getParam('do_action', '') === 'result');
    $buildSetParam = [];
    if (isset($_GET['build_set']) && is_array($_GET['build_set'])) {
        foreach ($_GET['build_set'] as $bid) {
            $bid = intval($bid);
            if ($bid > 0) {
                $buildSetParam[] = $bid;
            }
        }
    }
    $filterApplied = count($buildSetParam) > 0;

    // setUpBuilds() logic
    $idSet = $filterApplied
        ? array_keys(array_flip($buildSetParam))
        : (is_null($buildInfoSet) ? null : array_keys($buildInfoSet));

    $needSelection = false;
    if (($activeBuildsQty > $matrixCfg->buildQtyLimit) &&
        !($doApply && count($idSet) <= $matrixCfg->buildQtyLimit)) {
        $needSelection = true;
    }

    // Platforms
    $platformMap = $tplanMgr->getPlatforms($tplanId,
        ['outputFormat' => 'map']);
    $showPlatforms = !is_null($platformMap) && count($platformMap) > 0;

    // Priority enabled
    $projOpts = $proj['opt'] ?? null;
    $priorityEnabled = is_object($projOpts)
        ? !empty($projOpts->testPriorityEnabled)
        : (is_array($projOpts) ? !empty($projOpts['testPriorityEnabled']) : false);

    // TC prefix
    $tcCfg = config_get('testcase_cfg');
    $fullPrefix = $proj['prefix'] . $tcCfg->glue_character;

    $payload = [
        'status' => 'ok',
        'hasContext' => true,
        'tproject_id' => $tprojectId,
        'tplan_id' => $tplanId,
        'tproject_name' => $proj['name'],
        'tplan_name' => $tplanInfo['name'],
        'need_selection' => $needSelection,
        'build_qty_limit' => intval($matrixCfg->buildQtyLimit),
        'active_builds_qty' => $activeBuildsQty,
        'filter_applied' => $filterApplied,
        'show_platforms' => $showPlatforms,
        'priority_enabled' => $priorityEnabled,
        'builds' => [],
        'rows' => [],
    ];

    if ($needSelection) {
        // Launcher mode: return available builds for selection
        if (!is_null($buildInfoSet)) {
            foreach ($buildInfoSet as $bid => $binfo) {
                $payload['builds'][] = [
                    'id' => intval($bid),
                    'name' => $binfo['name'],
                ];
            }
        }
        $payload['legacy_url'] = '/lib/results/resultsTCFlat.php?' .
            'tplan_id=' . $tplanId . '&tproject_id=' . $tprojectId;
        $payload['elapsed_time'] =
            round(microtime(true) - $timerOn, 2);
        out($payload);
    }

    // Result mode: fetch flat execution matrix
    $metricsMgr = new tlTestPlanMetrics($db);
    $opt = [
        'getExecutionNotes' => true,
        'getTester' => true,
        'getUserAssignment' => true,
        'output' => 'cumulative',
        'getExecutionTimestamp' => true,
        'getExecutionDuration' => true,
    ];
    $buildSet = is_array($idSet) ? $idSet : null;
    $execStatus = $metricsMgr->getExecStatusMatrixFlat($tplanId,
        ['buildSet' => $buildSet], $opt);

    $metrics = $execStatus['metrics'];

    // Resolve user names for assigned_to and tested_by columns
    $userSet = getUsersForHtmlOptions($db, null, null, null, null,
        ['userDisplayFormat' => '%first% %last%']);

    // Status labels resolved server-side
    $cfgResults = config_get('results');
    $statusLabels = [];
    foreach ($cfgResults['status_code'] as $verbose => $scode) {
        $statusLabels[$scode] =
            lang_get($cfgResults['status_label'][$verbose]);
    }

    // Priority labels (from urgency config - matches legacy resultsTCFlat.php)
    $prioLabels = [];
    foreach (config_get('urgency')['code_label'] as $pcode => $plabel) {
        $prioLabels[intval($pcode)] = lang_get($plabel);
    }

    // Execution type labels
    $execTypeLabels = [
        TESTCASE_EXECUTION_TYPE_MANUAL =>
            lang_get('execution_type_manual'),
        TESTCASE_EXECUTION_TYPE_AUTO =>
            lang_get('execution_type_auto'),
    ];

    // Build name map
    $buildNameMap = [];
    if (!is_null($buildInfoSet)) {
        foreach ($buildInfoSet as $bid => $binfo) {
            $buildNameMap[intval($bid)] = $binfo['name'];
        }
    }

    $rowsOut = [];
    if (!is_null($metrics) && count($metrics) > 0) {
        foreach ($metrics as $mrow) {
            $suiteName = $mrow['suiteName'];
            $externalId = $fullPrefix . $mrow['external_id'];
            $tcName = $mrow['name'];
            $version = intval($mrow['version']);
            $buildId = intval($mrow['build_id']);
            $buildName = isset($buildNameMap[$buildId])
                ? $buildNameMap[$buildId] : ('#' . $buildId);
            $status = $mrow['status'];
            $statusLabel = isset($statusLabels[$status])
                ? $statusLabels[$status] : $status;

            // Priority level (pre-computed by getExecStatusMatrixFlat)
            $prioLevel = intval($mrow['priority_level'] ?? MEDIUM);
            $prioLabel = isset($prioLabels[intval($prioLevel)])
                ? $prioLabels[intval($prioLevel)] : '';

            // Assigned to
            $assignedTo = '';
            $userIdVal = intval($mrow['user_id'] ?? 0);
            if ($userIdVal > 0 && isset($userSet[$userIdVal])) {
                $assignedTo = $userSet[$userIdVal];
            }

            // Tester
            $tester = '';
            $testerId = intval($mrow['tester_id'] ?? 0);
            if ($testerId > 0 && isset($userSet[$testerId])) {
                $tester = $userSet[$testerId];
            }

            // Execution timestamp
            $execTs = $mrow['execution_ts'] ?? '';

            // Execution notes
            $execNotes = $mrow['execution_notes'] ?? '';

            // Execution duration
            $execDuration = $mrow['execution_duration'] ?? '';

            // Execution type
            $execType = isset($execTypeLabels[$mrow['exec_type'] ?? 0])
                ? $execTypeLabels[$mrow['exec_type'] ?? 0] : '';

            $row = [
                'suite_name' => $suiteName,
                'external_id' => $externalId,
                'tc_name' => $tcName,
                'version' => $version,
                'build_name' => $buildName,
                'assigned_to' => $assignedTo,
                'status_code' => $status,
                'status_label' => $statusLabel,
                'exec_ts' => $execTs,
                'tester' => $tester,
                'exec_notes' => $execNotes,
                'exec_duration' => $execDuration,
                'exec_type' => $execType,
                'priority_level' => intval($prioLevel),
                'priority_label' => $prioLabel,
            ];

            if ($showPlatforms) {
                $platId = intval($mrow['platform_id'] ?? 0);
                $row['platform_name'] = isset($platformMap[$platId])
                    ? $platformMap[$platId] : '';
            }

            $rowsOut[] = $row;
        }
    }

    $payload['hasData'] = count($rowsOut) > 0;
    $payload['rows'] = $rowsOut;

    // BFF gateway for XLS export
    $exportUrl = '/api/reportsexport/index.php?action=results_tc_flat&tplan_id=' . $tplanId . '&tproject_id=' . $tprojectId;
    if ($filterApplied) {
        $exportUrl .= '&buildListForExcel=' . implode(',', $idSet);
        foreach ($idSet as $bid) {
            $exportUrl .= '&build_set%5B%5D=' . intval($bid);
        }
    }
    $payload['export_xls_url'] = $exportUrl;

    $payload['elapsed_time'] =
        round(microtime(true) - $timerOn, 2);
    out($payload);
}

// ---------------------------------------------------------------------------
// Absolute Latest Execution Results on Test Plan (Refs #686)
// Modernizes lib/results/resultsTCAbsoluteLatest.php as reached from the
// Reports sub-menu entry link_report_test_absolute_latest_exec. Two-step
// flow: launcher (pick platform) → results (matrix of latest exec status per
// test case on the selected platform, ignoring builds). Right gate:
// 'testplan_metrics' — the same right the legacy screen enforces through
// checkRights() via resultsNavigator.php.
// ---------------------------------------------------------------------------
if ($action === 'absolute_latest_init') {
    // Launcher phase: return context + platforms for the dropdown
    if ($tplanId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Missing test plan id']);
    }

    $tplanInfo = $tplanMgr->get_by_id($tplanId);
    if (is_null($tplanInfo)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test plan id']);
    }
    $tprojectId = intval($tplanInfo['testproject_id']);
    $proj = $tprojectMgr->get_by_id($tprojectId);
    if (is_null($proj)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test project id']);
    }

    if (!$user->hasRight($db, 'testplan_metrics', $tprojectId, $tplanId)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }

    $platformSet = $tplanMgr->getPlatforms($tplanId, ['outputFormat' => 'map']);
    $platforms = [];
    if (!is_null($platformSet) && count($platformSet) > 0) {
        foreach ($platformSet as $pid => $pname) {
            $platforms[] = ['id' => intval($pid), 'name' => $pname];
        }
    }

    // Priority enabled flag
    $projOpts = $proj['opt'] ?? null;
    $priorityEnabled = is_object($projOpts)
        ? !empty($projOpts->testPriorityEnabled)
        : (is_array($projOpts) ? !empty($projOpts['testPriorityEnabled']) : false);

    // BFF gateway for XLS download + email
    $exportXlsUrl = '/api/reportsexport/index.php?action=absolute_latest&tplan_id=' . $tplanId . '&tproject_id=' . $tprojectId;
    $sendMailUrl = '/api/reportsexport/index.php?action=absolute_latest_mail&tplan_id=' . $tplanId . '&tproject_id=' . $tprojectId;

    out([
        'status' => 'ok',
        'hasContext' => true,
        'tproject_id' => $tprojectId,
        'tplan_id' => $tplanId,
        'tproject_name' => $proj['name'],
        'tplan_name' => $tplanInfo['name'],
        'platforms' => $platforms,
        'priority_enabled' => $priorityEnabled,
        'export_xls_url' => $exportXlsUrl,
        'send_mail_url' => $sendMailUrl,
    ]);
}

if ($action === 'absolute_latest_result') {
    // Results phase: return matrix data for the selected platform
    if ($tplanId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Missing test plan id']);
    }

    $platformId = intval(getParam('platform_id', 0));
    if ($platformId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Missing platform id']);
    }

    $timerOn = microtime(true);

    $tplanInfo = $tplanMgr->get_by_id($tplanId);
    if (is_null($tplanInfo)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test plan id']);
    }
    $tprojectId = intval($tplanInfo['testproject_id']);
    $proj = $tprojectMgr->get_by_id($tprojectId);
    if (is_null($proj)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test project id']);
    }

    if (!$user->hasRight($db, 'testplan_metrics', $tprojectId, $tplanId)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }

    // Priority enabled flag
    $projOpts = $proj['opt'] ?? null;
    $priorityEnabled = is_object($projOpts)
        ? !empty($projOpts->testPriorityEnabled)
        : (is_array($projOpts) ? !empty($projOpts['testPriorityEnabled']) : false);

    $tcCfg = config_get('testcase_cfg');
    $fullPrefix = $proj['prefix'] . $tcCfg->glue_character;

    $metricsMgr = new tlTestPlanMetrics($db);

    // Fetch data — same calls as legacy doProcess(), but guard against
    // PHP 8.x count(null) crash in getNeverRunOnSinglePlatform when there
    // are no active+open builds.
    $neverRun = [];
    $neverRunRaw = $metricsMgr->getNeverRunOnSinglePlatform($tplanId, $platformId);
    if (is_array($neverRunRaw)) {
        $neverRun = $neverRunRaw;
    }
    $execStatusRaw = $metricsMgr->getLatestExecOnSinglePlatformMatrix(
        $tplanId, $platformId, ['output' => 'array']);
    $execStatus = is_array($execStatusRaw) ? $execStatusRaw : [];

    // Merge never-run + executed into one dataset
    $allExec = array_merge($neverRun, $execStatus);

    // Status vocabulary resolved server-side
    $cfgResults = config_get('results');
    $statusLabels = [];
    foreach ($cfgResults['status_code'] as $verbose => $scode) {
        $statusLabels[$scode] = lang_get($cfgResults['status_label'][$verbose]);
    }

    // Priority threshold
    $priorityCfg = config_get('urgencyImportance');
    $prioLabels = [];
    foreach (config_get('priority')['code_label'] as $pcode => $plabel) {
        $prioLabels[intval($pcode)] = lang_get($plabel);
    }

    // Build row dataset — faithful port of legacy buildDataSet()
    $treeMgr = new tree($db);
    $tsuiteCache = [];
    $rowsOut = [];

    foreach ($allExec as $iidx => $tcases) {
        foreach ($tcases as $tcaseID => $platforms) {
            foreach ($platforms as $platID => $execData) {
                $rf = $execData[0];
                $tsuiteID = $rf['tsuite_id'];

                if (!isset($tsuiteCache[$tsuiteID])) {
                    $tsuiteCache[$tsuiteID] =
                        implode("/", $treeMgr->get_path($tsuiteID, null, 'name'));
                }

                $externalId = $fullPrefix . $rf['external_id'];
                $tcName = htmlspecialchars($externalId . ':' . $rf['name'], ENT_QUOTES);

                // Priority level
                $prioLevel = 0;
                $prioLabel = '';
                if ($priorityEnabled) {
                    $urgImp = $rf['urg_imp'] ?? 0;
                    if ($urgImp >= $priorityCfg->threshold['high']) {
                        $prioLevel = HIGH;
                    } elseif ($urgImp < $priorityCfg->threshold['low']) {
                        $prioLevel = LOW;
                    } else {
                        $prioLevel = MEDIUM;
                    }
                    $prioLabel = isset($prioLabels[intval($prioLevel)])
                        ? $prioLabels[intval($prioLevel)] : '';
                }

                // Status
                $statusCode = $rf['status'];
                $statusLabel = isset($statusLabels[$statusCode])
                    ? $statusLabels[$statusCode] : $statusCode;

                // Version tag
                $versionTag = isset($rf['version'])
                    ? ' v' . intval($rf['version']) : '';

                // Execution notes
                $execNotes = '';
                if (isset($rf['execution_notes']) && !is_null($rf['execution_notes'])) {
                    $execNotes = $rf['execution_notes'];
                }

                $rowsOut[] = [
                    'tsuite_name' => $tsuiteCache[$tsuiteID],
                    'tcase_id' => intval($tcaseID),
                    'external_id' => $externalId,
                    'tc_name' => $rf['name'],
                    'platform_name' => isset($rf['platform_name'])
                        ? $rf['platform_name'] : '',
                    'priority_level' => intval($prioLevel),
                    'priority_label' => $prioLabel,
                    'status_code' => $statusCode,
                    'status_label' => $statusLabel,
                    'version_tag' => $versionTag,
                    'exec_notes' => $execNotes,
                ];
            }
        }
    }

    // BFF gateway for XLS download + email (with platform)
    $exportXlsUrl = '/api/reportsexport/index.php?action=absolute_latest&tplan_id=' . $tplanId . '&tproject_id=' . $tprojectId . '&platform_id=' . $platformId;
    $sendMailUrl = '/api/reportsexport/index.php?action=absolute_latest_mail&tplan_id=' . $tplanId . '&tproject_id=' . $tprojectId . '&platform_id=' . $platformId;

    // Platform name for header
    $platformMap = $tplanMgr->getPlatforms($tplanId, ['outputFormat' => 'map']);
    $platformName = isset($platformMap[$platformId]) ? $platformMap[$platformId] : '';

    out([
        'status' => 'ok',
        'hasData' => count($rowsOut) > 0,
        'tproject_id' => $tprojectId,
        'tplan_id' => $tplanId,
        'tproject_name' => $proj['name'],
        'tplan_name' => $tplanInfo['name'],
        'platform_id' => $platformId,
        'platform_name' => $platformName,
        'priority_enabled' => $priorityEnabled,
        'rows' => $rowsOut,
        'export_xls_url' => $exportXlsUrl,
        'send_mail_url' => $sendMailUrl,
        'elapsed_time' => round(microtime(true) - $timerOn, 2),
    ]);
}

// ---------------------------------------------------------------------------
// Results by Status report — Failed / Blocked / Not Run
// Modernizes lib/results/resultsByStatus.php as reached from the Reports
// sub-menu entries list_tc_failed, list_tc_blocked, list_tc_not_run
// (?type=f|b|n). Every test case with a tester assigned is listed with its
// last execution on the relevant status. For Not Run: shows assigned users
// instead of tester/date/notes/bugs. Right gate: 'testplan_metrics' — the
// same right the legacy screen enforces through checkRights().
// ---------------------------------------------------------------------------
if ($action === 'by_status') {
    $statusType = getParam('status', 'failed');
    $validStatuses = ['failed', 'blocked', 'not_run'];
    if (!in_array($statusType, $validStatuses, true)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid status type']);
    }

    if ($tplanId <= 0 || $tprojectId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Missing project or plan id']);
    }

    $proj = $tprojectMgr->get_by_id($tprojectId);
    if (is_null($proj) || !isset($proj['name'])) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test project id']);
    }
    $tplanInfo = $tplanMgr->get_by_id($tplanId);
    if (is_null($tplanInfo)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test plan id']);
    }

    if (!$user->hasRight($db, 'testplan_metrics', $tprojectId, $tplanId)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }

    $timerOn = microtime(true);
    $resultsCfg = config_get('results');
    $statusCodeMap = $resultsCfg['status_code'];
    $statusCode = $statusCodeMap[$statusType]; // 'f','b','n'

    $metricsMgr = new tlTestPlanMetrics($db);
    $tcaseMgr = new testcase($db);

    $showPlatforms = false;
    $platformMap = $tplanMgr->getPlatforms($tplanId, ['outputFormat' => 'map']);
    if (!is_null($platformMap) && count($platformMap) > 0) {
        $showPlatforms = true;
        natsort($platformMap);
    }

    $buildMap = $tplanMgr->get_builds_for_html_options($tplanId);

    $userNames = getUsersForHtmlOptions($db);

    $isNotRun = ($statusType === 'not_run');
    if ($isNotRun) {
        $opt = ['output' => 'array'];
        $metrics = $metricsMgr->getNotRunWithTesterAssigned($tplanId, null, $opt);
    } else {
        $opt = ['output' => 'mapByExecID', 'getOnlyAssigned' => true];
        $metrics = $metricsMgr->getExecutionsByStatus($tplanId, $statusCode, null, $opt);
    }

    $warningMsg = '';
    $title = lang_get('list_of_' . $statusType);
    $reportContext = lang_get('info_only_with_tester_assignment');
    $infoMsg = '';
    if ($isNotRun) {
        $infoMsg = lang_get('info_notrun_tc_report');
    } else {
        $infoMsg = lang_get('info_' . $statusType . '_tc_report');
    }

    $rows = [];
    $withoutBugsCounter = 0;
    $bugInterfaceOn = false;
    $its = null;

    if (!$isNotRun) {
        $bugInterfaceOn = !empty($proj['issue_tracker_enabled']);
        if ($bugInterfaceOn) {
            $itMgr = new tlIssueTracker($db);
            $its = $itMgr->getInterfaceObject($tprojectId);
            unset($itMgr);
        }
    }

    $pathCache = [];
    $levelCache = [];
    $topCache = [];
    $nameCache = ['build' => []];
    if (!is_null($buildMap)) {
        foreach ($buildMap as $bid => $bname) {
            $nameCache['build'][$bid] = $bname;
        }
    }
    if ($showPlatforms) {
        $nameCache['platform'] = [];
        foreach ($platformMap as $pid => $pname) {
            $nameCache['platform'][$pid] = $pname;
        }
    }

    if (!is_null($metrics) && count($metrics) > 0) {
        // Custom fields on execution (failed/blocked only)
        $cfSet = null;
        $cfOnExec = null;
        if (!$isNotRun) {
            $cfSet = $tcaseMgr->cfield_mgr->get_linked_cfields_at_execution(
                $tprojectId, true, 'testcase');
            $execKeys = array_keys($metrics);
            if (count($cfSet) > 0 && count($execKeys) > 0) {
                $cfOnExec = $tcaseMgr->cfield_mgr->get_linked_cfields_at_execution(
                    $tprojectId, true, 'testcase', null, $execKeys);
            }
        }

        foreach ($metrics as $execID => $exec) {
            $tcId = $exec['tcase_id'];

            if (!isset($pathCache[$tcId])) {
                $dummy = $tcaseMgr->getPathLayered(array($tcId));
                $pathCache[$tcId] = $dummy[$exec['tsuite_id']]['value'];
                $levelCache[$tcId] = $dummy[$exec['tsuite_id']]['level'];
                $ky = current(array_keys($dummy));
                $topCache[$tcId] = $ky;
            }

            $row = [
                'suite_name' => $pathCache[$tcId],
                'test_title' => $exec['full_external_id'] . ':' . $exec['name'],
                'version' => $exec['tcversion_number'],
            ];

            if ($showPlatforms) {
                $row['platform'] = isset($nameCache['platform'][$exec['platform_id']])
                    ? $nameCache['platform'][$exec['platform_id']] : '';
            }

            $row['build'] = isset($nameCache['build'][$exec['build_id']])
                ? $nameCache['build'][$exec['build_id']] : '';

            if ($isNotRun) {
                $assignedUsers = isset($exec['user_id']) ? $exec['user_id'] : [];
                if (!is_array($assignedUsers)) {
                    $assignedUsers = [$assignedUsers];
                }
                natsort($assignedUsers);
                $testerParts = [];
                foreach ($assignedUsers as $uid) {
                    if (isset($userNames, $userNames[$uid])) {
                        $testerParts[] = htmlspecialchars($userNames[$uid]);
                    } else {
                        $testerParts[] = sprintf(lang_get('deleted_user'), $uid);
                    }
                }
                $row['tester'] = implode(', ', $testerParts);
                $row['notes'] = isset($exec['summary']) ? $exec['summary'] : '';
            } else {
                $testerId = $exec['tester_id'];
                if ($testerId == 0) {
                    $row['tester'] = lang_get('nobody');
                } elseif (isset($userNames[$testerId])) {
                    $row['tester'] = $userNames[$testerId];
                } else {
                    $row['tester'] = sprintf(lang_get('deleted_user'), $testerId);
                }
                $row['execution_ts'] = $exec['execution_ts'];
                $row['notes'] = strip_tags($exec['execution_notes']);

                // Custom fields
                if (!is_null($cfSet) && !is_null($cfOnExec)) {
                    foreach ($cfSet as $cfID => $cfDef) {
                        if (isset($cfOnExec[$execID][$cfID]) && !is_null($cfOnExec[$execID][$cfID])) {
                            $row['cf_' . $cfID] = $tcaseMgr->cfield_mgr->string_custom_field_value(
                                $cfOnExec[$execID][$cfID], null);
                        } else {
                            $row['cf_' . $cfID] = '';
                        }
                    }
                }

                // Bug tracking
                $row['bugs'] = [];
                if ($bugInterfaceOn && $its && $exec['status'] != $statusCodeMap['not_run']) {
                    $bugSet = get_bugs_for_exec($db, $its, $exec['executions_id'], ['id', 'summary']);
                    if (count($bugSet) == 0) {
                        $withoutBugsCounter++;
                    }
                    foreach ($bugSet as $bug) {
                        $row['bugs'][] = [
                            'id' => $bug['id'],
                            'link' => $bug['link_to_bts'] ?? ('#' . $bug['id']),
                        ];
                    }
                }
            }

            $rows[] = $row;
        }
    }

    $warningMsgKey = 'no_' . str_replace('_', '', $statusType) . '_with_tester';
    if (count($rows) === 0) {
        $warningMsg = lang_get($warningMsgKey);
    }

    // BFF gateway for XLS download + email
    $xlsUrl = '/api/reportsexport/index.php?action=results_by_status&tplan_id=' . $tplanId . '&tproject_id=' . $tprojectId;
    $mailUrl = '/api/reportsexport/index.php?action=results_by_status_mail&tplan_id=' . $tplanId . '&tproject_id=' . $tprojectId;

    $bugsMsg = '';
    if (!$isNotRun) {
        $bugsMsg = lang_get('th_bugs_not_linked');
        // Check report config for misc.bugs_not_linked
        require_once(__DIR__ . '/../../cfg/reports.cfg.php');
        $rptCfg = config_get('reports_list');
        $needle = 'list_tc_';
        foreach ($rptCfg as $key => $val) {
            if (strpos($key, $needle) !== false) {
                $verbose = substr($key, strlen($needle));
                if ($verbose === $statusType) {
                    if (isset($val['misc']['bugs_not_linked']) && !$val['misc']['bugs_not_linked']) {
                        $bugsMsg = '';
                    }
                    break;
                }
            }
        }
    }

    $cfColumns = [];
    if (!$isNotRun && !is_null($cfSet)) {
        foreach ($cfSet as $cfID => $cfDef) {
            $cfColumns[] = ['id' => $cfID, 'label' => $cfDef['label']];
        }
    }

    $infoXls = lang_get('info_xls_report_results_by_status');

    out([
        'status' => 'ok',
        'hasData' => count($rows) > 0,
        'tproject_id' => $tprojectId,
        'tplan_id' => $tplanId,
        'tproject_name' => $proj['name'],
        'tplan_name' => $tplanInfo['name'],
        'status_type' => $statusType,
        'title' => $title,
        'report_context' => $reportContext,
        'info_msg' => $infoMsg,
        'info_xls_report' => $infoXls,
        'warning_msg' => $warningMsg,
        'show_platforms' => $showPlatforms,
        'bug_interface_on' => $bugInterfaceOn,
        'without_bugs_counter' => $withoutBugsCounter,
        'bugs_msg' => $bugsMsg,
        'cf_columns' => $cfColumns,
        'rows' => $rows,
        'export_xls_url' => $xlsUrl,
        'send_mail_url' => $mailUrl,
        'elapsed_time' => round(microtime(true) - $timerOn, 2),
    ]);
}

// ──────────────────────────────────────────────────────────────
// action: tcases_with_cf
// Mirrors lib/results/testCasesWithCF.php — for a test plan, list test
// cases with execution custom field data. Right: testplan_metrics.
// ──────────────────────────────────────────────────────────────
if ($action === 'tcases_with_cf') {
    $timerOn = microtime(true);

    $tplanId = intval(getParam('tplan_id'));
    $tprojId = $tprojectId;

    if ($tplanId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'No test plan selected']);
        exit;
    }

    $tplanMgr = new testplan($db);
    $tplanInfo = $tplanMgr->get_by_id($tplanId);
    if (!$tplanInfo) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Test plan not found']);
        exit;
    }

    if (!$user->hasRight($db, 'testplan_metrics', $tprojId, $tplanId)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
        exit;
    }

    $proj = new testproject($db);
    $projInfo = $proj->get_by_id($tprojId);
    $tcasePrefix = $proj->getTestCasePrefix($tprojId);

    $showPlatforms = $tplanMgr->hasLinkedPlatforms($tplanId);
    $hasLinkedTCs = $tplanMgr->count_testcases($tplanId) > 0;

    if (!$hasLinkedTCs) {
        out([
            'status' => 'ok',
            'hasData' => false,
            'tproject_name' => $projInfo['name'],
            'tplan_name' => $tplanInfo['name'],
            'show_platforms' => false,
            'cf_columns' => [],
            'rows' => [],
            'warning_msg' => lang_get('no_linked_tc_cf'),
            'elapsed_time' => round(microtime(true) - $timerOn, 2),
        ]);
        exit;
    }

    $cfieldMgr = new cfield_mgr($db);

    // Get execution custom fields linked to test project
    $cfDef = $cfieldMgr->get_linked_cfields_at_execution(
        $tprojId, 1, 'testcase', null, null, null, 'name'
    );
    $cfDef = (array)$cfDef;

    $cfColumns = [];
    foreach ($cfDef as $cfKey => $cfInfo) {
        $cfColumns[] = ['id' => $cfKey, 'label' => $cfInfo['label'], 'name' => $cfInfo['name']];
    }

    // Get CF values per execution
    $cfMap = $cfieldMgr->get_linked_cfields_at_execution(
        $tprojId, 1, 'testcase', null, null, $tplanId, 'exec_id'
    );

    $rows = [];
    if (!is_null($cfMap)) {
        $cfPlaceHolder = [];
        foreach ($cfDef as $cfKey => $cfVal) {
            $cfPlaceHolder[$cfKey] = '';
        }

        $tcaseMgr = new testcase($db);
        $resultsCfg = config_get('results');
        $codeStatus = $resultsCfg['code_status'];
        $statusLabels = [];
        foreach ($codeStatus as $code => $verbose) {
            if (isset($resultsCfg['status_label'][$verbose])) {
                $statusLabels[$code] = lang_get($resultsCfg['status_label'][$verbose]);
            }
        }

        foreach ($cfMap as $execId => $execInfo) {
            $row = $execInfo[0];

            // Get test suite path
            $pathData = $tcaseMgr->getPathLayered([$row['tcase_id']]);
            $pathItem = is_array($pathData) && count($pathData) > 0 ? end($pathData) : null;
            $suitePath = is_array($pathItem) && isset($pathItem['value']) ? $pathItem['value'] : '';

            $externalId = buildExternalIdString($tcasePrefix, $row['tc_external_id']);

            // Collect custom field values
            $cfValues = $cfPlaceHolder;
            foreach ($execInfo as $cfRow) {
                $cfValues[$cfRow['name']] = $cfieldMgr->string_custom_field_value($cfRow, null);
            }

            // Filter: skip rows where both notes and all CF values are empty (legacy parity)
            $hasValue = !empty($row['exec_notes']);
            if (!$hasValue) {
                foreach ($cfValues as $cfVal) {
                    if (!empty($cfVal)) { $hasValue = true; break; }
                }
            }
            if (!$hasValue) {
                continue;
            }

            $rows[] = [
                'tcase_id' => intval($row['tcase_id']),
                'tcversion_id' => intval($row['tcversion_id']),
                'tc_external_id' => intval($row['tc_external_id']),
                'external_id' => $externalId,
                'tcase_name' => $row['tcase_name'],
                'suite_path' => $suitePath,
                'tcversion_number' => intval($row['tcversion_number']),
                'platform_name' => $row['platform_name'] ?? '',
                'platform_id' => intval($row['platform_id'] ?? 0),
                'build_name' => $row['build_name'] ?? '',
                'builds_id' => intval($row['builds_id'] ?? 0),
                'tester' => $row['tester'] ?? '',
                'execution_ts' => $row['execution_ts'] ?? '',
                'exec_status' => $row['exec_status'] ?? '',
                'exec_status_label' => $statusLabels[$row['exec_status']] ?? $row['exec_status'],
                'exec_notes' => strip_tags($row['exec_notes'] ?? ''),
                'cfields' => $cfValues,
            ];
        }
    }

    $warningMsg = '';
    if (count($rows) === 0) {
        $warningMsg = lang_get('no_linked_tc_cf');
    }

    out([
        'status' => 'ok',
        'hasData' => count($rows) > 0,
        'tproject_id' => $tprojId,
        'tplan_id' => $tplanId,
        'tproject_name' => $projInfo['name'],
        'tplan_name' => $tplanInfo['name'],
        'show_platforms' => $showPlatforms,
        'cf_columns' => $cfColumns,
        'rows' => $rows,
        'warning_msg' => $warningMsg,
        'elapsed_time' => round(microtime(true) - $timerOn, 2),
    ]);
    exit;
}

// ──────────────────────────────────────────────────────────────
// action: never_run_init
// Mirrors lib/results/neverRunByPP.php — init phase: returns test plan +
// project context, platform list and export URLs.
// Right: testplan_metrics.
// ──────────────────────────────────────────────────────────────
if ($action === 'never_run_init') {
    $timerOn = microtime(true);

    if ($tplanId <= 0 || $tprojectId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'No test plan selected']);
        exit;
    }

    $tplanInfo = $tplanMgr->get_by_id($tplanId);
    if (!$tplanInfo) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Test plan not found']);
        exit;
    }

    if (!$user->hasRight($db, 'testplan_metrics', $tprojectId, $tplanId)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
        exit;
    }

    $projInfo = $tprojectMgr->get_by_id($tprojectId);

    // Platform list (same as legacy initializeGui())
    $platOpts = $tplanMgr->getPlatforms($tplanId, ['outputFormat' => 'map', 'addIfNull' => true]);
    $platforms = [];
    $showPlatforms = true;
    if (is_array($platOpts) && (count($platOpts) === 0 || (count($platOpts) === 1 && isset($platOpts[0])))) {
        $showPlatforms = false;
    } else {
        foreach ($platOpts as $pid => $pname) {
            if (intval($pid) > 0) {
                $platforms[] = ['id' => intval($pid), 'name' => $pname];
            }
        }
    }

    $exportUrl = '/api/reportsexport/index.php?action=never_run&tplan_id=' . $tplanId . '&tproject_id=' . $tprojectId;

    out([
        'status' => 'ok',
        'hasContext' => true,
        'tproject_id' => $tprojectId,
        'tplan_id' => $tplanId,
        'tproject_name' => $projInfo['name'] ?? '',
        'tplan_name' => $tplanInfo['name'] ?? '',
        'show_platforms' => $showPlatforms,
        'platforms' => $platforms,
        'export_xls_url' => $exportUrl,
        'elapsed_time' => round(microtime(true) - $timerOn, 2),
    ]);
    exit;
}

// ──────────────────────────────────────────────────────────────
// action: never_run_result
// Mirrors lib/results/neverRunByPP.php — result phase: returns test cases
// that were never executed across ALL active builds by Test Plan + Platform.
// Right: testplan_metrics.
// ──────────────────────────────────────────────────────────────
if ($action === 'never_run_result') {
    $timerOn = microtime(true);

    if ($tplanId <= 0 || $tprojectId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'No test plan selected']);
        exit;
    }

    $tplanInfo = $tplanMgr->get_by_id($tplanId);
    if (!$tplanInfo) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Test plan not found']);
        exit;
    }

    if (!$user->hasRight($db, 'testplan_metrics', $tprojectId, $tplanId)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
        exit;
    }

    // Platform filter — same logic as legacy neverRunByPP.php
    $platSetRaw = $_GET['platSet'] ?? [];
    if (!is_array($platSetRaw)) {
        $platSetRaw = [$platSetRaw];
    }
    $platSet = [];
    foreach ($platSetRaw as $v) {
        $v = intval($v);
        if ($v > 0) {
            $platSet[] = $v;
        }
    }

    // Check if platforms are used
    $platOpts = $tplanMgr->getPlatforms($tplanId, ['outputFormat' => 'map', 'addIfNull' => true]);
    $showPlatforms = true;
    if (is_array($platOpts) && (count($platOpts) === 0 || (count($platOpts) === 1 && isset($platOpts[0])))) {
        $showPlatforms = false;
    }

    $metricsMgr = new tlTestPlanMetrics($db);
    $metrics = $metricsMgr->getNeverRunByPlatform($tplanId, count($platSet) > 0 ? $platSet : null);

    $hasData = !is_null($metrics) && count($metrics) > 0;
    $rows = [];

    if ($hasData) {
        $tcaseMgr = new testcase($db);
        $tcasePrefix = $tprojectMgr->getTestCasePrefix($tprojectId);

        // Build platform name cache (raw — client handles escaping for JSON)
        $platNameCache = [];
        if ($showPlatforms) {
            foreach ($platOpts as $pid => $pname) {
                $platNameCache[intval($pid)] = $pname;
            }
        }

        foreach ($metrics as $elem) {
            $externalId = $elem['full_external_id'] ?? '';
            $row = [
                'tcase_id' => intval($elem['tcase_id']),
                'test_title' => $externalId . ':' . $elem['name'],
                'test_title_plain' => $externalId . ':' . $elem['name'],
            ];
            if ($showPlatforms) {
                $row['platform_name'] = $platNameCache[intval($elem['platform_id'])] ?? '';
                $row['platform_id'] = intval($elem['platform_id']);
            }
            $rows[] = $row;
        }
    }

    $projInfo = $tprojectMgr->get_by_id($tprojectId);

    $exportUrl = '/api/reportsexport/index.php?action=never_run&tplan_id=' . $tplanId . '&tproject_id=' . $tprojectId;

    out([
        'status' => 'ok',
        'hasContext' => true,
        'hasData' => $hasData,
        'tproject_id' => $tprojectId,
        'tplan_id' => $tplanId,
        'tproject_name' => $projInfo['name'] ?? '',
        'tplan_name' => $tplanInfo['name'] ?? '',
        'show_platforms' => $showPlatforms,
        'rows' => $rows,
        'info_msg' => $hasData ? '' : lang_get('info_notrun_tc_report'),
        'export_xls_url' => $exportUrl,
        'elapsed_time' => round(microtime(true) - $timerOn, 2),
    ]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// cases_without_tester — Test Cases Without Tester Assigned
// Mirrors lib/results/testCasesWithoutTester.php
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'cases_without_tester') {
    $timerOn = microtime(true);

    if ($tplanId <= 0 || $tprojectId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'No test plan selected']);
        exit;
    }

    $tplanInfo = $tplanMgr->get_by_id($tplanId);
    if (!$tplanInfo) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Test plan not found']);
        exit;
    }

    if (!$user->hasRight($db, 'testplan_metrics', $tprojectId, $tplanId)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
        exit;
    }

    $projInfo = $tprojectMgr->get_by_id($tprojectId);
    $tplan_name = $tplanInfo['name'] ?? '';
    $tproject_name = $projInfo['name'] ?? '';

    $hasLinkedTCs = $tplanMgr->count_testcases($tplanId) > 0;

    if (!$hasLinkedTCs) {
        out([
            'status' => 'ok',
            'hasContext' => true,
            'tproject_id' => $tprojectId,
            'tplan_id' => $tplanId,
            'tproject_name' => $tproject_name,
            'tplan_name' => $tplan_name,
            'has_linked_tcs' => false,
            'hasData' => false,
            'show_platforms' => false,
            'priority_enabled' => false,
            'rows' => [],
            'elapsed_time' => round(microtime(true) - $timerOn, 2),
        ]);
        exit;
    }

    $show_platforms = $tplanMgr->hasLinkedPlatforms($tplanId);

    $priority_enabled = false;
    if (isset($projInfo['opt']) && is_object($projInfo['opt'])) {
        $priority_enabled = !empty($projInfo['opt']->testPriorityEnabled);
    }

    $metricsMgr = new tlTestPlanMetrics($db);
    $metrics = $metricsMgr->getNotRunWOTesterAssigned($tplanId, null, null,
        ['output' => 'array', 'ignoreBuild' => true]);

    if (is_null($metrics)) {
        $metrics = [];
    }

    $rows = [];
    if (count($metrics) > 0) {
        $targetSet = [];
        foreach ($metrics as &$item) {
            $targetSet[] = $item['tcase_id'];
        }

        $tree_mgr = new tree($db);
        $path_info = $tree_mgr->get_full_path_verbose($targetSet);
        unset($tree_mgr, $targetSet);

        $platformCache = [];
        if ($show_platforms) {
            $platformCache = $tplanMgr->getPlatforms($tplanId,
                ['outputFormat' => 'mapAccessByID']);
        }

        foreach ($metrics as &$item) {
            $suite_path = isset($path_info[$item['tcase_id']])
                ? implode(' / ', $path_info[$item['tcase_id']])
                : '';

            $row = [
                'suite_path' => $suite_path,
                'tcase_id' => intval($item['tcase_id']),
                'external_id' => intval($item['external_id']),
                'name' => $item['name'] ?? '',
                'summary' => strip_tags($item['summary'] ?? ''),
            ];

            if ($show_platforms) {
                $row['platform_name'] = $platformCache[$item['platform_id']]['name'] ?? '';
            }

            if ($priority_enabled) {
                $prioInt = intval($tplanMgr->urgencyImportanceToPriorityLevel(
                    $item['urg_imp']));
                $prioMap = [intval(HIGH) => 'high', intval(MEDIUM) => 'medium', intval(LOW) => 'low'];
                $row['priority_level'] = $prioMap[$prioInt] ?? 'low';
            }

            $rows[] = $row;
        }
    }

    out([
        'status' => 'ok',
        'hasContext' => true,
        'tproject_id' => $tprojectId,
        'tplan_id' => $tplanId,
        'tproject_name' => $tproject_name,
        'tplan_name' => $tplan_name,
        'has_linked_tcs' => true,
        'hasData' => count($rows) > 0,
        'show_platforms' => $show_platforms,
        'priority_enabled' => $priority_enabled,
        'rows' => $rows,
        'elapsed_time' => round(microtime(true) - $timerOn, 2),
    ]);
    exit;
}

// ---------------------------------------------------------------------------
// Graphical Charts report (Refs #690)
// Modernizes lib/results/charts.php (which delegates to overallPieChart.php,
// platformPieChart.php, keywordBarChart.php, topLevelSuitesBarChart.php).
// Returns JSON for all 4 chart types so the client renders them with Chart.js
// instead of the legacy server-side pChart+GD PNG generation. Right gate:
// 'testplan_metrics' — same right the legacy screen enforces via checkRights().
// ---------------------------------------------------------------------------
if ($action === 'charts_data') {
    if ($tplanId <= 0 || $tprojectId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Missing project or plan id']);
    }

    $timerOn = microtime(true);

    $tplanInfo = $tplanMgr->get_by_id($tplanId);
    if (is_null($tplanInfo)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test plan id']);
    }
    $projInfo = $tprojectMgr->get_by_id($tprojectId);
    if (is_null($projInfo) || !isset($projInfo['name'])) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test project id']);
    }

    // Contextual re-check — same pattern as other metrics actions.
    if (!$user->hasRight($db, 'testplan_metrics', $tprojectId, $tplanId)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }

    $resultsCfg = config_get('results');
    $statusColour = $resultsCfg['charts']['status_colour'];
    $statusLabel  = $resultsCfg['status_label'];
    $metricsMgr = new tlTestPlanMetrics($db);

    // ── 1. Overall metrics pie ──
    $overall = null;
    $totals = $metricsMgr->getExecCountersByExecStatus($tplanId);
    if (!is_null($totals) && isset($totals['total'])) {
        unset($totals['total']);
        $oLabels  = [];
        $oValues  = [];
        $oColors  = [];
        foreach ($totals as $status => $qty) {
            $oLabels[] = lang_get($statusLabel[$status]) . ' (' . $qty . ')';
            $oValues[] = intval($qty);
            $oColors[] = '#' . ($statusColour[$status] ?? '888888');
        }
        if (count($oValues) > 0) {
            $overall = ['labels' => $oLabels, 'values' => $oValues, 'colors' => $oColors];
        }
    }

    // ── 2. Per-platform pies ──
    $platforms = [];
    $platSet = $tplanMgr->getPlatforms($tplanId, ['outputFormat' => 'map']);
    if (!is_null($platSet) && count($platSet) > 0) {
        $platMetrics = $metricsMgr->getStatusTotalsByPlatformForRender($tplanId);
        if (!is_null($platMetrics) && isset($platMetrics->info)) {
            foreach ($platSet as $platId => $platName) {
                $pData = ['name' => $platName, 'labels' => [], 'values' => [], 'colors' => []];
                if (isset($platMetrics->info[$platId]['details'])) {
                    foreach ($platMetrics->info[$platId]['details'] as $status => $info) {
                        $qty = intval($info['qty']);
                        $pData['labels'][] = lang_get($statusLabel[$status]) . ' (' . $qty . ')';
                        $pData['values'][] = $qty;
                        $pData['colors'][] = '#' . ($statusColour[$status] ?? '888888');
                    }
                }
                if (count($pData['values']) > 0) {
                    $platforms[] = $pData;
                }
            }
        }
    }

    // ── 3. Results by keyword (stacked bar) ──
    $byKeyword = null;
    $kwData = $metricsMgr->getStatusTotalsByKeywordForRender($tplanId);
    if (!is_null($kwData) && isset($kwData->info) && count($kwData->info) > 0) {
        // Sort alphabetically (same as legacy keywordBarChart.php)
        $sorted = [];
        foreach ($kwData->info as $kwId => $kwElem) {
            $sorted[$kwElem['name']] = $kwId;
        }
        ksort($sorted);

        $kwLabels = array_keys($sorted);
        $datasets = []; // [ { label, data } ]
        $statusOrder = [];
        foreach ($sorted as $kwName => $kwId) {
            foreach ($kwData->info[$kwId]['details'] as $status => $info) {
                if (!isset($statusOrder[$status])) {
                    $statusOrder[$status] = [];
                }
                $statusOrder[$status][] = intval($info['qty']);
            }
        }
        foreach ($statusOrder as $status => $values) {
            $datasets[] = [
                'label' => lang_get($statusLabel[$status]),
                'data'  => $values,
                'backgroundColor' => '#' . ($statusColour[$status] ?? '888888'),
            ];
        }
        if (count($kwLabels) > 0) {
            $byKeyword = ['labels' => $kwLabels, 'datasets' => $datasets];
        }
    }

    // ── 4. Results by top-level suite (stacked bar) ──
    // getRootTestSuites can produce invalid SQL (WHERE id IN ()) when no
    // root suites exist — guard with try/catch to avoid a fatal error.
    $bySuite = null;
    $suiteNames = [];
    try {
        $suiteNames = $metricsMgr->getRootTestSuites($tplanId, $tprojectId);
    } catch (\Throwable $e) {
        $suiteNames = [];
    }
    $suiteData = null;
    try {
        $suiteData = $metricsMgr->getStatusTotalsByTopLevelTestSuiteForRender($tplanId);
    } catch (\Throwable $e) {
        $suiteData = null;
    }
    if (!is_null($suiteData) && isset($suiteData->info) && !is_null($suiteData->info)) {
        // Sort alphabetically (same as legacy topLevelSuitesBarChart.php)
        $nameMap = is_array($suiteNames) ? $suiteNames : [];
        $sortedSuites = array_flip($nameMap);
        natcasesort($sortedSuites);

        $sLabels = [];
        $sStatusData = [];
        foreach ($sortedSuites as $name => $tsId) {
            if (isset($suiteData->info[$tsId])) {
                $sLabels[] = $name;
                foreach ($suiteData->info[$tsId]['details'] as $status => $info) {
                    if (!isset($sStatusData[$status])) {
                        $sStatusData[$status] = [];
                    }
                    $sStatusData[$status][] = intval($info['qty']);
                }
            }
        }
        $sDatasets = [];
        foreach ($sStatusData as $status => $values) {
            $sDatasets[] = [
                'label' => lang_get($statusLabel[$status]),
                'data'  => $values,
                'backgroundColor' => '#' . ($statusColour[$status] ?? '888888'),
            ];
        }
        if (count($sLabels) > 0) {
            $bySuite = ['labels' => $sLabels, 'datasets' => $sDatasets];
        }
    }

    $charts = [];
    if (!is_null($overall))   { $charts['overall']   = $overall; }
    if (count($platforms) > 0) { $charts['platforms'] = $platforms; }
    if (!is_null($byKeyword)) { $charts['by_keyword'] = $byKeyword; }
    if (!is_null($bySuite))   { $charts['by_suite']   = $bySuite; }

    out([
        'status'       => 'ok',
        'tproject_id'  => $tprojectId,
        'tplan_id'     => $tplanId,
        'tproject_name'=> $projInfo['name'],
        'tplan_name'   => $tplanInfo['name'],
        'charts'       => $charts,
        'elapsed_time' => round(microtime(true) - $timerOn, 2),
    ]);
}

// ──────────────────────────────────────────────────────────────
// action: tplan_with_cf
// Mirrors lib/results/testPlanWithCF.php — for a test plan, list test
// cases with Test Plan Design custom field values. Right: testplan_metrics.
// ──────────────────────────────────────────────────────────────
if ($action === 'tplan_with_cf') {
    $timerOn = microtime(true);

    if ($tplanId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Missing test plan id']);
    }

    $tplanInfo = $tplanMgr->get_by_id($tplanId);
    if (is_null($tplanInfo)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test plan id']);
    }
    $tprojectId = intval($tplanInfo['testproject_id']);
    $proj = $tprojectMgr->get_by_id($tprojectId);
    if (is_null($proj) || !isset($proj['name'])) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test project id']);
    }

    if (!$user->hasRight($db, 'testplan_metrics', $tprojectId, $tplanId)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }

    $hasLinkedTCs = $tplanMgr->count_testcases($tplanId) > 0;

    if (!$hasLinkedTCs) {
        out([
            'status' => 'ok',
            'hasData' => false,
            'tproject_id' => $tprojectId,
            'tplan_id' => $tplanId,
            'tproject_name' => $proj['name'],
            'tplan_name' => $tplanInfo['name'],
            'cf_columns' => [],
            'rows' => [],
            'warning_msg' => lang_get('no_linked_tplan_cf'),
            'elapsed_time' => round(microtime(true) - $timerOn, 2),
        ]);
        exit;
    }

    require_once(__DIR__ . '/../../lib/functions/cfield_mgr.class.php');
    $cfieldMgr = new cfield_mgr($db);
    $tcaseMgr = new testcase($db);
    $tcCfg = config_get('testcase_cfg');
    $titleSep = config_get('gui_title_separator_1');

    // CF definitions linked at testplan design scope (column headers)
    $cfDef = $cfieldMgr->get_linked_cfields_at_testplan_design(
        $tprojectId, 1, 'testcase', null, null, null, 'name');
    $cfDef = (array)$cfDef;

    $cfColumns = [];
    foreach ($cfDef as $cfKey => $cfInfo) {
        $cfColumns[] = ['id' => $cfKey, 'label' => $cfInfo['label'], 'name' => $cfInfo['name']];
    }

    // CF values per test plan
    $cfMap = $cfieldMgr->get_linked_cfields_at_testplan_design(
        $tprojectId, 1, 'testcase', null, null, $tplanId);

    $rows = [];
    $hasData = false;
    if (!is_null($cfMap)) {
        $cfPlaceHolder = [];
        foreach ($cfDef as $cfKey => $cfVal) {
            $cfPlaceHolder[$cfKey] = '';
        }

        foreach ($cfMap as $execId => $execInfo) {
            $row0 = $execInfo[0];

            // Test suite path
            $pathData = $tcaseMgr->getPathLayered([$row0['tcase_id']]);
            $pathItem = is_array($pathData) && count($pathData) > 0
                ? end($pathData) : null;
            $suitePath = is_array($pathItem) && isset($pathItem['value'])
                ? $pathItem['value'] : '';

            $externalId = buildExternalIdString(
                $proj['prefix'] . $tcCfg->glue_character,
                $row0['tc_external_id']);

            // Collect custom field values
            $cfValues = $cfPlaceHolder;
            foreach ($execInfo as $cfRow) {
                $cfValues[$cfRow['name']] = $cfieldMgr->string_custom_field_value($cfRow, null);
            }

            // Filter: skip rows where all CF values are empty (legacy parity)
            $hasValue = false;
            foreach ($cfValues as $cfVal) {
                if (!empty($cfVal)) { $hasValue = true; break; }
            }
            if (!$hasValue) {
                continue;
            }

            $rows[] = [
                'tcase_id' => intval($row0['tcase_id']),
                'tc_external_id' => intval($row0['tc_external_id']),
                'external_id' => $externalId,
                'tcase_name' => $row0['tcase_name'],
                'suite_path' => $suitePath,
                'cfields' => $cfValues,
            ];
            $hasData = true;
        }
    }

    $warningMsg = '';
    if (!$hasData) {
        $warningMsg = lang_get('no_linked_tplan_cf');
    }

    out([
        'status' => 'ok',
        'hasData' => $hasData,
        'tproject_id' => $tprojectId,
        'tplan_id' => $tplanId,
        'tproject_name' => $proj['name'],
        'tplan_name' => $tplanInfo['name'],
        'cf_columns' => $cfColumns,
        'rows' => $rows,
        'warning_msg' => $warningMsg,
        'elapsed_time' => round(microtime(true) - $timerOn, 2),
    ]);
    exit;
}

// ──────────────────────────────────────────────────────────────
// action: free_testcases
// Mirrors lib/results/freeTestCases.php — for a test project, list test
// cases that are NOT linked to any test plan.  Right: testplan_metrics.
// ──────────────────────────────────────────────────────────────
if ($action === 'free_testcases') {
    $timerOn = microtime(true);

    if ($tprojectId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Missing test project id']);
    }

    $projInfo = $tprojectMgr->get_by_id($tprojectId);
    if (is_null($projInfo) || !isset($projInfo['name'])) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test project id']);
    }

    if (!$user->hasRight($db, 'testplan_metrics', $tprojectId)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }

    $tprojOpt = $tprojectMgr->getOptions($tprojectId);
    $priorityEnabled = isset($tprojOpt->testPriorityEnabled)
        ? $tprojOpt->testPriorityEnabled : false;

    $freeData = $tprojectMgr->getFreeTestCases($tprojectId);
    if (is_null($freeData)) {
        $freeData = ['items' => null, 'allfree' => false];
    }

    $rows = [];
    $allFree = !empty($freeData['allfree'] ?? false);

    if (!is_null($freeData['items']) && count($freeData['items']) > 0) {
        $tcCfg = config_get('testcase_cfg');
        $prefix = $tprojectMgr->getTestCasePrefix($tprojectId)
            . $tcCfg->glue_character;

        $tcIds = array_keys($freeData['items']);
        $treeMgr = new tree($db);
        $pathInfo = $treeMgr->get_full_path_verbose($tcIds,
            ['output_format' => 'path_as_string']);
        unset($treeMgr);

        foreach ($freeData['items'] as $tcId => $tcInfo) {
            $suitePath = isset($pathInfo[$tcId]) ? $pathInfo[$tcId] : '';

            $row = [
                'tcase_id'       => intval($tcId),
                'tc_external_id' => intval($tcInfo['tc_external_id']),
                'external_id'    => $prefix . $tcInfo['tc_external_id'],
                'name'           => strip_tags($tcInfo['name']),
                'suite_path'     => $suitePath,
            ];

            if ($priorityEnabled) {
                $imp = intval($tcInfo['importance']);
                $prioMap = [
                    intval(HIGH)   => 'high',
                    intval(MEDIUM) => 'medium',
                    intval(LOW)    => 'low',
                ];
                $row['importance'] = $prioMap[$imp] ?? 'low';
            }

            $rows[] = $row;
        }
    }

    $warningMsg = '';
    if (count($rows) === 0 && $allFree) {
        $warningMsg = lang_get('all_testcases_are_free');
    } elseif (count($rows) === 0) {
        $warningMsg = lang_get('all_testcases_has_testplan');
    }

    out([
        'status'            => 'ok',
        'tproject_id'       => $tprojectId,
        'tproject_name'     => $projInfo['name'],
        'priority_enabled'  => $priorityEnabled,
        'all_free'          => $allFree,
        'has_data'          => count($rows) > 0,
        'rows'              => $rows,
        'warning_msg'       => $warningMsg,
        'elapsed_time'      => round(microtime(true) - $timerOn, 2),
    ]);
    exit;
}

// ── metrics_results_reqs ──────────────────────────────────────────────
// Requirements Coverage report — mirrors lib/results/resultsReqs.php
// (Refs #691). JSON payload consumed by
// gui/templates/results/resultsRequirements.html.
// Right gate: testplan_metrics (same as legacy checkRights).
if ($action === 'metrics_results_reqs') {
    if ($tplanId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Missing test plan id']);
    }

    $timerOn = microtime(true);

    $tplanInfo = $tplanMgr->get_by_id($tplanId);
    if (is_null($tplanInfo)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test plan id']);
    }
    $tprojectId = intval($tplanInfo['testproject_id']);
    $proj = $tprojectMgr->get_by_id($tprojectId);
    if (is_null($proj)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test project id']);
    }

    if (!$user->hasRight($db, 'testplan_metrics', $tprojectId, $tplanId)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }

    // Check requirements are enabled for this project
    require_once('requirements.inc.php');
    $reqCfg = config_get('req_cfg');
    $projOpts = $tprojectMgr->getOptions($tprojectId);
    $reqEnabled = !empty($projOpts) && !empty($projOpts->requirementsEnabled);
    if (!$reqEnabled) {
        out([
            'status' => 'ok',
            'tplan_name' => $tplanInfo['name'],
            'tproject_name' => $proj['name'],
            'platform_set' => [],
            'build_set' => [],
            'summary_counts' => [],
            'total_reqs' => 0,
            'rows' => [],
            'expected_coverage_enabled' => false,
            'elapsed_time' => round(microtime(true) - $timerOn, 2),
        ]);
        exit;
    }

    // Platform filter
    $platformFilter = intval(getParam('platform', 0));
    $buildFilter = intval(getParam('build', 0));

    // Platform set for dropdown
    $platformSet = $tplanMgr->platform_mgr->getLinkedToTestplanAsMap($tplanId);
    if (is_null($platformSet)) {
        $platformSet = [];
    }

    // Build set for dropdown (active builds only)
    $buildSet = $tplanMgr->get_builds_for_html_options($tplanId, 1);
    if (is_null($buildSet)) {
        $buildSet = [];
    }

    // Status code maps — exact copy of legacy setUpReqStatusCfg()
    $resultsCfg = config_get('results');
    $statusCodeMap = [];
    foreach ($resultsCfg['status_label_for_exec_ui'] as $status => $label) {
        $statusCodeMap[$status] = $resultsCfg['status_code'][$status];
    }

    $codeStatusMap = array_flip($statusCodeMap);
    foreach ($codeStatusMap as $code => $status) {
        $codeStatusMap[$code] = [
            'label' => $resultsCfg['status_label'][$status],
            'status' => $status,
            'css_class' => $status . '_text',
        ];
    }

    // Eval status map — mirrors legacy setUpReqStatusCfg() labels via lang_get()
    $evalStatusMap = $codeStatusMap;
    $evalLabels = init_labels([
        'partially_passed' => null, 'partially_passed_reqs' => null,
        'uncovered' => null, 'uncovered_reqs' => null,
        'passed_nfc' => null, 'passed_nfc_reqs' => null,
        'failed_nfc' => null, 'failed_nfc_reqs' => null,
        'blocked_nfc' => null, 'blocked_nfc_reqs' => null,
        'not_run_nfc' => null, 'not_run_nfc_reqs' => null,
        'passed' => null,
        'partially_passed_nfc' => null, 'partially_passed_nfc_reqs' => null,
    ]);
    // Short eval codes returned by evaluate_req_bff() map to these labels/css
    // CSS class names must match resultsRequirements.html eval-label classes
    $evalEntries = [
        'partially_passed'     => ['label' => $evalLabels['partially_passed'],     'css' => 'partially_passed'],
        'uncovered'            => ['label' => $evalLabels['uncovered'],            'css' => 'uncovered'],
        'partially_passed_nfc' => ['label' => $evalLabels['partially_passed_nfc'], 'css' => 'partially_passed_nfc'],
    ];
    foreach ($evalEntries as $ek => $info) {
        $evalStatusMap[$ek] = [
            'label' => $info['label'] ?? $ek,
            'long_label' => $info['label'] ?? $ek,
            'css_class' => $info['css'],
            'count' => 0,
        ];
    }
    // Map short status codes + *_nfc suffixes to translated labels
    // CSS classes use the eval key directly (e.g. 'passed', 'passed_nfc', 'failed', etc.)
    foreach ($statusCodeMap as $sName => $sCode) {
        $evalStatusMap[$sCode] = [
            'label' => lang_get($resultsCfg['status_label'][$sName]),
            'long_label' => lang_get('req_title_' . $sName),
            'css_class' => $sName,
            'count' => 0,
            'status' => $sName,
        ];
        $nfcKey = $sCode . '_nfc';
        $nfcLabelKey = $sName === 'not_run' ? 'not_run_nfc' : $sName . '_nfc';
        $evalStatusMap[$nfcKey] = [
            'label' => $evalLabels[$nfcLabelKey] ?? ($evalLabels[$sName . '_nfc'] ?? $nfcKey),
            'long_label' => $evalLabels[$nfcLabelKey . '_reqs'] ?? $nfcKey,
            'css_class' => $sName . '_nfc',
            'count' => 0,
        ];
    }
    // Init count on all codes
    foreach ($evalStatusMap as $ek => &$ev) {
        if (!isset($ev['count'])) {
            $ev['count'] = 0;
        }
    }
    unset($ev);

    // Get requirements by context
    $reqMgr = new requirement_mgr($db);
    $reqSpecMgr = new requirement_spec_mgr($db);
    $reqContext = [
        'tproject_id' => $tprojectId,
        'tplan_id' => $tplanId,
        'platform_id' => $platformFilter,
    ];

    // Req type & status label maps — mirrors legacy setUpLabels()
    $reqTypeLabels = init_labels($reqCfg->type_labels);
    $reqStatusLabels = init_labels($reqCfg->status_labels);

    $reqSetX = (array) $reqMgr->getAllByContext($reqContext);
    $reqIds = array_keys($reqSetX);

    // Build req spec map — mirrors legacy buildReqSpecMap()
    $rspecSet = [];
    $tcIds = [];
    $totalReqs = 0;

    // Items linked to test plan
    $itemsInTestPlan = $tplanMgr->getLinkedItems($tplanId);

    foreach ($reqIds as $id) {
        $req = $reqMgr->get_by_id($id, requirement_mgr::LATEST_VERSION);
        if (!is_array($req) || count($req) == 0) {
            continue;
        }
        $req = $req[0];

        if (!isset($rspecSet[$req['srs_id']])) {
            $rspecSet[$req['srs_id']] = $reqSpecMgr->get_by_id($req['srs_id']);
            $rspecSet[$req['srs_id']]['requirements'] = [];
        }

        $req['linked_testcases'] = (array) $reqMgr->getActiveForReqVersion($req['version_id']);

        // Exclude obsolete TCs or TCs not linked to test plan
        foreach ($req['linked_testcases'] as $itemID => $dummy) {
            if ($dummy['is_obsolete'] == "1" || !isset($itemsInTestPlan[$dummy['id']])) {
                unset($req['linked_testcases'][$itemID]);
            }
        }

        if (count($req['linked_testcases']) > 0) {
            $totalReqs++;
            $rspecSet[$req['srs_id']]['requirements'][$id] = $req;
            foreach ($req['linked_testcases'] as $tc) {
                $tcIds[] = $tc['id'];
            }
        }
    }

    // Get test case execution data
    $tcaseSet = [];
    if (count($tcIds)) {
        $filters = ['tcase_id' => $tcIds];
        if ($platformFilter > 0) {
            $filters['platform_id'] = $platformFilter;
        }
        if ($buildFilter > 0) {
            $filters['build_id'] = $buildFilter;
        }

        $filterOnlyPlatform = isset($filters['platform_id']) && !isset($filters['build_id']);
        $filterOnlyBuild = !isset($filters['platform_id']) && isset($filters['build_id']);
        $noFilter = !isset($filters['platform_id']) && !isset($filters['build_id']);
        $allFilters = isset($filters['platform_id']) && isset($filters['build_id']);

        $options = [
            'addExecInfo' => true,
            'accessKeyType' => 'tcase+platform',
            'build_is_active' => true,
        ];

        if ($noFilter || $filterOnlyPlatform) {
            $tcaseSet = $tplanMgr->getLTCVOnTestPlanPlatform($tplanId, $filters, $options);
        } else if ($allFilters || $filterOnlyBuild) {
            $tcaseSet = $tplanMgr->getLTCVNewGeneration($tplanId, $filters, $options);
        }
    }

    // Coverage algorithm config
    $reqCoverageCfg = config_get('req_cfg');
    $algorithmCfg = $reqCoverageCfg->coverageStatusAlgorithm;

    // Process each req spec and requirement
    $rows = [];
    foreach ($rspecSet as $rspecId => $rspecInfo) {
        $path = $reqMgr->tree_mgr->get_path($rspecInfo['id']);
        $pathNames = [];
        foreach ($path as $pval) {
            $pathNames[] = $pval['name'];
        }
        $reqSpecPath = implode('/', $pathNames);

        foreach ($rspecInfo['requirements'] as $reqId => $reqInfo) {
            $tcCounters = ['total' => 0, 'totalTPTCV' => 0, 'expected_coverage' => $reqInfo['expected_coverage'] ?? 0];

            foreach ($reqInfo['linked_testcases'] as $tcInfo) {
                $tcId = $tcInfo['id'];
                if (!isset($tcaseSet[$tcId])) {
                    continue;
                }
                $plat2loop = array_keys($tcaseSet[$tcId]);
                $tcCounters['total']++;

                foreach ($plat2loop as $platId) {
                    if (!isset($tcaseSet[$tcId][$platId])) {
                        continue;
                    }
                    $tcCounters['totalTPTCV']++;
                    if (isset($tcaseSet[$tcId][$platId]['exec_status'])) {
                        $status = $tcaseSet[$tcId][$platId]['exec_status'];
                        if (!isset($tcCounters[$status])) {
                            $tcCounters[$status] = 0;
                        }
                        $tcCounters[$status]++;
                    }
                }
            }

            // Evaluate requirement — mirrors legacy evaluate_req()
            $eval = evaluate_req_bff($statusCodeMap, $algorithmCfg, $tcCounters);
            if (!isset($evalStatusMap[$eval])) {
                $evalStatusMap[$eval] = [
                    'label' => $eval,
                    'long_label' => $eval,
                    'css_class' => 'not_run_text',
                    'count' => 0,
                ];
            }
            $evalStatusMap[$eval]['count']++;

            // Progress calculation
            $expectedCov = intval($reqInfo['expected_coverage'] ?? 0);
            $totalCount = ($reqCoverageCfg->expected_coverage_management && $expectedCov > 0)
                ? $expectedCov : $tcCounters['total'];

            $progressPct = 0;
            $statusCounts = [];
            foreach ($statusCodeMap as $sName => $sCode) {
                $cnt = isset($tcCounters[$sCode]) ? $tcCounters[$sCode] : 0;
                $pctOfTotal = ($totalCount > 0) ? round((100 / $totalCount) * $cnt, 2) : 0;
                $statusCounts[$sCode] = [
                    'count' => $cnt,
                    'percentage' => $pctOfTotal,
                    'total' => $totalCount,
                ];
                if ($sCode != $statusCodeMap['not_run']) {
                    $progressPct += $pctOfTotal;
                }
            }
            $progress = $totalCount > 0 ? round($progressPct, 2) : 0;

            // Linked TCs detail
            $linkedTCs = [];
            foreach ($reqInfo['linked_testcases'] as $ltc) {
                $tcId = $ltc['id'];
                if (!isset($tcaseSet[$tcId])) {
                    continue;
                }
                foreach ($tcaseSet[$tcId] as $pelem) {
                    $execStatus = $statusCodeMap['not_run'];
                    $execStatusLabel = 'not_run';
                    $platformName = '';
                    if (isset($pelem['platform_id']) && $pelem['platform_id'] > 0) {
                        $platformName = $pelem['platform_name'] ?? '';
                    }
                    if (isset($pelem['exec_status'])) {
                        $execStatus = $pelem['exec_status'];
                        $execStatusLabel = isset($evalStatusMap[$execStatus]) ? $evalStatusMap[$execStatus]['label'] : $execStatus;
                    }
                    $linkedTCs[] = [
                        'tc_external_id' => intval($ltc['tc_external_id']),
                        'name' => strip_tags($ltc['name']),
                        'version' => intval($ltc['version']),
                        'platform' => $platformName,
                        'exec_status' => $execStatus,
                        'exec_status_label' => $execStatusLabel,
                    ];
                }
            }

            // Expected coverage
            $currentCoverage = count($reqInfo['linked_testcases']);
            $rows[] = [
                'req_spec_path' => $reqSpecPath,
                'req_doc_id' => $reqInfo['req_doc_id'] ?? '',
                'title' => strip_tags($reqInfo['title'] ?? ''),
                'version' => intval($reqInfo['version'] ?? 0),
                'type' => isset($reqTypeLabels[$reqInfo['type']]) ? $reqTypeLabels[$reqInfo['type']] : ($reqInfo['type'] ?? ''),
                'status' => isset($reqStatusLabels[$reqInfo['status']]) ? $reqStatusLabels[$reqInfo['status']] : ($reqInfo['status'] ?? ''),
                'eval' => $eval,
                'eval_label' => isset($evalStatusMap[$eval]) ? $evalStatusMap[$eval]['label'] : $eval,
                'eval_css' => isset($evalStatusMap[$eval]) ? $evalStatusMap[$eval]['css_class'] : '',
                'expected_coverage' => $expectedCov,
                'current_coverage' => $currentCoverage,
                'status_passed' => $statusCounts[$statusCodeMap['passed']]['count'] ?? 0,
                'status_failed' => $statusCounts[$statusCodeMap['failed']]['count'] ?? 0,
                'status_blocked' => $statusCounts[$statusCodeMap['blocked']]['count'] ?? 0,
                'status_not_run' => $statusCounts[$statusCodeMap['not_run']]['count'] ?? 0,
                'progress' => $progress,
                'linked_tcs' => $linkedTCs,
            ];
        }
    }

    // Build summary counts from evalStatusMap
    $summaryCounts = [];
    foreach ($evalStatusMap as $ek => $ev) {
        if ($ev['count'] > 0) {
            $summaryCounts[] = [
                'eval' => $ek,
                'label' => $ev['label'],
                'count' => $ev['count'],
            ];
        }
    }

    out([
        'status' => 'ok',
        'tplan_name' => $tplanInfo['name'],
        'tproject_name' => $proj['name'],
        'platform_set' => $platformSet,
        'build_set' => $buildSet,
        'summary_counts' => $summaryCounts,
        'total_reqs' => $totalReqs,
        'rows' => $rows,
        'expected_coverage_enabled' => !empty($reqCoverageCfg->expected_coverage_management),
        'elapsed_time' => round(microtime(true) - $timerOn, 2),
    ]);
    exit;
}

/**
 * Evaluate requirement coverage — mirrors legacy evaluate_req() from
 * lib/results/resultsReqs.php exactly.
 */
function evaluate_req_bff(&$statusCode, &$algorithmCfg, &$counters) {
    $evaluation = null;
    $expectedCov = isset($counters['expected_coverage']) ? intval($counters['expected_coverage']) : 0;
    $isFullyCovered = ($counters['total'] >= $expectedCov && $expectedCov > 0) || ($expectedCov == 0);

    if (!isset($counters[$statusCode['not_run']])) {
        $counters[$statusCode['not_run']] = 0;
    }

    $doIt = true;
    if ($counters['total'] == 0) {
        $evaluation = 'uncovered';
        $doIt = false;
    }

    // Count how many status types are set
    $hmc = 0;
    foreach ($statusCode as $verbose => $code) {
        if (isset($counters[$code])) {
            $hmc++;
        }
    }

    if ($counters['total'] > 0) {
        list($evaluation, $doIt) = doNotRunAnalysisBff($hmc, $counters, $statusCode['not_run']);
        if (!$doIt) {
            $evaluation .= ($isFullyCovered ? '' : '_nfc');
        }
    }

    if ($doIt) {
        $evaluation = null;
        $analysisDone = false;
        foreach ($algorithmCfg['checkOrder'] as $checkKey) {
            $analysisDone = true;
            $doOuterBreak = false;
            foreach ($algorithmCfg['checkType'][$checkKey] as $status2check) {
                $code = $statusCode[$status2check];
                $count = isset($counters[$code]) ? $counters[$code] : 0;
                if ($checkKey == 'atLeastOne' && $count) {
                    $evaluation = $isFullyCovered ? $code : $code . "_nfc";
                    $doOuterBreak = true;
                    break;
                }
                if ($checkKey == 'all' && ($count == $counters['totalTPTCV'])) {
                    $evaluation = $isFullyCovered ? $code : $code . "_nfc";
                    $doOuterBreak = true;
                    break;
                }
            }
            if ($doOuterBreak) {
                break;
            }
        }

        if ($analysisDone && is_null($evaluation)) {
            $evaluation = 'partially_passed';
            if ($counters[$statusCode['not_run']] == 0) {
                $evaluation = $statusCode['passed'];
            }
            $evaluation .= ($isFullyCovered ? '' : '_nfc');
        }
    }
    return $evaluation;
}

/**
 * Not-run analysis — mirrors legacy doNotRunAnalysis().
 */
function doNotRunAnalysisBff($tcaseQty, $execStatusCounter, $notRunCode) {
    $evaluation = null;
    $doIt = true;
    if ($tcaseQty == 1) {
        if ($execStatusCounter[$notRunCode] != 0) {
            $evaluation = $notRunCode;
            $doIt = false;
        }
    } else {
        if ($execStatusCounter['totalTPTCV'] == $execStatusCounter[$notRunCode]) {
            $evaluation = $notRunCode;
            $doIt = false;
        }
    }
    return [$evaluation, $doIt];
}

// ---------------------------------------------------------------------------
// Execution Timeline Statistics (Refs #762)
// Modernizes lib/results/execTimelineStats.php — execution count and tester
// workforce grouped by month / day / day+hour. Right gate: testplan_metrics.
// ---------------------------------------------------------------------------
if ($action === 'exec_timeline') {
    if ($tplanId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Missing test plan id']);
    }

    $timerOn = microtime(true);

    $tplanInfo = $tplanMgr->get_by_id($tplanId);
    if (is_null($tplanInfo)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test plan id']);
    }
    $tprojectId = intval($tplanInfo['testproject_id']);
    $proj = $tprojectMgr->get_by_id($tprojectId);
    if (is_null($proj)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test project id']);
    }

    if (!$user->hasRight($db, 'testplan_metrics', $tprojectId, $tplanId)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }

    // Group parameter: day (default), month, day_hour — same as legacy.
    $group = getParam('group', 'day');
    $validGroups = ['month', 'day', 'day_hour'];
    if (!in_array($group, $validGroups, true)) {
        $group = 'day';
    }

    // Workforce (tester count) always enabled in legacy — same as
    // statsBy[$group]['workforce'] = true in execTimelineStats.php.
    $statsBy = [
        'month'    => ['timeline' => 'month',    'workforce' => true],
        'day'      => ['timeline' => 'day',      'workforce' => true],
        'day_hour' => ['timeline' => 'day_hour', 'workforce' => true],
    ];

    $metricsMgr = new tlTestPlanMetrics($db);
    $stats = $metricsMgr->getExecTimelineStats(
        $tplanId, null, $statsBy[$group]);

    // $stats is array($rs, $rswf) — $rs keyed by timeline field.
    $rs = $stats[0];
    $hasData = !is_null($rs) && count($rs) > 0;

    // Column definitions: mirrors the legacy initializeGui/switch logic.
    $columns = [];
    switch ($group) {
        case 'month':
            $columns[] = ['key' => 'yyyy_mm', 'label' => lang_get('yyyy_mm')];
            break;
        case 'day_hour':
            $columns[] = ['key' => 'yyyy_mm_dd', 'label' => lang_get('yyyy_mm_dd')];
            $columns[] = ['key' => 'hh', 'label' => lang_get('hh')];
            break;
        case 'day':
        default:
            $columns[] = ['key' => 'yyyy_mm_dd', 'label' => lang_get('yyyy_mm_dd')];
            break;
    }
    $columns[] = ['key' => 'qty', 'label' => lang_get('qty')];
    // Workforce column: always present when workforce=true.
    $columns[] = ['key' => 'testers', 'label' => lang_get('testers_qty')];

    // Rows: ordered list preserving the DB sort order.
    // For day_hour, getExecTimelineStats() returns a nested map:
    //   $rs[date][hour] = ['qty'=>X, 'yyyy_mm_dd'=>date, 'hh'=>hour, ...]
    // For day/month it returns a flat map keyed by the time field.
    // NOTE: the legacy merge of workforce data into $rs for day_hour
    // accidentally adds a spurious 'testers' key at the date level;
    // we filter that out (only numeric keys are real hours).
    $rows = [];
    if ($hasData) {
        if ($group === 'day_hour') {
            foreach ($rs as $dateKey => $hours) {
                if (!is_array($hours)) {
                    continue;
                }
                foreach ($hours as $hourKey => $elem) {
                    // Skip the spurious 'testers' key added by legacy merge
                    if (!is_numeric($hourKey)) {
                        continue;
                    }
                    if (!is_array($elem) || !isset($elem['qty'])) {
                        continue;
                    }
                    $rows[] = [
                        'yyyy_mm_dd' => $elem['yyyy_mm_dd'] ?? $dateKey,
                        'hh' => $elem['hh'] ?? $hourKey,
                        'qty' => intval($elem['qty'] ?? 0),
                        'testers' => intval($elem['testers'] ?? 0),
                    ];
                }
            }
        } else {
            foreach ($rs as $timeKey => $elem) {
                $row = [];
                if ($group === 'month') {
                    $row['yyyy_mm'] = $elem['yyyy_mm'] ?? $timeKey;
                } else {
                    $row['yyyy_mm_dd'] = $elem['yyyy_mm_dd'] ?? $timeKey;
                }
                $row['qty'] = intval($elem['qty'] ?? 0);
                $row['testers'] = intval($elem['testers'] ?? 0);
                $rows[] = $row;
            }
        }
    }

    // Platform awareness — same logic as legacy initializeGui().
    $platformSet = $tplanMgr->getPlatforms(
        $tplanId, ['outputFormat' => 'map']);
    $showPlatforms = !is_null($platformSet) && count($platformSet) > 0;
    if ($showPlatforms) {
        natsort($platformSet);
    }

    // BFF gateway for XLS download + email
    $sendMailUrl = '/api/reportsexport/index.php?action=exec_timeline_stats_mail&tplan_id=' . $tplanId . '&tproject_id=' . $tprojectId;
    $exportXlsUrl = '/api/reportsexport/index.php?action=exec_timeline_stats&tplan_id=' . $tplanId . '&tproject_id=' . $tprojectId;

    $payload = [
        'status' => 'ok',
        'hasContext' => true,
        'hasData' => $hasData,
        'tproject_id' => $tprojectId,
        'tplan_id' => $tplanId,
        'tproject_name' => $proj['name'],
        'tplan_name' => $tplanInfo['name'],
        'group' => $group,
        'columns' => $columns,
        'rows' => $rows,
        'show_platforms' => $showPlatforms,
        'send_mail_url' => $sendMailUrl,
        'export_xls_url' => $exportXlsUrl,
        'elapsed_time' => round(microtime(true) - $timerOn, 2),
    ];
    out($payload);
}

// ---------------------------------------------------------------------------
// action=results_bugs — Results by Issues / Bugs per Test Case
// Refs #763
// Two report types served by the same action, switched by the 'type' param:
//   type=0 (latest) — latest generation executions only (default)
//   type=1 (all)    — all executions with bugs
// Mirrors lib/results/resultsBugs.php logic exactly.
// Right enforced: testplan_metrics (same as legacy checkRights()).
// ---------------------------------------------------------------------------
if ($action === 'results_bugs') {
    if ($tprojectId <= 0 || $tplanId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Missing context']);
    }

    $proj = $tprojectMgr->get_by_id($tprojectId);
    if (is_null($proj) || !isset($proj['name'])) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Project not found']);
    }

    $planInfo = $tplanMgr->get_by_id($tplanId);
    if (is_null($planInfo) || !isset($planInfo['name'])) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Test plan not found']);
    }

    // Derive project from plan — same as every other action in this file.
    $tprojectId = intval($planInfo['testproject_id']);

    // Contextual re-check: per-project/per-plan roles.
    if (!$user->hasRight($db, 'testplan_metrics', $tprojectId, $tplanId)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }

    // Re-fetch project with derived ID in case it differs from GET param.
    $proj = $tprojectMgr->get_by_id($tprojectId);

    // Issue tracker setup (mirrors legacy lines 26-33 of resultsBugs.php)
    $bugInterfaceOn = $proj['issue_tracker_enabled'];
    $its = null;
    if ($bugInterfaceOn) {
        $itMgr = new tlIssueTracker($db);
        $its = $itMgr->getInterfaceObject($tprojectId);
    }

    // Type parameter (0=latest, 1=all) — mirrors legacy init_args()
    $typeParam = intval(getParam('type', '0'));
    $verboseType = ($typeParam == 1) ? 'all' : 'latest';
    $titleKey = ($typeParam == 1)
        ? 'link_report_total_bugs_all_exec'
        : 'link_report_total_bugs';

    $timerOn = microtime(true);
    $metricsMgr = new tlTestPlanMetrics($db);

    // Fetch execution set — mirrors legacy switch($args->verboseType)
    if ($verboseType === 'all') {
        $execSet = (array)$tplanMgr->getAllExecutionsWithBugs($tplanId);
    } else {
        $execSet = (array)$metricsMgr->getLTCVNewGeneration(
            $tplanId, null,
            [
                'addExecInfo' => true,
                'accessKeyType' => 'index',
                'specViewFields' => true,
                'testSuiteInfo' => true,
                'includeNotRun' => false,
            ]
        );
    }

    // Collect bugs per test case — mirrors legacy loop
    $openBugs = [];
    $resolvedBugs = [];
    $testcaseBugs = [];

    require_once(__DIR__ . '/../../lib/functions/exec.inc.php');

    foreach ($execSet as $execution) {
        $tcId = $execution['tc_id'];

        $bugUrls = [];
        if ($its) {
            $bugData = get_bugs_for_exec($db, $its, $execution['exec_id']);
            if ($bugData) {
                foreach ($bugData as $bugId => $bugInfo) {
                    if ($bugInfo['isResolved']) {
                        if (!in_array($bugId, $resolvedBugs)) {
                            $resolvedBugs[] = $bugId;
                        }
                    } else {
                        if (!in_array($bugId, $openBugs)) {
                            $openBugs[] = $bugId;
                        }
                    }
                    $bugUrls[] = [
                        'bug_id' => $bugId,
                        'link' => $bugInfo['link_to_bts'],
                        'is_resolved' => (bool)$bugInfo['isResolved'],
                        'build_name' => $bugInfo['build_name'] ?? '',
                    ];
                }
            }
        }

        if (!empty($bugUrls)) {
            if (!isset($testcaseBugs[$tcId])) {
                $testcaseBugs[$tcId] = [
                    'tc_id' => $tcId,
                    'tc_name' => $execution['name'],
                    'full_external_id' => $execution['full_external_id'] ?? '',
                    'tsuite_name' => $execution['tsuite_name'] ?? '',
                    'external_id' => $execution['external_id'] ?? 0,
                    'bugs' => [],
                ];
            }
            foreach ($bugUrls as $bug) {
                $existingLinks = array_column($testcaseBugs[$tcId]['bugs'], 'bug_id');
                if (!in_array($bug['bug_id'], $existingLinks)) {
                    $testcaseBugs[$tcId]['bugs'][] = $bug;
                }
            }
        }
    }

    $rows = array_values($testcaseBugs);

    $totalOpen = count($openBugs);
    $totalResolved = count($resolvedBugs);
    $totalBugs = $totalOpen + $totalResolved;
    $totalCasesWithBugs = count($rows);

    $payload = [
        'status' => 'ok',
        'tproject_id' => $tprojectId,
        'tplan_id' => $tplanId,
        'tproject_name' => $proj['name'],
        'tplan_name' => $planInfo['name'],
        'type' => $typeParam,
        'verbose_type' => $verboseType,
        'title_key' => $titleKey,
        'bug_interface_on' => $bugInterfaceOn,
        'total_open_bugs' => $totalOpen,
        'total_resolved_bugs' => $totalResolved,
        'total_bugs' => $totalBugs,
        'total_cases_with_bugs' => $totalCasesWithBugs,
        'rows' => $rows,
        'has_data' => count($rows) > 0,
        'elapsed_time' => round(microtime(true) - $timerOn, 2),
    ];
    out($payload);
}

// =============================================================================
// action=more_builds_init / action=more_builds — Results by Multiple Builds
// (lib/results/resultsMoreBuildsGUI.php + resultsMoreBuilds.php, Refs #838)
// Legacy checkRights() requires 'testplan_metrics' — enforced above + context.
//
// The legacy resultsMoreBuilds.php report-query is dead code (the newResults
// block is commented out), so this BFF rebuilds the intended feature: a
// parameter form (builds, platforms, keyword, top-level suites, last-status
// filter, owner/executor, search-in-notes, display options, date range) that
// drives a per-test-case x per-build last-execution-status matrix with
// per-build totals.
// =============================================================================
if ($action === 'more_builds_init') {
    if ($tplanId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Missing test plan id']);
    }
    $tplanInfo = $tplanMgr->get_by_id($tplanId);
    if (is_null($tplanInfo)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test plan id']);
    }
    $tprojectId = intval($tplanInfo['testproject_id']);
    $proj = $tprojectMgr->get_by_id($tprojectId);
    if (is_null($proj)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test project id']);
    }
    if (!$user->hasRight($db, 'testplan_metrics', $tprojectId, $tplanId)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }

    $resultsCfg = config_get('results');
    $reportsCfg = config_get('reportsCfg');

    // Builds (active by default, same as legacy get_builds(ACTIVE_BUILDS)).
    $buildItems = [];
    $buildInfoSet = $tplanMgr->get_builds($tplanId, testplan::ACTIVE_BUILDS);
    if (!is_null($buildInfoSet)) {
        foreach ($buildInfoSet as $bid => $binfo) {
            $buildItems[] = [
                'id' => intval($bid),
                'name' => $binfo['name'],
                'is_open' => (intval($binfo['is_open']) === 1),
            ];
        }
    }
    // Also expose closed builds so the user can include them if desired.
    $allBuildInfo = $tplanMgr->get_builds($tplanId);
    $closedBuildItems = [];
    if (!is_null($allBuildInfo)) {
        foreach ($allBuildInfo as $bid => $binfo) {
            if (intval($binfo['is_open']) !== 1) {
                $closedBuildItems[] = [
                    'id' => intval($bid),
                    'name' => $binfo['name'],
                    'is_open' => false,
                ];
            }
        }
    }

    // Top-level test suites (legacy getRootTestSuites).
    $suiteItems = [];
    $suites = $tplanMgr->getRootTestSuites($tplanId, $tprojectId,
        ['output' => 'plain']);
    if (!is_null($suites)) {
        foreach ($suites as $sid => $sname) {
            $suiteItems[] = ['id' => intval($sid), 'name' => $sname['name']];
        }
    }

    // Platforms (legacy getPlatforms).
    $platformItems = [];
    $platformMap = $tplanMgr->getPlatforms($tplanId,
        ['outputFormat' => 'map']);
    if (!is_null($platformMap)) {
        foreach ($platformMap as $pid => $pname) {
            $platformItems[] = ['id' => intval($pid), 'name' => $pname];
        }
    }

    // Keywords (legacy get_keywords_map; index 0 = ANY).
    $keywordItems = [['id' => 0, 'name' => lang_get('any')]];
    $kwMap = $tplanMgr->get_keywords_map($tplanId);
    if (!is_null($kwMap)) {
        foreach ($kwMap as $kid => $kname) {
            $keywordItems[] = ['id' => intval($kid), 'name' => $kname];
        }
    }

    // Users for owner/executor.
    $userItems = [];
    $users = getUsersForHtmlOptions($db, ALL_USERS_FILTER,
        ['0' => lang_get('any')]);
    if (is_array($users)) {
        foreach ($users as $uid => $uname) {
            $userItems[] = ['id' => intval($uid), 'name' => $uname];
        }
    }

    // Status options (legacy get_status_for_reports_html_options), exec
    // statuses in config order, values = status codes ('p','f','b','n','x').
    $statusOptions = [];
    foreach ($reportsCfg->exec_status as $verbose => $label) {
        $code = $resultsCfg['status_code'][$verbose];
        $statusOptions[] = [
            'code' => $code,
            'label' => lang_get($label),
        ];
    }

    // Server-side display-status labels (code -> label) for the matrix.
    $statusLabels = [];
    foreach ($resultsCfg['status_label'] as $verbose => $label) {
        $statusLabels[$resultsCfg['status_code'][$verbose]] =
            lang_get($label);
    }

    // Default date range (legacy: end = now, start = now - start_date_offset).
    $ldf = config_get('locales_date_format');
    $locale = $_SESSION['locale'] ?? 'en_GB';
    $dateFormat = isset($ldf[$locale]) ? $ldf[$locale] : 'Y-m-d';
    $startOffset = $reportsCfg->start_date_offset;
    $selectedStart = tlStrftime($dateFormat,
        time() - $startOffset);
    $selectedEnd = tlStrftime($dateFormat, time());
    $startTime = $reportsCfg->start_time;

    out([
        'status' => 'ok',
        'hasContext' => true,
        'tproject_id' => $tprojectId,
        'tplan_id' => $tplanId,
        'tproject_name' => $proj['name'],
        'tplan_name' => $tplanInfo['name'],
        'builds' => $buildItems,
        'closed_builds' => $closedBuildItems,
        'testsuites' => $suiteItems,
        'platforms' => $platformItems,
        'keywords' => $keywordItems,
        'users' => $userItems,
        'status_options' => $statusOptions,
        'status_labels' => $statusLabels,
        'selected_start_date' => $selectedStart,
        'selected_end_date' => $selectedEnd,
        'selected_start_time' => $startTime,
    ]);
}

if ($action === 'more_builds') {
    if ($tplanId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Missing test plan id']);
    }
    $tplanInfo = $tplanMgr->get_by_id($tplanId);
    if (is_null($tplanInfo)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test plan id']);
    }
    $tprojectId = intval($tplanInfo['testproject_id']);
    $proj = $tprojectMgr->get_by_id($tprojectId);
    if (is_null($proj)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test project id']);
    }
    if (!$user->hasRight($db, 'testplan_metrics', $tprojectId, $tplanId)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }

    $timerOn = microtime(true);
    $resultsCfg = config_get('results');
    $reportsCfg = config_get('reportsCfg');
    $metricsMgr = new tlTestPlanMetrics($db);

    // ---- Read filter parameters ----
    $buildSel = isset($_GET['build']) && is_array($_GET['build'])
        ? array_values(array_filter(array_map('intval', $_GET['build']),
            function ($b) { return $b > 0; })) : [];
    $suiteSel = isset($_GET['testsuite']) && is_array($_GET['testsuite'])
        ? array_values(array_filter(array_map('intval', $_GET['testsuite']),
            function ($s) { return $s > 0; })) : [];
    $platformSel = isset($_GET['platform']) && is_array($_GET['platform'])
        ? array_values(array_filter(array_map('intval', $_GET['platform']),
            function ($p) { return $p > 0; })) : [];
    $lastStatusSel = isset($_GET['lastStatus']) && is_array($_GET['lastStatus'])
        ? array_values(array_filter($_GET['lastStatus'],
            function ($s) { return is_string($s) && $s !== ''; })) : ['p', 'f', 'b', 'n', 'x'];
    $keywordId = intval(getParam('keyword', 0));
    $ownerId = intval(getParam('owner', 0));
    $executorId = intval(getParam('executor', 0));
    $searchNotes = getParam('search_notes_string');

    $displayLatest = (getParam('display_latest_results', '') !== '0');
    $displayTotals = (getParam('display_totals', '1') === '1');
    $displaySuiteSummaries = (getParam('display_suite_summaries', '0') === '1');
    $displayTestCases = (getParam('display_test_cases', '1') !== '0');
    $displayQueryParams = (getParam('display_query_params', '1') === '1');

    if (count($buildSel) === 0) {
        http_response_code(400);
        out(['status' => 'error',
             'message' => 'At least one build must be selected']);
    }

    // ---- Build name map (for headers + executed row reference) ----
    $buildNameMap = [];
    $buildInfoSet = $tplanMgr->get_builds($tplanId);
    if (!is_null($buildInfoSet)) {
        foreach ($buildInfoSet as $bid => $binfo) {
            $buildNameMap[intval($bid)] = $binfo['name'];
        }
    }

    // ---- Keyword map ----
    $keywordName = lang_get('any');
    $kwMap = $tplanMgr->get_keywords_map($tplanId);
    if (!is_null($kwMap) && isset($kwMap[$keywordId])) {
        $keywordName = $kwMap[$keywordId];
    }

    // ---- Users for owner/executor labels ----
    $userNameMap = [];
    $users = getUsersForHtmlOptions($db, ALL_USERS_FILTER);
    if (is_array($users)) {
        foreach ($users as $uid => $uname) {
            $userNameMap[intval($uid)] = $uname;
        }
    }
    $ownerName = isset($userNameMap[$ownerId]) ? $userNameMap[$ownerId]
        : lang_get('any');
    $executorName = isset($userNameMap[$executorId]) ? $userNameMap[$executorId]
        : lang_get('any');

    // ---- Gather all linked test cases + executions ----
    // getExecStatusMatrixFlat returns:
    //   metrics   : flat rows (one per linked tc x platform x build/not_run)
    //   latestExec: [platform_id][tcase_id] => last exec (build_id,status,id)
    $opt = ['output' => 'cumulative'];
    $execStatus = $metricsMgr->getExecStatusMatrixFlat($tplanId, null, $opt);
    $metricsRows = $execStatus['metrics'];
    $latestExec = $execStatus['latestExec'];

    // ---- Build a per-tcase summary ----
    $tcaseRows = [];  // tcase_id => row aggregate (suite name, full ext id, name)
    $tcExecByBuild = [];  // tcase_id => [build_id => last status]
    $suiteCache = [];

    if (!is_null($metricsRows)) {
        foreach ($metricsRows as $r) {
            $tcId = intval($r['tcase_id']);
            if (!isset($tcaseRows[$tcId])) {
                $suiteName = isset($r['suiteName']) ? $r['suiteName'] : '';
                // suiteName may be empty on the raw rows; cache by tsuite_id
                $tcaseRows[$tcId] = [
                    'tcase_id' => $tcId,
                    'tcversion_id' => intval($r['tcversion_id']),
                    'external_id' => intval($r['external_id']),
                    'full_external_id' => (isset($r['full_external_id'])
                        ? $r['full_external_id']
                        : ((isset($r['testcasePrefix']) ? $r['testcasePrefix'] : '')
                           . (isset($r['external_id']) ? $r['external_id'] : ''))),
                    'name' => $r['name'],
                    'tsuite_id' => intval($r['tsuite_id']),
                    'suite_name' => $suiteName,
                    'priority_level' => isset($r['priority_level'])
                        ? $r['priority_level'] : MEDIUM,
                ];
            }
            if (!isset($tcExecByBuild[$tcId])) {
                $tcExecByBuild[$tcId] = [];
            }
        }
    }

    // Build per-(tcase, build) last execution status from the flat metrics
    // rows (latestExec only keeps ONE build per platform+tcase, so it cannot
    // drive a per-build matrix). not_run union rows carry a null build_id and
    // are skipped here; any selected build with no execution row for a TC is
    // reported as not_run.
    $statusByTcBuild = [];  // tcase_id => build_id => ['exec_id'=>, 'status'=>]
    if (!is_null($metricsRows)) {
        foreach ($metricsRows as $r) {
            if (!isset($r['build_id']) || $r['build_id'] === null
                || $r['build_id'] === '') {
                continue;
            }
            $tcId = intval($r['tcase_id']);
            $buildId = intval($r['build_id']);
            $execId = intval($r['executions_id']);
            if (!isset($statusByTcBuild[$tcId])) {
                $statusByTcBuild[$tcId] = [];
            }
            if (!isset($statusByTcBuild[$tcId][$buildId])
                || $execId > $statusByTcBuild[$tcId][$buildId]['exec_id']) {
                $statusByTcBuild[$tcId][$buildId] = [
                    'exec_id' => $execId,
                    'status' => $r['status'],
                ];
            }
        }
    }

    // ---- Suite name resolution via tree path ----
    if (count($tcaseRows) > 0) {
        foreach ($tcaseRows as &$tr) {
            if ($tr['suite_name'] === '') {
                $path = $metricsMgr->tree_manager->get_path(
                    $tr['tsuite_id'], null, 'name');
                $tr['suite_name'] = is_array($path)
                    ? implode('/', array_values($path)) : '';
            }
        }
        unset($tr);
    }

    // ---- Apply filters ----
    // Suite filter: keep TCs whose top-level suite is in suiteSel (empty = all).
    $topLevelSuites = [];
    if (count($suiteSel) > 0) {
        $rootSet = $tplanMgr->getRootTestSuites($tplanId, $tprojectId,
            ['output' => 'plain']);
        if (!is_null($rootSet)) {
            $topLevelSuites = array_keys($rootSet);
        }
        $userWantsAll = (count($suiteSel) == count($topLevelSuites));
        if (!$userWantsAll) {
            // resolve each selected suite id to its subtree (descendants) so
            // linked TCs in nested suites are included.
            foreach ($suiteSel as $ssid) {
                $sub = $metricsMgr->tree_manager->get_subtree($ssid);
                if (is_array($sub)) {
                    foreach (array_keys($sub) as $nodeId) {
                        $topLevelSuites[] = intval($nodeId);
                    }
                } else {
                    $topLevelSuites[] = intval($ssid);
                }
            }
            $topLevelSuites = array_values(array_unique($topLevelSuites));
        }
    } else {
        $rootSet = $tplanMgr->getRootTestSuites($tplanId, $tprojectId,
            ['output' => 'plain']);
        if (!is_null($rootSet)) {
            $topLevelSuites = array_keys($rootSet);
        }
    }

    // TCs to include: from full exec metrics filtered by tsuite subtree +
    // also TCs that have only not-run rows (already in metricsRows).
    $keepTcase = [];
    $keepBySuite = [];
    foreach ($tcaseRows as $tcId => $tr) {
        if (count($topLevelSuites) > 0
            && !in_array($tr['tsuite_id'], $topLevelSuites)) {
            continue;
        }
        $keepTcase[] = $tcId;
        $keepBySuite[$tr['tsuite_id']][$tcId] = $tr;
    }
    $keepTcaseMap = array_flip($keepTcase);

    // ---- Platform filter (affects executed statuses) ----
    $platformFilter = $platformSel;
    // A TC is kept on a platform pass only if it has an execution (or not-run)
    // on one of the selected platforms. We approximate with latestExec for the
    // executed set and allow all when ALL platforms / no platforms selected.
    $platformFilteredTcase = $keepTcase;
    if (count($platformFilter) > 0) {
        $platformOk = [];
        if (!is_null($latestExec)) {
            foreach ($platformFilter as $pf) {
                if (isset($latestExec[$pf])) {
                    foreach (array_keys($latestExec[$pf]) as $ptc) {
                        $platformOk[intval($ptc)] = true;
                    }
                }
            }
        }
        $platformFilteredTcase = [];
        foreach ($keepTcase as $tcId) {
            if (isset($platformOk[$tcId])) {
                $platformFilteredTcase[] = $tcId;
            }
        }
    }

    // ---- keyword filter ----
    if ($keywordId > 0) {
        $kwTcIds = [];
        if (!is_null($metricsRows)) {
            $kwFlags = [];
            foreach ($metricsRows as $r) {
                if (intval($r['keyword_id']) === $keywordId) {
                    $kwFlags[intval($r['tcase_id'])] = true;
                }
            }
            $kwTcIds = array_keys($kwFlags);
        }
        $kwMapIdx = array_flip($kwTcIds);
        $tmp = [];
        foreach ($platformFilteredTcase as $tcId) {
            if (isset($kwMapIdx[$tcId])) {
                $tmp[] = $tcId;
            }
        }
        $platformFilteredTcase = $tmp;
    }

    // ---- search-in-notes (matches against any execution note for the tc) ----
    if ($searchNotes !== '') {
        $notesTc = [];
        if (!is_null($metricsRows)) {
            foreach ($metricsRows as $r) {
                if (isset($r['notes'])
                    && stripos($r['notes'], $searchNotes) !== false) {
                    $notesTc[intval($r['tcase_id'])] = true;
                }
            }
        }
        $nIdx = array_flip($notesTc);
        $tmp = [];
        foreach ($platformFilteredTcase as $tcId) {
            if (isset($nIdx[$tcId])) {
                $tmp[] = $tcId;
            }
        }
        $platformFilteredTcase = $tmp;
    }

    // ---- owner filter ----
    if ($ownerId > 0 && !is_null($metricsRows)) {
        $ownerTc = [];
        foreach ($metricsRows as $r) {
            if (isset($r['assigned_user_id'])
                && intval($r['assigned_user_id']) === $ownerId) {
                $ownerTc[intval($r['tcase_id'])] = true;
            }
        }
        $oIdx = array_flip($ownerTc);
        $tmp = [];
        foreach ($platformFilteredTcase as $tcId) {
            if (isset($oIdx[$tcId])) {
                $tmp[] = $tcId;
            }
        }
        $platformFilteredTcase = $tmp;
    }

    // ---- executor filter ----
    if ($executorId > 0 && !is_null($metricsRows)) {
        $execTc = [];
        foreach ($metricsRows as $r) {
            if (isset($r['executed_by']) && intval($r['executed_by']) === $executorId) {
                $execTc[intval($r['tcase_id'])] = true;
            }
        }
        $eIdx = array_flip($execTc);
        $tmp = [];
        foreach ($platformFilteredTcase as $tcId) {
            if (isset($eIdx[$tcId])) {
                $tmp[] = $tcId;
            }
        }
        $platformFilteredTcase = $tmp;
    }

    // ---- Build per-TC per-build status, honouring last-status filter ----
    $nb = count($buildSel);
    $rows = [];
    $notRunCode = $resultsCfg['status_code']['not_run'];

    foreach ($platformFilteredTcase as $tcId) {
        $tr = $tcaseRows[$tcId];
        $statusCell = [];
        foreach ($buildSel as $bid) {
            $st = $notRunCode;
            if (isset($statusByTcBuild[$tcId][$bid])) {
                $st = $statusByTcBuild[$tcId][$bid]['status'];
            }
            $statusCell[$bid] = $st;
        }

        // last-status filter: include TC if any of its selected-build statuses
        // is in lastStatusSel (legacy "displayResults[status]" semantics).
        $match = false;
        foreach ($statusCell as $st) {
            if (in_array($st, $lastStatusSel)) {
                $match = true;
                break;
            }
        }
        if (!$match) {
            continue;
        }

        $executedAny = false;
        $hasNotRun = false;
        $rowCells = [];
        foreach ($buildSel as $bid) {
            $st = $statusCell[$bid];
            if ($st !== $notRunCode) {
                $executedAny = true;
            } else {
                $hasNotRun = true;
            }
            $rowCells[] = [
                'build_id' => $bid,
                'status' => $st,
                'executed' => ($st !== $notRunCode),
            ];
        }
        $rows[] = [
            'tcase_id' => $tcId,
            'full_external_id' => $tr['full_external_id'],
            'name' => $tr['name'],
            'suite_name' => $tr['suite_name'],
            'priority_level' => $tr['priority_level'],
            'cells' => $rowCells,
            'executed_any' => $executedAny,
            'has_not_run' => $hasNotRun,
        ];
    }

    // ---- Build totals (over the same filtered row set as the matrix) ----
    $perBuildCounts = [];
    foreach ($buildSel as $bid) {
        $perBuildCounts[$bid] = [];
    }
    foreach ($rows as $r) {
        foreach ($r['cells'] as $cell) {
            $bid = $cell['build_id'];
            $perBuildCounts[$bid][$cell['status']] =
                isset($perBuildCounts[$bid][$cell['status']])
                    ? $perBuildCounts[$bid][$cell['status']] + 1 : 1;
        }
    }
    $buildTotals = [];
    foreach ($buildSel as $bid) {
        $buildTotals[] = [
            'build_id' => $bid,
            'name' => isset($buildNameMap[$bid]) ? $buildNameMap[$bid] : ('#' . $bid),
            'counts' => $perBuildCounts[$bid],
        ];
    }

    // Suite summaries: total / per-status counts grouped by suite name.
    $suiteSummaries = [];
    if ($displaySuiteSummaries) {
        $agg = [];
        foreach ($rows as $r) {
            $sn = $r['suite_name'];
            if (!isset($agg[$sn])) {
                $agg[$sn] = ['total' => 0];
            }
            $agg[$sn]['total']++;
            foreach ($r['cells'] as $cell) {
                $agg[$sn][$cell['status']] = isset($agg[$sn][$cell['status']])
                    ? $agg[$sn][$cell['status']] + 1 : 1;
            }
        }
        ksort($agg);
        $suiteSummaries = $agg;
    }

    // Status label map resolved server side.
    $statusLabels = [];
    foreach ($resultsCfg['status_label'] as $verbose => $label) {
        $statusLabels[$resultsCfg['status_code'][$verbose]] =
            lang_get($label);
    }

    // Suite names selected for the query-params block.
    $suiteSelNames = [];
    $rootSet = $tplanMgr->getRootTestSuites($tplanId, $tprojectId,
        ['output' => 'plain']);
    if (!is_null($rootSet)) {
        foreach ($suiteSel as $ssid) {
            if (isset($rootSet[$ssid])) {
                $suiteSelNames[] = $rootSet[$ssid]['name'];
            }
        }
    }

    out([
        'status' => 'ok',
        'hasContext' => true,
        'hasData' => count($rows) > 0,
        'tproject_id' => $tprojectId,
        'tplan_id' => $tplanId,
        'tproject_name' => $proj['name'],
        'tplan_name' => $tplanInfo['name'],
        'display' => [
            'query_params' => $displayQueryParams,
            'totals' => $displayTotals,
            'suite_summaries' => $displaySuiteSummaries,
            'test_cases' => $displayTestCases,
            'latest_results' => $displayLatest,
        ],
        'selected' => [
            'build_ids' => $buildSel,
            'suite_ids' => $suiteSel,
            'suite_names' => $suiteSelNames,
            'keyword_id' => $keywordId,
            'keyword_name' => $keywordName,
            'owner_id' => $ownerId,
            'owner_name' => $ownerName,
            'executor_id' => $executorId,
            'executor_name' => $executorName,
            'search_notes_string' => $searchNotes,
            'last_status' => $lastStatusSel,
        ],
        'builds' => $buildTotals,
        'rows' => $rows,
        'suite_summaries' => $suiteSummaries,
        'status_labels' => $statusLabels,
        'elapsed_time' => round(microtime(true) - $timerOn, 2),
    ]);
}

// ── uncovered_testcases ──────────────────────────────────────────────
// Uncovered Test Cases report — mirrors lib/results/uncoveredTestCases.php
// (Refs #843). For the current test project, list test cases that have NO
// requirement assigned (no row in req_coverage), grouped by test suite.
// Right gate: testplan_metrics (same as legacy checkRights()).
if ($action === 'uncovered_testcases') {
    $timerOn = microtime(true);

    if ($tprojectId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Missing test project id']);
    }

    $projInfo = $tprojectMgr->get_by_id($tprojectId);
    if (is_null($projInfo) || !isset($projInfo['name'])) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test project id']);
    }

    if (!$user->hasRight($db, 'testplan_metrics', $tprojectId)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }

    // 1. Does the project define any req spec? Does any hold requirements?
    $reqSpec = $tprojectMgr->genComboReqSpec($tprojectId);
    $hasReqSpec = is_array($reqSpec) && count($reqSpec) > 0;

    $hasRequirements = false;
    if ($hasReqSpec) {
        $reqSpecMgr = new requirement_spec_mgr($db);
        foreach ($reqSpec as $reqSpecID => $name) {
            try {
                $cnt = $reqSpecMgr->get_requirements_count($reqSpecID);
            } catch (Exception $e) {
                $cnt = 0;
            }
            if ($hasRequirements = ($cnt > 0)) {
                break;
            }
        }
        unset($reqSpecMgr);
    }

    $tables = tlObjectWithDB::getDBTables(
        ['req_coverage', 'nodes_hierarchy', 'tcversions', 'node_types']);
    $uncovered = [];

    if ($hasRequirements) {
        // All test case ids (active/inactive) in the test project.
        $tcasesID = null;
        $tprojectMgr->get_all_testcases_id($tprojectId, $tcasesID);

        if (!is_null($tcasesID) && count($tcasesID) > 0) {
            // get_all_testcases_id('just_id') returns a plain list of ids.
            $inIds = implode(',', array_map('intval', (array)$tcasesID));
            $sql = "SELECT NHA.id AS tc_id, NHA.name, NHA.parent_id AS testsuite_id, " .
                   "REQC.req_id " .
                   " FROM {$tables['nodes_hierarchy']} NHA " .
                   " JOIN {$tables['node_types']} NT ON NHA.node_type_id=NT.id " .
                   " LEFT OUTER JOIN {$tables['req_coverage']} REQC on REQC.testcase_id=NHA.id " .
                   " WHERE NT.description='testcase' AND NHA.id IN ({$inIds}) " .
                   " AND REQC.req_id IS NULL";
            $uncovered = $db->fetchRowsIntoMap($sql, 'tc_id');
        }
    }

    // 2. External ids for the uncovered test cases.
    if (is_array($uncovered) && count($uncovered) > 0) {
        $testSet = array_map('intval', array_keys($uncovered));
        $inClause = implode(',', $testSet);
        $sql = "SELECT DISTINCT NHA.id AS tc_id, TCV.tc_external_id " .
               " FROM {$tables['nodes_hierarchy']} NHA, " .
               " {$tables['nodes_hierarchy']} NHB, " .
               " {$tables['tcversions']} TCV, {$tables['node_types']} NT " .
               " WHERE NHA.node_type_id=NT.id AND NHA.id=NHB.parent_id AND NHB.id=TCV.id " .
               " AND NHA.id IN ({$inClause}) AND NT.description='testcase'";
        $externalId = $db->fetchRowsIntoMap($sql, 'tc_id');
        foreach ($externalId as $key => $value) {
            if (isset($uncovered[$key])) {
                $uncovered[$key]['external_id'] = $value['tc_external_id'];
            }
        }
    }

    $tcaseCfg = config_get('testcase_cfg');
    $tcPrefix = $tprojectMgr->getTestCasePrefix($tprojectId);
    if (is_null($tcPrefix)) {
        $tcPrefix = '';
    }
    $tcPrefix .= $tcaseCfg->glue_character;

    // 3. Group uncovered test cases by test suite (preserving the legacy
    //    gen_spec_view ordering: suite name -> its test cases).
    $suites = [];
    if (is_array($uncovered) && count($uncovered) > 0) {
        $tcIds = array_map('intval', array_keys($uncovered));

        // Suite display name per suite id.
        $suiteIds = [];
        foreach ($uncovered as $tcInfo) {
            $sid = intval($tcInfo['testsuite_id'] ?? 0);
            if ($sid > 0) {
                $suiteIds[$sid] = $sid;
            }
        }
        $suiteNames = [];
        if (count($suiteIds) > 0) {
            $nhTable = tlObjectWithDB::getDBTables(['nodes_hierarchy']);
            $sql = 'SELECT id, name FROM ' . $nhTable['nodes_hierarchy'] .
                   ' WHERE id IN (' . implode(',', $suiteIds) . ')';
            $suiteRows = $db->fetchRowsIntoMap($sql, 'id');
            foreach ($suiteRows as $sid => $srow) {
                $suiteNames[$sid] = $srow['name'];
            }
        }

        foreach ($uncovered as $tcId => $tcInfo) {
            $sid = intval($tcInfo['testsuite_id'] ?? 0);
            $suiteKey = $sid > 0 ? $sid : 0;
            $suiteName = $sid > 0 ? ($suiteNames[$sid] ?? ('#' . $sid)) : '';
            if (!isset($suites[$suiteKey])) {
                $suites[$suiteKey] = [
                    'suite_id' => $sid,
                    'suite_name' => $suiteName,
                    'tc_count' => 0,
                    'testcases' => [],
                ];
            }
            $suites[$suiteKey]['testcases'][] = [
                'tc_id'        => intval($tcId),
                'external_id'  => ($tcPrefix . ($tcInfo['external_id'] ?? '')),
                'name'         => strip_tags($tcInfo['name'] ?? ''),
            ];
            $suites[$suiteKey]['tc_count']++;
        }
        ksort($suites);
    }

    $suiteList = array_values($suites);

    out([
        'status'           => 'ok',
        'tproject_id'      => $tprojectId,
        'tproject_name'    => $projInfo['name'],
        'has_reqspec'      => $hasReqSpec,
        'has_requirements' => $hasRequirements,
        'has_data'         => count($suiteList) > 0,
        'suites'           => $suiteList,
        'elapsed_time'     => round(microtime(true) - $timerOn, 2),
    ]);
    exit;
}

http_response_code(404);
out(['status' => 'error', 'message' => 'Unknown action']);
