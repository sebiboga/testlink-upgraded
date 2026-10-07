<?php
/**
 * Platform Create/Edit BFF API
 * URL: /api/platformedit/
 * Plain PHP, no framework, no compilation.
 *
 * Dedicated, deep-linkable home for the legacy standalone form
 * lib/platforms/platformsEdit.php + gui/templates/dashio/platforms/platformsEdit.tpl.
 * The modern Platform Manager (gui/templates/platforms/platformsView.html) exposes
 * create/edit only as an inline modal; this screen restores the legacy form's own
 * addressable URL and the exact do_action contract it used.
 *
 * Rights (same as legacy):
 *   view   -> platform_view OR platform_management   (platformsView.php)
 *   manage -> platform_management                     (platformsEdit.php checkGUISecurityClearance)
 *
 * Refs #1871.
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

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

$method = $_SERVER['REQUEST_METHOD'];
$action = (string)($_GET['action'] ?? '');

function out($data) { echo json_encode($data); exit; }
function getBody() { return json_decode(file_get_contents('php://input'), true) ?? []; }

function canView($user, $db, $tproject_id) {
    return $user->hasRight($db, 'platform_management', $tproject_id) ||
           $user->hasRight($db, 'platform_view', $tproject_id);
}
function canManage($user, $db, $tproject_id) {
    return $user->hasRight($db, 'platform_management', $tproject_id);
}

function auditDenied($db, $user, $action) {
    // Same convention as api/issuetracker / api/codetracker / api/platformsimport:
    // a denied attempt leaves an Event Viewer trace even though a JSON BFF cannot
    // redirect home the way the legacy page did.
    logAuditEvent(
        TLS('audit_security_user_right_missing', $user->login, basename($_SERVER['PHP_SELF']), $action),
        'AUDIT', $user->dbID, 'user'
    );
}

/**
 * Platform must belong to the addressed test project. Legacy platformsEdit.php
 * re-derived tproject_id from the platform row itself; here the caller states
 * the project and we prove ownership (fail closed with 404).
 */
function needOwnedPlatform($platMgr, $id, $tproject_id) {
    if (!$platMgr->belongsToTestProject($id, $tproject_id)) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Platform not found',
             'error_code' => 'NOT_FOUND']);
    }
    return $platMgr->getPlatform($id);
}

function platMgrFor($db, $tproject_id) {
    return new tlPlatform($db, $tproject_id);
}

$tproject_mgr = new testproject($db);

// ---------------------------------------------------------------------------
// GET ?action=init&tproject_id=N[&platform_id=M][&tplan_id=K]
// ---------------------------------------------------------------------------
if ($method === 'GET' && $action === 'init') {
    $tproject_id = intval($_GET['tproject_id'] ?? 0);
    $platform_id = intval($_GET['platform_id'] ?? 0);
    $tplan_id    = intval($_GET['tplan_id'] ?? 0);

    $mgr = null;
    if ($tproject_id <= 0 && $platform_id > 0) {
        // legacy: tproject_id derived from the platform row
        $info = $db->get_recordset(
            "SELECT testproject_id FROM platforms WHERE id=" . intval($platform_id));
        $tproject_id = intval($info[0]['testproject_id'] ?? 0);
    }
    if ($tproject_id <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test project id',
             'error_code' => 'INVALID_TPROJECT']);
    }

    $tproject = $tproject_mgr->get_by_id($tproject_id);
    if (!$tproject) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Test project not found',
             'error_code' => 'NOT_FOUND']);
    }
    if (!canView($user, $db, $tproject_id)) {
        auditDenied($db, $user, $action);
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission',
             'error_code' => 'NO_RIGHT']);
    }

    $mgr = platMgrFor($db, $tproject_id);

    $mode = $platform_id > 0 ? 'edit' : 'create';
    $platform = [
        'id' => 0, 'name' => '', 'notes' => '',
        'enable_on_design' => 1, 'enable_on_execution' => 1, 'is_open' => 1,
    ];
    if ($platform_id > 0) {
        $row = needOwnedPlatform($mgr, $platform_id, $tproject_id);
        $platform = [
            'id' => intval($row['id']),
            'name' => (string)$row['name'],
            'notes' => (string)($row['notes'] ?? ''),
            'enable_on_design' => intval($row['enable_on_design']),
            'enable_on_execution' => intval($row['enable_on_execution']),
            'is_open' => intval($row['is_open']),
        ];
    }

    out(['status' => 'ok', 'data' => [
        'mode' => $mode,
        'canManage' => canManage($user, $db, $tproject_id),
        'tproject' => ['id' => $tproject_id, 'name' => (string)$tproject['name']],
        'tplan_id' => $tplan_id,
        'platform' => $platform,
    ]]);
}

// ---------------------------------------------------------------------------
// POST ?action=save  body: tproject_id, platform_id(0=create), name, notes,
//                          enable_on_design, enable_on_execution, is_open
// ---------------------------------------------------------------------------
if ($method === 'POST' && $action === 'save') {
    $body = getBody();
    $tproject_id = intval($body['tproject_id'] ?? 0);
    $platform_id = intval($body['platform_id'] ?? 0);
    if ($tproject_id <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test project id',
             'error_code' => 'INVALID_TPROJECT']);
    }
    if (!canManage($user, $db, $tproject_id)) {
        auditDenied($db, $user, $action);
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission',
             'error_code' => 'NO_RIGHT']);
    }

    $name = trim((string)($body['name'] ?? ''));
    if ($name === '') {
        http_response_code(422);
        out(['status' => 'error', 'message' => 'Empty platform name is not allowed',
             'error_code' => 'E_NAMELENGTH']);
    }

    $mgr = platMgrFor($db, $tproject_id);
    $notes = (string)($body['notes'] ?? '');
    $enDesign = !empty($body['enable_on_design']) ? 1 : 0;
    $enExec   = !empty($body['enable_on_execution']) ? 1 : 0;
    $isOpen   = !empty($body['is_open']) ? 1 : 0;

    if ($platform_id > 0) {
        needOwnedPlatform($mgr, $platform_id, $tproject_id);
        // guard against renames that duplicate an existing platform name
        $dupId = $mgr->getID($name);
        if ($dupId && intval($dupId) != $platform_id) {
            http_response_code(422);
            out(['status' => 'error', 'message' => 'Platform name already exists',
                 'error_code' => 'E_NAMEALREADYEXISTS']);
        }
        $result = $mgr->update($platform_id, $name, $notes, $enDesign, $enExec, $isOpen);
        if ($result == tl::OK) {
            out(['status' => 'ok', 'mode' => 'updated', 'id' => $platform_id]);
        }
        http_response_code(422);
        out(['status' => 'error', 'message' => 'Platform update failed',
             'error_code' => intval($result)]);
    }

    $plat = new stdClass();
    $plat->name = $name;
    $plat->notes = $notes;
    $plat->enable_on_design = $enDesign;
    $plat->enable_on_execution = $enExec;
    $plat->is_open = $isOpen;
    try {
        $op = $mgr->create($plat);
    } catch (Exception $e) {
        http_response_code(422);
        out(['status' => 'error', 'message' => 'Empty platform name is not allowed',
             'error_code' => 'E_NAMELENGTH']);
    }
    if ($op['status'] == tl::OK) {
        out(['status' => 'ok', 'mode' => 'created', 'id' => intval($op['id'])]);
    }
    http_response_code(422);
    out(['status' => 'error', 'message' => 'Platform could not be created',
         'error_code' => intval($op['status'])]);
}

// ---------------------------------------------------------------------------
// POST ?action=flag  body: tproject_id, platform_id, field, value
// mirrors enableDesign/disableDesign/enableExec/disableExec/openForExec/closeForExec
// ---------------------------------------------------------------------------
if ($method === 'POST' && $action === 'flag') {
    $body = getBody();
    $tproject_id = intval($body['tproject_id'] ?? 0);
    $platform_id = intval($body['platform_id'] ?? 0);
    if ($tproject_id <= 0 || $platform_id <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid parameters',
             'error_code' => 'INVALID_PARAM']);
    }
    if (!canManage($user, $db, $tproject_id)) {
        auditDenied($db, $user, $action);
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission',
             'error_code' => 'NO_RIGHT']);
    }

    $fieldMap = [
        'enable_on_design' => ['on' => 'enableDesign', 'off' => 'disableDesign'],
        'enable_on_execution' => ['on' => 'enableExec', 'off' => 'disableExec'],
        'is_open' => ['on' => 'openForExec', 'off' => 'closeForExec'],
    ];
    $field = (string)($body['field'] ?? '');
    if (!isset($fieldMap[$field])) {
        http_response_code(422);
        out(['status' => 'error', 'message' => 'Unknown flag field',
             'error_code' => 'UNKNOWN_FIELD']);
    }

    $mgr = platMgrFor($db, $tproject_id);
    needOwnedPlatform($mgr, $platform_id, $tproject_id);
    $value = !empty($body['value']) ? 1 : 0;
    $pfn = $fieldMap[$field][$value ? 'on' : 'off'];
    $mgr->$pfn($platform_id);
    out(['status' => 'ok', 'field' => $field, 'value' => $value]);
}

// ---------------------------------------------------------------------------
// POST ?action=delete  body: tproject_id, platform_id
// ---------------------------------------------------------------------------
if ($method === 'POST' && $action === 'delete') {
    $body = getBody();
    $tproject_id = intval($body['tproject_id'] ?? 0);
    $platform_id = intval($body['platform_id'] ?? 0);
    if ($tproject_id <= 0 || $platform_id <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid parameters',
             'error_code' => 'INVALID_PARAM']);
    }
    if (!canManage($user, $db, $tproject_id)) {
        auditDenied($db, $user, $action);
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission',
             'error_code' => 'NO_RIGHT']);
    }

    $mgr = platMgrFor($db, $tproject_id);
    $p = needOwnedPlatform($mgr, $platform_id, $tproject_id);

    // Same gate as api/platforms DELETE (Refs #1637): pass null for the three
    // enable filters so getAll() returns the UNFILTERED project platform set,
    // otherwise a platform with is_open=0/enable_on_execution=0 is missed.
    $all = $mgr->getAll(['include_linked_count' => true,
                         'enable_on_design'    => null,
                         'enable_on_execution' => null,
                         'is_open'             => null]);
    $linked = 0;
    $found = false;
    foreach ((array)$all as $row) {
        if (intval($row['id']) === $platform_id) {
            $linked = intval($row['linked_count']);
            $found = true;
            break;
        }
    }
    if (!$found) {
        http_response_code(500);
        out(['status' => 'error',
             'message' => 'Platform usage could not be determined, delete refused',
             'error_code' => 'DELETE_CHECK_FAILED']);
    }
    if ($linked > 0) {
        http_response_code(422);
        out(['status' => 'error',
             'message' => 'Platform is being used by test plans and cannot be removed',
             'error_code' => 'DELETE_BLOCKED']);
    }

    if ($mgr->delete($platform_id) == tl::OK) {
        out(['status' => 'ok', 'id' => $platform_id, 'name' => (string)$p['name']]);
    }
    http_response_code(422);
    out(['status' => 'error', 'message' => 'Platform delete failed',
         'error_code' => 'E_DBERROR']);
}

if (!in_array($method, ['GET', 'POST'], true)) {
    http_response_code(405);
    out(['status' => 'error', 'message' => 'Method not allowed',
         'error_code' => 'METHOD_NOT_ALLOWED']);
}

http_response_code(400);
out(['status' => 'error', 'message' => 'Unknown action', 'error_code' => 'UNKNOWN_ACTION']);