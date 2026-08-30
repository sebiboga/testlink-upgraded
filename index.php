<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/ 
 * This script is distributed under the GNU General Public License 2 or later. 
 *
 * @filesource  index.php
 * @package     TestLink
 * @copyright   2006-2017, TestLink community
 * @link        http://www.testlink.org
 *
 *
**/
require_once('lib/functions/configCheck.php');
checkConfiguration();
require_once('config.inc.php');
require_once('common.php');
doSessionStart();

// will be very interesting understand why we do this
unset($_SESSION['basehref']);  
setPaths();
list($args,$gui) = initEnv();

// verify the session during a work
$redir2login = true;
if( isset($_SESSION['currentUser']) ) {
  // Session exists we need to do other checks.
  // we use/copy Mantisbt approach
  $securityCookie = tlUser::auth_get_current_user_cookie();
  $redir2login = is_null($securityCookie);

  if(!$redir2login) {
    // need to get fresh info from db, before asking for securityCookie
    doDBConnect($db,database::ONERROREXIT);
    $user = new tlUser();
    $user->dbID = $_SESSION['currentUser']->dbID;
    $user->readFromDB($db);
    $dbSecurityCookie = $user->getSecurityCookie();
    $redir2login = ( $securityCookie != $dbSecurityCookie );
  } 
}

if($redir2login) {
  // destroy user in session as security measure
  unset($_SESSION['currentUser']);

  // If session does not exists I think is better in order to
  // manage other type of authentication method/schemas
  // to understand that this is a sort of FIRST Access.
  //
  // When TL undertand that session exists but has expired
  // is OK to call login with expired indication, but is not this case
  //
  // Dev Notes:
  // may be we are going to login.php and it will call us again!
  $urlo = TL_BASE_HREF . "login.php" . ($args->ssodisable ? '?ssodisable' : '');
  redirect($urlo);
  exit;
}


// We arrive to these lines only if we are logged in
//
// The frameset must commit the requested test project to the session before
// the iframes load: titlebar and mainframe issue independent requests, so a
// project chosen here would otherwise be seen by only one of them.
initProject($db,$_REQUEST);

$tplEngine = new TLSmarty();
$tplEngine->assign('gui', $gui);
$tplEngine->display('main.tpl');


/**
 * Work area to show after a test project switch.
 *
 * The test project selector targets _top, so choosing a project rebuilds the
 * whole frameset here and the user would always land on the main page. Send
 * them back to the work area they were in instead.
 *
 * Only features that depend on the test project alone are accepted. The ones
 * getActions() guards behind a test plan reference the previous project's test
 * plan, so they fall back to the main page, as does anything unknown: the
 * feature is never echoed into the URL, only matched against this list.
 */
function getReturnWorkArea($feature) {
  // Refs #780: the Dashboard (home / main page) is modernized as a standalone
  // Dashio HTML screen backed by the api/mainpage BFF. lib/general/mainPage.php
  // remains for any deep link that still points at it, but the post-login /
  // post-project-change landing now renders the modernized screen.
  $mainPage = 'gui/templates/mainpage/mainPage.html';

  if (!is_string($feature)) {
    return $mainPage;
  }

  $tprojectScoped = array('editTc','keywordsAssign','assignReqs',
                          'reqSpecMgmt','printReqSpec',
                          'searchReq','searchReqSpec');

  return in_array($feature,$tprojectScoped,true)
         ? 'lib/general/frmWorkArea.php?feature=' . $feature
         : $mainPage;
}

/**
 *
 *
 */
function initEnv() {
  $iParams = array("reqURI" => array(tlInputParameter::STRING_N,0,4000));
  $pParams = G_PARAMS($iParams);
  
  $args = new stdClass();
  $args->ssodisable = getSSODisable();

  // CWE-79: 
  // Improper Neutralization of Input 
  // During Web Page Generation ('Cross-site Scripting')
  // 
  // https://cxsecurity.com/issue/WLB-2019110139
  $args->reqURI = '';
  if ($pParams["reqURI"] != '') {
    $args->reqURI = $pParams["reqURI"];

    // CWE-79: sanitize reqURI — allowlist safe path prefixes,
    // reject any scheme-like pattern (contains ":" or "@")
    if (preg_match('/[:@\\\\]/', $args->reqURI)
        || !preg_match('#^(lib/|gui/)#i', $args->reqURI)) {
      $args->reqURI = null;
    }
  }
  if (null == $args->reqURI) {
    // read from _REQUEST as tproject_id below: the test project selector
    // posts, and G_PARAMS() only looks at GET
    $args->reqURI = getReturnWorkArea(isset($_REQUEST['returnFeature'])
                                      ? $_REQUEST['returnFeature'] : null);
  }
  $args->reqURI = $_SESSION['basehref'] . $args->reqURI;



  $args->tproject_id = isset($_REQUEST['tproject_id']) ? intval($_REQUEST['tproject_id']) : 0;
  $args->tplan_id = isset($_REQUEST['tplan_id']) ? intval($_REQUEST['tplan_id']) : 0;

  $gui = new stdClass();
  $gui->title = lang_get('main_page_title');
  $gui->mainframe = htmlspecialchars($args->reqURI, ENT_QUOTES, 'UTF-8');
  $gui->asideframe = 'lib/general/asideMenu.php';
  $gui->asideRailed = menuRailIsOn();
  $gui->navbar_height = config_get('navbar_height');

  $sso = ($args->ssodisable ? '&ssodisable' : '');
  $gui->titleframe = "lib/general/navBar.php?" . 
                     "tproject_id={$args->tproject_id}&" .
                     "tplan_id={$args->tplan_id}&" .
                     "updateMainPage=1" . $sso;
  $gui->logout = 'logout.php?viewer=' . $sso;

  return array($args,$gui);
}