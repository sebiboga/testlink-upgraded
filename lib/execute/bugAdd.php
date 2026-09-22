<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * @filesource  bugAdd.php
 *
 * 2010.1.shim - Refs #1560: the legacy Smarty Bug Add / Link popup was
 * replaced by the modern Dashio screen gui/templates/execute/bugAdd.html +
 * the api/bugadd BFF. This controller is kept as a session-guarded redirect
 * shim so old deep links (and the legacy testlink_library.js callers still
 * running on un-modernized parents) resolve: anonymous users are sent to the
 * login screen (legacy testlinkInitPage behaviour) and authenticated users
 * land on the modern popup with the exec/bug/step/plan/project ids and the
 * requested user_action forwarded. The rights gate lives in the BFF
 * (testplan_execute on the owning project).
**/
require_once("../../config.inc.php");
require_once('../functions/common.php');

// Anonymous -> login (same contract as the legacy testlinkInitPage call).
testlinkInitPage($db, FALSE, false, null, true);

$tprojectID = isset($_SESSION['testprojectID']) ? intval($_SESSION['testprojectID']) : 0;
$tplanID = isset($_SESSION['testplanID']) ? intval($_SESSION['testplanID']) : 0;

$userAction = isset($_REQUEST['user_action']) && is_scalar($_REQUEST['user_action'])
    ? strtolower(trim((string) $_REQUEST['user_action'])) : 'link';

$url = $_SESSION['basehref'] . 'gui/templates/execute/bugAdd.html';
$url .= '?user_action=' . rawurlencode($userAction);
$url .= '&exec_id=' . intval($_REQUEST['exec_id'] ?? 0);
foreach (array('tcversion_id', 'tcstep_id') as $p) {
    if (isset($_REQUEST[$p])) {
        $url .= '&' . $p . '=' . intval($_REQUEST[$p]);
    }
}
if (isset($_REQUEST['bug_id']) && is_scalar($_REQUEST['bug_id'])
    && $_REQUEST['bug_id'] != '') {
    $url .= '&bug_id=' . rawurlencode((string) $_REQUEST['bug_id']);
}
$url .= '&tproject_id=' . $tprojectID . '&tplan_id=' . $tplanID;
header('Location: ' . $url);
exit;