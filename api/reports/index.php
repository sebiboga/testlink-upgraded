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
        $names = tlUser::getNames($db);

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
                    'login' => isset($names[$userId]['login'])
                        ? $names[$userId]['login'] : '',
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

http_response_code(404);
out(['status' => 'error', 'message' => 'Unknown action']);
