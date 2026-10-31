<?php
/**
 * Test Plan Failure Pareto report - BFF (Refs #1979).
 *
 * Net-new modern screen (gui/templates/results/failurePareto.html). TestLink
 * 1.9.20 can list results and defects, but never answers "which few test cases
 * concentrate most of the failures of this plan" (a Pareto / 80-20 view).
 *
 * Semantics:
 *   - the universe is the set of test-case VERSIONS linked to the plan
 *     (testplan_tcversions); versions not assigned to the plan are ignored.
 *   - executions are restricted to the plan and to the optional build /
 *     platform filter. Every execution row counts as one failure event when
 *     its status is `f`; blocked (`b`) is reported separately and is never
 *     folded into failures.
 *   - `failing_units` counts the distinct (tcversion, platform) pairs whose
 *     LATEST execution in scope is failed, i.e. what is red right now.
 *   - rows are the test cases with at least one failed execution, ranked by
 *     failed executions (desc), then currently failing units, then name.
 *     `cumulative_pct` is the share of all failures covered by the ranked
 *     prefix; `in_top80` marks the cases needed to reach 80% of failures.
 *   - verdict: `clean` (no failures), `concentrated` (the smallest set of
 *     cases needed for 80% of the failures is <= 20% of the failing cases),
 *     `diffuse` otherwise.
 *
 * Actions
 *   GET|HEAD ?action=init&tplan_id=N[&tproject_id=P]
 *       -> context + build / platform filters
 *   GET|HEAD ?action=report&tplan_id=N[&tproject_id=P][&build_id=B]
 *             [&platform_id=PL]
 *       -> summary + ranked rows
 *
 * Security: session authentication, the shared BFF same-origin guard and the
 * `testplan_metrics` right on the OWNING test project. The client supplied
 * tproject_id is only an assertion (compared, never used to widen the query).
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

function fpOut($payload, $code = null)
{
    if ($code !== null) {
        http_response_code($code);
    }
    echo json_encode($payload);
    exit;
}

function fpFail($httpCode, $code, $message = '')
{
    fpOut(array(
        'status' => 'error',
        'code' => $code,
        'message' => $message !== '' ? $message : $code,
    ), $httpCode);
}

/** Strict positive integer reader - "1abc", "1.9", "-3" and "" are refused. */
function fpPositiveInt($raw)
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

function fpDecode($value)
{
    return html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function fpRound($value, $decimals = 1)
{
    return round((float)$value, $decimals);
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    fpFail(405, 'method_not_allowed');
}

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    fpFail(401, 'not_authenticated', 'Not authenticated');
}

bffEnforceSession($db);

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    fpFail(401, 'not_authenticated', 'User not found');
}

$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if ($action !== 'init' && $action !== 'report') {
    fpFail(400, 'invalid_request', 'Unknown action');
}

$tplanId = fpPositiveInt($_GET['tplan_id'] ?? null);
$assertedProjectId = fpPositiveInt($_GET['tproject_id'] ?? null);
$buildId = fpPositiveInt($_GET['build_id'] ?? null);
$platformId = fpPositiveInt($_GET['platform_id'] ?? null);

if ($tplanId <= 0) {
    fpFail(400, 'invalid_request', 'Missing or invalid tplan_id');
}

$tbl = tlObjectWithDB::getDBTables(array(
    'testplans', 'builds', 'platforms', 'testplan_tcversions', 'tcversions',
    'nodes_hierarchy', 'executions', 'testprojects', 'users',
));
$planRow = $db->get_recordset(
    "SELECT id, testproject_id FROM {$tbl['testplans']} WHERE id = " . intval($tplanId));

if (!is_array($planRow) || count($planRow) === 0) {
    fpFail(404, 'tplan_not_found', 'Test plan not found');
}

$plan = $planRow[0];
$owningProjectId = intval($plan['testproject_id']);
if ($owningProjectId <= 0) {
    fpFail(404, 'tplan_not_found', 'Test plan has no owning test project');
}

if (!$user->hasRight($db, 'testplan_metrics', $owningProjectId, $tplanId, true)) {
    tLog('BFF failurepareto: user ' . intval($userId) .
         ' has no testplan_metrics right on tproject ' . $owningProjectId .
         ' (tplan ' . $tplanId . ')', 'ERROR');
    fpFail(403, 'no_right', 'testplan_metrics right required');
}

if ($assertedProjectId > 0 && $assertedProjectId !== $owningProjectId) {
    fpFail(404, 'project_mismatch', 'Test plan belongs to another test project');
}

$planName = testplan::getName($db, $tplanId);
$projectName = testproject::getName($db, $owningProjectId);
if (is_null($planName) || trim((string)$planName) === '') {
    fpFail(404, 'tplan_not_found', 'Test plan not found');
}

// Builds / platforms of the OWNING project (filter vocabulary).
$builds = array();
$rs = $db->get_recordset(
    "SELECT id, name FROM {$tbl['builds']} WHERE testproject_id = " . intval($owningProjectId) .
    " AND active = 1 ORDER BY id");
if (is_array($rs)) {
    foreach ($rs as $r) {
        $builds[] = array('id' => intval($r['id']), 'name' => fpDecode($r['name']));
    }
}
if ($buildId > 0) {
    $known = false;
    foreach ($builds as $b) {
        if ($b['id'] === $buildId) { $known = true; break; }
    }
    if (!$known) {
        fpFail(400, 'invalid_request', 'build_id does not belong to this test project');
    }
}

$platforms = array();
$rp = $db->get_recordset(
    "SELECT id, name FROM {$tbl['platforms']} WHERE testproject_id = " . intval($owningProjectId) .
    " ORDER BY name");
if (is_array($rp)) {
    foreach ($rp as $r) {
        $platforms[] = array('id' => intval($r['id']), 'name' => fpDecode($r['name']));
    }
}
if ($platformId > 0) {
    $known = false;
    foreach ($platforms as $p) {
        if ($p['id'] === $platformId) { $known = true; break; }
    }
    if (!$known) {
        fpFail(400, 'invalid_request', 'platform_id does not belong to this test project');
    }
}

$context = array(
    'tplan_id' => $tplanId,
    'tplan_name' => (string)$planName,
    'tproject_id' => $owningProjectId,
    'tproject_name' => (string)$projectName,
);
$filters = array('builds' => $builds, 'platforms' => $platforms);
$filterState = array('build_id' => $buildId, 'platform_id' => $platformId);

if ($action === 'init') {
    fpOut(array(
        'status' => 'ok',
        'code' => 200,
        'context' => $context,
        'filters' => $filters,
        'filter' => $filterState,
    ));
}

// ---------------------------------------------------------------------------
// Assigned versions of the plan. A version linked for several platforms
// collapses to one entry here (the report measures versions as the universe).
// ---------------------------------------------------------------------------
$versions = array();
$assignedRs = $db->get_recordset(
    "SELECT tpt.tcversion_id, tv.version, tv.tc_external_id, " .
    "       tn.name AS tcase_name, tn.id AS tcase_node_id " .
    "FROM {$tbl['testplan_tcversions']} tpt " .
    "JOIN {$tbl['tcversions']} tv ON tv.id = tpt.tcversion_id " .
    "JOIN {$tbl['nodes_hierarchy']} vn ON vn.id = tv.id AND vn.node_type_id = 4 " .
    "JOIN {$tbl['nodes_hierarchy']} tn ON tn.id = vn.parent_id AND tn.node_type_id = 3 " .
    "WHERE tpt.testplan_id = " . intval($tplanId) .
    ($platformId > 0
        ? " AND (tpt.platform_id = " . intval($platformId) . " OR tpt.platform_id = 0)"
        : "") .
    " ORDER BY tpt.tcversion_id");
if (is_array($assignedRs)) {
    foreach ($assignedRs as $r) {
        $tcv = intval($r['tcversion_id']);
        if (isset($versions[$tcv])) {
            continue;
        }
        $versions[$tcv] = array(
            'tcversion_id' => $tcv,
            'version' => intval($r['version']),
            'external_id' => intval($r['tc_external_id']),
            'tcase_name' => fpDecode($r['tcase_name']),
            'tcase_node_id' => intval($r['tcase_node_id']),
        );
    }
}

// Per-version execution counters inside the scope.
$execCounters = array();
$latestUnit = array();
$latestCase = array();
if (count($versions) > 0) {
    $inList = implode(',', array_map('intval', array_keys($versions)));
    $whereExec = "e.testplan_id = " . intval($tplanId) .
        " AND e.tcversion_id IN (" . $inList . ")";
    if ($buildId > 0) {
        $whereExec .= " AND e.build_id = " . intval($buildId);
    }
    if ($platformId > 0) {
        $whereExec .= " AND e.platform_id = " . intval($platformId);
    }

    $aggRs = $db->get_recordset(
        "SELECT e.tcversion_id, " .
        "       COUNT(*) AS total_exec, " .
        "       SUM(CASE WHEN e.status = 'p' THEN 1 ELSE 0 END) AS passed_exec, " .
        "       SUM(CASE WHEN e.status = 'f' THEN 1 ELSE 0 END) AS failed_exec, " .
        "       SUM(CASE WHEN e.status = 'b' THEN 1 ELSE 0 END) AS blocked_exec " .
        "FROM {$tbl['executions']} e " .
        "WHERE " . $whereExec . " " .
        "GROUP BY e.tcversion_id");
    if (is_array($aggRs)) {
        foreach ($aggRs as $r) {
            $execCounters[intval($r['tcversion_id'])] = array(
                'total' => intval($r['total_exec']),
                'passed' => intval($r['passed_exec']),
                'failed' => intval($r['failed_exec']),
                'blocked' => intval($r['blocked_exec']),
            );
        }
    }

    // Latest execution per (version, platform) and latest per version, by id.
    $execRs = $db->get_recordset(
        "SELECT e.id, e.tcversion_id, e.platform_id, e.status, e.execution_ts " .
        "FROM {$tbl['executions']} e " .
        "WHERE " . $whereExec . " " .
        "ORDER BY e.id");
    if (is_array($execRs)) {
        foreach ($execRs as $r) {
            $tcv = intval($r['tcversion_id']);
            $plat = intval($r['platform_id']);
            $status = (string)$r['status'];
            if ($status !== 'p' && $status !== 'f' && $status !== 'b') {
                $status = 'n';
            }
            $latestUnit[$tcv . ':' . $plat] = $status;
            $latestCase[$tcv] = array('status' => $status, 'ts' => (string)$r['execution_ts']);
        }
    }
}

// Suite path of every test case in scope.
$suitePaths = array();
if (count($versions) > 0) {
    $tcaseNodes = array();
    foreach ($versions as $v) {
        $tcaseNodes[$v['tcase_node_id']] = true;
    }
    $nodeList = implode(',', array_map('intval', array_keys($tcaseNodes)));
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
            $suitePaths[intval($r['tcase_node'])] = fpDecode($r['suite_path']);
        }
    }
}

$prefixRs = $db->get_recordset(
    "SELECT prefix FROM {$tbl['testprojects']} WHERE id = " . intval($owningProjectId));
$prefix = (is_array($prefixRs) && count($prefixRs) > 0) ? (string)$prefixRs[0]['prefix'] : '';

// Aggregate per TEST CASE.
$byCase = array();
$summaryTotals = array(
    'assigned_versions' => count($versions),
    'executed_versions' => 0,
    'total_executions' => 0,
    'passed_executions' => 0,
    'failed_executions' => 0,
    'blocked_executions' => 0,
);
foreach ($versions as $tcv => $v) {
    $case = $v['tcase_node_id'];
    if (!isset($byCase[$case])) {
        $byCase[$case] = array(
            'tcase_node_id' => $case,
            'tcase_name' => $v['tcase_name'],
            'external_full' => ($v['external_id'] > 0
                ? (($prefix !== '' ? $prefix . '-' : '') . $v['external_id'])
                : ''),
            'suite_path' => $suitePaths[$case] ?? '',
            'assigned_versions' => 0,
            'executed_versions' => 0,
            'failed_executions' => 0,
            'passed_executions' => 0,
            'blocked_executions' => 0,
            'total_executions' => 0,
            'failing_units' => 0,
            'latest_status' => 'not_run',
            'last_ts' => '',
        );
    }
    $row = &$byCase[$case];
    $row['assigned_versions']++;

    $c = $execCounters[$tcv] ?? null;
    if ($c !== null) {
        $row['total_executions'] += $c['total'];
        $row['failed_executions'] += $c['failed'];
        $row['passed_executions'] += $c['passed'];
        $row['blocked_executions'] += $c['blocked'];
        if ($c['total'] > 0) {
            $row['executed_versions']++;
        }
        $summaryTotals['total_executions'] += $c['total'];
        $summaryTotals['failed_executions'] += $c['failed'];
        $summaryTotals['passed_executions'] += $c['passed'];
        $summaryTotals['blocked_executions'] += $c['blocked'];
    }
    if (isset($latestCase[$tcv])) {
        $lc = $latestCase[$tcv];
        if ($row['last_ts'] === '' || strcmp($lc['ts'], $row['last_ts']) > 0) {
            $row['last_ts'] = $lc['ts'];
            $row['latest_status'] = ($lc['status'] === 'n') ? 'not_run' : $lc['status'];
        }
    }
    unset($row);
}

// Failing units: latest execution per (version, platform) is failed.
foreach ($latestUnit as $key => $status) {
    if ($status !== 'f') {
        continue;
    }
    $parts = explode(':', $key);
    $tcv = intval($parts[0]);
    if (!isset($versions[$tcv])) {
        continue;
    }
    $case = $versions[$tcv]['tcase_node_id'];
    $byCase[$case]['failing_units']++;
}
foreach ($byCase as $case => $row) {
    if ($row['executed_versions'] > 0) {
        $summaryTotals['executed_versions']++;
    }
}

// Pareto rows: every test case with at least one failed execution.
$rows = array();
$totalFailures = 0;
foreach ($byCase as $row) {
    if ($row['failed_executions'] <= 0) {
        continue;
    }
    $totalFailures += $row['failed_executions'];
    $row['failure_rate_pct'] = ($row['total_executions'] > 0)
        ? fpRound(100.0 * $row['failed_executions'] / $row['total_executions'], 1) : 0.0;
    $rows[] = $row;
}

usort($rows, function ($a, $b) {
    if ($a['failed_executions'] !== $b['failed_executions']) {
        return $b['failed_executions'] - $a['failed_executions'];
    }
    if ($a['failing_units'] !== $b['failing_units']) {
        return $b['failing_units'] - $a['failing_units'];
    }
    return strcmp($a['tcase_name'], $b['tcase_name']);
});

$cumulative = 0;
$casesTo80 = 0;
$reached80 = false;
$rank = 0;
foreach ($rows as $i => $row) {
    $rank++;
    $cumulative += $row['failed_executions'];
    $pct = ($totalFailures > 0) ? fpRound(100.0 * $cumulative / $totalFailures, 1) : 0.0;
    if (!$reached80 && $pct >= 80.0) {
        $reached80 = true;
        $casesTo80 = $rank;
    }
    $rows[$i]['rank'] = $rank;
    $rows[$i]['cumulative_failures'] = $cumulative;
    $rows[$i]['cumulative_pct'] = $pct;
    $rows[$i]['in_top80'] = ($casesTo80 === 0) ? true : ($rank <= $casesTo80);
}
if ($totalFailures > 0 && !$reached80) {
    $casesTo80 = count($rows);
}
foreach ($rows as $i => $row) {
    $rows[$i]['in_top80'] = ($casesTo80 > 0) ? ($row['rank'] <= $casesTo80) : false;
}

$failingCases = count($rows);
$topShare = ($rows && $totalFailures > 0)
    ? fpRound(100.0 * $rows[0]['failed_executions'] / $totalFailures, 1) : 0.0;
$verdict = 'clean';
if ($failingCases > 0) {
    $shareOfCases = 100.0 * $casesTo80 / $failingCases;
    $verdict = ($shareOfCases <= 20.0) ? 'concentrated' : 'diffuse';
}

$summary = array(
    'assigned_versions' => $summaryTotals['assigned_versions'],
    'executed_versions' => $summaryTotals['executed_versions'],
    'executed_coverage_pct' => ($summaryTotals['assigned_versions'] > 0)
        ? fpRound(100.0 * $summaryTotals['executed_versions'] / $summaryTotals['assigned_versions'], 1) : 0.0,
    'total_executions' => $summaryTotals['total_executions'],
    'passed_executions' => $summaryTotals['passed_executions'],
    'failed_executions' => $summaryTotals['failed_executions'],
    'blocked_executions' => $summaryTotals['blocked_executions'],
    'failure_rate_pct' => ($summaryTotals['total_executions'] > 0)
        ? fpRound(100.0 * $summaryTotals['failed_executions'] / $summaryTotals['total_executions'], 1) : 0.0,
    'failing_cases' => $failingCases,
    'failing_units' => array_sum(array_map(function ($r) { return intval($r['failing_units']); }, $rows)),
    'top_case_share_pct' => $topShare,
    'cases_to_80' => $casesTo80,
    'cases_to_80_pct' => ($failingCases > 0)
        ? fpRound(100.0 * $casesTo80 / $failingCases, 1) : 0.0,
    'verdict' => $verdict,
);

fpOut(array(
    'status' => 'ok',
    'code' => 200,
    'context' => $context,
    'filters' => $filters,
    'filter' => $filterState,
    'summary' => $summary,
    'rows' => $rows,
));
