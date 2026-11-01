<?php
/**
 * Test Plan Results by Build report - BFF (Refs #1980).
 *
 * Net-new modern screen (gui/templates/results/resultsByBuild.html) replacing
 * the last legacy Results renderer (gui/templates/dashio/results/resultsBuild.tpl).
 * The 1.9.20 Results menu reports execution data per build only through derived
 * views (build DIFF, duration, tester, timeline); there was no plain table that
 * answers "how did each build of this plan actually end up?".
 *
 * Semantics:
 *   - the unit is the test-case VERSION currently linked to the plan
 *     (testplan_tcversions); unassigned versions are ignored.
 *   - a BUILD row is the plan snapshot taken inside that build: for every
 *     assigned version the status of its LATEST execution in that build
 *     (ordered by execution_ts then id). No execution in that build = not run.
 *   - the SUMMARY is the plan snapshot across every build in scope (latest
 *     execution per version anywhere), i.e. the current state of the plan.
 *   - coverage_pct  = executed versions / assigned versions
 *   - pass_rate_pct = passed versions / executed versions (p + f + b)
 *   - total_executions counts execution EVENTS on assigned versions (a version
 *     retested three times in one build counts three times).
 *   - verdict: `empty` when the plan has no assigned version; `ready` when the
 *     plan snapshot has coverage >= 80% and pass rate >= 80%; `at_risk` when
 *     coverage < 50% or an executed plan has pass rate < 50%; `watch`
 *     otherwise.
 *
 * Actions
 *   GET|HEAD ?action=init&tplan_id=N[&tproject_id=P]
 *       -> context + platform filter
 *   GET|HEAD ?action=report&tplan_id=N[&tproject_id=P][&platform_id=PL]
 *       -> summary + per-build rows
 *
 * Security: session authentication, the shared BFF same-origin guard and the
 * `testplan_metrics` right on the OWNING test project. The client supplied
 * tproject_id is only an assertion (compared, never used to widen the query).
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

function rbOut($payload, $code = null)
{
    if ($code !== null) {
        http_response_code($code);
    }
    echo json_encode($payload);
    exit;
}

function rbFail($httpCode, $code, $message = '')
{
    rbOut(array(
        'status' => 'error',
        'code' => $code,
        'message' => $message !== '' ? $message : $code,
    ), $httpCode);
}

/** Strict positive integer reader - "1abc", "1.9", "-3" and "" are refused. */
function rbPositiveInt($raw)
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

function rbDecode($value)
{
    return html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function rbRound($value, $decimals = 1)
{
    return round((float)$value, $decimals);
}

/** Normalise an executions.status value into p / f / b / n. */
function rbStatus($raw)
{
    $s = (string)$raw;
    return ($s === 'p' || $s === 'f' || $s === 'b') ? $s : 'n';
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    rbFail(405, 'method_not_allowed');
}

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    rbFail(401, 'not_authenticated', 'Not authenticated');
}

bffEnforceSession($db);

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    rbFail(401, 'not_authenticated', 'User not found');
}

$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if ($action !== 'init' && $action !== 'report') {
    rbFail(400, 'invalid_request', 'Unknown action');
}

$tplanId = rbPositiveInt($_GET['tplan_id'] ?? null);
$assertedProjectId = rbPositiveInt($_GET['tproject_id'] ?? null);
$platformId = rbPositiveInt($_GET['platform_id'] ?? null);

if (isset($_GET['platform_id'])) {
    $rawPlatform = is_array($_GET['platform_id']) ? '' : trim((string)$_GET['platform_id']);
    if ($rawPlatform === '' || !preg_match('/^[0-9]+$/', $rawPlatform)) {
        rbFail(400, 'invalid_request', 'Invalid platform_id');
    }
}

if ($tplanId <= 0) {
    rbFail(400, 'invalid_request', 'Missing or invalid tplan_id');
}

$tbl = tlObjectWithDB::getDBTables(array(
    'testplans', 'builds', 'platforms', 'testplan_tcversions', 'tcversions',
    'nodes_hierarchy', 'executions', 'testprojects', 'users',
));
$planRow = $db->get_recordset(
    "SELECT id, testproject_id FROM {$tbl['testplans']} WHERE id = " . intval($tplanId));

if (!is_array($planRow) || count($planRow) === 0) {
    rbFail(404, 'tplan_not_found', 'Test plan not found');
}

$plan = $planRow[0];
$owningProjectId = intval($plan['testproject_id']);
if ($owningProjectId <= 0) {
    rbFail(404, 'tplan_not_found', 'Test plan has no owning test project');
}

if (!$user->hasRight($db, 'testplan_metrics', $owningProjectId, $tplanId, true)) {
    tLog('BFF resultsbybuild: user ' . intval($userId) .
         ' has no testplan_metrics right on tproject ' . $owningProjectId .
         ' (tplan ' . $tplanId . ')', 'ERROR');
    rbFail(403, 'no_right', 'testplan_metrics right required');
}

if ($assertedProjectId > 0 && $assertedProjectId !== $owningProjectId) {
    rbFail(404, 'project_mismatch', 'Test plan belongs to another test project');
}

$planName = testplan::getName($db, $tplanId);
$projectName = testproject::getName($db, $owningProjectId);
if (is_null($planName) || trim((string)$planName) === '') {
    rbFail(404, 'tplan_not_found', 'Test plan not found');
}

// Platforms of the OWNING project (filter vocabulary).
$platforms = array();
$rp = $db->get_recordset(
    "SELECT id, name FROM {$tbl['platforms']} WHERE testproject_id = " . intval($owningProjectId) .
    " ORDER BY name");
if (is_array($rp)) {
    foreach ($rp as $r) {
        $platforms[] = array('id' => intval($r['id']), 'name' => rbDecode($r['name']));
    }
}
if ($platformId > 0) {
    $known = false;
    foreach ($platforms as $p) {
        if ($p['id'] === $platformId) { $known = true; break; }
    }
    if (!$known) {
        rbFail(400, 'invalid_request', 'platform_id does not belong to this test project');
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
    rbOut(array(
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
    "SELECT id, name, active FROM {$tbl['builds']} WHERE testproject_id = " .
    intval($owningProjectId) . " ORDER BY id");
if (is_array($rb2)) {
    foreach ($rb2 as $r) {
        $bid = intval($r['id']);
        $builds[$bid] = array(
            'id' => $bid,
            'name' => rbDecode($r['name']),
            'active' => (intval($r['active']) === 1),
        );
        $buildOrder[] = $bid;
    }
}

// ---------------------------------------------------------------------------
// Assigned versions of the plan (one row per version; a version linked twice
// for two platforms collapses to one row, which is what the report measures).
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
// One pass over the executions of the plan, in chronological order:
//   - globalLatest[tcv]            = latest status of the plan snapshot
//   - buildLatest[build][tcv]      = latest status of that build snapshot
//   - buildEvents[build]           = execution events in that build
//   - buildLastTs[build]           = last execution timestamp in that build
// ---------------------------------------------------------------------------
$globalLatest = array();
$buildLatest = array();
$buildEvents = array();
$buildLastTs = array();
$buildPresent = array();
$totalExecutions = 0;

$whereExec = "e.testplan_id = " . intval($tplanId);
if ($platformId > 0) {
    $whereExec .= " AND e.platform_id = " . intval($platformId);
}
$execRs = $db->get_recordset(
    "SELECT e.tcversion_id, e.status, e.execution_ts, e.build_id " .
    "FROM {$tbl['executions']} e " .
    "WHERE " . $whereExec .
    " ORDER BY e.execution_ts ASC, e.id ASC");
if (is_array($execRs)) {
    foreach ($execRs as $r) {
        $tcv = intval($r['tcversion_id']);
        if (!isset($assigned[$tcv])) {
            continue;
        }
        $bid = intval($r['build_id']);
        $status = rbStatus($r['status']);
        $ts = (string)$r['execution_ts'];

        $globalLatest[$tcv] = array('status' => $status, 'ts' => $ts);

        if ($bid > 0) {
            if (!isset($buildLatest[$bid])) {
                $buildLatest[$bid] = array();
                $buildEvents[$bid] = 0;
                $buildPresent[$bid] = true;
            }
            $buildLatest[$bid][$tcv] = array('status' => $status, 'ts' => $ts);
            $buildEvents[$bid]++;
            $buildLastTs[$bid] = $ts;
        }

        $totalExecutions++;
    }
}

/** Snapshot aggregation helper: count statuses of a latest-status map. */
function rbSnapshot($latest)
{
    $snap = array('executed' => 0, 'passed' => 0, 'failed' => 0, 'blocked' => 0);
    foreach ($latest as $entry) {
        $snap['executed']++;
        if ($entry['status'] === 'p') { $snap['passed']++; }
        else if ($entry['status'] === 'f') { $snap['failed']++; }
        else if ($entry['status'] === 'b') { $snap['blocked']++; }
    }
    return $snap;
}

function rbPct($num, $den)
{
    return ($den > 0) ? rbRound(100.0 * $num / $den, 1) : 0.0;
}

// Plan snapshot (summary).
$globalSnap = rbSnapshot($globalLatest);
$globalSnap['not_run'] = max(0, $assignedTotal - $globalSnap['executed']);

$coveragePct = rbPct($globalSnap['executed'], $assignedTotal);
$passRatePct = rbPct($globalSnap['passed'], $globalSnap['executed']);
$verdict = 'watch';
if ($assignedTotal === 0) {
    $verdict = 'empty';
} else if ($coveragePct < 50.0
    || ($globalSnap['executed'] > 0 && $passRatePct < 50.0)) {
    $verdict = 'at_risk';
} else if ($coveragePct >= 80.0 && $passRatePct >= 80.0) {
    $verdict = 'ready';
}

// Per-build rows. Every project build appears; builds referenced by an
// execution but missing from the project (deleted build) are appended so no
// execution history disappears.
$rowIds = $buildOrder;
foreach (array_keys($buildPresent) as $bid) {
    if (!isset($builds[$bid])) {
        $builds[$bid] = array(
            'id' => intval($bid),
            'name' => '#' . intval($bid),
            'active' => false,
        );
        $rowIds[] = intval($bid);
    }
}

$rows = array();
$buildsWithActivity = 0;
foreach ($rowIds as $bid) {
    $bid = intval($bid);
    $latest = $buildLatest[$bid] ?? array();
    $snap = rbSnapshot($latest);
    $notRun = max(0, $assignedTotal - $snap['executed']);
    $events = intval($buildEvents[$bid] ?? 0);
    $hasActivity = ($events > 0);

    if ($hasActivity) {
        $buildsWithActivity++;
    }
    $rows[] = array(
        'build_id' => $bid,
        'build_name' => $builds[$bid]['name'],
        'is_open' => $builds[$bid]['active'] ? true : false,
        'assigned' => $assignedTotal,
        'executed' => $snap['executed'],
        'passed' => $snap['passed'],
        'failed' => $snap['failed'],
        'blocked' => $snap['blocked'],
        'not_run' => $notRun,
        'coverage_pct' => rbPct($snap['executed'], $assignedTotal),
        'pass_rate_pct' => rbPct($snap['passed'], $snap['executed']),
        'executions' => $events,
        'has_activity' => $hasActivity,
        'last_ts' => (string)($buildLastTs[$bid] ?? ''),
    );
}

// Newest builds first (id is the creation order and therefore the build order).
usort($rows, function ($a, $b) {
    if ($a['build_id'] === $b['build_id']) { return 0; }
    return ($a['build_id'] > $b['build_id']) ? -1 : 1;
});

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
    'verdict' => $verdict,
);

rbOut(array(
    'status' => 'ok',
    'code' => 200,
    'context' => $context,
    'filters' => $filters,
    'filter' => $filterState,
    'summary' => $summary,
    'rows' => $rows,
));
