<?php
require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function eownOut($payload, $code = null)
{
    if ($code !== null) {
        http_response_code($code);
    }
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function eownFail($httpCode, $code, $message = '')
{
    eownOut(array(
        'status' => 'error',
        'code' => $code,
        'message' => $message !== '' ? $message : $code,
    ), $httpCode);
}

function eownPositiveInt($raw)
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

function eownNonNegativeInt($raw, $default = 0)
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

function eownDecode($value)
{
    return html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function eownPct($part, $total)
{
    return ($total > 0) ? round(100.0 * $part / $total, 1) : 0.0;
}

function eownStatus($letter)
{
    switch (strtolower(trim((string)$letter))) {
        case 'p': return 'passed';
        case 'f': return 'failed';
        case 'b': return 'blocked';
    }
    return 'not_run';
}

function eownUserName($row)
{
    if (!is_array($row)) {
        return '';
    }
    $full = trim((string)($row['first'] ?? '') . ' ' . (string)($row['last'] ?? ''));
    return eownDecode($full !== '' ? $full : ($row['login'] ?? ''));
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    eownFail(405, 'method_not_allowed');
}

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    eownFail(401, 'not_authenticated');
}
bffEnforceSession($db);

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    eownFail(401, 'not_authenticated');
}

$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if (!in_array($action, array('init', 'report', 'detail'), true)) {
    eownFail(400, 'invalid_request');
}

$tplanId = eownPositiveInt($_GET['tplan_id'] ?? null);
$assertedProjectId = eownPositiveInt($_GET['tproject_id'] ?? null);
$buildId = eownNonNegativeInt($_GET['build_id'] ?? '0');
$platformId = eownNonNegativeInt($_GET['platform_id'] ?? '0');
$scope = strtolower(trim((string)($_GET['scope'] ?? 'all')));
$detailTcversionId = eownPositiveInt($_GET['tcversion_id'] ?? null);
$detailPlatformId = eownNonNegativeInt($_GET['platform_id'] ?? '0');
if ($buildId < 0 || $platformId < 0 || $detailPlatformId < 0) {
    eownFail(400, 'invalid_request');
}
if (!in_array($scope, array('all', 'gap'), true)) {
    eownFail(400, 'invalid_request');
}
if ($action === 'detail') {
    if ($detailTcversionId <= 0) {
        eownFail(400, 'invalid_request');
    }
} elseif ($tplanId <= 0) {
    eownFail(400, 'invalid_request');
}
$tbl = tlObjectWithDB::getDBTables(array(
    'testplans', 'nodes_hierarchy', 'testplan_tcversions', 'tcversions',
    'builds', 'platforms', 'testplan_platforms', 'executions',
    'user_assignments', 'users',
));

if ($tplanId <= 0) {
    eownFail(400, 'invalid_request');
}

$planRs = $db->get_recordset(
    "SELECT id, testproject_id FROM {$tbl['testplans']} WHERE id = " . intval($tplanId));
if (!is_array($planRs) || count($planRs) === 0) {
    eownFail(404, 'tplan_not_found');
}
$owningProjectId = intval($planRs[0]['testproject_id']);
if ($owningProjectId <= 0) {
    eownFail(404, 'tplan_not_found');
}
if (!$user->hasRight($db, 'testplan_metrics', $owningProjectId, $tplanId, true)) {
    tLog('BFF executionownership: user ' . intval($userId) .
         ' has no testplan_metrics right on tproject ' . intval($owningProjectId) .
         ' (tplan ' . $tplanId . ')', 'AUDIT');
    eownFail(403, 'no_right');
}
if ($assertedProjectId > 0 && $assertedProjectId !== $owningProjectId) {
    eownFail(404, 'project_mismatch');
}

$planName = testplan::getName($db, $tplanId);
$projectName = testproject::getName($db, $owningProjectId);
if (is_null($planName) || trim((string)$planName) === '' ||
    is_null($projectName) || trim((string)$projectName) === '') {
    eownFail(404, 'tplan_not_found');
}

$builds = array();
$buildRs = $db->get_recordset(
    "SELECT id, name FROM {$tbl['builds']} WHERE testproject_id = " . intval($owningProjectId) .
    " ORDER BY id");
if (is_array($buildRs)) {
    foreach ($buildRs as $b) {
        $builds[] = array('id' => intval($b['id']), 'name' => eownDecode($b['name']));
    }
}

$platforms = array();
$platformRs = $db->get_recordset(
    "SELECT p.id, p.name FROM {$tbl['platforms']} p" .
    " JOIN {$tbl['testplan_platforms']} tpp ON tpp.platform_id = p.id" .
    " WHERE tpp.testplan_id = " . intval($tplanId) . " ORDER BY p.name");
if (is_array($platformRs)) {
    foreach ($platformRs as $p) {
        $platforms[] = array('id' => intval($p['id']), 'name' => eownDecode($p['name']));
    }
}

$buildNames = array();
foreach ($builds as $b) { $buildNames[$b['id']] = $b['name']; }
$platformNames = array();
foreach ($platforms as $p) { $platformNames[$p['id']] = $p['name']; }

if ($buildId > 0 && !isset($buildNames[$buildId])) {
    eownFail(400, 'invalid_request');
}

if ($action === 'detail') {
    if ($detailPlatformId > 0 && !isset($platformNames[$detailPlatformId])) {
        eownFail(400, 'invalid_request');
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
        eownFail(404, 'tcversion_not_found');
    }
    $detailSql =
        "SELECT e.id, e.build_id, e.platform_id, e.tester_id, e.status, e.execution_ts, e.notes, " .
        "       u.login, u.first, u.last " .
        "FROM {$tbl['executions']} e LEFT JOIN {$tbl['users']} u ON u.id = e.tester_id " .
        "WHERE e.testplan_id = " . intval($tplanId) .
        " AND e.tcversion_id = " . intval($detailTcversionId);
    if ($detailPlatformId > 0) {
        $detailSql .= " AND (e.platform_id = 0 OR e.platform_id = " . intval($detailPlatformId) . ")";
    }
    $detailSql .= " ORDER BY e.execution_ts DESC, e.id DESC LIMIT 30";
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
                'tester' => eownUserName($e),
                'status' => eownStatus($e['status'] ?? ''),
                'execution_ts' => (string)($e['execution_ts'] ?? ''),
                'notes' => eownDecode($e['notes'] ?? ''),
            );
        }
    }
    eownOut(array(
        'status' => 'ok',
        'code' => 200,
        'context' => array(
            'tplan_id' => $tplanId,
            'tplan_name' => eownDecode((string)$planName),
            'tproject_id' => $owningProjectId,
            'tproject_name' => eownDecode((string)$projectName),
        ),
        'history' => $history,
    ));
}

if ($buildId > 0 && !isset($buildNames[$buildId])) {
    eownFail(400, 'invalid_request');
}
if ($platformId > 0 && !isset($platformNames[$platformId])) {
    eownFail(400, 'invalid_request');
}

$context = array(
    'tplan_id' => $tplanId,
    'tplan_name' => eownDecode((string)$planName),
    'tproject_id' => $owningProjectId,
    'tproject_name' => eownDecode((string)$projectName),
);
$filterEcho = array(
    'build_id' => $buildId, 'platform_id' => $platformId, 'scope' => $scope,
);

if ($action === 'init') {
    eownOut(array(
        'status' => 'ok',
        'code' => 200,
        'context' => $context,
        'filter' => $filterEcho,
        'filters' => array('builds' => $builds, 'platforms' => $platforms),
    ));
}

$assignments = array();
$assignRs = $db->get_recordset(
    "SELECT ua.feature_id, ua.build_id, ua.user_id " .
    "FROM {$tbl['user_assignments']} ua " .
    "WHERE ua.type = 1 AND ua.user_id > 0 AND COALESCE(ua.status, 1) NOT IN (2, 3)");
if (is_array($assignRs)) {
    foreach ($assignRs as $a) {
        $abuild = intval($a['build_id']);
        if ($buildId > 0 && $abuild !== 0 && $abuild !== $buildId) {
            continue;
        }
        $fid = intval($a['feature_id']);
        if (!isset($assignments[$fid])) { $assignments[$fid] = array(); }
        $assignments[$fid][intval($a['user_id'])] = true;
    }
}

$userNames = array();
$userRs = $db->get_recordset(
    "SELECT id, login, first, last FROM {$tbl['users']}");
if (is_array($userRs)) {
    foreach ($userRs as $u) {
        $userNames[intval($u['id'])] = eownUserName($u);
    }
}

$execSql =
    "SELECT e.id, e.tcversion_id, e.platform_id, e.build_id, e.tester_id, " .
    "       e.status, e.execution_ts, e.notes " .
    "FROM {$tbl['executions']} e WHERE e.testplan_id = " . intval($tplanId);
if ($buildId > 0) {
    $execSql .= " AND e.build_id = " . intval($buildId);
}
if ($platformId > 0) {
    $execSql .= " AND (e.platform_id = 0 OR e.platform_id = " . intval($platformId) . ")";
}
$execSql .= " ORDER BY e.execution_ts ASC, e.id ASC";
$lastExec = array();
$lastExecAny = array();
$testerSet = array();
$execRs = $db->get_recordset($execSql);
if (is_array($execRs)) {
    foreach ($execRs as $e) {
        $tcv = intval($e['tcversion_id']);
        $plat = intval($e['platform_id']);
        $lastExec[$tcv . '|' . $plat] = $e;
        $lastExecAny[$tcv] = $e;
        $testerId = intval($e['tester_id']);
        if ($testerId > 0) { $testerSet[$testerId] = true; }
    }
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
    'owned' => 0,
    'mismatch' => 0,
    'unowned' => 0,
    'no_execution' => 0,
    'mismatch_pct' => 0.0,
    'suites' => 0,
    'testers' => count($testerSet),
    'builds' => count($builds),
    'platforms' => count($platforms),
);
$suiteSet = array();

foreach ($assignRs as $r) {
    $tcv = intval($r['tcversion_id']);
    $fid = intval($r['feature_id']);
    $pinned = intval($r['platform_id']);
    $ownerIds = isset($assignments[$fid]) ? array_keys($assignments[$fid]) : array();

    $e = ($pinned === 0 && isset($lastExecAny[$tcv]))
        ? $lastExecAny[$tcv]
        : (isset($lastExec[$tcv . '|' . $pinned]) ? $lastExec[$tcv . '|' . $pinned] : null);
    if ($e === null && $pinned !== 0 && isset($lastExec[$tcv . '|0'])) {
        $e = $lastExec[$tcv . '|0'];
    }
    $hasExec = is_array($e);
    $testerId = $hasExec ? intval($e['tester_id']) : 0;
    if (!$hasExec) {
        $gap = 'no_execution';
    } elseif (count($ownerIds) === 0) {
        $gap = 'unowned';
    } elseif (!in_array($testerId, $ownerIds, true)) {
        $gap = 'mismatch';
    } else {
        $gap = 'owned';
    }
    $status = eownStatus($e ? ($e['status'] ?? '') : '');
    $suiteId = intval($r['suite_id']);

    $summary['assigned']++;
    if ($hasExec) { $summary['executed']++; }
    if (isset($summary[$gap])) { $summary[$gap]++; }
    if ($gap !== 'owned' && $gap !== 'no_execution' && $suiteId > 0) {
        $suiteSet[$suiteId] = true;
    }

    if ($scope === 'gap' && $gap === 'owned') {
        continue;
    }

    $level = function_exists('priority_to_level')
        ? intval(priority_to_level(intval($r['urgency']))) : MEDIUM;
    $priority = $level >= HIGH ? 'high' : ($level >= MEDIUM ? 'medium' : 'low');

    $rows[] = array(
        'feature_id' => $fid,
        'tcase_id' => intval($r['tcase_id']),
        'tcversion_id' => $tcv,
        'tcase_name' => eownDecode($r['tcase_name']),
        'version' => intval($r['version']),
        'suite_id' => $suiteId,
        'suite_name' => eownDecode((string)($r['suite_name'] ?: '-')),
        'platform_id' => $pinned,
        'platform_name' => ($pinned > 0 && isset($platformNames[$pinned])) ? $platformNames[$pinned] : '',
        'priority' => $priority,
        'owners' => $ownerIds,
        'owner_names' => array_values(array_map(function ($id) use ($userNames) {
            return isset($userNames[$id]) ? $userNames[$id] : ('#' . $id);
        }, $ownerIds)),
        'tester_id' => $testerId,
        'tester' => ($testerId > 0 && isset($userNames[$testerId])) ? $userNames[$testerId] : '',
        'gap' => $gap,
        'last_status' => $status,
        'last_build' => ($hasExec && isset($buildNames[intval($e['build_id'])]))
            ? $buildNames[intval($e['build_id'])] : '',
        'last_run' => $hasExec ? (string)($e['execution_ts'] ?? '') : '',
        'last_note' => $hasExec ? eownDecode($e['notes'] ?? '') : '',
    );
}

$summary['mismatch_pct'] = eownPct($summary['mismatch'] + $summary['unowned'], $summary['executed']);
$summary['suites'] = count($suiteSet);

if ($summary['assigned'] === 0) {
    $verdict = 'no_data';
} elseif ($summary['executed'] === 0) {
    $verdict = 'no_data';
} elseif ($summary['mismatch'] + $summary['unowned'] === 0) {
    $verdict = 'clear';
} elseif ($summary['mismatch_pct'] >= 30 || $summary['mismatch'] + $summary['unowned'] >= 5) {
    $verdict = 'at_risk';
} else {
    $verdict = 'watch';
}

eownOut(array(
    'status' => 'ok',
    'code' => 200,
    'context' => $context,
    'filter' => $filterEcho,
    'filters' => array('builds' => $builds, 'platforms' => $platforms),
    'summary' => $summary,
    'rows' => $rows,
    'verdict' => $verdict,
));
