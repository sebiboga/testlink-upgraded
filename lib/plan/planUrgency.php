<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 * 
 *
 * @filesource  planUrgency.php
 * @package     TestLink
 * @author      Martin Havlat
 * @copyright   2003-2020, TestLink community 
 * @link        http://www.testlink.org
 * 
 **/
 
require('../../config.inc.php');
require_once('common.php');
testlinkInitPage($db,false,false);
$args = init_args();

$context = new stdClass();
$context->tproject_id = $args->tproject_id;
$context->tplan_id = $args->tplan_id;
checkRights($db,$_SESSION['currentUser'],$context);



if ($args->show_help) {
  show_instructions('test_urgency');
  exit();  
}

$templateCfg = templateConfiguration();
$tplan_mgr = new testPlanUrgency($db);
$gui = initializeGui($args,$tplan_mgr->tree_manager);

if ($args->urgency != OFF || isset($args->urgency_tc)){
  $gui->user_feedback = doProcess($args,$tplan_mgr);
}  


// get the current urgency for child test cases
$context = new stdClass();
$context->tplan_id = $args->tplan_id;
$context->tsuite_id = $args->node_id;
$context->tproject_id = $args->tproject_id;
$context->platform_id = $args->platform_id;

$gui->listTestCases = $tplan_mgr->getSuiteUrgency($context,array('build4testers' => $args->build4testers),
                                                  array('testcases' => $args->testCaseSet));
if (!is_array($gui->listTestCases) && !is_object($gui->listTestCases)) {
  $gui->listTestCases = array();
}

foreach($gui->listTestCases as $tcversion_id => $tcaseSet) 
{
  foreach($tcaseSet as $idx => $tcase)
  {
    $gui->listTestCases[$tcversion_id][$idx]['priority'] = priority_to_level($tcase['priority']);
  }  
}

$smarty = new TLSmarty();
$smarty->assign('gui', $gui);
$smarty->display($templateCfg->template_dir . $templateCfg->default_template);


/*
  function: init_args()

  args: -
  
  returns: object with user input.

*/
function init_args()
{
  $_REQUEST = strings_stripSlashes($_REQUEST);
    
  $args = new stdClass();
  $args->show_help = (isset($_REQUEST['level']) && $_REQUEST['level']=='testproject');
    
  $args->tproject_id = intval(isset($_REQUEST['tproject_id']) ? $_REQUEST['tproject_id'] : (isset($_SESSION['testprojectID']) ? $_SESSION['testprojectID'] : 0));
  $args->tplan_id = intval(isset($_REQUEST['tplan_id']) ? $_REQUEST['tplan_id'] : (isset($_SESSION['testplanID']) ? $_SESSION['testplanID'] : 0));
  $args->tplan_name = isset($_SESSION['testplanName']) ? $_SESSION['testplanName'] : '';
  $args->node_type = isset($_REQUEST['level']) ? $_REQUEST['level'] : OFF;
  $args->node_id = isset($_REQUEST['id']) ? $_REQUEST['id'] : ERROR;

  // Sets urgency for suite
 
  if (isset($_REQUEST['high_urgency'])) {  
    $args->urgency = HIGH;
  } elseif (isset($_REQUEST['medium_urgency'])) {  
    $args->urgency = MEDIUM;
  } elseif (isset($_REQUEST['low_urgency'])) {  
    $args->urgency = LOW;
  } else {
    $args->urgency = OFF;
  }  

  // Sets urgency for every single tc
  if (isset($_REQUEST['urgency']))  {
    $args->urgency_tc = $_REQUEST['urgency'];
  }

  // For more information about the data accessed in session here, see the comment
  // in the file header of lib/functions/tlTestCaseFilterControl.class.php.
  $args->treeFormToken = isset($_REQUEST['form_token']) ? $_REQUEST['form_token'] : 0;
  $mode = 'plan_mode';
  $session_data = isset($_SESSION[$mode]) && isset($_SESSION[$mode][$args->treeFormToken]) ? 
                  $_SESSION[$mode][$args->treeFormToken] : null;


  $args->testCaseSet = is_array($session_data) && isset($session_data['testcases_to_show']) ? $session_data['testcases_to_show'] : null;
  $args->build4testers = is_array($session_data) && isset($session_data['setting_build']) ? intval($session_data['setting_build']) : 0;
  $args->platform_id = is_array($session_data) && isset($session_data['setting_platform']) ? intval($session_data['setting_platform']) : 0;
      
  return $args;
}

/**
 *
 */
function initializeGui(&$argsObj,&$treeMgr)
{
  $guiObj = new stdClass();

  $ni = $treeMgr->get_node_hierarchy_info($argsObj->node_id);
  $guiObj->node_name = is_array($ni) && isset($ni['name']) ? $ni['name'] : '';
  $guiObj->user_feedback = null;
  $guiObj->node_id = $argsObj->node_id;
  $guiObj->tplan_id = $argsObj->tplan_id;
  $guiObj->tproject_id = $argsObj->tproject_id;
  $guiObj->tplan_name = $argsObj->tplan_name;
  $guiObj->formToken = $argsObj->treeFormToken;
  $guiObj->pageTitle = lang_get('plan_urgency');
  if ($argsObj->tplan_name) {
    $guiObj->pageTitle .= ' - ' . $argsObj->tplan_name;
  }
  return $guiObj;
} 


/**
 *
 */
function doProcess(&$argsObj,&$tplanMgr)
{
  $userFeedback = null;

  // Set urgency for test suite
  if($argsObj->urgency != OFF)
  {
    $userFeedback['type'] = $tplanMgr->setSuiteUrgency($argsObj->tplan_id, $argsObj->node_id, $argsObj->urgency);
    $userFeedback['message'] = lang_get(($userFeedback['type'] == OK) ? "feedback_urgency_ok" : "feedback_urgency_fail");
  }

  // Set urgency for individual testcases
  if (isset($argsObj->urgency_tc)) {
    foreach ($argsObj->urgency_tc as $id => $urgency)  {
      $tplanMgr->setTestUrgency($argsObj->tplan_id, 
                                intval($id), intval($urgency));
    }
  }

  return $userFeedback;
}


/**
 *
 */
function checkRights(&$db,&$user,&$context)
{
  $context->rightsOr = [];
  $context->rightsAnd = ["testplan_planning"];
  pageAccessCheck($db, $user, $context);
}