<?php
/**
 * Platform Pie Chart - REST BFF API
 * URL: /api/platformpiechart/index.php
 * Plain PHP, no framework, no compilation
 *
 * Modern twin of lib/results/platformPieChart.php (TestLink 1.9.20).
 *
 * WHAT THE LEGACY FILE DID
 * ------------------------
 * The 1.9.20 file was an image endpoint: it included the vendored pChart
 * library and answered with a PNG pie chart of the execution-status totals of
 * ONE test plan platform. The data came from
 * tlTestPlanMetrics::getStatusTotalsByPlatformForRender($tplan_id)
 * (per-platform status totals, status set = results.status_label_for_exec_ui,
 * only ACTIVE BUILDS by default: getExecCountersByPlatformExecStatus falls
 * back to "active builds only" when no build set is given), with the pie
 * slices labelled via results.status_label. When the requested platform had no
 * test case assigned, it rendered an all-zero pie instead of failing.
 *
 * WHY THE PORT IS NEEDED
 * ----------------------
 * The legacy endpoint drew only ONE platform per call (platform_id was a
 * mandatory argument), so a full "overall metrics by platform" picture meant
 * one HTTP request and one PNG per platform. The modern screen aggregates ALL
 * platforms of the plan into one table + stacked bars and answers a single
 * GET, mirroring the same getStatusTotalsByPlatformForRender() aggregation the
 * legacy chart used - the numbers stay TestLink-native, not a re-derivation.
 *
 * THE HOLES THE PORT CLOSES
 * -------------------------
 *  - checkRights() was a bare global hasRight('testplan_metrics'): a user with
 *    the right held ONLY globally could read ANY plan's totals - the owning
 *    project was never proven, and there was no opaque-vs-permission
 *    separation (a missing platform answered an image, not an error).
 *  - tproject_id was blindly read from the session; a stale session pointed
 *    the chart at the wrong project without any error.
 *  - The response was a GD-wrapped PNG with no nosniff and no
 *    application/json contract; there was no state handling at all
 *    (no 400/401/403/404/405 - every failure was a blank image or a fatal).
 *
 * SEMANTICS (documented because they drive the table the screen renders):
 *  - rows = the platforms with at least one test-case version assigned to the
 *    plan (platform_id 0 "no platform" is skipped, exactly like the counter
 *    in every sibling BFF);
 *  - per row: total_tc = assigned versions, details[status] = {qty,
 *    percentage} and percentage_completed, all straight from
 *    getStatusTotalsByPlatformForRender() - the same numbers the legacy pie
 *    drew;
 *  - the status column order is canonical (passed, failed, blocked, not_run
 *    first, any extra configured status appended) so the screen can colour the
 *    stacked bars with the family palette;
 *  - totals = the column sums across platforms. Because one version can be
 *    assigned to several platforms, per-platform rows share versions: the
 *    Totals row is the sum of the shown platform columns (each row is
 *    self-consistent), not a de-duplicated whole-plan count.
 *
 * Rights gate: testplan_metrics on the OWNING test project AND test plan, with
 * getAccess = true (legacy pageAccessCheck semantics: a right held only
 * globally no longer reaches a private test project).
 *
 * Contract: GET|HEAD ?action=init&tplan_id=N[&tproject_id=P]
 *   200 {status, code, context, statuses[], platforms[], totals}
 *   400 invalid_request | 401 not_authenticated / session_expired
 *   403 no_right | 404 tplan_not_found / project_mismatch | 405 method_not_allowed
 *
 * Refs #1906
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
require_once('testplan.class.php');
require_once('testproject.class.php');
require_once('tlTestPlanMetrics.class.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

/**
 * Single JSON exit point. $code is applied ONLY when explicitly given, so a
 * guard/validation branch that already called http_response_code() is not
 * reset back to 200 (the api/reports convention).
 */
function ppcOut($payload, $code = null)
{
    if ($code !== null) {
        http_response_code($code);
    }
    echo json_encode($payload);
    exit;
}

function ppcFail($httpCode, $code, $message = '')
{
    ppcOut(array(
        'status' => 'error',
        'code' => $code,
        'message' => $message !== '' ? $message : $code,
    ), $httpCode);
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    ppcFail(405, 'method_not_allowed');
}

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    ppcFail(401, 'not_authenticated', 'Not authenticated');
}

bffEnforceSession($db);

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    ppcFail(401, 'not_authenticated', 'User not found');
}

$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if ($action !== 'init') {
    ppcFail(400, 'invalid_request', 'Unknown action');
}

/** Strict positive integer reader - "1abc", "1.9", "-3" and "" are all refused. */
function ppcPositiveInt($raw)
{
    if ($raw === null || is_array($raw)) {
        return 0;
    }
    $raw = trim((string)$raw);
    if ($raw === '' || !preg_match('/^[0-9]+$/', $raw)) {
        return 0;
    }
    $n = intval($raw);
    return ($n > 0) ? $n : 0;
}

$tplanId = ppcPositiveInt($_GET['tplan_id'] ?? null);
$assertedProjectId = ppcPositiveInt($_GET['tproject_id'] ?? null);

if ($tplanId <= 0) {
    ppcFail(400, 'invalid_request', 'Missing or invalid tplan_id');
}

// Order of operations (identical to api/prioritybarchart, the reviewed
// precedent):
//   1. resolve the plan -> opaque 404 when it does not exist or has no owner
//   2. check the right on the OWNING project
//   3. only then honour the client's tproject_id assertion
$tbl = tlObjectWithDB::getDBTables(array('testplans'));
$planRow = $db->get_recordset(
    "SELECT id, testproject_id FROM {$tbl['testplans']} WHERE id = " . intval($tplanId));

if (!is_array($planRow) || count($planRow) === 0) {
    ppcFail(404, 'tplan_not_found', 'Test plan not found');
}

$plan = $planRow[0];
$owningProjectId = intval($plan['testproject_id']);
if ($owningProjectId <= 0) {
    ppcFail(404, 'tplan_not_found', 'Test plan has no owning test project');
}

// Rights: testplan_metrics on the owning project + plan, getAccess = true.
if (!$user->hasRight($db, 'testplan_metrics', $owningProjectId, $tplanId, true)) {
    tLog('BFF platformpiechart: user ' . intval($userId) .
         ' has no testplan_metrics right on tproject ' . $owningProjectId .
         ' (tplan ' . $tplanId . ')', 'ERROR');
    ppcFail(403, 'no_right', 'testplan_metrics right required');
}

// tproject_id is a client-side ASSERTION only: it lets a stale deep link fail
// loudly instead of silently reporting another project, it never steers the read.
if ($assertedProjectId > 0 && $assertedProjectId !== $owningProjectId) {
    ppcFail(404, 'project_mismatch', 'Test plan belongs to another test project');
}

$planName = testplan::getName($db, $tplanId);
$projectName = testproject::getName($db, $owningProjectId);
if (is_null($planName) || trim((string)$planName) === '') {
    ppcFail(404, 'tplan_not_found', 'Test plan not found');
}

// Canonical display order for the status columns: the exec-UI family first
// (same ordering the stacked-bar siblings use), anything the installation
// configured on top appended alphabetically.
$metricsMgr = new tlTestPlanMetrics($db);
$verboseStatuses = $metricsMgr->getStatusForReports();
$canonical = array('passed', 'failed', 'blocked', 'not_run');
$statusOrder = array();
$known = array();
foreach ($canonical as $code) {
    if (in_array($code, $verboseStatuses, true)) {
        $statusOrder[] = $code;
        $known[] = $code;
    }
}
foreach ($verboseStatuses as $code) {
    if (!in_array($code, $known, true)) {
        $statusOrder[] = $code;
    }
}
unset($verboseStatuses);

// The same aggregation the legacy pie consumed. getStatusTotalsByPlatformForRender
// returns null when the plan has no builds/linked cases (issue #634 semantics) -
// that is the legitimate "nothing to chart" state, not an error.
$renderObj = null;
try {
    $renderObj = $metricsMgr->getStatusTotalsByPlatformForRender($tplanId);
} catch (Exception $e) {
    tLog('BFF platformpiechart: metrics aggregation failed for tplan ' .
         $tplanId . ': ' . $e->getMessage(), 'ERROR');
    ppcFail(500, 'internal_error', 'Metrics aggregation failed');
}
unset($metricsMgr);

$rows = array();
$totals = array('total_tc' => 0);
foreach ($statusOrder as $code) {
    $totals[$code] = 0;
}
$totals['percentage_completed'] = 0.0;

if (!is_null($renderObj) && isset($renderObj->info) && is_array($renderObj->info)) {
    foreach ($renderObj->info as $platformId => $info) {
        $platformId = intval($platformId);
        if ($platformId <= 0) {
            // platform_id 0 is the "no platform" pseudo-platform.
            continue;
        }
        $totalTc = intval($info['total_tc'] ?? 0);
        $details = array();
        $executed = 0;
        foreach ($statusOrder as $code) {
            $qty = 0;
            $percentage = 0.0;
            if (isset($info['details'][$code])) {
                $qty = intval($info['details'][$code]['qty'] ?? 0);
                $percentage = floatval($info['details'][$code]['percentage'] ?? 0);
            }
            $details[$code] = array('qty' => $qty, 'percentage' => $percentage);
            if ($code !== 'not_run') {
                $executed += $qty;
            }
        }
        $rows[] = array(
            'platform_id' => $platformId,
            'platform' => (string)($info['name'] ?? ('#' . $platformId)),
            'total_tc' => $totalTc,
            'executed' => $executed,
            'percentage_completed' => (float)($info['percentage_completed'] ?? 0),
            'details' => $details,
        );
        $totals['total_tc'] += $totalTc;
        foreach ($statusOrder as $code) {
            $totals[$code] += $details[$code]['qty'];
        }
    }
}

usort($rows, function ($a, $b) {
    if ($a['platform'] === $b['platform']) {
        return $a['platform_id'] - $b['platform_id'];
    }
    return strcasecmp($a['platform'], $b['platform']);
});

$notRunTotal = array_sum(
    array_map(function ($r) { return $r['details']['not_run']['qty'] ?? 0; }, $rows));
$totals['executed'] = $totals['total_tc'] - $notRunTotal;
if ($totals['total_tc'] > 0) {
    foreach ($statusOrder as $code) {
        $totals[$code . '_percent'] = round(($totals[$code] * 100) / $totals['total_tc'], 1);
    }
    $totals['percentage_completed'] = round(($totals['executed'] * 100) / $totals['total_tc'], 1);
} else {
    foreach ($statusOrder as $code) {
        $totals[$code . '_percent'] = 0.0;
    }
    $totals['percentage_completed'] = 0.0;
}

ppcOut(array(
    'status' => 'ok',
    'code' => 'ok',
    'context' => array(
        'tplan_id' => $tplanId,
        'tplan_name' => (string)$planName,
        'tproject_id' => $owningProjectId,
        'tproject_name' => (string)$projectName,
        'platforms' => count($rows),
        'tcversions' => $totals['total_tc'],
    ),
    'statuses' => $statusOrder,
    'platforms' => $rows,
    'totals' => $totals,
));