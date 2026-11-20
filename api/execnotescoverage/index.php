<?php
/**
 * Test Plan Execution Notes Coverage BFF (Refs #2014).
 *
 * Net-new read-only report: 1.9.20 stores execution notes but has no plan-wide
 * view of which assigned test-case versions carry explanatory evidence.  The
 * report answers that question without exposing raw HTML from the notes.
 */
require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();
require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function encOut($payload, $code = null)
{
    if ($code !== null) {
        http_response_code($code);
    }
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function encFail($httpCode, $code, $message = '')
{
    encOut(array(
        'status' => 'error',
        'code' => $code,
        'message' => $message !== '' ? $message : $code,
    ), $httpCode);
}

function encPosInt($raw)
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

function encDecode($value)
{
    return html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function encPlain($value, $limit = 1200, $collapse = true)
{
    $s = strip_tags(encDecode($value));
    $s = $collapse ? trim(preg_replace('/\s+/', ' ', $s)) : trim($s);
    if (function_exists('mb_substr')) {
        return mb_substr($s, 0, $limit, 'UTF-8');
    }
    return substr($s, 0, $limit);
}

function encPct($part, $total)
{
    return $total > 0 ? round(100.0 * $part / $total, 1) : 0.0;
}

function encStatus($letter)
{
    switch (strtolower(trim((string)$letter))) {
        case 'p': return 'passed';
        case 'f': return 'failed';
        case 'b': return 'blocked';
    }
    return 'not_run';
}

function encCsvCell($value)
{
    $s = encPlain($value, 4000);
    if ($s !== '' && preg_match('/^\s*[=+\-@]/', $s)) {
        $s = "'" . $s;
    }
    return str_replace(array("\r", "\n"), array(' ', ' '), $s);
}

function encPath($db, $tbl, $nodeId, &$cache)
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
            $parts[] = encDecode($r['name']);
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
    encFail(405, 'method_not_allowed');
}

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    encFail(401, 'not_authenticated', 'Not authenticated');
}
bffEnforceSession($db);

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    encFail(401, 'not_authenticated', 'User not found');
}

$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if (!in_array($action, array('init', 'report', 'detail', 'csv'), true)) {
    encFail(400, 'invalid_request', 'Unknown action');
}

$tplanId = encPosInt($_GET['tplan_id'] ?? null);
$assertedProjectId = encPosInt($_GET['tproject_id'] ?? null);
$rawBuild = trim((string)($_GET['build_id'] ?? '0'));
$rawPlatform = trim((string)($_GET['platform_id'] ?? '0'));
$status = strtolower(trim((string)($_GET['status'] ?? 'all')));
$scope = strtolower(trim((string)($_GET['scope'] ?? 'all')));
$tcversionId = encPosInt($_GET['tcversion_id'] ?? null);
$linkKey = trim((string)($_GET['link_key'] ?? ''));
if ($linkKey !== '' && !preg_match('/^[0-9]+\/[0-9]+$/', $linkKey)) {
    encFail(400, 'invalid_request', 'Invalid link_key');
}
if ($linkKey !== '' && $action !== 'detail') {
    encFail(400, 'invalid_request', 'link_key is only valid for the detail action');
}

if (!in_array($status, array('all', 'p', 'f', 'b'), true) ||
    !in_array($scope, array('all', 'documented', 'partial', 'undocumented', 'latest_missing'), true)) {
    encFail(400, 'invalid_request', 'Invalid filter');
}
if ($tplanId <= 0) {
    encFail(400, 'invalid_request', 'Missing or invalid tplan_id');
}
if (($rawBuild !== '' && !preg_match('/^[0-9]+$/', $rawBuild)) ||
    ($rawPlatform !== '' && !preg_match('/^[0-9]+$/', $rawPlatform))) {
    encFail(400, 'invalid_request', 'Invalid filter');
}
if ($tcversionId > 0 && $action !== 'detail') {
    encFail(400, 'invalid_request', 'tcversion_id is only valid for the detail action');
}
if ($action === 'detail' && $tcversionId <= 0 && $linkKey === '') {
    encFail(400, 'invalid_request', 'Missing tcversion_id or link_key');
}
$buildId = intval($rawBuild);
$platformId = intval($rawPlatform);
if ($action === 'detail') {
    $buildId = 0;
    $platformId = 0;
}

$tbl = tlObjectWithDB::getDBTables(array(
    'testplans', 'testprojects', 'nodes_hierarchy', 'tcversions',
    'builds', 'platforms', 'testplan_platforms', 'testplan_tcversions', 'executions',
));

$planRow = $db->get_recordset(
    "SELECT id, testproject_id FROM {$tbl['testplans']} WHERE id = " . intval($tplanId));
if (!is_array($planRow) || count($planRow) === 0) {
    encFail(404, 'tplan_not_found', 'Test plan not found');
}
$owningProjectId = intval($planRow[0]['testproject_id']);
if ($owningProjectId <= 0) {
    encFail(404, 'tplan_not_found', 'Test plan has no owning test project');
}
if (!$user->hasRight($db, 'testplan_metrics', $owningProjectId, $tplanId, true)) {
    tLog('BFF execnotescoverage: user ' . intval($userId) .
         ' has no testplan_metrics right on tproject ' . intval($owningProjectId) .
         ' (tplan ' . $tplanId . ')', 'AUDIT');
    encFail(403, 'no_right', 'testplan_metrics right required');
}
if ($assertedProjectId > 0 && $assertedProjectId !== $owningProjectId) {
    encFail(404, 'project_mismatch', 'Test plan belongs to another test project');
}

$planName = testplan::getName($db, $tplanId);
$projectName = testproject::getName($db, $owningProjectId);
if (is_null($planName) || trim((string)$planName) === '' ||
    is_null($projectName) || trim((string)$projectName) === '') {
    encFail(404, 'tplan_not_found', 'Test plan not found');
}

$builds = array();
$buildRs = $db->get_recordset(
    "SELECT id, name FROM {$tbl['builds']} WHERE testproject_id = " . intval($owningProjectId) .
    " AND active = 1 ORDER BY id");
if (is_array($buildRs)) {
    foreach ($buildRs as $b) {
        $builds[] = array('id' => intval($b['id']), 'name' => encDecode($b['name']));
    }
}

$platforms = array();
$platformRs = $db->get_recordset(
    "SELECT p.id, p.name FROM {$tbl['platforms']} p" .
    " JOIN {$tbl['testplan_platforms']} tpp ON tpp.platform_id = p.id" .
    " WHERE tpp.testplan_id = " . intval($tplanId) . " ORDER BY p.name");
if (is_array($platformRs)) {
    foreach ($platformRs as $p) {
        $platforms[] = array('id' => intval($p['id']), 'name' => encDecode($p['name']));
    }
}

$knownBuild = ($buildId === 0);
foreach ($builds as $b) { if ($b['id'] === $buildId) { $knownBuild = true; break; } }
if (!$knownBuild) {
    encFail(400, 'invalid_request', 'build_id does not belong to this test project');
}
$knownPlatform = ($platformId === 0);
foreach ($platforms as $p) { if ($p['id'] === $platformId) { $knownPlatform = true; break; } }
if (!$knownPlatform) {
    encFail(400, 'invalid_request', 'platform_id does not belong to this test plan');
}

$context = array(
    'tplan_id' => $tplanId,
    'tplan_name' => encDecode((string)$planName),
    'tproject_id' => $owningProjectId,
    'tproject_name' => encDecode((string)$projectName),
);
$filterEcho = array(
    'build_id' => $buildId, 'platform_id' => $platformId,
    'status' => $status, 'scope' => $scope,
);
$filtersPayload = array('builds' => $builds, 'platforms' => $platforms);

if ($action === 'init') {
    encOut(array(
        'status' => 'ok',
        'code' => 200,
        'context' => $context,
        'filters' => $filtersPayload,
        'filter' => $filterEcho,
    ));
}

$versionSql =
    "SELECT tptv.tcversion_id, tptv.platform_id AS assigned_platform_id," .
    "       tv.version, tv.tc_external_id, nh_tc.id AS tcase_id," .
    "       nh_tc.name AS tcase_name, tp.prefix" .
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
        'tcase_name' => encDecode($r['tcase_name']),
        'external_id' => trim((string)$r['prefix']) . '-' . intval($r['tc_external_id']),
        'version' => intval($r['version']),
        'assigned_platform_id' => $assignedPid,
        'executions' => 0,
        'with_notes' => 0,
        'without_notes' => 0,
        'note_state' => 'no_exec',
        'latest_with_note' => false,
        'status' => 'not_run',
        'status_letter' => '',
        'build_id' => 0,
        'build_name' => '',
        'platform_id' => $assignedPid,
        'platform_name' => $assignedPid > 0 && isset($platformNames[$assignedPid]) ?
            $platformNames[$assignedPid] : '',
        'execution_ts' => '',
        'tester_id' => 0,
        'latest_note' => '',
        'path' => '',
        'note_rows' => array(),
    );
}

$execSql =
    "SELECT id, tcversion_id, status, build_id, platform_id, execution_ts," .
    "       tester_id, notes FROM {$tbl['executions']}" .
    " WHERE testplan_id = " . intval($tplanId);
if ($buildId > 0) {
    $execSql .= " AND build_id = " . intval($buildId);
}
$execRs = $db->get_recordset($execSql . " ORDER BY execution_ts ASC, id ASC");
if (is_array($execRs)) {
    foreach ($execRs as $e) {
        $vid = intval($e['tcversion_id']);
        if (!isset($linkKeysByVersion[$vid])) { continue; }
        $pid = intval($e['platform_id']);
        if ($platformId > 0 && $pid !== 0 && $pid !== $platformId) { continue; }
        $note = encPlain($e['notes'] ?? '', 4000, false);
        foreach ($linkKeysByVersion[$vid] as $key) {
            $assignedPid = $versions[$key]['assigned_platform_id'];
            if ($pid > 0 && $assignedPid !== 0 && $assignedPid !== $pid) { continue; }
            $versions[$key]['executions']++;
            if ($note !== '') {
                $versions[$key]['with_notes']++;
            } else {
                $versions[$key]['without_notes']++;
            }
            $versions[$key]['note_rows'][] = array(
                'execution_id' => intval($e['id']),
                'execution_ts' => (string)($e['execution_ts'] ?? ''),
                'status' => encStatus($e['status']),
                'build_name' => isset($buildNames[intval($e['build_id'])]) ?
                    $buildNames[intval($e['build_id'])] : '',
                'platform_id' => $pid,
                'platform_name' => $pid > 0 && isset($platformNames[$pid]) ?
                    $platformNames[$pid] : '',
                'tester_id' => intval($e['tester_id']),
                'notes' => $note,
            );
            $ts = (string)($e['execution_ts'] ?? '');
            if ($ts >= $versions[$key]['execution_ts']) {
                $versions[$key]['status'] = encStatus($e['status']);
                $versions[$key]['status_letter'] = strtolower(trim((string)$e['status']));
                $versions[$key]['build_id'] = intval($e['build_id']);
                $versions[$key]['build_name'] = isset($buildNames[intval($e['build_id'])]) ?
                    $buildNames[intval($e['build_id'])] : '';
                $versions[$key]['platform_id'] = $pid;
                $versions[$key]['platform_name'] = $pid > 0 && isset($platformNames[$pid]) ?
                    $platformNames[$pid] : $versions[$key]['platform_name'];
                $versions[$key]['execution_ts'] = $ts;
                $versions[$key]['tester_id'] = intval($e['tester_id']);
                $versions[$key]['latest_note'] = $note;
                $versions[$key]['latest_with_note'] = ($note !== '');
            }
        }
    }
}

$paths = array();
foreach ($versions as &$v) {
    if ($v['executions'] === 0) {
        $v['note_state'] = 'no_exec';
    } elseif ($v['with_notes'] === 0) {
        $v['note_state'] = 'undocumented';
    } elseif ($v['without_notes'] === 0) {
        $v['note_state'] = 'documented';
    } else {
        $v['note_state'] = 'partial';
    }
    $v['path'] = encPath($db, $tbl, $v['tcase_id'], $paths);
    $paths[$v['tcase_id']] = $v['path'];
    $v['note_coverage'] = encPct($v['with_notes'], $v['executions']);
}
unset($v);

if ($action === 'detail') {
    $detailKey = null;
    if ($linkKey !== '' && isset($versions[$linkKey])) {
        $detailKey = $linkKey;
    } elseif ($linkKey === '') {
        foreach ($versions as $key => $candidate) {
            if (intval($candidate['tcversion_id']) === $tcversionId) {
                $detailKey = $key;
                break;
            }
        }
    }
    if ($detailKey === null) {
        encFail(404, 'tcversion_not_found', 'Test case version is not assigned to this plan');
    }
    $item = $versions[$detailKey];
    $notes = $item['note_rows'];
    unset($item['note_rows']);
    encOut(array(
        'status' => 'ok',
        'code' => 200,
        'context' => $context,
        'item' => $item,
        'notes' => $notes,
    ));
}

$rows = array();
foreach ($versions as $v) {
    if ($status !== 'all' && $v['status'] !== encStatus($status)) { continue; }
    if ($scope === 'documented' && $v['note_state'] !== 'documented') { continue; }
    if ($scope === 'partial' && $v['note_state'] !== 'partial') { continue; }
    if ($scope === 'undocumented' && $v['note_state'] !== 'undocumented') { continue; }
    if ($scope === 'latest_missing' &&
        ($v['executions'] === 0 || $v['latest_with_note'])) { continue; }
    unset($v['note_rows']);
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
    'executed_versions' => 0,
    'executions' => 0,
    'with_notes' => 0,
    'without_notes' => 0,
    'documented' => 0,
    'partial' => 0,
    'undocumented' => 0,
    'no_exec' => 0,
    'latest_with_notes' => 0,
    'latest_missing' => 0,
    'passed' => 0,
    'failed' => 0,
    'blocked' => 0,
);
foreach ($rows as $r) {
    if ($r['executions'] > 0) { $summary['executed_versions']++; }
    $summary['executions'] += $r['executions'];
    $summary['with_notes'] += $r['with_notes'];
    $summary['without_notes'] += $r['without_notes'];
    if (isset($summary[$r['note_state']])) { $summary[$r['note_state']]++; }
    if ($r['executions'] > 0) {
        if ($r['latest_with_note']) { $summary['latest_with_notes']++; }
        else { $summary['latest_missing']++; }
    }
    if ($r['status'] === 'passed') { $summary['passed']++; }
    if ($r['status'] === 'failed') { $summary['failed']++; }
    if ($r['status'] === 'blocked') { $summary['blocked']++; }
}
$summary['note_coverage'] = encPct($summary['with_notes'], $summary['executions']);

if ($summary['shown'] === 0 || $summary['executions'] === 0) {
    $verdict = 'no_data';
} elseif ($summary['with_notes'] === 0) {
    $verdict = 'undocumented';
} elseif ($summary['note_coverage'] < 50.0) {
    $verdict = 'at_risk';
} elseif ($summary['note_coverage'] < 100.0) {
    $verdict = 'watch';
} else {
    $verdict = 'documented';
}

if ($action === 'csv') {
    if (ob_get_level() > 0) { ob_clean(); }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="exec-notes-coverage.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, array('test_case', 'external_id', 'version', 'suite_path', 'executions',
        'with_notes', 'without_notes', 'note_coverage_pct', 'evidence_state',
        'latest_status', 'latest_build', 'latest_platform', 'latest_notes', 'executed_on'), ';');
    foreach ($rows as $r) {
        fputcsv($out, array(
            encCsvCell($r['tcase_name']), encCsvCell($r['external_id']),
            $r['version'], encCsvCell($r['path']), $r['executions'],
            $r['with_notes'], $r['without_notes'],
            encPct($r['with_notes'], $r['executions']), encCsvCell($r['note_state']),
            encCsvCell($r['status']), encCsvCell($r['build_name']),
            encCsvCell($r['platform_name']), encCsvCell($r['latest_note'] ?? ''),
            encCsvCell($r['execution_ts']),
        ), ';');
    }
    fclose($out);
    exit;
}

encOut(array(
    'status' => 'ok',
    'code' => 200,
    'context' => $context,
    'filter' => $filterEcho,
    'filters' => $filtersPayload,
    'summary' => $summary,
    'rows' => $rows,
    'verdict' => $verdict,
));
