<?php
/**
 * Test Plan Linked Defects - BFF (Refs #2011).
 *
 * Net-new read-only report (gui/templates/results/linkedDefects.html) over the
 * defect links TestLink stores on executions. 1.9.20 can link a bug to an
 * execution but never answers "which defects affect this plan, and where?"
 *
 * Contract: GET|HEAD
 *   ?action=init&tplan_id=N[&tproject_id=P]
 *   ?action=report&tplan_id=N[&tproject_id=P][&build_id=B][&platform_id=L]
 *                [&status=all|p|f|b][&bug_id=ID]
 *   ?action=detail&tplan_id=N&bug_id=ID[&same filters]
 *   ?action=csv&tplan_id=N[&same filters]
 *   400 invalid_request | 401 not_authenticated | 403 no_right
 *   404 tplan_not_found/project_mismatch | 405 method_not_allowed
 */
require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function ldfOut($payload, $code = null)
{
    if ($code !== null) {
        http_response_code($code);
    }
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function ldfFail($httpCode, $code, $message = '')
{
    ldfOut(array(
        'status' => 'error',
        'code' => $code,
        'message' => $message !== '' ? $message : $code,
    ), $httpCode);
}

function ldfPositiveInt($raw)
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

function ldfDecode($value)
{
    return html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function ldfStatus($letter)
{
    switch (strtolower(trim((string)$letter))) {
        case 'p': return 'passed';
        case 'f': return 'failed';
        case 'b': return 'blocked';
    }
    return 'not_run';
}

function ldfPct($part, $total)
{
    return ($total > 0) ? round(100.0 * $part / $total, 1) : 0.0;
}

function ldfCsvCell($value)
{
    $s = ldfDecode($value);
    if ($s !== '' && preg_match('/^[\s]*[=+\-@]/', $s)) {
        $s = "'" . $s;
    }
    return str_replace(array('"', "\r", "\n"), array('""', ' ', ' '), $s);
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    ldfFail(405, 'method_not_allowed');
}

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    ldfFail(401, 'not_authenticated', 'Not authenticated');
}
bffEnforceSession($db);

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    ldfFail(401, 'not_authenticated', 'User not found');
}

$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if (!in_array($action, array('init', 'report', 'detail', 'csv'), true)) {
    ldfFail(400, 'invalid_request', 'Unknown action');
}

$tplanId = ldfPositiveInt($_GET['tplan_id'] ?? null);
$assertedProjectId = ldfPositiveInt($_GET['tproject_id'] ?? null);
$rawBuild = trim((string)($_GET['build_id'] ?? '0'));
$rawPlatform = trim((string)($_GET['platform_id'] ?? '0'));
$status = strtolower(trim((string)($_GET['status'] ?? 'all')));
$bugId = trim((string)($_GET['bug_id'] ?? ''));
if (!in_array($status, array('all', 'p', 'f', 'b'), true)) {
    ldfFail(400, 'invalid_request', 'Invalid status');
}
if ($tplanId <= 0) {
    ldfFail(400, 'invalid_request', 'Missing or invalid tplan_id');
}
if (($rawBuild !== '' && !preg_match('/^[0-9]+$/', $rawBuild)) ||
    ($rawPlatform !== '' && !preg_match('/^[0-9]+$/', $rawPlatform))) {
    ldfFail(400, 'invalid_request', 'Invalid filter');
}
if (strlen($bugId) > 64 || ($bugId !== '' && preg_match('/[^\x20-\x7e]/', $bugId))) {
    ldfFail(400, 'invalid_request', 'Invalid bug id');
}
$buildId = intval($rawBuild);
$platformId = intval($rawPlatform);

$tbl = tlObjectWithDB::getDBTables(array(
    'testplans', 'testprojects', 'nodes_hierarchy', 'tcversions',
    'builds', 'platforms', 'testplan_platforms', 'executions', 'execution_bugs',
));

// Opaque plan resolution first, right on the OWNING project second, client
// project assertion last (the #1697 no-oracle lesson).
$planRow = $db->get_recordset(
    "SELECT id, testproject_id FROM {$tbl['testplans']} WHERE id = " . intval($tplanId));
if (!is_array($planRow) || count($planRow) === 0) {
    ldfFail(404, 'tplan_not_found', 'Test plan not found');
}
$owningProjectId = intval($planRow[0]['testproject_id']);
if ($owningProjectId <= 0) {
    ldfFail(404, 'tplan_not_found', 'Test plan has no owning test project');
}
if (!$user->hasRight($db, 'testplan_metrics', $owningProjectId, $tplanId, true)) {
    tLog('BFF linkeddefects: user ' . intval($userId) .
         ' has no testplan_metrics right on tproject ' . intval($owningProjectId) .
         ' (tplan ' . $tplanId . ')', 'AUDIT');
    ldfFail(403, 'no_right', 'testplan_metrics right required');
}
if ($assertedProjectId > 0 && $assertedProjectId !== $owningProjectId) {
    ldfFail(404, 'project_mismatch', 'Test plan belongs to another test project');
}

$planName = testplan::getName($db, $tplanId);
$projectName = testproject::getName($db, $owningProjectId);
if (is_null($planName) || trim((string)$planName) === '' ||
    is_null($projectName) || trim((string)$projectName) === '') {
    ldfFail(404, 'tplan_not_found', 'Test plan not found');
}

$builds = array();
$buildRs = $db->get_recordset(
    "SELECT id, name FROM {$tbl['builds']} WHERE testproject_id = " . intval($owningProjectId) .
    " AND active = 1 ORDER BY id");
if (is_array($buildRs)) {
    foreach ($buildRs as $b) {
        $builds[] = array('id' => intval($b['id']), 'name' => ldfDecode($b['name']));
    }
}

$platforms = array();
$platformRs = $db->get_recordset(
    "SELECT p.id, p.name FROM {$tbl['platforms']} p" .
    " JOIN {$tbl['testplan_platforms']} tpp ON tpp.platform_id = p.id" .
    " WHERE tpp.testplan_id = " . intval($tplanId) . " ORDER BY p.name");
if (is_array($platformRs)) {
    foreach ($platformRs as $p) {
        $platforms[] = array('id' => intval($p['id']), 'name' => ldfDecode($p['name']));
    }
}

$knownBuild = ($buildId === 0);
foreach ($builds as $b) { if ($b['id'] === $buildId) { $knownBuild = true; break; } }
if (!$knownBuild) {
    ldfFail(400, 'invalid_request', 'build_id does not belong to this test project');
}
$knownPlatform = ($platformId === 0);
foreach ($platforms as $p) { if ($p['id'] === $platformId) { $knownPlatform = true; break; } }
if (!$knownPlatform) {
    ldfFail(400, 'invalid_request', 'platform_id does not belong to this test plan');
}
if ($bugId !== '' && $action !== 'detail') {
    ldfFail(400, 'invalid_request', 'bug_id is only valid for the detail action');
}
if ($action === 'detail' && $bugId === '') {
    ldfFail(400, 'invalid_request', 'Missing bug_id');
}

$context = array(
    'tplan_id' => $tplanId,
    'tplan_name' => ldfDecode((string)$planName),
    'tproject_id' => $owningProjectId,
    'tproject_name' => ldfDecode((string)$projectName),
);
$filterEcho = array(
    'build_id' => $buildId, 'platform_id' => $platformId,
    'status' => $status, 'bug_id' => $bugId,
);
$filtersPayload = array('builds' => $builds, 'platforms' => $platforms);

if ($action === 'init') {
    ldfOut(array(
        'status' => 'ok',
        'code' => 200,
        'context' => $context,
        'filters' => $filtersPayload,
        'filter' => $filterEcho,
    ));
}

// ---------------------------------------------------------------------------
// Link rows: every execution bug link inside the requested scope.
// ---------------------------------------------------------------------------
$linkSql =
    "SELECT eb.bug_id, eb.execution_id, eb.tcstep_id, e.status, e.execution_ts, " .
    "       e.tester_id, e.build_id, e.platform_id, e.tcversion_id, " .
    "       nh_tc.id AS tcase_id, nh_tc.name AS tcase_name, tv.version " .
    "FROM {$tbl['execution_bugs']} eb" .
    " JOIN {$tbl['executions']} e ON e.id = eb.execution_id" .
    " JOIN {$tbl['tcversions']} tv ON tv.id = e.tcversion_id" .
    " JOIN {$tbl['nodes_hierarchy']} nh_tv ON nh_tv.id = tv.id" .
    " JOIN {$tbl['nodes_hierarchy']} nh_tc ON nh_tc.id = nh_tv.parent_id" .
    " WHERE e.testplan_id = " . intval($tplanId);
if ($buildId > 0) {
    $linkSql .= " AND e.build_id = " . intval($buildId);
}
if ($platformId > 0) {
    $linkSql .= " AND (e.platform_id = 0 OR e.platform_id = " . intval($platformId) . ")";
}
if ($status !== 'all') {
    $linkSql .= " AND e.status = '" . $db->prepare_string($status) . "'";
}
if ($bugId !== '') {
    $linkSql .= " AND eb.bug_id = '" . $db->prepare_string($bugId) . "'";
}
$linkSql .= " ORDER BY e.execution_ts DESC, e.id DESC, eb.bug_id";
$linkRs = $db->get_recordset($linkSql);
if (!is_array($linkRs)) { $linkRs = array(); }

$buildNames = array();
foreach ($builds as $b) { $buildNames[$b['id']] = $b['name']; }
$platformNames = array();
foreach ($platforms as $p) { $platformNames[$p['id']] = $p['name']; }

$links = array();
foreach ($linkRs as $r) {
    $pid = intval($r['platform_id']);
    $links[] = array(
        'bug_id' => ldfDecode($r['bug_id']),
        'execution_id' => intval($r['execution_id']),
        'tcstep_id' => intval($r['tcstep_id']),
        'tcase_id' => intval($r['tcase_id']),
        'tcase_name' => ldfDecode($r['tcase_name']),
        'version' => intval($r['version']),
        'tcversion_id' => intval($r['tcversion_id']),
        'status' => ldfStatus($r['status']),
        'status_letter' => strtolower(trim((string)$r['status'])),
        'build_id' => intval($r['build_id']),
        'build_name' => isset($buildNames[intval($r['build_id'])]) ? $buildNames[intval($r['build_id'])] : '',
        'platform_id' => $pid,
        'platform_name' => ($pid > 0 && isset($platformNames[$pid])) ? $platformNames[$pid] : '',
        'tester_id' => intval($r['tester_id']),
        'execution_ts' => (string)($r['execution_ts'] ?? ''),
    );
}

if ($action === 'detail') {
    ldfOut(array(
        'status' => 'ok',
        'code' => 200,
        'context' => $context,
        'filter' => $filterEcho,
        'links' => $links,
    ));
}

// ---------------------------------------------------------------------------
// Aggregate per defect.
// ---------------------------------------------------------------------------
$byBug = array();
foreach ($links as $l) {
    $bug = $l['bug_id'];
    if (!isset($byBug[$bug])) {
        $byBug[$bug] = array(
            'bug_id' => $bug,
            'links' => 0,
            'executions' => array(),
            'cases' => array(),
            'builds' => array(),
            'platforms' => array(),
            'statuses' => array(),
            'first_ts' => $l['execution_ts'],
            'last_ts' => $l['execution_ts'],
            'sample_case' => $l['tcase_name'],
            'sample_build' => $l['build_name'],
            'sample_platform' => $l['platform_name'],
            'sample_tester' => $l['tester_id'],
            'details' => array(),
        );
    }
    $b = &$byBug[$bug];
    $b['links']++;
    $b['executions'][$l['execution_id']] = true;
    $b['cases'][$l['tcase_id']] = true;
    if ($l['build_id'] > 0) { $b['builds'][$l['build_id']] = true; }
    if ($l['platform_id'] > 0) { $b['platforms'][$l['platform_id']] = true; }
    $b['statuses'][$l['status']] = true;
    if ($l['execution_ts'] !== '' && $l['execution_ts'] < $b['first_ts']) {
        $b['first_ts'] = $l['execution_ts'];
    }
    if ($l['execution_ts'] > $b['last_ts']) {
        $b['last_ts'] = $l['execution_ts'];
    }
    if (count($b['details']) < 12) { $b['details'][] = $l; }
    unset($b);
}

$rows = array();
$summary = array(
    'links' => count($links),
    'defects' => count($byBug),
    'executions' => 0,
    'cases' => 0,
    'builds' => 0,
    'platforms' => 0,
    'failed_links' => 0,
    'blocked_links' => 0,
    'passed_links' => 0,
    'failed_defects' => 0,
    'last_activity' => '',
);
$allBuilds = array();
$allPlatforms = array();
$allCases = array();
$allExecutions = array();

foreach ($links as $l) {
    if ($l['status'] === 'failed') { $summary['failed_links']++; }
    if ($l['status'] === 'blocked') { $summary['blocked_links']++; }
    if ($l['status'] === 'passed') { $summary['passed_links']++; }
}
foreach ($byBug as $bug => $b) {
    $hasFailed = isset($b['statuses']['failed']);
    $hasBlocked = isset($b['statuses']['blocked']);
    $hasPassed = isset($b['statuses']['passed']);
    if ($hasFailed) {
        $verdict = 'failed';
    } elseif ($hasBlocked) {
        $verdict = 'blocked';
    } elseif ($hasPassed) {
        $verdict = 'passed';
    } else {
        $verdict = 'unresolved';
    }
    if ($verdict === 'failed') { $summary['failed_defects']++; }
    foreach ($b['executions'] as $id => $v) { $allExecutions[$id] = true; }
    foreach ($b['cases'] as $id => $v) { $allCases[$id] = true; }
    foreach ($b['builds'] as $id => $v) { if ($id > 0) { $allBuilds[$id] = true; } }
    foreach ($b['platforms'] as $id => $v) { $allPlatforms[$id] = true; }
    if ($b['last_ts'] > $summary['last_activity']) {
        $summary['last_activity'] = $b['last_ts'];
    }
    $statuses = array_keys($b['statuses']);
    sort($statuses);
    $rows[] = array(
        'bug_id' => $bug,
        'links' => $b['links'],
        'executions' => count($b['executions']),
        'cases' => count($b['cases']),
        'builds' => count($b['builds']),
        'platforms' => count($b['platforms']),
        'statuses' => $statuses,
        'verdict' => $verdict,
        'first_ts' => $b['first_ts'],
        'last_ts' => $b['last_ts'],
        'sample_case' => $b['sample_case'],
        'sample_build' => $b['sample_build'],
        'sample_platform' => $b['sample_platform'],
        'sample_tester' => $b['sample_tester'],
        'details' => $b['details'],
    );
}
usort($rows, function ($a, $b) {
    if ($a['links'] !== $b['links']) { return $b['links'] - $a['links']; }
    return strcmp($b['last_ts'], $a['last_ts']);
});

$summary['executions'] = count($allExecutions);
$summary['cases'] = count($allCases);
$summary['builds'] = count($allBuilds);
$summary['platforms'] = count($allPlatforms);
$summary['pass_rate'] = ldfPct($summary['passed_links'], $summary['links']);

if ($summary['links'] === 0) {
    $verdict = 'no_data';
} elseif ($summary['failed_defects'] > 0) {
    $verdict = 'at_risk';
} elseif ($summary['blocked_links'] > 0) {
    $verdict = 'watch';
} else {
    $verdict = 'clear';
}

if ($action === 'csv') {
    if (ob_get_level() > 0) { ob_clean(); }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="linked-defects.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, array(
        'bug_id', 'links', 'executions', 'cases', 'builds', 'platforms',
        'statuses', 'verdict', 'first_ts', 'last_ts', 'sample_case',
        'sample_build', 'sample_platform',
    ), ';');
    foreach ($rows as $r) {
        fputcsv($out, array(
            ldfCsvCell($r['bug_id']), $r['links'], $r['executions'], $r['cases'],
            $r['builds'], $r['platforms'], ldfCsvCell(implode('|', $r['statuses'])),
            ldfCsvCell($r['verdict']), ldfCsvCell($r['first_ts']),
            ldfCsvCell($r['last_ts']), ldfCsvCell($r['sample_case']),
            ldfCsvCell($r['sample_build']), ldfCsvCell($r['sample_platform']),
        ), ';');
    }
    fclose($out);
    exit;
}

ldfOut(array(
    'status' => 'ok',
    'code' => 200,
    'context' => $context,
    'filter' => $filterEcho,
    'filters' => $filtersPayload,
    'summary' => $summary,
    'rows' => $rows,
    'verdict' => $verdict,
));
