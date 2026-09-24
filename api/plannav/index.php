<?php
/**
 * Test Plan Navigator BFF API
 * URL: /api/plannav/
 * Plain PHP, no framework, no compilation
 *
 * Modern twin of the legacy Test Plan Navigator frames
 *   lib/plan/planTCNavigator.php            (feature: planUpdateTC / test_urgency /
 *                                            tc_exec_assignment)
 *   lib/plan/planAddTCNavigator.php         (feature: planAddTC / planRemoveTC,
 *                                            group-by mode_test_suite | mode_req_coverage)
 * and of tlTestCaseFilterControl->build_tree_menu() which produced the JS
 * navigator tree in 1.9.20.
 *
 * The legacy navigators were pure plumbing: they rendered the left-pane tree +
 * action buttons and then load the workframe controller. This BFF supplies the
 * same context (accessible test plans, suite tree with deep linked/undlinked
 * counts, requirement coverage tree, build selector) and the action deep links
 * to the already-modernized plan screens, so the hub (gui/templates/plans/
 * planNav.html) can forward tproject_id/tplan_id exactly like 1.9.20 did.
 *
 * Rights: legacy navigators had no explicit page right (testlinkInitPage only);
 * the action screens gate themselves. Same contract here: any authenticated
 * user with at least one accessible test plan may load the hub; the per-feature
 * flags mirror the plan screens' own gates
 *   planAddTC      -> testplan_planning                       (legacy planAddTC.php)
 *   planUpdateTC   -> testplan_planning                       (legacy planUpdateTC.php)
 *   test_urgency   -> testplan_planning                       (legacy planUrgency.php)
 *   tc_exec_assignment -> exec_assign_testcases               (legacy tc_exec_assignment.php)
 *
 * Hardening (Refs #1573): all routes are wrapped in try/catch -> 500 JSON
 * (the legacy db layer throws on XHR query failures, database.class.php), known
 * routes answer 405 JSON for non-GET verbs, the keyword_id filter is applied to
 * BOTH direct totals and deep linked counts, the default plan honors the
 * session plan (legacy navigation-frame parity), and the /suites deep
 * aggregate runs in a single bottom-up pass (O(N), not O(N x depth)).
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

try {

// The connect/auth phase faults too (DB down, session roll, bad user row):
// it must produce the same JSON contract as any route, never raw HTML/echo.
$db = new database(DB_TYPE);
ob_start();
doDBConnect($db);
if (ob_get_length() !== false && ob_get_length() > 0) {
    // doDBConnect() echoes the raw DBMS message on failure (common.php) —
    // swallow it here and answer 500 JSON instead.
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Internal error']);
    exit;
}
ob_end_clean();

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

$path = $_SERVER['PATH_INFO'] ?? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = preg_replace('#^/api/plannav(/index\.php)?#', '', $path);
$path = '/' . trim($path, '/');
$method = $_SERVER['REQUEST_METHOD'];
$segments = array_values(array_filter(explode('/', $path)));

// Known routes are strict GET endpoints; any other verb is a 405 before
// dispatch. Placed after the helper definitions: try-block functions are
// defined lazily, so out() must exist before this guard can call it.
if ($method !== 'GET' && count($segments) === 1 &&
    in_array($segments[0], ['init', 'suites', 'reqs'], true)) {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

function out($data) { echo json_encode($data); exit; }
function getParam($key, $default = null) { return $_GET[$key] ?? $default; }

$tplanMgr = new testplan($db);
$tprojectMgr = new testproject($db);

function nodeTypes($db) {
    static $cache = null;
    if (!is_null($cache)) {
        return $cache;
    }
    $ntTable = tlObject::getDBTables()['node_types'];
    $sql = "SELECT id, description FROM {$ntTable} " .
           "WHERE description IN ('testsuite','testcase')";
    $rows = $db->fetchRowsIntoMap($sql, 'description');
    $cache = [
        'testsuite' => intval($rows['testsuite']['id'] ?? 0),
        'testcase' => intval($rows['testcase']['id'] ?? 0),
    ];
    return $cache;
}

// context + rights for one test project / test plan (+ accessible plan list)
function navContext($user, $db, $tprojectMgr, $tplanMgr, $tprojectId, $tplanId) {
    $plans = [];
    $rows = $user->getAccessibleTestPlans($db, $tprojectId, null,
        ['output' => 'mapfull', 'active' => null]);
    if (!is_null($rows)) {
        foreach ($rows as $r) {
            $pid = intval($r['id']);
            $plans[] = [
                'id' => $pid,
                'name' => (string)$r['name'],
                'active' => intval($r['active']),
            ];
        }
    }
    if (count($plans) === 0) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }
    if ($tplanId <= 0) {
        // Legacy navigation-frame parity: honor the session's current plan
        // (doGetTestPlanID semantics) when the request carries no plan id;
        // fall back to the first accessible plan.
        $tplanId = intval($_SESSION['testplanID'] ?? 0);
        $inPlans = false;
        if ($tplanId > 0) {
            foreach ($plans as $p) {
                if ($p['id'] === $tplanId) {
                    $inPlans = true;
                    break;
                }
            }
        }
        if (!$inPlans) {
            $tplanId = intval($plans[0]['id']);
        }
    } else {
        $own = $tplanMgr->get_by_id($tplanId);
        if (!$own || intval($own['testproject_id']) != $tprojectId) {
            http_response_code(404);
            out(['status' => 'error', 'message' => 'Test plan not found']);
        }
    }
    $canPlan = (bool)$user->hasRight($db, 'testplan_planning',
        $tprojectId, $tplanId);
    $canAssign = (bool)$user->hasRight($db, 'exec_assign_testcases',
        $tprojectId, $tplanId);
    $canViewEvents = (bool)$user->hasRight($db, 'mgt_view_events',
        $tprojectId, $tplanId);
    return [
        'tplan_id' => $tplanId,
        'plans' => $plans,
        'rights' => [
            'canPlan' => $canPlan,
            'canAssign' => $canAssign,
            'canUrgency' => $canPlan,
            'canUpdateTC' => $canPlan,
            'canViewEvents' => $canViewEvents,
        ],
    ];
}

// ---------------------------------------------------------------------------
// GET /init?tproject_id=N[&tplan_id=M]
// navigator context: plan picker + rights + action deep links + build selector
// ---------------------------------------------------------------------------
if ($method === 'GET' && count($segments) === 1 &&
    $segments[0] === 'init') {
    $tprojectId = intval(getParam('tproject_id', 0));
    if ($tprojectId <= 0) {
        $tprojectId = intval($_SESSION['testprojectID'] ?? 0);
    }
    if ($tprojectId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' =>
            'tproject_id is required']);
    }
    $ctx = navContext($user, $db, $tprojectMgr, $tplanMgr,
        $tprojectId, intval(getParam('tplan_id', 0)));
    $tplanId = $ctx['tplan_id'];
    $rights = $ctx['rights'];

    // Persist the validated plan context (legacy navigation frame wrote the
    // session at this point too), so the next param-less load of the hub keeps
    // the last selected plan.
    $_SESSION['testprojectID'] = $tprojectId;
    $_SESSION['testplanID'] = $tplanId;

    $builds = $tplanMgr->get_builds_for_html_options($tplanId,
        testplan::GET_ACTIVE_BUILD, testplan::GET_OPEN_BUILD);
    if (is_null($builds)) {
        $builds = new stdClass();
    }
    $bkeys = array_keys((array)$builds);
    $defaultBuild = count($bkeys) ? intval(end($bkeys)) : 0;

    $base = '/gui/templates/';
    $q = "testproject_id={$tprojectId}&testplan_id={$tplanId}";
    // navigator_title parity: legacy titles + legacy menu targets (modernized)
    $actions = [
        'addremove' => $rights['canPlan'] ?
            ['url' => $base . 'plans/planAddTCView.html', 'params' => $q] : null,
        'updateTC' => $rights['canUpdateTC'] ?
            ['url' => $base . 'plans/planUpdateTC.html', 'params' => $q] : null,
        'urgency' => $rights['canUrgency'] ?
            ['url' => $base . 'plans/testUrgency.html', 'params' => $q] : null,
        'assignment' => $rights['canAssign'] ?
            ['url' => $base . 'execute/tcExecAssignment.html', 'params' => $q] : null,
        'eventviewer' => $rights['canViewEvents'] ?
            ['url' => $base . 'eventviewer/eventviewer.html', 'params' => $q] : null,
    ];

    $tproject = $tprojectMgr->get_by_id($tprojectId);
    $tplanInfo = $tplanMgr->get_by_id($tplanId);
    out([
        'status' => 'ok',
        'tproject_id' => $tprojectId,
        'tproject_name' => is_null($tproject) ? '' : (string)$tproject['name'],
        'tplan_id' => $tplanId,
        'tplan_name' => is_array($tplanInfo) ? (string)$tplanInfo['name'] : '',
        'plans' => $ctx['plans'],
        'builds' => $builds,
        'default_build_id' => $defaultBuild,
        'rights' => $rights,
        'actions' => $actions,
    ]);
}

// ---------------------------------------------------------------------------
// GET /suites?tproject_id=&tplan_id=[&container_id=][&keyword_id=]
// suite subtree (group-by mode_test_suite) with DEEP total/linked quantities
// ---------------------------------------------------------------------------
if ($method === 'GET' && count($segments) === 1 &&
    $segments[0] === 'suites') {
    $tprojectId = intval(getParam('tproject_id', 0));
    $tplanId = intval(getParam('tplan_id', 0));
    if ($tprojectId <= 0 || $tplanId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' =>
            'tproject_id and tplan_id are required']);
    }
    navContext($user, $db, $tprojectMgr, $tplanMgr, $tprojectId, $tplanId);

    $TLT = tlObject::getDBTables();
    $nh = $TLT['nodes_hierarchy'];
    $nt = nodeTypes($db);
    // The hub shows the whole project suite subtree at once (flat deep tree), so
    // the tree is always anchored at the owning test project. container_id is a
    // legacy-drill param only; ignoring it also closes a cross-project IDOR
    // (an arbitrary nodes_hierarchy.id must never seed the CTE root).
    $rootId = $tprojectId;

    $sql = "WITH RECURSIVE subtree AS ( " .
        " SELECT NH.id AS node_id FROM {$nh} NH WHERE NH.id = {$rootId} " .
        " UNION ALL " .
        " SELECT NH.id FROM {$nh} NH " .
        " JOIN subtree S ON NH.parent_id = S.node_id " .
        " WHERE NH.node_type_id = {$nt['testsuite']} ) " .
        " SELECT NH.id, NH.name, NH.parent_id, NH.node_order " .
        " FROM {$nh} NH JOIN subtree S ON NH.id = S.node_id " .
        " WHERE NH.node_type_id = {$nt['testsuite']} " .
        " ORDER BY NH.parent_id, NH.node_order, NH.name";
    $suiteRows = $db->fetchRowsIntoMap($sql, 'id');

    $suites = [];
    $suiteIds = [];
    $childrenMap = [];
    if (!is_null($suiteRows) && count($suiteRows) > 0) {
        $suiteIds = array_map('intval', array_keys($suiteRows));
        foreach ($suiteRows as $sid => $s) {
            $sid = intval($sid);
            $suites[$sid] = [
                'id' => $sid,
                'name' => (string)$s['name'],
                'parent_id' => intval($s['parent_id']),
                'total_qty' => 0,
                'linked_qty' => 0,
            ];
            $childrenMap[intval($s['parent_id'])][] = $sid;
        }
    }

    if (count($suiteIds) > 0) {
        $idList = implode(',', $suiteIds);
        $kwList = '';
        $kwId = intval(getParam('keyword_id', 0));
        if ($kwId > 0) {
            $kwList = $kwId;
        }
        $kwJoin = $kwList !== '' ?
            " JOIN {$TLT['testcase_keywords']} TK " .
            " ON TK.testcase_id = NHTC.id " .
            " AND TK.keyword_id IN ({$kwList}) " : "";

        // direct totals (keyword-filtered)
        $sql = "SELECT NHTC.parent_id AS tsuite_id, COUNT(*) AS qty " .
            " FROM {$nh} NHTC {$kwJoin} " .
            " WHERE NHTC.node_type_id = {$nt['testcase']} " .
            " AND NHTC.parent_id IN ({$idList}) GROUP BY NHTC.parent_id";
        $rows = $db->fetchRowsIntoMap($sql, 'tsuite_id');
        if (!is_null($rows)) {
            foreach ($rows as $sid => $r) {
                $suites[intval($sid)]['total_qty'] = intval($r['qty']);
            }
        }

        // deep linked distinct testcases (SAME keyword filter as the direct
        // totals, so deep linked quantities can never exceed deep totals)
        $tpv = $TLT['testplan_tcversions'];
        $sql = "SELECT COUNT(DISTINCT NHTC.id) AS qty, NHTC.parent_id AS tsuite_id " .
            " FROM {$tpv} TPTCV " .
            " JOIN {$nh} NHTCV ON NHTCV.id = TPTCV.tcversion_id " .
            " JOIN {$nh} NHTC ON NHTC.id = NHTCV.parent_id " .
            " {$kwJoin} " .
            " WHERE TPTCV.testplan_id = {$tplanId} " .
            " AND NHTC.node_type_id = {$nt['testcase']} " .
            " AND NHTC.parent_id IN ({$idList}) GROUP BY NHTC.parent_id";
        $rows = $db->fetchRowsIntoMap($sql, 'tsuite_id');
        if (!is_null($rows)) {
            foreach ($rows as $sid => $r) {
                $suites[intval($sid)]['linked_qty'] = intval($r['qty']);
            }
        }

        // aggregate deep sums in ONE bottom-up pass (children before parents):
        // each suite's subtree is folded into its ancestors exactly once, so the
        // whole forest costs O(N + edges) instead of the legacy per-suite
        // re-walk (O(N x depth) on deep chains).
        $deep = [];
        foreach ($suites as $sid => $s) {
            $deep[$sid] = ['total' => $s['total_qty'], 'linked' => $s['linked_qty']];
        }
        $tops = [];
        foreach ($suites as $sid => $s) {
            if (!isset($suites[$s['parent_id']])) {
                $tops[] = $sid; // parent is not part of the suite forest
            }
        }
        $order = [];
        foreach ($tops as $top) {
            $stack = [$top];
            while ($stack) {
                $n = array_pop($stack);
                if ($n > 0) {
                    $stack[] = -$n;
                    foreach ($childrenMap[$n] ?? [] as $ch) {
                        $stack[] = $ch;
                    }
                } else {
                    $order[] = -$n;
                }
            }
        }
        $out = [];
        foreach ($order as $nid) {
            foreach ($childrenMap[$nid] ?? [] as $ch) {
                $deep[$nid]['total'] += $deep[$ch]['total'];
                $deep[$nid]['linked'] += $deep[$ch]['linked'];
            }
        }
        foreach ($suites as $sid => $s) {
            $suites[$sid]['deep_total_qty'] = $deep[$sid]['total'];
            $suites[$sid]['deep_linked_qty'] = $deep[$sid]['linked'];
            $out[] = $suites[$sid];
        }
    } else {
        $out = [];
    }

    out(['status' => 'ok', 'items' => $out]);
}

// ---------------------------------------------------------------------------
// GET /reqs?tproject_id=&tplan_id=
// requirement coverage tree (group-by mode_req_coverage): req-spec subtree with
// per-requirement linked / covered-in-plan quantities
// ---------------------------------------------------------------------------
if ($method === 'GET' && count($segments) === 1 &&
    $segments[0] === 'reqs') {
    $tprojectId = intval(getParam('tproject_id', 0));
    $tplanId = intval(getParam('tplan_id', 0));
    if ($tprojectId <= 0 || $tplanId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' =>
            'tproject_id and tplan_id are required']);
    }
    navContext($user, $db, $tprojectMgr, $tplanMgr, $tprojectId, $tplanId);

    $TLT = tlObject::getDBTables();
    $nh = $TLT['nodes_hierarchy'];
    $rs = $TLT['req_specs'];
    $rc = $TLT['req_coverage'];
    $tpv = $TLT['testplan_tcversions'];
    $reqTable = $TLT['requirements'];

    // accessible req-spec subtree of this project
    $sql = "SELECT RS.id, NH.name, NH.parent_id, NH.node_order " .
        " FROM {$rs} RS JOIN {$nh} NH ON NH.id = RS.id " .
        " WHERE RS.testproject_id = {$tprojectId} " .
        " ORDER BY NH.parent_id, NH.node_order, NH.name";
    $specRows = $db->fetchRowsIntoMap($sql, 'id');

    $specs = [];
    $specIds = [];
    $specChildren = [];
    if (!is_null($specRows) && count($specRows) > 0) {
        $specIds = array_map('intval', array_keys($specRows));
        foreach ($specRows as $sid => $s) {
            $sid = intval($sid);
            $specs[$sid] = [
                'id' => $sid,
                'name' => (string)$s['name'],
                'parent_id' => intval($s['parent_id']),
            ];
            $specChildren[$sid] = [];
        }
    }

    $items = [];
    if (count($specIds) > 0) {
        $idList = implode(',', $specIds);

        // requirements inside these specs
        $sql = "SELECT REQ.id, REQ.srs_id, NH.name, NH.node_order " .
            " FROM {$reqTable} REQ JOIN {$nh} NH ON NH.id = REQ.id " .
            " WHERE REQ.srs_id IN ({$idList}) " .
            " ORDER BY NH.parent_id, NH.node_order, NH.name";
        $reqRows = $db->fetchRowsIntoMap($sql, 'id');
        $reqBySpec = [];
        if (!is_null($reqRows)) {
            foreach ($reqRows as $rid => $r) {
                $rid = intval($rid);
                $specId = intval($r['srs_id']);
                $reqBySpec[$specId][$rid] = [
                    'id' => $rid,
                    'name' => (string)$r['name'],
                ];
            }
        }

        // per-requirement: linked TC versions + covered in this plan
        $allReqIds = [];
        foreach ($reqBySpec as $rsid => $reqs) {
            foreach ($reqs as $rid => $rq) {
                $allReqIds[intval($rid)] = intval($rid);
            }
        }
        $covMap = [];
        if (count($allReqIds) > 0) {
            $ridList = implode(',', $allReqIds);
            $sql = "SELECT RC.req_id, " .
                " COUNT(DISTINCT RC.tcversion_id) AS lnk_qty, " .
                " COUNT(DISTINCT CASE WHEN TPV.tcversion_id IS NOT NULL " .
                "   THEN RC.tcversion_id END) AS cov_qty " .
                " FROM {$rc} RC " .
                " LEFT JOIN (SELECT DISTINCT tcversion_id FROM {$tpv} " .
                "            WHERE testplan_id = {$tplanId}) TPV " .
                "   ON TPV.tcversion_id = RC.tcversion_id " .
                " WHERE RC.req_id IN ({$ridList}) " .
                " GROUP BY RC.req_id";
            $rows = $db->fetchRowsIntoMap($sql, 'req_id');
            if (!is_null($rows)) {
                foreach ($rows as $rid => $r) {
                    $covMap[intval($rid)] = [
                        'linked_qty' => intval($r['lnk_qty']),
                        'covered_qty' => intval($r['cov_qty']),
                    ];
                }
            }
        }

        // emit spec nodes + requirement leaves, compute deep aggregates
        $out = [];
        foreach ($specs as $sid => $s) {
            $specTotal = 0;
            $specCovered = 0;
            $leafReqs = [];
            foreach ($reqBySpec[$sid] ?? [] as $rid => $rq) {
                $c = $covMap[$rid] ?? ['linked_qty' => 0, 'covered_qty' => 0];
                $leafReqs[] = [
                    'id' => $rid,
                    'name' => $rq['name'],
                    'linked_qty' => $c['linked_qty'],
                    'covered_qty' => $c['covered_qty'],
                ];
                $specTotal += 1;
                if ($c['covered_qty'] > 0) {
                    $specCovered += 1;
                }
            }
            $out[] = [
                'id' => $sid,
                'name' => $s['name'],
                'parent_id' => $s['parent_id'],
                'total_qty' => $specTotal,
                'covered_qty' => $specCovered,
                'requirements' => $leafReqs,
            ];
        }
        out(['status' => 'ok', 'items' => $out]);
    } else {
        out(['status' => 'ok', 'items' => []]);
    }
}

// Known routes only accept GET (handled up front); anything else is a 404.
http_response_code(404);
out(['status' => 'error', 'message' => 'Not found']);

} catch (Throwable $e) {
    // DB errors surface as exceptions for XHR clients (database.class.php) and
    // carry their own ERROR event row; any other Throwable is logged here so it
    // shows in the Event Viewer, then the JSON contract is kept (Refs #1573).
    try {
        tLog('plannav BFF: ' . get_class($e) . ' - ' . $e->getMessage(),
            'ERROR', 'plannav');
    } catch (Throwable $ignored) {
        // DB may be unreachable — nothing to attach the log row to.
    }
    http_response_code(500);
    out(['status' => 'error', 'message' => 'Internal error']);
}