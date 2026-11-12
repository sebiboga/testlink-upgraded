<?php
/**
 * Requirement Specification Create/Edit — BFF API.
 * URL: /api/reqspecedit/index.php
 *
 * Modern twin of the standalone legacy renderer
 * lib/requirements/reqSpecEdit.php (doAction=create|createChild|edit) +
 * gui/templates/dashio/requirements/reqSpecEdit.tpl. The screen is a full-page
 * Dashio editor reached from the Requirement Specification manager
 * ("Full-screen editor") and from legacy bookmarks (the controller is now a
 * GET/HEAD-only 302 shim).
 *
 * Rights (legacy checkRights(): rightsAnd = mgt_view_req + mgt_modify_req) are
 * enforced on the ADDRESSED test project BEFORE it is resolved, so a bogus and
 * a real-but-unreadable project id answer the same opaque 403 (no oracle).
 *
 * Endpoints (JSON in/out):
 *   GET  ?action=init&tproject_id=N[&spec_id=M]
 *        -> context (project, rights, child_requirements_mgmt, req_spec types),
 *           parent candidates (nested-spec creation) and, when spec_id is
 *           given, the latest revision of that specification (edit mode).
 *   POST ?action=save  {tproject_id, spec_id?, parent_id?, doc_id, title,
 *                       type, total_req?, scope}
 *        -> create (spec_id 0/absent) or update (spec_id > 0).
 *
 * Machine codes: 400 invalid_request / missing_field, 401 not_authenticated /
 * session_expired, 403 no_right, 404 tproject_not_found / spec_not_found,
 * 405 method_not_allowed, 409 duplicate_doc_id.
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
require_once(__DIR__ . '/../../lib/functions/requirements.inc.php');
require_once(__DIR__ . '/../../lib/functions/requirement_spec_mgr.class.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

$db = new database(DB_TYPE);
doDBConnect($db);

bffEnforceSession($db);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$action = isset($_REQUEST['action']) && is_scalar($_REQUEST['action'])
    ? trim((string)$_REQUEST['action']) : '';
$BODY = json_decode(file_get_contents('php://input'), true);
if (!is_array($BODY)) { $BODY = []; }

$TL_REQ_SPEC_NODE_TYPE = 6;

function out($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function fail($code, $machine, $message) {
    out(['status' => 'error', 'code' => $machine, 'message' => $message], $code);
}

function reqSpecEditScalar($value, $default = '') {
    return is_scalar($value) ? (string)$value : $default;
}

$userId = intval($_SESSION['userID'] ?? 0);
if ($userId <= 0) {
    fail(401, 'not_authenticated', 'Not authenticated');
}
$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    fail(401, 'not_authenticated', 'User not found');
}

if ($method !== 'GET' && $method !== 'HEAD' && $method !== 'POST') {
    header('Allow: GET, HEAD, POST');
    fail(405, 'method_not_allowed', 'Method not allowed');
}

$tprojectMgr = new testproject($db);
$reqSpecMgr  = new requirement_spec_mgr($db);
$reqCfg      = config_get('req_cfg');

function canViewReqSpec($user, $db, $tprojectId) {
    return $user->hasRight($db, 'mgt_view_req', $tprojectId) ||
           $user->hasRight($db, 'mgt_modify_req', $tprojectId);
}
function canManageReqSpec($user, $db, $tprojectId) {
    return $user->hasRight($db, 'mgt_modify_req', $tprojectId);
}

/** Tolerant boolean read of a req_cfg knob (same as api/reqspec). */
function reqSpecBoolCfg($cfg, $key, $default) {
    if (!isset($cfg->$key)) { return (bool)$default; }
    $v = $cfg->$key;
    if (is_string($v)) {
        $t = strtoupper(trim($v));
        if ($t === 'DISABLED' || $t === 'FALSE' || $t === '') { return false; }
        if ($t === 'ENABLED' || $t === 'TRUE') { return true; }
        return (bool)intval($v);
    }
    return (bool)$v;
}

/** Project name lives on the nodes_hierarchy project node (2.0.1 schema). */
function reqSpecProjectName($db, $tprojectId) {
    $rows = $db->get_recordset(
        'SELECT name FROM nodes_hierarchy WHERE id = ' . intval($tprojectId) .
        ' AND node_type_id = 1 LIMIT 1');
    return ($rows && isset($rows[0]['name'])) ? (string)$rows[0]['name'] : '';
}

/**
 * Address the test project. Rights are checked on the ADDRESSED id before it
 * is resolved (no existence oracle for unentitled callers).
 */
function reqSpecNeedProject($tprojectId) {
    global $db, $user, $tprojectMgr;
    if ($tprojectId <= 0) {
        fail(400, 'invalid_request', 'Invalid test project id');
    }
    if (!canViewReqSpec($user, $db, $tprojectId)) {
        fail(403, 'no_right', 'You are not authorized on this test project');
    }
    $info = $tprojectMgr->get_by_id($tprojectId);
    if (!$info) {
        fail(404, 'tproject_not_found', 'Test project does not exist');
    }
    return $info;
}

/** A specification must exist (latest revision present) and belong to the project. */
function reqSpecNeedOwnedSpec($specId, $tprojectId) {
    global $db;
    if ($specId <= 0) {
        fail(400, 'invalid_request', 'Invalid specification id');
    }
    $rows = $db->get_recordset(
        'SELECT RS.testproject_id FROM req_specs RS' .
        ' JOIN req_specs_revisions RSV ON RSV.parent_id = RS.id' .
        ' WHERE RS.id = ' . intval($specId) . ' LIMIT 1');
    if (!$rows || intval($rows[0]['testproject_id']) !== intval($tprojectId)) {
        fail(404, 'spec_not_found', 'Requirement specification not found');
    }
}

/** Specifications of a project for the nested parent picker. */
function reqSpecListForParent($db, $tprojectId) {
    $rows = $db->get_recordset(
        'SELECT RS.id, RS.doc_id, NH.name AS title, NH.parent_id' .
        ' FROM req_specs RS' .
        ' JOIN nodes_hierarchy NH ON NH.id = RS.id' .
        ' WHERE RS.testproject_id = ' . intval($tprojectId) .
        ' ORDER BY NH.name ASC, RS.id ASC');
    $out = [];
    foreach (($rows ? $rows : []) as $r) {
        $out[] = [
            'id'        => intval($r['id']),
            'doc_id'    => (string)$r['doc_id'],
            'title'     => (string)$r['title'],
            'parent_id' => intval($r['parent_id']),
        ];
    }
    return $out;
}

/** Latest revision of a specification, together with its tree placement. */
function reqSpecLatest($db, $specId, $tprojectId) {
    $rows = $db->get_recordset(
        'SELECT RS.id, RS.doc_id, RS.testproject_id, NH.name AS title, NH.parent_id' .
        ' FROM req_specs RS' .
        ' JOIN nodes_hierarchy NH ON NH.id = RS.id' .
        ' WHERE RS.id = ' . intval($specId) .
        '   AND RS.testproject_id = ' . intval($tprojectId) . ' LIMIT 1');
    if (!$rows || !$rows[0]) { return null; }
    $base = $rows[0];
    $revRows = $db->get_recordset(
        'SELECT revision, scope, type, total_req FROM req_specs_revisions' .
        ' WHERE parent_id = ' . intval($specId) .
        ' ORDER BY revision DESC LIMIT 1');
    $rev = ($revRows && $revRows[0]) ? $revRows[0] : [];
    return [
        'id'         => intval($base['id']),
        'doc_id'     => (string)$base['doc_id'],
        'title'      => (string)$base['title'],
        'parent_id'  => intval($base['parent_id']),
        'revision'   => intval($rev['revision'] ?? 0),
        'scope'      => (string)($rev['scope'] ?? ''),
        'type'       => (string)($rev['type'] ?? ''),
        'total_req'  => intval($rev['total_req'] ?? 0),
    ];
}

function reqSpecTypes() {
    return [
        ['code' => '1', 'label' => 'rse.typeSection'],
        ['code' => '2', 'label' => 'rse.typeUser'],
        ['code' => '3', 'label' => 'rse.typeSystem'],
    ];
}

if ($method === 'GET' || $method === 'HEAD') {
    if ($action !== '' && $action !== 'init') {
        fail(400, 'invalid_request', 'Unknown action');
    }
    $tprojectId = intval($_REQUEST['tproject_id'] ?? 0);
    reqSpecNeedProject($tprojectId);

    // The legacy screen required mgt_view_req AND mgt_modify_req.
    if (!canManageReqSpec($user, $db, $tprojectId)) {
        fail(403, 'no_right', 'You have no right to create or edit requirement specifications');
    }

    $specId = intval($_REQUEST['spec_id'] ?? 0);
    $spec = null;
    if ($specId > 0) {
        reqSpecNeedOwnedSpec($specId, $tprojectId);
        $spec = reqSpecLatest($db, $specId, $tprojectId);
        if ($spec === null) {
            fail(404, 'spec_not_found', 'Requirement specification not found');
        }
    }

    $childSpecMgmt = reqSpecBoolCfg($reqCfg, 'child_requirements_mgmt', false);
    $externalReqMgmt = reqSpecBoolCfg($reqCfg, 'external_req_management', false);

    out([
        'status' => 'ok',
        'context' => [
            'tproject_id'   => $tprojectId,
            'tproject_name' => reqSpecProjectName($db, $tprojectId),
            'spec_id'       => $specId,
            'mode'          => $specId > 0 ? 'edit' : 'create',
        ],
        'rights' => [
            'can_view'   => canViewReqSpec($user, $db, $tprojectId),
            'can_manage' => canManageReqSpec($user, $db, $tprojectId),
        ],
        'settings' => [
            'child_requirements_mgmt' => $childSpecMgmt,
            'external_req_management' => $externalReqMgmt,
        ],
        'types' => reqSpecTypes(),
        'parents' => $childSpecMgmt ? reqSpecListForParent($db, $tprojectId) : [],
        'spec' => $spec,
    ]);
}

// ------------------------------------------------------------------ save ----
if ($action !== 'save') {
    fail(400, 'invalid_request', 'Unknown action');
}

$tprojectId = intval($BODY['tproject_id'] ?? ($_REQUEST['tproject_id'] ?? 0));
if ($tprojectId <= 0) {
    fail(400, 'invalid_request', 'Invalid test project id');
}
// Right is checked on the addressed project BEFORE it is resolved.
if (!canManageReqSpec($user, $db, $tprojectId)) {
    fail(403, 'no_right', 'You have no right to create or edit requirement specifications');
}
reqSpecNeedProject($tprojectId);

$specId  = intval($BODY['spec_id'] ?? 0);
$parentId = intval($BODY['parent_id'] ?? 0);
$docId   = trim(reqSpecEditScalar($BODY['doc_id'] ?? ''));
$title   = trim(reqSpecEditScalar($BODY['title'] ?? ''));
$scope   = reqSpecEditScalar($BODY['scope'] ?? '');
$type    = trim(reqSpecEditScalar($BODY['type'] ?? '1'));
$totalReq = intval($BODY['total_req'] ?? 0);

if ($docId === '') {
    fail(400, 'missing_field', 'Document ID cannot be empty');
}
if ($title === '') {
    fail(400, 'missing_field', 'Title cannot be empty');
}
if (!in_array($type, ['1', '2', '3'], true)) {
    $type = '1';
}
if ($totalReq < 0) { $totalReq = 0; }

if ($specId <= 0) {
    // Create (root-level when no parent, nested when parent_id > 0).
    if ($parentId > 0) {
        reqSpecNeedOwnedSpec($parentId, $tprojectId);
    }
    if ($parentId > 0 && !reqSpecBoolCfg($reqCfg, 'child_requirements_mgmt', false)) {
        fail(400, 'invalid_request', 'Nested requirement specifications are disabled');
    }
    $op = $reqSpecMgr->create(
        $tprojectId,
        $parentId > 0 ? $parentId : $tprojectId,
        $docId, $title, $scope, $totalReq, $userId, $type
    );
    if (empty($op['status_ok'])) {
        $msg = (string)($op['msg'] ?? 'Create failed');
        if (stripos($msg, 'duplicat') !== false || stripos($msg, 'already') !== false) {
            fail(409, 'duplicate_doc_id', $msg);
        }
        fail(400, 'invalid_request', $msg);
    }
    out([
        'status' => 'ok',
        'id'     => intval($op['id']),
        'mode'   => 'create',
    ]);
}

// Update (the parent is not changed by the legacy edit form).
reqSpecNeedOwnedSpec($specId, $tprojectId);
$item = [
    'id'          => $specId,
    'doc_id'      => $docId,
    'name'        => $title,
    'scope'       => $scope,
    'type'        => $type,
    'countReq'    => $totalReq,
    'user_id'     => $userId,
    'modifier_id' => $userId,
    'node_order'  => null,
];
$op = $reqSpecMgr->update($item);
if (empty($op['status_ok'])) {
    $msg = (string)($op['msg'] ?? 'Update failed');
    if (stripos($msg, 'duplicat') !== false || stripos($msg, 'already') !== false) {
        fail(409, 'duplicate_doc_id', $msg);
    }
    fail(400, 'invalid_request', $msg);
}
out(['status' => 'ok', 'id' => $specId, 'mode' => 'edit']);
