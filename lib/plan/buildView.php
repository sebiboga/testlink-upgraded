<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * @filesource buildView.php
 *
 * Builds & Releases / View Build - MODERNIZED (Dashio standalone page) - Refs #1791
 *
 * The legacy renderer (buildView.php + gui/templates/dashio/plan/buildView.tpl)
 * has been replaced by:
 *
 *   gui/templates/plans/buildsView.html   the screen
 *   api/builds/index.php                  the BFF (GET /, GET /{id}, GET /cfields,
 *                                         POST /, PUT /{id}, DELETE /{id})
 *
 * "View Build" was modernized in #1723, and the 2.0.1 aside menu already points at
 * the new screen (lib/functions/common.php:2220 sets
 * $actions->buildView = "/gui/templates/plans/buildsView.html?{$ctx}"), so this
 * controller was reachable only from a stale bookmark, a wiki link or the cancel
 * button of the retired legacy buildEdit.tpl.
 *
 * It was kept only as a fatal error: the legacy renderer called
 * build::getCustomFieldsValues() (invoked on the build manager, once per
 * build) and that method no longer exists in 2.0.1 - custom fields are
 * served by the REST BFF from cfield_build_design_values via cfield_mgr - so any
 * build with design-time custom fields answered HTTP 500
 * ("Call to undefined method build::getCustomFieldsValues()"). The same defect
 * took down lib/plan/buildEdit.php in #1787 and lib/plan/planView.php in #1791.
 *
 * This file is a redirect ONLY - it holds no state and executes no legacy code
 * path. Every write goes through the BFF, which re-checks testplan_create_build
 * (canManage(), api/builds/index.php:142) plus exec_delete for a build that
 * still carries results (canDeleteExec(), :146) and resolves the owning test
 * project from build.testproject_id on every route.
 */
require('../../config.inc.php');
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
  $dest = 'buildView.php' . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== ''
      ? '?' . $_SERVER['QUERY_STRING'] : '');
  redirect('login.php?note=expired&destination=' . urlencode($dest));
}

// buildsView.html reads tplan_id (gui/templates/plans/buildsView.html:190) and
// drives the build selection itself.
// tplan_id is the ONLY parameter the legacy controller ever read
// (lib/plan/buildView.php at HEAD, :34). build_id / buildID were carried by
// buildView.tpl as hidden inputs for buildEdit.php, never by this controller, so
// they are forwarded only so that a bookmark carrying them keeps them - they are
// inert for the modernized page.
$tplan_id  = isset($_REQUEST['tplan_id'])  ? intval($_REQUEST['tplan_id'])  : 0;
$build_id  = isset($_REQUEST['build_id'])  ? intval($_REQUEST['build_id'])  : 0;
$build_id  = (isset($_REQUEST['buildID']) && $build_id === 0)
             ? intval($_REQUEST['buildID']) : $build_id;

// redirect() builds "$level.href='...'", so $level must be a LOCATION OBJECT,
// never a method: 'window.location.replace' would emit
// window.location.replace.href='...' (an expando on the function object) and the
// browser would stay on a blank page. The replace() semantics are wanted here
// (a legacy bookmark should not stay in the history), so they are emitted here.
$target = '/gui/templates/plans/buildsView.html?tplan_id=' . $tplan_id;
if ($build_id > 0) {
  $target .= '&build_id=' . $build_id;
}

$safeTarget = addslashes($target);
echo "<html><head></head><body>";
echo "<script type='text/javascript'>";
echo "window.location.replace('$safeTarget');";
echo "</script></body></html>";
exit;