<?php
/**
 * Test Plan Unassigned Tests - BFF (Refs #2007).
 *
 * Net-new read-only report (gui/templates/results/unassignedTests.html) that
 * lists the test-case versions assigned to a test plan and highlights the ones
 * with NO active tester. TestLink 1.9.20 only exposed the assignment editor:
 * answering "which of my planned tests has nobody responsible for it?" meant
 * walking every assigned version by hand.
 *
 * Semantics:
 *   - the unit is a testplan_tcversions row (an assigned version, optionally
 *     pinned to a platform); platform_id = 0 applies to every platform;
 *   - a tester is an active user_assignments row (type = 1, user_id > 0,
 *     status not in 2/3); build_id = 0 assigns the version on every build;
 *   - with build_id > 0 the assignment must be build-wide or for that build
 *     and the last execution is read inside that build; without it any active
 *     assignment and any execution of the plan counts;
 *   - last execution = newest execution_ts (ties: highest id) for the
 *     (version, platform) pair inside the selected filter scope.
 *
 * Rights: session authentication + the shared BFF same-origin guard and
 * `testplan_metrics` on the OWNING project of the plan. The right is checked
 * BEFORE the client's tproject_id assertion, so an unknown plan is opaque and
 * the endpoint is not an existence oracle. Denials are logged at AUDIT.
 *
 * Contract: GET|HEAD
 *   ?action=init&tplan_id=N[&tproject_id=P]
 *   ?action=report&tplan_id=N[&tproject_id=P][&build_id=B][&platform_id=L]
 *                 [&scope=all|unassigned]
 *   400 invalid_request | 401 not_authenticated | 403 no_right
 *   404 tplan_not_found/project_not_found/project_mismatch
 *   405 method_not_allowed
 */
require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function uatOut($payload, $code = null)
{
    if ($code !== null) {
        http_response_code($code);
    }
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function uatFail($httpCode, $code, $message = '')
{
    uatOut(array(
        'status' => 'error',
        'code' => $code,
        'message' => $message !== '' ? $message : $code,
    ), $httpCode);
}

function uatPositiveInt($raw)
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

function uatDecode($value)
{
    return html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function uatPct($part, $total)
{
    return ($total > 0) ? round(100.0 * $part / $total, 1) : 0.0;
}

function uatStatus($letter)
{
    switch (strtolower(trim((string)$letter))) {
        case 'p': return 'passed';
        case 'f': return 'failed';
        case 'b': return 'blocked';
    }
    return 'not_run';
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    uatFail(405, 'method_not_allowed');
}

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    uatFail(401, 'not_authenticated', 'Not authenticated');
}
bffEnforceSession($db);

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    uatFail(401, 'not_authenticated', 'User not found');
}

$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if ($action !== 'init' && $action !== 'report') {
    uatFail(400, 'invalid_request', 'Unknown action');
}

$tplanId = uatPositiveInt($_GET['tplan_id'] ?? null);
$assertedProjectId = uatPositiveInt($_GET['tproject_id'] ?? null);
$rawBuild = trim((string)($_GET['build_id'] ?? '0'));
$rawPlatform = trim((string)($_GET['platform_id'] ?? '0'));
$scope = strtolower(trim((string)($_GET['scope'] ?? 'all')));
if (!in_array($scope, array('all', 'unassigned'), true)) {
    uatFail(400, 'invalid_request', 'Invalid scope');
}
if ($tplanId <= 0) {
    uatFail(400, 'invalid_request', 'Missing or invalid tplan_id');
}
if (($rawBuild !== '' && !preg_match('/^[0-9]+$/', $rawBuild)) ||
    ($rawPlatform !== '' && !preg_match('/^[0-9]+$/', $rawPlatform))) {
    uatFail(400, 'invalid_request', 'Invalid filter');
}
$buildId = intval($rawBuild);
$platformId = intval($rawPlatform);

$tbl = tlObjectWithDB::getDBTables(array(
    'testplans', 'testprojects', 'nodes_hierarchy', 'testplan_tcversions',
    'tcversions', 'builds', 'platforms', 'testplan_platforms', 'executions',
    'user_assignments',
));

// Opaque 404 first, right on the OWNING project second, client assertion last.
$planRow = $db->get_recordset(
    "SELECT id, testproject_id FROM {$tbl['testplans']} WHERE id = " . intval($tplanId));
if (!is_array($planRow) || count($planRow) === 0) {
    uatFail(404, 'tplan_not_found', 'Test plan not found');
}
$owningProjectId = intval($planRow[0]['testproject_id']);
if ($owningProjectId <= 0) {
    uatFail(404, 'tplan_not_found', 'Test plan has no owning test project');
}
if (!$user->hasRight($db, 'testplan_metrics', $owningProjectId, $tplanId, true)) {
    tLog('BFF unassignedtests: user ' . intval($userId) .
         ' has no testplan_metrics right on tproject ' . intval($owningProjectId) .
         ' (tplan ' . $tplanId . ')', 'AUDIT');
    uatFail(403, 'no_right', 'testplan_metrics right required');
}
if ($assertedProjectId > 0 && $assertedProjectId !== $owningProjectId) {
    uatFail(404, 'project_mismatch', 'Test plan belongs to another test project');
}

$planName = testplan::getName($db, $tplanId);
$projectName = testproject::getName($db, $owningProjectId);
if (is_null($planName) || trim((string)$planName) === '' ||
    is_null($projectName) || trim((string)$projectName) === '') {
    uatFail(404, 'tplan_not_found', 'Test plan not found');
}

$builds = array();
$buildRs = $db->get_recordset(
    "SELECT id, name FROM {$tbl['builds']} WHERE testproject_id = " . intval($owningProjectId) .
    " AND active = 1 ORDER BY id");
if (is_array($buildRs)) {
    foreach ($buildRs as $b) {
        $builds[] = array('id' => intval($b['id']), 'name' => uatDecode($b['name']));
    }
}

$platforms = array();
$platformRs = $db->get_recordset(
    "SELECT p.id, p.name FROM {$tbl['platforms']} p" .
    " JOIN {$tbl['testplan_platforms']} tpp ON tpp.platform_id = p.id" .
    " WHERE tpp.testplan_id = " . intval($tplanId) . " ORDER BY p.name");
if (is_array($platformRs)) {
    foreach ($platformRs as $p) {
        $platforms[] = array('id' => intval($p['id']), 'name' => uatDecode($p['name']));
    }
}

$knownBuild = ($buildId === 0);
foreach ($builds as $b) { if ($b['id'] === $buildId) { $knownBuild = true; break; } }
if (!$knownBuild) {
    uatFail(400, 'invalid_request', 'build_id does not belong to this test project');
}
$knownPlatform = ($platformId === 0);
foreach ($platforms as $p) { if ($p['id'] === $platformId) { $knownPlatform = true; break; } }
if (!$knownPlatform) {
    uatFail(400, 'invalid_request', 'platform_id does not belong to this test plan');
}

$context = array(
    'tplan_id' => $tplanId,
    'tplan_name' => uatDecode((string)$planName),
    'tproject_id' => $owningProjectId,
    'tproject_name' => uatDecode((string)$projectName),
);
$filterEcho = array(
    'build_id' => $buildId, 'platform_id' => $platformId, 'scope' => $scope,
);

if ($action === 'init') {
    uatOut(array(
        'status' => 'ok',
        'code' => 200,
        'context' => $context,
        'filters' => array('builds' => $builds, 'platforms' => $platforms),
        'filter' => $filterEcho,
    ));
}

// ---------------------------------------------------------------------------
// report
// ---------------------------------------------------------------------------

// Active assignments keyed by the plan assignment id (tpt.id). build_id = 0
// means "all builds of this project"; closed/completed assignments do not
// count as an owner (same rule as api/testerworkload).
$assignCounts = array();
$assignRs = $db->get_recordset(
    "SELECT ua.feature_id, ua.build_id, ua.user_id " .
    "FROM {$tbl['user_assignments']} ua " .
    "WHERE ua.type = 1 AND ua.user_id > 0 AND COALESCE(ua.status, 1) NOT IN (2, 3)");
if (is_array($assignRs)) {
    foreach ($assignRs as $a) {
        $abuild = intval($a['build_id']);
        if ($buildId > 0 && $abuild !== 0 && $abuild !== $buildId) {
            continue;
        }
        $fid = intval($a['feature_id']);
        if (!isset($assignCounts[$fid])) { $assignCounts[$fid] = array(); }
        $assignCounts[$fid][intval($a['user_id'])] = true;
    }
}

// Latest execution per (version, platform) in the selected build/platform
// scope, plus the execution count per version for the detail column.
$execSql =
    "SELECT e.id, e.tcversion_id, e.platform_id, e.build_id, e.status, " .
    "       e.execution_ts, e.notes " .
    "FROM {$tbl['executions']} e " .
    "WHERE e.testplan_id = " . intval($tplanId);
if ($buildId > 0) {
    $execSql .= " AND e.build_id = " . intval($buildId);
}
if ($platformId > 0) {
    $execSql .= " AND (e.platform_id = 0 OR e.platform_id = " . intval($platformId) . ")";
}
$execSql .= " ORDER BY e.execution_ts ASC, e.id ASC";
$lastExec = array();
$lastExecAny = array();
$execCounts = array();
$execRs = $db->get_recordset($execSql);
if (is_array($execRs)) {
    foreach ($execRs as $e) {
        $tcv = intval($e['tcversion_id']);
        $plat = intval($e['platform_id']);
        $lastExec[$tcv . '|' . $plat] = $e;
        $lastExecAny[$tcv] = $e;
        if (!isset($execCounts[$tcv])) { $execCounts[$tcv] = 0; }
        $execCounts[$tcv]++;
    }
}

// Every assigned version of the plan, with its test case / suite metadata.
$featureSql =
    "SELECT tpt.id AS feature_id, tpt.tcversion_id, tpt.platform_id, tpt.urgency, " .
    "       tv.version, nh_tc.id AS tcase_id, nh_tc.name AS tcase_name, " .
    "       nh_ts.id AS suite_id, nh_ts.name AS suite_name " .
    "FROM {$tbl['testplan_tcversions']} tpt" .
    " JOIN {$tbl['tcversions']} tv ON tv.id = tpt.tcversion_id" .
    " JOIN {$tbl['nodes_hierarchy']} nh_tv ON nh_tv.id = tv.id" .
    " JOIN {$tbl['nodes_hierarchy']} nh_tc ON nh_tc.id = nh_tv.parent_id" .
    " LEFT JOIN {$tbl['nodes_hierarchy']} nh_ts ON nh_ts.id = nh_tc.parent_id" .
    " WHERE tpt.testplan_id = " . intval($tplanId);
if ($platformId > 0) {
    $featureSql .= " AND (tpt.platform_id = 0 OR tpt.platform_id = " . intval($platformId) . ")";
}
$featureSql .= " ORDER BY nh_ts.name, nh_tc.name, tpt.platform_id, tv.id";
$featureRs = $db->get_recordset($featureSql);
if (!is_array($featureRs)) { $featureRs = array(); }

$platformNames = array();
foreach ($platforms as $p) { $platformNames[$p['id']] = $p['name']; }
$buildNames = array();
foreach ($builds as $b) { $buildNames[$b['id']] = $b['name']; }

$rows = array();
$summary = array(
    'assigned' => 0,
    'covered' => 0,
    'unassigned' => 0,
    'unassigned_pct' => 0.0,
    'never_executed' => 0,
    'passed' => 0,
    'failed' => 0,
    'blocked' => 0,
    'not_run' => 0,
    'suites' => 0,
    'builds' => count($builds),
    'platforms' => count($platforms),
);
$suiteSet = array();

foreach ($featureRs as $r) {
    $tcv = intval($r['tcversion_id']);
    $fid = intval($r['feature_id']);
    $pinned = intval($r['platform_id']);
    $assignees = isset($assignCounts[$fid]) ? count($assignCounts[$fid]) : 0;
    $isUnassigned = ($assignees === 0);

    $e = ($pinned === 0 && isset($lastExecAny[$tcv]))
        ? $lastExecAny[$tcv]
        : (isset($lastExec[$tcv . '|' . $pinned]) ? $lastExec[$tcv . '|' . $pinned] : null);
    if ($e === null && $pinned !== 0 && isset($lastExec[$tcv . '|0'])) {
        $e = $lastExec[$tcv . '|0'];
    }
    $hasExec = is_array($e);
    $status = uatStatus($e ? ($e['status'] ?? '') : '');
    $suiteId = intval($r['suite_id']);

    $summary['assigned']++;
    if ($isUnassigned) {
        $summary['unassigned']++;
        if ($suiteId > 0) { $suiteSet[$suiteId] = true; }
        if (!$hasExec) { $summary['never_executed']++; }
        if (isset($summary[$status])) { $summary[$status]++; }
    } else {
        $summary['covered']++;
    }

    if ($scope === 'unassigned' && !$isUnassigned) {
        continue;
    }

    $level = function_exists('priority_to_level')
        ? intval(priority_to_level(intval($r['urgency']))) : MEDIUM;
    $priority = $level >= HIGH ? 'high' : ($level >= MEDIUM ? 'medium' : 'low');

    $rows[] = array(
        'feature_id' => $fid,
        'tcase_id' => intval($r['tcase_id']),
        'tcversion_id' => $tcv,
        'tcase_name' => uatDecode($r['tcase_name']),
        'version' => intval($r['version']),
        'suite_id' => $suiteId,
        'suite_name' => uatDecode((string)($r['suite_name'] ?: '-')),
        'platform_id' => $pinned,
        'platform_name' => ($pinned > 0 && isset($platformNames[$pinned])) ? $platformNames[$pinned] : '',
        'priority' => $priority,
        'assignees' => $assignees,
        'unassigned' => $isUnassigned,
        'executions' => isset($execCounts[$tcv]) ? $execCounts[$tcv] : 0,
        'last_status' => $status,
        'last_build' => ($hasExec && isset($buildNames[intval($e['build_id'])]))
            ? $buildNames[intval($e['build_id'])] : '',
        'last_run' => $hasExec ? (string)($e['execution_ts'] ?? '') : '',
        'last_note' => $hasExec ? uatDecode($e['notes'] ?? '') : '',
    );
}

$summary['unassigned_pct'] = uatPct($summary['unassigned'], $summary['assigned']);
$summary['suites'] = count($suiteSet);
$summary['not_run'] = $summary['never_executed'];

if ($summary['assigned'] === 0) {
    $verdict = 'no_data';
} elseif ($summary['unassigned'] === 0) {
    $verdict = 'clear';
} elseif ($summary['unassigned_pct'] >= 30 || $summary['unassigned'] >= 5) {
    $verdict = 'at_risk';
} else {
    $verdict = 'watch';
}

uatOut(array(
    'status' => 'ok',
    'code' => 200,
    'context' => $context,
    'filter' => $filterEcho,
    'filters' => array('builds' => $builds, 'platforms' => $platforms),
    'summary' => $summary,
    'rows' => $rows,
    'verdict' => $verdict,
));
