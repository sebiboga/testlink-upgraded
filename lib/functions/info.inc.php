<?php
/** 
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later. 
 *
 * Functions for GUI support
 * 
 * @package 	TestLink
 * @author 		Martin Havlat
 * @copyright 	2005-2009, TestLink community 
 * @version    	CVS: $Id: info.inc.php,v 1.9 2010/05/15 11:11:51 franciscom Exp $
 * @link 		http://www.teamst.org/index.php
 * @uses 		common.php
 *
 * @internal Revisions:
 * 
 */

/** @uses email_api.php */
require_once("../functions/email_api.php");


/**
 * Display simple info and exit
 *
 * MODERNIZED (Refs #1899): the legacy renderer (workAreaSimple.tpl, which
 * emitted the caller-supplied HTML un-escaped) has been replaced by the
 * standalone Dashio Info page (gui/templates/feedback/info.html +
 * api/info/index.php), which resolves a localized title + message from the
 * stable machine id below.
 *
 * @param string $title    legacy title (kept for signature compatibility, unused)
 * @param string $message  legacy message (kept for signature compatibility, unused)
 * @param string $code     stable machine id shown by the modern page
 *                         (default 'missing_testplan' - the report document error)
 */
function displayInfo($title, $message, $code = 'missing_testplan')
{
	// common.php (loaded by every caller) provides redirectToInfoPage().
	redirectToInfoPage($code);
}
?>