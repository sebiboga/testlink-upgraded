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

// Legacy apikey / public-link anonymous access (Refs #1220), mirroring the
// auth resultsTCFlat.php init_args() applies:
//   - 32-char apikey  -> remote access for the owning user; the same
//                        testplan_metrics right gate as a logged session
//   - longer apikey   -> anonymous access tied to the connected test
//                        plan/project entity (addOpAccess=false, no rights)
// The apikey is forwarded to the legacy controller in the redirect, whose
// own init_args() re-runs setUpEnvFor*() against the same (fresh) session,
// guaranteeing identical behaviour to a legacy embed/public link.
// The apikey / public-link flow is scoped to the Results TC Flat screen
// (results_tc_flat + results_tc_flat_mail). Any other action keeps the
// session-only auth path below.
$action = $_GET['action'] ?? '';
$apikeyAction = ($action === 'results_tc_flat' || $action === 'results_tc_flat_mail');

$apikey = isset($_GET['apikey']) ? trim((string)$_GET['apikey']) : '';
if (!$apikeyAction) {
    $apikey = '';
}
$isAnon = false;

$userId = $_SESSION['userID'] ?? null;
if ($apikey !== '') {
    if (strlen($apikey) === 32) {
        $apiUsers = tlUser::getByAPIKey($db, $apikey);
        if (is_array($apiUsers) && count($apiUsers) === 1) {
            $uid = key($apiUsers);
            $user = new tlUser($uid);
            $user->readFromDB($db);
            $userId = $uid;
            $_SESSION['userID'] = $uid;
            $_SESSION['currentUser'] = $user;
            $_SESSION['lastActivity'] = time();
            if (!isset($_SESSION['basehref'])) {
                setPaths();
            }
            if (!isset($_SESSION['locale']) || is_null($_SESSION['locale'])) {
                $_SESSION['locale'] = $user->locale;
                setDateTimeFormats($_SESSION['locale']);
            }
        } else {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'Unknown api key']);
            exit;
        }
    } else {
        $entity = getEntityByAPIKey($db, $apikey, 'testplan');
        if (is_null($entity)) {
            $entity = getEntityByAPIKey($db, $apikey, 'testproject');
        }
        if (is_null($entity)) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'Unknown api key']);
            exit;
        }
        $isAnon = true;
        $user = new tlUser();
        $userId = -1;
        $_SESSION['userID'] = -1;
        $_SESSION['currentUser'] = $user;
        $_SESSION['lastActivity'] = time();
        if (!isset($_SESSION['basehref'])) {
            setPaths();
        }
        if (!isset($_SESSION['locale']) || is_null($_SESSION['locale'])) {
            $_SESSION['locale'] = config_get('default_language');
            setDateTimeFormats($_SESSION['locale']);
        }
    }
}

if (!$isAnon && !isset($user)) {
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
}

$tplanId = intval($_GET['tplan_id'] ?? 0);
$tprojectId = intval($_GET['tproject_id'] ?? 0);

if ($tplanId <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing tplan_id']);
    exit;
}

// Rights check: testplan_metrics on the owning test project
// (skipped for anonymous/apikey access - legacy addOpAccess=false).
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
if (!$isAnon && !$user->hasRightOnProj($db, 'testplan_metrics', $tprojectId, $tplanId)) {
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
        'params' => ['format' => FORMAT_XLS, 'do_action' => 'result',
                      'exportSpreadSheet_x' => '1'],
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
        'params' => ['format' => FORMAT_MAIL_HTML, 'do_action' => 'result',
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

// Forward the apikey so the legacy controller's own init_args() runs its
// setUpEnvForRemoteAccess()/setUpEnvForAnonymousAccess() flow (Refs #1220).
if ($apikey !== '') {
    $params['apikey'] = $apikey;
}

// For reports with build filtering, forward the build set / build list
// (some screens include build_set[] and some build the comma-separated
// buildListForExcel in the export URL).
if (isset($_GET['build_set']) && is_array($_GET['build_set'])) {
    $params['build_set'] = $_GET['build_set'];
}
if (isset($_GET['buildListForExcel']) && $_GET['buildListForExcel'] !== '') {
    $params['buildListForExcel'] = $_GET['buildListForExcel'];
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
