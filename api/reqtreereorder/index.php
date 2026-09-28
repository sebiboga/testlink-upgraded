<?php
/**
 * Requirement Specification Tree Reorder / Re-parent BFF API
 * URL: /api/reqtreereorder/index.php
 * Plain PHP, no framework, no compilation.
 *
 * This is the modern, rights-checked replacement of the 1.9.20 ExtJS
 * drag-and-drop of the REQUIREMENT SPECIFICATION tree, whose backend was
 * lib/ajax/dragdroprequirementnodes.php (Refs #1681).
 *
 * The legacy endpoint handled BOTH gestures:
 *   doAction=changeParent -> tree::change_parent($nodeid, $newparentid)
 *                           => UPDATE nodes_hierarchy SET parent_id = <new>
 *                              WHERE id = <node>
 *                           => UPDATE requirements    SET srs_id  = <new>
 *                              WHERE id = <node>
 *   doAction=doReorder    -> tree::change_order_bulk(explode(',', $nodelist))
 *                           => UPDATE nodes_hierarchy
 *                              SET node_order = <array index> WHERE id = <node>
 *
 * and it did so with NO rights check, NO ownership check, NO same-origin
 * proof, reading $_REQUEST (so a GET mutated) - the same hole shape that
 * #1660 closed for the test-case tree (dragdroptprojectnodes.php) and #1673
 * closed for the step order (lib/ajax/stepReorder.php). lib/ajax/
 * dragdroprequirementnodes.php is now a non-mutating session-guarded shim.
 *
 * 2.0.1 schema note (the #1660 finding): nodes_hierarchy has only
 * id, name, parent_id, node_type_id, node_order - there is no
 * testproject_id column. Ownership is therefore proved by walking parent_id
 * up to the node_type_id = 1 (testproject) node, or - cheaper and used here -
 * by joining the owning row of the spec/requirement table, which does keep
 * its own project link (req_specs.testproject_id / requirements.srs_id).
 *
 * node_type_id map (from the node_types table): 1 testproject,
 * 2 testsuite, 3 testcase, 4 testcase_version, 6 requirement_spec,
 * 7 requirement, 8 requirement_version, 11 requirement_spec_revision.
 *
 * Rights (legacy parity of the reqSpecMgmt/reqEdit "reorder" gate):
 *   view   -> mgt_view_req OR mgt_modify_req  (read the tree + spec list)
 *   write  -> mgt_modify_req                  (move / reorder)
 * both evaluated on the OWNING test project of the addressed node, not on the
 * requested one, so a caller cannot borrow a project they may manage to
 * rewrite another project's tree.
 *
 * Endpoints (JSON in/out):
 *   GET  ?action=init&tproject_id=N[&req_spec_id=M][&node_id=K]
 *        -> context + spec options + ordered requirement list + grants
 *   POST ?action=move
 *        {tproject_id, node_id, new_spec_id, position: "top"|"bottom"}
 *        Re-parents one requirement into another specification.
 *   POST ?action=reorder
 *        {tproject_id, req_spec_id, nodes_order:[req_id, ...]}
 *        Rewrites node_order of a specification from a complete id list.
 *
 * Status codes: 200 ok / no_change, 400 bad param, 401 anonymous or expired
 * session, 403 no right, 404 unknown spec/requirement/project, 405 wrong verb,
 * 500 guarded.
 */
require_once(__DIR__ . '/../../config.inc.php');
require_once(__DIR__ . '/../../config_db.inc.php');
require_once('common.php');

doSessionStart();
require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

$db = new database(DB_TYPE);
doDBConnect($db);

function out($data) { echo json_encode($data); exit; }
function failOut($code, $message, $machine = '') {
    http_response_code($code);
    $p = ['status' => 'error', 'message' => $message];
    if ($machine !== '') { $p['code'] = $machine; }
    out($p);
}

set_exception_handler(function ($e) {
    error_log('api/reqtreereorder: ' . $e->getMessage());
    if (!headers_sent()) { http_response_code(500); }
    echo json_encode(['status' => 'error', 'code' => 'server_error',
                      'message' => 'Internal error']);
    exit;
});

$userId = isset($_SESSION['userID']) ? intval($_SESSION['userID']) : 0;
if ($userId <= 0) {
    http_response_code(401);
    out(['status' => 'error', 'code' => 'not_authenticated',
         'message' => 'Not authenticated']);
}

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    http_response_code(401);
    out(['status' => 'error', 'code' => 'not_authenticated',
         'message' => 'User not found']);
}

// Refs #1614 / #1660 review: a write-capable BFF must honour the legacy
// session inactivity timeout (checkSessionValid), not just "userID > 0".
bffEnforceSession($db);

$reqSpecMgr = new requirement_spec_mgr($db);
$reqMgr     = new requirement_mgr($db);
$tprojMgr   = new testproject($db);

define('NODE_TYPE_TESTPROJECT',     1);
define('NODE_TYPE_REQUIREMENT_SPEC', 6);
define('NODE_TYPE_REQUIREMENT',      7);

$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
$action = isset($_REQUEST['action']) ? trim((string)$_REQUEST['action']) : '';
$BODY   = json_decode(file_get_contents('php://input'), true);
if (!is_array($BODY)) { $BODY = array(); }

function param($key, $default = 0) {
    global $BODY, $_REQUEST;
    if (array_key_exists($key, $BODY)) { return $BODY[$key]; }
    if (array_key_exists($key, $_REQUEST)) { return $_REQUEST[$key]; }
    return $default;
}

/** Test project must exist; returns its id. No right check here. */
function needTprojectId() {
    global $tprojMgr;
    $tid = intval(param('tproject_id', 0));
    if ($tid <= 0) {
        failOut(400, 'Invalid test project id', 'invalid_tproject');
    }
    $info = $tprojMgr->get_by_id($tid);
    if (!$info) {
        failOut(404, 'Test project does not exist', 'tproject_not_found');
    }
    return $tid;
}

/** Spec must exist AND belong to $tproject_id; returns its id. */
function needOwnedSpec($specId, $tproject_id) {
    global $db, $reqSpecMgr;
    $sid = intval($specId);
    if ($sid <= 0) {
        failOut(400, 'Invalid requirement specification id', 'invalid_req_spec');
    }
    $rows = $db->get_recordset(
        'SELECT RS.testproject_id, NH.node_type_id' .
        ' FROM ' . $reqSpecMgr->object_table . ' RS' .
        ' LEFT JOIN nodes_hierarchy NH ON NH.id = RS.id' .
        ' WHERE RS.id = ' . $sid);
    if (!$rows) {
        failOut(404, 'Requirement specification not found', 'req_spec_not_found');
    }
    if (intval($rows[0]['node_type_id']) !== NODE_TYPE_REQUIREMENT_SPEC) {
        failOut(400, 'Node ' . $sid . ' is not a requirement specification',
                'not_a_req_spec');
    }
    if (intval($rows[0]['testproject_id']) !== intval($tproject_id)) {
        failOut(404, 'Requirement specification not found', 'req_spec_not_found');
    }
    return $sid;
}

/** Requirement must exist; returns array(row, owning tproject id). */
function needOwnedRequirement($reqId) {
    global $db, $reqMgr;
    $rid = intval($reqId);
    if ($rid <= 0) {
        failOut(400, 'Invalid requirement id', 'invalid_requirement');
    }
    $rows = $db->get_recordset(
        'SELECT R.id, R.srs_id, R.req_doc_id, NH.name AS title, NH.parent_id,' .
        ' NH.node_order, NH.node_type_id' .
        ' FROM ' . $reqMgr->object_table . ' R' .
        ' LEFT JOIN nodes_hierarchy NH ON NH.id = R.id' .
        ' WHERE R.id = ' . $rid);
    if (!$rows) {
        failOut(404, 'Requirement does not exist', 'requirement_not_found');
    }
    if (intval($rows[0]['node_type_id']) !== NODE_TYPE_REQUIREMENT) {
        failOut(400, 'Node ' . $rid . ' is not a requirement', 'not_a_requirement');
    }
    return $rows[0];
}

/** Owning test project of a requirement, proved through its spec. */
function requirementOwnerTproject($row) {
    global $db, $reqSpecMgr;
    $rows = $db->get_recordset(
        'SELECT testproject_id FROM ' . $reqSpecMgr->object_table .
        ' WHERE id = ' . intval($row['srs_id']));
    if (!$rows) {
        failOut(404, 'Owning requirement specification not found',
                'req_spec_not_found');
    }
    return intval($rows[0]['testproject_id']);
}

/** Spec picker options: every requirement specification of the project. */
function specOptions($tproject_id) {
    global $db, $reqSpecMgr;
    $rows = $db->get_recordset(
        'SELECT RS.id, RS.doc_id, NH.name AS title,' .
        ' (SELECT COUNT(1) FROM requirements C WHERE C.srs_id = RS.id) AS total_reqs' .
        ' FROM ' . $reqSpecMgr->object_table . ' RS' .
        ' JOIN nodes_hierarchy NH ON NH.id = RS.id AND NH.node_type_id = ' .
            NODE_TYPE_REQUIREMENT_SPEC .
        ' WHERE RS.testproject_id = ' . intval($tproject_id) .
        ' ORDER BY RS.doc_id ASC, RS.id ASC');
    $out = array();
    foreach (($rows ? $rows : array()) as $r) {
        $out[] = array(
            'id'          => intval($r['id']),
            'doc_id'      => (string)$r['doc_id'],
            'title'       => (string)$r['title'],
            'total_reqs'  => intval($r['total_reqs']),
        );
    }
    return $out;
}

/**
 * Ordered requirement list of a spec. Same read order the modern spec viewer
 * uses (nodes_hierarchy.node_order ASC, id ASC) so both screens agree.
 */
function orderedRequirements($specId) {
    global $db, $reqMgr;
    $rows = $db->get_recordset(
        'SELECT R.id, R.req_doc_id, NH.name AS title, NH.node_order,' .
        ' V.status, V.type, V.version' .
        ' FROM ' . $reqMgr->object_table . ' R' .
        ' JOIN nodes_hierarchy NH ON NH.id = R.id' .
        ' LEFT JOIN nodes_hierarchy VN ON VN.parent_id = R.id' .
        '   AND VN.node_type_id = 8' .
        // TestLink ADDS a node_type_id=8 node on every revision and never deletes
        // the previous one, so without "latest only" a revised requirement is
        // returned once per version - the screen would list it several times and
        // the resulting POST would always be rejected as a duplicate id. The
        // LEFT JOIN is kept (unlike api/reqreorder) so a requirement that has no
        // version node at all still appears in the list.
        '   AND VN.id = (SELECT MAX(VN2.id) FROM nodes_hierarchy VN2' .
        '        WHERE VN2.parent_id = R.id AND VN2.node_type_id = 8)' .
        ' LEFT JOIN req_versions V ON V.id = VN.id' .
        ' WHERE R.srs_id = ' . intval($specId) .
        ' ORDER BY NH.node_order ASC, R.id ASC');
    $list = array();
    foreach (($rows ? $rows : array()) as $r) {
        $list[] = array(
            'id'         => intval($r['id']),
            'req_doc_id' => (string)$r['req_doc_id'],
            'title'      => (string)$r['title'],
            'status'     => (string)$r['status'],
            'type'       => (string)$r['type'],
            'version'    => intval($r['version']),
            'node_order' => intval($r['node_order']),
        );
    }
    return $list;
}

function specHeader($specId) {
    global $db, $reqSpecMgr;
    $rows = $db->get_recordset(
        'SELECT RS.id, RS.doc_id, NH.name AS title, V.revision, V.type,' .
        ' V.scope, V.total_req, V.author_id, U.login AS author_login' .
        ' FROM ' . $reqSpecMgr->object_table . ' RS' .
        ' LEFT JOIN nodes_hierarchy NH ON NH.id = RS.id' .
        ' LEFT JOIN req_specs_revisions V ON V.parent_id = RS.id' .
        '   AND V.revision = (SELECT MAX(V2.revision) FROM req_specs_revisions V2' .
        '        WHERE V2.parent_id = RS.id)' .
        ' LEFT JOIN users U ON U.id = V.author_id' .
        ' WHERE RS.id = ' . intval($specId));
    return ($rows && $rows[0]) ? $rows[0] : array();
}

// ============================================================== init =======
if ($action === 'init') {
    if ($method !== 'GET') {
        failOut(405, 'This action only accepts GET', 'wrong_method');
    }
    $tproject_id = needTprojectId();

    // Rights are decided FIRST, on the project the caller asked for. Resolving
    // the node before this point would turn the endpoint into an existence
    // oracle: a user with no rights here got 404 "requirement_not_found" for a
    // foreign id but 403 for one that exists, which is exactly the cross-project
    // probe this endpoint must not answer.
    $canSee = $user->hasRight($db, 'mgt_view_req', $tproject_id) ||
              $user->hasRight($db, 'mgt_modify_req', $tproject_id);
    if (!$canSee) {
        failOut(403, 'You are not authorized to view requirements', 'no_right');
    }

    $specId = intval(param('req_spec_id', 0));
    $nodeId = intval(param('node_id', 0));
    $selectedNode = null;

    if ($nodeId > 0) {
        // The node proof is the authority: its OWNING project decides rights,
        // never the tproject_id the caller happened to send.
        $row = needOwnedRequirement($nodeId);
        $ownerTid = requirementOwnerTproject($row);
        $specId = intval($row['srs_id']);
        $spec = specHeader($specId);
        $selectedNode = array(
            'id'          => intval($row['id']),
            'req_doc_id'  => (string)$row['req_doc_id'],
            'title'       => (string)$row['title'],
            'current_spec_id'    => $specId,
            'current_spec_title' => (string)($spec['title'] ?? ''),
            'current_spec_doc_id' => (string)($spec['doc_id'] ?? ''),
        );
    } elseif ($specId > 0) {
        $specId = needOwnedSpec($specId, $tproject_id);
    }

    if ($selectedNode !== null && $ownerTid !== $tproject_id) {
        failOut(404, 'Requirement does not exist in this test project',
                'requirement_not_found');
    }

    $spec = ($specId > 0) ? specHeader($specId) : array();

    out(array(
        'status'      => 'ok',
        'context'     => array(
            'tproject_id'     => $tproject_id,
            'tproject_name'   => (string)($tprojMgr->get_by_id($tproject_id)['name'] ?? ''),
            'req_spec_id'     => $specId,
            'req_spec_title'  => (string)($spec['title'] ?? ''),
            'req_spec_doc_id' => (string)($spec['doc_id'] ?? ''),
            'revision'        => intval($spec['revision'] ?? 0),
            // Author of the newest specification revision - powers the
            // "Modified by" tile instead of a placeholder dash.
            'author_id'       => intval($spec['author_id'] ?? 0),
            'author_login'    => (string)($spec['author_login'] ?? ''),
        ),
        'specs'       => specOptions($tproject_id),
        'requirements' => ($specId > 0) ? orderedRequirements($specId) : array(),
        'selected_node' => $selectedNode,
        'grant'       => array(
            'view'   => $canSee,
            'modify' => $user->hasRight($db, 'mgt_modify_req', $tproject_id),
        ),
    ));
}

// ============================================================== move =======
// Modern equivalent of the legacy doAction=changeParent (nodes_hierarchy
// parent_id + requirements srs_id, both, in one action). The legacy version
// read a $top_or_bottom parameter and then NEVER USED IT, and never touched
// node_order, so a moved requirement kept a foreign node_order and could
// land in an arbitrary slot of the new specification. Here the position is
// honoured: "bottom" appends after the last sibling, "top" shifts the
// siblings down and inserts at 0.
if ($action === 'move') {
    if ($method !== 'POST') {
        failOut(405, 'This action only accepts POST', 'wrong_method');
    }
    $tproject_id = needTprojectId();
    $row = needOwnedRequirement(param('node_id', 0));
    $ownerTid = requirementOwnerTproject($row);
    if ($ownerTid !== $tproject_id) {
        failOut(404, 'Requirement does not exist in this test project',
                'requirement_not_found');
    }
    if (!$user->hasRight($db, 'mgt_modify_req', $ownerTid)) {
        failOut(403, 'You have no right to modify requirements', 'no_right');
    }
    $newSpecId = needOwnedSpec(param('new_spec_id', 0), $tproject_id);

    // requirements carries UNIQUE KEY (srs_id, req_doc_id) and the move does not
    // regenerate req_doc_id, so re-using a document id that already exists in the
    // target specification would blow up on the SECOND write - after
    // nodes_hierarchy had already been re-parented and the siblings shifted.
    // There is no transaction wrapper in the driver, so the collision is caught
    // up front and answered with a stable machine code instead of a 500 plus
    // half-applied table drift.
    if ($newSpecId !== intval($row['srs_id'])) {
        $dup = $db->get_recordset(
            'SELECT id FROM ' . $reqMgr->object_table .
            ' WHERE srs_id = ' . $newSpecId .
            '   AND req_doc_id = \'' . $db->prepare_string($row['req_doc_id']) . '\'' .
            '   AND id <> ' . intval($row['id']));
        if ($dup && $dup[0]) {
            failOut(409, 'The target specification already contains document ID '
                    . $row['req_doc_id'], 'duplicate_doc_id');
        }
    }

    $position = strtolower(trim((string)param('position', 'bottom')));
    if ($position !== 'top' && $position !== 'bottom') {
        failOut(400, 'Position must be "top" or "bottom"', 'invalid_position');
    }

    $curSpec   = intval($row['srs_id']);
    $curParent = intval($row['parent_id']);
    $curOrder  = intval($row['node_order']);

    // A specification node is always its own parent_id in 2.0.1, but the
    // legacy write set both columns; mirror the target parent from the spec
    // node so the two tables cannot drift apart.
    $specNode = $db->get_recordset(
        'SELECT parent_id FROM nodes_hierarchy WHERE id = ' . $newSpecId .
        ' AND node_type_id = ' . NODE_TYPE_REQUIREMENT_SPEC);
    $newParentId = ($specNode && $specNode[0]) ? intval($specNode[0]['parent_id']) : 0;

    if ($curSpec === $newSpecId && $newParentId === $curParent) {
        $maxRow = $db->get_recordset(
            'SELECT MAX(node_order) AS m FROM nodes_hierarchy' .
            ' WHERE parent_id = ' . $newParentId .
            '   AND node_type_id = ' . NODE_TYPE_REQUIREMENT);
        $maxOrder = ($maxRow && $maxRow[0]) ? intval($maxRow[0]['m']) : 0;
        if (($position === 'bottom' && $curOrder >= $maxOrder) ||
            ($position === 'top' && $curOrder === 0)) {
            out(array(
                'status' => 'no_change',
                'message' => 'The requirement is already in that position',
                'node_id' => intval($row['id']),
                'req_spec_id' => $newSpecId,
            ));
        }
    }

    if ($position === 'top') {
        $db->exec_query(
            // node_order is nullable, so a plain +1 leaves NULL siblings tying
            // with the row we insert at 0.
            'UPDATE nodes_hierarchy SET node_order = COALESCE(node_order, 0) + 1' .
            ' WHERE parent_id = ' . $newParentId .
            '   AND node_type_id = ' . NODE_TYPE_REQUIREMENT .
            '   AND id <> ' . intval($row['id']));
        $newOrder = 0;
    } else {
        $maxRow = $db->get_recordset(
            'SELECT MAX(node_order) AS m FROM nodes_hierarchy' .
            ' WHERE parent_id = ' . $newParentId .
            '   AND node_type_id = ' . NODE_TYPE_REQUIREMENT);
        $maxOrder = ($maxRow && $maxRow[0]) ? intval($maxRow[0]['m']) : 0;
        $newOrder = $maxOrder + 1;
        if ($curParent === $newParentId && $curOrder === $newOrder) {
            out(array('status' => 'no_change',
                      'message' => 'The requirement is already in that position',
                      'node_id' => intval($row['id']),
                      'req_spec_id' => $newSpecId));
        }
    }

    $db->exec_query(
        'UPDATE nodes_hierarchy SET parent_id = ' . $newParentId .
        ', node_order = ' . intval($newOrder) .
        ' WHERE id = ' . intval($row['id']));
    $db->exec_query(
        'UPDATE ' . $reqMgr->object_table . ' SET srs_id = ' . $newSpecId .
        ' WHERE id = ' . intval($row['id']));

    tLog('BFF reqtreereorder: requirement ' . intval($row['id']) .
         ' (' . $row['req_doc_id'] . ') moved from spec ' . $curSpec .
         ' to spec ' . $newSpecId . ' at position ' . $position . '.',
         'INFO');

    out(array(
        'status'      => 'ok',
        'moved'       => 1,
        'node_id'     => intval($row['id']),
        'req_doc_id'  => (string)$row['req_doc_id'],
        'from_req_spec_id' => $curSpec,
        'req_spec_id' => $newSpecId,
        'position'    => $position,
    ));
}

// ============================================================ reorder ======
// Modern equivalent of the legacy doAction=doReorder
// (tree::change_order_bulk(explode(',', $nodelist))).
if ($action === 'reorder') {
    if ($method !== 'POST') {
        failOut(405, 'This action only accepts POST', 'wrong_method');
    }
    $tproject_id = needTprojectId();
    $specId = needOwnedSpec(param('req_spec_id', 0), $tproject_id);
    if (!$user->hasRight($db, 'mgt_modify_req', $tproject_id)) {
        failOut(403, 'You have no right to modify requirements', 'no_right');
    }

    $submitted = param('nodes_order', array());
    if (!is_array($submitted)) {
        failOut(400, 'nodes_order must be an array of requirement ids',
                'invalid_nodes_order');
    }
    $order = array();
    $seen = array();
    foreach ($submitted as $v) {
        $nid = intval($v);
        if ($nid <= 0) {
            failOut(400, 'Invalid requirement id in nodes_order',
                    'invalid_nodes_order');
        }
        // The duplicate check must look at the IDS seen so far, not at the
        // value at array index $nid: $order is a positional list, so
        // isset($order[$nid]) tested an unrelated slot and let a repeated id
        // through - which then wrote node_order twice for the same node and
        // left the omitted sibling with a stale order.
        if (isset($seen[$nid])) {
            failOut(400, 'Duplicate requirement id in nodes_order',
                    'invalid_nodes_order');
        }
        $seen[$nid] = 1;
        $order[] = $nid;
    }

    $current = orderedRequirements($specId);
    $currentIds = array();
    foreach ($current as $c) { $currentIds[] = intval($c['id']); }

    if (count($order) < 2 && count($currentIds) >= 2) {
        failOut(400, 'At least two requirements are needed to reorder',
                'invalid_nodes_order');
    }

    // Every submitted id must be a requirement of THIS specification and the
    // list must be complete, so a caller can never renumber only part of the
    // tree (the legacy endpoint happily accepted any comma list).
    $currentSet = array_flip($currentIds);
    foreach ($order as $nid) {
        if (!isset($currentSet[$nid])) {
            failOut(400, 'Requirement ' . $nid .
                    ' does not belong to this specification',
                    'foreign_requirement');
        }
    }
    if (count($order) !== count($currentIds)) {
        failOut(400, 'The order list must contain every requirement of the ' .
                     'specification (' . count($currentIds) . ' expected, ' .
                     count($order) . ' received)', 'incomplete_nodes_order');
    }

    // Refs #1674: compare the submitted ORDER with the current ORDER. A
    // sorted-id comparison is equal before and after any write and reports
    // no_change for every reorder.
    if ($order === $currentIds) {
        out(array('status' => 'no_change',
                  'message' => 'The order was not changed',
                  'reordered' => 0,
                  'req_spec_id' => $specId));
    }

    foreach ($order as $idx => $nid) {
        $db->exec_query(
            'UPDATE nodes_hierarchy SET node_order = ' . intval($idx) .
            ' WHERE id = ' . intval($nid));
    }

    tLog('BFF reqtreereorder: specification ' . $specId .
         ' reordered (' . count($order) . ' requirements).', 'INFO');

    out(array('status' => 'ok', 'reordered' => count($order),
              'req_spec_id' => $specId));
}

failOut(400, 'Unknown action', 'unknown_action');
