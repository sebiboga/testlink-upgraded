<?php
/**
 * Test Plan Execution Frequency / Re-run Analysis report - BFF (Refs #1988).
 *
 * Net-new read-only modern screen (gui/templates/results/execFrequency.html).
 * TestLink 1.9.20 / 2.0.1 can show the CURRENT result of every assigned test
 * case (Results by Status), the execution history of ONE test case, the
 * duration of each run and the flakiness of a version's history, but no
 * report answers "how often is each assigned test case re-executed?" - the
 * over-testing / under-testing signal of a test plan.
 *
 * Semantics:
 *   - the universe is the set of `testplan_tcversions` links of the addressed
 *     plan (one unit per assigned tcversion; a platform link with
 *     platform_id = 0 covers every execution platform of that version).
 *   - executions are counted inside the plan and the selected build/platform
 *     scope. A run is one `executions` row.
 *   - reruns = executions - 1 (a version executed once has 0 reruns).
 *   - verdict per unit:
 *       never_run  -> 0 executions
 *       single     -> exactly 1 execution
 *       rerun      -> 2 .. (threshold - 1) executions
 *       high_rerun -> >= threshold executions (threshold 2..100, default 4)
 *   - plan verdict precedence: no_data > under_tested > over_tested > healthy.
 *     under_tested = never-run share >= 20%; over_tested = high-rerun share
 *     >= 20%. When both are true under_tested wins (the more critical risk).
 *
 * Actions
 *   GET|HEAD ?action=init&tplan_id=N[&tproject_id=P][&rerun_threshold=T]
 *       -> context (plan / project) + build and platform filters
 *   GET|HEAD ?action=report&tplan_id=N[&tproject_id=P][&build_id=B]
 *                    [&platform_id=PL][&rerun_threshold=T]
 *       -> summary KPIs + one row per assigned (version, platform) unit,
 *          most re-executed first, plus a per-suite roll-up.
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
function efOut($payload, $code = null)
{
    if ($code !== null) {
        http_response_code($code);
    }
    // JSON_INVALID_UTF8_SUBSTITUTE: legacy rows can carry non-UTF-8 bytes
    // (notes pasted from old trackers) and a bare json_encode() would then
    // return false -> empty 200 body.
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function efFail($httpCode, $code, $message = '')
{
    efOut(array(
        'status' => 'error',
        'code' => $code,
        'message' => $message !== '' ? $message : $code,
    ), $httpCode);
}

/** Strict positive integer reader - "1abc", "1.9", "-3" and "" are all refused. */
function efPositiveInt($raw)
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

function efDecode($value)
{
    return html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function efRound($value, $decimals = 1)
{
    return round((float)$value, $decimals);
}

function efPct($part, $total)
{
    return ($total > 0) ? efRound(100.0 * $part / $total, 1) : 0.0;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    efFail(405, 'method_not_allowed');
}

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    efFail(401, 'not_authenticated', 'Not authenticated');
}

bffEnforceSession($db);

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    efFail(401, 'not_authenticated', 'User not found');
}

$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if ($action !== 'init' && $action !== 'report') {
    efFail(400, 'invalid_request', 'Unknown action');
}

$tplanId = efPositiveInt($_GET['tplan_id'] ?? null);
$assertedProjectId = efPositiveInt($_GET['tproject_id'] ?? null);
$buildId = efPositiveInt($_GET['build_id'] ?? null);
$platformId = efPositiveInt($_GET['platform_id'] ?? null);

$rerunThreshold = efPositiveInt($_GET['rerun_threshold'] ?? null);
if ($rerunThreshold <= 0) {
    $rerunThreshold = 4;
}
if ($rerunThreshold < 2) {
    $rerunThreshold = 2;
}
if ($rerunThreshold > 100) {
    $rerunThreshold = 100;
}

if ($tplanId <= 0) {
    efFail(400, 'invalid_request', 'Missing or invalid tplan_id');
}

// Order of operations (same as api/blockedageing, api/testerworkload):
//   1. resolve the plan -> opaque 404 when it does not exist or has no owner
//   2. check the right on the OWNING project (getAccess = true)
//   3. only then honour the client's tproject_id assertion
$tbl = tlObjectWithDB::getDBTables(array(
    'testplans', 'builds', 'platforms', 'testplan_platforms',
    'testplan_tcversions', 'tcversions', 'nodes_hierarchy', 'executions',
    'testprojects',
));
$planRow = $db->get_recordset(
    "SELECT id, testproject_id FROM {$tbl['testplans']} WHERE id = " . intval($tplanId));

if (!is_array($planRow) || count($planRow) === 0) {
    efFail(404, 'tplan_not_found', 'Test plan not found');
}

$plan = $planRow[0];
$owningProjectId = intval($plan['testproject_id']);
if ($owningProjectId <= 0) {
    efFail(404, 'tplan_not_found', 'Test plan has no owning test project');
}

if (!$user->hasRight($db, 'testplan_metrics', $owningProjectId, $tplanId, true)) {
    tLog('BFF execfrequency: user ' . intval($userId) .
         ' has no testplan_metrics right on tproject ' . $owningProjectId .
         ' (tplan ' . $tplanId . ')', 'AUDIT');
    efFail(403, 'no_right', 'testplan_metrics right required');
}

if ($assertedProjectId > 0 && $assertedProjectId !== $owningProjectId) {
    efFail(404, 'project_mismatch', 'Test plan belongs to another test project');
}

$planName = testplan::getName($db, $tplanId);
$projectName = testproject::getName($db, $owningProjectId);
if (is_null($planName) || trim((string)$planName) === '') {
    efFail(404, 'tplan_not_found', 'Test plan not found');
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
        $builds[] = array('id' => intval($r['id']), 'name' => efDecode($r['name']));
    }
}
if ($buildId > 0) {
    $known = false;
    foreach ($builds as $b) {
        if ($b['id'] === $buildId) { $known = true; break; }
    }
    if (!$known) {
        efFail(400, 'invalid_request', 'build_id does not belong to this test project');
    }
}

// Only the platforms actually linked to this plan: a project-wide list would
// offer a platform the plan never uses, and picking it would label every
// assigned unit "never run" simply because the plan is platform-less.
$platforms = array();
$rp = $db->get_recordset(
    "SELECT p.id, p.name FROM {$tbl['platforms']} p" .
    " JOIN {$tbl['testplan_platforms']} tp ON tp.platform_id = p.id" .
    " WHERE tp.testplan_id = " . intval($tplanId) .
    " ORDER BY p.name");
if (is_array($rp)) {
    foreach ($rp as $r) {
        $platforms[] = array('id' => intval($r['id']), 'name' => efDecode($r['name']));
    }
}
if ($platformId > 0) {
    $known = false;
    foreach ($platforms as $p) {
        if ($p['id'] === $platformId) { $known = true; break; }
    }
    if (!$known) {
        efFail(400, 'invalid_request', 'platform_id is not linked to this test plan');
    }
}

$context = array(
    'tplan_id' => $tplanId,
    'tplan_name' => efDecode((string)$planName),
    'tproject_id' => $owningProjectId,
    'tproject_name' => efDecode((string)$projectName),
);
$filters = array('builds' => $builds, 'platforms' => $platforms);
$filterEcho = array(
    'build_id' => $buildId,
    'platform_id' => $platformId,
    'rerun_threshold' => $rerunThreshold,
);

if ($action === 'init') {
    efOut(array(
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
    // as the sibling plan reports (api/blockedageing, api/failurepareto).
    $versionSql .= " AND (tpt.platform_id = " . intval($platformId) .
                   " OR tpt.platform_id = 0)";
}
$versionRs = $db->get_recordset($versionSql);

$versions = array();
$caseNodes = array();
$units = array();
if (is_array($versionRs)) {
    foreach ($versionRs as $v) {
        $tcv = intval($v['tcversion_id']);
        $lp = intval($v['platform_id']);
        $versions[$tcv] = array(
            'tcase_node_id' => intval($v['tcase_node_id']),
            'tcase_name' => efDecode($v['tcase_name']),
            'version' => intval($v['version']),
            'external_id' => intval($v['tc_external_id']),
        );
        $caseNodes[intval($v['tcase_node_id'])] = true;
        $units[$tcv . '|' . $lp] = array('tcversion_id' => $tcv, 'link_platform' => $lp);
    }
}

// Executions in scope: only versions still linked to the plan (EXISTS).
$execSql =
    "SELECT e.id, e.tcversion_id, e.platform_id, e.build_id, e.tester_id, " .
    "       e.status, e.execution_ts " .
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

$execByTcv = array();
$distinctTesters = array();
$distinctBuilds = array();
if (is_array($execRs)) {
    foreach ($execRs as $e) {
        $tcv = intval($e['tcversion_id']);
        if (!isset($versions[$tcv])) {
            continue;
        }
        $execByTcv[$tcv][] = $e;
        if (intval($e['tester_id']) > 0) {
            $distinctTesters[intval($e['tester_id'])] = true;
        }
        if (intval($e['build_id']) > 0) {
            $distinctBuilds[intval($e['build_id'])] = true;
        }
    }
}

// Suite paths of the owning test cases (recursive walk, depth-guarded, the
// same idiom as api/blockedageing / api/failurepareto).
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
            $suitePaths[intval($r['tcase_node'])] = efDecode($r['suite_path']);
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

// ---------------------------------------------------------------------------
// One row per assigned unit.
// ---------------------------------------------------------------------------
$rows = array();
$summary = array(
    'assigned_links' => 0,
    'executed' => 0,
    'never_run' => 0,
    'never_run_pct' => 0.0,
    'total_executions' => 0,
    'reruns' => 0,
    'avg_per_executed' => 0.0,
    'high_rerun' => 0,
    'high_rerun_pct' => 0.0,
    'distinct_testers' => count($distinctTesters),
    'distinct_builds' => count($distinctBuilds),
);

foreach ($units as $unit) {
    $tcv = $unit['tcversion_id'];
    $linkPlatform = $unit['link_platform'];
    if (!isset($versions[$tcv])) {
        continue;
    }
    $v = $versions[$tcv];

    $runs = array();
    if (isset($execByTcv[$tcv])) {
        foreach ($execByTcv[$tcv] as $e) {
            if ($linkPlatform > 0 && intval($e['platform_id']) !== $linkPlatform) {
                continue;
            }
            $runs[] = $e;
        }
    }

    $execCount = count($runs);
    $reruns = ($execCount > 0) ? ($execCount - 1) : 0;
    $runBuilds = array();
    $runPlatforms = array();
    $runTesters = array();
    $firstTs = '';
    $lastTs = '';
    $lastStatus = '';
    foreach ($runs as $e) {
        if (intval($e['build_id']) > 0) { $runBuilds[intval($e['build_id'])] = true; }
        $ep = intval($e['platform_id']);
        if ($ep > 0) { $runPlatforms[$ep] = true; }
        if (intval($e['tester_id']) > 0) { $runTesters[intval($e['tester_id'])] = true; }
        if ($firstTs === '') { $firstTs = (string)$e['execution_ts']; }
        $lastTs = (string)$e['execution_ts'];
        $lastStatus = (string)$e['status'];
    }

    $daysSpan = 0;
    if ($firstTs !== '' && $lastTs !== '') {
        $t1 = strtotime($firstTs);
        $t2 = strtotime($lastTs);
        if ($t1 !== false && $t2 !== false) {
            $daysSpan = max(0, intval(floor(($t2 - $t1) / 86400)));
        }
    }

    if ($execCount === 0) {
        $verdict = 'never_run';
    } elseif ($execCount === 1) {
        $verdict = 'single';
    } elseif ($execCount < $rerunThreshold) {
        $verdict = 'rerun';
    } else {
        $verdict = 'high_rerun';
    }

    $builderName = '';
    if (count($runBuilds) > 0) {
        $names = array();
        foreach (array_keys($runBuilds) as $bid) {
            $names[] = isset($buildNames[$bid]) ? $buildNames[$bid] : ('#' . $bid);
        }
        sort($names);
        $builderName = implode(', ', $names);
    }

    $summary['assigned_links']++;
    $summary['total_executions'] += $execCount;
    $summary['reruns'] += $reruns;
    if ($execCount > 0) {
        $summary['executed']++;
    } else {
        $summary['never_run']++;
    }
    if ($verdict === 'high_rerun') {
        $summary['high_rerun']++;
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
        'link_platform' => $linkPlatform,
        'platform_name' => ($linkPlatform > 0 && isset($platformNames[$linkPlatform]))
            ? $platformNames[$linkPlatform] : '',
        'exec_count' => $execCount,
        'reruns' => $reruns,
        'build_count' => count($runBuilds),
        'builds' => $builderName,
        'platform_count' => count($runPlatforms),
        'tester_count' => count($runTesters),
        'first_run' => $firstTs,
        'last_run' => $lastTs,
        'days_span' => $daysSpan,
        'last_status' => $lastStatus,
        'verdict' => $verdict,
    );
}

// Most re-executed first - the report exists to surface over-tested versions;
// never-run versions follow at the bottom, alphabetical within equal counts.
usort($rows, function ($a, $b) {
    if ($a['exec_count'] === $b['exec_count']) {
        return strcmp($a['tcase_name'], $b['tcase_name']);
    }
    return ($a['exec_count'] < $b['exec_count']) ? 1 : -1;
});

$summary['never_run_pct'] = efPct($summary['never_run'], $summary['assigned_links']);
$summary['high_rerun_pct'] = efPct($summary['high_rerun'], $summary['assigned_links']);
$summary['avg_per_executed'] = ($summary['executed'] > 0)
    ? efRound($summary['total_executions'] / $summary['executed'], 1) : 0.0;

// Judge the thresholds on the UNROUNDED shares, so 200/1001 (19.98%) is not
// rounded up to 20.0 and misclassified as under/over-tested.
$neverShare = ($summary['assigned_links'] > 0)
    ? (100.0 * $summary['never_run'] / $summary['assigned_links']) : 0.0;
$highShare = ($summary['assigned_links'] > 0)
    ? (100.0 * $summary['high_rerun'] / $summary['assigned_links']) : 0.0;

// Verdict precedence: no_data > under_tested > over_tested > healthy.
if ($summary['assigned_links'] === 0) {
    $verdict = 'no_data';
} elseif ($neverShare >= 20.0) {
    $verdict = 'under_tested';
} elseif ($highShare >= 20.0) {
    $verdict = 'over_tested';
} else {
    $verdict = 'healthy';
}

// Per-suite roll-up (keeps the drill-down useful without a second BFF call).
$suiteAgg = array();
foreach ($rows as $r) {
    $key = ($r['suite_path'] !== '') ? $r['suite_path'] : '-';
    if (!isset($suiteAgg[$key])) {
        $suiteAgg[$key] = array(
            'suite_path' => ($r['suite_path'] !== '' ? $r['suite_path'] : ''),
            'units' => 0,
            'executed' => 0,
            'never_run' => 0,
            'total_executions' => 0,
            'high_rerun' => 0,
            'avg_exec' => 0.0,
        );
    }
    $s = &$suiteAgg[$key];
    $s['units']++;
    $s['total_executions'] += $r['exec_count'];
    if ($r['exec_count'] > 0) { $s['executed']++; } else { $s['never_run']++; }
    if ($r['verdict'] === 'high_rerun') { $s['high_rerun']++; }
    unset($s);
}
$suiteRows = array();
foreach ($suiteAgg as $s) {
    $s['avg_exec'] = ($s['units'] > 0) ? efRound($s['total_executions'] / $s['units'], 1) : 0.0;
    $suiteRows[] = $s;
}
usort($suiteRows, function ($a, $b) {
    if ($a['total_executions'] === $b['total_executions']) {
        return strcmp($a['suite_path'], $b['suite_path']);
    }
    return ($a['total_executions'] < $b['total_executions']) ? 1 : -1;
});

efOut(array(
    'status' => 'ok',
    'code' => 200,
    'context' => $context,
    'filters' => $filters,
    'filter' => $filterEcho,
    'summary' => $summary,
    'verdict' => $verdict,
    'rows' => $rows,
    'suites' => $suiteRows,
));
