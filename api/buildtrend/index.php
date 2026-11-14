<?php
/**
 * Test Plan Build Quality Trend report - BFF (Refs #1991).
 *
 * Net-new read-only 2.0.1 report (gui/templates/results/buildTrend.html).
 * The 1.9.20 Results menu only exposed a per-build SNAPSHOT (the modern
 * resultsByBuild) or a pairwise DIFF of exactly two builds (buildComparison);
 * neither answers "is this plan getting better or worse build after build?".
 * This report ranks every build of the plan chronologically and reports, per
 * build, the latest-execution snapshot (passed / failed / blocked / not run),
 * coverage, pass rate, execution events, distinct testers and linked defects,
 * plus the delta against the previous build that had activity and a
 * baseline / improved / stable / regressed verdict. A plan-wide trend compares
 * the first and last active build.
 *
 * Actions
 *   GET|HEAD ?action=init&tplan_id=N[&tproject_id=P]
 *       -> context + platform filter vocabulary
 *   GET|HEAD ?action=report&tplan_id=N[&tproject_id=P][&platform_id=PL]
 *       -> summary + chronological per-build rows
 *
 * Security: session authentication, the shared BFF same-origin guard and the
 * `testplan_metrics` right on the OWNING test project. The client supplied
 * tproject_id is only an assertion (compared, never used to widen a query),
 * and the right is checked BEFORE the project resolution (no-oracle lesson).
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

function btOut($payload, $code = null)
{
    if ($code !== null) {
        http_response_code($code);
    }
    echo json_encode($payload);
    exit;
}

function btFail($httpCode, $code, $message = '')
{
    btOut(array(
        'status' => 'error',
        'code' => $code,
        'message' => $message !== '' ? $message : $code,
    ), $httpCode);
}

/** Strict positive integer reader - "1abc", "1.9", "-3" and "" are refused. */
function btPositiveInt($raw)
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

function btDecode($value)
{
    return html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function btRound($value, $decimals = 1)
{
    return round((float)$value, $decimals);
}

/** Normalise an executions.status value into p / f / b / n. */
function btStatus($raw)
{
    $s = (string)$raw;
    return ($s === 'p' || $s === 'f' || $s === 'b') ? $s : 'n';
}

function btPct($num, $den)
{
    return ($den > 0) ? btRound(100.0 * $num / $den, 1) : 0.0;
}

/** Snapshot aggregation helper: count statuses of a latest-status map. */
function btSnapshot($latest)
{
    $snap = array('executed' => 0, 'passed' => 0, 'failed' => 0, 'blocked' => 0);
    foreach ($latest as $entry) {
        if ($entry['status'] === 'p') { $snap['executed']++; $snap['passed']++; }
        else if ($entry['status'] === 'f') { $snap['executed']++; $snap['failed']++; }
        else if ($entry['status'] === 'b') { $snap['executed']++; $snap['blocked']++; }
    }
    return $snap;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    btFail(405, 'method_not_allowed');
}

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    btFail(401, 'not_authenticated', 'Not authenticated');
}

bffEnforceSession($db);

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    btFail(401, 'not_authenticated', 'User not found');
}

$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if ($action !== 'init' && $action !== 'report') {
    btFail(400, 'invalid_request', 'Unknown action');
}

$tplanId = btPositiveInt($_GET['tplan_id'] ?? null);
$assertedProjectId = btPositiveInt($_GET['tproject_id'] ?? null);
$platformId = btPositiveInt($_GET['platform_id'] ?? null);

if (isset($_GET['platform_id'])) {
    $rawPlatform = is_array($_GET['platform_id']) ? '' : trim((string)$_GET['platform_id']);
    if ($rawPlatform === '' || !preg_match('/^[0-9]+$/', $rawPlatform)) {
        btFail(400, 'invalid_request', 'Invalid platform_id');
    }
}

if ($tplanId <= 0) {
    btFail(400, 'invalid_request', 'Missing or invalid tplan_id');
}

$tbl = tlObjectWithDB::getDBTables(array(
    'testplans', 'builds', 'platforms', 'testplan_tcversions', 'tcversions',
    'nodes_hierarchy', 'executions', 'execution_bugs', 'testprojects', 'users',
));
$planRow = $db->get_recordset(
    "SELECT id, testproject_id FROM {$tbl['testplans']} WHERE id = " . intval($tplanId));

if (!is_array($planRow) || count($planRow) === 0) {
    btFail(404, 'tplan_not_found', 'Test plan not found');
}

$plan = $planRow[0];
$owningProjectId = intval($plan['testproject_id']);
if ($owningProjectId <= 0) {
    btFail(404, 'tplan_not_found', 'Test plan has no owning test project');
}

if (!$user->hasRight($db, 'testplan_metrics', $owningProjectId, $tplanId, true)) {
    tLog('BFF buildtrend: user ' . intval($userId) .
         ' has no testplan_metrics right on tproject ' . $owningProjectId .
         ' (tplan ' . $tplanId . ')', 'AUDIT');
    btFail(403, 'no_right', 'testplan_metrics right required');
}

if ($assertedProjectId > 0 && $assertedProjectId !== $owningProjectId) {
    btFail(404, 'project_mismatch', 'Test plan belongs to another test project');
}

$planName = testplan::getName($db, $tplanId);
$projectName = testproject::getName($db, $owningProjectId);
if (is_null($planName) || trim((string)$planName) === '') {
    btFail(404, 'tplan_not_found', 'Test plan not found');
}

// Platforms of the OWNING project (filter vocabulary).
$platforms = array();
$rp = $db->get_recordset(
    "SELECT id, name FROM {$tbl['platforms']} WHERE testproject_id = " . intval($owningProjectId) .
    " ORDER BY name");
if (is_array($rp)) {
    foreach ($rp as $r) {
        $platforms[] = array('id' => intval($r['id']), 'name' => btDecode($r['name']));
    }
}
if ($platformId > 0) {
    $known = false;
    foreach ($platforms as $p) {
        if ($p['id'] === $platformId) { $known = true; break; }
    }
    if (!$known) {
        btFail(400, 'invalid_request', 'platform_id does not belong to this test project');
    }
}

$context = array(
    'tplan_id' => $tplanId,
    'tplan_name' => (string)$planName,
    'tproject_id' => $owningProjectId,
    'tproject_name' => (string)$projectName,
);
$filters = array('platforms' => $platforms);
$filterState = array('platform_id' => $platformId);

if ($action === 'init') {
    btOut(array(
        'status' => 'ok',
        'code' => 200,
        'context' => $context,
        'filters' => $filters,
        'filter' => $filterState,
    ));
}

// ---------------------------------------------------------------------------
// Builds of the owning project (all of them, so historical executions keep a
// readable name; `active` only drives the open/closed badge).
// ---------------------------------------------------------------------------
$builds = array();
$buildOrder = array();
$rb2 = $db->get_recordset(
    "SELECT id, name, active, is_open, creation_ts FROM {$tbl['builds']} WHERE testproject_id = " .
    intval($owningProjectId) . " ORDER BY id");
if (is_array($rb2)) {
    foreach ($rb2 as $r) {
        $bid = intval($r['id']);
        $builds[$bid] = array(
            'id' => $bid,
            'name' => btDecode($r['name']),
            'active' => (intval($r['active']) === 1),
            'is_open' => (intval($r['is_open']) === 1),
            'creation_ts' => (string)$r['creation_ts'],
        );
        $buildOrder[] = $bid;
    }
}

// ---------------------------------------------------------------------------
// Assigned versions of the plan (a version linked twice for two platforms
// collapses to one row, which is what the report measures).
// ---------------------------------------------------------------------------
$assigned = array();
$assignedRs = $db->get_recordset(
    "SELECT DISTINCT tpt.tcversion_id " .
    "FROM {$tbl['testplan_tcversions']} tpt " .
    "WHERE tpt.testplan_id = " . intval($tplanId) .
    ($platformId > 0
        ? " AND (tpt.platform_id = " . intval($platformId) . " OR tpt.platform_id = 0)"
        : "") .
    " ORDER BY tpt.tcversion_id");
if (is_array($assignedRs)) {
    foreach ($assignedRs as $r) {
        $assigned[intval($r['tcversion_id'])] = true;
    }
}
$assignedTotal = count($assigned);

// ---------------------------------------------------------------------------
// One chronological pass over the executions of the plan:
//   - globalLatest[tcv]        = latest status of the plan snapshot
//   - buildLatest[build][tcv]  = latest status of that build snapshot
//   - buildEvents[build]       = execution events in that build
//   - buildLastTs[build]       = last execution timestamp in that build
//   - buildTesters[build]      = distinct testers in that build
// ---------------------------------------------------------------------------
$globalLatest = array();
$buildLatest = array();
$buildEvents = array();
$buildLastTs = array();
$buildPresent = array();
$buildTesters = array();
$totalExecutions = 0;

$whereExec = "e.testplan_id = " . intval($tplanId);
if ($platformId > 0) {
    $whereExec .= " AND e.platform_id = " . intval($platformId);
}
$execRs = $db->get_recordset(
    "SELECT e.tcversion_id, e.status, e.execution_ts, e.build_id, e.tester_id " .
    "FROM {$tbl['executions']} e " .
    "WHERE " . $whereExec .
    " ORDER BY e.id ASC");
if (is_array($execRs)) {
    foreach ($execRs as $r) {
        $tcv = intval($r['tcversion_id']);
        if (!isset($assigned[$tcv])) {
            continue;
        }
        $bid = intval($r['build_id']);
        $status = btStatus($r['status']);
        $ts = (string)$r['execution_ts'];

        $globalLatest[$tcv] = array('status' => $status, 'ts' => $ts);

        if ($bid > 0) {
            if (!isset($buildLatest[$bid])) {
                $buildLatest[$bid] = array();
                $buildEvents[$bid] = 0;
                $buildPresent[$bid] = true;
                $buildTesters[$bid] = array();
            }
            $buildLatest[$bid][$tcv] = array('status' => $status, 'ts' => $ts);
            $buildEvents[$bid]++;
            $buildLastTs[$bid] = $ts;
            $testerId = intval($r['tester_id'] ?? 0);
            if ($testerId > 0) {
                $buildTesters[$bid][$testerId] = true;
            }
            $totalExecutions++;
        }
    }
}

// Linked defects per build (distinct bug_id on the executions of the build).
$buildDefects = array();
$defectRs = $db->get_recordset(
    "SELECT e.build_id, COUNT(DISTINCT eb.bug_id) AS defects " .
    "FROM {$tbl['executions']} e " .
    "INNER JOIN {$tbl['execution_bugs']} eb ON eb.execution_id = e.id " .
    "WHERE " . $whereExec .
    " GROUP BY e.build_id");
if (is_array($defectRs)) {
    foreach ($defectRs as $r) {
        $buildDefects[intval($r['build_id'])] = intval($r['defects']);
    }
}

// Plan snapshot (summary) across every build in scope.
$globalSnap = btSnapshot($globalLatest);
$globalSnap['not_run'] = max(0, $assignedTotal - $globalSnap['executed']);

$coveragePct = btPct($globalSnap['executed'], $assignedTotal);
$passRatePct = btPct($globalSnap['passed'], $globalSnap['executed']);
$verdict = 'watch';
if ($assignedTotal === 0) {
    $verdict = 'empty';
} else if ($coveragePct < 50.0
    || ($globalSnap['executed'] > 0 && $passRatePct < 50.0)) {
    $verdict = 'at_risk';
} else if ($coveragePct >= 80.0 && $passRatePct >= 80.0) {
    $verdict = 'ready';
}

// Per-build rows, CHRONOLOGICAL (oldest first) so the table reads as a trend.
// Builds referenced by an execution but missing from the project (deleted
// build) are appended so no execution history disappears.
$rowIds = $buildOrder;
foreach (array_keys($buildPresent) as $bid) {
    if (!isset($builds[$bid])) {
        $builds[$bid] = array(
            'id' => intval($bid),
            'name' => '#' . intval($bid),
            'active' => false,
            'is_open' => false,
            'creation_ts' => '',
        );
        $rowIds[] = intval($bid);
    }
}
sort($rowIds, SORT_NUMERIC);

$rows = array();
$buildsWithActivity = 0;
$prevActive = null;
$firstActive = null;
$totalDefects = 0;
foreach ($rowIds as $bid) {
    $bid = intval($bid);
    $latest = $buildLatest[$bid] ?? array();
    $snap = btSnapshot($latest);
    $notRun = max(0, $assignedTotal - $snap['executed']);
    $events = intval($buildEvents[$bid] ?? 0);
    $hasActivity = ($events > 0);
    $testers = isset($buildTesters[$bid]) ? count($buildTesters[$bid]) : 0;
    $defects = intval($buildDefects[$bid] ?? 0);
    $totalDefects += $defects;

    $rowCoverage = btPct($snap['executed'], $assignedTotal);
    $rowPassRate = btPct($snap['passed'], $snap['executed']);

    $deltaPass = null;
    $deltaCoverage = null;
    $rowVerdict = 'na';
    if ($hasActivity) {
        $buildsWithActivity++;
        if ($firstActive === null) {
            $firstActive = array('pass_rate' => $rowPassRate, 'coverage' => $rowCoverage);
        }
        if ($prevActive === null) {
            $rowVerdict = 'baseline';
        } else {
            $deltaPass = btRound($rowPassRate - $prevActive['pass_rate'], 1);
            $deltaCoverage = btRound($rowCoverage - $prevActive['coverage'], 1);
            if ($deltaPass >= 1.0) {
                $rowVerdict = 'improved';
            } else if ($deltaPass <= -1.0) {
                $rowVerdict = 'regressed';
            } else {
                $rowVerdict = 'stable';
            }
        }
        $prevActive = array('pass_rate' => $rowPassRate, 'coverage' => $rowCoverage);
    }

    $rows[] = array(
        'build_id' => $bid,
        'build_name' => $builds[$bid]['name'],
        'is_open' => !empty($builds[$bid]['is_open']),
        'creation_ts' => (string)$builds[$bid]['creation_ts'],
        'assigned' => $assignedTotal,
        'executed' => $snap['executed'],
        'passed' => $snap['passed'],
        'failed' => $snap['failed'],
        'blocked' => $snap['blocked'],
        'not_run' => $notRun,
        'coverage_pct' => $rowCoverage,
        'pass_rate_pct' => $rowPassRate,
        'executions' => $events,
        'testers' => $testers,
        'defects' => $defects,
        'delta_pass_rate_pct' => $deltaPass,
        'delta_coverage_pct' => $deltaCoverage,
        'has_activity' => $hasActivity,
        'last_ts' => (string)($buildLastTs[$bid] ?? ''),
        'verdict' => $rowVerdict,
    );
}

// Plan-wide trend: first vs last build that actually had activity.
$trend = 'not_enough_data';
$trendDelta = null;
if ($firstActive !== null && $prevActive !== null && $buildsWithActivity >= 2) {
    $trendDelta = btRound($prevActive['pass_rate'] - $firstActive['pass_rate'], 1);
    if ($trendDelta >= 1.0) {
        $trend = 'improving';
    } else if ($trendDelta <= -1.0) {
        $trend = 'declining';
    } else {
        $trend = 'stable';
    }
}

$summary = array(
    'total_builds' => count($rows),
    'builds_with_activity' => $buildsWithActivity,
    'assigned_versions' => $assignedTotal,
    'executed_versions' => $globalSnap['executed'],
    'passed' => $globalSnap['passed'],
    'failed' => $globalSnap['failed'],
    'blocked' => $globalSnap['blocked'],
    'not_run' => $globalSnap['not_run'],
    'coverage_pct' => $coveragePct,
    'pass_rate_pct' => $passRatePct,
    'total_executions' => $totalExecutions,
    'total_defects' => $totalDefects,
    'trend' => $trend,
    'trend_delta_pct' => $trendDelta,
    'verdict' => $verdict,
);

btOut(array(
    'status' => 'ok',
    'code' => 200,
    'context' => $context,
    'filters' => $filters,
    'filter' => $filterState,
    'summary' => $summary,
    'rows' => $rows,
));
