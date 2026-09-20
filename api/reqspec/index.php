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
 *   POST ?action=create_spec               {tproject_id,doc_id,title,type,total_req,scope,parent_id?}
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

// Refs #1376, #1516 — tolerant boolean read of a req_cfg knob
// (ENABLED/DISABLED constants are ints 1/0; a literal 'DISABLED'/'FALSE'/'' means false).
function boolishConfig($cfg, $key, $default) {
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

// Refs #1376, #1516 — effective expected_coverage to persist, mirroring legacy:
//  - management disabled (req_cfg->expected_coverage_management = false) → 0
//  - selected type not enabled in type_expected_coverage map            → 0
//  - otherwise the posted free numeric input, any positive integer      → max(1,int)
function effectiveExpectedCoverage($type, $posted) {
    $cfg = config_get('req_cfg');
    if (!boolishConfig($cfg, 'expected_coverage_management', false)) { return 0; }
    $typeEc = isset($cfg->type_expected_coverage) ? (array)$cfg->type_expected_coverage : [];
    $typeEnabled = isset($typeEc[$type]) ? (bool)$typeEc[$type] : true;
    if (!$typeEnabled) { return 0; }
    return max(1, intval($posted));
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

/**
 * requirements (latest version each) of a spec, direct + child-spec subtree,
 * same query shape the reqs route / spec_view use (mirror of the legacy
 * requirement_spec_mgr::get_requirements(range='all') walk). Refs #1348
 */
function listSpecRequirements(&$db, $specId) {
    $sql = "SELECT r.id, r.srs_id, r.req_doc_id, nh.name AS title, nh.node_order," .
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
    $rows = $db->get_recordset($sql);
    $out = [];
    foreach (($rows ? $rows : []) as $r) {
        $out[] = [
            'id'          => intval($r['id']),
            'req_doc_id'  => (string)$r['req_doc_id'],
            'title'       => (string)$r['title'],
            'scope'       => (string)$r['scope'],
            'status'      => (string)$r['status'],
            'type'        => (string)$r['type'],
            'version'     => intval($r['version']),
            'active'      => intval($r['active']),
            'is_open'     => intval($r['is_open']),
            'expected_coverage' => intval($r['expected_coverage']),
        ];
    }
    return $out;
}

// Refs #1348 — /bulk monitoring + copy requirements shared refresh payload.
// Mirrors reqSpecCommands::bulkReqMon(): the spec's requirements with the
// current user's monitor flag (getMonitoredByUser scoped to the spec) plus the
// enable_start_btn / enable_stop_btn toggles driving the start/stop submit
// buttons in the legacy reqBulkMon.tpl.
function bulkMonPayload(&$reqSpecMgr, &$reqMgr, &$db, $specId, $userId, $ownerTid) {
    $spec = @$reqSpecMgr->get_by_id(intval($specId)) ?: null;
    $items = listSpecRequirements($db, intval($specId));
    $monSet = null;
    try {
        $monSet = $reqMgr->getMonitoredByUser(intval($userId), intval($ownerTid),
                                              ['reqSpecID' => intval($specId)]);
    } catch (Exception $e) {
        $monSet = null;
    }
    $enableStart = false;
    $enableStop = false;
    foreach ($items as &$it) {
        $on = ($monSet !== null && isset($monSet[$it['id']]));
        $it['monitor'] = $on;
        if ($on) { $enableStop = true; } else { $enableStart = true; }
    }
    unset($it);
    return [
        'tproject_id'      => intval($ownerTid),
        'tproject_name'    => testproject::getName($db, $ownerTid),
        'spec' => [
            'id'     => $spec ? intval($spec['id']) : 0,
            'doc_id' => $spec ? (string)$spec['doc_id'] : '',
            'title'  => $spec ? (string)$spec['title'] : '',
        ],
        'items'            => $items,
        'enable_start_btn' => $enableStart,
        'enable_stop_btn'  => $enableStop,
    ];
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

    // Refs #1376, #1516: per-requirement-type expected-coverage enable map
    // (legacy reqCommands.class.php:33-41). A type absent from the map is
    // ENABLED by default (value 1).
    $typeEc = isset($cfg->type_expected_coverage) ? (array)$cfg->type_expected_coverage : [];
    $expectedCoverageByType = [];
    foreach ($reqTypes as $code => $dummy) {
        $value = isset($typeEc[$code]) ? ($typeEc[$code] ? 1 : 0) : 1;
        $expectedCoverageByType[(string)$code] = $value;
    }

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
        // Refs #1516: legacy expected-coverage gates (reqEdit.tpl:345)
        'expectedCoverageManagement' => boolishConfig($cfg, 'expected_coverage_management', false),
        'expectedCoverageByType' => $expectedCoverageByType,
        // Refs #1345 - legacy reqSpecViewButtons.inc.tpl:38 gate: the "New Req
        // Spec" viewer action is only offered when child specs are enabled
        // (config.inc.php:1661, req_cfg->child_requirements_mgmt == ENABLED).
        'childRequirementsManagement' => boolishConfig($cfg, 'child_requirements_mgmt', true),
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
    // Refs #1345 - child spec semantics (legacy reqSpecEdit.php doAction=
    // createChild&parentID=<spec_id> -> reqSpecCommands::doCreate): when the
    // caller passes parent_id the new spec node hangs UNDER that req spec in
    // nodes_hierarchy. Parent spec must belong to the test project in context.
    // parent_id <= 0 keeps the historical root-level attach-to-tproject create.
    $parentId = intval($BODY['parent_id'] ?? 0);
    if ($parentId > 0) {
        needOwnedSpec($parentId, $tproject_id);
    }

    if ($docId === '') { badRequest('Document ID cannot be empty'); }
    if ($title === '') { badRequest(lang_get('warning_empty_req_title')); }

    // Specs attach directly to the test project node (same as legacy
    // reqSpecCommands root-level create). Parent 0 leaves the node orphaned:
    // get_all_requirement_ids() and every tree walk never find it. Refs #569
    $op = $reqSpecMgr->create($tproject_id, $parentId > 0 ? $parentId : $tproject_id,
                              $docId, $title, $scope, $countReq, $userId, $type);
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
    // Refs #1516: gate expected_coverage through legacy config (mirrors #1376 fix)
    $expectedCoverage = effectiveExpectedCoverage($type, $BODY['expected_coverage'] ?? 1);

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
    // Refs #1516: gate expected_coverage through legacy config (mirrors #1376 fix)
    $expectedCoverage = effectiveExpectedCoverage($type,
        $BODY['expected_coverage'] !== null && $BODY['expected_coverage'] !== ''
            ? $BODY['expected_coverage'] : $latestVersion['expected_coverage']);

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
                $value = tlStrftime($vType == 'date' ? config_get('date_format') : config_get('timestamp_format'), intval($value));
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
                    'download_url' => '/api/attachments/index.php?action=download&id=' . intval($ai['id']),
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

    // Refs #1346 - the legacy Import (branch) button text switches to
    // "Import via API (<name>)" when the test project has a requirement
    // management system linked (legacy reqSpecView::initialize_gui via
    // reqSpecCommands::getReqMgrSystem() -> tlReqMgrSystem::getLinkedTo(),
    // gated on testprojects.reqmgr_integration_enabled). Expose the linked
    // system name/type so the modern viewer can render the same label.
    $reqMgrSystem = null;
    $tprojInfo = $tprojectMgr->get_by_id($ownerTid);
    if (!empty($tprojInfo['reqmgr_integration_enabled'])) {
        $sysMgr = new tlReqMgrSystem($db);
        $linked = @$sysMgr->getLinkedTo($ownerTid);
        if (!empty($linked)) {
            $reqMgrSystem = [
                'id'   => intval($linked['reqmgrsystem_id']),
                'name' => (string)$linked['reqmgrsystem_name'],
                'type' => (string)(isset($linked['verboseType']) ? $linked['verboseType'] : $linked['type']),
            ];
        }
    }

    $revCount = intval($db->fetchFirstRowSingleColumn(
        "SELECT COUNT(*) AS n FROM req_specs_revisions WHERE parent_id = " . intval($specId),
        'n'));

    // Refs #1351 - revision log history tooltip. Legacy reqSpecViewJS.inc.tpl:12-38
    // tip4log() builds an Ext.ToolTip autoLoading lib/ajax/getreqspeclog.php?item_id=
    // <req_specs_revisions.id> on hover of the revision row; the payload carries the
    // FULL untruncated log_message of the shown (latest) revision so the modern screen
    // can render the identical mouse-tracked tooltip without an extra round-trip.
    $logMessage = (string)$db->fetchFirstRowSingleColumn(
        'SELECT log_message FROM req_specs_revisions WHERE id = ' . intval($spec['revision_id']),
        'log_message');

    // Refs #1351 - also expose req_spec_cfg->log_message_len, the same sink
    // spec_revision_compare returns for the sibling reqSpecCompare tooltip
    // (api/reqspec/index.php:870-872); kept here so the two screens share one
    // payload shape and the viewer could truncate a log cell later.
    $logMessageLen = (is_object($specCfg) && isset($specCfg->log_message_len))
        ? intval($specCfg->log_message_len) : 0;

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
    // Refs #1345 - spec TYPE labels, needed by the viewer's create-child/edit
    // modal type dropdown (options carries them for the management screen, but
    // deep links may reach spec_view without a prior options round-trip).
    $specTypesMap = [];
    if (is_object($specCfg) && !empty($specCfg->type_labels)) {
        foreach ($specCfg->type_labels as $code => $labelKey) {
            $specTypesMap[(string)$code] = lang_get($labelKey);
        }
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
            'log_message'        => $logMessage,
            'log_message_len'    => $logMessageLen,
            'external_req_management' =>
                (isset($reqCfg->external_req_management)
                 && $reqCfg->external_req_management == ENABLED) ? true : false,
            // Refs #1345 - req_cfg->child_requirements_mgmt parity (default
            // ENABLED, config.inc.php:1661); legacy hides the "New Req Spec"
            // viewer button when child-spec management is disabled.
            'child_requirements_mgmt' => boolishConfig($reqCfg, 'child_requirements_mgmt', true),
        ],
        // Refs #1346 - linked requirement-management system (null when the
        // project has none enabled); drives the "Import via API (name)" label.
        'req_mgr_system' => $reqMgrSystem,
        'cfields'      => $cfields,
        'attachments'  => $attachments,
        // legacy attachments.inc.tpl:163 shows the upload limit hint
        // ($gui->import_limit = TL_REPOSITORY_MAXFILESIZE); the viewer needs it
        // for the manager-only upload control (Refs #1352)
        'attachments_max_size' => intval(TL_REPOSITORY_MAXFILESIZE),
        'requirements' => $requirements,
        'reqTypes'     => $reqTypesMap,
        'reqStatuses'  => $reqStatusesMap,
        'specTypes'    => $specTypesMap,
        'rights' => [
            'manage' => $user->hasRight($db, 'mgt_modify_req', $ownerTid),
            // Refs #1350 - the print view routes to the reqdoc/printDocument
            // flow whose gate is `testplan_metrics` (api/reqdoc/index.php:191,
            // mirror of legacy printDocument.php). Expose it so the toolbar only
            // shows the print button when the document can actually be generated.
            'can_print' => $user->hasRightOnProj($db, 'testplan_metrics', $ownerTid),
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
                $value = tlStrftime($vType == 'date' ? config_get('date_format') : config_get('timestamp_format'), intval($value));
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

// ------------------------------------- spec revision compare (reqSpecCompareRevisions) ---
// Refs #837 - mirrors lib/requirements/reqSpecCompareRevisions.php: compare any two
// spec revisions (history list + attributes/scope/custom-fields diff).
//   GET ?action=spec_revision_compare&spec_id=N&tproject_id=N          -> revision list
//   GET ?action=spec_revision_compare&spec_id=N&left=A&right=B[&method=html|text][&context=N]
// Right: mgt_view_req (viewer, same as spec_revision_view).
if ($method === 'GET' && $action === 'spec_revision_compare') {
    $specId = intval($_REQUEST['spec_id'] ?? 0);
    if ($specId <= 0) { badRequest('Invalid requirement spec id'); }

    // resolve owning test project from the spec row (rights gate like spec_view)
    $specRow = $db->get_recordset(
        'SELECT testproject_id, doc_id FROM req_specs WHERE id = ' . intval($specId) . ' LIMIT 1');
    if (!$specRow) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Requirement specification not found']);
    }
    $ownerTid = intval($specRow[0]['testproject_id']);
    if (isset($_REQUEST['tproject_id']) && intval($_REQUEST['tproject_id']) > 0
        && intval($_REQUEST['tproject_id']) !== $ownerTid) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Test project mismatch']);
    }
    if (!$user->hasRight($db, 'mgt_view_req', $ownerTid)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }

    // Refs #1360 - prefill default context = diffEngine->context (5), mirror of
    // legacy lib/requirements/reqSpecCompareRevisions.php:241-245 and of the
    // sibling reqCompare screen (api/reqcompare/index.php:161, :220-222).
    $diffEngine = config_get('diffEngine');
    $defContext = (is_object($diffEngine) && isset($diffEngine->context))
        ? intval($diffEngine->context) : 5;

    $leftId  = intval($_REQUEST['left'] ?? 0);
    $rightId = intval($_REQUEST['right'] ?? 0);

    // ---- history list (revision rows, DESC like legacy) ----
    if ($leftId <= 0 && $rightId <= 0) {
        // Refs #1357 - expose the legacy truncation length so the modern screen
        // can render the cell truncated to req_spec_cfg->log_message_len and offer
        // a hover tooltip with the FULL log (legacy reqSpecCompareRevisions.php:262-271
        // truncates the cell server-side; tip4log + getreqspeclog.php fetch the rest).
        $specCfg = config_get('req_spec_cfg');
        $logMessageLen = (is_object($specCfg) && isset($specCfg->log_message_len))
            ? intval($specCfg->log_message_len) : 0;

        $history = $reqSpecMgr->get_history($specId, [
            'output' => 'array', 'decode_user' => true, 'order_by_dir' => 'DESC',
        ]);
        $items = [];
        foreach (($history ? $history : []) as $row) {
            $items[] = [
                'item_id'    => intval($row['item_id']),
                'revision'   => intval($row['revision']),
                'log_message'=> (string)$row['log_message'],
                'timestamp'  => (string)$row['timestamp'],
                'last_editor'=> (string)$row['last_editor'],
                'creation_ts'=> (string)$row['creation_ts'],
            ];
        }
        out([
            'status'         => 'ok',
            'tproject_id'    => $ownerTid,
            'tproject_name'  => testproject::getName($db, $ownerTid),
            'spec_id'        => $specId,
            'spec_doc_id'    => (string)$specRow[0]['doc_id'],
            'context'        => $defContext,
            'log_message_len'=> $logMessageLen,
            'revisions'      => $items,
        ]);
    }

    // ---- diff two revisions ----
    if ($leftId <= 0 || $rightId <= 0 || $leftId === $rightId) {
        badRequest('Select two different revisions to compare');
    }

    // fetch both revisions joined to their owning spec for context
    $revRows = $db->get_recordset(
        'SELECT RSV.*, RS.testproject_id, RS.doc_id AS spec_doc_id' .
        ' FROM req_specs_revisions RSV' .
        ' JOIN req_specs RS ON RS.id = RSV.parent_id' .
        ' WHERE RSV.parent_id = ' . intval($specId) .
        ' AND RSV.id IN (' . intval($leftId) . ',' . intval($rightId) . ')');
    if (!$revRows || count($revRows) < 2) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'One or both revisions not found']);
    }
    $byId = [];
    foreach ($revRows as $r) { $byId[intval($r['id'])] = $r; }
    $left  = $byId[$leftId];
    $right = $byId[$rightId];

    $useDaisy = ($_REQUEST['method'] ?? 'html') === 'html';

    $specCfg = config_get('req_spec_cfg');
    $typeLabels = isset($specCfg->type_labels) ? (array)$specCfg->type_labels : [];

    // attribute diff (doc_id / name / type) - legacy getAttrDiff()
    $attrRows = [];
    $attrDefs = [
        'doc_id' => ['label' => null],
        'name'   => ['label' => null],
        'type'   => ['label' => 'type_labels'],
    ];
    foreach ($attrDefs as $fkey => $def) {
        $l = isset($specCfg->$fkey) ? (string)$specCfg->$fkey : $fkey;
        $lv = (string)$left[$fkey];
        $rv = (string)$right[$fkey];
        if (!empty($def['label']) && isset($specCfg->{$def['label']})) {
            $map = (array)$specCfg->{$def['label']};
            if (isset($map[$lv])) { $lv = lang_get($map[$lv]); }
            if (isset($map[$rv])) { $rv = lang_get($map[$rv]); }
        }
        $attrRows[] = [
            'label'   => lang_get($l),
            'lvalue'  => $lv,
            'rvalue'  => $rv,
            'changed' => ($lv !== $rv),
        ];
    }

    // scope diff
    $diffData = ['type' => $useDaisy ? 'html' : 'text', 'left' => '', 'right' => '', 'count' => 0];
    $lvScope = (string)$left['scope'];
    $rvScope = (string)$right['scope'];
    $diffData['left'] = $lvScope;
    $diffData['right'] = $rvScope;
    if ($lvScope !== $rvScope) {
        if ($useDaisy) {
            require_once(__DIR__ . '/../../third_party/daisydiff/src/HTMLDiff.php');
            $differ = new HTMLDiffer();
            list($diffHtml, $count) = $differ->htmlDiff($lvScope, $rvScope);
            $diffData['type'] = 'html';
            $diffData['diff'] = $diffHtml;
            $diffData['count'] = intval($count);
        } else {
            require_once(__DIR__ . '/../../third_party/diff/diff.php');
            $context = isset($_REQUEST['context_show_all'])
                ? -1 : (isset($_REQUEST['context']) && is_numeric($_REQUEST['context'])
                    ? intval($_REQUEST['context']) : $defContext);
            $differ = new diff();
            $differ->doDiff(
                explode("\n", str_replace('</p>', "</p>\n", $lvScope)),
                explode("\n", str_replace('</p>', "</p>\n", $rvScope)));
            $diffData['type'] = 'text';
            $diffData['diff'] = $differ->inline(
                explode("\n", str_replace('</p>', "</p>\n", $lvScope)),
                'revision:' . intval($left['revision']),
                explode("\n", str_replace('</p>', "</p>\n", $rvScope)),
                'revision:' . intval($right['revision']),
                $context);
            $diffData['count'] = count($differ->changes);
        }
    }

    // linked custom fields on each revision (same sink as spec_revision_view)
    function reqSpecCFieldValuesCmp($mgr, $specId, $revId, $ownerTid) {
        $out = [];
        $cfMap = $mgr->get_linked_cfields([
            'parent_id'   => $specId,
            'item_id'     => $revId,
            'tproject_id' => $ownerTid,
        ]);
        if (empty($cfMap)) { return $out; }
        foreach ($cfMap as $cf) {
            $vType = isset($mgr->cfield_mgr->custom_field_types[$cf['type']])
                ? $mgr->cfield_mgr->custom_field_types[$cf['type']] : 'string';
            $value = isset($cf['value']) ? $cf['value'] : '';
            if (is_array($value)) { $value = implode(', ', $value); }
            $value = preg_replace('!\s+!', ' ', trim((string)$value));
            // legacy date/datetime formatting: date_format + (for datetime) time_format
            $dateFmt = config_get('date_format');
            if ($dateFmt === null || $dateFmt === false) { $dateFmt = '%d/%m/%Y'; }
            $timeFmt = '%H:%i:%s';
            $guiCfg = config_get('gui');
            if (is_object($guiCfg) && isset($guiCfg->custom_fields) && is_object($guiCfg->custom_fields)
                && isset($guiCfg->custom_fields->time_format)) { $timeFmt = $guiCfg->custom_fields->time_format; }
            if (($vType == 'date' || $vType == 'datetime') && is_numeric($value) && intval($value) != 0) {
                $fmt = ($vType == 'datetime') ? ($dateFmt . ' ' . $timeFmt) : $dateFmt;
                $value = tlStrftime($fmt, intval($value));
            }
            $out[$cf['name']] = [
                'label'  => $cf['label'],
                'value'  => $value,
            ];
        }
        return $out;
    }
    $cfLeft  = reqSpecCFieldValuesCmp($reqSpecMgr, $specId, $leftId, $ownerTid);
    $cfRight = reqSpecCFieldValuesCmp($reqSpecMgr, $specId, $rightId, $ownerTid);
    $cfKeys  = array_unique(array_merge(array_keys($cfLeft), array_keys($cfRight)));
    $cfRows  = [];
    foreach ($cfKeys as $k) {
        $lv = isset($cfLeft[$k]) ? $cfLeft[$k]['value'] : '';
        $rv = isset($cfRight[$k]) ? $cfRight[$k]['value'] : '';
        $cfShown = true;
        $cfgCf = config_get('custom_fields');
        if (isset($cfgCf->show_custom_fields_without_value) && !$cfgCf->show_custom_fields_without_value
            && $lv === '' && $rv === '') {
            $cfShown = false;
        }
        if (!$cfShown) { continue; }
        $cfRows[] = [
            'label'   => isset($cfLeft[$k]) ? $cfLeft[$k]['label'] : (isset($cfRight[$k]) ? $cfRight[$k]['label'] : $k),
            'lvalue'  => $lv,
            'rvalue'  => $rv,
            'changed' => ($lv !== $rv),
        ];
    }

    out([
        'status'      => 'ok',
        'tproject_id' => $ownerTid,
        'tproject_name' => testproject::getName($db, $ownerTid),
        'spec_id'     => $specId,
        'spec_doc_id' => (string)$specRow[0]['doc_id'],
        'spec_name'   => (string)$left['name'],
        'left'  => [
            'item_id'  => intval($left['id']),
            'revision' => intval($left['revision']),
            'doc_id'   => (string)$left['doc_id'],
            'name'     => (string)$left['name'],
            'timestamp'=> (string)$left['creation_ts'],
        ],
        'right' => [
            'item_id'  => intval($right['id']),
            'revision' => intval($right['revision']),
            'doc_id'   => (string)$right['doc_id'],
            'name'     => (string)$right['name'],
            'timestamp'=> (string)$right['creation_ts'],
        ],
        'method'   => $useDaisy ? 'html' : 'text',
        'attributes' => $attrRows,
        'scope'    => $diffData,
        'custom_fields' => $cfRows,
    ]);
}

// ------------------------------------------------- copy requirements (Refs #1348) ---
// Legacy: lib/requirements/reqSpecEdit.php?doAction=copyRequirements&req_spec_id=<id>
// -> reqSpecCommands::copyRequirements() renders reqCopy.tpl: choose a target
// req spec in the project (containers = get_subtree + createHierarchyMap dotted
// by doc_id) + the source spec's requirements (itemSet checkboxes) + the
// copy_testcase_assignments checkbox. doCopyRequirements() then runs
// requirement_mgr::copy_to() per selected requirement with a COPY audit event.
if ($method === 'GET' && $action === 'copy_options') {
    $specId = intval($_REQUEST['id'] ?? 0);
    if ($specId <= 0) { badRequest('Invalid req spec id'); }

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
    needManageRight($ownerTid);

    $spec = $reqSpecMgr->get_by_id($specId);
    if (!$spec) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Requirement specification not found']);
    }

    // destination specs: the project req-spec subtree (legacy non-recursive
    // get_subtree with order_cfg type rspec + output rspec), dotted by doc_id.
    $excludeNodeTypes = ['testplan' => 'exclude_me', 'testsuite' => 'exclude_me',
                         'testcase' => 'exclude_me', 'requirement' => 'exclude_me',
                         'requirement_spec_revision' => 'exclude_me'];
    $filters = ['exclude_node_types' => $excludeNodeTypes];
    $getOpts = ['order_cfg' => ['type' => 'rspec'], 'output' => 'rspec', 'get_items' => true];
    $subtree = $reqMgr->tree_mgr->get_subtree($ownerTid, $filters, $getOpts);
    $containers = [];
    if (count($subtree)) {
        $containers = $reqMgr->tree_mgr->createHierarchyMap(
            $subtree, 'dotted', ['field' => 'doc_id', 'format' => '%s:']);
    }

    out([
        'status'        => 'ok',
        'tproject_id'   => $ownerTid,
        'tproject_name' => testproject::getName($db, $ownerTid),
        'spec' => [
            'id'     => intval($spec['id']),
            'doc_id' => (string)$spec['doc_id'],
            'title'  => (string)$spec['title'],
        ],
        'items'      => listSpecRequirements($db, $specId),
        'containers' => $containers,
    ]);
}

if ($method === 'POST' && $action === 'copy_reqs') {
    $tproject_id = needTprojectId();
    needManageRight($tproject_id);

    $specId = intval($BODY['req_spec_id'] ?? 0);
    if ($specId <= 0) { badRequest('Invalid req spec id'); }
    needOwnedSpec($specId, $tproject_id);

    $containerId = intval($BODY['container_id'] ?? 0);
    if ($containerId <= 0) { badRequest('Invalid target specification id'); }
    $tgtRows = $db->get_recordset(
        'SELECT testproject_id FROM req_specs WHERE id = ' . intval($containerId) . ' LIMIT 1');
    if (!$tgtRows || intval($tgtRows[0]['testproject_id']) !== intval($tproject_id)) {
        badRequest('Target specification does not belong to this test project');
    }

    $itemSet = array_unique(array_filter(array_map('intval', (array)($BODY['itemSet'] ?? []))));
    if (!count($itemSet)) { badRequest(lang_get('select_at_least_one_req')); }

    $copyOptions = ['copy_also' => ['testcase_assignment' => !empty($BODY['copy_testcase_assignment'])]];
    $messages = [];
    $errors = [];
    foreach ($itemSet as $itemId) {
        $ret = $reqMgr->copy_to($itemId, $containerId, $userId, $tproject_id, $copyOptions);
        if ($ret['status_ok']) {
            $newReq = $reqMgr->get_by_id(intval($ret['id']), requirement_mgr::LATEST_VERSION);
            $srcReq = $reqMgr->get_by_id($itemId, requirement_mgr::LATEST_VERSION);
            $logMsg = (string)$ret['msg'];
            if (is_array($newReq) && count($newReq) && is_array($srcReq) && count($srcReq)) {
                // TLS() returns a tlMetaString -> cast to localize via __toString()
                $logMsg = (string)TLS('audit_requirement_copy',
                                     $newReq[0]['req_doc_id'], $srcReq[0]['req_doc_id']);
            }
            logAuditEvent($logMsg, 'COPY', intval($ret['id']), 'requirements');
            $messages[] = $logMsg;
        } else {
            $errors[] = (string)$ret['msg'];
        }
    }

    out([
        'status'        => 'ok',
        'tproject_id'   => $tproject_id,
        'tproject_name' => testproject::getName($db, $tproject_id),
        'messages'      => $messages,
        'errors'        => $errors,
        'copied'        => count($messages),
        'items'         => listSpecRequirements($db, $specId),
    ]);
}

// ------------------------------------------------- bulk monitoring (Refs #1348) ---
// Legacy: lib/requirements/reqSpecEdit.php?doAction=bulkReqMon&req_spec_id=<id>
// -> reqSpecCommands::bulkReqMon() renders reqBulkMon.tpl (per-req monitor flag
// + Start/Stop/Toggle submit buttons); doBulkReqMon() toggles monitorOn/monitorOff
// for the selected requirements of the current user and re-renders.
if ($method === 'GET' && $action === 'bulk_mon_options') {
    $specId = intval($_REQUEST['id'] ?? 0);
    if ($specId <= 0) { badRequest('Invalid req spec id'); }
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
    needManageRight($ownerTid);
    out(array_merge(['status' => 'ok'],
                    bulkMonPayload($reqSpecMgr, $reqMgr, $db, $specId, $userId, $ownerTid)));
}

if ($method === 'POST' && $action === 'bulk_mon_toggle') {
    $tproject_id = needTprojectId();
    needManageRight($tproject_id);

    $specId = intval($BODY['req_spec_id'] ?? 0);
    if ($specId <= 0) { badRequest('Invalid req spec id'); }
    needOwnedSpec($specId, $tproject_id);

    $op = (string)($BODY['op'] ?? '');
    if (!in_array($op, ['toogleMon', 'startMon', 'stopMon'], true)) {
        badRequest('Invalid operation');
    }
    $itemSet = array_unique(array_filter(array_map('intval', (array)($BODY['itemSet'] ?? []))));
    if (!count($itemSet)) { badRequest(lang_get('select_at_least_one_req')); }

    if ($op === 'toogleMon') {
        $monSet = $reqMgr->getMonitoredByUser($userId, $tproject_id, ['reqSpecID' => $specId]);
        foreach ($itemSet as $reqId) {
            $isOn = ($monSet !== null && isset($monSet[$reqId]));
            if ($isOn) { $reqMgr->monitorOff($reqId, $userId, $tproject_id); }
            else       { $reqMgr->monitorOn($reqId, $userId, $tproject_id); }
        }
    } else {
        $call = ($op === 'startMon') ? 'monitorOn' : 'monitorOff';
        foreach ($itemSet as $reqId) {
            $reqMgr->$call($reqId, $userId, $tproject_id);
        }
    }

    out(array_merge(['status' => 'ok'],
                    bulkMonPayload($reqSpecMgr, $reqMgr, $db, $specId, $userId, $tproject_id)));
}

http_response_code(404);
out(['status' => 'error', 'message' => 'Unknown action']);
