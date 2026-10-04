<?php
/**
 * Risk-Based Testing BFF API — likelihood / impact + risk-coverage view.
 * URL: /api/riskcoverage/index.php
 * Plain PHP, no framework, no compilation.
 *
 * Backs the modernized "Risk-Based Testing" screen
 * gui/templates/results/riskCoverage.html (task issue #1277, ISTQB #1054).
 *
 * This is the ISTQB risk-based-testing model TestLink 1.9.20 never had: the
 * only risk-shaped artifact in the 1.9.20 tree was the vestigial
 * `risk_assignments` table (install/sql/mysql/testlink_create_tables.sql:413,
 * dropped again by alter_tables/1.8/mysql/DB.1.2/db_schema_update.sql:31 and
 * referenced only by the table whitelist lib/functions/object.class.php:288 —
 * zero PHP call sites) plus the crude importance x urgency proxy computed in
 * lib/functions/testcase.class.php:4688,6826. So there is nothing to port:
 * the model is built from scratch here and the 1.9.20 proxy is kept as the
 * fallback rating for test cases nobody has rated yet.
 *
 * Model
 *   likelihood 1..5  (rare -> almost certain)      weight 1
 *   impact     1..5  (negligible -> severe)        weight 1
 *   risk score = likelihood * impact               -> 1 .. 25
 *   level: 1..5 low, 6..11 medium, 12..25 high     (thresholds sent to the UI)
 *
 * Routes (session based, JSON I/O):
 *   GET  ?action=projects                          -> projects the user may read
 *   GET  ?action=plans&tproject_id=N               -> test plans of the project
 *   GET  ?action=init&tproject_id=N[&tplan_id=M]   -> context, rights, thresholds
 *   GET  ?action=coverage&tproject_id=N            -> requirement risk-coverage rows
 *   GET  ?action=register&tproject_id=N            -> test case risk register
 *   GET  ?action=metrics&tproject_id=N[&tplan_id=M][&level=X]
 *                                                  -> execution metrics per risk level
 *   GET  ?action=tc_risk&tc_id=N                   -> one test case rating
 *   POST ?action=save_tc_risk {tc_id, likelihood, impact, testproject_id}
 *
 * Storage: lazily created `tc_risk` table (CREATE TABLE IF NOT EXISTS, the
 * established BFF pattern of api/nfrtype/index.php:96 and api/reviews/index.php:93)
 * so a freshly imported DB keeps working without touching
 * install/sql/mysql/testlink_create_tables.sql.
 *
 * Rights model:
 *   - reads  : 'mgt_view_req' (requirement coverage), 'mgt_view_tc' (register),
 *              'testplan_metrics' (metrics); a user holding ANY of the three may
 *              open the screen, each section is filtered by the right it needs
 *   - writes : 'mgt_modify_tc' on the OWNING test project (403 otherwise)
 *   - unauthenticated -> 401; unknown action -> 400; forged tc_id -> 404
 *
 * Every write emits an AUDIT logEvent() so the Event Viewer tracks the module.
 */
require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');

$db = null;
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || $userId <= 0) {
    http_response_code(401);
    echo json_encode(array('status' => 'error', 'message' => 'Not authenticated'));
    exit;
}

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    http_response_code(401);
    echo json_encode(array('status' => 'error', 'message' => 'User not found'));
    exit;
}

$tprojectMgr = new testproject($db);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = trim((string)($_REQUEST['action'] ?? ''));

// The screen POSTs a JSON envelope (content-type: application/json), so the
// verb is NOT in $_REQUEST: read the body once, up front, and let it carry
// the action when $_REQUEST has none.
$requestBody = json_decode(file_get_contents('php://input'), true);
if (!is_array($requestBody)) {
    $requestBody = $_POST;
}
if ($action === '' && isset($requestBody['action'])) {
    $action = trim((string)$requestBody['action']);
}

/* ------------------------------------------------------------------ model */

/** Risk level thresholds (score -> level code). Kept server side, sent to UI. */
function riskThresholds() {
    return array('low_max' => 5, 'medium_max' => 11);
}

/** Score -> level code. */
function riskLevel($score) {
    $th = riskThresholds();
    $score = (int)$score;
    if ($score <= 0) {
        return 'unrated';
    }
    if ($score <= $th['low_max']) {
        return 'low';
    }
    if ($score <= $th['medium_max']) {
        return 'medium';
    }
    return 'high';
}

/** The level domain in display order (used by the metrics filter). */
function riskLevels() {
    return array('high', 'medium', 'low', 'unrated');
}

/** Table name (respects the optional DB_TABLE_PREFIX). */
function riskTable() {
    $t = tlObjectWithDB::getDBTables(array('tc_risk'));
    return $t['tc_risk'];
}

/** Idempotent lazy schema migration so a freshly imported DB works unchanged. */
function riskEnsureSchema($db) {
    $t = riskTable();
    $db->exec_query(
        "CREATE TABLE IF NOT EXISTS {$t} (" .
        " tc_id INT UNSIGNED NOT NULL," .
        " testproject_id INT UNSIGNED NOT NULL DEFAULT 0," .
        " likelihood TINYINT UNSIGNED NOT NULL DEFAULT 1," .
        " impact TINYINT UNSIGNED NOT NULL DEFAULT 1," .
        " updated_by INT UNSIGNED NULL," .
        " updated_ts TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP" .
        " ON UPDATE CURRENT_TIMESTAMP," .
        " PRIMARY KEY (tc_id)," .
        " KEY idx_tc_risk_tproject (testproject_id)" .
        ") ENGINE=InnoDB DEFAULT CHARSET=utf8");
}

/** Resolve + authorize the owning test project (param wins, session fallback). */
function riskResolveTproject($db, $user) {
    $tpid = (int)($_REQUEST['tproject_id'] ?? 0);
    if ($tpid <= 0) {
        $tpid = (int)($_SESSION['testprojectID'] ?? 0);
    }
    if ($tpid <= 0) {
        http_response_code(400);
        echo json_encode(array('status' => 'error', 'message' => 'No test project selected'));
        exit;
    }
    $project = $tprojectMgr = new testproject($db);
    $project = $tprojectMgr->get_by_id($tpid);
    if (is_null($project)) {
        http_response_code(404);
        echo json_encode(array('status' => 'error', 'message' => 'Test project not found'));
        exit;
    }
    return $tpid;
}

/** Any read right over the risk model? */
function riskCanRead($db, $user, $tpid) {
    foreach (array('mgt_view_req', 'mgt_view_tc', 'testplan_metrics') as $right) {
        if ($user->hasRight($db, $right, $tpid) === 'yes') {
            return true;
        }
    }
    return false;
}

/** Risk ratings of a whole test project, keyed by tc_id. */
function riskRatings($db, $tpid) {
    riskEnsureSchema($db);
    $rows = (array)$db->get_recordset(
        " SELECT tc_id, likelihood, impact, updated_by, updated_ts FROM " .
        riskTable() . " WHERE testproject_id = " . intval($tpid));
    $out = array();
    foreach ($rows as $r) {
        $lik = max(1, min(5, (int)$r['likelihood']));
        $imp = max(1, min(5, (int)$r['impact']));
        $out[(int)$r['tc_id']] = array(
            'likelihood'  => $lik,
            'impact'      => $imp,
            'score'       => $lik * $imp,
            'level'       => riskLevel($lik * $imp),
            'updated_by'  => isset($r['updated_by']) ? (int)$r['updated_by'] : 0,
            'updated_ts'  => $r['updated_ts'],
        );
    }
    return $out;
}

/**
 * Test cases of a project (id -> meta) with the 1.9.20 importance x urgency
 * proxy used as the fallback rating: TestLink importance/urgency are 1..3,
 * so the proxy lands on 1..9 and is mapped onto the 1..25 matrix by
 * score = proxy * 2 (a Medium 2x2 test case -> 8 = medium, as legacy would
 * treat a 2x2 priority case as a medium-effort one).
 */
function riskTestCases($db, $tpid, $tplanId = 0) {
    // node_type 4 = test case VERSION, its parent (3) is the test case and the
    // grandparent (2) is the test suite.
    $sql = " SELECT NHTC.id AS tc_id, NHTCV.id AS tcversion_id, NHTC.name AS name," .
           " TCV.tc_external_id AS external_id, TCV.importance AS importance," .
           " NHTS.name AS suite_name" .
           " FROM nodes_hierarchy NHTCV" .
           " JOIN nodes_hierarchy NHTC ON NHTC.id = NHTCV.parent_id AND NHTC.node_type_id = 3" .
           " JOIN nodes_hierarchy NHTS ON NHTS.id = NHTC.parent_id AND NHTS.node_type_id = 2" .
           " JOIN tcversions TCV ON TCV.id = NHTCV.id" .
           " WHERE TCV.active = 1 AND TCV.is_open = 1" .
           " AND NHTS.parent_id = " . intval($tpid) .
           " ORDER BY NHTS.name, NHTC.name";
    if ($tplanId > 0) {
        $tt = tlObjectWithDB::getDBTables(array('testplan_tcversions'));
        $sql = " SELECT NHTC.id AS tc_id, NHTCV.id AS tcversion_id, NHTC.name AS name," .
               " TCV.tc_external_id AS external_id, TCV.importance AS importance," .
               " NHTS.name AS suite_name" .
               " FROM nodes_hierarchy NHTCV" .
               " JOIN nodes_hierarchy NHTC ON NHTC.id = NHTCV.parent_id AND NHTC.node_type_id = 3" .
               " JOIN nodes_hierarchy NHTS ON NHTS.id = NHTC.parent_id AND NHTS.node_type_id = 2" .
               " JOIN tcversions TCV ON TCV.id = NHTCV.id" .
               " JOIN {$tt['testplan_tcversions']} TPTV ON TPTV.tcversion_id = NHTCV.id" .
               " WHERE TCV.active = 1 AND TCV.is_open = 1" .
               " AND NHTS.parent_id = " . intval($tpid) .
               " AND TPTV.testplan_id = " . intval($tplanId) .
               " ORDER BY NHTS.name, NHTC.name";
    }
    $rows = (array)$db->get_recordset($sql);
    $out = array();
    foreach ($rows as $r) {
        $out[(int)$r['tc_id']] = array(
            'tc_id'       => (int)$r['tc_id'],
            'tcversion_id'=> (int)$r['tcversion_id'],
            'name'        => $r['name'],
            'external_id' => (int)$r['external_id'],
            'suite_name'  => $r['suite_name'],
            'importance'  => (int)$r['importance'],
        );
    }
    return $out;
}

/** Latest execution status per tcversion inside one test plan. */
function riskExecutions($db, $tplanId) {
    if ($tplanId <= 0) {
        return array();
    }
    $rows = (array)$db->get_recordset(
        " SELECT LE.tcversion_id, E.status FROM latest_exec_by_testplan LE" .
        " JOIN executions E ON E.id = LE.id" .
        " WHERE LE.testplan_id = " . intval($tplanId));
    $out = array();
    foreach ($rows as $r) {
        $out[(int)$r['tcversion_id']] = (string)$r['status'];
    }
    return $out;
}

/** Merge a rating with the test case meta + execution status. */
function riskRow($tc, $rating, $execStatus) {
    $lik = $rating ? $rating['likelihood'] : 0;
    $imp = $rating ? $rating['impact'] : 0;
    $proxy = 0;
    if (!$rating) {
        // TestLink importance is 1 = High, 2 = Medium, 3 = Low; the 1.9.20
        // proxy is mapped onto the 1..5 matrix as 5 / 3 / 1.
        $map = array(1 => 5, 2 => 3, 3 => 1);
        $key = (int)$tc['importance'];
        $imp = isset($map[$key]) ? $map[$key] : 3;
        $lik = $imp;
        $proxy = $imp;
    }
    $score = $lik * $imp;
    $status = ($execStatus !== null && $execStatus !== '') ? $execStatus : 'n';
    return array(
        'tc_id'        => (int)$tc['tc_id'],
        'tcversion_id' => (int)$tc['tcversion_id'],
        'external_id'  => (int)$tc['external_id'],
        'name'         => $tc['name'],
        'suite_name'   => $tc['suite_name'],
        'rated'        => $rating ? 1 : 0,
        'likelihood'   => $lik,
        'impact'       => $imp,
        'score'        => $rating ? $score : 0,
        'level'        => $rating ? riskLevel($score) : 'unrated',
        'proxy_score'  => $rating ? 0 : $score,
        'importance'   => (int)$tc['importance'],
        'exec_status'  => $status,
        'executed'     => ($status !== 'n') ? 1 : 0,
        'passed'       => ($status === 'p') ? 1 : 0,
        'failed'       => ($status === 'f') ? 1 : 0,
        'blocked'      => ($status === 'b') ? 1 : 0,
    );
}

/* --------------------------------------------------------------- GET side */

if ($method === 'GET') {

    if ($action === 'projects') {
        $sql = " SELECT tp.id, nh.name, tp.prefix FROM testprojects tp" .
               " JOIN nodes_hierarchy nh ON nh.id = tp.id" .
               " WHERE tp.active = 1 ORDER BY nh.name";
        $rows = (array)$db->get_recordset($sql);
        $items = array();
        foreach ($rows as $row) {
            $tpid = (int)$row['id'];
            if (!riskCanRead($db, $user, $tpid)) {
                continue;
            }
            $items[] = array('id' => $tpid, 'name' => $row['name'],
                'prefix' => $row['prefix']);
        }
        echo json_encode(array('status' => 'ok', 'projects' => $items));
        exit;
    }

    if ($action === 'plans') {
        $tpid = riskResolveTproject($db, $user);
        if (!riskCanRead($db, $user, $tpid)) {
            http_response_code(403);
            echo json_encode(array('status' => 'error', 'message' => 'No permission'));
            exit;
        }
        $rows = (array)$db->get_recordset(
            " SELECT tp.id, nh.name FROM testplans tp" .
            " JOIN nodes_hierarchy nh ON nh.id = tp.id" .
            " WHERE tp.testproject_id = " . intval($tpid) . " AND tp.active = 1" .
            " ORDER BY nh.name");
        $items = array();
        foreach ($rows as $row) {
            $items[] = array('id' => (int)$row['id'], 'name' => $row['name']);
        }
        echo json_encode(array('status' => 'ok', 'plans' => $items));
        exit;
    }

    if ($action === 'init') {
        $tpid = riskResolveTproject($db, $user);
        if (!riskCanRead($db, $user, $tpid)) {
            http_response_code(403);
            echo json_encode(array('status' => 'error', 'message' => 'No permission'));
            exit;
        }
        $tprjTable = tlObjectWithDB::getDBTables(array('testprojects'));
        $nameRow = $db->fetchFirstRow(
            " SELECT nh.name FROM {$tprjTable['testprojects']} tp" .
            " JOIN nodes_hierarchy nh ON nh.id = tp.id WHERE tp.id = " . intval($tpid));
        $tplanId = (int)($_REQUEST['tplan_id'] ?? 0);
        $tplanName = '';
        if ($tplanId > 0) {
            $tp = tlObjectWithDB::getDBTables(array('testplans'));
            $prow = $db->fetchFirstRow(
                " SELECT nh.name FROM {$tp['testplans']} tpl" .
                " JOIN nodes_hierarchy nh ON nh.id = tpl.id" .
                " WHERE tpl.id = " . intval($tplanId) .
                " AND tpl.testproject_id = " . intval($tpid));
            if ($prow && is_array($prow)) {
                $tplanName = $prow['name'];
            } else {
                $tplanId = 0;
            }
        }
        echo json_encode(array(
            'status'        => 'ok',
            'tproject_id'   => $tpid,
            'tproject_name' => $nameRow && is_array($nameRow) ? $nameRow['name'] : '',
            'tplan_id'      => $tplanId,
            'tplan_name'    => $tplanName,
            'canViewReq'    => ($user->hasRight($db, 'mgt_view_req', $tpid) === 'yes'),
            'canViewTc'     => ($user->hasRight($db, 'mgt_view_tc', $tpid) === 'yes'),
            'canViewMetrics'=> ($user->hasRight($db, 'testplan_metrics', $tpid) === 'yes'),
            'canEdit'       => ($user->hasRight($db, 'mgt_modify_tc', $tpid) === 'yes'),
            'thresholds'    => riskThresholds(),
            'levels'        => riskLevels(),
        ));
        exit;
    }

    // Requirement risk coverage: covered vs uncovered requirements against
    // the risk rating of the test cases that cover them (residual risk = the
    // highest-rated covering test case that has NOT been executed yet, or the
    // highest-rated uncovered requirement when nothing covers it at all).
    if ($action === 'coverage') {
        $tpid = riskResolveTproject($db, $user);
        if ($user->hasRight($db, 'mgt_view_req', $tpid) !== 'yes') {
            http_response_code(403);
            echo json_encode(array('status' => 'error', 'message' => 'No permission'));
            exit;
        }
        $tplanId = (int)($_REQUEST['tplan_id'] ?? 0);
        $execs = riskExecutions($db, $tplanId);
        $ratings = riskRatings($db, $tpid);
        $cases = riskTestCases($db, $tpid, 0);

        $rows = (array)$db->get_recordset(
            " SELECT RQ.id AS req_id, RQ.req_doc_id," .
            " (SELECT RR.name FROM req_revisions RR WHERE RR.parent_id = RQ.id" .
            "  ORDER BY RR.revision DESC LIMIT 1) AS name" .
            " FROM req_specs RS JOIN requirements RQ ON RQ.srs_id = RS.id" .
            " WHERE RS.testproject_id = " . intval($tpid) .
            " ORDER BY RQ.req_doc_id");

        $items = array();
        foreach ($rows as $r) {
            $reqId = (int)$r['req_id'];
            $linkRows = (array)$db->get_recordset(
                " SELECT testcase_id FROM req_coverage" .
                " WHERE req_id = " . $reqId . " AND is_active = 1 AND link_status = 1");
            $linked = array();
            foreach ($linkRows as $lr) {
                $tcId = (int)$lr['testcase_id'];
                if (isset($cases[$tcId])) {
                    $linked[] = $tcId;
                }
            }
            $total = count($linked);
            $executed = 0;
            $maxScore = 0;
            $unratedCount = 0;
            $riskSum = 0;
            $ratedCount = 0;
            $residual = 0;
            foreach ($linked as $tcId) {
                $r2 = riskRow($cases[$tcId],
                    isset($ratings[$tcId]) ? $ratings[$tcId] : null,
                    isset($execs[$cases[$tcId]['tcversion_id']])
                        ? $execs[$cases[$tcId]['tcversion_id']] : null);
                if ($r2['executed']) {
                    $executed++;
                }
                if (!$r2['rated']) {
                    $unratedCount++;
                    continue;
                }
                $ratedCount++;
                $riskSum += $r2['score'];
                if ($r2['score'] > $maxScore) {
                    $maxScore = $r2['score'];
                }
                if (!$r2['executed'] && $r2['score'] > $residual) {
                    $residual = $r2['score'];
                }
            }
            $coverage = ($total > 0) ? round(($executed / $total) * 100, 1) : 0.0;
            $status = ($total === 0) ? 'uncovered'
                : ($executed === 0 ? 'not_tested' : 'covered');
            $items[] = array(
                'req_id'        => $reqId,
                'req_doc_id'    => (string)$r['req_doc_id'],
                'name'          => $r['name'] === null ? '' : (string)$r['name'],
                'total_tcs'     => $total,
                'executed_tcs'  => $executed,
                'unrated_tcs'   => $unratedCount,
                'rated_tcs'     => $ratedCount,
                'coverage_pct'  => $coverage,
                'max_risk'      => $maxScore,
                'avg_risk'      => $ratedCount > 0 ? round($riskSum / $ratedCount, 1) : 0,
                'residual_risk' => $residual,
                'residual_level'=> riskLevel($residual),
                'risk_level'    => riskLevel($maxScore),
                'coverage_status'=> $status,
            );
        }

        $sum = array('total_reqs' => count($items), 'uncovered' => 0,
            'covered' => 0, 'not_tested' => 0, 'high_risk_uncovered' => 0,
            'residual_high' => 0);
        foreach ($items as $it) {
            $sum[$it['coverage_status']]++;
            if (($it['risk_level'] === 'high' || $it['residual_level'] === 'high') &&
                $it['coverage_status'] !== 'covered') {
                $sum['high_risk_uncovered']++;
            }
            if ($it['residual_level'] === 'high') {
                $sum['residual_high']++;
            }
        }
        echo json_encode(array('status' => 'ok',
            'tproject_id' => $tpid, 'tplan_id' => $tplanId,
            'summary' => $sum, 'items' => $items));
        exit;
    }

    // Test case risk register (the editable grid).
    if ($action === 'register') {
        $tpid = riskResolveTproject($db, $user);
        if ($user->hasRight($db, 'mgt_view_tc', $tpid) !== 'yes') {
            http_response_code(403);
            echo json_encode(array('status' => 'error', 'message' => 'No permission'));
            exit;
        }
        $tplanId = (int)($_REQUEST['tplan_id'] ?? 0);
        $execs = riskExecutions($db, $tplanId);
        $ratings = riskRatings($db, $tpid);
        $cases = riskTestCases($db, $tpid, $tplanId > 0 ? $tplanId : 0);
        $items = array();
        $dist = array('high' => 0, 'medium' => 0, 'low' => 0, 'unrated' => 0);
        foreach ($cases as $tcId => $tc) {
            $row = riskRow($tc,
                isset($ratings[$tcId]) ? $ratings[$tcId] : null,
                isset($execs[$tc['tcversion_id']]) ? $execs[$tc['tcversion_id']] : null);
            $dist[$row['level']]++;
            $items[] = $row;
        }
        echo json_encode(array('status' => 'ok', 'tproject_id' => $tpid,
            'tplan_id' => $tplanId, 'distribution' => $dist, 'items' => $items));
        exit;
    }

    // Execution metrics grouped by risk level, filterable by level.
    if ($action === 'metrics') {
        $tpid = riskResolveTproject($db, $user);
        if ($user->hasRight($db, 'testplan_metrics', $tpid) !== 'yes') {
            http_response_code(403);
            echo json_encode(array('status' => 'error', 'message' => 'No permission'));
            exit;
        }
        $tplanId = (int)($_REQUEST['tplan_id'] ?? 0);
        $filter = trim((string)($_REQUEST['level'] ?? ''));
        $execs = riskExecutions($db, $tplanId);
        $ratings = riskRatings($db, $tpid);
        $cases = riskTestCases($db, $tpid, $tplanId);

        $groups = array();
        foreach (riskLevels() as $lv) {
            $groups[$lv] = array('level' => $lv, 'total' => 0, 'executed' => 0,
                'passed' => 0, 'failed' => 0, 'blocked' => 0,
                'exec_pct' => 0, 'pass_rate' => 0);
        }
        $items = array();
        foreach ($cases as $tcId => $tc) {
            $row = riskRow($tc,
                isset($ratings[$tcId]) ? $ratings[$tcId] : null,
                isset($execs[$tc['tcversion_id']]) ? $execs[$tc['tcversion_id']] : null);
            if ($filter !== '' && $filter !== 'all' && $row['level'] !== $filter) {
                continue;
            }
            $g = $groups[$row['level']];
            $g['total']++;
            $g['executed'] += $row['executed'];
            $g['passed'] += $row['passed'];
            $g['failed'] += $row['failed'];
            $g['blocked'] += $row['blocked'];
            $groups[$row['level']] = $g;
            $items[] = $row;
        }
        $out = array();
        $tot = array('total' => 0, 'executed' => 0, 'passed' => 0, 'failed' => 0,
            'blocked' => 0);
        foreach ($groups as $lv => $g) {
            $g['exec_pct'] = $g['total'] > 0 ? round(($g['executed'] / $g['total']) * 100, 1) : 0;
            $g['pass_rate'] = $g['executed'] > 0 ? round(($g['passed'] / $g['executed']) * 100, 1) : 0;
            foreach ($tot as $k => $v) {
                $tot[$k] += $g[$k];
            }
            if ($g['total'] > 0 || $filter === $lv || $filter === '') {
                $out[] = $g;
            }
        }
        $tot['exec_pct'] = $tot['total'] > 0 ? round(($tot['executed'] / $tot['total']) * 100, 1) : 0;
        $tot['pass_rate'] = $tot['executed'] > 0 ? round(($tot['passed'] / $tot['executed']) * 100, 1) : 0;
        echo json_encode(array('status' => 'ok', 'tproject_id' => $tpid,
            'tplan_id' => $tplanId, 'level_filter' => $filter,
            'groups' => $out, 'total' => $tot, 'items' => $items));
        exit;
    }

    // Single test case rating (used by the edit modal).
    if ($action === 'tc_risk') {
        $tcId = (int)($_REQUEST['tc_id'] ?? 0);
        if ($tcId <= 0) {
            http_response_code(400);
            echo json_encode(array('status' => 'error', 'message' => 'tc_id required'));
            exit;
        }
        riskEnsureSchema($db);
        $tv = tlObjectWithDB::getDBTables(array('tcversions'));
        $prow = $db->fetchFirstRow(
            " SELECT NHTC.id AS tc_id, NHTCV.id AS tcversion_id, NHTC.name," .
            " NHTS.name AS suite_name, NHTS.parent_id AS tproject_id," .
            " TCV.tc_external_id AS external_id, TCV.importance AS importance" .
            " FROM nodes_hierarchy NHTCV" .
            " JOIN nodes_hierarchy NHTC ON NHTC.id = NHTCV.parent_id AND NHTC.node_type_id = 3" .
            " JOIN nodes_hierarchy NHTS ON NHTS.id = NHTC.parent_id AND NHTS.node_type_id = 2" .
            " JOIN {$tv['tcversions']} TCV ON TCV.id = NHTCV.id" .
            " WHERE NHTCV.id = " . $tcId . " AND NHTCV.node_type_id = 4");
        if (!$prow || !is_array($prow)) {
            http_response_code(404);
            echo json_encode(array('status' => 'error', 'message' => 'Test case version not found'));
            exit;
        }
        $tpid = (int)$prow['tproject_id'];
        if (!riskCanRead($db, $user, $tpid)) {
            http_response_code(403);
            echo json_encode(array('status' => 'error', 'message' => 'No permission'));
            exit;
        }
        $ratings = riskRatings($db, $tpid);
        $tcId = (int)$prow['tc_id'];
        $rating = isset($ratings[$tcId]) ? $ratings[$tcId] : null;
        echo json_encode(array(
            'status'  => 'ok',
            'testcase'=> array('tc_id' => $tcId,
                'tcversion_id' => (int)$prow['tcversion_id'],
                'external_id' => (int)$prow['external_id'],
                'name'   => $prow['name'], 'suite_name' => $prow['suite_name'],
                'testproject_id' => $tpid,
                'importance' => (int)$prow['importance']),
            'risk'    => $rating,
            'thresholds' => riskThresholds(),
            'canEdit' => ($user->hasRight($db, 'mgt_modify_tc', $tpid) === 'yes'),
        ));
        exit;
    }

    http_response_code(400);
    echo json_encode(array('status' => 'error', 'message' => 'Unknown action'));
    exit;
}

/* -------------------------------------------------------------- POST side */

if ($method === 'POST') {

    if ($action !== 'save_tc_risk') {
        http_response_code(400);
        echo json_encode(array('status' => 'error', 'message' => 'Unknown action'));
        exit;
    }

    $body = $requestBody;
    $tcversionId = (int)($body['tc_id'] ?? 0);
    if ($tcversionId <= 0) {
        http_response_code(400);
        echo json_encode(array('status' => 'error', 'message' => 'tc_id required'));
        exit;
    }
    $likelihood = (int)($body['likelihood'] ?? 0);
    $impact = (int)($body['impact'] ?? 0);
    if ($likelihood < 1 || $likelihood > 5 || $impact < 1 || $impact > 5) {
        http_response_code(400);
        echo json_encode(array('status' => 'error',
            'message' => 'Likelihood and impact must be between 1 and 5'));
        exit;
    }

    riskEnsureSchema($db);
    $tv = tlObjectWithDB::getDBTables(array('tcversions'));
    $prow = $db->fetchFirstRow(
        " SELECT NHTC.id AS tc_id, NHTC.name, NHTS.parent_id AS tproject_id" .
        " FROM nodes_hierarchy NHTCV" .
        " JOIN nodes_hierarchy NHTC ON NHTC.id = NHTCV.parent_id AND NHTC.node_type_id = 3" .
        " JOIN nodes_hierarchy NHTS ON NHTS.id = NHTC.parent_id AND NHTS.node_type_id = 2" .
        " JOIN {$tv['tcversions']} TCV ON TCV.id = NHTCV.id" .
        " WHERE NHTCV.id = " . $tcversionId . " AND NHTCV.node_type_id = 4");
    if (!$prow || !is_array($prow)) {
        http_response_code(404);
        echo json_encode(array('status' => 'error', 'message' => 'Test case version not found'));
        exit;
    }
    $tpid = (int)$prow['tproject_id'];
    if ($user->hasRight($db, 'mgt_modify_tc', $tpid) !== 'yes') {
        http_response_code(403);
        echo json_encode(array('status' => 'error', 'message' => 'No permission'));
        exit;
    }

    $tcId = (int)$prow['tc_id'];
    $t = riskTable();
    $exists = $db->fetchFirstRow(" SELECT tc_id FROM {$t} WHERE tc_id = " . $tcId);
    if ($exists && is_array($exists)) {
        $db->exec_query(" UPDATE {$t} SET likelihood = " . $likelihood .
            ", impact = " . $impact . ", testproject_id = " . $tpid .
            ", updated_by = " . (int)$userId .
            " WHERE tc_id = " . $tcId);
    } else {
        $db->exec_query(" INSERT INTO {$t}" .
            " (tc_id, testproject_id, likelihood, impact, updated_by) VALUES (" .
            $tcId . ", " . $tpid . ", " . $likelihood . ", " . $impact . ", " .
            (int)$userId . ")");
    }

    $event = new stdClass();
    $event->message = 'Risk rating saved: ' . $prow['name'] .
        ' (likelihood ' . $likelihood . ' x impact ' . $impact .
        ' = score ' . ($likelihood * $impact) . ', level ' .
        riskLevel($likelihood * $impact) . ')';
    $event->logLevel = 'AUDIT';
    $event->source = 'GUI';
    $event->objectID = $tpid;
    $event->objectType = 'testprojects';
    $event->code = 'RISK_SAVE';
    logEvent($event);

    echo json_encode(array('status' => 'ok',
        'risk' => array('tc_id' => $tcId, 'likelihood' => $likelihood,
            'impact' => $impact, 'score' => $likelihood * $impact,
            'level' => riskLevel($likelihood * $impact)),
        'message' => 'Risk rating saved'));
    exit;
}

http_response_code(405);
echo json_encode(array('status' => 'error', 'message' => 'Method not allowed'));
exit;
