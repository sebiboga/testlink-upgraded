<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * @filesource  usersView.php
 *
 * 2.0.1.shim - Refs #1878: the top Admin menu entry (cfg/const.inc.php
 * guiTopMenu[6], label title_admin, right mgt_users) still pointed at this
 * legacy controller while the modern twin gui/templates/usermanagement/
 * usersView.html + api/users was already live for the ASIDE Users entry
 * ($actions->userMgmt). This file is kept as a session-guarded redirect
 * shim so old deep links and bookmarks keep working:
 * anonymous users are sent to the login screen (legacy testlinkInitPage
 * contract) and authenticated users land on the modern screen with their
 * tproject_id / tplan_id session context forwarded.
 *
 * NON-MUTATING: the legacy GET operation=disable write is NOT executed any
 * more (disable lives in the modern screen's api/users BFF behind a
 * same-origin + rights proof); the operation is only forwarded so the
 * modern screen keeps the caller's context.
**/
require_once("../../config.inc.php");
require_once('../functions/common.php');

// Anonymous -> login (same contract as the legacy testlinkInitPage call).
testlinkInitPage($db, FALSE, false, null, true);

$tprojectID = isset($_SESSION['testprojectID']) ? intval($_SESSION['testprojectID']) : 0;
$tplanID = isset($_SESSION['testplanID']) ? intval($_SESSION['testplanID']) : 0;

$url = $_SESSION['basehref'] . 'gui/templates/usermanagement/usersView.html';
$url .= '?tproject_id=' . $tprojectID . '&tplan_id=' . $tplanID;

// Forward legacy deep-link context (values are CR/LF-stripped and re-encoded).
foreach (array('operation', 'user', 'user_id', 'feedback', 'login') as $key) {
    if (isset($_REQUEST[$key]) && is_scalar($_REQUEST[$key])) {
        $value = preg_replace('/[\r\n]/', '', strval($_REQUEST[$key]));
        if ($value !== '') {
            $url .= '&' . $key . '=' . rawurlencode($value);
        }
    }
}

header('Location: ' . $url);
exit;
