<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * @filesource  bugDelete.php
 *
 * 2010.1.shim - Refs #1559: the legacy Smarty Bug Delete popup was replaced
 * by the modern Dashio screen gui/templates/execute/bugDelete.html + the
 * api/bugdelete BFF. This controller is kept as a session-guarded redirect
 * shim so old deep links (and the legacy testlink_library.js deleteBug()
 * callers still running on un-modernized parents) resolve: anonymous users
 * are sent to the login screen (legacy testlinkInitPage behaviour) and
 * authenticated users land on the modern popup with the exec/bug/step ids
 * forwarded. The rights gate lives in the BFF (testplan_execute).
**/
require_once("../../config.inc.php");
require_once('../functions/common.php');

// Anonymous -> login (same contract as the legacy testlinkInitPage call).
testlinkInitPage($db, FALSE, false, null, true);

// Legacy input contract: ?exec_id=<id>[&tcstep_id=<id>][&bug_id=<id>]
$tprojectID = isset($_SESSION['testprojectID']) ? intval($_SESSION['testprojectID']) : 0;
$tplanID = isset($_SESSION['testplanID']) ? intval($_SESSION['testplanID']) : 0;

$url = $_SESSION['basehref'] . 'gui/templates/execute/bugDelete.html';
$url .= '?exec_id=' . intval($_REQUEST['exec_id'] ?? 0);
if (isset($_REQUEST['tcstep_id'])) {
    $url .= '&tcstep_id=' . intval($_REQUEST['tcstep_id']);
}
if (isset($_REQUEST['bug_id']) && $_REQUEST['bug_id'] != '') {
    $url .= '&bug_id=' . rawurlencode($_REQUEST['bug_id']);
}
$url .= '&tproject_id=' . $tprojectID . '&tplan_id=' . $tplanID;
header('Location: ' . $url);
exit;