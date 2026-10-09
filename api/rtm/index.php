<?php
/**
 * api/rtm — Requirements Traceability Matrix BFF
 *
 * Implements enhancement #1065: a bidirectional requirement ⇄ test-case
 * traceability report for the active test plan. TestLink 1.9.20 had no RTM
 * screen (only the Requirement Coverage report, which drops orphan
 * requirements and never shows the linked test-case detail per requirement),
 * so this is a NEW Reports screen rather than a port:
 *
 *   gui/templates/results/rtm.html  +  this BFF.
 *
 * Routes (session auth, same-origin guard, JSON I/O, no Smarty):
 *   GET ?action=context[&tplan_id=N][&tproject_id=M]
 *        -> plan name, project name, build set, platform set, requirements
 *           enabled flag (the Context card / filters).
 *   GET ?action=matrix[&tplan_id=N][&build_id=B][&platform_id=P]
 *        -> one row per requirement of the owning project (orphans included)
 *           with its req_coverage linked test cases, the latest execution
 *           result per linked version on the build+platform filter, a defect
 *           count per requirement, the coverage status (covered / partial /
 *           uncovered) and the plan's orphan test cases (in plan, linked to
 *           no requirement). Also the summary counters.
 *
 * Rights: the legacy Requirement Coverage report and the Results navigator
 * both gate on testplan_metrics; this report does the same, on the OWNING
 * project which is PROVED from the test plan (a plan id is the entry point,
 * so there is no tproject_id assertion; tproject_id, when supplied, must
 * match the owning project or the request is answered 404 project_mismatch -
 * the #1697/no-existence-oracle convention).
 *
 * Coverage semantics (documented in the screen): a requirement is
 *   - uncovered  when none of its linked test cases is assigned to the plan
 *     or none has been executed on the selected build/platform,
 *   - covered    when every plan-assigned linked test case has been executed
 *     and the LATEST result of each is 'pass',
 *   - partial    otherwise (executed but a fail/blocked result is present).
 * A requirement with zero req_coverage links at all is additionally flagged
 * as "orphan". A plan-assigned test case with no req_coverage row is listed
 * under "orphan test cases".
 *
 * Defect counts read execution_bugs joined via executions, so they follow
 * the same build/platform filter as the execution column.
 */
require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
require_once(__DIR__ . '/../../lib/functions/requirement_mgr.class.php');
require_once(__DIR__ . '/../../lib/functions/requirement_spec_mgr.class.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || $userId <= 0) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'User not found']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

$bff = function ($action) use ($db, $user) {
    $tables = tlObjectWithDB::getDBTables();
    $query = $_GET;

    $tplan_id = isset($query['tplan_id']) ? intval($query['tplan_id']) : 0;
    if ($tplan_id <= 0) {
        $tplan_id = isset($_SESSION['testplanID']) ? intval($_SESSION['testplanID']) : 0;
    }
    if ($tplan_id <= 0) {
        http_response_code(400);
        return ['status' => 'error', 'message' => 'No test plan in context'];
    }

    $planInfo = $db->fetchOneValue(
        'SELECT TP.id FROM ' . $tables['testplans'] . ' TP WHERE TP.id = ' . intval($tplan_id));
    if (!$planInfo) {
        http_response_code(404);
        return ['status' => 'error', 'message' => 'Unknown test plan'];
    }

    $owning = $db->fetchOneValue(
        'SELECT TP.testproject_id FROM ' . $tables['testplans'] . ' TP WHERE TP.id = ' . intval($tplan_id));
    if (!$owning) {
        http_response_code(404);
        return ['status' => 'error', 'message' => 'Unknown test plan'];
    }
    $tproject_id = intval($owning);

    // tproject_id, when supplied, is a client-side assertion (stale deep link
    // or cross-project hand-off must not silently read another project).
    if (isset($query['tproject_id']) && intval($query['tproject_id']) > 0
        && intval($query['tproject_id']) !== $tproject_id) {
        http_response_code(404);
        return ['status' => 'error', 'message' => 'Project mismatch'];
    }

    if (!$user->hasRight($db, 'testplan_metrics', $tproject_id, $tplan_id)) {
        http_response_code(403);
        return ['status' => 'error', 'message' => 'Missing rights: testplan_metrics'];
    }

    // 2.0.1 schema drift (#1767 lesson): testplans / testprojects carry NO
    // name column - the name lives in nodes_hierarchy.
    $planName = (string)$db->fetchOneValue(
        'SELECT NH.name FROM ' . $tables['nodes_hierarchy'] . ' NH WHERE NH.id = ' . intval($tplan_id));
    $projectName = (string)$db->fetchOneValue(
        'SELECT NH.name FROM ' . $tables['nodes_hierarchy'] . ' NH WHERE NH.id = ' . intval($tproject_id));

    $tprojMgr = new testproject($db);

    $projOpts = $tprojMgr->getOptions($tproject_id);
    $reqEnabled = (!empty($projOpts) && isset($projOpts->requirementsEnabled))
        ? (bool)$projOpts->requirementsEnabled : false;

    // Build set
    $builds = array();
    $brows = $db->get_recordset(
        'SELECT B.id, B.name FROM ' . $tables['builds'] . ' B' .
        ' WHERE B.testproject_id = ' . intval($tproject_id) . ' AND B.active = 1' .
        ' ORDER BY B.creation_ts, B.id');
    foreach ((array)$brows as $row) {
        $builds[] = ['id' => intval($row['id']), 'name' => (string)$row['name']];
    }

    // Platform set (map incl. the default platform 0)
    $platformSet = array();
    $tplanMgr = new testplan($db);
    $platMap = $tplanMgr->getPlatforms($tplan_id, ['outputFormat' => 'map']);
    if (is_array($platMap) && count($platMap) > 0) {
        foreach ($platMap as $pid => $pname) {
            $platformSet[] = ['id' => intval($pid), 'name' => (string)$pname];
        }
    } else {
        $platformSet[] = ['id' => 0, 'name' => ''];
    }

    if ($action === 'context') {
        return [
            'status' => 'ok',
            'tplan_id' => $tplan_id,
            'tproject_id' => $tproject_id,
            'tplan_name' => $planName,
            'tproject_name' => $projectName,
            'requirements_enabled' => $reqEnabled,
            'builds' => $builds,
            'platforms' => $platformSet,
        ];
    }

    if ($action === 'matrix') {
        if (!$reqEnabled) {
            http_response_code(200);
            return ['status' => 'ok', 'requirements_enabled' => false,
                    'summary' => null, 'rows' => [], 'orphan_tcs' => []];
        }

        $buildFilter = isset($query['build_id']) ? intval($query['build_id']) : 0;
        $platformFilter = isset($query['platform_id']) ? intval($query['platform_id']) : 0;

        $buildWhere = $buildFilter > 0 ? " AND E.build_id = " . intval($buildFilter) : '';
        $platformWhere = $platformFilter > 0 ? " AND E.platform_id = " . intval($platformFilter) : '';

        // Requirements of the owning project (LATEST version only), orphans
        // included. The version node is a nodes_hierarchy child of the
        // requirement node (req_versions.id == the node id, like tcversions).
        $reqRows = $db->get_recordset(
            "SELECT R.id, R.srs_id, R.req_doc_id,
                    NH.name AS title,
                    RV.id AS version_id, RV.version, RV.status AS req_status,
                    RV.type AS req_type, RV.expected_coverage,
                    RS.doc_id AS spec_doc_id, SN.name AS spec_title
             FROM {$tables['requirements']} R
             JOIN {$tables['nodes_hierarchy']} NH ON NH.id = R.id
             JOIN {$tables['req_specs']} RS ON RS.id = R.srs_id
             JOIN {$tables['nodes_hierarchy']} SN ON SN.id = RS.id
             JOIN {$tables['nodes_hierarchy']} NHV ON NHV.parent_id = R.id
             JOIN {$tables['req_versions']} RV ON RV.id = NHV.id
              AND RV.version = (SELECT MAX(RV2.version) FROM {$tables['req_versions']} RV2
                                JOIN {$tables['nodes_hierarchy']} NHV2 ON NHV2.id = RV2.id
                                WHERE NHV2.parent_id = R.id)
             WHERE RS.testproject_id = " . intval($tproject_id) .
            ' ORDER BY RS.doc_id, R.req_doc_id');

        if (!is_array($reqRows) || count($reqRows) === 0) {
            return [
                'status' => 'ok',
                'requirements_enabled' => true,
                'summary' => ['total' => 0, 'covered' => 0, 'partial' => 0,
                              'uncovered' => 0, 'orphan_reqs' => 0,
                              'plan_tcs' => 0, 'plan_linked_tcs' => 0,
                              'orphan_tcs' => 0],
                'rows' => [],
                'orphan_tcs' => [],
                'builds' => $builds,
                'platforms' => $platformSet,
            ];
        }

        $reqIds = array_map(function ($r) { return intval($r['id']); }, $reqRows);
        $reqIdSql = implode(',', $reqIds);

        // req_coverage links (active) for the requirement set
        $linkRows = $db->get_recordset(
            "SELECT RC.req_id, RC.testcase_id, RC.tcversion_id, RC.link_status, RC.is_active,
                    NH.name AS tc_name, TV.tc_external_id, TV.version AS tc_version,
                    PN.name AS suite_name
             FROM {$tables['req_coverage']} RC
             JOIN {$tables['nodes_hierarchy']} NH ON NH.id = RC.testcase_id
             JOIN {$tables['tcversions']} TV ON TV.id = RC.tcversion_id
             LEFT JOIN {$tables['nodes_hierarchy']} PN ON PN.id = NH.parent_id
             WHERE RC.is_active = 1 AND RC.req_id IN ($reqIdSql)");

        $linksByReq = array();
        foreach ((array)$linkRows as $row) {
            $rid = intval($row['req_id']);
            if (!isset($linksByReq[$rid])) {
                $linksByReq[$rid] = array();
            }
            $linksByReq[$rid][] = [
                'testcase_id' => intval($row['testcase_id']),
                'tcversion_id' => intval($row['tcversion_id']),
                'link_status' => intval($row['link_status']),
                'is_active' => intval($row['is_active']),
                'tc_name' => (string)$row['tc_name'],
                'tc_external_id' => intval($row['tc_external_id']),
                'tc_version' => intval($row['tc_version']),
                'suite_name' => (string)$row['suite_name'],
            ];
        }

        // Plan-assigned test case versions (platform rows for the same version
        // collapse into one entry - the matrix is requirement centric).
        $planTcvs = array();
        $ptcv = $db->get_recordset(
            'SELECT DISTINCT TPTV.tcversion_id FROM ' . $tables['testplan_tcversions'] . ' TPTV' .
            ' WHERE TPTV.testplan_id = ' . intval($tplan_id));
        foreach ((array)$ptcv as $row) {
            $planTcvs[intval($row['tcversion_id'])] = true;
        }

        // Latest execution status per plan tcversion on the filter.
        // MAX(id) is the deterministic 'latest recorded' key (executions are
        // append-only, id increases with time).
        $execRows = $db->get_recordset(
            "SELECT E.tcversion_id, E.status FROM {$tables['executions']} E
             WHERE E.testplan_id = " . intval($tplan_id) . $buildWhere . $platformWhere . '
               AND E.id = (SELECT MAX(E2.id) FROM ' . $tables['executions'] . ' E2
                           WHERE E2.testplan_id = E.testplan_id
                             AND E2.tcversion_id = E.tcversion_id'
                . ($buildFilter > 0 ? ' AND E2.build_id = ' . intval($buildFilter) : '')
                . ($platformFilter > 0 ? ' AND E2.platform_id = ' . intval($platformFilter) : '') . ')');
        $execByTcv = array();
        foreach ((array)$execRows as $row) {
            $execByTcv[intval($row['tcversion_id'])] = (string)$row['status'];
        }

        // Defect count per requirement on the filter (execution_bugs via
        // executions of the requirement's linked test cases).
        $defRows = $db->get_recordset(
            "SELECT RC.req_id, COUNT(DISTINCT EB.bug_id) AS defects
             FROM {$tables['execution_bugs']} EB
             JOIN {$tables['executions']} E ON E.id = EB.execution_id
             JOIN {$tables['req_coverage']} RC ON RC.tcversion_id = E.tcversion_id
              AND RC.is_active = 1
             WHERE E.testplan_id = " . intval($tplan_id) . $buildWhere . $platformWhere .
            ' AND RC.req_id IN (' . $reqIdSql . ')
             GROUP BY RC.req_id');
        $defectsByReq = array();
        foreach ((array)$defRows as $row) {
            $defectsByReq[intval($row['req_id'])] = intval($row['defects']);
        }

        // Orphan test cases: in plan, active req_coverage link NOT present.
        // tcversion nodes are children of the testcase node in nodes_hierarchy
        // (TV.id == version node id), so names need a two-hop walk.
        $orphanRows = $db->get_recordset(
            "SELECT TV.id AS tcversion_id, NTC.name AS tc_name, TV.tc_external_id, TV.version AS tc_version,
                    PN.name AS suite_name
             FROM {$tables['testplan_tcversions']} TPTV
             JOIN {$tables['tcversions']} TV ON TV.id = TPTV.tcversion_id
             JOIN {$tables['nodes_hierarchy']} NTC ON NTC.id = TV.id
             LEFT JOIN {$tables['nodes_hierarchy']} PN ON PN.id = NTC.parent_id
             WHERE TPTV.testplan_id = " . intval($tplan_id) .
            ' AND NOT EXISTS (SELECT 1 FROM ' . $tables['req_coverage'] . ' RC
                              WHERE RC.tcversion_id = TPTV.tcversion_id AND RC.is_active = 1)
             ORDER BY TV.tc_external_id');
        $orphanTcs = array();
        foreach ((array)$orphanRows as $row) {
            $orphanTcs[] = [
                'tcversion_id' => intval($row['tcversion_id']),
                'tc_external_id' => intval($row['tc_external_id']),
                'tc_name' => (string)$row['tc_name'],
                'tc_version' => intval($row['tc_version']),
                'suite_name' => (string)$row['suite_name'],
            ];
        }

        // Build the matrix rows.
        $summary = ['total' => 0, 'covered' => 0, 'partial' => 0, 'uncovered' => 0,
                    'orphan_reqs' => 0, 'plan_tcs' => count($planTcvs),
                    'plan_linked_tcs' => 0, 'orphan_tcs' => count($orphanTcs)];
        $rows = array();

        foreach ($reqRows as $req) {
            $rid = intval($req['id']);
            $links = isset($linksByReq[$rid]) ? $linksByReq[$rid] : array();
            $isOrphan = count($links) === 0;

            $inPlan = 0;
            $executed = 0;
            $passed = 0;
            $linkedTcs = array();
            foreach ($links as $link) {
                $tcv = $link['tcversion_id'];
                $inPlanFlag = isset($planTcvs[$tcv]);
                if ($inPlanFlag) {
                    $inPlan++;
                    $summary['plan_linked_tcs']++;
                }
                $status = '';
                if (isset($execByTcv[$tcv])) {
                    $status = $execByTcv[$tcv];
                    $executed++;
                    if ($status === 'p') {
                        $passed++;
                    }
                }
                $linkedTcs[] = [
                    'testcase_id' => $link['testcase_id'],
                    'tcversion_id' => $tcv,
                    'tc_external_id' => $link['tc_external_id'],
                    'name' => $link['tc_name'],
                    'version' => $link['tc_version'],
                    'suite' => $link['suite_name'],
                    'in_plan' => $inPlanFlag,
                    'exec_status' => $status,
                ];
            }

            if ($inPlan === 0 || $executed === 0) {
                $covStatus = 'uncovered';
            } elseif ($passed === $executed) {
                $covStatus = 'covered';
            } else {
                $covStatus = 'partial';
            }

            $summary['total']++;
            $summary[$covStatus]++;
            if ($isOrphan) {
                $summary['orphan_reqs']++;
            }

            $rows[] = [
                'req_id' => $rid,
                'doc_id' => (string)$req['req_doc_id'],
                'title' => (string)$req['title'],
                'spec_doc_id' => (string)$req['spec_doc_id'],
                'spec_title' => (string)$req['spec_title'],
                'req_type' => (string)$req['req_type'],
                'req_status' => (string)$req['req_status'],
                'expected_coverage' => intval($req['expected_coverage']),
                'linked_count' => count($links),
                'in_plan_count' => $inPlan,
                'executed_count' => $executed,
                'passed_count' => $passed,
                'coverage_status' => $covStatus,
                'orphan' => $isOrphan,
                'defects' => isset($defectsByReq[$rid]) ? $defectsByReq[$rid] : 0,
                'linked_tcs' => $linkedTcs,
            ];
        }

        return [
            'status' => 'ok',
            'requirements_enabled' => true,
            'tplan_id' => $tplan_id,
            'tproject_id' => $tproject_id,
            'tplan_name' => $planName,
            'tproject_name' => $projectName,
            'build_id' => $buildFilter,
            'platform_id' => $platformFilter,
            'builds' => $builds,
            'platforms' => $platformSet,
            'summary' => $summary,
            'rows' => $rows,
            'orphan_tcs' => $orphanTcs,
        ];
    }

    http_response_code(400);
    return ['status' => 'error', 'message' => 'Unknown action'];
};

try {
    $action = isset($_GET['action']) ? $_GET['action'] : '';
    if ($action === 'context' || $action === 'matrix') {
        echo json_encode($bff($action));
    } else {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Unknown or missing action']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server error']);
}