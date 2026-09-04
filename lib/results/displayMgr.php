<?php
/** 
 * TestLink Open Source Project - http://testlink.sourceforge.net/ 
 * This script is distributed under the GNU General Public License 2 or later.
 *  
 * 
 *
 */
require_once('email_api.php');
require_once('reports.cfg.php');

/**
 *
 *
 */
function initArgsForReports(&$dbHandler) {
  $tplanMgr = null;
  $iParams = 
    array("apikey" => array(tlInputParameter::STRING_N,32,64),
          "tproject_id" => array(tlInputParameter::INT_N), 
          "tplan_id" => array(tlInputParameter::INT_N),
          "format" => array(tlInputParameter::INT_N),
          "type" => array(tlInputParameter::STRING_N,0,1),
          "sendByMail" => array(tlInputParameter::INT_N),
          "spreadsheet" => array(tlInputParameter::INT_N),
          "doAction" => array(tlInputParameter::STRING_N,5,15),
          "platSet" => array(tlInputParameter::ARRAY_INT),
          "build_set" => array(tlInputParameter::ARRAY_INT),
          "buildListForExcel" => array(tlInputParameter::STRING_N,0,100));

  $args = new stdClass();
  R_PARAMS($iParams,$args);

  $rCfg = config_get('results');
  $args->statusCode = $rCfg['status_code'];

  $args->spreadsheet = intval($args->spreadsheet);
  $args->accessType = 'gui';
  $args->addOpAccess = true;

  $args->getSpreadsheetBy = 
    isset($_REQUEST['sendSpreadSheetByMail_x']) ? 'email' : null;

  if( is_null($args->getSpreadsheetBy) ) {
    $args->getSpreadsheetBy = isset($_REQUEST['exportSpreadSheet_x']) ? 
                              'download' : null;
  }  

  if( !is_null($args->apikey) ) {
    $cerbero = new stdClass();
    $cerbero->args = new stdClass();
    $cerbero->args->tproject_id = $args->tproject_id;
    $cerbero->args->tplan_id = $args->tplan_id;
    
    if(strlen($args->apikey) == 32) {
      $cerbero->args->getAccessAttr = true;
      $cerbero->method = 'checkRights';
      $cerbero->redirect_target = "../../login.php?note=logout";
      $args->accessType = 'remote';
      setUpEnvForRemoteAccess($dbHandler,$args->apikey,$cerbero);
    } else {
      $args->addOpAccess = false;
      $cerbero->method = null;
      $args->accessType = 'anonymous';
      setUpEnvForAnonymousAccess($dbHandler,$args->apikey,$cerbero);
    }  
  } else {
    testlinkInitPage($dbHandler,true,false,"checkRights");

    $tplanMgr = new testplan($dbHandler);
    $tplan = $tplanMgr->get_by_id($args->tplan_id);
    if (is_null($tplan)) {
      // unknown/stale tplan_id on the report URL: stop gracefully instead of
      // dereferencing null (E_WARNING) and dying on the uncaught exception below
      require_once(__DIR__ . '/../functions/info.inc.php');
      displayInfo(lang_get('error_print_doc_title'),
                  lang_get('error_print_doc_missing_testplan'));
    }
    $args->tproject_id = $tplan['testproject_id'];
  }

  if ($args->tproject_id <= 0) {
    $msg = __FILE__ . '::' . __FUNCTION__ . " :: Invalid Test Project ID ({$args->tproject_id})";
    throw new Exception($msg);
  }

  if (is_null($args->format)) {
    tlog("Parameter 'format' is not defined — defaulting to FORMAT_HTML", 'WARNING');
    $args->format = FORMAT_HTML;
  }

  switch ($args->format) {
    case FORMAT_XLS:
      if($args->buildListForExcel != '') {  
        $args->build_set = explode(',',$args->buildListForExcel);
      }  
    break;
  }  
  
  $args->format = $args->sendByMail ? FORMAT_MAIL_HTML : $args->format;

  $args->user = $_SESSION['currentUser'];
  $args->basehref = $_SESSION['basehref'];

  return array($tplanMgr,$args);
}


/**
 * 
 *
 */
function generateHtmlEmail(&$smarty, $template_file, $mailCfg) {
  // same objet that is returned by email_send
  $op = new stdClass();
  $op->status_ok = true;
  $op->msg = 'ok';
  
  $html_report = $smarty->fetch($template_file);
  if( ! property_exists($mailCfg,'from') ) {
    $mailCfg->from = $_SESSION['currentUser']->emailAddress;
  }

  if( ! property_exists($mailCfg,'to') ) {
    $mailCfg->to = $mailCfg->from;
  }
  
  if($mailCfg->to == ""){
    $op->status_ok = false;
    $op->msg = lang_get("error_sendreport_no_email_credentials");
  } else {
    // Link to test case is still raw link (no title) in email(HTML) type of test report
    $op = email_send( $mailCfg->from, $mailCfg->to, $mailCfg->subject, 
                      $html_report, $mailCfg->cc, null,false,true,
                      array('strip_email_links' => false));

    if($op->status_ok) {
      $op->msg = sprintf(lang_get('mail_sent_to'), $mailCfg->to);
    }
  }
  return $op;
}


/**
 * 
 *
 */
function displayReport($template_file, &$smarty, $doc_format, $mailCfg = null)
{

  $doc_format = intval($doc_format);
  switch($doc_format)
  {
    case FORMAT_HTML:
    case FORMAT_ODT:
    case FORMAT_ODS:
    case FORMAT_XLS:
    case FORMAT_MSWORD:
    case FORMAT_PDF:
      flushHttpHeader($doc_format, $doc_kind = 0);
    break;  

    case FORMAT_MAIL_HTML:
      $op = generateHtmlEmail($smarty, $template_file,  $mailCfg);
      
      switch($template_file)
      {
        case 'results/resultsGeneral.tpl'; 
         flushHttpHeader(FORMAT_HTML, $doc_kind = 0);
         $mf = new stdClass();
         $mf->msg = $op->status_ok ? '' : lang_get('send_mail_ko');
         $mf->msg .= ' ' . $op->msg;
         $mf->title = ''; //$mailCfg->subject;
         $smarty->assign('mailFeedBack',$mf);
        break;   

        default:
          $message = $op->status_ok ? '' : lang_get('send_mail_ko');  
          $smarty = new TLSmarty();
          $smarty->assign('message', $message . ' ' . $op->msg);
          $smarty->assign('title', $mailCfg->subject);
          $template_file = "emailSent.tpl";
        break;   
      }
    break;
  } 

  $smarty->display($template_file);
}


/**
 * Generate HTML header and send it to browser
 * @param string $format identifier of document format; value must be in $tlCfg->reports_formats
 * @param integer $doc_kind Magic number of document kind; see consts.inc.php for list 
 *    (for example: DOC_TEST_PLAN_DESIGN)
 * @author havlatm
 */
function flushHttpHeader($format, $doc_kind = 0)
{
  $file_extensions = config_get('reports_file_extension');
  $reports_applications = config_get('reports_applications');

  switch($doc_kind) {
    case DOC_TEST_SPEC: 
      $kind_acronym = '_test_spec'; 
    break;
    
    case DOC_TEST_PLAN_DESIGN: 
      $kind_acronym = '_test_plan'; 
    break;

    case DOC_TEST_PLAN_EXECUTION: 
      $kind_acronym = '_test_report';
    break;
    
    case DOC_REQ_SPEC: 
      $kind_acronym = '_req_spec'; 
    break;
    
    default: 
      $kind_acronym = '';
    break;  
  }
  

  if ($format == FORMAT_MAIL_HTML) {
    tLog('flushHttpHeader> Invalid format: '.$format, 'ERROR');
  }
  
  $filename = isset($_SESSION['testprojectPrefix']) ? $_SESSION['testprojectPrefix'] : '';
  $extension = $file_extensions[$format] ?? 'html';
  $filename .= $kind_acronym . '-' . date('Y-m-d') . '.' . $extension;
  tLog('Flush HTTP header for '.$format); 

  
  $contentType = isset($reports_applications[$format]) ? $reports_applications[$format] : 'text/html';
  $contentType .= (is_null($format) || $format=='') ? '' : ("; name='Testlink_" . $format ."'") ;
  header("Content-type: {$contentType}");
  header("Content-Description: TestLink - Generated Document (see " . __FUNCTION__ . ")" );
  if( (!is_null($format) && $format != '') && $format != FORMAT_HTML )
  {
    header("Content-Disposition: attachment; filename=$filename");
  }  
  flush();
}

/**
 * Default the statistics sections consumed by report XLS exporters.
 *
 * The createSpreadsheet() implementations bind their data sources by
 * reference (e.g. $gui->statistics->priorities). When a section was not
 * computed for the current report configuration (priority/keywords
 * disabled, no platforms, empty plan) the property does not exist and the
 * reference silently becomes null, fatalling count()/foreach() later on.
 */
function xlsDefaultStatsSections(&$guiObj)
{
  $sections = array('platform','overallBuildStatus','overalBuildStatus',
                    'buildByPlatMetrics','testsuites','priorities',
                    'keywords','testers','milestones');

  if (!isset($guiObj->statistics) || !is_object($guiObj->statistics)) {
    // some controllers build statistics as a plain array for the HTML
    // template; the spreadsheet exporter only consumes object sections.
    $guiObj->statistics = new stdClass();
  }

  foreach ($sections as $section) {
    if (!isset($guiObj->statistics->$section) ||
        is_scalar($guiObj->statistics->$section)) {
      $guiObj->statistics->$section = array();
    }
  }
}

/**
 * Convert legacy PHPExcel-flavoured style arrays into PhpSpreadsheet keys.
 *
 * PhpSpreadsheet silently ignores 'style', 'outline', 'vertical', 'type'
 * and 'startcolor', so exported sheets lost their borders and fills after
 * the PHPExcel -> PhpSpreadsheet switch. 'outline' is expanded to the four
 * explicit edges; inside-only separators ('vertical'/'horizontal') have no
 * range equivalent and are dropped.
 */
function xlsNormalizeLegacyStyles(array $styles)
{
  foreach ($styles as $key => $s) {
    if (!is_array($s)) {
      continue;
    }

    if (isset($s['fill']) && is_array($s['fill'])) {
      if (array_key_exists('type', $s['fill']) &&
          !array_key_exists('fillType', $s['fill'])) {
        $s['fill']['fillType'] = $s['fill']['type'];
      }
      unset($s['fill']['type']);

      if (array_key_exists('startcolor', $s['fill']) &&
          !array_key_exists('startColor', $s['fill'])) {
        $s['fill']['startColor'] = $s['fill']['startcolor'];
      }
      unset($s['fill']['startcolor']);
    }

    if (isset($s['borders']) && is_array($s['borders'])) {
      $borders = array();
      foreach ($s['borders'] as $edge => $cfg) {
        if (!is_array($cfg)) {
          continue;
        }
        if (array_key_exists('style', $cfg)) {
          $cfg = array('borderStyle' => $cfg['style']);
        }
        switch ($edge) {
          case 'outline':
            foreach (array('left','right','top','bottom') as $side) {
              if (!isset($borders[$side])) {
                $borders[$side] = $cfg;
              }
            }
            break;

          case 'vertical':
          case 'horizontal':
            break;

          default:
            $borders[$edge] = $cfg;
        }
      }
      $s['borders'] = $borders;
    }

    $styles[$key] = $s;
  }

  return $styles;
}
