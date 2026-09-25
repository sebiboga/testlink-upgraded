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
 * The whole controller is kept as a session-guarded redirect shim so old deep
 * links and bookmarks still resolve:
 *   - anonymous users are sent to the login screen (legacy testlinkInitPage
 *     behaviour);
 *   - authenticated users land on the modern popup with the test suite id
 *     (legacy ?id= / ?tsuite_id= / POST id) and the test project context
 *     forwarded.
 * Every write path of the legacy controller (doAction=bulkassign /
 * switchspec / assign / unassign) is now served by the BFF, which enforces
 * the same legacy right (req_tcase_link_management) and additionally proves
 * the suite belongs to the test project.
**/
require_once("../../config.inc.php");
require_once("common.php");

// Anonymous -> login (same contract as the legacy testlinkInitPage call).
testlinkInitPage($db, TRUE);

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

$url = $_SESSION['basehref'] . 'gui/templates/requirements/reqTcBulkAssign.html';
$url .= '?tsuite_id=' . $id . '&tproject_id=' . $tprojectID . '&tplan_id=' . $tplanID;
if (isset($_REQUEST['idSRS']) && is_scalar($_REQUEST['idSRS'])) {
    $url .= '&idSRS=' . intval($_REQUEST['idSRS']);
}
header('Location: ' . $url);
exit;
