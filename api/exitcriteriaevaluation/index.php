<?php
/**
 * Exit Criteria evaluation dashboard - REST BFF API
 * URL: /api/exitcriteriaevaluation/index.php
 * Plain PHP, no framework, no compilation
 *
 * Refs #1953 (enhancement) - the assessment half of the parent feature #1088
 * ("Implement definition of EXIT CRITERIA").
 *
 * WHY THIS EXISTS
 * ---------------
 * The *definitions* half of the exit-criteria feature already shipped as
 * gui/templates/strategy/exitCriteria.html + api/exitcriteria/index.php
 * (Refs #1936). Its table `tc_exit_criteria` deliberately carries a
 * MEASURABLE metric (none | progress | pass_rate | failed | blocked |
 * req_coverage) together with a `target_value` - but nothing in 2.0.1 ever
 * EVALUATED those metrics, so `metric` / `target_value` were write-only
 * columns. This BFF is the missing evaluation engine: it reads a test plan
 * (optionally narrowed to a build and/or a platform), computes the actual
 * value of every measurable criterion and compares it with its target.
 *
 * METRIC -> ACTUAL MAPPING (documented on the screen too)
 * -------------------------------------------------------
 *   progress      executed assignments / assigned assignments   (percent)
 *   pass_rate     passed assignments / executed assignments     (percent)
 *   failed        assignments whose latest result is 'f'       (count)
 *   blocked       assignments whose latest result is 'b'       (count)
 *   req_coverage  fully covered requirements / requirements     (percent)
 *   none          free-text criterion - NEVER auto evaluated    (manual)
 *
 * DIRECTION CONVENTION (the definitions screen stores no direction)
 * ---------------------------------------------------------------
 *   percent metrics (progress, pass_rate, req_coverage) are MINIMUMS:
 *       met when actual >= target
 *   count metrics (failed, blocked) are MAXIMUMS:
 *       met when actual <= target
 *   metric = none -> status "manual" (a human decides).
 *
 * THE UNIT is the test-plan ASSIGNMENT row (tcversion_id, platform_id) of
 * `testplan_tcversions`, so a version run on two platforms is two units; the
 * "latest result" is MAX(executions.id) per (tcversion_id, platform_id) inside
 * the plan on the active build/platform filter - the same last-recorded-wins
 * convention tlTestPlanMetrics and the other report BFFs use.
 *
 * SECURITY
 * --------
 * Session auth + bffSameOriginGuard + bffEnforceSession.
 *   - reads require mgt_view_tc OR mgt_modify_tc on the ADDRESSED project,
 *     checked BEFORE the project is resolved so the endpoint cannot be used as
 *     a project-existence oracle (the #1697 lesson);
 *   - the evaluation additionally requires testplan_metrics on the OWNING
 *     project AND the plan (getAccess = true), the owning project being
 *     resolved from the plan and never taken from a client id;
 *   - the client-asserted tproject_id is only compared AFTER the right check
 *     (404 project_mismatch), so it cannot be used as an oracle either.
 * All ids are strictly validated positive integers, all payloads are escaped
 * by the client with .text(), and every request-derived scalar is is_scalar
 * guarded (no PHP 8 "Array to string" E_WARNING can reach the events table).
 *
 * Contract:
 *   GET|HEAD ?action=projects
 *       -> { status, projects:[{id,name,prefix}] }
 *   GET|HEAD ?action=init[&tproject_id=P]
 *       -> { status, code, context, domains, stats, items }
 *   GET|HEAD ?action=evaluate&tplan_id=N[&tproject_id=P][&build_id=B][&platform_id=PL]
 *       -> { status, code, context, metrics, criteria, levels, summary, verdict }
 *
 *   400 invalid_request | unknown_action | no_testproject
 *   401 not_authenticated / session_expired
 *   403 no_right
 *   404 tproject_not_found / tplan_not_found / project_mismatch
 *   405 method_not_allowed
 *   500 server_error
 *
 * Refs #1953, #1088
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
require_once('testproject.class.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

/**
 * Single JSON exit point. $code is applied ONLY when explicitly given, so a
 * branch that already called http_response_code() is not reset back to 200.
 */
function ecvOut($payload, $code = null)
{
    if ($code !== null) {
        http_response_code($code);
    }
    echo json_encode($payload);
    exit;
}

/** Emit a machine-code error and stop. */
function ecvFail($httpCode, $code, $message = '')
{
    ecvOut(array(
        'status' => 'error',
        'code' => $code,
        'message' => $message !== '' ? $message : $code,
    ), $httpCode);
}

/** Strict positive integer reader - "1abc", "1.9", "-3" and "" are refused. */
function ecvPositiveInt($raw)
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

/** Allowed test levels (same catalogue as the definitions screen). */
function ecvLevels()
{
    return array('unit', 'integration', 'system', 'acceptance', 'regression', 'release');
}

/** Allowed measurable metrics (same catalogue as the definitions screen). */
function ecvMetrics()
{
    return array('none', 'progress', 'pass_rate', 'failed', 'blocked', 'req_coverage');
}

/** Metrics expressed in percent (target within 0..100, direction "gte"). */
function ecvPercentMetrics()
{
    return array('progress', 'pass_rate', 'req_coverage');
}

/** Metrics expressed as a count (direction "lte"). */
function ecvCountMetrics()
{
    return array('failed', 'blocked');
}

/** Table name handling (respects the optional DB_TABLE_PREFIX). */
function ecvCriteriaTable()
{
    $t = tlObjectWithDB::getDBTables(array('tc_exit_criteria'));
    return $t['tc_exit_criteria'];
}

/** Idempotent lazy schema migration so a freshly imported DB works unchanged. */
function ecvEnsureSchema($db)
{
    $t = ecvCriteriaTable();
    $db->exec_query(
        "CREATE TABLE IF NOT EXISTS {$t} (" .
        " id INT UNSIGNED NOT NULL AUTO_INCREMENT," .
        " testproject_id INT UNSIGNED NOT NULL DEFAULT 0," .
        " name VARCHAR(255) NOT NULL DEFAULT ''," .
        " test_level VARCHAR(32) NOT NULL DEFAULT 'system'," .
        " entry_criteria TEXT NULL," .
        " exit_criteria TEXT NULL," .
        " metric VARCHAR(32) NOT NULL DEFAULT 'none'," .
        " target_value DECIMAL(10,2) NULL," .
        " created_by INT UNSIGNED NULL," .
        " creation_ts TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP," .
        " update_ts DATETIME NULL," .
        " PRIMARY KEY (id)," .
        " KEY idx_ec_project (testproject_id)" .
        ") ENGINE=InnoDB DEFAULT CHARSET=utf8");
    return $t;
}

/** Whether the user may read the exit criteria of a project. */
function ecvCanView($user, $db, $tpid)
{
    if (intval($tpid) <= 0) {
        return false;
    }
    foreach (array('mgt_view_tc', 'mgt_modify_tc') as $r) {
        if ($user->hasRight($db, $r, $tpid) === 'yes') {
            return true;
        }
    }
    return false;
}

/** Project display name + prefix, or null when the project does not exist. */
function ecvProjectInfo($db, $tpid)
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

/** Test plans of a project (id + name) for the scope selector. */
function ecvPlans($db, $tpid)
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
    foreach ($rows as $r) {
        $out[] = array(
            'id' => intval($r['id']),
            'name' => html_entity_decode((string)$r['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        );
    }
    return $out;
}

/** The project's exit criteria, ordered by level (legacy order) then name. */
function ecvCriteria($db, $t, $tpid)
{
    $levelOrder = "'" . implode("','", ecvLevels()) . "'";
    $rows = (array)$db->get_recordset(
        "SELECT * FROM {$t} WHERE testproject_id = " . intval($tpid) .
        " ORDER BY FIELD(test_level, {$levelOrder}), name ASC");
    $items = array();
    foreach ($rows as $r) {
        $target = $r['target_value'];
        $items[] = array(
            'id' => intval($r['id']),
            'name' => (string)$r['name'],
            'level' => (string)$r['test_level'],
            'entry_criteria' => (string)($r['entry_criteria'] ?? ''),
            'exit_criteria' => (string)($r['exit_criteria'] ?? ''),
            'metric' => (string)$r['metric'],
            'target_value' => ($target === null || $target === '') ? null : (float)$target,
        );
    }
    return $items;
}

// ---------------------------------------------------------------------------
// Transport
// ---------------------------------------------------------------------------

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    ecvFail(405, 'method_not_allowed', 'Method not allowed');
}

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    ecvFail(401, 'not_authenticated', 'Not authenticated');
}

bffEnforceSession($db);

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    ecvFail(401, 'not_authenticated', 'User not found');
}

$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if (!in_array($action, array('projects', 'init', 'evaluate'), true)) {
    ecvFail(400, 'unknown_action', 'Unknown action');
}

$criteriaTable = ecvEnsureSchema($db);

// ---------------------------------------------------------------------------
// Project picker
// ---------------------------------------------------------------------------

if ($action === 'projects') {
    $tbl = tlObjectWithDB::getDBTables(array('testprojects', 'nodes_hierarchy'));
    $tp = $tbl['testprojects'];
    $nh = $tbl['nodes_hierarchy'];
    $rows = (array)$db->get_recordset(
        "SELECT p.id AS id, p.prefix AS prefix, nh.name AS name" .
        " FROM {$tp} p JOIN {$nh} nh ON nh.id = p.id AND nh.node_type_id = 1" .
        " ORDER BY nh.name ASC, p.id ASC");
    $projects = array();
    foreach ($rows as $r) {
        $pid = intval($r['id']);
        if (!ecvCanView($user, $db, $pid)) {
            continue;
        }
        $projects[] = array(
            'id' => $pid,
            'name' => html_entity_decode((string)$r['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'prefix' => (string)$r['prefix'],
        );
    }
    ecvOut(array('status' => 'ok', 'code' => 'ok', 'projects' => $projects));
}

// ---------------------------------------------------------------------------
// Addressed project (init + evaluate share the resolution rules)
// ---------------------------------------------------------------------------

$assertedProjectId = ecvPositiveInt($_GET['tproject_id'] ?? null);
$tplanId = ecvPositiveInt($_GET['tplan_id'] ?? null);
$buildId = ecvPositiveInt($_GET['build_id'] ?? null);
$platformId = ecvPositiveInt($_GET['platform_id'] ?? null);
$hasPlatform = isset($_GET['platform_id']) && $_GET['platform_id'] !== '';

if ($action === 'init') {
    $tpid = ($assertedProjectId > 0) ? $assertedProjectId : intval($_SESSION['testprojectID'] ?? 0);
    if ($tpid <= 0) {
        ecvFail(400, 'no_testproject', 'No test project selected');
    }
    // Rights BEFORE the project is resolved - an unentitled caller must not be
    // able to tell an existing project from a missing one (#1697).
    if (!ecvCanView($user, $db, $tpid)) {
        tLog('BFF exitcriteriaevaluation: user ' . intval($userId) .
            ' has no view right on tproject ' . intval($tpid), 'AUDIT');
        ecvFail(403, 'no_right', 'No permission to view the exit criteria');
    }
    $info = ecvProjectInfo($db, $tpid);
    if ($info === null) {
        ecvFail(404, 'tproject_not_found', 'Test project not found');
    }
    $items = ecvCriteria($db, $criteriaTable, $tpid);
    $stats = array(
        'total' => count($items),
        'measurable' => 0,
        'manual' => 0,
        'by_level' => array(),
    );
    foreach ($items as $it) {
        $stats['by_level'][$it['level']] = isset($stats['by_level'][$it['level']])
            ? $stats['by_level'][$it['level']] + 1 : 1;
        if ($it['metric'] === 'none') {
            $stats['manual']++;
        } else {
            $stats['measurable']++;
        }
    }
    ecvOut(array(
        'status' => 'ok',
        'code' => 'ok',
        'context' => array(
            'tproject_id' => intval($tpid),
            'tproject_name' => html_entity_decode((string)$info['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'tproject_prefix' => (string)$info['prefix'],
            'plans' => ecvPlans($db, $tpid),
        ),
        'domains' => array(
            'levels' => ecvLevels(),
            'metrics' => ecvMetrics(),
            'percent_metrics' => ecvPercentMetrics(),
            'count_metrics' => ecvCountMetrics(),
        ),
        'stats' => $stats,
        'items' => $items,
    ));
}

// ---------------------------------------------------------------------------
// Evaluation
// ---------------------------------------------------------------------------

if ($tplanId <= 0) {
    ecvFail(400, 'invalid_request', 'Missing or invalid tplan_id');
}

// 1. resolve the plan (opaque 404 when missing) BEFORE any right probe
$tblPlans = tlObjectWithDB::getDBTables(array('testplans'));
$planRow = $db->get_recordset(
    "SELECT id, testproject_id FROM {$tblPlans['testplans']} WHERE id = " . intval($tplanId));
if (!is_array($planRow) || count($planRow) === 0) {
    ecvFail(404, 'tplan_not_found', 'Test plan not found');
}
$owningProjectId = intval($planRow[0]['testproject_id']);
if ($owningProjectId <= 0) {
    ecvFail(404, 'tplan_not_found', 'Test plan has no owning test project');
}

// 2. rights on the OWNING project + plan
if (!ecvCanView($user, $db, $owningProjectId)) {
    tLog('BFF exitcriteriaevaluation: user ' . intval($userId) .
        ' has no view right on tproject ' . $owningProjectId .
        ' (tplan ' . $tplanId . ')', 'AUDIT');
    ecvFail(403, 'no_right', 'No permission to view the exit criteria');
}
if (!$user->hasRight($db, 'testplan_metrics', $owningProjectId, $tplanId, true)) {
    tLog('BFF exitcriteriaevaluation: user ' . intval($userId) .
        ' has no testplan_metrics right on tproject ' . $owningProjectId .
        ' (tplan ' . $tplanId . ')', 'AUDIT');
    ecvFail(403, 'no_right', 'testplan_metrics right required');
}

// 3. only now honour the client assertion
if ($assertedProjectId > 0 && $assertedProjectId !== $owningProjectId) {
    ecvFail(404, 'project_mismatch', 'Test plan belongs to another test project');
}

$planInfo = ecvProjectInfo($db, $owningProjectId);
$projectName = ($planInfo === null) ? '' :
    html_entity_decode((string)$planInfo['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
$projectPrefix = ($planInfo === null) ? '' : (string)$planInfo['prefix'];
$planNameRow = $db->fetchFirstRow(
    "SELECT name FROM " . tlObjectWithDB::getDBTables(array('nodes_hierarchy'))['nodes_hierarchy'] .
    " WHERE id = " . intval($tplanId));
$planName = ($planNameRow === null) ? '' :
    html_entity_decode((string)$planNameRow['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8');

$tables = tlObjectWithDB::getDBTables(array(
    'testplan_tcversions', 'executions', 'builds', 'platforms',
    'requirements', 'req_specs', 'req_coverage', 'nodes_hierarchy',
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
    $bname = html_entity_decode((string)$row['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
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
    $pname = html_entity_decode((string)$row['pname'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $platforms[] = array('id' => $pid, 'name' => $pname);
    if ($pid === $platformId) {
        $platformName = $pname;
    }
}

// Requirements enabled flag lives in the serialized testproject options blob
// (testprojects.option_reqs is a DEAD column on 2.0.1 - see #1833).
$reqEnabled = false;
$tprojMgr = new testproject($db);
$tprojOpts = $tprojMgr->getOptions($owningProjectId);
if (!empty($tprojOpts) && isset($tprojOpts->requirementsEnabled)) {
    $reqEnabled = (bool)$tprojOpts->requirementsEnabled;
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
                  WHERE testplan_id = " . intval($tplanId) . $buildWhere .
                 " GROUP BY tcversion_id, platform_id) last ON last.mid = e.id
          WHERE e.testplan_id = " . intval($tplanId));
    foreach ((array)$rse as $row) {
        $vid = intval($row['vid']);
        $pid = intval($row['pid']);
        $st = strtolower(trim((string)$row['st']));
        if ($vid <= 0) {
            continue;
        }
        $latest[$vid . ':' . $pid] = $st;
    }
}

$total = count($assignments);
$executed = 0;
$passed = 0;
$failed = 0;
$blocked = 0;
foreach ($assignments as $a) {
    $key = $a[0] . ':' . $a[1];
    $st = isset($latest[$key]) ? $latest[$key] : '';
    if ($st === 'p') {
        $passed++;
        $executed++;
    } elseif ($st === 'f') {
        $failed++;
        $executed++;
    } elseif ($st === 'b') {
        $blocked++;
        $executed++;
    }
}
$notRun = $total - $executed;
$progress = ($total > 0) ? round(($executed * 100) / $total, 1) : 0.0;
$passRate = ($executed > 0) ? round(($passed * 100) / $executed, 1) : 0.0;

// ---------------------------------------------------------------------------
// 3. Requirement coverage (only when requirements are enabled).
// ---------------------------------------------------------------------------
$reqStats = array(
    'enabled' => $reqEnabled,
    'total' => 0, 'covered' => 0, 'partial' => 0, 'uncovered' => 0,
    'coverage' => 0.0,
);
if ($reqEnabled) {
    $planTcv = array();
    foreach ($assignments as $a) {
        $planTcv[$a[0]] = true;
    }

    $reqRows = $db->get_recordset(
        'SELECT R.id FROM ' . $tables['requirements'] . ' R
          JOIN ' . $tables['req_specs'] . ' RS ON RS.id = R.srs_id
         WHERE RS.testproject_id = ' . intval($owningProjectId));
    $reqIds = array();
    foreach ((array)$reqRows as $row) {
        $reqIds[] = intval($row['id']);
    }
    $reqStats['total'] = count($reqIds);

    if (count($reqIds) > 0) {
        $reqIdSql = implode(',', array_map('intval', $reqIds));
        $lics = $db->get_recordset(
            'SELECT RC.req_id, RC.tcversion_id
               FROM ' . $tables['req_coverage'] . ' RC
              WHERE RC.is_active = 1 AND RC.req_id IN (' . $reqIdSql . ')');
        $linksByReq = array();
        foreach ((array)$lics as $row) {
            $rid = intval($row['req_id']);
            $vid = intval($row['tcversion_id']);
            if ($vid <= 0) {
                continue;
            }
            if (!isset($linksByReq[$rid])) {
                $linksByReq[$rid] = array();
            }
            $linksByReq[$rid][] = $vid;
        }

        foreach ($reqIds as $rid) {
            $linkedTcv = isset($linksByReq[$rid]) ? $linksByReq[$rid] : array();
            $inPlan = array();
            foreach ($linkedTcv as $vid) {
                if (isset($planTcv[$vid])) {
                    $inPlan[$vid] = true;
                }
            }
            if (count($inPlan) === 0) {
                $reqStats['uncovered']++;
                continue;
            }
            $allPass = true;
            foreach ($inPlan as $vid => $ignored) {
                $found = false;
                foreach ($assignments as $a) {
                    if ($a[0] !== intval($vid)) {
                        continue;
                    }
                    $key = $a[0] . ':' . $a[1];
                    if (isset($latest[$key]) && $latest[$key] === 'p') {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $allPass = false;
                    break;
                }
            }
            if ($allPass) {
                $reqStats['covered']++;
            } else {
                $reqStats['partial']++;
            }
        }
        $reqStats['coverage'] = ($reqStats['total'] > 0)
            ? round(($reqStats['covered'] * 100) / $reqStats['total'], 1) : 0.0;
    }
}

// ---------------------------------------------------------------------------
// 4. Criterion evaluation.
// ---------------------------------------------------------------------------
$criteria = ecvCriteria($db, $criteriaTable, $owningProjectId);

$actualByMetric = array(
    'progress' => $progress,
    'pass_rate' => $passRate,
    'failed' => (float)$failed,
    'blocked' => (float)$blocked,
    'req_coverage' => $reqStats['coverage'],
);

$rows = array();
$summary = array(
    'total' => count($criteria),
    'measurable' => 0, 'met' => 0, 'not_met' => 0,
    'manual' => 0, 'na' => 0,
);
$levels = array();
foreach (ecvLevels() as $lvl) {
    $levels[$lvl] = array(
        'level' => $lvl, 'total' => 0, 'met' => 0, 'not_met' => 0,
        'manual' => 0, 'na' => 0,
    );
}

foreach ($criteria as $it) {
    $metric = $it['metric'];
    $target = $it['target_value'];
    $actual = null;
    $unit = 'count';
    $direction = 'lte';
    $status = 'manual';

    if ($metric !== 'none') {
        $summary['measurable']++;
        if (in_array($metric, ecvPercentMetrics(), true)) {
            $unit = 'percent';
            $direction = 'gte';
        } else {
            $unit = 'count';
            $direction = 'lte';
        }
        if ($metric === 'req_coverage' && !$reqEnabled) {
            $status = 'na';
        } else {
            $actual = $actualByMetric[$metric];
            if ($target === null) {
                // No target recorded: the criterion cannot be judged.
                $status = 'na';
            } else {
                $status = ($direction === 'gte')
                    ? (($actual >= $target) ? 'met' : 'not_met')
                    : (($actual <= $target) ? 'met' : 'not_met');
            }
        }
    } else {
        $summary['manual']++;
    }

    if ($status === 'met') {
        $summary['met']++;
    } elseif ($status === 'not_met') {
        $summary['not_met']++;
    } elseif ($status === 'na') {
        $summary['na']++;
    }

    $lvl = $it['level'];
    if (isset($levels[$lvl])) {
        $levels[$lvl]['total']++;
        if ($status === 'met') {
            $levels[$lvl]['met']++;
        } elseif ($status === 'not_met') {
            $levels[$lvl]['not_met']++;
        } elseif ($status === 'na') {
            $levels[$lvl]['na']++;
        } else {
            $levels[$lvl]['manual']++;
        }
    }

    $rows[] = array(
        'id' => $it['id'],
        'name' => $it['name'],
        'level' => $it['level'],
        'metric' => $metric,
        'target_value' => $target,
        'actual_value' => $actual,
        'unit' => $unit,
        'direction' => $direction,
        'status' => $status,
        'entry_criteria' => $it['entry_criteria'],
        'exit_criteria' => $it['exit_criteria'],
    );
}

$summary['met_pct'] = ($summary['measurable'] > 0)
    ? round(($summary['met'] * 100) / $summary['measurable'], 1) : 0.0;

// ---------------------------------------------------------------------------
// 5. Verdict.
// ---------------------------------------------------------------------------
if ($total === 0) {
    $verdict = 'no_data';       // the plan carries no assignment at all
} elseif ($summary['measurable'] === 0) {
    $verdict = 'manual';        // only free-text criteria - a human decides
} elseif ($summary['not_met'] > 0) {
    $verdict = 'not_ready';
} else {
    $verdict = 'ready';
}

ecvOut(array(
    'status' => 'ok',
    'code' => 'ok',
    'context' => array(
        'tplan_id' => $tplanId,
        'tplan_name' => $planName,
        'tproject_id' => $owningProjectId,
        'tproject_name' => $projectName,
        'tproject_prefix' => $projectPrefix,
        'requirements_enabled' => $reqEnabled,
        'build_id' => $buildId,
        'build_name' => $buildName,
        'platform_id' => $hasPlatform ? $platformId : 0,
        'platform_name' => $platformName,
        'builds' => $builds,
        'platforms' => $platforms,
    ),
    'metrics' => array(
        'total' => $total,
        'executed' => $executed,
        'not_run' => $notRun,
        'passed' => $passed,
        'failed' => $failed,
        'blocked' => $blocked,
        'progress' => $progress,
        'pass_rate' => $passRate,
        'requirements' => $reqStats,
    ),
    'criteria' => $rows,
    'levels' => array_values($levels),
    'summary' => $summary,
    'verdict' => array(
        'verdict' => $verdict,
        'counts' => array(
            'met' => $summary['met'],
            'not_met' => $summary['not_met'],
            'manual' => $summary['manual'],
            'na' => $summary['na'],
        ),
    ),
));
