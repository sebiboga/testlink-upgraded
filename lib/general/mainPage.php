<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/ 
 * This script is distributed under the GNU General Public License 2 or later. 
 *
 * @filesource	mainPage.php
 * 
 * Page has two functions: navigation and select Test Plan
 *
 * This file is the first page that the user sees when they log in.
 * Most of the code in it is html but there is some logic that displays
 * based upon the login. 
 * There is also some javascript that handles the form information.
 *
 **/

require_once('../../config.inc.php');
require_once('common.php');

testlinkInitPage($db,TRUE);

$smarty = new TLSmarty();
$tproject_mgr = new testproject($db);
$user = $_SESSION['currentUser'];

$testprojectID = 
isset($_SESSION['testprojectID']) 
? intval($_SESSION['testprojectID']) : 0;

if( isset($_REQUEST['testplan']) ) {
  $testplanID = $_REQUEST['testplan'];
} else {
  $testplanID = isset($_SESSION['testplanID']) ? $_SESSION['testplanID'] : 0;
}
$testplanID = intval($testplanID);


$accessibleItems = $tproject_mgr->get_accessible_for_user($user->dbID,array('output' => 'map_name_with_inactive_mark'));
$tprojectQty = $tproject_mgr->getItemCount();
$userIsBlindFolded = (is_null($accessibleItems) || count($accessibleItems) == 0) && $tprojectQty > 0;

if($userIsBlindFolded) {
  $testprojectID = $testplanID = 0;
  $_SESSION['testprojectTopMenu'] = '';
}

$tplan2check = null;
$currentUser = $_SESSION['currentUser'];
$userID = $currentUser->dbID;

$gui = new stdClass();
$gui->grants = getGrants($db,$user,$testprojectID,$userIsBlindFolded);

/*
echo '<pre>';
var_dump($gui->grants);
echo '</pre>';
*/

$gui->hasTestCases = false;

if($gui->grants['view_tc']) { 
	$gui->hasTestCases = $tproject_mgr->count_testcases($testprojectID) > 0 ? 1 : 0;
}

$gui->hasKeywords = false;
if($gui->hasTestCases) {
  $gui->hasKeywords = $tproject_mgr->hasKeywords($testprojectID);
}  


// ----- Test Plan Section --------------------------------
/** 
 * @TODO - franciscom - we must understand if these two calls are really needed,
 * or is enough just call to getAccessibleTestPlans()
 */
$filters = array('plan_status' => ACTIVE);
$gui->num_active_tplans = $tproject_mgr->getActiveTestPlansCount($testprojectID);

// get Test Plans available for the user 
$arrPlans = (array)$currentUser->getAccessibleTestPlans($db,$testprojectID);

if($testplanID > 0) {
	// if this test plan is present on $arrPlans
	//	  OK we will set it on $arrPlans as selected one.
	// else 
	//    need to set test plan on session
	//
	$index=0;
	$found=0;
	$loop2do=count($arrPlans);
	for($idx=0; $idx < $loop2do; $idx++) {
  	if( $arrPlans[$idx]['id'] == $testplanID ) {
     	$found = 1;
     	$index = $idx;
     	break;
    }
  }
  if( $found == 0 ) {
    // update test plan id
    if(count($arrPlans) > 0) {
      $index = 0;
      $testplanID = $arrPlans[$index]['id'];
    } 
    else {
      // Session still points at a Test Plan of ANOTHER Test Project while
      // the newly selected one has no accessible Test Plans. Nothing can be
      // selected here: drop the stale session plan instead of reading
      // offset 0 of an empty array (PHP 8.x turns this into E_WARNINGs,
      // see GitHub issue #548). setSessionTestPlan(null) clears the keys.
      $testplanID = 0;
      setSessionTestPlan(null);
    }
  } 

  if($testplanID > 0) {
    setSessionTestPlan($arrPlans[$index]);         
    $arrPlans[$index]['selected']=1;
  }
}

$gui->testplanRole = null;
if ($testplanID)  {

  $rd = null; 
  // Role can be configured or inherited
  if( isset($currentUser->tplanRoles[$testplanID]) ) {
    // Configured
    $role = $currentUser->tplanRoles[$testplanID];
    $rd = $role->getDisplayName();
  } else {
    if( config_get('testplan_role_inheritance_mode') == 'global' ) {
      $rd = $currentUser->globalRole->name;
    }
  } 

  if( null != $rd ) {
    $gui->testplanRole = $tlCfg->gui->role_separator_open .$rd . $tlCfg->gui->role_separator_close;
  }
}
$rights2check = array('testplan_execute','testplan_create_build',
                      'testplan_metrics','testplan_planning',
                      'testplan_user_role_assignment',
                      'mgt_testplan_create',
                      'cfield_view', 'cfield_management',
                      'testplan_milestone_overview',
                      'exec_testcases_assigned_to_me',
                      'exec_assign_testcases','exec_ro_access',
                      'testplan_add_remove_platforms',
                      'testplan_update_linked_testcase_versions',
                      'testplan_set_urgent_testcases',
                      'testplan_show_testcases_newest_versions');

foreach($rights2check as $key => $the_right) {
  $gui->grants[$the_right] = $userIsBlindFolded ? 'no' : $currentUser->hasRight($db,$the_right,$testprojectID,$testplanID);
}
                         
$gui->grants['tproject_user_role_assignment'] = "no";
if( $currentUser->hasRight($db,"testproject_user_role_assignment",$testprojectID,-1) == "yes" ||
    $currentUser->hasRight($db,"user_role_assignment",null,-1) == "yes" )
{ 
    $gui->grants['tproject_user_role_assignment'] = "yes";
}


$gui->url = array('metrics_dashboard' => 'lib/results/metricsDashboard.php',
                  'testcase_assignments' => 'lib/testcases/tcAssignedToUser.php');
$gui->launcher = 'lib/general/frmWorkArea.php';
$gui->arrPlans = $arrPlans;                   
$gui->countPlans = count($gui->arrPlans);


$gui->testprojectID = $testprojectID;
$gui->testplanID = $testplanID;

$gui->docs = config_get('userDocOnDesktop') ? getUserDocumentation() : null;

$secCfg = config_get('config_check_warning_frequence');
$gui->securityNotes = '';
if( (strcmp($secCfg, 'ALWAYS') == 0) || 
      (strcmp($secCfg, 'ONCE_FOR_SESSION') == 0 && !isset($_SESSION['getSecurityNotesOnMainPageDone'])) )
{
  $_SESSION['getSecurityNotesOnMainPageDone'] = 1;
  $gui->securityNotes = getSecurityNotes($db);
}  

$tprojMgr = new testproject($db);
$tprojOpts = $tprojMgr->getOptions(intval($_SESSION['testprojectID']));
$gui->opt_requirements = (!empty($tprojOpts) && isset($tprojOpts->requirementsEnabled))
                         ? $tprojOpts->requirementsEnabled : null; 


$gui->plugins = array();
foreach(array('EVENT_LEFTMENU_TOP',
              'EVENT_LEFTMENU_BOTTOM',
              'EVENT_RIGHTMENU_TOP',
              'EVENT_RIGHTMENU_BOTTOM') as $menu_item) 
{
  # to be compatible with PHP 5.4
  $menu_content = event_signal($menu_item);
  if( !empty($menu_content) )
  {
    $gui->plugins[$menu_item] = $menu_content;
  }
}

// The left side menu (aside.tpl) needs the full user UX environment:
// showMenu (gates the whole menu), activeMenu, uri, logo, whoami and
// grants as an object. mainPage.php builds $gui by hand, so take those
// from initUserEnv(), the same way every other page rendering the menu does.
// forceCreateProj sends an admin to test project creation when the system
// has none yet, otherwise there is no menu entry to create the first one.
$uxContext = new stdClass();
$uxContext->tproject_id = $testprojectID;
$uxContext->tplan_id = $testplanID;
list($uxArgs,$ux) = initUserEnv($db,$uxContext,array('forceCreateProj' => true));

// grants is overwritten on purpose: the code above uses it as an array,
// aside.tpl reads it as an object. All array access is already done here.
foreach(array('showMenu','activeMenu','uri','logo','whoami',
              'grants','access','prjSet','zeroTestProjects') as $prop) {
  if( property_exists($ux,$prop) ) {
    $gui->$prop = $ux->$prop;
  }
}

// mainPage.tpl (dashio) draws a dashboard from $gui->dashboard. Nothing ever
// built it, so the theme's main panel rendered empty except for its subtitle.
$gui->dashboard = getDashboardData($db,$testprojectID,$testplanID,$gui);

// Second dashboard widget. Scoped to the test project, not the test plan, so
// it still has something to say before any plan exists.
$gui->tcGrowth = getTestCaseGrowthData($db,$testprojectID);

// Third dashboard widget: bugs linked to this plan's executions.
$gui->bugsInfo = getBugsTestedData($db,$testprojectID,$testplanID);

$tplKey = 'mainPage';
$tpl = $tplKey . '.tpl';
$tplCfg = config_get('tpl');
if( null !== $tplCfg && isset($tplCfg[$tplKey]) ) {
  $tpl = $tplCfg->$tplKey;
} 

$smarty->assign('gui',$gui);
$smarty->display($tpl);


/**
 * Execution status counters for the dashboard on the home page.
 *
 * Returns null when there is nothing worth drawing (no project, no test plan,
 * a plan with no builds, or a plan with no linked test cases) so the template
 * can fall back to a short "nothing to show yet" message instead of an empty
 * chart.
 */
function getDashboardData(&$dbHandler,$tprojectID,$tplanID,$guiObj)
{
  if($tprojectID <= 0 || $tplanID <= 0) {
    return null;
  }

  // tlTestPlanMetrics throws on a plan with no builds rather than returning
  // an empty result set, and a plan has none until the user creates one.
  $tplanMgr = new testplan($dbHandler);
  if($tplanMgr->getNumberOfBuilds($tplanID) == 0) {
    return null;
  }

  $metricsMgr = new tlTestPlanMetrics($dbHandler);
  $rx = $metricsMgr->getStatusTotalsByTopLevelTestSuiteForRender($tplanID,null,
          array('groupByPlatform' => 1));
  if(is_null($rx) || !property_exists($rx,'info') || is_null($rx->info)) {
    return null;
  }

  $resultsCfg = config_get('results');
  $dbo = new stdClass();
  $dbo->total = 0;
  $dbo->slices = array();

  // Only the four statuses that make up a run: the rest ('all',
  // 'not_available', 'unknown') are filter helpers, not execution outcomes.
  $palette = array('passed' => '#4ECDC4', 'failed' => '#e6605e',
                   'blocked' => '#f0ad4e', 'not_run' => '#8f8f8f');

  foreach($palette as $statusVerbose => $color) {
    $qty = 0;
    // getStatusTotalsByTopLevelTestSuiteForRender() keys info by platform,
    // then by test suite - sum every suite of every platform.
    foreach($rx->info as $suiteSet) {
      foreach($suiteSet as $suiteInfo) {
        if(isset($suiteInfo['details'][$statusVerbose]['qty'])) {
          $qty += intval($suiteInfo['details'][$statusVerbose]['qty']);
        }
      }
    }

    $lblKey = isset($resultsCfg['status_label'][$statusVerbose])
              ? $resultsCfg['status_label'][$statusVerbose] : $statusVerbose;

    $dbo->slices[$statusVerbose] = array('qty' => $qty, 'percentage' => 0,
                                         'color' => $color,
                                         'label' => lang_get($lblKey));
    $dbo->total += $qty;
  }

  if($dbo->total == 0) {
    return null;
  }

  $executed = $dbo->total - $dbo->slices['not_run']['qty'];
  $dbo->executed = $executed;
  $dbo->percentage_completed = number_format(100 * ($executed / $dbo->total),1);

  foreach($dbo->slices as $statusVerbose => $slice) {
    $dbo->slices[$statusVerbose]['percentage'] =
      number_format(100 * ($slice['qty'] / $dbo->total),1);
  }

  $dbo->tplan_name = isset($_SESSION['testplanName']) ? $_SESSION['testplanName'] : '';

  return $dbo;
}

/**
 * Monthly test case growth for the second dashboard widget.
 *
 * Returns null when there is no project or the project has no test cases at
 * all, so the template can skip the widget instead of drawing a flat row of
 * zeros that tells the user nothing.
 */
function getTestCaseGrowthData(&$dbHandler,$tprojectID)
{
  if($tprojectID <= 0) {
    return null;
  }

  $tprojectMgr = new testproject($dbHandler);
  $monthly = $tprojectMgr->getTestCaseCreationMonthly($tprojectID,12);

  if(array_sum($monthly) == 0) {
    return null;
  }

  $gx = new stdClass();
  $gx->labels = array();
  $gx->values = array();
  $gx->total = 0;
  $gx->peak = 0;
  foreach($monthly as $yearMonth => $qty) {
    // 'YYYY-MM' -> 'Aug 26', short enough to fit 12 labels on one axis.
    $gx->labels[] = date('M y',strtotime($yearMonth . '-01'));
    $gx->values[] = $qty;
    $gx->total += $qty;
    $gx->peak = max($gx->peak,$qty);
  }

  return $gx;
}

/**
 * Bugs linked to executions of the current test plan, one row per bug.
 *
 * Reads execution_bugs (via getAllExecutionsWithBugs), so it reports what
 * testers actually attached while executing rather than anything derived from
 * the plan's name. Returns null when the plan has no linked bugs, so the
 * template skips the widget entirely.
 */
function getBugsTestedData(&$dbHandler,$tprojectID,$tplanID)
{
  if($tprojectID <= 0 || $tplanID <= 0) {
    return null;
  }

  $tplanMgr = new testplan($dbHandler);
  $rows = $tplanMgr->getAllExecutionsWithBugs($tplanID);

  if(empty($rows)) {
    return null;
  }

  // A bug can be attached to several executions of several test cases; the
  // widget lists each bug once, naming every test case that surfaced it.
  $bugs = array();
  foreach($rows as $row) {
    $bugID = $row['bug_id'];
    if(!isset($bugs[$bugID])) {
      $bugs[$bugID] = array();
    }

    $bugs[$bugID][$row['full_external_id'] . ':' . $row['name']] = true;
  }

  // With a tracker configured the issue's real title and status come from it;
  // without one all TestLink holds is the bare id typed at execution time.
  $its = null;
  $tprojectMgr = new testproject($dbHandler);
  $tprojectInfo = $tprojectMgr->get_by_id($tprojectID);
  if(!empty($tprojectInfo['issue_tracker_enabled'])) {
    $itMgr = new tlIssueTracker($dbHandler);
    $its = $itMgr->getInterfaceObject($tprojectID);
  }

  // Open issues are the ones still costing the team something, so they get the
  // 'failed' red; anything the tracker reports as closed gets the 'passed'
  // green. Same palette as the execution pie above.
  $statusColor = array('closed' => '#5cb85c', 'open' => '#e6605e');

  $dbo = array();
  foreach($bugs as $bugID => $tcaseSet) {
    $item = array('id' => $bugID,
                  'tcases' => implode(', ',array_keys($tcaseSet)),
                  'url' => '',
                  'title' => '',
                  'status' => '',
                  'color' => '#8f8f8f');

    if(is_object($its)) {
      $issue = $its->getIssue($bugID);
      if(is_object($issue)) {
        $item['url'] = $issue->url;
        // summary is "<title>:\n<body>" - the dashboard only has room for the
        // title, and the body is a wall of text on most trackers.
        $item['title'] = rtrim(strtok((string)$issue->summary,"\n"),':');
        $item['status'] = (string)$issue->statusVerbose;

        $key = strtolower($item['status']);
        if(isset($statusColor[$key])) {
          $item['color'] = $statusColor[$key];
        }
      }
    }

    $dbo[] = $item;
  }

  return $dbo;
}

/**
 * Get User Documentation
 * based on contribution by Eugenia Drosdezki
 */
function getUserDocumentation()
{
  $target_dir = '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'docs';
  $documents = null;
    
  if ($handle = opendir($target_dir)) 
  {
    while (false !== ($file = readdir($handle))) 
    {
      clearstatcache();
      if (($file != ".") && ($file != "..")) 
      {
        if (is_file($target_dir . DIRECTORY_SEPARATOR . $file))
        {
          $documents[] = $file;
        }    
      }
    }
    closedir($handle);
  }
  return $documents;
}

/**
 *
 */
function getGrants($dbHandler,$user,$tproject_id,$forceToNo=false)
{
  // User has test project rights
  // This talks about Default/Global
  //
  // key: more or less verbose
  // value: string present on rights table
  $right2check = 
    array('project_edit' => 'mgt_modify_product',
          'reqs_view' => "mgt_view_req", 
          'monitor_req' => "monitor_requirement", 
          'req_tcase_link_management' => "req_tcase_link_management",
          'reqs_edit' => "mgt_modify_req",
          'keywords_view' => "mgt_view_key",
          'keyword_assignment' => "keyword_assignment",
          'keywords_edit' => "mgt_modify_key",
          'platform_management' => "platform_management",
          'platform_view' => "platform_view",
          'issuetracker_management' => "issuetracker_management",
          'issuetracker_view' => "issuetracker_view",
          'codetracker_management' => "codetracker_management",
          'codetracker_view' => "codetracker_view",
          'configuration' => "system_configuraton",
          'cfield_management' => 'cfield_management',
          'cfield_view' => 'cfield_view',
          'cfield_assignment' => 'cfield_assignment',
          'usergroups' => "mgt_view_usergroups",
          'view_tc' => "mgt_view_tc",
          'view_testcase_spec' => "mgt_view_tc",
          'project_inventory_view' => 'project_inventory_view',
          'project_inventory_management' => 'project_inventory_management',
          'modify_tc' => 'mgt_modify_tc',
          'exec_edit_notes' => 'exec_edit_notes', 
          'exec_delete' => 'exec_delete',
          'testplan_unlink_executed_testcases' => 'testplan_unlink_executed_testcases',
          'testproject_delete_executed_testcases' => 'testproject_delete_executed_testcases',
          'exec_ro_access' => 'exec_ro_access');
 if ($forceToNo) {
    $grants = array_fill_keys(array_keys($right2check), 'no');
    // inventory grants must stay int 1/0 (see below) - 'no' is truthy and
    // would make templates render the Inventory link
    $grants['project_inventory_view'] = 0;
    $grants['project_inventory_management'] = 0;
    return $grants;      
 }  
  
  
 $grants['project_edit'] = $user->hasRight($dbHandler,$right2check['project_edit'],$tproject_id); 

  /** redirect admin to create testproject if not found */
  if ($grants['project_edit'] && !isset($_SESSION['testprojectID'])) {
	  redirect($_SESSION['basehref'] . 'lib/project/projectEdit.php?doAction=create');
	  exit();
  }
  

  foreach($right2check as $humankey => $right) {
    $grants[$humankey] = $user->hasRight($dbHandler,$right,$tproject_id); 
  }


  // check right ONLY if option is enabled.
  // Read the LIVE test project options from DB: the legacy
  // $_SESSION['testprojectOptions'] key is never written in this codebase,
  // so the old isset() check always failed and the grants kept the raw
  // 'yes'/'no' strings from hasRight() - 'no' is truthy, which made
  // mainPageLeft.tpl render the Inventory link while inventory was disabled.
  // Inventory grants are ALWAYS int 1/0 (same convention as
  // getGrantSetWithExit()).
  $tprojMgr = new testproject($dbHandler);
  $tprojOpt = $tprojMgr->getOptions($tproject_id);
  if( !empty($tprojOpt->inventoryEnabled) ) {
    $invr = array('project_inventory_view','project_inventory_management');
    foreach($invr as $r){
      $grants[$r] = ($user->hasRight($dbHandler,$r,$tproject_id) == 'yes') ? 1 : 0;
    }
  } else {
    $grants['project_inventory_view'] = 0;
    $grants['project_inventory_management'] = 0;
  }

  return $grants;  
}
