<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 *
 * @version $Revision: 1.6 $
 *
 * manage launch of help pages.
 *
 * 2010.1.shim - Refs #1552: legacy standalone Help popup was replaced by the
 * modern gui/templates/help/showHelp.html screen + api/help/index.php BFF.
 * This file is kept as a redirect shim so any stale link still works:
 * the old implementation was broken dead code anyway (it referenced the
 * undefined constant TL_HELP_RPATH and rendered gui/help/<locale>/*.html
 * files that were deleted when their content was migrated into the locale
 * $TLS_htmltext bundles).
**/
require('../../config.inc.php');
require_once("common.php");
// start session, need to get right basehref
testlinkInitPage($db);

$args = init_args();

$url = $_SESSION['basehref'] . 'gui/templates/help/showHelp.html';
$url .= '?help=' . urlencode($args->help) . '&locale=' . urlencode($args->locale);
header('Location: ' . $url);
exit;

function init_args()
{
	$iParams = array(
		"help" => array(tlInputParameter::STRING_N),
		"locale" => array(tlInputParameter::STRING_N,0,10),
	);
	$args = new stdClass();
	$pParams = R_PARAMS($iParams,$args);
	
	return $args;
}
?>