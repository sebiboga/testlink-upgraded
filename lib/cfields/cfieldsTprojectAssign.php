<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * @filesource  cfieldsTprojectAssign.php
 *
 * 2.0.1 - Refs #1816: the legacy Smarty "Custom Fields - Test Project" screen
 * (lib/cfields/cfieldsTprojectAssign.php, 270 lines +
 *  gui/templates/dashio/cfields/cfieldsTprojectAssign.tpl, 197 lines) was
 * replaced by the modern Dashio screen
 * gui/templates/cfields/cfieldsTprojectAssign.html backed by
 * api/cfieldstproject.
 *
 * The legacy controller had FOUR write actions in one switch over
 * $_REQUEST['doAction'] (doAssign / doUnassign / doReorder / doBooleanMgmt) and
 * no CSRF proof, so any of them - including via a plain GET - mutated the
 * custom-field assignment of the tproject_id posted alongside. Those POST
 * forms are gone with the template, so the writes are REFUSED rather than
 * silently redirecting: a legacy page that still POSTs here would otherwise
 * report success while doing nothing. The modern screen sends
 * action=assign|unassign|save to the BFF, which enforces cfield_management on
 * the addressed project and proves every submitted custom field id.
 *
 * The legacy screen also had NO live entry point left: the only reference in
 * the whole tree was the dead tl-classic mainPageLeft.tpl. The modern screen is
 * reachable from the Custom Fields manager toolbar
 * (gui/templates/cfields/cfieldsView.html) and from
 * $actions->cfieldsTprojectAssign in lib/functions/common.php.
 *
 * Anonymous users are sent to the login screen (legacy testlinkInitPage
 * behaviour, plus the ?destination= note the modern login screen renders) and
 * authenticated users land on the modern screen.
 */
require_once(dirname(__FILE__) . "/../../config.inc.php");
require_once("common.php");

/**
 * Legacy writes that used to be accepted by this file. They MUST NOT be
 * replayed from a redirect: doing so would attach, detach or re-order custom
 * fields behind the user's back.
 */
$writeActions = array('doAssign', 'doUnassign', 'doReorder', 'doBooleanMgmt');

$doAction = isset($_REQUEST['doAction']) ? (string) $_REQUEST['doAction'] : '';
$tprojectId = intval($_REQUEST['tproject_id'] ?? ($_SESSION['testprojectID'] ?? 0));
if ($tprojectId <= 0) {
    $tprojectId = intval($_SESSION['testprojectID'] ?? 0);
}

if (in_array($doAction, $writeActions, true)) {
    header('Content-Type: text/plain; charset=utf-8');
    http_response_code(405);
    header('Allow: GET');
    echo "CFPA-405: '" . $doAction . "' is no longer accepted here.\n"
       . "Custom field assignment is now a POST-driven BFF: "
       . "gui/templates/cfields/cfieldsTprojectAssign.html (api/cfieldstproject).\n"
       . "Open the screen from the Custom Fields manager and use its Assign / Unassign / Save buttons.\n";
    exit;
}

$dest = 'gui/templates/cfields/cfieldsTprojectAssign.html'
      . ($tprojectId > 0 ? '?tproject_id=' . $tprojectId : '');

// Same contract as the other 302 shims: anonymous -> login with a destination.
if (!isset($_SESSION['userID']) || intval($_SESSION['userID']) <= 0) {
    header('Location: ' . $_SESSION['basehref'] . 'login.php?note=expired'
         . '&destination=' . rawurlencode($dest));
    exit;
}

header('Location: ' . $_SESSION['basehref'] . $dest);
exit;
