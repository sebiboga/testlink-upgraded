<?php
/**
 * Test Plan Execution Ageing report - BFF (Refs #1989).
 *
 * Net-new read-only modern screen (gui/templates/results/executionAgeing.html).
 * TestLink 1.9.20 can show the result of each test case, but it never answers
 * "which test cases assigned to this plan have not been executed for a long
 * time?" - there is no freshness dimension for a plan. This report ages every
 * assigned (tcversion, platform) unit from its LATEST execution in the plan
 * (optionally narrowed to one build / platform), buckets it (fresh / aging /
 * stale / dormant / never run) and rolls the buckets up per test suite.
 *
 * Semantics:
 *   - the universe is the set of `testplan_tcversions` links of the addressed
 *     plan (platform_id = 0 means "any platform" and matches every execution
 *     platform, the same convention as api/blockedageing).
 *   - the CURRENT result of a unit is the LATEST execution (execution_ts, id)
 *     for the same (tcversion, platform) inside the plan and the selected
 *     build/platform scope.
 *   - AGE is measured in whole days from that execution to NOW() on the DB
 *     server clock. A unit without any execution in scope is `never`.
 *   - buckets: fresh <= 7, aging <= 30, stale <= 90, dormant > 90 days.
 *   - rows are ordered by severity (never, dormant, stale, aging, fresh) so
 *     the biggest risks are on top.
 *
 * Actions
 *   GET|HEAD ?action=init&tplan_id=N[&tproject_id=P]
 *       -> context (plan / project) + build and platform filters
 *   GET|HEAD ?action=report&tplan_id=N[&tproject_id=P][&build_id=B][&platform_id=PL]
 *       -> summary KPIs, one row per unit, per-suite roll-up
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

function eaOut($payload, $code = null)
{
    if ($code !== null) {
        http_response_code($code);
    }
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function eaFail($httpCode, $code, $message = '')
{
    eaOut(array(
        'status' => 'error',
        'code' => $code,
        'message' => $message !== '' ? $message : $code,
    ), $httpCode);
}

function eaPositiveInt($raw)
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

function eaDecode($value)
{
    return html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function eaRound($value, $decimals = 1)
{
    return round((float)$value, $decimals);
}

function eaPct($part, $total)
{
    return ($total > 0) ? eaRound(100.0 * $part / $total, 1) : 0.0;
}

function eaBucket($never, $age)
{
    if ($never) {
        return 'never';
    }
    if ($age > 90) {
        return 'dormant';
    }
    if ($age > 30) {
        return 'stale';
    }
    if ($age > 7) {
        return 'aging';
    }
    return 'fresh';
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    eaFail(405, 'method_not_allowed');
}

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    eaFail(401, 'not_authenticated', 'Not authenticated');
}

bffEnforceSession($db);

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    eaFail(401, 'not_authenticated', 'User not found');
}

$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if ($action !== 'init' && $action !== 'report') {
    eaFail(400, 'invalid_request', 'Unknown action');
}

$tplanId = eaPositiveInt($_GET['tplan_id'] ?? null);
$assertedProjectId = eaPositiveInt($_GET['tproject_id'] ?? null);
$buildId = eaPositiveInt($_GET['build_id'] ?? null);
$platformId = eaPositiveInt($_GET['platform_id'] ?? null);

if ($tplanId <= 0) {
    eaFail(400, 'invalid_request', 'Missing or invalid tplan_id');
}

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
    eaFail(404, 'tplan_not_found', 'Test plan not found');
}

$plan = $planRow[0];
$owningProjectId = intval($plan['testproject_id']);
if ($owningProjectId <= 0) {
    eaFail(404, 'tplan_not_found', 'Test plan has no owning test project');
}

if (!$user->hasRight($db, 'testplan_metrics', $owningProjectId, $tplanId, true)) {
    tLog('BFF executionageing: user ' . intval($userId) .
         ' has no testplan_metrics right on tproject ' . $owningProjectId .
         ' (tplan ' . $tplanId . ')', 'ERROR');
    eaFail(403, 'no_right', 'testplan_metrics right required');
}

if ($assertedProjectId > 0 && $assertedProjectId !== $owningProjectId) {
    eaFail(404, 'project_mismatch', 'Test plan belongs to another test project');
}

$planName = testplan::getName($db, $tplanId);
$projectName = testproject::getName($db, $owningProjectId);
if (is_null($planName) || trim((string)$planName) === '') {
    eaFail(404, 'tplan_not_found', 'Test plan not found');
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
        $builds[] = array('id' => intval($r['id']), 'name' => eaDecode($r['name']));
    }
}
if ($buildId > 0) {
    $known = false;
    foreach ($builds as $b) {
        if ($b['id'] === $buildId) { $known = true; break; }
    }
    if (!$known) {
        eaFail(400, 'invalid_request', 'build_id does not belong to this test project');
    }
}

$platforms = array();
$rp = $db->get_recordset(
    "SELECT id, name FROM {$tbl['platforms']} WHERE testproject_id = " . intval($owningProjectId) .
    " ORDER BY name");
if (is_array($rp)) {
    foreach ($rp as $r) {
        $platforms[] = array('id' => intval($r['id']), 'name' => eaDecode($r['name']));
    }
}
if ($platformId > 0) {
    $known = false;
    foreach ($platforms as $p) {
        if ($p['id'] === $platformId) { $known = true; break; }
    }
    if (!$known) {
        eaFail(400, 'invalid_request', 'platform_id does not belong to this test project');
    }
}

$context = array(
    'tplan_id' => $tplanId,
    'tplan_name' => eaDecode((string)$planName),
    'tproject_id' => $owningProjectId,
    'tproject_name' => eaDecode((string)$projectName),
);
$filters = array('builds' => $builds, 'platforms' => $platforms);
$filterEcho = array('build_id' => $buildId, 'platform_id' => $platformId);

if ($action === 'init') {
    eaOut(array(
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
            'tcase_name' => eaDecode($v['tcase_name']),
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

// Latest execution per (tcversion, platform) in scope. EXISTS proves the pair
// is still linked to the plan. The filter narrows the execution scope, so a
// test not run in the selected build becomes `never` for that scope.
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

// Suite paths of the owning test cases (recursive walk, depth-guarded).
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
            $suitePaths[intval($r['tcase_node'])] = eaDecode($r['suite_path']);
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

$testerIds = array();
foreach ($latest as $e) {
    if (intval($e['tester_id']) > 0) {
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
            $display = trim(eaDecode(trim((string)$r['first']) . ' ' . trim((string)$r['last'])));
            if ($display === '') {
                $display = eaDecode($r['login']);
            }
            $testerNames[intval($r['id'])] = $display;
        }
    }
}

// Universe of units, same convention as api/blockedageing.
$units = array();
foreach ($versions as $tcv => $v) {
    $plats = isset($linkPlatforms[$tcv]) ? $linkPlatforms[$tcv] : array();
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

$summary = array(
    'assigned_links' => count($units),
    'executed' => 0,
    'never' => 0,
    'fresh' => 0,
    'aging' => 0,
    'stale' => 0,
    'dormant' => 0,
    'avg_age' => 0.0,
    'max_age' => 0,
    'testers' => 0,
);
$suites = array();
$rows = array();
$ageSum = 0;
$distinctTesters = array();

foreach ($units as $key => $on) {
    list($tcv, $p) = array_map('intval', explode('|', $key));
    $v = $versions[$tcv];
    $e = isset($latest[$tcv . '|' . $p]) ? $latest[$tcv . '|' . $p] : null;
    $suite = isset($suitePaths[$v['tcase_node_id']]) ? $suitePaths[$v['tcase_node_id']] : '';
    if (!isset($suites[$suite])) {
        $suites[$suite] = array(
            'suite_path' => $suite, 'links' => 0, 'never' => 0, 'fresh' => 0,
            'aging' => 0, 'stale' => 0, 'dormant' => 0, 'age_sum' => 0,
            'executed' => 0, 'max_age' => 0,
        );
    }
    $suites[$suite]['links']++;

    $never = ($e === null);
    $age = 0;
    if (!$never) {
        $ts = strtotime((string)$e['execution_ts']);
        if ($ts !== false) {
            $age = max(0, intval(floor(($now - $ts) / 86400)));
        }
        $summary['executed']++;
        $ageSum += $age;
        if ($age > $summary['max_age']) {
            $summary['max_age'] = $age;
        }
        $distinctTesters[intval($e['tester_id'])] = true;
        $suites[$suite]['executed']++;
        $suites[$suite]['age_sum'] += $age;
        if ($age > $suites[$suite]['max_age']) {
            $suites[$suite]['max_age'] = $age;
        }
    }
    $bucket = eaBucket($never, $age);
    $summary[$bucket]++;
    $suites[$suite][$bucket]++;

    $rows[] = array(
        'tcversion_id' => $tcv,
        'tcase_node_id' => $v['tcase_node_id'],
        'tcase_name' => $v['tcase_name'],
        'external_full' => ($v['external_id'] > 0
            ? (($prefix !== '' ? $prefix . '-' : '') . $v['external_id'])
            : ''),
        'suite_path' => $suite,
        'version' => $v['version'],
        'platform_id' => $p,
        'platform_name' => isset($platformNames[$p]) ? $platformNames[$p] : '',
        'build_id' => $never ? 0 : intval($e['build_id']),
        'build_name' => ($never || !isset($buildNames[intval($e['build_id'])]))
            ? '' : $buildNames[intval($e['build_id'])],
        'tester_id' => $never ? 0 : intval($e['tester_id']),
        'tester_name' => ($never || !isset($testerNames[intval($e['tester_id'])]))
            ? '' : $testerNames[intval($e['tester_id'])],
        'last_status' => $never ? '' : (string)$e['status'],
        'execution_ts' => $never ? '' : (string)$e['execution_ts'],
        'age_days' => $never ? null : $age,
        'bucket' => $bucket,
        'never_run' => $never,
    );
}

$severity = array('never' => 0, 'dormant' => 1, 'stale' => 2, 'aging' => 3, 'fresh' => 4);
usort($rows, function ($a, $b) use ($severity) {
    $sa = $severity[$a['bucket']];
    $sb = $severity[$b['bucket']];
    if ($sa !== $sb) {
        return ($sa < $sb) ? -1 : 1;
    }
    $aa = ($a['age_days'] === null) ? 0 : intval($a['age_days']);
    $ab = ($b['age_days'] === null) ? 0 : intval($b['age_days']);
    if ($aa === $ab) {
        return strcmp($a['tcase_name'], $b['tcase_name']);
    }
    return ($aa < $ab) ? 1 : -1;
});

$suiteRows = array();
foreach ($suites as $s) {
    $s['avg_age'] = ($s['executed'] > 0) ? eaRound($s['age_sum'] / $s['executed'], 1) : 0.0;
    $s['stale_pct'] = eaPct($s['stale'] + $s['dormant'], $s['links']);
    unset($s['age_sum']);
    $suiteRows[] = $s;
}
usort($suiteRows, function ($a, $b) {
    $ra = $a['dormant'] + $a['never'];
    $rb = $b['dormant'] + $b['never'];
    if ($ra === $rb) {
        return strcmp($a['suite_path'], $b['suite_path']);
    }
    return ($ra < $rb) ? 1 : -1;
});

$summary['testers'] = count(array_filter(array_keys($distinctTesters), function ($id) {
    return $id > 0;
}));
$summary['avg_age'] = ($summary['executed'] > 0)
    ? eaRound($ageSum / $summary['executed'], 1) : 0.0;
$summary['never_pct'] = eaPct($summary['never'], $summary['assigned_links']);

if ($summary['assigned_links'] === 0) {
    $verdict = 'clear';
} elseif ($summary['never'] > 0 || $summary['dormant'] > 0) {
    $verdict = 'at_risk';
} elseif ($summary['stale'] > 0) {
    $verdict = 'watch';
} else {
    $verdict = 'fresh';
}

eaOut(array(
    'status' => 'ok',
    'code' => 200,
    'context' => $context,
    'filters' => $filters,
    'filter' => $filterEcho,
    'summary' => $summary,
    'verdict' => $verdict,
    'suites' => $suiteRows,
    'rows' => $rows,
));
