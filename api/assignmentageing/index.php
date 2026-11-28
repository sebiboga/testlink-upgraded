<?php
/**
 * Test Plan Assignment Ageing report BFF (Refs #2095).
 *
 * Read-only report over active `user_assignments` execution-assignment rows of
 * one test plan: how old is the assigned work, is it overdue, and was it ever
 * executed? Actions: GET|HEAD ?action=init|report|detail
 */
require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
require_once('testplan.class.php');
require_once('testproject.class.php');

doSessionStart();

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    http_response_code(405);
    echo json_encode(array('status' => 'error', 'code' => 'method_not_allowed',
        'message' => 'method_not_allowed'));
    exit;
}

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function aagOut($payload, $code = null)
{
    if ($code !== null) {
        http_response_code($code);
    }
    echo json_encode($payload);
    exit;
}

function aagFail($httpCode, $code, $message = '')
{
    aagOut(array(
        'status' => 'error',
        'code' => $code,
        'message' => $message !== '' ? $message : $code,
    ), $httpCode);
}

function aagPositiveInt($raw)
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

function aagDecode($value)
{
    return html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function aagPct($part, $total)
{
    return ($total > 0) ? round(100.0 * $part / $total, 1) : 0.0;
}

function aagDays($ts, $now)
{
    if ($ts === null || (string)$ts === '') {
        return null;
    }
    $t = strtotime((string)$ts);
    if ($t === false) {
        return null;
    }
    return max(0, (int)floor(($now - $t) / 86400));
}

function aagResult($letter)
{
    switch ((string)$letter) {
        case 'p': return 'passed';
        case 'f': return 'failed';
        case 'b': return 'blocked';
    }
    return 'not_run';
}

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    aagFail(401, 'not_authenticated');
}
bffEnforceSession($db);
$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    aagFail(401, 'not_authenticated');
}

$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if (!in_array($action, array('init', 'report', 'detail'), true)) {
    aagFail(400, 'invalid_request');
}

$tplanId = aagPositiveInt($_GET['tplan_id'] ?? null);
$assertedProjectId = aagPositiveInt($_GET['tproject_id'] ?? null);
if ($tplanId <= 0) {
    aagFail(400, 'invalid_request');
}

$tbl = tlObjectWithDB::getDBTables(array(
    'testplans', 'testprojects', 'builds', 'platforms', 'testplan_tcversions',
    'tcversions', 'nodes_hierarchy', 'executions', 'user_assignments', 'users',
));

$planRs = $db->get_recordset(
    "SELECT id, testproject_id FROM {$tbl['testplans']} WHERE id = " . intval($tplanId));
if (!is_array($planRs) || count($planRs) === 0) {
    aagFail(404, 'tplan_not_found');
}
$owningProjectId = intval($planRs[0]['testproject_id']);
if ($owningProjectId <= 0) {
    aagFail(404, 'tplan_not_found');
}
if (!$user->hasRight($db, 'testplan_metrics', $owningProjectId, $tplanId, true)) {
    tLog('BFF assignmentageing: user ' . intval($userId) .
         ' has no testplan_metrics right on tproject ' . $owningProjectId .
         ' (tplan ' . $tplanId . ')', 'AUDIT');
    aagFail(403, 'no_right');
}
if ($assertedProjectId > 0 && $assertedProjectId !== $owningProjectId) {
    aagFail(404, 'project_mismatch');
}

$planName = testplan::getName($db, $tplanId);
$projectName = testproject::getName($db, $owningProjectId);
$prefixRs = $db->get_recordset(
    "SELECT prefix FROM {$tbl['testprojects']} WHERE id = " . intval($owningProjectId));
$prefix = (is_array($prefixRs) && count($prefixRs) > 0)
    ? (string)$prefixRs[0]['prefix'] : '';
if (is_null($planName) || trim((string)$planName) === '') {
    aagFail(404, 'tplan_not_found');
}

$context = array(
    'tplan_id' => $tplanId,
    'tplan_name' => aagDecode((string)$planName),
    'tproject_id' => $owningProjectId,
    'tproject_name' => aagDecode((string)$projectName),
    'prefix' => $prefix,
);

$builds = array();
$buildRs = $db->get_recordset(
    "SELECT id, name FROM {$tbl['builds']} WHERE testproject_id = " . intval($owningProjectId) .
    " AND active = 1 ORDER BY id");
if (is_array($buildRs)) {
    foreach ($buildRs as $b) {
        $builds[] = array('id' => intval($b['id']), 'name' => aagDecode($b['name']));
    }
}

$platforms = array();
$platformRs = $db->get_recordset(
    "SELECT id, name FROM {$tbl['platforms']} WHERE testproject_id = " . intval($owningProjectId) .
    " ORDER BY name");
if (is_array($platformRs)) {
    foreach ($platformRs as $p) {
        $platforms[] = array('id' => intval($p['id']), 'name' => aagDecode($p['name']));
    }
}

$testerRs = $db->get_recordset(
    "SELECT DISTINCT ua.user_id, u.login, u.first, u.last " .
    "FROM {$tbl['user_assignments']} ua " .
    "JOIN {$tbl['testplan_tcversions']} tpt ON tpt.id = ua.feature_id " .
    "LEFT JOIN {$tbl['users']} u ON u.id = ua.user_id " .
    "WHERE ua.type = 1 AND tpt.testplan_id = " . intval($tplanId) .
    " ORDER BY u.login");
$testers = array();
if (is_array($testerRs)) {
    foreach ($testerRs as $u) {
        $uid = intval($u['user_id']);
        if ($uid <= 0) {
            continue;
        }
        $name = trim(aagDecode(trim((string)$u['first']) . ' ' . trim((string)$u['last'])));
        if ($name === '') {
            $name = aagDecode((string)$u['login']);
        }
        $testers[] = array('id' => $uid, 'name' => $name);
    }
}

$testerNames = array();
foreach ($testers as $u) {
    $testerNames[$u['id']] = $u['name'];
}

$buildId = aagPositiveInt($_GET['build_id'] ?? null);
$platformId = aagPositiveInt($_GET['platform_id'] ?? null);
$testerId = aagPositiveInt($_GET['tester_id'] ?? null);
$bucketFilter = isset($_GET['bucket']) && !is_array($_GET['bucket'])
    ? strtolower(trim((string)$_GET['bucket'])) : '';
$scope = isset($_GET['scope']) && !is_array($_GET['scope'])
    ? strtolower(trim((string)$_GET['scope'])) : 'active';
if (!in_array($scope, array('active', 'all'), true)) {
    $scope = 'active';
}

$knownBuild = false;
foreach ($builds as $b) {
    if ($b['id'] === $buildId) { $knownBuild = true; break; }
}
if ($buildId > 0 && !$knownBuild) {
    aagFail(400, 'invalid_request');
}
$knownPlatform = false;
foreach ($platforms as $p) {
    if ($p['id'] === $platformId) { $knownPlatform = true; break; }
}
if ($platformId > 0 && !$knownPlatform) {
    aagFail(400, 'invalid_request');
}

$filters = array(
    'builds' => $builds,
    'platforms' => $platforms,
    'testers' => $testers,
    'buckets' => array('never', 'fresh', 'watch', 'stale', 'dormant'),
    'scopes' => array('active', 'all'),
);
$filterEcho = array(
    'build_id' => $buildId,
    'platform_id' => $platformId,
    'tester_id' => $testerId,
    'bucket' => $bucketFilter,
    'scope' => $scope,
);

if ($action === 'init') {
    aagOut(array(
        'status' => 'ok',
        'code' => 200,
        'context' => $context,
        'filters' => $filters,
        'filter' => $filterEcho,
    ));
}

// ---------------------------------------------------------------------------
// Version + suite metadata for the plan's assigned test cases.
// ---------------------------------------------------------------------------
$versionSql =
    "SELECT tpt.id AS tpt_id, tpt.tcversion_id, tpt.platform_id, " .
    "       ntc.id AS tcase_node_id, ntc.name AS tcase_name, ntc.parent_id AS suite_id, " .
    "       tcv.version, tcv.tc_external_id, tcv.importance " .
    "FROM {$tbl['testplan_tcversions']} tpt " .
    "JOIN {$tbl['nodes_hierarchy']} ntcv ON ntcv.id = tpt.tcversion_id " .
    "JOIN {$tbl['nodes_hierarchy']} ntc ON ntc.id = ntcv.parent_id " .
    "JOIN {$tbl['tcversions']} tcv ON tcv.id = tpt.tcversion_id " .
    "WHERE tpt.testplan_id = " . intval($tplanId);
$versionRs = $db->get_recordset($versionSql);
$versions = array();
$suiteParents = array();
$suiteNames = array();
$suiteIds = array();
if (is_array($versionRs)) {
    foreach ($versionRs as $v) {
        $tcv = intval($v['tcversion_id']);
        $versions[$tcv] = array(
            'tpt_id' => intval($v['tpt_id']),
            'platform_id' => intval($v['platform_id']),
            'tcase_name' => aagDecode($v['tcase_name']),
            'suite_id' => intval($v['suite_id']),
            'version' => intval($v['version']),
            'external_id' => intval($v['tc_external_id']),
            'importance' => intval($v['importance']),
        );
        $suiteIds[intval($v['suite_id'])] = true;
    }
}
if (count($suiteIds) > 0) {
    $queue = array_keys($suiteIds);
    $guard = 0;
    while (count($queue) > 0 && $guard < 500) {
        $guard++;
        $ids = array_splice($queue, 0, 200);
        $in = implode(',', array_map('intval', $ids));
        $suiteRs = $db->get_recordset(
            "SELECT id, name, parent_id, node_type_id FROM {$tbl['nodes_hierarchy']} " .
            "WHERE id IN (" . $in . ")");
        if (!is_array($suiteRs)) {
            continue;
        }
        foreach ($suiteRs as $s) {
            $sid = intval($s['id']);
            $suiteNames[$sid] = aagDecode($s['name']);
            $parent = intval($s['parent_id']);
            $suiteParents[$sid] = $parent;
            if ($parent > 0 && intval($s['node_type_id']) === 2 && !isset($suiteNames[$parent])) {
                $queue[] = $parent;
            }
        }
    }
}
$aagSuitePath = function ($suiteId) use (&$suiteParents, &$suiteNames) {
    $parts = array();
    $cur = intval($suiteId);
    $guard = 0;
    while ($cur > 0 && $guard < 32) {
        if (!isset($suiteNames[$cur])) { break; }
        $parts[] = $suiteNames[$cur];
        $cur = intval($suiteParents[$cur] ?? 0);
        $guard++;
    }
    return implode(' / ', array_reverse($parts));
};

// ---------------------------------------------------------------------------
// Latest execution per (tcversion, platform) in the filtered scope.
// ---------------------------------------------------------------------------
$nowRs = $db->get_recordset("SELECT UNIX_TIMESTAMP(NOW()) AS now_ts");
$now = (is_array($nowRs) && count($nowRs) > 0) ? intval($nowRs[0]['now_ts']) : time();

$execSql =
    "SELECT e.id, e.tcversion_id, e.platform_id, e.build_id, e.tester_id, " .
    "       e.status, e.execution_ts, e.notes " .
    "FROM {$tbl['executions']} e WHERE e.testplan_id = " . intval($tplanId) .
    " AND EXISTS (SELECT 1 FROM {$tbl['testplan_tcversions']} tpt" .
    "             WHERE tpt.testplan_id = " . intval($tplanId) .
    "               AND tpt.tcversion_id = e.tcversion_id" .
    "               AND (tpt.platform_id = 0 OR tpt.platform_id = e.platform_id))";
if ($buildId > 0) {
    $execSql .= " AND e.build_id = " . intval($buildId);
}
if ($platformId > 0) {
    $execSql .= " AND e.platform_id = " . intval($platformId);
}
$execSql .= " ORDER BY e.execution_ts, e.id";
$execRs = $db->get_recordset($execSql);

$latest = array();
$history = array();
if (is_array($execRs)) {
    foreach ($execRs as $e) {
        $tcv = intval($e['tcversion_id']);
        $plat = intval($e['platform_id']);
        $key = $tcv . '|' . $plat;
        $latest[$key] = $e;
        $history[$key][] = array(
            'id' => intval($e['id']),
            'build_id' => intval($e['build_id']),
            'tester_id' => intval($e['tester_id']),
            'tester_name' => isset($testerNames[intval($e['tester_id'])])
                ? $testerNames[intval($e['tester_id'])] : '',
            'status' => aagResult($e['status']),
            'execution_ts' => (string)$e['execution_ts'],
            'notes' => aagDecode((string)$e['notes']),
        );
    }
}

$buildNames = array();
foreach ($builds as $b) { $buildNames[$b['id']] = $b['name']; }
$platformNames = array();
foreach ($platforms as $p) { $platformNames[$p['id']] = $p['name']; }
// ---------------------------------------------------------------------------
// Assignment rows.
// ---------------------------------------------------------------------------
$assignSql =
    "SELECT ua.id, ua.user_id, ua.build_id, ua.deadline_ts, ua.status AS assign_status, " .
    "       ua.creation_ts, ua.assigner_id, " .
    "       tpt.id AS tpt_id, tpt.tcversion_id, tpt.platform_id " .
    "FROM {$tbl['user_assignments']} ua " .
    "JOIN {$tbl['testplan_tcversions']} tpt ON tpt.id = ua.feature_id " .
    "WHERE ua.type = 1 AND tpt.testplan_id = " . intval($tplanId);
if ($platformId > 0) {
    $assignSql .= " AND (tpt.platform_id = 0 OR tpt.platform_id = " . intval($platformId) . ")";
}
if ($buildId > 0) {
    $assignSql .= " AND (ua.build_id = 0 OR ua.build_id = " . intval($buildId) . ")";
}
if ($testerId > 0) {
    $assignSql .= " AND ua.user_id = " . intval($testerId);
}
$assignSql .= " ORDER BY ua.creation_ts, ua.id";
$assignRs = $db->get_recordset($assignSql);

$rows = array();
$summary = array(
    'assignments' => 0,
    'never_executed' => 0,
    'executed' => 0,
    'overdue' => 0,
    'fresh' => 0,
    'watch' => 0,
    'stale' => 0,
    'dormant' => 0,
    'avg_age_days' => 0.0,
    'oldest_days' => 0,
    'completion_pct' => 0.0,
);
$ageSum = 0;
$ageCount = 0;

if (is_array($assignRs)) {
    foreach ($assignRs as $a) {
        $assignStatus = intval($a['assign_status'] ?? 1);
        if ($scope === 'active' && ($assignStatus === 2 || $assignStatus === 3)) {
            continue;
        }
        $uid = intval($a['user_id']);
        if ($uid <= 0) {
            continue;
        }
        $tcv = intval($a['tcversion_id']);
        $plat = intval($a['platform_id']);
        $ver = $versions[$tcv] ?? null;
        if (is_null($ver)) {
            continue;
        }
        $keys = array($tcv . '|' . $plat);
        if ($plat === 0) {
            foreach ($platformNames as $pid => $unused) {
                $keys[] = $tcv . '|' . intval($pid);
            }
        }
        $last = null;
        foreach ($keys as $k) {
            if (isset($latest[$k]) &&
                (is_null($last) || strcmp((string)$latest[$k]['execution_ts'],
                                          (string)$last['execution_ts']) > 0)) {
                $last = $latest[$k];
            }
        }
        if (!is_null($last) && $buildId > 0 && intval($last['build_id']) !== $buildId) {
            $last = null;
        }

        $created = aagDays($a['creation_ts'] ?? '', $now);
        $lastDays = is_null($last) ? null : aagDays($last['execution_ts'], $now);
        if (is_null($last)) {
            $bucket = 'never';
            $age = $created;
        } else {
            $age = $lastDays;
            if ($age === null) { $bucket = 'fresh'; }
            elseif ($age <= 7) { $bucket = 'fresh'; }
            elseif ($age <= 30) { $bucket = 'watch'; }
            elseif ($age <= 90) { $bucket = 'stale'; }
            else { $bucket = 'dormant'; }
        }
        if ($bucketFilter !== '' && $bucketFilter !== 'all' && $bucketFilter !== $bucket) {
            continue;
        }

        $deadline = (string)($a['deadline_ts'] ?? '');
        $deadlineTs = ($deadline !== '') ? strtotime($deadline) : false;
        $overdueDays = 0;
        $daysLeft = null;
        $deadlineState = 'none';
        if ($deadlineTs !== false && is_null($last)) {
            if ($deadlineTs < $now) {
                $overdueDays = (int)floor(($now - $deadlineTs) / 86400);
                $deadlineState = 'overdue';
            } else {
                $daysLeft = (int)floor(($deadlineTs - $now) / 86400);
                $deadlineState = 'pending';
            }
        }

        $assignBuild = intval($a['build_id']);
        $row = array(
            'id' => intval($a['id']),
            'assignment_status' => $assignStatus,
            'tester_id' => $uid,
            'tester' => $testerNames[$uid] ?? ('user ' . $uid),
            'assigner_id' => intval($a['assigner_id'] ?? 0),
            'tpt_id' => intval($a['tpt_id']),
            'tcversion_id' => $tcv,
            'external_id' => $prefix . '-' . intval($ver['external_id']),
            'tcase_name' => $ver['tcase_name'],
            'suite_path' => $aagSuitePath($ver['suite_id']),
            'version' => $ver['version'],
            'importance' => $ver['importance'],
            'platform_id' => $plat,
            'platform' => $plat > 0 ? ($platformNames[$plat] ?? ('platform ' . $plat)) : '',
            'build_id' => $assignBuild,
            'build' => $assignBuild > 0 ? ($buildNames[$assignBuild] ?? ('build ' . $assignBuild)) : '',
            'creation_ts' => (string)$a['creation_ts'],
            'age_days' => is_null($age) ? 0 : $age,
            'deadline_ts' => $deadline,
            'days_left' => $daysLeft,
            'overdue_days' => $overdueDays,
            'deadline_state' => $deadlineState,
            'bucket' => $bucket,
            'executed' => is_null($last) ? 0 : 1,
            'result' => is_null($last) ? 'not_run' : aagResult($last['status']),
            'last_execution_ts' => is_null($last) ? '' : (string)$last['execution_ts'],
            'last_tester' => is_null($last) ? '' :
                ($testerNames[intval($last['tester_id'])] ?? ''),
        );
        $rows[] = $row;

        $summary['assignments']++;
        $summary[$bucket]++;
        if ($deadlineState === 'overdue') {
            $summary['overdue']++;
        }
        if ($row['executed']) {
            $summary['executed']++;
        } else {
            $summary['never_executed']++;
        }
        if (!is_null($age)) {
            $ageSum += $age;
            $ageCount++;
            if ($age > $summary['oldest_days']) { $summary['oldest_days'] = $age; }
        }
    }
}
$summary['completion_pct'] = aagPct($summary['executed'], $summary['assignments']);
$summary['avg_age_days'] = $ageCount > 0 ? round($ageSum / $ageCount, 1) : 0.0;

if ($summary['assignments'] === 0) {
    $verdict = 'no_data';
} elseif ($summary['dormant'] > 0 || aagPct($summary['overdue'], $summary['assignments']) >= 20) {
    $verdict = 'at_risk';
} elseif ($summary['stale'] > 0 || $summary['overdue'] > 0) {
    $verdict = 'watch';
} else {
    $verdict = 'healthy';
}

usort($rows, function ($a, $b) {
    if ($a['age_days'] !== $b['age_days']) {
        return $b['age_days'] - $a['age_days'];
    }
    return strcasecmp($a['external_id'], $b['external_id']);
});

if ($action === 'detail') {
    $assignmentId = aagPositiveInt($_GET['assignment_id'] ?? null);
    if ($assignmentId <= 0) {
        aagFail(400, 'invalid_request');
    }
    $found = null;
    foreach ($rows as $r) {
        if ($r['id'] === $assignmentId) { $found = $r; break; }
    }
    if (is_null($found)) {
        aagFail(404, 'assignment_not_found');
    }
    $keys = array($found['tcversion_id'] . '|' . $found['platform_id']);
    if (intval($found['platform_id']) === 0) {
        foreach ($platformNames as $pid => $unused) {
            $keys[] = $found['tcversion_id'] . '|' . intval($pid);
        }
    }
    $events = array();
    $fullHistory = array();
    foreach ($keys as $key) {
        if (isset($history[$key])) {
            $fullHistory = array_merge($fullHistory, $history[$key]);
        }
    }
    usort($fullHistory, function ($a, $b) {
        return strcmp($a['execution_ts'], $b['execution_ts']);
    });
    $fullHistory = array_reverse($fullHistory);
    $events = array_slice($fullHistory, 0, 100);
    aagOut(array(
        'status' => 'ok',
        'code' => 200,
        'context' => $context,
        'assignment' => $found,
        'history' => $events,
        'history_truncated' => count($fullHistory) > 100,
    ));
}

aagOut(array(
    'status' => 'ok',
    'code' => 200,
    'context' => $context,
    'filters' => $filters,
    'filter' => $filterEcho,
    'summary' => $summary,
    'verdict' => $verdict,
    'rows' => $rows,
));
