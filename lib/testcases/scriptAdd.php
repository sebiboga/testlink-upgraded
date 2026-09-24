<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * @filesource  scriptAdd.php
 *
 * 2010.1.shim - Refs #1574: the legacy Smarty Script Add popup was replaced
 * by the modern Dashio screen gui/templates/testcases/scriptEdit.html + the
 * api/scriptedit BFF. This controller is kept as a session-guarded redirect
 * shim so old deep links (and legacy open_script_add_window() callers still
 * running on un-modernized parents) resolve: anonymous users are sent to the
 * login screen (legacy testlinkInitPage behaviour) and authenticated users
 * land on the modern popup with the tcversion/project/plan ids forwarded.
 * The rights gate lives in the BFF (mgt_modify_tc).
**/
require_once("../../config.inc.php");
require_once('../functions/common.php');

// Anonymous -> login (same contract as the legacy testlinkInitPage call).
testlinkInitPage($db, FALSE, false, null, true);

$tprojectID = isset($_SESSION['testprojectID']) ? intval($_SESSION['testprojectID']) : 0;
$tplanID = isset($_SESSION['testplanID']) ? intval($_SESSION['testplanID']) : 0;

$url = $_SESSION['basehref'] . 'gui/templates/testcases/scriptEdit.html';
$url .= '?user_action=' . rawurlencode((string) ($_REQUEST['user_action'] ?? 'link'));
$url .= '&tcversion_id=' . intval($_REQUEST['tcversion_id'] ?? 0);
if (isset($_REQUEST['tproject_id']) && $_REQUEST['tproject_id'] != '') {
    $url .= '&tproject_id=' . intval($_REQUEST['tproject_id']);
} else {
    $url .= '&tproject_id=' . $tprojectID;
}
if (isset($_REQUEST['tplan_id']) && $_REQUEST['tplan_id'] != '') {
    $url .= '&tplan_id=' . intval($_REQUEST['tplan_id']);
} else {
    $url .= '&tplan_id=' . $tplanID;
}
header('Location: ' . $url);
exit;