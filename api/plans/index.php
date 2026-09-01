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
        'exportAction'  => '/gui/templates/plans/planExport.html?tproject_id=' .
                           intval($tproject_id) . '&tplan_id=',
        'importAction'  => '/gui/templates/plans/planImport.html?tproject_id=' .
                           intval($tproject_id) . '&tplan_id=',
        'assignRolesAction' =>
            '/gui/templates/usermanagement/usersAssignPlan.html?tproject_id=' .
            intval($tproject_id) . '&tplan_id=',
        'gotoExecuteAction' =>
            '/gui/templates/execute/execTest.html?feature=executeTest&' . $entProj . '&tplan_id=',
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

    // --- Custom field columns for test plans (refs #584) ---
    // Fetch CF definitions linked to testplans at design time.
    // Follows legacy planView.php:38-90 pattern + TL_TPLANVIEW_HIDECOL config.
    $cfColumns = [];
    $cfValues = [];  // plan_id => [cf_name => value]
    $cfHideNames = [];

    if (!is_null($rows) && count($rows) > 0 && isset($tplanMgr)) {
        $cfieldMgr = new cfield_mgr($db);
        // 1. Get CF definitions: all enabled CFs linked to this project
        //    for testplan node type (show_on_testplan_design or enable_on_testplan_design).
        //    Use get_linked_cfields_at_testplan_design without link_id to get defs only.
        $cfDefs = $cfieldMgr->get_linked_cfields_at_testplan_design(
            $tproject_id, cfield_mgr::ENABLED);
        if (!is_null($cfDefs) && count($cfDefs) > 0) {
            // 2. Check TL_TPLANVIEW_HIDECOL config: a special CF whose
            //    possible_values is a pipe-delimited list of CF names to hide.
            //    (Same approach as legacy planView.php lines 45-65.)
            $tpMgr = new testproject($db);
            $ppfx = $tpMgr->getTestCasePrefix($tproject_id);
            foreach (['_' . $ppfx, ''] as $suf) {
                $gopt = ['name' => 'TL_TPLANVIEW_HIDECOL' . $suf];
                $col2hideCF = $cfieldMgr->get_linked_to_testproject(
                    $tproject_id, 1, $gopt);
                if (!empty($col2hideCF)) {
                    $first = reset($col2hideCF);
                    $cfHideNames = array_flip(
                        explode('|', $first['possible_values']));
                    // Also hide the config CF itself from columns
                    $cfHideNames[$first['name']] = '';
                    break;
                }
            }

            // 3. Build visible CF column definitions (filter hidden ones)
            foreach ($cfDefs as $cfId => $cf) {
                if (!isset($cfHideNames[$cf['name']])) {
                    $cfColumns[] = [
                        'id' => intval($cfId),
                        'label' => $cf['label'],
                        'name' => $cf['name'],
                    ];
                }
            }

            // 4. Batch-fetch all CF values for all plans in one query
            if (count($cfColumns) > 0) {
                $tplanSet = array_keys($rows);
                $planList = implode(',', array_map('intval', $tplanSet));
                $cfIdList = implode(',', array_map(function ($c) {
                    return $c['id'];
                }, $cfColumns));
                $cfTbl = tlObject::getDBTables(
                    ['cfield_testplan_design_values']);
                $sql = "SELECT CFTDV.link_id AS plan_id, CF.name, CFTDV.value" .
                       " FROM {$cfTbl['cfield_testplan_design_values']} CFTDV" .
                       " JOIN custom_fields CF ON CF.id = CFTDV.field_id" .
                       " WHERE CFTDV.field_id IN ({$cfIdList})" .
                       " AND CFTDV.link_id IN ({$planList})";
                $cfRows = $db->fetchRowsIntoMap(
                    $sql, 'plan_id');
                if (!is_null($cfRows)) {
                    foreach ($cfRows as $planId => $row) {
                        $cfValues[intval($planId)][$row['name']] =
                            $row['value'] ?? '';
                    }
                }
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

            // Attach CF values for this plan
            if (isset($cfValues[$idk])) {
                $it['cf'] = $cfValues[$idk];
            }

            $items[] = $it;
        }
    }

    out([
        'status' => 'ok',
        'items' => $items,
        'tproject' => ['id' => $tproject_id, 'name' => $tproject_name],
        'draw_platform_qty_column' => $drawPlatformQtyColumn,
        'cfields_columns' => $cfColumns,
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
// POST / — Create test plan (legacy do_action=do_create in planEdit.php)
// body: { tproject_id, name, notes?, active?, is_public? }
// Right gate: mgt_testplan_create (same as planEdit.php init_args()).
// ===========================================================================
if ($method === 'POST' && count($segments) === 0) {
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

    $name = trim((string)($body['name'] ?? ''));
    if ($name === '') {
        http_response_code(422);
        out(['status' => 'error', 'message' => 'Test plan name is required',
             'error_code' => 'NAME_REQUIRED']);
    }

    $tprojectMgr = new testproject($db);
    if ($tprojectMgr->check_tplan_name_existence($tproject_id, $name)) {
        http_response_code(422);
        out(['status' => 'error',
             'message' => 'A test plan with this name already exists',
             'error_code' => 'DUPLICATE_NAME']);
    }

    $notes   = (string)($body['notes'] ?? '');
    $active  = !empty($body['active']) ? 1 : 0;
    $isPublic = !empty($body['is_public']) ? 1 : 0;

    $tplanMgr = new testplan($db);
    $newId = $tplanMgr->create($name, $notes, $tproject_id, $active, $isPublic);
    if ($newId == 0) {
        http_response_code(500);
        out(['status' => 'error', 'message' => 'Failed to create test plan: ' .
             $db->error_msg()]);
    }

    logAuditEvent(TLS("audit_testplan_created",
        testproject::getName($db, $tproject_id), $name),
        "CREATED", $newId, "testplans");

    // Assign creator's effective role if plan is private (same as planEdit.php)
    if (!$isPublic) {
        $effectiveRole = $user->getEffectiveRole($db, $tproject_id, null);
        if (!tlUser::hasRoleOnTestPlan($db, $userId, $newId)) {
            $tplanMgr->addUserRole($userId, $newId, $effectiveRole->dbID);
        }
    }

    // Return the new plan info for the front end
    $info = $tplanMgr->get_by_id($newId);
    out([
        'status' => 'ok',
        'id' => intval($newId),
        'name' => $name,
        'active' => $active,
        'is_public' => $isPublic,
    ]);
}

// ===========================================================================
// PUT /{id} — Update test plan (legacy do_action=do_update in planEdit.php)
// body: { tproject_id, name, notes?, active?, is_public? }
// Right gate: mgt_testplan_create (same as planEdit.php init_args()).
// ===========================================================================
if ($method === 'PUT' && isset($segments[0]) && ctype_digit($segments[0]) &&
    !isset($segments[1])) {
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
    $info = $tplanMgr->get_by_id($id);
    if (!$info || intval($info['testproject_id']) != $tproject_id) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Test plan not found']);
    }

    $name = trim((string)($body['name'] ?? ''));
    if ($name === '') {
        http_response_code(422);
        out(['status' => 'error', 'message' => 'Test plan name is required',
             'error_code' => 'NAME_REQUIRED']);
    }

    // Name uniqueness: allow keeping the same name on update
    $tprojectMgr = new testproject($db);
    if ($tprojectMgr->check_tplan_name_existence($tproject_id, $name)) {
        $nameIsOwn = (string)$info['name'] === $name;
        if (!$nameIsOwn) {
            http_response_code(422);
            out(['status' => 'error',
                 'message' => 'A test plan with this name already exists',
                 'error_code' => 'DUPLICATE_NAME']);
        }
    }

    $notes   = (string)($body['notes'] ?? '');
    $active  = !empty($body['active']) ? 1 : 0;
    $isPublic = !empty($body['is_public']) ? 1 : 0;

    if (!$tplanMgr->update($id, $name, $notes, $active, $isPublic)) {
        http_response_code(500);
        out(['status' => 'error', 'message' => 'Failed to update test plan: ' .
             $db->error_msg()]);
    }

    logAuditEvent(TLS("audit_testplan_saved",
        testproject::getName($db, $tproject_id), $name),
        "SAVE", $id, "testplans");

    // Handle private→public / public→private role assignment (planEdit.php lines 93-101)
    if (!$isPublic) {
        if (!tlUser::hasRoleOnTestPlan($db, $userId, $id)) {
            $effectiveRole = $user->getEffectiveRole($db, $tproject_id, null);
            $tplanMgr->addUserRole($userId, $id, $effectiveRole->dbID);
        }
    }

    out([
        'status' => 'ok',
        'id' => $id,
        'name' => $name,
        'active' => $active,
        'is_public' => $isPublic,
    ]);
}

// ===========================================================================
// GET /{id} — Fetch single test plan (for edit modal pre-fill)
// ===========================================================================
if ($method === 'GET' && isset($segments[0]) && ctype_digit($segments[0]) &&
    !isset($segments[1])) {
    $tproject_id = needTprojectId();
    if (!canManage($user, $db, $tproject_id)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }

    $id = intval($segments[0]);
    $tplanMgr = new testplan($db);
    $info = $tplanMgr->get_by_id($id);
    if (!$info || intval($info['testproject_id']) != $tproject_id) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Test plan not found']);
    }

    out([
        'status' => 'ok',
        'id' => intval($id),
        'name' => (string)$info['name'],
        'notes' => (string)($info['notes'] ?? ''),
        'active' => intval($info['active']),
        'is_public' => intval($info['is_public']),
    ]);
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

// true when nodeId sits inside the tproject subtree (walks parents up)
function planAddTcInProject(&$db, $nh, $nodeId, $tproject_id) {
    $walker = intval($nodeId);
    $steps = 0;
    while ($walker > 0 && $steps++ < 50) {
        if ($walker == intval($tproject_id)) {
            return true;
        }
        $rowP = $db->fetchRowsIntoMap(
            "SELECT parent_id FROM {$nh} WHERE id = {$walker}", 'parent_id');
        $keys = array_keys((array)$rowP);
        $walker = count($keys) ? intval($keys[0]) : 0;
    }
    return false;
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
    // plan picker must never leak other projects' plan data
    if ($tplan_id > 0) {
        $own = $tplanMgr->get_by_id($tplan_id);
        if (!$own || intval($own['testproject_id']) != $tproject_id) {
            http_response_code(404);
            out(['status' => 'error', 'message' => 'Test plan not found']);
        }
    }
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
    // recursion root must live inside this project (no cross-project walk)
    if ($rootId != $tproject_id) {
        $prow = planAddTcInProject($db, $nh, $rootId, $tproject_id);
        if (!$prow) {
            http_response_code(404);
            out(['status' => 'error', 'message' => 'Container not found']);
        }
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

    // every test case must live inside the target project
    function planAddTcPairsInProject($db, $nh, $pairs, $tproject_id) {
        foreach ($pairs as $p) {
            list($tcId,,) = $p;
            if (!planAddTcInProject($db, $nh, intval($tcId), $tproject_id)) {
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
    // no cross-project linking: both sets must belong to this project
    if (!planAddTcPairsInProject($db, $nh, $addPairs, $tproject_id) ||
        !planAddTcPairsInProject($db, $nh, $remPairs, $tproject_id)) {
        http_response_code(403);
        out(['status' => 'error',
             'message' => 'Test case does not belong to this project']);
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
            // drop pairs already linked (legacy filtered duplicates via
            // getFilteredLinkedVersions; blind re-link would dup features)
            $have = [];
            $dup = $db->fetchColumnsIntoMap("SELECT CONCAT(tcversion_id, '_', " .
                "COALESCE(platform_id,0)) AS k FROM {$tpv} " .
                "WHERE testplan_id = {$tplan_id}", 'k', 'k');
            foreach ((array)$dup as $k => $v) {
                $have[$k] = true;
            }
            $fresh = [];
            $itemsToLink = ['tcversion' => [], 'platform' => [], 'items' => []];
            foreach ($addPairs as $p) {
                list($tcId, $platId, $tvId) = $p;
                if (isset($have[$tvId . '_' . $platId])) {
                    continue;
                }
                $fresh[] = $p;
                $itemsToLink['tcversion'][$tcId] = $tvId;
                $itemsToLink['platform'][$platId] = $platId;
                $itemsToLink['items'][$tcId][$platId] = $tvId;
            }
            if (count($fresh) > 0) {
                $addedFeatures = $tplanMgr->link_tcversions($tplan_id,
                    $itemsToLink, intval($user->dbID));
            }
            $added = count($fresh);
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

// ---------------------------------------------------------------------------
// Set Test Urgency (modernized planUrgency.php screen) - Refs #605
// legacy rights: checkRights() rightsAnd testplan_planning on every request
// ---------------------------------------------------------------------------

/**
 * Legacy planUrgency.php checkRights(): page AND all mutations live behind
 * "testplan_planning" (rightsAnd semantics -> no OR fallback).
 */
function urgencyRights($user, $db, $tproject_id) {
    return (bool)$user->hasRight($db, 'testplan_planning', $tproject_id);
}

function urgencyNeedCtx($body, $tplanMgr) {
    $tplan_id = intval($body['tplan_id'] ?? ($_GET['tplan_id'] ?? 0));
    $tproject_id = intval($body['tproject_id'] ?? ($_GET['tproject_id'] ?? 0));
    if ($tplan_id <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test plan id',
             'error_code' => 'NO_TPLAN']);
    }
    if ($tproject_id <= 0) {
        $info = $tplanMgr->get_by_id($tplan_id);
        $tproject_id = intval($info['testproject_id'] ?? 0);
    }
    if ($tproject_id <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test project id',
             'error_code' => 'NO_TPROJECT']);
    }
    return [$tproject_id, $tplan_id];
}

// GET /urgency?tproject_id=&tplan_id=
// context for the screen: accessible plans selector + suites of the active
// plan that have directly-linked test cases (setSuiteUrgency scope), with
// their current linked-tcversion urgency summary.
if ($method === 'GET' && count($segments) === 1 && $segments[0] === 'urgency') {
    $tproject_id = intval($_GET['tproject_id'] ?? 0);
    if ($tproject_id <= 0) {
        $tproject_id = needTprojectId();
    }
    if (!urgencyRights($user, $db, $tproject_id)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }

    $tplanMgr = new testPlanUrgency($db);

    $tplans = [];
    $rows = $user->getAccessibleTestPlans($db, $tproject_id, null,
        ['output' => 'mapfull', 'active' => null]);
    foreach ((array)$rows as $r) {
        $tplans[] = [
            'id' => intval($r['id']),
            'name' => (string)$r['name'],
            'active' => intval($r['active']),
            'is_public' => intval($r['is_public']),
        ];
    }

    $tplan_id = intval($_GET['tplan_id'] ?? 0);
    $tplanInfo = null;
    if ($tplan_id > 0) {
        $info = $tplanMgr->get_by_id($tplan_id);
        if (!is_null($info) && intval($info['parent_id']) == $tproject_id) {
            $tplanInfo = [
                'id' => intval($info['id']),
                'name' => (string)$info['name'],
                'active' => intval($info['active']),
                'is_open' => isset($info['is_open']) ? intval($info['is_open']) : 1,
            ];
        } else {
            $tplan_id = 0;
        }
    }
    if ($tplan_id <= 0 && count($tplans) > 0) {
        $tplan_id = intval($tplans[0]['id']);
        $info = $tplanMgr->get_by_id($tplan_id);
        $tplanInfo = [
            'id' => $tplan_id,
            'name' => (string)$info['name'],
            'active' => intval($info['active']),
            'is_open' => isset($info['is_open']) ? intval($info['is_open']) : 1,
        ];
    }

    // Suites holding DIRECTLY linked test cases - exactly the set
    // setSuiteUrgency() touches (tcversion.parent(testcase).parent(suite)).
    $suites = [];
    if ($tplan_id > 0) {
        $tbl = tlObjectWithDB::getDBTables(
            ['testplan_tcversions', 'nodes_hierarchy']);
        $sql = " SELECT NSU.id AS tsuite_id, NSU.name AS tsuite_name," .
               " NSU.node_order, COUNT(DISTINCT NTC.id) AS tc_qty," .
               " COUNT(DISTINCT TPTCV.tcversion_id) AS tcv_qty," .
               " MIN(TPTCV.urgency) AS urg_min, MAX(TPTCV.urgency) AS urg_max" .
               " FROM {$tbl['testplan_tcversions']} TPTCV" .
               " JOIN {$tbl['nodes_hierarchy']} NHA" .
               " ON NHA.id = TPTCV.tcversion_id" .
               " JOIN {$tbl['nodes_hierarchy']} NTC" .
               " ON NTC.id = NHA.parent_id" .
               " JOIN {$tbl['nodes_hierarchy']} NSU" .
               " ON NSU.id = NTC.parent_id" .
               " WHERE TPTCV.testplan_id = " . intval($tplan_id) .
               " GROUP BY NSU.id, NSU.name, NSU.node_order" .
               " ORDER BY NSU.node_order";
        $rs = $db->fetchRowsIntoMap($sql, 'tsuite_id');
        foreach ((array)$rs as $sid => $s) {
            $suites[] = [
                'id' => intval($sid),
                'name' => (string)$s['tsuite_name'],
                'node_order' => intval($s['node_order']),
                'tc_qty' => intval($s['tc_qty']),
                'tcv_qty' => intval($s['tcv_qty']),
                // 0 == mixed urgencies among linked tcversions
                'urgency' =>
                    intval($s['urg_min']) === intval($s['urg_max'])
                        ? intval($s['urg_min']) : 0,
            ];
        }
    }

    $tproject_name = trim((string)($_SESSION['testprojectName'] ?? ''));
    if ($tproject_name === '') {
        $tproject_name = testproject::getName($db, $tproject_id);
    }

    out([
        'status' => 'ok',
        'tproject' => ['id' => $tproject_id, 'name' => $tproject_name],
        'tplans' => $tplans,
        'tplan' => $tplanInfo,
        'suites' => $suites,
        'rights' => ['canManage' => true],
    ]);
}

// GET /urgency/suite?tplan_id=&tsuite_id=[&platform_id=]
// test cases directly under one suite with their per-plan urgency,
// importance, computed priority level and tester assignments.
// Assignments are resolved against the latest ACTIVE build of the plan
// (legacy resolved them from the execution-tree session build setting).
if ($method === 'GET' && count($segments) === 2 &&
    $segments[0] === 'urgency' && $segments[1] === 'suite') {
    list($tproject_id, $tplan_id) =
        urgencyNeedCtx($_GET, new testplan($db));
    if (!urgencyRights($user, $db, $tproject_id)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }
    $tsuite_id = intval($_GET['tsuite_id'] ?? 0);
    if ($tsuite_id <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid suite id',
             'error_code' => 'NO_SUITE']);
    }

    $tplanMgr = new testPlanUrgency($db);
    $platform_id = intval($_GET['platform_id'] ?? 0);

    // latest active build drives the tester-assignment join (build4testers)
    $build4testers = 0;
    $bset = $tplanMgr->get_builds($tplan_id, 1, 1);
    if (!is_null($bset) && count($bset) > 0) {
        $bk = array_keys($bset);
        $build4testers = intval($bk[0]);
    }

    $context = new stdClass();
    $context->tplan_id = $tplan_id;
    $context->tsuite_id = $tsuite_id;
    $context->tproject_id = $tproject_id;
    $context->platform_id = $platform_id;

    $raw = $tplanMgr->getSuiteUrgency(
        $context,
        ['build4testers' => $build4testers],
        null
    );

    // CUMULATIVE map: tcversion_id => [row per platform]; merge to one UI row
    $items = [];
    foreach ((array)$raw as $tcversion_id => $rowsSet) {
        if (!is_array($rowsSet) || count($rowsSet) == 0) {
            continue;
        }
        $first = reset($rowsSet);
        $assigned = [];
        foreach ($rowsSet as $res) {
            $who = trim((string)($res['assigned_to'] ?? ''));
            if ($who !== '' && !in_array($who, $assigned)) {
                $assigned[] = $who;
            }
        }
        $urgencies = [];
        foreach ($rowsSet as $res) {
            $urgencies[intval($res['urgency'])] = true;
        }
        $urgency = count($urgencies) === 1 ? intval($first['urgency'])
                                           : max(array_keys($urgencies));
        $importance = intval($first['importance']);
        $items[] = [
            'tcversion_id' => intval($tcversion_id),
            'testcase_id' => intval($first['testcase_id']),
            'name' => (string)$first['name'],
            'tcprefix' => (string)$first['tcprefix'],
            'tc_external_id' => intval($first['tc_external_id']),
            'fullExternalId' =>
                (string)$first['tcprefix'] . (string)$first['tc_external_id'],
            'urgency' => $urgency,
            'importance' => $importance,
            'priority_level' => priority_to_level($importance * $urgency),
            'priority_value' => $importance * $urgency,
            'assigned_to' => implode(', ', $assigned),
            'platform_qty' => count($rowsSet),
        ];
    }

    usort($items, function ($a, $b) { return $a['tcversion_id'] <=> $b['tcversion_id']; });

    out([
        'status' => 'ok',
        'items' => $items,
        'build4testers' => $build4testers,
    ]);
}

// POST /urgency/suite  body: {tplan_id[, tproject_id], tsuite_id, urgency}
// legacy doProcess() suite branch -> setSuiteUrgency()
if ($method === 'POST' && count($segments) === 2 &&
    $segments[0] === 'urgency' && $segments[1] === 'suite') {
    $body = getBody();
    list($tproject_id, $tplan_id) = urgencyNeedCtx($body, new testplan($db));
    if (!urgencyRights($user, $db, $tproject_id)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }
    $tsuite_id = intval($body['tsuite_id'] ?? 0);
    $urgency = intval($body['urgency'] ?? 0);
    if ($tsuite_id <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid suite id',
             'error_code' => 'NO_SUITE']);
    }
    if (!in_array($urgency, [LOW, MEDIUM, HIGH], true)) {
        http_response_code(422);
        out(['status' => 'error', 'message' => 'Invalid urgency value',
             'error_code' => 'BAD_URGENCY']);
    }
    $tplanMgr = new testPlanUrgency($db);
    $ret = $tplanMgr->setSuiteUrgency($tplan_id, $tsuite_id, $urgency);
    out([
        'status' => ($ret === OK || $ret === tl::OK) ? 'ok' : 'error',
        'feedback_type' => ($ret === OK || $ret === tl::OK) ? 'ok' : 'fail',
    ]);
}

// POST /urgency/tcases  body: {tplan_id[, tproject_id], items:{tcversion_id:urgency}}
// legacy doProcess() per-testcase branch -> setTestUrgency() loop
if ($method === 'POST' && count($segments) === 2 &&
    $segments[0] === 'urgency' && $segments[1] === 'tcases') {
    $body = getBody();
    list($tproject_id, $tplan_id) = urgencyNeedCtx($body, new testplan($db));
    if (!urgencyRights($user, $db, $tproject_id)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }
    $items = [];
    foreach ((array)($body['items'] ?? []) as $tvId => $urg) {
        $tvId = intval($tvId);
        $urg = intval($urg);
        if ($tvId > 0 && in_array($urg, [LOW, MEDIUM, HIGH], true)) {
            $items[$tvId] = $urg;
        }
    }
    if (count($items) == 0) {
        http_response_code(422);
        out(['status' => 'error', 'message' => 'No urgency changes provided',
             'error_code' => 'NO_SELECTION']);
    }
    $tplanMgr = new testPlanUrgency($db);
    $failed = 0;
    foreach ($items as $tvId => $urg) {
        $ret = $tplanMgr->setTestUrgency($tplan_id, $tvId, $urg);
        if (!($ret === OK || $ret === tl::OK)) {
            $failed++;
        }
    }
    out([
        'status' => $failed === 0 ? 'ok' : 'error',
        'updated' => count($items) - $failed,
        'failed' => $failed,
    ]);
}

// ---------------------------------------------------------------------------
// Update Linked Test Case Versions screen (modernized planUpdateTC.php)
// Refs #619
//
// Rights: legacy lib/plan/planUpdateTC.php checkRights() runs pageAccessCheck
// with rightsAnd = ['testplan_planning'], menu visibility is gated separately
// by testplan_update_linked_testcase_versions (aside.tpl). Same contract here:
// every route requires testplan_planning server-side.
// ---------------------------------------------------------------------------
function updTcRights($user, $db, $tproject_id) {
    return (bool)$user->hasRight($db, 'testplan_planning', $tproject_id);
}

function updTcNeedCtx($src, $user, $db) {
    $tplan_id = intval($src['tplan_id'] ?? 0);
    if ($tplan_id <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test plan id',
             'error_code' => 'NO_TPLAN']);
    }
    $tplanMgr = new testplan($db);
    $info = $tplanMgr->get_by_id($tplan_id);
    if (is_null($info)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test plan id',
             'error_code' => 'NO_TPLAN']);
    }
    $tproject_id = intval($src['tproject_id'] ?? 0);
    if ($tproject_id <= 0) {
        $tproject_id = intval($info['parent_id']);
    } else if ($tproject_id !== intval($info['parent_id'])) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Test plan not in project',
             'error_code' => 'NO_TPROJECT']);
    }
    if ($tproject_id <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test project id',
             'error_code' => 'NO_TPROJECT']);
    }
    return [$tproject_id, $tplan_id];
}

/**
 * All linked tcversions of the plan (optionally of ONE test case), each with
 * its available ACTIVE target versions (siblings != linked), execution count
 * on the linked version and suite placement. Mirrors what legacy
 * processTestPlan()/processTestSuite()/tideUpForGUI() fed into the Smarty view.
 */
function updTcItems($db, $tplan_id, $tcprefix)
{
    $tbl = tlObjectWithDB::getDBTables(
        ['testplan_tcversions', 'nodes_hierarchy', 'tcversions', 'executions']);

    $sql = " SELECT T.tcversion_id AS linked_tcv," .
           " LVER.version AS linked_version, LVER.active AS linked_active," .
           " LVER.tc_external_id," .
           " NHA.parent_id AS tc_id, NHC.name AS tc_name," .
           " NTC.id AS tsuite_id, NTC.name AS tsuite_name," .
           " NSIB.id AS sib_tcv, V.version AS sib_version," .
           " V.active AS sib_active" .
           " FROM {$tbl['testplan_tcversions']} T" .
           " JOIN {$tbl['nodes_hierarchy']} NHA ON NHA.id = T.tcversion_id" .
           " JOIN {$tbl['nodes_hierarchy']} NHC ON NHC.id = NHA.parent_id" .
           " JOIN {$tbl['nodes_hierarchy']} NTC ON NTC.id = NHC.parent_id" .
           " JOIN {$tbl['tcversions']} LVER ON LVER.id = T.tcversion_id" .
           " LEFT JOIN {$tbl['nodes_hierarchy']} NSIB" .
           " ON NSIB.parent_id = NHA.parent_id AND NSIB.id <> T.tcversion_id" .
           " LEFT JOIN {$tbl['tcversions']} V ON V.id = NSIB.id" .
           " WHERE T.testplan_id = " . intval($tplan_id) .
           " ORDER BY NTC.name, NHC.name";

    $rows = $db->get_recordset($sql);

    // executions on the LINKED versions inside this plan (informational)
    $execQty = [];
    $esql = " SELECT tcversion_id, COUNT(*) AS qty" .
            " FROM {$tbl['executions']}" .
            " WHERE testplan_id = " . intval($tplan_id) .
            " GROUP BY tcversion_id";
    foreach ((array)$db->get_recordset($esql) as $e) {
        $execQty[intval($e['tcversion_id'])] = intval($e['qty']);
    }

    $items = [];
    foreach ((array)$rows as $r) {
        $tcid = intval($r['tc_id']);
        if (!isset($items[$tcid])) {
            $extId = intval($r['tc_external_id']);
            $it = [
                'testcase_id' => $tcid,
                'name' => (string)$r['tc_name'],
                'fullExternalId' => $tcprefix . $extId,
                'tsuite_id' => intval($r['tsuite_id']),
                'tsuite_name' => (string)$r['tsuite_name'],
                'linked_tcversion_id' => intval($r['linked_tcv']),
                'linked_version' => intval($r['linked_version']),
                'linked_active' => intval($r['linked_active']),
                'executed_qty' => isset($execQty[intval($r['linked_tcv'])])
                    ? $execQty[intval($r['linked_tcv'])] : 0,
                'targets' => [],
                'newest_tcversion_id' => 0,
                'newest_version' => 0,
            ];
            $items[$tcid] = $it;
        }
        // collect ACTIVE sibling versions as update candidates
        // (legacy tideUpForGUI(): canUpdateVersion counts active versions)
        if (!is_null($r['sib_tcv']) && intval($r['sib_tcv']) > 0 &&
            intval($r['sib_active']) === 1) {
            $items[$tcid]['targets'][] = [
                'id' => intval($r['sib_tcv']),
                'version' => intval($r['sib_version']),
            ];
            if (intval($r['sib_tcv']) >
                $items[$tcid]['newest_tcversion_id']) {
                $items[$tcid]['newest_tcversion_id'] = intval($r['sib_tcv']);
                $items[$tcid]['newest_version'] =
                    intval($r['sib_version']);
            }
        }
    }

    // finalize: sort targets oldest -> newest, flag already-latest rows
    // EXACT legacy get_linked_and_newest_tcversions() semantics
    // (testplan.class.php): newest = MAX node id among ACTIVE siblings with
    // node id > linked id; updatable only if that candidate's VERSION number
    // is greater than the linked version number (id order != version order,
    // e.g. after imports). Keeps bulk preview == bulk action. Non-updatable
    // active siblings stay available as manual targets (tideUpForGUI parity).
    foreach ($items as $tcid => $it) {
        usort($items[$tcid]['targets'], function ($a, $b) {
            return $a['id'] <=> $b['id'];
        });
        $newestId = 0;
        $newestVer = 0;
        foreach ($items[$tcid]['targets'] as $t) {
            if ($t['id'] > $it['linked_tcversion_id'] &&
                $t['id'] > $newestId) {
                $newestId = intval($t['id']);
                $newestVer = intval($t['version']);
            }
        }
        $updatable = ($newestId > 0) &&
            ($it['linked_version'] < $newestVer);
        $items[$tcid]['newest_tcversion_id'] =
            $updatable ? $newestId : 0;
        $items[$tcid]['newest_version'] = $updatable ? $newestVer : 0;
        $items[$tcid]['is_latest'] = !$updatable;
    }

    return array_values($items);
}

// GET /update-linked?tproject_id=&tplan_id=
// screen context: accessible plans selector, suites holding linked test
// cases (with updatable counts) and the full linked-item detail set.
if ($method === 'GET' && count($segments) === 1 &&
    $segments[0] === 'update-linked') {
    // context-only mode (#624): tplan_id=0 with a valid tproject_id must
    // still deliver the plan picker payload instead of a dead-end 400
    $tplan_id = intval($_GET['tplan_id'] ?? 0);
    $tproject_id = intval($_GET['tproject_id'] ?? 0);
    if ($tplan_id <= 0) {
        if ($tproject_id <= 0) {
            http_response_code(400);
            out(['status' => 'error', 'message' => 'Invalid test project id',
                 'error_code' => 'NO_TPROJECT']);
        }
        $tprojMgr = new testproject($db);
        if (is_null($tprojMgr->get_by_id($tproject_id))) {
            http_response_code(400);
            out(['status' => 'error', 'message' => 'Invalid test project id',
                 'error_code' => 'NO_TPROJECT']);
        }
        if (!updTcRights($user, $db, $tproject_id)) {
            http_response_code(403);
            out(['status' => 'error', 'message' => 'No permission']);
        }
        $rows = $user->getAccessibleTestPlans($db, $tproject_id, null,
            ['output' => 'mapfull', 'active' => null]);
        $tplans = [];
        foreach ((array)$rows as $r) {
            $tplans[] = [
                'id' => intval($r['id']),
                'name' => (string)$r['name'],
                'active' => intval($r['active']),
                'is_public' => intval($r['is_public']),
            ];
        }
        $tproject_name = trim((string)($_SESSION['testprojectName'] ?? ''));
        if ($tproject_name === '') {
            $tproject_name = testproject::getName($db, $tproject_id);
        }
        out([
            'status' => 'ok',
            'tproject' => ['id' => $tproject_id, 'name' => $tproject_name],
            'tplans' => $tplans,
            'tplan' => null,
            'suites' => [],
            'items' => [],
            'linked_count' => 0,
            'state' => 'pick',
        ]);
    }

    list($tproject_id, $tplan_id) = updTcNeedCtx($_GET, $user, $db);
    if (!updTcRights($user, $db, $tproject_id)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }

    $tplanMgr = new testplan($db);

    $tplans = [];
    $rows = $user->getAccessibleTestPlans($db, $tproject_id, null,
        ['output' => 'mapfull', 'active' => null]);
    foreach ((array)$rows as $r) {
        $tplans[] = [
            'id' => intval($r['id']),
            'name' => (string)$r['name'],
            'active' => intval($r['active']),
            'is_public' => intval($r['is_public']),
        ];
    }

    $tplanInfo = null;
    $info = $tplanMgr->get_by_id($tplan_id);
    if (!is_null($info) && intval($info['parent_id']) == $tproject_id) {
        $tplanInfo = [
            'id' => intval($info['id']),
            'name' => (string)$info['name'],
            'active' => intval($info['active']),
        ];
    } else {
        $tplan_id = 0;
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Test plan not in project',
             'error_code' => 'BAD_TPLAN']);
    }

    // test case prefix (legacy initializeGui())
    $tcaseMgr = new testcase($db);
    $tcprefix = $tcaseMgr->tproject_mgr->getTestCasePrefix($tproject_id);
    $tcCfg = config_get('testcase_cfg');
    $tcprefix .= $tcCfg->glue_character;

    $items = updTcItems($db, $tplan_id, $tcprefix);
    $linkedCount = intval($tplanMgr->getLinkedCount($tplan_id));

    // suite summary derived from the item set
    $suites = [];
    foreach ($items as $it) {
        $sid = $it['tsuite_id'];
        if (!isset($suites[$sid])) {
            $suites[$sid] = [
                'id' => $sid,
                'name' => $it['tsuite_name'],
                'tc_qty' => 0,
                'updatable_qty' => 0,
            ];
        }
        $suites[$sid]['tc_qty']++;
        if (!$it['is_latest']) {
            $suites[$sid]['updatable_qty']++;
        }
    }
    usort($suites, function ($a, $b) { return strnatcasecmp($a['name'], $b['name']); });

    // overall screen state (legacy messages)
    $state = 'ready';
    if ($linkedCount === 0) {
        $state = 'empty';          // lang_get('testplan_seems_empty')
    } else if (count($items) === 0) {
        $state = 'latest';         // lang_get('no_newest_version_of_linked_tcversions')
    }

    $tproject_name = trim((string)($_SESSION['testprojectName'] ?? ''));
    if ($tproject_name === '') {
        $tproject_name = testproject::getName($db, $tproject_id);
    }

    out([
        'status' => 'ok',
        'tproject' => ['id' => $tproject_id, 'name' => $tproject_name],
        'tplans' => $tplans,
        'tplan' => $tplanInfo,
        'suites' => array_values($suites),
        'items' => $items,
        'linked_count' => $linkedCount,
        'state' => $state,
        'rights' => [
            'canManage' => updTcRights($user, $db, $tproject_id),
            'menu_grant' =>
                (bool)$user->hasRight($db,
                    'testplan_update_linked_testcase_versions', $tproject_id),
        ],
    ]);
}

// POST /update-linked/selected  body: {tproject_id,tplan_id,items:{tc_id:new_tcv_id}}
// legacy doUpdate(): remap testplan_tcversions + executions +
// cfield_execution_values to the user-chosen new version.
// Server-side validation per pair (client form values are never trusted):
//   - the test case must still be linked to the plan
//   - the target must be an ACTIVE version of the SAME test case
if ($method === 'POST' && count($segments) === 2 &&
    $segments[0] === 'update-linked' && $segments[1] === 'selected') {
    $body = getBody();
    list($tproject_id, $tplan_id) = updTcNeedCtx($body, $user, $db);
    if (!updTcRights($user, $db, $tproject_id)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }

    $req = [];
    foreach ((array)($body['items'] ?? []) as $tcid => $newTcv) {
        $tcid = intval($tcid);
        $newTcv = intval($newTcv);
        if ($tcid > 0 && $newTcv > 0) {
            $req[$tcid] = $newTcv;
        }
    }
    if (count($req) === 0) {
        http_response_code(422);
        out(['status' => 'error', 'message' => 'No selection provided',
             'error_code' => 'NO_SELECTION']);
    }

    $tbl = tlObjectWithDB::getDBTables(
        ['testplan_tcversions', 'nodes_hierarchy', 'tcversions',
         'executions', 'cfield_execution_values']);

    $updated = 0;
    $skipped = 0;
    foreach ($req as $tcid => $newTcv) {
        // current linked version of THIS test case in THIS plan
        $cur = $db->fetchFirstRow(
            " SELECT T.tcversion_id AS ltcv" .
            " FROM {$tbl['testplan_tcversions']} T" .
            " JOIN {$tbl['nodes_hierarchy']} NHA ON NHA.id = T.tcversion_id" .
            " WHERE T.testplan_id = " . intval($tplan_id) .
            " AND NHA.parent_id = " . intval($tcid));
        if (is_null($cur) || !isset($cur['ltcv'])) {
            $skipped++;
            continue;
        }
        $ltcv = intval($cur['ltcv']);
        if ($ltcv === $newTcv) {
            $skipped++;      // nothing to change
            continue;
        }
        // target must be an ACTIVE sibling version of the same test case
        $chk = $db->fetchFirstRow(
            " SELECT COUNT(*) AS q" .
            " FROM {$tbl['nodes_hierarchy']} NH" .
            " JOIN {$tbl['tcversions']} TV ON TV.id = NH.id" .
            " WHERE NH.parent_id = " . intval($tcid) .
            " AND NH.id = " . intval($newTcv) .
            " AND TV.active = 1");
        if (intval($chk['q']) === 0) {
            $skipped++;
            continue;
        }

        // legacy doUpdate(): three-table remap, old version read live above
        foreach ([$tbl['testplan_tcversions'], $tbl['executions'],
                  $tbl['cfield_execution_values']] as $table2update) {
            $sql = " UPDATE {$table2update}" .
                   " SET tcversion_id = " . intval($newTcv) .
                   " WHERE tcversion_id = " . intval($ltcv) .
                   " AND testplan_id = " . intval($tplan_id);
            $db->exec_query($sql);
        }
        $updated++;
    }

    out([
        'status' => 'ok',
        'updated' => $updated,
        'skipped' => $skipped,
        // legacy feedback lang_get('tplan_updated')
        'feedback_key' => 'tplan_updated',
    ]);
}

// POST /update-linked/latest  body: {tproject_id,tplan_id}
// legacy doBulkUpdateToLatest(): every linked tcversion whose newest ACTIVE
// version differs gets remapped across the same three tables.
if ($method === 'POST' && count($segments) === 2 &&
    $segments[0] === 'update-linked' && $segments[1] === 'latest') {
    $body = getBody();
    list($tproject_id, $tplan_id) = updTcNeedCtx($body, $user, $db);
    if (!updTcRights($user, $db, $tproject_id)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }

    $tplanMgr = new testplan($db);
    $linkedItems = $tplanMgr->get_linked_items_id($tplan_id);
    if (is_null($linkedItems)) {
        // legacy: lang_get('no_testcase_available')
        out(['status' => 'ok', 'updated' => 0,
             'feedback_key' => 'no_testcase_available']);
    }

    $items = $tplanMgr->get_linked_and_newest_tcversions($tplan_id);
    // hoisted out of the per-item loop (#624 finding 5)
    $tbl = tlObjectWithDB::getDBTables(
        ['testplan_tcversions', 'executions', 'cfield_execution_values']);
    $qty = 0;
    foreach ((array)$items as $value) {
        if (intval($value['newest_tcversion_id']) !=
            intval($value['tcversion_id'])) {
            $newtcversion = intval($value['newest_tcversion_id']);
            $tcversionID = intval($value['tcversion_id']);
            foreach ($tbl as $table2update) {
                $sql = " UPDATE {$table2update}" .
                       " SET tcversion_id = " . intval($newtcversion) .
                       " WHERE tcversion_id = " . intval($tcversionID) .
                       " AND testplan_id = " . intval($tplan_id);
                $db->exec_query($sql);
            }
            $qty++;
        }
    }

    out([
        'status' => 'ok',
        'updated' => $qty,
        // legacy: all_versions_where_latest | num_of_updated
        'feedback_key' => $qty == 0 ? 'all_versions_where_latest'
                                    : 'num_of_updated',
    ]);
}

// ---------------------------------------------------------------------------
// Show Newest Test Case Versions screen (modernized newest_tcversions.php)
// Refs #643
//
// READ-ONLY screen, exactly like legacy lib/plan/newest_tcversions.php:
// for every test case linked to the plan whose LINKED version is older than
// the newest ACTIVE version, show linked vs newest + compare/design/history
// links. There is NO write operation on this screen in 1.9.20 (the actual
// "update" lives in planUpdateTC), so the BFF exposes a single GET.
//
// Rights: legacy checkRights() runs pageAccessCheck with rightsAnd =
// ['testplan_planning'] -> same contract here, server-side on every request.
// ---------------------------------------------------------------------------
function ntcvRights($user, $db, $tproject_id) {
    return (bool)$user->hasRight($db, 'testplan_planning', $tproject_id);
}

if ($method === 'GET' && count($segments) === 1 &&
    $segments[0] === 'newest-versions') {
    // context-only mode: tplan_id=0 with valid tproject_id still delivers the
    // plan picker payload (same contract as update-linked, see #624)
    $tplan_id = intval($_GET['tplan_id'] ?? 0);
    $tproject_id = intval($_GET['tproject_id'] ?? 0);

    if ($tproject_id <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test project id',
             'error_code' => 'NO_TPROJECT']);
    }
    $tprojMgr = new testproject($db);
    if (is_null($tprojMgr->get_by_id($tproject_id))) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test project id',
             'error_code' => 'NO_TPROJECT']);
    }
    if (!ntcvRights($user, $db, $tproject_id)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission',
             'error_code' => 'NO_RIGHTS']);
    }

    $tplanMgr = new testplan($db);

    $tplans = [];
    $rows = $user->getAccessibleTestPlans($db, $tproject_id, null,
        ['output' => 'mapfull', 'active' => ACTIVE]);  // review #643 f2: legacy default filters inactive
    foreach ((array)$rows as $r) {
        if (!intval($r['active'])) { continue; }  // belt & braces
        $tplans[] = [
            'id' => intval($r['id']),
            'name' => (string)$r['name'],
            'active' => intval($r['active']),
            'is_public' => intval($r['is_public']),
        ];
    }

    $tcaseMgr = new testcase($db);
    $testcaseCfg = config_get('testcase_cfg');
    $prefix = $tcaseMgr->tproject_mgr->getTestCasePrefix($tproject_id) .
              $testcaseCfg->glue_character;

    $tproject_name = trim((string)($_SESSION['testprojectName'] ?? ''));
    if ($tproject_name === '') {
        $tproject_name = testproject::getName($db, $tproject_id);
    }

    if ($tplan_id <= 0) {
        out([
            'status' => 'ok',
            'state' => 'pick',
            'tproject' => ['id' => $tproject_id, 'name' => $tproject_name],
            'tplans' => $tplans,
            'tplan' => null,
            'tcprefix' => $prefix,
            'linked_count' => 0,
            'items' => [],
        ]);
    }

    $info = $tplanMgr->get_by_id($tplan_id);
    if (is_null($info) || intval($info['parent_id']) !== $tproject_id) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Test plan not in project',
             'error_code' => 'BAD_TPLAN']);
    }

    // legacy parity: get_linked_items_id()/get_linked_and_newest_tcversions()
    // return null (not []) when nothing matches - count(null) is fatal on
    // PHP 8 (see #528); cast to array like the legacy controller does now.
    $linkedItems = (array)$tplanMgr->get_linked_items_id($tplan_id);
    $linkedCount = count($linkedItems);

    $items = [];
    if ($linkedCount > 0) {
        $newestMap =
          (array)$tplanMgr->get_linked_and_newest_tcversions($tplan_id);
        if (count($newestMap) > 0) {
            // suite placement, same path building as legacy controller:
            // drop root node, append trailing separator
            $treeMgr = new tree($db);
            $pathInfo =
              $treeMgr->get_full_path_verbose(array_keys($newestMap));
            foreach ($newestMap as $tcId => $tc) {
                $path = isset($pathInfo[$tcId]) ? $pathInfo[$tcId] : [];
                unset($path[0]);
                $path[] = '';
                $items[] = [
                    'tc_id' => intval($tc['tc_id']),
                    'tcversion_id' => intval($tc['tcversion_id']),
                    'newest_tcversion_id' =>
                        intval($tc['newest_tcversion_id']),
                    'tc_external_id' => intval($tc['tc_external_id']),
                    'name' => (string)$tc['name'],
                    'version' => intval($tc['version']),
                    'newest_version' => intval($tc['newest_version']),
                    'path' => implode(' / ', $path),
                ];
            }
        }
    }

    usort($items, function ($a, $b) {
        return $a['tc_id'] <=> $b['tc_id'];
    });

    out([
        'status' => 'ok',
        // pick | no-linked | all-current | ok  (legacy gui states)
        'state' => count($items) > 0 ? 'ok'
                 : ($linkedCount === 0 ? 'no-linked' : 'all-current'),
        'tproject' => ['id' => $tproject_id, 'name' => $tproject_name],
        'tplans' => $tplans,
        'tplan' => [
            'id' => intval($info['id']),
            'name' => (string)$info['name'],
            'active' => intval($info['active']),
        ],
        'tcprefix' => $prefix,
        'linked_count' => $linkedCount,
        'items' => $items,
    ]);
}

http_response_code(404);
out(['status' => 'error', 'message' => 'Not found']);
