<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * @filesource  execNavigator.php
 *
 * 2010.1.shim - Refs #1562: the legacy Smarty/ExtJS Execution Navigator was
 * replaced by the modern Dashio screen gui/templates/execute/execNavigator.html
 * + the api/execnavigator BFF (which reuses the exact legacy execution tree
 * pipeline tlTestCaseFilterControl + execTree()). This controller is kept as a
 * session-guarded redirect shim so old deep links (frmWorkArea executeTest
 * launcher, execDashboard.php right-frame flows still running on
 * un-modernized parents) resolve: anonymous users are sent to the login screen
 * (legacy testlinkInitPage behaviour) and authenticated users land on the
 * modern navigator with the plan/project/build/platform ids + any
 * loadExecDashboard intent forwarded. The rights gate lives in the BFF
 * (testplan_execute OR exec_ro_access, admin shortcut parity).
**/
require_once("../../config.inc.php");
require_once('../functions/common.php');
require_once('../functions/users.inc.php');

// Anonymous -> login (same contract as the legacy testlinkInitPage call).
testlinkInitPage($db, FALSE, false, null, true);

if (!isset($_SESSION['testplanID']) || intval($_SESSION['testplanID']) <= 0) {
    $tplanID = isset($_REQUEST['setting_testplan']) ? intval($_REQUEST['setting_testplan']) : 0;
} else {
    $tplanID = intval($_SESSION['testplanID']);
}
$tprojectID = isset($_SESSION['testprojectID']) ? intval($_SESSION['testprojectID']) : 0;

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
$url = $base . 'gui/templates/execute/execNavigator.html';
$url .= '?tplan_id=' . $tplanID . '&tproject_id=' . $tprojectID;
if (isset($_REQUEST['setting_build'])) {
    $url .= '&setting_build=' . intval($_REQUEST['setting_build']);
}
if (isset($_REQUEST['setting_platform'])) {
    $url .= '&setting_platform=' . intval($_REQUEST['setting_platform']);
}
if (isset($_REQUEST['loadExecDashboard'])) {
    $url .= '&loadExecDashboard=' . intval($_REQUEST['loadExecDashboard']);
}
header('Location: ' . $url);
exit;