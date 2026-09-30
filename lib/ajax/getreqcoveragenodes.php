<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * @filesource getreqcoveragenodes.php
 *
 * @internal Refs #1765 - legacy REQUIREMENT COVERAGE tree lazy loader. It was
 *   the server side of the ExtJS tree built by
 *   lib/functions/tlTestCaseFilterByRequirementControl.class.php (the
 *   drag-and-drop source of "create suite / create test case", "assign
 *   keywords to test cases" and "assign requirements to test cases") and
 *   answered the children of ONE expanded node:
 *     ?root_node=<tproject_id>[&node=<parent id>][&filter_node=<id>]
 *     [&show_children=<0|1>][&operation=manage|print]
 *   Each row was an ExtJS tree node (text / id / leaf / cls /
 *   testlink_node_type / forbidden_parent / position / href=javascript:EP|ERS|ER)
 *   built from `SELECT ... FROM nodes_hierarchy WHERE parent_id = <parent>`.
 *
 *   It is retired here because it was never authorized AND was unreachable:
 *
 *   1. ORPHANED dead code - tlTestCaseFilterByRequirementControl has ZERO users
 *      in the tree (no template, no screen and no JS instantiates it), so no
 *      shipped UI ever displayed this tree and its behaviour was never
 *      exercised.
 *   2. It performed NO rights check at all - only testlinkInitPage(), i.e. a
 *      SESSION check. Any authenticated user, including one with no requirement
 *      right, could read the specification doc_ids/titles and the requirement
 *      doc_ids/titles of ANY test project by sending an arbitrary root_node or
 *      node id (same class of bug as #1696 on the sibling
 *      getrequirementnodes.php).
 *   3. It had no project scope and no node-type gate: display_children() joined
 *      on `parent_id` only, so the walk escaped the requirement tree entirely.
 *   4. The tree it fed advertised drag-and-drop writes onto
 *      lib/ajax/dragdroprequirementnodes.php, which mutated nodes_hierarchy on
 *      a plain GET with no rights / ownership / same-origin check (bug #1681,
 *      hardened by #1699).
 *
 *   The equivalent authorized surface is the modern Requirement Coverage Tree
 *   navigator (gui/templates/requirements/reqCoverageTree.html) backed by
 *   api/reqcoveragetree, which checks mgt_view_req / mgt_modify_req on the
 *   ADDRESSED project, proves every node id to belong to that project, gates
 *   the node types and reports requirement -> test case coverage. It is
 *   READ-ONLY on purpose: the assignment gesture lives in
 *   gui/templates/requirements/reqTcBulkAssign.html (bulk) and
 *   reqTcAssign.html (single test case), and the move/reorder gesture in
 *   reqTreeReorder.html.
 *
 *   Legacy deep links (GET) are answered with a 302 to the modern screen; the
 *   read is deliberately NOT replayed, because it was never authorized. A
 *   write verb is refused with 405 and a pointer to the modern endpoints.
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
    tLog('BFF shim: refused ' . $method . ' on the retired legacy coverage-tree loader - '
        . 'read it from GET /api/reqcoveragetree/index.php?action=init|children|coverage instead (Refs #1765).',
        'WARNING');
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode(array(
        'status' => 'error',
        'code' => 'method_not_allowed',
        'message' => 'The legacy coverage tree loader was retired; '
            . 'use GET /api/reqcoveragetree/index.php?action=init|children|coverage',
    ));
    exit;
}

$q = $_GET;

$target = '/gui/templates/requirements/reqCoverageTree.html';

$params = array();
// root_node was the test project id of the tree; node was the expanded node,
// which carries no meaning for the modern screen (it expands client side).
foreach (array('tproject_id', 'root_node') as $k) {
    if (isset($q[$k]) && intval($q[$k]) > 0) {
        $params['tproject_id'] = intval($q[$k]);
        break;
    }
}

if (!empty($params)) {
    $target .= '?' . http_build_query($params);
}

header('Location: ' . $target);
exit;
