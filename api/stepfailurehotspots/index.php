<?php
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

function sfhOut($payload, $code = null)
{
    if ($code !== null) { http_response_code($code); }
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function sfhFail($httpCode, $code, $message = '')
{
    sfhOut(array('status' => 'error', 'code' => $code,
                 'message' => $message !== '' ? $message : $code), $httpCode);
}

function sfhPositiveInt($raw)
{
    if ($raw === null || is_array($raw)) { return 0; }
    $raw = trim((string)$raw);
    if ($raw === '' || !preg_match('/^[0-9]+$/', $raw)) { return 0; }
    $n = intval($raw);
    return $n > 0 ? $n : 0;
}

function sfhDecode($value)
{
    return html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function sfhText($value)
{
    return trim(preg_replace('/\s+/', ' ', strip_tags(sfhDecode($value))));
}

function sfhPct($part, $total)
{
    return $total > 0 ? round(100.0 * $part / $total, 1) : 0.0;
}

function sfhStatus($letter)
{
    switch (strtolower(trim((string)$letter))) {
        case 'p': return 'passed';
        case 'f': return 'failed';
        case 'b': return 'blocked';
    }
    return 'not_run';
}

function sfhCsvCell($value)
{
    $s = sfhDecode($value);
    if ($s !== '' && preg_match('/^\s*[=+\-@]/', $s)) { $s = "'" . $s; }
    return $s;
}

function sfhPath($db, $tbl, $nodeId, &$cache)
{
    $nodeId = intval($nodeId);
    if (isset($cache[$nodeId])) { return $cache[$nodeId]; }
    $parts = array();
    $current = $nodeId;
    for ($depth = 0; $depth < 12 && $current > 0; $depth++) {
        $row = $db->get_recordset("SELECT id, name, parent_id, node_type_id FROM " .
            $tbl['nodes_hierarchy'] . " WHERE id = " . intval($current));
        if (!is_array($row) || count($row) === 0) { break; }
        $r = $row[0];
        $parent = intval($r['parent_id']);
        if ($depth > 0 && intval($r['node_type_id']) !== 1) { $parts[] = sfhDecode($r['name']); }
        if (intval($r['node_type_id']) === 1 || $parent === $current) { break; }
        $current = $parent;
    }
    $cache[$nodeId] = implode(' / ', array_reverse($parts));
    return $cache[$nodeId];
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    sfhFail(405, 'method_not_allowed');
}

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) { sfhFail(401, 'not_authenticated', 'Not authenticated'); }
bffEnforceSession($db);
$user = tlUser::getByID($db, $userId);
if (is_null($user)) { sfhFail(401, 'not_authenticated', 'User not found'); }

$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if (!in_array($action, array('init', 'report', 'detail', 'csv'), true)) {
    sfhFail(400, 'invalid_request', 'Unknown action');
}

$tplanId = sfhPositiveInt($_GET['tplan_id'] ?? null);
$assertedProjectId = sfhPositiveInt($_GET['tproject_id'] ?? null);
$stepId = sfhPositiveInt($_GET['step_id'] ?? null);
$rawBuild = trim((string)($_GET['build_id'] ?? '0'));
$rawPlatform = trim((string)($_GET['platform_id'] ?? '0'));
$rawMin = trim((string)($_GET['min_failures'] ?? '1'));
$scope = strtolower(trim((string)($_GET['scope'] ?? 'all')));
if ($tplanId <= 0) { sfhFail(400, 'invalid_request', 'Missing or invalid tplan_id'); }
if (!preg_match('/^[0-9]+$/', $rawBuild) || !preg_match('/^[0-9]+$/', $rawPlatform) ||
    !preg_match('/^[0-9]+$/', $rawMin)) {
    sfhFail(400, 'invalid_request', 'Invalid filter');
}
if (!in_array($scope, array('all', 'failed', 'blocked', 'failing'), true)) {
    sfhFail(400, 'invalid_request', 'Invalid scope');
}
$minFailures = intval($rawMin);
if ($minFailures > 100) { sfhFail(400, 'invalid_request', 'Invalid min_failures'); }
if ($action === 'detail' && $stepId <= 0) { sfhFail(400, 'invalid_request', 'Missing step_id'); }
if ($action !== 'detail' && $stepId > 0) {
    sfhFail(400, 'invalid_request', 'step_id is only valid for detail');
}
$buildId = intval($rawBuild);
$platformId = intval($rawPlatform);

$tbl = tlObjectWithDB::getDBTables(array(
    'testplans', 'testprojects', 'nodes_hierarchy', 'tcversions', 'tcsteps',
    'builds', 'platforms', 'testplan_platforms', 'testplan_tcversions',
    'executions', 'execution_tcsteps', 'users',
));

$planRow = $db->get_recordset("SELECT id, testproject_id FROM {$tbl['testplans']} WHERE id = " .
    intval($tplanId));
if (!is_array($planRow) || count($planRow) === 0) { sfhFail(404, 'tplan_not_found', 'Test plan not found'); }
$owningProjectId = intval($planRow[0]['testproject_id']);
if ($owningProjectId <= 0) { sfhFail(404, 'tplan_not_found', 'Test plan has no owning project'); }
if (!$user->hasRight($db, 'testplan_metrics', $owningProjectId, $tplanId, true)) {
    tLog('BFF stepFailureHotspots: user ' . intval($userId) .
         ' has no testplan_metrics right on tproject ' . intval($owningProjectId) .
         ' (tplan ' . $tplanId . ')', 'AUDIT');
    sfhFail(403, 'no_right', 'testplan_metrics right required');
}
if ($assertedProjectId > 0 && $assertedProjectId !== $owningProjectId) {
    sfhFail(404, 'project_mismatch', 'Test plan belongs to another test project');
}
$planName = testplan::getName($db, $tplanId);
$projectName = testproject::getName($db, $owningProjectId);
if (is_null($planName) || trim((string)$planName) === '' ||
    is_null($projectName) || trim((string)$projectName) === '') {
    sfhFail(404, 'tplan_not_found', 'Test plan not found');
}

$builds = array();
$buildRs = $db->get_recordset("SELECT id, name FROM {$tbl['builds']} WHERE testproject_id = " .
    intval($owningProjectId) . " ORDER BY id");
if (is_array($buildRs)) {
    foreach ($buildRs as $b) { $builds[] = array('id' => intval($b['id']), 'name' => sfhDecode($b['name'])); }
}
$platforms = array();
$platformRs = $db->get_recordset("SELECT p.id, p.name FROM {$tbl['platforms']} p" .
    " JOIN {$tbl['testplan_platforms']} tpp ON tpp.platform_id = p.id" .
    " WHERE tpp.testplan_id = " . intval($tplanId) . " ORDER BY p.name");
if (is_array($platformRs)) {
    foreach ($platformRs as $p) { $platforms[] = array('id' => intval($p['id']), 'name' => sfhDecode($p['name'])); }
}
$knownBuild = ($buildId === 0);
foreach ($builds as $b) { if ($b['id'] === $buildId) { $knownBuild = true; break; } }
if (!$knownBuild) { sfhFail(400, 'invalid_request', 'build_id does not belong to this project'); }
$knownPlatform = ($platformId === 0);
foreach ($platforms as $p) { if ($p['id'] === $platformId) { $knownPlatform = true; break; } }
if (!$knownPlatform) { sfhFail(400, 'invalid_request', 'platform_id does not belong to this plan'); }

$buildNames = array();
foreach ($builds as $b) { $buildNames[$b['id']] = $b['name']; }
$platformNames = array();
foreach ($platforms as $p) { $platformNames[$p['id']] = $p['name']; }
$context = array(
    'tplan_id' => $tplanId, 'tplan_name' => sfhDecode((string)$planName),
    'tproject_id' => $owningProjectId, 'tproject_name' => sfhDecode((string)$projectName),
);
$filterEcho = array('build_id' => $buildId, 'platform_id' => $platformId,
                    'scope' => $scope, 'min_failures' => $minFailures);
$filters = array('builds' => $builds, 'platforms' => $platforms);
if ($action === 'init') {
    sfhOut(array('status' => 'ok', 'code' => 200, 'context' => $context,
                 'filters' => $filters, 'filter' => $filterEcho));
}

$stepRs = $db->get_recordset(
    "SELECT ts.id AS step_id, ts.step_number, ts.actions, ts.expected_results," .
    "       tv.id AS tcversion_id, tv.version, tv.tc_external_id," .
    "       nh_tc.id AS tcase_id, nh_tc.name AS tcase_name, tp.prefix" .
    " FROM {$tbl['tcsteps']} ts" .
    " JOIN {$tbl['nodes_hierarchy']} nhs ON nhs.id = ts.id" .
    " JOIN {$tbl['nodes_hierarchy']} nh_tv ON nh_tv.id = nhs.parent_id" .
    " JOIN {$tbl['tcversions']} tv ON tv.id = nh_tv.id" .
    " JOIN {$tbl['nodes_hierarchy']} nh_tc ON nh_tc.id = nh_tv.parent_id" .
    " JOIN {$tbl['testplan_tcversions']} tptv ON tptv.tcversion_id = tv.id" .
    "      AND tptv.testplan_id = " . intval($tplanId) .
    " JOIN {$tbl['testprojects']} tp ON tp.id = " . intval($owningProjectId) .
    " WHERE ts.active = 1" .
    "   AND (" . intval($platformId) . " = 0 OR tptv.platform_id = 0" .
    "        OR tptv.platform_id = " . intval($platformId) . ")" .
    " ORDER BY nh_tc.name, tv.version, ts.step_number, ts.id");
if (!is_array($stepRs)) { $stepRs = array(); }

$pathCache = array();
$steps = array();
foreach ($stepRs as $r) {
    $sid = intval($r['step_id']);
    if (isset($steps[$sid])) { continue; }
    $steps[$sid] = array(
        'step_id' => $sid, 'step_number' => intval($r['step_number']),
        'actions' => sfhText($r['actions']), 'expected' => sfhText($r['expected_results']),
        'tcversion_id' => intval($r['tcversion_id']), 'version' => intval($r['version']),
        'tcase_id' => intval($r['tcase_id']), 'tcase_name' => sfhDecode($r['tcase_name']),
        'external_id' => trim((string)$r['prefix']) . '-' . intval($r['tc_external_id']),
        'suite_path' => sfhPath($db, $tbl, intval($r['tcase_id']), $pathCache),
        'evidence' => 0, 'passed' => 0, 'failed' => 0, 'blocked' => 0,
        'not_run' => 0,
        'builds' => array(), 'platforms' => array(), 'testers' => array(),
        'latest_status' => 'not_run', 'latest_ts' => '', 'latest_ts_raw' => '',
        'latest_build' => '',
        'latest_platform' => '', 'latest_tester_id' => 0, 'latest_notes' => '',
        'runs' => array(),
    );
}

$evidenceRs = $db->get_recordset(
    "SELECT es.id AS evidence_id, es.execution_id, es.tcstep_id, es.status, es.notes," .
    "       e.build_id, e.platform_id, e.tester_id, e.execution_ts" .
    " FROM {$tbl['execution_tcsteps']} es" .
    " JOIN {$tbl['executions']} e ON e.id = es.execution_id" .
    " WHERE e.testplan_id = " . intval($tplanId) .
    "   AND EXISTS (SELECT 1 FROM {$tbl['testplan_tcversions']} tpt" .
    "               WHERE tpt.testplan_id = " . intval($tplanId) .
    "                 AND tpt.tcversion_id = e.tcversion_id" .
    "                 AND (tpt.platform_id = 0 OR tpt.platform_id = e.platform_id))" .
    "   AND (" . intval($platformId) . " = 0 OR e.platform_id = " . intval($platformId) . ")" .
    "   AND (" . intval($buildId) . " = 0 OR e.build_id = " . intval($buildId) . ")" .
    " ORDER BY e.execution_ts, e.id, es.id");
if (!is_array($evidenceRs)) { $evidenceRs = array(); }

$testerIds = array();
foreach ($evidenceRs as $e) {
    $sid = intval($e['tcstep_id']);
    if (!isset($steps[$sid])) { continue; }
    $s = &$steps[$sid];
    $sv = sfhStatus($e['status']);
    $s['evidence']++;
    $s[$sv]++;
    $bid = intval($e['build_id']);
    $pid = intval($e['platform_id']);
    $tid = intval($e['tester_id']);
    if ($bid > 0 && !isset($s['builds'][$bid]) && isset($buildNames[$bid])) {
        $s['builds'][$bid] = $buildNames[$bid];
    }
    if ($pid > 0 && !isset($s['platforms'][$pid]) && isset($platformNames[$pid])) {
        $s['platforms'][$pid] = $platformNames[$pid];
    }
    if ($tid > 0) { $testerIds[$tid] = true; }
    if (count($s['runs']) < 50) {
        $s['runs'][] = array(
            'execution_id' => intval($e['execution_id']), 'status' => $sv,
            'notes' => sfhText($e['notes']),
            'build' => isset($buildNames[$bid]) ? $buildNames[$bid] : '',
            'platform' => isset($platformNames[$pid]) ? $platformNames[$pid] : '',
            'tester_id' => $tid, 'tester' => '',
            'execution_ts' => sfhText($e['execution_ts']),
        );
    }
    $rawTs = (string)$e['execution_ts'];
    $sameTime = ($rawTs === (string)$s['latest_ts_raw']);
    if ((string)$s['latest_ts_raw'] === '' || $rawTs > (string)$s['latest_ts_raw'] ||
        ($sameTime && intval($e['execution_id']) >= intval($s['latest_exec_id']))) {
        $s['latest_exec_id'] = intval($e['execution_id']);
        $s['latest_ts_raw'] = $rawTs;
        $s['latest_ts'] = sfhText($rawTs);
        $s['latest_status'] = $sv;
        $s['latest_build'] = isset($buildNames[$bid]) ? $buildNames[$bid] : '';
        $s['latest_platform'] = isset($platformNames[$pid]) ? $platformNames[$pid] : '';
        $s['latest_tester_id'] = $tid;
        $s['latest_notes'] = sfhText($e['notes']);
    }
    unset($s);
}

$testerNames = array();
if (count($testerIds) > 0) {
    $userRs = $db->get_recordset("SELECT id, login, first, last FROM {$tbl['users']} WHERE id IN (" .
        implode(',', array_keys($testerIds)) . ")");
    if (is_array($userRs)) {
        foreach ($userRs as $u) {
            $name = trim(sfhDecode($u['first']) . ' ' . sfhDecode($u['last']));
            $testerNames[intval($u['id'])] = $name !== '' ? $name : sfhDecode($u['login']);
        }
    }
}

foreach ($steps as &$s) {
    foreach ($s['runs'] as $i => $r) {
        $s['runs'][$i]['tester'] = isset($testerNames[$r['tester_id']]) ?
            $testerNames[$r['tester_id']] : '';
    }
    $s['latest_tester'] = isset($testerNames[$s['latest_tester_id']]) ?
        $testerNames[$s['latest_tester_id']] : '';
    unset($s['latest_tester_id'], $s['latest_exec_id'], $s['latest_ts_raw']);
    $s['builds'] = array_values($s['builds']);
    $s['platforms'] = array_values($s['platforms']);
    $s['score'] = 2 * $s['failed'] + $s['blocked'];
    $s['failure_rate'] = sfhPct($s['failed'], $s['evidence']);
    if ($s['failed'] >= 3 || ($s['failed'] > 0 && $s['failure_rate'] >= 50)) {
        $s['verdict'] = 'critical';
    } elseif ($s['failed'] > 0) {
        $s['verdict'] = 'high';
    } elseif ($s['blocked'] > 0) {
        $s['verdict'] = 'blocked';
    } elseif ($s['evidence'] > 0) {
        $s['verdict'] = 'clear';
    } else {
        $s['verdict'] = 'no_data';
    }
}
unset($s);

$rows = array();
foreach ($steps as $s) {
    if ($scope === 'failed' && $s['failed'] < 1) { continue; }
    if ($scope === 'blocked' && $s['blocked'] < 1) { continue; }
    if ($scope === 'failing' && $s['score'] < 1) { continue; }
    if ($s['score'] < $minFailures) { continue; }
    $rows[] = $s;
}
usort($rows, function ($a, $b) {
    if ($a['score'] !== $b['score']) { return $b['score'] - $a['score']; }
    if ($a['failed'] !== $b['failed']) { return $b['failed'] - $a['failed']; }
    if ($a['blocked'] !== $b['blocked']) { return $b['blocked'] - $a['blocked']; }
    $c = strcmp($a['tcase_name'], $b['tcase_name']);
    if ($c !== 0) { return $c; }
    return $a['step_number'] - $b['step_number'];
});

$sum = array('evidence' => 0, 'passed' => 0, 'failed' => 0, 'blocked' => 0, 'not_run' => 0);
$critical = 0; $high = 0; $blockedSteps = 0; $affectedCases = array(); $suites = array();
foreach ($steps as $s) {
    foreach (array_keys($sum) as $k) { $sum[$k] += $s[$k]; }
    if ($s['verdict'] === 'critical') { $critical++; }
    if ($s['verdict'] === 'high') { $high++; }
    if ($s['blocked'] > 0) { $blockedSteps++; }
    if ($s['score'] > 0) { $affectedCases[$s['tcase_id']] = true; }
    if ($s['suite_path'] !== '') { $suites[$s['suite_path']] = true; }
}
$noEvidence = 0;
foreach ($steps as $s) { if ($s['evidence'] === 0) { $noEvidence++; } }
if (count($steps) === 0 || $sum['evidence'] === 0) {
    $verdict = 'no_data';
} elseif ($critical > 0) {
    $verdict = 'at_risk';
} elseif ($high > 0 || $blockedSteps > 0) {
    $verdict = 'watch';
} else {
    $verdict = 'clear';
}
$kpi = array(
    'design_steps' => count($steps),
    'step_results' => $sum['evidence'],
    'passed' => $sum['passed'],
    'failed' => $sum['failed'],
    'blocked' => $sum['blocked'],
    'not_run_steps' => $noEvidence,
    'failure_rate' => sfhPct($sum['failed'], $sum['evidence']),
    'critical_steps' => $critical,
    'high_steps' => $high,
    'blocked_steps' => $blockedSteps,
    'affected_cases' => count($affectedCases),
    'affected_suites' => count($suites),
    'verdict' => $verdict,
);

if ($action === 'detail') {
    $detail = null;
    foreach ($steps as $s) { if ($s['step_id'] === $stepId) { $detail = $s; break; } }
    if (is_null($detail)) { sfhFail(404, 'step_not_found', 'Step not found in this plan'); }
    sfhOut(array('status' => 'ok', 'code' => 200, 'context' => $context,
                 'filter' => $filterEcho, 'step' => $detail));
}

if ($action === 'csv') {
    $out = fopen('php://temp', 'r+');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, array('test_case', 'external_id', 'version', 'suite', 'step', 'actions',
        'expected', 'verdict', 'score', 'evidence', 'passed', 'failed', 'blocked',
        'failure_rate', 'latest_status', 'latest_build', 'latest_platform',
        'latest_tester', 'latest_ts', 'latest_notes'), ';');
    foreach ($rows as $r) {
        fputcsv($out, array(
            sfhCsvCell($r['tcase_name']), sfhCsvCell($r['external_id']), $r['version'],
            sfhCsvCell($r['suite_path']), $r['step_number'], sfhCsvCell($r['actions']),
            sfhCsvCell($r['expected']), $r['verdict'], $r['score'], $r['evidence'],
            $r['passed'], $r['failed'], $r['blocked'], $r['failure_rate'],
            $r['latest_status'], sfhCsvCell($r['latest_build']),
            sfhCsvCell($r['latest_platform']), sfhCsvCell($r['latest_tester']),
            sfhCsvCell($r['latest_ts']), sfhCsvCell($r['latest_notes']),
        ), ';');
    }
    rewind($out);
    $csv = stream_get_contents($out);
    fclose($out);
    sfhOut(array('status' => 'ok', 'code' => 200, 'csv' => $csv, 'rows' => count($rows)));
}

sfhOut(array('status' => 'ok', 'code' => 200, 'context' => $context,
             'filters' => $filters, 'filter' => $filterEcho,
             'kpi' => $kpi, 'rows' => array_values($rows)));
