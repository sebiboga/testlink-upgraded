<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * @filesource  scriptDelete.php
 *
 * 2010.1.shim - Refs #1574: the legacy Smarty Script Delete popup was
 * replaced by the modern Dashio screen gui/templates/testcases/scriptEdit.html
 * + the api/scriptedit BFF. This controller is kept as a session-guarded
 * redirect shim so old deep links (and legacy deleteScript() callers still
 * running on un-modernized parents) resolve: anonymous users are sent to the
 * login screen (legacy testlinkInitPage behaviour) and authenticated users
 * land on the modern popup with the script id pre-selected for immediate
 * delete confirmation. The rights gate lives in the BFF (mgt_modify_tc).
**/
require_once("../../config.inc.php");
require_once('../functions/common.php');

// Anonymous -> login (same contract as the legacy testlinkInitPage call).
testlinkInitPage($db, FALSE, false, null, true);

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
$url = $base . 'gui/templates/testcases/scriptEdit.html';
$url .= '?user_action=delete';
$url .= '&tcversion_id=' . intval($_REQUEST['tcversion_id'] ?? 0);
if (isset($_REQUEST['tproject_id']) && $_REQUEST['tproject_id'] != '') {
    $url .= '&tproject_id=' . intval($_REQUEST['tproject_id']);
} else {
    $url .= '&tproject_id=' . $tprojectID;
}
if (isset($_REQUEST['script_id']) && is_scalar($_REQUEST['script_id'])
    && $_REQUEST['script_id'] != '') {
    $url .= '&script_id=' . rawurlencode((string) $_REQUEST['script_id']);
}
header('Location: ' . $url);
exit;