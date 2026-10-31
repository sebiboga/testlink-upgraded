<?php
/**
 * Test Plan Tester Workload report - BFF (Refs #1977, #1073).
 *
 * Net-new read-only modern screen (gui/templates/results/testerWorkload.html).
 * TestLink 1.9.20 can list the test cases assigned to ONE user for ONE build
 * (tcAssignedToUser) and the results of one build grouped per tester
 * (resultsByTesterPerBuild), but it never shows the plan-wide WORKLOAD: how
 * much is assigned to each tester, how much is already executed, how much is
 * still open, overdue, or done by somebody who was never assigned.
 *
 * Semantics:
 *   - an ASSIGNMENT is a row of `user_assignments` with type = 1
 *     (fk_table = testplan_tcversions) whose feature_id is a
 *     `testplan_tcversions` row of the addressed plan.
 *   - `user_assignments.build_id = 0` means "any build of the plan"; a positive
 *     value pins the assignment to that build.
 *   - an assignment is DONE when at least one execution exists for the same
 *     (tcversion, platform) on the assignment build (or on any build when the
 *     assignment has no build), inside the selected build/platform scope.
 *   - the "performed" counters count executions BY that tester (executions
 *     .tester_id), independent of who was assigned.
 *   - a tester row exists when the user appears as an assignee OR executed
 *     something in scope, so unassigned "helper" testers are visible too.
 *
 * Actions
 *   GET|HEAD ?action=init&tplan_id=N[&tproject_id=P]
 *       -> context (plan / project) + build and platform filters
 *   GET|HEAD ?action=report&tplan_id=N[&tproject_id=P][&build_id=B][&platform_id=PL]
 *       -> summary KPIs + one row per tester (+ an "unassigned" row)
 *
 * Security: session authentication, the shared BFF same-origin guard and the
 * `testplan_metrics` right on the OWNING test project (getAccess = true). The
 * client supplied tproject_id is only an ASSERTION: it is compared with the
 * plan's owning project and never used to widen a query.
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

/** Single JSON exit point. */
function twOut($payload, $code = null)
{
    if ($code !== null) {
        http_response_code($code);
    }
    echo json_encode($payload);
    exit;
}

function twFail($httpCode, $code, $message = '')
{
    twOut(array(
        'status' => 'error',
        'code' => $code,
        'message' => $message !== '' ? $message : $code,
    ), $httpCode);
}

/** Strict positive integer reader - "1abc", "1.9", "-3" and "" are all refused. */
function twPositiveInt($raw)
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

function twDecode($value)
{
    return html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function twRound($value, $decimals = 1)
{
    return round((float)$value, $decimals);
}

function twPct($part, $total)
{
    return ($total > 0) ? twRound(100.0 * $part / $total, 1) : 0.0;
}

function twStatus($letter)
{
    switch ((string)$letter) {
        case 'p': return 'passed';
        case 'f': return 'failed';
        case 'b': return 'blocked';
    }
    return 'not_run';
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    twFail(405, 'method_not_allowed');
}

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    twFail(401, 'not_authenticated', 'Not authenticated');
}

bffEnforceSession($db);

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    twFail(401, 'not_authenticated', 'User not found');
}

$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if ($action !== 'init' && $action !== 'report') {
    twFail(400, 'invalid_request', 'Unknown action');
}

$tplanId = twPositiveInt($_GET['tplan_id'] ?? null);
$assertedProjectId = twPositiveInt($_GET['tproject_id'] ?? null);
$buildId = twPositiveInt($_GET['build_id'] ?? null);
$platformId = twPositiveInt($_GET['platform_id'] ?? null);

if ($tplanId <= 0) {
    twFail(400, 'invalid_request', 'Missing or invalid tplan_id');
}

// Order of operations (same as api/execduration, api/flakytests):
//   1. resolve the plan -> opaque 404 when it does not exist or has no owner
//   2. check the right on the OWNING project (getAccess = true)
//   3. only then honour the client's tproject_id assertion
$tbl = tlObjectWithDB::getDBTables(array(
    'testplans', 'builds', 'platforms', 'testplan_tcversions', 'tcversions',
    'nodes_hierarchy', 'executions', 'testprojects', 'user_assignments', 'users',
));
$planRow = $db->get_recordset(
    "SELECT id, testproject_id FROM {$tbl['testplans']} WHERE id = " . intval($tplanId));

if (!is_array($planRow) || count($planRow) === 0) {
    twFail(404, 'tplan_not_found', 'Test plan not found');
}

$plan = $planRow[0];
$owningProjectId = intval($plan['testproject_id']);
if ($owningProjectId <= 0) {
    twFail(404, 'tplan_not_found', 'Test plan has no owning test project');
}

if (!$user->hasRight($db, 'testplan_metrics', $owningProjectId, $tplanId, true)) {
    tLog('BFF testerworkload: user ' . intval($userId) .
         ' has no testplan_metrics right on tproject ' . $owningProjectId .
         ' (tplan ' . $tplanId . ')', 'ERROR');
    twFail(403, 'no_right', 'testplan_metrics right required');
}

if ($assertedProjectId > 0 && $assertedProjectId !== $owningProjectId) {
    twFail(404, 'project_mismatch', 'Test plan belongs to another test project');
}

$planName = testplan::getName($db, $tplanId);
$projectName = testproject::getName($db, $owningProjectId);
if (is_null($planName) || trim((string)$planName) === '') {
    twFail(404, 'tplan_not_found', 'Test plan not found');
}

// ---------------------------------------------------------------------------
// Filters: builds / platforms belonging to the OWNING project.
// ---------------------------------------------------------------------------
$builds = array();
$rs = $db->get_recordset(
    "SELECT id, name FROM {$tbl['builds']} WHERE testproject_id = " . intval($owningProjectId) .
    " AND active = 1 ORDER BY id");
if (is_array($rs)) {
    foreach ($rs as $r) {
        $builds[] = array('id' => intval($r['id']), 'name' => twDecode($r['name']));
    }
}
if ($buildId > 0) {
    $known = false;
    foreach ($builds as $b) {
        if ($b['id'] === $buildId) { $known = true; break; }
    }
    if (!$known) {
        twFail(400, 'invalid_request', 'build_id does not belong to this test project');
    }
}

$platforms = array();
$rp = $db->get_recordset(
    "SELECT id, name FROM {$tbl['platforms']} WHERE testproject_id = " . intval($owningProjectId) .
    " ORDER BY name");
if (is_array($rp)) {
    foreach ($rp as $r) {
        $platforms[] = array('id' => intval($r['id']), 'name' => twDecode($r['name']));
    }
}
if ($platformId > 0) {
    $known = false;
    foreach ($platforms as $p) {
        if ($p['id'] === $platformId) { $known = true; break; }
    }
    if (!$known) {
        twFail(400, 'invalid_request', 'platform_id does not belong to this test project');
    }
}

$context = array(
    'tplan_id' => $tplanId,
    'tplan_name' => (string)$planName,
    'tproject_id' => $owningProjectId,
    'tproject_name' => (string)$projectName,
);
$filters = array('builds' => $builds, 'platforms' => $platforms);
$filterEcho = array('build_id' => $buildId, 'platform_id' => $platformId);

if ($action === 'init') {
    twOut(array(
        'status' => 'ok',
        'code' => 200,
        'context' => $context,
        'filters' => $filters,
        'filter' => $filterEcho,
    ));
}

// ---------------------------------------------------------------------------
// report
// ---------------------------------------------------------------------------
$now = time();

$assignSql =
    "SELECT ua.id, ua.user_id, ua.build_id, ua.deadline_ts, ua.status AS assign_status, " .
    "       tpt.id AS tpt_id, tpt.tcversion_id, tpt.platform_id " .
    "FROM {$tbl['user_assignments']} ua " .
    "JOIN {$tbl['testplan_tcversions']} tpt ON tpt.id = ua.feature_id " .
    "WHERE ua.type = 1 AND tpt.testplan_id = " . intval($tplanId);
if ($platformId > 0) {
    $assignSql .= " AND tpt.platform_id = " . intval($platformId);
}
if ($buildId > 0) {
    $assignSql .= " AND (ua.build_id = 0 OR ua.build_id = " . intval($buildId) . ")";
}
$assignSql .= " ORDER BY ua.user_id, ua.id";
$assignRs = $db->get_recordset($assignSql);

$perUser = array();
$summary = array(
    'testers' => 0,
    'assignments' => 0,
    'done' => 0,
    'remaining' => 0,
    'overdue' => 0,
    'executions' => 0,
    'active_testers' => 0,
    'avg_assignments' => 0.0,
    'completion_pct' => 0.0,
    'passed' => 0,
    'failed' => 0,
    'blocked' => 0,
    'not_run' => 0,
);

$assignExecs = array();
if (is_array($assignRs)) {
    foreach ($assignRs as $a) {
        $uid = intval($a['user_id']);
        if ($uid <= 0) {
            continue;
        }
        if (!isset($perUser[$uid])) {
            $perUser[$uid] = array(
                'assigned' => 0, 'done' => 0, 'open' => 0, 'overdue' => 0,
                'executions' => 0, 'passed' => 0, 'failed' => 0,
                'blocked' => 0, 'not_run' => 0, 'last_ts' => '', 'next_deadline' => '',
            );
        }
        $perUser[$uid]['assigned']++;
        $summary['assignments']++;

        $tcv = intval($a['tcversion_id']);
        $plat = intval($a['platform_id']);
        $abuild = intval($a['build_id']);
        $execWhere =
            "e.testplan_id = " . intval($tplanId) .
            " AND e.tcversion_id = " . $tcv .
            " AND e.platform_id = " . $plat;
        if ($abuild > 0) {
            $execWhere .= " AND e.build_id = " . $abuild;
        }
        if ($buildId > 0 && $abuild === 0) {
            $execWhere .= " AND e.build_id = " . intval($buildId);
        }
        if (!isset($assignExecs[$execWhere])) {
            $cntRs = $db->get_recordset(
                "SELECT COUNT(*) AS c FROM {$tbl['executions']} e WHERE " . $execWhere);
            $assignExecs[$execWhere] = (is_array($cntRs) && count($cntRs) > 0)
                ? intval($cntRs[0]['c']) : 0;
        }
        $execCount = $assignExecs[$execWhere];

        $deadline = (string)($a['deadline_ts'] ?? '');
        $isOpen = (intval($a['assign_status'] ?? 1) !== 2 && intval($a['assign_status'] ?? 1) !== 3);
        if ($execCount > 0) {
            $perUser[$uid]['done']++;
            $summary['done']++;
        } else {
            $perUser[$uid]['open']++;
            $summary['remaining']++;
            if ($deadline !== '' && strtotime($deadline) !== false && strtotime($deadline) < $now) {
                $perUser[$uid]['overdue']++;
                $summary['overdue']++;
            }
        }
        if ($isOpen && $deadline !== '') {
            $dl = strtotime($deadline);
            if ($dl !== false && ($perUser[$uid]['next_deadline'] === '' ||
                                  $dl < strtotime($perUser[$uid]['next_deadline']))) {
                $perUser[$uid]['next_deadline'] = $deadline;
            }
        }
    }
}

// Executions IN scope (performed per tester).
$execSql =
    "SELECT e.tester_id, e.status, e.execution_ts, e.tcversion_id, e.build_id, e.platform_id " .
    "FROM {$tbl['executions']} e WHERE e.testplan_id = " . intval($tplanId) .
    " AND e.tester_id IS NOT NULL AND e.tester_id > 0" .
    " AND e.tcversion_id IN (SELECT tpt.tcversion_id FROM {$tbl['testplan_tcversions']} tpt" .
    " WHERE tpt.testplan_id = " . intval($tplanId) . ")";
if ($buildId > 0) {
    $execSql .= " AND e.build_id = " . intval($buildId);
}
if ($platformId > 0) {
    $execSql .= " AND e.platform_id = " . intval($platformId);
}
$execSql .= " ORDER BY e.execution_ts, e.id";
$execRs = $db->get_recordset($execSql);

if (is_array($execRs)) {
    foreach ($execRs as $e) {
        $uid = intval($e['tester_id']);
        if ($uid <= 0) {
            continue;
        }
        if (!isset($perUser[$uid])) {
            $perUser[$uid] = array(
                'assigned' => 0, 'done' => 0, 'open' => 0, 'overdue' => 0,
                'executions' => 0, 'passed' => 0, 'failed' => 0,
                'blocked' => 0, 'not_run' => 0, 'last_ts' => '', 'next_deadline' => '',
            );
        }
        $st = twStatus($e['status']);
        $perUser[$uid]['executions']++;
        $perUser[$uid][$st]++;
        $perUser[$uid]['last_ts'] = (string)$e['execution_ts'];
        $summary['executions']++;
        $summary[$st]++;
    }
}

// User names.
$userIds = array_map('intval', array_keys($perUser));
$names = array();
if (count($userIds) > 0) {
    $inList = implode(',', $userIds);
    $uRs = $db->get_recordset(
        "SELECT id, login, first, last FROM {$tbl['users']} WHERE id IN (" . $inList . ")");
    if (is_array($uRs)) {
        foreach ($uRs as $r) {
            $display = trim(twDecode(trim((string)$r['first']) . ' ' . trim((string)$r['last'])));
            if ($display === '') {
                $display = twDecode($r['login']);
            }
            $names[intval($r['id'])] = array(
                'display' => $display,
                'login' => twDecode($r['login']),
            );
        }
    }
}

$rows = array();
foreach ($perUser as $uid => $u) {
    $exec = $u['executions'];
    $verdict = 'in_progress';
    if ($u['assigned'] === 0) {
        $verdict = 'ad_hoc';
    } elseif ($u['open'] === 0) {
        $verdict = 'complete';
    } elseif ($u['overdue'] > 0) {
        $verdict = 'at_risk';
    }
    $rows[] = array(
        'user_id' => intval($uid),
        'display_name' => $names[$uid]['display'] ?? ('#' . $uid),
        'login' => $names[$uid]['login'] ?? '',
        'assigned' => $u['assigned'],
        'done' => $u['done'],
        'remaining' => $u['open'],
        'overdue' => $u['overdue'],
        'completion_pct' => twPct($u['done'], $u['assigned']),
        'executions' => $exec,
        'passed' => $u['passed'],
        'failed' => $u['failed'],
        'blocked' => $u['blocked'],
        'not_run' => $u['not_run'],
        'pass_rate' => twPct($u['passed'], $exec),
        'last_ts' => $u['last_ts'],
        'next_deadline' => $u['next_deadline'],
        'verdict' => $verdict,
    );
}

usort($rows, function ($a, $b) {
    if ($a['remaining'] === $b['remaining']) {
        if ($a['assigned'] === $b['assigned']) {
            return strcmp($a['display_name'], $b['display_name']);
        }
        return ($a['assigned'] < $b['assigned']) ? 1 : -1;
    }
    return ($a['remaining'] < $b['remaining']) ? 1 : -1;
});

$summary['testers'] = count($rows);
$summary['active_testers'] = 0;
foreach ($rows as $r) {
    if ($r['executions'] > 0) {
        $summary['active_testers']++;
    }
}
$summary['avg_assignments'] = $summary['testers'] > 0
    ? twRound($summary['assignments'] / $summary['testers'], 1) : 0.0;
$summary['completion_pct'] = twPct($summary['done'], $summary['assignments']);

twOut(array(
    'status' => 'ok',
    'code' => 200,
    'context' => $context,
    'filters' => $filters,
    'filter' => $filterEcho,
    'summary' => $summary,
    'rows' => $rows,
));
