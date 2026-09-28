<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * @filesource reqTcAssign.php
 *
 * 2.0.1.shim - Refs #1595: the legacy Smarty "Requirements Bulk Assignment"
 * screen (this controller in testsuite/bulk mode + the dashio
 * reqTcBulkAssignment.tpl grid) and the single-test-case "assign requirements"
 * mode were replaced by the modern Dashio popup
 * gui/templates/requirements/reqTcBulkAssign.html + the api/reqtcbassign BFF.
 *
 * 2.0.1.shim - Refs #1702: BOTH modes are now covered, and the shim used to
 * mis-dispatch the testcase mode. It only read ?id= and always redirected to
 * reqTcBulkAssign.html?tsuite_id=<id>, ignoring edit=testcase - so the live
 * entry point openReqWindow(tcase_id) in gui/javascript/testlink_library.js,
 * which builds ?edit=testcase&showCloseButton=1&callback=<cb>&id=<tcase_id>,
 * resolved to the BULK popup with a TEST CASE id in the tsuite_id slot: the
 * wrong screen, against a suite that does not exist. The testcase mode is now
 * served by gui/templates/requirements/reqTcAssign.html + api/reqtcassign.
 *
 * The whole controller is kept as a session-guarded redirect shim so old deep
 * links and bookmarks still resolve:
 *   - anonymous users are sent to the login screen (legacy testlinkInitPage
 *     behaviour);
 *   - ?edit=testcase (or a ?id= that is a test case node) lands on the modern
 *     single-test-case popup;
 *   - everything else lands on the modern bulk popup with the test suite id
 *     (legacy ?id= / ?tsuite_id= / POST id) and the test project context
 *     forwarded.
 * Every write path of the legacy controller (doAction=bulkassign /
 * switchspec / assign / unassign) is now served by a BFF, which enforces the
 * same legacy right (req_tcase_link_management) and additionally proves the
 * addressed node belongs to the test project.
**/
require_once("../../config.inc.php");
require_once("common.php");

// Same contract as the legacy controller: no project initialisation and the
// regular session check (anonymous users are sent to the login screen).
testlinkInitPage($db, false, false);

// Legacy input contract: the testsuite/bulk mode was reached with
// ?id=<test suite node id> (also POSTed as 'id' by the legacy grid form).
$id = 0;
foreach (array('id', 'tsuite_id', 'idSRS_id') as $k) {
    if (isset($_REQUEST[$k]) && is_scalar($_REQUEST[$k])) {
        $id = intval($_REQUEST[$k]);
        if ($id > 0) {
            break;
        }
    }
}

$tprojectID = isset($_SESSION['testprojectID']) ? intval($_SESSION['testprojectID']) : 0;
$tplanID = isset($_SESSION['testplanID']) ? intval($_SESSION['testplanID']) : 0;

// Refs #1702: decide the mode from the node TYPE, not from ?edit= only.
// A legacy ?edit=testcase link passes a test case node id; a bulk link passes
// a test suite node id (sometimes with ?edit=testsuite). Resolving the node
// type also repairs old links that only carried ?id=.
$mode = 'bulk';
if ((isset($_REQUEST['edit']) && strval($_REQUEST['edit']) === 'testcase') && $id > 0) {
    $mode = 'testcase';
} elseif ($id > 0) {
    // NOTE: this is tree_manager::getNodeType() inlined on purpose.
    // lib/functions/tree.class.php is not part of the request path loaded by
    // this shim (config.inc.php / common.php never require it), so
    // `new tree_manager($db)` fatals with "Class not found" and the shim
    // answers 500. One narrow query keeps the shim dependency free.
    // getDBTables() is per table, so the two names are asked for separately.
    $tNH = tlObjectWithDB::getDBTables('nodes_hierarchy');
    $tNT = tlObjectWithDB::getDBTables('node_types');
    $rs = $db->get_recordset(
        'SELECT NT.description AS node_type ' .
        " FROM {$tNH['nodes_hierarchy']} NH " .
        " JOIN {$tNT['node_types']} NT ON NT.id = NH.node_type_id " .
        ' WHERE NH.id = ' . $id);
    $nodeType = is_null($rs) ? null : current($rs);
    if (!is_null($nodeType) && $nodeType['node_type'] === 'testcase') {
        $mode = 'testcase';
    }
}

if ($mode === 'testcase') {
    $url = $_SESSION['basehref'] . 'gui/templates/requirements/reqTcAssign.html';
    $url .= '?tcase_id=' . $id . '&tproject_id=' . $tprojectID . '&tplan_id=' . $tplanID;
    header('Location: ' . $url);
    exit;
}

$url = $_SESSION['basehref'] . 'gui/templates/requirements/reqTcBulkAssign.html';
$url .= '?tsuite_id=' . $id . '&tproject_id=' . $tprojectID . '&tplan_id=' . $tplanID;
if (isset($_REQUEST['idSRS']) && is_scalar($_REQUEST['idSRS'])) {
    $url .= '&idSRS=' . intval($_REQUEST['idSRS']);
}
header('Location: ' . $url);
exit;
