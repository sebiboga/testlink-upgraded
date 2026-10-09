<?php
/**
 * Release Quality Gates / Go-No-Go dashboard - REST BFF API
 * URL: /api/qualitygate/index.php
 * Plain PHP, no framework, no compilation
 *
 * Refs #1067 (enhancement). There is no 1.9.20 controller behind this screen:
 * the ledger's TODO section is empty and every ASIDE entry already maps to a
 * modern .html + BFF, so (like #1065 RTM and #1845 priorityBarChart) this run
 * builds the next coherent, genuinely-MISSING report - a consolidated release
 * readiness view that aggregates the quality signals TestLink already holds.
 *
 * WHAT IT REPORTS
 * ---------------
 * For one test plan (optionally narrowed to a build and/or a platform) the BFF
 * evaluates a set of threshold rules ("gates") and produces an overall
 * GO / CONDITIONAL GO / NO-GO verdict plus a 0-100 readiness score.
 *
 * Gate set (defaults; every threshold is overridable from the screen):
 *   1. execution progress   executed / assigned >= progress_min (100)
 *   2. pass rate            passed / executed  >= pass_rate_min (95)
 *   3. failed cases         failed             <= max_failed (0)      [critical]
 *   4. blocked cases        blocked            <= max_blocked (0)
 *   5. unaddressed failures failed cases with a linked bug <= max_unaddressed (0) [critical]
 *   6. requirement coverage covered requirements / total >= req_coverage_min (100)
 *                           (only when the test project has requirements enabled)
 *
 * THE UNIT is the test-plan ASSIGNMENT row (tcversion_id, platform_id) of the
 * testplan_tcversions table, so a version run on two platforms is two units.
 * "latest result" is MAX(executions.id) per (tcversion_id, platform_id) inside
 * the plan on the active build/platform filter - the same last-recorded-wins
 * convention tlTestPlanMetrics and the other report BFFs use. A failure is
 * "unaddressed" when none of its executions on the filter carries an
 * execution_bugs row (the evidence gate, computable with no BTS call).
 *
 * SECURITY
 * --------
 * Session auth + bffSameOriginGuard + bffEnforceSession; right testplan_metrics
 * held on the OWNING test project AND the plan with getAccess = true. The
 * owning project is resolved from the plan, never from a client id, and the
 * asserted tproject_id is only compared afterwards so the endpoint cannot be
 * used as a project-existence oracle (the #1697 lesson). All ids are strictly
 * validated positive integers with no string interpolation into SQL.
 *
 * Contract: GET|HEAD ?action=init&tplan_id=N[&tproject_id=P][&build_id=B]
 *                   [&platform_id=PL][&<threshold params>]
 *   200 {status, code, context, metrics, gates, verdict}
 *   400 invalid_request | 401 not_authenticated / session_expired
 *   403 no_right | 404 tplan_not_found / project_mismatch
 *   405 method_not_allowed
 *
 * Refs #1067
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
 * reset back to 200 (the api/reports convention).
 */
function qgOut($payload, $code = null)
{
    if ($code !== null) {
        http_response_code($code);
    }
    echo json_encode($payload);
    exit;
}

function qgFail($httpCode, $code, $message = '')
{
    qgOut(array(
        'status' => 'error',
        'code' => $code,
        'message' => $message !== '' ? $message : $code,
    ), $httpCode);
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    qgFail(405, 'method_not_allowed');
}

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    qgFail(401, 'not_authenticated', 'Not authenticated');
}

bffEnforceSession($db);

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    qgFail(401, 'not_authenticated', 'User not found');
}

$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if ($action !== 'init') {
    qgFail(400, 'invalid_request', 'Unknown action');
}

/** Strict positive integer reader - "1abc", "1.9", "-3" and "" are all refused. */
function qgPositiveInt($raw)
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

/** Clamped numeric threshold reader: missing/array -> $default, clamped to [$min,$max]. */
function qgThreshold($raw, $default, $min, $max)
{
    if ($raw === null || is_array($raw)) {
        return $default;
    }
    $raw = trim((string)$raw);
    if ($raw === '' || !is_numeric($raw)) {
        return $default;
    }
    $v = floatval($raw);
    if ($v < $min) {
        $v = $min;
    }
    if ($v > $max) {
        $v = $max;
    }
    return $v;
}

$tplanId = qgPositiveInt($_GET['tplan_id'] ?? null);
$assertedProjectId = qgPositiveInt($_GET['tproject_id'] ?? null);
$buildId = qgPositiveInt($_GET['build_id'] ?? null);
$platformId = qgPositiveInt($_GET['platform_id'] ?? null);
$hasPlatform = isset($_GET['platform_id']) && $_GET['platform_id'] !== '';

$thresholds = array(
    'progress_min' => qgThreshold($_GET['progress_min'] ?? null, 100, 0, 100),
    'pass_rate_min' => qgThreshold($_GET['pass_rate_min'] ?? null, 95, 0, 100),
    'max_failed' => qgThreshold($_GET['max_failed'] ?? null, 0, 0, 100000),
    'max_blocked' => qgThreshold($_GET['max_blocked'] ?? null, 0, 0, 100000),
    'max_unaddressed' => qgThreshold($_GET['max_unaddressed'] ?? null, 0, 0, 100000),
    'req_coverage_min' => qgThreshold($_GET['req_coverage_min'] ?? null, 100, 0, 100),
);

if ($tplanId <= 0) {
    qgFail(400, 'invalid_request', 'Missing or invalid tplan_id');
}

// 1. resolve the plan (opaque 404 when missing) BEFORE any right probe
$tbl = tlObjectWithDB::getDBTables(array('testplans'));
$planRow = $db->get_recordset(
    "SELECT id, testproject_id FROM {$tbl['testplans']} WHERE id = " . intval($tplanId));
if (!is_array($planRow) || count($planRow) === 0) {
    qgFail(404, 'tplan_not_found', 'Test plan not found');
}
$owningProjectId = intval($planRow[0]['testproject_id']);
if ($owningProjectId <= 0) {
    qgFail(404, 'tplan_not_found', 'Test plan has no owning test project');
}

// 2. right on the OWNING project + plan
if (!$user->hasRight($db, 'testplan_metrics', $owningProjectId, $tplanId, true)) {
    tLog('BFF qualitygate: user ' . intval($userId) .
         ' has no testplan_metrics right on tproject ' . $owningProjectId .
         ' (tplan ' . $tplanId . ')', 'AUDIT');
    qgFail(403, 'no_right', 'testplan_metrics right required');
}

// 3. only now honour the client assertion
if ($assertedProjectId > 0 && $assertedProjectId !== $owningProjectId) {
    qgFail(404, 'project_mismatch', 'Test plan belongs to another test project');
}

$planName = testplan::getName($db, $tplanId);
$projectName = testproject::getName($db, $owningProjectId);
$planName = is_null($planName) ? '' : (string)$planName;
$projectName = is_null($projectName) ? '' : (string)$projectName;

$tables = tlObjectWithDB::getDBTables(array(
    'testplan_tcversions', 'executions', 'execution_bugs', 'builds', 'platforms',
    'requirements', 'req_specs', 'req_versions', 'req_coverage', 'nodes_hierarchy',
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
                  WHERE testplan_id = " . intval($tplanId) . $buildWhere . "
                  GROUP BY tcversion_id, platform_id) last ON last.mid = e.id
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
$failedKeys = array(); // "tcv:plat" of failed assignments
foreach ($assignments as $a) {
    $key = $a[0] . ':' . $a[1];
    $st = isset($latest[$key]) ? $latest[$key] : '';
    if ($st === 'p') {
        $passed++;
        $executed++;
    } elseif ($st === 'f') {
        $failed++;
        $executed++;
        $failedKeys[$key] = true;
    } elseif ($st === 'b') {
        $blocked++;
        $executed++;
    }
}
$notRun = $total - $executed;
$progress = ($total > 0) ? round(($executed * 100) / $total, 1) : 0.0;
$passRate = ($executed > 0) ? round(($passed * 100) / $executed, 1) : 0.0;

// ---------------------------------------------------------------------------
// 3. Evidence gate: failed assignments with NO linked bug on the filter.
// ---------------------------------------------------------------------------
$addressed = array(); // "tcv:plat" with at least one execution_bugs row
if (count($failedKeys) > 0) {
    $platformWhere = ($hasPlatform && $platformId > 0) ? ' AND e.platform_id = ' . intval($platformId) : '';
    $rsbug = $db->get_recordset(
        "SELECT DISTINCT e.tcversion_id AS vid, e.platform_id AS pid
           FROM {$tables['execution_bugs']} eb
           JOIN {$tables['executions']} e ON e.id = eb.execution_id
          WHERE e.testplan_id = " . intval($tplanId) . $buildWhere . $platformWhere);
    foreach ((array)$rsbug as $row) {
        $addressed[intval($row['vid']) . ':' . intval($row['pid'])] = true;
    }
}
$unaddressed = 0;
foreach ($failedKeys as $key => $ignored) {
    if (!isset($addressed[$key])) {
        $unaddressed++;
    }
}

// ---------------------------------------------------------------------------
// 4. Requirement coverage gate (only when requirements are enabled).
// ---------------------------------------------------------------------------
$reqStats = array(
    'enabled' => $reqEnabled,
    'total' => 0, 'covered' => 0, 'partial' => 0, 'uncovered' => 0,
    'coverage' => 0.0,
);
if ($reqEnabled) {
    // plan-assigned tcversion ids (platform rows collapse - requirement centric)
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
            // covered when every plan-linked version has a passing latest result
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
// 5. Gate evaluation.
// ---------------------------------------------------------------------------
$gates = array();

$gates[] = array(
    'id' => 'exec_progress',
    'value' => $progress,
    'target' => $thresholds['progress_min'],
    'unit' => 'percent',
    'direction' => 'gte',
    'status' => ($progress >= $thresholds['progress_min']) ? 'pass' : 'fail',
    'critical' => true,
);
$gates[] = array(
    'id' => 'pass_rate',
    'value' => $passRate,
    'target' => $thresholds['pass_rate_min'],
    'unit' => 'percent',
    'direction' => 'gte',
    'status' => ($passRate >= $thresholds['pass_rate_min']) ? 'pass' : 'fail',
    'critical' => false,
);
$gates[] = array(
    'id' => 'failed_cases',
    'value' => $failed,
    'target' => $thresholds['max_failed'],
    'unit' => 'count',
    'direction' => 'lte',
    'status' => ($failed <= $thresholds['max_failed']) ? 'pass' : 'fail',
    'critical' => true,
);
$gates[] = array(
    'id' => 'blocked_cases',
    'value' => $blocked,
    'target' => $thresholds['max_blocked'],
    'unit' => 'count',
    'direction' => 'lte',
    'status' => ($blocked <= $thresholds['max_blocked']) ? 'pass' : 'fail',
    'critical' => false,
);
$gates[] = array(
    'id' => 'unaddressed_failures',
    'value' => $unaddressed,
    'target' => $thresholds['max_unaddressed'],
    'unit' => 'count',
    'direction' => 'lte',
    'status' => ($unaddressed <= $thresholds['max_unaddressed']) ? 'pass' : 'fail',
    'critical' => true,
);
if ($reqEnabled) {
    $gates[] = array(
        'id' => 'req_coverage',
        'value' => $reqStats['coverage'],
        'target' => $thresholds['req_coverage_min'],
        'unit' => 'percent',
        'direction' => 'gte',
        'status' => ($reqStats['coverage'] >= $thresholds['req_coverage_min']) ? 'pass' : 'fail',
        'critical' => false,
    );
} else {
    $gates[] = array(
        'id' => 'req_coverage',
        'value' => null,
        'target' => $thresholds['req_coverage_min'],
        'unit' => 'percent',
        'direction' => 'gte',
        'status' => 'na',
        'critical' => false,
    );
}

$passCount = 0;
$failCount = 0;
$naCount = 0;
$criticalFail = false;
$scoreSum = 0.0;
$scoreN = 0;
foreach ($gates as $g) {
    if ($g['status'] === 'na') {
        $naCount++;
        continue;
    }
    if ($g['status'] === 'pass') {
        $passCount++;
    } else {
        $failCount++;
        if ($g['critical']) {
            $criticalFail = true;
        }
    }
    $value = is_null($g['value']) ? 0 : floatval($g['value']);
    $target = floatval($g['target']);
    if ($g['direction'] === 'gte') {
        $ratio = ($target > 0) ? min(1.0, $value / $target) : 1.0;
    } else {
        if ($value <= $target) {
            $ratio = 1.0;
        } elseif ($value > 0) {
            $ratio = $target / $value;
        } else {
            $ratio = 0.0;
        }
    }
    $scoreSum += $ratio;
    $scoreN++;
}
$score = ($scoreN > 0) ? intval(round(($scoreSum * 100) / $scoreN)) : 0;

if ($failCount === 0) {
    $decision = 'go';
} elseif ($criticalFail) {
    $decision = 'no_go';
} else {
    $decision = 'conditional';
}

qgOut(array(
    'status' => 'ok',
    'code' => 'ok',
    'context' => array(
        'tplan_id' => $tplanId,
        'tplan_name' => $planName,
        'tproject_id' => $owningProjectId,
        'tproject_name' => $projectName,
        'requirements_enabled' => $reqEnabled,
        'build_id' => $buildId,
        'build_name' => $buildName,
        'platform_id' => $hasPlatform ? $platformId : 0,
        'platform_name' => $platformName,
        'builds' => $builds,
        'platforms' => $platforms,
        'thresholds' => $thresholds,
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
        'unaddressed' => $unaddressed,
        'requirements' => $reqStats,
    ),
    'gates' => $gates,
    'verdict' => array(
        'decision' => $decision,
        'score' => $score,
        'counts' => array('pass' => $passCount, 'fail' => $failCount, 'na' => $naCount),
    ),
));
