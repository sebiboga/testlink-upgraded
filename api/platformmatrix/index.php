<?php
/**
 * Test Plan Platform Coverage Matrix report - BFF (Refs #1992).
 *
 * Net-new read-only 2.0.1 report (gui/templates/results/platformMatrix.html).
 * 1.9.20 could diff exactly two platforms (the modern platformComparison) or
 * show a per-build snapshot (resultsByBuild), but it never answered the single
 * most common platform question: "which assigned test cases pass on EVERY
 * platform of this plan?". This report puts the platforms in columns and the
 * assigned test-case versions in rows, with the latest execution result per
 * (version, platform) cell.
 *
 * Actions
 *   GET|HEAD ?action=init&tplan_id=N[&tproject_id=P]
 *       -> context + build/platform filter vocabulary
 *   GET|HEAD ?action=report&tplan_id=N[&tproject_id=P][&build_id=B][&platform_id=PL]
 *       -> summary + per-platform totals + matrix rows
 *
 * Cell values: p (passed) / f (failed) / b (blocked) / n (not run) / na (not
 * assigned to that platform). A version assigned with platform_id = 0 is
 * assigned to every platform of the plan.
 *
 * Security: session authentication, the shared BFF same-origin guard and the
 * `testplan_metrics` right on the OWNING test project. The client supplied
 * tproject_id is only an assertion (compared, never used to widen a query),
 * and the right is checked BEFORE the project resolution (no-oracle lesson).
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

function pmOut($payload, $code = null)
{
    if ($code !== null) {
        http_response_code($code);
    }
    echo json_encode($payload);
    exit;
}

function pmFail($httpCode, $code, $message = '')
{
    pmOut(array(
        'status' => 'error',
        'code' => $code,
        'message' => $message !== '' ? $message : $code,
    ), $httpCode);
}

/** Strict positive integer reader - "1abc", "1.9", "-3" and "" are refused. */
function pmPositiveInt($raw)
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

function pmDecode($value)
{
    return html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function pmRound($value, $decimals = 1)
{
    return round((float)$value, $decimals);
}

function pmPct($num, $den)
{
    return ($den > 0) ? pmRound(100.0 * $num / $den, 1) : 0.0;
}

/** Normalise an executions.status value into p / f / b / n. */
function pmStatus($raw)
{
    $s = (string)$raw;
    return ($s === 'p' || $s === 'f' || $s === 'b') ? $s : 'n';
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    pmFail(405, 'method_not_allowed');
}

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    pmFail(401, 'not_authenticated', 'Not authenticated');
}

bffEnforceSession($db);

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    pmFail(401, 'not_authenticated', 'User not found');
}

$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if ($action !== 'init' && $action !== 'report') {
    pmFail(400, 'invalid_request', 'Unknown action');
}

$tplanId = pmPositiveInt($_GET['tplan_id'] ?? null);
$assertedProjectId = pmPositiveInt($_GET['tproject_id'] ?? null);
$platformId = pmPositiveInt($_GET['platform_id'] ?? null);
// An explicit build_id=0 means "All builds"; a missing one defaults to the
// first open build. Keep the distinction for the filter resolution below.
$buildParamPresent = array_key_exists('build_id', $_GET);
$buildId = pmPositiveInt($_GET['build_id'] ?? null);

foreach (array('platform_id', 'build_id') as $strictKey) {
    if (isset($_GET[$strictKey])) {
        $raw = is_array($_GET[$strictKey]) ? '' : trim((string)$_GET[$strictKey]);
        if ($raw === '' || !preg_match('/^[0-9]+$/', $raw)) {
            pmFail(400, 'invalid_request', 'Invalid ' . $strictKey);
        }
    }
}

if ($tplanId <= 0) {
    pmFail(400, 'invalid_request', 'Missing or invalid tplan_id');
}

$tbl = tlObjectWithDB::getDBTables(array(
    'testplans', 'builds', 'platforms', 'testplan_platforms', 'testplan_tcversions',
    'tcversions', 'nodes_hierarchy', 'executions', 'testprojects',
));
$planRow = $db->get_recordset(
    "SELECT id, testproject_id FROM {$tbl['testplans']} WHERE id = " . intval($tplanId));

if (!is_array($planRow) || count($planRow) === 0) {
    pmFail(404, 'tplan_not_found', 'Test plan not found');
}

$plan = $planRow[0];
$owningProjectId = intval($plan['testproject_id']);
if ($owningProjectId <= 0) {
    pmFail(404, 'tplan_not_found', 'Test plan has no owning test project');
}

if (!$user->hasRight($db, 'testplan_metrics', $owningProjectId, $tplanId, true)) {
    tLog('BFF platformmatrix: user ' . intval($userId) .
         ' has no testplan_metrics right on tproject ' . $owningProjectId .
         ' (tplan ' . $tplanId . ')', 'AUDIT');
    pmFail(403, 'no_right', 'testplan_metrics right required');
}

if ($assertedProjectId > 0 && $assertedProjectId !== $owningProjectId) {
    pmFail(404, 'project_mismatch', 'Test plan belongs to another test project');
}

$planName = testplan::getName($db, $tplanId);
$projectName = testproject::getName($db, $owningProjectId);
if (is_null($planName) || trim((string)$planName) === '') {
    pmFail(404, 'tplan_not_found', 'Test plan not found');
}

// ---------------------------------------------------------------------------
// Builds of the owning project (filter vocabulary + default open build).
// ---------------------------------------------------------------------------
$builds = array();
$openBuildId = 0;
$rb = $db->get_recordset(
    "SELECT id, name, active, is_open FROM {$tbl['builds']} WHERE testproject_id = " .
    intval($owningProjectId) . " ORDER BY id");
if (is_array($rb)) {
    foreach ($rb as $r) {
        $bid = intval($r['id']);
        $isOpen = (intval($r['is_open']) === 1);
        if ($isOpen && $openBuildId === 0) {
            $openBuildId = $bid;
        }
        $builds[$bid] = array(
            'id' => $bid,
            'name' => pmDecode($r['name']),
            'active' => (intval($r['active']) === 1),
            'is_open' => $isOpen,
        );
    }
}
if ($buildId > 0 && !isset($builds[$buildId])) {
    pmFail(400, 'invalid_request', 'build_id does not belong to this test project');
}

// ---------------------------------------------------------------------------
// Platforms in scope: the ones the plan links, plus any platform that still
// appears on an assignment or an execution (so deleted links keep their data).
// ---------------------------------------------------------------------------
$platformName = array();
$rp = $db->get_recordset(
    "SELECT id, name FROM {$tbl['platforms']} WHERE testproject_id = " .
    intval($owningProjectId) . " ORDER BY name");
if (is_array($rp)) {
    foreach ($rp as $r) {
        $platformName[intval($r['id'])] = pmDecode($r['name']);
    }
}

$planPlatformIds = array();
$rpp = $db->get_recordset(
    "SELECT platform_id FROM {$tbl['testplan_platforms']} WHERE testplan_id = " .
    intval($tplanId) . " AND active = 1");
if (is_array($rpp)) {
    foreach ($rpp as $r) {
        $pid = intval($r['platform_id']);
        if ($pid > 0 && isset($platformName[$pid])) {
            $planPlatformIds[$pid] = true;
        }
    }
}

// Assignments: rows = tcversions, each with the set of platforms it covers.
$assignedByTcv = array();
$assignRs = $db->get_recordset(
    "SELECT tcversion_id, platform_id FROM {$tbl['testplan_tcversions']} " .
    "WHERE testplan_id = " . intval($tplanId) . " ORDER BY tcversion_id");
if (is_array($assignRs)) {
    foreach ($assignRs as $r) {
        $tcv = intval($r['tcversion_id']);
        $pid = intval($r['platform_id']);
        if ($tcv <= 0) {
            continue;
        }
        if (!isset($assignedByTcv[$tcv])) {
            $assignedByTcv[$tcv] = array('global' => false, 'platforms' => array());
        }
        if ($pid <= 0) {
            $assignedByTcv[$tcv]['global'] = true;
        } else if (isset($platformName[$pid])) {
            $assignedByTcv[$tcv]['platforms'][$pid] = true;
            $planPlatformIds[$pid] = true;
        }
    }
}

// Any platform on an execution of this plan is kept too.
$execRs = $db->get_recordset(
    "SELECT DISTINCT platform_id FROM {$tbl['executions']} WHERE testplan_id = " .
    intval($tplanId) . " AND platform_id > 0");
if (is_array($execRs)) {
    foreach ($execRs as $r) {
        $pid = intval($r['platform_id']);
        if (isset($platformName[$pid])) {
            $planPlatformIds[$pid] = true;
        }
    }
}

$platformIds = array_keys($planPlatformIds);
sort($platformIds, SORT_NUMERIC);

// The filter vocabulary always offers every plan platform; a platform_id
// filter only narrows the report columns, never the dropdown itself.
$allPlatforms = array();
foreach ($platformIds as $pid) {
    $allPlatforms[] = array('id' => intval($pid), 'name' => $platformName[$pid]);
}

if ($platformId > 0) {
    if (!isset($platformName[$platformId])) {
        pmFail(400, 'invalid_request', 'platform_id does not belong to this test project');
    }
    $platformIds = array($platformId);
}

$platforms = array();
foreach ($platformIds as $pid) {
    $platforms[] = array('id' => intval($pid), 'name' => $platformName[$pid]);
}

$context = array(
    'tplan_id' => $tplanId,
    'tplan_name' => (string)$planName,
    'tproject_id' => $owningProjectId,
    'tproject_name' => (string)$projectName,
);
$buildList = array();
foreach ($builds as $b) {
    $buildList[] = $b;
}
$filters = array(
    'builds' => $buildList,
    'platforms' => $allPlatforms,
    'default_build_id' => $openBuildId,
);
$filterState = array(
    'build_id' => $buildParamPresent ? $buildId : $openBuildId,
    'platform_id' => $platformId,
);

if ($action === 'init') {
    pmOut(array(
        'status' => 'ok',
        'code' => 200,
        'context' => $context,
        'filters' => $filters,
        'filter' => $filterState,
    ));
}

// ---------------------------------------------------------------------------
// The report itself. `build_id` 0 (no open build and none supplied) means
// "every build": the latest execution per (version, platform) still wins.
// ---------------------------------------------------------------------------
$effectiveBuildId = $buildParamPresent ? $buildId : $openBuildId;
$filterState['build_id'] = $effectiveBuildId;

$whereExec = "e.testplan_id = " . intval($tplanId);
if ($effectiveBuildId > 0) {
    $whereExec .= " AND e.build_id = " . intval($effectiveBuildId);
}

$cellStatus = array(); // [tcv][platform_id] => p|f|b
$execRs2 = $db->get_recordset(
    "SELECT e.tcversion_id, e.platform_id, e.status, e.execution_ts, e.id " .
    "FROM {$tbl['executions']} e " .
    "WHERE " . $whereExec .
    " ORDER BY e.id ASC");
if (is_array($execRs2)) {
    foreach ($execRs2 as $r) {
        $tcv = intval($r['tcversion_id']);
        $pid = intval($r['platform_id']);
        if (!isset($assignedByTcv[$tcv]) || $pid <= 0 || !isset($platformName[$pid])) {
            continue;
        }
        if ($platformId > 0 && $pid !== $platformId) {
            continue;
        }
        $cellStatus[$tcv][$pid] = pmStatus($r['status']);
    }
}

// Test case names + versions for the assigned rows.
$tcMeta = array();
if (count($assignedByTcv) > 0) {
    $tcvIds = array_keys($assignedByTcv);
    $rsMeta = $db->get_recordset(
        "SELECT tv.id AS tcversion_id, nhv.parent_id AS testcase_id, tv.version, " .
        "tv.importance, tv.tc_external_id, nh_tc.name AS tc_name " .
        "FROM {$tbl['tcversions']} tv " .
        "INNER JOIN {$tbl['nodes_hierarchy']} nhv ON nhv.id = tv.id " .
        "INNER JOIN {$tbl['nodes_hierarchy']} nh_tc ON nh_tc.id = nhv.parent_id " .
        "WHERE tv.id IN (" . implode(',', array_map('intval', $tcvIds)) . ")");
    if (is_array($rsMeta)) {
        foreach ($rsMeta as $r) {
            $tcMeta[intval($r['tcversion_id'])] = array(
                'testcase_id' => intval($r['testcase_id']),
                'testcase_name' => pmDecode($r['tc_name']),
                'version' => intval($r['version']),
                'importance' => intval($r['importance']),
                'external_id' => (string)($r['tc_external_id'] ?? ''),
            );
        }
    }
}

$perPlatform = array();
foreach ($platformIds as $pid) {
    $perPlatform[$pid] = array(
        'platform_id' => intval($pid),
        'platform_name' => $platformName[$pid],
        'assigned' => 0, 'executed' => 0, 'passed' => 0,
        'failed' => 0, 'blocked' => 0, 'not_run' => 0,
    );
}

$rows = array();
$cellsAssigned = 0;
$executedCells = 0;
$passedCells = 0;
$failedCells = 0;
$blockedCells = 0;
$notRunCells = 0;
$fullyGreen = 0;
$rowsWithFailures = 0;

foreach ($assignedByTcv as $tcv => $assign) {
    $tcv = intval($tcv);
    $applies = array();
    foreach ($platformIds as $pid) {
        $isAssigned = $assign['global'] || isset($assign['platforms'][$pid]);
        if ($isAssigned) {
            $applies[$pid] = true;
        }
    }
    if (count($applies) === 0) {
        continue;
    }

    $meta = $tcMeta[$tcv] ?? array(
        'testcase_id' => 0, 'testcase_name' => '',
        'version' => 0, 'importance' => 0, 'external_id' => '',
    );

    $cells = array();
    $rowPassed = 0;
    $rowFailed = 0;
    $rowBlocked = 0;
    $rowNotRun = 0;
    foreach ($platformIds as $pid) {
        if (!isset($applies[$pid])) {
            $cells[(string)$pid] = 'na';
            continue;
        }
        $st = $cellStatus[$tcv][$pid] ?? 'n';
        $cells[(string)$pid] = $st;
        $cellsAssigned++;
        $perPlatform[$pid]['assigned']++;
        if ($st === 'p') {
            $rowPassed++; $passedCells++; $executedCells++;
            $perPlatform[$pid]['passed']++; $perPlatform[$pid]['executed']++;
        } else if ($st === 'f') {
            $rowFailed++; $failedCells++; $executedCells++;
            $perPlatform[$pid]['failed']++; $perPlatform[$pid]['executed']++;
        } else if ($st === 'b') {
            $rowBlocked++; $blockedCells++; $executedCells++;
            $perPlatform[$pid]['blocked']++; $perPlatform[$pid]['executed']++;
        } else {
            $rowNotRun++; $notRunCells++;
            $perPlatform[$pid]['not_run']++;
        }
    }

    $rowAssigned = $rowPassed + $rowFailed + $rowBlocked + $rowNotRun;
    $rowExecuted = $rowPassed + $rowFailed + $rowBlocked;
    if ($rowAssigned > 0 && $rowPassed === $rowAssigned) {
        $fullyGreen++;
    }
    if ($rowFailed > 0) {
        $rowsWithFailures++;
    }

    $rowVerdict = 'not_run';
    if ($rowAssigned === 0) {
        $rowVerdict = 'na';
    } else if ($rowExecuted === 0) {
        $rowVerdict = 'not_run';
    } else if ($rowFailed > 0) {
        $rowVerdict = 'failed';
    } else if ($rowBlocked > 0) {
        $rowVerdict = 'blocked';
    } else if ($rowNotRun > 0) {
        $rowVerdict = 'partial';
    } else {
        $rowVerdict = 'all_pass';
    }

    $rows[] = array(
        'tcversion_id' => $tcv,
        'testcase_id' => $meta['testcase_id'],
        'testcase_name' => $meta['testcase_name'],
        'external_id' => $meta['external_id'],
        'version' => $meta['version'],
        'importance' => $meta['importance'],
        'cells' => $cells,
        'assigned' => $rowAssigned,
        'executed' => $rowExecuted,
        'passed' => $rowPassed,
        'failed' => $rowFailed,
        'blocked' => $rowBlocked,
        'not_run' => $rowNotRun,
        'coverage_pct' => pmPct($rowExecuted, $rowAssigned),
        'pass_rate_pct' => pmPct($rowPassed, $rowExecuted),
        'verdict' => $rowVerdict,
    );
}

usort($rows, function ($a, $b) {
    $va = ($a['failed'] > 0) ? 0 : (($a['blocked'] > 0) ? 1 : (($a['not_run'] > 0) ? 2 : 3));
    $vb = ($b['failed'] > 0) ? 0 : (($b['blocked'] > 0) ? 1 : (($b['not_run'] > 0) ? 2 : 3));
    if ($va !== $vb) {
        return $va - $vb;
    }
    return strcasecmp($a['testcase_name'], $b['testcase_name']);
});

$platformRows = array();
$worstPlatformId = 0;
$worstPassRate = null;
foreach ($platformIds as $pid) {
    $p = $perPlatform[$pid];
    $p['coverage_pct'] = pmPct($p['executed'], $p['assigned']);
    $p['pass_rate_pct'] = pmPct($p['passed'], $p['executed']);
    $platformRows[] = $p;
    if ($p['executed'] > 0
        && ($worstPassRate === null || $p['pass_rate_pct'] < $worstPassRate)) {
        $worstPassRate = $p['pass_rate_pct'];
        $worstPlatformId = intval($pid);
    }
}

$coveragePct = pmPct($executedCells, $cellsAssigned);
$passRatePct = pmPct($passedCells, $executedCells);

$verdict = 'no_data';
if ($cellsAssigned > 0) {
    if ($executedCells === 0) {
        $verdict = 'no_data';
    } else if ($coveragePct < 50.0 || $passRatePct < 80.0) {
        $verdict = 'at_risk';
    } else if ($coveragePct < 80.0 || $passRatePct < 95.0 || $failedCells > 0) {
        $verdict = 'watch';
    } else {
        $verdict = 'ready';
    }
}

$summary = array(
    'platform_count' => count($platformIds),
    'assigned_cases' => count($rows),
    'assigned_cells' => $cellsAssigned,
    'executed_cells' => $executedCells,
    'passed_cells' => $passedCells,
    'failed_cells' => $failedCells,
    'blocked_cells' => $blockedCells,
    'not_run_cells' => $notRunCells,
    'coverage_pct' => $coveragePct,
    'pass_rate_pct' => $passRatePct,
    'fully_green_cases' => $fullyGreen,
    'rows_with_failures' => $rowsWithFailures,
    'worst_platform_id' => $worstPlatformId,
    'worst_platform_name' => ($worstPlatformId > 0) ? $platformName[$worstPlatformId] : '',
    'worst_platform_pass_rate_pct' => ($worstPassRate === null) ? null : $worstPassRate,
    'verdict' => $verdict,
);

pmOut(array(
    'status' => 'ok',
    'code' => 200,
    'context' => $context,
    'filters' => $filters,
    'filter' => $filterState,
    'summary' => $summary,
    'platforms' => $platformRows,
    'rows' => $rows,
));
