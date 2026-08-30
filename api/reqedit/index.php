<?php
/**
 * Requirement Editor BFF API
 * URL: /api/reqedit/index.php
 * Plain PHP, no framework, no compilation
 *
 * Standalone modern screen for lib/requirements/reqEdit.php (TestLink 1.9.20
 * "Requirement editor"). Editing also lives inside the modern reqSpecMgmt
 * modal; this dedicated endpoint powers the standalone reqEdit.html screen
 * that opens from the modernized Advanced Search / search requirement screens
 * (searchAdvancedView.html, searchReq.html, searchReqSpec.html), which used to
 * jump straight to the legacy reqEdit.php controller.
 *
 * Rights (same as legacy reqEdit.php / api/reqspec):
 *   view   -> mgt_view_req OR mgt_modify_req
 *   manage -> mgt_modify_req
 *
 * Endpoints (JSON in/out):
 *   GET  ?action=form&id=N                -> requirement (latest version) + spec + options (edit)
 *   GET  ?action=form&spec_id=N           -> options + spec info (create)
 *   POST ?action=save                     -> create (no id) or update (id) a requirement
 *   POST ?action=version&id=N             -> create a new version of the requirement
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
require_once(__DIR__ . '/../../lib/functions/requirements.inc.php');
require_once(__DIR__ . '/../../lib/functions/requirement_spec_mgr.class.php');
require_once(__DIR__ . '/../../lib/functions/requirement_mgr.class.php');

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
$action = isset($_REQUEST['action']) ? trim($_REQUEST['action']) : '';
$BODY = json_decode(file_get_contents('php://input'), true) ?? [];

function out($data) { echo json_encode($data); exit; }
function badRequest($msg) {
    http_response_code(400);
    out(['status' => 'error', 'message' => $msg]);
}

$tprojectMgr = new testproject($db);
$reqSpecMgr  = new requirement_spec_mgr($db);
$reqMgr      = new requirement_mgr($db);

function canView($user, $db, $tproject_id) {
    return $user->hasRight($db, 'mgt_view_req', $tproject_id) ||
           $user->hasRight($db, 'mgt_modify_req', $tproject_id);
}
function canManage($user, $db, $tproject_id) {
    return $user->hasRight($db, 'mgt_modify_req', $tproject_id);
}

/** Resolve + authorize the test project in context (query string or body). */
function needTprojectId() {
    global $tprojectMgr, $user, $db, $BODY;
    $id = intval($_REQUEST['tproject_id'] ?? ($BODY['tproject_id'] ?? 0));
    if ($id <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test project id']);
    }
    $info = $tprojectMgr->get_by_id($id);
    if (!$info) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Test project does not exist']);
    }
    if (!canView($user, $db, $id)) {
        http_response_code(403);
        out(['status' => 'error',
             'message' => 'You are not authorized to view requirement specifications']);
    }
    return $id;
}

function needManageRight($tproject_id) {
    global $user, $db;
    if (!canManage($user, $db, $tproject_id)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'You have no right to modify requirements']);
    }
}

/** Spec must exist and belong to the test project in context (mirror api/reqspec). */
function needOwnedSpec($specId, $tproject_id, &$reqSpecMgr, &$db) {
    $rows = $db->get_recordset(
        'SELECT RS.testproject_id FROM ' . $reqSpecMgr->object_table . ' RS' .
        ' JOIN req_specs_revisions RSV ON RSV.parent_id = RS.id' .
        ' WHERE RS.id = ' . intval($specId) . ' LIMIT 1');
    if (!$rows || intval($rows[0]['testproject_id']) !== intval($tproject_id)) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Requirement specification not found']);
    }
    return @$reqSpecMgr->get_by_id(intval($specId)) ?: null;
}

/** Localized type/status option maps + defaults, same as api/reqspec options. */
function reqOptions() {
    $cfg = config_get('req_cfg');
    $reqTypes = [];
    foreach ($cfg->type_labels as $code => $labelKey) {
        $reqTypes[(string)$code] = lang_get($labelKey);
    }
    $reqStatuses = [];
    foreach ($cfg->status_labels as $code => $labelKey) {
        $reqStatuses[(string)$code] = lang_get($labelKey);
    }
    return [
        'reqTypes'    => $reqTypes,
        'reqStatuses' => $reqStatuses,
        'defaultReqType'    => TL_REQ_TYPE_FEATURE,
        'defaultReqStatus'  => TL_REQ_STATUS_VALID,
    ];
}

if ($action === '') {
    http_response_code(400);
    out(['status' => 'error', 'message' => 'Missing action']);
}

// ------------------------------------------------------------------ form ---
// GET ?action=form&id=N (edit) | ?action=form&spec_id=N&tproject_id=N (create)
if ($method === 'GET' && $action === 'form') {
    $tproject_id = needTprojectId();
    $options = reqOptions();

    $reqId = intval($_REQUEST['id'] ?? 0);
    if ($reqId > 0) {
        $req = null;
        // resolve owning project from the requirement spec
        $info = $db->get_recordset(
            "SELECT srs.testproject_id FROM requirements r" .
            " JOIN req_specs srs ON srs.id = r.srs_id" .
            " WHERE r.id = " . intval($reqId) . " LIMIT 1");
        if (!$info || intval($info[0]['testproject_id']) !== intval($tproject_id)) {
            http_response_code(404);
            out(['status' => 'error', 'message' => 'Requirement not found or not in project']);
        }
        // loadRequirement uses $tproject_id via a closure-global; pass explicitly
        $rows = $db->get_recordset(
            "SELECT r.id, r.srs_id, r.req_doc_id, nh.name AS title, nh.node_order," .
            " v.scope, v.status, v.type, v.version, v.active, v.is_open," .
            " v.expected_coverage, v.creation_ts, v.modification_ts," .
            " u.login AS author_login, srsnh.name AS spec_title" .
            " FROM requirements r" .
            " JOIN nodes_hierarchy nh ON nh.id = r.id" .
            " JOIN nodes_hierarchy vh ON vh.parent_id = r.id" .
            " JOIN req_versions v ON v.id = vh.id" .
            "   AND v.version = (SELECT MAX(v2.version) FROM req_versions v2" .
            "                    JOIN nodes_hierarchy h2 ON h2.id = v2.id" .
            "                    WHERE h2.parent_id = r.id)" .
            " JOIN nodes_hierarchy srsnh ON srsnh.id = r.srs_id" .
            " LEFT JOIN users u ON u.id = v.author_id" .
            " WHERE r.id = " . intval($reqId) . " LIMIT 1");
        if (!$rows) {
            http_response_code(404);
            out(['status' => 'error', 'message' => 'Requirement not found']);
        }
        $r = $rows[0];
        $req = [
            'id'                => intval($r['id']),
            'srs_id'            => intval($r['srs_id']),
            'req_doc_id'        => (string)$r['req_doc_id'],
            'title'             => (string)$r['title'],
            'scope'             => (string)$r['scope'],
            'status'            => (string)$r['status'],
            'type'              => (string)$r['type'],
            'version'           => intval($r['version']),
            'active'            => intval($r['active']),
            'is_open'           => intval($r['is_open']),
            'expected_coverage' => intval($r['expected_coverage']),
            'author'            => (string)$r['author_login'],
            'spec_title'        => (string)$r['spec_title'],
        ];
        $specId = intval($req['srs_id']);
        out(['status' => 'ok', 'mode' => 'edit', 'requirement' => $req,
             'options' => $options, 'tproject_id' => $tproject_id]);
    }

    // create mode: require a spec in project
    $specId = intval($_REQUEST['spec_id'] ?? 0);
    if ($specId <= 0) {
        badRequest('Missing requirement id or spec id');
    }
    $spec = needOwnedSpec($specId, $tproject_id, $reqSpecMgr, $db);
    $specTitle = '#' . $specId;
    if ($spec && isset($spec['title']) && trim((string)$spec['title']) !== '') {
        $specTitle = (string)$spec['title'];
    } else {
        $st = $db->get_recordset(
            "SELECT RSV.name FROM req_specs_revisions RSV" .
            " WHERE RSV.parent_id = " . intval($specId) .
            " ORDER BY RSV.revision DESC LIMIT 1");
        if ($st && trim((string)$st[0]['name']) !== '') {
            $specTitle = (string)$st[0]['name'];
        }
    }
    out(['status' => 'ok', 'mode' => 'create',
         'requirement' => ['srs_id' => $specId, 'spec_title' => $specTitle,
                           'version' => 0, 'req_doc_id' => '', 'title' => '',
                           'scope' => '', 'status' => $options['defaultReqStatus'],
                           'type' => $options['defaultReqType'], 'expected_coverage' => 1],
         'options' => $options, 'tproject_id' => $tproject_id]);
}

// ------------------------------------------------------------------ save ---
// POST ?action=save  body: {tproject_id, id?, spec_id, req_doc_id, title,
//                           scope, status, type, expected_coverage}
if ($method === 'POST' && $action === 'save') {
    $tproject_id = needTprojectId();
    needManageRight($tproject_id);

    $reqId = intval($BODY['id'] ?? 0);
    $docId = trim((string)($BODY['req_doc_id'] ?? ''));
    $title = trim((string)($BODY['title'] ?? ''));
    if ($docId === '') { badRequest('Document ID cannot be empty'); }
    if ($title === '') { badRequest('Title cannot be empty'); }

    $scope = (string)($BODY['scope'] ?? '');
    $status = strtoupper(trim((string)($BODY['status'] ?? TL_REQ_STATUS_VALID)));
    $type = (string)($BODY['type'] ?? TL_REQ_TYPE_FEATURE);
    $expectedCoverage = max(1, intval($BODY['expected_coverage'] ?? 1));

    if ($reqId > 0) {
        // resolve owning project + latest version
        $info = $db->get_recordset(
            "SELECT srs.testproject_id FROM requirements r" .
            " JOIN req_specs srs ON srs.id = r.srs_id" .
            " WHERE r.id = " . intval($reqId) . " LIMIT 1");
        if (!$info || intval($info[0]['testproject_id']) !== intval($tproject_id)) {
            http_response_code(404);
            out(['status' => 'error', 'message' => 'Requirement not found or not in project']);
        }
        $reqData = $reqMgr->get_by_id(intval($reqId));
        if (!$reqData) {
            http_response_code(404);
            out(['status' => 'error', 'message' => 'Requirement not found']);
        }
        $latest = null;
        foreach ($reqData as $v) {
            if (is_null($latest) || intval($v['version']) > intval($latest['version'])) {
                $latest = $v;
            }
        }
        $op = $reqMgr->update(intval($reqId), intval($latest['version_id']), $docId, $title,
                              $scope, $userId, $status, $type, $expectedCoverage);
        if (!$op['status_ok']) {
            badRequest($op['msg']);
        }
        out(['status' => 'ok', 'mode' => 'update', 'id' => intval($reqId)]);
    } else {
        $specId = intval($BODY['spec_id'] ?? 0);
        if ($specId <= 0) { badRequest('Invalid specification id'); }
        needOwnedSpec($specId, $tproject_id, $reqSpecMgr, $db);
        $op = $reqMgr->create($specId, $docId, $title, $scope, $userId,
                              $status, $type, $expectedCoverage);
        if (!$op['status_ok']) {
            badRequest($op['msg']);
        }
        out(['status' => 'ok', 'mode' => 'create', 'id' => intval($op['id'])]);
    }
}

// --------------------------------------------------------------- version ---
// POST ?action=version&id=N  -> create a new version of the requirement
if ($method === 'POST' && $action === 'version') {
    $tproject_id = needTprojectId();
    needManageRight($tproject_id);

    $reqId = intval($_REQUEST['id'] ?? ($BODY['id'] ?? 0));
    if ($reqId <= 0) { badRequest('Invalid requirement id'); }

    $info = $db->get_recordset(
        "SELECT srs.testproject_id FROM requirements r" .
        " JOIN req_specs srs ON srs.id = r.srs_id" .
        " WHERE r.id = " . intval($reqId) . " LIMIT 1");
    if (!$info || intval($info[0]['testproject_id']) !== intval($tproject_id)) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Requirement not found or not in project']);
    }

    $reqData = $reqMgr->get_by_id(intval($reqId));
    if (!$reqData) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Requirement not found']);
    }
    $latest = null;
    foreach ($reqData as $v) {
        if (is_null($latest) || intval($v['version']) > intval($latest['version'])) {
            $latest = $v;
        }
    }
    $newVersion = intval($latest['version']) + 1;
    $ok = $reqMgr->create_version(intval($reqId), $newVersion, (string)$latest['scope'],
                                  $userId, $latest['status']);
    if (!$ok) {
        http_response_code(500);
        out(['status' => 'error', 'message' => 'Failed to create new version']);
    }
    out(['status' => 'ok', 'version' => $newVersion]);
}

http_response_code(400);
out(['status' => 'error', 'message' => 'Unknown action']);
