<?php
/**
 * Test Plan Priority Coverage report - BFF (Refs #1978).
 *
 * Net-new modern screen (gui/templates/results/priorityCoverage.html). The
 * 1.9.20 suite reports execution status per build / suite / tester / keyword
 * (api/prioritybarchart is keyword based despite its name) but never per test
 * case IMPORTANCE. A QA lead therefore cannot answer "is the High priority
 * scope executed and green before shipping" without exporting the plan.
 *
 * Semantics:
 *   - the unit is the test-case VERSION currently linked to the plan
 *     (testplan_tcversions); unassigned versions are ignored.
 *   - the status of a version is the status of its LATEST execution inside the
 *     selected scope (plan, optional build / platform), ordered by
 *     execution_ts then id. No execution = not run.
 *   - importance is the tcversions.importance band: 1 = Low, 2 = Medium,
 *     3 = High (NULL / out of range is normalised to Medium, the TestLink
 *     default).
 *   - coverage_pct   = versions with an execution / assigned versions
 *   - pass_rate_pct  = passed versions / executed versions (p + f + b)
 *   - verdict: `ready` when every non-empty band has coverage >= 80% and
 *     pass rate >= 80%; `at_risk` when a band has coverage < 50% or an
 *     executed band has pass rate < 50%; `watch` otherwise.
 *
 * Actions
 *   GET|HEAD ?action=init&tplan_id=N[&tproject_id=P]
 *       -> context + build / platform filters
 *   GET|HEAD ?action=report&tplan_id=N[&tproject_id=P][&build_id=B]
 *             [&platform_id=PL][&importance=I]
 *       -> summary + per-importance bands + per-version rows
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

function pcDecode($value)
{
    return html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function pcRound($value, $decimals = 1)
{
    return round((float)$value, $decimals);
}

/** Normalise a tcversions.importance value into the 1..3 band. */
function pcBand($raw)
{
    $n = intval($raw);
    return ($n === 1 || $n === 3) ? $n : 2;
}

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
if ($action !== 'init' && $action !== 'report') {
    pcFail(400, 'invalid_request', 'Unknown action');
}

$tplanId = pcPositiveInt($_GET['tplan_id'] ?? null);
$assertedProjectId = pcPositiveInt($_GET['tproject_id'] ?? null);
$buildId = pcPositiveInt($_GET['build_id'] ?? null);
$platformId = pcPositiveInt($_GET['platform_id'] ?? null);

$importanceFilter = 0;
if (isset($_GET['importance'])) {
    $raw = is_array($_GET['importance']) ? '' : trim((string)$_GET['importance']);
    if ($raw === '' || !preg_match('/^[0-9]+$/', $raw)) {
        pcFail(400, 'invalid_request', 'Invalid importance');
    }
    $importanceFilter = intval($raw);
    if ($importanceFilter < 1 || $importanceFilter > 3) {
        pcFail(400, 'invalid_request', 'importance out of range');
    }
}

if ($tplanId <= 0) {
    pcFail(400, 'invalid_request', 'Missing or invalid tplan_id');
}

$tbl = tlObjectWithDB::getDBTables(array(
    'testplans', 'builds', 'platforms', 'testplan_tcversions', 'tcversions',
    'nodes_hierarchy', 'executions', 'testprojects', 'users',
));
$planRow = $db->get_recordset(
    "SELECT id, testproject_id FROM {$tbl['testplans']} WHERE id = " . intval($tplanId));

if (!is_array($planRow) || count($planRow) === 0) {
    pcFail(404, 'tplan_not_found', 'Test plan not found');
}

$plan = $planRow[0];
$owningProjectId = intval($plan['testproject_id']);
if ($owningProjectId <= 0) {
    pcFail(404, 'tplan_not_found', 'Test plan has no owning test project');
}

if (!$user->hasRight($db, 'testplan_metrics', $owningProjectId, $tplanId, true)) {
    tLog('BFF prioritycoverage: user ' . intval($userId) .
         ' has no testplan_metrics right on tproject ' . $owningProjectId .
         ' (tplan ' . $tplanId . ')', 'ERROR');
    pcFail(403, 'no_right', 'testplan_metrics right required');
}

if ($assertedProjectId > 0 && $assertedProjectId !== $owningProjectId) {
    pcFail(404, 'project_mismatch', 'Test plan belongs to another test project');
}

$planName = testplan::getName($db, $tplanId);
$projectName = testproject::getName($db, $owningProjectId);
if (is_null($planName) || trim((string)$planName) === '') {
    pcFail(404, 'tplan_not_found', 'Test plan not found');
}

// Builds / platforms of the OWNING project (filter vocabulary).
$builds = array();
$rs = $db->get_recordset(
    "SELECT id, name FROM {$tbl['builds']} WHERE testproject_id = " . intval($owningProjectId) .
    " AND active = 1 ORDER BY id");
if (is_array($rs)) {
    foreach ($rs as $r) {
        $builds[] = array('id' => intval($r['id']), 'name' => pcDecode($r['name']));
    }
}
if ($buildId > 0) {
    $known = false;
    foreach ($builds as $b) {
        if ($b['id'] === $buildId) { $known = true; break; }
    }
    if (!$known) {
        pcFail(400, 'invalid_request', 'build_id does not belong to this test project');
    }
}

$platforms = array();
$rp = $db->get_recordset(
    "SELECT id, name FROM {$tbl['platforms']} WHERE testproject_id = " . intval($owningProjectId) .
    " ORDER BY name");
if (is_array($rp)) {
    foreach ($rp as $r) {
        $platforms[] = array('id' => intval($r['id']), 'name' => pcDecode($r['name']));
    }
}
if ($platformId > 0) {
    $known = false;
    foreach ($platforms as $p) {
        if ($p['id'] === $platformId) { $known = true; break; }
    }
    if (!$known) {
        pcFail(400, 'invalid_request', 'platform_id does not belong to this test project');
    }
}

// Name maps (also inactive builds/platforms, so an old execution never renders
// as "#<id>").
$buildNames = array();
$rb = $db->get_recordset(
    "SELECT id, name FROM {$tbl['builds']} WHERE testproject_id = " . intval($owningProjectId));
if (is_array($rb)) {
    foreach ($rb as $r) {
        $buildNames[intval($r['id'])] = pcDecode($r['name']);
    }
}
$platformNames = array();
$rp2 = $db->get_recordset(
    "SELECT id, name FROM {$tbl['platforms']} WHERE testproject_id = " . intval($owningProjectId));
if (is_array($rp2)) {
    foreach ($rp2 as $r) {
        $platformNames[intval($r['id'])] = pcDecode($r['name']);
    }
}

$context = array(
    'tplan_id' => $tplanId,
    'tplan_name' => (string)$planName,
    'tproject_id' => $owningProjectId,
    'tproject_name' => (string)$projectName,
);
$filters = array('builds' => $builds, 'platforms' => $platforms);
$filterState = array(
    'build_id' => $buildId,
    'platform_id' => $platformId,
    'importance' => $importanceFilter,
);

if ($action === 'init') {
    pcOut(array(
        'status' => 'ok',
        'code' => 200,
        'context' => $context,
        'filters' => $filters,
        'filter' => $filterState,
    ));
}

// ---------------------------------------------------------------------------
// Assigned versions of the plan (one row per version; a version linked twice
// for two platforms collapses to one row, which is what the report measures).
// ---------------------------------------------------------------------------
$versions = array();
$assignedRs = $db->get_recordset(
    "SELECT tpt.tcversion_id, tv.importance, tv.version, tv.tc_external_id, " .
    "       tn.name AS tcase_name, tn.id AS tcase_node_id " .
    "FROM {$tbl['testplan_tcversions']} tpt " .
    "JOIN {$tbl['tcversions']} tv ON tv.id = tpt.tcversion_id " .
    "JOIN {$tbl['nodes_hierarchy']} vn ON vn.id = tv.id AND vn.node_type_id = 4 " .
    "JOIN {$tbl['nodes_hierarchy']} tn ON tn.id = vn.parent_id AND tn.node_type_id = 3 " .
    "WHERE tpt.testplan_id = " . intval($tplanId) .
    " ORDER BY tpt.tcversion_id");
if (is_array($assignedRs)) {
    foreach ($assignedRs as $r) {
        $tcv = intval($r['tcversion_id']);
        if (isset($versions[$tcv])) {
            continue;
        }
        $band = pcBand($r['importance']);
        if ($importanceFilter > 0 && $band !== $importanceFilter) {
            continue;
        }
        $versions[$tcv] = array(
            'tcversion_id' => $tcv,
            'importance' => $band,
            'version' => intval($r['version']),
            'external_id' => intval($r['tc_external_id']),
            'tcase_name' => pcDecode($r['tcase_name']),
            'tcase_node_id' => intval($r['tcase_node_id']),
            'status' => 'not_run',
            'execution_ts' => '',
            'tester' => '',
            'build_id' => 0,
            'platform_id' => 0,
        );
    }
}

// Latest execution per version inside the scope.
$latest = array();
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
    $execRs = $db->get_recordset(
        "SELECT e.tcversion_id, e.status, e.execution_ts, e.build_id, e.platform_id, " .
        "       u.login AS tester_login " .
        "FROM {$tbl['executions']} e " .
        "LEFT JOIN {$tbl['users']} u ON u.id = e.tester_id " .
        "WHERE " . $whereExec .
        " ORDER BY e.execution_ts, e.id");
    if (is_array($execRs)) {
        foreach ($execRs as $r) {
            $tcv = intval($r['tcversion_id']);
            $status = (string)$r['status'];
            if ($status !== 'p' && $status !== 'f' && $status !== 'b') {
                $status = 'n';
            }
            $latest[$tcv] = array(
                'status' => $status,
                'execution_ts' => (string)$r['execution_ts'],
                'build_id' => intval($r['build_id']),
                'platform_id' => intval($r['platform_id']),
                'tester' => (string)($r['tester_login'] ?? ''),
            );
        }
    }
}

// Suite path of every version in scope.
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
    $pathRs = $db->get_recordset($pathSql);
    if (is_array($pathRs)) {
        foreach ($pathRs as $r) {
            $suitePaths[intval($r['tcase_node'])] = pcDecode($r['suite_path']);
        }
    }
}

$prefixRs = $db->get_recordset(
    "SELECT prefix FROM {$tbl['testprojects']} WHERE id = " . intval($owningProjectId));
$prefix = (is_array($prefixRs) && count($prefixRs) > 0) ? (string)$prefixRs[0]['prefix'] : '';

// Aggregate per importance band. Always emit the three bands so the screen can
// render an empty band instead of hiding it.
$bandMeta = array(
    3 => array('label' => 'high'),
    2 => array('label' => 'medium'),
    1 => array('label' => 'low'),
);
$bands = array();
foreach ($bandMeta as $band => $meta) {
    $bands[$band] = array(
        'importance' => $band,
        'label' => $meta['label'],
        'total' => 0, 'executed' => 0, 'passed' => 0, 'failed' => 0,
        'blocked' => 0, 'not_run' => 0,
        'coverage_pct' => 0.0, 'pass_rate_pct' => 0.0,
        'at_risk' => false,
    );
}

$rows = array();
$totals = array(
    'total' => 0, 'executed' => 0, 'passed' => 0, 'failed' => 0,
    'blocked' => 0, 'not_run' => 0, 'untested_high' => 0,
);
foreach ($versions as $tcv => $v) {
    $band = $v['importance'];
    $ex = $latest[$tcv] ?? null;
    $status = ($ex === null) ? 'not_run' : $ex['status'];
    $executed = ($status === 'p' || $status === 'f' || $status === 'b');
    if (!$executed) {
        $status = 'not_run';
    }

    $bands[$band]['total']++;
    $totals['total']++;
    if ($executed) {
        $bands[$band]['executed']++;
        $totals['executed']++;
    } else {
        $bands[$band]['not_run']++;
        $totals['not_run']++;
        if ($band === 3) { $totals['untested_high']++; }
    }
    if ($status === 'p') { $bands[$band]['passed']++; $totals['passed']++; }
    else if ($status === 'f') { $bands[$band]['failed']++; $totals['failed']++; }
    else if ($status === 'b') { $bands[$band]['blocked']++; $totals['blocked']++; }

    $rows[] = array(
        'tcversion_id' => intval($tcv),
        'tcase_name' => $v['tcase_name'],
        'external_id' => $v['external_id'],
        'external_full' => ($v['external_id'] > 0
            ? (($prefix !== '' ? $prefix . '-' : '') . $v['external_id'])
            : ''),
        'suite_path' => $suitePaths[$v['tcase_node_id']] ?? '',
        'version' => $v['version'],
        'importance' => $band,
        'importance_label' => $bandMeta[$band]['label'],
        'status' => $status,
        'executed' => $executed,
        'last_ts' => ($ex !== null) ? $ex['execution_ts'] : '',
        'tester' => ($ex !== null) ? $ex['tester'] : '',
        'build_id' => ($ex !== null) ? $ex['build_id'] : 0,
        'build_name' => ($ex !== null && $ex['build_id'] > 0)
            ? ($buildNames[$ex['build_id']] ?? ('#' . $ex['build_id'])) : '',
        'platform_id' => ($ex !== null) ? $ex['platform_id'] : 0,
        'platform_name' => ($ex !== null && $ex['platform_id'] > 0)
            ? ($platformNames[$ex['platform_id']] ?? ('#' . $ex['platform_id'])) : '',
    );
}

// Finalise band percentages / risk.
$atRiskBands = 0;
$allReady = true;
foreach ($bands as $band => $b) {
    if ($b['total'] <= 0) {
        $bands[$band]['coverage_pct'] = 0.0;
        $bands[$band]['pass_rate_pct'] = 0.0;
        continue;
    }
    $bands[$band]['coverage_pct'] = pcRound(100.0 * $b['executed'] / $b['total'], 1);
    $bands[$band]['pass_rate_pct'] = ($b['executed'] > 0)
        ? pcRound(100.0 * $b['passed'] / $b['executed'], 1) : 0.0;
    $risk = ($bands[$band]['coverage_pct'] < 80.0)
        || ($b['executed'] > 0 && $bands[$band]['pass_rate_pct'] < 80.0);
    $bands[$band]['at_risk'] = $risk;
    if ($risk) { $atRiskBands++; }
    if ($bands[$band]['coverage_pct'] < 80.0
        || ($b['executed'] > 0 && $bands[$band]['pass_rate_pct'] < 80.0)) {
        $allReady = false;
    }
}

$high = $bands[3];
$verdict = 'watch';
if ($totals['total'] === 0) {
    $verdict = 'empty';
} else if ($high['total'] > 0 && ($high['coverage_pct'] < 50.0
           || ($high['executed'] > 0 && $high['pass_rate_pct'] < 50.0))) {
    $verdict = 'at_risk';
} else if ($high['total'] > 0 && $allReady) {
    $verdict = 'ready';
}

$summary = array(
    'total' => $totals['total'],
    'executed' => $totals['executed'],
    'passed' => $totals['passed'],
    'failed' => $totals['failed'],
    'blocked' => $totals['blocked'],
    'not_run' => $totals['not_run'],
    'coverage_pct' => $totals['total'] > 0
        ? pcRound(100.0 * $totals['executed'] / $totals['total'], 1) : 0.0,
    'pass_rate_pct' => $totals['executed'] > 0
        ? pcRound(100.0 * $totals['passed'] / $totals['executed'], 1) : 0.0,
    'high_total' => $high['total'],
    'high_executed' => $high['executed'],
    'high_not_run' => $high['not_run'],
    'high_coverage_pct' => $high['coverage_pct'],
    'high_pass_rate_pct' => $high['pass_rate_pct'],
    'untested_high' => $totals['untested_high'],
    'at_risk_bands' => $atRiskBands,
    'bands' => 3,
    'verdict' => $verdict,
);

usort($rows, function ($a, $b) {
    if ($a['importance'] !== $b['importance']) {
        return $b['importance'] - $a['importance'];
    }
    $c = strcmp($a['suite_path'], $b['suite_path']);
    if ($c !== 0) { return $c; }
    return strcmp($a['tcase_name'], $b['tcase_name']);
});

$bandRows = array();
foreach (array(3, 2, 1) as $band) {
    $bandRows[] = $bands[$band];
}

pcOut(array(
    'status' => 'ok',
    'code' => 200,
    'context' => $context,
    'filters' => $filters,
    'filter' => $filterState,
    'summary' => $summary,
    'bands' => $bandRows,
    'rows' => $rows,
));
