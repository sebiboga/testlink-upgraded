<?php
/**
 * Test Plan Suite x Platform Coverage Matrix report - BFF (Refs #1996).
 *
 * Net-new modern screen (gui/templates/results/suitePlatformMatrix.html).
 * 1.9.20 reports could show one suite for one build, every build for one plan
 * (resultsByBuild) or every assigned case against every platform
 * (platformMatrix). The pairwise suite x platform question - "which suite is
 * green on which platform?" - had to be stitched by eye. This report closes the
 * suite/build/platform triangle started by #1987, #1992 and #1993.
 *
 * Semantics
 *   - unit: a (test-case version, platform) assignment of the plan;
 *   - rows: the DIRECT PARENT suite of the test case (like resultsByTSuite);
 *   - columns: every platform the plan links, plus any platform still present
 *     on an assignment or an execution;
 *   - a version assigned with platform_id = 0 applies to every platform;
 *   - the CURRENT status of a (version, platform) is the status of its most
 *     recent execution (MAX(executions.id)) across every build of the plan;
 *   - a cell with no applicable assignment is "na" (not counted as not-run).
 *
 * Actions
 *   GET|HEAD ?action=projects
 *     -> projects the user can read
 *   GET|HEAD ?action=init&tproject_id=N[&tplan_id=P]
 *     -> project context + plans + platform filter vocabulary
 *   GET|HEAD ?action=report&tplan_id=N[&tproject_id=P][&platform_id=PL]
 *     -> summary KPIs, per-suite totals, per-platform totals and the cells
 *
 * Security: session authentication, the shared BFF same-origin guard and the
 * `testplan_metrics` right on the OWNING test project of the plan. The client
 * supplied tproject_id is only an ASSERTION.
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
function spmOut($payload, $code = null)
{
    if ($code !== null) {
        http_response_code($code);
    }
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function spmFail($httpCode, $code, $message = '')
{
    spmOut(array(
        'status' => 'error',
        'code' => $code,
        'message' => $message !== '' ? $message : $code,
    ), $httpCode);
}

/** Strict positive integer reader - "1abc", "1.9", "-3" and "" are refused. */
function spmPositiveInt($raw)
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
function spmStatusKey($raw)
{
    switch ((string)$raw) {
        case 'p': return 'passed';
        case 'f': return 'failed';
        case 'b': return 'blocked';
        default:  return 'not_run';
    }
}

function spmPct($num, $den)
{
    if ($den <= 0) {
        return 0.0;
    }
    return round(100.0 * $num / $den, 1);
}

function spmDecode($value)
{
    return html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/** Read access to a test project (used for the project picker). */
function spmCanViewProject($user, $db, $tpid)
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
 * Latest status per (platform, test-case version) across every build.
 *
 * @param array $platformIds list of int platform ids
 * @return array "$platformId:$tcversionId" => status key
 */
function spmLatestStatuses($db, $tbl, $planId, $platformIds)
{
    if (count($platformIds) === 0) {
        return array();
    }
    $where = "testplan_id = " . intval($planId) .
             " AND platform_id IN (" . implode(',', array_map('intval', $platformIds)) . ")";
    $sql =
        "SELECT e.platform_id AS platform_id, e.tcversion_id AS tcversion_id, " .
        "e.status AS status " .
        "FROM {$tbl['executions']} e " .
        "INNER JOIN (SELECT platform_id, tcversion_id, MAX(id) AS mid " .
        "FROM {$tbl['executions']} WHERE {$where} " .
        "GROUP BY platform_id, tcversion_id) x ON x.mid = e.id";
    $out = array();
    foreach ((array)$db->get_recordset($sql) as $r) {
        $key = intval($r['platform_id']) . ':' . intval($r['tcversion_id']);
        $out[$key] = spmStatusKey($r['status']);
    }
    return $out;
}

/** Count the statuses of one universe against a status map. */
function spmCountCell($universe, $statuses)
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
    $counts['pass_rate'] = spmPct($counts['passed'], $counts['executed']);
    $counts['coverage'] = spmPct($counts['executed'], $counts['assigned']);
    return $counts;
}

/** Accumulate one cell into a totals bucket. */
function spmAccumulate(&$totals, $cell)
{
    foreach (array('assigned', 'executed', 'passed', 'failed', 'blocked', 'not_run') as $k) {
        $totals[$k] += intval($cell[$k]);
    }
}

function spmFinishTotals($totals)
{
    $totals['pass_rate'] = spmPct($totals['passed'], $totals['executed']);
    $totals['coverage'] = spmPct($totals['executed'], $totals['assigned']);
    return $totals;
}

// ---------------------------------------------------------------------------
// Method / session
// ---------------------------------------------------------------------------
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    spmFail(405, 'method_not_allowed');
}

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    spmFail(401, 'not_authenticated', 'Not authenticated');
}

$db = new database(DB_TYPE);
doDBConnect($db);
bffEnforceSession($db);

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    spmFail(401, 'not_authenticated', 'User not found');
}

if (is_array($_GET['action'] ?? null)) {
    spmFail(400, 'invalid_parameter', 'Invalid action');
}
$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if (!in_array($action, array('projects', 'init', 'report'), true)) {
    spmFail(400, 'unknown_action', 'Unknown action');
}

$tbl = tlObjectWithDB::getDBTables(array(
    'testplans', 'testprojects', 'nodes_hierarchy', 'platforms',
    'testplan_platforms', 'testplan_tcversions', 'tcversions', 'executions',
));

$assertedProjectId = spmPositiveInt($_GET['tproject_id'] ?? null);
$requestedPlanId = spmPositiveInt($_GET['tplan_id'] ?? null);
$platformFilterId = spmPositiveInt($_GET['platform_id'] ?? null);

/** Platform ids the plan links (active), plus assignment/execution platforms. */
function spmPlanPlatformIds($db, $tbl, $planId, $platformName, $readAssignments)
{
    $ids = array();
    foreach ((array)$db->get_recordset(
        "SELECT platform_id FROM {$tbl['testplan_platforms']} WHERE testplan_id = " .
        intval($planId) . " AND active = 1") as $r) {
        $pid = intval($r['platform_id']);
        if ($pid > 0 && isset($platformName[$pid])) {
            $ids[$pid] = true;
        }
    }
    if ($readAssignments) {
        foreach ((array)$db->get_recordset(
            "SELECT DISTINCT platform_id FROM {$tbl['testplan_tcversions']} WHERE testplan_id = " .
            intval($planId) . " AND platform_id > 0") as $r) {
            $pid = intval($r['platform_id']);
            if (isset($platformName[$pid])) {
                $ids[$pid] = true;
            }
        }
    }
    foreach ((array)$db->get_recordset(
        "SELECT DISTINCT platform_id FROM {$tbl['executions']} WHERE testplan_id = " .
        intval($planId) . " AND platform_id > 0") as $r) {
        $pid = intval($r['platform_id']);
        if (isset($platformName[$pid])) {
            $ids[$pid] = true;
        }
    }
    return array_map('intval', array_keys($ids));
}

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
        if (!spmCanViewProject($user, $db, $pid)) {
            continue;
        }
        $projects[] = array(
            'id' => $pid,
            'name' => spmDecode($r['name']),
            'prefix' => (string)$r['prefix'],
        );
    }
    spmOut(array('status' => 'ok', 'code' => 'ok', 'projects' => $projects));
}

// ---------------------------------------------------------------------------
// init: project context + plans + the platform filter
// ---------------------------------------------------------------------------
if ($action === 'init') {
    $tpid = ($assertedProjectId > 0) ? $assertedProjectId
                                     : intval($_SESSION['testprojectID'] ?? 0);
    if ($tpid <= 0) {
        spmFail(400, 'invalid_request', 'Missing or invalid tproject_id');
    }
    if (!spmCanViewProject($user, $db, $tpid)) {
        tLog('BFF suiteplatformmatrix: user ' . intval($userId) .
             ' has no read right on tproject ' . $tpid, 'AUDIT');
        spmFail(403, 'no_right', 'Read right required on the test project');
    }

    $projectRow = (array)$db->get_recordset(
        "SELECT p.id AS id, p.prefix AS prefix, nh.name AS name " .
        "FROM {$tbl['testprojects']} p " .
        "INNER JOIN {$tbl['nodes_hierarchy']} nh ON nh.id = p.id AND nh.node_type_id = 1 " .
        "WHERE p.id = " . $tpid);
    if (count($projectRow) === 0) {
        spmFail(404, 'tproject_not_found', 'Test project not found');
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
            'name' => spmDecode($r['name']),
            'active' => intval($r['active']) ? true : false,
            'is_open' => intval($r['is_open']) ? true : false,
        );
    }

    $platformName = array();
    foreach ((array)$db->get_recordset(
        "SELECT id, name FROM {$tbl['platforms']} WHERE testproject_id = " . $tpid .
        " ORDER BY name, id") as $r) {
        $platformName[intval($r['id'])] = spmDecode($r['name']);
    }
    $platformIds = spmPlanPlatformIds($db, $tbl, $requestedPlanId, $platformName,
                                      $requestedPlanId > 0);
    if (count($platformIds) === 0) {
        $platformIds = array_map('intval', array_keys($platformName));
        sort($platformIds, SORT_NUMERIC);
    }
    $platforms = array();
    foreach ($platformIds as $pid) {
        $platforms[] = array('id' => intval($pid), 'name' => $platformName[$pid]);
    }

    spmOut(array(
        'status' => 'ok',
        'code' => 'ok',
        'context' => array(
            'tproject_id' => $tpid,
            'tproject_name' => spmDecode($projectRow[0]['name']),
            'tproject_prefix' => (string)$projectRow[0]['prefix'],
            'tplan_id' => $requestedPlanId,
        ),
        'plans' => $plans,
        'filters' => array('platforms' => $platforms),
    ));
}

// ---------------------------------------------------------------------------
// report: the suite x platform coverage matrix
// ---------------------------------------------------------------------------
if ($requestedPlanId <= 0) {
    spmFail(400, 'invalid_request', 'Missing or invalid tplan_id');
}

$planRow = (array)$db->get_recordset(
    "SELECT id, testproject_id FROM {$tbl['testplans']} WHERE id = " . $requestedPlanId);
if (count($planRow) === 0) {
    spmFail(404, 'tplan_not_found', 'Test plan not found');
}
$owningProjectId = intval($planRow[0]['testproject_id']);
if ($owningProjectId <= 0) {
    spmFail(404, 'tplan_not_found', 'Test plan not found');
}

if (!$user->hasRight($db, 'testplan_metrics', $owningProjectId, $requestedPlanId, true)) {
    tLog('BFF suiteplatformmatrix: user ' . intval($userId) .
         ' has no testplan_metrics right on tproject ' . $owningProjectId .
         ' (tplan ' . $requestedPlanId . ')', 'AUDIT');
    spmFail(403, 'no_right', 'testplan_metrics right required');
}

if ($assertedProjectId > 0 && $assertedProjectId !== $owningProjectId) {
    spmFail(404, 'project_mismatch', 'Test plan belongs to another test project');
}

$planName = testplan::getName($db, $requestedPlanId);
$projectName = testproject::getName($db, $owningProjectId);
if (is_null($planName) || trim((string)$planName) === '') {
    spmFail(404, 'tplan_not_found', 'Test plan not found');
}

$platformName = array();
$platformMeta = array();
foreach ((array)$db->get_recordset(
    "SELECT id, name FROM {$tbl['platforms']} WHERE testproject_id = " . $owningProjectId .
    " ORDER BY name, id") as $r) {
    $pid = intval($r['id']);
    $platformName[$pid] = spmDecode($r['name']);
    $platformMeta[$pid] = array('id' => $pid, 'name' => spmDecode($r['name']));
}
if ($platformFilterId > 0 && !isset($platformName[$platformFilterId])) {
    spmFail(400, 'invalid_request', 'platform_id does not belong to this test project');
}

$allPlatformIds = spmPlanPlatformIds($db, $tbl, $requestedPlanId, $platformName, true);
sort($allPlatformIds, SORT_NUMERIC);
$allPlatforms = array();
foreach ($allPlatformIds as $pid) {
    $allPlatforms[] = $platformMeta[$pid];
}

$platformIds = $allPlatformIds;
if ($platformFilterId > 0) {
    $platformIds = array($platformFilterId);
}

// --- the universes: (version, platform) assignments grouped by suite --------
$assignSql =
    "SELECT tpt.tcversion_id AS tcversion_id, tpt.platform_id AS assign_platform, " .
    "tc.parent_id AS tcase_id, tcase.parent_id AS suite_id " .
    "FROM {$tbl['testplan_tcversions']} tpt " .
    "JOIN {$tbl['nodes_hierarchy']} tc ON tc.id = tpt.tcversion_id " .
    "JOIN {$tbl['nodes_hierarchy']} tcase ON tcase.id = tc.parent_id " .
    "WHERE tpt.testplan_id = " . $requestedPlanId .
    " GROUP BY tpt.tcversion_id, tpt.platform_id, tc.parent_id, tcase.parent_id";
$assignments = array();   // [$suiteId][$tcv] = ['global'=>bool, 'platforms'=>[pid=>true]]
$distinct = array();
$suiteIds = array();
foreach ((array)$db->get_recordset($assignSql) as $r) {
    $sid = intval($r['suite_id']);
    $tid = intval($r['tcversion_id']);
    $apid = intval($r['assign_platform']);
    if ($sid <= 0 || $tid <= 0) {
        continue;
    }
    if (!isset($assignments[$sid])) {
        $assignments[$sid] = array();
        $suiteIds[$sid] = true;
    }
    if (!isset($assignments[$sid][$tid])) {
        $assignments[$sid][$tid] = array('global' => false, 'platforms' => array());
    }
    if ($apid <= 0) {
        $assignments[$sid][$tid]['global'] = true;
    } else if (isset($platformName[$apid])) {
        $assignments[$sid][$tid]['platforms'][$apid] = true;
    }
    $applies = ($apid <= 0) || in_array($apid, $platformIds, true);
    if ($applies) {
        $distinct[intval($r['tcase_id'])] = true;
    }
}
$suiteIds = array_map('intval', array_keys($suiteIds));

// --- resolve suite names + paths (walk type-2 ancestors) -----------------
$suiteNames = array();
$suitePaths = array();
if (count($suiteIds) > 0) {
    $nodes = array();
    foreach ((array)$db->get_recordset(
        "SELECT id, name, parent_id, node_type_id FROM {$tbl['nodes_hierarchy']} " .
        "WHERE id IN (" . implode(',', $suiteIds) . ")") as $r) {
        $nodes[intval($r['id'])] = $r;
    }
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
        foreach ((array)$db->get_recordset(
            "SELECT id, name, parent_id, node_type_id FROM {$tbl['nodes_hierarchy']} " .
            "WHERE id IN (" . implode(',', $batch) . ")") as $r) {
            $nid = intval($r['id']);
            $nodes[$nid] = $r;
            $fetched[$nid] = true;
            if (intval($r['node_type_id']) === 2) {
                $pending[intval($r['parent_id'])] = true;
            }
        }
    }
    foreach ($suiteIds as $sid) {
        $name = isset($nodes[$sid]) ? spmDecode($nodes[$sid]['name']) : ('#' . $sid);
        $suiteNames[$sid] = $name;
        $pathParts = array($name);
        $cursor = isset($nodes[$sid]) ? intval($nodes[$sid]['parent_id']) : 0;
        $guard = 0;
        while ($cursor > 0 && isset($nodes[$cursor]) && $guard < 64
               && intval($nodes[$cursor]['node_type_id']) === 2) {
            $pathParts[] = spmDecode($nodes[$cursor]['name']);
            $cursor = intval($nodes[$cursor]['parent_id']);
            $guard++;
        }
        $suitePaths[$sid] = implode(' / ', array_reverse($pathParts));
    }
}
uksort($suiteNames, function ($a, $b) use ($suitePaths) {
    $c = strcasecmp($suitePaths[$a], $suitePaths[$b]);
    if ($c !== 0) {
        return $c;
    }
    return intval($a) - intval($b);
});
$suiteIds = array_map('intval', array_keys($suiteNames));

$statuses = spmLatestStatuses($db, $tbl, $requestedPlanId, $platformIds);

// --- build the cells + totals -------------------------------------------
$cells = array();
$platformTotals = array();
foreach ($platformIds as $pid) {
    $platformTotals[$pid] = array(
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

$foundSuiteIds = array();
foreach ($suiteIds as $sid) {
    foreach ($platformIds as $pid) {
        $universe = array();
        foreach ((isset($assignments[$sid]) ? $assignments[$sid] : array()) as $tid => $a) {
            if ($a['global'] || isset($a['platforms'][$pid])) {
                $universe[$tid] = true;
            }
        }
        if (count($universe) === 0) {
            continue;
        }
        $foundSuiteIds[$sid] = true;
        $cellStatuses = array();
        foreach ($universe as $tid => $true) {
            $key = $pid . ':' . $tid;
            if (isset($statuses[$key])) {
                $cellStatuses[$tid] = $statuses[$key];
            }
        }
        $cell = spmCountCell($universe, $cellStatuses);
        $cell['suite_id'] = $sid;
        $cell['platform_id'] = $pid;
        $cells[] = $cell;
        spmAccumulate($suiteTotals[$sid], $cell);
        spmAccumulate($platformTotals[$pid], $cell);
        spmAccumulate($overall, $cell);
    }
}
// Keep only suites that are actually applicable to the filtered platforms.
$suiteIds = array_values(array_filter($suiteIds, function ($sid) use ($foundSuiteIds) {
    return isset($foundSuiteIds[$sid]);
}));
$suiteIds = array_map('intval', $suiteIds);

$suitesOut = array();
foreach ($suiteIds as $sid) {
    $row = spmFinishTotals($suiteTotals[$sid]);
    $row['id'] = $sid;
    $row['name'] = $suiteNames[$sid];
    $row['path'] = $suitePaths[$sid];
    $suitesOut[] = $row;
}
$platformsOut = array();
foreach ($platformIds as $pid) {
    $row = spmFinishTotals($platformTotals[$pid]);
    $row['id'] = $pid;
    $row['name'] = $platformName[$pid];
    $platformsOut[] = $row;
}
$summary = spmFinishTotals($overall);
$summary['platforms'] = count($platformIds);
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

$platformFilterMeta = ($platformFilterId > 0) ? $platformMeta[$platformFilterId] : null;

spmOut(array(
    'status' => 'ok',
    'code' => 'ok',
    'context' => array(
        'tproject_id' => $owningProjectId,
        'tproject_name' => spmDecode($projectName),
        'tplan_id' => $requestedPlanId,
        'tplan_name' => spmDecode($planName),
        'platform_id' => $platformFilterId,
        'platform' => $platformFilterMeta,
    ),
    'filters' => array(
        'platforms' => $allPlatforms,
    ),
    'summary' => $summary,
    'suites' => $suitesOut,
    'platforms' => $platformsOut,
    'cells' => $cells,
));
