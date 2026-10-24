<?php
/**
 * Test Plan Build Comparison report - BFF (Refs #1962, parent #1073).
 *
 * Net-new modern screen (gui/templates/results/buildComparison.html).
 * TestLink 1.9.20 only ever shows the CURRENT status of a test plan and the
 * planComparison report compares two DIFFERENT plans; metricsTrends shows an
 * aggregate per-build trend. None of them answers the regression question
 * "what changed for the individual test cases between build A and build B?".
 *
 * Semantics
 *   - the unit is the test-case VERSION linked to the plan through
 *     testplan_tcversions;
 *   - for each build the CURRENT status of a version is the status of its
 *     most recent execution (MAX(executions.id)) on that build - the same
 *     "latest result wins" rule every other 2.0.1 report uses;
 *   - a version linked to the plan but never executed on a build counts as
 *     NOT RUN on that build;
 *   - an optional platform filter narrows both the universe and the
 *     executions (platform is part of the assignment in TestLink).
 *
 * Actions
 *   GET|HEAD ?action=init&tproject_id=N[&tplan_id=P]
 *     -> project context + plans + builds + platforms
 *   GET|HEAD ?action=report&tplan_id=N[&tproject_id=P]&build_a=B&build_b=C
 *             [&platform_id=PL]
 *     -> per-build summary + the per-test-case diff rows
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
function bcOut($payload, $code = null)
{
    if ($code !== null) {
        http_response_code($code);
    }
    echo json_encode($payload);
    exit;
}

function bcFail($httpCode, $code, $message = '')
{
    bcOut(array(
        'status' => 'error',
        'code' => $code,
        'message' => $message !== '' ? $message : $code,
    ), $httpCode);
}

/** Strict positive integer reader - "1abc", "1.9", "-3" and "" are refused. */
function bcPositiveInt($raw)
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
function bcStatusKey($raw)
{
    switch ((string)$raw) {
        case 'p': return 'passed';
        case 'f': return 'failed';
        case 'b': return 'blocked';
        default:  return 'not_run';
    }
}

function bcPct($num, $den)
{
    if ($den <= 0) {
        return 0.0;
    }
    return round(100.0 * $num / $den, 1);
}

function bcDecode($value)
{
    return html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/** Classify the transition of one test-case version between two builds. */
function bcChange($a, $b)
{
    if ($a === $b) {
        return 'same_' . $a;
    }
    if ($b === 'passed' && ($a === 'failed' || $a === 'blocked')) {
        return 'fixed';
    }
    if ($a === 'passed' && ($b === 'failed' || $b === 'blocked')) {
        return 'regressed';
    }
    if ($a === 'not_run' && $b === 'passed') {
        return 'new_pass';
    }
    if ($a === 'not_run' && ($b === 'failed' || $b === 'blocked')) {
        return 'new_fail';
    }
    if (($a === 'failed' || $a === 'blocked') && $b === 'not_run') {
        return 'revived';
    }
    return 'changed';
}

/** Read access to a test project (used for the project picker). */
function bcCanViewProject($user, $db, $tpid)
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
 * Latest status per test-case version for ONE build.
 *
 * @return array tcversion_id => status key
 */
function bcLatestStatus($db, $tbl, $planId, $buildId, $platformId)
{
    $where = "testplan_id = " . intval($planId) . " AND build_id = " . intval($buildId);
    if ($platformId > 0) {
        $where .= " AND platform_id = " . intval($platformId);
    }
    $sql =
        "SELECT e.tcversion_id AS tcversion_id, e.status AS status " .
        "FROM {$tbl['executions']} e " .
        "INNER JOIN (SELECT tcversion_id, MAX(id) AS mid FROM {$tbl['executions']} " .
        "WHERE {$where} GROUP BY tcversion_id) x ON x.mid = e.id";
    $out = array();
    foreach ((array)$db->get_recordset($sql) as $r) {
        $out[intval($r['tcversion_id'])] = bcStatusKey($r['status']);
    }
    return $out;
}

/** Summary block for one build over the plan universe. */
function bcBuildSummary($universe, $statuses)
{
    $counts = array('executed' => 0, 'passed' => 0, 'failed' => 0,
                    'blocked' => 0, 'not_run' => 0);
    foreach ($universe as $tid => $true) {
        $key = isset($statuses[$tid]) ? $statuses[$tid] : 'not_run';
        if ($key === 'not_run') {
            $counts['not_run']++;
            continue;
        }
        $counts['executed']++;
        $counts[$key]++;
    }
    $assigned = count($universe);
    $counts['assigned'] = $assigned;
    $counts['pass_rate'] = bcPct($counts['passed'], $counts['executed']);
    $counts['coverage'] = bcPct($counts['executed'], $assigned);
    return $counts;
}

// ---------------------------------------------------------------------------
// Method / session
// ---------------------------------------------------------------------------
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    bcFail(405, 'method_not_allowed');
}

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    bcFail(401, 'not_authenticated', 'Not authenticated');
}

$db = new database(DB_TYPE);
doDBConnect($db);
bffEnforceSession($db);

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    bcFail(401, 'not_authenticated', 'User not found');
}

$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if (is_array($_GET['action'] ?? null)) {
    bcFail(400, 'invalid_parameter', 'Invalid action');
}
if (!in_array($action, array('init', 'report'), true)) {
    bcFail(400, 'unknown_action', 'Unknown action');
}

$tbl = tlObjectWithDB::getDBTables(array(
    'testplans', 'testprojects', 'nodes_hierarchy', 'builds', 'platforms',
    'testplan_tcversions', 'tcversions', 'executions', 'testplan_platforms',
));

$assertedProjectId = bcPositiveInt($_GET['tproject_id'] ?? null);
$requestedPlanId = bcPositiveInt($_GET['tplan_id'] ?? null);

// ---------------------------------------------------------------------------
// init: project context + plans + the build / platform filters
// ---------------------------------------------------------------------------
if ($action === 'init') {
    $tpid = ($assertedProjectId > 0) ? $assertedProjectId
                                     : intval($_SESSION['testprojectID'] ?? 0);
    if ($tpid <= 0) {
        bcFail(400, 'invalid_request', 'Missing or invalid tproject_id');
    }
    // The right is checked on the ADDRESSED project (no project-id oracle).
    if (!bcCanViewProject($user, $db, $tpid)) {
        tLog('BFF buildcomparison: user ' . intval($userId) .
             ' has no read right on tproject ' . $tpid, 'AUDIT');
        bcFail(403, 'no_right', 'Read right required on the test project');
    }

    $projectRow = (array)$db->get_recordset(
        "SELECT p.id AS id, p.prefix AS prefix, nh.name AS name " .
        "FROM {$tbl['testprojects']} p " .
        "INNER JOIN {$tbl['nodes_hierarchy']} nh ON nh.id = p.id AND nh.node_type_id = 1 " .
        "WHERE p.id = " . $tpid);
    if (count($projectRow) === 0) {
        bcFail(404, 'tproject_not_found', 'Test project not found');
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
            'name' => bcDecode($r['name']),
            'active' => intval($r['active']) ? true : false,
            'is_open' => intval($r['is_open']) ? true : false,
        );
    }

    $buildRows = (array)$db->get_recordset(
        "SELECT id, name FROM {$tbl['builds']} WHERE testproject_id = " . $tpid .
        " AND active = 1 ORDER BY id");
    $builds = array();
    foreach ($buildRows as $r) {
        $builds[] = array('id' => intval($r['id']), 'name' => bcDecode($r['name']));
    }

    $platformRows = (array)$db->get_recordset(
        "SELECT id, name FROM {$tbl['platforms']} WHERE testproject_id = " . $tpid .
        " ORDER BY name");
    $platforms = array();
    foreach ($platformRows as $r) {
        $platforms[] = array('id' => intval($r['id']), 'name' => bcDecode($r['name']));
    }

    bcOut(array(
        'status' => 'ok',
        'code' => 'ok',
        'context' => array(
            'tproject_id' => $tpid,
            'tproject_name' => bcDecode($projectRow[0]['name']),
            'tproject_prefix' => (string)$projectRow[0]['prefix'],
            'tplan_id' => $requestedPlanId,
        ),
        'plans' => $plans,
        'filters' => array('builds' => $builds, 'platforms' => $platforms),
    ));
}

// ---------------------------------------------------------------------------
// report: the build-to-build comparison
// ---------------------------------------------------------------------------
$buildAId = bcPositiveInt($_GET['build_a'] ?? null);
$buildBId = bcPositiveInt($_GET['build_b'] ?? null);
$platformId = bcPositiveInt($_GET['platform_id'] ?? null);

if ($requestedPlanId <= 0) {
    bcFail(400, 'invalid_request', 'Missing or invalid tplan_id');
}
if ($buildAId <= 0 || $buildBId <= 0) {
    bcFail(400, 'invalid_request', 'Missing or invalid build_a / build_b');
}
if ($buildAId === $buildBId) {
    bcFail(400, 'invalid_request', 'Two different builds are required');
}

$planRow = (array)$db->get_recordset(
    "SELECT id, testproject_id FROM {$tbl['testplans']} WHERE id = " . $requestedPlanId);
if (count($planRow) === 0) {
    bcFail(404, 'tplan_not_found', 'Test plan not found');
}
$owningProjectId = intval($planRow[0]['testproject_id']);
if ($owningProjectId <= 0) {
    bcFail(404, 'tplan_not_found', 'Test plan not found');
}

// The right is checked on the OWNING project of the plan.
if (!$user->hasRight($db, 'testplan_metrics', $owningProjectId, $requestedPlanId, true)) {
    tLog('BFF buildcomparison: user ' . intval($userId) .
         ' has no testplan_metrics right on tproject ' . $owningProjectId .
         ' (tplan ' . $requestedPlanId . ')', 'AUDIT');
    bcFail(403, 'no_right', 'testplan_metrics right required');
}

if ($assertedProjectId > 0 && $assertedProjectId !== $owningProjectId) {
    bcFail(404, 'project_mismatch', 'Test plan belongs to another test project');
}

$planName = testplan::getName($db, $requestedPlanId);
$projectName = testproject::getName($db, $owningProjectId);
if (is_null($planName) || trim((string)$planName) === '') {
    bcFail(404, 'tplan_not_found', 'Test plan not found');
}

// --- the builds / platform must belong to the owning project -------------
$buildRows = (array)$db->get_recordset(
    "SELECT id, name, active FROM {$tbl['builds']} WHERE testproject_id = " . $owningProjectId .
    " ORDER BY id");
$buildNames = array();
foreach ($buildRows as $r) {
    $buildNames[intval($r['id'])] = bcDecode($r['name']);
}
if (!isset($buildNames[$buildAId]) || !isset($buildNames[$buildBId])) {
    bcFail(400, 'invalid_request', 'build_a / build_b do not belong to this test project');
}

if ($platformId > 0) {
    $platformRows = (array)$db->get_recordset(
        "SELECT id FROM {$tbl['platforms']} WHERE testproject_id = " . $owningProjectId .
        " AND id = " . $platformId);
    if (count($platformRows) === 0) {
        bcFail(400, 'invalid_request', 'platform_id does not belong to this test project');
    }
}

// --- the universe: test-case versions linked to the plan -----------------
$universeSql =
    "SELECT DISTINCT tpt.tcversion_id AS tcversion_id " .
    "FROM {$tbl['testplan_tcversions']} tpt " .
    "WHERE tpt.testplan_id = " . $requestedPlanId;
if ($platformId > 0) {
    $universeSql .= " AND tpt.platform_id = " . intval($platformId);
}
$universe = array();
foreach ((array)$db->get_recordset($universeSql) as $r) {
    $universe[intval($r['tcversion_id'])] = true;
}

// --- test case metadata (name lives on the testcase node) ----------------
$meta = array();
$universeIds = array_keys($universe);
if (count($universeIds) > 0) {
    $inIds = implode(',', array_map('intval', $universeIds));
    $metaSql =
        "SELECT tcv.id AS tcversion_id, tcv.tc_external_id AS external_id, " .
        "tcv.version AS version, TC.name AS tcase_name, TS.name AS suite_name " .
        "FROM {$tbl['tcversions']} tcv " .
        "INNER JOIN {$tbl['nodes_hierarchy']} VN ON VN.id = tcv.id " .
        "INNER JOIN {$tbl['nodes_hierarchy']} TC ON TC.id = VN.parent_id " .
        "LEFT JOIN {$tbl['nodes_hierarchy']} TS ON TS.id = TC.parent_id " .
        "WHERE tcv.id IN (" . $inIds . ")";
    foreach ((array)$db->get_recordset($metaSql) as $r) {
        $meta[intval($r['tcversion_id'])] = array(
            'external_id' => bcDecode($r['external_id']),
            'version' => intval($r['version']),
            'tcase_name' => bcDecode($r['tcase_name']),
            'suite_name' => bcDecode($r['suite_name']),
        );
    }
}

$aStatuses = bcLatestStatus($db, $tbl, $requestedPlanId, $buildAId, $platformId);
$bStatuses = bcLatestStatus($db, $tbl, $requestedPlanId, $buildBId, $platformId);

$summaryA = bcBuildSummary($universe, $aStatuses);
$summaryB = bcBuildSummary($universe, $bStatuses);

// --- the per-case diff rows ---------------------------------------------
$rows = array();
$changes = array(
    'fixed' => 0, 'regressed' => 0, 'new_pass' => 0, 'new_fail' => 0,
    'revived' => 0, 'changed' => 0, 'same_passed' => 0, 'same_failed' => 0,
    'same_blocked' => 0, 'same_not_run' => 0,
);
foreach ($universeIds as $tid) {
    $aKey = isset($aStatuses[$tid]) ? $aStatuses[$tid] : 'not_run';
    $bKey = isset($bStatuses[$tid]) ? $bStatuses[$tid] : 'not_run';
    $change = bcChange($aKey, $bKey);
    if (isset($changes[$change])) {
        $changes[$change]++;
    }
    $m = isset($meta[$tid]) ? $meta[$tid] : array(
        'external_id' => '', 'version' => 0, 'tcase_name' => '', 'suite_name' => '');
    $rows[] = array(
        'tcversion_id' => intval($tid),
        'external_id' => $m['external_id'],
        'version' => $m['version'],
        'name' => $m['tcase_name'],
        'suite' => $m['suite_name'],
        'status_a' => $aKey,
        'status_b' => $bKey,
        'change' => $change,
    );
}
usort($rows, function ($x, $y) {
    $c = strcmp((string)$x['suite'], (string)$y['suite']);
    if ($c !== 0) {
        return $c;
    }
    return strcmp((string)$x['name'], (string)$y['name']);
});

bcOut(array(
    'status' => 'ok',
    'code' => 'ok',
    'context' => array(
        'tproject_id' => $owningProjectId,
        'tproject_name' => bcDecode($projectName),
        'tplan_id' => $requestedPlanId,
        'tplan_name' => bcDecode($planName),
        'build_a' => array('id' => $buildAId, 'name' => $buildNames[$buildAId]),
        'build_b' => array('id' => $buildBId, 'name' => $buildNames[$buildBId]),
        'platform_id' => $platformId,
    ),
    'builds' => array(
        'a' => $summaryA,
        'b' => $summaryB,
    ),
    'changes' => $changes,
    'rows' => $rows,
));
