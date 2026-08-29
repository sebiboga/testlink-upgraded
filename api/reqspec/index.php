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
 *   GET  ?action=spec_view&id=N            -> spec header (latest revision) + cfields + attachments
 *   GET  ?action=spec_revision_view&id=N   -> a SINGLE spec revision (read-only viewer)
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
 * Spec must exist (with at least one revision) AND belong to test project in context.
 */
function needOwnedSpec($specId, $tproject_id) {
    global $reqSpecMgr, $db;
    // requirement_spec_mgr::get_by_id() fatals when the spec does not exist
    // (get_last_child_info() returns null -> E_WARNING + broken SQL), so
    // probe existence and revision presence first. Refs #569
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

function badRequest($msg) {
    http_response_code(400);
    out(['status' => 'error', 'message' => $msg]);
}

/**
 * ids of the spec subtree: the spec itself plus every req_spec descendant,
 * discovered through nodes_hierarchy (req_spec nodes are tree children).
 * Same reachable set used by legacy get_requirements/doFreeze tree walks.
 */
function reqSpecSubtreeIds(&$db, $specId) {
    $specIds = [intval($specId)];
    $frontier = [intval($specId)];
    while (count($frontier)) {
        $inExpr = implode(',', array_map('intval', $frontier));
        $kids = $db->get_recordset(
            "SELECT RS.id FROM req_specs RS" .
            " JOIN nodes_hierarchy NH ON NH.id = RS.id" .
            " WHERE NH.parent_id IN ($inExpr)");
        $frontier = [];
        if (!empty($kids)) {
            foreach ($kids as $k) { $n = intval($k['id']); $specIds[] = $n; $frontier[] = $n; }
        }
    }
    return $specIds;
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

// ------------------------------------------------- spec view (reqSpecView) ---
// Refs #755 - lib/requirements/reqSpecView.php modernization.
// Gate matches the legacy pageAccessCheck(): strict rightsAnd=['mgt_view_req'].
// Includes the spec header (latest revision), design CF values on that
// revision, attachment list and the requirement set of the spec (reusing the
// reqs route data shape so the viewer table mirrors reqSpecMgmt.html).
if ($method === 'GET' && $action === 'spec_view') {
    $specId = intval($_REQUEST['id'] ?? 0);
    if ($specId <= 0) { badRequest('Invalid req spec id'); }

    // probe existence first: get_by_id() -> get_last_child_info() fatals with
    // E_WARNING + broken SQL on a nonexistent spec (see Refs #569)
    $rows = $db->get_recordset(
        'SELECT testproject_id FROM ' . $reqSpecMgr->object_table . ' WHERE id = ' . intval($specId));
    if (!$rows) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Requirement specification not found']);
    }
    $ownerTid = intval($rows[0]['testproject_id']);

    if (!$user->hasRight($db, 'mgt_view_req', $ownerTid)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }

    $spec = $reqSpecMgr->get_by_id($specId);
    if (!$spec) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Requirement specification not found']);
    }

    $reqCfg = config_get('req_cfg');
    $specCfg = config_get('req_spec_cfg');

    $modifiedNever = is_null($spec['modification_ts'])
        || $spec['modification_ts'] == '0000-00-00 00:00:00';

    // design custom fields linked to requirement_spec + values on the revision
    // (same access requirement_spec_mgr::get_linked_cfields() uses)
    $cfields = [];
    $cfMap = $reqSpecMgr->get_linked_cfields([
        'parent_id'   => $specId,
        'item_id'     => intval($spec['revision_id']),
        'tproject_id' => $ownerTid,
    ]);
    if (!empty($cfMap)) {
        foreach ($cfMap as $cf) {
            $vType = isset($reqSpecMgr->cfield_mgr->custom_field_types[$cf['type']])
                ? $reqSpecMgr->cfield_mgr->custom_field_types[$cf['type']] : 'string';
            $value = isset($cf['value']) ? $cf['value'] : '';
            if (is_array($value)) { $value = implode(', ', $value); }
            $value = preg_replace('!\s+!', ' ', trim((string)$value));
            if (($vType == 'date' || $vType == 'datetime') && is_numeric($value) && intval($value) != 0) {
                $value = tlStrftime(config_get($vType), intval($value));
            }
            $cfields[] = [
                'name'  => $cf['name'],
                'label' => $cf['label'],
                'type'  => intval($cf['type']),
                'verbose_type' => $vType,
                'value' => $value,
            ];
        }
    }

    // attachments of the spec (same sink the tcView BFF uses)
    $attachments = [];
    if (function_exists('getAttachmentInfosFrom')) {
        $attMap = getAttachmentInfosFrom($reqSpecMgr, $specId);
        if (!empty($attMap)) {
            foreach ($attMap as $ai) {
                $attachments[] = [
                    'id'           => intval($ai['id']),
                    'title'        => $ai['title'],
                    'file_name'    => isset($ai['file_name']) ? $ai['file_name'] : '',
                    'file_size'    => isset($ai['file_size']) ? intval($ai['file_size']) : 0,
                    'file_type'    => isset($ai['file_type']) ? $ai['file_type'] : '',
                    'date_added'   => isset($ai['date_added']) ? (string)$ai['date_added'] : '',
                    'download_url' => 'lib/attachments/attachmentdownload.php?id=' . intval($ai['id']),
                ];
            }
        }
    }

    $prefix = $tprojectMgr->getTestCasePrefix($ownerTid);
    $directLink = $_SESSION['basehref'] . 'linkto.php?tprojectPrefix=' . urlencode($prefix) .
                  '&item=reqspec&id=' . urlencode($spec['doc_id']);

    // requirements of the spec (latest version per requirement, same query the
    // reqs action runs so the client table reuses its shape)
    $reqSql = "SELECT r.id, r.srs_id, r.req_doc_id, nh.name AS title," .
           " v.scope, v.status, v.type, v.version, v.active, v.is_open," .
           " v.expected_coverage" .
           " FROM requirements r" .
           " JOIN nodes_hierarchy nh ON nh.id = r.id" .
           " JOIN nodes_hierarchy vh ON vh.parent_id = r.id" .
           " JOIN req_versions v ON v.id = vh.id" .
           "     AND v.version = (SELECT MAX(v2.version) FROM req_versions v2" .
           "                      JOIN nodes_hierarchy h2 ON h2.id = v2.id" .
           "                      WHERE h2.parent_id = r.id)" .
           " WHERE r.srs_id = " . intval($specId) .
           " ORDER BY nh.node_order ASC, r.id ASC";
    $reqRows = $db->get_recordset($reqSql);
    $requirements = [];
    foreach (($reqRows ? $reqRows : []) as $r) {
        $requirements[] = [
            'id'         => intval($r['id']),
            'req_doc_id' => (string)$r['req_doc_id'],
            'title'      => (string)$r['title'],
            'status'     => (string)$r['status'],
            'type'       => (string)$r['type'],
            'version'    => intval($r['version']),
            'is_open'    => intval($r['is_open']),
        ];
    }

    $revCount = intval($db->fetchFirstRowSingleColumn(
        "SELECT COUNT(*) AS n FROM req_specs_revisions WHERE parent_id = " . intval($specId),
        'n'));

    // localized type/status maps for the viewer (deep links may arrive without
    // a tproject_id, so the view payload carries its own domain labels)
    $reqTypesMap = [];
    foreach ($reqCfg->type_labels as $code => $labelKey) {
        $reqTypesMap[(string)$code] = lang_get($labelKey);
    }
    $reqStatusesMap = [];
    foreach ($reqCfg->status_labels as $code => $labelKey) {
        $reqStatusesMap[(string)$code] = lang_get($labelKey);
    }

    out([
        'status'  => 'ok',
        'tproject_id'   => $ownerTid,
        'tproject_name' => testproject::getName($db, $ownerTid),
        'spec' => [
            'id'              => intval($spec['id']),
            'doc_id'          => (string)$spec['doc_id'],
            'title'           => (string)$spec['title'],
            'type'            => (string)$spec['type'],
            'type_label'      => isset($specCfg->type_labels[$spec['type']])
                                   ? lang_get($specCfg->type_labels[$spec['type']]) : (string)$spec['type'],
            'revision'        => intval($spec['revision']),
            'revision_id'     => intval($spec['revision_id']),
            'scope'           => (string)$spec['scope'],
            'total_req'       => intval($spec['total_req']),
            'author'          => (string)$spec['author'],
            'modifier'        => $modifiedNever ? '' : (string)$spec['modifier'],
            'creation_ts'     => (string)$spec['creation_ts'],
            'modification_ts' => $modifiedNever ? '' : (string)$spec['modification_ts'],
            'modified_never'  => $modifiedNever,
            'direct_link'     => $directLink,
            // legacy get_requirements(range='all') counts direct + child-spec
            // requirements, so count the reachable subtree (grid stays direct)
            'requirements_count' => intval($db->fetchFirstRowSingleColumn(
                'SELECT COUNT(*) AS n FROM requirements WHERE srs_id IN (' .
                implode(',', reqSpecSubtreeIds($db, $spec['id'])) . ')',
                'n')),
            'revisions_count'    => $revCount,
            'external_req_management' =>
                (isset($reqCfg->external_req_management)
                 && $reqCfg->external_req_management == ENABLED) ? true : false,
        ],
        'cfields'      => $cfields,
        'attachments'  => $attachments,
        'requirements' => $requirements,
        'reqTypes'     => $reqTypesMap,
        'reqStatuses'  => $reqStatusesMap,
        'rights' => [
            'manage' => $user->hasRight($db, 'mgt_modify_req', $ownerTid),
        ],
    ]);
}

// ------------------------------------------- spec revision view (spec_revision_view) ---
// Refs #755 - mirrors lib/requirements/reqSpecViewRevision.php: view a SINGLE
// spec revision read-only. Input  ?action=spec_revision_view&id=<spec_revision_id>
// (a row in req_specs_revisions). The revision's parent spec gives the project
// context for the rights gate. Right: strict mgt_view_req (legacy rightsAnd).
if ($method === 'GET' && $action === 'spec_revision_view') {
    $revId = intval($_REQUEST['id'] ?? 0);
    if ($revId <= 0) { badRequest('Invalid spec revision id'); }

    // join the revision to its parent spec to resolve ownership + project
    // (table names are hard-coded, same as spec_view — $tables is protected)
    $rows = $db->get_recordset(
        "SELECT RSV.*, RS.testproject_id, RS.doc_id AS spec_doc_id" .
        " FROM req_specs_revisions RSV" .
        " JOIN req_specs RS ON RS.id = RSV.parent_id" .
        " WHERE RSV.id = " . intval($revId));
    if (!$rows) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Requirement spec revision not found']);
    }
    $rev = $rows[0];
    $ownerTid = intval($rev['testproject_id']);
    $specId = intval($rev['parent_id']);

    if (!$user->hasRight($db, 'mgt_view_req', $ownerTid)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }

    $reqSpecMgr->decode_users($rows);
    $rev = $rows[0];

    // design custom fields linked to requirement_spec + values on THIS revision
    // (same sink as spec_view, but item_id = this revision id)
    $cfields = [];
    $cfMap = $reqSpecMgr->get_linked_cfields([
        'parent_id'   => $specId,
        'item_id'     => $revId,
        'tproject_id' => $ownerTid,
    ]);
    if (!empty($cfMap)) {
        foreach ($cfMap as $cf) {
            $vType = isset($reqSpecMgr->cfield_mgr->custom_field_types[$cf['type']])
                ? $reqSpecMgr->cfield_mgr->custom_field_types[$cf['type']] : 'string';
            $value = isset($cf['value']) ? $cf['value'] : '';
            if (is_array($value)) { $value = implode(', ', $value); }
            $value = preg_replace('!\s+!', ' ', trim((string)$value));
            if (($vType == 'date' || $vType == 'datetime') && is_numeric($value) && intval($value) != 0) {
                $value = tlStrftime(config_get($vType), intval($value));
            }
            $cfields[] = [
                'name'  => $cf['name'],
                'label' => $cf['label'],
                'type'  => intval($cf['type']),
                'verbose_type' => $vType,
                'value' => $value,
            ];
        }
    }

    $specCfg = config_get('req_spec_cfg');

    // is this revision the LATEST one for the spec? (for a "back to current" link)
    $latestRev = intval($db->fetchFirstRowSingleColumn(
        "SELECT MAX(RSV2.revision) AS last_rev FROM req_specs_revisions RSV2" .
        " WHERE RSV2.parent_id = " . intval($specId), 'last_rev'));

    $modifiedNever = is_null($rev['modification_ts'])
        || $rev['modification_ts'] == '0000-00-00 00:00:00';

    out([
        'status'  => 'ok',
        'tproject_id'   => $ownerTid,
        'tproject_name' => testproject::getName($db, $ownerTid),
        'spec_id'   => $specId,
        'spec_doc_id' => (string)$rev['spec_doc_id'],
        'revision' => [
            'id'          => intval($rev['id']),
            'revision'    => intval($rev['revision']),
            'doc_id'      => (string)$rev['doc_id'],
            'name'        => (string)$rev['name'],
            'scope'       => (string)$rev['scope'],
            'type'        => (string)$rev['type'],
            'type_label'  => isset($specCfg->type_labels[$rev['type']])
                               ? lang_get($specCfg->type_labels[$rev['type']]) : (string)$rev['type'],
            'total_req'   => intval($rev['total_req']),
            'log_message' => (string)$rev['log_message'],
            'author'      => (string)$rev['author'],
            'modifier'    => $modifiedNever ? '' : (string)$rev['modifier'],
            'creation_ts'     => (string)$rev['creation_ts'],
            'modification_ts' => $modifiedNever ? '' : (string)$rev['modification_ts'],
            'modified_never'  => $modifiedNever,
            'is_latest'   => (intval($rev['revision']) === intval($latestRev)),
        ],
        'cfields' => $cfields,
    ]);
}

// ------------------------------------------------- spec freeze (reqSpecView) ---
// Refs #755 - mirrors lib/requirements/reqSpecCommands.class.php doFreeze():
// recursively freezes the LATEST version of every requirement under the spec
// subtree (child specs included). Right: mgt_modify_req (legacy req_mgmt).
if ($method === 'POST' && $action === 'freeze_spec') {
    $tproject_id = needTprojectId();
    needManageRight($tproject_id);

    $specId = intval($_REQUEST['id'] ?? ($BODY['id'] ?? 0));
    if ($specId <= 0) { badRequest('Invalid req spec id'); }
    needOwnedSpec($specId, $tproject_id);

    // collect spec ids in the subtree (spec itself + every req_spec descendant)
    $specIds = reqSpecSubtreeIds($db, $specId);

    $reqIds = $db->fetchColumnsIntoArray(
        'SELECT id FROM requirements WHERE srs_id IN (' .
        implode(',', $specIds) . ')', 'id');

    $frozenQty = 0;
    foreach (($reqIds ? $reqIds : []) as $reqId) {
        $versions = $reqMgr->get_by_id($reqId, requirement_mgr::LATEST_VERSION);
        if (!empty($versions) && isset($versions[0]['version_id'])
            && intval($versions[0]['is_open']) === 1) {
            $reqMgr->updateOpen(intval($versions[0]['version_id']), false);
            logAuditEvent(TLS('audit_req_version_frozen',
                intval($versions[0]['version']),
                (string)$versions[0]['req_doc_id'],
                (string)$versions[0]['title']),
                'FREEZE', intval($versions[0]['version_id']), 'req_version');
            $frozenQty++;
        }
    }

    out(['status' => 'ok', 'frozen' => $frozenQty]);
}

// ------------------------------------------- spec new revision (reqSpecView) ---
// Refs #755 - mirrors reqSpecCommands::doCreateRevision(): clones the latest
// spec revision with a log message. Right: mgt_modify_req.
if ($method === 'POST' && $action === 'create_revision') {
    $tproject_id = needTprojectId();
    needManageRight($tproject_id);

    $specId = intval($_REQUEST['id'] ?? ($BODY['id'] ?? 0));
    if ($specId <= 0) { badRequest('Invalid req spec id'); }
    needOwnedSpec($specId, $tproject_id);

    $logMessage = trim((string)($BODY['log_message'] ?? ''));
    $ret = $reqSpecMgr->clone_revision($specId, [
        'log_message' => $logMessage,
        'author_id'   => $userId,
    ]);
    if (!isset($ret['status_ok']) || !$ret['status_ok']) {
        badRequest(isset($ret['msg']) ? $ret['msg'] : 'Revision creation failed');
    }
    out(['status' => 'ok', 'revision_id' => intval($ret['id'])]);
}

http_response_code(404);
out(['status' => 'error', 'message' => 'Unknown action']);
