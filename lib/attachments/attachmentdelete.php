<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * @filesource  attachmentdelete.php
 *
 * 2.0.1.shim - Refs #1638: the legacy Smarty Attachment Delete popup
 * (gui/templates/dashio/attachments/attachmentdelete.tpl) was replaced by the
 * modern Dashio popup gui/templates/attachments/attachmentDelete.html + the
 * api/attachmentsdelete BFF. This controller is kept as a session-guarded
 * redirect shim so old deep links resolve: anonymous users are sent to the
 * login screen (legacy testlinkInitPage behaviour) and authenticated users
 * land on the modern popup with the attachment id (and the owning object, when
 * the legacy caller supplied it) forwarded.
 *
 * Note the legacy page deleted the attachment as a side effect of a plain GET,
 * so simply opening this URL removed a file. The shim never mutates anything:
 * deletion now requires an explicit POST to the BFF behind
 * bffSameOriginGuard(), and the popup makes the user confirm it.
 *
 * The rights gate (config_get('attachments')->enabled, legacy checkRights())
 * plus the attachment ownership proof live in the BFF.
**/
require_once('../../config.inc.php');
require_once('../functions/common.php');

// Anonymous -> login: the bounce comes from checkSessionValid() inside
// testlinkInitPage(). The 5th argument ($onFailureGoToLogin) is a no-op here - it
// is only consulted when $userRightsCheckFunction is not null (common.php:558).
testlinkInitPage($db, FALSE, false, null);

$id = isset($_REQUEST['id']) && is_scalar($_REQUEST['id'])
    ? intval($_REQUEST['id']) : 0;
$table = isset($_REQUEST['table']) && is_scalar($_REQUEST['table'])
    ? trim((string) $_REQUEST['table']) : '';
$fkId = isset($_REQUEST['fk_id']) && is_scalar($_REQUEST['fk_id'])
    ? intval($_REQUEST['fk_id']) : 0;

$url = $_SESSION['basehref'] . 'gui/templates/attachments/attachmentDelete.html';
$url .= '?id=' . $id;
// both parts are required: forwarding table with fk_id=0 could never satisfy
// the BFF ownership proof, so it would only produce a misleading 403
if ($table !== '' && $fkId > 0) {
    $url .= '&table=' . rawurlencode($table);
    $url .= '&fk_id=' . $fkId;
}
$url .= '&tproject_id=' .
    intval($_SESSION['testprojectID'] ?? 0);
header('Location: ' . $url);
exit;
