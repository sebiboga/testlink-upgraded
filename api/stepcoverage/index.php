<?php
require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function stpcOut($payload, $code = null)
{
    if ($code !== null) {
        http_response_code($code);
    }
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function stpcFail($httpCode, $code, $message = '')
{
    stpcOut(array(
        'status' => 'error',
        'code' => $code,
        'message' => $message !== '' ? $message : $code,
    ), $httpCode);
}

function stpcPositiveInt($raw)
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

function stpcDecode($value)
{
    return html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function stpcPct($part, $total)
{
    return $total > 0 ? round(100.0 * $part / $total, 1) : 0.0;
}

function stpcStatus($letter)
{
    switch (strtolower(trim((string)$letter))) {
        case 'p': return 'passed';
        case 'f': return 'failed';
        case 'b': return 'blocked';
    }
    return 'not_run';
}

function stpcCsvCell($value)
{
    $s = stpcDecode($value);
    if ($s !== '' && preg_match('/^[\s]*[=+\-@]/', $s)) {
        $s = "'" . $s;
    }
    return str_replace(array('"', "\r", "\n"), array('""', ' ', ' '), $s);
}

function stpcPath($db, $tbl, $nodeId, $cache)
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
        if (intval($r['node_type_id']) !== 1) {
            $parts[] = stpcDecode($r['name']);
        }
        $parent = intval($r['parent_id']);
        if (intval($r['node_type_id']) === 1 || $parent === $current) {
            break;
        }
        $current = $parent;
    }
    $parts = array_reverse($parts);
    return implode(' / ', $parts);
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    stpcFail(405, 'method_not_allowed');
}

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    stpcFail(401, 'not_authenticated', 'Not authenticated');
}
bffEnforceSession($db);

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    stpcFail(401, 'not_authenticated', 'User not found');
}

$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if (!in_array($action, array('init', 'report', 'detail', 'csv'), true)) {
    stpcFail(400, 'invalid_request', 'Unknown action');
}

$tplanId = stpcPositiveInt($_GET['tplan_id'] ?? null);
$assertedProjectId = stpcPositiveInt($_GET['tproject_id'] ?? null);
$rawBuild = trim((string)($_GET['build_id'] ?? '0'));
$rawPlatform = trim((string)($_GET['platform_id'] ?? '0'));
$status = strtolower(trim((string)($_GET['status'] ?? 'all')));
$scope = strtolower(trim((string)($_GET['scope'] ?? 'all')));
$tcversionId = stpcPositiveInt($_GET['tcversion_id'] ?? null);
if (!in_array($status, array('all', 'p', 'f', 'b'), true)) {
    stpcFail(400, 'invalid_request', 'Invalid status');
}
if (!in_array($scope, array('all', 'no_steps', 'complete', 'partial', 'missing_expected'), true)) {
    stpcFail(400, 'invalid_request', 'Invalid scope');
}
if ($tplanId <= 0) {
    stpcFail(400, 'invalid_request', 'Missing or invalid tplan_id');
}
if (($rawBuild !== '' && !preg_match('/^[0-9]+$/', $rawBuild)) ||
    ($rawPlatform !== '' && !preg_match('/^[0-9]+$/', $rawPlatform))) {
    stpcFail(400, 'invalid_request', 'Invalid filter');
}
if ($tcversionId > 0 && $action !== 'detail') {
    stpcFail(400, 'invalid_request', 'tcversion_id is only valid for the detail action');
}
if ($action === 'detail' && $tcversionId <= 0) {
    stpcFail(400, 'invalid_request', 'Missing tcversion_id');
}
$buildId = intval($rawBuild);
$platformId = intval($rawPlatform);

$tbl = tlObjectWithDB::getDBTables(array(
    'testplans', 'testprojects', 'nodes_hierarchy', 'tcversions',
    'tcsteps', 'builds', 'platforms', 'testplan_platforms',
    'testplan_tcversions', 'executions',
));

$planRow = $db->get_recordset(
    "SELECT id, testproject_id FROM {$tbl['testplans']} WHERE id = " . intval($tplanId));
if (!is_array($planRow) || count($planRow) === 0) {
    stpcFail(404, 'tplan_not_found', 'Test plan not found');
}
$owningProjectId = intval($planRow[0]['testproject_id']);
if ($owningProjectId <= 0) {
    stpcFail(404, 'tplan_not_found', 'Test plan has no owning test project');
}
if (!$user->hasRight($db, 'testplan_metrics', $owningProjectId, $tplanId, true)) {
    tLog('BFF stepcoverage: user ' . intval($userId) .
         ' has no testplan_metrics right on tproject ' . intval($owningProjectId) .
         ' (tplan ' . $tplanId . ')', 'AUDIT');
    stpcFail(403, 'no_right', 'testplan_metrics right required');
}
if ($assertedProjectId > 0 && $assertedProjectId !== $owningProjectId) {
    stpcFail(404, 'project_mismatch', 'Test plan belongs to another test project');
}

$planName = testplan::getName($db, $tplanId);
$projectName = testproject::getName($db, $owningProjectId);
if (is_null($planName) || trim((string)$planName) === '' ||
    is_null($projectName) || trim((string)$projectName) === '') {
    stpcFail(404, 'tplan_not_found', 'Test plan not found');
}

$builds = array();
$buildRs = $db->get_recordset(
    "SELECT id, name FROM {$tbl['builds']} WHERE testproject_id = " . intval($owningProjectId) .
    " AND active = 1 ORDER BY id");
if (is_array($buildRs)) {
    foreach ($buildRs as $b) {
        $builds[] = array('id' => intval($b['id']), 'name' => stpcDecode($b['name']));
    }
}

$platforms = array();
$platformRs = $db->get_recordset(
    "SELECT p.id, p.name FROM {$tbl['platforms']} p" .
    " JOIN {$tbl['testplan_platforms']} tpp ON tpp.platform_id = p.id" .
    " WHERE tpp.testplan_id = " . intval($tplanId) . " ORDER BY p.name");
if (is_array($platformRs)) {
    foreach ($platformRs as $p) {
        $platforms[] = array('id' => intval($p['id']), 'name' => stpcDecode($p['name']));
    }
}

$knownBuild = ($buildId === 0);
foreach ($builds as $b) { if ($b['id'] === $buildId) { $knownBuild = true; break; } }
if (!$knownBuild) {
    stpcFail(400, 'invalid_request', 'build_id does not belong to this test project');
}
$knownPlatform = ($platformId === 0);
foreach ($platforms as $p) { if ($p['id'] === $platformId) { $knownPlatform = true; break; } }
if (!$knownPlatform) {
    stpcFail(400, 'invalid_request', 'platform_id does not belong to this test plan');
}

$context = array(
    'tplan_id' => $tplanId,
    'tplan_name' => stpcDecode((string)$planName),
    'tproject_id' => $owningProjectId,
    'tproject_name' => stpcDecode((string)$projectName),
);
$filterEcho = array(
    'build_id' => $buildId, 'platform_id' => $platformId,
    'status' => $status, 'scope' => $scope,
);
$filtersPayload = array('builds' => $builds, 'platforms' => $platforms);

if ($action === 'init') {
    stpcOut(array(
        'status' => 'ok',
        'code' => 200,
        'context' => $context,
        'filters' => $filtersPayload,
        'filter' => $filterEcho,
    ));
}

$versionSql =
    "SELECT tptv.tcversion_id, tptv.platform_id AS assigned_platform_id," .
    "       tv.version, tv.tc_external_id, tv.id AS vid," .
    "       nh_tc.id AS tcase_id, nh_tc.name AS tcase_name," .
    "       nh_tv.parent_id AS tcase_node_id, tp.prefix" .
    " FROM {$tbl['testplan_tcversions']} tptv" .
    " JOIN {$tbl['tcversions']} tv ON tv.id = tptv.tcversion_id" .
    " JOIN {$tbl['nodes_hierarchy']} nh_tv ON nh_tv.id = tv.id" .
    " JOIN {$tbl['nodes_hierarchy']} nh_tc ON nh_tc.id = nh_tv.parent_id" .
    " JOIN {$tbl['testprojects']} tp ON tp.id = " . intval($owningProjectId) .
    " WHERE tptv.testplan_id = " . intval($tplanId) .
    " ORDER BY nh_tc.name, tv.version";
$versionRs = $db->get_recordset($versionSql);
if (!is_array($versionRs)) { $versionRs = array(); }

$versions = array();
$versionIds = array();
foreach ($versionRs as $r) {
    $vid = intval($r['tcversion_id']);
    $versionIds[$vid] = true;
    $external = trim((string)$r['prefix']) . '-' . intval($r['tc_external_id']);
    $versions[$vid] = array(
        'tcversion_id' => $vid,
        'tcase_id' => intval($r['tcase_id']),
        'tcase_name' => stpcDecode($r['tcase_name']),
        'external_id' => $external,
        'version' => intval($r['version']),
        'assigned_platform_id' => intval($r['assigned_platform_id']),
        'steps' => 0,
        'with_expected' => 0,
        'missing_expected' => 0,
        'complete' => 'no_steps',
        'status' => 'not_run',
        'status_letter' => '',
        'build_id' => 0,
        'build_name' => '',
        'platform_id' => intval($r['assigned_platform_id']),
        'platform_name' => '',
        'execution_ts' => '',
        'tester_id' => 0,
        'path' => '',
        'step_rows' => array(),
    );
}

$buildNames = array();
foreach ($builds as $b) { $buildNames[$b['id']] = $b['name']; }
$platformNames = array();
foreach ($platforms as $p) { $platformNames[$p['id']] = $p['name']; }

$stepRs = array();
if (count($versionIds) > 0) {
    $stepSql =
        "SELECT s.id, s.step_number, s.actions, s.expected_results, s.active," .
        "       nh.parent_id AS tcversion_id" .
        " FROM {$tbl['tcsteps']} s" .
        " JOIN {$tbl['nodes_hierarchy']} nh ON nh.id = s.id" .
        " WHERE s.active = 1 AND nh.parent_id IN (" .
        implode(',', array_fill(0, count($versionIds), '%d')) . ")" .
        " ORDER BY nh.parent_id, s.step_number, s.id";
    $stepRs = $db->get_recordset(sprintf($stepSql, ...array_keys($versionIds)));
}
if (is_array($stepRs)) {
    foreach ($stepRs as $s) {
        $vid = intval($s['tcversion_id']);
        if (!isset($versions[$vid])) { continue; }
        $actions = trim(stpcDecode($s['actions']));
        $expected = trim(stpcDecode($s['expected_results']));
        $versions[$vid]['steps']++;
        if ($expected !== '') {
            $versions[$vid]['with_expected']++;
        } else {
            $versions[$vid]['missing_expected']++;
        }
        $versions[$vid]['step_rows'][] = array(
            'step_number' => intval($s['step_number']),
            'actions' => $actions,
            'expected_results' => $expected,
        );
    }
}

$execSql =
    "SELECT id, tcversion_id, status, build_id, platform_id, execution_ts, tester_id" .
    " FROM {$tbl['executions']} WHERE testplan_id = " . intval($tplanId);
if ($buildId > 0) {
    $execSql .= " AND build_id = " . intval($buildId);
}
$execRs = $db->get_recordset($execSql . " ORDER BY execution_ts ASC, id ASC");
if (is_array($execRs)) {
    foreach ($execRs as $e) {
        $vid = intval($e['tcversion_id']);
        if (!isset($versions[$vid])) { continue; }
        $pid = intval($e['platform_id']);
        if ($platformId > 0 && $pid !== 0 && $pid !== $platformId) { continue; }
        $versions[$vid]['status'] = stpcStatus($e['status']);
        $versions[$vid]['status_letter'] = strtolower(trim((string)$e['status']));
        $versions[$vid]['build_id'] = intval($e['build_id']);
        $versions[$vid]['build_name'] = isset($buildNames[intval($e['build_id'])]) ?
            $buildNames[intval($e['build_id'])] : '';
        $versions[$vid]['platform_id'] = $pid;
        $versions[$vid]['platform_name'] = $pid > 0 && isset($platformNames[$pid]) ?
            $platformNames[$pid] : '';
        $versions[$vid]['execution_ts'] = (string)($e['execution_ts'] ?? '');
        $versions[$vid]['tester_id'] = intval($e['tester_id']);
    }
}

$paths = array();
foreach ($versions as &$v) {
    if ($v['steps'] === 0) {
        $v['complete'] = 'no_steps';
    } elseif ($v['missing_expected'] === 0) {
        $v['complete'] = 'complete';
    } elseif ($v['with_expected'] > 0) {
        $v['complete'] = 'partial';
    } else {
        $v['complete'] = 'missing_expected';
    }
    $v['path'] = stpcPath($db, $tbl, $v['tcase_id'], $paths);
    $paths[$v['tcase_id']] = $v['path'];
}
unset($v);

if ($action === 'detail') {
    if (!isset($versions[$tcversionId])) {
        stpcFail(404, 'tcversion_not_found', 'Test case version is not assigned to this plan');
    }
    $v = $versions[$tcversionId];
    if ($status !== 'all' && $v['status'] !== stpcStatus($status)) {
        stpcOut(array('status' => 'ok', 'code' => 200, 'context' => $context,
            'item' => $v, 'steps' => array()));
    }
    stpcOut(array(
        'status' => 'ok',
        'code' => 200,
        'context' => $context,
        'item' => $v,
        'steps' => $v['step_rows'],
    ));
}

$rows = array();
foreach ($versions as $v) {
    if ($status !== 'all' && $v['status'] !== stpcStatus($status)) { continue; }
    if ($scope === 'no_steps' && $v['complete'] !== 'no_steps') { continue; }
    if ($scope === 'complete' && $v['complete'] !== 'complete') { continue; }
    if ($scope === 'partial' && $v['complete'] !== 'partial') { continue; }
    if ($scope === 'missing_expected' && $v['missing_expected'] <= 0) { continue; }
    unset($v['step_rows']);
    $rows[] = $v;
}
usort($rows, function ($a, $b) {
    $cmp = strcmp($a['path'], $b['path']);
    if ($cmp !== 0) { return $cmp; }
    $cmp = strcmp($a['tcase_name'], $b['tcase_name']);
    if ($cmp !== 0) { return $cmp; }
    return $a['version'] - $b['version'];
});

$summary = array(
    'assigned' => count($versions),
    'shown' => count($rows),
    'with_steps' => 0,
    'no_steps' => 0,
    'complete' => 0,
    'partial' => 0,
    'missing_expected' => 0,
    'total_steps' => 0,
    'steps_with_expected' => 0,
    'executed' => 0,
    'passed' => 0,
    'failed' => 0,
    'blocked' => 0,
);
foreach ($rows as $r) {
    if ($r['steps'] > 0) { $summary['with_steps']++; } else { $summary['no_steps']++; }
    if ($r['complete'] === 'complete') { $summary['complete']++; }
    if ($r['complete'] === 'partial') { $summary['partial']++; }
    if ($r['missing_expected'] > 0) { $summary['missing_expected']++; }
    $summary['total_steps'] += $r['steps'];
    $summary['steps_with_expected'] += $r['with_expected'];
    if ($r['status'] !== 'not_run') { $summary['executed']++; }
    if ($r['status'] === 'passed') { $summary['passed']++; }
    if ($r['status'] === 'failed') { $summary['failed']++; }
    if ($r['status'] === 'blocked') { $summary['blocked']++; }
}
$summary['step_coverage'] = stpcPct($summary['with_steps'], $summary['shown']);
$summary['expected_coverage'] = stpcPct($summary['steps_with_expected'], $summary['total_steps']);
$summary['pass_rate'] = stpcPct($summary['passed'], $summary['executed']);

if ($summary['shown'] === 0) {
    $verdict = 'no_data';
} elseif ($summary['no_steps'] === $summary['shown']) {
    $verdict = 'undocumented';
} elseif ($summary['missing_expected'] > 0 || $summary['partial'] > 0) {
    $verdict = 'at_risk';
} elseif ($summary['with_steps'] < $summary['shown']) {
    $verdict = 'watch';
} else {
    $verdict = 'complete';
}

if ($action === 'csv') {
    if (ob_get_level() > 0) { ob_clean(); }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="step-coverage.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, array('test_case', 'external_id', 'version', 'suite_path', 'steps',
        'with_expected', 'missing_expected', 'completeness', 'status', 'build',
        'platform', 'execution_ts'), ';');
    foreach ($rows as $r) {
        fputcsv($out, array(
            stpcCsvCell($r['tcase_name']), stpcCsvCell($r['external_id']),
            $r['version'], stpcCsvCell($r['path']), $r['steps'], $r['with_expected'],
            $r['missing_expected'], stpcCsvCell($r['complete']), stpcCsvCell($r['status']),
            stpcCsvCell($r['build_name']), stpcCsvCell($r['platform_name']),
            stpcCsvCell($r['execution_ts']),
        ), ';');
    }
    fclose($out);
    exit;
}

stpcOut(array(
    'status' => 'ok',
    'code' => 200,
    'context' => $context,
    'filter' => $filterEcho,
    'filters' => $filtersPayload,
    'summary' => $summary,
    'rows' => $rows,
    'verdict' => $verdict,
));
