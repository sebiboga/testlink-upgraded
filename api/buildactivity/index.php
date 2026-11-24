<?php
/**
 * Test Plan Build Execution Activity report - BFF (Refs #2056).
 *
 * Net-new read-only 2.0.1 report (gui/templates/results/buildActivity.html).
 * buildTrend reports the per-build LATEST-STATUS snapshot and its quality
 * delta; executionTimeline lists chronological executions. Neither answers
 * "when was each build actually exercised, by whom and with which result mix?".
 * This report groups ALL execution events of one plan by build: result mix,
 * distinct cases / testers / defects, duration, active days, last-7-day
 * activity, idle gap and a busy/watch/idle verdict, plus a daily activity
 * series and a per-build execution detail list.
 *
 * Actions (GET|HEAD):
 *   ?action=init&tplan_id=N[&tproject_id=P]
 *   ?action=report&tplan_id=N[&tproject_id=P][&platform_id=PL][&build_id=B][&status=p|f|b][&window_days=0|N]
 *   ?action=detail&tplan_id=N&build_id=B[...]
 *
 * Security: session auth + bffSameOriginGuard + bffEnforceSession; the
 * `testplan_metrics` right is checked on the OWNING project before the client
 * project assertion (no existence oracle). Denials are logged at AUDIT.
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

function baOut($payload, $httpCode = null)
{
    if ($httpCode !== null) {
        http_response_code($httpCode);
    }
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function baFail($httpCode, $code, $message = '')
{
    baOut(array(
        'status' => 'error',
        'code' => $code,
        'message' => $message !== '' ? $message : $code,
    ), $httpCode);
}

function baPositiveInt($raw)
{
    if ($raw === null || is_array($raw)) {
        return 0;
    }
    $raw = trim((string)$raw);
    if ($raw === '' || !preg_match('/^[0-9]+$/', $raw)) {
        return 0;
    }
    $n = intval($raw);
    return $n > 0 ? $n : 0;
}

function baDecode($value)
{
    return html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function baRound($value, $decimals = 1)
{
    return round((float)$value, $decimals);
}

function baPct($num, $den)
{
    return $den > 0 ? baRound(100.0 * $num / $den, 1) : 0.0;
}

function baStatus($raw)
{
    $s = (string)$raw;
    return ($s === 'p' || $s === 'f' || $s === 'b') ? $s : 'n';
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    baFail(405, 'method_not_allowed', 'Only GET and HEAD are supported');
}

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    baFail(401, 'not_authenticated', 'Not authenticated');
}
bffEnforceSession($db);
$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    baFail(401, 'not_authenticated', 'User not found');
}

$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if (!in_array($action, array('init', 'report', 'detail'), true)) {
    baFail(400, 'invalid_request', 'Unknown action');
}

$tplanId = baPositiveInt($_GET['tplan_id'] ?? null);
$assertedProjectId = baPositiveInt($_GET['tproject_id'] ?? null);
$platformId = baPositiveInt($_GET['platform_id'] ?? null);
$buildFilter = baPositiveInt($_GET['build_id'] ?? null);
$statusFilter = strtolower(trim((string)($_GET['status'] ?? 'all')));
if (!in_array($statusFilter, array('all', 'p', 'f', 'b'), true)) {
    baFail(400, 'invalid_request', 'Invalid status filter');
}
$windowDaysRaw = ($_GET['window_days'] ?? '0');
if (is_array($windowDaysRaw) || !preg_match('/^[0-9]+$/', trim((string)$windowDaysRaw))) {
    baFail(400, 'invalid_request', 'Invalid window_days');
}
$windowDays = intval($windowDaysRaw);
if ($windowDays > 3650) {
    baFail(400, 'invalid_request', 'window_days is too large');
}

if (isset($_GET['platform_id']) && $platformId <= 0) {
    baFail(400, 'invalid_request', 'Invalid platform_id');
}
if (isset($_GET['build_id']) && $buildFilter <= 0) {
    baFail(400, 'invalid_request', 'Invalid build_id');
}
if ($tplanId <= 0) {
    baFail(400, 'invalid_request', 'Missing or invalid tplan_id');
}

$tbl = tlObjectWithDB::getDBTables(array(
    'testplans', 'builds', 'platforms', 'testplan_tcversions', 'tcversions',
    'nodes_hierarchy', 'executions', 'execution_bugs', 'testprojects',
));

$planRow = $db->get_recordset(
    "SELECT id, testproject_id FROM {$tbl['testplans']} WHERE id = " . intval($tplanId));
if (!is_array($planRow) || count($planRow) === 0) {
    baFail(404, 'tplan_not_found', 'Test plan not found');
}
$owningProjectId = intval($planRow[0]['testproject_id']);
if ($owningProjectId <= 0) {
    baFail(404, 'tplan_not_found', 'Test plan has no owning test project');
}
if (!$user->hasRight($db, 'testplan_metrics', $owningProjectId, $tplanId, true)) {
    tLog('BFF buildactivity: user ' . intval($userId) .
         ' has no testplan_metrics right on tproject ' . $owningProjectId .
         ' (tplan ' . $tplanId . ')', 'AUDIT');
    baFail(403, 'no_right', 'testplan_metrics right required');
}
if ($assertedProjectId > 0 && $assertedProjectId !== $owningProjectId) {
    baFail(404, 'project_mismatch', 'Test plan belongs to another test project');
}

$planName = testplan::getName($db, $tplanId);
$projectName = testproject::getName($db, $owningProjectId);
if (is_null($planName) || trim((string)$planName) === '') {
    baFail(404, 'tplan_not_found', 'Test plan not found');
}

$builds = array();
$buildIds = array();
$rsBuilds = $db->get_recordset(
    "SELECT id, name, active, is_open, creation_ts FROM {$tbl['builds']} " .
    "WHERE testproject_id = " . intval($owningProjectId) . " ORDER BY id");
if (is_array($rsBuilds)) {
    foreach ($rsBuilds as $r) {
        $bid = intval($r['id']);
        $builds[$bid] = array(
            'id' => $bid,
            'name' => baDecode($r['name']),
            'active' => (intval($r['active']) === 1),
            'is_open' => (intval($r['is_open']) === 1),
            'creation_ts' => (string)$r['creation_ts'],
        );
        $buildIds[] = $bid;
    }
}
if ($buildFilter > 0 && !isset($builds[$buildFilter])) {
    baFail(404, 'build_not_found', 'Build does not belong to the test project');
}

$platforms = array();
$rsPlatforms = $db->get_recordset(
    "SELECT id, name FROM {$tbl['platforms']} WHERE testproject_id = " .
    intval($owningProjectId) . " ORDER BY name");
if (is_array($rsPlatforms)) {
    foreach ($rsPlatforms as $r) {
        $platforms[] = array('id' => intval($r['id']), 'name' => baDecode($r['name']));
    }
}
if ($platformId > 0) {
    $known = false;
    foreach ($platforms as $p) {
        if ($p['id'] === $platformId) { $known = true; break; }
    }
    if (!$known) {
        baFail(400, 'invalid_request', 'platform_id does not belong to this test project');
    }
}

$assigned = array();
$rsAssigned = $db->get_recordset(
    "SELECT DISTINCT tcversion_id FROM {$tbl['testplan_tcversions']} " .
    "WHERE testplan_id = " . intval($tplanId));
if (is_array($rsAssigned)) {
    foreach ($rsAssigned as $r) {
        $assigned[intval($r['tcversion_id'])] = true;
    }
}

$context = array(
    'tplan_id' => $tplanId,
    'tplan_name' => (string)$planName,
    'tproject_id' => $owningProjectId,
    'tproject_name' => (string)$projectName,
    'assigned_versions' => count($assigned),
);
$filters = array(
    'platforms' => $platforms,
    'builds' => array_values($builds),
    'statuses' => array(
        array('id' => 'all', 'label_key' => 'bact.statusAll'),
        array('id' => 'p', 'label_key' => 'bact.statusPassed'),
        array('id' => 'f', 'label_key' => 'bact.statusFailed'),
        array('id' => 'b', 'label_key' => 'bact.statusBlocked'),
    ),
    'windows' => array(
        array('days' => 7, 'label_key' => 'bact.window7'),
        array('days' => 30, 'label_key' => 'bact.window30'),
        array('days' => 90, 'label_key' => 'bact.window90'),
        array('days' => 365, 'label_key' => 'bact.window365'),
    ),
);
$filterState = array(
    'platform_id' => $platformId,
    'build_id' => $buildFilter,
    'status' => $statusFilter,
    'window_days' => $windowDays,
);

if ($action === 'init') {
    baOut(array(
        'status' => 'ok', 'code' => 200,
        'context' => $context, 'filters' => $filters, 'filter' => $filterState,
    ));
}

// Defect links keyed by execution id (distinct bug references per execution).
$defectsByExecution = array();
$rsBugs = $db->get_recordset(
    "SELECT eb.execution_id, eb.bug_id FROM {$tbl['execution_bugs']} eb " .
    "INNER JOIN {$tbl['executions']} e ON e.id = eb.execution_id " .
    "WHERE e.testplan_id = " . intval($tplanId));
if (is_array($rsBugs)) {
    foreach ($rsBugs as $r) {
        $eid = intval($r['execution_id']);
        $defectsByExecution[$eid][trim((string)$r['bug_id'])] = true;
    }
}

$cutoff = $windowDays > 0 ? date('Y-m-d H:i:s', time() - $windowDays * 86400) : '';
$allExecutions = array();
$rsExec = $db->get_recordset(
    "SELECT id, build_id, tester_id, execution_ts, status, tcversion_id, " .
    "platform_id, execution_duration, notes " .
    "FROM {$tbl['executions']} WHERE testplan_id = " . intval($tplanId) .
    " ORDER BY id");
if (is_array($rsExec)) {
    foreach ($rsExec as $r) {
        $bid = intval($r['build_id']);
        $status = baStatus($r['status']);
        $ts = (string)$r['execution_ts'];
        $tcv = intval($r['tcversion_id']);
        if (!isset($assigned[$tcv])) { continue; }
        if ($platformId > 0 && intval($r['platform_id']) !== $platformId) { continue; }
        if ($buildFilter > 0 && $bid !== $buildFilter) { continue; }
        if ($statusFilter !== 'all' && $status !== $statusFilter) { continue; }
        if ($cutoff !== '' && $ts !== '' && strcmp($ts, $cutoff) < 0) { continue; }
        $allExecutions[] = array(
            'id' => intval($r['id']),
            'build_id' => $bid,
            'tester_id' => intval($r['tester_id'] ?? 0),
            'execution_ts' => $ts,
            'status' => $status,
            'tcversion_id' => $tcv,
            'platform_id' => intval($r['platform_id']),
            'duration' => (float)($r['execution_duration'] ?? 0),
            'notes' => baDecode($r['notes'] ?? ''),
            'bugs' => isset($defectsByExecution[intval($r['id'])])
                ? array_keys($defectsByExecution[intval($r['id'])]) : array(),
        );
    }
}

// Aggregate per build. Every project build gets a row so empty builds stay visible.
$stats = array();
foreach ($buildIds as $bid) {
    $stats[$bid] = array(
        'executions' => 0, 'passed' => 0, 'failed' => 0, 'blocked' => 0,
        'cases' => array(), 'testers' => array(), 'bugs' => array(),
        'days' => array(), 'duration_sum' => 0.0, 'duration_count' => 0,
        'first_ts' => '', 'last_ts' => '', 'recent' => 0,
    );
}
foreach ($allExecutions as $e) {
    $bid = $e['build_id'];
    if (!isset($stats[$bid])) {
        $stats[$bid] = array(
            'executions' => 0, 'passed' => 0, 'failed' => 0, 'blocked' => 0,
            'cases' => array(), 'testers' => array(), 'bugs' => array(),
            'days' => array(), 'duration_sum' => 0.0, 'duration_count' => 0,
            'first_ts' => '', 'last_ts' => '', 'recent' => 0,
        );
    }
    $s = &$stats[$bid];
    $s['executions']++;
    if ($e['status'] === 'p') { $s['passed']++; }
    elseif ($e['status'] === 'f') { $s['failed']++; }
    elseif ($e['status'] === 'b') { $s['blocked']++; }
    $s['cases'][$e['tcversion_id']] = true;
    if ($e['tester_id'] > 0) { $s['testers'][$e['tester_id']] = true; }
    foreach ($e['bugs'] as $bug) { $s['bugs'][$bug] = true; }
    $day = substr($e['execution_ts'], 0, 10);
    if ($day !== '') { $s['days'][$day] = true; }
    if ($e['duration'] > 0) { $s['duration_sum'] += $e['duration']; $s['duration_count']++; }
    if ($s['first_ts'] === '' || strcmp($e['execution_ts'], $s['first_ts']) < 0) {
        $s['first_ts'] = $e['execution_ts'];
    }
    if ($s['last_ts'] === '' || strcmp($e['execution_ts'], $s['last_ts']) > 0) {
        $s['last_ts'] = $e['execution_ts'];
    }
    if (strcmp($e['execution_ts'], date('Y-m-d H:i:s', time() - 7 * 86400)) >= 0) {
        $s['recent']++;
    }
    unset($s);
}

$now = time();
$rows = array();
$activeBuilds = 0;
$totalExecutions = 0;
$totalBugs = array();
$allTesters = array();
$busiest = null;
$lastActivity = '';
foreach ($buildIds as $bid) {
    $s = $stats[$bid];
    $exec = intval($s['executions']);
    $totalExecutions += $exec;
    foreach ($s['bugs'] as $bug => $_) { $totalBugs[$bug] = true; }
    foreach ($s['testers'] as $tester => $_) { $allTesters[$tester] = true; }
    if ($exec > 0) {
        $activeBuilds++;
        if ($busiest === null || $exec > $busiest['executions']) {
            $busiest = array('id' => $bid, 'name' => $builds[$bid]['name'], 'executions' => $exec);
        }
    }
    if ($s['last_ts'] !== '' && strcmp($s['last_ts'], $lastActivity) > 0) {
        $lastActivity = $s['last_ts'];
    }
    $idleDays = $s['last_ts'] !== ''
        ? max(0, (int)floor(($now - strtotime($s['last_ts'])) / 86400)) : null;
    $verdict = 'idle';
    if ($exec > 0) {
        $verdict = $s['recent'] > 0 ? 'busy' : 'watch';
    }
    $rows[] = array(
        'build_id' => $bid,
        'build_name' => $builds[$bid]['name'],
        'active' => $builds[$bid]['active'],
        'is_open' => $builds[$bid]['is_open'],
        'executions' => $exec,
        'cases' => count($s['cases']),
        'passed' => $s['passed'],
        'failed' => $s['failed'],
        'blocked' => $s['blocked'],
        'pass_rate_pct' => baPct($s['passed'], $exec),
        'testers' => count($s['testers']),
        'defects' => count($s['bugs']),
        'active_days' => count($s['days']),
        'avg_duration' => $s['duration_count'] > 0
            ? baRound($s['duration_sum'] / $s['duration_count'], 1) : null,
        'total_duration' => baRound($s['duration_sum'], 1),
        'recent' => $s['recent'],
        'first_ts' => $s['first_ts'],
        'last_ts' => $s['last_ts'],
        'idle_days' => $idleDays,
        'verdict' => $verdict,
    );
}

// Deleted builds referenced by executions are appended so history is not lost.
$orphanStats = array();
foreach ($allExecutions as $e) {
    $bid = $e['build_id'];
    if (isset($stats[$bid])) { continue; }
    if (!isset($orphanStats[$bid])) {
        $orphanStats[$bid] = array('executions' => 0, 'passed' => 0, 'failed' => 0,
            'blocked' => 0, 'last_ts' => '');
    }
    $orphanStats[$bid]['executions']++;
    if ($e['status'] === 'p') { $orphanStats[$bid]['passed']++; }
    elseif ($e['status'] === 'f') { $orphanStats[$bid]['failed']++; }
    elseif ($e['status'] === 'b') { $orphanStats[$bid]['blocked']++; }
    if (strcmp($e['execution_ts'], $orphanStats[$bid]['last_ts']) > 0) {
        $orphanStats[$bid]['last_ts'] = $e['execution_ts'];
    }
}
foreach ($orphanStats as $bid => $s) {
    $rows[] = array(
        'build_id' => intval($bid), 'build_name' => '#' . intval($bid),
        'active' => false, 'is_open' => false, 'executions' => $s['executions'],
        'cases' => 0, 'passed' => $s['passed'], 'failed' => $s['failed'],
        'blocked' => $s['blocked'],
        'pass_rate_pct' => baPct($s['passed'], $s['executions']),
        'testers' => 0, 'defects' => 0, 'active_days' => 0,
        'avg_duration' => null, 'total_duration' => 0.0, 'recent' => 0,
        'first_ts' => '', 'last_ts' => $s['last_ts'], 'idle_days' => null,
        'verdict' => 'watch',
    );
}

$allPassed = 0;
$allFailed = 0;
$allBlocked = 0;
$daily = array();
foreach ($allExecutions as $e) {
    if ($e['status'] === 'p') { $allPassed++; }
    elseif ($e['status'] === 'f') { $allFailed++; }
    elseif ($e['status'] === 'b') { $allBlocked++; }
    $day = substr($e['execution_ts'], 0, 10);
    if ($day === '') { continue; }
    if (!isset($daily[$day])) {
        $daily[$day] = array('day' => $day, 'executions' => 0,
            'passed' => 0, 'failed' => 0, 'blocked' => 0);
    }
    $daily[$day]['executions']++;
    if ($e['status'] === 'p') { $daily[$day]['passed']++; }
    elseif ($e['status'] === 'f') { $daily[$day]['failed']++; }
    elseif ($e['status'] === 'b') { $daily[$day]['blocked']++; }
}
ksort($daily);
$dailyRows = array_values($daily);

$verdict = 'idle';
if ($totalExecutions > 0) {
    $verdict = ($activeBuilds >= 2 && $lastActivity !== ''
        && strcmp($lastActivity, date('Y-m-d H:i:s', time() - 14 * 86400)) >= 0)
        ? 'active' : 'watch';
}
$summary = array(
    'builds' => count($rows),
    'active_builds' => $activeBuilds,
    'executions' => $totalExecutions,
    'passed' => $allPassed,
    'failed' => $allFailed,
    'blocked' => $allBlocked,
    'pass_rate_pct' => baPct($allPassed, $totalExecutions),
    'distinct_cases' => count($assigned),
    'testers' => count($allTesters),
    'defects' => count($totalBugs),
    'active_days' => count($dailyRows),
    'last_activity' => $lastActivity,
    'busiest_build' => $busiest,
    'verdict' => $verdict,
);

$payload = array(
    'status' => 'ok', 'code' => 200,
    'context' => $context, 'filters' => $filters, 'filter' => $filterState,
    'summary' => $summary, 'rows' => $rows, 'daily' => $dailyRows,
);

if ($action === 'detail') {
    $buildId = baPositiveInt($_GET['build_id'] ?? null);
    if ($buildId <= 0) {
        baFail(400, 'invalid_request', 'Missing or invalid build_id');
    }
    $detail = array();
    foreach ($allExecutions as $e) {
        if ($e['build_id'] !== $buildId) { continue; }
        $detail[] = array(
            'id' => $e['id'], 'tcversion_id' => $e['tcversion_id'],
            'status' => $e['status'], 'execution_ts' => $e['execution_ts'],
            'tester_id' => $e['tester_id'], 'platform_id' => $e['platform_id'],
            'duration' => $e['duration'],
            'notes' => mb_substr(strip_tags($e['notes']), 0, 240),
            'bugs' => $e['bugs'],
        );
    }
    if (count($detail) === 0 && !isset($stats[$buildId]) && !isset($orphanStats[$buildId])) {
        baFail(404, 'build_not_found', 'Build has no executions in this scope');
    }
    usort($detail, function ($a, $b) {
        return strcmp($b['execution_ts'], $a['execution_ts']);
    });
    $payload['detail'] = array_slice($detail, 0, 200);
    $payload['detail_total'] = count($detail);
    $payload['build_id'] = $buildId;
}

baOut($payload);
