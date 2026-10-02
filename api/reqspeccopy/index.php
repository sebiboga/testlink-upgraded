<?php
/**
 * Requirement Specification COPY popup BFF API.
 * URL: /api/reqspeccopy/index.php
 *
 * Modern, rights-checked replacement for the legacy `copy` / `doCopy` actions of
 * lib/requirements/reqSpecEdit.php, which rendered the Smarty screen
 * gui/templates/dashio/requirements/reqSpecCopy.tpl (Refs #1797).
 *
 * Legacy behaviour being reproduced
 * ---------------------------------
 *   reqSpecCommands::copy()  -> renders the popup: the source specification
 *                               (doc_id + title) plus a `containerID` <select>
 *                               built from the requirement hierarchy of the
 *                               current test project (test plan, test suite, test
 *                               case and requirement nodes are excluded, so the
 *                               only possible destinations are a test project
 *                               root or another requirement specification) and a
 *                               `target_position` radio pair (top / bottom).
 *   reqSpecCommands::doCopy() -> requirement_spec_mgr::copy_to() writes the copy
 *                               (recursive: child specifications, requirements,
 *                               requirement versions, custom fields and
 *                               attachments) and answers the legacy message
 *                               lang_get('req_spec_copy_done').
 *
 * Gaps in the legacy screen that are CLOSED here
 * ----------------------------------------------
 *  1. NO rights check on the destination. Legacy `checkRights()` ran against the
 *     session context only; `containerID` and `tproject_id` came straight out of
 *     the POST body, so a copy could be aimed at any node of any test project.
 *     Here `mgt_view_req` + `mgt_modify_req` are enforced on the DESTINATION
 *     project and `mgt_view_req` on the project that OWNS the source spec.
 *  2. The source specification was offered as its own destination, which makes
 *     copy_to() duplicate a subtree into itself. The source and its whole
 *     descendant subtree are now removed from the destination list.
 *  3. `target_position` (top / bottom) was rendered but NEVER READ by doCopy() -
 *     copy_to() was called with 4 arguments and the radio pair was dead UI. The
 *     position is now honoured by renumbering the destination siblings.
 *  4. The write was a plain form POST that the controller executed and then
 *     re-rendered as the same page. Writes here are POST-only behind
 *     bffSameOriginGuard() + bffEnforceSession().
 *  5. The destination select was built from `$_SESSION`-derived tproject_id; the
 *     destination project is an explicit, rights-checked parameter here.
 *
 * JSON contract
 * -------------
 *   GET  ?action=init&req_spec_id=N[&tproject_id=P]
 *        200 {status:'ok', source:{...}, destinations:[...], rights:{...},
 *             default_project_id:N, tree_has_container:bool}
 *        400 invalid_req_spec_id / invalid_tproject_id / invalid_destination
 *        401 not_authenticated | session_expired
 *        403 no_right
 *        404 req_spec_not_found
 *        405 method_not_allowed
 *   POST ?action=copy  req_spec_id=N&container_id=M[&tproject_id=P][&target_position=top|bottom]
 *        200 {status:'ok', new:{id,doc_id,title}, message:'<legacy text>'}
 *        400 / 401 / 403 / 404 / 405 / 409 (as above)
 *
 * Every error answer carries a stable machine code in `code`.
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once(__DIR__ . '/../../config_db.inc.php');
require_once('common.php');

doSessionStart();
require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$db = new database(DB_TYPE);
doDBConnect($db);

function out($data)
{
    echo json_encode($data);
    exit;
}

function failOut($code, $message, $machine = '')
{
    http_response_code($code);
    $p = array('status' => 'error', 'message' => $message);
    if ($machine !== '') {
        $p['code'] = $machine;
    }
    out($p);
}

set_exception_handler(function ($e) {
    error_log('api/reqspeccopy: ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo json_encode(array('status' => 'error', 'code' => 'server_error',
        'message' => 'Internal error'));
    exit;
});

/* ------------------------------------------------------------------ session */

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
        'message' => 'User found'));
}

bffEnforceSession($db);

/* --------------------------------------------------------------- constants */

define('RSC_NODE_TESTPROJECT', 1);
define('RSC_NODE_REQ_SPEC',    6);

/* ------------------------------------------------------------------ request */

$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
$action = isset($_REQUEST['action']) ? trim((string)$_REQUEST['action']) : 'init';

/**
 * Read a request parameter: query string / form body first, then a JSON body.
 */
function rscParam($key, $default = '')
{
    if (array_key_exists($key, $_REQUEST)) {
        return $_REQUEST[$key];
    }
    static $json = null;
    if ($json === null) {
        $json = array();
        $ctype = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
        $raw = file_get_contents('php://input');
        if ($raw !== '' && $raw !== false && stripos($ctype, 'json') !== false) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $json = $decoded;
            }
        }
    }
    return array_key_exists($key, $json) ? $json[$key] : $default;
}

function rscParamInt($key, $default = 0)
{
    $v = rscParam($key, null);
    if ($v === null || $v === '' || is_array($v)) {
        return $default;
    }
    return intval($v);
}

/**
 * Strict positive integer id - anything else is a bad request, never a lookup.
 */
function rscPositiveId($raw, $machine)
{
    if ($raw === null || $raw === '' || is_array($raw) || !is_scalar($raw)) {
        failOut(400, 'Missing or invalid id (' . $machine . ')', $machine);
    }
    $s = trim((string)$raw);
    if ($s === '' || !preg_match('/^[0-9]+$/', $s) || intval($s) <= 0) {
        failOut(400, 'Missing or invalid id (' . $machine . ')', $machine);
    }
    return intval($s);
}

/* ------------------------------------------------------------------ helpers */

/**
 * Owning test project of any nodes_hierarchy node: walk parent_id up to the
 * node_type_id = 1 root. 0 when the chain is broken.
 */
function rscOwningProject($db, $nodeId)
{
    $id = intval($nodeId);
    $guard = 0;
    while ($id > 0 && $guard < 64) {
        $r = $db->fetchFirstRow(
            'SELECT NH.parent_id, NH.node_type_id
               FROM nodes_hierarchy NH
              WHERE NH.id = ' . $id);
        if (!is_array($r)) {
            return 0;
        }
        if (intval($r['node_type_id']) === RSC_NODE_TESTPROJECT) {
            return $id;
        }
        $id = intval($r['parent_id']);
        $guard++;
    }
    return 0;
}

/**
 * node_type_id + owning test project of a node, in one round trip.
 */
function rscNodeInfo($db, $nodeId)
{
    $id = intval($nodeId);
    if ($id <= 0) {
        return null;
    }
    $r = $db->fetchFirstRow(
        'SELECT NH.id, NH.parent_id, NH.node_order, NH.node_type_id, NH.name
           FROM nodes_hierarchy NH
          WHERE NH.id = ' . $id);
    if (!is_array($r)) {
        return null;
    }
    return array(
        'id'           => intval($r['id']),
        'parent_id'    => intval($r['parent_id']),
        'node_order'   => intval($r['node_order']),
        'node_type_id' => intval($r['node_type_id']),
        'name'         => $r['name'],
        'tproject_id'  => rscOwningProject($db, $id),
    );
}

/**
 * Does the caller hold `right` on the given test project?
 */
function rscHasRight($db, $user, $right, $tprojectId)
{
    if ($tprojectId <= 0) {
        return false;
    }
    return (bool)$user->hasRight($db, $right, $tprojectId);
}

/**
 * Requirement Specification Copy needs BOTH rights, on the destination project,
 * exactly like the legacy checkRights() (rightsAnd = mgt_view_req +
 * mgt_modify_req).
 */
function rscRequireCopyRights($db, $user, $tprojectId)
{
    if (!rscHasRight($db, $user, 'mgt_view_req', $tprojectId) ||
        !rscHasRight($db, $user, 'mgt_modify_req', $tprojectId)) {
        if (!rscHasRight($db, $user, 'mgt_view_req', 0) &&
            !rscHasRight($db, $user, 'mgt_modify_req', 0)) {
            tLog('api/reqspeccopy: mgt_view_req + mgt_modify_req missing on test project ' .
                 intval($tprojectId) . ' for user ' . intval($user->id), 'ERROR');
        }
        failOut(403, 'mgt_view_req + mgt_modify_req are required on the destination ' .
                     'test project', 'no_right');
    }
}

/**
 * Resolve + authorize the SOURCE specification. Returns the manager, the spec
 * row and the id of the project that owns it.
 *
 * The rights check runs BEFORE the specification is resolved whenever a
 * destination project is asserted, so a caller without the right cannot turn
 * this endpoint into a cross-project id oracle (the #1697 lesson).
 */
function rscResolveSource($db, $user, $reqSpecId, $assertedProjectId)
{
    $specMgr = new requirement_spec_mgr($db);

    // Pre-authorization: if the caller told us which project it is aiming at,
    // prove the right on THAT project before the source id is dereferenced.
    if ($assertedProjectId > 0) {
        rscRequireCopyRights($db, $user, $assertedProjectId);
    }

    // Refs #1797. requirement_spec_mgr::get_by_id() runs an unguarded JOIN, so
    // an id that is not a specification node makes it raise a DB Access Error -
    // which the shared handler answers as HTTP 200 with a server-path backtrace
    // in the body (verified: ?action=init&req_spec_id=999999). Prove the node
    // exists AND is a specification before dereferencing it.
    $node = rscNodeInfo($db, $reqSpecId);
    if (is_null($node) || $node['node_type_id'] !== RSC_NODE_REQ_SPEC) {
        failOut(404, 'Requirement specification not found', 'req_spec_not_found');
    }

    $spec = $specMgr->get_by_id($reqSpecId);
    if (empty($spec) || intval($spec['id']) !== $reqSpecId) {
        failOut(404, 'Requirement specification not found', 'req_spec_not_found');
    }

    $ownerProject = $node['tproject_id'];
    if ($ownerProject <= 0) {
        failOut(404, 'Requirement specification not found', 'req_spec_not_found');
    }

    // Reading the source always needs mgt_view_req on its owning project.
    if (!rscHasRight($db, $user, 'mgt_view_req', $ownerProject)) {
        tLog('api/reqspeccopy: mgt_view_req missing on test project ' . $ownerProject .
             ' for user ' . intval($user->id), 'ERROR');
        failOut(403, 'mgt_view_req is required on the test project that owns the ' .
                     'source specification', 'no_right');
    }

    return array($specMgr, $spec, $ownerProject);
}

/**
 * The destination list, exactly like legacy reqSpecCommands::copy(): the test
 * project root plus every requirement specification below it (test plan, test
 * suite, test case and requirement nodes excluded). The source specification and
 * its whole subtree are removed.
 *
 * @return array flat list of {id, name, depth, is_project}
 */
function rscDestinationList($db, $tprojectId, $sourceSpecId)
{
    $rows = $db->get_recordset(
        'SELECT NH.id, NH.parent_id, NH.node_order, NH.node_type_id, NH.name
           FROM nodes_hierarchy NH
          WHERE NH.node_type_id IN (' . RSC_NODE_TESTPROJECT . ',' . RSC_NODE_REQ_SPEC . ')
          ORDER BY NH.node_type_id ASC, NH.node_order ASC, NH.id ASC');

    $byId = array();
    $children = array();
    foreach ((array)$rows as $r) {
        $id = intval($r['id']);
        $byId[$id] = array(
            'id'           => $id,
            'parent_id'    => intval($r['parent_id']),
            'node_order'   => intval($r['node_order']),
            'node_type_id' => intval($r['node_type_id']),
            'name'         => (string)$r['name'],
        );
        $children[$byId[$id]['parent_id']][] = $id;
    }

    // The source specification itself is off limits - it is the root of the
    // subtree that must not be duplicated into itself, so the walk below never
    // descends into it. Its ANCESTORS (including the test project root) stay in
    // the list: they are legitimate destinations.
    $blocked = array(intval($sourceSpecId) => true);

    $out = array();
    $stack = array(array($tprojectId, 0));
    $seen = array();
    while (count($stack) > 0) {
        list($id, $depth) = array_pop($stack);
        if ($id <= 0 || isset($seen[$id])) {
            continue;
        }
        $seen[$id] = true;
        if (!isset($byId[$id]) || isset($blocked[$id])) {
            continue;
        }
        $out[] = array(
            'id'         => $id,
            'name'       => $byId[$id]['name'],
            'depth'      => $depth,
            'is_project' => ($byId[$id]['node_type_id'] === RSC_NODE_TESTPROJECT),
        );
        if (isset($children[$id])) {
            $kids = $children[$id];
            for ($i = count($kids) - 1; $i >= 0; $i--) {
                $stack[] = array($kids[$i], $depth + 1);
            }
        }
    }
    return $out;
}

/**
 * Resolve + prove the DESTINATION container: it must be a test project root or a
 * requirement specification, and it must live inside the destination project.
 */
function rscResolveContainer($db, $containerId, $tprojectId)
{
    $info = rscNodeInfo($db, $containerId);
    if (is_null($info)) {
        failOut(404, 'Destination container not found', 'destination_not_found');
    }
    if ($info['node_type_id'] !== RSC_NODE_TESTPROJECT &&
        $info['node_type_id'] !== RSC_NODE_REQ_SPEC) {
        failOut(400, 'Destination must be a test project or a requirement specification',
            'invalid_destination');
    }
    if ($info['tproject_id'] !== intval($tprojectId)) {
        failOut(400, 'Destination container belongs to another test project',
            'invalid_destination');
    }
    return $info;
}

/**
 * Honour `target_position`, which the legacy radio pair never did.
 *
 * nodes_hierarchy carries the `pid_m_nodeorder` index on
 * (parent_id, node_order), so the renumbering is done in two phases: every
 * sibling is first parked far above every real order value (node_order is
 * `int unsigned`, so a negative parking range is not available), then moved
 * into its final 1..n position. Doing it in this order means no intermediate
 * state can make two siblings share a (parent_id, node_order) pair.
 */
function rscApplyTargetPosition($db, $containerId, $newSpecId, $position)
{
    $rows = $db->get_recordset(
        'SELECT NH.id
           FROM nodes_hierarchy NH
          WHERE NH.parent_id = ' . intval($containerId) .
          ' AND NH.node_type_id IN (' . RSC_NODE_TESTPROJECT . ',' . RSC_NODE_REQ_SPEC . ')');
    $siblings = array();
    foreach ((array)$rows as $r) {
        $id = intval($r['id']);
        if ($id !== intval($newSpecId)) {
            $siblings[] = $id;
        }
    }
    if (count($siblings) === 0) {
        return;
    }
    sort($siblings, SORT_NUMERIC);

    if ($position === 'top') {
        array_unshift($siblings, intval($newSpecId));
    } else {
        $siblings[] = intval($newSpecId);
    }

    // phase 1 - parked far above every real order, so no intermediate state can
    // collide with a sibling that has not been moved yet
    $park = 1000000;
    for ($i = 0; $i < count($siblings); $i++) {
        $db->exec_query('UPDATE nodes_hierarchy SET node_order = ' . ($park + $i) .
                        ' WHERE id = ' . intval($siblings[$i]));
    }
    // phase 2 - final 1..n
    for ($i = 0; $i < count($siblings); $i++) {
        $db->exec_query('UPDATE nodes_hierarchy SET node_order = ' . ($i + 1) .
                        ' WHERE id = ' . intval($siblings[$i]));
    }
}

/* ------------------------------------------------------------------ routing */

$allowed = array('init', 'copy');
if (!in_array($action, $allowed, true)) {
    failOut(400, 'Unknown action', 'unknown_action');
}

/* ---- POST targets -------------------------------------------------------- */

if ($action === 'copy') {
    if ($method !== 'POST') {
        header('Allow: POST');
        failOut(405, 'This action accepts POST only', 'method_not_allowed');
    }

    $reqSpecId  = rscPositiveId(rscParam('req_spec_id', null), 'invalid_req_spec_id');
    $containerId = rscPositiveId(rscParam('container_id', null), 'invalid_container_id');

    $tprojectRaw = rscParam('tproject_id', null);
    $asserted = 0;
    if ($tprojectRaw !== null && $tprojectRaw !== '' && !is_array($tprojectRaw)) {
        if (!preg_match('/^[0-9]+$/', trim((string)$tprojectRaw))) {
            failOut(400, 'Invalid tproject_id', 'invalid_tproject_id');
        }
        $asserted = intval($tprojectRaw);
    }

    $position = strtolower(trim((string)rscParam('target_position', 'bottom')));
    if ($position === '') {
        $position = 'bottom';
    }
    if ($position !== 'top' && $position !== 'bottom') {
        failOut(400, 'target_position must be top or bottom', 'invalid_target_position');
    }

    list($specMgr, $spec, $ownerProject) =
        rscResolveSource($db, $user, $reqSpecId, $asserted);

    // Destination project: explicit, or the project that owns the source.
    $tprojectId = ($asserted > 0) ? $asserted : $ownerProject;
    rscRequireCopyRights($db, $user, $tprojectId);

    // The destination must not be the source itself nor one of its descendants.
    $dest = rscResolveContainer($db, $containerId, $tprojectId);
    $walk = intval($containerId);
    $guard = 0;
    while ($walk > 0 && $guard < 64) {
        if ($walk === $reqSpecId) {
            failOut(400, 'A specification cannot be copied into itself or into one of ' .
                         'its own child specifications', 'destination_inside_source');
        }
        $info = rscNodeInfo($db, $walk);
        if (is_null($info)) {
            break;
        }
        $walk = $info['parent_id'];
        $guard++;
    }

    $op = $specMgr->copy_to($reqSpecId, $containerId, $tprojectId, $userId);

    if (empty($op['status_ok'])) {
        failOut(409, 'The copy could not be completed',
            isset($op['msg']) && $op['msg'] !== 'ok' ? 'copy_failed' : 'copy_failed');
    }

    $newId = intval($op['id']);
    rscApplyTargetPosition($db, $containerId, $newId, $position);

    $newSpec = $specMgr->get_by_id($newId);
    $newDocId = isset($newSpec['doc_id']) ? $newSpec['doc_id'] : '';

    // Legacy message, server-side, so every server locale keeps parity:
    // lang_get('req_spec_copy_done') - "A copy of Req. Spec (DOCID:%s - %s)
    // has been done (DOCID:%s)".
    $message = sprintf(lang_get('req_spec_copy_done'),
        isset($spec['doc_id']) ? $spec['doc_id'] : '',
        isset($spec['title']) ? $spec['title'] : '',
        $newDocId);

    out(array(
        'status'        => 'ok',
        'message'       => $message,
        'new'           => array(
            'id'      => $newId,
            'doc_id'  => $newDocId,
            'title'   => isset($newSpec['title']) ? $newSpec['title'] : '',
            'parent'  => $dest['name'],
            'position'=> $position,
        ),
        'source'        => array(
            'id'      => $reqSpecId,
            'doc_id'  => isset($spec['doc_id']) ? $spec['doc_id'] : '',
            'title'   => isset($spec['title']) ? $spec['title'] : '',
        ),
        'tproject_id'   => intval($tprojectId),
    ));
}

/* ---- GET ?action=init ---------------------------------------------------- */

if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    failOut(405, 'This action accepts GET only', 'method_not_allowed');
}

$reqSpecId = rscPositiveId(rscParam('req_spec_id', null), 'invalid_req_spec_id');

$tprojectRaw = rscParam('tproject_id', null);
$asserted = 0;
if ($tprojectRaw !== null && $tprojectRaw !== '' && !is_array($tprojectRaw)) {
    if (!preg_match('/^[0-9]+$/', trim((string)$tprojectRaw))) {
        failOut(400, 'Invalid tproject_id', 'invalid_tproject_id');
    }
    $asserted = intval($tprojectRaw);
}

list($specMgr, $spec, $ownerProject) = rscResolveSource($db, $user, $reqSpecId, $asserted);

$tprojectId = ($asserted > 0) ? $asserted : $ownerProject;
rscRequireCopyRights($db, $user, $tprojectId);

$destinations = rscDestinationList($db, $tprojectId, $reqSpecId);

$tprojMgr = new testproject($db);
$tpInfo = $tprojMgr->get_by_id($tprojectId);
$prefix = '';
// Refs #1797. `testprojects` in 2.0.1 carries NO `name` column - the project
// name lives in nodes_hierarchy.name, so it is read from there.
$tpNode = rscNodeInfo($db, $tprojectId);
$tpName = (is_array($tpNode) && $tpNode['node_type_id'] === RSC_NODE_TESTPROJECT)
              ? $tpNode['name'] : '';
if (is_array($tpInfo) && isset($tpInfo['prefix'])) {
    $prefix = $tpInfo['prefix'];
}

$authorName = '';
if (!empty($spec['author_id'])) {
    $au = tlUser::getByID($db, intval($spec['author_id']));
    if (!is_null($au)) {
        $authorName = trim($au->getFullName() . ' (' . $au->login . ')');
    }
}

$reqCount = 0;
$cr = $db->fetchFirstRow(
    'SELECT COUNT(*) AS c FROM requirements WHERE srs_id = ' . $reqSpecId);
if (is_array($cr)) {
    $reqCount = intval($cr['c']);
}
if (isset($spec['total_req']) && intval($spec['total_req']) > 0) {
    $reqCount = intval($spec['total_req']);
}

out(array(
    'status'      => 'ok',
    'source'      => array(
        'id'                => $reqSpecId,
        'doc_id'            => isset($spec['doc_id']) ? $spec['doc_id'] : '',
        'title'             => isset($spec['title']) ? $spec['title'] : '',
        'scope'             => isset($spec['scope']) ? $spec['scope'] : '',
        'type'              => isset($spec['type']) ? $spec['type'] : '',
        'total_req'         => $reqCount,
        'author'            => $authorName,
        'tproject_id'       => intval($ownerProject),
        'tproject_name'     => ($ownerProject === $tprojectId) ? $tpName : '',
    ),
    'destination' => array(
        'tproject_id'   => intval($tprojectId),
        'tproject_name' => $tpName,
        'prefix'        => $prefix,
    ),
    'default_project_id' => intval($tprojectId),
    'destinations'  => $destinations,
    'rights'        => array(
        'view'   => rscHasRight($db, $user, 'mgt_view_req', $tprojectId),
        'modify' => rscHasRight($db, $user, 'mgt_modify_req', $tprojectId),
        'can_copy' => true,
    ),
    'default_position' => 'top',
));