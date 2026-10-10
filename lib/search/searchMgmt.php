<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * @filesource	searchMgmt.php
 *
 * 2.0.1 shim - Refs #1785: the legacy Smarty Full-Text Search screen was
 * replaced by the modern Dashio screen gui/templates/search/searchMgmt.html +
 * the api/searchmgmt BFF. This controller is kept as a session-guarded
 * redirect shim so the legacy navBar form (which POSTs `target` here) and old
 * deep links still resolve: anonymous users are sent to the login screen
 * (legacy testlinkInitPage behaviour) and authenticated users land on the
 * modern screen with the target term and the current test project forwarded.
**/

require_once('../../config.inc.php');
require_once('common.php');

// Anonymous -> login (same contract as the legacy testlinkInitPage call).
testlinkInitPage($db, TRUE);

/**
 * Refs #1893: guard against array-shaped query params (e.g. ?target[]=x).
 * Casting an array to string triggers "Array to string conversion" (persisted
 * by watchPHPErrors as an E_WARNING row in events) and coerces to "Array".
 * Validate scalar before casting; a non-scalar target is treated as absent.
 * Same idiom as listTestCases.php (Refs #1732) / reqMgrSystemEdit.php (#1731).
 */
function shimReqScalar($name)
{
    if (!isset($_REQUEST[$name]) || !is_scalar($_REQUEST[$name])) {
        return null;
    }
    return trim((string)$_REQUEST[$name]);
}

// Legacy input contract: POST/GET `target` (the navBar one-box search term).
$target = shimReqScalar('target');
if ($target === null) {
  $target = '';
}
if (strlen($target) > 200) {
  $target = substr($target, 0, 200);
}

$tprojectID = isset($_SESSION['testprojectID']) ? intval($_SESSION['testprojectID']) : 0;
$tplanID = isset($_SESSION['testplanID']) ? intval($_SESSION['testplanID']) : 0;

$url = $_SESSION['basehref'] . 'gui/templates/search/searchMgmt.html';
$url .= '?tproject_id=' . $tprojectID . '&tplan_id=' . $tplanID;
if ($target !== '') {
  $url .= '&target=' . rawurlencode($target);
}
header('Location: ' . $url);
exit;
