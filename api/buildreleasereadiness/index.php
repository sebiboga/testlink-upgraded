<?php
/**
 * Test Plan Build Release Readiness - BFF (Refs #2087).
 *
 * Net-new read-only 2.0.1 report (gui/templates/results/buildReleaseReadiness.html).
 * 1.9.20/2.0.1 show the current result of a plan on a build but answer no
 * release question: every build of a plan is judged here from the LATEST
 * execution of each assigned test-case version inside that build.
 *
 * Actions (GET|HEAD):
 *   ?action=init&tplan_id=N[&tproject_id=P]
 *   ?action=report&tplan_id=N[&tproject_id=P][&state=all|active|closed][&platform_id=P][&verdict=all|ready|at_risk|not_ready|no_data]
 *   ?action=detail&tplan_id=N&build_id=B[&tproject_id=P][&platform_id=P]
 *
 * Security: session auth + bffSameOriginGuard + bffEnforceSession. The
 * `testplan_metrics` right is checked on the OWNING project before the client
 * project assertion (no existence oracle); denials are logged at AUDIT.
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

function brrOut($payload, $code = null)
{
    if ($code !== null) {
        http_response_code($code);
    }
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function brrFail($httpCode, $code, $message = '')
{
    brrOut(array('status' => 'error', 'code' => $code,
                 'message' => $message !== '' ? $message : $code), $httpCode);
}

function brrInt($raw)
{
    if ($raw === null || is_array($raw)) {
        return 0;
    }
    $raw = trim((string)$raw);
    if ($raw === '' || !preg_match('/^[0-9]+$/', $raw)) {
        return 0;
    }
    return intval($raw);
}

function brrText($value)
{
    return html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function brrPct($part, $total)
{
    return $total > 0 ? round(100.0 * $part / $total, 1) : 0.0;
}

function brrStatus($raw)
{
    $s = strtolower(trim((string)$raw));
    return ($s === 'p' || $s === 'f' || $s === 'b') ? $s : 'n';
}

function brrVerdict($assigned, $executed, $counts, $bugs)
{
    if ($assigned <= 0 || $executed <= 0) {
        return 'no_data';
    }
    $coverage = $executed / $assigned;
    $pass = $counts['p'] / $executed;
    if ($coverage >= 0.9 && $pass >= 0.95 && $counts['f'] === 0 && $counts['b'] === 0 && $bugs === 0) {
        return 'ready';
    }
    if ($coverage >= 0.7 && $pass >= 0.8) {
        return 'at_risk';
    }
    return 'not_ready';
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    brrFail(405, 'method_not_allowed', 'Only GET and HEAD are supported');
}

$db = new database(DB_TYPE);
doDBConnect($db);
$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    brrFail(401, 'not_authenticated', 'Not authenticated');
}
bffEnforceSession($db);
$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    brrFail(401, 'not_authenticated', 'User not found');
}

$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if (!in_array($action, array('init', 'report', 'detail'), true)) {
    brrFail(400, 'invalid_request', 'Unknown action');
}
$tplanId = brrInt($_GET['tplan_id'] ?? null);
$assertedProjectId = brrInt($_GET['tproject_id'] ?? null);
$buildId = brrInt($_GET['build_id'] ?? null);
$platformId = brrInt($_GET['platform_id'] ?? null);
$state = strtolower(trim((string)($_GET['state'] ?? 'all')));
$verdictFilter = strtolower(trim((string)($_GET['verdict'] ?? 'all')));
if ($tplanId <= 0) {
    brrFail(400, 'invalid_request', 'Missing or invalid tplan_id');
}
if (!in_array($state, array('all', 'active', 'closed'), true)) {
    brrFail(400, 'invalid_request', 'Invalid state filter');
}
if (!in_array($verdictFilter, array('all', 'ready', 'at_risk', 'not_ready', 'no_data'), true)) {
    brrFail(400, 'invalid_request', 'Invalid verdict filter');
}

$tbl = tlObjectWithDB::getDBTables(array('testplans', 'builds', 'platforms',
    'testplan_tcversions', 'tcversions', 'nodes_hierarchy', 'executions', 'execution_bugs'));
$planRows = $db->get_recordset("SELECT id, testproject_id FROM {$tbl['testplans']} WHERE id = " . intval($tplanId));
$owningProjectId = (is_array($planRows) && count($planRows) > 0)
    ? intval($planRows[0]['testproject_id']) : 0;
if ($owningProjectId <= 0 || !$user->hasRight($db, 'testplan_metrics', $owningProjectId, $tplanId, true)) {
    tLog('BFF buildreleasereadiness: user ' . intval($userId) .
         ' denied tplan ' . $tplanId . ' (project ' . $owningProjectId . ')', 'AUDIT');
    brrFail(403, 'no_right', 'testplan_metrics right required');
}
if ($assertedProjectId > 0 && $assertedProjectId !== $owningProjectId) {
    brrFail(404, 'project_mismatch', 'Test plan does not belong to this test project');
}
$planName = testplan::getName($db, $tplanId);
$projectName = testproject::getName($db, $owningProjectId);
if (is_null($planName) || trim((string)$planName) === '') {
    brrFail(404, 'tplan_not_found', 'Test plan not found');
}

$builds = array();
$rs = $db->get_recordset("SELECT id, name, active, is_open, release_date, closed_on_date " .
    "FROM {$tbl['builds']} WHERE testproject_id = " . intval($owningProjectId) . " ORDER BY id");
if (is_array($rs)) {
    foreach ($rs as $r) {
        $isOpen = intval($r['is_open']) === 1;
        $builds[] = array(
            'id' => intval($r['id']),
            'name' => brrText($r['name']),
            'active' => intval($r['active']) === 1,
            'open' => $isOpen,
            'state' => intval($r['active']) === 1 ? 'active' : 'closed',
            'release_date' => $r['release_date'] !== null ? (string)$r['release_date'] : '',
            'closed_on_date' => $r['closed_on_date'] !== null ? (string)$r['closed_on_date'] : '',
        );
    }
}
$platforms = array();
$rs = $db->get_recordset("SELECT id, name FROM {$tbl['platforms']} WHERE testproject_id = " .
    intval($owningProjectId) . " ORDER BY name");
if (is_array($rs)) {
    foreach ($rs as $r) {
        $platforms[] = array('id' => intval($r['id']), 'name' => brrText($r['name']));
    }
}
if ($buildId > 0) {
    $found = false;
    foreach ($builds as $b) {
        if ($b['id'] === $buildId) { $found = true; break; }
    }
    if (!$found) {
        brrFail(400, 'invalid_request', 'build_id does not belong to this test project');
    }
}
if ($platformId > 0) {
    $found = false;
    foreach ($platforms as $p) {
        if ($p['id'] === $platformId) { $found = true; break; }
    }
    if (!$found) {
        brrFail(400, 'invalid_request', 'platform_id does not belong to this test project');
    }
}
$context = array(
    'tplan_id' => $tplanId,
    'tplan_name' => brrText((string)$planName),
    'tproject_id' => $owningProjectId,
    'tproject_name' => brrText((string)$projectName),
);
$filters = array('builds' => $builds, 'platforms' => $platforms,
                 'states' => array('all', 'active', 'closed'),
                 'verdicts' => array('all', 'ready', 'at_risk', 'not_ready', 'no_data'));
$filter = array('build_id' => $buildId, 'platform_id' => $platformId,
                'state' => $state, 'verdict' => $verdictFilter);
if ($action === 'init') {
    brrOut(array('status' => 'ok', 'code' => 200, 'context' => $context,
                 'filters' => $filters, 'filter' => $filter));
}

$linkSql = "SELECT tpt.tcversion_id, tpt.platform_id, tv.importance, tv.version, " .
    "tcn.name AS tcase_name, stn.name AS suite_name " .
    "FROM {$tbl['testplan_tcversions']} tpt " .
    "JOIN {$tbl['tcversions']} tv ON tv.id = tpt.tcversion_id " .
    "JOIN {$tbl['nodes_hierarchy']} vnode ON vnode.id = tpt.tcversion_id " .
    "JOIN {$tbl['nodes_hierarchy']} tcn ON tcn.id = vnode.parent_id " .
    "LEFT JOIN {$tbl['nodes_hierarchy']} stn ON stn.id = tcn.parent_id " .
    "WHERE tpt.testplan_id = " . intval($tplanId);
if ($platformId > 0) {
    $linkSql .= " AND (tpt.platform_id = " . intval($platformId) . " OR tpt.platform_id = 0)";
}
$linkRows = $db->get_recordset($linkSql);
$links = array();
if (is_array($linkRows)) {
    foreach ($linkRows as $r) {
        $tcv = intval($r['tcversion_id']);
        $pl = intval($platformId) > 0 ? $platformId : intval($r['platform_id']);
        $links[] = array('tcversion_id' => $tcv, 'platform_id' => $pl,
            'link_platform_id' => intval($r['platform_id']),
            'importance' => intval($r['importance']),
            'version' => intval($r['version']),
            'name' => brrText($r['tcase_name']),
            'suite' => brrText($r['suite_name']));
    }
}

$execSql = "SELECT e.id, e.build_id, e.tcversion_id, e.platform_id, e.status, " .
    "e.execution_ts, e.tester_id, e.notes FROM {$tbl['executions']} e " .
    "WHERE e.testplan_id = " . intval($tplanId) . " AND e.execution_ts IS NOT NULL";
if ($buildId > 0) {
    $execSql .= " AND e.build_id = " . intval($buildId);
}
if ($platformId > 0) {
    $execSql .= " AND (e.platform_id = " . intval($platformId) . " OR e.platform_id = 0)";
}
$execSql .= " ORDER BY e.id ASC";
$execRows = $db->get_recordset($execSql);
$execById = array();
$exact = array();
$globalLatest = array();
$bugByExec = array();
if (is_array($execRows)) {
    foreach ($execRows as $e) {
        $id = intval($e['id']);
        $build = intval($e['build_id']);
        $tcv = intval($e['tcversion_id']);
        $plat = intval($e['platform_id']);
        $execById[$id] = array('id' => $id, 'build_id' => $build,
            'tcversion_id' => $tcv, 'platform_id' => $plat,
            'status' => brrStatus($e['status']),
            'ts' => (string)$e['execution_ts'],
            'tester_id' => intval($e['tester_id']),
            'notes' => brrText($e['notes']));
        $exact[$build . '/' . $tcv . '/' . $plat] = $id;
        if ($platformId <= 0 || $plat === $platformId || $plat === 0) {
            $globalLatest[$build . '/' . $tcv] = $id;
        }
    }
}
$ids = array_keys($execById);
if (count($ids) > 0) {
    $rs = $db->get_recordset("SELECT execution_id, bug_id FROM {$tbl['execution_bugs']} " .
        "WHERE execution_id IN (" . implode(',', array_map('intval', $ids)) . ")");
    if (is_array($rs)) {
        foreach ($rs as $r) {
            $eid = intval($r['execution_id']);
            if (!isset($execById[$eid])) { continue; }
            $bug = brrText($r['bug_id']);
            if ($bug === '') { continue; }
            $bugByExec[$eid][$bug] = true;
        }
    }
}

function brrLatestExec($execById, $exact, $globalLatest, $buildId, $tcversionId, $linkPlatformId)
{
    if (intval($linkPlatformId) > 0) {
        $key = intval($buildId) . '/' . intval($tcversionId) . '/' . intval($linkPlatformId);
        return isset($exact[$key]) ? $execById[$exact[$key]] : null;
    }
    $key = intval($buildId) . '/' . intval($tcversionId);
    return isset($globalLatest[$key]) ? $execById[$globalLatest[$key]] : null;
}

$allRows = array();
$allDefects = array();
foreach ($builds as $build) {
    $counts = array('p' => 0, 'f' => 0, 'b' => 0, 'n' => 0);
    $bugIds = array();
    $caseRows = array();
    foreach ($links as $link) {
        $exec = brrLatestExec($execById, $exact, $globalLatest,
            $build['id'], $link['tcversion_id'], $link['link_platform_id']);
        $status = $exec === null ? 'n' : $exec['status'];
        $counts[$status]++;
        $execBugs = array();
        if ($exec !== null && isset($bugByExec[$exec['id']])) {
            $execBugs = array_keys($bugByExec[$exec['id']]);
        }
        foreach ($execBugs as $bug) {
            $bugIds[$bug] = true;
        }
        $caseRows[] = array('tcversion_id' => $link['tcversion_id'],
            'name' => $link['name'], 'suite' => $link['suite'],
            'version' => $link['version'], 'importance' => $link['importance'],
            'platform_id' => $link['platform_id'],
            'status' => $status, 'execution_ts' => $exec === null ? '' : $exec['ts'],
            'tester_id' => $exec === null ? 0 : $exec['tester_id'],
            'notes' => $exec === null ? '' : $exec['notes'],
            'bugs' => $execBugs);
    }
    $assigned = count($links);
    $executed = $counts['p'] + $counts['f'] + $counts['b'];
    $allRows[] = array('build_id' => $build['id'], 'name' => $build['name'],
        'state' => $build['state'], 'open' => $build['open'],
        'release_date' => $build['release_date'], 'closed_on_date' => $build['closed_on_date'],
        'assigned' => $assigned, 'executed' => $executed,
        'counts' => $counts, 'coverage_pct' => brrPct($executed, $assigned),
        'pass_rate' => brrPct($counts['p'], $executed), 'defects' => count($bugIds),
        'bug_ids' => array_keys($bugIds),
        'verdict' => brrVerdict($assigned, $executed, $counts, count($bugIds)),
        'cases' => $caseRows);
}

$payload = array('status' => 'ok', 'code' => 200, 'context' => $context,
    'filters' => $filters, 'filter' => $filter, 'today' => date('Y-m-d'));

if ($action === 'detail') {
    $detail = null;
    foreach ($allRows as $r) {
        if ($r['build_id'] === $buildId) { $detail = $r; break; }
    }
    if ($detail === null) {
        brrFail(404, 'build_not_found', 'Build not found in this scope');
    }
    $payload['detail'] = $detail;
    brrOut($payload);
}

$rows = array();
foreach ($allRows as $r) {
    if ($state === 'active' && $r['state'] !== 'active') { continue; }
    if ($state === 'closed' && $r['state'] !== 'closed') { continue; }
    if ($buildId > 0 && $r['build_id'] !== $buildId) { continue; }
    if ($verdictFilter !== 'all' && $r['verdict'] !== $verdictFilter) { continue; }
    $rows[] = $r;
}

$kpi = array('builds' => count($rows), 'assigned' => 0, 'executed' => 0, 'passed' => 0,
    'failed' => 0, 'blocked' => 0, 'not_run' => 0, 'defects' => 0,
    'coverage_pct' => 0.0, 'pass_rate' => 0.0, 'ready' => 0,
    'at_risk' => 0, 'not_ready' => 0, 'no_data' => 0);
foreach ($rows as $r) {
    foreach ($r['bug_ids'] as $bug) { $allDefects[$bug] = true; }
    $kpi['assigned'] += $r['assigned'];
    $kpi['executed'] += $r['executed'];
    $kpi['passed'] += $r['counts']['p'];
    $kpi['failed'] += $r['counts']['f'];
    $kpi['blocked'] += $r['counts']['b'];
    $kpi['not_run'] += $r['counts']['n'];
    $kpi[$r['verdict']]++;
}
$kpi['defects'] = count($allDefects);
$kpi['coverage_pct'] = brrPct($kpi['executed'], $kpi['assigned']);
$kpi['pass_rate'] = brrPct($kpi['passed'], $kpi['executed']);
$payload['kpi'] = $kpi;
$payload['rows'] = $rows;
brrOut($payload);
