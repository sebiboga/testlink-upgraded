<?php
/**
 * Test Plan Execution Burn-down - REST BFF API
 * URL: /api/execburndown/index.php
 *
 * Net-new 2.0.1 report (Refs #1973). Every 1.9.20 report is a point-in-time
 * snapshot; the only time axis was the per-build strip of metricsTrends. This
 * BFF answers a plan-wide progress-over-time series so a team can see whether
 * the remaining-to-run line actually goes down.
 *
 * SEMANTICS
 *  - the universe ("planned") is the set of (test-case version, platform)
 *    assignment rows of the plan (testplan_tcversions), the same universe the
 *    execution screens use;
 *  - an execution only counts when its (tcversion, platform) pair is still
 *    assigned to the plan;
 *  - "executed" / "cumulative" is the number of DISTINCT (tcversion, platform)
 *    pairs with at least one recorded execution up to and including that day,
 *    so re-running the same test case does not move the burn-down twice;
 *  - "executions" is the raw number of execution rows recorded that day
 *    (re-runs included); "new_cases" is the number of pairs executed for the
 *    first time that day;
 *  - an optional build_id scopes the executions (not the planned universe);
 *  - the window is the last N days (1..365, default 30) ending today; the
 *    cumulative baseline still counts executions recorded before the window.
 *
 * Contract: GET|HEAD ?action=init&tplan_id=N[&tproject_id=P]
 *           GET|HEAD ?action=report&tplan_id=N[&tproject_id=P][&build_id=B][&days=D]
 *   200 {status, code, context, days:[...], totals, statuses}
 *   400 invalid_request | 401 not_authenticated / session_expired
 *   403 no_right | 404 tplan_not_found / project_mismatch | 405 method_not_allowed
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
require_once('testplan.class.php');
require_once('testproject.class.php');

doSessionStart();

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    ebdFail(405, 'method_not_allowed');
}

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function ebdOut($payload, $code = null)
{
    if ($code !== null) {
        http_response_code($code);
    }
    echo json_encode($payload);
    exit;
}

function ebdFail($httpCode, $code, $message = '')
{
    ebdOut(array(
        'status' => 'error',
        'code' => $code,
        'message' => $message !== '' ? $message : $code,
    ), $httpCode);
}

function ebdPositiveInt($raw)
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

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    ebdFail(401, 'not_authenticated', 'Not authenticated');
}

bffEnforceSession($db);

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    ebdFail(401, 'not_authenticated', 'User not found');
}

$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if ($action !== 'init' && $action !== 'report') {
    ebdFail(400, 'invalid_request', 'Unknown action');
}

$tplanId = ebdPositiveInt($_GET['tplan_id'] ?? null);
$assertedProjectId = ebdPositiveInt($_GET['tproject_id'] ?? null);
$buildId = ebdPositiveInt($_GET['build_id'] ?? null);
$days = ebdPositiveInt($_GET['days'] ?? null);
if ($days <= 0) {
    $days = 30;
}
if ($days > 365) {
    $days = 365;
}

if ($tplanId <= 0) {
    ebdFail(400, 'invalid_request', 'Missing or invalid tplan_id');
}

$tbl = tlObjectWithDB::getDBTables(array('testplans'));
$planRow = $db->get_recordset(
    "SELECT id, testproject_id FROM {$tbl['testplans']} WHERE id = " . intval($tplanId));

if (!is_array($planRow) || count($planRow) === 0) {
    ebdFail(404, 'tplan_not_found', 'Test plan not found');
}

$plan = $planRow[0];
$owningProjectId = intval($plan['testproject_id']);
if ($owningProjectId <= 0) {
    ebdFail(404, 'tplan_not_found', 'Test plan has no owning test project');
}

if (!$user->hasRight($db, 'testplan_metrics', $owningProjectId, $tplanId, true)) {
    tLog('BFF execburndown: user ' . intval($userId) .
         ' has no testplan_metrics right on tproject ' . $owningProjectId .
         ' (tplan ' . $tplanId . ')', 'ERROR');
    ebdFail(403, 'no_right', 'testplan_metrics right required');
}

if ($assertedProjectId > 0 && $assertedProjectId !== $owningProjectId) {
    ebdFail(404, 'project_mismatch', 'Test plan belongs to another test project');
}

$planName = testplan::getName($db, $tplanId);
$projectName = testproject::getName($db, $owningProjectId);
if (is_null($planName) || trim((string)$planName) === '') {
    ebdFail(404, 'tplan_not_found', 'Test plan not found');
}

$t = tlObjectWithDB::getDBTables(
    array('executions', 'testplan_tcversions', 'builds', 'testplans'));
$ex = $t['executions'];
$tpv = $t['testplan_tcversions'];
$bld = $t['builds'];
$tpl = $t['testplans'];
$tp = intval($tplanId);

$buildRows = $db->get_recordset(
    "SELECT id, name, is_open, release_date FROM {$bld} " .
    "WHERE testproject_id = " . intval($owningProjectId) . " ORDER BY id DESC");
if (!is_array($buildRows)) {
    $buildRows = array();
}
$builds = array();
$buildIds = array();
foreach ($buildRows as $b) {
    $bid = intval($b['id']);
    $buildIds[$bid] = true;
    $builds[] = array(
        'id' => $bid,
        'name' => (string)$b['name'],
        'is_open' => intval($b['is_open']) === 1,
        'release_date' => is_null($b['release_date']) ? null : (string)$b['release_date'],
    );
}

if ($buildId > 0 && !isset($buildIds[$buildId])) {
    ebdFail(404, 'tplan_not_found', 'Build does not belong to the owning test project');
}

$plannedRow = $db->get_recordset(
    "SELECT COUNT(*) AS qty FROM {$tpv} WHERE testplan_id = {$tp}");
$planned = 0;
if (is_array($plannedRow) && count($plannedRow) > 0) {
    $planned = intval($plannedRow[0]['qty']);
}

$context = array(
    'tplan_id' => $tplanId,
    'tplan_name' => (string)$planName,
    'tproject_id' => $owningProjectId,
    'tproject_name' => (string)$projectName,
    'planned' => $planned,
    'builds' => $builds,
    'build_id' => $buildId,
    'days' => $days,
    'has_executions' => false,
);

if ($action === 'init') {
    $firstRow = $db->get_recordset(
        "SELECT MIN(execution_ts) AS first_ts, MAX(execution_ts) AS last_ts " .
        "FROM {$ex} WHERE testplan_id = {$tp}");
    $firstTs = null;
    $lastTs = null;
    if (is_array($firstRow) && count($firstRow) > 0) {
        $firstTs = $firstRow[0]['first_ts'];
        $lastTs = $firstRow[0]['last_ts'];
    }
    $context['first_execution'] = is_null($firstTs) ? null : (string)$firstTs;
    $context['last_execution'] = is_null($lastTs) ? null : (string)$lastTs;
    $context['has_executions'] = !is_null($firstTs);

    ebdOut(array(
        'status' => 'ok',
        'code' => 200,
        'context' => $context,
    ));
}

// ---------------------------------------------------------------------------
// report: aggregate executions per day + first-execution day per assignment.
// ---------------------------------------------------------------------------
$buildFilter = ($buildId > 0) ? " AND e.build_id = " . intval($buildId) : '';
$sql =
  "SELECT e.id, e.execution_ts, e.status, e.tcversion_id, e.platform_id " .
  "FROM {$ex} e " .
  "WHERE e.testplan_id = {$tp} " . $buildFilter .
  "  AND e.execution_ts IS NOT NULL " .
  "  AND EXISTS (SELECT 1 FROM {$tpv} t WHERE t.testplan_id = {$tp} " .
  "              AND t.tcversion_id = e.tcversion_id " .
  "              AND t.platform_id = e.platform_id) " .
  "ORDER BY e.execution_ts, e.id";

$rows = $db->get_recordset($sql);
if (!is_array($rows)) {
    $rows = array();
}

$perDay = array();
$firstDay = array();
$totals = array(
    'executions' => 0,
    'passed' => 0,
    'failed' => 0,
    'blocked' => 0,
    'new_cases' => 0,
);

foreach ($rows as $r) {
    $ts = (string)$r['execution_ts'];
    $day = substr($ts, 0, 10);
    if ($day === '') {
        continue;
    }
    $status = (string)$r['status'];
    $bucket = ($status === 'p') ? 'passed' : (($status === 'f') ? 'failed' : (($status === 'b') ? 'blocked' : null));

    if (!isset($perDay[$day])) {
        $perDay[$day] = array(
            'executions' => 0,
            'passed' => 0,
            'failed' => 0,
            'blocked' => 0,
            'new_cases' => 0,
        );
    }
    $perDay[$day]['executions']++;
    $totals['executions']++;
    if ($bucket !== null) {
        $perDay[$day][$bucket]++;
        $totals[$bucket]++;
    }

    $pair = intval($r['tcversion_id']) . ':' . intval($r['platform_id']);
    if (!isset($firstDay[$pair])) {
        $firstDay[$pair] = $day;
    }
}

// Count how many assignments were first executed on each day.
$newPerDay = array();
foreach ($firstDay as $pair => $day) {
    if (!isset($newPerDay[$day])) {
        $newPerDay[$day] = 0;
    }
    $newPerDay[$day]++;
    $totals['new_cases']++;
}

$tz = new DateTimeZone(date_default_timezone_get());
$today = new DateTime('today', $tz);
$start = (clone $today)->modify('-' . ($days - 1) . ' days');

// Sorted list of first-execution days for the cumulative baseline.
$firstDaysSorted = array_values($firstDay);
sort($firstDaysSorted, SORT_STRING);

$cumulative = 0;
$firstIdx = 0;
$firstCount = count($firstDaysSorted);
$windowStartStr = $start->format('Y-m-d');

// Baseline: everything first executed strictly before the window.
while ($firstIdx < $firstCount && $firstDaysSorted[$firstIdx] < $windowStartStr) {
    $cumulative++;
    $firstIdx++;
}

$outDays = array();
$cursor = clone $start;
while ($cursor <= $today) {
    $day = $cursor->format('Y-m-d');

    while ($firstIdx < $firstCount && $firstDaysSorted[$firstIdx] <= $day) {
        $cumulative++;
        $firstIdx++;
    }

    $d = isset($perDay[$day]) ? $perDay[$day] : array(
        'executions' => 0, 'passed' => 0, 'failed' => 0, 'blocked' => 0, 'new_cases' => 0);
    $d['new_cases'] = isset($newPerDay[$day]) ? $newPerDay[$day] : 0;

    $remaining = $planned - $cumulative;
    if ($remaining < 0) {
        $remaining = 0;
    }
    $percent = ($planned > 0) ? round(100.0 * $cumulative / $planned, 1) : 0;

    $outDays[] = array(
        'date' => $day,
        'executions' => $d['executions'],
        'passed' => $d['passed'],
        'failed' => $d['failed'],
        'blocked' => $d['blocked'],
        'new_cases' => $d['new_cases'],
        'cumulative' => $cumulative,
        'remaining' => $remaining,
        'percent' => $percent,
    );

    $cursor->modify('+1 day');
}

if (count($rows) === 0) {
    // Nothing was recorded in this scope: let the screen show its empty state
    // instead of a flat zero chart that looks like a broken report.
    $outDays = array();
}

$executed = count($firstDay);
$remainingTotal = $planned - $executed;
if ($remainingTotal < 0) {
    $remainingTotal = 0;
}
$totals['planned'] = $planned;
$totals['executed'] = $executed;
$totals['remaining'] = $remainingTotal;
$totals['percent'] = ($planned > 0) ? round(100.0 * $executed / $planned, 1) : 0;

$resultsCfg = config_get('results');
$statusDomain = array('passed' => 'p', 'failed' => 'f', 'blocked' => 'b');
$statuses = array();
foreach ($statusDomain as $status => $code) {
    $statuses[] = array(
        'code' => $status,
        'label' => lang_get($resultsCfg['status_label'][$status] ?? ('test_status_' . $status)),
        'colour' => (string)($resultsCfg['charts']['status_colour'][$status] ?? '999999'),
    );
}

$context['has_executions'] = (count($rows) > 0);
$context['window_start'] = $windowStartStr;
$context['window_end'] = $today->format('Y-m-d');

ebdOut(array(
    'status' => 'ok',
    'code' => 200,
    'context' => $context,
    'days' => $outDays,
    'totals' => $totals,
    'statuses' => $statuses,
));
