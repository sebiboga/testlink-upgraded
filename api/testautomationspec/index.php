<?php
ob_start();
/**
 * Test Automation Specification BFF API
 * URL: /api/testautomationspec/
 * Plain PHP, no framework, no compilation
 *
 * Serves the modernized "Report Test Automation Spec" screen
 * (gui/templates/results/testAutomationSpec.html) - the last standalone
 * legacy lib/results/* report controller without a modern equivalent
 * (lib/results/testAutomationSpec.php + gui/templates/dashio/results/
 * testAutomationSpec.tpl, TestLink 1.9.20).
 *
 * The legacy screen lists every test case whose execution type is
 * "Automated" grouped by its test suite, and is used by automation teams as
 * the source of truth for what should be wired into test automation.
 *
 * The report generator is the battle-tested legacy flat spec-view builder
 * lib/functions/specview.php (function-only library, same include strategy
 * as api/testcasesprint Refs #1010): the BFF calls genSpecViewFlat() with
 * the exact arguments the legacy controller used - specViewType 'testproject'
 * and the exec_type=TESTCASE_EXECUTION_TYPE_AUTO filter - then re-shapes
 * the flat view rows into clean JSON.
 *
 * Routes:
 *   GET ?action=spec&tproject_id=N
 *        -> { status, tprojectId, tprojectName, numTc, totalSuites,
 *             generatedOn,
 *             suites: [ { id, name, testcase_qty,
 *                        testcases: [ { id, external_id, name, version,
 *                                       importance } ] } ] }
 *        Access: authenticated session + mgt_view_tc on the context project.
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
require_once('users.inc.php');
require_once(__DIR__ . '/../../lib/functions/specview.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');

$db = new database(DB_TYPE);
doDBConnect($db);

function out($data) { echo json_encode($data); exit; }

$userId = $_SESSION['userID'] ?? null;
if (!$userId || $userId <= 0) {
    http_response_code(401);
    out(['status' => 'error', 'message' => 'Not authenticated']);
}

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    http_response_code(401);
    out(['status' => 'error', 'message' => 'User not found']);
}

$action = $_GET['action'] ?? '';

if ($action !== 'spec') {
    http_response_code(400);
    out(['status' => 'error', 'message' => 'Unknown action']);
}

// Context project: URL arg first (ASIDE link carries $ctx), session fallback
// (legacy initUserEnv resolves the context from the session the same way).
$tproject_id = intval($_GET['tproject_id'] ?? 0);
if ($tproject_id <= 0) {
    $tproject_id = intval($_SESSION['testprojectID'] ?? 0);
}
if ($tproject_id <= 0) {
    http_response_code(400);
    out(['status' => 'error', 'message' => 'Missing test project']);
}

// Project must exist first (404 before the rights probe, like the
// requirements BFF - api/requirements/index.php).
$tprojMgr = new testproject($db);
$tproject = $tprojMgr->get_by_id($tproject_id);
if (is_null($tproject)) {
    http_response_code(404);
    out(['status' => 'error', 'message' => 'Test project not found']);
}

// Gate: viewing the test-case design of the REQUESTED project (mgt_view_tc).
// NOTE: hasRightOnProj() reads the *session* testprojectID - the id on the
// URL must be checked explicitly with hasRight() (same pattern as the
// requirements BFF).
$canView = $user->hasRight($db, 'mgt_view_tc', $tproject_id);
if (!$canView) {
    http_response_code(403);
    out(['status' => 'error', 'message' => 'No rights to view this report']);
}

// Rebuild the automated-test-case flat spec view exactly like legacy
// testAutomationSpec.php:
//   genSpecViewFlat($db,'testproject',$tproject_id,$tproject_id,'',
//                   $ll,0,$filters,$opt)  with
//   $filters = array('exec_type' => TESTCASE_EXECUTION_TYPE_AUTO)
//   $opt     = array('onlyLatestTCV' => true)
$filters = array('exec_type' => TESTCASE_EXECUTION_TYPE_AUTO);
$opt = array('onlyLatestTCV' => true);
$ll = null;
try {
    $flat = genSpecViewFlat($db, 'testproject', $tproject_id, $tproject_id,
                            '', $ll, 0, $filters, $opt);
} catch (Throwable $e) {
    http_response_code(500);
    out(['status' => 'error', 'message' => 'Report generation failed']);
}

$suites = array();
foreach ($flat['spec_view'] as $suite) {
    if (intval($suite['testcase_qty']) <= 0) {
        continue; // skip suite containers without automated cases
    }
    $rows = array();
    foreach ($suite['testcases'] as $tcase) {
        $version = '';
        if (is_array($tcase['tcversions'])) {
            $v = current($tcase['tcversions']);
            $version = $v === false ? '' : (string)$v;
        }
        $importance = is_array($tcase['importance'])
            ? intval(current($tcase['importance']))
            : 0;
        $rows[] = array(
            'id'         => intval($tcase['id']),
            'external_id'=> $tcase['external_id'] ?? '',
            'name'       => $tcase['name'],
            'version'    => $version,
            'importance' => $importance,
        );
    }
    $suites[] = array(
        'id'           => $suite['testsuite']['id'],
        'name'         => $suite['testsuite']['name'],
        'testcase_qty' => intval($suite['testcase_qty']),
        'testcases'    => $rows,
    );
}

out(array(
    'status'       => 'ok',
    'tprojectId'   => $tproject_id,
    'tprojectName' => $tproject['name'],
    'numTc'        => intval($flat['num_tc']),
    'totalSuites'  => count($suites),
    'generatedOn'  => date('Y-m-d H:i:s'),
    'suites'       => $suites,
));