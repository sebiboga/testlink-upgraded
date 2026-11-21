<?php
/**
 * Test Plan Defect Density BFF (issue #2018).
 *
 * Read-only report: for every test-case version assigned to one test plan,
 * count the plan's executions and the DISTINCT bugs linked through
 * execution_bugs, then rank the cases by defects per failed execution.
 */
require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function dfdOut($payload, $code = null)
{
    if ($code !== null) {
        http_response_code($code);
    }
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function dfdFail($httpCode, $code, $message = '')
{
    dfdOut(array(
        'status' => 'error',
        'code' => $code,
        'message' => $message !== '' ? $message : $code,
    ), $httpCode);
}

function dfdPositiveInt($raw)
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

function dfdDecode($value)
{
    return html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function dfdPct($part, $total)
{
    return $total > 0 ? round(100.0 * $part / $total, 1) : 0.0;
}

function dfdStatus($letter)
{
    switch (strtolower(trim((string)$letter))) {
        case 'p': return 'passed';
        case 'f': return 'failed';
        case 'b': return 'blocked';
    }
    return 'not_run';
}

function dfdCsvCell($value)
{
    $s = dfdDecode($value);
    if ($s !== '' && preg_match('/^[\s]*[=+\-@]/', $s)) {
        $s = "'" . $s;
    }
    return str_replace(array('"', "\r", "\n"), array('""', ' ', ' '), $s);
}

function dfdPath($db, $tbl, $nodeId, $cache)
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
            $parts[] = dfdDecode($r['name']);
        }
        if (intval($r['node_type_id']) === 1 || $parent === $current) {
            break;
        }
        $current = $parent;
    }
    return implode(' / ', array_reverse($parts));
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    dfdFail(405, 'method_not_allowed');
}

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    dfdFail(401, 'not_authenticated', 'Not authenticated');
}
bffEnforceSession($db);

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    dfdFail(401, 'not_authenticated', 'User not found');
}

$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if (!in_array($action, array('init', 'report', 'detail', 'csv'), true)) {
    dfdFail(400, 'invalid_request', 'Unknown action');
}

$tplanId = dfdPositiveInt($_GET['tplan_id'] ?? null);
$assertedProjectId = dfdPositiveInt($_GET['tproject_id'] ?? null);
$rawBuild = trim((string)($_GET['build_id'] ?? '0'));
$rawPlatform = trim((string)($_GET['platform_id'] ?? '0'));
$status = strtolower(trim((string)($_GET['status'] ?? 'all')));
$scope = strtolower(trim((string)($_GET['scope'] ?? 'all')));
$tcversionId = dfdPositiveInt($_GET['tcversion_id'] ?? null);
if (!in_array($status, array('all', 'p', 'f', 'b'), true)) {
    dfdFail(400, 'invalid_request', 'Invalid status');
}
if (!in_array($scope, array('all', 'with_defects', 'without_defects', 'never_run'), true)) {
    dfdFail(400, 'invalid_request', 'Invalid scope');
}
if ($tplanId <= 0) {
    dfdFail(400, 'invalid_request', 'Missing or invalid tplan_id');
}
if (($rawBuild !== '' && !preg_match('/^[0-9]+$/', $rawBuild)) ||
    ($rawPlatform !== '' && !preg_match('/^[0-9]+$/', $rawPlatform))) {
    dfdFail(400, 'invalid_request', 'Invalid filter');
}
if ($tcversionId > 0 && $action !== 'detail') {
    dfdFail(400, 'invalid_request', 'tcversion_id is only valid for the detail action');
}
if ($action === 'detail' && $tcversionId <= 0) {
    dfdFail(400, 'invalid_request', 'Missing tcversion_id');
}
$buildId = intval($rawBuild);
$platformId = intval($rawPlatform);

$tbl = tlObjectWithDB::getDBTables(array(
    'testplans', 'testprojects', 'nodes_hierarchy', 'tcversions',
    'builds', 'platforms', 'testplan_platforms', 'testplan_tcversions',
    'executions', 'execution_bugs',
));

$planRow = $db->get_recordset(
    "SELECT id, testproject_id FROM {$tbl['testplans']} WHERE id = " . intval($tplanId));
if (!is_array($planRow) || count($planRow) === 0) {
    dfdFail(404, 'tplan_not_found', 'Test plan not found');
}
$owningProjectId = intval($planRow[0]['testproject_id']);
if ($owningProjectId <= 0) {
    dfdFail(404, 'tplan_not_found', 'Test plan has no owning test project');
}
if (!$user->hasRight($db, 'testplan_metrics', $owningProjectId, $tplanId, true)) {
    tLog('BFF defectdensity: user ' . intval($userId) .
         ' has no testplan_metrics right on tproject ' . intval($owningProjectId) .
         ' (tplan ' . $tplanId . ')', 'AUDIT');
    dfdFail(403, 'no_right', 'testplan_metrics right required');
}
if ($assertedProjectId > 0 && $assertedProjectId !== $owningProjectId) {
    dfdFail(404, 'project_mismatch', 'Test plan belongs to another test project');
}

$planName = testplan::getName($db, $tplanId);
$projectName = testproject::getName($db, $owningProjectId);
if (is_null($planName) || trim((string)$planName) === '' ||
    is_null($projectName) || trim((string)$projectName) === '') {
    dfdFail(404, 'tplan_not_found', 'Test plan not found');
}

$builds = array();
$buildRs = $db->get_recordset(
    "SELECT id, name FROM {$tbl['builds']} WHERE testproject_id = " . intval($owningProjectId) .
    " AND active = 1 ORDER BY id");
if (is_array($buildRs)) {
    foreach ($buildRs as $b) {
        $builds[] = array('id' => intval($b['id']), 'name' => dfdDecode($b['name']));
    }
}
$platforms = array();
$platformRs = $db->get_recordset(
    "SELECT p.id, p.name FROM {$tbl['platforms']} p" .
    " JOIN {$tbl['testplan_platforms']} tpp ON tpp.platform_id = p.id" .
    " WHERE tpp.testplan_id = " . intval($tplanId) . " ORDER BY p.name");
if (is_array($platformRs)) {
    foreach ($platformRs as $p) {
        $platforms[] = array('id' => intval($p['id']), 'name' => dfdDecode($p['name']));
    }
}
$knownBuild = ($buildId === 0);
foreach ($builds as $b) { if ($b['id'] === $buildId) { $knownBuild = true; break; } }
if (!$knownBuild) {
    dfdFail(400, 'invalid_request', 'build_id does not belong to this test project');
}
$knownPlatform = ($platformId === 0);
foreach ($platforms as $p) { if ($p['id'] === $platformId) { $knownPlatform = true; break; } }
if (!$knownPlatform) {
    dfdFail(400, 'invalid_request', 'platform_id does not belong to this test plan');
}

$context = array(
    'tplan_id' => $tplanId,
    'tplan_name' => dfdDecode((string)$planName),
    'tproject_id' => $owningProjectId,
    'tproject_name' => dfdDecode((string)$projectName),
);
$filterEcho = array(
    'build_id' => $buildId, 'platform_id' => $platformId,
    'status' => $status, 'scope' => $scope,
);
$filtersPayload = array('builds' => $builds, 'platforms' => $platforms);

if ($action === 'init') {
    dfdOut(array(
        'status' => 'ok',
        'code' => 200,
        'context' => $context,
        'filters' => $filtersPayload,
        'filter' => $filterEcho,
    ));
}

$versionSql =
    "SELECT tptv.tcversion_id, tptv.platform_id AS assigned_platform_id," .
    "       tv.version, tv.tc_external_id," .
    "       nh_tc.id AS tcase_id, nh_tc.name AS tcase_name, tp.prefix" .
    " FROM {$tbl['testplan_tcversions']} tptv" .
    " JOIN {$tbl['tcversions']} tv ON tv.id = tptv.tcversion_id" .
    " JOIN {$tbl['nodes_hierarchy']} nh_tv ON nh_tv.id = tv.id" .
    " JOIN {$tbl['nodes_hierarchy']} nh_tc ON nh_tc.id = nh_tv.parent_id" .
    " JOIN {$tbl['testprojects']} tp ON tp.id = " . intval($owningProjectId) .
    " WHERE tptv.testplan_id = " . intval($tplanId) .
    " ORDER BY nh_tc.name, tv.version";
$versionRs = $db->get_recordset($versionSql);
if (!is_array($versionRs)) { $versionRs = array(); }

$buildNames = array();
foreach ($builds as $b) { $buildNames[$b['id']] = $b['name']; }
$platformNames = array();
foreach ($platforms as $p) { $platformNames[$p['id']] = $p['name']; }

$versions = array();
$linkKeysByVersion = array();
foreach ($versionRs as $r) {
    $vid = intval($r['tcversion_id']);
    $assignedPid = intval($r['assigned_platform_id']);
    if ($platformId > 0 && $assignedPid !== 0 && $assignedPid !== $platformId) {
        continue;
    }
    $key = $vid . '/' . $assignedPid;
    $linkKeysByVersion[$vid][] = $key;
    $versions[$key] = array(
        'link_key' => $key,
        'tcversion_id' => $vid,
        'tcase_id' => intval($r['tcase_id']),
        'tcase_name' => dfdDecode($r['tcase_name']),
        'external_id' => trim((string)$r['prefix']) . '-' . intval($r['tc_external_id']),
        'version' => intval($r['version']),
        'assigned_platform_id' => $assignedPid,
        'path' => '',
        'executions' => 0,
        'passed' => 0,
        'failed' => 0,
        'blocked' => 0,
        'not_run' => 0,
        'linked_bugs' => 0,
        'density' => 0.0,
        'last_result' => 'not_run',
        'last_build' => '',
        'last_platform' => '',
        'last_ts' => '',
        'verdict' => 'no_run',
        'bug_ids' => array(),
        'execution_rows' => array(),
    );
}

$execSql =
    "SELECT e.id, e.tcversion_id, e.status, e.build_id, e.platform_id," .
    "       e.execution_ts, e.tester_id, e.notes" .
    " FROM {$tbl['executions']} e WHERE e.testplan_id = " . intval($tplanId);
if ($buildId > 0) {
    $execSql .= " AND e.build_id = " . intval($buildId);
}
$execRs = $db->get_recordset($execSql . " ORDER BY e.execution_ts ASC, e.id ASC");
$bugMap = array();
$bugRs = $db->get_recordset(
    "SELECT b.execution_id, b.bug_id, b.tcstep_id FROM {$tbl['execution_bugs']} b" .
    " JOIN {$tbl['executions']} e ON e.id = b.execution_id" .
    " WHERE e.testplan_id = " . intval($tplanId));
if (is_array($bugRs)) {
    foreach ($bugRs as $b) {
        $bugMap[intval($b['execution_id'])][] = dfdDecode($b['bug_id']);
    }
}
if (is_array($execRs)) {
    foreach ($execRs as $e) {
        $vid = intval($e['tcversion_id']);
        if (!isset($linkKeysByVersion[$vid])) { continue; }
        $pid = intval($e['platform_id']);
        if ($platformId > 0 && $pid !== 0 && $pid !== $platformId) { continue; }
        $bugs = isset($bugMap[intval($e['id'])]) ? array_values(array_unique($bugMap[intval($e['id'])])) : array();
        foreach ($linkKeysByVersion[$vid] as $key) {
            $assignedPid = $versions[$key]['assigned_platform_id'];
            if ($pid > 0 && $assignedPid !== 0 && $assignedPid !== $pid) { continue; }
            $v = &$versions[$key];
            $v['executions']++;
            $state = dfdStatus($e['status']);
            if ($state === 'passed') { $v['passed']++; }
            if ($state === 'failed') { $v['failed']++; }
            if ($state === 'blocked') { $v['blocked']++; }
            if ($state === 'not_run') { $v['not_run']++; }
            foreach ($bugs as $bugId) {
                if (!in_array($bugId, $v['bug_ids'], true)) { $v['bug_ids'][] = $bugId; }
            }
            $v['last_result'] = $state;
            $v['last_build'] = isset($buildNames[intval($e['build_id'])]) ?
                $buildNames[intval($e['build_id'])] : '';
            $v['last_platform'] = $pid > 0 && isset($platformNames[$pid]) ?
                $platformNames[$pid] : '';
            $v['last_ts'] = (string)($e['execution_ts'] ?? '');
            $v['execution_rows'][] = array(
                'execution_id' => intval($e['id']),
                'status' => $state,
                'build' => $v['last_build'],
                'platform' => $v['last_platform'],
                'execution_ts' => (string)($e['execution_ts'] ?? ''),
                'tester_id' => intval($e['tester_id']),
                'bugs' => $bugs,
                'notes' => trim(dfdDecode($e['notes'])),
            );
            unset($v);
        }
    }
}

$paths = array();
foreach ($versions as &$v) {
    $v['linked_bugs'] = count($v['bug_ids']);
    $v['density'] = $v['failed'] > 0 ? round($v['linked_bugs'] / $v['failed'], 2) :
        ($v['linked_bugs'] > 0 ? (float)$v['linked_bugs'] : 0.0);
    if ($v['executions'] === 0) {
        $v['verdict'] = 'no_run';
    } elseif ($v['linked_bugs'] >= 3) {
        $v['verdict'] = 'spike';
    } elseif ($v['density'] >= 1) {
        $v['verdict'] = 'hot';
    } elseif ($v['linked_bugs'] > 0) {
        $v['verdict'] = 'watch';
    } else {
        $v['verdict'] = 'clean';
    }
    $v['path'] = dfdPath($db, $tbl, $v['tcase_id'], $paths);
    $paths[$v['tcase_id']] = $v['path'];
}
unset($v);

if ($action === 'detail') {
    $detailKey = null;
    foreach ($versions as $key => $candidate) {
        if (intval($candidate['tcversion_id']) === $tcversionId) {
            $detailKey = $key;
            break;
        }
    }
    if ($detailKey === null) {
        dfdFail(404, 'tcversion_not_found', 'Test case version is not assigned to this plan');
    }
    $item = $versions[$detailKey];
    dfdOut(array(
        'status' => 'ok',
        'code' => 200,
        'context' => $context,
        'item' => $item,
        'executions' => $item['execution_rows'],
    ));
}

$rows = array();
foreach ($versions as $v) {
    if ($status !== 'all' && $v['last_result'] !== dfdStatus($status)) { continue; }
    if ($scope === 'with_defects' && $v['linked_bugs'] === 0) { continue; }
    if ($scope === 'without_defects' && $v['linked_bugs'] > 0) { continue; }
    if ($scope === 'never_run' && $v['executions'] > 0) { continue; }
    unset($v['bug_ids'], $v['execution_rows']);
    $rows[] = $v;
}
usort($rows, function ($a, $b) {
    $cmp = $b['linked_bugs'] - $a['linked_bugs'];
    if ($cmp !== 0) { return $cmp; }
    $cmp = (int)(($b['density'] - $a['density']) * 100);
    if ($cmp !== 0) { return $cmp; }
    $cmp = $b['failed'] - $a['failed'];
    if ($cmp !== 0) { return $cmp; }
    return strcmp($a['tcase_name'], $b['tcase_name']);
});

$summary = array(
    'assigned' => count($versions),
    'shown' => count($rows),
    'executed_cases' => 0,
    'executions' => 0,
    'passed' => 0,
    'failed' => 0,
    'blocked' => 0,
    'never_run' => 0,
    'cases_with_defects' => 0,
    'linked_bugs' => 0,
    'hot' => 0,
    'spike' => 0,
    'density' => 0.0,
    'pass_rate' => 0.0,
);
foreach ($rows as $r) {
    $summary['executions'] += $r['executions'];
    $summary['passed'] += $r['passed'];
    $summary['failed'] += $r['failed'];
    $summary['blocked'] += $r['blocked'];
    $summary['linked_bugs'] += $r['linked_bugs'];
    if ($r['executions'] > 0) { $summary['executed_cases']++; }
    if ($r['executions'] === 0) { $summary['never_run']++; }
    if ($r['linked_bugs'] > 0) { $summary['cases_with_defects']++; }
    if ($r['verdict'] === 'hot') { $summary['hot']++; }
    if ($r['verdict'] === 'spike') { $summary['spike']++; }
}
$summary['density'] = dfdPct($summary['linked_bugs'], $summary['failed']);
$summary['pass_rate'] = dfdPct($summary['passed'], $summary['executions']);

if ($summary['shown'] === 0) {
    $verdict = 'no_data';
} elseif ($summary['spike'] > 0) {
    $verdict = 'at_risk';
} elseif ($summary['hot'] > 0) {
    $verdict = 'watch';
} elseif ($summary['cases_with_defects'] > 0) {
    $verdict = 'monitor';
} else {
    $verdict = 'clear';
}

if ($action === 'csv') {
    if (ob_get_level() > 0) { ob_clean(); }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="defect-density.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, array('test_case', 'external_id', 'version', 'suite_path',
        'executions', 'passed', 'failed', 'blocked', 'linked_bugs', 'density',
        'verdict', 'last_result', 'last_build', 'last_platform', 'last_ts'), ';');
    foreach ($rows as $r) {
        fputcsv($out, array(
            dfdCsvCell($r['tcase_name']), dfdCsvCell($r['external_id']),
            $r['version'], dfdCsvCell($r['path']), $r['executions'], $r['passed'],
            $r['failed'], $r['blocked'], $r['linked_bugs'], $r['density'],
            dfdCsvCell($r['verdict']), dfdCsvCell($r['last_result']),
            dfdCsvCell($r['last_build']), dfdCsvCell($r['last_platform']),
            dfdCsvCell($r['last_ts']),
        ), ';');
    }
    fclose($out);
    exit;
}

dfdOut(array(
    'status' => 'ok',
    'code' => 200,
    'context' => $context,
    'filter' => $filterEcho,
    'filters' => $filtersPayload,
    'summary' => $summary,
    'rows' => $rows,
    'verdict' => $verdict,
));
