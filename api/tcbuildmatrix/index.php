<?php
/**
 * Test Plan Test Case x Build Result Matrix report - BFF (Refs #1999).
 *
 * Net-new modern screen (gui/templates/results/tcBuildMatrix.html).
 * TestLink 1.9.20/2.0.1 reports aggregate a whole plan for one build
 * (resultsByBuild), one suite for one build (resultsByTSuite) or a suite /
 * build / platform cross-tab (suiteBuildMatrix, buildPlatformMatrix,
 * suitePlatformMatrix); none shows every individual TEST CASE against every
 * build, so "which test case is green in which build?" has to be stitched by
 * eye. This report is that missing per-test-case granularity.
 *
 * Semantics
 *   - the unit is one test-case VERSION currently linked to the plan in
 *     testplan_tcversions; a version linked on several platforms collapses to
 *     one row (the report measures the version, not the pin);
 *   - a build column is every build of the owning test project (inactive
 *     builds included, so history is never hidden); an optional build filter
 *     keeps a single column;
 *   - the CURRENT status of a version in a build is the status of its most
 *     recent execution (MAX(executions.id)) on that build across every
 *     platform - the "latest result wins" rule of the other 2.0.1 reports;
 *   - a version assigned but never executed in a build counts as NOT RUN.
 *
 * Actions
 *   GET|HEAD ?action=projects
 *     -> projects the user can read
 *   GET|HEAD ?action=init&tproject_id=N[&tplan_id=P]
 *     -> project context + plans + project builds
 *   GET|HEAD ?action=report&tplan_id=N[&tproject_id=P][&build_id=B]
 *     -> summary KPIs, per-version rows with per-build cells and per-build totals
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
function tcbmOut($payload, $code = null)
{
    if ($code !== null) {
        http_response_code($code);
    }
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function tcbmFail($httpCode, $code, $message = '')
{
    tcbmOut(array(
        'status' => 'error',
        'code' => $code,
        'message' => $message !== '' ? $message : $code,
    ), $httpCode);
}

/** Strict positive integer reader - "1abc", "1.9", "-3" and "" are refused. */
function tcbmPositiveInt($raw)
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
function tcbmStatusKey($raw)
{
    switch ((string)$raw) {
        case 'p': return 'passed';
        case 'f': return 'failed';
        case 'b': return 'blocked';
        default:  return 'not_run';
    }
}

function tcbmPct($num, $den)
{
    if ($den <= 0) {
        return 0.0;
    }
    return round(100.0 * $num / $den, 1);
}

function tcbmDecode($value)
{
    return html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/** Read access to a test project (used for the project picker). */
function tcbmCanViewProject($user, $db, $tpid)
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
 * The newest execution id wins; a single execution row has one id.
 *
 * @param array $buildIds list of int build ids
 * @return array "$buildId:$tcversionId" => status key
 */
function tcbmLatestStatuses($db, $tbl, $planId, $buildIds)
{
    if (count($buildIds) === 0) {
        return array();
    }
    $where = "testplan_id = " . intval($planId) .
             " AND build_id IN (" . implode(',', array_map('intval', $buildIds)) . ")" .
             " AND status IS NOT NULL AND status <> ''";
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
        $out[$key] = tcbmStatusKey($r['status']);
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Method / session
// ---------------------------------------------------------------------------
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    tcbmFail(405, 'method_not_allowed');
}

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    tcbmFail(401, 'not_authenticated', 'Not authenticated');
}

$db = new database(DB_TYPE);
doDBConnect($db);
bffEnforceSession($db);

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    tcbmFail(401, 'not_authenticated', 'User not found');
}

if (is_array($_GET['action'] ?? null)) {
    tcbmFail(400, 'invalid_parameter', 'Invalid action');
}
$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if (!in_array($action, array('projects', 'init', 'report'), true)) {
    tcbmFail(400, 'unknown_action', 'Unknown action');
}

$tbl = tlObjectWithDB::getDBTables(array(
    'testplans', 'testprojects', 'nodes_hierarchy', 'builds',
    'testplan_tcversions', 'tcversions', 'executions',
));

$assertedProjectId = tcbmPositiveInt($_GET['tproject_id'] ?? null);
$requestedPlanId = tcbmPositiveInt($_GET['tplan_id'] ?? null);

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
        if (!tcbmCanViewProject($user, $db, $pid)) {
            continue;
        }
        $projects[] = array(
            'id' => $pid,
            'name' => tcbmDecode($r['name']),
            'prefix' => (string)$r['prefix'],
        );
    }
    tcbmOut(array('status' => 'ok', 'code' => 'ok', 'projects' => $projects));
}

// ---------------------------------------------------------------------------
// init: project context + plans + the build filter
// ---------------------------------------------------------------------------
if ($action === 'init') {
    $tpid = ($assertedProjectId > 0) ? $assertedProjectId
                                     : intval($_SESSION['testprojectID'] ?? 0);
    if ($tpid <= 0) {
        tcbmFail(400, 'invalid_request', 'Missing or invalid tproject_id');
    }
    // The right is checked on the ADDRESSED project (no project-id oracle).
    if (!tcbmCanViewProject($user, $db, $tpid)) {
        tLog('BFF tcbuildmatrix: user ' . intval($userId) .
             ' has no read right on tproject ' . $tpid, 'AUDIT');
        tcbmFail(403, 'no_right', 'Read right required on the test project');
    }

    $projectRow = (array)$db->get_recordset(
        "SELECT p.id AS id, p.prefix AS prefix, nh.name AS name " .
        "FROM {$tbl['testprojects']} p " .
        "INNER JOIN {$tbl['nodes_hierarchy']} nh ON nh.id = p.id AND nh.node_type_id = 1 " .
        "WHERE p.id = " . $tpid);
    if (count($projectRow) === 0) {
        tcbmFail(404, 'tproject_not_found', 'Test project not found');
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
            'name' => tcbmDecode($r['name']),
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
            'name' => tcbmDecode($r['name']),
            'active' => intval($r['active']) ? true : false,
            'is_open' => intval($r['is_open']) ? true : false,
        );
    }

    tcbmOut(array(
        'status' => 'ok',
        'code' => 'ok',
        'context' => array(
            'tproject_id' => $tpid,
            'tproject_name' => tcbmDecode($projectRow[0]['name']),
            'tproject_prefix' => (string)$projectRow[0]['prefix'],
            'tplan_id' => $requestedPlanId,
        ),
        'plans' => $plans,
        'filters' => array('builds' => $builds),
    ));
}

// ---------------------------------------------------------------------------
// report: the test case x build result matrix
// ---------------------------------------------------------------------------
$buildFilterId = tcbmPositiveInt($_GET['build_id'] ?? null);

if ($requestedPlanId <= 0) {
    tcbmFail(400, 'invalid_request', 'Missing or invalid tplan_id');
}

$planRow = (array)$db->get_recordset(
    "SELECT id, testproject_id FROM {$tbl['testplans']} WHERE id = " . $requestedPlanId);
if (count($planRow) === 0) {
    tcbmFail(404, 'tplan_not_found', 'Test plan not found');
}
$owningProjectId = intval($planRow[0]['testproject_id']);
if ($owningProjectId <= 0) {
    tcbmFail(404, 'tplan_not_found', 'Test plan not found');
}

// The right is checked on the OWNING project of the plan.
if (!$user->hasRight($db, 'testplan_metrics', $owningProjectId, $requestedPlanId, true)) {
    tLog('BFF tcbuildmatrix: user ' . intval($userId) .
         ' has no testplan_metrics right on tproject ' . $owningProjectId .
         ' (tplan ' . $requestedPlanId . ')', 'AUDIT');
    tcbmFail(403, 'no_right', 'testplan_metrics right required');
}

if ($assertedProjectId > 0 && $assertedProjectId !== $owningProjectId) {
    tcbmFail(404, 'project_mismatch', 'Test plan belongs to another test project');
}

$planName = testplan::getName($db, $requestedPlanId);
$projectName = testproject::getName($db, $owningProjectId);
$projectPrefixRow = (array)$db->get_recordset(
    "SELECT prefix FROM {$tbl['testprojects']} WHERE id = " . $owningProjectId);
$projectPrefix = count($projectPrefixRow) > 0 ? (string)$projectPrefixRow[0]['prefix'] : '';
if (is_null($planName) || trim((string)$planName) === '') {
    tcbmFail(404, 'tplan_not_found', 'Test plan not found');
}

// --- builds of the owning project ---------------------------------------
$buildRows = (array)$db->get_recordset(
    "SELECT id, name, active, is_open FROM {$tbl['builds']} " .
    "WHERE testproject_id = " . $owningProjectId . " ORDER BY id ASC");
$buildNames = array();
$buildMeta = array();
foreach ($buildRows as $r) {
    $bid = intval($r['id']);
    $buildNames[$bid] = tcbmDecode($r['name']);
    $buildMeta[$bid] = array(
        'id' => $bid,
        'name' => tcbmDecode($r['name']),
        'active' => intval($r['active']) ? true : false,
        'is_open' => intval($r['is_open']) ? true : false,
    );
}
if ($buildFilterId > 0 && !isset($buildNames[$buildFilterId])) {
    tcbmFail(400, 'invalid_request', 'build_id does not belong to this test project');
}

// --- the assigned versions (one row per version) -------------------------
$versions = array();
$assignedRs = (array)$db->get_recordset(
    "SELECT tpt.tcversion_id AS tcversion_id, tv.version AS version, " .
    "tv.tc_external_id AS tc_external_id, tv.importance AS importance, " .
    "tn.name AS tcase_name, tn.id AS tcase_node_id " .
    "FROM {$tbl['testplan_tcversions']} tpt " .
    "JOIN {$tbl['tcversions']} tv ON tv.id = tpt.tcversion_id " .
    "JOIN {$tbl['nodes_hierarchy']} vn ON vn.id = tv.id AND vn.node_type_id = 4 " .
    "JOIN {$tbl['nodes_hierarchy']} tn ON tn.id = vn.parent_id AND tn.node_type_id = 3 " .
    "WHERE tpt.testplan_id = " . $requestedPlanId .
    " ORDER BY tpt.tcversion_id");
foreach ($assignedRs as $r) {
    $tcv = intval($r['tcversion_id']);
    if ($tcv <= 0 || isset($versions[$tcv])) {
        continue;
    }
    $versions[$tcv] = array(
        'tcversion_id' => $tcv,
        'version' => intval($r['version']),
        'external_id' => intval($r['tc_external_id']),
        'importance' => intval($r['importance']),
        'tcase_name' => tcbmDecode($r['tcase_name']),
        'tcase_node_id' => intval($r['tcase_node_id']),
    );
}

// --- suite path of every version in scope --------------------------------
$suitePaths = array();
if (count($versions) > 0) {
    $inList = implode(',', array_map('intval', array_keys($versions)));
    $pathSql =
        "WITH RECURSIVE anc AS (" .
        "  SELECT tn.id AS tcase_node, sn.id AS node_id, sn.name AS node_name, " .
        "         sn.parent_id, 0 AS depth " .
        "  FROM {$tbl['nodes_hierarchy']} tn " .
        "  JOIN {$tbl['nodes_hierarchy']} sn ON sn.id = tn.parent_id AND sn.node_type_id = 2 " .
        "  JOIN {$tbl['nodes_hierarchy']} vn ON vn.parent_id = tn.id AND vn.node_type_id = 4 " .
        "  JOIN {$tbl['tcversions']} tv ON tv.id = vn.id " .
        "  WHERE tv.id IN (" . $inList . ")" .
        "  UNION ALL " .
        "  SELECT a.tcase_node, pn.id, pn.name, pn.parent_id, a.depth + 1 " .
        "  FROM anc a " .
        "  JOIN {$tbl['nodes_hierarchy']} pn ON pn.id = a.parent_id AND pn.node_type_id = 2" .
        "  WHERE a.depth < 32" .
        ") " .
        "SELECT tcase_node, GROUP_CONCAT(node_name ORDER BY depth DESC SEPARATOR ' / ') AS suite_path " .
        "FROM (SELECT DISTINCT tcase_node, node_name, depth FROM anc) d " .
        "GROUP BY tcase_node";
    foreach ((array)$db->get_recordset($pathSql) as $r) {
        $suitePaths[intval($r['tcase_node'])] = tcbmDecode($r['suite_path']);
    }
}

// --- the builds kept in the matrix --------------------------------------
$buildIds = array_keys($buildNames);
$buildIds = array_map('intval', $buildIds);
if ($buildFilterId > 0) {
    $buildIds = array($buildFilterId);
}

$statuses = tcbmLatestStatuses($db, $tbl, $requestedPlanId, $buildIds);

// --- build the per-version rows + per-build totals ----------------------
$buildTotals = array();
foreach ($buildIds as $bid) {
    $buildTotals[$bid] = array(
        'assigned' => 0, 'executed' => 0, 'passed' => 0, 'failed' => 0,
        'blocked' => 0, 'not_run' => 0);
}
$overall = array(
    'assigned' => 0, 'executed' => 0, 'passed' => 0, 'failed' => 0,
    'blocked' => 0, 'not_run' => 0);

$rows = array();
foreach ($versions as $tcv => $v) {
    $cells = array();
    $row = array(
        'tcversion_id' => intval($tcv),
        'tcase_name' => $v['tcase_name'],
        'external_id' => $v['external_id'],
        'version' => $v['version'],
        'importance' => $v['importance'],
        'suite_path' => isset($suitePaths[$v['tcase_node_id']])
            ? $suitePaths[$v['tcase_node_id']] : '',
        'builds_total' => count($buildIds),
        'executed_builds' => 0,
        'passed_builds' => 0,
        'failed_builds' => 0,
        'blocked_builds' => 0,
        'not_run_builds' => 0,
        'pass_rate' => 0.0,
        'verdict' => 'not_run',
    );
    foreach ($buildIds as $bid) {
        $key = $bid . ':' . intval($tcv);
        $st = isset($statuses[$key]) ? $statuses[$key] : 'not_run';
        $cells[(string)$bid] = $st;

        $buildTotals[$bid]['assigned']++;
        $overall['assigned']++;
        if ($st === 'not_run') {
            $row['not_run_builds']++;
            $buildTotals[$bid]['not_run']++;
            $overall['not_run']++;
            continue;
        }
        $row['executed_builds']++;
        $buildTotals[$bid]['executed']++;
        $overall['executed']++;
        if ($st === 'passed') {
            $row['passed_builds']++;
            $buildTotals[$bid]['passed']++;
            $overall['passed']++;
        } else if ($st === 'failed') {
            $row['failed_builds']++;
            $buildTotals[$bid]['failed']++;
            $overall['failed']++;
        } else if ($st === 'blocked') {
            $row['blocked_builds']++;
            $buildTotals[$bid]['blocked']++;
            $overall['blocked']++;
        }
    }
    $row['pass_rate'] = tcbmPct($row['passed_builds'], $row['executed_builds']);
    if ($row['executed_builds'] === 0) {
        $row['verdict'] = 'not_run';
    } else if ($row['failed_builds'] === 0 && $row['blocked_builds'] === 0) {
        $row['verdict'] = 'stable_pass';
    } else if ($row['passed_builds'] === 0 && $row['blocked_builds'] === 0) {
        $row['verdict'] = 'stable_fail';
    } else if ($row['passed_builds'] === 0 && $row['failed_builds'] === 0) {
        $row['verdict'] = 'blocked_only';
    } else {
        $row['verdict'] = 'mixed';
    }
    $row['cells'] = $cells;
    $rows[] = $row;
}

// Display order: suite path, then test case name, then version.
usort($rows, function ($a, $b) {
    $c = strcasecmp($a['suite_path'], $b['suite_path']);
    if ($c !== 0) {
        return $c;
    }
    $c = strcasecmp($a['tcase_name'], $b['tcase_name']);
    if ($c !== 0) {
        return $c;
    }
    return intval($a['version']) - intval($b['version']);
});

$buildsOut = array();
foreach ($buildIds as $bid) {
    $row = $buildTotals[$bid];
    $row['pass_rate'] = tcbmPct($row['passed'], $row['executed']);
    $row['coverage'] = tcbmPct($row['executed'], $row['assigned']);
    $row['id'] = $bid;
    $row['name'] = $buildNames[$bid];
    $row['active'] = $buildMeta[$bid]['active'];
    $row['is_open'] = $buildMeta[$bid]['is_open'];
    $buildsOut[] = $row;
}

$summary = $overall;
$summary['pass_rate'] = tcbmPct($summary['passed'], $summary['executed']);
$summary['coverage'] = tcbmPct($summary['executed'], $summary['assigned']);
$summary['testcases'] = count($rows);
$summary['builds'] = count($buildIds);
$summary['cells'] = $summary['assigned'];
$summary['flaky'] = 0;
$summary['stable_pass'] = 0;
$summary['stable_fail'] = 0;
$summary['blocked_only'] = 0;
$summary['never_run'] = 0;
foreach ($rows as $r) {
    if ($r['verdict'] === 'mixed') {
        $summary['flaky']++;
    } else if ($r['verdict'] === 'stable_pass') {
        $summary['stable_pass']++;
    } else if ($r['verdict'] === 'stable_fail') {
        $summary['stable_fail']++;
    } else if ($r['verdict'] === 'blocked_only') {
        $summary['blocked_only']++;
    } else {
        $summary['never_run']++;
    }
}
if ($summary['testcases'] === 0 || $summary['builds'] === 0) {
    $summary['verdict'] = 'no_data';
} elseif ($summary['executed'] === 0) {
    $summary['verdict'] = 'not_started';
} elseif ($summary['not_run'] === 0) {
    $summary['verdict'] = 'complete';
} else {
    $summary['verdict'] = 'in_progress';
}

$buildFilterMeta = ($buildFilterId > 0) ? $buildMeta[$buildFilterId] : null;

tcbmOut(array(
    'status' => 'ok',
    'code' => 'ok',
    'context' => array(
        'tproject_id' => $owningProjectId,
        'tproject_name' => tcbmDecode($projectName),
        'tproject_prefix' => $projectPrefix,
        'tplan_id' => $requestedPlanId,
        'tplan_name' => tcbmDecode($planName),
        'build_id' => $buildFilterId,
        'build' => $buildFilterMeta,
    ),
    'filters' => array(
        'builds' => array_values($buildMeta),
    ),
    'summary' => $summary,
    'builds' => $buildsOut,
    'rows' => $rows,
));
