<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * @filesource dragdroptreenodes.php
 *
 * @internal Refs #1740 - legacy generic tree drag-and-drop endpoint. It was
 *   the server side of a SuiteTree/TableDnD widget of the 1.9.20 test-spec
 *   frame and accepted
 *     ?doAction=changeParent&nodeid=<id>&newparentid=<id>
 *     ?doAction=doReorder&nodelist=<id>,<id>,...
 *
 *   It is retired here because it was never reviewed AND was unreachable:
 *
 *   1. ORPHANED dead code - no template, no screen and no JS in the whole tree
 *      referenced this file (the sibling endpoints dragdroptprojectnodes.php /
 *      dragdroprequirementnodes.php had the real callers), so its behaviour was
 *      never exercised by the shipped UI.
 *   2. It performed NO authorization at all - only testlinkInitPage(), i.e. a
 *      session check. Any authenticated user, including one without any
 *      test-case management right, could re-parent ANY nodes_hierarchy row of
 *      ANY test project - or of a requirement tree, or of a test plan.
 *   3. It read $_REQUEST, so a plain GET mutated state: the endpoint was
 *      CSRF-able.
 *   4. The submitted node ids were never proven to belong to anything, and
 *      change_parent() happily re-parented a node under one of its own
 *      descendants, detaching that whole subtree from the project root.
 *
 *   The equivalent authorized surface is the modern Move / Reorder Test Suites
 *   screen (gui/templates/testcases/suiteMove.html) backed by api/suitemove,
 *   which checks mgt_modify_tc on the OWNING project, requires POST behind
 *   bffSameOriginGuard(), proves every submitted id to be a test suite
 *   (node_type_id = 2) of that project, proves the destination is a suite of
 *   the same project and refuses a move that would create a cycle.
 *
 *   Legacy deep links (GET) are answered with a 302 to the modern screen. The
 *   mutation is deliberately NOT replayed: it was never authorized, and a 302
 *   that silently re-parented a tree would be worse than refusing it. A write
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
    tLog('BFF shim: refused ' . $method . ' on the retired legacy tree drag-drop ' .
         'endpoint - use POST /api/suitemove/index.php?action=move|reorder instead (Refs #1740).',
         'WARNING');
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode(array(
        'status' => 'error',
        'code' => 'method_not_allowed',
        'message' => 'The legacy tree drag-drop endpoint was retired; ' .
                     'use POST /api/suitemove/index.php?action=move|reorder',
    ));
    exit;
}

$q = $_GET;

$target = '/gui/templates/testcases/suiteMove.html';

$params = array();
foreach (array('tproject_id', 'container_id') as $k) {
    if (isset($q[$k]) && intval($q[$k]) > 0) {
        $params[$k] = intval($q[$k]);
    }
}

if (!empty($params)) {
    $target .= '?' . http_build_query($params);
}

header('Location: ' . $target);
exit;
