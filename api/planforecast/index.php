<?php
/**
 * Test Plan Completion Forecast report - BFF (Refs #1983).
 *
 * Net-new read-only modern screen (gui/templates/results/planForecast.html).
 * TestLink 1.9.20 / 2.0.1 ship progress HISTORY (execBurnDown shows what
 * already happened) and point-in-time snapshots, but nothing answers the
 * manager question: WILL THIS PLAN FINISH BY ITS MILESTONE DEADLINE?
 *
 * Semantics:
 *   - the universe is the set of `testplan_tcversions` links of the addressed
 *     plan (one assigned tcversion per platform link; platform_id = 0 means
 *     "any platform" and matches every execution platform) - the same
 *     convention as the sibling plan reports (api/blockedageing, #1981).
 *   - a link is EXECUTED when at least one execution exists for the same
 *     (tcversion, platform) inside the plan and the selected build/platform
 *     scope (a platform_id = 0 link is executed on EVERY platform).
 *   - VELOCITY = how many links became executed for the FIRST time per day.
 *     Two figures: the RECENT velocity over a trailing window (default 14
 *     days, ?days=D, capped 1..90, measured on the DB clock) and the OVERALL
 *     velocity since the plan's first execution (>= 1 day).
 *   - ETA = today + ceil(remaining / recent velocity) in calendar days. When
 *     the recent window saw no new execution and work remains, the plan is
 *     STALLED and no ETA is offered.
 *   - the verdict compares the ETA with the plan's next upcoming milestone
 *     (target_date >= today): complete / on_track (ETA <= target) /
 *     at_risk (ETA <= target + 7d) / late (ETA beyond) / stalled /
 *     no_milestone (nothing upcoming to compare with).
 *
 * Actions
 *   GET|HEAD ?action=init&tplan_id=N[&tproject_id=P]
 *       -> context (plan / project) + build and platform filters
 *   GET|HEAD ?action=report&tplan_id=N[&tproject_id=P][&build_id=B]
 *               [&platform_id=PL][&days=D]
 *       -> summary KPIs + verdict + per-milestone projection rows + the
 *          daily first-execution activity series
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
function pfOut($payload, $code = null)
{
    if ($code !== null) {
        http_response_code($code);
    }
    // JSON_INVALID_UTF8_SUBSTITUTE: legacy rows can carry non-UTF-8 bytes
    // (notes pasted from old trackers) and a bare json_encode() would then
    // return false -> empty 200 body (code review, Refs #1981).
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function pfFail($httpCode, $code, $message = '')
{
    pfOut(array(
        'status' => 'error',
        'code' => $code,
        'message' => $message !== '' ? $message : $code,
    ), $httpCode);
}

/** Strict positive integer reader - "1abc", "1.9", "-3" and "" are all refused. */
function pfPositiveInt($raw)
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

function pfDecode($value)
{
    return html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function pfRound($value, $decimals = 1)
{
    return round((float)$value, $decimals);
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    pfFail(405, 'method_not_allowed');
}

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    pfFail(401, 'not_authenticated', 'Not authenticated');
}

bffEnforceSession($db);

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    pfFail(401, 'not_authenticated', 'User not found');
}

$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if ($action !== 'init' && $action !== 'report') {
    pfFail(400, 'invalid_request', 'Unknown action');
}

$tplanId = pfPositiveInt($_GET['tplan_id'] ?? null);
$assertedProjectId = pfPositiveInt($_GET['tproject_id'] ?? null);
$buildId = pfPositiveInt($_GET['build_id'] ?? null);
$platformId = pfPositiveInt($_GET['platform_id'] ?? null);

// Velocity window, default 14 days, capped 1..90 (a bigger window just makes
// the recent figure converge to the overall one).
$days = pfPositiveInt($_GET['days'] ?? null);
if ($days <= 0) {
    $days = 14;
}
if ($days > 90) {
    pfFail(400, 'invalid_request', 'days must be between 1 and 90');
}

if ($tplanId <= 0) {
    pfFail(400, 'invalid_request', 'Missing or invalid tplan_id');
}

// Order of operations (same as api/blockedageing, api/testerworkload):
//   1. resolve the plan -> opaque 404 when it does not exist or has no owner
//   2. check the right on the OWNING project (getAccess = true)
//   3. only then honour the client's tproject_id assertion
$tbl = tlObjectWithDB::getDBTables(array(
    'testplans', 'builds', 'platforms', 'testplan_tcversions', 'executions',
    'milestones',
));
$planRow = $db->get_recordset(
    "SELECT id, testproject_id FROM {$tbl['testplans']} WHERE id = " . intval($tplanId));

if (!is_array($planRow) || count($planRow) === 0) {
    pfFail(404, 'tplan_not_found', 'Test plan not found');
}

$plan = $planRow[0];
$owningProjectId = intval($plan['testproject_id']);
if ($owningProjectId <= 0) {
    pfFail(404, 'tplan_not_found', 'Test plan has no owning test project');
}

if (!$user->hasRight($db, 'testplan_metrics', $owningProjectId, $tplanId, true)) {
    tLog('BFF planforecast: user ' . intval($userId) .
         ' has no testplan_metrics right on tproject ' . $owningProjectId .
         ' (tplan ' . $tplanId . ')', 'ERROR');
    pfFail(403, 'no_right', 'testplan_metrics right required');
}

if ($assertedProjectId > 0 && $assertedProjectId !== $owningProjectId) {
    pfFail(404, 'project_mismatch', 'Test plan belongs to another test project');
}

$planName = testplan::getName($db, $tplanId);
$projectName = testproject::getName($db, $owningProjectId);
if (is_null($planName) || trim((string)$planName) === '') {
    pfFail(404, 'tplan_not_found', 'Test plan not found');
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
        $builds[] = array('id' => intval($r['id']), 'name' => pfDecode($r['name']));
    }
}
if ($buildId > 0) {
    $known = false;
    foreach ($builds as $b) {
        if ($b['id'] === $buildId) { $known = true; break; }
    }
    if (!$known) {
        pfFail(400, 'invalid_request', 'build_id does not belong to this test project');
    }
}

$platforms = array();
$rp = $db->get_recordset(
    "SELECT id, name FROM {$tbl['platforms']} WHERE testproject_id = " . intval($owningProjectId) .
    " ORDER BY name");
if (is_array($rp)) {
    foreach ($rp as $r) {
        $platforms[] = array('id' => intval($r['id']), 'name' => pfDecode($r['name']));
    }
}
if ($platformId > 0) {
    $known = false;
    foreach ($platforms as $p) {
        if ($p['id'] === $platformId) { $known = true; break; }
    }
    if (!$known) {
        pfFail(400, 'invalid_request', 'platform_id does not belong to this test project');
    }
}

$context = array(
    'tplan_id' => $tplanId,
    'tplan_name' => pfDecode((string)$planName),
    'tproject_id' => $owningProjectId,
    'tproject_name' => pfDecode((string)$projectName),
);
$filters = array('builds' => $builds, 'platforms' => $platforms);
$filterEcho = array('build_id' => $buildId, 'platform_id' => $platformId, 'days' => $days);

if ($action === 'init') {
    pfOut(array(
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
$nowRs = $db->get_recordset("SELECT DATE(NOW()) AS today");
$today = (is_array($nowRs) && count($nowRs) > 0) ? (string)$nowRs[0]['today'] : date('Y-m-d');

// Universe: the plan's assigned links (tcversion, platform), narrowed by the
// platform filter (the platform is part of the assignment in TestLink); a
// platform_id = 0 link matches every platform - sibling convention (#1981).
$versionSql =
    "SELECT tpt.tcversion_id, tpt.platform_id " .
    "FROM {$tbl['testplan_tcversions']} tpt " .
    "WHERE tpt.testplan_id = " . intval($tplanId);
if ($platformId > 0) {
    $versionSql .= " AND (tpt.platform_id = " . intval($platformId) .
                   " OR tpt.platform_id = 0)";
}
$versionRs = $db->get_recordset($versionSql);

$linkSet = array();
$zeroLink = array();
$assigned = 0;
if (is_array($versionRs)) {
    foreach ($versionRs as $v) {
        $tcv = intval($v['tcversion_id']);
        $pl = intval($v['platform_id']);
        $linkSet[$tcv . '/' . $pl] = true;
        if ($pl === 0) {
            $zeroLink[$tcv] = true;
        }
        $assigned++;
    }
}

// First execution day per (tcversion, platform) pair inside the scope. The
// platform semantics follow the siblings: a platform-scoped report counts an
// execution on the selected platform, and a platform-less link (0) is
// executed by an execution on ANY platform; a link pinned to platform X is
// only executed by an execution on platform X.
$execSql =
    "SELECT e.tcversion_id, e.platform_id, DATE(MIN(e.execution_ts)) AS first_day " .
    "FROM {$tbl['executions']} e " .
    "WHERE e.testplan_id = " . intval($tplanId);
if ($buildId > 0) {
    $execSql .= " AND e.build_id = " . intval($buildId);
}
if ($platformId > 0) {
    $execSql .= " AND (e.platform_id = " . intval($platformId) . " OR e.platform_id = 0)";
}
// ORDER BY first_day ASC: a platform-less link (0) can be proven executed by
// an execution on ANY platform, so the earliest candidate group must stamp
// the link first (first-wins must mean earliest-wins, code review #1983).
$execSql .= " AND e.execution_ts IS NOT NULL GROUP BY e.tcversion_id, e.platform_id"
          . " ORDER BY first_day ASC";
$execRs = $db->get_recordset($execSql);

// first-day counters + per-day raw execution volume (for the activity table).
$firstDayByLink = array();      // link -> first day (Y-m-d)
$firstDayCounters = array();    // day -> newly executed links
$dayExecCounts = array();        // day -> raw execution rows in scope
if (is_array($execRs)) {
    foreach ($execRs as $e) {
        $tcv = intval($e['tcversion_id']);
        $ep = intval($e['platform_id']);
        $day = (string)$e['first_day'];
        if ($day === '' || $day === '0000-00-00') {
            continue;
        }
        // Map the execution back onto the link it proves executed.
        $candidates = array($tcv . '/' . $ep);
        if (isset($zeroLink[$tcv])) {
            $candidates[] = $tcv . '/0';
        }
        foreach ($candidates as $linkKey) {
            if (isset($linkSet[$linkKey]) && !isset($firstDayByLink[$linkKey])) {
                $firstDayByLink[$linkKey] = $day;
                $firstDayCounters[$day] = ($firstDayCounters[$day] ?? 0) + 1;
            }
        }
    }
}

// Raw per-day execution volume (all rows in scope, regardless of link state).
$daySql =
    "SELECT DATE(e.execution_ts) AS day, COUNT(*) AS cnt " .
    "FROM {$tbl['executions']} e " .
    "WHERE e.testplan_id = " . intval($tplanId);
if ($buildId > 0) {
    $daySql .= " AND e.build_id = " . intval($buildId);
}
if ($platformId > 0) {
    $daySql .= " AND (e.platform_id = " . intval($platformId) . " OR e.platform_id = 0)";
}
$daySql .= " AND e.execution_ts IS NOT NULL GROUP BY DATE(e.execution_ts)";
$dayRs = $db->get_recordset($daySql);
if (is_array($dayRs)) {
    foreach ($dayRs as $r) {
        $d = (string)$r['day'];
        if ($d !== '' && $d !== '0000-00-00') {
            $dayExecCounts[$d] = intval($r['cnt']);
        }
    }
}

// Executed links = links proven above (the link must still exist in the
// plan). The overall-velocity span starts at the earliest MAPPED first day,
// so executions of since-unlinked versions cannot deflate it (#1983 review).
$executedLinks = array();
foreach ($firstDayByLink as $linkKey => $day) {
    if (isset($linkSet[$linkKey])) {
        $executedLinks[$linkKey] = $day;
    }
}
$executed = count($executedLinks);
$earliestDay = ($executed > 0) ? min($executedLinks) : null;
$remaining = max(0, $assigned - $executed);
$progressPct = ($assigned > 0) ? pfRound(100.0 * $executed / $assigned, 1) : 0.0;

// Recent velocity: newly executed links whose first day falls inside the
// trailing window [today - days + 1, today] on the DB clock.
$windowStart = date('Y-m-d', strtotime($today . ' -' . (intval($days) - 1) . ' day'));
$recentNew = 0;
foreach ($executedLinks as $day) {
    if ($day >= $windowStart && $day <= $today) {
        $recentNew++;
    }
}
$recentVelocity = pfRound($recentNew / max(1, $days), 2);

// Overall velocity: executed links / elapsed days since the plan's first
// execution (at least 1 day so a same-day burst does not divide by zero).
$overallVelocity = 0.0;
if ($earliestDay !== null && $executed > 0) {
    $span = floor((strtotime($today) - strtotime($earliestDay)) / 86400) + 1;
    $overallVelocity = pfRound($executed / max(1, $span), 2);
}

// ETA on the recent velocity (the honest forward-looking figure). Calendar
// days; a stalled recent window (velocity 0 with work left) refuses to guess.
// The ETA divides by the UNROUNDED velocity - rounding it first inflates the
// ETA at low velocities (code review #1983).
$etaDays = null;
$etaDate = null;
if ($remaining > 0 && $recentNew > 0) {
    $etaDays = (int)ceil($remaining * max(1, $days) / $recentNew);
    $etaDate = date('Y-m-d', strtotime($today . ' +' . $etaDays . ' day'));
}

// Milestones of the plan (legacy `milestones`, a/b/c target percentages kept
// for context). Next upcoming = smallest target_date >= today.
$mileRs = $db->get_recordset(
    "SELECT id, name, target_date, a, b, c FROM {$tbl['milestones']} " .
    "WHERE testplan_id = " . intval($tplanId) . " ORDER BY target_date, id");
$milestones = array();
$nextMilestone = null;
if (is_array($mileRs)) {
    foreach ($mileRs as $m) {
        $row = array(
            'id' => intval($m['id']),
            'name' => pfDecode($m['name']),
            'target_date' => (string)$m['target_date'],
            'a' => intval($m['a']),
            'b' => intval($m['b']),
            'c' => intval($m['c']),
        );
        $t = $row['target_date'];
        $daysLeft = (int)floor((strtotime($t) - strtotime($today)) / 86400);
        $row['days_left'] = $daysLeft;
        // Required daily velocity to be finished exactly at the target date.
        $row['required_velocity'] = ($daysLeft > 0 && $remaining > 0)
            ? pfRound($remaining / $daysLeft, 2) : null;
        // Projected completion date when an ETA exists.
        $row['projected_date'] = $etaDate;
        if ($remaining <= 0) {
            $row['verdict'] = 'complete';
        } elseif ($daysLeft < 0) {
            $row['verdict'] = 'past_due';
        } elseif ($etaDate === null) {
            $row['verdict'] = 'stalled';
        } elseif ($etaDate <= $t) {
            $row['verdict'] = 'on_track';
        } elseif (strtotime($etaDate) <= strtotime($t) + 7 * 86400) {
            $row['verdict'] = 'at_risk';
        } else {
            $row['verdict'] = 'late';
        }
        $milestones[] = $row;
        if ($t >= $today && is_null($nextMilestone)) {
            $nextMilestone = $row;
        }
    }
}

// Overall verdict (the manager's one-word answer).
if ($assigned <= 0) {
    $verdict = 'no_data';
} elseif ($remaining <= 0) {
    $verdict = 'complete';
} elseif ($recentVelocity <= 0) {
    $verdict = 'stalled';
} elseif (is_null($nextMilestone)) {
    $verdict = 'no_milestone';
} else {
    $verdict = $nextMilestone['verdict'];
}

// Activity series: the trailing `days` days, oldest first, plus the
// cumulative executed figure after each day.
$activity = array();
$cumulative = 0;
// Cumulative needs the WHOLE history (not just the window): count links whose
// first day is strictly before the window start.
$beforeWindow = 0;
foreach ($executedLinks as $day) {
    if ($day < $windowStart) {
        $beforeWindow++;
    }
}
$cumulative = $beforeWindow;
$activityDays = array();
for ($i = intval($days) - 1; $i >= 0; $i--) {
    $activityDays[] = date('Y-m-d', strtotime($today . ' -' . $i . ' day'));
}
foreach ($activityDays as $d) {
    $newly = $firstDayCounters[$d] ?? 0;
    $cumulative += $newly;
    $activity[] = array(
        'day' => $d,
        'newly_executed' => $newly,
        'executions' => $dayExecCounts[$d] ?? 0,
        'cumulative_executed' => $cumulative,
        'remaining_after' => max(0, $assigned - $cumulative),
    );
}

$summary = array(
    'assigned' => $assigned,
    'executed' => $executed,
    'remaining' => $remaining,
    'progress_pct' => $progressPct,
    'recent_velocity' => $recentVelocity,
    'overall_velocity' => $overallVelocity,
    'recent_new_links' => $recentNew,
    'eta_days' => $etaDays,
    'eta_date' => $etaDate,
    'verdict' => $verdict,
    'window_days' => intval($days),
    'window_start' => $windowStart,
    'earliest_execution' => $earliestDay,
    'milestone_count' => count($milestones),
);

$next = null;
if (!is_null($nextMilestone)) {
    $next = array(
        'name' => $nextMilestone['name'],
        'target_date' => $nextMilestone['target_date'],
        'days_left' => $nextMilestone['days_left'],
        'required_velocity' => $nextMilestone['required_velocity'],
        'a' => $nextMilestone['a'],
    );
}

pfOut(array(
    'status' => 'ok',
    'code' => 200,
    'context' => $context,
    'filters' => $filters,
    'filter' => $filterEcho,
    'summary' => $summary,
    'next_milestone' => $next,
    'milestones' => $milestones,
    'activity' => $activity,
));
