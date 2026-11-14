<?php
/**
 * Test Plan Portfolio Summary - BFF (Refs #1990, #1073).
 *
 * Net-new read-only report (gui/templates/results/planPortfolio.html).
 *
 * TestLink 1.9.20 / 2.0.1 never shows a CROSS-PLAN execution portfolio for a
 * test project: Test Plan Management (planView) lists plans with test-case /
 * build / platform counts but no execution progress, and every metrics report
 * is scoped to ONE plan (the Plan Comparison report compares exactly two).
 * This BFF answers "which plans are ready, stale or failing?" in one view.
 *
 * Semantics:
 *   - the unit is a `testplan_tcversions` row (an assigned test-case VERSION,
 *     once per linked platform);
 *   - the status of an assignment is the LATEST execution of the same version
 *     for the same platform (a platform_id = 0 link is satisfied by any
 *     platform), ordered by execution_ts then id;
 *   - statuses p/f/b are reported; anything else / no execution -> not_run;
 *   - a plan's `progress_state` is empty (no assignments), not_started (no
 *     execution), complete (every assignment executed) or in_progress;
 *   - a plan's `health` compares the pass rate of the EXECUTED assignments:
 *     na (nothing executed), good (pass rate >= 90 and no blocked/failed),
 *     watch (pass rate >= 70) or bad.
 *
 * Rights: session authentication, the shared BFF same-origin guard, and
 * `testplan_metrics` on the ADDRESSED test project (no client supplied plan
 * id can widen the scope). The project id in the query string is optional and
 * defaults to the session test project; it is resolved against the project
 * table before the right is checked.
 *
 * Contract: GET|HEAD
 *   ?action=projects                              -> readable project switcher
 *   ?action=init[&tproject_id=P]                  -> context + rights
 *   ?action=report[&tproject_id=P]                -> summary + one row per plan
 *   200 {status, code, context:{tproject_id,tproject_name},
 *        summary:{plans_total,plans_active,plans_closed,assigned,executed,
 *                 passed,failed,blocked,not_run,coverage_pct,pass_rate_pct,
 *                 plans_complete,plans_in_progress,plans_not_started,
 *                 plans_empty,testers,defects,builds,last_exec_ts},
 *        rows:[{tplan_id,tplan_name,active,is_open,assigned,executed,passed,
 *               failed,blocked,not_run,coverage_pct,pass_rate_pct,testers,
 *               assigned_testers,builds_used,defects,last_exec_ts,
 *               progress_state,health}]}
 *   400 invalid_request | 401 not_authenticated / session_expired
 *   403 no_right | 404 project_not_found | 405 method_not_allowed
 */
require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
require_once('testproject.class.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

/** Single JSON exit point. */
function ppOut($payload, $code = null)
{
    if ($code !== null) {
        http_response_code($code);
    }
    echo json_encode($payload);
    exit;
}

function ppFail($httpCode, $code, $message = '')
{
    ppOut(array(
        'status' => 'error',
        'code' => $code,
        'message' => $message !== '' ? $message : $code,
    ), $httpCode);
}

/** Strict positive integer reader - "1abc", "1.9", "-3" and "" are all refused. */
function ppPositiveInt($raw)
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

function ppDecode($value)
{
    return html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function ppPct($part, $total)
{
    return ($total > 0) ? round(100.0 * $part / $total, 1) : 0.0;
}

function ppStatusKey($raw)
{
    switch ((string)$raw) {
        case 'p': return 'passed';
        case 'f': return 'failed';
        case 'b': return 'blocked';
    }
    return 'not_run';
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    ppFail(405, 'method_not_allowed');
}

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    ppFail(401, 'not_authenticated', 'Not authenticated');
}

bffEnforceSession($db);

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    ppFail(401, 'not_authenticated', 'User not found');
}

$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if ($action !== 'init' && $action !== 'report' && $action !== 'projects') {
    ppFail(400, 'invalid_request', 'Unknown action');
}

$tbl = tlObjectWithDB::getDBTables(array(
    'testprojects', 'testplans', 'nodes_hierarchy', 'testplan_tcversions',
    'executions', 'execution_bugs', 'user_assignments', 'builds',
));

// ---------------------------------------------------------------------------
// projects: the switcher only offers projects the caller may read metrics on.
// ---------------------------------------------------------------------------
if ($action === 'projects') {
    $sql = "SELECT tp.id, nh.name, tp.prefix FROM {$tbl['testprojects']} tp" .
           " JOIN {$tbl['nodes_hierarchy']} nh ON nh.id = tp.id" .
           " WHERE tp.active = 1 ORDER BY nh.name";
    $rows = $db->get_recordset($sql);
    $items = array();
    if (is_array($rows)) {
        foreach ($rows as $row) {
            $pid = intval($row['id']);
            if ($pid <= 0) {
                continue;
            }
            if (!$user->hasRight($db, 'testplan_metrics', $pid, null, true)) {
                continue;
            }
            $items[] = array(
                'id' => $pid,
                'name' => ppDecode($row['name']),
                'prefix' => ppDecode($row['prefix']),
            );
        }
    }
    ppOut(array('status' => 'ok', 'code' => 200, 'projects' => $items));
}

// ---------------------------------------------------------------------------
// Resolve the addressed project: explicit GET id, else the session project.
// ---------------------------------------------------------------------------
$projectId = ppPositiveInt($_GET['tproject_id'] ?? null);
if ($projectId <= 0) {
    $projectId = intval($_SESSION['testprojectID'] ?? 0);
}

if ($projectId <= 0) {
    // Fall back to the first project the caller may read metrics on.
    $sql = "SELECT tp.id FROM {$tbl['testprojects']} tp" .
           " JOIN {$tbl['nodes_hierarchy']} nh ON nh.id = tp.id" .
           " WHERE tp.active = 1 ORDER BY nh.name";
    $rows = $db->get_recordset($sql);
    if (is_array($rows)) {
        foreach ($rows as $row) {
            $pid = intval($row['id']);
            if ($pid > 0 && $user->hasRight($db, 'testplan_metrics', $pid, null, true)) {
                $projectId = $pid;
                break;
            }
        }
    }
}

if ($projectId <= 0) {
    ppFail(404, 'project_not_found', 'No readable test project');
}

$projectRow = $db->get_recordset(
    "SELECT tp.id, nh.name FROM {$tbl['testprojects']} tp" .
    " JOIN {$tbl['nodes_hierarchy']} nh ON nh.id = tp.id" .
    " WHERE tp.id = " . intval($projectId));
if (!is_array($projectRow) || count($projectRow) === 0) {
    ppFail(404, 'project_not_found', 'Test project not found');
}
$project = $projectRow[0];
$projectName = ppDecode($project['name']);

if (!$user->hasRight($db, 'testplan_metrics', $projectId, null, true)) {
    tLog('BFF planportfolio: user ' . intval($userId) .
         ' has no testplan_metrics right on tproject ' . intval($projectId), 'ERROR');
    ppFail(403, 'no_right', 'testplan_metrics right required');
}

$context = array(
    'tproject_id' => $projectId,
    'tproject_name' => $projectName,
);

if ($action === 'init') {
    ppOut(array(
        'status' => 'ok',
        'code' => 200,
        'context' => $context,
    ));
}

// ---------------------------------------------------------------------------
// report
// ---------------------------------------------------------------------------
$planRows = $db->get_recordset(
    "SELECT tp.id, nh.name, tp.active, tp.is_open" .
    " FROM {$tbl['testplans']} tp" .
    " JOIN {$tbl['nodes_hierarchy']} nh ON nh.id = tp.id" .
    " WHERE tp.testproject_id = " . intval($projectId) .
    " ORDER BY nh.name");
$plans = array();
$planIds = array();
if (is_array($planRows)) {
    foreach ($planRows as $r) {
        $id = intval($r['id']);
        if ($id <= 0) {
            continue;
        }
        $planIds[] = $id;
        $plans[$id] = array(
            'tplan_id' => $id,
            'tplan_name' => ppDecode($r['name']),
            'active' => intval($r['active']),
            'is_open' => intval($r['is_open']),
            'assigned' => 0,
            'executed' => 0,
            'passed' => 0,
            'failed' => 0,
            'blocked' => 0,
            'not_run' => 0,
            'coverage_pct' => 0.0,
            'pass_rate_pct' => 0.0,
            'testers' => 0,
            'assigned_testers' => 0,
            'builds_used' => 0,
            'defects' => 0,
            'last_exec_ts' => '',
            'progress_state' => 'empty',
            'health' => 'na',
        );
    }
}

$summary = array(
    'plans_total' => 0,
    'plans_active' => 0,
    'plans_closed' => 0,
    'assigned' => 0,
    'executed' => 0,
    'passed' => 0,
    'failed' => 0,
    'blocked' => 0,
    'not_run' => 0,
    'coverage_pct' => 0.0,
    'pass_rate_pct' => 0.0,
    'plans_complete' => 0,
    'plans_in_progress' => 0,
    'plans_not_started' => 0,
    'plans_empty' => 0,
    'testers' => 0,
    'defects' => 0,
    'builds' => 0,
    'last_exec_ts' => '',
);

if (count($planIds) > 0) {
    $inIds = implode(',', array_map('intval', $planIds));

    // Project-level build count (builds belong to the project, not the plan).
    $bRs = $db->get_recordset(
        "SELECT COUNT(*) AS c FROM {$tbl['builds']}" .
        " WHERE testproject_id = " . intval($projectId) . " AND active = 1");
    if (is_array($bRs) && count($bRs) > 0) {
        $summary['builds'] = intval($bRs[0]['c']);
    }

    // Assigned test-case versions per plan.
    $aRs = $db->get_recordset(
        "SELECT testplan_id, COUNT(*) AS c FROM {$tbl['testplan_tcversions']}" .
        " WHERE testplan_id IN (" . $inIds . ") GROUP BY testplan_id");
    if (is_array($aRs)) {
        foreach ($aRs as $r) {
            $pid = intval($r['testplan_id']);
            if (isset($plans[$pid])) {
                $plans[$pid]['assigned'] = intval($r['c']);
            }
        }
    }

    // Latest execution per (plan, version, platform). Ordered ascending so the
    // last write is the newest (execution_ts, id).
    $latest = array();
    $latestAny = array();
    $execTesters = array();
    $execBuilds = array();
    $eRs = $db->get_recordset(
        "SELECT id, testplan_id, tcversion_id, platform_id, status, execution_ts, tester_id, build_id" .
        " FROM {$tbl['executions']}" .
        " WHERE testplan_id IN (" . $inIds . ")" .
        " ORDER BY execution_ts, id");
    if (is_array($eRs)) {
        foreach ($eRs as $e) {
            $pid = intval($e['testplan_id']);
            $tcv = intval($e['tcversion_id']);
            $plat = intval($e['platform_id']);
            $latest[$pid . '|' . $tcv . '|' . $plat] = $e;
            $latestAny[$pid . '|' . $tcv] = $e;

            $uid = intval($e['tester_id']);
            if ($uid > 0) {
                $execTesters[$pid . '|' . $uid] = true;
            }
            if (intval($e['build_id']) > 0) {
                $execBuilds[$pid . '|' . intval($e['build_id'])] = true;
            }
            $ts = (string)$e['execution_ts'];
            if ($ts !== '' && $ts > $summary['last_exec_ts']) {
                $summary['last_exec_ts'] = $ts;
            }
            if (isset($plans[$pid])) {
                $planTs = $plans[$pid]['last_exec_ts'];
                if ($ts !== '' && $ts > $planTs) {
                    $plans[$pid]['last_exec_ts'] = $ts;
                }
            }
        }
    }

    // Walk every assignment once and fold in the latest execution.
    $tRs = $db->get_recordset(
        "SELECT testplan_id, tcversion_id, platform_id" .
        " FROM {$tbl['testplan_tcversions']}" .
        " WHERE testplan_id IN (" . $inIds . ")");
    if (is_array($tRs)) {
        foreach ($tRs as $t) {
            $pid = intval($t['testplan_id']);
            if (!isset($plans[$pid])) {
                continue;
            }
            $tcv = intval($t['tcversion_id']);
            $plat = intval($t['platform_id']);
            $key = $pid . '|' . $tcv . '|' . $plat;
            $exec = null;
            if ($plat > 0 && isset($latest[$key])) {
                $exec = $latest[$key];
            } elseif (isset($latestAny[$pid . '|' . $tcv])) {
                $exec = $latestAny[$pid . '|' . $tcv];
            }
            if ($exec === null) {
                $plans[$pid]['not_run']++;
                continue;
            }
            $st = ppStatusKey($exec['status']);
            if ($st === 'not_run') {
                $plans[$pid]['not_run']++;
                continue;
            }
            $plans[$pid][$st]++;
            $plans[$pid]['executed']++;
        }
    }

    // Distinct assigned testers (active assignments) per plan.
    $uaRs = $db->get_recordset(
        "SELECT tpt.testplan_id, COUNT(DISTINCT ua.user_id) AS c" .
        " FROM {$tbl['user_assignments']} ua" .
        " JOIN {$tbl['testplan_tcversions']} tpt ON tpt.id = ua.feature_id" .
        " WHERE ua.type = 1 AND ua.status = 1 AND ua.user_id > 0" .
        "   AND tpt.testplan_id IN (" . $inIds . ")" .
        " GROUP BY tpt.testplan_id");
    if (is_array($uaRs)) {
        foreach ($uaRs as $r) {
            $pid = intval($r['testplan_id']);
            if (isset($plans[$pid])) {
                $plans[$pid]['assigned_testers'] = intval($r['c']);
            }
        }
    }

    // Distinct linked defects per plan.
    $dRs = $db->get_recordset(
        "SELECT e.testplan_id, COUNT(DISTINCT eb.bug_id) AS c" .
        " FROM {$tbl['execution_bugs']} eb" .
        " JOIN {$tbl['executions']} e ON e.id = eb.execution_id" .
        " WHERE e.testplan_id IN (" . $inIds . ")" .
        " GROUP BY e.testplan_id");
    if (is_array($dRs)) {
        foreach ($dRs as $r) {
            $pid = intval($r['testplan_id']);
            if (isset($plans[$pid])) {
                $plans[$pid]['defects'] = intval($r['c']);
            }
        }
    }

    // Distinct testers / builds used per plan (execution side).
    $planTesters = array();
    foreach (array_keys($execTesters) as $k) {
        list($pid, $uid) = explode('|', $k);
        $planTesters[intval($pid)][intval($uid)] = true;
    }
    $planBuilds = array();
    foreach (array_keys($execBuilds) as $k) {
        list($pid, $bid) = explode('|', $k);
        $planBuilds[intval($pid)][intval($bid)] = true;
    }

    foreach ($plans as $pid => &$p) {
        $p['testers'] = isset($planTesters[$pid]) ? count($planTesters[$pid]) : 0;
        $p['builds_used'] = isset($planBuilds[$pid]) ? count($planBuilds[$pid]) : 0;
        $p['not_run'] = max(0, $p['assigned'] - $p['executed']);
        $p['coverage_pct'] = ppPct($p['executed'], $p['assigned']);
        $p['pass_rate_pct'] = ppPct($p['passed'], $p['executed']);

        if ($p['assigned'] === 0) {
            $p['progress_state'] = 'empty';
        } elseif ($p['executed'] === 0) {
            $p['progress_state'] = 'not_started';
        } elseif ($p['not_run'] === 0) {
            $p['progress_state'] = 'complete';
        } else {
            $p['progress_state'] = 'in_progress';
        }

        if ($p['executed'] === 0) {
            $p['health'] = 'na';
        } elseif ($p['pass_rate_pct'] >= 90.0 && $p['failed'] === 0 && $p['blocked'] === 0) {
            $p['health'] = 'good';
        } elseif ($p['pass_rate_pct'] >= 70.0) {
            $p['health'] = 'watch';
        } else {
            $p['health'] = 'bad';
        }

        $summary['plans_total']++;
        if ($p['active'] > 0) {
            $summary['plans_active']++;
        } else {
            $summary['plans_closed']++;
        }
        $summary['assigned'] += $p['assigned'];
        $summary['executed'] += $p['executed'];
        $summary['passed'] += $p['passed'];
        $summary['failed'] += $p['failed'];
        $summary['blocked'] += $p['blocked'];
        $summary['not_run'] += $p['not_run'];
        if ($p['progress_state'] === 'complete') {
            $summary['plans_complete']++;
        } elseif ($p['progress_state'] === 'in_progress') {
            $summary['plans_in_progress']++;
        } elseif ($p['progress_state'] === 'not_started') {
            $summary['plans_not_started']++;
        } else {
            $summary['plans_empty']++;
        }
    }
    unset($p);

    $summary['coverage_pct'] = ppPct($summary['executed'], $summary['assigned']);
    $summary['pass_rate_pct'] = ppPct($summary['passed'], $summary['executed']);
    $summary['testers'] = count($execTesters);

    $allBugRs = $db->get_recordset(
        "SELECT COUNT(DISTINCT eb.bug_id) AS c" .
        " FROM {$tbl['execution_bugs']} eb" .
        " JOIN {$tbl['executions']} e ON e.id = eb.execution_id" .
        " WHERE e.testplan_id IN (" . $inIds . ")");
    if (is_array($allBugRs) && count($allBugRs) > 0) {
        $summary['defects'] = intval($allBugRs[0]['c']);
    }
}

ppOut(array(
    'status' => 'ok',
    'code' => 200,
    'context' => $context,
    'summary' => $summary,
    'rows' => array_values($plans),
));
