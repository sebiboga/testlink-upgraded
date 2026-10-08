<?php
/**
 * Test Plan Create/Edit standalone screen BFF (Refs #1882)
 * URL: /api/planedit/?action=init|save
 *
 * Modern twin of lib/plan/planEdit.php + gui/templates/dashio/plan/planEdit.tpl
 * (the standalone 1.9.20 Create/Edit test plan screen, still a full legacy
 * Smarty renderer at every deep link until this screen landed).
 *
 * Legacy parity:
 *   - rights: mgt_testplan_create on the ADDRESSED test project for every
 *     route (planEdit.php init_args() -> checkGUISecurityClearance
 *     array('mgt_testplan_create'),'and' - create AND edit AND attachments);
 *   - name rules: required, unique per project; on UPDATE the current
 *     plan's own name is allowed (initializeGui() nameCanBeUsed logic);
 *   - private plan -> creator gets his effective role on the plan when he
 *     has no explicit plan role (planEdit.php:93-101 / 151-158);
 *   - audit events audit_testplan_created / audit_testplan_saved;
 *   - create may COPY from an existing plan of the SAME project with the
 *     full legacy copy option set (planEdit.php:142-149 ->
 *     testplan::copy_as() with items2copy/copy_assigned_to/tcversion_type);
 *   - edit mode returns the plan api_key (legacy planEdit.tpl:123-130).
 *
 * Attachments (legacy do_action=fileUpload|deleteFile) are served by the
 * already-generic api/attachments/index.php (table=testplans&id=<plan>) -
 * 'testplans' is one of its whitelisted fk tables and its owner check
 * (api/_attachauth.php) enforces the same mgt_testplan_create gate.
 *
 * HTTP contract: 400 invalid/missing ids, 401 anonymous or expired session,
 * 403 no right / CSRF, 404 unknown plan or project, 405 unknown action or
 * non-GET on init, 409 duplicate name, 500 guarded.
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json');

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = intval($_SESSION['userID'] ?? 0);
if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated',
                      'error_code' => 'UNAUTHENTICATED']);
    exit;
}

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated',
                      'error_code' => 'UNAUTHENTICATED']);
    exit;
}

function out($data, $code = null) {
    if ($code !== null) {
        http_response_code($code);
    }
    echo json_encode($data);
    exit;
}

function fail($code, $status, $message, $errorCode = null) {
    $payload = ['status' => $status, 'message' => $message];
    if ($errorCode !== null) {
        $payload['error_code'] = $errorCode;
    }
    out($payload, $code);
}

/**
 * Legacy checkGUISecurityClearance(..., array('mgt_testplan_create'),'and')
 * on the addressed project. A denial is audited so it still shows up in the
 * Event Viewer (a JSON BFF cannot redirect home like legacy did).
 */
function peRequireManage($db, $user, $tproject_id) {
    if (!$user->hasRight($db, 'mgt_testplan_create', intval($tproject_id))) {
        logAuditEvent('audit_security_user_right_missing',
                      'mgt_testplan_create', 0, 'testprojects');
        fail(403, 'error', 'No permission: mgt_testplan_create required',
             'NO_RIGHT');
    }
}

$method = $_SERVER['REQUEST_METHOD'];
$action = strval($_GET['action'] ?? '');

if ($action === 'init') {
    if ($method !== 'GET' && $method !== 'HEAD') {
        fail(405, 'error', 'Method not allowed', 'METHOD_NOT_ALLOWED');
    }
    bffEnforceSession($db);

    $itemID = intval($_GET['itemID'] ?? 0);
    $tproject_id = intval($_GET['tproject_id'] ?? 0);

    $plan = null;
    if ($itemID > 0) {
        $tplanMgr = new testplan($db);
        $rows = $tplanMgr->get_by_id($itemID);
        if (empty($rows)) {
            fail(404, 'error', 'Test plan not found', 'PLAN_NOT_FOUND');
        }
        $plan = $rows;
        $planProjectId = intval($plan['testproject_id']);
        if ($tproject_id > 0 && $tproject_id !== $planProjectId) {
            fail(400, 'error', 'Test plan does not belong to the given test project',
                 'PROJECT_MISMATCH');
        }
        $tproject_id = $planProjectId;
    }

    if ($tproject_id <= 0) {
        fail(400, 'error', 'Invalid test project id', 'INVALID_TPROJECT');
    }

    $tpMgr = new testproject($db);
    $tpRow = $tpMgr->get_by_id($tproject_id);
    if (empty($tpRow)) {
        fail(404, 'error', 'Test project not found', 'PROJECT_NOT_FOUND');
    }
    $tprojectName = testproject::getName($db, $tproject_id);

    peRequireManage($db, $user, $tproject_id);

    $tplanMgr = new testplan($db);
    $payload = [
        'status' => 'ok',
        'mode' => $plan ? 'edit' : 'create',
        'tproject' => ['id' => $tproject_id, 'name' => $tprojectName],
        'plan' => null,
        'copy_sources' => [],
        'defaults' => [],
        'rights' => [
            'canCreate' => true,
            'canViewEvents' => (bool)$user->hasRight($db, 'mgt_view_events'),
        ],
        'maxUpload' => defined('TL_REPOSITORY_MAXFILESIZE')
            ? intval(TL_REPOSITORY_MAXFILESIZE) : 10240,
        'attachments' => [],
    ];

    if ($plan) {
        $payload['plan'] = [
            'id' => intval($plan['id']),
            'name' => strval($plan['name'] ?? ''),
            'notes' => strval($plan['notes'] ?? ''),
            'active' => intval($plan['active'] ?? 0),
            'is_public' => intval($plan['is_public'] ?? 0),
            'api_key' => strval($plan['api_key'] ?? ''),
        ];
        $payload['attachments'] = peAttachmentRows($db, $itemID);
    } else {
        // Legacy planEdit.php case 'create': template contents as the
        // starting notes body (getItemTemplateContents('testplan_template')).
        $templateNotes = '';
        if (function_exists('getItemTemplateContents')) {
            $templateNotes = strval(
                getItemTemplateContents('testplan_template', 'notes', ''));
        }
        $payload['defaults'] = ['notes' => $templateNotes];

        // Copy sources: every test plan accessible on this project
        // (legacy gui->tplans = getAccessibleTestPlans(tproject_id)).
        $plans = $user->getAccessibleTestPlans(
            $db, $tproject_id, null,
            ['output' => 'mapfull', 'active' => null]);
        if (!is_null($plans)) {
            foreach ($plans as $pid => $prow) {
                $payload['copy_sources'][] = [
                    'id' => intval($pid),
                    'name' => strval($prow['name'] ?? ('#' . $pid)),
                ];
            }
        }
    }

    out($payload);
}

if ($action === 'save') {
    if ($method !== 'POST') {
        fail(405, 'error', 'Method not allowed', 'METHOD_NOT_ALLOWED');
    }
    bffEnforceSession($db);

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        fail(400, 'error', 'Invalid JSON body', 'BAD_BODY');
    }

    $itemID = intval($body['itemID'] ?? 0);
    $tproject_id = intval($body['tproject_id'] ?? 0);
    $name = trim(strval($body['name'] ?? ''));
    $notes = strval($body['notes'] ?? '');
    $active = !empty($body['active']) ? 1 : 0;
    $isPublic = !empty($body['is_public']) ? 1 : 0;

    $tplanMgr = new testplan($db);
    $tpMgr = new testproject($db);

    $plan = null;
    if ($itemID > 0) {
        $rows = $tplanMgr->get_by_id($itemID);
        if (empty($rows)) {
            fail(404, 'error', 'Test plan not found', 'PLAN_NOT_FOUND');
        }
        $plan = $rows;
        $planProjectId = intval($plan['testproject_id']);
        if ($tproject_id > 0 && $tproject_id !== $planProjectId) {
            fail(400, 'error', 'Test plan does not belong to the given test project',
                 'PROJECT_MISMATCH');
        }
        $tproject_id = $planProjectId;
    }

    if ($tproject_id <= 0) {
        fail(400, 'error', 'Invalid test project id', 'INVALID_TPROJECT');
    }
    if (empty($tpMgr->get_by_id($tproject_id))) {
        fail(404, 'error', 'Test project not found', 'PROJECT_NOT_FOUND');
    }

    peRequireManage($db, $user, $tproject_id);

    if ($name === '') {
        fail(400, 'error', 'Test plan name is required', 'NAME_REQUIRED');
    }

    // Legacy initializeGui() nameCanBeUsed: create -> must not exist;
    // update -> may exist only when it is THIS plan's own name.
    $nameExists = (bool)$tpMgr->check_tplan_name_existence($tproject_id, $name);
    if ($nameExists) {
        $ownName = $plan && strval($plan['name'] ?? '') === $name;
        if (!$plan || !$ownName) {
            fail(409, 'error',
                 'A test plan with this name already exists', 'DUPLICATE_NAME');
        }
    }

    $tprojectName = testproject::getName($db, $tproject_id);

    if ($plan) {
        // ---------------------------------------------------- update
        if (!$tplanMgr->update($itemID, $name, $notes, $active, $isPublic)) {
            fail(500, 'error', 'Failed to update test plan: ' .
                 $db->error_msg(), 'UPDATE_FAILED');
        }
        logAuditEvent(TLS('audit_testplan_saved', $tprojectName, $name),
                      'SAVE', $itemID, 'testplans');

        if (!$isPublic) {
            if (!tlUser::hasRoleOnTestPlan($db, $userId, $itemID)) {
                $effectiveRole = $user->getEffectiveRole($db, $tproject_id, null);
                if (!is_null($effectiveRole)) {
                    $tplanMgr->addUserRole($userId, $itemID,
                                           $effectiveRole->dbID);
                }
            }
        }
        out(['status' => 'ok', 'id' => intval($itemID), 'name' => $name]);
    }

    // -------------------------------------------------------- create
    $newId = $tplanMgr->create($name, $notes, $tproject_id, $active, $isPublic);
    if ($newId == 0) {
        fail(500, 'error', 'Failed to create test plan: ' .
             $db->error_msg(), 'CREATE_FAILED');
    }
    logAuditEvent(TLS('audit_testplan_created', $tprojectName, $name),
                  'CREATED', $newId, 'testplans');

    // Copy from an existing plan (legacy planEdit.php:142-149).
    $copyFrom = intval($body['copy_from_tplan_id'] ?? 0);
    if ($copyFrom > 0) {
        $source = $tplanMgr->get_by_id($copyFrom);
        if (empty($source)) {
            fail(404, 'error', 'Source test plan not found',
                 'COPY_SOURCE_NOT_FOUND');
        }
        if (intval($source['testproject_id']) !== $tproject_id) {
            fail(400, 'error',
                 'Source test plan belongs to another test project',
                 'COPY_SOURCE_MISMATCH');
        }
        $copt = is_array($body['copy_options'] ?? null)
            ? $body['copy_options'] : [];
        $allowed = ['copy_tcases', 'copy_priorities', 'copy_milestones',
                    'copy_user_roles', 'copy_builds', 'copy_platforms_links',
                    'copy_attachments'];
        $copyOptions = [];
        foreach ($allowed as $key) {
            $copyOptions[$key] = !empty($copt[$key]) ? 1 : 0;
        }
        $tcversionType = strval($body['tcversion_type'] ?? 'current');
        if (!in_array($tcversionType, ['current', 'latest'], true)) {
            $tcversionType = 'current';
        }
        $options = [
            'items2copy' => $copyOptions,
            'copy_assigned_to' => !empty($body['copy_assigned_to']) ? 1 : 0,
            'tcversion_type' => $tcversionType,
        ];
        $tplanMgr->copy_as($copyFrom, $newId, $name, $tproject_id, $userId,
                           $options);
    }

    if (!$isPublic) {
        if (!tlUser::hasRoleOnTestPlan($db, $userId, $newId)) {
            $effectiveRole = $user->getEffectiveRole($db, $tproject_id, null);
            if (!is_null($effectiveRole)) {
                $tplanMgr->addUserRole($userId, $newId, $effectiveRole->dbID);
            }
        }
    }

    out(['status' => 'ok', 'id' => intval($newId), 'name' => $name]);
}

/**
 * Attachment rows for the edit card - same shape api/attachments returns,
 * so the screen could switch to that endpoint without a contract change.
 */
function peAttachmentRows($db, $planId) {
    $attTables = tlObjectWithDB::getDBTables(['attachments']);
    $rows = $db->get_recordset(
        "SELECT id, title, file_name, file_type, file_size, date_added " .
        "FROM {$attTables['attachments']} " .
        "WHERE fk_id = " . intval($planId) .
        " AND fk_table = 'testplans' ORDER BY id");
    $out = [];
    if (!is_null($rows)) {
        foreach ($rows as $r) {
            $out[] = [
                'id' => intval($r['id']),
                'title' => strval($r['title'] ?? ''),
                'file_name' => strval($r['file_name'] ?? ''),
                'file_type' => strval($r['file_type'] ?? ''),
                'file_size' => intval($r['file_size'] ?? 0),
                'date_added' => strval($r['date_added'] ?? ''),
            ];
        }
    }
    return $out;
}

fail(400, 'error', 'Unknown action', 'UNKNOWN_ACTION');
