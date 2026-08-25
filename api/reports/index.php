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
    // direct report link per build (lnl.php with the plan api key).
    $builds = [];
    if ($docType === 'testreport_onbuild') {
        $buildInfoSet = $tplanMgr->get_builds($tplanId);
        if (!is_null($buildInfoSet)) {
            foreach ($buildInfoSet as $bid => $binfo) {
                $builds[] = [
                    'id' => intval($bid),
                    'name' => $binfo['name'],
                    'report_url' => 'lnl.php?apikey=' . $tplanInfo['api_key'] .
                        '&tproject_id=' . $tprojectId .
                        '&tplan_id=' . $tplanId .
                        '&type=testreport_onbuild&build_id=' . intval($bid),
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
        // legacy lib/results/resultsGeneral.php endpoints kept for the two
        // export buttons - document/mail/spreadsheet generation stays legacy.
        // Root-relative (sibling convention, see testPlanReport.html
        // PRINT_URL): the page lives under /gui/templates/results/.
        'send_mail_url' => '/lib/results/resultsGeneral.php?format=' .
            FORMAT_MAIL_HTML . '&tplan_id=' . $tplanId,
        'export_xls_url' => '/lib/results/resultsGeneral.php?format=' .
            FORMAT_XLS . '&tplan_id=' . $tplanId . '&spreadsheet=1',
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
        // toolbar buttons - mail/spreadsheet generation stays legacy.
        // Root-relative (sibling convention, see generalMetrics.html):
        // the page lives under /gui/templates/results/.
        'send_mail_url' => '/lib/results/resultsByTSuite.php?format=' .
            FORMAT_MAIL_HTML . '&tplan_id=' . $tplanId .
            '&tproject_id=' . $tprojectId,
        'export_xls_url' => '/lib/results/resultsByTSuite.php?format=' .
            FORMAT_XLS . '&tplan_id=' . $tplanId .
            '&tproject_id=' . $tprojectId . '&spreadsheet=1',
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
        // toolbar buttons - mail/spreadsheet generation stays legacy.
        // Root-relative (sibling convention, see resultsByTSuite.html):
        // the page lives under /gui/templates/results/.
        'send_mail_url' => '/lib/results/baselinel1l2.php?format=' .
            FORMAT_MAIL_HTML . '&tplan_id=' . $tplanId .
            '&tproject_id=' . $tprojectId,
        'export_xls_url' => '/lib/results/baselinel1l2.php?format=' .
            FORMAT_XLS . '&tplan_id=' . $tplanId .
            '&tproject_id=' . $tprojectId . '&spreadsheet=1',
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
        // user link target kept on the legacy controller (assignment
        // overview popup behaviour unchanged)
        'assignment_url' => '/lib/testcases/tcAssignedToUser.php',
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
        // keep toolbar exports functional in launcher mode as well
        // (legacy launcher page exposes the same two endpoints)
        $xlsBase0 = '/lib/results/resultsTC.php?format=' . FORMAT_XLS .
            '&doAction=result&tplan_id=' . $tplanId .
            '&tproject_id=' . $tprojectId;
        $payload['export_xls_url'] = $xlsBase0 . '&exportSpreadSheet_x=1';
        $payload['send_mail_url'] =
            $xlsBase0 . '&sendSpreadSheetByMail_x=1';
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

    // Toolbar keeps the two legacy endpoints (XLS download + email) exactly
    // like the other modernized reports keep theirs. Provided in launcher
    // mode too so the buttons still work after a build-selection apply.
    $xlsBase = '/lib/results/resultsTC.php?format=' . FORMAT_XLS .
        // legacy controller reads doAction (camelCase) - with do_action the
        // >buildQtyLimit path would land on the launcher instead of exporting
        '&doAction=result&tplan_id=' . $tplanId .
        '&tproject_id=' . $tprojectId;
    if ($filterApplied) {
        $xlsBase .= '&buildListForExcel=' . implode(',', $idSet);
        foreach ($idSet as $bid) {
            $xlsBase .= '&build_set%5B%5D=' . intval($bid);
        }
    }
    $payload['export_xls_url'] = $xlsBase . '&exportSpreadSheet_x=1';
    $payload['send_mail_url'] = $xlsBase . '&sendSpreadSheetByMail_x=1';

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

    // Legacy XLS export endpoint
    $xlsBase = '/lib/results/resultsTCFlat.php?format=' . FORMAT_XLS .
        '&do_action=result&tplan_id=' . $tplanId .
        '&tproject_id=' . $tprojectId;
    if ($filterApplied) {
        $xlsBase .= '&buildListForExcel=' . implode(',', $idSet);
        foreach ($idSet as $bid) {
            $xlsBase .= '&build_set%5B%5D=' . intval($bid);
        }
    }
    $payload['export_xls_url'] = $xlsBase;

    $payload['elapsed_time'] =
        round(microtime(true) - $timerOn, 2);
    out($payload);
}

http_response_code(404);
out(['status' => 'error', 'message' => 'Unknown action']);
