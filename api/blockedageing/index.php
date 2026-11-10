<?php
/**
 * Test Plan Blocked Tests Ageing report - BFF (Refs #1981, #1073).
 *
 * Net-new read-only modern screen (gui/templates/results/blockedAgeing.html).
 * TestLink 1.9.20 can list the test cases whose CURRENT status is blocked
 * (Results by Status), but it never shows the AGEING dimension of the blocked
 * backlog: how long each test has been stuck blocked, in which build/platform,
 * with the blocking note and the linked defects, and which blocked items are
 * old enough to threaten the plan. Nothing tracks blocked work over time.
 *
 * Semantics:
 *   - the universe is the set of `testplan_tcversions` links of the addressed
 *     plan (one assigned tcversion per platform link; platform_id = 0 means
 *     "any platform" and matches every execution platform).
 *   - the CURRENT result of a link is the LATEST execution (execution_ts, id)
 *     for the same (tcversion, platform) inside the plan and the selected
 *     build/platform scope.
 *   - a row appears in the report when that latest execution is BLOCKED
 *     (status 'b'). A test that was blocked once and later re-executed with
 *     any other status is NOT listed - it is no longer stuck.
 *   - AGE is measured in whole days from the blocking execution timestamp to
 *     NOW() on the DB server clock.
 *   - the linked-defect count of a row counts the rows of `execution_bugs`
 *     attached to the blocking execution (step-level links included).
 *
 * Actions
 *   GET|HEAD ?action=init&tplan_id=N[&tproject_id=P]
 *       -> context (plan / project) + build and platform filters
 *   GET|HEAD ?action=report&tplan_id=N[&tproject_id=P][&build_id=B][&platform_id=PL]
 *       -> summary KPIs + one row per stuck (version, platform) link,
 *          oldest first
 *
 * Security: session authentication, the shared BFF same-origin guard and the
 * `testplan_metrics` right on the OWNING test project (getAccess = true). The
 * client supplied tproject_id is only an ASSERTION: it is compared with the
 * plan's owning project and never used to widen a query.
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
function baOut($payload, $code = null)
{
    if ($code !== null) {
        http_response_code($code);
    }
    // JSON_INVALID_UTF8_SUBSTITUTE: legacy rows can carry non-UTF-8 bytes
    // (notes pasted from old trackers) and a bare json_encode() would then
    // return false -> empty 200 body (code review, Refs #1981).
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function baFail($httpCode, $code, $message = '')
{
    baOut(array(
        'status' => 'error',
        'code' => $code,
        'message' => $message !== '' ? $message : $code,
    ), $httpCode);
}

/** Strict positive integer reader - "1abc", "1.9", "-3" and "" are all refused. */
function baPositiveInt($raw)
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

function baDecode($value)
{
    return html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function baRound($value, $decimals = 1)
{
    return round((float)$value, $decimals);
}

function baPct($part, $total)
{
    return ($total > 0) ? baRound(100.0 * $part / $total, 1) : 0.0;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    baFail(405, 'method_not_allowed');
}

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    baFail(401, 'not_authenticated', 'Not authenticated');
}

bffEnforceSession($db);

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    baFail(401, 'not_authenticated', 'User not found');
}

$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if ($action !== 'init' && $action !== 'report') {
    baFail(400, 'invalid_request', 'Unknown action');
}

$tplanId = baPositiveInt($_GET['tplan_id'] ?? null);
$assertedProjectId = baPositiveInt($_GET['tproject_id'] ?? null);
$buildId = baPositiveInt($_GET['build_id'] ?? null);
$platformId = baPositiveInt($_GET['platform_id'] ?? null);

if ($tplanId <= 0) {
    baFail(400, 'invalid_request', 'Missing or invalid tplan_id');
}

// Order of operations (same as api/testerworkload, api/execduration):
//   1. resolve the plan -> opaque 404 when it does not exist or has no owner
//   2. check the right on the OWNING project (getAccess = true)
//   3. only then honour the client's tproject_id assertion
$tbl = tlObjectWithDB::getDBTables(array(
    'testplans', 'builds', 'platforms', 'testplan_tcversions', 'tcversions',
    'nodes_hierarchy', 'executions', 'execution_bugs', 'testprojects', 'users',
));
$planRow = $db->get_recordset(
    "SELECT id, testproject_id FROM {$tbl['testplans']} WHERE id = " . intval($tplanId));

if (!is_array($planRow) || count($planRow) === 0) {
    baFail(404, 'tplan_not_found', 'Test plan not found');
}

$plan = $planRow[0];
$owningProjectId = intval($plan['testproject_id']);
if ($owningProjectId <= 0) {
    baFail(404, 'tplan_not_found', 'Test plan has no owning test project');
}

if (!$user->hasRight($db, 'testplan_metrics', $owningProjectId, $tplanId, true)) {
    tLog('BFF blockedageing: user ' . intval($userId) .
         ' has no testplan_metrics right on tproject ' . $owningProjectId .
         ' (tplan ' . $tplanId . ')', 'ERROR');
    baFail(403, 'no_right', 'testplan_metrics right required');
}

if ($assertedProjectId > 0 && $assertedProjectId !== $owningProjectId) {
    baFail(404, 'project_mismatch', 'Test plan belongs to another test project');
}

$planName = testplan::getName($db, $tplanId);
$projectName = testproject::getName($db, $owningProjectId);
if (is_null($planName) || trim((string)$planName) === '') {
    baFail(404, 'tplan_not_found', 'Test plan not found');
}

// ---------------------------------------------------------------------------
// Filters: builds / platforms belonging to the OWNING project.
// ---------------------------------------------------------------------------
$builds = array();
$rs = $db->get_recordset(
    "SELECT id, name FROM {$tbl['builds']} WHERE testproject_id = " . intval($owningProjectId) .
    " AND active = 1 ORDER BY id");
if (is_array($rs)) {
    foreach ($rs as $r) {
        $builds[] = array('id' => intval($r['id']), 'name' => baDecode($r['name']));
    }
}
if ($buildId > 0) {
    $known = false;
    foreach ($builds as $b) {
        if ($b['id'] === $buildId) { $known = true; break; }
    }
    if (!$known) {
        baFail(400, 'invalid_request', 'build_id does not belong to this test project');
    }
}

$platforms = array();
$rp = $db->get_recordset(
    "SELECT id, name FROM {$tbl['platforms']} WHERE testproject_id = " . intval($owningProjectId) .
    " ORDER BY name");
if (is_array($rp)) {
    foreach ($rp as $r) {
        $platforms[] = array('id' => intval($r['id']), 'name' => baDecode($r['name']));
    }
}
if ($platformId > 0) {
    $known = false;
    foreach ($platforms as $p) {
        if ($p['id'] === $platformId) { $known = true; break; }
    }
    if (!$known) {
        baFail(400, 'invalid_request', 'platform_id does not belong to this test project');
    }
}

$context = array(
    'tplan_id' => $tplanId,
    'tplan_name' => baDecode((string)$planName),
    'tproject_id' => $owningProjectId,
    'tproject_name' => baDecode((string)$projectName),
);
$filters = array('builds' => $builds, 'platforms' => $platforms);
$filterEcho = array('build_id' => $buildId, 'platform_id' => $platformId);

if ($action === 'init') {
    baOut(array(
        'status' => 'ok',
        'code' => 200,
        'context' => $context,
        'filters' => $filters,
        'filter' => $filterEcho,
    ));
}

// ---------------------------------------------------------------------------
// report
// ---------------------------------------------------------------------------
$nowRs = $db->get_recordset("SELECT UNIX_TIMESTAMP(NOW()) AS now_ts");
$now = (is_array($nowRs) && count($nowRs) > 0) ? intval($nowRs[0]['now_ts']) : time();

// Universe: the plan's assigned links (tcversion, platform), narrowed by the
// platform filter (the platform is part of the assignment in TestLink).
$versionSql =
    "SELECT tpt.tcversion_id, tpt.platform_id, " .
    "       ntc.id AS tcase_node_id, ntc.name AS tcase_name, " .
    "       tcv.version, tcv.tc_external_id " .
    "FROM {$tbl['testplan_tcversions']} tpt " .
    "JOIN {$tbl['nodes_hierarchy']} ntcv ON ntcv.id = tpt.tcversion_id " .
    "JOIN {$tbl['nodes_hierarchy']} ntc ON ntc.id = ntcv.parent_id " .
    "JOIN {$tbl['tcversions']} tcv ON tcv.id = tpt.tcversion_id " .
    "WHERE tpt.testplan_id = " . intval($tplanId);
if ($platformId > 0) {
    // A platform filter narrows the universe but KEEPS the platform-less
    // links (platform_id = 0 matches every platform) - the same convention
    // as the sibling plan reports (api/failurepareto). Code review, Refs #1981.
    $versionSql .= " AND (tpt.platform_id = " . intval($platformId) .
                   " OR tpt.platform_id = 0)";
}
$versionRs = $db->get_recordset($versionSql);

$versions = array();
$caseNodes = array();
$linkPlatforms = array();
$zeroLink = array();
if (is_array($versionRs)) {
    foreach ($versionRs as $v) {
        $tcv = intval($v['tcversion_id']);
        $versions[$tcv] = array(
            'tcase_node_id' => intval($v['tcase_node_id']),
            'tcase_name' => baDecode($v['tcase_name']),
            'version' => intval($v['version']),
            'external_id' => intval($v['tc_external_id']),
        );
        $caseNodes[intval($v['tcase_node_id'])] = true;
        $lp = intval($v['platform_id']);
        if ($lp > 0) {
            $linkPlatforms[$tcv][$lp] = true;
        } else {
            $zeroLink[$tcv] = true;
        }
    }
}

// Latest execution per (tcversion, platform) in scope. EXISTS proves the
// (tcversion, platform) pair is still linked to the plan (a platform_id = 0
// link matches every platform) - a version unlinked from the plan is never
// reported even when its old executions survive.
$execSql =
    "SELECT e.id, e.tcversion_id, e.platform_id, e.build_id, e.tester_id, " .
    "       e.status, e.execution_ts, e.notes " .
    "FROM {$tbl['executions']} e WHERE e.testplan_id = " . intval($tplanId) .
    " AND EXISTS (SELECT 1 FROM {$tbl['testplan_tcversions']} tpt" .
    "             WHERE tpt.testplan_id = " . intval($tplanId) .
    "               AND tpt.tcversion_id = e.tcversion_id" .
    "               AND (tpt.platform_id = 0 OR tpt.platform_id = e.platform_id))";
if ($buildId > 0) {
    $execSql .= " AND e.build_id = " . intval($buildId);
}
if ($platformId > 0) {
    $execSql .= " AND e.platform_id = " . intval($platformId);
}
$execSql .= " ORDER BY e.execution_ts, e.id";
$execRs = $db->get_recordset($execSql);

$latest = array();
$execPlatByVer = array();
if (is_array($execRs)) {
    foreach ($execRs as $e) {
        $tcv = intval($e['tcversion_id']);
        if (!isset($versions[$tcv])) {
            continue;
        }
        $latest[$tcv . '|' . intval($e['platform_id'])] = $e;
        $execPlatByVer[$tcv][intval($e['platform_id'])] = true;
    }
}

// Suite paths of the owning test cases (recursive walk, depth-guarded, the
// same idiom as api/failurepareto).
$suitePaths = array();
if (count($caseNodes) > 0) {
    $nodeList = implode(',', array_map('intval', array_keys($caseNodes)));
    $pathSql =
        "WITH RECURSIVE anc AS (" .
        "  SELECT tn.id AS tcase_node, sn.id AS node_id, sn.name AS node_name, " .
        "         sn.parent_id, 0 AS depth " .
        "  FROM {$tbl['nodes_hierarchy']} tn " .
        "  JOIN {$tbl['nodes_hierarchy']} sn ON sn.id = tn.parent_id AND sn.node_type_id = 2 " .
        "  WHERE tn.id IN (" . $nodeList . ")" .
        "  UNION ALL " .
        "  SELECT a.tcase_node, pn.id, pn.name, pn.parent_id, a.depth + 1 " .
        "  FROM anc a " .
        "  JOIN {$tbl['nodes_hierarchy']} pn ON pn.id = a.parent_id AND pn.node_type_id = 2" .
        "  WHERE a.depth < 32" .
        ") " .
        "SELECT tcase_node, GROUP_CONCAT(node_name ORDER BY depth DESC SEPARATOR ' / ') AS suite_path " .
        "FROM (SELECT DISTINCT tcase_node, node_name, depth FROM anc) d " .
        "GROUP BY tcase_node";
    $pathRs = $db->get_recordset($pathSql);
    if (is_array($pathRs)) {
        foreach ($pathRs as $r) {
            $suitePaths[intval($r['tcase_node'])] = baDecode($r['suite_path']);
        }
    }
}

$prefixRs = $db->get_recordset(
    "SELECT prefix FROM {$tbl['testprojects']} WHERE id = " . intval($owningProjectId));
$prefix = (is_array($prefixRs) && count($prefixRs) > 0) ? (string)$prefixRs[0]['prefix'] : '';

$buildNames = array();
foreach ($builds as $b) {
    $buildNames[$b['id']] = $b['name'];
}
$platformNames = array();
foreach ($platforms as $p) {
    $platformNames[$p['id']] = $p['name'];
}

// Tester display names of every blocking execution.
$testerIds = array();
foreach ($latest as $e) {
    if ((string)$e['status'] === 'b' && intval($e['tester_id']) > 0) {
        $testerIds[intval($e['tester_id'])] = true;
    }
}
$testerNames = array();
if (count($testerIds) > 0) {
    $inList = implode(',', array_map('intval', array_keys($testerIds)));
    $uRs = $db->get_recordset(
        "SELECT id, login, first, last FROM {$tbl['users']} WHERE id IN (" . $inList . ")");
    if (is_array($uRs)) {
        foreach ($uRs as $r) {
            $display = trim(baDecode(trim((string)$r['first']) . ' ' . trim((string)$r['last'])));
            if ($display === '') {
                $display = baDecode($r['login']);
            }
            $testerNames[intval($r['id'])] = $display;
        }
    }
}

// Linked defects per blocking execution (one query, counted in PHP).
$bugCount = array();
$blockedExecIds = array();
foreach ($latest as $e) {
    if ((string)$e['status'] === 'b') {
        $blockedExecIds[intval($e['id'])] = true;
    }
}
if (count($blockedExecIds) > 0) {
    $inList = implode(',', array_map('intval', array_keys($blockedExecIds)));
    $bRs = $db->get_recordset(
        "SELECT execution_id, COUNT(*) AS bugs FROM {$tbl['execution_bugs']} " .
        "WHERE execution_id IN (" . $inList . ") GROUP BY execution_id");
    if (is_array($bRs)) {
        foreach ($bRs as $r) {
            $bugCount[intval($r['execution_id'])] = intval($r['bugs']);
        }
    }
}

$rows = array();
$summary = array(
    'assigned_links' => 0,
    'blocked' => 0,
    'blocked_pct' => 0.0,
    'avg_age' => 0.0,
    'max_age' => 0,
    'older_7' => 0,
    'older_30' => 0,
    'with_bugs' => 0,
    'testers' => 0,
);

// Universe size AFTER the platform narrowing (a platform filter narrows the
// universe too, the same convention as the other plan reports, while the
// platform-less links stay in scope). The UNIT is the (version, platform)
// link because that is the row unit of the report: a version linked without
// a platform and executed on two platforms owns TWO units, so the blocked
// share can never exceed 100% (code review, Refs #1981).
$units = array();
foreach ($versions as $tcv => $v) {
    $plats = isset($linkPlatforms[$tcv]) ? $linkPlatforms[$tcv] : array();
    // A platform-less link matches every execution platform of the version
    // in scope, so each executed platform owns one unit; a link with no
    // execution in scope still counts as its own unit.
    if (!empty($zeroLink[$tcv]) && isset($execPlatByVer[$tcv])) {
        foreach ($execPlatByVer[$tcv] as $p => $on) {
            $plats[$p] = true;
        }
    }
    if (empty($plats)) {
        $plats[0] = true;
    }
    foreach ($plats as $p => $on) {
        $units[$tcv . '|' . $p] = true;
    }
}
$universeLinks = count($units);
$summary['assigned_links'] = $universeLinks;

$ageSum = 0;
foreach ($latest as $key => $e) {
    if ((string)$e['status'] !== 'b') {
        continue;
    }
    $tcv = intval($e['tcversion_id']);
    if (!isset($versions[$tcv])) {
        continue;
    }
    $v = $versions[$tcv];
    $ts = strtotime((string)$e['execution_ts']);
    $age = ($ts !== false) ? max(0, intval(floor(($now - $ts) / 86400))) : 0;
    $eid = intval($e['id']);
    $bugs = isset($bugCount[$eid]) ? $bugCount[$eid] : 0;
    $testerId = intval($e['tester_id']);

    $summary['blocked']++;
    $ageSum += $age;
    if ($age > $summary['max_age']) {
        $summary['max_age'] = $age;
    }
    if ($age > 7) {
        $summary['older_7']++;
    }
    if ($age > 30) {
        $summary['older_30']++;
    }
    if ($bugs > 0) {
        $summary['with_bugs']++;
    }

    $rows[] = array(
        'tcversion_id' => $tcv,
        'tcase_node_id' => $v['tcase_node_id'],
        'tcase_name' => $v['tcase_name'],
        'external_full' => ($v['external_id'] > 0
            ? (($prefix !== '' ? $prefix . '-' : '') . $v['external_id'])
            : ''),
        'suite_path' => isset($suitePaths[$v['tcase_node_id']])
            ? $suitePaths[$v['tcase_node_id']] : '',
        'version' => $v['version'],
        'platform_id' => intval($e['platform_id']),
        'platform_name' => isset($platformNames[intval($e['platform_id'])])
            ? $platformNames[intval($e['platform_id'])] : '',
        'build_id' => intval($e['build_id']),
        'build_name' => isset($buildNames[intval($e['build_id'])])
            ? $buildNames[intval($e['build_id'])] : '',
        'tester_id' => $testerId,
        'tester_name' => isset($testerNames[$testerId]) ? $testerNames[$testerId] : '',
        'execution_ts' => (string)$e['execution_ts'],
        'age_days' => $age,
        'bugs' => $bugs,
        'notes' => baDecode($e['notes']),
    );
}

// Oldest first - the report exists to surface what is stuck longest.
usort($rows, function ($a, $b) {
    if ($a['age_days'] === $b['age_days']) {
        return strcmp($a['tcase_name'], $b['tcase_name']);
    }
    return ($a['age_days'] < $b['age_days']) ? 1 : -1;
});

$distinctTesters = array();
foreach ($rows as $r) {
    if ($r['tester_id'] > 0) {
        $distinctTesters[$r['tester_id']] = true;
    }
}
$summary['testers'] = count($distinctTesters);
$summary['blocked_pct'] = baPct($summary['blocked'], $summary['assigned_links']);
$summary['avg_age'] = $summary['blocked'] > 0
    ? baRound($ageSum / $summary['blocked'], 1) : 0.0;

// Verdict: nothing stuck -> clear; anything >30d -> at_risk; anything >7d ->
// watch; only fresh blocks -> fresh.
if ($summary['blocked'] === 0) {
    $verdict = 'clear';
} elseif ($summary['older_30'] > 0) {
    $verdict = 'at_risk';
} elseif ($summary['older_7'] > 0) {
    $verdict = 'watch';
} else {
    $verdict = 'fresh';
}

baOut(array(
    'status' => 'ok',
    'code' => 200,
    'context' => $context,
    'filters' => $filters,
    'filter' => $filterEcho,
    'summary' => $summary,
    'verdict' => $verdict,
    'rows' => $rows,
));
