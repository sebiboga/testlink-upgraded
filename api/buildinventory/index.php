<?php
/**
 * Project Build Inventory - BFF (Refs #2061).
 *
 * Net-new read-only 2.0.1 project report (gui/templates/plans/buildInventory.html).
 * buildsView.html answers questions about ONE plan's builds; 1.9.20/2.0.1 offer
 * no project-wide view of the whole build registry: which builds are open,
 * which are stale, and where the execution evidence actually is.
 *
 * Actions (GET|HEAD):
 *   ?action=projects
 *   ?action=init&tproject_id=P
 *   ?action=report&tproject_id=P[&plan_id=N][&state=all|active|inactive][&open=all|open|closed]
 *   ?action=detail&tproject_id=P&build_id=B[&plan_id=N]
 *
 * Security: session auth + bffSameOriginGuard + bffEnforceSession. The
 * `testplan_metrics` right is checked on the ADDRESSED project before the
 * project is resolved (no existence oracle); denials are logged at AUDIT.
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

function biOut($payload, $httpCode = null)
{
    if ($httpCode !== null) {
        http_response_code($httpCode);
    }
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function biFail($httpCode, $code, $message = '')
{
    biOut(array(
        'status' => 'error',
        'code' => $code,
        'message' => $message !== '' ? $message : $code,
    ), $httpCode);
}

function biPositiveInt($raw)
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

function biDecode($value)
{
    return html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function biPct($num, $den)
{
    return $den > 0 ? round(100.0 * $num / $den, 1) : 0.0;
}

function biStatus($raw)
{
    $s = (string)$raw;
    return ($s === 'p' || $s === 'f' || $s === 'b') ? $s : 'n';
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    biFail(405, 'method_not_allowed', 'Only GET and HEAD are supported');
}

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    biFail(401, 'not_authenticated', 'Not authenticated');
}
bffEnforceSession($db);
$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    biFail(401, 'not_authenticated', 'User not found');
}

$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if (!in_array($action, array('projects', 'init', 'report', 'detail'), true)) {
    biFail(400, 'invalid_request', 'Unknown action');
}

$tbl = tlObjectWithDB::getDBTables(array(
    'testprojects', 'testplans', 'builds', 'executions', 'execution_bugs',
    'testplan_tcversions', 'tcversions', 'nodes_hierarchy', 'platforms',
));

if ($action === 'projects') {
    $rs = $db->get_recordset("SELECT id FROM {$tbl['testprojects']} ORDER BY id");
    $projects = array();
    if (is_array($rs)) {
        foreach ($rs as $row) {
            $pid = intval($row['id']);
            if (!$user->hasRight($db, 'testplan_metrics', $pid, null, true)) {
                continue;
            }
            $name = testproject::getName($db, $pid);
            $projects[] = array(
                'id' => $pid,
                'name' => biDecode(is_null($name) ? ('#' . $pid) : $name),
            );
        }
    }
    usort($projects, function ($a, $b) {
        $cmp = strcasecmp($a['name'], $b['name']);
        return $cmp !== 0 ? $cmp : ($a['id'] - $b['id']);
    });
    biOut(array('status' => 'ok', 'projects' => $projects));
}

$projectId = biPositiveInt($_GET['tproject_id'] ?? null);
$planId = biPositiveInt($_GET['plan_id'] ?? null);
$stateFilter = strtolower(trim((string)($_GET['state'] ?? 'all')));
$openFilter = strtolower(trim((string)($_GET['open'] ?? 'all')));
if (!in_array($stateFilter, array('all', 'active', 'inactive'), true)) {
    biFail(400, 'invalid_request', 'Invalid state filter');
}
if (!in_array($openFilter, array('all', 'open', 'closed'), true)) {
    biFail(400, 'invalid_request', 'Invalid open filter');
}
if (isset($_GET['plan_id']) && $planId <= 0) {
    biFail(400, 'invalid_request', 'Invalid plan_id');
}
if ($projectId <= 0) {
    biFail(400, 'invalid_request', 'Missing or invalid tproject_id');
}

// No-oracle rule: refuse before looking up whether the project exists.
if (!$user->hasRight($db, 'testplan_metrics', $projectId, null, true)) {
    tLog('BFF buildinventory: user ' . intval($userId) .
         ' has no testplan_metrics right on tproject ' . $projectId, 'AUDIT');
    biFail(403, 'no_right', 'testplan_metrics right required');
}
$exists = $db->get_recordset(
    "SELECT id FROM {$tbl['testprojects']} WHERE id = " . intval($projectId));
if (!is_array($exists) || count($exists) === 0) {
    biFail(404, 'tproject_not_found', 'Test project not found');
}
$prjName = testproject::getName($db, $projectId);
if (is_null($prjName)) {
    biFail(404, 'tproject_not_found', 'Test project not found');
}
$projectName = biDecode($prjName);

$plans = array();
$rsPlans = $db->get_recordset(
    "SELECT id FROM {$tbl['testplans']} WHERE testproject_id = " .
    intval($projectId) . " ORDER BY id");
if (is_array($rsPlans)) {
    foreach ($rsPlans as $row) {
        $pid = intval($row['id']);
        $name = testplan::getName($db, $pid);
        $plans[$pid] = array(
            'id' => $pid,
            'name' => biDecode(is_null($name) ? ('#' . $pid) : $name),
        );
    }
}
if ($planId > 0 && !isset($plans[$planId])) {
    biFail(404, 'plan_not_found', 'Test plan not found in this project');
}

$whereBuild = "testproject_id = " . intval($projectId);
if ($stateFilter === 'active') {
    $whereBuild .= " AND active = 1";
} elseif ($stateFilter === 'inactive') {
    $whereBuild .= " AND active = 0";
}
if ($openFilter === 'open') {
    $whereBuild .= " AND is_open = 1";
} elseif ($openFilter === 'closed') {
    $whereBuild .= " AND is_open = 0";
}

$builds = array();
$buildIds = array();
$rsBuilds = $db->get_recordset(
    "SELECT id, name, notes, active, is_open, author_id, creation_ts, " .
    "release_date, closed_on_date, commit_id, tag, branch, release_candidate " .
    "FROM {$tbl['builds']} WHERE {$whereBuild} ORDER BY creation_ts, id");
if (is_array($rsBuilds)) {
    foreach ($rsBuilds as $row) {
        $bid = intval($row['id']);
        $builds[$bid] = array(
            'id' => $bid,
            'name' => biDecode($row['name']),
            'notes' => trim(strip_tags(biDecode($row['notes'] ?? ''))),
            'active' => intval($row['active']) === 1,
            'is_open' => intval($row['is_open']) === 1,
            'author_id' => intval($row['author_id'] ?? 0),
            'creation_ts' => (string)$row['creation_ts'],
            'release_date' => $row['release_date'] ?? null,
            'closed_on_date' => $row['closed_on_date'] ?? null,
            'commit_id' => biDecode($row['commit_id'] ?? ''),
            'tag' => biDecode($row['tag'] ?? ''),
            'branch' => biDecode($row['branch'] ?? ''),
            'release_candidate' => biDecode($row['release_candidate'] ?? ''),
            'executions' => 0, 'passed' => 0, 'failed' => 0, 'blocked' => 0,
            'not_run' => 0, 'cases' => 0, 'testers' => 0, 'defects' => 0,
            'last_execution_ts' => null, 'duration' => 0.0, 'verdict' => 'no_data',
        );
        $buildIds[] = $bid;
    }
}

$execByBuild = array();
$defectSets = array();
$buildPlans = array();
if (count($buildIds) > 0) {
    $idList = implode(',', array_map('intval', $buildIds));
    $sql = "SELECT e.id, e.build_id, e.testplan_id, e.tcversion_id, e.status, " .
           "e.tester_id, e.execution_ts, e.execution_duration, e.platform_id " .
           "FROM {$tbl['executions']} e " .
           "WHERE e.build_id IN ({$idList})";
    if ($planId > 0) {
        $sql .= " AND e.testplan_id = " . intval($planId);
    }
    $sql .= " ORDER BY e.execution_ts, e.id";
    $rsExec = $db->get_recordset($sql);
    if (is_array($rsExec)) {
        foreach ($rsExec as $row) {
            $bid = intval($row['build_id']);
            if (!isset($builds[$bid])) {
                continue;
            }
            $st = biStatus($row['status']);
            $execByBuild[$bid][] = $row;
            $planOfExec = intval($row['testplan_id'] ?? 0);
            if ($planOfExec > 0) {
                if (!isset($buildPlans[$bid])) { $buildPlans[$bid] = array(); }
                $buildPlans[$bid][$planOfExec] = true;
            }
            $builds[$bid]['executions']++;
            $builds[$bid][$st === 'p' ? 'passed' : ($st === 'f' ? 'failed' : ($st === 'b' ? 'blocked' : 'not_run'))]++;
            $builds[$bid]['duration'] += (float)($row['execution_duration'] ?? 0);
            $ts = (string)($row['execution_ts'] ?? '');
            if ($ts !== '' && ($builds[$bid]['last_execution_ts'] === null ||
                strcmp($ts, (string)$builds[$bid]['last_execution_ts']) > 0)) {
                $builds[$bid]['last_execution_ts'] = $ts;
            }
        }
    }
}

// Defect counts are keyed by execution id; build them from the scoped execution set.
if (count($buildIds) > 0) {
    $execIds = array();
    $execToBuild = array();
    foreach ($execByBuild as $bid => $rows) {
        foreach ($rows as $row) {
            $eid = intval($row['id']);
            $execIds[] = $eid;
            $execToBuild[$eid] = $bid;
        }
    }
    if (count($execIds) > 0) {
        $rsBugs = $db->get_recordset(
            "SELECT DISTINCT execution_id, bug_id FROM {$tbl['execution_bugs']} " .
            "WHERE execution_id IN (" . implode(',', array_map('intval', $execIds)) . ")");
        if (is_array($rsBugs)) {
            foreach ($rsBugs as $row) {
                $eid = intval($row['execution_id']);
                if (!isset($execToBuild[$eid])) {
                    continue;
                }
                $bid = $execToBuild[$eid];
                $key = trim((string)$row['bug_id']);
                if (!isset($defectSets[$bid])) {
                    $defectSets[$bid] = array();
                }
                $defectSets[$bid][$key] = true;
            }
        }
    }
}

$assignments = array();
$planIds = array_keys($plans);
if (count($planIds) > 0) {
    $rsAssign = $db->get_recordset(
        "SELECT testplan_id, COUNT(DISTINCT tcversion_id) AS qty " .
        "FROM {$tbl['testplan_tcversions']} WHERE testplan_id IN (" .
        implode(',', array_map('intval', $planIds)) . ") GROUP BY testplan_id");
    if (is_array($rsAssign)) {
        foreach ($rsAssign as $row) {
            $assignments[intval($row['testplan_id'])] = intval($row['qty']);
        }
    }
}

$rows = array();
$kpi = array(
    'builds' => 0, 'active' => 0, 'open' => 0, 'with_executions' => 0,
    'executions' => 0, 'passed' => 0, 'failed' => 0, 'blocked' => 0,
    'pass_rate' => 0.0, 'defects' => 0, 'last_activity' => null,
);
foreach ($builds as $bid => $build) {
    $cases = array();
    $testers = array();
    foreach ($execByBuild[$bid] ?? array() as $row) {
        $cases[intval($row['tcversion_id'])] = true;
        $testerId = intval($row['tester_id'] ?? 0);
        if ($testerId > 0) { $testers[$testerId] = true; }
    }
    $exec = $build['executions'];
    $failRate = biPct($build['failed'], $exec);
    $blockRate = biPct($build['blocked'], $exec);
    if ($exec === 0) {
        $verdict = 'no_data';
    } elseif ($failRate >= 20.0) {
        $verdict = 'at_risk';
    } elseif ($blockRate >= 20.0) {
        $verdict = 'watch';
    } elseif ($build['failed'] === 0 && $build['blocked'] === 0) {
        $verdict = 'ready';
    } else {
        $verdict = 'in_progress';
    }
    $build['cases'] = count($cases);
    $build['testers'] = count($testers);
    $build['defects'] = isset($defectSets[$bid]) ? count($defectSets[$bid]) : 0;
    $build['plans'] = array();
    foreach (array_keys(isset($buildPlans[$bid]) ? $buildPlans[$bid] : array()) as $planWithEvidence) {
        if (isset($plans[intval($planWithEvidence)])) {
            $build['plans'][] = $plans[intval($planWithEvidence)]['name'];
        }
    }
    $build['pass_rate'] = biPct($build['passed'], $exec);
    $build['fail_rate'] = $failRate;
    $build['duration'] = round($build['duration'], 2);
    $build['verdict'] = $verdict;

    $kpi['builds']++;
    if ($build['active']) { $kpi['active']++; }
    if ($build['is_open']) { $kpi['open']++; }
    if ($exec > 0) { $kpi['with_executions']++; }
    $kpi['executions'] += $exec;
    $kpi['passed'] += $build['passed'];
    $kpi['failed'] += $build['failed'];
    $kpi['blocked'] += $build['blocked'];
    $kpi['defects'] += $build['defects'];
    if ($build['last_execution_ts'] !== null &&
        ($kpi['last_activity'] === null ||
         strcmp((string)$build['last_execution_ts'], (string)$kpi['last_activity']) > 0)) {
        $kpi['last_activity'] = $build['last_execution_ts'];
    }
    $rows[] = $build;
}
$kpi['pass_rate'] = biPct($kpi['passed'], $kpi['executions']);
$assignedTotal = 0;
foreach ($assignments as $qty) {
    $assignedTotal += $qty;
}

$projectVerdict = 'no_data';
if ($kpi['builds'] > 0) {
    if ($kpi['with_executions'] === 0) {
        $projectVerdict = 'not_started';
    } elseif ($kpi['failed'] > 0 && biPct($kpi['failed'], $kpi['executions']) >= 20.0) {
        $projectVerdict = 'at_risk';
    } elseif ($kpi['blocked'] > 0) {
        $projectVerdict = 'watch';
    } elseif ($kpi['passed'] === $kpi['executions']) {
        $projectVerdict = 'ready';
    } else {
        $projectVerdict = 'in_progress';
    }
}

$context = array(
    'tproject_id' => $projectId,
    'tproject_name' => $projectName,
    'plan_id' => $planId,
    'plan_name' => $planId > 0 ? $plans[$planId]['name'] : null,
    'filters' => array('state' => $stateFilter, 'open' => $openFilter),
);

if ($action === 'init') {
    biOut(array(
        'status' => 'ok',
        'context' => $context,
        'plans' => array_values($plans),
        'kpi' => $kpi,
    ));
}

if ($action === 'detail') {
    $buildId = biPositiveInt($_GET['build_id'] ?? null);
    $detailBuild = null;
    if ($buildId > 0 && isset($builds[$buildId])) {
        $detailBuild = $builds[$buildId];
    } elseif ($buildId > 0) {
        // A detail deep link must still work when the current report filters
        // exclude the build (state/open), as long as it belongs to this project.
        $rsOne = $db->get_recordset(
            "SELECT id, name, notes, active, is_open, author_id, creation_ts, " .
            "release_date, closed_on_date, commit_id, tag, branch, release_candidate " .
            "FROM {$tbl['builds']} WHERE id = " . intval($buildId) .
            " AND testproject_id = " . intval($projectId));
        if (is_array($rsOne) && count($rsOne) > 0) {
            $detailBuild = array(
                'id' => $buildId,
                'name' => biDecode($rsOne[0]['name']),
                'notes' => trim(strip_tags(biDecode($rsOne[0]['notes'] ?? ''))),
                'active' => intval($rsOne[0]['active']) === 1,
                'is_open' => intval($rsOne[0]['is_open']) === 1,
            );
        }
    }
    if (is_null($detailBuild)) {
        biFail(404, 'build_not_found', 'Build not found in this project');
    }
    $detailRows = isset($execByBuild[$buildId]) ? array_reverse($execByBuild[$buildId]) : array();
    if (!isset($builds[$buildId])) {
        // The report filter hid this build; a direct detail link still shows
        // its evidence, newest first, under the same plan scope.
        $sqlOne = "SELECT id, build_id, testplan_id, tcversion_id, status, tester_id, " .
                  "execution_ts, execution_duration, platform_id FROM {$tbl['executions']} " .
                  "WHERE build_id = " . intval($buildId);
        if ($planId > 0) { $sqlOne .= " AND testplan_id = " . intval($planId); }
        $sqlOne .= " ORDER BY execution_ts DESC, id DESC";
        $rsOne = $db->get_recordset($sqlOne);
        $detailRows = is_array($rsOne) ? $rsOne : array();
    }
    $details = array();
    foreach ($detailRows as $row) {
        $tcName = '#';
        $rsName = $db->get_recordset(
            "SELECT nh.name, tv.version FROM {$tbl['tcversions']} tv " .
            "JOIN {$tbl['nodes_hierarchy']} nh ON nh.id = tv.id " .
            "WHERE tv.id = " . intval($row['tcversion_id']));
        if (is_array($rsName) && count($rsName) > 0) {
            $tcName = biDecode($rsName[0]['name']) . ' v' . $rsName[0]['version'];
        }
        $planName = isset($plans[intval($row['testplan_id'])])
            ? $plans[intval($row['testplan_id'])]['name'] : '#' . intval($row['testplan_id']);
        $platformName = '';
        $pid = intval($row['platform_id'] ?? 0);
        if ($pid > 0) {
            $rsPl = $db->get_recordset(
                "SELECT name FROM {$tbl['platforms']} WHERE id = " . $pid);
            if (is_array($rsPl) && count($rsPl) > 0) {
                $platformName = biDecode($rsPl[0]['name']);
            }
        }
        $details[] = array(
            'id' => intval($row['id']),
            'execution_ts' => (string)$row['execution_ts'],
            'status' => biStatus($row['status']),
            'case_name' => $tcName,
            'plan_name' => $planName,
            'platform_name' => $platformName,
            'tester_id' => intval($row['tester_id'] ?? 0),
            'duration' => round((float)($row['execution_duration'] ?? 0), 2),
        );
        if (count($details) >= 200) {
            break;
        }
    }
    biOut(array(
        'status' => 'ok',
        'context' => $context,
        'build' => $detailBuild,
        'executions' => $details,
        'truncated' => count($detailRows) > 200,
    ));
}

biOut(array(
    'status' => 'ok',
    'context' => $context,
    'plans' => array_values($plans),
    'assignments' => $assignments,
    'assigned_total' => $assignedTotal,
    'kpi' => $kpi,
    'project_verdict' => $projectVerdict,
    'rows' => array_values($rows),
));
