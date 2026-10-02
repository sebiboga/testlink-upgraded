<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * @filesource  buildEdit.php
 *
 * Build Create/Edit - MODERNIZED (Dashio standalone page) - Refs #1787
 *
 * The legacy 769-line renderer (buildEdit.php + gui/templates/dashio/plan/
 * buildEdit.tpl) has been replaced by:
 *
 *   gui/templates/plans/buildEdit.html   the screen
 *   api/builds/index.php                 the BFF (GET /, GET /{id}, GET
 *                                        /cfields, POST /, PUT /{id})
 *
 * This file is kept only so that a bookmark, a wiki link or an old aside
 * entry lands on the new screen instead of a fatal error: the legacy
 * renderer called build::getCustomFieldsValues(), a method that no longer
 * exists in 2.0.1, so ANY direct hit of this URL ended in a PHP fatal
 * ("Call to undefined method build::getCustomFieldsValues()").
 *
 * It is a redirect ONLY - it holds no state and executes no legacy code
 * path. Every write goes through the BFF, which re-checks the legacy right
 * (testplan_create_build) and resolves the owning test project through the
 * test plan on every route.
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
  $dest = 'buildEdit.php' . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== ''
      ? '?' . $_SERVER['QUERY_STRING'] : '');
  redirect('login.php?note=expired&destination=' . urlencode($dest));
}

// Preserve the addressing parameters the legacy launcher used to forward.
$tplan_id  = isset($_REQUEST['tplan_id'])  ? intval($_REQUEST['tplan_id'])  : 0;
$build_id  = isset($_REQUEST['build_id'])  ? intval($_REQUEST['build_id'])  : 0;
$build_id  = (isset($_REQUEST['buildID']) && $build_id === 0)
             ? intval($_REQUEST['buildID']) : $build_id;

$target = '/gui/templates/plans/buildEdit.html?tplan_id=' . $tplan_id;
if ($build_id > 0) {
  $target .= '&build_id=' . $build_id;
}

redirect($target, 'window.location.replace');