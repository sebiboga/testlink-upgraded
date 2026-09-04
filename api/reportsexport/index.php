<?php
/**
 * Report XLS/Mail Export BFF API
 * URL: /api/reportsexport/
 * Plain PHP, no framework
 *
 * Authenticated gateway for report spreadsheet export and email endpoints.
 * Validates session + testplan_metrics right, then proxies the request to
 * the corresponding legacy controller that generates the XLS/mail content.
 *
 * This removes direct legacy PHP references from the modernized report
 * screens: export_xls_url and send_mail_url now point here instead of
 * lib/results/*.php.
 *
 * Routes:
 *   GET ?action=<type>&tplan_id=N [&tproject_id=M]
 *       action = general_metrics | results_by_tsuite | baseline_l1l2 |
 *                results_by_status | results_matrix | results_tc_flat |
 *                absolute_latest | never_run | exec_timeline_stats |
 *                general_metrics_mail | results_by_tsuite_mail | ...
 *
 *       -> 302 redirect to the legacy controller with the proper format
 *          parameters so it generates the XLS file / email form.
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once(__DIR__ . '/../../cfg/reports.cfg.php');
require_once('common.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

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

$action = $_GET['action'] ?? '';
$tplanId = intval($_GET['tplan_id'] ?? 0);
$tprojectId = intval($_GET['tproject_id'] ?? 0);

if ($tplanId <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing tplan_id']);
    exit;
}

// Rights check: testplan_metrics on the owning test project
$tplanMgr = new testplan($db);
$tplanInfo = $tplanMgr->get_by_id($tplanId);
if (!$tplanInfo) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Test plan not found']);
    exit;
}
if (!$tprojectId) {
    $tprojectId = $tplanInfo['testproject_id'];
}
if (!$user->hasRightOnProj($db, 'testplan_metrics', $tprojectId, $tplanId)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'No permission']);
    exit;
}

// Map of actions to legacy controller paths and query parameters.
// XLS actions redirect to the legacy controller which generates the file.
// Mail actions redirect to the legacy controller which shows the email form.
$exportMap = [
    // --- XLS exports ---
    'general_metrics' => [
        'file' => '/lib/results/resultsGeneral.php',
        'params' => ['format' => FORMAT_XLS, 'spreadsheet' => '1'],
    ],
    'results_by_tsuite' => [
        'file' => '/lib/results/resultsByTSuite.php',
        'params' => ['format' => FORMAT_XLS, 'spreadsheet' => '1'],
    ],
    'baseline_l1l2' => [
        'file' => '/lib/results/baselinel1l2.php',
        'params' => ['format' => FORMAT_XLS, 'spreadsheet' => '1'],
    ],
    'results_by_status' => [
        'file' => '/lib/results/resultsByStatus.php',
        'params' => ['format' => FORMAT_XLS, 'exportSpreadSheet_x' => '1'],
    ],
    'results_matrix' => [
        'file' => '/lib/results/resultsTC.php',
        'params' => ['format' => FORMAT_XLS, 'doAction' => 'result',
                      'exportSpreadSheet_x' => '1'],
    ],
    'results_tc_flat' => [
        'file' => '/lib/results/resultsTCFlat.php',
        'params' => ['format' => FORMAT_XLS, 'exportSpreadSheet_x' => '1'],
    ],
    'absolute_latest' => [
        'file' => '/lib/results/resultsTCAbsoluteLatest.php',
        'params' => ['format' => FORMAT_XLS],
    ],
    'never_run' => [
        'file' => '/lib/results/neverRunByPP.php',
        'params' => ['format' => FORMAT_XLS],
    ],
    'exec_timeline_stats' => [
        'file' => '/lib/results/execTimelineStats.php',
        'params' => ['format' => FORMAT_XLS],
    ],
    'assigned_tc_overview' => [
        'file' => '/lib/results/resultsTC.php',
        'params' => ['format' => FORMAT_XLS, 'doAction' => 'result',
                      'exportSpreadSheet_x' => '1'],
    ],
    // --- Email report forms ---
    'general_metrics_mail' => [
        'file' => '/lib/results/resultsGeneral.php',
        'params' => ['format' => FORMAT_MAIL_HTML],
    ],
    'results_by_tsuite_mail' => [
        'file' => '/lib/results/resultsByTSuite.php',
        'params' => ['format' => FORMAT_MAIL_HTML],
    ],
    'baseline_l1l2_mail' => [
        'file' => '/lib/results/baselinel1l2.php',
        'params' => ['format' => FORMAT_MAIL_HTML],
    ],
    'results_by_status_mail' => [
        'file' => '/lib/results/resultsByStatus.php',
        'params' => ['format' => FORMAT_MAIL_HTML,
                      'sendSpreadSheetByMail_x' => '1'],
    ],
    'results_matrix_mail' => [
        'file' => '/lib/results/resultsTC.php',
        'params' => ['format' => FORMAT_MAIL_HTML, 'doAction' => 'result',
                      'sendSpreadSheetByMail_x' => '1'],
    ],
    'results_tc_flat_mail' => [
        'file' => '/lib/results/resultsTCFlat.php',
        'params' => ['format' => FORMAT_MAIL_HTML,
                      'sendSpreadSheetByMail_x' => '1'],
    ],
    'absolute_latest_mail' => [
        'file' => '/lib/results/resultsTCAbsoluteLatest.php',
        'params' => ['format' => FORMAT_MAIL_HTML],
    ],
    'never_run_mail' => [
        'file' => '/lib/results/neverRunByPP.php',
        'params' => ['format' => FORMAT_MAIL_HTML],
    ],
    'exec_timeline_stats_mail' => [
        'file' => '/lib/results/execTimelineStats.php',
        'params' => ['format' => FORMAT_MAIL_HTML],
    ],
    'assigned_tc_overview_mail' => [
        'file' => '/lib/results/resultsTC.php',
        'params' => ['format' => FORMAT_MAIL_HTML, 'doAction' => 'result',
                      'sendSpreadSheetByMail_x' => '1'],
    ],
];

if (!isset($exportMap[$action])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
    exit;
}

$target = $exportMap[$action];
$params = $target['params'];
$params['tplan_id'] = $tplanId;
if ($tprojectId > 0) {
    $params['tproject_id'] = $tprojectId;
}

// For results_by_status, add build_set if provided (some screens include it)
if (isset($_GET['build_set']) && is_array($_GET['build_set'])) {
    $params['build_set'] = $_GET['build_set'];
}

// results_by_status: forward the single-letter status code to the legacy
// controller's "type" param (resultsByStatus.php reads type=f|b|n).
if (isset($_GET['status_code'])) {
    $params['type'] = $_GET['status_code'];
}

// Forward platform_id for reports that are scoped per platform
// (e.g. absolute_latest builds platform_id into its export URL).
$platformId = intval($_GET['platform_id'] ?? 0);
if ($platformId > 0) {
    $params['platform_id'] = $platformId;
}

$legacyUrl = $target['file'] . '?' . http_build_query($params);

// Redirect to the legacy controller (303 changes POST→GET; 302 keeps method
// but most browsers convert POST→GET on 302 anyway — legacy controllers
// read from $_REQUEST/$_GET so either works).
header('Location: ' . $legacyUrl, true, 303);
exit;
