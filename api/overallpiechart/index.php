<?php
/**
 * Overall Metrics Pie Chart - REST BFF API
 * URL: /api/overallpiechart/index.php
 * Plain PHP, no framework, no compilation
 *
 * Modern twin of lib/results/overallPieChart.php (TestLink 1.9.20).
 *
 * WHAT THE LEGACY FILE DID
 * ------------------------
 * The 1.9.20 file was an orphan BYTE endpoint: it never rendered a Smarty
 * template, it included the vendored pChart library (third_party/pchart) and
 * answered with a PNG of a PIE chart of the plan-wide execution-status
 * distribution. The data came from tlTestPlanMetrics::getExecCountersByExecStatus()
 * (latest executions per test-case version + platform, across all builds), one
 * slice per status bucket (passed / failed / blocked / not run / unknown).
 *
 * WHY IT NEEDED A MODERN TWIN
 * ---------------------------
 *  - the pChart render path is retired from the modernized Charts screen
 *    (charts.html renders Chart.js from api/reports charts_data);
 *  - charts_data DOES return an overall aggregate for the Chart.js canvas, but
 *    it exposes no standalone numbers/CSV report - unlike its four siblings
 *    Metrics by Platform (platformPieChart.html), Results by Priority
 *    (priorityBarChart.html, #1845), Results by Keyword (keywordBarChart.html,
 *    #1906) and Results by Top-Level Suite (topLevelSuitesBarChart.html, #1905);
 *  - the legacy endpoint's only gate was hasRight('testplan_metrics', ...)
 *    evaluated against the GLOBAL right with no ownership proof: the owning
 *    project is now derived from the plan and the right checked on it
 *    (getAccess = true), and tproject_id is a client-side assertion only.
 *
 * SEMANTICS OF THE AGGREGATE (kept identical to the legacy pie):
 *  - the unit is the test-case VERSION assigned to the test plan tracked per
 *    platform; the bucket is the LATEST recorded execution status across all
 *    builds (the same tlTestPlanMetrics helper the legacy chart consumed);
 *  - unknownCodes (a status code stored in executions.status that config
 *    results.status_code no longer defines) are aggregated on the explicit
 *    'unknown' bucket, exactly as the 2.0.1-stable helper does (#1698);
 *  - a plan with no builds (helper returns null, legacy issue #634) answers
 *    {context, items: [], totals: {...}} with HTTP 200 and can_draw = false -
 *    the legacy returned a blank image for that case;
 *  - percentage per bucket = qty / total (total = sum of every bucket,
 *    not_run included), first decimal.
 *
 * Rights gate: the plan is resolved first (404 tplan_not_found when it does
 * not exist), then testplan_metrics is checked on the OWNING test project AND
 * test plan with getAccess = true (legacy pageAccessCheck semantics: a right
 * held only globally no longer reaches a private test project -> 403). This
 * mirrors the accepted sibling api/keywordbarchart.
 *
 * Contract: GET|HEAD ?action=init&tplan_id=N[&tproject_id=P]
 *   200 {status, code, context:{tplan_id,tplan_name,tproject_id,tproject_name,can_draw},
 *        items:[{code,label,colour,qty,percentage}],
 *        totals:{total,executed,passed,failed,blocked,not_run,unknown}}
 *   400 invalid_request | 401 not_authenticated / session_expired
 *   403 no_right | 404 tplan_not_found / project_mismatch | 405 method_not_allowed
 *
 * Refs #1907
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
require_once('testplan.class.php');
require_once('testproject.class.php');

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
function opcOut($payload, $code = null)
{
    if ($code !== null) {
        http_response_code($code);
    }
    echo json_encode($payload);
    exit;
}

function opcFail($httpCode, $code, $message = '')
{
    opcOut(array(
        'status' => 'error',
        'code' => $code,
        'message' => $message !== '' ? $message : $code,
    ), $httpCode);
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    opcFail(405, 'method_not_allowed');
}

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    opcFail(401, 'not_authenticated', 'Not authenticated');
}

bffEnforceSession($db);

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    opcFail(401, 'not_authenticated', 'User not found');
}

$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if ($action !== 'init') {
    opcFail(400, 'invalid_request', 'Unknown action');
}

/** Strict positive integer reader - "1abc", "1.9", "-3" and "" are all refused. */
function opcPositiveInt($raw)
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

$tplanId = opcPositiveInt($_GET['tplan_id'] ?? null);
$assertedProjectId = opcPositiveInt($_GET['tproject_id'] ?? null);

if ($tplanId <= 0) {
    opcFail(400, 'invalid_request', 'Missing or invalid tplan_id');
}

// Order of operations (identical to api/keywordbarchart #1906):
//   1. resolve the plan -> opaque 404 when it does not exist or has no owner
//   2. check the right on the OWNING project (getAccess = true)
//   3. only then honour the client's tproject_id assertion
$tbl = tlObjectWithDB::getDBTables(array('testplans'));
$planRow = $db->get_recordset(
    "SELECT id, testproject_id FROM {$tbl['testplans']} WHERE id = " . intval($tplanId));

if (!is_array($planRow) || count($planRow) === 0) {
    opcFail(404, 'tplan_not_found', 'Test plan not found');
}

$plan = $planRow[0];
$owningProjectId = intval($plan['testproject_id']);
if ($owningProjectId <= 0) {
    opcFail(404, 'tplan_not_found', 'Test plan has no owning test project');
}

if (!$user->hasRight($db, 'testplan_metrics', $owningProjectId, $tplanId, true)) {
    tLog('BFF overallpiechart: user ' . intval($userId) .
         ' has no testplan_metrics right on tproject ' . $owningProjectId .
         ' (tplan ' . $tplanId . ')', 'ERROR');
    opcFail(403, 'no_right', 'testplan_metrics right required');
}

if ($assertedProjectId > 0 && $assertedProjectId !== $owningProjectId) {
    opcFail(404, 'project_mismatch', 'Test plan belongs to another test project');
}

$planName = testplan::getName($db, $tplanId);
$projectName = testproject::getName($db, $owningProjectId);
if (is_null($planName) || trim((string)$planName) === '') {
    opcFail(404, 'tplan_not_found', 'Test plan not found');
}

// ---------------------------------------------------------------------------
// Data: the EXACT legacy helper (tlTestPlanMetrics, 2.0.1-stable).
// ---------------------------------------------------------------------------
$resultsCfg = config_get('results');
$metricsMgr = new tlTestPlanMetrics($db);

$counters = $metricsMgr->getExecCountersByExecStatus($tplanId);
$canDraw = !is_null($counters) && count($counters) > 0;

// Status buckets this chart draws: the legacy UI order first, then any other
// verbose status the helper actually returned (e.g. 'not_available', or a code
// of a customized status map). The legacy pie iterated EVERY key
// getExecCountersByExecStatus() returned and api/reports charts_data still
// does; a hardcoded domain would silently drop such a bucket from items while
// its qty still inflated totals.total (the displayed total would disagree with
// the sum of the rows). 'total' is not a drawable bucket.
$knownDomain = array('passed', 'failed', 'blocked', 'not_run', 'unknown');
$extraDomain = $canDraw
    ? array_values(array_diff(array_keys($counters), array_merge($knownDomain, array('total'))))
    : array();
$statusDomain = array_merge($knownDomain, $extraDomain);

$items = array();
$totals = array('total' => 0, 'executed' => 0);
foreach ($statusDomain as $status) {
    $totals[$status] = 0;
}

if ($canDraw) {
    // total is the sum of every bucket, not_run included (the helper counts it).
    $totals['total'] = intval($counters['total'] ?? 0);

    foreach ($statusDomain as $status) {
        $totals[$status] = intval($counters[$status] ?? 0);
    }
    // Every bucket except 'not_run' counts as executed (the helper's 'unknown'
    // and any extra verbose buckets included) - identical to the sibling
    // reports' semantics, and equal to passed+failed+blocked on stock installs.
    $totals['executed'] = $totals['total'] - $totals['not_run'];

    foreach ($statusDomain as $status) {
        // The legacy pie iterated over whatever buckets were present; the
        // 'unknown' bucket only exists when the helper actually saw such a code,
        // so it stays hidden unless it carries a count.
        if ($status === 'unknown' && $totals[$status] <= 0) {
            continue;
        }
        $qty = $totals[$status];
        $items[] = array(
            'code' => $status,
            'label' => lang_get($resultsCfg['status_label'][$status] ?? ('test_status_' . $status)),
            'colour' => (string)($resultsCfg['charts']['status_colour'][$status] ?? '999999'),
            'qty' => $qty,
            'percentage' => ($totals['total'] > 0)
                ? round(100.0 * $qty / $totals['total'], 1)
                : 0,
        );
    }
}

opcOut(array(
    'status' => 'ok',
    'code' => 200,
    'context' => array(
        'tplan_id' => $tplanId,
        'tplan_name' => (string)$planName,
        'tproject_id' => $owningProjectId,
        'tproject_name' => (string)$projectName,
        'can_draw' => $canDraw,
    ),
    'items' => $items,
    'totals' => $totals,
));
