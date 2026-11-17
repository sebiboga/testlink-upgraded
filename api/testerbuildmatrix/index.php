<?php
/**
 * Test Plan Tester x Build Execution Matrix report - BFF (Refs #1997).
 *
 * Net-new read-only report. 1.9.20 reports either a whole plan on one build,
 * one tester's workload across builds or a suite/build cross-tab; none shows
 * every tester against every build at a glance.
 *
 * Semantics
 *   - the universe is the set of version/platform links assigned to the plan
 *     in testplan_tcversions;
 *   - a link belongs to a tester when it has an active testcase_execution
 *     assignment (user_assignments.type = 1, status = 1) with user_id > 0 and
 *     build_id = 0 (any build) or build_id = the column build; links without
 *     such an assignment belong to the synthetic "unassigned" row;
 *   - a build column is every build of the owning test project;
 *   - the current status of a link in a build is its most recent execution on
 *     that build (MAX(executions.id)), matching the other 2.0.1 reports;
 *   - a link assigned but never executed in a build counts as NOT RUN.
 *
 * Actions
 *   GET|HEAD ?action=projects
 *   GET|HEAD ?action=init&tproject_id=N[&tplan_id=P]
 *   GET|HEAD ?action=report&tplan_id=N[&tproject_id=P][&build_id=B][&platform_id=P]
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

function tbmOut($payload, $code = null)
{
    if ($code !== null) {
        http_response_code($code);
    }
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function tbmFail($httpCode, $code, $message = '')
{
    tbmOut(array('status' => 'error', 'code' => $code,
                 'message' => $message !== '' ? $message : $code), $httpCode);
}

function tbmPositiveInt($raw)
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

function tbmDecode($value)
{
    return html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function tbmStatusKey($raw)
{
    switch ((string)$raw) {
        case 'p': return 'passed';
        case 'f': return 'failed';
        case 'b': return 'blocked';
        default:  return 'not_run';
    }
}

function tbmPct($num, $den)
{
    return $den > 0 ? round(100.0 * $num / $den, 1) : 0.0;
}

function tbmEmptyCounts()
{
    return array('assigned' => 0, 'executed' => 0, 'passed' => 0,
                 'failed' => 0, 'blocked' => 0, 'not_run' => 0);
}

function tbmFinish(&$counts)
{
    $counts['pass_rate'] = tbmPct($counts['passed'], $counts['executed']);
    $counts['coverage'] = tbmPct($counts['executed'], $counts['assigned']);
    return $counts;
}

function tbmAccumulate(&$totals, $cell)
{
    foreach (array('assigned', 'executed', 'passed', 'failed', 'blocked', 'not_run') as $k) {
        $totals[$k] += intval($cell[$k]);
    }
}

function tbmCanViewProject($user, $db, $tpid)
{
    if (intval($tpid) <= 0) {
        return false;
    }
    foreach (array('mgt_view_tc', 'mgt_modify_tc', 'testplan_metrics') as $right) {
        if ($user->hasRight($db, $right, $tpid) === 'yes') {
            return true;
        }
    }
    return false;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    tbmFail(405, 'method_not_allowed');
}

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    tbmFail(401, 'not_authenticated', 'Not authenticated');
}

$db = new database(DB_TYPE);
doDBConnect($db);
bffEnforceSession($db);

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    tbmFail(401, 'not_authenticated', 'User not found');
}

if (is_array($_GET['action'] ?? null)) {
    tbmFail(400, 'invalid_parameter', 'Invalid action');
}
$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if (!in_array($action, array('projects', 'init', 'report'), true)) {
    tbmFail(400, 'unknown_action', 'Unknown action');
}

$tbl = tlObjectWithDB::getDBTables(array(
    'testplans', 'testprojects', 'nodes_hierarchy', 'builds', 'platforms',
    'testplan_tcversions', 'executions', 'user_assignments', 'users',
));

$assertedProjectId = tbmPositiveInt($_GET['tproject_id'] ?? null);
$requestedPlanId = tbmPositiveInt($_GET['tplan_id'] ?? null);
$platformFilterId = tbmPositiveInt($_GET['platform_id'] ?? null);

if ($action === 'projects') {
    $rows = (array)$db->get_recordset(
        "SELECT p.id AS id, p.prefix AS prefix, nh.name AS name " .
        "FROM {$tbl['testprojects']} p " .
        "INNER JOIN {$tbl['nodes_hierarchy']} nh ON nh.id = p.id AND nh.node_type_id = 1 " .
        "ORDER BY nh.name ASC, p.id ASC");
    $projects = array();
    foreach ($rows as $r) {
        $pid = intval($r['id']);
        if (!tbmCanViewProject($user, $db, $pid)) {
            continue;
        }
        $projects[] = array('id' => $pid, 'name' => tbmDecode($r['name']),
                            'prefix' => (string)$r['prefix']);
    }
    tbmOut(array('status' => 'ok', 'code' => 'ok', 'projects' => $projects));
}

if ($action === 'init') {
    $tpid = $assertedProjectId > 0 ? $assertedProjectId
                                   : intval($_SESSION['testprojectID'] ?? 0);
    if ($tpid <= 0) {
        tbmFail(400, 'invalid_request', 'Missing or invalid tproject_id');
    }
    if (!tbmCanViewProject($user, $db, $tpid)) {
        tLog('BFF testerbuildmatrix: user ' . intval($userId) .
             ' has no read right on tproject ' . $tpid, 'AUDIT');
        tbmFail(403, 'no_right', 'Read right required on the test project');
    }
    $projectRow = (array)$db->get_recordset(
        "SELECT p.id AS id, p.prefix AS prefix, nh.name AS name " .
        "FROM {$tbl['testprojects']} p " .
        "INNER JOIN {$tbl['nodes_hierarchy']} nh ON nh.id = p.id AND nh.node_type_id = 1 " .
        "WHERE p.id = " . $tpid);
    if (count($projectRow) === 0) {
        tbmFail(404, 'tproject_not_found', 'Test project not found');
    }
    $planRows = (array)$db->get_recordset(
        "SELECT tp.id AS id, tp.active AS active, tp.is_open AS is_open, nh.name AS name " .
        "FROM {$tbl['testplans']} tp " .
        "LEFT JOIN {$tbl['nodes_hierarchy']} nh ON nh.id = tp.id " .
        "WHERE tp.testproject_id = " . $tpid . " ORDER BY nh.name ASC, tp.id ASC");
    $plans = array();
    foreach ($planRows as $r) {
        $plans[] = array('id' => intval($r['id']), 'name' => tbmDecode($r['name']),
                         'active' => intval($r['active']) ? true : false,
                         'is_open' => intval($r['is_open']) ? true : false);
    }
    $buildRows = (array)$db->get_recordset(
        "SELECT id, name, active, is_open FROM {$tbl['builds']} " .
        "WHERE testproject_id = " . $tpid . " ORDER BY id ASC");
    $builds = array();
    foreach ($buildRows as $r) {
        $builds[] = array('id' => intval($r['id']), 'name' => tbmDecode($r['name']),
                          'active' => intval($r['active']) ? true : false,
                          'is_open' => intval($r['is_open']) ? true : false);
    }
    $platformRows = (array)$db->get_recordset(
        "SELECT id, name FROM {$tbl['platforms']} WHERE testproject_id = " . $tpid .
        " ORDER BY name ASC, id ASC");
    $platforms = array();
    foreach ($platformRows as $r) {
        $platforms[] = array('id' => intval($r['id']), 'name' => tbmDecode($r['name']));
    }
    tbmOut(array(
        'status' => 'ok', 'code' => 'ok',
        'context' => array('tproject_id' => $tpid,
                           'tproject_name' => tbmDecode($projectRow[0]['name']),
                           'tproject_prefix' => (string)$projectRow[0]['prefix'],
                           'tplan_id' => $requestedPlanId),
        'plans' => $plans,
        'filters' => array('builds' => $builds, 'platforms' => $platforms),
    ));
}

if ($requestedPlanId <= 0) {
    tbmFail(400, 'invalid_request', 'Missing or invalid tplan_id');
}
$planRow = (array)$db->get_recordset(
    "SELECT id, testproject_id FROM {$tbl['testplans']} WHERE id = " . $requestedPlanId);
if (count($planRow) === 0) {
    tbmFail(404, 'tplan_not_found', 'Test plan not found');
}
$owningProjectId = intval($planRow[0]['testproject_id']);
if ($owningProjectId <= 0) {
    tbmFail(404, 'tplan_not_found', 'Test plan not found');
}
if (!$user->hasRight($db, 'testplan_metrics', $owningProjectId, $requestedPlanId, true)) {
    tLog('BFF testerbuildmatrix: user ' . intval($userId) .
         ' has no testplan_metrics right on tproject ' . $owningProjectId .
         ' (tplan ' . $requestedPlanId . ')', 'AUDIT');
    tbmFail(403, 'no_right', 'testplan_metrics right required');
}
if ($assertedProjectId > 0 && $assertedProjectId !== $owningProjectId) {
    tbmFail(404, 'project_mismatch', 'Test plan belongs to another test project');
}
$planName = testplan::getName($db, $requestedPlanId);
$projectName = testproject::getName($db, $owningProjectId);
if (is_null($planName) || trim((string)$planName) === '') {
    tbmFail(404, 'tplan_not_found', 'Test plan not found');
}

$buildRows = (array)$db->get_recordset(
    "SELECT id, name, active, is_open FROM {$tbl['builds']} " .
    "WHERE testproject_id = " . $owningProjectId . " ORDER BY id ASC");
$buildMeta = array();
foreach ($buildRows as $r) {
    $bid = intval($r['id']);
    $buildMeta[$bid] = array('id' => $bid, 'name' => tbmDecode($r['name']),
                             'active' => intval($r['active']) ? true : false,
                             'is_open' => intval($r['is_open']) ? true : false);
}
$buildFilterId = tbmPositiveInt($_GET['build_id'] ?? null);
if ($buildFilterId > 0 && !isset($buildMeta[$buildFilterId])) {
    tbmFail(400, 'invalid_request', 'build_id does not belong to this test project');
}
$platformRows = (array)$db->get_recordset(
    "SELECT id, name FROM {$tbl['platforms']} WHERE testproject_id = " . $owningProjectId .
    " ORDER BY name ASC, id ASC");
$platformNames = array();
$platforms = array();
foreach ($platformRows as $r) {
    $pid = intval($r['id']);
    $platformNames[$pid] = tbmDecode($r['name']);
    $platforms[] = array('id' => $pid, 'name' => $platformNames[$pid]);
}
if ($platformFilterId > 0 && !isset($platformNames[$platformFilterId])) {
    tbmFail(400, 'invalid_request', 'platform_id does not belong to this test project');
}

$buildIds = $buildFilterId > 0 ? array($buildFilterId) : array_keys($buildMeta);
$buildIds = array_map('intval', $buildIds);

$linkSql =
    "SELECT tpt.id AS link_id, tpt.tcversion_id, tpt.platform_id " .
    "FROM {$tbl['testplan_tcversions']} tpt " .
    "WHERE tpt.testplan_id = " . $requestedPlanId;
if ($platformFilterId > 0) {
    $linkSql .= " AND (tpt.platform_id = 0 OR tpt.platform_id = " . $platformFilterId . ")";
}
$links = array();
foreach ((array)$db->get_recordset($linkSql) as $r) {
    $lid = intval($r['link_id']);
    $links[$lid] = array('tcversion_id' => intval($r['tcversion_id']),
                         'platform_id' => intval($r['platform_id']));
}

$assignSql =
    "SELECT ua.user_id, ua.build_id, ua.feature_id " .
    "FROM {$tbl['user_assignments']} ua " .
    "JOIN {$tbl['testplan_tcversions']} tpt ON tpt.id = ua.feature_id " .
    "WHERE ua.type = 1 AND ua.status NOT IN (2, 3) AND ua.user_id > 0 " .
    "AND tpt.testplan_id = " . $requestedPlanId;
$assignment = array();
foreach ((array)$db->get_recordset($assignSql) as $r) {
    $fid = intval($r['feature_id']);
    if (!isset($links[$fid])) {
        continue;
    }
    $assignment[$fid][intval($r['user_id'])][intval($r['build_id'])] = true;
}

$userIds = array();
foreach ($assignment as $byUser) {
    foreach ($byUser as $uid => $ignore) {
        $userIds[intval($uid)] = true;
    }
}
$userNames = array();
if (count($userIds) > 0) {
    $ids = implode(',', array_map('intval', array_keys($userIds)));
    foreach ((array)$db->get_recordset(
        "SELECT id, login, first, last FROM {$tbl['users']} WHERE id IN ({$ids})") as $r) {
        $full = trim(tbmDecode($r['first']) . ' ' . tbmDecode($r['last']));
        $userNames[intval($r['id'])] = $full !== '' ? $full : tbmDecode($r['login']);
    }
}
foreach (array_keys($userIds) as $uid) {
    if (!isset($userNames[$uid])) {
        $userNames[$uid] = '#' . $uid;
    }
}

$statuses = array();
if (count($buildIds) > 0) {
    $statusSql =
        "SELECT e.build_id, e.platform_id, e.tcversion_id, e.status " .
        "FROM {$tbl['executions']} e " .
        "INNER JOIN (SELECT build_id, platform_id, tcversion_id, MAX(id) AS mid " .
        "FROM {$tbl['executions']} " .
        "WHERE testplan_id = " . $requestedPlanId .
        " AND build_id IN (" . implode(',', $buildIds) . ") " .
        "GROUP BY build_id, platform_id, tcversion_id) x ON x.mid = e.id";
    foreach ((array)$db->get_recordset($statusSql) as $r) {
        $key = intval($r['build_id']) . ':' . intval($r['tcversion_id']) . ':' .
               intval($r['platform_id']);
        $statuses[$key] = tbmStatusKey($r['status']);
    }
}

$rows = array();
foreach ($userNames as $uid => $name) {
    $rows[] = array('id' => intval($uid), 'user_id' => intval($uid),
                    'name' => $name, 'unassigned' => false);
}
$rows[] = array('id' => 0, 'user_id' => 0,
                'name' => '__UNASSIGNED__', 'unassigned' => true);
usort($rows, function ($a, $b) {
    if ($a['unassigned'] !== $b['unassigned']) {
        return $a['unassigned'] ? 1 : -1;
    }
    $c = strcasecmp($a['name'], $b['name']);
    return $c !== 0 ? $c : intval($a['id']) - intval($b['id']);
});

$cells = array();
$rowTotals = array();
$buildTotals = array();
foreach ($rows as $r) {
    $rowTotals[$r['id']] = tbmEmptyCounts();
}
foreach ($buildIds as $bid) {
    $buildTotals[$bid] = tbmEmptyCounts();
}
$overall = tbmEmptyCounts();

foreach ($rows as $r) {
    $rid = $r['id'];
    foreach ($buildIds as $bid) {
        $counts = tbmEmptyCounts();
        foreach ($links as $lid => $link) {
            $byUser = isset($assignment[$lid]) ? $assignment[$lid] : array();
            $mine = false;
            if ($r['unassigned']) {
                $mine = count($byUser) === 0;
            } elseif (isset($byUser[$rid])) {
                $mine = isset($byUser[$rid][0]) || isset($byUser[$rid][$bid]);
            }
            if (!$mine) {
                continue;
            }
            $counts['assigned']++;
            $lpid = intval($link['platform_id']);
            $candidates = array($bid . ':' . $link['tcversion_id'] . ':' . $lpid);
            if ($lpid > 0) {
                $candidates[] = $bid . ':' . $link['tcversion_id'] . ':0';
            } else {
                foreach ($platformNames as $pid => $ignore) {
                    $candidates[] = $bid . ':' . $link['tcversion_id'] . ':' . intval($pid);
                }
            }
            $status = 'not_run';
            foreach ($candidates as $key) {
                if (isset($statuses[$key])) {
                    $status = $statuses[$key];
                    break;
                }
            }
            if ($status !== 'not_run') {
                $counts['executed']++;
                if (isset($counts[$status])) {
                    $counts[$status]++;
                }
            } else {
                $counts['not_run']++;
            }
        }
        tbmFinish($counts);
        $counts['user_id'] = $rid;
        $counts['build_id'] = $bid;
        $cells[] = $counts;
        tbmAccumulate($rowTotals[$rid], $counts);
        tbmAccumulate($buildTotals[$bid], $counts);
        tbmAccumulate($overall, $counts);
    }
}

$rowsOut = array();
foreach ($rows as $r) {
    $t = tbmFinish($rowTotals[$r['id']]);
    $t['id'] = $r['id'];
    $t['name'] = $r['name'];
    $t['unassigned'] = $r['unassigned'];
    $rowsOut[] = $t;
}
$buildsOut = array();
foreach ($buildIds as $bid) {
    $t = tbmFinish($buildTotals[$bid]);
    $t['id'] = $bid;
    $t['name'] = $buildMeta[$bid]['name'];
    $t['active'] = $buildMeta[$bid]['active'];
    $t['is_open'] = $buildMeta[$bid]['is_open'];
    $buildsOut[] = $t;
}
$summary = tbmFinish($overall);
$summary['builds'] = count($buildIds);
$summary['testers'] = count($rows) - 1;
$summary['links'] = count($links);
$summary['unassigned_links'] = 0;
foreach ($links as $lid => $ignore) {
    if (!isset($assignment[$lid]) || count($assignment[$lid]) === 0) {
        $summary['unassigned_links']++;
    }
}
if ($summary['assigned'] === 0) {
    $summary['verdict'] = 'no_data';
} elseif ($summary['executed'] === 0) {
    $summary['verdict'] = 'not_started';
} elseif ($summary['not_run'] === 0) {
    $summary['verdict'] = 'complete';
} else {
    $summary['verdict'] = 'in_progress';
}

tbmOut(array(
    'status' => 'ok', 'code' => 'ok',
    'context' => array('tproject_id' => $owningProjectId,
                       'tproject_name' => tbmDecode($projectName),
                       'tplan_id' => $requestedPlanId,
                       'tplan_name' => tbmDecode($planName),
                       'build_id' => $buildFilterId,
                       'platform_id' => $platformFilterId,
                       'platform_name' => $platformFilterId > 0
                           ? $platformNames[$platformFilterId] : null),
    'filters' => array('builds' => array_values($buildMeta),
                       'platforms' => array_values($platforms)),
    'summary' => $summary,
    'testers' => $rowsOut,
    'builds' => $buildsOut,
    'cells' => $cells,
));
