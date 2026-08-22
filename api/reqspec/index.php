<?php
/**
 * Requirement Specification Management BFF API
 * URL: /api/reqspec/index.php
 * Plain PHP, no framework, no compilation
 *
 * Mirrors lib/requirements/reqSpecListTree.php + reqSpecEdit.php + reqEdit.php
 * (TestLink 1.9.20 "Requirement Specification Management" screen, feature
 * reqSpecMgmt launched from frmWorkArea.php).
 *
 * Rights split (same as the legacy screens):
 *   view   -> mgt_view_req OR mgt_modify_req
 *   manage -> mgt_modify_req
 *
 * Endpoints (JSON in/out):
 *   GET  ?action=options&tproject_id=N     -> domains, defaults, rights, project name
 *   GET  ?action=specs&tproject_id=N       -> list of requirement specs (latest revision)
 *   POST ?action=create_spec               {tproject_id,doc_id,title,type,total_req,scope}
 *   POST ?action=update_spec&id=N          {tproject_id,doc_id,title,type,total_req,scope}
 *   POST ?action=delete_spec&id=N&tproject_id=N
 *   GET  ?action=reqs&spec_id=N&tproject_id=N -> requirements of a spec (latest version)
 *   POST ?action=create_req                {tproject_id,spec_id,req_doc_id,title,status,type,expected_coverage,scope}
 *   POST ?action=update_req&id=N           {tproject_id,req_doc_id,title,status,type,expected_coverage,scope}
 *   POST ?action=delete_req&id=N&tproject_id=N
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

$tprojectMgr = new testproject($db);
$reqSpecMgr  = new requirement_spec_mgr($db);
$reqMgr      = new requirement_mgr($db);

// Legacy reqSpecListTree.php checkRights(): mgt_view_req OR mgt_modify_req to see
// the tree, every write action requires mgt_modify_req.
function canView($user, $db, $tproject_id) {
    return $user->hasRight($db, 'mgt_view_req', $tproject_id) ||
           $user->hasRight($db, 'mgt_modify_req', $tproject_id);
}
function canManage($user, $db, $tproject_id) {
    return $user->hasRight($db, 'mgt_modify_req', $tproject_id);
}

/**
 * Test project in context must exist; viewer rights enforced.
 * Reads tproject_id from query string or JSON body.
 */
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

/**
 * Spec must exist AND belong to test project in context.
 */
function needOwnedSpec($specId, $tproject_id) {
    global $reqSpecMgr, $db;
    // requirement_spec_mgr::get_by_id() fatals when the spec does not exist
    // (get_last_child_info() returns null -> E_WARNING + broken SQL), so
    // probe existence with a plain query first. Refs #569
    $rows = $db->get_recordset(
        'SELECT testproject_id FROM ' . $reqSpecMgr->object_table .
        ' WHERE id = ' . intval($specId));
    if (!$rows || intval($rows[0]['testproject_id']) !== intval($tproject_id)) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Requirement specification not found']);
    }
    return $reqSpecMgr->get_by_id($specId);
}

function badRequest($msg) {
    http_response_code(400);
    out(['status' => 'error', 'message' => $msg]);
}

if ($action === '' ) {
    http_response_code(400);
    out(['status' => 'error', 'message' => 'Missing action']);
}

// ---------------------------------------------------------------- options ---
if ($method === 'GET' && $action === 'options') {
    $tproject_id = needTprojectId();

    $cfg = config_get('req_cfg');
    $specCfg = config_get('req_spec_cfg');

    // localized labels are resolved server-side with the user session locale
    $specTypes = [];
    foreach ($specCfg->type_labels as $code => $labelKey) {
        $specTypes[(string)$code] = lang_get($labelKey);
    }
    $reqTypes = [];
    foreach ($cfg->type_labels as $code => $labelKey) {
        $reqTypes[(string)$code] = lang_get($labelKey);
    }
    $reqStatuses = [];
    foreach ($cfg->status_labels as $code => $labelKey) {
        $reqStatuses[(string)$code] = lang_get($labelKey);
    }

    $info = $tprojectMgr->get_by_id($tproject_id);

    out([
        'status' => 'ok',
        'tproject_id' => $tproject_id,
        'tproject_name' => $info['name'],
        'specTypes' => $specTypes,
        'reqTypes' => $reqTypes,
        'reqStatuses' => $reqStatuses,
        'defaultSpecType' => TL_REQ_SPEC_TYPE_SECTION,
        'defaultReqType' => TL_REQ_TYPE_FEATURE,
        'defaultReqStatus' => TL_REQ_STATUS_VALID,
        'rights' => [
            'view'   => canView($user, $db, $tproject_id),
            'manage' => canManage($user, $db, $tproject_id),
        ],
    ]);
}

// ------------------------------------------------------------------ specs ---
if ($method === 'GET' && $action === 'specs') {
    $tproject_id = needTprojectId();

    $sql = "SELECT rs.id, rs.doc_id, nh.name AS title, nh.node_order," .
           " latest.scope, latest.type, latest.total_req, latest.revision," .
           " latest.creation_ts, latest.modification_ts, u.login AS author_login," .
           " COALESCE(rc.cnt, 0) AS req_count," .
           " (SELECT COUNT(*) FROM req_specs_revisions x" .
           "  WHERE x.parent_id = rs.id) AS revisions_cnt" .
           " FROM req_specs rs" .
           " JOIN nodes_hierarchy nh ON nh.id = rs.id" .
           " JOIN req_specs_revisions latest" .
           "      ON latest.parent_id = rs.id" .
           "     AND latest.revision = (SELECT MAX(r2.revision)" .
           "         FROM req_specs_revisions r2" .
           "         WHERE r2.parent_id = rs.id)" .
           " LEFT JOIN users u ON u.id = latest.author_id" .
           " LEFT JOIN (SELECT srs_id, COUNT(*) cnt FROM requirements" .
           "            GROUP BY srs_id) rc ON rc.srs_id = rs.id" .
           " WHERE rs.testproject_id = " . intval($tproject_id) .
           " ORDER BY nh.node_order ASC, nh.id ASC";

    $rows = $db->get_recordset($sql);

    $out = [];
    foreach (($rows ? $rows : []) as $r) {
        $out[] = [
            'id'             => intval($r['id']),
            'doc_id'         => (string)$r['doc_id'],
            'title'          => (string)$r['title'],
            'scope'          => (string)$r['scope'],
            'type'           => (string)$r['type'],
            'total_req'      => intval($r['total_req']),
            'revision'       => intval($r['revision']),
            'revisions_cnt'  => intval($r['revisions_cnt']),
            'creation_ts'    => (string)$r['creation_ts'],
            'modification_ts'=> (string)$r['modification_ts'],
            'author'         => (string)$r['author_login'],
            'req_count'      => intval($r['req_count']),
        ];
    }
    out(['status' => 'ok', 'specs' => $out]);
}

if ($method === 'POST' && $action === 'create_spec') {
    $tproject_id = needTprojectId();
    needManageRight($tproject_id);

    $docId  = trim((string)($BODY['doc_id'] ?? ''));
    $title  = trim((string)($BODY['title'] ?? ''));
    $scope  = (string)($BODY['scope'] ?? '');
    $type   = (string)($BODY['type'] ?? TL_REQ_SPEC_TYPE_SECTION);
    $countReq = intval($BODY['total_req'] ?? 0);

    if ($docId === '') { badRequest('Document ID cannot be empty'); }
    if ($title === '') { badRequest(lang_get('warning_empty_req_title')); }

    // Specs attach directly to the test project node (same as legacy
    // reqSpecCommands root-level create). Parent 0 leaves the node orphaned:
    // get_all_requirement_ids() and every tree walk never find it. Refs #569
    $op = $reqSpecMgr->create($tproject_id, $tproject_id, $docId, $title, $scope,
                              $countReq, $userId, $type);
    if (!$op['status_ok']) {
        badRequest($op['msg']);
    }
    out(['status' => 'ok', 'id' => intval($op['id'])]);
}

if ($method === 'POST' && $action === 'update_spec') {
    $tproject_id = needTprojectId();
    needManageRight($tproject_id);

    $specId = intval($_REQUEST['id'] ?? 0);
    if ($specId <= 0) { badRequest('Invalid specification id'); }
    needOwnedSpec($specId, $tproject_id);

    $item = [
        'id'          => $specId,
        'doc_id'      => trim((string)($BODY['doc_id'] ?? '')),
        'name'        => trim((string)($BODY['title'] ?? '')),
        'scope'       => (string)($BODY['scope'] ?? ''),
        'type'        => (string)($BODY['type'] ?? TL_REQ_SPEC_TYPE_SECTION),
        'countReq'    => intval($BODY['total_req'] ?? 0),
        'user_id'     => $userId,
        'modifier_id' => $userId,
        'node_order'  => null,
    ];
    if ($item['doc_id'] === '') { badRequest('Document ID cannot be empty'); }
    if ($item['name'] === '') { badRequest('Title cannot be empty'); }

    $op = $reqSpecMgr->update($item);
    if (!$op['status_ok']) {
        badRequest($op['msg']);
    }
    out(['status' => 'ok']);
}

if ($method === 'POST' && $action === 'delete_spec') {
    $tproject_id = needTprojectId();
    needManageRight($tproject_id);

    $specId = intval($_REQUEST['id'] ?? 0);
    if ($specId <= 0) { badRequest('Invalid specification id'); }
    needOwnedSpec($specId, $tproject_id);

    // legacy reqSpecCommands::doDelete uses delete_deep()
    // (removes requirements, versions, revisions and coverage too)
    $reqSpecMgr->delete_deep($specId);
    out(['status' => 'ok']);
}

// ----------------------------------------------------------- requirements ---
if ($method === 'GET' && $action === 'reqs') {
    $tproject_id = needTprojectId();
    $specId = intval($_REQUEST['spec_id'] ?? 0);
    if ($specId <= 0) { badRequest('Invalid specification id'); }
    needOwnedSpec($specId, $tproject_id);

        $sql = "SELECT r.id, r.srs_id, r.req_doc_id, nh.name AS title, nh.node_order," .
           " v.scope, v.status, v.type, v.version, v.active, v.is_open," .
           " v.expected_coverage, v.creation_ts, v.modification_ts," .
           " u.login AS author_login" .
           " FROM requirements r" .
           " JOIN nodes_hierarchy nh ON nh.id = r.id" .
           // requirement versions hang off the requirement node via nodes_hierarchy
           " JOIN nodes_hierarchy vh ON vh.parent_id = r.id" .
           " JOIN req_versions v ON v.id = vh.id" .
           "     AND v.version = (SELECT MAX(v2.version) FROM req_versions v2" .
           "                      JOIN nodes_hierarchy h2 ON h2.id = v2.id" .
           "                      WHERE h2.parent_id = r.id)" .
           " LEFT JOIN users u ON u.id = v.author_id" .
           " WHERE r.srs_id = " . intval($specId) .
           " ORDER BY nh.node_order ASC, r.id ASC";

    $rows = $db->get_recordset($sql);

    $out = [];
    foreach (($rows ? $rows : []) as $r) {
        $out[] = [
            'id'                => intval($r['id']),
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
        ];
    }
    out(['status' => 'ok', 'requirements' => $out]);
}

if ($method === 'POST' && $action === 'create_req') {
    $tproject_id = needTprojectId();
    needManageRight($tproject_id);

    $specId = intval($BODY['spec_id'] ?? 0);
    if ($specId <= 0) { badRequest('Invalid specification id'); }
    needOwnedSpec($specId, $tproject_id);

    $docId = trim((string)($BODY['req_doc_id'] ?? ''));
    $title = trim((string)($BODY['title'] ?? ''));
    if ($docId === '') { badRequest('Document ID cannot be empty'); }
    if ($title === '') { badRequest('Title cannot be empty'); }

    $scope = (string)($BODY['scope'] ?? '');
    $status = strtoupper(trim((string)($BODY['status'] ?? TL_REQ_STATUS_VALID)));
    $type = (string)($BODY['type'] ?? TL_REQ_TYPE_FEATURE);
    $expectedCoverage = max(1, intval($BODY['expected_coverage'] ?? 1));

    $op = $reqMgr->create($specId, $docId, $title, $scope, $userId,
                          $status, $type, $expectedCoverage);
    if (!$op['status_ok']) {
        badRequest($op['msg']);
    }
    out(['status' => 'ok', 'id' => intval($op['id'])]);
}

if ($method === 'POST' && $action === 'update_req') {
    $tproject_id = needTprojectId();
    needManageRight($tproject_id);

    $reqId = intval($_REQUEST['id'] ?? 0);
    if ($reqId <= 0) { badRequest('Invalid requirement id'); }

    $info = $reqMgr->get_by_id($reqId);
    if (!$info) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Requirement not found']);
    }
    $req = $info[0];
    needOwnedSpec(intval($req['srs_id']), $tproject_id);

    // update applies to the LATEST version (legacy reqEdit.php default behaviour)
    $latestVersion = null;
    foreach ($info as $v) {
        if (is_null($latestVersion) || intval($v['version']) > intval($latestVersion['version'])) {
            $latestVersion = $v;
        }
    }

    $docId = trim((string)($BODY['req_doc_id'] ?? ''));
    $title = trim((string)($BODY['title'] ?? ''));
    if ($docId === '') { badRequest('Document ID cannot be empty'); }
    if ($title === '') { badRequest('Title cannot be empty'); }

    $scope = (string)($BODY['scope'] ?? '');
    $status = strtoupper(trim((string)($BODY['status'] ?? $latestVersion['status'])));
    $type = (string)($BODY['type'] ?? $latestVersion['type']);
    $expectedCoverage = max(1, intval(
        $BODY['expected_coverage'] !== null && $BODY['expected_coverage'] !== ''
            ? intval($BODY['expected_coverage'])
            : intval($latestVersion['expected_coverage'])));

    // get_by_id() exposes the version node id under 'version_id'
    $op = $reqMgr->update($reqId, intval($latestVersion['version_id']), $docId, $title,
                          $scope, $userId, $status, $type, $expectedCoverage);
    if (!$op['status_ok']) {
        badRequest($op['msg']);
    }
    out(['status' => 'ok']);
}

if ($method === 'POST' && $action === 'delete_req') {
    $tproject_id = needTprojectId();
    needManageRight($tproject_id);

    $reqId = intval($_REQUEST['id'] ?? 0);
    if ($reqId <= 0) { badRequest('Invalid requirement id'); }

    $info = $reqMgr->get_by_id($reqId);
    if (!$info) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Requirement not found']);
    }
    $req = $info[0];
    needOwnedSpec(intval($req['srs_id']), $tproject_id);

    // legacy reqCommands::doDelete uses delete() with ALL_VERSIONS
    $reqMgr->delete($reqId);
    out(['status' => 'ok']);
}

http_response_code(404);
out(['status' => 'error', 'message' => 'Unknown action']);
