<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * @filesource  execExport.php
 *
 * 2010.1.shim - Refs #1024: the legacy Smarty Execution Export popup was replaced
 * by the modern Dashio screen gui/templates/execute/execExport.html + the
 * api/executeexport BFF. This controller is kept as a session-guarded redirect
 * shim so old deep links (and the legacy dashio/tl-classic templates that still
 * post/open this URL) resolve: anonymous users are sent to the login screen
 * (legacy testlinkInitPage behaviour) and authenticated users land on the
 * modern popup with the execution/export context forwarded. The rights gate
 * lives in the BFF (testplan_execute OR exec_ro_access on the owning project).
 **/
require_once("../../config.inc.php");
require_once('../functions/common.php');

// Anonymous -> login (same contract as the legacy testlinkInitPage call).
testlinkInitPage($db, FALSE, false, null, true);

$tprojectID = isset($_SESSION['testprojectID']) ? intval($_SESSION['testprojectID']) : 0;
$tplanID = isset($_SESSION['testplanID']) ? intval($_SESSION['testplanID']) : 0;
$buildID = isset($_REQUEST['build_id']) ? intval($_REQUEST['build_id']) : (isset($_REQUEST['buildID']) ? intval($_REQUEST['buildID']) : 0);
$platformID = isset($_REQUEST['platform_id']) ? intval($_REQUEST['platform_id']) : (isset($_REQUEST['platformID']) ? intval($_REQUEST['platformID']) : 0);
$tsuiteID = isset($_REQUEST['tsuite_id']) ? intval($_REQUEST['tsuite_id']) : (isset($_REQUEST['tsuiteID']) ? intval($_REQUEST['tsuiteID']) : 0);
$tcversionSet = isset($_REQUEST['tcversionSet']) ? strval($_REQUEST['tcversionSet']) : '';

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
$url = $base . 'gui/templates/execute/execExport.html';
$url .= '?tproject_id=' . $tprojectID;
$url .= '&tplan_id=' . $tplanID;
$url .= '&build_id=' . $buildID;
$url .= '&platform_id=' . $platformID;
$url .= '&tsuite_id=' . $tsuiteID;
if ($tcversionSet !== '') {
    $parts = preg_split('/[,\s]+/', $tcversionSet);
    $clean = array();
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p !== '' && ctype_digit($p)) {
            $clean[] = $p;
        }
    }
    if (!empty($clean)) {
        $url .= '&tcversionSet=' . implode(',', $clean);
    }
}
header('Location: ' . $url);
exit;
