<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 *
 * @filesource  reqSpecEdit.php
 * @author      Martin Havlat
 *
 * View existing and create a new req. specification.
 *
 */
require_once("../../config.inc.php");
require_once("common.php");
require_once('requirements.inc.php');
require_once("web_editor.php");
$editorCfg = getWebEditorCfg('requirement_spec');
require_once(require_web_editor($editorCfg['type']));
$req_cfg = config_get('req_cfg');

testlinkInitPage($db,false,false);

$templateCfg = templateConfiguration();

// Refs #1736: ?doAction[]=x (or ?doAction[a]=1&doAction[b]=2) makes PHP fill
// $_REQUEST['doAction'] with an ARRAY. init_args() declares doAction as
// tlInputParameter::STRING_N (line 57), and inputparameter.class.php:295 calls
// trim($value) on whatever it read - trim() does not accept an array, so the
// request died with an uncaught TypeError ("trim(): Argument #1 ($string) must
// be of type string, array given") BEFORE any of this screen's own code ran:
// HTTP 500, 0-byte body, ZERO Event Viewer rows.
//
// Drop the array before R_PARAMS can see it, so init_args() reads it as absent
// and the whitelist check below refuses it with the same graceful 302 as any
// other unusable action. The same local-shim idiom as #1731 in
// lib/reqmgrsystems/reqMgrSystemEdit.php:78-92 (shimReqScalar), rather than a
// change to the shared input-parameter layer: inputparameter.class.php:295
// trims unconditionally for every STRING_N parameter of every controller, so
// fixing it there is a separate, much wider change - filed as its own issue.
reqSpecEditDropShapedAction();

$args = init_args();
$commandMgr = new reqSpecCommands($db,$args->tproject_id);
$gui = initialize_gui($db,$args,$req_cfg,$commandMgr);

$context = new stdClass();
$context->tproject_id = $args->tproject_id;
checkRights($db,$args->user,$context);


$auditContext = new stdClass();
$auditContext->tproject = $args->tproject_name;
$commandMgr->setAuditContext($auditContext);

$pFn = $args->doAction;
$op = null;

// Refs #1736: method_exists() is NOT a whitelist of requestable actions. It also
// matches the internal helpers of reqSpecCommands (24 method_exists-visible members,
// only 18 of them GUI actions), and the dispatch below calls whatever it matched with
// a FIXED 2-argument signature:
//   - simpleCompare (reqParams=4), process_revision (3)  -> ArgumentCountError -> HTTP 500, 0 bytes
//   - initGuiObjForAttachmentOperations (PRIVATE, 1)      -> "Call to private method" Error -> HTTP 500
//   - initGuiBean (0), getReqMgrSystem (0)                -> not a GUI bean -> HTTP 500 / bogus result
//   - setAuditContext (1)                                 -> returns null -> $op null
// i.e. an unrecognised doAction produced FIVE different outcomes: a silent 500 with a
// 0-byte body and ZERO Event Viewer rows (worse than a blank page: nothing to grep),
// or the 28-byte 'Can not process RENDERING!!!' stub accompanied by 3 E_WARNING rows
// from renderGui()'s default: branch (reqSpecEdit.php:147).
//
// Consult the real whitelist instead: only a name renderGui() can actually dispatch is
// invoked, so arity and visibility are guaranteed to match by construction, and the
// array-shaped ?doAction[]=x (which made method_exists() throw a TypeError -> HTTP 500)
// never reaches the check at all, because $pFn is then an array and fails the is_string()
// test. Everything refused below is bounced with a 302, never answered blank.
if(is_string($pFn) && $pFn !== '' && isset(reqSpecEditActionWhitelist()[$pFn]))
{
  $op = $commandMgr->$pFn($args,$_REQUEST);
}
else
{
  reqSpecEditRefuseUnknownAction($pFn);
  // unreachable: reqSpecEditRefuseUnknownAction() always redirects and exits.
}

renderGui($args,$gui,$op,$templateCfg,$editorCfg);


/**
 * Drop a non-scalar $_REQUEST['doAction'] before R_PARAMS() can choke on it.
 *
 * Refs #1736. PHP itself builds the array: ?doAction[]=x makes
 * $_REQUEST['doAction'] an array, and inputparameter.class.php:295 then calls
 * trim() on it -> uncaught TypeError -> HTTP 500 with a 0-byte body and no
 * Event Viewer row. Removing the key here makes init_args() read it as absent,
 * which the action whitelist refuses with a 302. Nothing is coerced or guessed:
 * an array is not a verb.
 *
 * @return void
 */
function reqSpecEditDropShapedAction()
{
  if (isset($_REQUEST['doAction']) && !is_scalar($_REQUEST['doAction'])) {
    unset($_REQUEST['doAction']);
  }
}


/**
 * The set of doAction values this controller can render.
 *
 * This is deliberately the exact case list of renderGui()'s GUI-rendering switch
 * (reqSpecEdit.php:207-224), which is the set the templates actually ask for:
 *   lib/functions/requirement_spec_mgr.class.php:2560 -> fileUpload
 *   lib/functions/requirement_spec_mgr.class.php:2573 -> deleteFile
 *   gui/templates/dashio/requirements/reqSpecView.tpl:46-58 -> createChild,
 *        copyRequirements, copy, bulkReqMon
 *   gui/templates/dashio/requirements/include/reqSpecViewJS.inc.tpl:7,42 -> doDelete, doFreeze
 *   gui/templates/dashio/requirements/reqSpecReorder.tpl:13 -> doReorder
 *   gui/templates/dashio/requirements/project_req_spec_mgmt.tpl:11,14 -> create, reorder
 *   gui/templates/dashio/requirements/reqSpecCopy.tpl:34 -> doCopy
 * Keeping the two lists in sync is the invariant; adding a case to renderGui()'s switch
 * without adding it here degrades that action to a 302.
 *
 * Refs #1736.
 *
 * @return array<string,bool> whitelist used with isset()
 */
function reqSpecEditActionWhitelist()
{
  return array(
    'edit' => true,
    'create' => true,
    'createChild' => true,
    'reorder' => true,
    'doDelete' => true,
    'doReorder' => true,
    'doCreate' => true,
    'doUpdate' => true,
    'copyRequirements' => true,
    'doCopyRequirements' => true,
    'copy' => true,
    'doCopy' => true,
    'doFreeze' => true,
    'doCreateRevision' => true,
    'fileUpload' => true,
    'deleteFile' => true,
    'bulkReqMon' => true,
    'doBulkReqMon' => true,
  );
}


/**
 * Refuse a doAction this controller can not render.
 *
 * Refs #1736. Same remedy the sibling controller got in #1627 / #1722
 * (lib/reqmgrsystems/reqMgrSystemEdit.php:87-103): no action may answer a blank page
 * (28 bytes) or a silent 500, so the request is logged and the user is bounced to the
 * requirement-spec management screen with the test-project context carried forward.
 *
 * The log message is a FIXED literal: the requested action is NOT interpolated. The
 * doAction value comes straight from the query string and is attacker controlled, so
 * logging it would (a) put unbounded text into the events table and (b) make a crafted
 * query string able to forge a log row. This is the same reasoning as #1731.
 *
 * Level INFO, not ERROR: INFO does not persist below WARNING, so a crafted query string
 * can not write an Error/Warning row into the Event Viewer - measured 0 rows of any
 * level for a request that used to write 3.
 *
 * @param mixed $pFn the refused doAction (string, null, or an array for ?doAction[]=x)
 * @return void
 */
function reqSpecEditRefuseUnknownAction($pFn)
{
  $isKnown = is_string($pFn) && $pFn !== '';
  tLog('reqSpecEdit.php: the requested doAction is not one this screen can render - ' .
       'refusing the request instead of rendering a blank page (Refs #1736). ' .
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
function init_args()
{
  $args = new stdClass();

  $iParams = array("countReq" => array(tlInputParameter::INT_N,99999),
                   "req_spec_id" => array(tlInputParameter::INT_N),
                   "req_spec_revision_id" => array(tlInputParameter::INT_N),
                   "parentID" => array(tlInputParameter::INT_N),
                   "doAction" => array(tlInputParameter::STRING_N,0,250),
                   "title" => array(tlInputParameter::STRING_N,0,100),
                   "scope" => array(tlInputParameter::STRING_N),
                   "doc_id" => array(tlInputParameter::STRING_N,1,32),
                   "nodes_order" => array(tlInputParameter::ARRAY_INT),
                   "containerID" => array(tlInputParameter::INT_N),
                   "itemSet" => array(tlInputParameter::ARRAY_INT),
                   "reqSpecType" => array(tlInputParameter::STRING_N,0,1),
                   "copy_testcase_assignment" => array(tlInputParameter::CB_BOOL),
                   "save_rev" => array(tlInputParameter::INT_N),
                   "do_save" => array(tlInputParameter::INT_N),
                   "log_message" => array(tlInputParameter::STRING_N),
                   "file_id" => array(tlInputParameter::INT_N),
                   "fileTitle" => array(tlInputParameter::STRING_N,0,100));

  $args = new stdClass();
  R_PARAMS($iParams,$args);

  // i guess due to required revison log it is necessary to strip slashes
  // after R_PARAMS call - at least this fixed the problem
  $_REQUEST=strings_stripSlashes($_REQUEST);
  
  $args->tproject_id = isset($_SESSION['testprojectID']) ? $_SESSION['testprojectID'] : 0;
  $args->tproject_name = isset($_SESSION['testprojectName']) ? $_SESSION['testprojectName'] : "";
  $args->tplan_id = isset($_SESSION['testplanID']) ? intval($_SESSION['testplanID']) : null;
  $args->user_id = isset($_SESSION['userID']) ? $_SESSION['userID'] : 0;
  $args->basehref = $_SESSION['basehref'];
  
  $args->parentID = is_null($args->parentID) ? $args->tproject_id : $args->parentID;

  $args->refreshTree = isset($_SESSION['setting_refresh_tree_on_action'])
                       ? $_SESSION['setting_refresh_tree_on_action'] : 0;
  
  $args->countReq = is_null($args->countReq) ? 0 : intval($args->countReq);
  
  // Process buttons
  $args->op = null;
  $btnSet = array('toogleMon','startMon','stopMon');
  foreach( $btnSet as $btn )
  {
    if( isset($_REQUEST[$btn]) )
    {
      $args->op = $btn;
      break;
    }  
  }  
  
  $args->user = $_SESSION['currentUser'];

  return $args;
}


/**
 * renderGui
 *
 */
function renderGui(&$argsObj,$guiObj,$opObj,$templateCfg,$editorCfg)
{
  $smartyObj = new TLSmarty();
  $renderType = 'none';
  $tpl = $tpd = null;

  $actionOperation = array('create' => 'doCreate', 'edit' => 'doUpdate',
                           'doDelete' => '', 'doReorder' => '', 'reorder' => '',
                           'doCreate' => 'doCreate', 'doUpdate' => 'doUpdate',
                           'createChild' => 'doCreate', 'copy' => 'doCopy',
                           'doCopy' => 'doCopy',
                           'doFreeze' => 'doFreeze',
                           'copyRequirements' => 'doCopyRequirements',
                           'doCopyRequirements' => 'doCopyRequirements',
                           'doCreateRevision' => 'doCreateRevision',
                           'fileUpload' => '', 'deleteFile' => '',
                           'bulkReqMon' => 'doBulkReqMon',
                           'doBulkReqMon' => 'doBulkReqMon');
  // ------------------------------------------------------------------------------------------------
  // Web Editor Processing
  $owebEditor = web_editor('scope',$argsObj->basehref,$editorCfg) ;
  switch($argsObj->doAction)
  {
    case "edit":
    case "doCreate":
      $owebEditor->Value = $argsObj->scope;
    break;

    case "fileUpload":
    case "deleteFile":
    break;

    default:
      // Refs #1736: $opObj is the command RESULT, not the gui bean, and it is null
      // whenever the doAction was not a reqSpecCommands method. Reading three
      // properties off it cost 3 E_WARNING rows per request (persisted to `events`
      // as log_level=2, all attributed to this line). The dispatch in the controller
      // now consults a real whitelist so $opObj is never null from the URL - this
      // guard is defence in depth, so no future caller can reintroduce the warnings.
      // A null bean means "the command bean has nothing to contribute", which is
      // exactly the condition the true-branch below already handles: keep the
      // user-supplied scope as-is instead of pre-filling a template.
      if(is_null($opObj) || $opObj->askForRevision || $opObj->askForLog || !$opObj->action_status_ok)
      {
        $owebEditor->Value = $argsObj->scope;
      }
      else
      {
        $owebEditor->Value = getItemTemplateContents('req_spec_template',$owebEditor->InstanceName,$argsObj->scope);
      }                        
    break;
  }
  $guiObj->scope = $owebEditor->CreateHTML();
  $guiObj->editorType = $editorCfg['type'];  

  // Tree refresh Processing
  switch($argsObj->doAction)
  {
    case "doCreate":
    case "doUpdate": 
    case "doCopyRequirements":
    case "doCopy":
    case "doFreeze":
    case "doDelete":
    case "doBulkReqMon":
      $guiObj->refreshTree = $argsObj->refreshTree;
    break;
  }

  // GUI rendering Processing
  switch($argsObj->doAction)
  {
    case "edit":
    case "create":
    case "createChild":
    case "reorder":
    case "doDelete":
    case "doReorder":
    case "doCreate":
    case "doUpdate":
    case "copyRequirements":
    case "doCopyRequirements":
    case "copy":
    case "doCopy":
    case "doFreeze":
    case "doCreateRevision":
    case "fileUpload":
    case "deleteFile":
    case "bulkReqMon":
    case "doBulkReqMon":
      $renderType = 'template';
      $key2loop = get_object_vars($opObj);
            
      if($opObj->action_status_ok == false)
      {
        // Remember that scope normally is a WebRichEditor, and that
        // we have already processed WebRichEditor
        // Need to understand if remove of scope key can be done always
        // no matter action_status_ok
        unset($key2loop['scope']);
      }
      // reqSpecEdit.tpl (and reqBulkMon.tpl) render these two hidden fields, but
      // no reqSpecCommands method declares them on the GUI bean, so they must
      // come from the session args. Set BEFORE the copy loop, as a default: a
      // command object that owns the key (reorder, doReorder, bulkReqMon) still
      // overwrites it with its own value.
      $guiObj->tproject_id = $argsObj->tproject_id;
      $guiObj->tplan_id = $argsObj->tplan_id;

      foreach($key2loop as $key => $value)
      {
        $guiObj->$key = $value;
      }
      
      $guiObj->operation = $actionOperation[$argsObj->doAction];
      $tpl = is_null($opObj->template) ? $templateCfg->default_template : $opObj->template;
      $tpd = isset($key2loop['template_dir']) ? $opObj->template_dir : $templateCfg->template_dir;

      $pos = strpos($tpl, '.php');
      if ($pos === false) {
        $tpl = $tpd . $tpl;
      } else {
        $renderType = 'redirect'; 
        if (null != $guiObj->uploadOp && $guiObj->uploadOp->statusOK == false) {
          $tpl .= "&uploadOPStatusCode=" . $guiObj->uploadOp->statusCode;
        }
      }
    break;
  }
    
  switch($renderType)
  {
    case 'template':
      $smartyObj->assign('mgt_view_events',has_rights($db,"mgt_view_events"));
      $smartyObj->assign('gui',$guiObj);
      $smartyObj->display($tpl);
    break;  
 
    case 'redirect':
      header("Location: {$tpl}");
      exit();
    break;

    default:
      echo 'Can not process RENDERING!!!';
    break;
  }
}

/**
 * 
 *
 */
function initialize_gui(&$dbHandler, &$argsObj, &$req_cfg, &$commandMgr)
{
  $gui = $commandMgr->initGuiBean();
  $gui->parentID = $argsObj->parentID;
  $gui->user_feedback = null;
  $gui->main_descr = null;
  $gui->action_descr = null;
  $gui->refreshTree = 0;
  $gui->external_req_management = ($req_cfg->external_req_management == ENABLED) ? 1 : 0;
  $gui->grants = new stdClass();
  $gui->grants->req_mgmt = has_rights($dbHandler,"mgt_modify_req");

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