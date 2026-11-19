<?php
/**
 * Test Plan Execution Timeline report - BFF (Refs #2005).
 *
 * Net-new read-only modern screen (gui/templates/results/executionTimeline.html).
 * TestLink 1.9.20 / 2.0.1 offer the execution history of ONE test case and
 * point-in-time plan reports, but no chronological view of the whole plan:
 * this report answers "what happened on each day of this plan?" - raw
 * executions, their results, the people who ran them and the cumulative
 * coverage of the plan's assigned test-case versions.
 *
 * Actions
 *   GET|HEAD ?action=init&tplan_id=N[&tproject_id=P]
 *       -> context (plan / project), build/platform/tester/status filters
 *   GET|HEAD ?action=report&tplan_id=N[&tproject_id=P][&build_id=B]
 *               [&platform_id=PL][&status=p|f|b][&tester_id=U][&days=D]
 *       -> KPI summary, verdict, per-day timeline rows and per-status totals
 *
 * Semantics:
 *   - the coverage universe is the plan's `testplan_tcversions` links;
 *     `platform_id = 0` means "any platform" and is proven executed by an
 *     execution on ANY platform (sibling convention, api/planforecast).
 *   - coverage uses every execution in the build/platform scope, regardless
 *     of the status/tester filters; those two filters narrow the displayed
 *     execution activity only, so the progress KPI never lies about coverage.
 *   - a link is counted once, on its earliest execution day in scope.
 *   - the timeline spans the trailing `days` days (1..365, default 30) of the
 *     DB clock; days without executions are returned with zero counters so
 *     the chart/table has no holes.
 *
 * Security: session authentication + the shared BFF same-origin guard and
 * `testplan_metrics` on the OWNING project. The client `tproject_id` is only
 * an assertion, checked after the right decision (no project existence
 * oracle). Denials are logged at AUDIT.
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

/** Single JSON exit point; keep the HTTP code set by the failure helpers. */
function exltOut($payload, $code = null)
{
    if ($code !== null) {
        http_response_code($code);
    }
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function exltFail($httpCode, $code, $message = '')
{
    exltOut(array(
        'status' => 'error',
        'code' => $code,
        'message' => $message !== '' ? $message : $code,
    ), $httpCode);
}

/** Strict positive integer reader ("1abc", "-3", "" and arrays are refused). */
function exltPositiveInt($raw)
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

function exltDecode($value)
{
    return html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function exltRound($value, $decimals = 1)
{
    return round((float)$value, $decimals);
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    exltFail(405, 'method_not_allowed');
}

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    exltFail(401, 'not_authenticated', 'Not authenticated');
}
bffEnforceSession($db);

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    exltFail(401, 'not_authenticated', 'User not found');
}

$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if ($action !== 'init' && $action !== 'report') {
    exltFail(400, 'invalid_request', 'Unknown action');
}

$tplanId = exltPositiveInt($_GET['tplan_id'] ?? null);
$assertedProjectId = exltPositiveInt($_GET['tproject_id'] ?? null);
$buildId = exltPositiveInt($_GET['build_id'] ?? null);
$platformId = exltPositiveInt($_GET['platform_id'] ?? null);
$testerId = exltPositiveInt($_GET['tester_id'] ?? null);
// Statuses come from configuration: besides p/f/b TestLink stores n, x and u
// (and installations may add their own). The filter uses the verbose key so
// the screen can label it with the shared localized status strings.
$resultsCfg = config_get('results');
$statusCodes = is_array($resultsCfg['status_code'] ?? null) ? $resultsCfg['status_code'] : array();
$statusVerbose = array();
foreach ($statusCodes as $verbose => $code) {
    if ($verbose !== 'all' && is_string($code) && $code !== '') {
        $statusVerbose[$verbose] = $code;
    }
}
$statusFilter = strtolower(trim((string)($_GET['status'] ?? '')));
if ($statusFilter !== '' && !isset($statusVerbose[$statusFilter])) {
    exltFail(400, 'invalid_request', 'Invalid status filter');
}

$days = exltPositiveInt($_GET['days'] ?? null);
if ($days <= 0) {
    $days = 30;
}
if ($days > 365) {
    exltFail(400, 'invalid_request', 'days must be between 1 and 365');
}

if ($tplanId <= 0) {
    exltFail(400, 'invalid_request', 'Missing or invalid tplan_id');
}

// Resolve the plan first, then check the right on its OWNING project; only
// then honour the client's project assertion (Refs #1697 no-oracle lesson).
$tbl = tlObjectWithDB::getDBTables(array(
    'testplans', 'builds', 'platforms', 'users', 'testplan_tcversions', 'executions',
));
$planRow = $db->get_recordset(
    "SELECT id, testproject_id FROM {$tbl['testplans']} WHERE id = " . intval($tplanId));
if (!is_array($planRow) || count($planRow) === 0) {
    exltFail(404, 'tplan_not_found', 'Test plan not found');
}
$owningProjectId = intval($planRow[0]['testproject_id']);
if ($owningProjectId <= 0) {
    exltFail(404, 'tplan_not_found', 'Test plan has no owning test project');
}
if (!$user->hasRight($db, 'testplan_metrics', $owningProjectId, $tplanId, true)) {
    tLog('BFF executiontimeline: user ' . intval($userId) .
         ' has no testplan_metrics right on tproject ' . $owningProjectId .
         ' (tplan ' . $tplanId . ')', 'AUDIT');
    exltFail(403, 'no_right', 'testplan_metrics right required');
}
if ($assertedProjectId > 0 && $assertedProjectId !== $owningProjectId) {
    exltFail(404, 'project_mismatch', 'Test plan belongs to another test project');
}

$planName = testplan::getName($db, $tplanId);
$projectName = testproject::getName($db, $owningProjectId);
if (is_null($planName) || trim((string)$planName) === '') {
    exltFail(404, 'tplan_not_found', 'Test plan not found');
}

// Filters are bounded to the owning project so a deep link cannot widen the
// report to another project's builds, platforms or users.
$builds = array();
$rs = $db->get_recordset(
    "SELECT id, name FROM {$tbl['builds']} WHERE testproject_id = " . intval($owningProjectId) .
    " AND active = 1 ORDER BY id");
if (is_array($rs)) {
    foreach ($rs as $r) {
        $builds[] = array('id' => intval($r['id']), 'name' => exltDecode($r['name']));
    }
}
if ($buildId > 0) {
    $known = false;
    foreach ($builds as $b) {
        if ($b['id'] === $buildId) { $known = true; break; }
    }
    if (!$known) {
        exltFail(400, 'invalid_request', 'build_id does not belong to this test project');
    }
}

$platforms = array();
$rs = $db->get_recordset(
    "SELECT id, name FROM {$tbl['platforms']} WHERE testproject_id = " . intval($owningProjectId) .
    " ORDER BY name");
if (is_array($rs)) {
    foreach ($rs as $r) {
        $platforms[] = array('id' => intval($r['id']), 'name' => exltDecode($r['name']));
    }
}
if ($platformId > 0) {
    $known = false;
    foreach ($platforms as $p) {
        if ($p['id'] === $platformId) { $known = true; break; }
    }
    if (!$known) {
        exltFail(400, 'invalid_request', 'platform_id does not belong to this test project');
    }
}

// Testers are the users that actually executed something on this plan, not
// every user of the installation.
$testers = array();
$rs = $db->get_recordset(
    "SELECT DISTINCT u.id, u.login, u.first, u.last FROM {$tbl['users']} u " .
    "JOIN {$tbl['executions']} e ON e.tester_id = u.id " .
    "WHERE e.testplan_id = " . intval($tplanId) . " ORDER BY u.login");
if (is_array($rs)) {
    foreach ($rs as $r) {
        $name = trim(exltDecode($r['first']) . ' ' . exltDecode($r['last']));
        $testers[] = array(
            'id' => intval($r['id']),
            'name' => $name !== '' ? $name : exltDecode($r['login']),
        );
    }
}
if ($testerId > 0) {
    $known = false;
    foreach ($testers as $t) {
        if ($t['id'] === $testerId) { $known = true; break; }
    }
    if (!$known) {
        exltFail(400, 'invalid_request', 'tester_id has no execution on this test plan');
    }
}

$context = array(
    'tplan_id' => $tplanId,
    'tplan_name' => exltDecode((string)$planName),
    'tproject_id' => $owningProjectId,
    'tproject_name' => exltDecode((string)$projectName),
);
$filters = array(
    'builds' => $builds,
    'platforms' => $platforms,
    'testers' => $testers,
    'statuses' => $statusVerbose,
);
$filterEcho = array(
    'build_id' => $buildId,
    'platform_id' => $platformId,
    'tester_id' => $testerId,
    'status' => $statusFilter,
    'days' => $days,
);

if ($action === 'init') {
    exltOut(array(
        'status' => 'ok',
        'code' => 200,
        'context' => $context,
        'filters' => $filters,
        'filter' => $filterEcho,
    ));
}

// ---------------------------------------------------------------------------
// report
// ---------------------------------------------------------------------------
$nowRs = $db->get_recordset("SELECT DATE(NOW()) AS today");
$today = (is_array($nowRs) && count($nowRs) > 0) ? (string)$nowRs[0]['today'] : date('Y-m-d');
$windowStart = date('Y-m-d', strtotime($today . ' -' . ($days - 1) . ' day'));

// Coverage universe: plan links, narrowed by platform (0 = any platform).
$linkRs = $db->get_recordset(
    "SELECT tcversion_id, platform_id FROM {$tbl['testplan_tcversions']} " .
    "WHERE testplan_id = " . intval($tplanId));
$linkSet = array();
$zeroLinks = array();
$assigned = 0;
if (is_array($linkRs)) {
    foreach ($linkRs as $r) {
        $tcv = intval($r['tcversion_id']);
        $pl = intval($r['platform_id']);
        $linkSet[$tcv . '/' . $pl] = true;
        if ($pl === 0) {
            $zeroLinks[$tcv] = true;
        }
        if ($platformId <= 0 || $pl === $platformId || $pl === 0) {
            $assigned++;
        }
    }
}

// Shared build/platform scope SQL for both the coverage and activity queries.
$scopeSql = " FROM {$tbl['executions']} e WHERE e.testplan_id = " . intval($tplanId);
if ($buildId > 0) {
    $scopeSql .= " AND e.build_id = " . intval($buildId);
}
if ($platformId > 0) {
    $scopeSql .= " AND (e.platform_id = " . intval($platformId) . " OR e.platform_id = 0)";
}

// First execution day per (tcversion, platform) - earliest wins, so a
// platform-less link can be proven by an execution on any platform.
$firstSql = "SELECT e.tcversion_id, e.platform_id, DATE(MIN(e.execution_ts)) AS first_day" .
            $scopeSql . " AND e.execution_ts IS NOT NULL" .
            " GROUP BY e.tcversion_id, e.platform_id ORDER BY first_day ASC";
$firstRs = $db->get_recordset($firstSql);
$firstDayByLink = array();
$firstDayCount = array();
$executedLinks = array();
if (is_array($firstRs)) {
    foreach ($firstRs as $r) {
        $tcv = intval($r['tcversion_id']);
        $ep = intval($r['platform_id']);
        $day = (string)$r['first_day'];
        if ($day === '' || $day === '0000-00-00') {
            continue;
        }
        $candidates = array($tcv . '/' . $ep);
        if (isset($zeroLinks[$tcv])) {
            $candidates[] = $tcv . '/0';
        }
        foreach ($candidates as $key) {
            if (isset($linkSet[$key]) && !isset($firstDayByLink[$key])) {
                if ($platformId <= 0 || intval(substr(strrchr($key, '/'), 1)) === $platformId ||
                    intval(substr(strrchr($key, '/'), 1)) === 0) {
                    $firstDayByLink[$key] = $day;
                    $executedLinks[$key] = $day;
                    $firstDayCount[$day] = ($firstDayCount[$day] ?? 0) + 1;
                }
            }
        }
    }
}
$executed = count($executedLinks);
$remaining = max(0, $assigned - $executed);

// Daily raw activity with the optional status/tester narrowing. Group by
// status as well so configured codes (n/x/u or custom ones) are never silently
// dropped; every code below comes from the whitelist built above.
$activitySql = "SELECT DATE(e.execution_ts) AS day, e.status AS status," .
               " COUNT(*) AS total, COUNT(DISTINCT e.tcversion_id) AS versions," .
               " COUNT(DISTINCT e.tester_id) AS testers" . $scopeSql .
               " AND e.execution_ts IS NOT NULL AND e.execution_ts >= '" . $windowStart . "'";
if ($statusFilter !== '') {
    $activitySql .= " AND e.status = '" . $statusVerbose[$statusFilter] . "'";
}
if ($testerId > 0) {
    $activitySql .= " AND e.tester_id = " . intval($testerId);
}
$activitySql .= " GROUP BY DATE(e.execution_ts), e.status";
$activityRs = $db->get_recordset($activitySql);
$byDay = array();
$statusTotals = array();
$matchedTotal = 0;
if (is_array($activityRs)) {
    foreach ($activityRs as $r) {
        $day = (string)$r['day'];
        if ($day === '' || $day === '0000-00-00') {
            continue;
        }
        $qty = intval($r['total']);
        $code = (string)$r['status'];
        $verbose = null;
        foreach ($statusVerbose as $key => $cfgCode) {
            if ($cfgCode === $code) { $verbose = $key; break; }
        }
        if ($verbose === null) { $verbose = 'unknown'; }
        if (!isset($statusTotals[$verbose])) { $statusTotals[$verbose] = 0; }
        $statusTotals[$verbose] += $qty;
        $matchedTotal += $qty;
        if (!isset($byDay[$day])) {
            $byDay[$day] = array('total' => 0, 'statuses' => array(),
                                 'versions' => 0, 'testers' => 0);
        }
        $byDay[$day]['total'] += $qty;
        $byDay[$day]['statuses'][$verbose] = ($byDay[$day]['statuses'][$verbose] ?? 0) + $qty;
        $byDay[$day]['versions'] = max($byDay[$day]['versions'], intval($r['versions']));
        $byDay[$day]['testers'] = max($byDay[$day]['testers'], intval($r['testers']));
    }
}

// Timeline: every day of the window, including zero-activity days. Cumulative
// coverage needs the whole history, not only the displayed window.
$beforeWindow = 0;
foreach ($executedLinks as $day) {
    if ($day < $windowStart) {
        $beforeWindow++;
    }
}
$cumulative = $beforeWindow;
$timeline = array();
for ($i = $days - 1; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime($today . ' -' . $i . ' day'));
    $newly = $firstDayCount[$day] ?? 0;
    $cumulative += $newly;
    $row = $byDay[$day] ?? array('total' => 0, 'statuses' => array(),
                                 'versions' => 0, 'testers' => 0);
    $timeline[] = array(
        'day' => $day,
        'executions' => intval($row['total']),
        'statuses' => $row['statuses'],
        'versions' => intval($row['versions']),
        'testers' => intval($row['testers']),
        'newly_executed' => $newly,
        'cumulative_executed' => $cumulative,
        'coverage_pct' => ($assigned > 0) ? exltRound(100.0 * $cumulative / $assigned, 1) : 0.0,
        'is_weekend' => in_array(date('N', strtotime($day)), array('6', '7'), true),
    );
}

$activeDays = 0;
$firstDay = null;
$lastDay = null;
foreach ($byDay as $day => $row) {
    if ($row['total'] > 0) {
        $activeDays++;
        if ($firstDay === null || $day < $firstDay) { $firstDay = $day; }
        if ($lastDay === null || $day > $lastDay) { $lastDay = $day; }
    }
}

if ($assigned <= 0) {
    $verdict = 'no_data';
} elseif ($remaining <= 0) {
    $verdict = 'complete';
} elseif ($activeDays === 0) {
    $verdict = 'idle';
} else {
    $verdict = 'active';
}

$summary = array(
    'assigned' => $assigned,
    'executed' => $executed,
    'remaining' => $remaining,
    'coverage_pct' => ($assigned > 0) ? exltRound(100.0 * $executed / $assigned, 1) : 0.0,
    'executions' => $matchedTotal,
    'active_days' => $activeDays,
    'avg_per_active_day' => ($activeDays > 0) ? exltRound($matchedTotal / $activeDays, 1) : 0.0,
    'pass_rate' => ($matchedTotal > 0) ? exltRound(
        100.0 * ($statusTotals['passed'] ?? 0) / $matchedTotal, 1) : 0.0,
    'passed' => $statusTotals['passed'] ?? 0,
    'failed' => $statusTotals['failed'] ?? 0,
    'blocked' => $statusTotals['blocked'] ?? 0,
    'first_execution' => $firstDay,
    'last_execution' => $lastDay,
    'window_days' => $days,
    'window_start' => $windowStart,
    'today' => $today,
    'verdict' => $verdict,
);

exltOut(array(
    'status' => 'ok',
    'code' => 200,
    'context' => $context,
    'filters' => $filters,
    'filter' => $filterEcho,
    'summary' => $summary,
    'status_totals' => $statusTotals,
    'timeline' => $timeline,
));
