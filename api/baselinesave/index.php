<?php
/**
 * Baseline snapshot creation BFF
 * URL: /api/baselinesave/
 * Plain PHP, no framework, no compilation.
 *
 * Backs the modern screen gui/templates/results/baselineSave.html (Refs #1964,
 * closes the documented gap #1189).
 *
 * 1.9.20 could save a baseline snapshot only as a side effect of the "Results
 * by Test Suite" report (lib/results/resultsByTSuite.php doAction=saveForBaseline,
 * reached by a GET link), and the modern result of the pair
 * (gui/templates/results/baselineL1L2.html) is READ ONLY: no modern UI could
 * create a snapshot at all.
 *
 * This BFF owns the write. It uses the very same metrics call as the legacy
 * controller (tlTestPlanMetrics::getStatusTotalsTSuiteDepth2ForRender() +
 * getExecTimeSpan()), so the stored rows stay byte-for-byte compatible with
 * baseline_l1l2_context / baseline_l1l2_details and every existing report
 * keeps reading them unchanged.
 *
 * Routes (session based, JSON I/O):
 *   GET|HEAD ?action=init&tproject_id=N[&tplan_id=M]
 *        -> { status, context, plans, plan, platforms, snapshots, rights }
 *   GET|HEAD ?action=snapshots&tproject_id=N[&tplan_id=M]
 *        -> { status, snapshots }
 *   POST ?action=save&tproject_id=N&tplan_id=M
 *        -> { status, saved:[{platform_id,context_id,details}], message }
 *
 * Rights model (checked on the ADDRESSED test project BEFORE it is resolved,
 * the #1697 lesson: no existence oracle):
 *   - read + save require 'testplan_metrics' on the project (legacy parity:
 *     initArgsForReports() gated savForBaseline on the same right)
 *   - unauthenticated -> 401; unknown action -> 400; wrong verb -> 405;
 *     unknown project/plan -> 404; nothing executable -> 409
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

/** Read a scalar request parameter (never an array). */
function bsvParam($source, $key, $default = '')
{
    $v = $source[$key] ?? $default;
    if (is_array($v) || is_object($v)) { return $default; }
    return trim((string)$v);
}

/** Strict positive int from a request source, 0 when absent/invalid. */
function bsvInt($source, $key)
{
    $v = $source[$key] ?? 0;
    if (!is_scalar($v)) { return 0; }
    return intval($v);
}

/** True only for a unique-key violation (e.g. same-second save); other DB
 *  errors must not be reported as a duplicate. */
function bsvIsDuplicateError(Throwable $e)
{
    if (intval($e->getCode()) === 1062) { return true; }
    $msg = strtolower($e->getMessage());
    return strpos($msg, 'duplicate entry') !== false
        || strpos($msg, 'duplicate key') !== false
        || strpos($msg, 'unique constraint') !== false;
}

function bsvOut($payload, $code = 200)
{
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

function bsvCanView($user, $db, $tpid)
{
    return $user->hasRight($db, 'testplan_metrics', $tpid) === 'yes';
}

/** Project display name + prefix, or null when the project does not exist. */
function bsvProjectInfo($db, $tpid)
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

/** Test plans of a project (id + name), for the scope selector. */
function bsvPlans($db, $tpid)
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

/** A plan of the addressed project, or null. */
function bsvPlanInfo($db, $tpid, $tplanId)
{
    $tbl = tlObjectWithDB::getDBTables(array('testplans', 'nodes_hierarchy'));
    $tpl = $tbl['testplans'];
    $nh = $tbl['nodes_hierarchy'];
    $row = $db->fetchFirstRow(
        "SELECT t.id AS id, nh.name AS name, t.testproject_id AS testproject_id" .
        " FROM {$tpl} t JOIN {$nh} nh ON nh.id = t.id AND nh.node_type_id = 5" .
        " WHERE t.id = " . intval($tplanId) .
        " AND t.testproject_id = " . intval($tpid));
    return (is_array($row) && $row) ? $row : null;
}

/** Existing snapshots of a plan (newest first), with their detail-row count. */
function bsvSnapshots($db, $tplanId)
{
    $tbl = tlObjectWithDB::getDBTables(array(
        'baseline_l1l2_context', 'baseline_l1l2_details', 'platforms'));
    $ctx = $tbl['baseline_l1l2_context'];
    $det = $tbl['baseline_l1l2_details'];
    $plat = $tbl['platforms'];
    $rows = (array)$db->get_recordset(
        "SELECT c.id AS id, c.platform_id AS platform_id," .
        " c.begin_exec_ts AS begin_exec_ts, c.end_exec_ts AS end_exec_ts," .
        " c.creation_ts AS creation_ts, p.name AS platform_name," .
        " COUNT(d.id) AS detail_rows, COALESCE(SUM(d.qty),0) AS qty_total" .
        " FROM {$ctx} c" .
        " LEFT JOIN {$det} d ON d.context_id = c.id" .
        " LEFT JOIN {$plat} p ON p.id = c.platform_id" .
        " WHERE c.testplan_id = " . intval($tplanId) .
        " GROUP BY c.id, c.platform_id, c.begin_exec_ts, c.end_exec_ts," .
        " c.creation_ts, p.name" .
        " ORDER BY c.creation_ts DESC, c.id DESC");
    $out = array();
    foreach ((array)$rows as $r) {
        $out[] = array(
            'id' => intval($r['id']),
            'platform_id' => intval($r['platform_id']),
            'platform_name' => isset($r['platform_name']) && $r['platform_name'] !== null
                ? (string)$r['platform_name'] : '',
            'begin_exec_ts' => (string)$r['begin_exec_ts'],
            'end_exec_ts' => (string)$r['end_exec_ts'],
            'creation_ts' => (string)$r['creation_ts'],
            'detail_rows' => intval($r['detail_rows']),
            'qty_total' => intval($r['qty_total']),
        );
    }
    return $out;
}

/**
 * Platform preview of the snapshot that save would write: the same per-plan
 * metrics the legacy resultsByTSuite controller used, grouped by platform.
 */
function bsvPreview($db, $tplanId)
{
    $metricsMgr = new tlTestPlanMetrics($db);
    $tplanMgr = new testplan($db);

    $platformSet = $tplanMgr->getPlatforms($tplanId, array('outputFormat' => 'map'));
    $showPlatforms = !is_null($platformSet) && count($platformSet) > 0;
    if ($showPlatforms) {
        natsort($platformSet);
    } else {
        $platformSet = array(0 => '');
    }

    $tsInf = $metricsMgr->getStatusTotalsTSuiteDepth2ForRender(
        $tplanId, null, array('groupByPlatform' => 1));

    $execContext = array('testplan_id');
    if ($showPlatforms) { $execContext[] = 'platform_id'; }
    $span = $metricsMgr->getExecTimeSpan($tplanId, $execContext);

    $cfgResults = config_get('results');
    $verboseCode = isset($cfgResults['status_code']) ? $cfgResults['status_code'] : array();

    $platforms = array();
    foreach ($platformSet as $platId => $platName) {
        $platId = intval($platId);
        $sp = null;
        if ($showPlatforms) {
            if (!is_null($span) && isset($span[$tplanId][$platId])) {
                $sp = $span[$tplanId][$platId];
            }
        } else {
            if (!is_null($span) && isset($span[$tplanId]) && !is_null($span[$tplanId])) {
                $sp = $span[$tplanId];
            }
        }

        $suiteCount = 0;
        $totalTc = 0;
        $statusTotals = array();
        if (!is_null($tsInf) && !empty($tsInf->infoL2) && isset($tsInf->infoL2[$platId])) {
            $suiteCount = count($tsInf->infoL2[$platId]);
            foreach ($tsInf->infoL2[$platId] as $info) {
                $totalTc += intval($info['total_tc']);
                if (isset($info['details'])) {
                    foreach ($info['details'] as $verbose => $figures) {
                        if (!isset($statusTotals[$verbose])) { $statusTotals[$verbose] = 0; }
                        $statusTotals[$verbose] += intval($figures['qty']);
                    }
                }
            }
        }

        $platforms[] = array(
            'id' => $platId,
            'name' => (string)$platName,
            'begin_exec_ts' => (is_array($sp) && isset($sp['begin'])) ? (string)$sp['begin'] : null,
            'end_exec_ts' => (is_array($sp) && isset($sp['end'])) ? (string)$sp['end'] : null,
            'has_executions' => is_array($sp),
            'suite_count' => $suiteCount,
            'total_tc' => $totalTc,
            'status_totals' => $statusTotals,
        );
    }

    $executable = 0;
    foreach ($platforms as $p) {
        if ($p['has_executions']) { $executable++; }
    }

    return array(
        'platforms' => $platforms,
        'has_platforms' => $showPlatforms,
        'has_data' => !is_null($tsInf) && !empty($tsInf->infoL2),
        'executable_platforms' => $executable,
        'verbose_code' => $verboseCode,
    );
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = bsvParam($_GET, 'action', '');
if ($action === '') { $action = bsvParam($_POST, 'action', 'init'); }

if (!in_array($action, array('init', 'snapshots', 'save'), true)) {
    bsvOut(array('status' => 'error', 'code' => 'unknown_action',
        'message' => 'Unknown action'), 400);
}

if ($action === 'save') {
    if ($method !== 'POST') {
        header('Allow: POST');
        bsvOut(array('status' => 'error', 'code' => 'wrong_method',
            'message' => 'Method not allowed'), 405);
    }
} elseif ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    bsvOut(array('status' => 'error', 'code' => 'wrong_method',
        'message' => 'Method not allowed'), 405);
}

$tpid = bsvInt($_REQUEST, 'tproject_id');
if ($tpid <= 0) { $tpid = intval($_SESSION['testprojectID'] ?? 0); }
if ($tpid <= 0) {
    bsvOut(array('status' => 'error', 'code' => 'no_testproject',
        'message' => 'No test project selected'), 400);
}

// Rights BEFORE the project is resolved - no existence oracle (#1697).
if (!bsvCanView($user, $db, $tpid)) {
    bsvOut(array('status' => 'error', 'code' => 'no_right',
        'message' => 'No permission to save baselines for this test project'), 403);
}

$info = bsvProjectInfo($db, $tpid);
if ($info === null) {
    bsvOut(array('status' => 'error', 'code' => 'tproject_not_found',
        'message' => 'Test project not found'), 404);
}

$plans = bsvPlans($db, $tpid);
$tplanId = bsvInt($_REQUEST, 'tplan_id');
if ($tplanId <= 0 && !empty($plans)) { $tplanId = intval($plans[0]['id']); }

$plan = null;
if ($tplanId > 0) {
    $plan = bsvPlanInfo($db, $tpid, $tplanId);
    if ($plan === null) {
        bsvOut(array('status' => 'error', 'code' => 'tplan_not_found',
            'message' => 'Test plan not found in this test project'), 404);
    }
}

$snapshots = $plan === null ? array() : bsvSnapshots($db, $tplanId);

if ($action === 'snapshots') {
    bsvOut(array('status' => 'ok', 'snapshots' => $snapshots));
}

$preview = $plan === null
    ? array('platforms' => array(), 'has_platforms' => false, 'has_data' => false,
            'executable_platforms' => 0, 'verbose_code' => array())
    : bsvPreview($db, $tplanId);

// ---- write ---------------------------------------------------------------

if ($action === 'save') {
    if ($plan === null || $preview['executable_platforms'] <= 0) {
        bsvOut(array('status' => 'error', 'code' => 'no_executions',
            'message' => 'Baseline NOT saved: the test plan has no execution records yet.',
            'snapshots' => $snapshots), 409);
    }

    $tbl = tlObjectWithDB::getDBTables(array(
        'baseline_l1l2_context', 'baseline_l1l2_details'));
    $ctxTable = $tbl['baseline_l1l2_context'];
    $detTable = $tbl['baseline_l1l2_details'];

    $metricsMgr = new tlTestPlanMetrics($db);
    $tplanMgr = new testplan($db);
    $platformSet = $tplanMgr->getPlatforms($tplanId, array('outputFormat' => 'map'));
    $showPlatforms = !is_null($platformSet) && count($platformSet) > 0;
    if (!$showPlatforms) { $platformSet = array(0 => ''); }

    $tsInf = $metricsMgr->getStatusTotalsTSuiteDepth2ForRender(
        $tplanId, null, array('groupByPlatform' => 1));
    if (is_null($tsInf) || empty($tsInf->infoL2)) {
        bsvOut(array('status' => 'error', 'code' => 'no_executions',
            'message' => 'Baseline NOT saved: the test plan has no execution records yet.',
            'snapshots' => $snapshots), 409);
    }

    $execContext = array('testplan_id');
    if ($showPlatforms) { $execContext[] = 'platform_id'; }
    $span = $metricsMgr->getExecTimeSpan($tplanId, $execContext);
    $verboseCode = $preview['verbose_code'];

    $saved = array();
    if (!$db->exec_query('START TRANSACTION')) {
        bsvOut(array('status' => 'error', 'code' => 'save_failed',
            'message' => 'Baseline save failed. Please try again.'), 500);
    }

    try {
        foreach ($tsInf->infoL2 as $platId => $elem) {
            $platId = intval($platId);
            $sp = null;
            if ($showPlatforms) {
                if (!is_null($span) && isset($span[$tplanId][$platId])) {
                    $sp = $span[$tplanId][$platId];
                }
            } else {
                if (!is_null($span) && isset($span[$tplanId]) && !is_null($span[$tplanId])) {
                    $sp = $span[$tplanId];
                }
            }
            // No execution records for this platform => nothing to baseline.
            if (!is_array($sp) || empty($sp['begin']) || empty($sp['end'])) {
                continue;
            }

            $begin = date('Y-m-d H:i:s', strtotime((string)$sp['begin']));
            $end = date('Y-m-d H:i:s', strtotime((string)$sp['end']));
            if ($begin === false || $end === false) { continue; }

            // udx1_context (testplan_id, platform_id, creation_ts): saving twice
            // inside the same second is a duplicate, not a server error. Resolve
            // it explicitly because exec_query() throws a message-less exception
            // for every DB failure (database.class.php:205).
            $creationTs = date('Y-m-d H:i:s');
            $dupRs = $db->exec_query(
                "SELECT COUNT(1) AS c FROM {$ctxTable}" .
                " WHERE testplan_id=" . intval($tplanId) .
                " AND platform_id=" . $platId .
                " AND creation_ts='" . $db->prepare_string($creationTs) . "'");
            $dup = $dupRs ? intval($db->fetch_array($dupRs)['c']) : 0;
            if ($dup > 0) {
                $db->exec_query('ROLLBACK');
                bsvOut(array('status' => 'error', 'code' => 'duplicate_snapshot',
                    'message' => 'A baseline for this platform was already saved in this second. Try again.',
                    'snapshots' => $snapshots), 409);
            }

            $ctxInserted = $db->exec_query(
                "INSERT INTO {$ctxTable}" .
                " (testplan_id,platform_id,begin_exec_ts,end_exec_ts,creation_ts)" .
                " VALUES(" . intval($tplanId) . "," . $platId . "," .
                "'" . $db->prepare_string($begin) . "'," .
                "'" . $db->prepare_string($end) . "'," .
                "'" . $db->prepare_string($creationTs) . "')");
            if (!$ctxInserted) {
                $dupRs = $db->exec_query(
                    "SELECT COUNT(1) AS c FROM {$ctxTable}" .
                    " WHERE testplan_id=" . intval($tplanId) .
                    " AND platform_id=" . $platId .
                    " AND creation_ts='" . $db->prepare_string($creationTs) . "'");
                if ($dupRs && intval($db->fetch_array($dupRs)['c']) > 0) {
                    $db->exec_query('ROLLBACK');
                    bsvOut(array('status' => 'error', 'code' => 'duplicate_snapshot',
                        'message' => 'A baseline for this platform was already saved in this second. Try again.',
                        'snapshots' => $snapshots), 409);
                }
                throw new RuntimeException('baseline context insert failed');
            }

            $contextId = intval($db->insert_id($ctxTable));
            $written = 0;
            foreach ($elem as $l2Id => $row) {
                $parentId = isset($row['parent_id']) ? intval($row['parent_id']) : 0;
                $totalTc = isset($row['total_tc']) ? intval($row['total_tc']) : 0;
                if (!isset($row['details'])) { continue; }
                foreach ($row['details'] as $verbose => $figures) {
                    $status = isset($verboseCode[$verbose])
                        ? (string)$verboseCode[$verbose] : (string)$verbose;
                    $status = substr($status, 0, 1);
                    $qty = isset($figures['qty']) ? intval($figures['qty']) : 0;
                    $detInserted = $db->exec_query(
                        "INSERT INTO {$detTable}" .
                        " (context_id,top_tsuite_id,child_tsuite_id,status,qty,total_tc)" .
                        " VALUES(" . $contextId . "," . $parentId . "," . intval($l2Id) . "," .
                        "'" . $db->prepare_string($status) . "'," . $qty . "," . $totalTc . ")");
                    if (!$detInserted) {
                        throw new RuntimeException('baseline detail insert failed');
                    }
                    $written++;
                }
            }

            $saved[] = array('platform_id' => $platId, 'context_id' => $contextId,
                             'details' => $written);
        }
    } catch (Throwable $e) {
        $db->exec_query('ROLLBACK');
        if (bsvIsDuplicateError($e)) {
            bsvOut(array('status' => 'error', 'code' => 'duplicate_snapshot',
                'message' => 'A baseline for this platform was already saved in this second. Try again.',
                'snapshots' => $snapshots), 409);
        }
        bsvOut(array('status' => 'error', 'code' => 'save_failed',
            'message' => 'Baseline save failed. Please try again.'), 500);
    }

    if (empty($saved)) {
        $db->exec_query('ROLLBACK');
        bsvOut(array('status' => 'error', 'code' => 'no_executions',
            'message' => 'Baseline NOT saved: the test plan has no execution records yet.',
            'snapshots' => $snapshots), 409);
    }
    if (!$db->exec_query('COMMIT')) {
        $db->exec_query('ROLLBACK');
        bsvOut(array('status' => 'error', 'code' => 'save_failed',
            'message' => 'Baseline save failed. Please try again.'), 500);
    }
    foreach ($saved as $one) {
        logAuditEvent(
            'Baseline snapshot saved for test plan ' . intval($tplanId) .
            ' platform ' . intval($one['platform_id']) .
            ' (' . intval($one['details']) . ' detail rows)',
            'CREATE', intval($one['context_id']), 'baseline_l1l2_context');
    }

    bsvOut(array(
        'status' => 'ok',
        'saved' => $saved,
        'message' => 'Baseline saved',
        'snapshots' => bsvSnapshots($db, $tplanId),
    ));
}

// ---- init ----------------------------------------------------------------

bsvOut(array(
    'status' => 'ok',
    'context' => array(
        'tproject_id' => $tpid,
        'tproject_name' => (string)$info['name'],
        'tproject_prefix' => (string)$info['prefix'],
    ),
    'plans' => $plans,
    'plan' => $plan === null ? null : array(
        'id' => intval($plan['id']),
        'name' => (string)$plan['name'],
    ),
    'platforms' => $preview['platforms'],
    'has_platforms' => $preview['has_platforms'],
    'has_data' => $preview['has_data'],
    'executable_platforms' => $preview['executable_platforms'],
    'snapshots' => $snapshots,
    'rights' => array('canSave' => true),
));
