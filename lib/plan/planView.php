<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * @filesource  planView.php
 *
 * Test Plan Management listing - MODERNIZED (Dashio standalone page) - Refs #1791
 *
 * The legacy renderer (planView.php + gui/templates/dashio/plan/planView.tpl) has
 * been replaced by:
 *
 *   gui/templates/plans/planView.html   the screen
 *   api/plans/index.php                 the BFF (GET /, GET /{id}, POST /,
 *                                       PUT /{id}, DELETE /{id})
 *
 * The 2.0.1 aside menu already points at the new screen
 * (lib/functions/common.php:2193 sets
 * $actions->planView = "/gui/templates/plans/planView.html?{$ctx}"), so this
 * controller was reachable only from a stale bookmark, a wiki link or the
 * buttons of the retired legacy planEdit.tpl.
 *
 * It was kept only as a fatal error: the legacy renderer called
 * getCustomFieldsValues() on a testplan instance for every listed test plan, and
 * that method no longer exists in 2.0.1 (custom fields are served today by the
 * REST BFF from cfield_testplan_design_values via cfield_mgr). The call sat
 * behind `if ($hasCF)`, so the listing only died once the ACTIVE test project
 * had a design-time custom field linked - which is why it survived the
 * modernization unnoticed:
 *   PHP Fatal error: Uncaught Error: Call to undefined method
 *   testplan::getCustomFieldsValues() in lib/plan/planView.php:75
 * (testplan does NOT extend build - it extends tlObjectWithAttachments - so the
 * fatal names testplan::; the sibling buildView.php:94 names build::.)
 *
 * This was a READ-ONLY listing: it rendered no form of its own and posted
 * nothing. Every action it offered (create, edit, delete, setActive/inactivate,
 * export, import, assignRoles, gotoExecute) was a LINK built from
 * testplan::getViewActions(), and every one of those targets still exists in
 * 2.0.1 - planEdit.php (which also still handles its own delete through
 * do_action=do_delete), planExport.php, planImport.php, usersAssignPlan.php,
 * execTest.php. All of them are modernized screens whose own BFF re-checks
 * mgt_testplan_create, so nothing a user could do here is lost by redirecting.
 * Verified screen by screen against planView.html.
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
  $dest = 'planView.php' . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== ''
      ? '?' . $_SERVER['QUERY_STRING'] : '');
  redirect('login.php?note=expired&destination=' . urlencode($dest));
}

// The legacy controller took the test project from $_SESSION['testprojectID']
// (init_args()) and IGNORED ?tproject_id= completely - and because it called
// testlinkInitPage($db,false,false), it never even refreshed that session value
// from the URL. So the old behaviour of a bookmark WITHOUT the parameter was
// "show the session's active project". The modernized page reads tproject_id
// from the query string (gui/templates/plans/planView.html:179), so the session
// value is forwarded here as the fallback to keep that case working; an
// explicit ?tproject_id= still wins, which is what the URL always meant.
$tproject_id = isset($_REQUEST['tproject_id']) ? intval($_REQUEST['tproject_id']) : 0;
if ($tproject_id <= 0) {
    $tproject_id = isset($_SESSION['testprojectID']) ? intval($_SESSION['testprojectID']) : 0;
}

$target = '/gui/templates/plans/planView.html?tproject_id=' . $tproject_id;

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