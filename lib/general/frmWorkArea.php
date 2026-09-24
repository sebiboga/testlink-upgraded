<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/ 
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * @filesource  frmWorkArea.php
 * @author      Martin Havlat
 *
 * 2010.2.shim - Refs #1575: the legacy work-area frameset (left tree + right
 * content pane rendered by frmInner.tpl) was replaced by the modernized
 * Dashio standalone screens. Every legacy feature=? deep link is forwarded
 * here by the dead tl-classic templates ($gui->launcher) and by stale bookmarks;
 * this controller preserves the session guard, validates the feature argument
 * and redirects (302) to the equivalent modern /gui/templates HTML screen
 * carrying the tproject_id / tplan_id context, exactly like the mainPage.php
 * shim (Refs #1555).
**/
require_once('../../config.inc.php');
require_once("common.php");

testlinkInitPage($db,TRUE);

$args = init_args();

// feature => modern standalone screen (without tproject/tplan context)
$feature_map = array(
  'editTc'            => 'gui/templates/testcases/testSpec.html',
  'assignReqs'        => 'gui/templates/requirements/assignReqs.html',
  'searchTc'          => 'gui/templates/search/searchView.html',
  'searchReq'         => 'gui/templates/requirements/searchReq.html',
  'searchReqSpec'     => 'gui/templates/requirements/searchReqSpec.html',
  'printTestSpec'     => 'gui/templates/testcases/printTestSpec.html',
  'printReqSpec'      => 'gui/templates/requirements/printReqSpec.html',
  'keywordsAssign'    => 'gui/templates/keywords/keywordsAssign.html',
  'planAddTC'         => 'gui/templates/plans/planAddTCView.html',
  'planRemoveTC'      => 'gui/templates/plans/planAddTCView.html',
  'planUpdateTC'      => 'gui/templates/plans/planUpdateTC.html',
  'show_ve'           => 'gui/templates/plans/planNav.html',
  'newest_tcversions' => 'gui/templates/plans/showNewestTcVersions.html',
  'test_urgency'      => 'gui/templates/plans/testUrgency.html',
  'tc_exec_assignment'=> 'gui/templates/execute/tcExecAssignment.html',
  'executeTest'       => 'gui/templates/execute/execTest.html',
  'showMetrics'       => 'gui/templates/results/resultsNavigator.html',
  'reqSpecMgmt'       => 'gui/templates/requirements/reqSpecMgmt.html'
);

$showFeature = $args->feature;
if (isset($feature_map[$showFeature]) === FALSE) {
  // argument is wrong
  tLog("Wrong page argument feature = ".$showFeature, 'ERROR');
  exit();
}

$url = $_SESSION['basehref'] . $feature_map[$showFeature];
$url .= (strpos($feature_map[$showFeature], "?") === false) ? "?" : "&";
$tproject_id = isset($_SESSION['testprojectID']) ? intval($_SESSION['testprojectID']) : 0;
$tplan_id = isset($_SESSION['testplanID']) ? intval($_SESSION['testplanID']) : 0;
$url .= "tproject_id={$tproject_id}&tplan_id={$tplan_id}";

if ($args->tproject_id > 0) {
  $url = preg_replace('/tproject_id=\d+/', "tproject_id={$args->tproject_id}", $url);
}
if ($args->tplan_id > 0) {
  $url = preg_replace('/tplan_id=\d+/', "tplan_id={$args->tplan_id}", $url);
}

header('Location: ' . $url);
exit();

/**
 *
 */
function init_args()
{
  $_REQUEST=strings_stripSlashes($_REQUEST);
  $args = new stdClass();
  $iParams = array("feature" => array(tlInputParameter::STRING_N),
                   "tproject_id" => array(tlInputParameter::INT_N),
                   "tplan_id" => array(tlInputParameter::INT_N));
  R_PARAMS($iParams,$args);

  return $args;
}