<?php
/**
 * Test Plan Comparison report - BFF (Refs #1957).
 *
 * Net-new modern screen (gui/templates/results/planComparison.html).
 * TestLink 1.9.20 (and 2.0.1) exposes only SINGLE plan reports: every entry
 * of the Reports chapter is scoped to one test plan, so answering "how does
 * the regression plan compare with the smoke plan?" meant opening two report
 * tabs and comparing the numbers by eye. This BFF compares two test plans of
 * the SAME test project side by side and adds the delta (B - A) per metric.
 *
 * Semantics (documented here because the screen relies on them):
 *   - the unit is the test-case VERSION (tcversion_id) linked to the plan
 *     through testplan_tcversions, never the test case;
 *   - with a platform filter the universe is the versions linked ON THAT
 *     PLATFORM (the platform is part of the assignment in TestLink);
 *   - the CURRENT status of a version is the status of its most recent
 *     execution inside the analysed scope (MAX(executions.id)) - the same
 *     "latest result wins" rule the other 2.0.1 reports use;
 *   - a version executed in the scope but NO LONGER linked to the plan is
 *     ignored (it is not part of the plan any more);
 *   - a version linked to the plan but never executed in the scope counts as
 *     NOT RUN;
 *   - the per-build / per-platform breakdown counts a version ONCE PER
 *     build / platform it was executed in, using the latest execution of
 *     that version inside that build / platform (so a version run on three
 *     builds appears three times, once per build).
 *
 * Actions
 *   GET|HEAD ?action=projects
 *       -> { status, projects:[{id,name,prefix}] } - the projects the user
 *          may read (mgt_view_tc / mgt_modify_tc on the project)
 *   GET|HEAD ?action=init&tproject_id=N[&tplan_id=A]
 *       -> context (project) + the test plans of the project + the build and
 *          platform filters
 *   GET|HEAD ?action=report&tplan_a=N&tplan_b=M[&tproject_id=P]
 *             [&build_id=B][&platform_id=PL]
 *       -> the two metric blocks, the comparison rows with their delta and
 *          the per-build / per-platform breakdowns
 *
 * Security: session authentication, the shared BFF same-origin guard and
 * the `testplan_metrics` right on the OWNING test project of the compared
 * plans (getAccess = true). The client supplied tproject_id is only an
 * ASSERTION: it is compared with the plans' owning project and never used to
 * widen the query. Both plans must belong to the SAME project - comparing
 * plans of two different projects is refused (invalid_request) instead of
 * silently comparing incomparable data.
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
function pcOut($payload, $code = null)
{
    if ($code !== null) {
        http_response_code($code);
    }
    echo json_encode($payload);
    exit;
}

function pcFail($httpCode, $code, $message = '')
{
    pcOut(array(
        'status' => 'error',
        'code' => $code,
        'message' => $message !== '' ? $message : $code,
    ), $httpCode);
}

/** Strict positive integer reader - "1abc", "1.9", "-3" and "" are refused. */
function pcPositiveInt($raw)
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
function pcStatusKey($raw)
{
    switch ((string)$raw) {
        case 'p': return 'passed';
        case 'f': return 'failed';
        case 'b': return 'blocked';
        default:  return 'not_run';
    }
}

function pcPct($num, $den)
{
    if ($den <= 0) {
        return 0.0;
    }
    return round(100.0 * $num / $den, 1);
}

function pcDecode($value)
{
    return html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/** Read access to a test project (used for the project picker). */
function pcCanViewProject($user, $db, $tpid)
{
    if (intval($tpid) <= 0) {
        return false;
    }
    foreach (array('mgt_view_tc', 'mgt_modify_tc') as $right) {
        if ($user->hasRight($db, $right, $tpid) === 'yes') {
            return true;
        }
    }
    return false;
}

/**
 * Compute the metric block of one test plan.
 *
 * @param int   $planId     the test plan
 * @param int   $projectId  the OWNING test project (for the build / platform lists)
 * @param int   $buildId    build filter (0 = all)
 * @param int   $platformId platform filter (0 = all)
 * @return array metrics + the per-build / per-platform breakdowns
 */
function pcPlanMetrics($db, $tbl, $planId, $projectId, $buildId, $platformId)
{
    $planId = intval($planId);

    // --- universe: the test-case versions linked to the plan ---------------
    $universeSql =
        "SELECT DISTINCT tpt.tcversion_id " .
        "FROM {$tbl['testplan_tcversions']} tpt " .
        "WHERE tpt.testplan_id = " . $planId;
    if ($platformId > 0) {
        $universeSql .= " AND tpt.platform_id = " . intval($platformId);
    }
    $universe = array();
    $rs = $db->get_recordset($universeSql);
    if (is_array($rs)) {
        foreach ($rs as $r) {
            $universe[intval($r['tcversion_id'])] = true;
        }
    }

    // --- the analysed scope of the executions ------------------------------
    $where = "testplan_id = " . $planId;
    if ($buildId > 0) {
        $where .= " AND build_id = " . intval($buildId);
    }
    if ($platformId > 0) {
        $where .= " AND platform_id = " . intval($platformId);
    }

    // --- current status per version (latest execution wins) ---------------
    $latestSql =
        "SELECT e.tcversion_id AS tcversion_id, e.status AS status, " .
        "e.build_id AS build_id, e.platform_id AS platform_id " .
        "FROM {$tbl['executions']} e " .
        "INNER JOIN (SELECT tcversion_id, MAX(id) AS mid FROM {$tbl['executions']} " .
        "WHERE {$where} GROUP BY tcversion_id) x ON x.mid = e.id";
    $latestRows = (array)$db->get_recordset($latestSql);

    $counts = array('executed' => 0, 'passed' => 0, 'failed' => 0, 'blocked' => 0);
    $executedVersions = array();
    foreach ($latestRows as $r) {
        $tid = intval($r['tcversion_id']);
        if (!isset($universe[$tid])) {
            // executed but no longer linked to the plan -> not part of it
            continue;
        }
        $executedVersions[$tid] = true;
        $counts['executed']++;
        $key = pcStatusKey($r['status']);
        if ($key === 'not_run') {
            // an execution always carries a status; a NULL / unknown status
            // is treated as "not run" and must not inflate the counters
            $counts['executed']--;
            unset($executedVersions[$tid]);
            continue;
        }
        $counts[$key]++;
    }
    $assigned = count($universe);
    $notRun = $assigned - $counts['executed'];
    if ($notRun < 0) {
        $notRun = 0;
    }

    // --- defects (distinct bug references linked to the executions) --------
    $defectRows = (array)$db->get_recordset(
        "SELECT COUNT(DISTINCT eb.bug_id) AS qty, COUNT(*) AS links " .
        "FROM {$tbl['execution_bugs']} eb " .
        "INNER JOIN {$tbl['executions']} e ON e.id = eb.execution_id " .
        "WHERE {$where} AND eb.bug_id <> ''");
    $defects = 0;
    $defectLinks = 0;
    if (count($defectRows) > 0) {
        $defects = intval($defectRows[0]['qty']);
        $defectLinks = intval($defectRows[0]['links']);
    }

    // --- per build breakdown (latest execution per version AND build) -----
    $buildRows = (array)$db->get_recordset(
        "SELECT b.id AS id, b.name AS name FROM {$tbl['builds']} b " .
        "WHERE b.testproject_id = " . intval($projectId) . " ORDER BY b.id");
    $builds = array();
    foreach ($buildRows as $r) {
        $builds[] = array(
            'id' => intval($r['id']),
            'name' => pcDecode($r['name']),
            'executed' => 0, 'passed' => 0, 'failed' => 0, 'blocked' => 0,
        );
    }
    $buildIndex = array();
    foreach ($builds as $i => $b) {
        $buildIndex[$b['id']] = $i;
    }
    $perBuildSql =
        "SELECT e.tcversion_id AS tcversion_id, e.build_id AS build_id, e.status AS status " .
        "FROM {$tbl['executions']} e " .
        "INNER JOIN (SELECT tcversion_id, build_id, MAX(id) AS mid FROM {$tbl['executions']} " .
        "WHERE {$where} GROUP BY tcversion_id, build_id) x ON x.mid = e.id";
    foreach ((array)$db->get_recordset($perBuildSql) as $r) {
        $tid = intval($r['tcversion_id']);
        if (!isset($universe[$tid])) {
            continue;
        }
        $bid = intval($r['build_id']);
        if (!isset($buildIndex[$bid])) {
            continue;
        }
        $key = pcStatusKey($r['status']);
        if ($key === 'not_run') {
            continue;
        }
        $idx = $buildIndex[$bid];
        $builds[$idx]['executed']++;
        $builds[$idx][$key]++;
    }

    // --- per platform breakdown (platforms linked to the plan) -------------
    $platformRows = (array)$db->get_recordset(
        "SELECT p.id AS id, p.name AS name " .
        "FROM {$tbl['testplan_platforms']} tpp " .
        "INNER JOIN {$tbl['platforms']} p ON p.id = tpp.platform_id " .
        "WHERE tpp.testplan_id = " . $planId . " ORDER BY p.name");
    $platforms = array();
    foreach ($platformRows as $r) {
        $platforms[] = array(
            'id' => intval($r['id']),
            'name' => pcDecode($r['name']),
            'executed' => 0, 'passed' => 0, 'failed' => 0, 'blocked' => 0,
        );
    }
    $platformIndex = array();
    foreach ($platforms as $i => $p) {
        $platformIndex[$p['id']] = $i;
    }
    $perPlatformSql =
        "SELECT e.tcversion_id AS tcversion_id, e.platform_id AS platform_id, e.status AS status " .
        "FROM {$tbl['executions']} e " .
        "INNER JOIN (SELECT tcversion_id, platform_id, MAX(id) AS mid FROM {$tbl['executions']} " .
        "WHERE {$where} GROUP BY tcversion_id, platform_id) x ON x.mid = e.id";
    foreach ((array)$db->get_recordset($perPlatformSql) as $r) {
        $tid = intval($r['tcversion_id']);
        if (!isset($universe[$tid])) {
            continue;
        }
        $pid = intval($r['platform_id']);
        if (!isset($platformIndex[$pid])) {
            continue;
        }
        $key = pcStatusKey($r['status']);
        if ($key === 'not_run') {
            continue;
        }
        $idx = $platformIndex[$pid];
        $platforms[$idx]['executed']++;
        $platforms[$idx][$key]++;
    }

    $metrics = array(
        'assigned' => $assigned,
        'executed' => $counts['executed'],
        'passed' => $counts['passed'],
        'failed' => $counts['failed'],
        'blocked' => $counts['blocked'],
        'not_run' => $notRun,
        'pass_rate' => pcPct($counts['passed'], $counts['executed']),
        'coverage' => pcPct($counts['executed'], $assigned),
        'defects' => $defects,
        'defect_links' => $defectLinks,
        'builds' => count($builds),
        'platforms' => count($platforms),
    );

    return array(
        'metrics' => $metrics,
        'builds' => $builds,
        'platforms' => $platforms,
    );
}

// ---------------------------------------------------------------------------
// Method / session
// ---------------------------------------------------------------------------
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    pcFail(405, 'method_not_allowed');
}

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    pcFail(401, 'not_authenticated', 'Not authenticated');
}

bffEnforceSession($db);

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    pcFail(401, 'not_authenticated', 'User not found');
}

$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if (!in_array($action, array('projects', 'init', 'report'), true)) {
    pcFail(400, 'unknown_action', 'Unknown action');
}

$tbl = tlObjectWithDB::getDBTables(array(
    'testplans', 'testprojects', 'nodes_hierarchy', 'builds', 'platforms',
    'testplan_tcversions', 'tcversions', 'executions', 'execution_bugs',
    'testplan_platforms', 'users',
));

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
        if (!pcCanViewProject($user, $db, $pid)) {
            continue;
        }
        $projects[] = array(
            'id' => $pid,
            'name' => pcDecode($r['name']),
            'prefix' => (string)$r['prefix'],
        );
    }
    pcOut(array('status' => 'ok', 'code' => 'ok', 'projects' => $projects));
}

// ---------------------------------------------------------------------------
// init: project context + the test plans of the project + the filters
// ---------------------------------------------------------------------------
$assertedProjectId = pcPositiveInt($_GET['tproject_id'] ?? null);
$requestedPlanId = pcPositiveInt($_GET['tplan_id'] ?? null);
$buildId = pcPositiveInt($_GET['build_id'] ?? null);
$platformId = pcPositiveInt($_GET['platform_id'] ?? null);

if ($action === 'init') {
    $tpid = ($assertedProjectId > 0) ? $assertedProjectId : intval($_SESSION['testprojectID'] ?? 0);
    if ($tpid <= 0) {
        pcFail(400, 'invalid_request', 'Missing or invalid tproject_id');
    }
    // The right is checked on the ADDRESSED project (no project-id oracle).
    if (!pcCanViewProject($user, $db, $tpid)) {
        tLog('BFF plancomparison: user ' . intval($userId) .
             ' has no read right on tproject ' . $tpid, 'ERROR');
        pcFail(403, 'no_right', 'Read right required on the test project');
    }

    $projectRow = (array)$db->get_recordset(
        "SELECT p.id AS id, p.prefix AS prefix, nh.name AS name " .
        "FROM {$tbl['testprojects']} p " .
        "INNER JOIN {$tbl['nodes_hierarchy']} nh ON nh.id = p.id AND nh.node_type_id = 1 " .
        "WHERE p.id = " . $tpid);
    if (count($projectRow) === 0) {
        pcFail(404, 'tproject_not_found', 'Test project not found');
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
            'name' => pcDecode($r['name']),
            'active' => intval($r['active']) ? true : false,
            'is_open' => intval($r['is_open']) ? true : false,
        );
    }

    $buildRows = (array)$db->get_recordset(
        "SELECT id, name FROM {$tbl['builds']} WHERE testproject_id = " . $tpid .
        " AND active = 1 ORDER BY id");
    $builds = array();
    foreach ($buildRows as $r) {
        $builds[] = array('id' => intval($r['id']), 'name' => pcDecode($r['name']));
    }

    $platformRows = (array)$db->get_recordset(
        "SELECT id, name FROM {$tbl['platforms']} WHERE testproject_id = " . $tpid .
        " ORDER BY name");
    $platforms = array();
    foreach ($platformRows as $r) {
        $platforms[] = array('id' => intval($r['id']), 'name' => pcDecode($r['name']));
    }

    pcOut(array(
        'status' => 'ok',
        'code' => 'ok',
        'context' => array(
            'tproject_id' => $tpid,
            'tproject_name' => pcDecode($projectRow[0]['name']),
            'tproject_prefix' => (string)$projectRow[0]['prefix'],
            'tplan_id' => $requestedPlanId,
        ),
        'plans' => $plans,
        'filters' => array('builds' => $builds, 'platforms' => $platforms),
    ));
}

// ---------------------------------------------------------------------------
// report: the comparison itself
// ---------------------------------------------------------------------------
$planAId = pcPositiveInt($_GET['tplan_a'] ?? null);
$planBId = pcPositiveInt($_GET['tplan_b'] ?? null);

if ($planAId <= 0 || $planBId <= 0) {
    pcFail(400, 'invalid_request', 'Missing or invalid tplan_a / tplan_b');
}

/**
 * Resolve a test plan -> its owning project (opaque 404 when unknown).
 */
function pcResolvePlan($db, $tbl, $planId)
{
    $rows = (array)$db->get_recordset(
        "SELECT id, testproject_id FROM {$tbl['testplans']} WHERE id = " . intval($planId));
    if (count($rows) === 0) {
        return null;
    }
    $row = $rows[0];
    $projectId = intval($row['testproject_id']);
    if ($projectId <= 0) {
        return null;
    }
    return array('id' => intval($row['id']), 'testproject_id' => $projectId);
}

$planA = pcResolvePlan($db, $tbl, $planAId);
$planB = pcResolvePlan($db, $tbl, $planBId);
if (is_null($planA) || is_null($planB)) {
    pcFail(404, 'tplan_not_found', 'Test plan not found');
}

// Both plans must live in the same test project: plans of two different
// projects are not comparable (different requirements, different scope).
if ($planA['testproject_id'] !== $planB['testproject_id']) {
    pcFail(400, 'plans_not_comparable', 'Both test plans must belong to the same test project');
}

$owningProjectId = $planA['testproject_id'];

// The right is checked on the OWNING project of the compared plans.
if (!$user->hasRight($db, 'testplan_metrics', $owningProjectId, $planA['id'], true)) {
    tLog('BFF plancomparison: user ' . intval($userId) .
         ' has no testplan_metrics right on tproject ' . $owningProjectId .
         ' (tplan ' . $planA['id'] . ')', 'ERROR');
    pcFail(403, 'no_right', 'testplan_metrics right required');
}

if ($assertedProjectId > 0 && $assertedProjectId !== $owningProjectId) {
    pcFail(404, 'project_mismatch', 'Test plan belongs to another test project');
}

$planAName = testplan::getName($db, $planA['id']);
$planBName = testplan::getName($db, $planB['id']);
$projectName = testproject::getName($db, $owningProjectId);
if (is_null($planAName) || trim((string)$planAName) === '' ||
    is_null($planBName) || trim((string)$planBName) === '') {
    pcFail(404, 'tplan_not_found', 'Test plan not found');
}

// --- the build / platform filters must belong to the owning project -------
$buildRows = (array)$db->get_recordset(
    "SELECT id, name, active FROM {$tbl['builds']} WHERE testproject_id = " . $owningProjectId .
    " ORDER BY id");
$builds = array();
$buildNames = array();
foreach ($buildRows as $r) {
    $bid = intval($r['id']);
    $buildNames[$bid] = pcDecode($r['name']);
    if (intval($r['active'])) {
        $builds[] = array('id' => $bid, 'name' => $buildNames[$bid]);
    }
}
if ($buildId > 0 && !isset($buildNames[$buildId])) {
    pcFail(400, 'invalid_request', 'build_id does not belong to this test project');
}

$platformRows = (array)$db->get_recordset(
    "SELECT id, name FROM {$tbl['platforms']} WHERE testproject_id = " . $owningProjectId .
    " ORDER BY name");
$platforms = array();
$platformNames = array();
foreach ($platformRows as $r) {
    $pid = intval($r['id']);
    $platformNames[$pid] = pcDecode($r['name']);
    $platforms[] = array('id' => $pid, 'name' => $platformNames[$pid]);
}
if ($platformId > 0 && !isset($platformNames[$platformId])) {
    pcFail(400, 'invalid_request', 'platform_id does not belong to this test project');
}

$a = pcPlanMetrics($db, $tbl, $planA['id'], $owningProjectId, $buildId, $platformId);
$b = pcPlanMetrics($db, $tbl, $planB['id'], $owningProjectId, $buildId, $platformId);

// --- the comparison rows --------------------------------------------------
// type: int | pct   better: higher | lower | neutral
$metricDefs = array(
    array('key' => 'assigned', 'type' => 'int', 'better' => 'neutral'),
    array('key' => 'executed', 'type' => 'int', 'better' => 'neutral'),
    array('key' => 'passed', 'type' => 'int', 'better' => 'higher'),
    array('key' => 'failed', 'type' => 'int', 'better' => 'lower'),
    array('key' => 'blocked', 'type' => 'int', 'better' => 'lower'),
    array('key' => 'not_run', 'type' => 'int', 'better' => 'lower'),
    array('key' => 'pass_rate', 'type' => 'pct', 'better' => 'higher'),
    array('key' => 'coverage', 'type' => 'pct', 'better' => 'higher'),
    array('key' => 'defects', 'type' => 'int', 'better' => 'lower'),
    array('key' => 'builds', 'type' => 'int', 'better' => 'neutral'),
    array('key' => 'platforms', 'type' => 'int', 'better' => 'neutral'),
);
$comparison = array();
foreach ($metricDefs as $def) {
    $av = $a['metrics'][$def['key']];
    $bv = $b['metrics'][$def['key']];
    $delta = $bv - $av;
    if ($def['type'] === 'pct') {
        $delta = round($delta, 1);
    }
    $comparison[] = array(
        'key' => $def['key'],
        'type' => $def['type'],
        'better' => $def['better'],
        'a' => $av,
        'b' => $bv,
        'delta' => $delta,
    );
}

// --- per build / per platform breakdown (both plans side by side) --------
$buildBreakdown = array();
foreach ($a['builds'] as $i => $row) {
    $bid = $row['id'];
    $bRow = null;
    foreach ($b['builds'] as $cand) {
        if ($cand['id'] === $bid) {
            $bRow = $cand;
            break;
        }
    }
    $buildBreakdown[] = array(
        'id' => $bid,
        'name' => $row['name'],
        'a' => array(
            'executed' => intval($row['executed']),
            'passed' => intval($row['passed']),
            'failed' => intval($row['failed']),
            'blocked' => intval($row['blocked']),
        ),
        'b' => array(
            'executed' => $bRow ? intval($bRow['executed']) : 0,
            'passed' => $bRow ? intval($bRow['passed']) : 0,
            'failed' => $bRow ? intval($bRow['failed']) : 0,
            'blocked' => $bRow ? intval($bRow['blocked']) : 0,
        ),
    );
}

$platformBreakdown = array();
foreach ($a['platforms'] as $row) {
    $pid = $row['id'];
    $bRow = null;
    foreach ($b['platforms'] as $cand) {
        if ($cand['id'] === $pid) {
            $bRow = $cand;
            break;
        }
    }
    $platformBreakdown[] = array(
        'id' => $pid,
        'name' => $row['name'],
        'a' => array(
            'executed' => intval($row['executed']),
            'passed' => intval($row['passed']),
            'failed' => intval($row['failed']),
            'blocked' => intval($row['blocked']),
        ),
        'b' => array(
            'executed' => $bRow ? intval($bRow['executed']) : 0,
            'passed' => $bRow ? intval($bRow['passed']) : 0,
            'failed' => $bRow ? intval($bRow['failed']) : 0,
            'blocked' => $bRow ? intval($bRow['blocked']) : 0,
        ),
    );
}

pcOut(array(
    'status' => 'ok',
    'code' => 'ok',
    'context' => array(
        'tproject_id' => $owningProjectId,
        'tproject_name' => pcDecode($projectName),
        'tplan_a' => array('id' => $planA['id'], 'name' => pcDecode($planAName)),
        'tplan_b' => array('id' => $planB['id'], 'name' => pcDecode($planBName)),
    ),
    'filters' => array(
        'builds' => $builds,
        'platforms' => $platforms,
        'build_id' => $buildId,
        'platform_id' => $platformId,
    ),
    'plans' => array(
        'a' => array(
            'id' => $planA['id'],
            'name' => pcDecode($planAName),
            'metrics' => $a['metrics'],
        ),
        'b' => array(
            'id' => $planB['id'],
            'name' => pcDecode($planBName),
            'metrics' => $b['metrics'],
        ),
    ),
    'comparison' => $comparison,
    'build_breakdown' => $buildBreakdown,
    'platform_breakdown' => $platformBreakdown,
));
