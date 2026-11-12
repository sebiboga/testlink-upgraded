<?php
/**
 * Test Plan Build x Platform Coverage Matrix report - BFF (Refs #1987).
 *
 * Net-new modern screen (gui/templates/results/buildPlatformMatrix.html).
 * TestLink 1.9.20 reports either aggregate a plan for ONE build
 * (resultsByBuild), ONE platform (resultsByPlatform) or plot an aggregate
 * per-build trend (metricsTrends); none shows the per-build x per-platform
 * cross-tab of assigned / executed / passed / failed / blocked / not-run, so
 * "which build is green on which platform?" has to be stitched by eye.
 *
 * Semantics
 *   - the universe is the set of test-case VERSIONS assigned to the plan in
 *     testplan_tcversions; the platform is part of the assignment
 *     (testplan_tcversions.platform_id), so every platform column has its own
 *     universe and annotations platform_id = 0 are a "no platform" column;
 *   - for each (build, platform) cell the CURRENT status of a version is the
 *     status of its most recent execution (MAX(executions.id)) on that build
 *     and platform - the "latest result wins" rule used by the other 2.0.1
 *     reports;
 *   - a version assigned on a platform but never executed on a build counts
 *     as NOT RUN in that cell;
 *   - an optional build filter keeps a single matrix row, an optional
 *     platform filter keeps a single matrix column.
 *
 * Actions
 *   GET|HEAD ?action=projects
 *     -> projects the user can read
 *   GET|HEAD ?action=init&tproject_id=N[&tplan_id=P]
 *     -> project context + plans + project builds + project platforms
 *   GET|HEAD ?action=report&tplan_id=N[&tproject_id=P][&build_id=B]
 *             [&platform_id=PL]
 *     -> summary KPIs, per-build totals, per-platform totals and the
 *        build x platform cells
 *
 * Security: session authentication, the shared BFF same-origin guard and the
 * `testplan_metrics` right on the OWNING test project of the plan
 * (getAccess = true). The client supplied tproject_id is only an ASSERTION.
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
function bpxmOut($payload, $code = null)
{
    if ($code !== null) {
        http_response_code($code);
    }
    echo json_encode($payload);
    exit;
}

function bpxmFail($httpCode, $code, $message = '')
{
    bpxmOut(array(
        'status' => 'error',
        'code' => $code,
        'message' => $message !== '' ? $message : $code,
    ), $httpCode);
}

/** Strict positive integer reader - "1abc", "1.9", "-3" and "" are refused. */
function bpxmPositiveInt($raw)
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

/** Normalize a raw executions.status char to the report's status key. */
function bpxmStatusKey($raw)
{
    switch ((string)$raw) {
        case 'p': return 'passed';
        case 'f': return 'failed';
        case 'b': return 'blocked';
        default:  return 'not_run';
    }
}

function bpxmPct($num, $den)
{
    if ($den <= 0) {
        return 0.0;
    }
    return round(100.0 * $num / $den, 1);
}

function bpxmDecode($value)
{
    return html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/** Read access to a test project (used for the project picker). */
function bpxmCanViewProject($user, $db, $tpid)
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

/**
 * Latest status per test-case version for a set of builds and platforms.
 *
 * @param array $buildIds    list of int build ids
 * @param array $platformIds list of int platform ids (empty = all)
 * @return array "$buildId:$platformId:$tcversionId" => status key
 */
function bpxmLatestStatuses($db, $tbl, $planId, $buildIds, $platformIds)
{
    if (count($buildIds) === 0) {
        return array();
    }
    $where = "testplan_id = " . intval($planId) .
             " AND build_id IN (" . implode(',', array_map('intval', $buildIds)) . ")";
    if (count($platformIds) > 0) {
        $where .= " AND platform_id IN (" .
                  implode(',', array_map('intval', $platformIds)) . ")";
    }
    $sql =
        "SELECT e.build_id AS build_id, e.platform_id AS platform_id, " .
        "e.tcversion_id AS tcversion_id, e.status AS status " .
        "FROM {$tbl['executions']} e " .
        "INNER JOIN (SELECT build_id, platform_id, tcversion_id, MAX(id) AS mid " .
        "FROM {$tbl['executions']} WHERE {$where} " .
        "GROUP BY build_id, platform_id, tcversion_id) x ON x.mid = e.id";
    $out = array();
    foreach ((array)$db->get_recordset($sql) as $r) {
        $key = intval($r['build_id']) . ':' . intval($r['platform_id']) . ':' .
               intval($r['tcversion_id']);
        $out[$key] = bpxmStatusKey($r['status']);
    }
    return $out;
}

/** Count the statuses of one universe against a status map. */
function bpxmCountCell($universe, $statuses)
{
    $counts = array(
        'assigned' => 0, 'executed' => 0, 'passed' => 0, 'failed' => 0,
        'blocked' => 0, 'not_run' => 0,
    );
    foreach ($universe as $tid => $true) {
        $counts['assigned']++;
        $key = isset($statuses[$tid]) ? $statuses[$tid] : 'not_run';
        if ($key === 'not_run') {
            $counts['not_run']++;
            continue;
        }
        $counts['executed']++;
        if (isset($counts[$key])) {
            $counts[$key]++;
        }
    }
    $counts['pass_rate'] = bpxmPct($counts['passed'], $counts['executed']);
    $counts['coverage'] = bpxmPct($counts['executed'], $counts['assigned']);
    return $counts;
}

/** Accumulate one cell into a totals bucket. */
function bpxmAccumulate(&$totals, $cell)
{
    foreach (array('assigned', 'executed', 'passed', 'failed', 'blocked', 'not_run') as $k) {
        $totals[$k] += intval($cell[$k]);
    }
}

function bpxmFinishTotals($totals)
{
    $totals['pass_rate'] = bpxmPct($totals['passed'], $totals['executed']);
    $totals['coverage'] = bpxmPct($totals['executed'], $totals['assigned']);
    return $totals;
}

// ---------------------------------------------------------------------------
// Method / session
// ---------------------------------------------------------------------------
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    bpxmFail(405, 'method_not_allowed');
}

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    bpxmFail(401, 'not_authenticated', 'Not authenticated');
}

$db = new database(DB_TYPE);
doDBConnect($db);
bffEnforceSession($db);

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    bpxmFail(401, 'not_authenticated', 'User not found');
}

$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if (is_array($_GET['action'] ?? null)) {
    bpxmFail(400, 'invalid_parameter', 'Invalid action');
}
if (!in_array($action, array('projects', 'init', 'report'), true)) {
    bpxmFail(400, 'unknown_action', 'Unknown action');
}

$tbl = tlObjectWithDB::getDBTables(array(
    'testplans', 'testprojects', 'nodes_hierarchy', 'builds', 'platforms',
    'testplan_tcversions', 'tcversions', 'executions', 'testplan_platforms',
));

$assertedProjectId = bpxmPositiveInt($_GET['tproject_id'] ?? null);
$requestedPlanId = bpxmPositiveInt($_GET['tplan_id'] ?? null);

// ---------------------------------------------------------------------------
// projects: the project picker
// ---------------------------------------------------------------------------
if ($action === 'projects') {
    $rows = (array)$db->get_recordset(
        "SELECT p.id AS id, p.prefix AS prefix, nh.name AS name " .
        "FROM {$tbl['testprojects']} p " .
        "INNER JOIN {$tbl['nodes_hierarchy']} nh ON nh.id = p.id AND nh.node_type_id = 1 " .
        "ORDER BY nh.name ASC, p.id ASC");
    $projects = array();
    foreach ($rows as $r) {
        $pid = intval($r['id']);
        if (!bpxmCanViewProject($user, $db, $pid)) {
            continue;
        }
        $projects[] = array(
            'id' => $pid,
            'name' => bpxmDecode($r['name']),
            'prefix' => (string)$r['prefix'],
        );
    }
    bpxmOut(array('status' => 'ok', 'code' => 'ok', 'projects' => $projects));
}

// ---------------------------------------------------------------------------
// init: project context + plans + the build / platform filters
// ---------------------------------------------------------------------------
if ($action === 'init') {
    $tpid = ($assertedProjectId > 0) ? $assertedProjectId
                                     : intval($_SESSION['testprojectID'] ?? 0);
    if ($tpid <= 0) {
        bpxmFail(400, 'invalid_request', 'Missing or invalid tproject_id');
    }
    // The right is checked on the ADDRESSED project (no project-id oracle).
    if (!bpxmCanViewProject($user, $db, $tpid)) {
        tLog('BFF buildplatformmatrix: user ' . intval($userId) .
             ' has no read right on tproject ' . $tpid, 'AUDIT');
        bpxmFail(403, 'no_right', 'Read right required on the test project');
    }

    $projectRow = (array)$db->get_recordset(
        "SELECT p.id AS id, p.prefix AS prefix, nh.name AS name " .
        "FROM {$tbl['testprojects']} p " .
        "INNER JOIN {$tbl['nodes_hierarchy']} nh ON nh.id = p.id AND nh.node_type_id = 1 " .
        "WHERE p.id = " . $tpid);
    if (count($projectRow) === 0) {
        bpxmFail(404, 'tproject_not_found', 'Test project not found');
    }

    $planRows = (array)$db->get_recordset(
        "SELECT tp.id AS id, tp.active AS active, tp.is_open AS is_open, nh.name AS name " .
        "FROM {$tbl['testplans']} tp " .
        "LEFT JOIN {$tbl['nodes_hierarchy']} nh ON nh.id = tp.id " .
        "WHERE tp.testproject_id = " . $tpid . " ORDER BY nh.name ASC, tp.id ASC");
    $plans = array();
    foreach ($planRows as $r) {
        $plans[] = array(
            'id' => intval($r['id']),
            'name' => bpxmDecode($r['name']),
            'active' => intval($r['active']) ? true : false,
            'is_open' => intval($r['is_open']) ? true : false,
        );
    }

    $buildRows = (array)$db->get_recordset(
        "SELECT id, name, active, is_open FROM {$tbl['builds']} " .
        "WHERE testproject_id = " . $tpid . " ORDER BY id ASC");
    $builds = array();
    foreach ($buildRows as $r) {
        $builds[] = array(
            'id' => intval($r['id']),
            'name' => bpxmDecode($r['name']),
            'active' => intval($r['active']) ? true : false,
            'is_open' => intval($r['is_open']) ? true : false,
        );
    }

    $platformRows = (array)$db->get_recordset(
        "SELECT id, name FROM {$tbl['platforms']} WHERE testproject_id = " . $tpid .
        " ORDER BY name ASC, id ASC");
    $platforms = array();
    foreach ($platformRows as $r) {
        $platforms[] = array('id' => intval($r['id']), 'name' => bpxmDecode($r['name']));
    }

    bpxmOut(array(
        'status' => 'ok',
        'code' => 'ok',
        'context' => array(
            'tproject_id' => $tpid,
            'tproject_name' => bpxmDecode($projectRow[0]['name']),
            'tproject_prefix' => (string)$projectRow[0]['prefix'],
            'tplan_id' => $requestedPlanId,
        ),
        'plans' => $plans,
        'filters' => array('builds' => $builds, 'platforms' => $platforms),
    ));
}

// ---------------------------------------------------------------------------
// report: the build x platform coverage matrix
// ---------------------------------------------------------------------------
$buildFilterId = bpxmPositiveInt($_GET['build_id'] ?? null);
$platformFilterId = bpxmPositiveInt($_GET['platform_id'] ?? null);

if ($requestedPlanId <= 0) {
    bpxmFail(400, 'invalid_request', 'Missing or invalid tplan_id');
}

$planRow = (array)$db->get_recordset(
    "SELECT id, testproject_id FROM {$tbl['testplans']} WHERE id = " . $requestedPlanId);
if (count($planRow) === 0) {
    bpxmFail(404, 'tplan_not_found', 'Test plan not found');
}
$owningProjectId = intval($planRow[0]['testproject_id']);
if ($owningProjectId <= 0) {
    bpxmFail(404, 'tplan_not_found', 'Test plan not found');
}

// The right is checked on the OWNING project of the plan.
if (!$user->hasRight($db, 'testplan_metrics', $owningProjectId, $requestedPlanId, true)) {
    tLog('BFF buildplatformmatrix: user ' . intval($userId) .
         ' has no testplan_metrics right on tproject ' . $owningProjectId .
         ' (tplan ' . $requestedPlanId . ')', 'AUDIT');
    bpxmFail(403, 'no_right', 'testplan_metrics right required');
}

if ($assertedProjectId > 0 && $assertedProjectId !== $owningProjectId) {
    bpxmFail(404, 'project_mismatch', 'Test plan belongs to another test project');
}

$planName = testplan::getName($db, $requestedPlanId);
$projectName = testproject::getName($db, $owningProjectId);
if (is_null($planName) || trim((string)$planName) === '') {
    bpxmFail(404, 'tplan_not_found', 'Test plan not found');
}

// --- builds of the owning project ---------------------------------------
$buildRows = (array)$db->get_recordset(
    "SELECT id, name, active, is_open FROM {$tbl['builds']} " .
    "WHERE testproject_id = " . $owningProjectId . " ORDER BY id ASC");
$buildNames = array();
$buildMeta = array();
foreach ($buildRows as $r) {
    $bid = intval($r['id']);
    $buildNames[$bid] = bpxmDecode($r['name']);
    $buildMeta[$bid] = array(
        'id' => $bid,
        'name' => bpxmDecode($r['name']),
        'active' => intval($r['active']) ? true : false,
        'is_open' => intval($r['is_open']) ? true : false,
    );
}
if ($buildFilterId > 0 && !isset($buildNames[$buildFilterId])) {
    bpxmFail(400, 'invalid_request', 'build_id does not belong to this test project');
}

// --- platforms: linked to the plan and/or used by an assignment ----------
$platformRows = (array)$db->get_recordset(
    "SELECT p.id AS id, p.name AS name, tpp.active AS link_active " .
    "FROM {$tbl['testplan_platforms']} tpp " .
    "INNER JOIN {$tbl['platforms']} p ON p.id = tpp.platform_id " .
    "WHERE tpp.testplan_id = " . $requestedPlanId .
    " AND tpp.active = 1 ORDER BY p.name ASC, p.id ASC");
$platformNames = array();
$platformLinked = array();
foreach ($platformRows as $r) {
    $pid = intval($r['id']);
    $platformNames[$pid] = bpxmDecode($r['name']);
    $platformLinked[$pid] = true;
}
$usedPlatformRows = (array)$db->get_recordset(
    "SELECT DISTINCT platform_id FROM {$tbl['testplan_tcversions']} " .
    "WHERE testplan_id = " . $requestedPlanId);
$hasNoPlatform = false;
foreach ($usedPlatformRows as $r) {
    $pid = intval($r['platform_id']);
    if ($pid === 0) {
        $hasNoPlatform = true;
    } elseif (!isset($platformNames[$pid])) {
        $pRow = (array)$db->get_recordset(
            "SELECT id, name FROM {$tbl['platforms']} WHERE id = " . $pid .
            " AND testproject_id = " . $owningProjectId);
        if (count($pRow) > 0) {
            $platformNames[$pid] = bpxmDecode($pRow[0]['name']);
        } else {
            $platformNames[$pid] = '#' . $pid;
        }
    }
}
if ($platformFilterId > 0) {
    if (!isset($platformNames[$platformFilterId])) {
        bpxmFail(400, 'invalid_request', 'platform_id is not linked to this test plan');
    }
    $platformNames = array($platformFilterId => $platformNames[$platformFilterId]);
    $platformLinked = array($platformFilterId => true);
    $hasNoPlatform = ($platformFilterId === 0);
}

// Keep a stable display order: by name, "no platform" last.
uksort($platformNames, function ($a, $b) use ($platformNames) {
    $c = strcasecmp($platformNames[$a], $platformNames[$b]);
    if ($c !== 0) {
        return $c;
    }
    return intval($a) - intval($b);
});
if ($hasNoPlatform) {
    $platformNames[0] = '';
}
if (count($platformNames) === 0) {
    $platformNames[0] = '';
}

$platformIds = array_keys($platformNames);
$platformIds = array_map('intval', $platformIds);

// --- the universes: versions assigned per platform -----------------------
$universeSql =
    "SELECT tcversion_id, platform_id FROM {$tbl['testplan_tcversions']} " .
    "WHERE testplan_id = " . $requestedPlanId;
if ($platformFilterId > 0) {
    $universeSql .= " AND platform_id = " . $platformFilterId;
} elseif ($hasNoPlatform && !in_array(0, $platformIds, true)) {
    $universeSql .= " AND platform_id <> 0";
}
$universes = array();
$distinct = array();
foreach ((array)$db->get_recordset($universeSql) as $r) {
    $pid = intval($r['platform_id']);
    $tid = intval($r['tcversion_id']);
    if (!isset($universes[$pid])) {
        $universes[$pid] = array();
    }
    $universes[$pid][$tid] = true;
    $distinct[$tid] = true;
}

// --- the builds kept in the matrix --------------------------------------
$buildIds = array_keys($buildNames);
$buildIds = array_map('intval', $buildIds);
if ($buildFilterId > 0) {
    $buildIds = array($buildFilterId);
}

$statuses = bpxmLatestStatuses($db, $tbl, $requestedPlanId, $buildIds, $platformIds);

// --- build the cells + totals -------------------------------------------
$cells = array();
$buildTotals = array();
foreach ($buildIds as $bid) {
    $buildTotals[$bid] = array(
        'assigned' => 0, 'executed' => 0, 'passed' => 0, 'failed' => 0,
        'blocked' => 0, 'not_run' => 0);
}
$platformTotals = array();
foreach ($platformIds as $pid) {
    $platformTotals[$pid] = array(
        'assigned' => 0, 'executed' => 0, 'passed' => 0, 'failed' => 0,
        'blocked' => 0, 'not_run' => 0);
}
$overall = array(
    'assigned' => 0, 'executed' => 0, 'passed' => 0, 'failed' => 0,
    'blocked' => 0, 'not_run' => 0);

foreach ($buildIds as $bid) {
    foreach ($platformIds as $pid) {
        $universe = isset($universes[$pid]) ? $universes[$pid] : array();
        $cellStatuses = array();
        foreach ($universe as $tid => $true) {
            $key = $bid . ':' . $pid . ':' . $tid;
            if (isset($statuses[$key])) {
                $cellStatuses[$tid] = $statuses[$key];
            }
        }
        $cell = bpxmCountCell($universe, $cellStatuses);
        $cell['build_id'] = $bid;
        $cell['platform_id'] = $pid;
        $cells[] = $cell;
        bpxmAccumulate($buildTotals[$bid], $cell);
        bpxmAccumulate($platformTotals[$pid], $cell);
        bpxmAccumulate($overall, $cell);
    }
}

$buildsOut = array();
foreach ($buildIds as $bid) {
    $row = bpxmFinishTotals($buildTotals[$bid]);
    $row['id'] = $bid;
    $row['name'] = $buildNames[$bid];
    $row['active'] = $buildMeta[$bid]['active'];
    $row['is_open'] = $buildMeta[$bid]['is_open'];
    $buildsOut[] = $row;
}
$platformsOut = array();
foreach ($platformIds as $pid) {
    $row = bpxmFinishTotals($platformTotals[$pid]);
    $row['id'] = $pid;
    $row['name'] = ($pid === 0) ? '' : $platformNames[$pid];
    $row['linked'] = isset($platformLinked[$pid]) ? true : false;
    $platformsOut[] = $row;
}
$summary = bpxmFinishTotals($overall);
$summary['builds'] = count($buildIds);
$realPlatforms = 0;
foreach ($platformIds as $pid) {
    if ($pid > 0) { $realPlatforms++; }
}
if ($realPlatforms === 0 && $summary['assigned'] > 0) {
    $realPlatforms = 1; // the "no platform" column still carries assignments
}
$summary['platforms'] = $realPlatforms;
$summary['distinct_cases'] = count($distinct);
if ($summary['assigned'] === 0) {
    $summary['verdict'] = 'no_data';
} elseif ($summary['executed'] === 0) {
    $summary['verdict'] = 'not_started';
} elseif ($summary['not_run'] === 0) {
    $summary['verdict'] = 'complete';
} else {
    $summary['verdict'] = 'in_progress';
}

$buildFilterMeta = ($buildFilterId > 0) ? $buildMeta[$buildFilterId] : null;
$platformFilterName = ($platformFilterId > 0) ? $platformNames[$platformFilterId] : '';

bpxmOut(array(
    'status' => 'ok',
    'code' => 'ok',
    'context' => array(
        'tproject_id' => $owningProjectId,
        'tproject_name' => bpxmDecode($projectName),
        'tplan_id' => $requestedPlanId,
        'tplan_name' => bpxmDecode($planName),
        'build_id' => $buildFilterId,
        'build' => $buildFilterMeta,
        'platform_id' => $platformFilterId,
        'platform' => $platformFilterName,
    ),
    'filters' => array(
        'builds' => array_values($buildMeta),
        'platforms' => array_values($platformsOut),
    ),
    'summary' => $summary,
    'builds' => $buildsOut,
    'platforms' => $platformsOut,
    'cells' => $cells,
));
