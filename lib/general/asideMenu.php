<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * @filesource  asideMenu.php
 *
 * Renders the left side menu for its own frame in main.tpl.
 *
 * The menu used to be drawn by each content page, so it disappeared on every
 * page that did not include it - all of the two pane work areas among them.
 * Serving it here keeps it on screen whatever the main frame shows.
 *
**/
require_once('../../config.inc.php');
require_once("common.php");
testlinkInitPage($db,('initProject' == 'initProject'));

// The menu needs showMenu, activeMenu, uri, logo, whoami, access and
// menuGrants. TLSmarty::addMenuContext() fills all of them from initUserEnv()
// for any page that has not built them itself, which is the case here, so an
// empty object is all that has to be handed over.
$gui = new stdClass();

// aside.tpl checks $gui->hasKeywords to show/hide keyword assignment.
// The old single-frame layout computed this in mainPage.php; since the
// sidebar now has its own frame we need it here too.
$tprojectID = isset($_SESSION['testprojectID']) ? intval($_SESSION['testprojectID']) : 0;
$gui->hasKeywords = false;
if($tprojectID > 0) {
  $tproject_mgr = new testproject($db);
  $gui->hasKeywords = $tproject_mgr->hasKeywords($tprojectID);
}

// Plugins can inject links via these four events (aside.tpl groups them all
// into one "Plugins" section) - mainPage.php used to compute this for the old
// single-frame layout, but that code went dead once the menu got its own
// frame (issue #437) since mainPage.tpl no longer includes aside.tpl.
// Recompute it here instead. tl-classic spreads LEFTMENU_*/RIGHTMENU_* across
// two separate page columns that dashio's single-rail sidebar has no
// equivalent of, so they're merged into one list rather than dropping half
// of them like the previous version of this file did.
$gui->plugins = array();
foreach(array('EVENT_LEFTMENU_TOP','EVENT_LEFTMENU_BOTTOM',
              'EVENT_RIGHTMENU_TOP','EVENT_RIGHTMENU_BOTTOM') as $menu_item) {
  $menu_content = event_signal($menu_item);
  if (!empty($menu_content)) {
    $gui->plugins[$menu_item] = $menu_content;
  }
}

$smarty = new TLSmarty();
$smarty->assign('gui',$gui);

// Render collapsed from the start when it was left that way, instead of
// painting the menu at full width and then snapping it shut.
$smarty->assign('railed',menuRailIsOn());
$smarty->assign('menuRailCookie',menuRailCookieName());

$smarty->display('asideFrame.tpl');
