<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * @filesource  platformsEdit.php
 *
 * 2010.1.shim - Refs #1871: the legacy Smarty Platform Create/Edit form was
 * replaced by the modern Dashio screen
 * gui/templates/platforms/platformsEdit.html + the api/platformedit BFF.
 * This controller is kept as a session-guarded redirect shim so the legacy
 * deep links (do_action=create|edit with a tproject_id / platform_id) resolve:
 * anonymous users are sent to the login screen (legacy testlinkInitPage
 * behaviour) and authenticated users land on the modern screen with the test
 * project / platform / test plan ids forwarded.
 *
 * The rights gate lives in the BFF: legacy checkRights()/initEnv() was
 * $user->hasRightOnProj($db,"platform_management") on the OWNING test project.
 *
 * The create/update/delete and the enable/disableDesign, enable/disableExec,
 * open/closeForExec flag actions are no longer performed here. The BFF
 * reproduces the legacy tlPlatform::create/update/delete behaviour (including
 * the duplicate-name and the not-removable-while-used-by-test-plans checks) and
 * answers a 400/401/403/404/405/422/500 JSON contract that the modern screen
 * localizes client-side.
 */
require('../../config.inc.php');
require_once('common.php');

testlinkInitPage($db, false, false, null, true);

// Refs #1871 (found while testing): the shim used to treat a write like a GET
// and answer 302. Nothing here is state-changing, so refuse anything but a
// safe read and keep this file from ever being used to smuggle a write past
// the BFF's checks. Anonymous callers never reach this — testlinkInitPage()
// -> checkSessionValid() already bounces them to the login screen first
// (standard top.location JS redirect, same as every other legacy controller).
if (isset($_SERVER['REQUEST_METHOD']) &&
    !in_array(strtoupper($_SERVER['REQUEST_METHOD']), array('GET', 'HEAD'), true)) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    exit('Method Not Allowed');
}

$base = isset($_SESSION['basehref']) ? $_SESSION['basehref'] : '/';

$tprojectID = 0;
foreach (array('tproject_id', 'testproject_id') as $key) {
    if (isset($_REQUEST[$key]) && is_scalar($_REQUEST[$key])) {
        $candidate = intval($_REQUEST[$key]);
        if ($candidate > 0) {
            $tprojectID = $candidate;
            break;
        }
    }
}
if ($tprojectID <= 0 && isset($_SESSION['testprojectID'])) {
    $tprojectID = intval($_SESSION['testprojectID']);
}

$platformID = 0;
if (isset($_REQUEST['platform_id']) && is_scalar($_REQUEST['platform_id'])) {
    $platformID = intval($_REQUEST['platform_id']);
}
if ($platformID <= 0 && isset($_REQUEST['id']) && is_scalar($_REQUEST['id'])) {
    $platformID = intval($_REQUEST['id']);
}

$tplanID = 0;
foreach (array('tplan_id', 'testplan_id') as $key) {
    if (isset($_REQUEST[$key]) && is_scalar($_REQUEST[$key])) {
        $candidate = intval($_REQUEST[$key]);
        if ($candidate > 0) {
            $tplanID = $candidate;
            break;
        }
    }
}
if ($tplanID <= 0 && isset($_SESSION['testplanid'])) {
    $tplanID = intval($_SESSION['testplanid']);
}

$url = $base . 'gui/templates/platforms/platformsEdit.html';
$params = array();
if ($tprojectID > 0) {
    $params[] = 'tproject_id=' . $tprojectID;
}
if ($platformID > 0) {
    $params[] = 'platform_id=' . $platformID;
}
if ($tplanID > 0) {
    $params[] = 'tplan_id=' . $tplanID;
}
if (count($params) > 0) {
    $url .= '?' . implode('&', $params);
}

header('Location: ' . $url, true, 302);
exit;