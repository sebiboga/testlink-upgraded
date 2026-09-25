<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 *
 * @filesource  eventviewer.php
 *
 * 2.0.1.shim - Refs #1579: the legacy standalone Event Viewer page was
 * replaced by the modern Dashio screen gui/templates/eventviewer/eventviewer.html
 * (Refs #872) backed by the api/eventviewer BFF. The legacy controller was the
 * LAST controller in lib/** still rendering a full standalone page; this file
 * is kept as a session-guarded redirect shim so old deep links keep working:
 * anonymous users are sent to the login screen (legacy testlinkInitPage
 * contract) and authenticated users land on the modern viewer with their
 * object_id / object_type / tproject_id / tplan_id context forwarded. The
 * rights gate (mgt_view_events / events_mgt) lives in the BFF exactly like the
 * legacy eventviewer.legacy.php checks.
**/
require_once("../../config.inc.php");
require_once('../functions/common.php');

// Anonymous -> login (same contract as the legacy testlinkInitPage call).
testlinkInitPage($db, FALSE, false, null, true);

$tprojectID = isset($_SESSION['testprojectID']) ? intval($_SESSION['testprojectID']) : 0;
$tplanID = isset($_SESSION['testplanID']) ? intval($_SESSION['testplanID']) : 0;

$objectId = intval($_REQUEST['object_id'] ?? 0);
$objectType = preg_replace('/[^a-zA-Z0-9_]/', '', strval($_REQUEST['object_type'] ?? ''));

$url = $_SESSION['basehref'] . 'gui/templates/eventviewer/eventviewer.html';
$url .= '?tproject_id=' . $tprojectID . '&tplan_id=' . $tplanID;
if ($objectId > 0) {
    $url .= '&object_id=' . $objectId;
}
if ($objectType !== '') {
    $url .= '&object_type=' . rawurlencode($objectType);
}
header('Location: ' . $url);
exit;