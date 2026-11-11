<?php
/**
 * Test Plan Estimated vs Actual Execution Effort report - BFF (Refs #1984).
 *
 * Net-new modern screen (gui/templates/results/execEffort.html). TestLink
 * stores BOTH numbers for years - `tcversions.estimated_exec_duration`
 * (the per-version estimate, minutes) and `executions.execution_duration`
 * (what a run actually took) - but 1.9.20/2.0.1 ships no screen that ever
 * compares them. #1975 (execDuration) reports actual durations only and
 * #1983 (planForecast) projects velocity; neither answers the manager
 * question "which tests run over budget, and how far off was the estimate?".
 *
 * Semantics (documented here because the screen relies on them):
 *   - the unit is the test-case VERSION assigned to the plan
 *     (`testplan_tcversions`); every ASSIGNED version is listed, executed or
 *     not, so the "not run" rows keep their planned effort visible.
 *   - `estimated_exec_duration` NULL or 0 means "no estimate recorded" - the
 *     row is then reported as `no_estimate` and never judged; an executed
 *     version whose executions never carried a recorded duration is
 *     reported as `untimed` (no actual effort data). Neither is judged.
 *   - `execution_duration` NULL / 0 on an execution means "not recorded"
 *     (same rule as #1975): it counts as an execution but never enters the
 *     actual-effort maths.
 *   - ACTUAL effort of a version = the SUM of the recorded durations of its
 *     executions inside the selected scope (a re-run costs again).
 *   - variance = actual - estimated (positive = over budget).
 *   - a timed+estimated version is OVER budget when its actual total exceeds
 *     the estimate by more than `over_pct` percent (default 0, 0..100).
 *   - a platform filter narrows BOTH the assignment universe and the
 *     executions; a build filter narrows the executions only.
 *
 * Actions
 *   GET|HEAD ?action=init&tplan_id=N[&tproject_id=P]
 *       -> context (plan / project) + the build and platform filters
 *   GET|HEAD ?action=report&tplan_id=N[&tproject_id=P][&build_id=B]
 *             [&platform_id=PL][&over_pct=O]
 *       -> summary KPIs + per-version rows + per-suite breakdown
 *
 * Security: session authentication, the shared BFF same-origin guard and the
 * `testplan_metrics` right on the OWNING test project (getAccess = true). The
 * client supplied tproject_id is only an ASSERTION: it is compared with the
 * plan's owning project and never used to widen the query.
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
function eeOut($payload, $code = null)
{
    if ($code !== null) {
        http_response_code($code);
    }
    echo json_encode($payload);
    exit;
}

function eeFail($httpCode, $code, $message = '')
{
    eeOut(array(
        'status' => 'error',
        'code' => $code,
        'message' => $message !== '' ? $message : $code,
    ), $httpCode);
}

/** Strict positive integer reader - "1abc", "1.9", "-3" and "" are all refused. */
function eePositiveInt($raw)
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

function eeDecode($value)
{
    return html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function eeRound($value, $decimals = 2)
{
    return round((float)$value, $decimals);
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    eeFail(405, 'method_not_allowed');
}

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    eeFail(401, 'not_authenticated', 'Not authenticated');
}

bffEnforceSession($db);

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    eeFail(401, 'not_authenticated', 'User not found');
}

$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if ($action !== 'init' && $action !== 'report') {
    eeFail(400, 'invalid_request', 'Unknown action');
}

$tplanId = eePositiveInt($_GET['tplan_id'] ?? null);
$assertedProjectId = eePositiveInt($_GET['tproject_id'] ?? null);
$buildId = eePositiveInt($_GET['build_id'] ?? null);
$platformId = eePositiveInt($_GET['platform_id'] ?? null);

if ($tplanId <= 0) {
    eeFail(400, 'invalid_request', 'Missing or invalid tplan_id');
}

// Over-budget tolerance (percent above the estimate), bounded so a crafted
// value can never produce a nonsensical KPI.
$overPct = 0;
if (isset($_GET['over_pct'])) {
    $raw = is_array($_GET['over_pct']) ? '' : trim((string)$_GET['over_pct']);
    if ($raw === '' || !preg_match('/^[0-9]+$/', $raw)) {
        eeFail(400, 'invalid_request', 'Invalid over_pct');
    }
    $overPct = intval($raw);
    if ($overPct < 0 || $overPct > 100) {
        eeFail(400, 'invalid_request', 'over_pct out of range');
    }
}

// Order of operations (identical to api/execduration and api/flakytests):
//   1. resolve the plan -> opaque 404 when it does not exist or has no owner
//   2. check the right on the OWNING project (getAccess = true)
//   3. only then honour the client's tproject_id assertion
$tbl = tlObjectWithDB::getDBTables(array(
    'testplans', 'builds', 'platforms', 'testplan_tcversions', 'tcversions',
    'nodes_hierarchy', 'executions', 'testprojects',
));
$planRow = $db->get_recordset(
    "SELECT id, testproject_id FROM {$tbl['testplans']} WHERE id = " . intval($tplanId));

if (!is_array($planRow) || count($planRow) === 0) {
    eeFail(404, 'tplan_not_found', 'Test plan not found');
}

$plan = $planRow[0];
$owningProjectId = intval($plan['testproject_id']);
if ($owningProjectId <= 0) {
    eeFail(404, 'tplan_not_found', 'Test plan has no owning test project');
}

if (!$user->hasRight($db, 'testplan_metrics', $owningProjectId, $tplanId, true)) {
    tLog('BFF execeffort: user ' . intval($userId) .
         ' has no testplan_metrics right on tproject ' . $owningProjectId .
         ' (tplan ' . $tplanId . ')', 'ERROR');
    eeFail(403, 'no_right', 'testplan_metrics right required');
}

if ($assertedProjectId > 0 && $assertedProjectId !== $owningProjectId) {
    eeFail(404, 'project_mismatch', 'Test plan belongs to another test project');
}

$planName = testplan::getName($db, $tplanId);
$projectName = testproject::getName($db, $owningProjectId);
if (is_null($planName) || trim((string)$planName) === '') {
    eeFail(404, 'tplan_not_found', 'Test plan not found');
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
        $builds[] = array('id' => intval($r['id']), 'name' => eeDecode($r['name']));
    }
}
if ($buildId > 0) {
    $known = false;
    foreach ($builds as $b) {
        if ($b['id'] === $buildId) { $known = true; break; }
    }
    if (!$known) {
        eeFail(400, 'invalid_request', 'build_id does not belong to this test project');
    }
}

$platforms = array();
$rp = $db->get_recordset(
    "SELECT id, name FROM {$tbl['platforms']} WHERE testproject_id = " . intval($owningProjectId) .
    " ORDER BY name");
if (is_array($rp)) {
    foreach ($rp as $r) {
        $platforms[] = array('id' => intval($r['id']), 'name' => eeDecode($r['name']));
    }
}
if ($platformId > 0) {
    $known = false;
    foreach ($platforms as $p) {
        if ($p['id'] === $platformId) { $known = true; break; }
    }
    if (!$known) {
        eeFail(400, 'invalid_request', 'platform_id does not belong to this test project');
    }
}

// Name map of every build of the project (also the deactivated ones, so an
// execution recorded on a closed build is never rendered as "#<id>").
$buildNames = array();
$rb = $db->get_recordset(
    "SELECT id, name FROM {$tbl['builds']} WHERE testproject_id = " . intval($owningProjectId));
if (is_array($rb)) {
    foreach ($rb as $r) {
        $buildNames[intval($r['id'])] = eeDecode($r['name']);
    }
}

$context = array(
    'tplan_id' => $tplanId,
    'tplan_name' => (string)$planName,
    'tproject_id' => $owningProjectId,
    'tproject_name' => (string)$projectName,
);
$filters = array('builds' => $builds, 'platforms' => $platforms);

if ($action === 'init') {
    eeOut(array(
        'status' => 'ok',
        'code' => 200,
        'context' => $context,
        'filters' => $filters,
        'filter' => array(
            'build_id' => $buildId,
            'platform_id' => $platformId,
            'over_pct' => $overPct,
        ),
    ));
}

// ---------------------------------------------------------------------------
// Universe: the test-case versions CURRENTLY assigned to the plan. A
// platform filter narrows the universe too (the platform is part of the
// assignment in TestLink); a build filter narrows the executions only.
// ---------------------------------------------------------------------------
$whereAssign = "tpt.testplan_id = " . intval($tplanId);
if ($platformId > 0) {
    $whereAssign .= " AND tpt.platform_id = " . intval($platformId);
}

$assignRs = $db->get_recordset(
    "SELECT DISTINCT tpt.tcversion_id FROM {$tbl['testplan_tcversions']} tpt WHERE " .
    $whereAssign);

$assignedVersionIds = array();
if (is_array($assignRs)) {
    foreach ($assignRs as $r) {
        $assignedVersionIds[] = intval($r['tcversion_id']);
    }
}
$assignedVersionIds = array_values(array_unique($assignedVersionIds));

// ---------------------------------------------------------------------------
// Version metadata: name, external id, version number, ESTIMATE, suite path.
// ---------------------------------------------------------------------------
$meta = array();
$suitePaths = array();
$versionInList = '';
if (count($assignedVersionIds) > 0) {
    $versionInList = implode(',', $assignedVersionIds);
    $nameRs = $db->get_recordset(
        "SELECT tv.id AS tcversion_id, tv.tc_external_id, tv.version, " .
        "       tv.estimated_exec_duration, tn.name AS tcase_name, tn.id AS tcase_node_id " .
        "FROM {$tbl['tcversions']} tv " .
        "JOIN {$tbl['nodes_hierarchy']} vn ON vn.id = tv.id AND vn.node_type_id = 4 " .
        "JOIN {$tbl['nodes_hierarchy']} tn ON tn.id = vn.parent_id AND tn.node_type_id = 3 " .
        "WHERE tv.id IN (" . $versionInList . ")");
    if (is_array($nameRs)) {
        foreach ($nameRs as $r) {
            $meta[intval($r['tcversion_id'])] = array(
                'tcase_name' => eeDecode($r['tcase_name']),
                'external_id' => intval($r['tc_external_id']),
                'version' => intval($r['version']),
                'estimated' => (($r['estimated_exec_duration'] === null) ? 0.0
                    : (float)$r['estimated_exec_duration']),
                'tcase_node_id' => intval($r['tcase_node_id']),
            );
        }
    }

    $pathSql =
        "WITH RECURSIVE anc AS (" .
        "  SELECT tn.id AS tcase_node, sn.id AS node_id, sn.name AS node_name, " .
        "         sn.parent_id, 0 AS depth " .
        "  FROM {$tbl['nodes_hierarchy']} tn " .
        "  JOIN {$tbl['nodes_hierarchy']} sn ON sn.id = tn.parent_id AND sn.node_type_id = 2 " .
        "  JOIN {$tbl['nodes_hierarchy']} vn ON vn.parent_id = tn.id AND vn.node_type_id = 4 " .
        "  JOIN {$tbl['tcversions']} tv ON tv.id = vn.id " .
        "  WHERE tv.id IN (" . $versionInList . ")" .
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
            $suitePaths[intval($r['tcase_node'])] = eeDecode($r['suite_path']);
        }
    }
}

// ---------------------------------------------------------------------------
// Executions inside the scope (build / platform filters, plan-scoped, and
// only versions still linked to the plan - an execution that survives an
// un-assignment must not resurrect the version in this plan's report).
// ---------------------------------------------------------------------------
$whereExec = "e.testplan_id = " . intval($tplanId) .
    " AND e.execution_ts IS NOT NULL" .
    " AND e.tcversion_id IN (SELECT tpt.tcversion_id FROM {$tbl['testplan_tcversions']} tpt" .
    " WHERE tpt.testplan_id = " . intval($tplanId) . ")";
if ($buildId > 0) {
    $whereExec .= " AND e.build_id = " . intval($buildId);
}
if ($platformId > 0) {
    $whereExec .= " AND e.platform_id = " . intval($platformId);
}

$execRs = $db->get_recordset(
    "SELECT e.tcversion_id, e.build_id, e.execution_duration, e.execution_ts " .
    "FROM {$tbl['executions']} e WHERE " . $whereExec .
    " ORDER BY e.execution_ts, e.id");

$perVersion = array();
$totalExecutions = 0;
$timedExecutions = 0;
$actualTotal = 0.0;

if (is_array($execRs)) {
    foreach ($execRs as $r) {
        $tcv = intval($r['tcversion_id']);
        $bid = intval($r['build_id']);
        $dur = ($r['execution_duration'] === null) ? null : (float)$r['execution_duration'];
        $hasDur = ($dur !== null && $dur > 0);

        $totalExecutions++;
        if (!isset($perVersion[$tcv])) {
            $perVersion[$tcv] = array(
                'executions' => 0, 'timed' => 0, 'sum' => 0.0,
                'last_ts' => '', 'builds' => array(),
            );
        }
        $perVersion[$tcv]['executions']++;
        $perVersion[$tcv]['last_ts'] = (string)$r['execution_ts'];
        if ($bid > 0) { $perVersion[$tcv]['builds'][$bid] = true; }

        if ($hasDur) {
            $timedExecutions++;
            $actualTotal += $dur;
            $perVersion[$tcv]['timed']++;
            $perVersion[$tcv]['sum'] += $dur;
        }
    }
}

// ---------------------------------------------------------------------------
// Rows: every ASSIGNED version, executed or not.
// ---------------------------------------------------------------------------
$prefixRs = $db->get_recordset(
    "SELECT prefix FROM {$tbl['testprojects']} WHERE id = " . intval($owningProjectId));
$prefix = (is_array($prefixRs) && count($prefixRs) > 0) ? (string)$prefixRs[0]['prefix'] : '';

$tolerance = 1.0 + ($overPct / 100.0);
$rows = array();
$summary = array(
    'over_pct' => $overPct,
    'versions' => count($assignedVersionIds),
    'executed' => 0,
    'timed_versions' => 0,
    'not_run' => 0,
    'untimed' => 0,
    'no_estimate' => 0,
    'over_budget' => 0,
    'within_budget' => 0,
    'executions' => $totalExecutions,
    'timed_executions' => $timedExecutions,
    'estimated_total' => 0.0,
    'estimated_executed' => 0.0,
    'actual_total' => 0.0,
    'variance' => 0.0,
    'variance_pct' => null,
    'overrun_minutes' => 0.0,
    'saved_minutes' => 0.0,
);

foreach ($assignedVersionIds as $tcv) {
    $m = $meta[$tcv] ?? array('tcase_name' => ('#' . $tcv), 'external_id' => 0,
        'version' => 1, 'estimated' => 0.0, 'tcase_node_id' => 0);
    $v = $perVersion[$tcv] ?? null;
    $executions = $v ? $v['executions'] : 0;
    $timed = $v ? $v['timed'] : 0;
    $actual = $v ? eeRound($v['sum'], 2) : 0.0;
    $estimated = eeRound($m['estimated'], 2);

    $summary['estimated_total'] += $estimated;

    $suitePath = $m['tcase_node_id'] > 0 ? ($suitePaths[$m['tcase_node_id']] ?? '') : '';

    if ($executions === 0) {
        $verdict = 'not_run';
        $variance = null;
        $variancePct = null;
        $summary['not_run']++;
    } elseif ($timed === 0) {
        // Executed but never with a recorded duration: no actual effort data.
        $verdict = 'untimed';
        $variance = null;
        $variancePct = null;
        $summary['executed']++;
        $summary['untimed']++;
    } elseif ($estimated <= 0) {
        $verdict = 'no_estimate';
        $variance = null;
        $variancePct = null;
        $summary['executed']++;
        $summary['timed_versions']++;
        $summary['no_estimate']++;
    } else {
        $summary['executed']++;
        $summary['timed_versions']++;
        $summary['estimated_executed'] += $estimated;
        $variance = eeRound($actual - $estimated, 2);
        $variancePct = eeRound(100.0 * ($actual - $estimated) / $estimated, 1);
        if ($variance > 0) {
            $summary['overrun_minutes'] += $variance;
        } else {
            $summary['saved_minutes'] += abs($variance);
        }
        if ($actual > $estimated * $tolerance) {
            $verdict = 'over';
            $summary['over_budget']++;
        } else {
            $verdict = 'within';
            $summary['within_budget']++;
        }
    }

    $buildList = array();
    if ($v) {
        foreach (array_keys($v['builds']) as $bid) {
            $buildList[] = array('id' => intval($bid), 'name' => $buildNames[$bid] ?? ('#' . $bid));
        }
    }

    $rows[] = array(
        'tcversion_id' => intval($tcv),
        'tcase_name' => $m['tcase_name'],
        'external_id' => $m['external_id'],
        'version' => $m['version'],
        'external_full' => ($m['external_id'] > 0
            ? (($prefix !== '' ? $prefix . '-' : '') . $m['external_id'])
            : ''),
        'suite_path' => $suitePath,
        'verdict' => $verdict,
        'executions' => $executions,
        'timed' => $timed,
        'estimated' => $estimated,
        'actual' => $actual,
        'variance' => $variance,
        'variance_pct' => $variancePct,
        'last_ts' => $v ? $v['last_ts'] : '',
        'builds' => $buildList,
    );
}

$summary['estimated_total'] = eeRound($summary['estimated_total'], 2);
$summary['estimated_executed'] = eeRound($summary['estimated_executed'], 2);
$summary['actual_total'] = eeRound($actualTotal, 2);
$summary['variance'] = eeRound($actualTotal - $summary['estimated_executed'], 2);
$summary['overrun_minutes'] = eeRound($summary['overrun_minutes'], 2);
$summary['saved_minutes'] = eeRound($summary['saved_minutes'], 2);
$summary['variance_pct'] = ($summary['estimated_executed'] > 0)
    ? eeRound(100.0 * ($actualTotal - $summary['estimated_executed']) / $summary['estimated_executed'], 1)
    : null;

// Verdict precedence (documented in the screen): no_data > over > within.
$verdict = 'no_data';
if ($summary['timed_versions'] > 0 && $summary['estimated_executed'] > 0) {
    $verdict = ($summary['over_budget'] > 0 || $summary['variance'] > 0) ? 'over' : 'within';
}

// Worst overrun first, then alphabetical.
usort($rows, function ($a, $b) {
    $va = ($a['variance'] === null) ? -INF : (float)$a['variance'];
    $vb = ($b['variance'] === null) ? -INF : (float)$b['variance'];
    if ($va == $vb) {
        return strcmp($a['tcase_name'], $b['tcase_name']);
    }
    return ($va < $vb) ? 1 : -1;
});

// ---------------------------------------------------------------------------
// Per-suite breakdown: assigned / executed / estimated / actual / variance.
// ---------------------------------------------------------------------------
$perSuite = array();
foreach ($rows as $r) {
    $sp = ($r['suite_path'] !== '') ? $r['suite_path'] : '(no suite)';
    if (!isset($perSuite[$sp])) {
        $perSuite[$sp] = array('suite' => $sp, 'assigned' => 0, 'executed' => 0,
            'estimated' => 0.0, 'actual' => 0.0);
    }
    $perSuite[$sp]['assigned']++;
    $perSuite[$sp]['estimated'] += (float)$r['estimated'];
    if ($r['executions'] > 0) {
        $perSuite[$sp]['executed']++;
        if ($r['timed'] > 0) {
            $perSuite[$sp]['actual'] += (float)$r['actual'];
        }
    }
}
$suiteRows = array();
foreach ($perSuite as $sp => $s) {
    $suiteRows[] = array(
        'suite' => $s['suite'],
        'assigned' => $s['assigned'],
        'executed' => $s['executed'],
        'estimated' => eeRound($s['estimated'], 2),
        'actual' => eeRound($s['actual'], 2),
        'variance' => ($s['actual'] > 0) ? eeRound($s['actual'] - $s['estimated'], 2) : null,
    );
}
usort($suiteRows, function ($a, $b) { return strcmp($a['suite'], $b['suite']); });

eeOut(array(
    'status' => 'ok',
    'code' => 200,
    'context' => $context,
    'filters' => $filters,
    'filter' => array(
        'build_id' => $buildId,
        'platform_id' => $platformId,
        'over_pct' => $overPct,
    ),
    'summary' => $summary,
    'verdict' => $verdict,
    'rows' => $rows,
    'suites' => $suiteRows,
));
