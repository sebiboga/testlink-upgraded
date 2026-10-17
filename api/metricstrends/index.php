<?php
/**
 * Metrics & Trends dashboard - REST BFF API
 * URL: /api/metricstrends/index.php
 * Plain PHP, no framework, no compilation
 *
 * Net-new 2.0.1 screen requested by enhancement #1073 ("Add Metrics & Trends
 * dashboards for builds, releases and defects" -> Reports > Metrics & Trends).
 * TestLink 1.9.20 exposed a *snapshot* only: the Test Plan Metrics screen
 * (modernized as gui/templates/results/metricsDashboard.html) shows the CURRENT
 * status of one plan; nothing rendered a *longitudinal* quality view across the
 * plan's builds. This BFF aggregates that history from the existing schema
 * (executions + testplan_tcversions + execution_bugs) with NO new tables and
 * nothing persisted.
 *
 * WHAT THE AGGREGATE MEANS
 * ------------------------
 *  - the unit is the test-case VERSION linked to the test plan
 *    (testplan_tcversions.tcversion_id, counted once even when the plan links
 *    it on several platforms);
 *  - summary is the CURRENT KNOWN STATE: the LATEST recorded execution of a
 *    version (per version + platform, across all builds unless a build is
 *    filtered) supplies the reported status; a version with no execution is
 *    'not_run'. This mirrors the "absolute latest execution" convention of the
 *    accepted siblings api/automationreports, api/defectintelligence;
 *  - build_trend is the SAME latest-per-(version,build) status counted per
 *    build, ordered by build id (creation order), each row carrying its
 *    progress %, pass rate %, a delta pass rate vs the previous build and an
 *    is_latest flag on the newest build - i.e. the "trend across builds" the
 *    enhancement asks for;
 *  - defects = COUNT(DISTINCT execution_bugs.bug_id) over the filtered
 *    executions (bug_id is a varchar, so a distinct count, never a numeric sum).
 *
 * FINAL STATUS CODES (kept byte-identical to the results family):
 *   p -> passed, f -> failed, b -> blocked, anything else / no execution -> not_run
 *
 * Rights gate: the plan is resolved first (404 tplan_not_found when it does not
 * exist / has no owner), then testplan_metrics is checked on the OWNING test
 * project AND test plan with getAccess = true (a right held only globally no
 * longer reaches a private test project -> 403). This mirrors the accepted
 * siblings api/automationreports, api/keywordbarchart, api/qualitygate.
 *
 * Contract: GET|HEAD ?action=init|report&tplan_id=N[&tproject_id=P]
 *                [&build_id=B][&platform_id=PL]
 *   200 {status, code, context:{tplan_id,tplan_name,tproject_id,tproject_name},
 *        filters:{builds:[{id,name}],platforms:[{id,name}]},
 *        filter:{build_id,platform_id},
 *        summary:{total_tc,executed,not_run,passed,failed,blocked,
 *                 progress_pct,pass_rate_pct,fail_rate_pct,automation_pct,
 *                 defects,latest_build_id,latest_build_name,builds_count},
 *        build_trend:[{build_id,build_name,executed,passed,failed,blocked,
 *                      not_run,progress_pct,pass_rate_pct,defects,is_latest,
 *                      delta_pass_rate}]}
 *   400 invalid_request | 401 not_authenticated / session_expired
 *   403 no_right | 404 tplan_not_found / project_mismatch | 405 method_not_allowed
 *
 * Refs #1935
 * Refs #1073
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

/** Single JSON exit point. */
function mtOut($payload, $code = null)
{
    if ($code !== null) {
        http_response_code($code);
    }
    echo json_encode($payload);
    exit;
}

function mtFail($httpCode, $code, $message = '')
{
    mtOut(array(
        'status' => 'error',
        'code' => $code,
        'message' => $message !== '' ? $message : $code,
    ), $httpCode);
}

/** Strict positive integer reader - "1abc", "1.9", "-3" and "" are all refused. */
function mtPositiveInt($raw)
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

/** Normalize a raw executions.status char to the report's final status key. */
function mtStatusKey($raw)
{
    switch ((string)$raw) {
        case 'p': return 'passed';
        case 'f': return 'failed';
        case 'b': return 'blocked';
        default:  return 'not_run';
    }
}

function mtPct($num, $den)
{
    if ($den <= 0) {
        return 0.0;
    }
    return round(100.0 * $num / $den, 1);
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    mtFail(405, 'method_not_allowed');
}

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    mtFail(401, 'not_authenticated', 'Not authenticated');
}

bffEnforceSession($db);

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    mtFail(401, 'not_authenticated', 'User not found');
}

$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if ($action !== 'init' && $action !== 'report') {
    mtFail(400, 'invalid_request', 'Unknown action');
}

$tplanId = mtPositiveInt($_GET['tplan_id'] ?? null);
$assertedProjectId = mtPositiveInt($_GET['tproject_id'] ?? null);
$buildId = mtPositiveInt($_GET['build_id'] ?? null);
$platformId = mtPositiveInt($_GET['platform_id'] ?? null);

if ($tplanId <= 0) {
    mtFail(400, 'invalid_request', 'Missing or invalid tplan_id');
}

// Order of operations (identical to api/automationreports #1062):
//   1. resolve the plan -> opaque 404 when it does not exist or has no owner
//   2. check the right on the OWNING project (getAccess = true)
//   3. only then honour the client's tproject_id assertion
$tbl = tlObjectWithDB::getDBTables(array(
    'testplans', 'builds', 'platforms', 'testplan_tcversions', 'tcversions',
    'nodes_hierarchy', 'executions', 'execution_bugs',
));
$planRow = $db->get_recordset(
    "SELECT id, testproject_id FROM {$tbl['testplans']} WHERE id = " . intval($tplanId));

if (!is_array($planRow) || count($planRow) === 0) {
    mtFail(404, 'tplan_not_found', 'Test plan not found');
}

$plan = $planRow[0];
$owningProjectId = intval($plan['testproject_id']);
if ($owningProjectId <= 0) {
    mtFail(404, 'tplan_not_found', 'Test plan has no owning test project');
}

if (!$user->hasRight($db, 'testplan_metrics', $owningProjectId, $tplanId, true)) {
    tLog('BFF metricstrends: user ' . intval($userId) .
         ' has no testplan_metrics right on tproject ' . $owningProjectId .
         ' (tplan ' . $tplanId . ')', 'ERROR');
    mtFail(403, 'no_right', 'testplan_metrics right required');
}

if ($assertedProjectId > 0 && $assertedProjectId !== $owningProjectId) {
    mtFail(404, 'project_mismatch', 'Test plan belongs to another test project');
}

$planName = testplan::getName($db, $tplanId);
$projectName = testproject::getName($db, $owningProjectId);
if (is_null($planName) || trim((string)$planName) === '') {
    mtFail(404, 'tplan_not_found', 'Test plan not found');
}

// ---------------------------------------------------------------------------
// Filters: builds / platforms belonging to the OWNING project.
// ---------------------------------------------------------------------------
$builds = array();
$rs = $db->get_recordset(
    "SELECT id, name FROM {$tbl['builds']} WHERE testproject_id = " . intval($owningProjectId) .
    " AND active = 1 ORDER BY id");
if (is_array($rs)) {
    foreach ($rs as $r) {
        $builds[] = array(
            'id' => intval($r['id']),
            'name' => html_entity_decode((string)$r['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        );
    }
}
// A build filter that does not belong to the project is refused (never silently
// ignored: that would leak counts from a foreign project).
if ($buildId > 0) {
    $known = false;
    foreach ($builds as $b) {
        if ($b['id'] === $buildId) { $known = true; break; }
    }
    if (!$known) {
        mtFail(400, 'invalid_request', 'build_id does not belong to this test project');
    }
}

$platforms = array();
$rp = $db->get_recordset(
    "SELECT id, name FROM {$tbl['platforms']} WHERE testproject_id = " . intval($owningProjectId) .
    " ORDER BY name");
if (is_array($rp)) {
    foreach ($rp as $r) {
        $platforms[] = array(
            'id' => intval($r['id']),
            'name' => html_entity_decode((string)$r['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        );
    }
}
if ($platformId > 0) {
    $known = false;
    foreach ($platforms as $p) {
        if ($p['id'] === $platformId) { $known = true; break; }
    }
    if (!$known) {
        mtFail(400, 'invalid_request', 'platform_id does not belong to this test project');
    }
}

// ---------------------------------------------------------------------------
// init: context + filters only (the caller has not chosen a filter yet).
// ---------------------------------------------------------------------------
if ($action === 'init') {
    mtOut(array(
        'status' => 'ok',
        'code' => 200,
        'context' => array(
            'tplan_id' => $tplanId,
            'tplan_name' => (string)$planName,
            'tproject_id' => $owningProjectId,
            'tproject_name' => (string)$projectName,
        ),
        'filters' => array('builds' => $builds, 'platforms' => $platforms),
    ));
}

// ---------------------------------------------------------------------------
// report: the longitudinal aggregate.
// ---------------------------------------------------------------------------
$whereExec = "e.testplan_id = " . intval($tplanId);
if ($buildId > 0) {
    $whereExec .= " AND e.build_id = " . intval($buildId);
}
if ($platformId > 0) {
    $whereExec .= " AND e.platform_id = " . intval($platformId);
}

// 1. Linked test-case versions (one per tcversion_id, even if multi-platform).
$linkedSql =
    "SELECT tv.id AS tcversion_id, tv.execution_type " .
    "FROM {$tbl['testplan_tcversions']} tpt " .
    "JOIN {$tbl['tcversions']} tv ON tv.id = tpt.tcversion_id " .
    "WHERE tpt.testplan_id = " . intval($tplanId) . " " .
    "GROUP BY tv.id, tv.execution_type";
$linked = $db->get_recordset($linkedSql);
if (!is_array($linked)) {
    $linked = array();
}

$linkedVersions = array();
$autoVersions = 0;
foreach ($linked as $v) {
    $linkedVersions[intval($v['tcversion_id'])] = true;
    if (intval($v['execution_type']) === TESTCASE_EXECUTION_TYPE_AUTO) {
        $autoVersions++;
    }
}
$totalTc = count($linkedVersions);

// 2. Latest execution per tcversion_id -> status (summary = current state).
$latest = array();
$latestSql =
    "SELECT m.tcversion_id, e.status " .
    "FROM {$tbl['executions']} e " .
    "JOIN (SELECT tcversion_id, MAX(id) AS max_id FROM {$tbl['executions']} e2 " .
    "      WHERE " . str_replace('e.', 'e2.', $whereExec) . " " .
    "      GROUP BY tcversion_id) m ON m.max_id = e.id";
$lr = $db->get_recordset($latestSql);
if (is_array($lr)) {
    foreach ($lr as $r) {
        $latest[intval($r['tcversion_id'])] = $r;
    }
}

$summary = array(
    'total_tc' => $totalTc, 'executed' => 0, 'not_run' => 0,
    'passed' => 0, 'failed' => 0, 'blocked' => 0,
    'progress_pct' => 0.0, 'pass_rate_pct' => 0.0, 'fail_rate_pct' => 0.0,
    'automation_pct' => 0.0, 'defects' => 0,
    'latest_build_id' => 0, 'latest_build_name' => '', 'builds_count' => 0,
);
foreach ($linkedVersions as $tcversionId => $unused) {
    $st = isset($latest[$tcversionId]) ? mtStatusKey($latest[$tcversionId]['status']) : 'not_run';
    $summary[$st]++;
}
$summary['executed'] = $summary['passed'] + $summary['failed'] + $summary['blocked'];
$summary['not_run'] = $totalTc - $summary['executed'];
if ($summary['not_run'] < 0) { $summary['not_run'] = 0; }
$summary['progress_pct'] = mtPct($summary['executed'], $totalTc);
$summary['pass_rate_pct'] = mtPct($summary['passed'], $totalTc);
$summary['fail_rate_pct'] = mtPct($summary['failed'], $totalTc);
$summary['automation_pct'] = mtPct($autoVersions, $totalTc);

// 3. Build trend: latest status per (version, build), counted per build.
$buildNames = array();
foreach ($builds as $b) { $buildNames[$b['id']] = $b['name']; }

$btSql =
    "SELECT e.build_id, e.status, COUNT(*) AS qty " .
    "FROM {$tbl['executions']} e " .
    "JOIN (SELECT tcversion_id, build_id, MAX(id) AS max_id FROM {$tbl['executions']} e2 " .
    "      WHERE " . str_replace('e.', 'e2.', $whereExec) . " GROUP BY tcversion_id, build_id) m " .
    "     ON m.max_id = e.id " .
    "GROUP BY e.build_id, e.status";
$btr = $db->get_recordset($btSql);
$btAcc = array();
if (is_array($btr)) {
    foreach ($btr as $r) {
        $bid = intval($r['build_id']);
        if (!isset($btAcc[$bid])) {
            $btAcc[$bid] = array(
                'build_id' => $bid,
                'build_name' => $buildNames[$bid] ?? ('#' . $bid),
                'passed' => 0, 'failed' => 0, 'blocked' => 0, 'executed' => 0,
                'defects' => 0,
            );
        }
        $st = mtStatusKey($r['status']);
        if ($st === 'not_run') { continue; }
        $btAcc[$bid][$st] += intval($r['qty']);
        $btAcc[$bid]['executed'] += intval($r['qty']);
    }
}

// defects per build (distinct bug refs on the executions of that build).
$bdSql =
    "SELECT e.build_id, COUNT(DISTINCT eb.bug_id) AS defects " .
    "FROM {$tbl['executions']} e " .
    "JOIN {$tbl['execution_bugs']} eb ON eb.execution_id = e.id " .
    "WHERE " . $whereExec . " GROUP BY e.build_id";
$bdr = $db->get_recordset($bdSql);
if (is_array($bdr)) {
    foreach ($bdr as $r) {
        $bid = intval($r['build_id']);
        if (!isset($btAcc[$bid])) {
            $btAcc[$bid] = array(
                'build_id' => $bid,
                'build_name' => $buildNames[$bid] ?? ('#' . $bid),
                'passed' => 0, 'failed' => 0, 'blocked' => 0, 'executed' => 0,
                'defects' => 0,
            );
        }
        $btAcc[$bid]['defects'] = intval($r['defects']);
    }
}

usort($btAcc, function ($a, $b) { return $a['build_id'] - $b['build_id']; });

$buildTrend = array();
$prevPassRate = null;
$maxBuildId = 0;
foreach ($btAcc as $b) {
    if ($b['build_id'] > $maxBuildId) { $maxBuildId = $b['build_id']; }
}
foreach ($btAcc as $b) {
    $passRate = mtPct($b['passed'], $totalTc);
    $b['not_run'] = max(0, $totalTc - $b['executed']);
    $b['progress_pct'] = mtPct($b['executed'], $totalTc);
    $b['pass_rate_pct'] = $passRate;
    $b['is_latest'] = ($b['build_id'] === $maxBuildId);
    $b['delta_pass_rate'] = ($prevPassRate === null) ? null : round($passRate - $prevPassRate, 1);
    $prevPassRate = $passRate;
    $buildTrend[] = $b;
}

// total defects across the filtered executions (summary tile).
$dtSql =
    "SELECT COUNT(DISTINCT eb.bug_id) AS defects " .
    "FROM {$tbl['executions']} e " .
    "JOIN {$tbl['execution_bugs']} eb ON eb.execution_id = e.id " .
    "WHERE " . $whereExec;
$dtr = $db->get_recordset($dtSql);
if (is_array($dtr) && count($dtr) > 0) {
    $summary['defects'] = intval($dtr[0]['defects']);
}
$summary['builds_count'] = count($buildTrend);
$summary['latest_build_id'] = $maxBuildId;
$summary['latest_build_name'] = ($maxBuildId > 0 && isset($buildNames[$maxBuildId]))
    ? $buildNames[$maxBuildId] : '';

mtOut(array(
    'status' => 'ok',
    'code' => 200,
    'context' => array(
        'tplan_id' => $tplanId,
        'tplan_name' => (string)$planName,
        'tproject_id' => $owningProjectId,
        'tproject_name' => (string)$projectName,
    ),
    'filters' => array('builds' => $builds, 'platforms' => $platforms),
    'filter' => array('build_id' => $buildId, 'platform_id' => $platformId),
    'summary' => $summary,
    'build_trend' => $buildTrend,
));
