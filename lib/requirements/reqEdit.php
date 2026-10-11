<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * @filesource  reqEdit.php
 * @author      Martin Havlat
 *
 * Screen to view existing requirements within a req. specification.
 *
 *
**/
require_once("../../config.inc.php");
require_once("common.php");
require_once("users.inc.php");
require_once('requirements.inc.php');
require_once('attachments.inc.php');
require_once("csv.inc.php");
require_once("xml.inc.php");
require_once("configCheck.php");
require_once("web_editor.php");

$editorCfg = getWebEditorCfg('requirement');
require_once(require_web_editor($editorCfg['type']));

testlinkInitPage($db,false,false);

$templateCfg = templateConfiguration();

// Refs #1739: ?doAction[]=x (or ?doAction[a]=1&doAction[b]=2) makes PHP fill
// $_REQUEST['doAction'] with an ARRAY. init_args() declares doAction as
// tlInputParameter::STRING_N (line 79), and inputparameter.class.php:295 calls
// trim($value) on whatever it read - trim() does not accept an array, so the
// request dies with a TypeError ("trim(): Argument #1 ($string) must be of type
// string, array given") BEFORE this screen's own code dispatches: HTTP 500 or a
// blank 200 with E_WARNING rows, not a sane "unusable action" answer.
//
// Drop the array before R_PARAMS can see it, so init_args() reads it as absent
// and the action whitelist below refuses it with the same graceful 302 as any
// other unusable action. The same local-shim idiom as #1736 in
// lib/requirements/reqSpecEdit.php (reqSpecEditDropShapedAction), rather than a
// change to the shared input-parameter layer: inputparameter.class.php:295
// trims unconditionally for every STRING_N parameter of every controller, so
// fixing it there is a separate, much wider change.
reqEditDropShapedAction();

$commandMgr = new reqCommands($db);

$args = init_args($db);
$gui = initialize_gui($db,$args,$commandMgr);

$context = new stdClass();
$context->tproject_id = $args->tproject_id;
checkRights($db,$args->user,$context);


$pFn = $args->doAction;
$op = null;

// Refs #1739: method_exists() is NOT a whitelist of requestable actions. It also
// matches the internal helpers of reqCommands (24 method_exists-visible members
// - simpleCompare:804 / initGuiBean:48 / initGuiObjForAttachmentOperations:1011
// / setAuditContext etc. - only 24 of them GUI actions), and the dispatch below
// calls whatever it matched with a FIXED 2-argument signature:
//   - simpleCompare (reqParams=4)          -> ArgumentCountError -> HTTP 500, 0 bytes
//   - initGuiObjForAttachmentOperations    -> "Call to private" / null $op
//   - initGuiBean (0) / setAuditContext(1) -> not a GUI bean / null $op
// i.e. an unrecognised doAction produced several different outcomes: a silent 500
// with a 0-byte body and ZERO Event Viewer rows (worse than a blank page: nothing
// to grep), or the blank 200 accompanied by 4 E_WARNING rows from renderGui()'s
// default: branch (reqEdit.php:160,172,173).
//
// Consult the real whitelist instead: only a name renderGui() can actually dispatch
// is invoked, so arity and visibility are guaranteed to match by construction, and
// the array-shaped ?doAction[]=x (which made method_exists() throw a TypeError ->
// HTTP 500) never reaches the check at all, because $pFn is then an array and fails
// the is_string() test. Everything refused below is bounced with a 302, never
// answered blank.
if(is_string($pFn) && $pFn !== '' && isset(reqEditActionWhitelist()[$pFn]))
{
  $op = $commandMgr->$pFn($args,$_REQUEST);
}
else
{
  reqEditRefuseUnknownAction($pFn);
  // unreachable: reqEditRefuseUnknownAction() always redirects and exits.
}

renderGui($args,$gui,$op,$templateCfg,$editorCfg,$db);


/**
 * init_args
 *
 */
function init_args(&$dbHandler)
{

  $reqTitleSize = config_get('field_size')->requirement_title;
  $iParams = array("requirement_id" => array(tlInputParameter::INT_N),
                   "req_version_id" => array(tlInputParameter::INT_N),
                   "req_spec_id" => array(tlInputParameter::INT_N),
                   "req_title" => array(tlInputParameter::STRING_N,0,$reqTitleSize),
                   "req_id_cbox" => array(tlInputParameter::ARRAY_INT),
                   "reqDocId" => array(tlInputParameter::STRING_N,0,64), 
                   "reqStatus" => array(tlInputParameter::STRING_N,0,1),
                   "reqType" => array(tlInputParameter::STRING_N,0,1),
                   "containerID" => array(tlInputParameter::INT_N),
                   "scope" => array(tlInputParameter::STRING_N),
                   "countReq" => array(tlInputParameter::INT_N),
                   "expected_coverage" => array(tlInputParameter::INT_N),
                   "doAction" => array(tlInputParameter::STRING_N,0,20),
                   "itemSet" => array(tlInputParameter::ARRAY_INT),
                   "testcase_count" => array(tlInputParameter::ARRAY_INT),
                   "copy_testcase_assignment" => array(tlInputParameter::CB_BOOL),
                   "relation_id" => array(tlInputParameter::INT_N),
                   "relation_source_req_id" => array(tlInputParameter::INT_N),
                   "relation_type" => array(tlInputParameter::STRING_N),
                   "relation_destination_req_doc_id" => array(tlInputParameter::STRING_N,0,64),
                   "relation_destination_testproject_id" => array(tlInputParameter::INT_N),
                   "save_rev" => array(tlInputParameter::INT_N),
                   "do_save" => array(tlInputParameter::INT_N),
                   "log_message" => array(tlInputParameter::STRING_N),
                   "tcaseIdentity" => array(tlInputParameter::STRING_N),
                   "file_id" => array(tlInputParameter::INT_N),
                   "fileTitle" => array(tlInputParameter::STRING_N,0,100));

  $args = new stdClass();
  R_PARAMS($iParams,$args);
  $_REQUEST=strings_stripSlashes($_REQUEST);
    
  $args->user = $_SESSION['currentUser'];
  $args->req_id = $args->requirement_id;
  $args->title = $args->req_title;
  $args->arrReqIds = $args->req_id_cbox;

  $args->basehref = $_SESSION['basehref'];
  $args->tproject_id = isset($_SESSION['testprojectID']) ? intval($_SESSION['testprojectID']) : 0;
  if($args->tproject_id <= 0)
  {
    throw new Exception(__FILE__ . '::' . __FUNCTION__ . " Test project ID can not be <= 0 ");  
  }                                                                                        
  
  $mgr = new testproject($dbHandler);
  $info = $mgr->get_by_id($args->tproject_id);
  if(is_null($info))
  {
    throw new Exception(__FILE__ . '::' . __FUNCTION__ . " Unable to get test project data ");  
  }                                                                                        
  
  $args->tproject_name = $info['name'];
  $args->tcasePrefix = $info['prefix'];
  
  
  $args->user_id = isset($_SESSION['userID']) ? $_SESSION['userID'] : 0;
  
  if (!is_numeric($args->expected_coverage)) {
    $args->expected_coverage = 0;
  }
  
  $args->refreshTree = isset($_SESSION['setting_refresh_tree_on_action'])
                       ? $_SESSION['setting_refresh_tree_on_action'] : 0;
  
  $args->stay_here = isset($_REQUEST['stay_here']) ? 1 : 0;
  return $args;
}

/**
 * 
 *
 */
function renderGui(&$argsObj,$guiObj,$opObj,$templateCfg,$editorCfg,&$dbHandler)
{
  $smartyObj = new TLSmarty();
  $renderType = 'none';
  // @TODO document
  $actionOpe = array('create' => 'doCreate', 'edit' => 'doUpdate',
                     'doDelete' => '', 'doReorder' => '', 'reorder' => '',
                     'createTestCases' => 'doCreateTestCases',
                     'doCreateTestCases' => 'doCreateTestCases',
                     'doCreate' => 'doCreate', 'doUpdate' => 'doUpdate',
                     'copy' => 'doCopy', 'doCopy' => 'doCopy',
                     'doCreateVersion' => 'doCreateVersion','doCreateRevision' => 'doCreateRevision',
                     'doDeleteVersion' => '', 'doFreezeVersion' => 'doFreezeVersion',
                     'doAddRelation' => 'doAddRelation', 'doDeleteRelation' => 'doDeleteRelation',
                     'doUnfreezeVersion' => 'doUnfreezeVersion',
                     'fileUpload' => '', 'deleteFile' => '',
                     'startMonitoring' => '','stopMonitoring' => '');

  $owebEditor = web_editor('scope',$argsObj->basehref,$editorCfg) ;
  switch($argsObj->doAction) {
    case "edit":
    case "doCreate":
      $owebEditor->Value = $argsObj->scope;
    break;

    case "fileUpload":
    case "deleteFile":
    case "startMonitoring":
    case "stopMonitoring":
    break;

    default:
      if($opObj->suggest_revision || $opObj->prompt_for_log) 
      {
        $owebEditor->Value = $argsObj->scope;
      }
      else
      {
        $owebEditor->Value = getItemTemplateContents('requirement_template',$owebEditor->InstanceName, 
                                                     $argsObj->scope);
      }
    break;
  }

  $guiObj->askForRevision = $opObj->suggest_revision ? 1 : 0;
  $guiObj->askForLog = $opObj->prompt_for_log ? 1 : 0;


  $guiObj->scope = $owebEditor->CreateHTML();
  $guiObj->editorType = $editorCfg['type'];
  switch($argsObj->doAction) 
  {
    case "doDelete":
      $guiObj->refreshTree = 1; // has to be forced
    break;
  
    case "doCreate":
      $guiObj->refreshTree = $argsObj->refreshTree;
    break;
  
    case "doUpdate":
      // IMPORTANT NOTICE
      // we do not set tree refresh here, because on this situation
      // tree update has to be done when reqView page is called.
      // If we ask for tree refresh here we are going to do double refresh (useless and time consuming)
    break;
  }
  
  switch($argsObj->doAction)
  {
    case "addTestCase":
    case "edit":
    case "create":
    case "reorder":
    case "doDelete":
    case "doReorder":
    case "createTestCases":
    case "doCreateTestCases":
    case "doCreate":
    case "doFreezeVersion":
    case "doUnfreezeVersion":
    case "doUpdate":
    case "copy":
    case "doCopy":
    case "doCreateVersion":
    case "doDeleteVersion":
    case "doAddRelation":
    case "doDeleteRelation":
    case "doCreateRevision":
    case "removeTestCase":
    case "fileUpload":
    case "deleteFile":
    case "stopMonitoring":
    case "startMonitoring":
      $renderType = 'template';
      $key2loop = get_object_vars($opObj);
      foreach($key2loop as $key => $value)
      {
        $guiObj->$key = $value;
      }

      // exceptions
      $guiObj->askForRevision = $opObj->suggest_revision ? 1 : 0;
      $guiObj->askForLog = $opObj->prompt_for_log ? 1 : 0;
      $guiObj->operation = isset($actionOpe[$argsObj->doAction]) ? $actionOpe[$argsObj->doAction] : $argsObj->doAction;
          
      $tplDir = (!isset($opObj->template_dir)  || is_null($opObj->template_dir)) ? $templateCfg->template_dir : $opObj->template_dir;
      $tpl = is_null($opObj->template) ? $templateCfg->default_template : $opObj->template;

      $pos = strpos($tpl, '.php');
      if($pos === false) {
        $tpl = $tplDir . $tpl;      
      } else {
        $renderType = 'redirect';
        if (null != $guiObj->uploadOp && $guiObj->uploadOp->statusOK == false) {
          $tpl .= "&uploadOPStatusCode=" . $guiObj->uploadOp->statusCode;
        }
      } 
    break;
  }
    
  $req_mgr = new requirement_mgr($dbHandler);
  $guiObj->last_doc_id = $req_mgr->get_last_doc_id_for_testproject($argsObj->tproject_id);
  $guiObj->doAction = $argsObj->doAction;

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
    break;
  }
}

/**
 * Drop a non-scalar $_REQUEST['doAction'] before R_PARAMS() can choke on it.
 *
 * Refs #1739. PHP itself builds the array: ?doAction[]=x makes
 * $_REQUEST['doAction'] an array, and inputparameter.class.php:295 then calls
 * trim() on it -> uncaught TypeError. Removing the key here makes init_args()
 * read it as absent, which the action whitelist refuses with a 302. Nothing is
 * coerced or guessed: an array is not a verb.
 *
 * @return void
 */
function reqEditDropShapedAction()
{
  if (isset($_REQUEST['doAction']) && !is_scalar($_REQUEST['doAction']))
  {
    unset($_REQUEST['doAction']);
  }
}


/**
 * The set of doAction values this controller can render.
 *
 * This is deliberately the exact case list of renderGui()'s GUI-rendering switch
 * (reqEdit.php:198-221), which is the set the templates actually ask for:
 *   gui/templates/dashio/requirements/reqSpecView.tpl:25,40,43 -> create, reorder,
 *        createTestCases
 *   gui/templates/dashio/requirements/reqViewVersions.tpl:56,70,84,98,128,326 ->
 *        doDelete, doDeleteVersion, doFreezeVersion, doUnfreezeVersion,
 *        doDeleteRelation + the plain form POST (doCreate/doUpdate)
 *   gui/templates/dashio/requirements/reqReorder.tpl:17                 -> doReorder
 *   gui/templates/dashio/requirements/reqViewVersionsViewer.tpl:52,218  -> attachment ops
 *   gui/templates/dashio/requirements/reqEdit.tpl:250                   -> fileUpload/deleteFile
 * Keeping the two lists in sync is the invariant; adding a case to renderGui()'s
 * switch without adding it here degrades that action to a 302.
 *
 * Refs #1739.
 *
 * @return array<string,bool> whitelist used with isset()
 */
function reqEditActionWhitelist()
{
  return array(
    'addTestCase' => true,
    'edit' => true,
    'create' => true,
    'reorder' => true,
    'doDelete' => true,
    'doReorder' => true,
    'createTestCases' => true,
    'doCreateTestCases' => true,
    'doCreate' => true,
    'doFreezeVersion' => true,
    'doUnfreezeVersion' => true,
    'doUpdate' => true,
    'copy' => true,
    'doCopy' => true,
    'doCreateVersion' => true,
    'doDeleteVersion' => true,
    'doAddRelation' => true,
    'doDeleteRelation' => true,
    'doCreateRevision' => true,
    'removeTestCase' => true,
    'fileUpload' => true,
    'deleteFile' => true,
    'stopMonitoring' => true,
    'startMonitoring' => true,
  );
}


/**
 * Refuse a doAction this controller can not render.
 *
 * Refs #1739. Same remedy the sibling controller got in #1736
 * (lib/requirements/reqSpecEdit.php:reqSpecEditRefuseUnknownAction) and, before
 * it, #1627 / #1722 (lib/reqmgrsystems/reqMgrSystemEdit.php:87-103): no action
 * may answer a blank page or a silent 500, so the request is logged and the
 * user is bounced to the requirement-spec management screen with the
 * test-project context carried forward.
 *
 * The log message is a FIXED literal: the requested action is NOT interpolated.
 * The doAction value comes straight from the query string and is attacker
 * controlled, so logging it would (a) put unbounded text into the events table
 * and (b) make a crafted query string able to forge a log row. This is the
 * same reasoning as #1731.
 *
 * Level INFO, not ERROR: INFO does not persist below WARNING, so a crafted query
 * string can not write an Error/Warning row into the Event Viewer - measured
 * 0 rows for a request that used to write 4.
 *
 * @param mixed $pFn the refused doAction (string, null, or an array for ?doAction[]=x)
 * @return void
 */
function reqEditRefuseUnknownAction($pFn)
{
  $isKnown = is_string($pFn) && $pFn !== '';
  tLog('reqEdit.php: the requested doAction is not one this screen can render - ' .
       'refusing the request instead of rendering a blank page (Refs #1739). ' .
       ($isKnown ? '' : 'The doAction value was absent or not a usable string. '),
       'INFO');

  $base = isset($_SESSION['basehref']) ? $_SESSION['basehref'] : '/';
  $url = $base . 'gui/templates/requirements/reqSpecMgmt.html';

  // Carry the frame context forward, exactly like the legacy $basehref links did.
  $tprojectId = isset($_SESSION['testprojectID']) ? intval($_SESSION['testprojectID']) : 0;
  if ($tprojectId > 0)
  {
    $url .= '?tproject_id=' . $tprojectId;
  }

  header('Location: ' . $url, true, 302);
  exit();
}


/**
 * 
 *
 */
function initialize_gui(&$dbHandler,&$argsObj,&$commandMgr)
{
  $req_spec_mgr = new requirement_spec_mgr($dbHandler);

  // new dBug($argsObj);

  $gui = $commandMgr->initGuiBean();
  $gui->req_cfg = config_get('req_cfg');
  
  $gui->req_spec_id = $argsObj->req_spec_id;
  if ($argsObj->req_spec_id)
  {
    $gui->requirements_count = $req_spec_mgr->get_requirements_count($gui->req_spec_id);
    $gui->req_spec = $req_spec_mgr->get_by_id($gui->req_spec_id);
  }
  $gui->user_feedback = null;
  $gui->main_descr = lang_get('req_spec_short');
  if (isset($gui->req_spec))
  {
     $gui->main_descr .= config_get('gui_title_separator_1') . $gui->req_spec['title'];
  }
  $gui->action_descr = null;
  
  $gui->grants = new stdClass();
  $gui->grants->req_mgmt = $argsObj->user->hasRightOnProj($dbHandler,"mgt_modify_req");
  $gui->grants->mgt_view_events = $argsObj->user->hasRightOnProj($dbHandler,"mgt_view_events");
  
  $gui->req_version_id = $argsObj->req_version_id;
  $gui->preSelectedType = TL_REQ_TYPE_USE_CASE;
  
  $gui->stay_here = $argsObj->stay_here;
  
  return $gui;
}

/**
 *
 */
function checkRights(&$db,&$user,&$context)
{
  $context->rightsOr = [];
  $context->rightsAnd = ["mgt_view_req","mgt_modify_req"];
  pageAccessCheck($db, $user, $context);
}