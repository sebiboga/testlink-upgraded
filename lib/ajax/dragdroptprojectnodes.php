<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * @filesource  dragdroptprojectnodes.php
 *
 * @internal Refs #1660 - legacy drag-and-drop endpoint of the ExtJS
 *   test-specification tree frame (lib/testcases/listTestCases.php +
 *   gui/templates/dashio/testcases/tcTree.tpl). Its ExtJS caller
 *   (gui/javascript/execTree.js writeNodePositionToDB() /
 *   treebyloader.js) is dead now that testSpec.html renders its own tree
 *   pane, so the controller has no live front-end.
 *
 *   It is retired here for a second reason: it performed NO authorization at
 *   all. doAction=changeParent called tree::change_parent() and
 *   doAction=doReorder called tree::change_order_bulk() on an arbitrary
 *   comma-separated list of node ids, without any rights check and without
 *   any test-project ownership check. Any authenticated user - including one
 *   holding no test-case management right whatsoever - could therefore
 *   re-parent nodes across test projects (and touch requirement and test-plan
 *   nodes) or rewrite arbitrary node_order values.
 *
 *   The equivalent authorized surface is the modern Reorder Test Cases popup
 *   (gui/templates/testcases/tcReorder.html) backed by api/tcreorder, which
 *   checks mgt_modify_tc on the OWNING project and proves every submitted
 *   node id to be a test case of the addressed container before writing.
 *
 *   Legacy deep links (GET) are answered with a 302 to the modern screen, so
 *   bookmarks and the old ExtJS callers land somewhere meaningful. The two
 *   write actions are deliberately NOT replayed: they were never authorized,
 *   and a 302 that silently performed the mutation would be worse than
 *   refusing it. A write verb is refused with 405 and a pointer to the
 *   modern endpoint.
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
    tLog('BFF shim: refused ' . $method . ' on the retired legacy drag-and-drop ' .
         'endpoint - use POST /api/tcreorder/index.php?action=move|sort|reorder ' .
         'instead (Refs #1660).', 'WARNING');
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode(array(
        'status' => 'error',
        'code' => 'method_not_allowed',
        'message' => 'The legacy drag-and-drop endpoint was retired; ' .
                     'use POST /api/tcreorder/index.php?action=move|sort|reorder',
    ));
    exit;
}

$q = $_GET;
$target = '/gui/templates/testcases/tcReorder.html'
        . (isset($q['tproject_id']) && intval($q['tproject_id']) > 0
           ? '?tproject_id=' . intval($q['tproject_id']) : '');

header('Location: ' . $target);
exit;
