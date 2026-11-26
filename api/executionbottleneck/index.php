<?php
require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function ebnOut($payload, $code = null)
{
    if ($code !== null) {
        http_response_code($code);
    }
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function ebnFail($httpCode, $code, $message = '')
{
    ebnOut(array(
        'status' => 'error',
        'code' => $code,
        'message' => $message !== '' ? $message : $code,
    ), $httpCode);
}

function ebnPositiveInt($raw)
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

function ebnNonNegativeInt($raw, $default = 0)
{
    if ($raw === null || is_array($raw)) {
        return $default;
    }
    $raw = trim((string)$raw);
    if ($raw === '') {
        return $default;
    }
    if (!preg_match('/^[0-9]+$/', $raw)) {
        return -1;
    }
    return intval($raw);
}

function ebnDecode($value)
{
    return html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function ebnPct($part, $total)
{
    return ($total > 0) ? round(100.0 * $part / $total, 1) : 0.0;
}

function ebnStatus($letter)
{
    switch (strtolower(trim((string)$letter))) {
        case 'p': return 'passed';
        case 'f': return 'failed';
        case 'b': return 'blocked';
    }
    return 'not_run';
}

function ebnDuration($raw)
{
    if ($raw === null || $raw === '') {
        return null;
    }
    $value = round((float)$raw * 60, 2);
    return ($value > 0 && $value <= 359999) ? $value : null;
}

function ebnUserName($row)
{
    if (!is_array($row)) {
        return '';
    }
    $full = trim((string)($row['first'] ?? '') . ' ' . (string)($row['last'] ?? ''));
    return ebnDecode($full !== '' ? $full : ($row['login'] ?? ''));
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    ebnFail(405, 'method_not_allowed');
}

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    ebnFail(401, 'not_authenticated');
}
bffEnforceSession($db);

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    ebnFail(401, 'not_authenticated');
}

$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if (!in_array($action, array('init', 'report', 'detail'), true)) {
    ebnFail(400, 'invalid_request');
}

$tplanId = ebnPositiveInt($_GET['tplan_id'] ?? null);
$assertedProjectId = ebnPositiveInt($_GET['tproject_id'] ?? null);
$buildId = ebnNonNegativeInt($_GET['build_id'] ?? '0');
$platformId = ebnNonNegativeInt($_GET['platform_id'] ?? '0');
$testerId = ebnNonNegativeInt($_GET['tester_id'] ?? '0');
$threshold = ebnNonNegativeInt($_GET['threshold'] ?? '60', 60);
$scope = strtolower(trim((string)($_GET['scope'] ?? 'all')));
$detailTcversionId = ebnPositiveInt($_GET['tcversion_id'] ?? null);
$detailPlatformId = ebnNonNegativeInt($_GET['detail_platform_id'] ?? '0');

if ($buildId < 0 || $platformId < 0 || $testerId < 0 || $threshold < 0) {
    ebnFail(400, 'invalid_request');
}
if ($threshold === 0 || $threshold > 36000) {
    ebnFail(400, 'invalid_request');
}
if (!in_array($scope, array('all', 'slow', 'rerun', 'never'), true)) {
    ebnFail(400, 'invalid_request');
}
if ($action === 'detail') {
    if ($detailTcversionId <= 0 || $detailPlatformId < 0) {
        ebnFail(400, 'invalid_request');
    }
} elseif ($tplanId <= 0) {
    ebnFail(400, 'invalid_request');
}

$tbl = tlObjectWithDB::getDBTables(array(
    'testplans', 'nodes_hierarchy', 'testplan_tcversions', 'tcversions',
    'builds', 'platforms', 'testplan_platforms', 'executions', 'users',
));

if ($tplanId <= 0) {
    ebnFail(400, 'invalid_request');
}

$planRs = $db->get_recordset(
    "SELECT id, testproject_id FROM {$tbl['testplans']} WHERE id = " . intval($tplanId));
if (!is_array($planRs) || count($planRs) === 0) {
    ebnFail(404, 'tplan_not_found');
}
$owningProjectId = intval($planRs[0]['testproject_id']);
if ($owningProjectId <= 0) {
    ebnFail(404, 'tplan_not_found');
}
if (!$user->hasRight($db, 'testplan_metrics', $owningProjectId, $tplanId, true)) {
    tLog('BFF executionbottleneck: user ' . intval($userId) .
         ' has no testplan_metrics right on tproject ' . intval($owningProjectId) .
         ' (tplan ' . $tplanId . ')', 'AUDIT');
    ebnFail(403, 'no_right');
}
if ($assertedProjectId > 0 && $assertedProjectId !== $owningProjectId) {
    ebnFail(404, 'project_mismatch');
}

$planName = testplan::getName($db, $tplanId);
$projectName = testproject::getName($db, $owningProjectId);
if (is_null($planName) || trim((string)$planName) === '' ||
    is_null($projectName) || trim((string)$projectName) === '') {
    ebnFail(404, 'tplan_not_found');
}

$builds = array();
$buildRs = $db->get_recordset(
    "SELECT id, name FROM {$tbl['builds']} WHERE testproject_id = " . intval($owningProjectId) .
    " ORDER BY id");
if (is_array($buildRs)) {
    foreach ($buildRs as $b) {
        $builds[] = array('id' => intval($b['id']), 'name' => ebnDecode($b['name']));
    }
}

$platforms = array();
$platformRs = $db->get_recordset(
    "SELECT p.id, p.name FROM {$tbl['platforms']} p" .
    " JOIN {$tbl['testplan_platforms']} tpp ON tpp.platform_id = p.id" .
    " WHERE tpp.testplan_id = " . intval($tplanId) . " ORDER BY p.name");
if (is_array($platformRs)) {
    foreach ($platformRs as $p) {
        $platforms[] = array('id' => intval($p['id']), 'name' => ebnDecode($p['name']));
    }
}

$buildNames = array();
foreach ($builds as $b) { $buildNames[$b['id']] = $b['name']; }
$platformNames = array();
foreach ($platforms as $p) { $platformNames[$p['id']] = $p['name']; }

if ($buildId > 0 && !isset($buildNames[$buildId])) {
    ebnFail(400, 'invalid_request');
}

$execSelect =
    "SELECT e.id, e.tcversion_id, e.platform_id, e.build_id, e.tester_id, " .
    "       e.status, e.execution_ts, e.execution_duration, e.notes, " .
    "       u.login, u.first, u.last " .
    "FROM {$tbl['executions']} e LEFT JOIN {$tbl['users']} u ON u.id = e.tester_id " .
    "WHERE e.testplan_id = " . intval($tplanId);
if ($buildId > 0) {
    $execSelect .= " AND e.build_id = " . intval($buildId);
}

$allExecRs = $db->get_recordset($execSelect . " ORDER BY e.execution_ts ASC, e.id ASC");
if (!is_array($allExecRs)) { $allExecRs = array(); }

$testerNames = array();
$testers = array();
foreach ($allExecRs as $e) {
    $tid = intval($e['tester_id']);
    if ($tid > 0 && !isset($testerNames[$tid])) {
        $testerNames[$tid] = ebnUserName($e);
        $testers[] = array('id' => $tid, 'name' => $testerNames[$tid]);
    }
}
ksort($testerNames);
if ($testerId > 0 && !isset($testerNames[$testerId])) {
    ebnFail(400, 'invalid_request');
}

$context = array(
    'tplan_id' => $tplanId,
    'tplan_name' => ebnDecode((string)$planName),
    'tproject_id' => $owningProjectId,
    'tproject_name' => ebnDecode((string)$projectName),
);
$filterEcho = array(
    'build_id' => $buildId,
    'platform_id' => $platformId,
    'tester_id' => $testerId,
    'scope' => $scope,
    'threshold' => $threshold,
);
$filters = array(
    'builds' => $builds,
    'platforms' => $platforms,
    'testers' => $testers,
);

if ($action === 'init') {
    ebnOut(array(
        'status' => 'ok',
        'code' => 200,
        'context' => $context,
        'filter' => $filterEcho,
        'filters' => $filters,
    ));
}

if ($action === 'detail') {
    if ($detailPlatformId > 0 && !isset($platformNames[$detailPlatformId])) {
        ebnFail(400, 'invalid_request');
    }
    $checkSql =
        "SELECT tpt.id FROM {$tbl['testplan_tcversions']} tpt" .
        " WHERE tpt.testplan_id = " . intval($tplanId) .
        " AND tpt.tcversion_id = " . intval($detailTcversionId);
    if ($detailPlatformId > 0) {
        $checkSql .= " AND (tpt.platform_id = 0 OR tpt.platform_id = " . intval($detailPlatformId) . ")";
    }
    $checkRs = $db->get_recordset($checkSql);
    if (!is_array($checkRs) || count($checkRs) === 0) {
        ebnFail(404, 'tcversion_not_found');
    }
    $detailSql = $execSelect;
    if ($detailPlatformId > 0) {
        $detailSql .= " AND (e.platform_id = 0 OR e.platform_id = " . intval($detailPlatformId) . ")";
    }
    $detailSql .= " AND e.tcversion_id = " . intval($detailTcversionId) .
                  " ORDER BY e.execution_ts DESC, e.id DESC LIMIT 50";
    $detailRs = $db->get_recordset($detailSql);
    $history = array();
    if (is_array($detailRs)) {
        foreach ($detailRs as $e) {
            $history[] = array(
                'id' => intval($e['id']),
                'build_name' => isset($buildNames[intval($e['build_id'])])
                    ? $buildNames[intval($e['build_id'])] : '',
                'platform_name' => intval($e['platform_id']) > 0 && isset($platformNames[intval($e['platform_id'])])
                    ? $platformNames[intval($e['platform_id'])] : '',
                'tester' => ebnUserName($e),
                'status' => ebnStatus($e['status'] ?? ''),
                'execution_ts' => (string)($e['execution_ts'] ?? ''),
                'duration' => ebnDuration($e['execution_duration'] ?? null),
                'notes' => ebnDecode($e['notes'] ?? ''),
            );
        }
    }
    ebnOut(array(
        'status' => 'ok',
        'code' => 200,
        'context' => $context,
        'filter' => $filterEcho,
        'history' => $history,
    ));
}

if ($platformId > 0 && !isset($platformNames[$platformId])) {
    ebnFail(400, 'invalid_request');
}

$execByTcversion = array();
foreach ($allExecRs as $e) {
    $tcv = intval($e['tcversion_id']);
    $plat = intval($e['platform_id']);
    if ($platformId > 0 && $plat !== 0 && $plat !== $platformId) {
        continue;
    }
    if ($testerId > 0 && intval($e['tester_id']) !== $testerId) {
        continue;
    }
    if (!isset($execByTcversion[$tcv])) { $execByTcversion[$tcv] = array(); }
    $execByTcversion[$tcv][] = $e;
}

$assignSql =
    "SELECT tpt.id AS feature_id, tpt.tcversion_id, tpt.platform_id, tpt.urgency, " .
    "       tv.version, nh_tc.id AS tcase_id, nh_tc.name AS tcase_name, " .
    "       nh_ts.id AS suite_id, nh_ts.name AS suite_name " .
    "FROM {$tbl['testplan_tcversions']} tpt" .
    " JOIN {$tbl['tcversions']} tv ON tv.id = tpt.tcversion_id" .
    " JOIN {$tbl['nodes_hierarchy']} nh_tv ON nh_tv.id = tv.id" .
    " JOIN {$tbl['nodes_hierarchy']} nh_tc ON nh_tc.id = nh_tv.parent_id" .
    " LEFT JOIN {$tbl['nodes_hierarchy']} nh_ts ON nh_ts.id = nh_tc.parent_id" .
    " WHERE tpt.testplan_id = " . intval($tplanId);
if ($platformId > 0) {
    $assignSql .= " AND (tpt.platform_id = 0 OR tpt.platform_id = " . intval($platformId) . ")";
}
$assignSql .= " ORDER BY nh_ts.name, nh_tc.name, tpt.platform_id, tv.id";
$assignRs = $db->get_recordset($assignSql);
if (!is_array($assignRs)) { $assignRs = array(); }

$rows = array();
$summary = array(
    'assigned' => 0,
    'executed' => 0,
    'never_run' => 0,
    'timed' => 0,
    'untimed' => 0,
    'reruns' => 0,
    'slow' => 0,
    'total_seconds' => 0.0,
    'avg_seconds' => 0.0,
    'max_seconds' => 0.0,
    'suites' => 0,
    'testers' => count($testerNames),
    'builds' => count($builds),
    'threshold' => $threshold,
);
$suiteSet = array();

foreach ($assignRs as $r) {
    $tcv = intval($r['tcversion_id']);
    $runs = isset($execByTcversion[$tcv]) ? $execByTcversion[$tcv] : array();
    $runCount = count($runs);
    $timedCount = 0;
    $total = 0.0;
    $max = 0.0;
    $last = null;
    foreach ($runs as $e) {
        $d = ebnDuration($e['execution_duration'] ?? null);
        if ($d !== null) {
            $timedCount++;
            $total += $d;
            if ($d > $max) { $max = $d; }
        }
        $last = $e;
    }
    $avg = ($timedCount > 0) ? round($total / $timedCount, 2) : 0.0;
    $isSlow = ($timedCount > 0 && $avg >= $threshold);
    $isRerun = ($runCount > 1);
    $level = function_exists('priority_to_level')
        ? intval(priority_to_level(intval($r['urgency']))) : MEDIUM;
    $priority = $level >= HIGH ? 'high' : ($level >= MEDIUM ? 'medium' : 'low');
    $suiteId = intval($r['suite_id']);

    $summary['assigned']++;
    if ($runCount > 0) { $summary['executed']++; } else { $summary['never_run']++; }
    $summary['timed'] += $timedCount;
    if ($runCount > 0 && $timedCount === 0) { $summary['untimed']++; }
    if ($isRerun) { $summary['reruns']++; }
    if ($isSlow) { $summary['slow']++; }
    $summary['total_seconds'] += $total;
    if ($max > $summary['max_seconds']) { $summary['max_seconds'] = $max; }
    if ($suiteId > 0 && ($isSlow || $isRerun)) { $suiteSet[$suiteId] = true; }

    if ($scope === 'slow' && !$isSlow) { continue; }
    if ($scope === 'rerun' && !$isRerun) { continue; }
    if ($scope === 'never' && $runCount > 0) { continue; }

    $lastTesterId = $last ? intval($last['tester_id']) : 0;
    $rows[] = array(
        'feature_id' => intval($r['feature_id']),
        'tcase_id' => intval($r['tcase_id']),
        'tcversion_id' => $tcv,
        'tcase_name' => ebnDecode($r['tcase_name']),
        'version' => intval($r['version']),
        'suite_id' => $suiteId,
        'suite_name' => ebnDecode((string)($r['suite_name'] ?: '-')),
        'platform_id' => intval($r['platform_id']),
        'platform_name' => intval($r['platform_id']) > 0 && isset($platformNames[intval($r['platform_id'])])
            ? $platformNames[intval($r['platform_id'])] : '',
        'priority' => $priority,
        'run_count' => $runCount,
        'timed_count' => $timedCount,
        'total_seconds' => round($total, 2),
        'avg_seconds' => $avg,
        'max_seconds' => round($max, 2),
        'last_status' => $last ? ebnStatus($last['status'] ?? '') : 'not_run',
        'last_build' => ($last && isset($buildNames[intval($last['build_id'])]))
            ? $buildNames[intval($last['build_id'])] : '',
        'last_run' => $last ? (string)($last['execution_ts'] ?? '') : '',
        'last_tester' => ($lastTesterId > 0 && isset($testers[$lastTesterId]))
            ? $testers[$lastTesterId] : '',
        'is_slow' => $isSlow,
        'is_rerun' => $isRerun,
    );
}

$summary['total_seconds'] = round($summary['total_seconds'], 2);
$summary['avg_seconds'] = ($summary['timed'] > 0)
    ? round($summary['total_seconds'] / $summary['timed'], 2) : 0.0;
$summary['max_seconds'] = round($summary['max_seconds'], 2);
$summary['suites'] = count($suiteSet);

if ($summary['assigned'] === 0) {
    $verdict = 'no_data';
} elseif ($summary['executed'] === 0) {
    $verdict = 'no_data';
} elseif ($summary['untimed'] === $summary['executed'] && $summary['timed'] === 0) {
    $verdict = 'no_data';
} elseif ($summary['slow'] === 0 && $summary['reruns'] === 0) {
    $verdict = 'clear';
} elseif ($summary['slow'] >= 5 || ebnPct($summary['slow'], $summary['executed']) >= 30) {
    $verdict = 'at_risk';
} else {
    $verdict = 'watch';
}

usort($rows, function ($a, $b) {
    if ($a['avg_seconds'] !== $b['avg_seconds']) {
        return ($a['avg_seconds'] < $b['avg_seconds']) ? 1 : -1;
    }
    return strcmp($a['suite_name'], $b['suite_name']);
});

ebnOut(array(
    'status' => 'ok',
    'code' => 200,
    'context' => $context,
    'filter' => $filterEcho,
    'filters' => $filters,
    'summary' => $summary,
    'rows' => $rows,
    'verdict' => $verdict,
));
