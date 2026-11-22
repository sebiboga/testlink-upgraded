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

function darOut($payload, $code = null)
{
    if ($code !== null) {
        http_response_code($code);
    }
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function darFail($httpCode, $code, $message = '')
{
    darOut(array('status' => 'error', 'code' => $code,
                 'message' => $message !== '' ? $message : $code), $httpCode);
}

function darPositiveInt($raw)
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

function darDecode($value)
{
    return html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function darText($value)
{
    return trim(strip_tags(darDecode($value)));
}

function darPct($part, $total)
{
    return $total > 0 ? round(100.0 * $part / $total, 1) : 0.0;
}

function darCsvCell($value)
{
    $s = darDecode($value);
    if ($s !== '' && preg_match('/^[\s]*[=+\-@]/', $s)) {
        $s = "'" . $s;
    }
    return str_replace(array("\r", "\n"), array(' ', ' '), $s);
}

function darTables()
{
    return tlObjectWithDB::getDBTables(array(
        'testplans', 'testprojects', 'nodes_hierarchy', 'tcversions',
        'builds', 'platforms', 'testplan_platforms', 'executions',
        'execution_bugs',
    ));
}

function darStatus($raw)
{
    $state = strtolower(trim((string)$raw));
    if ($state === 'p') { return 'passed'; }
    if ($state === 'f') { return 'failed'; }
    if ($state === 'b') { return 'blocked'; }
    return 'not_run';
}

function darPath($db, $tbl, $nodeId, &$cache)
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
            " WHERE id = " . $current);
        if (!is_array($row) || count($row) === 0) {
            break;
        }
        $r = $row[0];
        $parent = intval($r['parent_id']);
        if ($depth > 0 && intval($r['node_type_id']) !== 1) {
            $parts[] = darDecode($r['name']);
        }
        if (intval($r['node_type_id']) === 1 || $parent === $current) {
            break;
        }
        $current = $parent;
    }
    $path = implode(' / ', array_reverse($parts));
    $cache[$nodeId] = $path;
    return $path;
}

$method = strtoupper(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    darFail(405, 'method_not_allowed');
}

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = isset($_SESSION['userID']) ? intval($_SESSION['userID']) : 0;
if ($userId <= 0) {
    darFail(401, 'not_authenticated', 'Not authenticated');
}
bffEnforceSession($db);

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    darFail(401, 'not_authenticated', 'User not found');
}

$action = strtolower(trim((string)(isset($_GET['action']) ? $_GET['action'] : 'init')));
if (!in_array($action, array('init', 'report', 'detail', 'csv'), true)) {
    darFail(400, 'invalid_request', 'Unknown action');
}

$tplanId = darPositiveInt(isset($_GET['tplan_id']) ? $_GET['tplan_id'] : null);
$assertedProjectId = darPositiveInt(isset($_GET['tproject_id']) ? $_GET['tproject_id'] : null);
$rawBuild = trim((string)(isset($_GET['build_id']) ? $_GET['build_id'] : '0'));
$rawPlatform = trim((string)(isset($_GET['platform_id']) ? $_GET['platform_id'] : '0'));
$detailBugId = trim((string)(isset($_GET['bug_id']) ? $_GET['bug_id'] : ''));
if ($rawBuild !== '' && !preg_match('/^[0-9]+$/', $rawBuild)) {
    darFail(400, 'invalid_request', 'Invalid build_id');
}
if ($rawPlatform !== '' && !preg_match('/^[0-9]+$/', $rawPlatform)) {
    darFail(400, 'invalid_request', 'Invalid platform_id');
}
if ($detailBugId !== '' && $action !== 'detail') {
    darFail(400, 'invalid_request', 'bug_id is only valid for detail');
}
if ($action === 'detail' && $detailBugId === '') {
    darFail(400, 'invalid_request', 'Missing bug_id');
}
if (strlen($detailBugId) > 255) {
    darFail(400, 'invalid_request', 'bug_id is too long');
}
if ($tplanId <= 0) {
    darFail(400, 'invalid_request', 'Missing or invalid tplan_id');
}
$buildId = intval($rawBuild);
$platformId = intval($rawPlatform);

$tbl = darTables();
$planRow = $db->get_recordset(
    "SELECT id, testproject_id FROM {$tbl['testplans']} WHERE id = " . $tplanId);
if (!is_array($planRow) || count($planRow) === 0) {
    darFail(404, 'tplan_not_found', 'Test plan not found');
}
$owningProjectId = intval($planRow[0]['testproject_id']);
if ($owningProjectId <= 0) {
    darFail(404, 'tplan_not_found', 'Test plan has no owning test project');
}
if (!$user->hasRight($db, 'testplan_metrics', $owningProjectId, $tplanId, true)) {
    tLog('BFF defectarrivalrate: user ' . $userId . ' lacks testplan_metrics' .
         ' on tproject ' . $owningProjectId, 'AUDIT');
    darFail(403, 'no_right', 'testplan_metrics right required');
}
if ($assertedProjectId > 0 && $assertedProjectId !== $owningProjectId) {
    darFail(404, 'project_mismatch', 'Test plan belongs to another test project');
}

$planName = testplan::getName($db, $tplanId);
$projectName = testproject::getName($db, $owningProjectId);
if (is_null($planName) || trim((string)$planName) === '' ||
    is_null($projectName) || trim((string)$projectName) === '') {
    darFail(404, 'tplan_not_found', 'Test plan not found');
}
$context = array(
    'tplan_id' => $tplanId,
    'tplan_name' => darDecode((string)$planName),
    'tproject_id' => $owningProjectId,
    'tproject_name' => darDecode((string)$projectName),
);
$filter = array('build_id' => $buildId, 'platform_id' => $platformId);

$builds = array();
$buildRs = $db->get_recordset(
    "SELECT id, name FROM {$tbl['builds']} WHERE testproject_id = " . $owningProjectId .
    " AND active = 1 ORDER BY id");
if (is_array($buildRs)) {
    foreach ($buildRs as $b) {
        $builds[] = array('id' => intval($b['id']), 'name' => darDecode($b['name']));
    }
}
$platforms = array();
$platformRs = $db->get_recordset(
    "SELECT p.id, p.name FROM {$tbl['platforms']} p" .
    " JOIN {$tbl['testplan_platforms']} tpp ON tpp.platform_id = p.id" .
    " WHERE tpp.testplan_id = " . $tplanId . " ORDER BY p.name");
if (is_array($platformRs)) {
    foreach ($platformRs as $p) {
        $platforms[] = array('id' => intval($p['id']), 'name' => darDecode($p['name']));
    }
}
$knownBuild = ($buildId === 0);
foreach ($builds as $b) { if ($b['id'] === $buildId) { $knownBuild = true; } }
if (!$knownBuild) {
    darFail(400, 'invalid_request', 'build_id does not belong to this test project');
}
$knownPlatform = ($platformId === 0);
foreach ($platforms as $p) { if ($p['id'] === $platformId) { $knownPlatform = true; } }
if (!$knownPlatform) {
    darFail(400, 'invalid_request', 'platform_id does not belong to this test plan');
}
$filtersPayload = array('builds' => $builds, 'platforms' => $platforms);

if ($action === 'init') {
    darOut(array('status' => 'ok', 'code' => 200, 'context' => $context,
                 'filters' => $filtersPayload, 'filter' => $filter));
}

$sql =
    "SELECT e.id, e.tcversion_id, e.status, e.build_id, e.platform_id," .
    " e.execution_ts, e.tester_id, b.bug_id, DATE_FORMAT(e.execution_ts, '%Y-%m-%d') AS day" .
    " FROM {$tbl['execution_bugs']} b" .
    " JOIN {$tbl['executions']} e ON e.id = b.execution_id" .
    " WHERE e.testplan_id = " . $tplanId;
if ($buildId > 0) {
    $sql .= " AND e.build_id = " . $buildId;
}
if ($platformId > 0) {
    $sql .= " AND (e.platform_id = " . $platformId . " OR e.platform_id = 0)";
}
$execRs = $db->get_recordset($sql . " ORDER BY e.execution_ts ASC, e.id ASC");
if (!is_array($execRs)) {
    $execRs = array();
}

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

function darVersionInfo($db, $tbl, $tcversionId, $prefix, &$cache, &$pathCache)
{
    $vid = intval($tcversionId);
    if (isset($cache[$vid])) {
        return $cache[$vid];
    }
    $rs = $db->get_recordset(
        "SELECT tv.version, tv.tc_external_id, nh_tc.id AS tcase_id," .
        " nh_tc.name AS tcase_name" .
        " FROM {$tbl['tcversions']} tv" .
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
        'tcase_name' => darDecode($r['tcase_name']),
        'path' => darPath($db, $tbl, intval($r['tcase_id']), $pathCache),
    );
    $cache[$vid] = $info;
    return $info;
}

$days = array();
$defects = array();
foreach ($execRs as $e) {
    $bugId = darText($e['bug_id']);
    if ($bugId === '') {
        continue;
    }
    $day = (string)$e['day'];
    if ($day === '' || $day === '0000-00-00') {
        continue;
    }
    $ts = (string)($e['execution_ts'] ?? '');
    if (!isset($days[$day])) {
        $days[$day] = array(
            'day' => $day, 'new_defects' => 0, 'links' => 0, 'executions' => array(),
            'passed' => 0, 'failed' => 0, 'blocked' => 0, 'not_run' => 0,
            'cases' => array(), 'bugs' => array(),
        );
    }
    if (!isset($defects[$bugId])) {
        $defects[$bugId] = array(
            'bug_id' => $bugId, 'first_day' => $day, 'first_ts' => $ts,
            'last_ts' => $ts, 'links' => 0, 'executions' => array(),
            'passed' => 0, 'failed' => 0, 'blocked' => 0, 'not_run' => 0,
            'cases' => array(), 'suites' => array(), 'builds' => array(),
            'platforms' => array(), 'evidence' => array(),
        );
        $days[$day]['new_defects']++;
        $days[$day]['bugs'][$bugId] = true;
    }
    $item = &$defects[$bugId];
    $state = darStatus($e['status']);
    $execId = intval($e['id']);
    $days[$day]['links']++;
    $days[$day]['executions'][$execId] = true;
    $days[$day][$state]++;
    $item['links']++;
    $item['executions'][$execId] = true;
    $item[$state]++;
    if ($ts < $item['first_ts']) { $item['first_ts'] = $ts; }
    if ($ts > $item['last_ts']) { $item['last_ts'] = $ts; }
    $info = darVersionInfo($db, $tbl, intval($e['tcversion_id']), $prefix,
                           $versionCache, $pathCache);
    $caseLabel = $info['external_id'] . ' ' . $info['tcase_name'];
    $days[$day]['cases'][$caseLabel] = true;
    $item['cases'][$caseLabel] = true;
    if ($info['path'] !== '') {
        $item['suites'][explode(' / ', $info['path'])[0]] = true;
    }
    $buildName = isset($buildNames[intval($e['build_id'])]) ? $buildNames[intval($e['build_id'])] : '';
    $platformName = intval($e['platform_id']) > 0 && isset($platformNames[intval($e['platform_id'])])
        ? $platformNames[intval($e['platform_id'])] : '';
    if ($buildName !== '') { $item['builds'][$buildName] = true; }
    if ($platformName !== '') { $item['platforms'][$platformName] = true; }
    $item['evidence'][] = array(
        'execution_id' => $execId,
        'day' => $day,
        'execution_ts' => $ts,
        'status' => $state,
        'test_case' => $caseLabel,
        'version' => $info['version'],
        'suite' => $info['path'],
        'build' => $buildName,
        'platform' => $platformName,
        'tester_id' => intval($e['tester_id']),
    );
    unset($item);
}

$rows = array();
$cumulative = 0;
$allCases = array();
$peakDay = 0;
$peakDayValue = 0;
foreach ($days as $day => $d) {
    $cumulative += $d['new_defects'];
    if ($d['new_defects'] > $peakDayValue) {
        $peakDayValue = $d['new_defects'];
        $peakDay = $day;
    }
    foreach (array_keys($d['cases']) as $name) { $allCases[$name] = true; }
    $bugIds = array_keys($d['bugs']);
    sort($bugIds);
    $rows[] = array(
        'day' => $day,
        'new_defects' => $d['new_defects'],
        'cumulative' => $cumulative,
        'links' => $d['links'],
        'executions' => count($d['executions']),
        'passed' => $d['passed'],
        'failed' => $d['failed'],
        'blocked' => $d['blocked'],
        'not_run' => $d['not_run'],
        'cases' => count($d['cases']),
        'defects' => $bugIds,
        'defect_labels' => implode(' | ', $bugIds),
    );
}
sort($rows);

$lastSeven = 0;
$windowStart = date('Y-m-d', strtotime('-6 days'));
foreach ($rows as $r) {
    if ($r['day'] >= $windowStart) {
        $lastSeven += $r['new_defects'];
    }
}
$defectRows = array();
foreach ($defects as $bugId => $item) {
    $caseNames = array_keys($item['cases']);
    sort($caseNames);
    $suiteNames = array_keys($item['suites']);
    sort($suiteNames);
    $buildNamesRow = array_keys($item['builds']);
    sort($buildNamesRow);
    $platformNamesRow = array_keys($item['platforms']);
    sort($platformNamesRow);
    $defectRows[] = array(
        'bug_id' => $bugId,
        'first_day' => $item['first_day'],
        'first_ts' => $item['first_ts'],
        'last_ts' => $item['last_ts'],
        'links' => $item['links'],
        'executions' => count($item['executions']),
        'passed' => $item['passed'],
        'failed' => $item['failed'],
        'blocked' => $item['blocked'],
        'not_run' => $item['not_run'],
        'case_count' => count($caseNames),
        'case_names' => $caseNames,
        'suite_names' => $suiteNames,
        'build_names' => $buildNamesRow,
        'platform_names' => $platformNamesRow,
        'evidence' => $item['evidence'],
    );
}
usort($defectRows, function ($a, $b) {
    $cmp = strcmp($a['first_day'], $b['first_day']);
    if ($cmp !== 0) { return $cmp; }
    return strcmp($a['bug_id'], $b['bug_id']);
});

$totalDefects = count($defectRows);
$totalLinks = 0;
$failedRuns = 0;
foreach ($defectRows as $r) {
    $totalLinks += $r['links'];
    $failedRuns += $r['failed'];
}
$average = count($rows) > 0 ? round($totalDefects / count($rows), 2) : 0.0;
if ($totalDefects === 0) {
    $verdict = 'no_data';
} elseif ($lastSeven > max(3, $average * 3)) {
    $verdict = 'surge';
} elseif ($peakDayValue >= 5) {
    $verdict = 'watch';
} else {
    $verdict = 'steady';
}

$summary = array(
    'total_defects' => $totalDefects,
    'total_links' => $totalLinks,
    'active_days' => count($rows),
    'new_last_7' => $lastSeven,
    'average_per_day' => $average,
    'peak_day' => $peakDay,
    'peak_new' => $peakDayValue,
    'affected_cases' => count($allCases),
    'failed_runs' => $failedRuns,
    'first_day' => count($rows) > 0 ? $rows[0]['day'] : '',
    'last_day' => count($rows) > 0 ? $rows[count($rows) - 1]['day'] : '',
    'pass_share' => darPct(
        array_sum(array_map(function ($r) { return $r['passed']; }, $rows)),
        $totalLinks
    ),
);

if ($action === 'detail') {
    $detail = null;
    foreach ($defectRows as $r) {
        if ($r['bug_id'] === $detailBugId) { $detail = $r; break; }
    }
    if ($detail === null) {
        darFail(404, 'defect_not_found', 'Defect is not part of this report');
    }
    darOut(array('status' => 'ok', 'code' => 200, 'context' => $context,
                 'item' => $detail, 'executions' => $detail['evidence']));
}

if ($action === 'csv') {
    if (ob_get_level() > 0) { ob_clean(); }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="defect-arrival-rate.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, array('day', 'new_defects', 'cumulative', 'links', 'executions',
        'passed', 'failed', 'blocked', 'not_run', 'cases', 'defects'), ';');
    foreach ($rows as $r) {
        fputcsv($out, array(
            $r['day'], $r['new_defects'], $r['cumulative'], $r['links'],
            $r['executions'], $r['passed'], $r['failed'], $r['blocked'],
            $r['not_run'], $r['cases'], darCsvCell($r['defect_labels']),
        ), ';');
    }
    fclose($out);
    exit;
}

darOut(array('status' => 'ok', 'code' => 200, 'context' => $context,
             'filter' => $filter, 'filters' => $filtersPayload,
             'summary' => $summary, 'rows' => $rows,
             'defects' => $defectRows, 'verdict' => $verdict));
