<?php
/**
 * Test Plan Execution Flakiness BFF (Refs #2036)
 *
 * GET|HEAD ?action=init&tplan_id=N[&tproject_id=P]
 * GET|HEAD ?action=report&tplan_id=N[&tproject_id=P][&build_id=B][&platform_id=PL][&verdict=V]
 * GET|HEAD ?action=detail&tplan_id=N&tcversion_id=V[...]
 * GET|HEAD ?action=csv&tplan_id=N[...]
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

function flkOut($payload, $code = null)
{
    if ($code !== null) { http_response_code($code); }
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function flkFail($httpCode, $code, $message = '')
{
    flkOut(array('status' => 'error', 'code' => $code,
                 'message' => $message !== '' ? $message : $code), $httpCode);
}

function flkPositiveInt($raw)
{
    if ($raw === null || is_array($raw)) { return 0; }
    $raw = trim((string)$raw);
    if ($raw === '' || !preg_match('/^[0-9]+$/', $raw)) { return 0; }
    return intval($raw) > 0 ? intval($raw) : 0;
}

function flkDecode($value)
{
    return html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function flkText($value)
{
    return trim(strip_tags(flkDecode($value)));
}

function flkPct($part, $total)
{
    return $total > 0 ? round(100.0 * $part / $total, 1) : 0.0;
}

function flkCsvCell($value)
{
    $s = flkDecode($value);
    if ($s !== '' && preg_match('/^[\s]*[=+\-@]/', $s)) { $s = "'" . $s; }
    return str_replace(array("\r", "\n"), array(' ', ' '), $s);
}

function flkStatus($raw)
{
    $state = strtolower(trim((string)$raw));
    if ($state === 'p') { return 'passed'; }
    if ($state === 'f') { return 'failed'; }
    if ($state === 'b') { return 'blocked'; }
    return 'not_run';
}

function flkPath($db, $tbl, $nodeId, &$cache)
{
    $nodeId = intval($nodeId);
    if (isset($cache[$nodeId])) { return $cache[$nodeId]; }
    $parts = array();
    $current = $nodeId;
    for ($depth = 0; $depth < 12 && $current > 0; $depth++) {
        $row = $db->get_recordset(
            "SELECT id, name, parent_id, node_type_id FROM {$tbl['nodes_hierarchy']}" .
            " WHERE id = " . $current);
        if (!is_array($row) || count($row) === 0) { break; }
        $r = $row[0];
        $parent = intval($r['parent_id']);
        if ($depth > 0 && intval($r['node_type_id']) !== 1) { $parts[] = flkDecode($r['name']); }
        if (intval($r['node_type_id']) === 1 || $parent === $current) { break; }
        $current = $parent;
    }
    $path = implode(' / ', array_reverse($parts));
    $cache[$nodeId] = $path;
    return $path;
}

function flkVersionInfo($db, $tbl, $tcversionId, $prefix, &$cache, &$pathCache)
{
    $vid = intval($tcversionId);
    if (isset($cache[$vid])) { return $cache[$vid]; }
    $rs = $db->get_recordset(
        "SELECT tv.version, tv.tc_external_id, nh_tc.id AS tcase_id," .
        " nh_tc.name AS tcase_name FROM {$tbl['tcversions']} tv" .
        " JOIN {$tbl['nodes_hierarchy']} nh_tv ON nh_tv.id = tv.id" .
        " JOIN {$tbl['nodes_hierarchy']} nh_tc ON nh_tc.id = nh_tv.parent_id" .
        " WHERE tv.id = " . $vid);
    if (!is_array($rs) || count($rs) === 0) {
        return array('version' => 0, 'external_id' => '', 'tcase_name' => '', 'path' => '');
    }
    $r = $rs[0];
    $info = array(
        'version' => intval($r['version']),
        'external_id' => $prefix . '-' . intval($r['tc_external_id']),
        'tcase_name' => flkDecode($r['tcase_name']),
        'path' => flkPath($db, $tbl, intval($r['tcase_id']), $pathCache),
    );
    $cache[$vid] = $info;
    return $info;
}

$method = strtoupper(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    flkFail(405, 'method_not_allowed');
}

$db = new database(DB_TYPE);
doDBConnect($db);
$userId = isset($_SESSION['userID']) ? intval($_SESSION['userID']) : 0;
if ($userId <= 0) { flkFail(401, 'not_authenticated', 'Not authenticated'); }
bffEnforceSession($db);
$user = tlUser::getByID($db, $userId);
if (is_null($user)) { flkFail(401, 'not_authenticated', 'User not found'); }

$action = strtolower(trim((string)(isset($_GET['action']) ? $_GET['action'] : 'init')));
if (!in_array($action, array('init', 'report', 'detail', 'csv'), true)) {
    flkFail(400, 'invalid_request', 'Unknown action');
}
$tplanId = flkPositiveInt(isset($_GET['tplan_id']) ? $_GET['tplan_id'] : null);
$assertedProjectId = flkPositiveInt(isset($_GET['tproject_id']) ? $_GET['tproject_id'] : null);
$rawBuild = trim((string)(isset($_GET['build_id']) ? $_GET['build_id'] : '0'));
$rawPlatform = trim((string)(isset($_GET['platform_id']) ? $_GET['platform_id'] : '0'));
$verdictFilter = strtolower(trim((string)(isset($_GET['verdict']) ? $_GET['verdict'] : 'all')));
$detailVersionId = flkPositiveInt(isset($_GET['tcversion_id']) ? $_GET['tcversion_id'] : null);
if (!preg_match('/^[0-9]+$/', $rawBuild) || !preg_match('/^[0-9]+$/', $rawPlatform)) {
    flkFail(400, 'invalid_request', 'Invalid filter');
}
if (!in_array($verdictFilter, array('all', 'flaky', 'watch', 'stable', 'limited', 'never_run'), true)) {
    flkFail(400, 'invalid_request', 'Invalid verdict filter');
}
if ($action === 'detail' && $detailVersionId <= 0) {
    flkFail(400, 'invalid_request', 'Missing tcversion_id');
}
if ($tplanId <= 0) { flkFail(400, 'invalid_request', 'Missing or invalid tplan_id'); }

$tbl = tlObjectWithDB::getDBTables(array(
    'testplans', 'testprojects', 'nodes_hierarchy', 'tcversions',
    'builds', 'platforms', 'testplan_platforms', 'testplan_tcversions', 'executions',
));
$planRow = $db->get_recordset(
    "SELECT id, testproject_id FROM {$tbl['testplans']} WHERE id = " . $tplanId);
if (!is_array($planRow) || count($planRow) === 0) {
    flkFail(404, 'tplan_not_found', 'Test plan not found');
}
$owningProjectId = intval($planRow[0]['testproject_id']);
if ($owningProjectId <= 0) { flkFail(404, 'tplan_not_found', 'Test plan has no owning project'); }
if (!$user->hasRight($db, 'testplan_metrics', $owningProjectId, $tplanId, true)) {
    tLog('BFF executionflakiness: user ' . $userId . ' lacks testplan_metrics' .
         ' on tproject ' . $owningProjectId, 'AUDIT');
    flkFail(403, 'no_right', 'testplan_metrics right required');
}
if ($assertedProjectId > 0 && $assertedProjectId !== $owningProjectId) {
    flkFail(404, 'project_mismatch', 'Test plan belongs to another test project');
}
$planName = testplan::getName($db, $tplanId);
$projectName = testproject::getName($db, $owningProjectId);
if (is_null($planName) || trim((string)$planName) === '' ||
    is_null($projectName) || trim((string)$projectName) === '') {
    flkFail(404, 'tplan_not_found', 'Test plan not found');
}
$context = array(
    'tplan_id' => $tplanId,
    'tplan_name' => flkDecode((string)$planName),
    'tproject_id' => $owningProjectId,
    'tproject_name' => flkDecode((string)$projectName),
);

$builds = array();
$buildRs = $db->get_recordset(
    "SELECT id, name FROM {$tbl['builds']} WHERE testproject_id = " . $owningProjectId .
    " AND active = 1 ORDER BY id");
if (is_array($buildRs)) {
    foreach ($buildRs as $b) {
        $builds[] = array('id' => intval($b['id']), 'name' => flkDecode($b['name']));
    }
}
$platforms = array();
$platformRs = $db->get_recordset(
    "SELECT p.id, p.name FROM {$tbl['platforms']} p" .
    " JOIN {$tbl['testplan_platforms']} tpp ON tpp.platform_id = p.id" .
    " WHERE tpp.testplan_id = " . $tplanId . " ORDER BY p.name");
if (is_array($platformRs)) {
    foreach ($platformRs as $p) {
        $platforms[] = array('id' => intval($p['id']), 'name' => flkDecode($p['name']));
    }
}
$buildId = intval($rawBuild);
$platformId = intval($rawPlatform);
$knownBuild = ($buildId === 0);
foreach ($builds as $b) { if ($b['id'] === $buildId) { $knownBuild = true; } }
if (!$knownBuild) { flkFail(400, 'invalid_request', 'build_id does not belong to this project'); }
$knownPlatform = ($platformId === 0);
foreach ($platforms as $p) { if ($p['id'] === $platformId) { $knownPlatform = true; } }
if (!$knownPlatform) { flkFail(400, 'invalid_request', 'platform_id does not belong to this plan'); }
$filter = array('build_id' => $buildId, 'platform_id' => $platformId, 'verdict' => $verdictFilter);
$filtersPayload = array('builds' => $builds, 'platforms' => $platforms);

if ($action === 'init') {
    flkOut(array('status' => 'ok', 'code' => 200, 'context' => $context,
                 'filters' => $filtersPayload, 'filter' => $filter));
}

$assignRs = $db->get_recordset(
    "SELECT DISTINCT tcversion_id, platform_id FROM {$tbl['testplan_tcversions']}" .
    " WHERE testplan_id = " . $tplanId);
if (!is_array($assignRs)) { $assignRs = array(); }
$assigned = array();
foreach ($assignRs as $a) {
    $vid = intval($a['tcversion_id']);
    $assigned[$vid] = true;
}

$where = " WHERE e.testplan_id = " . $tplanId;
if ($buildId > 0) { $where .= " AND e.build_id = " . $buildId; }
if ($platformId > 0) { $where .= " AND (e.platform_id = " . $platformId . " OR e.platform_id = 0)"; }
$execRs = $db->get_recordset(
    "SELECT e.id, e.tcversion_id, e.status, e.build_id, e.platform_id," .
    " e.execution_ts, e.tester_id, e.tcversion_number, e.notes" .
    " FROM {$tbl['executions']} e" . $where .
    " ORDER BY e.tcversion_id, e.execution_ts ASC, e.id ASC");
if (!is_array($execRs)) { $execRs = array(); }

$buildNames = array();
foreach ($builds as $b) { $buildNames[$b['id']] = $b['name']; }
$platformNames = array();
foreach ($platforms as $p) { $platformNames[$p['id']] = $p['name']; }
$prefixRow = $db->get_recordset(
    "SELECT prefix FROM {$tbl['testprojects']} WHERE id = " . $owningProjectId);
$prefix = (is_array($prefixRow) && count($prefixRow) > 0)
    ? trim((string)$prefixRow[0]['prefix']) : '';
$versionCache = array();
$pathCache = array();
$cases = array();
foreach (array_keys($assigned) as $vid) {
    $cases[$vid] = array(
        'tcversion_id' => $vid, 'runs' => 0, 'flips' => 0, 'recovered' => 0,
        'regressed' => 0, 'passed' => 0, 'failed' => 0, 'blocked' => 0,
        'not_run' => 0, 'first_status' => '', 'last_status' => '',
        'first_ts' => '', 'last_ts' => '', 'builds' => array(),
        'platforms' => array(), 'testers' => array(), 'evidence' => array(),
    );
}
foreach ($execRs as $e) {
    $vid = intval($e['tcversion_id']);
    if (!isset($cases[$vid])) {
        $assigned[$vid] = true;
        $cases[$vid] = array(
            'tcversion_id' => $vid, 'runs' => 0, 'flips' => 0, 'recovered' => 0,
            'regressed' => 0, 'passed' => 0, 'failed' => 0, 'blocked' => 0,
            'not_run' => 0, 'first_status' => '', 'last_status' => '',
            'first_ts' => '', 'last_ts' => '', 'builds' => array(),
            'platforms' => array(), 'testers' => array(), 'evidence' => array(),
        );
    }
    $item = &$cases[$vid];
    $state = flkStatus($e['status']);
    $ts = (string)($e['execution_ts'] ?? '');
    if ($item['runs'] > 0 && $item['last_status'] !== $state) {
        $item['flips']++;
        if ($item['last_status'] === 'failed' && $state === 'passed') { $item['recovered']++; }
        if ($item['last_status'] === 'passed' && $state === 'failed') { $item['regressed']++; }
    }
    $item['first_status'] = $item['runs'] === 0 ? $state : $item['first_status'];
    $item['last_status'] = $state;
    if ($item['first_ts'] === '' || $ts < $item['first_ts']) { $item['first_ts'] = $ts; }
    if ($ts > $item['last_ts']) { $item['last_ts'] = $ts; }
    $item['runs']++;
    $item[$state]++;
    $buildIdRow = intval($e['build_id']);
    $platformIdRow = intval($e['platform_id']);
    if (isset($buildNames[$buildIdRow])) { $item['builds'][$buildNames[$buildIdRow]] = true; }
    if (isset($platformNames[$platformIdRow])) { $item['platforms'][$platformNames[$platformIdRow]] = true; }
    $testerId = intval($e['tester_id']);
    if ($testerId > 0) { $item['testers'][$testerId] = true; }
    $item['evidence'][] = array(
        'execution_id' => intval($e['id']),
        'execution_ts' => $ts,
        'status' => $state,
        'build' => isset($buildNames[$buildIdRow]) ? $buildNames[$buildIdRow] : '',
        'platform' => isset($platformNames[$platformIdRow]) ? $platformNames[$platformIdRow] : '',
        'tester_id' => $testerId,
        'tcversion_number' => intval($e['tcversion_number']),
        'notes' => flkText($e['notes']),
    );
    unset($item);
}

$rows = array();
foreach ($cases as $item) {
    $vid = intval($item['tcversion_id']);
    $info = flkVersionInfo($db, $tbl, $vid, $prefix, $versionCache, $pathCache);
    $runs = $item['runs'];
    if ($runs === 0) {
        $verdict = 'never_run';
    } elseif ($runs === 1) {
        $verdict = 'limited';
    } elseif ($item['flips'] >= 2 || flkPct($item['flips'], $runs - 1) >= 50.0) {
        $verdict = 'flaky';
    } elseif ($item['flips'] > 0 || $item['recovered'] > 0 || $item['regressed'] > 0) {
        $verdict = 'watch';
    } else {
        $verdict = 'stable';
    }
    $buildNamesRow = array_keys($item['builds']);
    sort($buildNamesRow);
    $platformNamesRow = array_keys($item['platforms']);
    sort($platformNamesRow);
    $testers = array_keys($item['testers']);
    sort($testers);
    $rows[] = array(
        'tcversion_id' => $vid,
        'test_case' => $info['external_id'] . ' ' . $info['tcase_name'],
        'tcase_name' => $info['tcase_name'],
        'external_id' => $info['external_id'],
        'version' => $info['version'],
        'suite' => $info['path'],
        'runs' => $runs,
        'flips' => $item['flips'],
        'flip_rate' => $runs > 1 ? flkPct($item['flips'], $runs - 1) : 0.0,
        'recovered' => $item['recovered'],
        'regressed' => $item['regressed'],
        'passed' => $item['passed'],
        'failed' => $item['failed'],
        'blocked' => $item['blocked'],
        'not_run' => $item['not_run'],
        'pass_rate' => flkPct($item['passed'], $runs),
        'first_status' => $item['first_status'],
        'last_status' => $item['last_status'],
        'first_ts' => $item['first_ts'],
        'last_ts' => $item['last_ts'],
        'build_names' => $buildNamesRow,
        'platform_names' => $platformNamesRow,
        'testers' => $testers,
        'verdict' => $verdict,
        'evidence' => $item['evidence'],
    );
}
usort($rows, function ($a, $b) {
    $cmp = $b['flips'] - $a['flips'];
    if ($cmp !== 0) { return $cmp; }
    return strcmp($a['test_case'], $b['test_case']);
});

$summary = array(
    'assigned' => count($rows),
    'executed' => 0,
    'executions' => 0,
    'total_flips' => 0,
    'flaky_cases' => 0,
    'watch_cases' => 0,
    'stable_cases' => 0,
    'limited_cases' => 0,
    'never_run' => 0,
    'recovered' => 0,
    'regressed' => 0,
    'passed' => 0,
    'failed' => 0,
    'blocked' => 0,
    'avg_runs' => 0.0,
    'max_runs' => 0,
    'pass_rate' => 0.0,
);
foreach ($rows as $r) {
    $summary['executions'] += $r['runs'];
    $summary['total_flips'] += $r['flips'];
    $summary['recovered'] += $r['recovered'];
    $summary['regressed'] += $r['regressed'];
    $summary['passed'] += $r['passed'];
    $summary['failed'] += $r['failed'];
    $summary['blocked'] += $r['blocked'];
    $summary[$r['verdict'] . '_cases'] = isset($summary[$r['verdict'] . '_cases'])
        ? $summary[$r['verdict'] . '_cases'] + 1 : 0;
    if ($r['verdict'] === 'never_run') { $summary['never_run']++; }
    if ($r['runs'] > 0) { $summary['executed']++; }
    if ($r['runs'] > $summary['max_runs']) { $summary['max_runs'] = $r['runs']; }
}
$summary['avg_runs'] = $summary['executed'] > 0
    ? round($summary['executions'] / $summary['executed'], 1) : 0.0;
$summary['pass_rate'] = flkPct($summary['passed'], $summary['executions']);
if ($summary['executions'] === 0) { $verdict = 'no_data'; }
elseif ($summary['flaky_cases'] > 0) { $verdict = 'at_risk'; }
elseif ($summary['watch_cases'] > 0 || $summary['recovered'] > 0 || $summary['regressed'] > 0) {
    $verdict = 'watch';
} else { $verdict = 'stable'; }

if ($verdictFilter !== 'all') {
    $filtered = array();
    foreach ($rows as $r) { if ($r['verdict'] === $verdictFilter) { $filtered[] = $r; } }
    $rows = $filtered;
}

if ($action === 'detail') {
    foreach ($rows as $r) {
        if ($r['tcversion_id'] === $detailVersionId) {
            flkOut(array('status' => 'ok', 'code' => 200, 'context' => $context,
                         'item' => $r, 'executions' => $r['evidence']));
        }
    }
    flkFail(404, 'test_case_not_found', 'Test case is not part of this report');
}

if ($action === 'csv') {
    if (ob_get_level() > 0) { ob_clean(); }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="execution-flakiness.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, array('test_case', 'version', 'suite', 'runs', 'flips', 'flip_rate',
        'recovered', 'regressed', 'passed', 'failed', 'blocked', 'not_run', 'pass_rate',
        'first_status', 'last_status', 'verdict'), ';');
    foreach ($rows as $r) {
        fputcsv($out, array(
            flkCsvCell($r['test_case']), $r['version'], flkCsvCell($r['suite']),
            $r['runs'], $r['flips'], $r['flip_rate'], $r['recovered'], $r['regressed'],
            $r['passed'], $r['failed'], $r['blocked'], $r['not_run'], $r['pass_rate'],
            $r['first_status'], $r['last_status'], $r['verdict'],
        ), ';');
    }
    fclose($out);
    exit;
}

flkOut(array('status' => 'ok', 'code' => 200, 'context' => $context,
             'filter' => $filter, 'filters' => $filtersPayload,
             'summary' => $summary, 'rows' => $rows, 'verdict' => $verdict));
