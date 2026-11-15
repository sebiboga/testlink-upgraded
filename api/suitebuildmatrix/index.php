<?php
/**
 * Test Plan Suite x Build Coverage Matrix report - BFF (Refs #1993).
 *
 * Net-new modern screen (gui/templates/results/suiteBuildMatrix.html).
 * TestLink 1.9.20 reports either aggregate a whole plan for one build
 * (resultsByBuild), one suite for one build (resultsByTSuite) or one
 * platform cross-tab (buildPlatformMatrix); none shows every test suite
 * against every build at a glance, so "which suite is green in which build?"
 * has to be stitched by eye.
 *
 * Semantics
 *   - the universe is the set of test-case VERSIONS assigned to the plan in
 *     testplan_tcversions; versions are grouped by the DIRECT PARENT suite of
 *     their test-case node (the same grouping resultsByTSuite uses);
 *   - a build column is every build of the owning test project;
 *   - the CURRENT status of a version in a build is the status of its most
 *     recent execution (MAX(executions.id)) on that build across every
 *     platform - the "latest result wins" rule of the other 2.0.1 reports;
 *   - a version assigned but never executed in a build counts as NOT RUN;
 *   - an optional build filter keeps a single matrix column.
 *
 * Actions
 *   GET|HEAD ?action=projects
 *     -> projects the user can read
 *   GET|HEAD ?action=init&tproject_id=N[&tplan_id=P]
 *     -> project context + plans + project builds
 *   GET|HEAD ?action=report&tplan_id=N[&tproject_id=P][&build_id=B]
 *     -> summary KPIs, per-suite totals, per-build totals and the cells
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
function sbmOut($payload, $code = null)
{
    if ($code !== null) {
        http_response_code($code);
    }
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function sbmFail($httpCode, $code, $message = '')
{
    sbmOut(array(
        'status' => 'error',
        'code' => $code,
        'message' => $message !== '' ? $message : $code,
    ), $httpCode);
}

/** Strict positive integer reader - "1abc", "1.9", "-3" and "" are refused. */
function sbmPositiveInt($raw)
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
function sbmStatusKey($raw)
{
    switch ((string)$raw) {
        case 'p': return 'passed';
        case 'f': return 'failed';
        case 'b': return 'blocked';
        default:  return 'not_run';
    }
}

function sbmPct($num, $den)
{
    if ($den <= 0) {
        return 0.0;
    }
    return round(100.0 * $num / $den, 1);
}

function sbmDecode($value)
{
    return html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/** Read access to a test project (used for the project picker). */
function sbmCanViewProject($user, $db, $tpid)
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
 * Latest status per test-case version for a set of builds (any platform).
 *
 * The newest execution id wins; ties across platforms are impossible because
 * a single execution row has one id.
 *
 * @param array $buildIds list of int build ids
 * @return array "$buildId:$tcversionId" => status key
 */
function sbmLatestStatuses($db, $tbl, $planId, $buildIds)
{
    if (count($buildIds) === 0) {
        return array();
    }
    $where = "testplan_id = " . intval($planId) .
             " AND build_id IN (" . implode(',', array_map('intval', $buildIds)) . ")";
    $sql =
        "SELECT e.build_id AS build_id, e.tcversion_id AS tcversion_id, " .
        "e.status AS status " .
        "FROM {$tbl['executions']} e " .
        "INNER JOIN (SELECT build_id, tcversion_id, MAX(id) AS mid " .
        "FROM {$tbl['executions']} WHERE {$where} " .
        "GROUP BY build_id, tcversion_id) x ON x.mid = e.id";
    $out = array();
    foreach ((array)$db->get_recordset($sql) as $r) {
        $key = intval($r['build_id']) . ':' . intval($r['tcversion_id']);
        $out[$key] = sbmStatusKey($r['status']);
    }
    return $out;
}

/** Count the statuses of one universe against a status map. */
function sbmCountCell($universe, $statuses)
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
    $counts['pass_rate'] = sbmPct($counts['passed'], $counts['executed']);
    $counts['coverage'] = sbmPct($counts['executed'], $counts['assigned']);
    return $counts;
}

/** Accumulate one cell into a totals bucket. */
function sbmAccumulate(&$totals, $cell)
{
    foreach (array('assigned', 'executed', 'passed', 'failed', 'blocked', 'not_run') as $k) {
        $totals[$k] += intval($cell[$k]);
    }
}

function sbmFinishTotals($totals)
{
    $totals['pass_rate'] = sbmPct($totals['passed'], $totals['executed']);
    $totals['coverage'] = sbmPct($totals['executed'], $totals['assigned']);
    return $totals;
}

// ---------------------------------------------------------------------------
// Method / session
// ---------------------------------------------------------------------------
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    sbmFail(405, 'method_not_allowed');
}

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    sbmFail(401, 'not_authenticated', 'Not authenticated');
}

$db = new database(DB_TYPE);
doDBConnect($db);
bffEnforceSession($db);

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    sbmFail(401, 'not_authenticated', 'User not found');
}

if (is_array($_GET['action'] ?? null)) {
    sbmFail(400, 'invalid_parameter', 'Invalid action');
}
$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if (!in_array($action, array('projects', 'init', 'report'), true)) {
    sbmFail(400, 'unknown_action', 'Unknown action');
}

$tbl = tlObjectWithDB::getDBTables(array(
    'testplans', 'testprojects', 'nodes_hierarchy', 'builds',
    'testplan_tcversions', 'tcversions', 'executions',
));

$assertedProjectId = sbmPositiveInt($_GET['tproject_id'] ?? null);
$requestedPlanId = sbmPositiveInt($_GET['tplan_id'] ?? null);

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
        if (!sbmCanViewProject($user, $db, $pid)) {
            continue;
        }
        $projects[] = array(
            'id' => $pid,
            'name' => sbmDecode($r['name']),
            'prefix' => (string)$r['prefix'],
        );
    }
    sbmOut(array('status' => 'ok', 'code' => 'ok', 'projects' => $projects));
}

// ---------------------------------------------------------------------------
// init: project context + plans + the build filter
// ---------------------------------------------------------------------------
if ($action === 'init') {
    $tpid = ($assertedProjectId > 0) ? $assertedProjectId
                                     : intval($_SESSION['testprojectID'] ?? 0);
    if ($tpid <= 0) {
        sbmFail(400, 'invalid_request', 'Missing or invalid tproject_id');
    }
    // The right is checked on the ADDRESSED project (no project-id oracle).
    if (!sbmCanViewProject($user, $db, $tpid)) {
        tLog('BFF suitebuildmatrix: user ' . intval($userId) .
             ' has no read right on tproject ' . $tpid, 'AUDIT');
        sbmFail(403, 'no_right', 'Read right required on the test project');
    }

    $projectRow = (array)$db->get_recordset(
        "SELECT p.id AS id, p.prefix AS prefix, nh.name AS name " .
        "FROM {$tbl['testprojects']} p " .
        "INNER JOIN {$tbl['nodes_hierarchy']} nh ON nh.id = p.id AND nh.node_type_id = 1 " .
        "WHERE p.id = " . $tpid);
    if (count($projectRow) === 0) {
        sbmFail(404, 'tproject_not_found', 'Test project not found');
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
            'name' => sbmDecode($r['name']),
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
            'name' => sbmDecode($r['name']),
            'active' => intval($r['active']) ? true : false,
            'is_open' => intval($r['is_open']) ? true : false,
        );
    }

    sbmOut(array(
        'status' => 'ok',
        'code' => 'ok',
        'context' => array(
            'tproject_id' => $tpid,
            'tproject_name' => sbmDecode($projectRow[0]['name']),
            'tproject_prefix' => (string)$projectRow[0]['prefix'],
            'tplan_id' => $requestedPlanId,
        ),
        'plans' => $plans,
        'filters' => array('builds' => $builds),
    ));
}

// ---------------------------------------------------------------------------
// report: the suite x build coverage matrix
// ---------------------------------------------------------------------------
$buildFilterId = sbmPositiveInt($_GET['build_id'] ?? null);

if ($requestedPlanId <= 0) {
    sbmFail(400, 'invalid_request', 'Missing or invalid tplan_id');
}

$planRow = (array)$db->get_recordset(
    "SELECT id, testproject_id FROM {$tbl['testplans']} WHERE id = " . $requestedPlanId);
if (count($planRow) === 0) {
    sbmFail(404, 'tplan_not_found', 'Test plan not found');
}
$owningProjectId = intval($planRow[0]['testproject_id']);
if ($owningProjectId <= 0) {
    sbmFail(404, 'tplan_not_found', 'Test plan not found');
}

// The right is checked on the OWNING project of the plan.
if (!$user->hasRight($db, 'testplan_metrics', $owningProjectId, $requestedPlanId, true)) {
    tLog('BFF suitebuildmatrix: user ' . intval($userId) .
         ' has no testplan_metrics right on tproject ' . $owningProjectId .
         ' (tplan ' . $requestedPlanId . ')', 'AUDIT');
    sbmFail(403, 'no_right', 'testplan_metrics right required');
}

if ($assertedProjectId > 0 && $assertedProjectId !== $owningProjectId) {
    sbmFail(404, 'project_mismatch', 'Test plan belongs to another test project');
}

$planName = testplan::getName($db, $requestedPlanId);
$projectName = testproject::getName($db, $owningProjectId);
if (is_null($planName) || trim((string)$planName) === '') {
    sbmFail(404, 'tplan_not_found', 'Test plan not found');
}

// --- builds of the owning project ---------------------------------------
$buildRows = (array)$db->get_recordset(
    "SELECT id, name, active, is_open FROM {$tbl['builds']} " .
    "WHERE testproject_id = " . $owningProjectId . " ORDER BY id ASC");
$buildNames = array();
$buildMeta = array();
foreach ($buildRows as $r) {
    $bid = intval($r['id']);
    $buildNames[$bid] = sbmDecode($r['name']);
    $buildMeta[$bid] = array(
        'id' => $bid,
        'name' => sbmDecode($r['name']),
        'active' => intval($r['active']) ? true : false,
        'is_open' => intval($r['is_open']) ? true : false,
    );
}
if ($buildFilterId > 0 && !isset($buildNames[$buildFilterId])) {
    sbmFail(400, 'invalid_request', 'build_id does not belong to this test project');
}

// --- the universes: versions assigned per suite --------------------------
$suiteSql =
    "SELECT tpt.tcversion_id AS tcversion_id, " .
    "tc.parent_id AS tcase_id, tcase.parent_id AS suite_id " .
    "FROM {$tbl['testplan_tcversions']} tpt " .
    "JOIN {$tbl['nodes_hierarchy']} tc ON tc.id = tpt.tcversion_id " .
    "JOIN {$tbl['nodes_hierarchy']} tcase ON tcase.id = tc.parent_id " .
    "WHERE tpt.testplan_id = " . $requestedPlanId .
    " GROUP BY tpt.tcversion_id, tc.parent_id, tcase.parent_id";
$universes = array();
$distinct = array();
$suiteIds = array();
foreach ((array)$db->get_recordset($suiteSql) as $r) {
    $sid = intval($r['suite_id']);
    $tid = intval($r['tcversion_id']);
    if ($sid <= 0) {
        continue;
    }
    if (!isset($universes[$sid])) {
        $universes[$sid] = array();
        $suiteIds[$sid] = true;
    }
    $universes[$sid][$tid] = true;
    $distinct[intval($r['tcase_id'])] = true;
}
$suiteIds = array_keys($suiteIds);
$suiteIds = array_map('intval', $suiteIds);

// --- resolve suite names + paths (walk type-2 ancestors) -----------------
$suiteNames = array();
$suitePaths = array();
if (count($suiteIds) > 0) {
    $nodeRows = (array)$db->get_recordset(
        "SELECT id, name, parent_id, node_type_id FROM {$tbl['nodes_hierarchy']} " .
        "WHERE id IN (" . implode(',', $suiteIds) . ")");
    $nodes = array();
    foreach ($nodeRows as $r) {
        $nodes[intval($r['id'])] = $r;
    }
    // Fetch the ancestor chain lazily but with one query per distinct parent.
    $pending = array();
    foreach ($suiteIds as $sid) {
        if (isset($nodes[$sid])) {
            $pending[intval($nodes[$sid]['parent_id'])] = true;
        }
    }
    $fetched = array();
    while (count($pending) > 0) {
        $batch = array();
        foreach (array_keys($pending) as $nid) {
            if ($nid > 0 && !isset($nodes[$nid]) && !isset($fetched[$nid])) {
                $batch[] = intval($nid);
            }
        }
        $pending = array();
        if (count($batch) === 0) {
            break;
        }
        $more = (array)$db->get_recordset(
            "SELECT id, name, parent_id, node_type_id FROM {$tbl['nodes_hierarchy']} " .
            "WHERE id IN (" . implode(',', $batch) . ")");
        foreach ($more as $r) {
            $nid = intval($r['id']);
            $nodes[$nid] = $r;
            $fetched[$nid] = true;
            if (intval($r['node_type_id']) === 2) {
                $pending[intval($r['parent_id'])] = true;
            }
        }
    }
    foreach ($suiteIds as $sid) {
        $name = isset($nodes[$sid]) ? sbmDecode($nodes[$sid]['name']) : ('#' . $sid);
        $suiteNames[$sid] = $name;
        $pathParts = array($name);
        $cursor = isset($nodes[$sid]) ? intval($nodes[$sid]['parent_id']) : 0;
        $guard = 0;
        while ($cursor > 0 && isset($nodes[$cursor]) && $guard < 64
               && intval($nodes[$cursor]['node_type_id']) === 2) {
            $pathParts[] = sbmDecode($nodes[$cursor]['name']);
            $cursor = intval($nodes[$cursor]['parent_id']);
            $guard++;
        }
        $suitePaths[$sid] = implode(' / ', array_reverse($pathParts));
    }
}
// Display order: by full path, then id.
uksort($suiteNames, function ($a, $b) use ($suitePaths) {
    $c = strcasecmp($suitePaths[$a], $suitePaths[$b]);
    if ($c !== 0) {
        return $c;
    }
    return intval($a) - intval($b);
});
$suiteIds = array_keys($suiteNames);
$suiteIds = array_map('intval', $suiteIds);

// --- the builds kept in the matrix --------------------------------------
$buildIds = array_keys($buildNames);
$buildIds = array_map('intval', $buildIds);
if ($buildFilterId > 0) {
    $buildIds = array($buildFilterId);
}

$statuses = sbmLatestStatuses($db, $tbl, $requestedPlanId, $buildIds);

// --- build the cells + totals -------------------------------------------
$cells = array();
$buildTotals = array();
foreach ($buildIds as $bid) {
    $buildTotals[$bid] = array(
        'assigned' => 0, 'executed' => 0, 'passed' => 0, 'failed' => 0,
        'blocked' => 0, 'not_run' => 0);
}
$suiteTotals = array();
foreach ($suiteIds as $sid) {
    $suiteTotals[$sid] = array(
        'assigned' => 0, 'executed' => 0, 'passed' => 0, 'failed' => 0,
        'blocked' => 0, 'not_run' => 0);
}
$overall = array(
    'assigned' => 0, 'executed' => 0, 'passed' => 0, 'failed' => 0,
    'blocked' => 0, 'not_run' => 0);

foreach ($suiteIds as $sid) {
    foreach ($buildIds as $bid) {
        $universe = isset($universes[$sid]) ? $universes[$sid] : array();
        $cellStatuses = array();
        foreach ($universe as $tid => $true) {
            $key = $bid . ':' . $tid;
            if (isset($statuses[$key])) {
                $cellStatuses[$tid] = $statuses[$key];
            }
        }
        $cell = sbmCountCell($universe, $cellStatuses);
        $cell['suite_id'] = $sid;
        $cell['build_id'] = $bid;
        $cells[] = $cell;
        sbmAccumulate($suiteTotals[$sid], $cell);
        sbmAccumulate($buildTotals[$bid], $cell);
        sbmAccumulate($overall, $cell);
    }
}

$suitesOut = array();
foreach ($suiteIds as $sid) {
    $row = sbmFinishTotals($suiteTotals[$sid]);
    $row['id'] = $sid;
    $row['name'] = $suiteNames[$sid];
    $row['path'] = $suitePaths[$sid];
    $suitesOut[] = $row;
}
$buildsOut = array();
foreach ($buildIds as $bid) {
    $row = sbmFinishTotals($buildTotals[$bid]);
    $row['id'] = $bid;
    $row['name'] = $buildNames[$bid];
    $row['active'] = $buildMeta[$bid]['active'];
    $row['is_open'] = $buildMeta[$bid]['is_open'];
    $buildsOut[] = $row;
}
$summary = sbmFinishTotals($overall);
$summary['builds'] = count($buildIds);
$summary['suites'] = count($suiteIds);
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

sbmOut(array(
    'status' => 'ok',
    'code' => 'ok',
    'context' => array(
        'tproject_id' => $owningProjectId,
        'tproject_name' => sbmDecode($projectName),
        'tplan_id' => $requestedPlanId,
        'tplan_name' => sbmDecode($planName),
        'build_id' => $buildFilterId,
        'build' => $buildFilterMeta,
    ),
    'filters' => array(
        'builds' => array_values($buildMeta),
    ),
    'summary' => $summary,
    'suites' => $suitesOut,
    'builds' => $buildsOut,
    'cells' => $cells,
));
