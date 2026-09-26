<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * @filesource	keywordsImport.php
 *
 * 2.0.1.shim - Refs #1615: the legacy Smarty keyword export screen was replaced
 * by the modern Dashio screen gui/templates/keywords/keywordsExport.html backed
 * by the api/keywordsxml BFF (which also carries the import flow of the
 * sibling lib/keywords/keywordsImport.php).
 *
 * This controller is kept as a session-guarded redirect shim so old deep links
 * and the still-shipped dashio / tl-classic templates resolve:
 *   - anonymous users are sent to the login screen (legacy testlinkInitPage
 *     behaviour);
 *   - every other request is mapped onto the modern screen in Import mode with
 *     the tproject_id context forwarded.
 *
 * NOTE: the second testlinkInitPage() argument stays FALSE on purpose, so a
 * crafted GET cannot overwrite the session testprojectID (Refs #1604 pattern).
**/
require_once('../../config.inc.php');
require_once('common.php');

testlinkInitPage($db, false, false);

$base = isset($_SESSION['basehref']) ? $_SESSION['basehref'] : '/';
$tproject_id = intval($_REQUEST['tproject_id'] ?? 0);
$tplan_id = isset($_REQUEST['tplan_id']) ? intval($_REQUEST['tplan_id']) : 0;

$url = $base . 'gui/templates/keywords/keywordsExport.html?mode=import' .
	'&tproject_id=' . $tproject_id . '&tplan_id=' . $tplan_id;

http_response_code(302);
header('Location: ' . $url);
exit;
