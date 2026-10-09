<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * 2.0.1.shim - Refs #817/#modernization: the legacy "Set Results" execution
 * screen (lib/execute/execSetResults.php + old templates) was replaced by the
 * modern Dashio screen gui/templates/execute/execSetResults.html backed by the
 * api/execsetresults BFF. This controller is kept as a session-guarded
 * redirect shim so old deep links and bookmarks keep working.
**/
require_once('../../config.inc.php');
require_once('common.php');

testlinkInitPage($db, false, false);

if (isset($_SERVER['REQUEST_METHOD']) && !in_array(strtoupper($_SERVER['REQUEST_METHOD']), array('GET','HEAD'), true)) {
  http_response_code(405);
  header('Allow: GET, HEAD');
  exit('Method Not Allowed');
}

if (empty($_SESSION['userID'])) {
  $dest = 'execSetResults.php' . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');
  redirect('login.php?note=expired&destination=' . urlencode($dest));
}

// Forward all relevant parameters to modern screen
$params = array(
  'tcase_id', 'tcversion_id', 'version_id', 'id', 'tplan_id', 'testplan_id',
  'build_id', 'setting_build', 'platform_id', 'setting_platform', 'level',
  'caller', 'status', 'doExec', 'exec', 'tcversion_set', 'tc_versions',
  'step_notes', 'copyAttFromLEXEC', 'assignTask', 'user_id', 'notes'
);
$target = $_SESSION['basehref'] . 'gui/templates/execute/execSetResults.html';
$sep = '?';
foreach ($params as $p) {
  if (isset($_REQUEST[$p]) && is_scalar($_REQUEST[$p])) {
    $val = preg_replace('/[\r\n]/', '', strval($_REQUEST[$p]));
    if ($val !== '') {
      $target .= $sep . $p . '=' . rawurlencode($val);
      $sep = '&';
    }
  }
}
$target .= ($sep === '?' ? '?' : '&') . 'tproject_id=' . (isset($_SESSION['testprojectID']) ? intval($_SESSION['testprojectID']) : 0);

header('Location: ' . $target);
exit;
