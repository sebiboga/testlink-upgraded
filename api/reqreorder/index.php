<?php
/**
 * Reorder Requirements BFF API
 * URL: /api/reqreorder/index.php
 * Plain PHP, no framework, no compilation — mirrors the legacy
 * lib/requirements/reqSpecMgmt.php "Reorder Requirements" sub-screen
 * (reqReorder.tpl + reqCommands doAction=reorder / doReorder) that the
 * reqSpecMgmt screen launches from the Requirement Specification viewer
 * toolbar (Refs #1488).
 *
 * The reorder is a REAL tree-node order change: every requirement is a
 * nodes_hierarchy child of the requirement specification node, and its
 * relative order is `nodes_hierarchy.node_order` (0-based). The legacy
 * `requirement_mgr::set_order()` -> `tree_mgr->change_order_bulk($map)`
 * writes `node_order = abs(intval($order))` where `$order` is the 0-based
 * array index in the posted top-to-bottom order. The modern reqspec viewer
 * already reads requirements `ORDER BY nh.node_order ASC`, so persisting
 * the same 0-based index keeps the two modern screens (spec viewer list +
 * reorder screen) in perfect sync.
 *
 * Rights (same split the legacy checkRights() uses on reqEdit.php reorder):
 *   view   -> mgt_view_req OR mgt_modify_req   (read the current order tree)
 *   reorder-> mgt_modify_req                    (persist a new order)
 *
 * Endpoints (JSON in/out):
 *   GET  ?action=init&req_spec_id=N&tproject_id=N
 *        -> spec header (latest spec revision) + ordered requirement list
 *   POST ?action=reorder&req_spec_id=N&tproject_id=N
 *        {nodes_order:[req_id, ...]}  -> persists node_order = index
 *        Returns the number of requirements reordered.
 */
require_once(__DIR__ . '/../../config.inc.php');
require_once(__DIR__ . '/../../config_db.inc.php');
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

function out($data) { echo json_encode($data); exit; }

$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_REQUEST['action']) ? trim($_REQUEST['action']) : '';
$BODY = json_decode(file_get_contents('php://input'), true) ?? [];

$reqSpecMgr = new requirement_spec_mgr($db);
$reqMgr     = new requirement_mgr($db);

/** Locate the test project the spec belongs to + verify it exists. */
function needTprojectId() {
    global $db, $tprojMgr, $user, $BODY;
    $tid = intval($_REQUEST['tproject_id'] ?? ($BODY['tproject_id'] ?? 0));
    if ($tid <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test project id']);
    }
    $info = $tprojMgr->get_by_id($tid);
    if (!$info) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Test project does not exist']);
    }
    if (!$user->hasRight($db, 'mgt_view_req', $tid) &&
        !$user->hasRight($db, 'mgt_modify_req', $tid)) {
        http_response_code(403);
        out(['status' => 'error',
             'message' => 'You are not authorized to view requirements']);
    }
    return $tid;
}

/** Spec must exist AND belong to the test project in context. */
function needOwnedSpec($specId, $tproject_id) {
    global $db, $reqSpecMgr;
    $rows = $db->get_recordset(
        'SELECT RS.testproject_id FROM ' . $reqSpecMgr->object_table . ' RS' .
        ' WHERE RS.id = ' . intval($specId));
    if (!$rows || intval($rows[0]['testproject_id']) !== intval($tproject_id)) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Requirement specification not found']);
    }
    return intval($rows[0]['testproject_id']);
}

/**
 * Build the ordered requirement list of a spec. Mirrors the legacy
 * requirement_mgr::get_requirements() order (nh.node_order ASC) that the
 * modern spec viewer uses, so the drag list and viewer agree.
 */
function buildOrderedReqs($specId) {
    global $db;
    // node types: requirements=7, requirement_version=8 (nodes_hierarchy
    // stores the requirement node itself; its LATEST version is the child
    // node with type requirement_version and max id). Titles/order live on
    // the requirement node, req_doc_id/status/type/version on req_versions.
    $sql = "SELECT R.id, NH.name AS title, V.req_doc_id, NH.node_order," .
           " V.status, V.type, V.version" .
           " FROM requirements R" .
           " JOIN nodes_hierarchy NH ON NH.id = R.id" .
           " JOIN nodes_hierarchy VN ON VN.parent_id = R.id" .
           "   AND VN.node_type_id = 8" .
           " JOIN req_versions V ON V.id = VN.id" .
           " WHERE R.srs_id = " . intval($specId) .
           "   AND VN.id = (SELECT MAX(VN2.id) FROM nodes_hierarchy VN2" .
           "        WHERE VN2.parent_id = R.id AND VN2.node_type_id = 8)" .
           " ORDER BY NH.node_order ASC, R.id ASC";
    $rows = $db->get_recordset($sql);
    $list = [];
    foreach (($rows ? $rows : []) as $r) {
        $list[] = [
            'id'         => intval($r['id']),
            'req_doc_id' => (string)$r['req_doc_id'],
            'title'      => (string)$r['title'],
            'status'     => (string)$r['status'],
            'type'       => (string)$r['type'],
            'version'    => intval($r['version']),
        ];
    }
    return $list;
}

// --------------------------------------------------------------- init ------
if ($method === 'GET' && $action === 'init') {
    $tproject_id = needTprojectId();
    $specId = intval($_REQUEST['req_spec_id'] ?? 0);
    if ($specId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid req spec id']);
    }
    $ownerTid = needOwnedSpec($specId, $tproject_id);

    $specHdr = $db->get_recordset(
        'SELECT RS.id, RS.doc_id, NH.name AS title, V.revision, V.type,' .
        ' V.scope, V.total_req' .
        ' FROM ' . $reqSpecMgr->object_table . ' RS' .
        ' JOIN nodes_hierarchy NH ON NH.id = RS.id' .
        ' JOIN req_specs_revisions V ON V.parent_id = RS.id' .
        '   AND V.revision = (SELECT MAX(V2.revision) FROM req_specs_revisions V2' .
        '        WHERE V2.parent_id = RS.id)' .
        ' WHERE RS.id = ' . intval($specId));
    $spec = $specHdr ? $specHdr[0] : null;

    out([
        'status'         => 'ok',
        'req_spec_id'    => $specId,
        'req_spec_title' => (string)($spec['title'] ?? ''),
        'doc_id'         => (string)($spec['doc_id'] ?? ''),
        'revision'       => intval($spec['revision'] ?? 0),
        'requirements'   => buildOrderedReqs($specId),
        'grant'          => [
            'view'       => $user->hasRight($db, 'mgt_view_req', $ownerTid) ||
                            $user->hasRight($db, 'mgt_modify_req', $ownerTid),
            'reorder'    => $user->hasRight($db, 'mgt_modify_req', $ownerTid),
        ],
    ]);
}

// ------------------------------------------------------------- reorder ------
if ($method === 'POST' && $action === 'reorder') {
    $tproject_id = needTprojectId();
    $specId = intval($_REQUEST['req_spec_id'] ?? ($BODY['req_spec_id'] ?? 0));
    if ($specId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid req spec id']);
    }
    $ownerTid = needOwnedSpec($specId, $tproject_id);

    if (!$user->hasRight($db, 'mgt_modify_req', $ownerTid)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'You have no right to modify requirements']);
    }

    $nodes = 0;
    $order = [];
    foreach ((array)($BODY['nodes_order'] ?? []) as $k => $v) {
        $nid = intval($v);
        if ($nid > 0 && !isset($order[$nid])) { $order[$nid] = intval($k); }
    }
    // same ownership probe legacy get_requirements does - every id must be a
    // requirement of THIS spec (guards against reordering foreign nodes)
    $in = [];
    foreach (array_keys($order) as $nid) { $in[] = intval($nid); }
    if (count($in) < 2) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'At least two requirements are needed to reorder']);
    }
    $inSql = implode(',', $in);
    $owned = $db->get_recordset(
        'SELECT id FROM ' . $reqMgr->object_table . ' WHERE srs_id = ' .
        intval($specId) . ' AND id IN (' . $inSql . ')');
    $ownedSet = [];
    foreach (($owned ? $owned : []) as $r) { $ownedSet[intval($r['id'])] = 1; }
    foreach ($in as $nid) {
        if (!isset($ownedSet[$nid])) {
            http_response_code(400);
            out(['status' => 'error',
                 'message' => 'Requirement ' . $nid . ' does not belong to this specification']);
        }
    }

    foreach ($order as $nid => $nodeOrder) {
        $db->exec_query(
            'UPDATE nodes_hierarchy SET node_order = ' .
            intval($nodeOrder) . ' WHERE id = ' . intval($nid));
    }

    out(['status' => 'ok', 'reordered' => count($order)]);
}

http_response_code(404);
out(['status' => 'error', 'message' => 'Unknown action']);
