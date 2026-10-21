<?php
/**
 * Risk Coverage Matrix BFF API
 * URL: /api/riskcoveragematrix/
 * Plain PHP, no framework, no compilation.
 *
 * Backs the modern screen gui/templates/risk/riskCoverageMatrix.html
 * (Refs #1951, parent feature #1066 "Risk-Based Testing planning and coverage
 * reporting").
 *
 * The risk REGISTRY (gui/templates/risk/riskRegister.html + api/risk/index.php,
 * Refs #1930) captures risks with impact / likelihood / severity / priority /
 * mitigation / owner / status and optionally links them to a requirement and a
 * test case, but it is CRUD only: it never says whether the risky area is
 * actually covered by EXECUTED tests. This BFF closes that gap - for every
 * risk of the addressed test project it computes the coverage of the linked
 * test case (and of the linked requirement), derives the RESIDUAL risk left
 * after execution and flags the "high risk / low coverage" worklist that a
 * release decision needs.
 *
 * It is a READ ONLY report: there is no write verb at all.
 *
 * Routes (session based, JSON I/O):
 *   GET|HEAD ?action=init&tproject_id=N[&tplan_id=M]
 *        -> { status, context, domains, stats, verdict, items }
 *   GET|HEAD ?action=report&tproject_id=N[&tplan_id=M]
 *        -> same payload (alias of init, kept for symmetry with the other
 *           report BFFs)
 *   GET|HEAD ?action=projects
 *        -> { status, projects:[{id,name,prefix}] }
 *
 * Rights model (checked on the ADDRESSED test project BEFORE it is resolved,
 * the #1697 lesson: no existence oracle):
 *   - reads require mgt_view_tc OR mgt_modify_tc on the project
 *   - unauthenticated -> 401; unknown action -> 400; wrong verb -> 405;
 *     an unknown project -> 404; an unknown plan -> 404
 *
 * The tc_risks table is created lazily (CREATE TABLE IF NOT EXISTS, shared
 * helper api/_riskschema.php) so a freshly imported database works unchanged.
 */
require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$db = null;
doDBConnect($db);

// Enforce the legacy session inactivity timeout (issue #1614): a tab left open
// past config_get('sessionInactivityTimeout') must not keep reading.
bffEnforceSession($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || $userId <= 0) {
    http_response_code(401);
    echo json_encode(array('status' => 'error', 'code' => 'not_authenticated',
        'message' => 'Not authenticated'));
    exit;
}

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    http_response_code(401);
    echo json_encode(array('status' => 'error', 'code' => 'user_not_found',
        'message' => 'User not found'));
    exit;
}

// Verb gate BEFORE the rights check: the report is read only, so an
// unsupported verb always answers 405 regardless of the caller's rights.
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    http_response_code(405);
    echo json_encode(array('status' => 'error', 'code' => 'wrong_method',
        'message' => 'Method not allowed'));
    exit;
}

/** Read a scalar request parameter (never an array). */
function rcmParam($source, $key, $default = '')
{
    $v = $source[$key] ?? $default;
    if (is_array($v) || is_object($v)) { return $default; }
    return trim((string)$v);
}

/** Strict positive int from the query string, 0 when absent/invalid. */
function rcmInt($source, $key)
{
    $v = $source[$key] ?? 0;
    if (!is_scalar($v)) { return 0; }
    return intval($v);
}

/** Derive the risk level from impact x likelihood (same scale as the registry). */
function rcmLevel($impact, $likelihood)
{
    $score = intval($impact) * intval($likelihood);
    if ($score >= 15) { return 'critical'; }
    if ($score >= 10) { return 'high'; }
    if ($score >= 5) { return 'medium'; }
    return 'low';
}

/** Whether the user may read the risk data of a project. */
function rcmCanView($user, $db, $tpid)
{
    foreach (array('mgt_view_tc', 'mgt_modify_tc') as $r) {
        if ($user->hasRight($db, $r, $tpid) === 'yes') { return true; }
    }
    return false;
}

/** Project display name + prefix, or null when the project does not exist. */
function rcmProjectInfo($db, $tpid)
{
    $tbl = tlObjectWithDB::getDBTables(array('testprojects', 'nodes_hierarchy'));
    $tp = $tbl['testprojects'];
    $nh = $tbl['nodes_hierarchy'];
    $row = $db->fetchFirstRow(
        "SELECT p.prefix AS prefix, nh.name AS name" .
        " FROM {$tp} p JOIN {$nh} nh ON nh.id = p.id" .
        " WHERE p.id = " . intval($tpid) . " AND nh.node_type_id = 1");
    return (is_array($row) && $row) ? $row : null;
}

/** Node name for a nodes_hierarchy id (test case / requirement label). */
function rcmNodeLabel($db, $nodeId)
{
    if (intval($nodeId) <= 0) { return ''; }
    $nh = tlObjectWithDB::getDBTables(array('nodes_hierarchy'))['nodes_hierarchy'];
    $row = $db->fetchFirstRow(
        "SELECT name FROM {$nh} WHERE id = " . intval($nodeId));
    return (is_array($row) && $row) ? (string)$row['name'] : '';
}

/** Test plans of a project (id + name), for the scope selector. */
function rcmPlans($db, $tpid)
{
    $tbl = tlObjectWithDB::getDBTables(array('testplans', 'nodes_hierarchy'));
    $tpl = $tbl['testplans'];
    $nh = $tbl['nodes_hierarchy'];
    $rows = (array)$db->get_recordset(
        "SELECT t.id AS id, nh.name AS name" .
        " FROM {$tpl} t JOIN {$nh} nh ON nh.id = t.id AND nh.node_type_id = 5" .
        " WHERE t.testproject_id = " . intval($tpid) .
        " ORDER BY nh.name ASC, t.id ASC");
    $out = array();
    foreach ((array)$rows as $r) {
        $out[] = array('id' => intval($r['id']), 'name' => (string)$r['name']);
    }
    return $out;
}

/** Version node ids (node_type_id = 4) grouped by their test case node id. */
function rcmVersionsByTcase($db, $tcaseIds)
{
    $versions = array();
    if (empty($tcaseIds)) { return $versions; }
    $nh = tlObjectWithDB::getDBTables(array('nodes_hierarchy'))['nodes_hierarchy'];
    $in = implode(',', array_map('intval', $tcaseIds));
    $rows = (array)$db->get_recordset(
        "SELECT id, parent_id FROM {$nh}" .
        " WHERE node_type_id = 4 AND parent_id IN ({$in})" .
        " ORDER BY id ASC");
    foreach ((array)$rows as $r) {
        $versions[intval($r['parent_id'])][] = intval($r['id']);
    }
    return $versions;
}

/** Distinct test cases covering the given requirement ids (active links only). */
function rcmTcasesForRequirements($db, $reqIds)
{
    $out = array();
    if (empty($reqIds)) { return $out; }
    $tbl = tlObjectWithDB::getDBTables(array('req_coverage'));
    $in = implode(',', array_map('intval', $reqIds));
    $rows = (array)$db->get_recordset(
        "SELECT DISTINCT testcase_id FROM {$tbl['req_coverage']}" .
        " WHERE req_id IN ({$in}) AND is_active = 1" .
        " ORDER BY testcase_id ASC");
    foreach ((array)$rows as $r) {
        $out[] = intval($r['testcase_id']);
    }
    return $out;
}

/**
 * Latest execution per (test case version, platform) inside the scope.
 * Returns array(tcversion_id => array('p'=>n,'f'=>n,'b'=>n,'n'=>n,'total'=>n)).
 */
function rcmLatestExecutions($db, $planIds, $versionIds)
{
    $out = array();
    if (empty($planIds) || empty($versionIds)) { return $out; }
    $tbl = tlObjectWithDB::getDBTables(array('executions'));
    $ex = $tbl['executions'];
    $planIn = implode(',', array_map('intval', $planIds));
    $verIn = implode(',', array_map('intval', $versionIds));
    $rows = (array)$db->get_recordset(
        "SELECT e.tcversion_id AS vid, e.status AS status" .
        " FROM {$ex} e" .
        " INNER JOIN (SELECT tcversion_id, platform_id, MAX(id) AS mid" .
        "   FROM {$ex} WHERE testplan_id IN ({$planIn})" .
        "   GROUP BY tcversion_id, platform_id) le ON le.mid = e.id" .
        " WHERE e.tcversion_id IN ({$verIn})");
    foreach ((array)$rows as $r) {
        $vid = intval($r['vid']);
        $st = strtolower(trim((string)$r['status']));
        if (!isset($out[$vid])) {
            $out[$vid] = array('p' => 0, 'f' => 0, 'b' => 0, 'n' => 0, 'total' => 0);
        }
        if (!isset($out[$vid][$st])) { $out[$vid][$st] = 0; }
        $out[$vid][$st]++;
        $out[$vid]['total']++;
    }
    return $out;
}

/** Distinct defect references linked to the executions of the given versions. */
function rcmDefectsByVersion($db, $planIds, $versionIds)
{
    $out = array();
    if (empty($planIds) || empty($versionIds)) { return $out; }
    $tbl = tlObjectWithDB::getDBTables(array('executions', 'execution_bugs'));
    $ex = $tbl['executions'];
    $eb = $tbl['execution_bugs'];
    $planIn = implode(',', array_map('intval', $planIds));
    $verIn = implode(',', array_map('intval', $versionIds));
    $rows = (array)$db->get_recordset(
        "SELECT e.tcversion_id AS vid, COUNT(DISTINCT eb.bug_id) AS cnt" .
        " FROM {$eb} eb INNER JOIN {$ex} e ON e.id = eb.execution_id" .
        " WHERE e.tcversion_id IN ({$verIn}) AND e.testplan_id IN ({$planIn})" .
        " GROUP BY e.tcversion_id");
    foreach ((array)$rows as $r) {
        $out[intval($r['vid'])] = intval($r['cnt']);
    }
    return $out;
}

/**
 * Metrics of one test case inside the scope: how many versions it has, how
 * many of them were executed (passed / failed / blocked), the COVERAGE
 * percentage (executed versions) and the MITIGATION percentage (versions whose
 * latest result is a pass - a failing test exposes a risk, it does not
 * mitigate it).
 */
function rcmTcaseMetrics($tcId, $versions, $execs, $defects)
{
    $m = array('versions' => 0, 'executed' => 0, 'v_passed' => 0, 'v_failed' => 0,
               'v_blocked' => 0, 'not_run' => 0, 'defects' => 0, 'coverage' => 0,
               'mitigation' => 0);
    if (!isset($versions[$tcId])) { return $m; }
    $vids = $versions[$tcId];
    $m['versions'] = count($vids);
    foreach ($vids as $vid) {
        $s = isset($execs[$vid]) ? $execs[$vid] : null;
        if ($s === null) { continue; }
        $m['not_run'] += $s['n'];
        $state = 'notrun';
        if ($s['f'] > 0) {
            $state = 'failed';
        } elseif ($s['b'] > 0) {
            $state = 'blocked';
        } elseif ($s['p'] > 0) {
            $state = 'passed';
        }
        if ($state !== 'notrun') { $m['executed']++; }
        if ($state === 'passed') { $m['v_passed']++; }
        if ($state === 'failed') { $m['v_failed']++; }
        if ($state === 'blocked') { $m['v_blocked']++; }
        if (isset($defects[$vid])) { $m['defects'] += $defects[$vid]; }
    }
    $m['coverage'] = $m['versions'] > 0
        ? (int)round(($m['executed'] * 100) / $m['versions'])
        : 0;
    $m['mitigation'] = $m['versions'] > 0
        ? (int)round(($m['v_passed'] * 100) / $m['versions'])
        : 0;
    return $m;
}

$action = rcmParam($_GET, 'action');
if ($action === '') { $action = 'init'; }

if (!in_array($action, array('init', 'report', 'projects'), true)) {
    http_response_code(400);
    echo json_encode(array('status' => 'error', 'code' => 'unknown_action',
        'message' => 'Unknown action'));
    exit;
}

// ---- project picker -------------------------------------------------------

if ($action === 'projects') {
    $tbl = tlObjectWithDB::getDBTables(array('testprojects', 'nodes_hierarchy'));
    $tp = $tbl['testprojects'];
    $nh = $tbl['nodes_hierarchy'];
    $rows = (array)$db->get_recordset(
        "SELECT p.id AS id, p.prefix AS prefix, nh.name AS name" .
        " FROM {$tp} p JOIN {$nh} nh ON nh.id = p.id AND nh.node_type_id = 1" .
        " ORDER BY nh.name ASC, p.id ASC");
    $projects = array();
    foreach ((array)$rows as $r) {
        $pid = intval($r['id']);
        if (!rcmCanView($user, $db, $pid)) { continue; }
        $projects[] = array('id' => $pid, 'name' => (string)$r['name'],
                            'prefix' => (string)$r['prefix']);
    }
    echo json_encode(array('status' => 'ok', 'projects' => $projects));
    exit;
}

// ---- addressed project ----------------------------------------------------

$tpid = rcmInt($_GET, 'tproject_id');
if ($tpid <= 0) { $tpid = intval($_SESSION['testprojectID'] ?? 0); }

if ($tpid <= 0) {
    http_response_code(400);
    echo json_encode(array('status' => 'error', 'code' => 'no_testproject',
        'message' => 'No test project selected'));
    exit;
}

// Rights BEFORE the project is resolved - an unentitled caller must not be
// able to tell an existing project from a missing one (#1697).
if (!rcmCanView($user, $db, $tpid)) {
    http_response_code(403);
    echo json_encode(array('status' => 'error', 'code' => 'no_right',
        'message' => 'No permission to view the risk coverage'));
    exit;
}

$info = rcmProjectInfo($db, $tpid);
if ($info === null) {
    http_response_code(404);
    echo json_encode(array('status' => 'error', 'code' => 'tproject_not_found',
        'message' => 'Test project not found'));
    exit;
}

// ---- execution scope ------------------------------------------------------

$tplanId = rcmInt($_GET, 'tplan_id');
$planIds = array();
$tplanName = '';
if ($tplanId > 0) {
    $tbl = tlObjectWithDB::getDBTables(array('testplans'));
    $row = $db->fetchFirstRow(
        "SELECT id FROM {$tbl['testplans']}" .
        " WHERE id = " . intval($tplanId) .
        " AND testproject_id = " . intval($tpid));
    if (!is_array($row) || !$row) {
        http_response_code(404);
        echo json_encode(array('status' => 'error', 'code' => 'tplan_not_found',
            'message' => 'Test plan not found'));
        exit;
    }
    $planIds = array($tplanId);
    $tplanName = rcmNodeLabel($db, $tplanId);
} else {
    $tbl = tlObjectWithDB::getDBTables(array('testplans'));
    $rows = (array)$db->get_recordset(
        "SELECT id FROM {$tbl['testplans']}" .
        " WHERE testproject_id = " . intval($tpid) . " ORDER BY id ASC");
    foreach ((array)$rows as $r) { $planIds[] = intval($r['id']); }
}

// ---- risks ----------------------------------------------------------------

require_once(__DIR__ . '/../_riskschema.php');
$riskTable = tlRiskEnsureSchema($db);

$riskRows = (array)$db->get_recordset(
    "SELECT * FROM {$riskTable} WHERE testproject_id = " . intval($tpid) .
    " ORDER BY (impact * likelihood) DESC, name ASC, id ASC");

$tcaseIds = array();
$reqIds = array();
foreach ((array)$riskRows as $r) {
    $tc = intval($r['tcase_id'] ?? 0);
    $rq = intval($r['requirement_id'] ?? 0);
    if ($tc > 0) { $tcaseIds[$tc] = $tc; }
    if ($rq > 0) { $reqIds[$rq] = $rq; }
}
$tcaseIds = array_values($tcaseIds);
$reqIds = array_values($reqIds);

// Test cases that cover the linked requirements also contribute to coverage.
$reqTcases = rcmTcasesForRequirements($db, $reqIds);
$allTcases = $tcaseIds;
foreach ($reqTcases as $tc) { $allTcases[$tc] = $tc; }
$allTcases = array_values($allTcases);

$versions = rcmVersionsByTcase($db, $allTcases);
$versionIds = array();
foreach ($versions as $vids) {
    foreach ($vids as $vid) { $versionIds[$vid] = $vid; }
}
$versionIds = array_values($versionIds);

$execs = rcmLatestExecutions($db, $planIds, $versionIds);
$defects = rcmDefectsByVersion($db, $planIds, $versionIds);

// Requirement -> covered test cases (a requirement is covered by a test case
// when at least one of the test case versions was executed in the scope).
$reqTcMap = array();
if (!empty($reqIds)) {
    $tbl = tlObjectWithDB::getDBTables(array('req_coverage'));
    $in = implode(',', array_map('intval', $reqIds));
    $rows = (array)$db->get_recordset(
        "SELECT DISTINCT req_id, testcase_id FROM {$tbl['req_coverage']}" .
        " WHERE req_id IN ({$in}) AND is_active = 1" .
        " ORDER BY req_id ASC, testcase_id ASC");
    foreach ((array)$rows as $r) {
        $reqTcMap[intval($r['req_id'])][] = intval($r['testcase_id']);
    }
}

// ---- build the matrix -----------------------------------------------------

$items = array();
$stats = array('total' => 0, 'low' => 0, 'medium' => 0, 'high' => 0,
               'critical' => 0, 'linked' => 0, 'no_link' => 0, 'covered' => 0,
               'partial' => 0, 'uncovered' => 0, 'mitigated' => 0,
               'failed_risks' => 0, 'high_risk_low_coverage' => 0,
               'defects' => 0, 'coverage_sum' => 0, 'mitigation_sum' => 0,
               'coverage_count' => 0, 'residual_total' => 0, 'open_risks' => 0);

foreach ((array)$riskRows as $r) {
    $impact = intval($r['impact']);
    $likelihood = intval($r['likelihood']);
    $score = $impact * $likelihood;
    $level = rcmLevel($impact, $likelihood);
    $status = (string)$r['status'];

    $tcId = intval($r['tcase_id'] ?? 0);
    $rqId = intval($r['requirement_id'] ?? 0);

    $tcMetrics = rcmTcaseMetrics($tcId, $versions, $execs, $defects);
    $tcLabel = $tcId > 0 ? rcmNodeLabel($db, $tcId) : '';

    $reqTcCount = 0;
    $reqCovered = 0;
    $reqMitigated = 0;
    $reqCoverage = 0;
    $reqMitigation = 0;
    $reqFailed = 0;
    if ($rqId > 0 && isset($reqTcMap[$rqId])) {
        $reqTcCount = count($reqTcMap[$rqId]);
        foreach ($reqTcMap[$rqId] as $rtc) {
            $m = rcmTcaseMetrics($rtc, $versions, $execs, $defects);
            if ($m['v_failed'] > 0) { $reqFailed++; }
            if ($m['executed'] > 0) {
                $reqCovered++;
                if ($m['v_failed'] === 0) { $reqMitigated++; }
            }
        }
        $reqCoverage = $reqTcCount > 0
            ? (int)round(($reqCovered * 100) / $reqTcCount) : 0;
        $reqMitigation = $reqTcCount > 0
            ? (int)round(($reqMitigated * 100) / $reqTcCount) : 0;
    }
    $reqLabel = $rqId > 0 ? rcmNodeLabel($db, $rqId) : '';

    // Coverage of the risk: the linked test case wins, the linked requirement
    // is the fallback; a risk without any link has no coverage at all.
    // Mitigation (the share of the risk actually retired by PASSING tests)
    // follows the same rule.
    if ($tcId > 0) {
        $coverage = $tcMetrics['coverage'];
        $mitigationPct = $tcMetrics['mitigation'];
        $coverageSource = 'tcase';
        $hasFailure = $tcMetrics['v_failed'] > 0;
    } elseif ($rqId > 0) {
        $coverage = $reqCoverage;
        $mitigationPct = $reqMitigation;
        $coverageSource = 'requirement';
        $hasFailure = $reqFailed > 0;
    } else {
        $coverage = 0;
        $mitigationPct = 0;
        $coverageSource = 'none';
        $hasFailure = false;
    }

    if ($tcId <= 0 && $rqId <= 0) {
        $state = 'unlinked';
        $mitigationState = 'not_linked';
    } else {
        if ($coverage >= 100) {
            $state = 'covered';
        } elseif ($coverage > 0) {
            $state = 'partial';
        } else {
            $state = 'uncovered';
        }
        if ($hasFailure) {
            $mitigationState = 'test_failed';
        } elseif ($mitigationPct >= 100) {
            $mitigationState = 'tested_ok';
        } elseif ($mitigationPct > 0) {
            $mitigationState = 'partially_tested';
        } else {
            $mitigationState = 'untested';
        }
    }

    // Residual risk: what is left of the risk score after the mitigated part.
    $residual = (int)round($score * (100 - $mitigationPct) / 100);

    $flag = ($level === 'high' || $level === 'critical') && $coverage < 50;

    $stats['total']++;
    if (isset($stats[$level])) { $stats[$level]++; }
    if ($tcId > 0 || $rqId > 0) {
        $stats['linked']++;
        $stats['coverage_sum'] += $coverage;
        $stats['mitigation_sum'] += $mitigationPct;
        $stats['coverage_count']++;
    } else {
        $stats['no_link']++;
    }
    if ($state === 'covered') { $stats['covered']++; }
    if ($state === 'partial') { $stats['partial']++; }
    if ($state === 'uncovered') { $stats['uncovered']++; }
    if ($mitigationState === 'tested_ok') { $stats['mitigated']++; }
    if ($mitigationState === 'test_failed') { $stats['failed_risks']++; }
    if ($flag) { $stats['high_risk_low_coverage']++; }
    $stats['defects'] += $tcMetrics['defects'];
    $stats['residual_total'] += $residual;
    if ($status === 'open') { $stats['open_risks']++; }

    $items[] = array(
        'id' => intval($r['id']),
        'name' => (string)$r['name'],
        'impact' => $impact,
        'likelihood' => $likelihood,
        'severity' => intval($r['severity']),
        'priority' => intval($r['priority']),
        'risk_score' => $score,
        'risk_level' => $level,
        'status' => $status,
        'owner' => (string)$r['owner'],
        'mitigation' => (string)($r['mitigation'] ?? ''),
        'requirement_id' => $rqId,
        'requirement_label' => $reqLabel,
        'requirement_tc_count' => $reqTcCount,
        'requirement_covered' => $reqCovered,
        'requirement_coverage' => $reqCoverage,
        'tcase_id' => $tcId,
        'tcase_label' => $tcLabel,
        'tcase_versions' => $tcMetrics['versions'],
        'tcase_executed' => $tcMetrics['executed'],
        'tcase_passed_versions' => $tcMetrics['v_passed'],
        'tcase_failed_versions' => $tcMetrics['v_failed'],
        'tcase_blocked_versions' => $tcMetrics['v_blocked'],
        'tcase_not_run' => $tcMetrics['not_run'],
        'tcase_coverage' => $tcMetrics['coverage'],
        'defects' => $tcMetrics['defects'],
        'coverage' => $coverage,
        'mitigation_pct' => $mitigationPct,
        'coverage_source' => $coverageSource,
        'coverage_state' => $state,
        'mitigation_state' => $mitigationState,
        'residual_risk' => $residual,
        'flag_high_risk_low_coverage' => $flag,
        'update_ts' => (string)($r['update_ts'] ?? ''),
    );
}

$avgCoverage = $stats['coverage_count'] > 0
    ? (int)round($stats['coverage_sum'] / $stats['coverage_count']) : 0;
$avgMitigation = $stats['coverage_count'] > 0
    ? (int)round($stats['mitigation_sum'] / $stats['coverage_count']) : 0;

$verdict = 'no_data';
if ($stats['total'] > 0) {
    if ($stats['high_risk_low_coverage'] === 0 && $avgMitigation >= 80
            && $stats['failed_risks'] === 0) {
        $verdict = 'ready';
    } elseif ($avgMitigation >= 50) {
        $verdict = 'at_risk';
    } else {
        $verdict = 'not_ready';
    }
}

echo json_encode(array(
    'status' => 'ok',
    'context' => array(
        'tproject_id' => intval($tpid),
        'tproject_name' => (string)$info['name'],
        'tproject_prefix' => (string)$info['prefix'],
        'tplan_id' => $tplanId,
        'tplan_name' => $tplanName,
        'scope' => $tplanId > 0 ? 'plan' : 'project',
        'plans' => rcmPlans($db, $tpid),
        'can_view' => true,
    ),
    'domains' => array(
        'levels' => array('low', 'medium', 'high', 'critical'),
        'states' => array('covered', 'partial', 'uncovered', 'unlinked'),
        'statuses' => array('open', 'mitigated', 'accepted', 'closed'),
    ),
    'stats' => $stats,
    'verdict' => array(
        'code' => $verdict,
        'avg_coverage' => $avgCoverage,
        'avg_mitigation' => $avgMitigation,
        'high_risk_low_coverage' => $stats['high_risk_low_coverage'],
        'failed_risks' => $stats['failed_risks'],
    ),
    'items' => $items,
));
