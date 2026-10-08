<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * Manages test plans - MODERNIZED (Dashio standalone page) - Refs #1882
 *
 * The legacy renderer (planEdit.php + gui/templates/dashio/plan/planEdit.tpl +
 * plan/planEditJS.inc.tpl + plan/inc_controls_planEdit.tpl) has been replaced by:
 *
 *   gui/templates/plans/planEdit.html   the screen (create + edit, full screen)
 *   api/planedit/index.php              the BFF (action=init, action=save)
 *
 * The screen covers everything the legacy controller did:
 *   - create / update with the legacy name rules and duplicate-name guard
 *     (check_tplan_name_existence) - Refs #1117 (the planView modal had no
 *     copy-from, no API key display and no attachments),
 *   - copy-from-an-existing-plan with the full legacy option set
 *     (items2copy: tcases/priorities/milestones/user_roles/builds/
 *     platforms_links/attachments + copy_assigned_to + tcversion_type) - Refs #1118,
 *   - api_key read-only display with copy button - Refs #1119,
 *   - attachments (upload/download/delete) through api/attachments,
 *   - private-plan creator role assignment (addUserRole), audit events
 *     (audit_testplan_created/saved), event-history link,
 *   - rights/401/404/400/409 states re-checked server-side by api/planedit
 *     (mgt_testplan_create on the addressed project).
 *
 * do_delete stays where it already lives in 2.0.1: the Test Plan Management
 * page's delete confirm modal (gui/templates/plans/planView.html + DELETE on
 * api/plans), so a legacy do_action=do_delete bookmark lands on the edit page
 * of that plan instead of performing an unchecked write here.
 *
 * This controller was kept only as a redirect for stale bookmarks / wiki links.
 */
require_once('../../config.inc.php');
require_once("common.php");
testlinkInitPage($db,false,false);

// Nothing here is state-changing: refuse anything but a safe read so this
// file can never be used to smuggle a write past the BFF's checks.
if (isset($_SERVER['REQUEST_METHOD']) && !in_array(strtoupper($_SERVER['REQUEST_METHOD']), array('GET','HEAD'), true)) {
  http_response_code(405);
  header('Allow: GET, HEAD');
  exit('Method Not Allowed');
}

if (empty($_SESSION['userID'])) {
  $dest = 'planEdit.php' . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== ''
      ? '?' . $_SERVER['QUERY_STRING'] : '');
  redirect('login.php?note=expired&destination=' . urlencode($dest));
}

// The legacy controller took the test project from $_SESSION['testprojectID']
// (init_args()) and only ever read ?tproject_id= for its own links, so the
// session value is the fallback for a bookmark without the parameter; an
// explicit ?tproject_id= always wins.
$tproject_id = isset($_REQUEST['tproject_id']) ? intval($_REQUEST['tproject_id']) : 0;
if ($tproject_id <= 0) {
    $tproject_id = isset($_SESSION['testprojectID']) ? intval($_SESSION['testprojectID']) : 0;
}

// Legacy edit/upload/delete URLs identify the plan through itemID; the
// fileUpload/deleteFile links built by testplan::getAttachmentDownloadURL()
// pass the plan through tplan_id instead - accept either.
$itemID = isset($_REQUEST['itemID']) ? intval($_REQUEST['itemID']) : 0;
if ($itemID <= 0 && isset($_REQUEST['tplan_id'])) {
    $itemID = intval($_REQUEST['tplan_id']);
}

$target = '/gui/templates/plans/planEdit.html?tproject_id=' . $tproject_id;
if ($itemID > 0) {
    $target .= '&itemID=' . $itemID;
}

// redirect() builds "$level.href='...'", so $level must be a LOCATION OBJECT,
// never a method: 'window.location.replace' would emit
// window.location.replace.href='...' (an expando on the function object) and the
// browser would stay on a blank page. The replace() semantics are wanted here
// (a legacy bookmark should not stay in the history), so they are emitted here.
$safeTarget = addslashes($target);
echo "<html><head></head><body>";
echo "<script type='text/javascript'>";
echo "window.location.replace('$safeTarget');";
echo "</script></body></html>";
exit;
