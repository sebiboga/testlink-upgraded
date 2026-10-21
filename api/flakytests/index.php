<?php
/**
 * Flaky / Unstable Test Cases report - BFF (Refs #1954).
 *
 * Net-new modern screen (gui/templates/results/flakyTests.html). TestLink
 * 1.9.20 shipped no screen that answered "which test cases are flaky?": the
 * execution history of a test-case version was only visible one execution at
 * a time (lib/execute/...), and the Reports menu showed the CURRENT status of
 * a version (the last execution), never the pattern of its history.
 *
 * A test-case version is FLAKY when its execution history contains at least
 * one PASSED and at least one FAILED execution, and it has been executed at
 * least `min_exec` times (default 2). A version whose executions are all
 * identical (all passed / all failed / all blocked) is STABLE, a version
 * executed fewer than `min_exec` times is UNPROVEN (not enough evidence) and
 * a version linked to the plan but never executed is NOT RUN.
 *
 * Semantics (documented here because the screen relies on them):
 *   - the unit is the test-case VERSION (tcversion_id), never the test case:
 *     two versions of the same test case are two independent units;
 *   - EVERY execution row counts (flakiness is a property of the history,
 *     not of the last run), so the build / platform filters narrow the
 *     history that is analysed;
 *   - BLOCKED executions are counted but never turn a version flaky (a
 *     blocked run is not evidence of a broken test);
 *   - a version that is linked to the plan but has no execution in the
 *     analysed scope is reported as NOT RUN.
 *
 * Actions
 *   GET|HEAD ?action=init&tplan_id=N[&tproject_id=P]
 *       -> context (plan / project) + the build and platform filters
 *   GET|HEAD ?action=report&tplan_id=N[&tproject_id=P][&build_id=B]
 *             [&platform_id=PL][&min_exec=K]
 *       -> summary + the per-version verdict rows
 *   GET|HEAD ?action=history&tplan_id=N&tcversion_id=V[&build_id=B]
 *             [&platform_id=PL]
 *       -> the execution history of one version (modal of the screen)
 *
 * Security: session authentication, the shared BFF same-origin guard and
 * the `testplan_metrics` right on the OWNING test project (getAccess = true).
 * The client supplied tproject_id is only an ASSERTION: it is compared with
 * the plan's owning project and never used to widen the query.
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
function ftOut($payload, $code = null)
{
    if ($code !== null) {
        http_response_code($code);
    }
    echo json_encode($payload);
    exit;
}

function ftFail($httpCode, $code, $message = '')
{
    ftOut(array(
        'status' => 'error',
        'code' => $code,
        'message' => $message !== '' ? $message : $code,
    ), $httpCode);
}

/** Strict positive integer reader - "1abc", "1.9", "-3" and "" are all refused. */
function ftPositiveInt($raw)
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
function ftStatusKey($raw)
{
    switch ((string)$raw) {
        case 'p': return 'passed';
        case 'f': return 'failed';
        case 'b': return 'blocked';
        default:  return 'not_run';
    }
}

function ftPct($num, $den)
{
    if ($den <= 0) {
        return 0.0;
    }
    return round(100.0 * $num / $den, 1);
}

function ftDecode($value)
{
    return html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    ftFail(405, 'method_not_allowed');
}

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    ftFail(401, 'not_authenticated', 'Not authenticated');
}

bffEnforceSession($db);

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    ftFail(401, 'not_authenticated', 'User not found');
}

$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if ($action !== 'init' && $action !== 'report' && $action !== 'history') {
    ftFail(400, 'invalid_request', 'Unknown action');
}

$tplanId = ftPositiveInt($_GET['tplan_id'] ?? null);
$assertedProjectId = ftPositiveInt($_GET['tproject_id'] ?? null);
$buildId = ftPositiveInt($_GET['build_id'] ?? null);
$platformId = ftPositiveInt($_GET['platform_id'] ?? null);
$tcversionId = ftPositiveInt($_GET['tcversion_id'] ?? null);

if ($tplanId <= 0) {
    ftFail(400, 'invalid_request', 'Missing or invalid tplan_id');
}

// The minimum number of executions that makes a version PROVEN. 1 is allowed
// (a single execution is a valid observation) but a value that cannot be a
// count is refused instead of being silently rounded.
$minExec = 2;
if (isset($_GET['min_exec']) && !is_array($_GET['min_exec'])) {
    $rawMinExec = trim((string)$_GET['min_exec']);
    if ($rawMinExec === '' || !preg_match('/^[0-9]+$/', $rawMinExec)) {
        ftFail(400, 'invalid_request', 'Invalid min_exec');
    }
    $minExec = intval($rawMinExec);
    if ($minExec < 1 || $minExec > 100) {
        ftFail(400, 'invalid_request', 'min_exec out of range');
    }
}

// Order of operations (identical to api/metricstrends):
//   1. resolve the plan -> opaque 404 when it does not exist or has no owner
//   2. check the right on the OWNING project (getAccess = true)
//   3. only then honour the client's tproject_id assertion
$tbl = tlObjectWithDB::getDBTables(array(
    'testplans', 'builds', 'platforms', 'testplan_tcversions', 'tcversions',
    'nodes_hierarchy', 'executions', 'testprojects', 'users',
));
$planRow = $db->get_recordset(
    "SELECT id, testproject_id FROM {$tbl['testplans']} WHERE id = " . intval($tplanId));

if (!is_array($planRow) || count($planRow) === 0) {
    ftFail(404, 'tplan_not_found', 'Test plan not found');
}

$plan = $planRow[0];
$owningProjectId = intval($plan['testproject_id']);
if ($owningProjectId <= 0) {
    ftFail(404, 'tplan_not_found', 'Test plan has no owning test project');
}

if (!$user->hasRight($db, 'testplan_metrics', $owningProjectId, $tplanId, true)) {
    tLog('BFF flakytests: user ' . intval($userId) .
         ' has no testplan_metrics right on tproject ' . $owningProjectId .
         ' (tplan ' . $tplanId . ')', 'ERROR');
    ftFail(403, 'no_right', 'testplan_metrics right required');
}

if ($assertedProjectId > 0 && $assertedProjectId !== $owningProjectId) {
    ftFail(404, 'project_mismatch', 'Test plan belongs to another test project');
}

$planName = testplan::getName($db, $tplanId);
$projectName = testproject::getName($db, $owningProjectId);
if (is_null($planName) || trim((string)$planName) === '') {
    ftFail(404, 'tplan_not_found', 'Test plan not found');
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
        $builds[] = array(
            'id' => intval($r['id']),
            'name' => ftDecode($r['name']),
        );
    }
}
// A build filter that does not belong to the project is refused (never silently
// ignored: that would leak counts from a foreign project).
if ($buildId > 0) {
    $known = false;
    foreach ($builds as $b) {
        if ($b['id'] === $buildId) { $known = true; break; }
    }
    if (!$known) {
        ftFail(400, 'invalid_request', 'build_id does not belong to this test project');
    }
}

$platforms = array();
$rp = $db->get_recordset(
    "SELECT id, name FROM {$tbl['platforms']} WHERE testproject_id = " . intval($owningProjectId) .
    " ORDER BY name");
if (is_array($rp)) {
    foreach ($rp as $r) {
        $platforms[] = array(
            'id' => intval($r['id']),
            'name' => ftDecode($r['name']),
        );
    }
}
if ($platformId > 0) {
    $known = false;
    foreach ($platforms as $p) {
        if ($p['id'] === $platformId) { $known = true; break; }
    }
    if (!$known) {
        ftFail(400, 'invalid_request', 'platform_id does not belong to this test project');
    }
}

// Name map of every build of the project (also the deactivated ones, so an
// execution recorded on a closed build is not rendered as "#<id>"); the
// dropdown above only offers the active ones.
$buildNames = array();
$rb = $db->get_recordset(
    "SELECT id, name FROM {$tbl['builds']} WHERE testproject_id = " . intval($owningProjectId) .
    " ORDER BY id");
if (is_array($rb)) {
    foreach ($rb as $r) {
        $buildNames[intval($r['id'])] = ftDecode($r['name']);
    }
}
foreach ($builds as $b) { $buildNames[$b['id']] = $b['name']; }
$platformNames = array();
foreach ($platforms as $p) { $platformNames[$p['id']] = $p['name']; }

$context = array(
    'tplan_id' => $tplanId,
    'tplan_name' => (string)$planName,
    'tproject_id' => $owningProjectId,
    'tproject_name' => (string)$projectName,
);
$filters = array('builds' => $builds, 'platforms' => $platforms);

// ---------------------------------------------------------------------------
// init: context + filters only (the caller has not chosen a filter yet).
// ---------------------------------------------------------------------------
if ($action === 'init') {
    ftOut(array(
        'status' => 'ok',
        'code' => 200,
        'context' => $context,
        'filters' => $filters,
    ));
}

// ---------------------------------------------------------------------------
// The analysed scope of the executions.
// ---------------------------------------------------------------------------
$whereExec = "e.testplan_id = " . intval($tplanId);
if ($buildId > 0) {
    $whereExec .= " AND e.build_id = " . intval($buildId);
}
if ($platformId > 0) {
    $whereExec .= " AND e.platform_id = " . intval($platformId);
}

// The universe of the report: the test-case versions linked to the plan.
// With a platform filter the universe is the versions linked ON THAT PLATFORM
// (the platform is part of the assignment in TestLink), otherwise every
// linked version counts once, no matter on how many platforms it is linked.
$linkedSql =
    "SELECT DISTINCT tpt.tcversion_id " .
    "FROM {$tbl['testplan_tcversions']} tpt " .
    "WHERE tpt.testplan_id = " . intval($tplanId);
if ($platformId > 0) {
    $linkedSql .= " AND tpt.platform_id = " . intval($platformId);
}
$linkedRs = $db->get_recordset($linkedSql);
$linkedVersions = array();
if (is_array($linkedRs)) {
    foreach ($linkedRs as $r) {
        $linkedVersions[intval($r['tcversion_id'])] = true;
    }
}

// ---------------------------------------------------------------------------
// history: the execution list of ONE version (the screen's modal).
// ---------------------------------------------------------------------------
if ($action === 'history') {
    if ($tcversionId <= 0) {
        ftFail(400, 'invalid_request', 'Missing or invalid tcversion_id');
    }
    // The version must be linked to the plan: the modal must never expose the
    // history of a version of another plan.
    $checkRs = $db->get_recordset(
        "SELECT COUNT(*) AS qty FROM {$tbl['testplan_tcversions']} " .
        "WHERE testplan_id = " . intval($tplanId) .
        " AND tcversion_id = " . intval($tcversionId));
    $qty = (is_array($checkRs) && count($checkRs) > 0) ? intval($checkRs[0]['qty']) : 0;
    if ($qty <= 0) {
        ftFail(404, 'tcase_not_found', 'Test case version is not linked to this test plan');
    }

    $histSql =
        "SELECT e.id, e.execution_ts, e.status, e.build_id, e.platform_id, " .
        "       e.execution_duration, e.notes, e.tcversion_number, e.tester_id, " .
        "       b.name AS build_name, p.name AS platform_name, " .
        "       CONCAT(COALESCE(u.first, ''), ' ', COALESCE(u.last, '')) AS tester_name " .
        "FROM {$tbl['executions']} e " .
        "LEFT JOIN {$tbl['builds']} b ON b.id = e.build_id " .
        "LEFT JOIN {$tbl['platforms']} p ON p.id = e.platform_id " .
        "LEFT JOIN {$tbl['users']} u ON u.id = e.tester_id " .
        "WHERE " . $whereExec . " AND e.tcversion_id = " . intval($tcversionId) . " " .
        "ORDER BY e.execution_ts, e.id";
    $histRs = $db->get_recordset($histSql);
    $history = array();
    if (is_array($histRs)) {
        foreach ($histRs as $r) {
            $history[] = array(
                'id' => intval($r['id']),
                'execution_ts' => (string)$r['execution_ts'],
                'status' => ftStatusKey($r['status']),
                'status_raw' => (string)$r['status'],
                'build_id' => intval($r['build_id']),
                'build_name' => ftDecode($r['build_name'] ?? ''),
                'platform_id' => intval($r['platform_id']),
                'platform_name' => ftDecode($r['platform_name'] ?? ''),
                'duration' => intval($r['execution_duration']),
                'notes' => ftDecode($r['notes'] ?? ''),
                'tester_name' => trim(ftDecode($r['tester_name'] ?? '')),
            );
        }
    }

    ftOut(array(
        'status' => 'ok',
        'code' => 200,
        'context' => $context,
        'filters' => $filters,
        'filter' => array('build_id' => $buildId, 'platform_id' => $platformId, 'min_exec' => $minExec),
        'tcversion_id' => $tcversionId,
        'history' => $history,
    ));
}

// ---------------------------------------------------------------------------
// report: the per-version verdict table.
// ---------------------------------------------------------------------------
// 1. The names: a test-case version is a node (node_type_id 4) whose parent
//    is the test-case node (node_type_id 3) that carries the name.
$nameRs = $db->get_recordset(
    "SELECT tv.id AS tcversion_id, tv.tc_external_id, tv.version, " .
    "       tn.name AS tcase_name, tn.id AS tcase_node_id " .
    "FROM {$tbl['tcversions']} tv " .
    "JOIN {$tbl['nodes_hierarchy']} vn ON vn.id = tv.id AND vn.node_type_id = 4 " .
    "JOIN {$tbl['nodes_hierarchy']} tn ON tn.id = vn.parent_id AND tn.node_type_id = 3 " .
    "WHERE tv.id IN (" . (count($linkedVersions) > 0
        ? implode(',', array_map('intval', array_keys($linkedVersions)))
        : '0') . ")");
$meta = array();
if (is_array($nameRs)) {
    foreach ($nameRs as $r) {
        $meta[intval($r['tcversion_id'])] = array(
            'tcase_name' => ftDecode($r['tcase_name']),
            'external_id' => intval($r['tc_external_id']),
            'version' => intval($r['version']),
            'tcase_node_id' => intval($r['tcase_node_id']),
        );
    }
}

// 2. The suite path of every linked test case (recursive walk up the suite
//    tree). node_type_id 2 is a test suite.
$suitePaths = array();
if (count($linkedVersions) > 0) {
    $pathSql =
        "WITH RECURSIVE anc AS (" .
        "  SELECT tn.id AS tcase_node, sn.id AS node_id, sn.name AS node_name, " .
        "         sn.parent_id, 0 AS depth " .
        "  FROM {$tbl['nodes_hierarchy']} tn " .
        "  JOIN {$tbl['nodes_hierarchy']} sn ON sn.id = tn.parent_id AND sn.node_type_id = 2 " .
        "  JOIN {$tbl['nodes_hierarchy']} vn ON vn.parent_id = tn.id AND vn.node_type_id = 4 " .
        "  JOIN {$tbl['tcversions']} tv ON tv.id = vn.id " .
        "  JOIN {$tbl['testplan_tcversions']} tpt ON tpt.tcversion_id = tv.id " .
        "       AND tpt.testplan_id = " . intval($tplanId) .
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
            $suitePaths[intval($r['tcase_node'])] = ftDecode($r['suite_path']);
        }
    }
}

// 3. The execution aggregates of the analysed scope.
$aggSql =
    "SELECT e.tcversion_id, " .
    "       COUNT(*) AS exec_count, " .
    "       SUM(CASE WHEN e.status = 'p' THEN 1 ELSE 0 END) AS passed, " .
    "       SUM(CASE WHEN e.status = 'f' THEN 1 ELSE 0 END) AS failed, " .
    "       SUM(CASE WHEN e.status = 'b' THEN 1 ELSE 0 END) AS blocked, " .
    "       MIN(e.execution_ts) AS first_ts, MAX(e.execution_ts) AS last_ts, " .
    "       GROUP_CONCAT(DISTINCT e.build_id ORDER BY e.build_id) AS build_ids " .
    "FROM {$tbl['executions']} e WHERE " . $whereExec . " GROUP BY e.tcversion_id";
$agg = array();
$aggRs = $db->get_recordset($aggSql);
if (is_array($aggRs)) {
    foreach ($aggRs as $r) {
        $agg[intval($r['tcversion_id'])] = $r;
    }
}

// 4. The last execution of every version (the current state of the version).
//    The order is (execution_ts, id) - the same one used by the execution
//    history - so "last status" and "last run" always describe the same
//    execution and match the modal.
$lastRs = $db->get_recordset(
    "SELECT tcversion_id, status, execution_ts FROM (" .
    "  SELECT e.tcversion_id, e.status, e.execution_ts, " .
    "         ROW_NUMBER() OVER (PARTITION BY e.tcversion_id " .
    "                              ORDER BY e.execution_ts DESC, e.id DESC) AS rn " .
    "  FROM {$tbl['executions']} e WHERE " . $whereExec . ") t WHERE t.rn = 1");
$last = array();
if (is_array($lastRs)) {
    foreach ($lastRs as $r) {
        $last[intval($r['tcversion_id'])] = $r;
    }
}

// 5. The number of status changes in the history (the flip count).
$flipRs = $db->get_recordset(
    "SELECT tcversion_id, SUM(CASE WHEN prev IS NOT NULL AND prev <> status THEN 1 ELSE 0 END) AS flips " .
    "FROM (SELECT tcversion_id, status, " .
    "             LAG(status) OVER (PARTITION BY tcversion_id " .
    "                                ORDER BY execution_ts, id) AS prev " .
    "      FROM {$tbl['executions']} e WHERE " . $whereExec . ") t GROUP BY tcversion_id");
$flips = array();
if (is_array($flipRs)) {
    foreach ($flipRs as $r) {
        $flips[intval($r['tcversion_id'])] = intval($r['flips']);
    }
}

// The project prefix builds the external id shown in the table (FL-1).
$prefixRs = $db->get_recordset(
    "SELECT prefix FROM {$tbl['testprojects']} WHERE id = " . intval($owningProjectId));
$prefix = (is_array($prefixRs) && count($prefixRs) > 0) ? (string)$prefixRs[0]['prefix'] : '';

// ---------------------------------------------------------------------------
// The rows: one per linked test-case version.
// ---------------------------------------------------------------------------
$rows = array();
$summary = array(
    'linked_versions' => 0,
    'executed_versions' => 0,
    'flaky' => 0,
    'stable' => 0,
    'unproven' => 0,
    'not_run' => 0,
    'executions' => 0,
    'passed_executions' => 0,
    'failed_executions' => 0,
    'blocked_executions' => 0,
    'flaky_rate_pct' => 0.0,
    'avg_executions' => 0.0,
    'min_exec' => $minExec,
);

foreach (array_keys($linkedVersions) as $tcvId) {
    $tcvId = intval($tcvId);
    $m = $meta[$tcvId] ?? array(
        'tcase_name' => ('#' . $tcvId),
        'external_id' => 0,
        'version' => 1,
        'tcase_node_id' => 0,
    );
    $a = $agg[$tcvId] ?? null;
    $execCount = $a ? intval($a['exec_count']) : 0;
    $passed = $a ? intval($a['passed']) : 0;
    $failed = $a ? intval($a['failed']) : 0;
    $blocked = $a ? intval($a['blocked']) : 0;
    $flipCount = $flips[$tcvId] ?? 0;

    if ($execCount === 0) {
        $verdict = 'not_run';
    } elseif ($execCount < $minExec) {
        $verdict = 'unproven';
    } elseif ($passed > 0 && $failed > 0) {
        $verdict = 'flaky';
    } else {
        $verdict = 'stable';
    }

    $buildIds = array();
    if ($a && !is_null($a['build_ids']) && $a['build_ids'] !== '') {
        foreach (explode(',', (string)$a['build_ids']) as $bid) {
            $bid = intval($bid);
            if ($bid > 0) { $buildIds[] = $bid; }
        }
    }
    $buildList = array();
    foreach ($buildIds as $bid) {
        $buildList[] = array(
            'id' => $bid,
            'name' => $buildNames[$bid] ?? ('#' . $bid),
        );
    }

    $lastStatus = 'not_run';
    $lastTs = '';
    if (isset($last[$tcvId])) {
        $lastStatus = ftStatusKey($last[$tcvId]['status']);
        $lastTs = (string)$last[$tcvId]['execution_ts'];
    }

    $rows[] = array(
        'tcversion_id' => $tcvId,
        'tcase_name' => $m['tcase_name'],
        'suite_path' => $m['tcase_node_id'] > 0
            ? ($suitePaths[$m['tcase_node_id']] ?? '')
            : '',
        'external_id' => ($prefix !== '' && $m['external_id'] > 0)
            ? ($prefix . '-' . $m['external_id'])
            : (string)$m['external_id'],
        'version' => $m['version'],
        'verdict' => $verdict,
        'executions' => $execCount,
        'passed' => $passed,
        'failed' => $failed,
        'blocked' => $blocked,
        'flips' => $flipCount,
        'last_status' => $lastStatus,
        'last_ts' => $lastTs,
        'first_ts' => $a ? (string)$a['first_ts'] : '',
        'builds' => $buildList,
    );

    $summary['linked_versions']++;
    $summary['executions'] += $execCount;
    $summary['passed_executions'] += $passed;
    $summary['failed_executions'] += $failed;
    $summary['blocked_executions'] += $blocked;
    if ($execCount > 0) {
        $summary['executed_versions']++;
    }
    switch ($verdict) {
        case 'flaky':    $summary['flaky']++; break;
        case 'stable':   $summary['stable']++; break;
        case 'unproven': $summary['unproven']++; break;
        default:         $summary['not_run']++; break;
    }
}

// The rows are ordered by severity (flaky first), then by the number of
// status changes, then by the number of executions and finally by name, so
// the most interesting version is always the first one of the table.
$verdictOrder = array('flaky' => 0, 'stable' => 1, 'unproven' => 2, 'not_run' => 3);
usort($rows, function ($a, $b) use ($verdictOrder) {
    $va = $verdictOrder[$a['verdict']] ?? 9;
    $vb = $verdictOrder[$b['verdict']] ?? 9;
    if ($va !== $vb) { return $va - $vb; }
    if ($a['flips'] !== $b['flips']) { return $b['flips'] - $a['flips']; }
    if ($a['executions'] !== $b['executions']) { return $b['executions'] - $a['executions']; }
    return strcasecmp($a['tcase_name'], $b['tcase_name']);
});

$summary['flaky_rate_pct'] = ftPct($summary['flaky'], $summary['executed_versions']);
$summary['avg_executions'] = $summary['executed_versions'] > 0
    ? round($summary['executions'] / $summary['executed_versions'], 2)
    : 0.0;

ftOut(array(
    'status' => 'ok',
    'code' => 200,
    'context' => $context,
    'filters' => $filters,
    'filter' => array(
        'build_id' => $buildId,
        'platform_id' => $platformId,
        'min_exec' => $minExec,
    ),
    'summary' => $summary,
    'rows' => $rows,
));
