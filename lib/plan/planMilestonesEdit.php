<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * Create / Edit Test Milestone - MODERNIZED (Dashio standalone page) - Refs #1894
 *
 * The legacy renderer (this file + gui/templates/dashio/plan/planMilestonesEdit.tpl
 * + planMilestonesCommands.class.php) has been replaced by:
 *
 *   gui/templates/plans/planMilestoneEdit.html   the screen (create + edit, full screen)
 *   api/milestoneedit/index.php                  the BFF (action=init, create, update, delete)
 *
 * The screen covers everything the legacy controller did:
 *   - create / update with the legacy form rules (non-empty name, unique name
 *     within the test plan, integer percentages 0..100, target date not in the
 *     past, target date not before the start date),
 *   - the testPriorityEnabled layout (three priority percentages, or a single
 *     medium percentage when priorities are disabled for the project),
 *   - delete with a confirm modal (legacy doDelete),
 *   - event-history link (mgt_view_events) and audit events
 *     (audit_milestone_created/saved/deleted),
 *   - rights/401/403/404/400 states re-checked server-side by api/milestoneedit
 *     (testplan_planning on the project that owns the addressed test plan).
 *
 * This legacy controller also had two long-standing defects that the rewrite
 * removes:
 *   - #1739: init_args() had no whitelist and the renderer dispatched through
 *     method_exists($commandMgr,$pFn), so an arbitrary ?doAction= value reached
 *     a sink; the modern screen only ever calls the fixed BFF routes.
 *   - #1886: ?doAction[]=x (an array) was passed straight to a command method
 *     and raised an uncaught TypeError -> HTTP 500; the BFF rejects a non-scalar
 *     action with 400 invalid_parameter.
 *
 * This controller was kept only as a redirect for stale bookmarks / wiki links.
 */
require_once('../../config.inc.php');
require_once("common.php");
testlinkInitPage($db,false,false);

// Nothing here is state-changing: refuse anything but a safe read so this file
// can never be used to smuggle a write past the BFF's checks.
if (isset($_SERVER['REQUEST_METHOD']) && !in_array(strtoupper($_SERVER['REQUEST_METHOD']), array('GET','HEAD'), true)) {
  http_response_code(405);
  header('Allow: GET, HEAD');
  exit('Method Not Allowed');
}

if (empty($_SESSION['userID'])) {
  $dest = 'planMilestonesEdit.php' . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== ''
      ? '?' . $_SERVER['QUERY_STRING'] : '');
  redirect('login.php?note=expired&destination=' . urlencode($dest));
}

// The legacy controller fell back to $_SESSION['testplanID'] when the request
// carried no tplan_id (init_args()), so keep that fallback for a bookmark.
$tplan_id = isset($_REQUEST['tplan_id']) ? intval($_REQUEST['tplan_id']) : 0;
if ($tplan_id <= 0) {
    $tplan_id = isset($_SESSION['testplanID']) ? intval($_SESSION['testplanID']) : 0;
}

// Legacy edit URLs identify the milestone through ?id=. A bare ?doAction= or a
// create URL carries no id, so it lands on the create form. An array action
// (?doAction[]=..., #1886) is not scalar and is ignored here - the redirect
// target is computed only from scalar inputs.
$doAction = isset($_REQUEST['doAction']) && is_scalar($_REQUEST['doAction'])
          ? (string)$_REQUEST['doAction'] : '';
$milestone_id = isset($_REQUEST['id']) ? intval($_REQUEST['id']) : 0;
if ($milestone_id <= 0 && isset($_REQUEST['milestone_id'])) {
    $milestone_id = intval($_REQUEST['milestone_id']);
}

$isEdit = ($milestone_id > 0) ||
          in_array($doAction, array('edit', 'doUpdate', 'do_update'), true);
$mode = $isEdit ? 'edit' : 'create';

$target = '/gui/templates/plans/planMilestoneEdit.html?mode=' . $mode;
if ($tplan_id > 0) {
    $target .= '&tplan_id=' . $tplan_id;
}
if ($isEdit && $milestone_id > 0) {
    $target .= '&milestone_id=' . $milestone_id;
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
