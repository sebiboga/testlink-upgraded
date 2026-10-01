<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * @filesource gettprojectnodes.php
 *
 * @internal Refs #1770 - legacy TEST CASE tree lazy loader. It was the server
 *   side of the ExtJS tree built by
 *   lib/functions/tlTestCaseFilterControl.class.php (the LEFT FRAME of the 1.9.20
 *   work areas "add/remove test cases" planAddTC_m1.tpl, "update test plan TC
 *   assignments" planUpdateTC.tpl, "test urgency" planUrgency.tpl and
 *   "execution assignment" tc_exec_assignment.tpl) and answered the children of
 *   ONE expanded node:
 *     ?root_node=<tproject_id>[&node=<parent id>][&filter_node=<id>]
 *     [&show_tcases=<0|1>][&tcprefix=<P>][&operation=manage|print]
 *   Each row was an ExtJS tree node (text / id / leaf / cls / position /
 *   testlink_node_type / testlink_node_name / forbidden_parent /
 *   href=javascript:EP|ETS|ET) built from
 *   `SELECT ... FROM nodes_hierarchy WHERE parent_id = <parent>`, with a
 *   recursive test case count appended to every folder label ("Name (n)") and
 *   the latest version's tc_external_id prefixed to every test case label when
 *   cfg treemenu_show_testcase_id is on.
 *
 *   It is retired here because it was never authorized:
 *
 *   1. It performed NO rights check at all - only testlinkInitPage(), i.e. a
 *      SESSION check. Any authenticated user, including one with no test case
 *      right, could read the suite names, test case names and tc_external_ids of
 *      ANY test project by sending an arbitrary root_node / node / filter_node
 *      id. Same class of bug as #1696 (getrequirementnodes.php) and #1765
 *      (getreqcoveragenodes.php).
 *   2. It had no project scope and no node-type gate: display_children() filtered
 *      on `parent_id` only, so the walk escaped the test specification entirely.
 *   3. Its SQL was driven by ids read out of $_REQUEST, and the deep label text
 *      was handed to ExtJS as HTML (the htmlspecialchars() call was the only
 *      thing between a suite name and the caller DOM).
 *   4. getAllTCasesID() recursed once per suite level with an unbounded
 *      `parent_id IN (...)` list built by string concatenation.
 *
 *   The equivalent authorized surface is the modern Test Case Tree navigator
 *   (gui/templates/testcases/tcProjectTree.html) backed by api/tcprojecttree,
 *   which checks mgt_view_tc / mgt_modify_tc on the ADDRESSED project BEFORE
 *   resolving it, proves every node id to live under that project root, keeps the
 *   legacy node-type exclusions and the show_tcases / filter_node gestures, and
 *   returns the names as DATA (the modern screen escapes them itself). It is
 *   READ-ONLY on purpose: the reorder gesture lives in
 *   gui/templates/testcases/tcReorder.html (api/tcreorder, Refs #1660) and the
 *   move/copy gesture in gui/templates/testcases/containerMoveTC.html
 *   (api/tcmovecopy, Refs #1724) - the legacy drag-and-drop source
 *   lib/ajax/dragdroptprojectnodes.php is a non-mutating shim.
 *
 *   Legacy deep links (GET) are answered with a 302 to the modern screen; the
 *   read is deliberately NOT replayed, because it was never authorized. A write
 *   verb is refused with 405 and a pointer to the modern endpoints.
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
    tLog('BFF shim: refused ' . $method . ' on the retired legacy test-case tree loader - '
        . 'read it from GET /api/tcprojecttree/index.php?action=init|children|projects instead (Refs #1770).',
        'WARNING');
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode(array(
        'status' => 'error',
        'code' => 'method_not_allowed',
        'message' => 'The legacy test case tree loader was retired; '
            . 'use GET /api/tcprojecttree/index.php?action=init|children|projects',
    ));
    exit;
}

$q = $_GET;

$target = '/gui/templates/testcases/tcProjectTree.html';

$params = array();
// root_node was the test project id of the tree; node was the expanded node,
// which carries no meaning for the modern screen (it expands client side).
foreach (array('tproject_id', 'root_node') as $k) {
    if (isset($q[$k]) && intval($q[$k]) > 0) {
        $params['tproject_id'] = intval($q[$k]);
        break;
    }
}
// filter_node and show_tcases are real gestures on the modern screen too
// (legacy filter_node narrowed the ROOT children to one node).
if (isset($q['filter_node']) && intval($q['filter_node']) > 0) {
    $params['filter_node'] = intval($q['filter_node']);
}
if (isset($q['show_tcases']) && $q['show_tcases'] !== '' && intval($q['show_tcases']) >= 0) {
    $params['show_tcases'] = intval($q['show_tcases']) ? 1 : 0;
}

if (!empty($params)) {
    $target .= '?' . http_build_query($params);
}

header('Location: ' . $target);
exit;
