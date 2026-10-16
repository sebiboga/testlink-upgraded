<?php
/**
 * Automation Test Reports - REST BFF API
 * URL: /api/automationreports/index.php
 * Plain PHP, no framework, no compilation
 *
 * Net-new 2.0.1 screen requested by enhancement #1062 ("Reports > Automation
 * Test Reports"). TestLink 1.9.20 shipped NO automation-reporting screen at all:
 * automated results were written into the same tl executions table as manual
 * ones and could only be counted through the generic metrics / charts reports,
 * which never split automated from manual execution nor exposed the automated
 * test-case inventory, the per-build automation trend or a failed-automation
 * worklist. This BFF aggregates those numbers from the existing schema (no new
 * tables, nothing persisted).
 *
 * WHAT THE AGGREGATE MEANS
 * ------------------------
 *  - the unit is the test-case VERSION linked to the test plan
 *    (testplan_tcversions.tcversion_id), because execution_type - manual vs
 *    automated - is a property of a test-case VERSION (tcversions.execution_type,
 *    1 = manual, 2 = automated; TESTCASE_EXECUTION_TYPE_MANUAL / _AUTO);
 *  - a linked version is counted once even when the plan links it on several
 *    platforms (testplan_tcversions may hold one row per platform);
 *  - the LATEST recorded execution of a version (per version + platform, across
 *    all builds unless a build is filtered) supplies the reported status; a
 *    version with no execution is 'not_run'. This mirrors the "absolute latest
 *    execution" convention of the legacy neverRun / resultsByStatus reports;
 *  - summary.automation_coverage = automated linked versions / linked versions;
 *  - build_trend is the same latest-per-(version,build) status counted per build
 *    (the "trend across builds" the enhancement asks for).
 *
 * FINAL STATUS CODES (kept byte-identical to the rest of the results family):
 *   p -> passed, f -> failed, b -> blocked, anything else / no execution -> not_run
 *
 * Rights gate: the plan is resolved first (404 tplan_not_found when it does not
 * exist / has no owner), then testplan_metrics is checked on the OWNING test
 * project AND test plan with getAccess = true (a right held only globally no
 * longer reaches a private test project -> 403). This mirrors the accepted
 * siblings api/keywordbarchart, api/prioritybarchart, api/toplevelsuitesbarchart.
 *
 * Contract: GET|HEAD ?action=init|report&tplan_id=N[&tproject_id=P]
 *                [&build_id=B][&platform_id=PL]
 *   200 {status, code, context:{tplan_id,tplan_name,tproject_id,tproject_name},
 *        filters:{builds:[{id,name}],platforms:[{id,name}]},
 *        summary:{total_tc,manual_tc,auto_tc,percent_automated,
 *                 passed,failed,blocked,not_run,executed,
 *                 manual:{passed,failed,blocked,not_run},
 *                 auto:{passed,failed,blocked,not_run}},
 *        build_trend:[{build_id,build_name,passed,failed,blocked,not_run,executed}],
 *        rows:[{tcversion_id,testcase_id,tc_external_id,version,name,suite,
 *               execution_type,status,build_id,build_name,platform_name,
 *               last_exec_ts,tester}],
 *        rows_truncated:bool}
 *   400 invalid_request | 401 not_authenticated / session_expired
 *   403 no_right | 404 tplan_not_found / project_mismatch | 405 method_not_allowed
 *
 * Refs #1921
 * Refs #1062
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

/** Single JSON exit point (see api/keywordbarchart for the rationale). */
function arOut($payload, $code = null)
{
    if ($code !== null) {
        http_response_code($code);
    }
    echo json_encode($payload);
    exit;
}

function arFail($httpCode, $code, $message = '')
{
    arOut(array(
        'status' => 'error',
        'code' => $code,
        'message' => $message !== '' ? $message : $code,
    ), $httpCode);
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    arFail(405, 'method_not_allowed');
}

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    arFail(401, 'not_authenticated', 'Not authenticated');
}

bffEnforceSession($db);

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    arFail(401, 'not_authenticated', 'User not found');
}

$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if ($action !== 'init' && $action !== 'report') {
    arFail(400, 'invalid_request', 'Unknown action');
}

/** Strict positive integer reader - "1abc", "1.9", "-3" and "" are all refused. */
function arPositiveInt($raw)
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

$tplanId = arPositiveInt($_GET['tplan_id'] ?? null);
$assertedProjectId = arPositiveInt($_GET['tproject_id'] ?? null);
$buildId = arPositiveInt($_GET['build_id'] ?? null);
$platformId = arPositiveInt($_GET['platform_id'] ?? null);

if ($tplanId <= 0) {
    arFail(400, 'invalid_request', 'Missing or invalid tplan_id');
}

// Order of operations (identical to api/keywordbarchart #1906):
//   1. resolve the plan -> opaque 404 when it does not exist or has no owner
//   2. check the right on the OWNING project (getAccess = true)
//   3. only then honour the client's tproject_id assertion
$tbl = tlObjectWithDB::getDBTables(array(
    'testplans', 'builds', 'platforms', 'testplan_tcversions', 'tcversions',
    'nodes_hierarchy', 'users', 'executions',
));
$planRow = $db->get_recordset(
    "SELECT id, testproject_id FROM {$tbl['testplans']} WHERE id = " . intval($tplanId));

if (!is_array($planRow) || count($planRow) === 0) {
    arFail(404, 'tplan_not_found', 'Test plan not found');
}

$plan = $planRow[0];
$owningProjectId = intval($plan['testproject_id']);
if ($owningProjectId <= 0) {
    arFail(404, 'tplan_not_found', 'Test plan has no owning test project');
}

if (!$user->hasRight($db, 'testplan_metrics', $owningProjectId, $tplanId, true)) {
    tLog('BFF automationreports: user ' . intval($userId) .
         ' has no testplan_metrics right on tproject ' . $owningProjectId .
         ' (tplan ' . $tplanId . ')', 'ERROR');
    arFail(403, 'no_right', 'testplan_metrics right required');
}

if ($assertedProjectId > 0 && $assertedProjectId !== $owningProjectId) {
    arFail(404, 'project_mismatch', 'Test plan belongs to another test project');
}

$planName = testplan::getName($db, $tplanId);
$projectName = testproject::getName($db, $owningProjectId);
if (is_null($planName) || trim((string)$planName) === '') {
    arFail(404, 'tplan_not_found', 'Test plan not found');
}

// ---------------------------------------------------------------------------
// Filters: builds / platforms belonging to the OWNING project.
// ---------------------------------------------------------------------------
$builds = array();
$rs = $db->get_recordset(
    "SELECT id, name FROM {$tbl['builds']} WHERE testproject_id = " . intval($owningProjectId) .
    " AND active = 1 ORDER BY name");
if (is_array($rs)) {
    foreach ($rs as $r) {
        $builds[] = array('id' => intval($r['id']), 'name' => html_entity_decode((string)$r['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
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
        arFail(400, 'invalid_request', 'build_id does not belong to this test project');
    }
}

$platforms = array();
$rp = $db->get_recordset(
    "SELECT id, name FROM {$tbl['platforms']} WHERE testproject_id = " . intval($owningProjectId) .
    " ORDER BY name");
if (is_array($rp)) {
    foreach ($rp as $r) {
        $platforms[] = array('id' => intval($r['id']), 'name' => html_entity_decode((string)$r['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
if ($platformId > 0) {
    $known = false;
    foreach ($platforms as $p) {
        if ($p['id'] === $platformId) { $known = true; break; }
    }
    if (!$known) {
        arFail(400, 'invalid_request', 'platform_id does not belong to this test project');
    }
}

// ---------------------------------------------------------------------------
// init: context + filters only (the caller has not chosen a filter yet).
// ---------------------------------------------------------------------------
if ($action === 'init') {
    arOut(array(
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
// report: the aggregate.
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
    "SELECT tv.id AS tcversion_id, tv.tc_external_id, tv.version, tv.execution_type, " .
    "       tcn.id AS testcase_id, tcn.name AS tc_name, sn.name AS suite_name " .
    "FROM {$tbl['testplan_tcversions']} tpt " .
    "JOIN {$tbl['tcversions']} tv ON tv.id = tpt.tcversion_id " .
    "JOIN {$tbl['nodes_hierarchy']} tvn ON tvn.id = tv.id " .
    "JOIN {$tbl['nodes_hierarchy']} tcn ON tcn.id = tvn.parent_id " .
    "LEFT JOIN {$tbl['nodes_hierarchy']} sn ON sn.id = tcn.parent_id " .
    "WHERE tpt.testplan_id = " . intval($tplanId) . " " .
    "GROUP BY tv.id, tv.tc_external_id, tv.version, tv.execution_type, " .
    "         tcn.id, tcn.name, sn.name " .
    "ORDER BY sn.name, tcn.name, tv.version";

$linked = $db->get_recordset($linkedSql);
if (!is_array($linked)) {
    $linked = array();
}

// 2. Latest execution per tcversion_id -> status / ts / tester / build / platform.
$latest = array();
$latestSql =
    "SELECT m.tcversion_id, e.status, e.build_id, e.platform_id, e.execution_ts, e.tester_id " .
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

// build / platform names for the rows.
$buildNames = array();
foreach ($builds as $b) { $buildNames[$b['id']] = $b['name']; }
$platformNames = array();
foreach ($platforms as $p) { $platformNames[$p['id']] = $p['name']; }

// tester logins (only for the versions that have an execution).
$testerNames = array();
$testerIds = array();
foreach ($latest as $r) {
    $tid = intval($r['tester_id'] ?? 0);
    if ($tid > 0) { $testerIds[$tid] = true; }
}
if (count($testerIds) > 0) {
    $ids = implode(',', array_map('intval', array_keys($testerIds)));
    $tu = $db->get_recordset("SELECT id, login FROM {$tbl['users']} WHERE id IN ({$ids})");
    if (is_array($tu)) {
        foreach ($tu as $r) { $testerNames[intval($r['id'])] = (string)$r['login']; }
    }
}

/** Normalize a raw executions.status char to the report's final status key. */
function arStatusKey($raw)
{
    switch ((string)$raw) {
        case 'p': return 'passed';
        case 'f': return 'failed';
        case 'b': return 'blocked';
        default:  return 'not_run';
    }
}

$summary = array(
    'total_tc' => 0, 'manual_tc' => 0, 'auto_tc' => 0, 'percent_automated' => 0,
    'passed' => 0, 'failed' => 0, 'blocked' => 0, 'not_run' => 0, 'executed' => 0,
    'manual' => array('passed' => 0, 'failed' => 0, 'blocked' => 0, 'not_run' => 0),
    'auto'   => array('passed' => 0, 'failed' => 0, 'blocked' => 0, 'not_run' => 0),
);

$rows = array();
foreach ($linked as $v) {
    $tcversionId = intval($v['tcversion_id']);
    $isAuto = (intval($v['execution_type']) === TESTCASE_EXECUTION_TYPE_AUTO);
    $typeKey = $isAuto ? 'auto' : 'manual';

    $ex = isset($latest[$tcversionId]) ? $latest[$tcversionId] : null;
    $status = $ex ? arStatusKey($ex['status']) : 'not_run';

    $summary['total_tc']++;
    $summary[$typeKey === 'auto' ? 'auto_tc' : 'manual_tc']++;
    $summary[$status]++;
    $summary[$typeKey][$status]++;
    if ($status !== 'not_run') { $summary['executed']++; }

    $rows[] = array(
        'tcversion_id' => $tcversionId,
        'testcase_id' => intval($v['testcase_id']),
        'tc_external_id' => intval($v['tc_external_id']),
        'version' => intval($v['version']),
        'name' => html_entity_decode((string)$v['tc_name'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        'suite' => html_entity_decode((string)($v['suite_name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        'execution_type' => $typeKey,
        'status' => $status,
        'build_id' => $ex ? intval($ex['build_id']) : 0,
        'build_name' => ($ex && isset($buildNames[intval($ex['build_id'])])) ? $buildNames[intval($ex['build_id'])] : '',
        'platform_name' => ($ex && isset($platformNames[intval($ex['platform_id'])])) ? $platformNames[intval($ex['platform_id'])] : '',
        'last_exec_ts' => $ex ? (string)($ex['execution_ts'] ?? '') : '',
        'tester' => ($ex && isset($testerNames[intval($ex['tester_id'] ?? 0)])) ? $testerNames[intval($ex['tester_id'] ?? 0)] : '',
    );
}

if ($summary['total_tc'] > 0) {
    $summary['percent_automated'] =
        round(100.0 * $summary['auto_tc'] / $summary['total_tc'], 1);
}

// 3. Build trend: latest status per (version, build), counted per build.
//    Only meaningful without an explicit platform filter narrowing the set; it
//    is still returned (it simply reflects the selected platform).
$buildTrend = array();
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
            $btAcc[$bid] = array('build_id' => $bid, 'build_name' => $buildNames[$bid] ?? ('#' . $bid),
                                 'passed' => 0, 'failed' => 0, 'blocked' => 0, 'not_run' => 0, 'executed' => 0);
        }
        $st = arStatusKey($r['status']);
        $btAcc[$bid][$st] += intval($r['qty']);
        if ($st !== 'not_run') { $btAcc[$bid]['executed'] += intval($r['qty']); }
    }
}
foreach ($btAcc as $b) {
    $buildTrend[] = $b;
}
usort($buildTrend, function ($a, $b) { return strcmp((string)$a['build_name'], (string)$b['build_name']); });

// Guard the payload size: a very large plan would otherwise ship every version.
$rowsTruncated = false;
if (count($rows) > 5000) {
    $rows = array_slice($rows, 0, 5000);
    $rowsTruncated = true;
}

arOut(array(
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
    'rows' => $rows,
    'rows_truncated' => $rowsTruncated,
));
