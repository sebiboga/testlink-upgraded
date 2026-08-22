<?php
/**
 * Test Plans BFF API
 * URL: /api/plans/
 * Plain PHP, no framework, no compilation
 *
 * Mirrors lib/plan/planView.php + the planView-related do_actions of
 * lib/plan/planEdit.php (TestLink 1.9.20 behavior).
 *
 * Rights split (same as the legacy screens):
 *   view   -> mgt_testplan_create (legacy planView.php checkRights())
 *   manage -> mgt_testplan_create (activate/inactivate/delete)
 *   per-row assign roles link -> testplan_user_role_assignment evaluated
 *   exactly like planView.php: tplan role > tproject role > global role.
 *
 * Create/Edit/Export/Import/AssignRoles/Execute stay links to their own
 * legacy controllers (separate screens in 1.9.20 too), the URLs are served
 * by this API so the front end never hardcodes them.
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
require_once('date_api.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();


header('Content-Type: application/json');

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

$path = $_SERVER['PATH_INFO'] ?? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = preg_replace('#^/api/plans(/index\.php)?#', '', $path);
$path = '/' . trim($path, '/');
$method = $_SERVER['REQUEST_METHOD'];
$segments = array_values(array_filter(explode('/', $path)));

function out($data) { echo json_encode($data); exit; }
function getParam($key, $default = null) { return $_GET[$key] ?? $default; }
function getBody() { return json_decode(file_get_contents('php://input'), true) ?? []; }

function needTprojectId() {
    $id = intval($_GET['tproject_id'] ?? ($_POST['tproject_id'] ?? 0));
    if ($id <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test project id']);
    }
    return $id;
}

// Legacy planView.php checkRights(): only mgt_testplan_create holders reach
// the screen at all; every mutation lives behind it as well.
function canManage($user, $db, $tproject_id) {
    return $user->hasRight($db, 'mgt_testplan_create', $tproject_id);
}

/**
 * Same per-plan role resolution as planView.php lines ~106-130:
 * explicit test plan role wins, then test project role, then global role.
 */
function rowAssignRolesRight($user, $db, $tproject_id, $hasRole) {
    $roleObj = null;
    if ($hasRole > 0 && !is_null($user->tplanRoles) &&
        isset($user->tplanRoles[$hasRole])) {
        $roleObj = $user->tplanRoles[$hasRole];
    } else if (!is_null($user->tprojectRoles) &&
               isset($user->tprojectRoles[$tproject_id])) {
        $roleObj = $user->tprojectRoles[$tproject_id];
    }
    if (is_null($roleObj)) {
        $roleObj = $user->globalRole;
    }
    return (bool)$roleObj->hasRight('testplan_user_role_assignment');
}

/**
 * Legacy action URL builder - mirrors testplan::getViewActions() so the
 * modern list keeps working with the still-legacy sub screens
 * (planEdit/planExport/planImport/usersAssign/frmWorkArea).
 * URLs are root-absolute: the HTML screen lives under /gui/templates/plans/
 * so relative paths would resolve against the wrong directory.
 */
function viewActions($tproject_id, $tplan_id = 0) {
    $ent = "tproject_id=" . intval($tproject_id) . "&tplan_id=" . intval($tplan_id);
    $entProj = "tproject_id=" . intval($tproject_id);
    return [
        'managerURL'    => '/lib/plan/planEdit.php?' . $ent,
        'createAction'  => '/lib/plan/planEdit.php?do_action=create&' . $ent,
        'editAction'    => '/lib/plan/planEdit.php?do_action=edit&' . $ent . '&itemID=',
        'exportAction'  => '/lib/plan/planExport.php?' . $ent . '&tplan_id=',
        'importAction'  => '/lib/plan/planImport.php?' . $ent . '&tplan_id=',
        'assignRolesAction' =>
            '/lib/usermanagement/usersAssign.php?featureType=testplan&' .
            $ent . '&featureID=',
        'gotoExecuteAction' =>
            '/lib/general/frmWorkArea.php?feature=executeTest&' . $entProj . '&tplan_id=',
    ];
}

$tplanMgr = null;

// ---------------------------------------------------------------------------
// GET /?tproject_id=N - accessible plans + counts + per-row rights
// Same data set as legacy planView.php initializeGui().
// ---------------------------------------------------------------------------
if ($method === 'GET' && count($segments) === 0) {
    $tproject_id = needTprojectId();
    if (!canManage($user, $db, $tproject_id)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }

    // project name fallback like legacy initializeGui() (session may be empty)
    $tproject_name = trim((string)($_SESSION['testprojectName'] ?? ''));
    if ($tproject_name === '') {
        $tproject_name = testproject::getName($db, $tproject_id);
    }

    $rows = $user->getAccessibleTestPlans($db, $tproject_id, null,
        ['output' => 'mapfull', 'active' => null]);

    $drawPlatformQtyColumn = false;
    $tcaseQty = [];
    $buildQty = [];
    if (!is_null($rows) && count($rows) > 0) {
        $tplanMgr = new testplan($db);
        $tplanMgr->platform_mgr->setTestProjectID($tproject_id);
        $dummy = $tplanMgr->platform_mgr->testProjectCount();
        $drawPlatformQtyColumn =
            $dummy[$tproject_id]['platform_qty'] > 0;

        $tplanSet = array_keys($rows);
        $dummy = $tplanMgr->count_testcases($tplanSet, null,
            ['output' => 'groupByTestPlan']);
        $buildQty = $tplanMgr->get_builds($tplanSet, null, null,
            ['getCount' => true]);

        foreach ($tplanSet as $idk) {
            $tcaseQty[$idk] = isset($dummy[$idk]['qty']) ? intval($dummy[$idk]['qty']) : 0;
            $buildQty[$idk] = isset($buildQty[$idk]['build_qty']) ?
                intval($buildQty[$idk]['build_qty']) : 0;
            if ($drawPlatformQtyColumn) {
                $plat = $tplanMgr->getPlatforms($idk);
                $rows[$idk]['platform_qty'] = is_null($plat) ? 0 : count($plat);
            }
        }
    }

    $items = [];
    if (!is_null($rows)) {
        foreach ($rows as $r) {
            $idk = intval($r['id']);
            $it = [
                'id' => $idk,
                'name' => (string)$r['name'],
                'notes' => (string)$r['notes'],
                'active' => intval($r['active']),
                'is_public' => intval($r['is_public']),
                'api_key_present' => true,
                'tcase_qty' => $tcaseQty[$idk] ?? 0,
                'build_qty' => $buildQty[$idk] ?? 0,
            ];
            if ($drawPlatformQtyColumn) {
                $it['platform_qty'] = intval($r['platform_qty'] ?? 0);
            }
            $it['rights']['assign_roles'] =
                rowAssignRolesRight($user, $db, $tproject_id,
                    intval($r['has_role'] ?? 0));
            $items[] = $it;
        }
    }

    out([
        'status' => 'ok',
        'items' => $items,
        'tproject' => ['id' => $tproject_id, 'name' => $tproject_name],
        'draw_platform_qty_column' => $drawPlatformQtyColumn,
        'actions' => viewActions($tproject_id),
        'rights' => ['canManage' => (bool)canManage($user, $db, $tproject_id)],
    ]);
}

// ---------------------------------------------------------------------------
// PUT /{id}/active - single toggle (legacy do_action=setActive|setInactive)
// body: tproject_id, active=0|1
// ---------------------------------------------------------------------------
if ($method === 'PUT' && isset($segments[0]) && ctype_digit($segments[0]) &&
    isset($segments[1]) && $segments[1] === 'active') {
    $body = getBody();
    $tproject_id = intval($body['tproject_id'] ?? 0);
    if ($tproject_id <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test project id']);
    }
    if (!canManage($user, $db, $tproject_id)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }

    $id = intval($segments[0]);
    $tplanMgr = new testplan($db);

    // plan must exist and belong to the context project (legacy relies on
    // the caller passing valid ids; we verify ownership server side)
    $info = $tplanMgr->get_by_id($id);
    if (!$info || intval($info['testproject_id']) != $tproject_id) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Test plan not found']);
    }

    $active = !empty($body['active']) ? 1 : 0;
    $tplanMgr->setActiveField($id, $active);
    out(['status' => 'ok', 'id' => $id, 'active' => $active]);
}

// ---------------------------------------------------------------------------
// POST /bulk-active - bulk activate/inactivate via checkboxes
// (legacy do_action=setActiveBulk|setInactiveBulk)
// body: tproject_id, ids:[...], active=0|1
// ---------------------------------------------------------------------------
if ($method === 'POST' && count($segments) === 1 && $segments[0] === 'bulk-active') {
    $body = getBody();
    $tproject_id = intval($body['tproject_id'] ?? 0);
    if ($tproject_id <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test project id']);
    }
    if (!canManage($user, $db, $tproject_id)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }

    $ids = array_values(array_filter(array_map('intval',
        (array)($body['ids'] ?? [])), function ($v) { return $v > 0; }));
    if (count($ids) == 0) {
        http_response_code(422);
        out(['status' => 'error', 'message' => 'No test plan selected',
             'error_code' => 'NO_SELECTION']);
    }

    $active = !empty($body['active']) ? 1 : 0;
    $tplanMgr = new testplan($db);

    // keep only plans that really live inside the context project
    // (testplan::get_by_id() 2nd arg is an options array - passing
    // tproject_id there is silently ignored, so compare explicitly)
    $owned = [];
    foreach ($ids as $id) {
        $info = $tplanMgr->get_by_id($id);
        if ($info && intval($info['testproject_id']) == $tproject_id) {
            $owned[] = $id;
        }
    }
    if (count($owned) == 0) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Test plan not found']);
    }

    $tplanMgr->setActiveField($owned, $active);
    out(['status' => 'ok', 'updated' => count($owned), 'active' => $active]);
}

// ---------------------------------------------------------------------------
// DELETE /{id}?tproject_id=N - delete test plan (legacy do_action=do_delete)
// testplan::delete() cascades builds/executions/links/cfields attachments...
// Audit event identical to planEdit.php.
// ---------------------------------------------------------------------------
if ($method === 'DELETE' && isset($segments[0]) && ctype_digit($segments[0]) &&
    !isset($segments[1])) {
    $tproject_id = needTprojectId();
    if (!canManage($user, $db, $tproject_id)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }

    $id = intval($segments[0]);
    $tplanMgr = new testplan($db);
    $tplanInfo = $tplanMgr->get_by_id($id);
    if (!$tplanInfo || intval($tplanInfo['testproject_id']) != $tproject_id) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Test plan not found']);
    }

    $tplanMgr->delete($id);
    logAuditEvent(TLS("audit_testplan_deleted",
        testproject::getName($db, $tproject_id), $tplanInfo['name']),
        "DELETE", $id, "testplan");

    out(['status' => 'ok']);
}

// ===========================================================================
// planAddTC (Add / Remove Test Cases into Test Plan)
// Mirrors lib/plan/planAddTC.php (TestLink 1.9.20):
//   view/manage -> testplan_planning (checked on tproject+tplan context)
//   removing a linked version WITH executions additionally requires
//   testplan_unlink_executed_testcases (legacy $gui->can_remove_executed_testcases)
// Link structures match testplan::link_tcversions() / unlink_tcversions():
//   items[tcase_id][platform_id] = tcversion_id (+ 'tcversion'/'platform' maps)
// ===========================================================================

function planAddTcRights($user, $db, $tproject_id, $tplan_id) {
    return [
        'canPlan' => (bool)$user->hasRight($db, 'testplan_planning',
            $tproject_id, $tplan_id),
        'canRemoveExecuted' => (bool)$user->hasRight($db,
            'testplan_unlink_executed_testcases', $tproject_id, $tplan_id),
        'canAssignExecTask' => (bool)$user->hasRight($db,
            'exec_assign_testcases', $tproject_id, $tplan_id),
    ];
}

function planAddTcNeedCtx(&$body, &$tplanMgr) {
    $tproject_id = intval($body['tproject_id'] ?? ($_GET['tproject_id'] ?? 0));
    $tplan_id = intval($body['tplan_id'] ?? ($_GET['tplan_id'] ?? 0));
    if ($tproject_id <= 0 || $tplan_id <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' =>
            'tproject_id and tplan_id are required']);
    }
    $info = $tplanMgr->get_by_id($tplan_id);
    if (!$info || intval($info['testproject_id']) != $tproject_id) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Test plan not found']);
    }
    return [$tproject_id, $tplan_id, $info];
}

// keyword filter: comma separated keyword ids -> IN(...) list or ''
function planAddTcKeywordList() {
    $raw = trim((string)(getParam('keyword_id', $_POST['keyword_id'] ?? '')));
    if ($raw === '') {
        return '';
    }
    $ids = array_values(array_unique(array_filter(array_map('intval',
        explode(',', $raw)), function ($v) { return $v > 0; })));
    return count($ids) ? implode(',', $ids) : '';
}

function planAddTcNodeTypes($db) {
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

// ---------------------------------------------------------------------------
// GET /planaddtc/init?tproject_id=N[&tplan_id=M]
// picker data: accessible plans + rights + keyword/platform sets
// ---------------------------------------------------------------------------
if ($method === 'GET' && count($segments) === 2 &&
    $segments[0] === 'planaddtc' && $segments[1] === 'init') {
    $tproject_id = needTprojectId();
    $rights0 = planAddTcRights($user, $db, $tproject_id, 0);
    // screen entry needs the planning right on the PROJECT level context
    if (!$rights0['canPlan']) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }

    $tprojectMgr = new testproject($db);
    $tplanMgr = new testplan($db);
    $prefix = $tprojectMgr->getTestCasePrefix($tproject_id);
    $glueChar = config_get('testcase_cfg')->glue_character;

    $plans = [];
    $rows = $user->getAccessibleTestPlans($db, $tproject_id, null,
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

    $TLT = tlObject::getDBTables();
    $kwTable = $TLT['keywords'];
    $kwRows = $db->fetchRowsIntoMap("SELECT id, keyword FROM {$kwTable} " .
        "WHERE testproject_id = {$tproject_id} ORDER BY keyword", 'id');
    $keywords = [];
    if (!is_null($kwRows)) {
        foreach ($kwRows as $k) {
            $keywords[] = ['id' => intval($k['id']),
                'keyword' => (string)$k['keyword']];
        }
    }

    $tplan_id = intval(getParam('tplan_id', 0));
    $platforms = [];
    $builds = [];
    $testers = [];
    $canAssignExecTask = false;
    if ($tplan_id > 0) {
        $platSet = $tplanMgr->getPlatforms($tplan_id);
        if (!is_null($platSet)) {
            foreach ($platSet as $p) {
                $platforms[] = ['id' => intval($p['id']),
                    'name' => (string)$p['name']];
            }
        }
        $builds = $tplanMgr->get_builds_for_html_options($tplan_id,
            testplan::GET_ACTIVE_BUILD, testplan::GET_OPEN_BUILD);
        if (is_null($builds)) {
            $builds = new stdClass();
        }
    }

    $ctxRights = $tplan_id > 0 ?
        planAddTcRights($user, $db, $tproject_id, $tplan_id) : $rights0;
    // legacy header block: assign freshly added test cases to a tester
    if ($ctxRights['canAssignExecTask'] && $tplan_id > 0) {
        $testers = getTestersForHtmlOptions($db, $tplan_id,
            $tprojectMgr->get_by_id($tproject_id));
        if (is_null($testers)) {
            $testers = new stdClass();
        }
        // newest open build preselected like legacy init_build_selector()
        $bkeys = array_keys((array)$builds);
        $defaultBuild = count($bkeys) ? intval(end($bkeys)) : 0;
    } else {
        $defaultBuild = 0;
    }

    out([
        'status' => 'ok',
        'tproject' => [
            'id' => $tproject_id,
            'name' => testproject::getName($db, $tproject_id),
            'prefix' => (string)$prefix,
        ],
        'tplan_id' => $tplan_id,
        'plans' => $plans,
        'keywords' => $keywords,
        'platforms' => $platforms,
        'builds' => $builds,
        'testers' => $testers,
        'default_build_id' => $defaultBuild,
        'rights' => $ctxRights,
        'ext_id_glue' => (string)$glueChar,
    ]);
}

// ---------------------------------------------------------------------------
// GET /planaddtc/suites?tproject_id=&tplan_id=[&keyword_id=]
// suite subtree of the project (or ?container_id=) with DEEP total/linked qty
// ---------------------------------------------------------------------------
if ($method === 'GET' && count($segments) === 2 &&
    $segments[0] === 'planaddtc' && $segments[1] === 'suites') {
    $tplanMgr = new testplan($db);
    list($tproject_id, $tplan_id, $tplanInfo) =
        planAddTcNeedCtx($_GET, $tplanMgr);
    if (!planAddTcRights($user, $db, $tproject_id, $tplan_id)['canPlan']) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }
    $nt = planAddTcNodeTypes($db);
    $TLT = tlObject::getDBTables();
    $nh = $TLT['nodes_hierarchy'];
    $rootId = intval(getParam('container_id', $tproject_id));
    if ($rootId <= 0) {
        $rootId = $tproject_id;
    }

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
        $kwList = planAddTcKeywordList();
        $kwJoin = $kwList !== '' ?
            " JOIN {$TLT['testcase_keywords']} TK " .
            " ON TK.testcase_id = NHTC.id " .
            " AND TK.keyword_id IN ({$kwList}) " : "";

        // direct totals
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

        // deep linked distinct testcases
        $tpv = $TLT['testplan_tcversions'];
        $sql = "SELECT COUNT(DISTINCT NHTC.id) AS qty, NHTC.parent_id AS tsuite_id " .
            " FROM {$tpv} TPTCV " .
            " JOIN {$nh} NHTCV ON NHTCV.id = TPTCV.tcversion_id " .
            " JOIN {$nh} NHTC ON NHTC.id = NHTCV.parent_id " .
            " WHERE TPTCV.testplan_id = {$tplan_id} " .
            " AND NHTC.node_type_id = {$nt['testcase']} " .
            " AND NHTC.parent_id IN ({$idList}) GROUP BY NHTC.parent_id";
        $rows = $db->fetchRowsIntoMap($sql, 'tsuite_id');
        if (!is_null($rows)) {
            foreach ($rows as $sid => $r) {
                $suites[intval($sid)]['linked_qty'] = intval($r['qty']);
            }
        }

        // aggregate deep sums bottom-up
        $out = [];
        foreach ($suites as $sid => $s) {
            $stack = [$sid];
            $seen = [];
            $tot = 0;
            $lnk = 0;
            while ($stack) {
                $cur = array_pop($stack);
                if (isset($seen[$cur])) {
                    continue;
                }
                $seen[$cur] = true;
                $tot += $suites[$cur]['total_qty'];
                $lnk += $suites[$cur]['linked_qty'];
                foreach ($childrenMap[$cur] ?? [] as $ch) {
                    $stack[] = $ch;
                }
            }
            $suites[$sid]['deep_total_qty'] = $tot;
            $suites[$sid]['deep_linked_qty'] = $lnk;
            $out[] = $suites[$sid];
        }
    } else {
        $out = [];
    }

    out(['status' => 'ok', 'items' => $out]);
}

// ---------------------------------------------------------------------------
// GET /planaddtc/container?tproject_id=&tplan_id=&container_id=[&keyword_id=]
// direct-child test cases of one suite with versions + link state
// ---------------------------------------------------------------------------
if ($method === 'GET' && count($segments) === 2 &&
    $segments[0] === 'planaddtc' && $segments[1] === 'container') {
    $tplanMgr = new testplan($db);
    list($tproject_id, $tplan_id, $tplanInfo) =
        planAddTcNeedCtx($_GET, $tplanMgr);
    $rights = planAddTcRights($user, $db, $tproject_id, $tplan_id);
    if (!$rights['canPlan']) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }
    $nt = planAddTcNodeTypes($db);
    $TLT = tlObject::getDBTables();
    $nh = $TLT['nodes_hierarchy'];
    $container_id = intval(getParam('container_id', 0));
    if ($container_id <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid container id']);
    }
    $node = $db->fetchRowsIntoMap("SELECT id, name, parent_id, node_type_id " .
        "FROM {$nh} WHERE id = {$container_id}", 'id');
    if (is_null($node) ||
        intval($node[$container_id]['node_type_id']) != $nt['testsuite']) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Test suite not found']);
    }
    // ownership: walk parents up to the project root
    $walker = intval($node[$container_id]['parent_id']);
    $owned = false;
    while ($walker > 0) {
        if ($walker == $tproject_id) {
            $owned = true;
            break;
        }
        $rowP = $db->fetchRowsIntoMap(
            "SELECT parent_id FROM {$nh} WHERE id = {$walker}", 'parent_id');
        $keys = array_keys((array)$rowP);
        $walker = count($keys) ? intval($keys[0]) : 0;
    }
    if (!$owned) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'Suite not in this project']);
    }

    $kwList = planAddTcKeywordList();
    $kwJoin = $kwList !== '' ?
        " JOIN {$TLT['testcase_keywords']} TK " .
        " ON TK.testcase_id = NHTC.id " .
        " AND TK.keyword_id IN ({$kwList}) " : "";

    // direct child test cases
    $sql = "SELECT NHTC.id AS tc_id, NHTC.name, NHTC.node_order " .
        " FROM {$nh} NHTC {$kwJoin} " .
        " WHERE NHTC.node_type_id = {$nt['testcase']} " .
        " AND NHTC.parent_id = {$container_id} " .
        " ORDER BY NHTC.node_order, NHTC.name";
    $tcRows = $db->fetchRowsIntoMap($sql, 'tc_id');
    $items = [];
    $tcIds = [];
    if (!is_null($tcRows) && count($tcRows) > 0) {
        $tcIds = array_map('intval', array_keys($tcRows));
        $tcList = implode(',', $tcIds);

        // all versions
        $tcv = $TLT['tcversions'];
        $sql = "SELECT NHTCV.parent_id AS tc_id, TCVA.id AS tcversion_id, " .
            " TCVA.version, TCVA.active, TCVA.importance, TCVA.execution_type " .
            " FROM {$tcv} TCVA JOIN {$nh} NHTCV ON NHTCV.id = TCVA.id " .
            " WHERE NHTCV.parent_id IN ({$tcList}) " .
            " ORDER BY NHTCV.parent_id, TCVA.version";
        $verRows = $db->fetchRowsIntoMap($sql, 'tcversion_id');
        $versionsByTc = [];
        if (!is_null($verRows)) {
            foreach ($verRows as $vr) {
                $versionsByTc[intval($vr['tc_id'])][] = [
                    'tcversion_id' => intval($vr['tcversion_id']),
                    'version' => intval($vr['version']),
                    'active' => intval($vr['active']),
                    'importance' => intval($vr['importance']),
                    'execution_type' => intval($vr['execution_type']),
                ];
            }
        }

        // linked features for this plan
        $tpv = $TLT['testplan_tcversions'];
        $exq = $TLT['executions'];
        $allVids = array_keys((array)$verRows);
        $linkedByVersion = [];
        $newestAvail = [];
        if (count($allVids) > 0) {
            $vidList = implode(',', array_map('intval', $allVids));
            $sql = "SELECT TPTCV.id AS feature_id, TPTCV.tcversion_id, " .
                " TPTCV.platform_id, TPTCV.node_order, " .
                " (SELECT COUNT(E.id) FROM {$exq} E WHERE " .
                "   E.testplan_id = TPTCV.testplan_id " .
                "   AND E.tcversion_id = TPTCV.tcversion_id " .
                "   AND E.platform_id = TPTCV.platform_id) AS executed_qty " .
                " FROM {$tpv} TPTCV WHERE TPTCV.testplan_id = {$tplan_id} " .
                " AND TPTCV.tcversion_id IN ({$vidList})";
            $featRows = $db->fetchRowsIntoMap($sql, 'feature_id');
            if (!is_null($featRows)) {
                foreach ($featRows as $fr) {
                    $tvId = intval($fr['tcversion_id']);
                    $linkedByVersion[$tvId][] = [
                        'feature_id' => intval($fr['feature_id']),
                        'tcversion_id' => $tvId,
                        'platform_id' => intval($fr['platform_id']),
                        'node_order' => intval($fr['node_order']),
                        'executed_qty' => intval($fr['executed_qty']),
                    ];
                }
            }
            // newer-version hint (same query shape as manager method but scoped)
            $sql = "SELECT MAX(NHB.id) AS newest_tcversion_id, NHA.parent_id AS tc_id " .
                " FROM {$nh} NHA " .
                " JOIN {$tpv} T ON NHA.id = T.tcversion_id " .
                " JOIN {$tcv} TCVA ON TCVA.id = T.tcversion_id " .
                " JOIN {$nh} NHB ON NHB.parent_id = NHA.parent_id " .
                " JOIN {$tcv} TCVB ON TCVB.id = NHB.id AND TCVB.active = 1 " .
                " WHERE T.testplan_id = {$tplan_id} " .
                " AND NHA.parent_id IN ({$tcList}) AND NHB.id > NHA.id " .
                " GROUP BY NHA.parent_id";
            $nr = $db->fetchRowsIntoMap($sql, 'tc_id');
            if (!is_null($nr)) {
                foreach ($nr as $tcIdK => $rowN) {
                    // only a real hint when newest ACTIVE version > linked one
                    $newestVid = intval($rowN['newest_tcversion_id']);
                    $linkedMax = 0;
                    foreach ($versionsByTc[intval($tcIdK)] ?? [] as $vdef) {
                        if ($vdef['tcversion_id'] < $newestVid &&
                            isset($linkedByVersion[$vdef['tcversion_id']])) {
                            $linkedMax = max($linkedMax, $vdef['tcversion_id']);
                        }
                    }
                    if ($linkedMax > 0) {
                        $newestAvail[intval($tcIdK)] = $newestVid;
                    }
                }
            }
        }

        $tprojectMgr = new testproject($db);
        $tcPrefix = (string)$tprojectMgr->getTestCasePrefix($tproject_id) .
            (string)config_get('testcase_cfg')->glue_character;

        foreach ($tcIds as $tid) {
            $vers = $versionsByTc[$tid] ?? [];
            $links = [];
            $maxLinkedVersion = 0;
            foreach ($vers as $vdef) {
                if (isset($linkedByVersion[$vdef['tcversion_id']])) {
                    $links = array_merge($links,
                        $linkedByVersion[$vdef['tcversion_id']]);
                    $maxLinkedVersion =
                        max($maxLinkedVersion, $vdef['tcversion_id']);
                }
            }
            usort($vers, function ($a, $b) { return $b['version'] <=> $a['version']; });
            $items[] = [
                'tc_id' => $tid,
                'name' => (string)$tcRows[$tid]['name'],
                'node_order' => intval($tcRows[$tid]['node_order']),
                'versions' => $vers,
                'links' => $links,
                'newest_available' => $newestAvail[$tid] ?? 0,
            ];
        }
        // external ids come from tcversions.tc_external_id - refetch cheaply
        $sql = "SELECT NHTCV.parent_id AS tc_id, MIN(TCVA.tc_external_id) AS ext_id " .
            " FROM {$tcv} TCVA JOIN {$nh} NHTCV ON NHTCV.id = TCVA.id " .
            " WHERE NHTCV.parent_id IN ({$tcList}) GROUP BY NHTCV.parent_id";
        $extRows = $db->fetchRowsIntoMap($sql, 'tc_id');
        foreach ($items as $ix => $it) {
            $extId = intval($extRows[$it['tc_id']]['ext_id'] ?? 0);
            $items[$ix]['full_external_id'] = $tcPrefix . $extId;
        }
    }

    $platSet = $tplanMgr->getPlatforms($tplan_id);
    $platforms = [];
    if (!is_null($platSet)) {
        foreach ($platSet as $p) {
            $platforms[] = ['id' => intval($p['id']), 'name' => (string)$p['name']];
        }
    }

    out([
        'status' => 'ok',
        'container' => [
            'id' => $container_id,
            'name' => (string)$node[$container_id]['name'],
        ],
        'items' => $items,
        'platforms' => $platforms,
        'rights' => $rights,
    ]);
}

// ---------------------------------------------------------------------------
// POST /planaddtc/save
// body: {tproject_id,tplan_id, add:{tcid:{platid:tcvid}},
//        remove:{tcid:{platid:tcvid}}, testerID?, build_id?, send_mail?}
// mirrors planAddTC.php doAddRemove -> addToTestPlan()/removeFromTestPlan()
// ---------------------------------------------------------------------------
if ($method === 'POST' && count($segments) === 2 &&
    $segments[0] === 'planaddtc' && $segments[1] === 'save') {
    $body = getBody();
    $tplanMgr = new testplan($db);
    list($tproject_id, $tplan_id, $tplanInfo) = planAddTcNeedCtx($body, $tplanMgr);
    $rights = planAddTcRights($user, $db, $tproject_id, $tplan_id);
    if (!$rights['canPlan']) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }

    $add = (array)($body['add'] ?? []);
    $remove = (array)($body['remove'] ?? []);
    if (count($add) == 0 && count($remove) == 0) {
        http_response_code(422);
        out(['status' => 'error', 'message' => 'Nothing selected',
             'error_code' => 'NO_SELECTION']);
    }

    // normalize + validate every pair against the DB
    $TLT = tlObject::getDBTables();
    $nh = $TLT['nodes_hierarchy'];
    $tcv = $TLT['tcversions'];
    $tpv = $TLT['testplan_tcversions'];
    $exq = $TLT['executions'];

    function planAddTcNormalize($map) {
        $pairs = []; // [tcid, platid, tvid]
        foreach ((array)$map as $tcId => $plats) {
            $tcId = intval($tcId);
            foreach ((array)$plats as $platId => $tvId) {
                $pairs[] = [$tcId, intval($platId), intval($tvId)];
            }
        }
        return $pairs;
    }

    function planAddTcValidatePairs($db, $nh, $tcv, $pairs) {
        foreach ($pairs as $p) {
            list($tcId,, $tvId) = $p;
            $sql = "SELECT NHTCV.parent_id AS tc_id, TCVA.active " .
                " FROM {$tcv} TCVA JOIN {$nh} NHTCV ON NHTCV.id = TCVA.id " .
                " WHERE TCVA.id = {$tvId}";
            $row = $db->fetchRowsIntoMap($sql, 'tc_id');
            if (is_null($row) || intval($row[$tcId]['tc_id'] ?? 0) !== $tcId) {
                return false;
            }
        }
        return true;
    }

    $addPairs = planAddTcNormalize($add);
    $remPairs = planAddTcNormalize($remove);
    if (!planAddTcValidatePairs($db, $nh, $tcv, $addPairs) ||
        !planAddTcValidatePairs($db, $nh, $tcv, $remPairs)) {
        http_response_code(400);
        out(['status' => 'error',
             'message' => 'Invalid test case/version combination']);
    }

    // adds must target ACTIVE versions (legacy shows only active for adding)
    foreach ($addPairs as $p) {
        $tvId = $p[2];
        $act = $db->fetchFirstRowSingleColumn(
            "SELECT active FROM {$tcv} WHERE id = {$tvId}", 'active');
        if (intval($act) != 1) {
            http_response_code(400);
            out(['status' => 'error',
                 'message' => 'Cannot add an inactive test case version',
                 'error_code' => 'INACTIVE_VERSION']);
        }
    }

    // removing links that carry executions needs the dedicated right
    if (!$rights['canRemoveExecuted'] && count($remPairs) > 0) {
        $cond = [];
        foreach ($remPairs as $p) {
            $cond[] = "(E.tcversion_id = {$p[2]} AND E.platform_id = {$p[1]})";
        }
        $sql = "SELECT COUNT(*) AS qty FROM {$exq} E WHERE " .
            " E.testplan_id = {$tplan_id} AND (" . implode(' OR ', $cond) . ")";
        $execQty = intval($db->fetchFirstRowSingleColumn($sql, 'qty'));
        if ($execQty > 0) {
            http_response_code(403);
            out(['status' => 'error',
                 'message' => 'Some selected test cases are already executed; ' .
                     'removing them requires the ' .
                     '"testplan_unlink_executed_testcases" right',
                 'error_code' => 'EXECUTED_PRESENT']);
        }
    }

    $added = 0;
    $removed = 0;
    $addedFeatures = null;
    try {
        if (count($addPairs) > 0) {
            $itemsToLink = ['tcversion' => [], 'platform' => [], 'items' => []];
            foreach ($addPairs as $p) {
                list($tcId, $platId, $tvId) = $p;
                $itemsToLink['tcversion'][$tcId] = $tvId;
                $itemsToLink['platform'][$platId] = $platId;
                $itemsToLink['items'][$tcId][$platId] = $tvId;
            }
            $addedFeatures = $tplanMgr->link_tcversions($tplan_id,
                $itemsToLink, intval($user->dbID));
            $added = count($addPairs);
        }
        if (count($remPairs) > 0) {
            $itemsToUnlink = ['tcversion' => [], 'platform' => [], 'items' => []];
            foreach ($remPairs as $p) {
                list($tcId, $platId, $tvId) = $p;
                $itemsToUnlink['tcversion'][$tcId] = $tvId;
                $itemsToUnlink['platform'][$platId] = $platId;
                $itemsToUnlink['items'][$tcId][$platId] = $tvId;
            }
            $tplanMgr->unlink_tcversions($tplan_id, $itemsToUnlink);
            $removed = count($remPairs);
        }
    } catch (Throwable $e) {
        http_response_code(500);
        out(['status' => 'error', 'message' => $e->getMessage()]);
    }

    // optional tester assignment on newly created features
    // (legacy addToTestPlan(): testerID + build_id [+ send_mail])
    $assigned = 0;
    $testerID = intval($body['testerID'] ?? 0);
    $buildID = intval($body['build_id'] ?? 0);
    if ($testerID > 0 && $buildID > 0 && !is_null($addedFeatures) &&
        count($addedFeatures) > 0) {
        try {
            $statusMap = $tplanMgr->assignment_mgr->get_available_status();
            $typesMap = $tplanMgr->assignment_mgr->get_available_types();
            $getOpt = ['outputFormat' => 'map', 'addIfNull' => true];
            $platformSet = $tplanMgr->getPlatforms($tplan_id, $getOpt);
            $features2 = ['add' => []];
            foreach ($addedFeatures as $platId => $tcversionInfo) {
                foreach ($tcversionInfo as $tvId => $featureId) {
                    $features2['add'][$featureId]['user_id'] = $testerID;
                    $features2['add'][$featureId]['type'] =
                        $typesMap['testcase_execution']['id'];
                    $features2['add'][$featureId]['status'] =
                        $statusMap['open']['id'];
                    $features2['add'][$featureId]['assigner_id'] =
                        intval($user->dbID);
                    $features2['add'][$featureId]['build_id'] = $buildID;
                    $features2['add'][$featureId]['creation_ts'] =
                        $db->db_now();
                    $features2['add'][$featureId]['platform_name'] =
                        $platformSet[$platId] ?? '';
                }
            }
            if (count($features2['add']) > 0) {
                $tplanMgr->assignment_mgr->assign($features2);
                $assigned = count($features2['add']);
            }
        } catch (Throwable $e) {
            // linking succeeded; report the assignment failure softly
            $assigned = -1;
        }
    }

    out([
        'status' => 'ok',
        'added' => $added,
        'removed' => $removed,
        'tester_assigned_features' => $assigned,
    ]);
}

// ---------------------------------------------------------------------------
// POST /planaddtc/reorder
// body: {tplan_id, order:{tcversion_id:node_order}}
// legacy doReorder -> testplan::setExecutionOrder()
// ---------------------------------------------------------------------------
if ($method === 'POST' && count($segments) === 2 &&
    $segments[0] === 'planaddtc' && $segments[1] === 'reorder') {
    $body = getBody();
    $tplanMgr = new testplan($db);
    list($tproject_id, $tplan_id, $tplanInfo) = planAddTcNeedCtx($body, $tplanMgr);
    if (!planAddTcRights($user, $db, $tproject_id, $tplan_id)['canPlan']) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }
    $order = [];
    foreach ((array)($body['order'] ?? []) as $tvId => $ord) {
        $tvId = intval($tvId);
        if ($tvId > 0) {
            $order[$tvId] = intval($ord);
        }
    }
    if (count($order) == 0) {
        http_response_code(422);
        out(['status' => 'error', 'message' => 'No execution order provided',
             'error_code' => 'NO_SELECTION']);
    }
    $tplanMgr->setExecutionOrder($tplan_id, $order);
    out(['status' => 'ok', 'updated' => count($order)]);
}

http_response_code(404);
out(['status' => 'error', 'message' => 'Not found']);
