<?php
/**
 * Reports Print BFF API
 * URL: /api/reportsprint/
 * Plain PHP, no framework, no compilation
 *
 * Mirrors lib/results/printDocument.php (TestLink 1.9.20) - the "Test Plan
 * Report" document generator that the modernized navigator screen
 * (gui/templates/results/testPlanReport.html) drives. That navigator offers a
 * suite tree + print options and previously opened the legacy controller
 * directly in a new tab (PRINT_URL = /lib/results/printDocument.php) and per
 * build through lnl.php.
 *
 * Modernization keeps reusing the battle-tested legacy render pipeline over
 * the network (same strategy as api/executionprint Refs #844): the BFF runs
 * the legacy generation inside an output buffer and returns the resulting
 * printable document as JSON; the standalone reportPrint.html screen provides
 * the Dashio shell, toolbar (Print / Close / Download) and print CSS, then
 * invokes window.print().
 *
 * IMPORTANT (scoping): the included legacy controller resolves its nested
 * require()/require() of config files against the current working directory
 * and, when included from a *function* scope, runs them in that local scope
 * where the global $tlCfg is invisible ("on null" fatal). So this file
 * includes lib/results/printDocument.php at the TOP-LEVEL script scope after
 * chdir() into lib/results - exactly the environment the legacy entry script
 * runs in. Never move this include inside a helper function.
 *
 * Security differences vs legacy (intentional hardening):
 *  - Legacy printDocument.php reached the generator with testlinkInitPage()
 *    (session) OR an anonymous apikey (lnl.php share links). The modern print
 *    popup runs inside the authenticated app, so the BFF REQUIRES a session
 *    user and never accepts an apikey anonymous path.
 *  - Rights: testplan_metrics on the context test project + test plan (the
 *    exact right legacy checkRights() enforces via hasRightOnProj()).
 *  - Plan-based document types validate that the test plan belongs to the
 *    request's test project (legacy Refs #573 behavior) -> 400 otherwise.
 *
 * Routes:
 *   GET ?action=print&type=testplan&level=testproject|testsuite&id=N
 *        &tproject_id=N&tplan_id=N&format=N&[opt=y|n...]
 *        -> { status, level, id, doc_type, body_html }
 *        Renders the document in HTML and returns it as JSON for the popup.
 *        Access: authenticated + testplan_metrics on context project/plan.
 *   GET ?action=download&... (same params)
 *        -> streams the generated document with a Content-Disposition
 *           attachment header (Word / HTML file download) instead of JSON.
 *        Access: same as print.
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once(__DIR__ . '/../../cfg/reports.cfg.php');
require_once('common.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

$db = new database(DB_TYPE);
doDBConnect($db);

function out($data) { echo json_encode($data); exit; }

$userId = $_SESSION['userID'] ?? null;
if (!$userId || $userId <= 0) {
    http_response_code(401);
    out(['status' => 'error', 'message' => 'Not authenticated']);
}
// keep the legacy checkSessionValid() (which redirects on staleness) happy so
// it never emits a redirect script into our captured body.
$_SESSION['lastActivity'] = time();

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    http_response_code(401);
    out(['status' => 'error', 'message' => 'User not found']);
}
$_SESSION['currentUser'] = $user;

$action = $_GET['action'] ?? '';

if ($action !== 'print' && $action !== 'download') {
    http_response_code(404);
    out(['status' => 'error', 'message' => 'Unknown action']);
}

// ---- parameter validation (mirrors printDocument.php init_args) ----
$type = $_GET['type'] ?? 'testplan';
$typeDomain = ['test_plan' => 'testplan', 'test_report' => 'testreport'];
if (isset($typeDomain[$type])) { $type = $typeDomain[$type]; }
$validTypes = ['testplan', 'testreport', 'testreport_onbuild'];
if (!in_array($type, $validTypes, true)) {
    http_response_code(400);
    out(['status' => 'error', 'message' => 'Invalid type']);
}

$level = $_GET['level'] ?? 'testproject';
$validLevels = ['testproject', 'testsuite'];
if (!in_array($level, $validLevels, true)) {
    http_response_code(400);
    out(['status' => 'error', 'message' => 'Invalid level']);
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$tprojectId = isset($_GET['tproject_id']) ? intval($_GET['tproject_id']) : 0;
$tplanId = isset($_GET['tplan_id']) ? intval($_GET['tplan_id']) : 0;
if ($tprojectId <= 0) {
    // legacy falls back to the session's active test project
    $tprojectId = isset($_SESSION['testprojectID']) ? intval($_SESSION['testprojectID']) : 0;
}
if ($tplanId <= 0) {
    $tplanId = isset($_GET['docTestPlanId']) ? intval($_GET['docTestPlanId']) : 0;
}
$format = isset($_GET['format']) ? intval($_GET['format']) : FORMAT_HTML;
$buildId = isset($_GET['build_id']) ? intval($_GET['build_id']) : 0;
if ($id <= 0 && $level === 'testproject') { $id = $tprojectId; }
if ($id <= 0) {
    http_response_code(400);
    out(['status' => 'error', 'message' => 'Missing id']);
}

$docType = $type;

// ---- context validation (legacy Refs #573) ----
$tprojectMgr = new testproject($db);
$tplanMgr = new testplan($db);
$proj = $tprojectMgr->get_by_id($tprojectId);
if (is_null($proj) || !isset($proj['name'])) {
    http_response_code(404);
    out(['status' => 'error', 'message' => 'Test project not found']);
}
$planBasedTypes = [DOC_TEST_PLAN_DESIGN, DOC_TEST_PLAN_EXECUTION, DOC_TEST_PLAN_EXECUTION_ON_BUILD];
if (in_array($docType, $planBasedTypes, true)) {
    $tplanInfo = $tplanMgr->get_by_id($tplanId);
    if (is_null($tplanInfo) || !isset($tplanInfo['tproject_id']) ||
        intval($tplanInfo['tproject_id']) !== $tprojectId) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test plan for this test project']);
    }
}

// ---- authorization: testplan_metrics on the context ----
$hasRight = false;
try {
    $hasRight = $user->hasRight($db, 'testplan_metrics', $tprojectId, $tplanId);
} catch (\Throwable $e) {
    $hasRight = false;
}
if (!$hasRight) {
    http_response_code(403);
    out(['status' => 'error', 'message' => 'No permission']);
}

// ---- set the session + request state the legacy controller expects ----
$_SESSION['testprojectID'] = $tprojectId;
if (empty($_SESSION['testprojectPrefix'])) {
    $_SESSION['testprojectPrefix'] = isset($proj['prefix']) ? $proj['prefix'] : '';
}

// Forward all generation params + optional print option flags into
// $_GET/$_REQUEST so the included controller's init_args()/initPrintOpt()
// read them unchanged (toc=y, summary=y, ... become booleans server-side).
$_GET['type'] = $type;
$_GET['level'] = $level;
$_GET['id'] = $id;
$_GET['docTestPlanId'] = $tplanId;
$_GET['tproject_id'] = $tprojectId;
$_GET['tplan_id'] = $tplanId;
$_GET['format'] = $format;
if ($buildId > 0) {
    $_GET['build_id'] = $buildId;
    $_REQUEST['build_id'] = $buildId;
}
$_REQUEST['type'] = $type;
$_REQUEST['level'] = $level;
$_REQUEST['id'] = $id;
$_REQUEST['docTestPlanId'] = $tplanId;
$_REQUEST['tproject_id'] = $tprojectId;
$_REQUEST['tplan_id'] = $tplanId;
$_REQUEST['format'] = $format;

setPaths();

// ---- generate the document at TOP-LEVEL scope (see scoping note) ----
$cwd = getcwd();
chdir(__DIR__ . '/../../lib/results');
ob_start();
try {
    include __DIR__ . '/../../lib/results/printDocument.php';
    $bodyHtml = (string)ob_get_clean();
} catch (\Throwable $e) {
    ob_end_clean();
    chdir((string)$cwd);
    http_response_code(500);
    out(['status' => 'error', 'message' => 'Report generation failed']);
}
chdir((string)$cwd);

if ($action === 'download') {
    if ($bodyHtml === '') {
        http_response_code(500);
        out(['status' => 'error', 'message' => 'Report generation returned no content']);
    }
    // legacy flushHttpHeader() already set attachment headers for non-HTML;
    // for HTML we set them here after the buffer is cleaned.
    if ($format === FORMAT_HTML) {
        $filename = (($_SESSION['testprojectPrefix'] ?? '') ?: 'report') .
            '-test_plan-' . date('Y-m-d') . '.html';
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
    }
    echo $bodyHtml;
    exit;
}

if ($action === 'print') {
    if ($bodyHtml === '') {
        http_response_code(500);
        out(['status' => 'error', 'message' => 'Report generation returned no content']);
    }
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode([
        'status' => 'ok',
        'level' => $level,
        'id' => $id,
        'doc_type' => $docType,
        'body_html' => $bodyHtml,
    ]);
    exit;
}

http_response_code(404);
out(['status' => 'error', 'message' => 'Unknown action']);
