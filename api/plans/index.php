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
            $ent . '&itemID=',
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
if ($method === 'PUT' && isset($segments[1]) && $segments[1] === 'active') {
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
    $owned = [];
    foreach ($ids as $id) {
        $info = $tplanMgr->get_by_id($id, $tproject_id);
        if ($info) {
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

http_response_code(404);
out(['status' => 'error', 'message' => 'Not found']);
