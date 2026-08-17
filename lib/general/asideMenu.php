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
$smarty = new TLSmarty();
$smarty->assign('gui',new stdClass());

// Render collapsed from the start when it was left that way, instead of
// painting the menu at full width and then snapping it shut.
$smarty->assign('railed',menuRailIsOn());
$smarty->assign('menuRailCookie',menuRailCookieName());

$smarty->display('asideFrame.tpl');
