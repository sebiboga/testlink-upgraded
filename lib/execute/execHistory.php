<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * @filesource  execHistory.php
 *
 * 2010.1.shim - Refs #1806: the legacy Smarty Execution History popup was
 * replaced by the modern Dashio screen gui/templates/execute/execHistory.html +
 * the api/execute BFF (action=history). This controller is kept as a session-
 * guarded redirect shim so old deep links and legacy JavaScript callers resolve:
 * anonymous users are sent to the login screen (legacy testlinkInitPage behaviour)
 * and authenticated users land on the modern popup with the required query params.
**/
require_once('../../config.inc.php');
require_once('common.php');

testlinkInitPage($db, FALSE, false, null, true);

$tcaseId = intval($_REQUEST['tcase_id'] ?? 0);
$tprojectId = intval($_REQUEST['tproject_id'] ?? ($_SESSION['testprojectID'] ?? 0));
$onlyActive = isset($_REQUEST['onlyActiveTestPlans']) ? intval($_REQUEST['onlyActiveTestPlans']) : 0;
if ($onlyActive !== 1) {
    $onlyActive = 0;
}

$url = $_SESSION['basehref'] . 'gui/templates/execute/execHistory.html';
$url .= '?tcase_id=' . $tcaseId;
if ($tprojectId > 0) {
    $url .= '&tproject_id=' . $tprojectId;
}
if ($onlyActive === 1) {
    $url .= '&onlyActiveTestPlans=1';
}
header('Location: ' . $url);
exit;
