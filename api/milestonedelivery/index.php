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

function mdOut($payload, $code = null)
{
    if ($code !== null) {
        http_response_code($code);
    }
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function mdFail($httpCode, $code, $message = '')
{
    mdOut(array('status' => 'error', 'code' => $code,
                'message' => $message !== '' ? $message : $code), $httpCode);
}

function mdInt($raw)
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

function mdText($value)
{
    return html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function mdPct($part, $total)
{
    return $total > 0 ? round(100.0 * $part / $total, 1) : 0.0;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    mdFail(405, 'method_not_allowed');
}

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    mdFail(401, 'not_authenticated');
}
bffEnforceSession($db);
$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    mdFail(401, 'not_authenticated');
}

$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if ($action !== 'init' && $action !== 'report') {
    mdFail(400, 'invalid_request', 'Unknown action');
}
$tplanId = mdInt($_GET['tplan_id'] ?? null);
$assertedProjectId = mdInt($_GET['tproject_id'] ?? null);
$buildId = mdInt($_GET['build_id'] ?? null);
$platformId = mdInt($_GET['platform_id'] ?? null);
if ($tplanId <= 0) {
    mdFail(400, 'invalid_request', 'Missing or invalid tplan_id');
}

$tbl = tlObjectWithDB::getDBTables(array('testplans', 'builds', 'platforms',
    'testplan_tcversions', 'tcversions', 'executions', 'milestones'));
$planRows = $db->get_recordset("SELECT id, testproject_id FROM {$tbl['testplans']} WHERE id = " . intval($tplanId));
if (!is_array($planRows) || count($planRows) === 0) {
    mdFail(404, 'tplan_not_found');
}
$owningProjectId = intval($planRows[0]['testproject_id']);
if ($owningProjectId <= 0) {
    mdFail(404, 'tplan_not_found');
}
if (!$user->hasRight($db, 'testplan_metrics', $owningProjectId, $tplanId, true)) {
    tLog('BFF milestonedelivery: user ' . intval($userId) .
         ' has no testplan_metrics on tproject ' . $owningProjectId .
         ' (tplan ' . $tplanId . ')', 'AUDIT');
    mdFail(403, 'no_right', 'testplan_metrics right required');
}
if ($assertedProjectId > 0 && $assertedProjectId !== $owningProjectId) {
    mdFail(404, 'project_mismatch');
}

$planName = testplan::getName($db, $tplanId);
$projectName = testproject::getName($db, $owningProjectId);
if (is_null($planName) || trim((string)$planName) === '') {
    mdFail(404, 'tplan_not_found');
}

$builds = array();
$rs = $db->get_recordset("SELECT id, name FROM {$tbl['builds']} WHERE testproject_id = " .
    intval($owningProjectId) . " AND active = 1 ORDER BY id");
if (is_array($rs)) {
    foreach ($rs as $r) {
        $builds[] = array('id' => intval($r['id']), 'name' => mdText($r['name']));
    }
}
$platforms = array();
$rs = $db->get_recordset("SELECT id, name FROM {$tbl['platforms']} WHERE testproject_id = " .
    intval($owningProjectId) . " ORDER BY name");
if (is_array($rs)) {
    foreach ($rs as $r) {
        $platforms[] = array('id' => intval($r['id']), 'name' => mdText($r['name']));
    }
}
if ($buildId > 0) {
    $found = false;
    foreach ($builds as $b) {
        if ($b['id'] === $buildId) { $found = true; break; }
    }
    if (!$found) {
        mdFail(400, 'invalid_request', 'build_id does not belong to this test project');
    }
}
if ($platformId > 0) {
    $found = false;
    foreach ($platforms as $p) {
        if ($p['id'] === $platformId) { $found = true; break; }
    }
    if (!$found) {
        mdFail(400, 'invalid_request', 'platform_id does not belong to this test project');
    }
}

$context = array(
    'tplan_id' => $tplanId,
    'tplan_name' => mdText((string)$planName),
    'tproject_id' => $owningProjectId,
    'tproject_name' => mdText((string)$projectName),
);
$filter = array('build_id' => $buildId, 'platform_id' => $platformId);
if ($action === 'init') {
    mdOut(array('status' => 'ok', 'code' => 200, 'context' => $context,
                'filters' => array('builds' => $builds, 'platforms' => $platforms),
                'filter' => $filter));
}

$nowRows = $db->get_recordset("SELECT DATE(NOW()) AS today");
$today = (is_array($nowRows) && count($nowRows) > 0)
    ? (string)$nowRows[0]['today'] : date('Y-m-d');

$linkSql = "SELECT tpt.tcversion_id, tpt.platform_id, tv.importance " .
    "FROM {$tbl['testplan_tcversions']} tpt " .
    "JOIN {$tbl['tcversions']} tv ON tv.id = tpt.tcversion_id " .
    "WHERE tpt.testplan_id = " . intval($tplanId);
if ($platformId > 0) {
    $linkSql .= " AND (tpt.platform_id = " . intval($platformId) . " OR tpt.platform_id = 0)";
}
$linkRows = $db->get_recordset($linkSql);
$links = array();
$zeroLinks = array();
if (is_array($linkRows)) {
    foreach ($linkRows as $r) {
        $tcv = intval($r['tcversion_id']);
        $pl = intval($r['platform_id']);
        $imp = intval($r['importance']);
        if ($imp < 1 || $imp > 3) { $imp = 2; }
        $key = $tcv . '/' . $pl;
        $links[$key] = array('tcversion_id' => $tcv, 'platform_id' => $pl, 'importance' => $imp);
        if ($pl === 0) { $zeroLinks[$tcv] = true; }
    }
}

$execSql = "SELECT tcversion_id, platform_id, status, DATE(execution_ts) AS day " .
    "FROM {$tbl['executions']} WHERE testplan_id = " . intval($tplanId) .
    " AND execution_ts IS NOT NULL AND LOWER(TRIM(status)) <> 'n'";
if ($buildId > 0) {
    $execSql .= " AND build_id = " . intval($buildId);
}
if ($platformId > 0) {
    $execSql .= " AND (platform_id = " . intval($platformId) . " OR platform_id = 0)";
}
$execSql .= " ORDER BY execution_ts ASC";
$execRows = $db->get_recordset($execSql);
$executions = array();
if (is_array($execRows)) {
    foreach ($execRows as $e) {
        $executions[] = array('tcversion_id' => intval($e['tcversion_id']),
            'platform_id' => intval($e['platform_id']),
            'status' => strtolower(trim((string)$e['status'])),
            'day' => (string)$e['day']);
    }
}

$mRows = $db->get_recordset("SELECT id, name, start_date, target_date, a, b, c " .
    "FROM {$tbl['milestones']} WHERE testplan_id = " . intval($tplanId) .
    " ORDER BY target_date, id");
$milestones = is_array($mRows) ? $mRows : array();

$rows = array();
foreach ($milestones as $m) {
    $id = intval($m['id']);
    $name = mdText($m['name']);
    $start = (string)$m['start_date'];
    $target = (string)$m['target_date'];
    if ($start === '0000-00-00') { $start = ''; }
    $expected = array(1 => intval($m['c']), 2 => intval($m['b']), 3 => intval($m['a']));
    $assigned = array(1 => 0, 2 => 0, 3 => 0);
    foreach ($links as $link) {
        $assigned[$link['importance']]++;
    }
    $execByPriority = array(1 => 0, 2 => 0, 3 => 0);
    $statusByPriority = array(1 => array(), 2 => array(), 3 => array());
    $doneKeys = array();
    foreach ($executions as $e) {
        if ($e['day'] > $target || ($start !== '' && $e['day'] < $start)) {
            continue;
        }
        $candidates = array($e['tcversion_id'] . '/' . $e['platform_id']);
        if (isset($zeroLinks[$e['tcversion_id']])) {
            $candidates[] = $e['tcversion_id'] . '/0';
        }
        foreach ($candidates as $key) {
            if (!isset($links[$key]) || isset($doneKeys[$key])) { continue; }
            $doneKeys[$key] = true;
            $imp = $links[$key]['importance'];
            $execByPriority[$imp]++;
            $st = $e['status'];
            if (!isset($statusByPriority[$imp][$st])) { $statusByPriority[$imp][$st] = 0; }
            $statusByPriority[$imp][$st]++;
        }
    }
    $executed = array_sum($execByPriority);
    $totalAssigned = array_sum($assigned);
    $perPriority = array();
    foreach (array(3, 2, 1) as $imp) {
        $perPriority[] = array('importance' => $imp,
            'expected_pct' => $expected[$imp],
            'assigned' => $assigned[$imp],
            'executed' => $execByPriority[$imp],
            'coverage_pct' => mdPct($execByPriority[$imp], $assigned[$imp]));
    }
    $weighted = 0.0;
    foreach ($expected as $imp => $pct) {
        $weighted += mdPct($execByPriority[$imp], $assigned[$imp]) * intval($pct) / 100.0;
    }
    $statusCounts = array('p' => 0, 'f' => 0, 'b' => 0, 'n' => 0);
    foreach ($statusByPriority as $counts) {
        foreach ($counts as $st => $cnt) {
            if (!isset($statusCounts[$st])) { $statusCounts[$st] = 0; }
            $statusCounts[$st] += $cnt;
        }
    }
    $statusCounts['n'] = max(0, $totalAssigned - $executed);
    $passRate = mdPct($statusCounts['p'], $executed);
    $daysLeft = (int)((strtotime($target) - strtotime($today)) / 86400);
    if ($totalAssigned === 0) { $verdict = 'no_data'; }
    elseif ($executed === $totalAssigned) { $verdict = 'complete'; }
    elseif ($daysLeft < 0) { $verdict = 'overdue'; }
    elseif ($daysLeft <= 7) { $verdict = 'at_risk'; }
    else { $verdict = 'on_track'; }
    $rows[] = array('id' => $id, 'name' => $name, 'start_date' => $start,
        'target_date' => $target, 'days_left' => $daysLeft,
        'expected' => $expected, 'assigned' => $assigned,
        'executed' => $execByPriority, 'status' => $statusByPriority,
        'per_priority' => $perPriority,
        'assigned_total' => $totalAssigned, 'executed_total' => $executed,
        'coverage_pct' => mdPct($executed, $totalAssigned),
        'completion_pct' => round(min(100.0, $weighted), 1),
        'status_counts' => $statusCounts, 'pass_rate' => $passRate,
        'verdict' => $verdict);
}

$totalAssigned = count($links);
$totalExecuted = 0;
$statusTotals = array();
foreach ($rows as $r) {
    $totalExecuted = max($totalExecuted, $r['executed_total']);
    foreach ($r['status'] as $imp => $counts) {
        foreach ($counts as $st => $cnt) {
            if (!isset($statusTotals[$st])) { $statusTotals[$st] = 0; }
            $statusTotals[$st] += $cnt;
        }
    }
}
$verdictCounts = array('no_data' => 0, 'complete' => 0, 'overdue' => 0,
    'at_risk' => 0, 'on_track' => 0);
foreach ($rows as $r) {
    $verdictCounts[$r['verdict']]++;
}
$planVerdict = 'no_data';
foreach (array('overdue', 'at_risk', 'on_track', 'complete') as $v) {
    if ($verdictCounts[$v] > 0) { $planVerdict = $v; break; }
}
if (count($rows) === 0) { $planVerdict = 'no_milestones'; }

ksort($statusTotals);
$statusRows = array();
foreach ($statusTotals as $st => $cnt) {
    $statusRows[] = array('status' => $st, 'count' => $cnt);
}

mdOut(array('status' => 'ok', 'code' => 200, 'context' => $context,
    'filters' => array('builds' => $builds, 'platforms' => $platforms),
    'filter' => $filter, 'today' => $today,
    'kpi' => array('milestones' => count($rows), 'assigned' => $totalAssigned,
        'executed' => $totalExecuted, 'not_run' => max(0, $totalAssigned - $totalExecuted),
        'coverage_pct' => mdPct($totalExecuted, $totalAssigned),
        'pass_rate' => mdPct(isset($statusTotals['p']) ? $statusTotals['p'] : 0, $totalExecuted),
        'complete' => $verdictCounts['complete'], 'overdue' => $verdictCounts['overdue'],
        'at_risk' => $verdictCounts['at_risk'], 'on_track' => $verdictCounts['on_track']),
    'verdict' => $planVerdict, 'status_totals' => $statusRows, 'rows' => $rows));
