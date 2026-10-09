<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * @filesource	planMilestonesEdit.php
 * @author Francisco Mancardi
 *
 *
 */
require_once("../../config.inc.php");
require_once("common.php");
testlinkInitPage($db,false,false);
$date_format_cfg = config_get('date_format');

$templateCfg = templateConfiguration();
$args = init_args($db,$date_format_cfg);
$gui = initialize_gui($db,$args);

$context = new stdClass();
$context->tproject_id = $args->tproject_id;
$context->tplan_id = $args->tplan_id;
checkRights($db,$_SESSION['currentUser'],$context);


$commandMgr = new planMilestonesCommands($db);

$pFn = $args->doAction;
$op = null;
if(method_exists($commandMgr,$pFn))
{
	$op = $commandMgr->$pFn($args,$_SESSION['basehref']);
}

renderGui($args,$gui,$op,$templateCfg);


/*
  function: 

  args :
  
  returns: 

*/
function init_args(&$dbHandler,$dateFormat)
{
	$_REQUEST = strings_stripSlashes($_REQUEST);
	$args = new stdClass();

	$args->target_date_original = isset($_REQUEST['target_date']) ? $_REQUEST['target_date'] : null;
	$args->start_date_original = isset($_REQUEST['start_date']) ? $_REQUEST['start_date'] : null;
	
	// convert target date to iso format to write to db
    if (isset($_REQUEST['target_date']) && $_REQUEST['target_date'] != '') {
		$date_array = split_localized_date($_REQUEST['target_date'], $dateFormat);
		if ($date_array != null) {
			// set date in iso format
			$args->target_date = $date_array['year'] . "-" . $date_array['month'] . "-" . $date_array['day'];
		}
	}
	
	// convert start date to iso format to write to db
    if (isset($_REQUEST['start_date']) && $_REQUEST['start_date'] != '') {
		$date_array = split_localized_date($_REQUEST['start_date'], $dateFormat);
		if ($date_array != null) {
			// set date in iso format
			$args->start_date = $date_array['year'] . "-" . $date_array['month'] . "-" . $date_array['day'];
		}
	}
 	
  	$key2loop = array('low_priority_tcases','medium_priority_tcases','high_priority_tcases');
  	foreach($key2loop as $key)
  	{
  	    $args->$key = isset($_REQUEST[$key]) ? intval($_REQUEST[$key]) : 0;     
  	}

	$args->id = isset($_REQUEST['id']) ? intval($_REQUEST['id']) : 0;
	$args->name = isset($_REQUEST['milestone_name']) ? $_REQUEST['milestone_name'] : null;
	$args->doAction = isset($_REQUEST['doAction']) ? $_REQUEST['doAction'] : null;

	$args->basehref=$_SESSION['basehref'];
	$args->tproject_id = isset($_SESSION['testprojectID']) ? intval($_SESSION['testprojectID']) : 0;
	$args->tproject_name = isset($_SESSION['testprojectName']) ? $_SESSION['testprojectName'] : "";
	
	$args->tplan_name = '';
	$args->tplan_id = isset($_REQUEST['tplan_id']) ? intval($_REQUEST['tplan_id']) : 0;
	if( $args->tplan_id == 0 )
	{
	    $args->tplan_id = isset($_SESSION['testplanID']) ? intval($_SESSION['testplanID']) : 0;
	}
	if( $args->tplan_id > 0 )
	{
	    $tplan_mgr = new testplan($dbHandler);
	    $info = $tplan_mgr->get_by_id($args->tplan_id);
	    // Refs #1890: get_by_id() returns null for a non-existent / stale tplan_id,
	    // so guard the read and fall back to an empty name instead of warning.
	    $args->tplan_name = is_array($info) && isset($info['name']) ? $info['name'] : '';
  	}
  	 	
	return $args;
}


/*
  function: renderGui

  args:

  returns:

*/
function renderGui(&$argsObj,$guiObj,$opObj,$templateCfg)
{
    $smartyObj = new TLSmarty();
    //
    // key: operation requested (normally received from GUI on doAction)
    // value: operation value to set on doAction HTML INPUT
    // This is useful when you use same template (example xxEdit.tpl), for create and edit.
    // When template is used for create -> operation: doCreate.
    // When template is used for edit -> operation: doUpdate.
    //              
    // used to set value of: $guiObj->operation
    //
    $actionOperation=array('create' => 'doCreate', 'edit' => 'doUpdate',
                           'doDelete' => '', 'doCreate' => 'doCreate', 
                           'doUpdate' => 'doUpdate');
     
    $renderType = 'none';
    switch($argsObj->doAction)
    {
        case "edit":
        case "create":
        case "doDelete":
		case "doCreate":
      	case "doUpdate":
            $renderType = 'template';
            $key2loop = get_object_vars($opObj);
            foreach($key2loop as $key => $value)
            {
                $guiObj->$key = $value;
            }
            $guiObj->operation = $actionOperation[$argsObj->doAction];
            
            $tplDir = (!isset($opObj->template_dir)  || is_null($opObj->template_dir)) ? $templateCfg->template_dir : $opObj->template_dir;
            $tpl = is_null($opObj->template) ? $templateCfg->default_template : $opObj->template;
            
            $pos = strpos($tpl, '.php');
           	if($pos === false)
           	{
                $tpl = $tplDir . $tpl;      
            }
            else
            {
                $renderType = 'redirect';  
            }
            break;
    }

    switch($renderType)
    {
        case 'template':
        	$smartyObj->assign('gui',$guiObj);
		    $smartyObj->display($tpl);
        break;  
 
        case 'redirect':
		      header("Location: {$tpl}");
	  		  exit();
        break;

        default:
            // Refs #1726: $renderType is still 'none' here, so this controller can not
            // render the requested doAction (or there is none at all). init_args() has
            // no whitelist, so a bare URL reaches this sink, and until now it ended the
            // request with HTTP 200 and a 0-byte body: a silent blank page with no
            // Event Viewer row. Apply the same graceful 302 to the modernized Plan
            // Milestones screen that #1722 established for reqMgrSystemEdit.php, and
            // write one ERROR row so the next occurrence is greppable. The modern page
            // needs the test-plan context, so carry it forward when we have it.
            // The logged value is scalar-only, control chars stripped and length-capped
            // so a crafted query string can not inject multi-line / unbounded log text
            // (the hardening rationale of the sibling's #1731 follow-up).
            $rawAction = is_scalar($argsObj->doAction) ? (string)$argsObj->doAction : '';
            $rawAction = substr(preg_replace('/[[:cntrl:]]/', '?', $rawAction), 0, 200);
            tLog('planMilestonesEdit.php - requested action is not renderable - Value:' .
                 ($rawAction === '' ? '(none)' : $rawAction) . ' - File: ' . basename(__FILE__) .
                 ' - Function: ' . __FUNCTION__, 'ERROR');
            $base = isset($_SESSION['basehref']) ? $_SESSION['basehref'] : '/';
            $url = $base . 'gui/templates/plans/planMilestones.html';
            if (isset($argsObj->tplan_id) && intval($argsObj->tplan_id) > 0)
            {
                $url .= '?tplan_id=' . intval($argsObj->tplan_id);
            }
            header('Location: ' . $url, true, 302);
            exit();
    }

}

/*
  function: initialize_gui

  args : -

  returns:

*/
function initialize_gui(&$dbHandler,&$argsObj)
{
    $req_spec_mgr = new requirement_spec_mgr($dbHandler);
    $gui = new stdClass();
    
    $gui->user_feedback = null;
    $gui->main_descr = lang_get('req_spec');
    $gui->action_descr = null;

    $gui->grants = new stdClass();
    $gui->grants->milestone_mgmt = has_rights($dbHandler,"testplan_planning");
	$gui->grants->mgt_view_events = has_rights($dbHandler,"mgt_view_events");

	// Refs #1887: planMilestonesEdit.tpl reads tproject_id/tplan_id/tprojOpt/
	// managerURL/cancelActionJS. This controller never set them, so under PHP 8
	// every render logged 8 E_WARNING rows into the Event Viewer (undefined
	// property / read on null). Populate them here exactly like the sibling
	// planMilestonesView.php does, so the form also gets its correct context
	// (hidden ids + the testPriorityEnabled layout) instead of defaulting.
	$gui->tproject_id = intval($argsObj->tproject_id);
	$gui->tplan_id = intval($argsObj->tplan_id);
	$gui->managerURL = "lib/plan/planMilestonesEdit.php" .
	                   "?tproject_id={$gui->tproject_id}&tplan_id={$gui->tplan_id}";
	$tprjMgr = new testproject($dbHandler);
	$gui->tprojOpt = $tprjMgr->getOptions($gui->tproject_id);
	// Empty => template keeps its historical history.back() fallback.
	$gui->cancelActionJS = '';

	return $gui;
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
