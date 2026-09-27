<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * @filesource  listTestCases.php
 * @author     Martin Havlat
 *
 * Generates tree menu with test specification.
 *
 * @internal revisions
 * @since 1.9.10
 *
 * @internal Refs #1660 - retired legacy frame.
 *   In 1.9.20 this was the LEFT frame of the Test Specification work area:
 *   an ExtJS tree (gui/templates/dashio/testcases/tcTree.tpl) built by
 *   tlTestCaseFilterControl('edit_mode'), with drag-and-drop re-parenting and
 *   re-ordering of test-project nodes and the reorder-test-cases toolbar.
 *   gui/templates/testcases/testSpec.html now renders its own Dashio tree pane
 *   and its own reorder popup, so this frame has no modern twin and no live
 *   caller left (it is no longer referenced from frmWorkArea.php nor from any
 *   $actions entry in lib/functions/common.php).
 *
 *   The reorder capability this frame carried is preserved in the modern
 *   Reorder Test Cases popup (gui/templates/testcases/tcReorder.html, BFF
 *   api/tcreorder), and the legacy drag-and-drop backend it called
 *   (lib/ajax/dragdroptprojectnodes.php - which had no rights check at all)
 *   is now a shim onto the same screen. This controller follows them there, so
 *   an old deep link such as
 *     lib/testcases/listTestCases.php?feature=edit_tc&testproject_id=7
 *   still reaches the modern Test Specification instead of rendering a
 *   dead ExtJS frame.
 */

require_once('../../config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

// Legacy testlinkInitPage() contract: an anonymous visitor is bounced to the
// login screen with a note=expired bounce and the original destination.
// checkSessionValid()'s own redirect is used (rather than a hand-rolled
// header()) because it walks up from dirname(SCRIPT_FILENAME) until it finds
// login.php - a relative 'login.php' would resolve against /lib/testcases/
// and 404.
if (!checkSessionValid($db)) {
    exit;  // unreachable: the call above already redirected
}

// The legacy feature was carried in the query string of the old work area.
$feature = isset($_REQUEST['feature']) ? (string)$_REQUEST['feature'] : 'edit_tc';

// features the legacy tree dispatched to. edit_tc is the test-specification
// tree itself; keywordsAssign and assignReqs are the other two tree modes,
// all of which are modern screens reachable from the modern Test
// Specification toolbar.
$targets = array(
    'edit_tc' => '/gui/templates/testcases/testSpec.html',
    'keywordsAssign' => '/gui/templates/keywords/keywordsAssign.html',
    'assignReqs' => '/gui/templates/requirements/reqTcBulkAssign.html',
);

if (!isset($targets[$feature])) {
    tLog('listTestCases shim: unknown feature "' . $feature . '" - refusing to guess ' .
         'a modern target (Refs #1660).', 'ERROR');
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Unknown legacy feature: ' . htmlspecialchars($feature, ENT_QUOTES, 'UTF-8');
    exit;
}

$qs = array();
foreach (array('tproject_id', 'tplan_id', 'tcase_id', 'container_id', 'idSRS') as $k) {
    if (isset($_REQUEST[$k]) && intval($_REQUEST[$k]) > 0) {
        $qs[$k] = intval($_REQUEST[$k]);
    }
}
// legacy tree arg spelling was testproject_id
if (isset($_REQUEST['testproject_id']) && !isset($qs['tproject_id']) &&
    intval($_REQUEST['testproject_id']) > 0) {
    $qs['tproject_id'] = intval($_REQUEST['testproject_id']);
}

$url = $targets[$feature];
if (!empty($qs)) {
    $url .= '?' . http_build_query($qs);
}

header('Location: ' . $url);
exit;
