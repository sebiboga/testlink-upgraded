<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * @filesource	eventinfo.php
 *
 * 2010.1.shim - Refs #1556: the legacy Smarty Event Info popup was replaced by
 * the modern Dashio screen gui/templates/eventviewer/eventinfo.html + the
 * api/eventinfo BFF. This controller is kept as a session-guarded redirect
 * shim so old deep links (and the legacy eventviewer.tpl ExtJS autoLoad panel)
 * still resolve: anonymous users are sent to the login screen (legacy
 * testlinkInitPage behaviour) and authenticated users land on the modern
 * popup with the event id forwarded.
**/
require_once("../../config.inc.php");
require_once("common.php");

// Anonymous -> login (same contract as the legacy testlinkInitPage call).
testlinkInitPage($db, TRUE);

// Legacy input contract: ?id=<event id> (also accepted as a POST field by the
// old ExtJS panel autoLoad).
$id = isset($_REQUEST['id']) ? intval($_REQUEST['id']) : 0;

$tprojectID = isset($_SESSION['testprojectID']) ? intval($_SESSION['testprojectID']) : 0;
$tplanID = isset($_SESSION['testplanID']) ? intval($_SESSION['testplanID']) : 0;

$url = $_SESSION['basehref'] . 'gui/templates/eventviewer/eventinfo.html';
$url .= '?id=' . $id . '&tproject_id=' . $tprojectID . '&tplan_id=' . $tplanID;
header('Location: ' . $url);
exit;
