<?php
/**
 * Test Execution Duration & Throughput report - BFF (Refs #1975, #1073).
 *
 * Net-new modern screen (gui/templates/results/execDuration.html). TestLink
 * 1.9.20 stores the actual duration of every execution in
 * `executions.execution_duration` (minutes, decimal(6,2)) but ships no screen
 * that reports it: the execution history of a test case shows one run at a
 * time, and every 1.9.20 report aggregates STATUS only. As a consequence a
 * team could not answer "how long does execution take", "which tests are the
 * slowest" or "how many executions per day did we run".
 *
 * Semantics (documented here because the screen relies on them):
 *   - the analysed window is the last `window_days` days (default 30, 1..365)
 *     ending now; only executions with an execution_ts inside the window are
 *     counted. The window keeps the throughput figure meaningful.
 *   - `execution_duration` is the ACTUAL duration in minutes. A NULL / zero
 *     duration means "not recorded" and is excluded from the duration maths
 *     (avg / median / p90 / min / max / total) but still counts as an
 *     execution for throughput and status distribution.
 *   - the unit of the table is the test-case VERSION (tcversion_id), the same
 *     unit the execution rows carry.
 *   - a version is SLOW when its average recorded duration is >= `slow_min`
 *     minutes (default 30, 1..1440).
 *
 * Actions
 *   GET|HEAD ?action=init&tplan_id=N[&tproject_id=P]
 *       -> context (plan / project) + the build and platform filters
 *   GET|HEAD ?action=report&tplan_id=N[&tproject_id=P][&build_id=B]
 *             [&platform_id=PL][&window_days=D][&slow_min=M]
 *       -> summary KPIs + per-version rows + per-build breakdown
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
function edOut($payload, $code = null)
{
    if ($code !== null) {
        http_response_code($code);
    }
    echo json_encode($payload);
    exit;
}

function edFail($httpCode, $code, $message = '')
{
    edOut(array(
        'status' => 'error',
        'code' => $code,
        'message' => $message !== '' ? $message : $code,
    ), $httpCode);
}

/** Strict positive integer reader - "1abc", "1.9", "-3" and "" are all refused. */
function edPositiveInt($raw)
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

function edDecode($value)
{
    return html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function edRound($value, $decimals = 2)
{
    return round((float)$value, $decimals);
}

/** Nearest-rank percentile of an ALREADY sorted numeric list. */
function edPercentile($sorted, $pct)
{
    $n = count($sorted);
    if ($n === 0) {
        return 0.0;
    }
    $rank = (int)ceil(($pct / 100.0) * $n);
    if ($rank < 1) {
        $rank = 1;
    }
    if ($rank > $n) {
        $rank = $n;
    }
    return (float)$sorted[$rank - 1];
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    edFail(405, 'method_not_allowed');
}

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    edFail(401, 'not_authenticated', 'Not authenticated');
}

bffEnforceSession($db);

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    edFail(401, 'not_authenticated', 'User not found');
}

$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if ($action !== 'init' && $action !== 'report') {
    edFail(400, 'invalid_request', 'Unknown action');
}

$tplanId = edPositiveInt($_GET['tplan_id'] ?? null);
$assertedProjectId = edPositiveInt($_GET['tproject_id'] ?? null);
$buildId = edPositiveInt($_GET['build_id'] ?? null);
$platformId = edPositiveInt($_GET['platform_id'] ?? null);

if ($tplanId <= 0) {
    edFail(400, 'invalid_request', 'Missing or invalid tplan_id');
}

// Window (days) and slow threshold (minutes): both are bounded so a crafted
// value can never produce an unbounded scan or a nonsensical KPI.
$windowDays = 30;
if (isset($_GET['window_days'])) {
    $raw = is_array($_GET['window_days']) ? '' : trim((string)$_GET['window_days']);
    if ($raw === '' || !preg_match('/^[0-9]+$/', $raw)) {
        edFail(400, 'invalid_request', 'Invalid window_days');
    }
    $windowDays = intval($raw);
    if ($windowDays < 1 || $windowDays > 365) {
        edFail(400, 'invalid_request', 'window_days out of range');
    }
}

$slowMin = 30;
if (isset($_GET['slow_min'])) {
    $raw = is_array($_GET['slow_min']) ? '' : trim((string)$_GET['slow_min']);
    if ($raw === '' || !preg_match('/^[0-9]+$/', $raw)) {
        edFail(400, 'invalid_request', 'Invalid slow_min');
    }
    $slowMin = intval($raw);
    if ($slowMin < 1 || $slowMin > 1440) {
        edFail(400, 'invalid_request', 'slow_min out of range');
    }
}

// Order of operations (identical to api/flakytests and api/metricstrends):
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
    edFail(404, 'tplan_not_found', 'Test plan not found');
}

$plan = $planRow[0];
$owningProjectId = intval($plan['testproject_id']);
if ($owningProjectId <= 0) {
    edFail(404, 'tplan_not_found', 'Test plan has no owning test project');
}

if (!$user->hasRight($db, 'testplan_metrics', $owningProjectId, $tplanId, true)) {
    tLog('BFF execduration: user ' . intval($userId) .
         ' has no testplan_metrics right on tproject ' . $owningProjectId .
         ' (tplan ' . $tplanId . ')', 'ERROR');
    edFail(403, 'no_right', 'testplan_metrics right required');
}

if ($assertedProjectId > 0 && $assertedProjectId !== $owningProjectId) {
    edFail(404, 'project_mismatch', 'Test plan belongs to another test project');
}

$planName = testplan::getName($db, $tplanId);
$projectName = testproject::getName($db, $owningProjectId);
if (is_null($planName) || trim((string)$planName) === '') {
    edFail(404, 'tplan_not_found', 'Test plan not found');
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
        $builds[] = array('id' => intval($r['id']), 'name' => edDecode($r['name']));
    }
}
if ($buildId > 0) {
    $known = false;
    foreach ($builds as $b) {
        if ($b['id'] === $buildId) { $known = true; break; }
    }
    if (!$known) {
        edFail(400, 'invalid_request', 'build_id does not belong to this test project');
    }
}

$platforms = array();
$rp = $db->get_recordset(
    "SELECT id, name FROM {$tbl['platforms']} WHERE testproject_id = " . intval($owningProjectId) .
    " ORDER BY name");
if (is_array($rp)) {
    foreach ($rp as $r) {
        $platforms[] = array('id' => intval($r['id']), 'name' => edDecode($r['name']));
    }
}
if ($platformId > 0) {
    $known = false;
    foreach ($platforms as $p) {
        if ($p['id'] === $platformId) { $known = true; break; }
    }
    if (!$known) {
        edFail(400, 'invalid_request', 'platform_id does not belong to this test project');
    }
}

// Name map of every build of the project (also the deactivated ones, so an
// execution recorded on a closed build is never rendered as "#<id>").
$buildNames = array();
$rb = $db->get_recordset(
    "SELECT id, name FROM {$tbl['builds']} WHERE testproject_id = " . intval($owningProjectId));
if (is_array($rb)) {
    foreach ($rb as $r) {
        $buildNames[intval($r['id'])] = edDecode($r['name']);
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
    edOut(array(
        'status' => 'ok',
        'code' => 200,
        'context' => $context,
        'filters' => $filters,
        'filter' => array(
            'build_id' => $buildId,
            'platform_id' => $platformId,
            'window_days' => $windowDays,
            'slow_min' => $slowMin,
        ),
    ));
}

// ---------------------------------------------------------------------------
// report: the executions inside the window and the aggregates built from them.
// ---------------------------------------------------------------------------
$whereExec = "e.testplan_id = " . intval($tplanId) .
    " AND e.execution_ts IS NOT NULL" .
    " AND e.execution_ts >= DATE_SUB(NOW(), INTERVAL " . intval($windowDays) . " DAY)";
if ($buildId > 0) {
    $whereExec .= " AND e.build_id = " . intval($buildId);
}
if ($platformId > 0) {
    $whereExec .= " AND e.platform_id = " . intval($platformId);
}

$execRs = $db->get_recordset(
    "SELECT e.tcversion_id, e.build_id, e.status, e.tester_id, " .
    "       e.execution_duration, e.execution_ts " .
    "FROM {$tbl['executions']} e WHERE " . $whereExec .
    " ORDER BY e.execution_ts, e.id");

$statusCount = array('passed' => 0, 'failed' => 0, 'blocked' => 0, 'not_run' => 0);
$durations = array();
$perVersion = array();
$perBuild = array();
$testers = array();
$totalExecutions = 0;
$timedExecutions = 0;
$totalMinutes = 0.0;

if (is_array($execRs)) {
    foreach ($execRs as $r) {
        $totalExecutions++;
        $tcv = intval($r['tcversion_id']);
        $bid = intval($r['build_id']);
        $dur = ($r['execution_duration'] === null) ? null : (float)$r['execution_duration'];
        $hasDur = ($dur !== null && $dur > 0);

        switch ((string)$r['status']) {
            case 'p': $statusCount['passed']++; break;
            case 'f': $statusCount['failed']++; break;
            case 'b': $statusCount['blocked']++; break;
            default:  $statusCount['not_run']++; break;
        }

        if (!isset($perVersion[$tcv])) {
            $perVersion[$tcv] = array(
                'executions' => 0, 'timed' => 0, 'sum' => 0.0,
                'min' => null, 'max' => null, 'last_ts' => '', 'last_status' => 'not_run',
                'testers' => array(), 'builds' => array(),
            );
        }
        $perVersion[$tcv]['executions']++;
        $perVersion[$tcv]['last_ts'] = (string)$r['execution_ts'];
        switch ((string)$r['status']) {
            case 'p': $perVersion[$tcv]['last_status'] = 'passed'; break;
            case 'f': $perVersion[$tcv]['last_status'] = 'failed'; break;
            case 'b': $perVersion[$tcv]['last_status'] = 'blocked'; break;
            default:  $perVersion[$tcv]['last_status'] = 'not_run'; break;
        }
        if ($bid > 0) { $perVersion[$tcv]['builds'][$bid] = true; }
        if (intval($r['tester_id']) > 0) { $perVersion[$tcv]['testers'][intval($r['tester_id'])] = true; }

        if (!isset($perBuild[$bid])) {
            $perBuild[$bid] = array('executions' => 0, 'timed' => 0, 'sum' => 0.0);
        }
        $perBuild[$bid]['executions']++;

        if ($hasDur) {
            $timedExecutions++;
            $totalMinutes += $dur;
            $durations[] = $dur;
            $testers[intval($r['tester_id'])] = true;

            $perVersion[$tcv]['timed']++;
            $perVersion[$tcv]['sum'] += $dur;
            if ($perVersion[$tcv]['min'] === null || $dur < $perVersion[$tcv]['min']) {
                $perVersion[$tcv]['min'] = $dur;
            }
            if ($perVersion[$tcv]['max'] === null || $dur > $perVersion[$tcv]['max']) {
                $perVersion[$tcv]['max'] = $dur;
            }
            $perBuild[$bid]['timed']++;
            $perBuild[$bid]['sum'] += $dur;
        }
    }
}

sort($durations);
$summary = array(
    'window_days' => $windowDays,
    'slow_min' => $slowMin,
    'executions' => $totalExecutions,
    'timed_executions' => $timedExecutions,
    'coverage_pct' => $totalExecutions > 0 ? edRound(100.0 * $timedExecutions / $totalExecutions, 1) : 0.0,
    'total_minutes' => edRound($totalMinutes, 2),
    'avg_minutes' => $timedExecutions > 0 ? edRound($totalMinutes / $timedExecutions, 2) : 0.0,
    'median_minutes' => edRound(edPercentile($durations, 50), 2),
    'p90_minutes' => edRound(edPercentile($durations, 90), 2),
    'min_minutes' => $timedExecutions > 0 ? edRound($durations[0], 2) : 0.0,
    'max_minutes' => $timedExecutions > 0 ? edRound($durations[count($durations) - 1], 2) : 0.0,
    'throughput_per_day' => edRound($totalExecutions / $windowDays, 2),
    'testers' => count(array_filter(array_keys($testers), function ($k) { return $k > 0; })),
    'passed' => $statusCount['passed'],
    'failed' => $statusCount['failed'],
    'blocked' => $statusCount['blocked'],
    'not_run' => $statusCount['not_run'],
    'versions' => count($perVersion),
    'slow_versions' => 0,
);

// ---------------------------------------------------------------------------
// Names + suite path of the executed versions (only the versions in scope).
// ---------------------------------------------------------------------------
$versionIds = array_map('intval', array_keys($perVersion));
$meta = array();
$suitePaths = array();
if (count($versionIds) > 0) {
    $inList = implode(',', $versionIds);
    $nameRs = $db->get_recordset(
        "SELECT tv.id AS tcversion_id, tv.tc_external_id, tv.version, " .
        "       tn.name AS tcase_name, tn.id AS tcase_node_id " .
        "FROM {$tbl['tcversions']} tv " .
        "JOIN {$tbl['nodes_hierarchy']} vn ON vn.id = tv.id AND vn.node_type_id = 4 " .
        "JOIN {$tbl['nodes_hierarchy']} tn ON tn.id = vn.parent_id AND tn.node_type_id = 3 " .
        "WHERE tv.id IN (" . $inList . ")");
    if (is_array($nameRs)) {
        foreach ($nameRs as $r) {
            $meta[intval($r['tcversion_id'])] = array(
                'tcase_name' => edDecode($r['tcase_name']),
                'external_id' => intval($r['tc_external_id']),
                'version' => intval($r['version']),
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
    $pathRs = $db->get_recordset($pathSql);
    if (is_array($pathRs)) {
        foreach ($pathRs as $r) {
            $suitePaths[intval($r['tcase_node'])] = edDecode($r['suite_path']);
        }
    }
}

$prefixRs = $db->get_recordset(
    "SELECT prefix FROM {$tbl['testprojects']} WHERE id = " . intval($owningProjectId));
$prefix = (is_array($prefixRs) && count($prefixRs) > 0) ? (string)$prefixRs[0]['prefix'] : '';

$rows = array();
foreach ($perVersion as $tcv => $v) {
    $m = $meta[$tcv] ?? array('tcase_name' => ('#' . $tcv), 'external_id' => 0,
        'version' => 1, 'tcase_node_id' => 0);
    $avg = $v['timed'] > 0 ? edRound($v['sum'] / $v['timed'], 2) : 0.0;
    $slow = ($v['timed'] > 0 && $avg >= $slowMin);
    if ($slow) {
        $summary['slow_versions']++;
    }
    $buildList = array();
    foreach (array_keys($v['builds']) as $bid) {
        $buildList[] = array('id' => intval($bid), 'name' => $buildNames[$bid] ?? ('#' . $bid));
    }
    $rows[] = array(
        'tcversion_id' => intval($tcv),
        'tcase_name' => $m['tcase_name'],
        'external_id' => $m['external_id'],
        'version' => $m['version'],
        'external_full' => ($prefix !== '' ? $prefix . '-' : '') . $m['external_id'],
        'suite_path' => $m['tcase_node_id'] > 0 ? ($suitePaths[$m['tcase_node_id']] ?? '') : '',
        'executions' => $v['executions'],
        'timed' => $v['timed'],
        'total_minutes' => edRound($v['sum'], 2),
        'avg_minutes' => $avg,
        'min_minutes' => $v['timed'] > 0 ? edRound($v['min'], 2) : 0.0,
        'max_minutes' => $v['timed'] > 0 ? edRound($v['max'], 2) : 0.0,
        'last_ts' => $v['last_ts'],
        'last_status' => $v['last_status'],
        'testers' => count($v['testers']),
        'slow' => $slow,
        'verdict' => $v['timed'] > 0 ? ($slow ? 'slow' : 'normal') : 'untimed',
        'builds' => $buildList,
    );
}

usort($rows, function ($a, $b) {
    if ($a['avg_minutes'] == $b['avg_minutes']) {
        return strcmp($a['tcase_name'], $b['tcase_name']);
    }
    return ($a['avg_minutes'] < $b['avg_minutes']) ? 1 : -1;
});

$buildRows = array();
foreach ($perBuild as $bid => $b) {
    $buildRows[] = array(
        'build_id' => intval($bid),
        'build_name' => $bid > 0 ? ($buildNames[$bid] ?? ('#' . $bid)) : '',
        'executions' => $b['executions'],
        'timed' => $b['timed'],
        'total_minutes' => edRound($b['sum'], 2),
        'avg_minutes' => $b['timed'] > 0 ? edRound($b['sum'] / $b['timed'], 2) : 0.0,
    );
}
usort($buildRows, function ($a, $b) { return $a['build_id'] - $b['build_id']; });

edOut(array(
    'status' => 'ok',
    'code' => 200,
    'context' => $context,
    'filters' => $filters,
    'filter' => array(
        'build_id' => $buildId,
        'platform_id' => $platformId,
        'window_days' => $windowDays,
        'slow_min' => $slowMin,
    ),
    'summary' => $summary,
    'rows' => $rows,
    'builds' => $buildRows,
));
