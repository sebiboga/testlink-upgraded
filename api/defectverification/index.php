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

function dvfOut($payload, $code = null)
{
    if ($code !== null) { http_response_code($code); }
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function dvfFail($httpCode, $code, $message = '')
{
    dvfOut(array('status' => 'error', 'code' => $code,
                 'message' => $message !== '' ? $message : $code), $httpCode);
}

function dvfPositiveInt($raw)
{
    if ($raw === null || is_array($raw)) { return 0; }
    $raw = trim((string)$raw);
    if ($raw === '' || strlen($raw) > 10 || !preg_match('/^[0-9]+$/', $raw)) { return 0; }
    $n = intval($raw);
    return $n > 0 ? $n : 0;
}

function dvfDecode($value)
{
    return html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function dvfText($value)
{
    return trim(preg_replace('/\s+/', ' ', strip_tags(dvfDecode($value))));
}

function dvfPct($part, $total)
{
    return $total > 0 ? round(100.0 * $part / $total, 1) : 0.0;
}

function dvfStatus($letter)
{
    switch (strtolower(trim((string)$letter))) {
        case 'p': return 'passed';
        case 'f': return 'failed';
        case 'b': return 'blocked';
    }
    return 'not_run';
}

function dvfCsvCell($value)
{
    $s = str_replace(array("\r", "\n", "\u{00A0}"), array(' ', ' ', ' '), dvfDecode($value));
    if ($s !== '' && preg_match('/^\s*[=+\-@]/', $s)) { $s = "'" . $s; }
    return $s;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    dvfFail(405, 'method_not_allowed');
}

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) { dvfFail(401, 'not_authenticated', 'Not authenticated'); }
bffEnforceSession($db);
$user = tlUser::getByID($db, $userId);
if (is_null($user)) { dvfFail(401, 'not_authenticated', 'User not found'); }

$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if (!in_array($action, array('init', 'projects', 'report', 'detail', 'csv'), true)) {
    dvfFail(400, 'invalid_request', 'Unknown action');
}

$tbl = tlObjectWithDB::getDBTables(array(
    'testplans', 'testprojects', 'nodes_hierarchy', 'tcversions', 'builds',
    'platforms', 'testplan_platforms', 'testplan_tcversions', 'executions',
    'execution_bugs', 'users',
));

if ($action === 'projects') {
    $items = array();
    $pRs = $db->get_recordset("SELECT id FROM {$tbl['testprojects']} ORDER BY id");
    if (is_array($pRs)) {
        foreach ($pRs as $p) {
            $pid = intval($p['id']);
            if (!$user->hasRight($db, 'testplan_metrics', $pid, null, true)) { continue; }
            $name = testproject::getName($db, $pid);
            if (is_null($name) || trim((string)$name) === '') { continue; }
            $items[] = array('id' => $pid, 'name' => dvfDecode($name));
        }
    }
    dvfOut(array('status' => 'ok', 'code' => 200, 'projects' => $items));
}

$tplanId = dvfPositiveInt($_GET['tplan_id'] ?? null);
$assertedProjectId = dvfPositiveInt($_GET['tproject_id'] ?? null);
$rawBuild = trim((string)($_GET['build_id'] ?? '0'));
$rawPlatform = trim((string)($_GET['platform_id'] ?? '0'));
$verdictFilter = strtolower(trim((string)($_GET['verdict'] ?? 'all')));
$bugFilter = trim((string)($_GET['bug_id'] ?? ''));
if ($tplanId <= 0) { dvfFail(400, 'invalid_request', 'Missing or invalid tplan_id'); }
if (!preg_match('/^[0-9]+$/', $rawBuild) || !preg_match('/^[0-9]+$/', $rawPlatform)) {
    dvfFail(400, 'invalid_request', 'Invalid filter');
}
if (!in_array($verdictFilter, array('all', 'unverified', 'escaped', 'verified'), true)) {
    dvfFail(400, 'invalid_request', 'Invalid verdict filter');
}
if (strlen($bugFilter) > 64) { dvfFail(400, 'invalid_request', 'Invalid bug_id filter'); }
$buildId = intval($rawBuild);
$platformId = intval($rawPlatform);

$planRow = $db->get_recordset("SELECT id, testproject_id FROM {$tbl['testplans']} WHERE id = " .
    intval($tplanId));
if (!is_array($planRow) || count($planRow) === 0) { dvfFail(404, 'tplan_not_found', 'Test plan not found'); }
$owningProjectId = intval($planRow[0]['testproject_id']);
if ($owningProjectId <= 0) { dvfFail(404, 'tplan_not_found', 'Test plan has no owning project'); }
if (!$user->hasRight($db, 'testplan_metrics', $owningProjectId, $tplanId, true)) {
    tLog('BFF defectVerification: user ' . intval($userId) .
         ' has no testplan_metrics right on tproject ' . intval($owningProjectId) .
         ' (tplan ' . $tplanId . ')', 'AUDIT');
    dvfFail(403, 'no_right', 'testplan_metrics right required');
}
if ($assertedProjectId > 0 && $assertedProjectId !== $owningProjectId) {
    dvfFail(404, 'project_mismatch', 'Test plan belongs to another test project');
}
$planName = testplan::getName($db, $tplanId);
$projectName = testproject::getName($db, $owningProjectId);
if (is_null($planName) || trim((string)$planName) === '' ||
    is_null($projectName) || trim((string)$projectName) === '') {
    dvfFail(404, 'tplan_not_found', 'Test plan not found');
}

$builds = array();
$buildRs = $db->get_recordset("SELECT id, name FROM {$tbl['builds']} WHERE testproject_id = " .
    intval($owningProjectId) . " ORDER BY id");
if (is_array($buildRs)) {
    foreach ($buildRs as $b) { $builds[] = array('id' => intval($b['id']), 'name' => dvfDecode($b['name'])); }
}
$platforms = array();
$platformRs = $db->get_recordset("SELECT p.id, p.name FROM {$tbl['platforms']} p" .
    " JOIN {$tbl['testplan_platforms']} tpp ON tpp.platform_id = p.id" .
    " WHERE tpp.testplan_id = " . intval($tplanId) . " ORDER BY p.name");
if (is_array($platformRs)) {
    foreach ($platformRs as $p) { $platforms[] = array('id' => intval($p['id']), 'name' => dvfDecode($p['name'])); }
}
$knownBuild = ($buildId === 0);
foreach ($builds as $b) { if ($b['id'] === $buildId) { $knownBuild = true; break; } }
if (!$knownBuild) { dvfFail(400, 'invalid_request', 'build_id does not belong to this project'); }
$knownPlatform = ($platformId === 0);
foreach ($platforms as $p) { if ($p['id'] === $platformId) { $knownPlatform = true; break; } }
if (!$knownPlatform) { dvfFail(400, 'invalid_request', 'platform_id does not belong to this plan'); }

$buildNames = array();
foreach ($builds as $b) { $buildNames[$b['id']] = $b['name']; }
$platformNames = array();
foreach ($platforms as $p) { $platformNames[$p['id']] = $p['name']; }
$prefixRow = $db->get_recordset("SELECT prefix FROM {$tbl['testprojects']} WHERE id = " . intval($owningProjectId));
$prefix = (is_array($prefixRow) && count($prefixRow) > 0) ? (string)$prefixRow[0]['prefix'] : '';
$context = array(
    'tplan_id' => $tplanId, 'tplan_name' => dvfDecode((string)$planName),
    'tproject_id' => $owningProjectId, 'tproject_name' => dvfDecode((string)$projectName),
);
$filterEcho = array('build_id' => $buildId, 'platform_id' => $platformId,
                    'verdict' => $verdictFilter, 'bug_id' => $bugFilter);
if ($action === 'init') {
    dvfOut(array('status' => 'ok', 'code' => 200, 'context' => $context,
                 'filters' => array('builds' => $builds, 'platforms' => $platforms),
                 'filter' => $filterEcho));
}

$scopeWhere = " e.testplan_id = " . intval($tplanId) .
    " AND EXISTS (SELECT 1 FROM {$tbl['testplan_tcversions']} tpt" .
    "             WHERE tpt.testplan_id = " . intval($tplanId) .
    "               AND tpt.tcversion_id = e.tcversion_id" .
    "               AND (tpt.platform_id = 0 OR tpt.platform_id = e.platform_id))" .
    " AND (" . intval($platformId) . " = 0 OR e.platform_id = " . intval($platformId) . ")" .
    " AND (" . intval($buildId) . " = 0 OR e.build_id = " . intval($buildId) . ")";

$linkRs = $db->get_recordset(
    "SELECT eb.bug_id, eb.execution_id, eb.tcstep_id, e.tcversion_id, e.tcversion_number," .
    "       e.build_id, e.platform_id, e.tester_id, e.status AS link_status," .
    "       e.execution_ts AS link_ts, e.notes AS link_notes," .
    "       nh.id AS tcase_id, nh.name AS tcase_name, tv.version, tv.tc_external_id" .
    " FROM {$tbl['execution_bugs']} eb" .
    " JOIN {$tbl['executions']} e ON e.id = eb.execution_id" .
    " JOIN {$tbl['nodes_hierarchy']} nh_tv ON nh_tv.id = e.tcversion_id" .
    " JOIN {$tbl['nodes_hierarchy']} nh ON nh.id = nh_tv.parent_id" .
    " JOIN {$tbl['tcversions']} tv ON tv.id = e.tcversion_id" .
    " WHERE " . $scopeWhere .
    ($bugFilter !== '' ? " AND eb.bug_id = '" . $db->prepare_string($bugFilter) . "'" : "") .
    " ORDER BY eb.bug_id, e.execution_ts, e.id");
if (!is_array($linkRs)) { $linkRs = array(); }

$needTester = array();
foreach ($linkRs as $r) {
    $tid = intval($r['tester_id']);
    if ($tid > 0) { $needTester[$tid] = true; }
}
$testerNames = array();
if (count($needTester) > 0) {
    $uRs = $db->get_recordset("SELECT id, login, first, last FROM {$tbl['users']} WHERE id IN (" .
        implode(',', array_keys($needTester)) . ")");
    if (is_array($uRs)) {
        foreach ($uRs as $u) {
            $name = trim(dvfDecode($u['first']) . ' ' . dvfDecode($u['last']));
            $testerNames[intval($u['id'])] = $name !== '' ? $name : dvfDecode($u['login']);
        }
    }
}

$followupRs = $db->get_recordset(
    "SELECT e.id, e.tcversion_id, e.platform_id, e.build_id, e.status," .
    "       e.execution_ts, e.tester_id, e.notes" .
    " FROM {$tbl['executions']} e" .
    " WHERE " . $scopeWhere . " ORDER BY e.tcversion_id, e.platform_id, e.execution_ts, e.id");
if (!is_array($followupRs)) { $followupRs = array(); }
$followups = array();
foreach ($followupRs as $e) {
    $key = intval($e['tcversion_id']) . ':' . intval($e['platform_id']);
    if (!isset($followups[$key])) { $followups[$key] = array(); }
    $followups[$key][] = $e;
}

$bugs = array();
foreach ($linkRs as $r) {
    $bugId = (string)$r['bug_id'];
    if (!isset($bugs[$bugId])) {
        $bugs[$bugId] = array(
            'bug_id' => $bugId, 'links' => 0, 'passed_links' => 0, 'failed_links' => 0,
            'blocked_links' => 0, 'verified' => 0, 'unverified' => 0, 'escaped' => 0,
            'cases' => array(), 'case_ids' => array(), 'builds' => array(), 'platforms' => array(),
            'latest_ts' => '', 'latest_status' => '', 'evidence' => array(),
            'verdict' => 'no_data',
        );
    }
    $b = &$bugs[$bugId];
    $sv = dvfStatus($r['link_status']);
    $b['links']++;
    if ($sv === 'passed') { $b['passed_links']++; }
    if ($sv === 'failed') { $b['failed_links']++; }
    if ($sv === 'blocked') { $b['blocked_links']++; }
    $bid = intval($r['build_id']);
    $pid = intval($r['platform_id']);
    if (isset($buildNames[$bid])) { $b['builds'][$buildNames[$bid]] = $buildNames[$bid]; }
    if (isset($platformNames[$pid])) { $b['platforms'][$platformNames[$pid]] = $platformNames[$pid]; }
    $b['cases'][trim($prefix) . '-' . intval($r['tc_external_id'])] =
        dvfDecode($r['tcase_name']);
    $b['case_ids'][intval($r['tcase_id'])] = true;

    $firstPass = null;
    $key = intval($r['tcversion_id']) . ':' . $pid;
    $linkTs = (string)$r['link_ts'];
    if (isset($followups[$key])) {
        foreach ($followups[$key] as $f) {
            if ((string)$f['execution_ts'] < $linkTs ||
                ((string)$f['execution_ts'] === $linkTs && intval($f['id']) <= intval($r['execution_id']))) { continue; }
            if (dvfStatus($f['status']) === 'passed') { $firstPass = $f; break; }
        }
    }
    $fbid = $firstPass ? intval($firstPass['build_id']) : 0;
    $fpid = $firstPass ? intval($firstPass['platform_id']) : $pid;
    $b['evidence'][] = array(
        'execution_id' => intval($r['execution_id']),
        'bug_id' => $bugId,
        'status' => $sv,
        'verification' => $sv === 'passed' ? 'escaped' : ($firstPass ? 'verified' : 'unverified'),
        'ts' => dvfText($linkTs),
        'build' => isset($buildNames[$bid]) ? $buildNames[$bid] : '',
        'platform' => isset($platformNames[$pid]) ? $platformNames[$pid] : '',
        'tcase' => dvfDecode($r['tcase_name']),
        'external_id' => trim($prefix) . '-' . intval($r['tc_external_id']),
        'version' => intval($r['version']),
        'tester' => isset($testerNames[intval($r['tester_id'])]) ? $testerNames[intval($r['tester_id'])] : '',
        'notes' => dvfText($r['link_notes']),
        'followup_ts' => $firstPass ? dvfText($firstPass['execution_ts']) : '',
        'followup_build' => $firstPass && isset($buildNames[$fbid]) ? $buildNames[$fbid] : '',
        'followup_platform' => $firstPass && isset($platformNames[$fpid]) ? $platformNames[$fpid] : '',
        'followup_tester' => $firstPass && isset($testerNames[intval($firstPass['tester_id'])]) ?
            $testerNames[intval($firstPass['tester_id'])] : '',
    );
    if ($sv === 'passed') {
        $b['escaped']++;
    } elseif ($firstPass) {
        $b['verified']++;
    } else {
        $b['unverified']++;
    }
    if ($linkTs >= (string)$b['latest_ts']) {
        $b['latest_ts'] = $linkTs;
        $b['latest_status'] = $sv;
    }
    unset($b);
}

foreach ($bugs as &$b) {
    if ($b['escaped'] > 0) { $b['verdict'] = 'escaped'; }
    elseif ($b['unverified'] > 0) { $b['verdict'] = 'unverified'; }
    elseif ($b['verified'] > 0) { $b['verdict'] = 'verified'; }
    else { $b['verdict'] = 'no_data'; }
    $b['builds'] = array_values($b['builds']);
    $b['platforms'] = array_values($b['platforms']);
    $b['case_count'] = count($b['case_ids']);
    unset($b['case_ids']);
    $b['cases'] = array_values($b['cases']);
    $b['verification_rate'] = dvfPct($b['verified'], $b['links'] - $b['escaped']);
    unset($b['latest_ts']);
}
unset($b);

$rows = array_values($bugs);
if ($verdictFilter !== 'all') {
    $rows = array_values(array_filter($rows, function ($r) use ($verdictFilter) {
        return $r['verdict'] === $verdictFilter;
    }));
}
usort($rows, function ($a, $b) {
    $order = array('escaped' => 0, 'unverified' => 1, 'verified' => 2, 'no_data' => 3);
    if ($order[$a['verdict']] !== $order[$b['verdict']]) {
        return $order[$a['verdict']] - $order[$b['verdict']];
    }
    if ($a['unverified'] !== $b['unverified']) { return $b['unverified'] - $a['unverified']; }
    return strcmp($a['bug_id'], $b['bug_id']);
});

$all = array_values($bugs);
$kpi = array(
    'defects' => count($all), 'links' => 0, 'escaped' => 0, 'unverified' => 0,
    'verified' => 0, 'verification_rate' => 0.0, 'affected_cases' => 0,
    'affected_builds' => 0, 'builds' => count($builds), 'platforms' => count($platforms),
    'verdict' => 'no_data',
);
$cases = array(); $usedBuilds = array();
foreach ($all as $b) {
    $kpi['links'] += $b['links'];
    $kpi['escaped'] += $b['escaped'];
    $kpi['unverified'] += $b['unverified'];
    $kpi['verified'] += $b['verified'];
    foreach ($b['evidence'] as $e) { $cases[] = $e['external_id']; }
    foreach ($b['builds'] as $n) { $usedBuilds[$n] = true; }
}
$kpi['affected_cases'] = count(array_unique($cases));
$kpi['affected_builds'] = count($usedBuilds);
$kpi['verification_rate'] = dvfPct($kpi['verified'], $kpi['links'] - $kpi['escaped']);
if (count($all) === 0) { $kpi['verdict'] = 'no_data'; }
elseif ($kpi['escaped'] > 0) { $kpi['verdict'] = 'at_risk'; }
elseif ($kpi['unverified'] > 0) { $kpi['verdict'] = 'watch'; }
else { $kpi['verdict'] = 'clear'; }

if ($action === 'detail') {
    $bugId = trim((string)($_GET['bug_id'] ?? ''));
    if ($bugId === '' || strlen($bugId) > 64) { dvfFail(400, 'invalid_request', 'Missing bug_id'); }
    if (!isset($bugs[$bugId])) { dvfFail(404, 'defect_not_found', 'Defect not found in this plan'); }
    dvfOut(array('status' => 'ok', 'code' => 200, 'context' => $context,
                 'filter' => $filterEcho, 'defect' => $bugs[$bugId]));
}

if ($action === 'csv') {
    $out = fopen('php://temp', 'r+');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, array('bug_id', 'verdict', 'links', 'passed_links', 'verified',
        'unverified', 'escaped', 'verification_rate', 'affected_cases', 'builds',
        'platforms', 'latest_status', 'cases'), ';');
    foreach ($rows as $r) {
        fputcsv($out, array(
            dvfCsvCell($r['bug_id']), $r['verdict'], $r['links'], $r['passed_links'],
            $r['verified'], $r['unverified'], $r['escaped'], $r['verification_rate'],
            $r['case_count'], dvfCsvCell(implode(', ', $r['builds'])),
            dvfCsvCell(implode(', ', $r['platforms'])), $r['latest_status'],
            dvfCsvCell(implode(', ', $r['cases'])),
        ), ';');
    }
    rewind($out);
    $csv = stream_get_contents($out);
    fclose($out);
    dvfOut(array('status' => 'ok', 'code' => 200, 'csv' => $csv, 'rows' => count($rows)));
}

dvfOut(array('status' => 'ok', 'code' => 200, 'context' => $context,
             'filters' => array('builds' => $builds, 'platforms' => $platforms),
             'filter' => $filterEcho, 'kpi' => $kpi, 'rows' => $rows));
