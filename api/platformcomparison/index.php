<?php
/**
 * Test Plan Platform Comparison report - BFF (Refs #1963, parent #1073).
 *
 * Net-new modern screen (gui/templates/results/platformComparison.html).
 * 1.9.20 shows the current status of a plan (and, with platforms enabled, it
 * repeats it once per platform); buildComparison compares two BUILDS of one
 * plan. None of them answers the question "for the same test cases, what
 * changed between platform A and platform B?".
 *
 * Semantics
 *   - the unit is the test-case VERSION linked to the plan through
 *     testplan_tcversions (union over the plan, so a version assigned to only
 *     one platform is still visible);
 *   - for each platform the CURRENT status of a version is the status of its
 *     most recent execution (MAX(executions.id)) on that platform - the same
 *     "latest result wins" rule every other 2.0.1 report uses;
 *   - "assigned" per platform counts the plan versions whose assignment row
 *     targets that platform (platform_id = P) or has no platform (legacy
 *     platform_id = 0); a version linked to the plan but never executed on a
 *     platform counts as NOT RUN;
 *   - statuses are classified into fixed / regressed / new_pass / new_fail /
 *     revived / changed / same_* exactly like the build comparison report.
 *
 * Actions
 *   GET|HEAD ?action=projects
 *     -> projects the user may read
 *   GET|HEAD ?action=init&tproject_id=N[&tplan_id=P]
 *     -> project context + plans + the platforms linked to the plan
 *        (all project platforms when no tplan_id is supplied)
 *   GET|HEAD ?action=report&tplan_id=N[&tproject_id=P]&platform_a=A&platform_b=B
 *     -> per-platform summary + the per-test-case diff rows
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

/** Classify the transition of one test-case version between two platforms. */
function pcChange($a, $b)
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
function pcCanViewProject($user, $db, $tpid)
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
 * Latest status per test-case version for ONE platform of a plan.
 *
 * @return array tcversion_id => status key
 */
function pcLatestStatus($db, $tbl, $planId, $platformId)
{
    $where = "testplan_id = " . intval($planId) .
             " AND platform_id = " . intval($platformId);
    $sql =
        "SELECT e.tcversion_id AS tcversion_id, e.status AS status " .
        "FROM {$tbl['executions']} e " .
        "INNER JOIN (SELECT tcversion_id, MAX(id) AS mid FROM {$tbl['executions']} " .
        "WHERE {$where} GROUP BY tcversion_id) x ON x.mid = e.id";
    $out = array();
    foreach ((array)$db->get_recordset($sql) as $r) {
        $out[intval($r['tcversion_id'])] = pcStatusKey($r['status']);
    }
    return $out;
}

/**
 * Versions of the plan assigned to one platform (legacy platform_id = 0
 * assignments are treated as applying to every platform).
 *
 * @return array tcversion_id => true
 */
function pcAssigned($db, $tbl, $planId, $platformId)
{
    $sql =
        "SELECT DISTINCT tcversion_id FROM {$tbl['testplan_tcversions']} " .
        "WHERE testplan_id = " . intval($planId) .
        " AND (platform_id = " . intval($platformId) . " OR platform_id = 0)";
    $out = array();
    foreach ((array)$db->get_recordset($sql) as $r) {
        $out[intval($r['tcversion_id'])] = true;
    }
    return $out;
}

/** Summary block for one platform over the plan universe. */
function pcPlatformSummary($universe, $assigned, $statuses)
{
    $counts = array('executed' => 0, 'passed' => 0, 'failed' => 0,
                    'blocked' => 0, 'not_run' => 0);
    $assignedCount = 0;
    foreach ($universe as $tid => $true) {
        $isAssigned = isset($assigned[$tid]);
        if ($isAssigned) {
            $assignedCount++;
        }
        $key = isset($statuses[$tid]) ? $statuses[$tid] : 'not_run';
        if ($key === 'not_run') {
            if ($isAssigned) {
                $counts['not_run']++;
            }
            continue;
        }
        $counts['executed']++;
        $counts[$key]++;
    }
    $counts['assigned'] = $assignedCount;
    $counts['pass_rate'] = pcPct($counts['passed'], $counts['executed']);
    $counts['coverage'] = pcPct($counts['executed'], $assignedCount);
    return $counts;
}

// ---------------------------------------------------------------------------
// Method / session
// ---------------------------------------------------------------------------
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    pcFail(405, 'method_not_allowed');
}

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    pcFail(401, 'not_authenticated', 'Not authenticated');
}

$db = new database(DB_TYPE);
doDBConnect($db);
bffEnforceSession($db);

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    pcFail(401, 'not_authenticated', 'User not found');
}

$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if (is_array($_GET['action'] ?? null)) {
    pcFail(400, 'invalid_parameter', 'Invalid action');
}
if (!in_array($action, array('projects', 'init', 'report'), true)) {
    pcFail(400, 'unknown_action', 'Unknown action');
}

$tbl = tlObjectWithDB::getDBTables(array(
    'testplans', 'testprojects', 'nodes_hierarchy', 'platforms',
    'testplan_platforms', 'testplan_tcversions', 'tcversions', 'executions',
));

$assertedProjectId = pcPositiveInt($_GET['tproject_id'] ?? null);
$requestedPlanId = pcPositiveInt($_GET['tplan_id'] ?? null);

// ---------------------------------------------------------------------------
// projects: the picker
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
// init: project context + plans + the platforms of the selected plan
// ---------------------------------------------------------------------------
if ($action === 'init') {
    $tpid = ($assertedProjectId > 0) ? $assertedProjectId
                                     : intval($_SESSION['testprojectID'] ?? 0);
    if ($tpid <= 0) {
        pcFail(400, 'invalid_request', 'Missing or invalid tproject_id');
    }
    // The right is checked on the ADDRESSED project (no project-id oracle).
    if (!pcCanViewProject($user, $db, $tpid)) {
        tLog('BFF platformcomparison: user ' . intval($userId) .
             ' has no read right on tproject ' . $tpid, 'AUDIT');
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
    $planIds = array();
    foreach ($planRows as $r) {
        $planIds[intval($r['id'])] = true;
        $plans[] = array(
            'id' => intval($r['id']),
            'name' => pcDecode($r['name']),
            'active' => intval($r['active']) ? true : false,
            'is_open' => intval($r['is_open']) ? true : false,
        );
    }

    $planScoped = ($requestedPlanId > 0 && isset($planIds[$requestedPlanId]));
    if ($planScoped) {
        $platformRows = (array)$db->get_recordset(
            "SELECT p.id AS id, p.name AS name " .
            "FROM {$tbl['platforms']} p " .
            "INNER JOIN {$tbl['testplan_platforms']} tpp " .
            "  ON tpp.platform_id = p.id AND tpp.testplan_id = " . $requestedPlanId .
            "  AND tpp.active = 1 " .
            "WHERE p.testproject_id = " . $tpid . " ORDER BY p.name ASC");
    } else {
        $platformRows = (array)$db->get_recordset(
            "SELECT id, name FROM {$tbl['platforms']} " .
            "WHERE testproject_id = " . $tpid . " AND is_open = 1 ORDER BY name ASC");
    }
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
        'filters' => array('platforms' => $platforms),
        'plan_scoped_platforms' => $planScoped,
    ));
}

// ---------------------------------------------------------------------------
// report: the platform-to-platform comparison
// ---------------------------------------------------------------------------
$platformAId = pcPositiveInt($_GET['platform_a'] ?? null);
$platformBId = pcPositiveInt($_GET['platform_b'] ?? null);

if ($requestedPlanId <= 0) {
    pcFail(400, 'invalid_request', 'Missing or invalid tplan_id');
}
if ($platformAId <= 0 || $platformBId <= 0) {
    pcFail(400, 'invalid_request', 'Missing or invalid platform_a / platform_b');
}
if ($platformAId === $platformBId) {
    pcFail(400, 'invalid_request', 'Two different platforms are required');
}

$planRow = (array)$db->get_recordset(
    "SELECT id, testproject_id FROM {$tbl['testplans']} WHERE id = " . $requestedPlanId);
if (count($planRow) === 0) {
    pcFail(404, 'tplan_not_found', 'Test plan not found');
}
$owningProjectId = intval($planRow[0]['testproject_id']);
if ($owningProjectId <= 0) {
    pcFail(404, 'tplan_not_found', 'Test plan not found');
}

// The right is checked on the OWNING project of the plan.
if (!$user->hasRight($db, 'testplan_metrics', $owningProjectId, $requestedPlanId, true)) {
    tLog('BFF platformcomparison: user ' . intval($userId) .
         ' has no testplan_metrics right on tproject ' . $owningProjectId .
         ' (tplan ' . $requestedPlanId . ')', 'AUDIT');
    pcFail(403, 'no_right', 'testplan_metrics right required');
}

if ($assertedProjectId > 0 && $assertedProjectId !== $owningProjectId) {
    pcFail(404, 'project_mismatch', 'Test plan belongs to another test project');
}

$planName = testplan::getName($db, $requestedPlanId);
$projectName = testproject::getName($db, $owningProjectId);
if (is_null($planName) || trim((string)$planName) === '') {
    pcFail(404, 'tplan_not_found', 'Test plan not found');
}

// --- both platforms must belong to the owning project and to the plan ------
$platformNames = array();
$platformRows = (array)$db->get_recordset(
    "SELECT p.id AS id, p.name AS name FROM {$tbl['platforms']} p " .
    "WHERE p.testproject_id = " . $owningProjectId);
foreach ($platformRows as $r) {
    $platformNames[intval($r['id'])] = pcDecode($r['name']);
}
if (!isset($platformNames[$platformAId]) || !isset($platformNames[$platformBId])) {
    pcFail(400, 'invalid_request', 'platform_a / platform_b do not belong to this test project');
}

$linked = array();
$linkRows = (array)$db->get_recordset(
    "SELECT platform_id FROM {$tbl['testplan_platforms']} " .
    "WHERE testplan_id = " . $requestedPlanId . " AND active = 1");
foreach ($linkRows as $r) {
    $linked[intval($r['platform_id'])] = true;
}
if (!isset($linked[$platformAId]) || !isset($linked[$platformBId])) {
    pcFail(400, 'invalid_request', 'platform_a / platform_b are not linked to this test plan');
}

// --- the universe: test-case versions linked to the plan -----------------
$universeSql =
    "SELECT DISTINCT tcversion_id FROM {$tbl['testplan_tcversions']} " .
    "WHERE testplan_id = " . $requestedPlanId;
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
            'external_id' => pcDecode($r['external_id']),
            'version' => intval($r['version']),
            'tcase_name' => pcDecode($r['tcase_name']),
            'suite_name' => pcDecode($r['suite_name']),
        );
    }
}

$aAssigned = pcAssigned($db, $tbl, $requestedPlanId, $platformAId);
$bAssigned = pcAssigned($db, $tbl, $requestedPlanId, $platformBId);
$aStatuses = pcLatestStatus($db, $tbl, $requestedPlanId, $platformAId);
$bStatuses = pcLatestStatus($db, $tbl, $requestedPlanId, $platformBId);

$summaryA = pcPlatformSummary($universe, $aAssigned, $aStatuses);
$summaryB = pcPlatformSummary($universe, $bAssigned, $bStatuses);

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
    $change = pcChange($aKey, $bKey);
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
        'assigned_a' => isset($aAssigned[$tid]),
        'assigned_b' => isset($bAssigned[$tid]),
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

pcOut(array(
    'status' => 'ok',
    'code' => 'ok',
    'context' => array(
        'tproject_id' => $owningProjectId,
        'tproject_name' => pcDecode($projectName),
        'tplan_id' => $requestedPlanId,
        'tplan_name' => pcDecode($planName),
        'platform_a' => array('id' => $platformAId, 'name' => $platformNames[$platformAId]),
        'platform_b' => array('id' => $platformBId, 'name' => $platformNames[$platformBId]),
    ),
    'platforms' => array(
        'a' => $summaryA,
        'b' => $summaryB,
    ),
    'changes' => $changes,
    'rows' => $rows,
));
