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

function serOut($payload, $code = null)
{
    if ($code !== null) {
        http_response_code($code);
    }
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function serFail($httpCode, $code, $message = '')
{
    serOut(array('status' => 'error', 'code' => $code,
                 'message' => $message !== '' ? $message : $code), $httpCode);
}

function serPositiveInt($raw)
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

function serDecode($value)
{
    return html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function serText($value)
{
    return trim(preg_replace('/\s+/', ' ', strip_tags(serDecode($value))));
}

function serPct($part, $total)
{
    return $total > 0 ? round(100.0 * $part / $total, 1) : 0.0;
}

function serStatus($letter)
{
    switch (strtolower(trim((string)$letter))) {
        case 'p': return 'passed';
        case 'f': return 'failed';
        case 'b': return 'blocked';
    }
    return 'not_run';
}

function serCsvCell($value)
{
    $s = serDecode($value);
    if ($s !== '' && preg_match('/^[\s]*[=+\-@]/', $s)) {
        $s = "'" . $s;
    }
    return str_replace(array('"', "\r", "\n"), array('""', ' ', ' '), $s);
}

function serPath($db, $tbl, $nodeId, &$cache)
{
    $nodeId = intval($nodeId);
    if (isset($cache[$nodeId])) {
        return $cache[$nodeId];
    }
    $parts = array();
    $current = $nodeId;
    for ($depth = 0; $depth < 12 && $current > 0; $depth++) {
        $row = $db->get_recordset(
            "SELECT id, name, parent_id, node_type_id FROM {$tbl['nodes_hierarchy']}" .
            " WHERE id = " . intval($current));
        if (!is_array($row) || count($row) === 0) {
            break;
        }
        $r = $row[0];
        $parent = intval($r['parent_id']);
        if ($depth > 0 && intval($r['node_type_id']) !== 1) {
            $parts[] = serDecode($r['name']);
        }
        if (intval($r['node_type_id']) === 1 || $parent === $current) {
            break;
        }
        $current = $parent;
    }
    $cache[$nodeId] = implode(' / ', array_reverse($parts));
    return $cache[$nodeId];
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    serFail(405, 'method_not_allowed');
}

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    serFail(401, 'not_authenticated', 'Not authenticated');
}
bffEnforceSession($db);

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    serFail(401, 'not_authenticated', 'User not found');
}

$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if (!in_array($action, array('init', 'report', 'detail', 'csv'), true)) {
    serFail(400, 'invalid_request', 'Unknown action');
}

$tplanId = serPositiveInt($_GET['tplan_id'] ?? null);
$assertedProjectId = serPositiveInt($_GET['tproject_id'] ?? null);
$stepId = serPositiveInt($_GET['step_id'] ?? null);
$rawBuild = trim((string)($_GET['build_id'] ?? '0'));
$rawPlatform = trim((string)($_GET['platform_id'] ?? '0'));
$status = strtolower(trim((string)($_GET['status'] ?? 'all')));
$scope = strtolower(trim((string)($_GET['scope'] ?? 'all')));
if ($tplanId <= 0) {
    serFail(400, 'invalid_request', 'Missing or invalid tplan_id');
}
if (!preg_match('/^[0-9]+$/', $rawBuild) || !preg_match('/^[0-9]+$/', $rawPlatform)) {
    serFail(400, 'invalid_request', 'Invalid filter');
}
if (!in_array($status, array('all', 'p', 'f', 'b'), true)) {
    serFail(400, 'invalid_request', 'Invalid status');
}
if (!in_array($scope, array('all', 'evidence', 'failed', 'blocked', 'passed', 'flaky'), true)) {
    serFail(400, 'invalid_request', 'Invalid scope');
}
if ($action === 'detail' && $stepId <= 0) {
    serFail(400, 'invalid_request', 'Missing step_id');
}
if ($action !== 'detail' && $stepId > 0) {
    serFail(400, 'invalid_request', 'step_id is only valid for the detail action');
}
$buildId = intval($rawBuild);
$platformId = intval($rawPlatform);

$tbl = tlObjectWithDB::getDBTables(array(
    'testplans', 'testprojects', 'nodes_hierarchy', 'tcversions', 'tcsteps',
    'builds', 'platforms', 'testplan_platforms', 'testplan_tcversions',
    'executions', 'execution_tcsteps', 'users',
));

$planRow = $db->get_recordset(
    "SELECT id, testproject_id FROM {$tbl['testplans']} WHERE id = " . intval($tplanId));
if (!is_array($planRow) || count($planRow) === 0) {
    serFail(404, 'tplan_not_found', 'Test plan not found');
}
$owningProjectId = intval($planRow[0]['testproject_id']);
if ($owningProjectId <= 0) {
    serFail(404, 'tplan_not_found', 'Test plan has no owning test project');
}
if (!$user->hasRight($db, 'testplan_metrics', $owningProjectId, $tplanId, true)) {
    tLog('BFF stepExecutionResults: user ' . intval($userId) .
         ' has no testplan_metrics right on tproject ' . intval($owningProjectId) .
         ' (tplan ' . $tplanId . ')', 'AUDIT');
    serFail(403, 'no_right', 'testplan_metrics right required');
}
if ($assertedProjectId > 0 && $assertedProjectId !== $owningProjectId) {
    serFail(404, 'project_mismatch', 'Test plan belongs to another test project');
}

$planName = testplan::getName($db, $tplanId);
$projectName = testproject::getName($db, $owningProjectId);
if (is_null($planName) || trim((string)$planName) === '' ||
    is_null($projectName) || trim((string)$projectName) === '') {
    serFail(404, 'tplan_not_found', 'Test plan not found');
}

$builds = array();
$buildRs = $db->get_recordset(
    "SELECT id, name FROM {$tbl['builds']} WHERE testproject_id = " . intval($owningProjectId) .
    " ORDER BY id");
if (is_array($buildRs)) {
    foreach ($buildRs as $b) {
        $builds[] = array('id' => intval($b['id']), 'name' => serDecode($b['name']));
    }
}
$platforms = array();
$platformRs = $db->get_recordset(
    "SELECT p.id, p.name FROM {$tbl['platforms']} p" .
    " JOIN {$tbl['testplan_platforms']} tpp ON tpp.platform_id = p.id" .
    " WHERE tpp.testplan_id = " . intval($tplanId) . " ORDER BY p.name");
if (is_array($platformRs)) {
    foreach ($platformRs as $p) {
        $platforms[] = array('id' => intval($p['id']), 'name' => serDecode($p['name']));
    }
}
$knownBuild = ($buildId === 0);
foreach ($builds as $b) { if ($b['id'] === $buildId) { $knownBuild = true; break; } }
if (!$knownBuild) {
    serFail(400, 'invalid_request', 'build_id does not belong to this test project');
}
$knownPlatform = ($platformId === 0);
foreach ($platforms as $p) { if ($p['id'] === $platformId) { $knownPlatform = true; break; } }
if (!$knownPlatform) {
    serFail(400, 'invalid_request', 'platform_id does not belong to this test plan');
}

$buildNames = array();
foreach ($builds as $b) { $buildNames[$b['id']] = $b['name']; }
$platformNames = array();
foreach ($platforms as $p) { $platformNames[$p['id']] = $p['name']; }

$context = array(
    'tplan_id' => $tplanId,
    'tplan_name' => serDecode((string)$planName),
    'tproject_id' => $owningProjectId,
    'tproject_name' => serDecode((string)$projectName),
);
$filterEcho = array(
    'build_id' => $buildId, 'platform_id' => $platformId,
    'status' => $status, 'scope' => $scope,
);
if ($action === 'init') {
    serOut(array(
        'status' => 'ok', 'code' => 200,
        'context' => $context,
        'filters' => array('builds' => $builds, 'platforms' => $platforms),
        'filter' => $filterEcho,
    ));
}

$stepRs = $db->get_recordset(
    "SELECT ts.id AS step_id, ts.step_number, ts.actions, ts.expected_results," .
    "       tv.id AS tcversion_id, tv.version, tv.tc_external_id," .
    "       nh_tc.id AS tcase_id, nh_tc.name AS tcase_name," .
    "       tp.prefix" .
    " FROM {$tbl['tcsteps']} ts" .
    " JOIN {$tbl['nodes_hierarchy']} nhs ON nhs.id = ts.id" .
    " JOIN {$tbl['nodes_hierarchy']} nh_tv ON nh_tv.id = nhs.parent_id" .
    " JOIN {$tbl['tcversions']} tv ON tv.id = nh_tv.id" .
    " JOIN {$tbl['nodes_hierarchy']} nh_tc ON nh_tc.id = nh_tv.parent_id" .
    " JOIN {$tbl['testplan_tcversions']} tptv ON tptv.tcversion_id = tv.id" .
    "      AND tptv.testplan_id = " . intval($tplanId) .
    " JOIN {$tbl['testprojects']} tp ON tp.id = " . intval($owningProjectId) .
    " WHERE (" . intval($platformId) . " = 0 OR tptv.platform_id = 0" .
    "        OR tptv.platform_id = " . intval($platformId) . ")" .
    " ORDER BY nh_tc.name, tv.version, ts.step_number, ts.id");
if (!is_array($stepRs)) { $stepRs = array(); }

$pathCache = array();
$steps = array();
foreach ($stepRs as $r) {
    $sid = intval($r['step_id']);
    if (isset($steps[$sid])) {
        continue;
    }
    $steps[$sid] = array(
        'step_id' => $sid,
        'step_number' => intval($r['step_number']),
        'actions' => serText($r['actions']),
        'expected' => serText($r['expected_results']),
        'tcversion_id' => intval($r['tcversion_id']),
        'tcase_id' => intval($r['tcase_id']),
        'tcase_name' => serDecode($r['tcase_name']),
        'external_id' => trim((string)$r['prefix']) . '-' . intval($r['tc_external_id']),
        'version' => intval($r['version']),
        'suite_path' => serPath($db, $tbl, intval($r['tcase_id']), $pathCache),
        'evidence' => 0, 'passed' => 0, 'failed' => 0, 'blocked' => 0,
        'not_run' => 0, 'verdict' => 'not_run', 'latest_status' => 'not_run',
        'latest_status_letter' => '', 'latest_ts' => '', 'latest_build' => '',
        'latest_platform' => '', 'latest_tester' => '', 'latest_notes' => '',
        'runs' => array(),
    );
}

$evidenceRs = $db->get_recordset(
    "SELECT es.id AS evidence_id, es.execution_id, es.tcstep_id, es.status, es.notes," .
    "       e.tcversion_id, e.build_id, e.platform_id, e.tester_id, e.execution_ts," .
    "       tptv.platform_id AS assigned_platform_id" .
    " FROM {$tbl['execution_tcsteps']} es" .
    " JOIN {$tbl['executions']} e ON e.id = es.execution_id" .
    " JOIN {$tbl['testplan_tcversions']} tptv ON tptv.tcversion_id = e.tcversion_id" .
    "      AND tptv.testplan_id = " . intval($tplanId) .
    " WHERE (" . intval($platformId) . " = 0 OR e.platform_id = " . intval($platformId) . ")" .
    "   AND (" . intval($buildId) . " = 0 OR e.build_id = " . intval($buildId) . ")" .
    " ORDER BY e.execution_ts, e.id, es.id");
if (!is_array($evidenceRs)) { $evidenceRs = array(); }

foreach ($evidenceRs as $e) {
    $sid = intval($e['tcstep_id']);
    if (!isset($steps[$sid])) {
        continue;
    }
    $s = &$steps[$sid];
    $sv = serStatus($e['status']);
    $s['evidence']++;
    $s[$sv]++;
    $letter = strtolower(trim((string)$e['status']));
    $run = array(
        'evidence_id' => intval($e['evidence_id']),
        'execution_id' => intval($e['execution_id']),
        'status' => $sv,
        'status_letter' => $letter,
        'notes' => serText($e['notes']),
        'build' => isset($buildNames[intval($e['build_id'])]) ?
            $buildNames[intval($e['build_id'])] : '',
        'platform' => isset($platformNames[intval($e['platform_id'])]) ?
            $platformNames[intval($e['platform_id'])] : '',
        'tester_id' => intval($e['tester_id']),
        'tester' => '',
        'execution_ts' => serText($e['execution_ts']),
    );
    $s['runs'][] = $run;
    $currentExecId = intval($e['execution_id']);
    $latestExecId = isset($s['latest_exec_id']) ? $s['latest_exec_id'] : 0;
    if ((string)$e['execution_ts'] > (string)$s['latest_ts'] ||
        $s['latest_ts'] === '' ||
        ((string)$e['execution_ts'] === (string)$s['latest_ts'] && $currentExecId > $latestExecId)) {
        $s['latest_exec_id'] = $currentExecId;
        $s['latest_ts'] = serText($e['execution_ts']);
        $s['latest_status'] = $sv;
        $s['latest_status_letter'] = $letter;
        $s['latest_build'] = $run['build'];
        $s['latest_platform'] = $run['platform'];
        $s['latest_notes'] = $run['notes'];
        $s['latest_tester_id'] = intval($e['tester_id']);
    }
    unset($s);
}

$testerIds = array();
foreach ($steps as $s) {
    foreach ($s['runs'] as $r) {
        if ($r['tester_id'] > 0) { $testerIds[$r['tester_id']] = true; }
    }
    if (isset($s['latest_tester_id']) && $s['latest_tester_id'] > 0) {
        $testerIds[$s['latest_tester_id']] = true;
    }
}
$testerNames = array();
if (count($testerIds) > 0) {
    $ids = implode(',', array_keys($testerIds));
    $userRs = $db->get_recordset(
        "SELECT id, login, first, last FROM {$tbl['users']} WHERE id IN ({$ids})");
    if (is_array($userRs)) {
        foreach ($userRs as $u) {
            $name = trim(serDecode($u['first']) . ' ' . serDecode($u['last']));
            $testerNames[intval($u['id'])] = $name !== '' ? $name : serDecode($u['login']);
        }
    }
}

foreach ($steps as &$s) {
    foreach ($s['runs'] as $i => $r) {
        $s['runs'][$i]['tester'] = isset($testerNames[$r['tester_id']]) ?
            $testerNames[$r['tester_id']] : '';
    }
    if (isset($s['latest_tester_id'])) {
        $s['latest_tester'] = isset($testerNames[$s['latest_tester_id']]) ?
            $testerNames[$s['latest_tester_id']] : '';
        unset($s['latest_tester_id']);
    }
    unset($s['latest_exec_id']);
    if ($s['failed'] > 0) {
        $s['verdict'] = 'failing';
    } elseif ($s['blocked'] > 0) {
        $s['verdict'] = 'blocked';
    } elseif ($s['passed'] > 0 && $s['evidence'] > $s['passed']) {
        $s['verdict'] = 'flaky';
    } elseif ($s['passed'] > 0) {
        $s['verdict'] = 'passing';
    } else {
        $s['verdict'] = 'not_run';
    }
}
unset($s);

$rows = array();
foreach ($steps as $s) {
    $statusMatch = ($status === 'all' ||
        ($status === 'p' && $s['latest_status'] === 'passed') ||
        ($status === 'f' && $s['latest_status'] === 'failed') ||
        ($status === 'b' && $s['latest_status'] === 'blocked'));
    $scopeMatch = ($scope === 'all' ||
        ($scope === 'evidence' && $s['evidence'] > 0) ||
        ($scope === 'failed' && $s['failed'] > 0) ||
        ($scope === 'blocked' && $s['blocked'] > 0) ||
        ($scope === 'passed' && $s['passed'] > 0) ||
        ($scope === 'flaky' && $s['verdict'] === 'flaky'));
    if (!$statusMatch || !$scopeMatch) {
        continue;
    }
    $rows[] = $s;
}
usort($rows, function ($a, $b) {
    $rank = array('failing' => 0, 'blocked' => 1, 'flaky' => 2, 'passing' => 3, 'not_run' => 4);
    $ra = isset($rank[$a['verdict']]) ? $rank[$a['verdict']] : 9;
    $rb = isset($rank[$b['verdict']]) ? $rank[$b['verdict']] : 9;
    if ($ra !== $rb) { return $ra - $rb; }
    if ($a['failed'] !== $b['failed']) { return $b['failed'] - $a['failed']; }
    $c = strcmp($a['tcase_name'], $b['tcase_name']);
    if ($c !== 0) { return $c; }
    return $a['step_number'] - $b['step_number'];
});

$totalSteps = count($steps);
$sum = array('evidence' => 0, 'passed' => 0, 'failed' => 0, 'blocked' => 0, 'not_run' => 0);
$failingSteps = 0;
$blockedSteps = 0;
$passingSteps = 0;
$flakySteps = 0;
$casesWithFailures = array();
foreach ($steps as $s) {
    foreach (array_keys($sum) as $k) { $sum[$k] += $s[$k]; }
    if ($s['verdict'] === 'failing') {
        $failingSteps++;
        $casesWithFailures[$s['tcase_id']] = true;
    }
    if ($s['verdict'] === 'blocked') { $blockedSteps++; }
    if ($s['verdict'] === 'passing') { $passingSteps++; }
    if ($s['verdict'] === 'flaky') { $flakySteps++; }
}
$notRunSteps = 0;
foreach ($steps as $s) {
    if ($s['evidence'] === 0) { $notRunSteps++; }
}
if ($totalSteps === 0 || $sum['evidence'] === 0) {
    $verdict = 'no_data';
} elseif ($failingSteps > 0) {
    $verdict = 'at_risk';
} elseif ($blockedSteps > 0 || $flakySteps > 0) {
    $verdict = 'watch';
} else {
    $verdict = 'clear';
}

$kpi = array(
    'versions' => count(array_unique(array_map(function ($s) { return $s['tcversion_id']; }, array_values($steps)))),
    'design_steps' => $totalSteps,
    'step_results' => $sum['evidence'],
    'passed' => $sum['passed'],
    'failed' => $sum['failed'],
    'blocked' => $sum['blocked'],
    'not_run' => $notRunSteps,
    'failure_rate' => serPct($sum['failed'], $sum['evidence']),
    'failing_steps' => $failingSteps,
    'blocked_steps' => $blockedSteps,
    'flaky_steps' => $flakySteps,
    'passing_steps' => $passingSteps,
    'cases_with_failures' => count($casesWithFailures),
    'verdict' => $verdict,
);

if ($action === 'detail') {
    $detail = null;
    foreach ($steps as $s) {
        if ($s['step_id'] === $stepId) { $detail = $s; break; }
    }
    if (is_null($detail)) {
        serFail(404, 'step_not_found', 'Step not found in this plan');
    }
    serOut(array('status' => 'ok', 'code' => 200, 'context' => $context,
                 'filter' => $filterEcho, 'step' => $detail));
}

if ($action === 'csv') {
    $lines = array();
    $header = array('test_case', 'external_id', 'version', 'suite', 'step', 'actions',
                    'expected', 'verdict', 'evidence', 'passed', 'failed', 'blocked',
                    'latest_status', 'latest_build', 'latest_platform', 'latest_tester',
                    'latest_ts', 'latest_notes');
    $lines[] = implode(';', $header);
    foreach ($rows as $r) {
        $lines[] = implode(';', array(
            serCsvCell($r['tcase_name']), serCsvCell($r['external_id']),
            $r['version'], serCsvCell($r['suite_path']), $r['step_number'],
            serCsvCell($r['actions']), serCsvCell($r['expected']),
            $r['verdict'], $r['evidence'], $r['passed'], $r['failed'], $r['blocked'],
            $r['latest_status'], serCsvCell($r['latest_build']),
            serCsvCell($r['latest_platform']), serCsvCell($r['latest_tester']),
            serCsvCell($r['latest_ts']), serCsvCell($r['latest_notes']),
        ));
    }
    serOut(array('status' => 'ok', 'code' => 200,
                 'csv' => "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n",
                 'rows' => count($rows)));
}

serOut(array(
    'status' => 'ok', 'code' => 200,
    'context' => $context,
    'filters' => array('builds' => $builds, 'platforms' => $platforms),
    'filter' => $filterEcho,
    'kpi' => $kpi,
    'rows' => array_values($rows),
));
