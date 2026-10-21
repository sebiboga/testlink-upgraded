<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/ 
 * This script is distributed under the GNU General Public License 2 or later. 
 *
 * @filesource	mainPage.php
 * 
 * Page has two functions: navigation and select Test Plan
 *
 * This file is the first page that the user sees when they log in.
 * Most of the code in it is html but there is some logic that displays
 * based upon the login. 
 * There is also some javascript that handles the form information.
 *
 * 2010.1.shim - Refs #1555: the legacy Smarty dashboard was replaced by the
 * modern gui/templates/mainpage/mainPage.html screen + api/mainpage BFF.
 * This controller is kept as a session-guarded redirect shim: it preserves
 * the login contract (anonymous -> login screen, same as the legacy flow),
 * forwards the legacy ?testplan deep-link parameter, applies the zero-test
 * project bootstrap (admin forced to the modernized Test Project Create/Edit
 * screen, Refs #967) and then lands the browser on the modern dashboard.
**/

require_once('../../config.inc.php');
require_once('common.php');

testlinkInitPage($db,TRUE);

$user = $_SESSION['currentUser'];

// Refs #967 parity: with no test project in the system at all, an admin who
// just logged in (or hit a stale deep link directly) must be sent to the
// modernized Test Project Create/Edit screen, exactly like getGrantSetWithExit()
// does for every modern page. The legacy getGrants() shipped the identical
// bootstrap but pointed at the old lib/project/projectEdit.php?doAction=create.
$base = str_replace(array("\r", "\n"), '', strval($_SESSION['basehref'] ?? ''));
// Harden against a poisoned session: CRLF would allow header injection and a
// foreign scheme/host an open redirect. Prefer a root-relative base built from
// the session, falling back to '/' (same hardening as the #1947/#1948 shims).
if (preg_match('~^https?://[^/]+(/.+)$~i', $base, $m)) {
    $base = rtrim($m[1], '/') . '/';
}
if ($base === '' || $base[0] !== '/' || strpos($base, '//') === 0) {
    $base = '/';
}
$tproject_mgr = new testproject($db);
$zeroTestProjects = ($tproject_mgr->getItemCount() == 0);
if ($zeroTestProjects && $user->hasRight($db,'mgt_modify_product')) {
  header('Location: ' . $base .
         'gui/templates/projects/projectEdit.html');
  exit();
}

// Legacy deep-link parity: mainPage.php?testplan=N selected a specific test
// plan. Forward it (and the committed project/plan context) so the modern
// mainPage.html + api/mainpage BFF still honor it.
$testplanID = isset($_REQUEST['testplan']) ? intval($_REQUEST['testplan']) : 0;
$tprojectID = isset($_SESSION['testprojectID']) ? intval($_SESSION['testprojectID']) : 0;
if ($testplanID <= 0) {
  $testplanID = isset($_SESSION['testplanID']) ? intval($_SESSION['testplanID']) : 0;
}

$url = $base . 'gui/templates/mainpage/mainPage.html';
$url .= '?tproject_id=' . $tprojectID . '&tplan_id=' . $testplanID;
header('Location: ' . $url);
exit;