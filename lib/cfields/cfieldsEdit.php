<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * @filesource  cfieldsEdit.php
 *
 * 2.0.1 - Refs #1812: the legacy Smarty Custom Field Editor
 * (lib/cfields/cfieldsEdit.php + gui/templates/dashio/cfields/cfieldsEdit.tpl
 * + cfieldsEditJS.tpl, 505 lines) was replaced by the modern Dashio popup
 * gui/templates/cfields/cfieldsEdit.html backed by api/cfieldsedit.
 *
 * This controller is kept as a session-guarded redirect shim so every old deep
 * link keeps working:
 *   - cfieldsEdit.php?do_action=create[&tproject_id=N]
 *   - cfieldsEdit.php?do_action=edit&cfield_id=N[&tproject_id=N]
 *   - cfieldsEdit.php?do_action=do_add|do_add_and_assign|do_update|do_delete
 *     (the POST forms are gone with the template, so the writes are refused
 *      rather than silently redirecting to a read-only form: a legacy page that
 *      still POSTs here would otherwise claim success while doing nothing)
 *
 * Anonymous users are sent to the login screen (legacy testlinkInitPage
 * behaviour, plus the ?destination= note the modern login screen renders) and
 * authenticated users land on the modern popup with the query parameters the
 * editor needs. A plain GET on the write actions is refused with 405 so a
 * bookmarked "do_delete" URL can never destroy a definition.
 */
require_once(dirname(__FILE__) . "/../../config.inc.php");
require_once("common.php");

$db = new database(DB_TYPE);
doDBConnect($db);

/**
 * Legacy writes that used to be accepted by this file.
 * They MUST NOT be replayed from a redirect: doing so would perform a create,
 * an update or a DELETE behind the user's back.
 */
$writeActions = array('do_add', 'do_add_and_assign', 'do_update', 'do_delete');

$doAction = isset($_REQUEST['do_action']) ? (string) $_REQUEST['do_action'] : 'create';
$cfieldId = intval($_REQUEST['cfield_id'] ?? 0);
$tprojectId = intval($_REQUEST['tproject_id'] ?? ($_SESSION['testprojectID'] ?? 0));

if (in_array($doAction, $writeActions, true)) {
    header('Content-Type: text/plain; charset=utf-8');
    http_response_code(405);
    header('Allow: GET');
    echo "CFE-405: '" . $doAction . "' is no longer accepted here.\n"
       . "The Custom Field Editor is now a POST-driven BFF: "
       . "gui/templates/cfields/cfieldsEdit.html (api/cfieldsedit).\n"
       . "Open the editor from the Custom Fields manager and use its Save / Delete buttons.\n";
    exit;
}

$isEdit = ($doAction === 'edit' && $cfieldId > 0);

// Legacy testlinkInitPage() contract: an anonymous visitor is bounced to the
// login screen with a note=expired bounce and the original destination.
// checkSessionValid()'s own redirect is used (rather than a hand-rolled
// header()): it walks up from dirname(SCRIPT_FILENAME) until it finds
// login.php and supplies a root-relative REQUEST_URI destination — a relative
// 'login.php' (built from an unset $_SESSION['basehref']) resolves against
// /lib/cfields/ and 404s, and a non-root-relative destination is dropped by
// the modern login guard (Refs #1883).
if (!checkSessionValid($db)) {
    exit;  // unreachable: the call above already redirected
}

$url = $_SESSION['basehref'] . 'gui/templates/cfields/cfieldsEdit.html';
$url .= '?do_action=' . rawurlencode($isEdit ? 'edit' : 'create');
if ($isEdit) {
    $url .= '&cfield_id=' . $cfieldId;
}
if ($tprojectId > 0) {
    $url .= '&tproject_id=' . $tprojectId;
}
header('Location: ' . $url);
exit;
