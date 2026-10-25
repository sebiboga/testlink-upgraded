<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * Report requirement based results - MODERNIZED (Dashio) - Refs #1966
 *
 * The legacy renderer has been replaced by:
 *   gui/templates/results/resultsRequirements.html
 *   api/reports/index.php action=metrics_results_reqs
 *
 * This controller is kept only as a redirect for stale bookmarks / wiki links.
 */
require_once('../../config.inc.php');
require_once('common.php');
testlinkInitPage($db,false,false);

if (isset($_SERVER['REQUEST_METHOD']) && !in_array(strtoupper($_SERVER['REQUEST_METHOD']), array('GET','HEAD'), true)) {
  http_response_code(405);
  header('Allow: GET, HEAD');
  exit('Method Not Allowed');
}

if (empty($_SESSION['userID'])) {
  $dest = 'lib/results/resultsReqs.php';
  if (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '') {
      $dest .= '?' . $_SERVER['QUERY_STRING'];
  }
  redirect('login.php?note=expired&destination=' . urlencode($dest));
}

$tproject_id = isset($_REQUEST['tproject_id']) ? intval($_REQUEST['tproject_id']) : 0;
if ($tproject_id <= 0) {
  $tproject_id = isset($_SESSION['testprojectID']) ? intval($_SESSION['testprojectID']) : 0;
}

$tplan_id = isset($_REQUEST['tplan_id']) ? intval($_REQUEST['tplan_id']) : 0;
if ($tplan_id <= 0) {
  $tplan_id = isset($_SESSION['testplanID']) ? intval($_SESSION['testplanID']) : 0;
}

$target = '/gui/templates/results/resultsRequirements.html?tproject_id=' . $tproject_id . '&tplan_id=' . $tplan_id;

$safeTarget = addslashes($target);
echo "<html><head></head><body>";
echo "<script type='text/javascript'>";
echo "window.location.replace('" . $safeTarget . "');";
echo "</script></body></html>";
exit;
