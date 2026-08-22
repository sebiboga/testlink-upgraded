<?php
/**
 * Requirement Specification Management BFF API
 * URL: /api/reqspec/
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
 * Endpoints:
 *   GET    /options?tproject_id=N            -> type/status domains + rights
 *   GET    /specs?tproject_id=N              -> list of requirement specs (latest revision)
 *   POST   /specs                            -> create spec        {tproject_id,doc_id,title,scope,type,total_req}
 *   PUT    /specs/{id}                       -> update spec        {doc_id,title,scope,type,total_req}
 *   DELETE /specs/{id}                       -> delete spec (deep: requirements included)
 *   GET    /specs/{id}/requirements          -> list of requirements of a spec (latest version)
 *   POST   /specs/{id}/requirements          -> create requirement {req_doc_id,title,scope,status,type}
 *   PUT    /requirements/{id}                -> update requirement latest version
 *   DELETE /requirements/{id}                -> delete requirement (all versions)
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
require_once(__DIR__ . '/../../lib/functions/requirement_spec_mgr.class.php');
require_once(__DIR__ . '/../../lib/functions/requirement_mgr.class.php');

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
$path = preg_replace('#^/api/reqspec(/index\.php)?#', '', $path);
$path = '/' . trim($path, '/');
$method = $_SERVER['REQUEST_METHOD'];
$segments = array_values(array_filter(explode('/', $path)));

function out($data) { echo json_encode($data); exit; }
function getBody() { return json_decode(file_get_contents('php://input'), true) ?? []; }

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
 * Test project in context must exist and be visible to current user.
 */
function needTprojectId() {
    global $tprojectMgr, $user;
    $id = intval($_GET['tproject_id'] ?? ($_REQUEST['tproject_id'] ?? 0));
    if ($id <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => lang_get('invalid_tproject_id')]);
    }
    $info = $tprojectMgr->get_by_id($id);
    if (!$info) {
        http_response_code(404);
        out(['status' => 'error', 'message' => lang_get('testproject_does_not_exist')]);
    }
    if (!canView($user, $GLOBALS['db'], $id)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => lang_get('authorization_error')]);
    }
    return $id;
}

function needManageRight($tproject_id) {
    global $user;
    if (!canManage($user, $GLOBALS['db'], $tproject_id)) {
        http_response_code(403);
        out(['status' => 'error',
             'message' => lang_get('reqspec_mgmt_no_rights')]);
    }
}

/**
 * Spec must exist AND belong to test project in context
 * (legacy reqSpecEdit.php re-derives tproject from the record itself).
 */
function needOwnedSpec($reqSpecMgr, $specId, $tproject_id) {
    $rec = $reqSpecMgr->get_by_id($specId);
    if (!$rec || intval($rec['testproject_id']) !== intval($tproject_id)) {
        http_response_code(404);
        out(['status' => 'error', 'message' => lang_get('req_spec_not_found') ?: 'Spec not found']);
    }
    return $rec;
}

/**
 * Latest revision per spec (max(revision) inside req_specs_revisions).
 */
function specsToJSON($rows) {
    $out = [];
    foreach ($rows as $r) {
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
    return $out;
}

function reqsToJSON($rows) {
    $out = [];
    foreach ($rows as $r) {
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
    return $out;
}

// ---------------------------------------------------------------- options ---
if ($method === 'GET' && $path === '/options') {
    $tproject_id = needTprojectId();

    $cfg = config_get('req_cfg');
    $specCfg = config_get('req_spec_cfg');

    // localized labels are resolved client-side through TLi18n using these keys
    $specTypes = [];
    foreach ($specCfg->type_labels as $code => $labelKey) {
        $specTypes[(string)$code] = $labelKey;
    }
    $reqTypes = [];
    foreach ($cfg->type_labels as $code => $labelKey) {
        $reqTypes[(string)$code] = $labelKey;
    }
    $reqStatuses = [];
    foreach ($cfg->status_labels as $code => $labelKey) {
        $reqStatuses[(string)$code] = $labelKey;
    }

    out([
        'status' => 'ok',
        'tproject_id' => $tproject_id,
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
if ($method === 'GET' && $path === '/specs') {
    $tproject_id = needTprojectId();

    $sql = "SELECT rs.id, rs.doc_id, nh.name AS title, nh.node_order," .
           " latest.scope, latest.type, latest.total_req, latest.revision," .
           " latest.creation_ts, latest.modification_ts, u.login AS author_login," .
           " COALESCE(rc.cnt, 0) AS req_count," .
           " (SELECT COUNT(*) FROM {$reqSpecMgr->tables['req_specs_revisions']} x" .
           "  WHERE x.parent_id = rs.id) AS revisions_cnt" .
           " FROM {$reqSpecMgr->tables['req_specs']} rs" .
           " JOIN {$reqSpecMgr->tables['nodes_hierarchy']} nh ON nh.id = rs.id" .
           " JOIN {$reqSpecMgr->tables['req_specs_revisions']} latest" .
           "      ON latest.parent_id = rs.id" .
           "     AND latest.revision = (SELECT MAX(r2.revision)" .
           "         FROM {$reqSpecMgr->tables['req_specs_revisions']} r2" .
           "         WHERE r2.parent_id = rs.id)" .
           " LEFT JOIN {$reqSpecMgr->tables['users']} u ON u.id = latest.author_id" .
           " LEFT JOIN (SELECT srs_id, COUNT(*) cnt FROM {$reqSpecMgr->tables['requirements']}" .
           "            GROUP BY srs_id) rc ON rc.srs_id = rs.id" .
           " WHERE rs.testproject_id = " . intval($tproject_id) .
           " ORDER BY nh.node_order ASC, nh.id ASC";

    $rows = $db->get_recordset($sql);
    out(['status' => 'ok', 'specs' => specsToJSON($rows ? $rows : [])]);
}

if ($method === 'POST' && $path === '/specs') {
    $tproject_id = needTprojectId();
    needManageRight($tproject_id);

    $b = getBody();
    $docId  = trim((string)($b['doc_id'] ?? ''));
    $title  = trim((string)($b['title'] ?? ''));
    $scope  = (string)($b['scope'] ?? '');
    $type   = (string)($b['type'] ?? TL_REQ_SPEC_TYPE_SECTION);
    $countReq = intval($b['total_req'] ?? 0);

    if ($docId === '' || $title === '') {
        http_response_code(400);
        out(['status' => 'error', 'message' => lang_get('warning_empty_req_title')]);
    }

    $op = $reqSpecMgr->create($tproject_id, 0, $docId, $title, $scope,
                              $countReq, $userId, $type);
    if (!$op['status_ok']) {
        http_response_code(400);
        out(['status' => 'error', 'message' => $op['msg']]);
    }
    out(['status' => 'ok', 'id' => intval($op['id'])]);
}

if ($method === 'PUT' && count($segments) === 2 && $segments[0] === 'specs') {
    $tproject_id = needTprojectId();
    needManageRight($tproject_id);

    $specId = intval($segments[1]);
    needOwnedSpec($reqSpecMgr, $specId, $tproject_id);

    $b = getBody();
    $item = [
        'id'         => $specId,
        'doc_id'     => trim((string)($b['doc_id'] ?? '')),
        'name'       => trim((string)($b['title'] ?? '')),
        'scope'      => (string)($b['scope'] ?? ''),
        'type'       => (string)($b['type'] ?? TL_REQ_SPEC_TYPE_SECTION),
        'countReq'   => intval($b['total_req'] ?? 0),
        'user_id'    => $userId,
        'node_order' => null,
    ];
    if ($item['doc_id'] === '' || $item['name'] === '') {
        http_response_code(400);
        out(['status' => 'error', 'message' => lang_get('warning_empty_req_title')]);
    }

    $op = $reqSpecMgr->update($item);
    if (!$op['status_ok']) {
        http_response_code(400);
        out(['status' => 'error', 'message' => $op['msg']]);
    }
    out(['status' => 'ok']);
}

if ($method === 'DELETE' && count($segments) === 2 && $segments[0] === 'specs') {
    $tproject_id = needTprojectId();
    needManageRight($tproject_id);

    $specId = intval($segments[1]);
    needOwnedSpec($reqSpecMgr, $specId, $tproject_id);

    // legacy reqSpecCommands::doDelete uses delete_deep()
    // (removes requirements, versions, revisions and coverage too)
    $reqSpecMgr->delete_deep($specId);
    out(['status' => 'ok']);
}

// ----------------------------------------------------------- requirements ---
if ($method === 'GET' && count($segments) === 3 &&
    $segments[0] === 'specs' && $segments[2] === 'requirements') {
    $tproject_id = needTprojectId();
    $specId = intval($segments[1]);
    needOwnedSpec($reqSpecMgr, $specId, $tproject_id);

    $T = $reqMgr->tables;
    $sql = "SELECT r.id, r.srs_id, r.req_doc_id, nh.name AS title, nh.node_order," .
           " v.scope, v.status, v.type, v.version, v.active, v.is_open," .
           " v.expected_coverage, v.creation_ts, v.modification_ts," .
           " u.login AS author_login" .
           " FROM {$T['requirements']} r" .
           " JOIN {$T['nodes_hierarchy']} nh ON nh.id = r.id" .
           " JOIN {$T['req_versions']} v ON v.parent_id = r.id" .
           "     AND v.version = (SELECT MAX(v2.version) FROM {$T['req_versions']} v2" .
           "                      WHERE v2.parent_id = r.id)" .
           " LEFT JOIN {$T['users']} u ON u.id = v.author_id" .
           " WHERE r.srs_id = " . intval($specId) .
           " ORDER BY nh.node_order ASC, r.id ASC";

    $rows = $db->get_recordset($sql);
    out(['status' => 'ok', 'requirements' => reqsToJSON($rows ? $rows : [])]);
}

if ($method === 'POST' && count($segments) === 3 &&
    $segments[0] === 'specs' && $segments[2] === 'requirements') {
    $tproject_id = needTprojectId();
    needManageRight($tproject_id);

    $specId = intval($segments[1]);
    needOwnedSpec($reqSpecMgr, $specId, $tproject_id);

    $b = getBody();
    $docId = trim((string)($b['req_doc_id'] ?? ''));
    $title = trim((string)($b['title'] ?? ''));
    $scope = (string)($b['scope'] ?? '');
    $status = strtoupper(trim((string)($b['status'] ?? TL_REQ_STATUS_VALID)));
    $type = (string)($b['type'] ?? TL_REQ_TYPE_FEATURE);
    $expectedCoverage = intval($b['expected_coverage'] ?? 1);

    if ($docId === '' || $title === '') {
        http_response_code(400);
        out(['status' => 'error', 'message' => lang_get('warning_empty_req_title')]);
    }

    $op = $reqMgr->create($specId, $docId, $title, $scope, $userId,
                          $status, $type, $expectedCoverage);
    if (!$op['status_ok']) {
        http_response_code(400);
        out(['status' => 'error', 'message' => $op['msg']]);
    }
    out(['status' => 'ok', 'id' => intval($op['id'])]);
}

if ($method === 'PUT' && count($segments) === 2 && $segments[0] === 'requirements') {
    $tproject_id = needTprojectId();
    needManageRight($tproject_id);

    $reqId = intval($segments[1]);

    // requirement must exist and its parent spec must belong to context project
    $info = $reqMgr->get_by_id($reqId);
    if (!$info) {
        http_response_code(404);
        out(['status' => 'error', 'message' => lang_get('req_not_found') ?: 'Requirement not found']);
    }
    $req = $info[0];
    needOwnedSpec($reqSpecMgr, intval($req['srs_id']), $tproject_id);

    $latestVersion = null;
    foreach ($info as $v) {
        if (is_null($latestVersion) || intval($v['version']) > intval($latestVersion['version'])) {
            $latestVersion = $v;
        }
    }

    $b = getBody();
    $docId = trim((string)($b['req_doc_id'] ?? ''));
    $title = trim((string)($b['title'] ?? ''));
    $scope = (string)($b['scope'] ?? '');
    $status = strtoupper(trim((string)($b['status'] ?? $latestVersion['status'])));
    $type = (string)($b['type'] ?? $latestVersion['type']);
    $expectedCoverage = intval($b['expected_coverage'] ?? $latestVersion['expected_coverage']);

    if ($docId === '' || $title === '') {
        http_response_code(400);
        out(['status' => 'error', 'message' => lang_get('warning_empty_req_title')]);
    }

    $op = $reqMgr->update($reqId, intval($latestVersion['id']), $docId, $title,
                          $scope, $userId, $status, $type, $expectedCoverage);
    if (!$op['status_ok']) {
        http_response_code(400);
        out(['status' => 'error', 'message' => $op['msg']]);
    }
    out(['status' => 'ok']);
}

if ($method === 'DELETE' && count($segments) === 2 && $segments[0] === 'requirements') {
    $tproject_id = needTprojectId();
    needManageRight($tproject_id);

    $reqId = intval($segments[1]);
    $info = $reqMgr->get_by_id($reqId);
    if (!$info) {
        http_response_code(404);
        out(['status' => 'error', 'message' => lang_get('req_not_found') ?: 'Requirement not found']);
    }
    $req = $info[0];
    needOwnedSpec($reqSpecMgr, intval($req['srs_id']), $tproject_id);

    // legacy reqCommands::doDelete uses delete() with ALL_VERSIONS
    $reqMgr->delete($reqId);
    out(['status' => 'ok']);
}

http_response_code(404);
out(['status' => 'error', 'message' => 'Unknown endpoint']);
