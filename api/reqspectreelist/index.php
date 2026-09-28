<?php
/**
 * Requirement Specification Tree navigator BFF API
 * URL: /api/reqspectreelist/index.php
 * Plain PHP, no framework, no compilation.
 *
 * Modern, rights-checked replacement of the 1.9.20 "Requirement Specification
 * tree" frame lib/requirements/reqSpecListTree.php (Refs #1695).
 *
 * Legacy behaviour being ported
 * -----------------------------
 * reqSpecListTree.php (84 lines) built a two-level ExtJS tree through
 * tlRequirementFilterControl::build_tree_menu() and rendered
 * gui/templates/dashio/requirements/reqSpecListTree.tpl (inc_head.tpl +
 * inc_ext_js.tpl + treebyloader.js / execTree.js). The tree was:
 *
 *   root   = test project, label "<project name> (<n requirements>)"
 *            href javascript:TPROJECT_REQ_SPEC_MGMT(<tproject_id>)
 *   level2 = requirement specifications, label "<doc_id>:<name> (<n reqs>)"
 *            href javascript:REQ_SPEC_MGMT(<tproject_id>,<id>)
 *   level3 = requirements,          label "<req_doc_id>:<name>"
 *            href javascript:REQ_MGMT(<tproject_id>,<id>)
 *
 * Without an active filter the children were LAZY LOADED from
 * lib/ajax/getrequirementnodes.php?mode=reqspec&root_node=<tproject_id>
 * (Ext JS passes &node=<expanded id>), so both files together implemented the
 * navigator. That loader is a pure read endpoint and this BFF re-implements its
 * node shape for the modern screen, node by node.
 *
 * Security holes inherited from the loader (closed here)
 * -----------------------------------------------------
 * 1. lib/ajax/getrequirementnodes.php performed NO rights check at all - only
 *    testlinkInitPage(). Any authenticated user could read the requirement
 *    documents (doc_id + title) of ANY test project by sending
 *    ?root_node=<foreign id>, and could read the children of ANY node in the
 *    hierarchy by sending an arbitrary ?node= (the SQL only filtered on
 *    `NHA.parent_id = <parent>`, with no project scope and no node-type gate).
 *    Here the rights are mgt_view_req OR mgt_modify_req on the addressed
 *    project, and every node id is proven to be a requirement specification of
 *    that same project (or the project's own node) before any name is returned.
 * 2. The tree the legacy screen advertised for drag-and-drop posted to
 *    lib/ajax/dragdroprequirementnodes.php; that endpoint is the one hardened
 *    by #1681 and is now a non-mutating shim, and this screen is READ-ONLY on
 *    purpose: the write gesture lives in gui/templates/requirements/
 *    reqTreeReorder.html (api/reqtreereorder).
 *
 * node_type_id map (node_types table): 1 testproject, 6 requirement_spec,
 * 7 requirement, 8 requirement_version, 11 requirement_spec_revision.
 * Ownership of a specification is read from req_specs.testproject_id, which
 * keeps its own project link (nodes_hierarchy does not - see #1660).
 *
 * Refs #1699 (container parity): with req_cfg->child_requirements_mgmt ENABLED
 * (the default, config.inc.php:1689) a node_type_id = 1 TEST PROJECT node can
 * be re-parented under a specification, and requirements then hang off that
 * container. The legacy loader walked ANY child node type, so the container and
 * its requirements appeared in the old tree; this navigator used to list only
 * requirements parented by the specification itself and dropped the whole
 * branch without a trace. specList() now reports the containers and children
 * accepts &container=, both behind the same rights + ownership proof.
 *
 * Endpoints (JSON in/out, GET only - this screen writes nothing):
 *   GET ?action=init&tproject_id=N
 *        -> context + rights + the project node + its specification children
 *           (doc_id, title, revision, total requirement count, author, and the
 *           CONTAINERS the specification holds - Refs #1699).
 *   GET ?action=children&tproject_id=N&node_id=M[&container=K]
 *        -> the lazy-load contract of lib/ajax/getrequirementnodes.php: the
 *           requirements of specification M (M is proven to belong to N). With
 *           &container=K it returns the requirements parented by container K,
 *           which is proven to be a node_type_id = 1 child of M.
 *   GET ?action=projects
 *        -> the requirement-enabled test projects the caller may read, so the
 *           screen can offer a project switcher (the legacy frame always used
 *           the session project and had no switcher at all).
 *
 * Status codes: 200 ok / 400 bad param / 401 anonymous or expired session,
 * 403 no right, 404 unknown project, 405 wrong verb, 500 guarded.
 */
require_once(__DIR__ . '/../../config.inc.php');
require_once(__DIR__ . '/../../config_db.inc.php');
require_once('common.php');

doSessionStart();
require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$db = new database(DB_TYPE);
doDBConnect($db);

function out($data) { echo json_encode($data); exit; }

function failOut($code, $message, $machine = '')
{
    http_response_code($code);
    $p = array('status' => 'error', 'message' => $message);
    if ($machine !== '') { $p['code'] = $machine; }
    out($p);
}

set_exception_handler(function ($e) {
    error_log('api/reqspectreelist: ' . $e->getMessage());
    if (!headers_sent()) { http_response_code(500); }
    echo json_encode(array('status' => 'error', 'code' => 'server_error',
        'message' => 'Internal error'));
    exit;
});

$userId = isset($_SESSION['userID']) ? intval($_SESSION['userID']) : 0;
if ($userId <= 0) {
    http_response_code(401);
    out(array('status' => 'error', 'code' => 'not_authenticated',
        'message' => 'Not authenticated'));
}

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    http_response_code(401);
    out(array('status' => 'error', 'code' => 'not_authenticated',
        'message' => 'User not found'));
}

// Refs #1614: the legacy frame went through testlinkInitPage(), which runs
// checkSessionValid() on EVERY page load, so a stale session was bounced to
// login.php?note=expired. A BFF must answer instead of redirecting.
bffEnforceSession($db);

$reqSpecMgr = new requirement_spec_mgr($db);
$reqMgr     = new requirement_mgr($db);
$tprojMgr   = new testproject($db);

define('NODE_TYPE_TESTPROJECT',      1);
define('NODE_TYPE_REQUIREMENT_SPEC', 6);
define('NODE_TYPE_REQUIREMENT',      7);

$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
$action = isset($_REQUEST['action']) ? trim((string)$_REQUEST['action']) : 'init';

function param($key, $default = 0)
{
    return array_key_exists($key, $_REQUEST) ? $_REQUEST[$key] : $default;
}

/**
 * Validate the requested test project id WITHOUT disclosing whether it exists.
 *
 * The rights check has to run BEFORE the existence lookup: if the existence
 * lookup came first it answered 404 tproject_not_found for a bogus id and 403
 * no_right for a real one, which lets ANY authenticated user enumerate the ids
 * of every test project in the installation. So an unauthorized caller gets the
 * same 403 no_right for both, and only a caller who may read requirements ever
 * learns that the project is missing.
 */
function needTprojectId()
{
    global $tprojMgr, $user;
    $tid = intval(param('tproject_id', 0));
    if ($tid <= 0) {
        failOut(400, 'Invalid test project id', 'invalid_tproject');
    }
    if (!canViewReqs($user, $tid)) {
        failOut(403, 'You are not authorized to view requirements', 'no_right');
    }
    $info = $tprojMgr->get_by_id($tid);
    if (is_null($info) || !isset($info['name'])) {
        failOut(404, 'Test project does not exist', 'tproject_not_found');
    }
    return $tid;
}

/** Tolerantly read a project flag (the #1376 / #1516 pattern). */
function projFlag($proj, $key, $default = 0)
{
    $opt = isset($proj['opt']) ? $proj['opt'] : null;
    if (is_object($opt) && isset($opt->$key)) {
        return intval($opt->$key) > 0 ? 1 : 0;
    }
    if (is_array($opt) && isset($opt[$key])) {
        return intval($opt[$key]) > 0 ? 1 : 0;
    }
    return intval($default) > 0 ? 1 : 0;
}

/** requirements are enabled per project (testprojects.option_reqs). */
function requirementsEnabled($proj)
{
    return projFlag($proj, 'requirementsEnabled',
        isset($proj['option_reqs']) ? intval($proj['option_reqs']) : 1);
}

function canViewReqs($user, $tproject_id)
{
    return $user->hasRight($GLOBALS['db'], 'mgt_view_req', $tproject_id) ||
           $user->hasRight($GLOBALS['db'], 'mgt_modify_req', $tproject_id);
}

/**
 * req_cfg->child_requirements_mgmt (config.inc.php:1689, ENABLED by default).
 *
 * When it is on, TestLink allows a TEST PROJECT node to be re-parented under a
 * requirement specification and requirements to hang off that container. The
 * legacy lazy loader walked any child node type, so such a container - and the
 * requirements under it - appeared in the old tree. When it is off, the legacy
 * loader still *listed* the container (the node-type filter is unrelated to the
 * flag) but the drag-and-drop refused the pairing
 * ($forbidden_parent['requirement_spec'] = 'none' becomes 'requirement_spec'),
 * i.e. the shape was not part of the supported UI. The modern navigator mirrors
 * that: the flag decides whether the container branch is offered at all.
 */
function childRequirementsMgmtEnabled()
{
    $cfg = config_get('req_cfg');
    $enabled = (is_object($cfg) && isset($cfg->child_requirements_mgmt))
        ? $cfg->child_requirements_mgmt : ENABLED;
    return (intval($enabled) > 0) ? 1 : 0;
}

/**
 * Requirement count of a container - a node_type_id = 1 node a specification
 * holds as a child. Live, exactly like the count on a specification row (the
 * #1681 lesson): the number of requirement rows parented by the container.
 *
 * Legacy did not count this node at all - getAllItemsID() is only called for
 * `requirement_spec` rows, with `container => requirement_spec`, so it could
 * not even traverse a test-project node, and a `testproject` row got nothing
 * but an href. The badge is therefore a 2.0.1 addition; the issue asked for "its
 * own live count" and this is that count.
 */
function containerReqCount($containerId)
{
    global $db, $reqMgr;
    $rows = $db->get_recordset(
        'SELECT COUNT(1) AS c FROM ' . $reqMgr->object_table . ' R' .
        ' JOIN nodes_hierarchy NH ON NH.id = R.id' .
        '   AND NH.node_type_id = ' . NODE_TYPE_REQUIREMENT .
        ' WHERE R.srs_id = ' . intval($containerId));
    return ($rows && $rows[0]) ? intval($rows[0]['c']) : 0;
}

/**
 * The node_type_id = 1 children of a specification - the containers that hold
 * further requirements. Only returned when child_requirements_mgmt is enabled.
 *
 * The node is proved to be a container OF THIS SPECIFICATION here (parent_id),
 * and the specification it hangs under has already been proved to belong to the
 * addressed project by needOwnedSpec() before this runs - so a container id can
 * never leak the name of a node from another project.
 */
function containerChildren($specId)
{
    global $db, $tprojMgr, $user;
    $rows = $db->get_recordset(
        'SELECT NH.id, NH.name AS title, NH.node_order' .
        ' FROM nodes_hierarchy NH' .
        ' WHERE NH.parent_id = ' . intval($specId) .
        '   AND NH.node_type_id = ' . NODE_TYPE_TESTPROJECT .
        ' ORDER BY NH.node_order ASC, NH.id ASC');

    $out = array();
    foreach (($rows ? $rows : array()) as $r) {
        $cid = intval($r['id']);
        $info = $tprojMgr->get_by_id($cid);
        $out[] = array(
            'id'            => $cid,
            'title'         => (string)$r['title'],
            'node_order'    => intval($r['node_order']),
            'total_reqs'    => containerReqCount($cid),
            'tproject_id'   => $cid,
            // A container that is a real, readable test project of its own gets
            // a deep link into the project. One that is only a node (deleted
            // project, or a project the caller may not read) still renders, but
            // without a link, so the tree never offers a destination that 403s.
            'openable'      => (!is_null($info) && isset($info['name'])
                                && canViewReqs($user, $cid)) ? 1 : 0,
        );
    }
    return $out;
}

/**
 * Specification list of a project, ordered the way the legacy tree ordered it
 * (nodes_hierarchy.node_order ASC, id ASC) so the modern navigator and the
 * legacy one present the specifications in the same sequence.
 *
 * The count is the live number of requirement rows parented by the
 * specification, NOT the denormalised req_specs_revisions.total_req (the #1681
 * lesson). It does NOT include the requirements that hang off a container the
 * specification holds: those are counted on the container row, exactly as the
 * legacy tree showed them (spec label = direct children, container label = its
 * own children).
 *
 * Refs #1699: `containers` carries the node_type_id = 1 children of the
 * specification when req_cfg->child_requirements_mgmt is on - the shape the
 * legacy lazy loader walked with getAllItemsID() and the modern navigator used
 * to drop silently.
 */
function specList($tproject_id)
{
    global $db, $reqSpecMgr;

    $rows = $db->get_recordset(
        'SELECT RS.id, RS.doc_id, NH.name AS title, NH.node_order,' .
        ' (SELECT COUNT(1) FROM requirements C WHERE C.srs_id = RS.id)' .
        '   AS total_reqs' .
        ' FROM ' . $reqSpecMgr->object_table . ' RS' .
        ' JOIN nodes_hierarchy NH ON NH.id = RS.id' .
        '   AND NH.node_type_id = ' . NODE_TYPE_REQUIREMENT_SPEC .
        ' WHERE RS.testproject_id = ' . intval($tproject_id) .
        ' ORDER BY NH.node_order ASC, RS.id ASC');

    $withContainers = childRequirementsMgmtEnabled();
    $out = array();
    foreach (($rows ? $rows : array()) as $r) {
        $sid = intval($r['id']);
        $out[] = array(
            'id'          => $sid,
            'doc_id'      => (string)$r['doc_id'],
            'title'       => (string)$r['title'],
            'node_order'  => intval($r['node_order']),
            'total_reqs'  => intval($r['total_reqs']),
            'containers'  => $withContainers
                ? containerChildren($sid) : array(),
        );
    }
    return $out;
}

/**
 * A specification node id must be a real requirement specification of
 * $tproject_id. This is the ownership proof the legacy loader never did.
 */
function needOwnedSpec($specId, $tproject_id)
{
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
    // Driven FROM req_specs, so a node that is not a specification produces no
    // row at all and a foreign specification fails the ownership test below:
    // both answer the same 404, which is what keeps this endpoint free of a
    // node-existence oracle.
    if (!$rows || !$rows[0] ||
        intval($rows[0]['node_type_id']) !== NODE_TYPE_REQUIREMENT_SPEC) {
        failOut(404, 'Requirement specification not found', 'req_spec_not_found');
    }
    if (intval($rows[0]['testproject_id']) !== intval($tproject_id)) {
        // Cross-project id: indistinguishable from a non-existent one on purpose.
        failOut(404, 'Requirement specification not found', 'req_spec_not_found');
    }
    return $sid;
}

/**
 * A container id must be a node_type_id = 1 node whose parent_id is the
 * addressed specification - and that specification is already proven to belong
 * to the addressed project by needOwnedSpec(). The chain is what keeps the
 * legacy loader's missing scope check closed: a project node from anywhere else
 * in the installation is not a child of this spec, so it fails here with the
 * same 404 as a non-existent node (no node-existence oracle).
 */
function needOwnedContainer($containerId, $specId)
{
    global $db;
    $cid = intval($containerId);
    if ($cid <= 0) {
        failOut(400, 'Invalid container id', 'invalid_container');
    }
    $rows = $db->get_recordset(
        'SELECT id FROM nodes_hierarchy' .
        ' WHERE id = ' . $cid .
        '   AND node_type_id = ' . NODE_TYPE_TESTPROJECT .
        '   AND parent_id = ' . intval($specId));
    if (!$rows) {
        failOut(404, 'Container not found', 'container_not_found');
    }
    return $cid;
}

/** Total requirement count of a project (the legacy root-node label). */
function projectReqCount($tproject_id)
{
    global $db;
    $rows = $db->get_recordset(
        'SELECT COUNT(1) AS c FROM requirements R' .
        ' JOIN ' . $GLOBALS['reqSpecMgr']->object_table . ' RS ON RS.id = R.srs_id' .
        ' WHERE RS.testproject_id = ' . intval($tproject_id));
    return ($rows && $rows[0]) ? intval($rows[0]['c']) : 0;
}

/**
 * Requirements parented by a specification OR by a container it holds, in tree
 * order - the third level of the legacy navigator, and the body of the
 * lazy-load answer. Only the fields the tree rendered (doc_id, name,
 * latest-version status) plus the modern deep link are returned.
 *
 * $srsId is any node id that can legally hold requirements: a specification
 * (node_type_id 6) or a container (node_type_id 1). The caller proves which one
 * it is - needOwnedSpec() for the former, needOwnedContainer() for the latter -
 * before this runs, so this function never has to re-derive the ownership.
 *
 * Refs #1699: `type` and `version` used to be emitted from keys the SELECT never
 * projected, so every requirement came back with the constant pair "type":"",
 * "version":0 and each expand wrote `E_WARNING Undefined array key` into the
 * events table. The SELECT now projects `V.type` / `V.version` off the latest
 * version row, so both keys carry real values. (reqSpecListTree.html does not
 * render them yet; they are part of the payload the sibling BFFs
 * api/reqtreereorder / api/reqreorder already return.)
 */
function requirementChildren($srsId)
{
    global $db, $reqMgr;
    $rows = $db->get_recordset(
        'SELECT R.id, R.req_doc_id, NH.name AS title, NH.node_order,' .
        ' V.status, V.type, V.version' .
        ' FROM ' . $reqMgr->object_table . ' R' .
        ' JOIN nodes_hierarchy NH ON NH.id = R.id' .
        '   AND NH.node_type_id = ' . NODE_TYPE_REQUIREMENT .
        ' LEFT JOIN nodes_hierarchy VN ON VN.parent_id = R.id' .
        '   AND VN.node_type_id = 8' .
        '   AND VN.id = (SELECT MAX(VN2.id) FROM nodes_hierarchy VN2' .
        '        WHERE VN2.parent_id = R.id AND VN2.node_type_id = 8)' .
        ' LEFT JOIN req_versions V ON V.id = VN.id' .
        ' WHERE R.srs_id = ' . intval($srsId) .
        ' ORDER BY NH.node_order ASC, R.id ASC');

    $out = array();
    foreach (($rows ? $rows : array()) as $r) {
        $out[] = array(
            'id'          => intval($r['id']),
            'req_doc_id'  => (string)$r['req_doc_id'],
            'title'       => (string)$r['title'],
            'node_order'  => intval($r['node_order']),
            'status'      => (string)$r['status'],
            'type'        => (string)$r['type'],
            'version'     => intval($r['version']),
        );
    }
    return $out;
}

// ================================================================ init ======
if ($action === 'init') {
    if ($method !== 'GET') {
        failOut(405, 'This action only accepts GET', 'wrong_method');
    }
    // needTprojectId() has already enforced mgt_view_req / mgt_modify_req on
    // this project BEFORE revealing whether it exists.
    $tproject_id = needTprojectId();
    $proj = $tprojMgr->get_by_id($tproject_id);
    $reqsEnabled = requirementsEnabled($proj);
    $specs = $reqsEnabled ? specList($tproject_id) : array();

    out(array(
        'status'  => 'ok',
        'context' => array(
            'tproject_id'     => $tproject_id,
            'tproject_name'   => (string)$proj['name'],
            'prefix'          => (string)(isset($proj['prefix']) ? $proj['prefix'] : ''),
            'requirements_enabled' => $reqsEnabled,
            'total_reqs'      => $reqsEnabled ? projectReqCount($tproject_id) : 0,
            'total_specs'     => count($specs),
            'user_id'         => $userId,
            'user_login'      => (string)$user->login,
        ),
        'specs'   => $specs,
        'grant'   => array(
            'view'   => true,
            // hasRight() can answer the string "yes" - normalize to a real
            // boolean so the JSON grant map is not mixed-type.
            'modify' => (bool)$user->hasRight($db, 'mgt_modify_req', $tproject_id),
        ),
    ));
}

// ============================================================ children ======
// The lazy-load answer of lib/ajax/getrequirementnodes.php?node=<id>. The node
// is proven to be a requirement specification of the addressed project before
// any requirement name is returned.
//
// Refs #1699: with &container=<node_id> the same answer is produced for a
// CONTAINER - a node_type_id = 1 node a specification holds as a child
// (req_cfg->child_requirements_mgmt). Without the parameter the node must be a
// specification; with it the node must be a container OF that specification.
// A container of a specification of another project fails the spec proof first
// (404 req_spec_not_found), a container that is not a child of the addressed
// specification fails the container proof (404 container_not_found) - both
// indistinguishable from "no such node".
if ($action === 'children') {
    if ($method !== 'GET') {
        failOut(405, 'This action only accepts GET', 'wrong_method');
    }
    $tproject_id = needTprojectId();
    $specId = needOwnedSpec(param('node_id', 0), $tproject_id);
    // An ABSENT container means "the specification's own children". A container
    // that is present but not a positive id is a caller bug, and answering the
    // specification's children for it would make the mistake invisible - so it
    // is rejected instead of silently degraded.
    $containerId = array_key_exists('container', $_REQUEST)
        ? intval(param('container', 0)) : 0;
    if ($containerId > 0) {
        if (!childRequirementsMgmtEnabled()) {
            failOut(400, 'Child requirement management is disabled',
                'child_requirements_mgmt_disabled');
        }
        $parentId = needOwnedContainer($containerId, $specId);
    } elseif (array_key_exists('container', $_REQUEST)) {
        failOut(400, 'Invalid container id', 'invalid_container');
    } else {
        $parentId = $specId;
    }
    out(array(
        'status'  => 'ok',
        'children' => requirementChildren($parentId),
        'parent_kind' => ($parentId === $specId) ? 'spec' : 'container',
    ));
}

// ============================================================ projects ======
// The legacy frame had no project switcher (it always read
// $_SESSION['testprojectID']). The modern screen gets one, so it needs the
// list of requirement-enabled projects the caller may actually read.
if ($action === 'projects') {
    if ($method !== 'GET') {
        failOut(405, 'This action only accepts GET', 'wrong_method');
    }
    $all = $tprojMgr->get_all();
    $sessionTid = isset($_SESSION['testprojectID']) ? intval($_SESSION['testprojectID']) : 0;
    $out = array();
    foreach (($all ? $all : array()) as $p) {
        $pid = intval($p['id']);
        if ($pid <= 0) { continue; }
        if (!canViewReqs($user, $pid)) { continue; }
        if (!requirementsEnabled($p)) { continue; }
        $out[] = array(
            'id'    => $pid,
            'name'  => (string)$p['name'],
            'prefix' => (string)(isset($p['prefix']) ? $p['prefix'] : ''),
            'active' => ($p['active'] == 1) ? 1 : 0,
            'is_current' => ($pid === $sessionTid) ? 1 : 0,
        );
    }
    usort($out, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
    out(array('status' => 'ok', 'projects' => $out,
        'session_tproject_id' => $sessionTid));
}

failOut(400, 'Unknown action', 'unknown_action');
