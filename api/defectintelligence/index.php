<?php
/**
 * Defect Intelligence dashboard - REST BFF API
 * URL: /api/defectintelligence/index.php
 * Plain PHP, no framework, no compilation
 *
 * Refs #1068 (enhancement). TestLink 1.9.20 captures execution results and can
 * link an execution to an external BTS bug (execution_bugs), but ships NO
 * analytics that turn those links into defect intelligence: which failures are
 * still unaddressed (no bug evidence), how defects concentrate per test suite
 * (defect density), which cases fail repeatedly across builds (recurring
 * failures) and how many distinct defect references a plan carries.
 *
 * The ledger's TODO section is empty (every ASIDE entry already maps to a
 * modern .html + BFF), so - exactly like #1065 (RTM), #1067 (quality gates) and
 * #1062/#1063 (automation/performance reports) - this run builds the next
 * coherent, genuinely-MISSING report instead of a legacy port: a Defect
 * Intelligence dashboard computable entirely from data TestLink already holds
 * (executions + execution_bugs), with no external BTS call required.
 *
 * WHAT IT REPORTS (for one test plan, optionally narrowed to a build/platform)
 *   summary
 *     assigned / executed / passed / failed / blocked / not_run / progress
 *     failed_cases        failed (tcversion,platform) assignments
 *     addressed           failed assignments with >=1 linked bug
 *     unaddressed         failed assignments with NO linked bug (evidence gate)
 *     distinct_defects    distinct execution_bugs.bug_id on the filter
 *     recurring_failures  cases failing on >=2 distinct builds
 *     defect_density      distinct_defects / failed_cases (0..N)
 *   suites[]   top-level suite roster: failed / addressed / unaddressed /
 *              distinct defects, sorted by failed desc then name
 *   failures[] one row per failed (tcversion,platform): external id, name,
 *              version, suite, top-level suite, latest status, defect refs,
 *              builds_failed, addressed flag
 *
 * THE UNIT is the test-plan ASSIGNMENT row (tcversion_id, platform_id) -
 * testplan_tcversions - so a version run on two platforms is two units.
 * "latest result" is MAX(executions.id) per (tcversion_id, platform_id) inside
 * the plan on the build/platform filter (last-recorded-wins, the tlTestPlanMetrics
 * convention). A failure is "unaddressed" when none of its executions on the
 * filter carries an execution_bugs row.
 *
 * SECURITY
 * Session auth + bffSameOriginGuard + bffEnforceSession; right testplan_metrics
 * held on the OWNING test project AND the plan (getAccess = true). The owning
 * project is resolved from the plan, never from a client id, and an asserted
 * tproject_id is only compared afterwards so the endpoint cannot be used as a
 * project-existence oracle (the #1697 lesson). All ids are strictly validated
 * positive integers with no string interpolation into SQL.
 *
 * Contract: GET|HEAD ?action=init&tplan_id=N[&tproject_id=P][&build_id=B][&platform_id=PL]
 *   200 {status, code, context, summary, suites, failures}
 *   400 invalid_request | 401 not_authenticated / session_expired
 *   403 no_right | 404 tplan_not_found / project_mismatch
 *   405 method_not_allowed
 *
 * Refs #1068
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

/**
 * Single JSON exit point. $code is applied ONLY when explicitly given, so a
 * guard/validation branch that already called http_response_code() is not
 * reset back to 200 (the api/qualitygate convention).
 */
function diOut($payload, $code = null)
{
    if ($code !== null) {
        http_response_code($code);
    }
    echo json_encode($payload);
    exit;
}

function diFail($httpCode, $code, $message = '')
{
    diOut(array(
        'status' => 'error',
        'code' => $code,
        'message' => $message !== '' ? $message : $code,
    ), $httpCode);
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    diFail(405, 'method_not_allowed');
}

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    diFail(401, 'not_authenticated', 'Not authenticated');
}

bffEnforceSession($db);

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    diFail(401, 'not_authenticated', 'User not found');
}

$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if ($action !== 'init') {
    diFail(400, 'invalid_request', 'Unknown action');
}

/** Strict positive integer reader - "1abc", "1.9", "-3" and "" are all refused. */
function diPositiveInt($raw)
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

$tplanId = diPositiveInt($_GET['tplan_id'] ?? null);
$assertedProjectId = diPositiveInt($_GET['tproject_id'] ?? null);
$buildId = diPositiveInt($_GET['build_id'] ?? null);
$platformId = diPositiveInt($_GET['platform_id'] ?? null);
$hasPlatform = isset($_GET['platform_id']) && $_GET['platform_id'] !== '';

if ($tplanId <= 0) {
    diFail(400, 'invalid_request', 'Missing or invalid tplan_id');
}

// 1. resolve the plan (opaque 404 when missing) BEFORE any right probe
$tbl = tlObjectWithDB::getDBTables(array('testplans'));
$planRow = $db->get_recordset(
    "SELECT id, testproject_id FROM {$tbl['testplans']} WHERE id = " . intval($tplanId));
if (!is_array($planRow) || count($planRow) === 0) {
    diFail(404, 'tplan_not_found', 'Test plan not found');
}
$owningProjectId = intval($planRow[0]['testproject_id']);
if ($owningProjectId <= 0) {
    diFail(404, 'tplan_not_found', 'Test plan has no owning test project');
}

// 2. right on the OWNING project + plan
if (!$user->hasRight($db, 'testplan_metrics', $owningProjectId, $tplanId, true)) {
    tLog('BFF defectintelligence: user ' . intval($userId) .
         ' has no testplan_metrics right on tproject ' . $owningProjectId .
         ' (tplan ' . $tplanId . ')', 'AUDIT');
    diFail(403, 'no_right', 'testplan_metrics right required');
}

// 3. only now honour the client assertion
if ($assertedProjectId > 0 && $assertedProjectId !== $owningProjectId) {
    diFail(404, 'project_mismatch', 'Test plan belongs to another test project');
}

$planName = testplan::getName($db, $tplanId);
$projectName = testproject::getName($db, $owningProjectId);
$planName = is_null($planName) ? '' : (string)$planName;
$projectName = is_null($projectName) ? '' : (string)$projectName;

$tables = tlObjectWithDB::getDBTables(array(
    'testplan_tcversions', 'executions', 'execution_bugs', 'builds', 'platforms',
    'tcversions', 'nodes_hierarchy',
));

// ---------------------------------------------------------------------------
// Context: builds (project-scoped) and platforms linked to the plan.
// ---------------------------------------------------------------------------
$buildName = '';
$builds = array();
$rsb = $db->get_recordset(
    'SELECT id, name FROM ' . $tables['builds'] .
    ' WHERE testproject_id = ' . intval($owningProjectId) . ' ORDER BY id');
foreach ((array)$rsb as $row) {
    $bid = intval($row['id']);
    $bname = (string)$row['name'];
    $builds[] = array('id' => $bid, 'name' => $bname);
    if ($bid === $buildId) {
        $buildName = $bname;
    }
}

$platformName = '';
$platforms = array();
$rsp = $db->get_recordset(
    'SELECT DISTINCT TPTV.platform_id AS pid, P.name AS pname
       FROM ' . $tables['testplan_tcversions'] . ' TPTV
       JOIN ' . $tables['platforms'] . ' P ON P.id = TPTV.platform_id
      WHERE TPTV.testplan_id = ' . intval($tplanId) . ' AND TPTV.platform_id > 0
      ORDER BY P.name');
foreach ((array)$rsp as $row) {
    $pid = intval($row['pid']);
    $pname = (string)$row['pname'];
    $platforms[] = array('id' => $pid, 'name' => $pname);
    if ($pid === $platformId) {
        $platformName = $pname;
    }
}

// ---------------------------------------------------------------------------
// Node map (id => [name,parent,type]) - used for the test-case name + the
// top-level suite climb. The tree is small relative to executions and is read
// once.
// ---------------------------------------------------------------------------
$nodes = array();
$rsn = $db->get_recordset(
    'SELECT id, name, parent_id, node_type_id FROM ' . $tables['nodes_hierarchy']);
foreach ((array)$rsn as $row) {
    $nodes[intval($row['id'])] = array(
        'name' => (string)$row['name'],
        'parent' => intval($row['parent_id']),
        'type' => intval($row['node_type_id']),
    );
}

/**
 * Climb from a suite node to its TOP-LEVEL suite name (nodes_hierarchy type 2
 * suite whose parent is the project, type 1). Returns '' when the node or the
 * chain is unknown.
 */
function diTopSuite($nodes, $suiteNodeId)
{
    $cur = intval($suiteNodeId);
    $guard = 0;
    $topName = '';
    while (isset($nodes[$cur]) && $guard++ < 100) {
        $node = $nodes[$cur];
        if ($node['type'] === 2) {
            $topName = $node['name'];
        }
        $parent = $node['parent'];
        if ($parent <= 0 || !isset($nodes[$parent]) || $nodes[$parent]['type'] === 1) {
            break;
        }
        $cur = $parent;
    }
    return $topName;
}

// ---------------------------------------------------------------------------
// 1. Assignments on the platform filter (the report universe).
// ---------------------------------------------------------------------------
$assignments = array(); // list of [tcversion_id, platform_id]
$ptcv = $db->get_recordset(
    'SELECT DISTINCT tcversion_id, platform_id FROM ' . $tables['testplan_tcversions'] .
    ' WHERE testplan_id = ' . intval($tplanId) .
    ($hasPlatform && $platformId > 0 ? ' AND platform_id = ' . intval($platformId) : ''));
foreach ((array)$ptcv as $row) {
    $assignments[] = array(intval($row['tcversion_id']), intval($row['platform_id']));
}

// ---------------------------------------------------------------------------
// 2. Latest execution status per (tcversion, platform) on the build filter.
// ---------------------------------------------------------------------------
$buildWhere = $buildId > 0 ? ' AND build_id = ' . intval($buildId) : '';
$latest = array(); // "tcv:plat" => 'p'|'f'|'b'
if (count($assignments) > 0) {
    $rse = $db->get_recordset(
        "SELECT e.tcversion_id AS vid, e.platform_id AS pid, e.status AS st
           FROM {$tables['executions']} e
           JOIN (SELECT tcversion_id, platform_id, MAX(id) AS mid
                   FROM {$tables['executions']}
                  WHERE testplan_id = " . intval($tplanId) . $buildWhere . "
                  GROUP BY tcversion_id, platform_id) last ON last.mid = e.id
          WHERE e.testplan_id = " . intval($tplanId));
    foreach ((array)$rse as $row) {
        $vid = intval($row['vid']);
        $pid = intval($row['pid']);
        if ($vid <= 0) {
            continue;
        }
        $latest[$vid . ':' . $pid] = strtolower(trim((string)$row['st']));
    }
}

$total = count($assignments);
$executed = 0;
$passed = 0;
$failed = 0;
$blocked = 0;
$failedKeys = array(); // "tcv:plat" => [tcv, plat]
foreach ($assignments as $a) {
    $key = $a[0] . ':' . $a[1];
    $st = isset($latest[$key]) ? $latest[$key] : '';
    if ($st === 'p') {
        $passed++;
        $executed++;
    } elseif ($st === 'f') {
        $failed++;
        $executed++;
        $failedKeys[$key] = $a;
    } elseif ($st === 'b') {
        $blocked++;
        $executed++;
    }
}
$notRun = $total - $executed;
$progress = ($total > 0) ? round(($executed * 100) / $total, 1) : 0.0;

// ---------------------------------------------------------------------------
// 3. Defect evidence per failed key on the filter.
// ---------------------------------------------------------------------------
$defectsByKey = array(); // "tcv:plat" => [bug_id => true]
$distinctDefects = array();
if (count($failedKeys) > 0) {
    $platformWhere = ($hasPlatform && $platformId > 0)
        ? ' AND e.platform_id = ' . intval($platformId) : '';
    $rsbug = $db->get_recordset(
        "SELECT DISTINCT e.tcversion_id AS vid, e.platform_id AS pid, eb.bug_id AS bug
           FROM {$tables['execution_bugs']} eb
           JOIN {$tables['executions']} e ON e.id = eb.execution_id
          WHERE e.testplan_id = " . intval($tplanId) . $buildWhere . $platformWhere);
    foreach ((array)$rsbug as $row) {
        $key = intval($row['vid']) . ':' . intval($row['pid']);
        $bug = (string)$row['bug'];
        if ($bug === '' || $bug === '0') {
            continue;
        }
        if (!isset($defectsByKey[$key])) {
            $defectsByKey[$key] = array();
        }
        $defectsByKey[$key][$bug] = true;
        $distinctDefects[$bug] = true;
    }
}

// ---------------------------------------------------------------------------
// 4. Recurring failures: distinct builds where each failed key has status 'f'
// (across ALL builds of the plan, independent of the build filter).
// ---------------------------------------------------------------------------
$buildsFailed = array(); // "tcv:plat" => count(distinct builds)
if (count($failedKeys) > 0) {
    $rsf = $db->get_recordset(
        "SELECT tcversion_id AS vid, platform_id AS pid, COUNT(DISTINCT build_id) AS nbf
           FROM {$tables['executions']}
          WHERE testplan_id = " . intval($tplanId) .
        " AND status = 'f' GROUP BY tcversion_id, platform_id");
    foreach ((array)$rsf as $row) {
        $buildsFailed[intval($row['vid']) . ':' . intval($row['pid'])] = intval($row['nbf']);
    }
}

// ---------------------------------------------------------------------------
// 5. Test-case metadata (name via the testcase node, suite via its parent).
// ---------------------------------------------------------------------------
$tcMeta = array(); // tcversion_id => [external, name, version, suite, topSuite]
if (count($failedKeys) > 0) {
    $vids = array();
    foreach ($failedKeys as $a) {
        $vids[$a[0]] = true;
    }
    $vidSql = implode(',', array_map('intval', array_keys($vids)));
    $rsmeta = $db->get_recordset(
        "SELECT TV.id AS tcversion_id, TV.tc_external_id, TV.version,
                NTC.name AS tc_name, NTC.parent_id AS suite_node
           FROM {$tables['tcversions']} TV
           JOIN {$tables['nodes_hierarchy']} NHV ON NHV.id = TV.id
           JOIN {$tables['nodes_hierarchy']} NTC ON NTC.id = NHV.parent_id
          WHERE TV.id IN (" . $vidSql . ')');
    foreach ((array)$rsmeta as $row) {
        $vid = intval($row['tcversion_id']);
        $suiteNode = intval($row['suite_node']);
        $suiteName = isset($nodes[$suiteNode]) ? $nodes[$suiteNode]['name'] : '';
        $tcMeta[$vid] = array(
            'external' => intval($row['tc_external_id']),
            'name' => (string)$row['tc_name'],
            'version' => intval($row['version']),
            'suite' => $suiteName,
            'topSuite' => diTopSuite($nodes, $suiteNode),
        );
    }
}

// ---------------------------------------------------------------------------
// 6. Build the failure rows + the per-suite aggregation.
// ---------------------------------------------------------------------------
$failures = array();
foreach ($failedKeys as $key => $a) {
    $vid = $a[0];
    $pid = $a[1];
    $refs = isset($defectsByKey[$key]) ? array_keys($defectsByKey[$key]) : array();
    sort($refs, SORT_STRING);
    $meta = isset($tcMeta[$vid]) ? $tcMeta[$vid] : array(
        'external' => 0, 'name' => '', 'version' => 0, 'suite' => '', 'topSuite' => '');
    $failures[] = array(
        'tcversion_id' => $vid,
        'platform_id' => $pid,
        'tc_external_id' => $meta['external'],
        'tc_name' => $meta['name'],
        'tc_version' => $meta['version'],
        'suite_name' => $meta['suite'],
        'top_suite' => $meta['topSuite'],
        'latest_status' => 'f',
        'defect_refs' => $refs,
        'defect_count' => count($refs),
        'builds_failed' => isset($buildsFailed[$key]) ? $buildsFailed[$key] : 0,
        'addressed' => count($refs) > 0,
    );
}

$addressed = 0;
$recurring = 0;
$suites = array(); // topSuite => [failed, addressed, refs=>[]]
foreach ($failures as $f) {
    if ($f['addressed']) {
        $addressed++;
    }
    if ($f['builds_failed'] >= 2) {
        $recurring++;
    }
    $top = $f['top_suite'] !== '' ? $f['top_suite'] : '(none)';
    if (!isset($suites[$top])) {
        $suites[$top] = array('failed' => 0, 'addressed' => 0, 'refs' => array());
    }
    $suites[$top]['failed']++;
    if ($f['addressed']) {
        $suites[$top]['addressed']++;
    }
    foreach ($f['defect_refs'] as $bug) {
        $suites[$top]['refs'][$bug] = true;
    }
}

$suiteRows = array();
foreach ($suites as $name => $s) {
    $refCount = count($s['refs']);
    $suiteRows[] = array(
        'suite_name' => $name,
        'failed' => $s['failed'],
        'addressed' => $s['addressed'],
        'unaddressed' => $s['failed'] - $s['addressed'],
        'defects' => $refCount,
        'density' => ($s['failed'] > 0) ? round($refCount / $s['failed'], 2) : 0.0,
    );
}
usort($suiteRows, function ($x, $y) {
    if ($x['failed'] !== $y['failed']) {
        return $y['failed'] - $x['failed'];
    }
    return strcasecmp($x['suite_name'], $y['suite_name']);
});

$failedCount = count($failures);
$summary = array(
    'assigned' => $total,
    'executed' => $executed,
    'passed' => $passed,
    'failed' => $failed,
    'blocked' => $blocked,
    'not_run' => $notRun,
    'progress' => $progress,
    'failed_cases' => $failedCount,
    'addressed' => $addressed,
    'unaddressed' => $failedCount - $addressed,
    'distinct_defects' => count($distinctDefects),
    'recurring_failures' => $recurring,
    'defect_density' => ($failedCount > 0) ? round(count($distinctDefects) / $failedCount, 2) : 0.0,
);

diOut(array(
    'status' => 'ok',
    'code' => 'ok',
    'context' => array(
        'tproject_id' => $owningProjectId,
        'project_name' => $projectName,
        'tplan_id' => $tplanId,
        'tplan_name' => $planName,
        'build_id' => $buildId,
        'build_name' => $buildName,
        'platform_id' => $hasPlatform ? $platformId : 0,
        'platform_name' => $platformName,
        'builds' => $builds,
        'platforms' => $platforms,
    ),
    'summary' => $summary,
    'suites' => $suiteRows,
    'failures' => $failures,
));
