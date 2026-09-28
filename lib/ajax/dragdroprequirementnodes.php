<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * @filesource dragdroprequirementnodes.php
 *
 * @internal Refs #1681 - legacy drag-and-drop endpoint of the REQUIREMENT
 *   SPECIFICATION tree. In 1.9.20 the tree was the left frame of the work
 *   area and dropping a node re-parented it or rewrote the sibling order.
 *   There was no screen and no right of its own for that gesture; the
 *   backend was this file, called by the ExtJS tree
 *   (gui/templates/dashio/requirements/reqSpecTree.tpl and its tl-classic
 *   twin).
 *
 *   It is retired here for the same three reasons as the test-case tree
 *   endpoint closed in #1660 (lib/ajax/dragdroptprojectnodes.php) and the
 *   step endpoint closed in #1673 (lib/ajax/stepReorder.php):
 *
 *   1. it performed NO authorization and NO ownership check at all. It read
 *      the test project from $_SESSION only to initialize the page; the write
 *      itself accepted arbitrary node ids, so any authenticated user could
 *      re-parent requirement nodes ACROSS test projects and rewrite arbitrary
 *      nodes_hierarchy.node_order values.
 *   2. it read $_REQUEST, so a plain GET mutated state: the endpoint was
 *      CSRF-able, and it also accepted cross-site top-level form posts.
 *   3. the submitted node ids were never proven to be requirement nodes, let
 *      alone requirements of the caller's test project. The companion
 *      "UPDATE requirements SET srs_id=" ran unconditionally, even when
 *      change_parent() had silently matched nothing.
 *
 *   Two legacy defects are fixed in the replacement rather than reproduced:
 *   the legacy init_args() read a "top_or_bottom" argument that the switch
 *   never used, and change_parent() never touched node_order - so a moved
 *   requirement kept the node_order it had in its old specification and
 *   landed in an arbitrary slot of the new one.
 *
 *   The equivalent authorized surface is the modern Requirement
 *   Specification Tree screen (gui/templates/requirements/reqTreeReorder.html)
 *   backed by api/reqtreereorder, which checks mgt_modify_req on the OWNING
 *   test project, requires POST behind bffSameOriginGuard(), proves every
 *   submitted id to be a requirement / specification of the addressed project
 *   and honours the position.
 *
 *   Legacy deep links (GET) are answered with a 302 to the modern screen. The
 *   mutation is deliberately NOT replayed: it was never authorized, and a 302
 *   that silently re-parented a node would be worse than refusing it. A write
 *   verb is refused with 405 and a pointer to the modern endpoint.
 */

require_once('../../config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

// Legacy testlinkInitPage() contract: an anonymous visitor is bounced to the
// login screen. checkSessionValid()'s own redirect is used (rather than a
// hand-rolled header()) because it walks up from dirname(SCRIPT_FILENAME)
// until it finds login.php - a relative 'login.php' would resolve against
// /lib/ajax/ and 404.
if (!checkSessionValid($db)) {
    exit;  // unreachable: the call above already redirected
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method !== 'GET' && $method !== 'HEAD') {
    tLog('BFF shim: refused ' . $method . ' on the retired legacy ' .
         'requirement-tree drag-and-drop endpoint - use POST ' .
         '/api/reqtreereorder/index.php?action=move|reorder instead ' .
         '(Refs #1681).', 'WARNING');
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode(array(
        'status' => 'error',
        'code' => 'method_not_allowed',
        'message' => 'The legacy requirement-tree drag-and-drop endpoint was ' .
                     'retired; use POST ' .
                     '/api/reqtreereorder/index.php?action=move|reorder',
    ));
    exit;
}

$q = $_GET;

// The legacy tree passed nodeid/newparentid (the dragged node and its drop
// target) and the session supplied the project. A deep link can therefore
// only carry the node to preselect: the modern screen then lets the user pick
// the target specification and the position, which is the part that was never
// authorized before.
$target = '/gui/templates/requirements/reqTreeReorder.html';

$params = array();
if (isset($q['tproject_id']) && intval($q['tproject_id']) > 0) {
    $params['tproject_id'] = intval($q['tproject_id']);
} elseif (isset($_SESSION['testprojectID']) && intval($_SESSION['testprojectID']) > 0) {
    $params['tproject_id'] = intval($_SESSION['testprojectID']);
}
if (isset($q['node_id']) && intval($q['node_id']) > 0) {
    $params['node_id'] = intval($q['node_id']);
} elseif (isset($q['nodeid']) && intval($q['nodeid']) > 0) {
    // legacy spelling used by the ExtJS tree
    $params['node_id'] = intval($q['nodeid']);
}
if (isset($q['req_spec_id']) && intval($q['req_spec_id']) > 0) {
    $params['req_spec_id'] = intval($q['req_spec_id']);
} elseif (isset($q['newparentid']) && intval($q['newparentid']) > 0) {
    $params['req_spec_id'] = intval($q['newparentid']);
}

if (!empty($params)) {
    $target .= '?' . http_build_query($params);
}

header('Location: ' . $target);
exit;
