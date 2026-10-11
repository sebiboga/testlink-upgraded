<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * @filesource  rolesEdit.php
 *
 * 2.0.1.shim - Refs #1898: the legacy Smarty Role Create/Edit form (the last
 * full legacy renderer still served in the User Management area, together with
 * its doCreate / doUpdate / duplicate actions executed straight from the
 * request) was replaced by the modern Dashio screen
 * gui/templates/usermanagement/rolesEdit.html + the api/rolesedit BFF.
 * This controller is kept as a session-guarded redirect shim so the legacy
 * deep links (doAction=create|edit|duplicate with roleid) resolve: anonymous
 * users are sent to the login screen (legacy testlinkInitPage behaviour) and
 * authenticated users land on the modern screen with the role id and the test
 * project / test plan ids forwarded.
 *
 * The rights gate lives in the BFF: legacy checkRights() was
 * $user->hasRight($db, 'role_management') and api/rolesedit re-checks it on
 * every route (403 + audit trace without it).
 *
 * The create/update/duplicate actions are no longer performed here. The BFF
 * reproduces the legacy behaviour (the tlRight::getAll() description filter,
 * generateUniqueName() duplication, admin / <no rights> protection, demo-mode
 * write blocking, audit logging) and answers a 400/401/403/404/405 JSON
 * contract that the modern screen localizes client-side.
 */
require_once('../../config.inc.php');
require_once('common.php');
require_once('users.inc.php');

testlinkInitPage($db, false, false, 'checkRights');

// Refs #1898: nothing here is state-changing, so refuse anything but a safe
// read and keep this file from ever being used to smuggle a write past the
// BFF's checks. Anonymous callers never reach this - testlinkInitPage()
// already bounces them to the login screen first (standard behaviour of every
// other legacy controller).
if (isset($_SERVER['REQUEST_METHOD']) &&
    !in_array(strtoupper($_SERVER['REQUEST_METHOD']), array('GET', 'HEAD'), true)) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    exit('Method Not Allowed');
}

$base = isset($_SESSION['basehref']) ? $_SESSION['basehref'] : '/';

// Legacy init_args() contract: doAction=create|edit|duplicate (rolesView.tpl /
// tabsmenu.tpl), roleid in edit / duplicate mode.
$mode = 'create';
$doAction = '';
if (isset($_REQUEST['doAction']) && is_scalar($_REQUEST['doAction'])) {
    $doAction = strtolower(trim((string)$_REQUEST['doAction']));
}
if (in_array($doAction, array('edit', 'update', 'doupdate'), true)) {
    $mode = 'edit';
} elseif (in_array($doAction, array('duplicate', 'copy'), true)) {
    $mode = 'duplicate';
} elseif (in_array($doAction, array('create', 'docreate'), true)) {
    $mode = 'create';
}

$roleID = 0;
if (isset($_REQUEST['roleid']) && is_scalar($_REQUEST['roleid'])) {
    $roleID = intval($_REQUEST['roleid']);
}
// A deep link that names a role but no operation is an edit link.
if ($roleID > 0 && $mode === 'create' && $doAction === '') {
    $mode = 'edit';
}
if ($roleID <= 0) {
    $mode = 'create';
}

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
if ($tplanID <= 0 && isset($_SESSION['testplanID'])) {
    $tplanID = intval($_SESSION['testplanID']);
}

$url = $base . 'gui/templates/usermanagement/rolesEdit.html';
$params = array('mode=' . $mode);
if ($roleID > 0) {
    $params[] = 'roleid=' . $roleID;
}
if ($tprojectID > 0) {
    $params[] = 'tproject_id=' . $tprojectID;
}
if ($tplanID > 0) {
    $params[] = 'tplan_id=' . $tplanID;
}
$url .= '?' . implode('&', $params);

header('Location: ' . $url, true, 302);
exit;

/**
 * Legacy parity (rolesEdit.php:310-313 of 1.9.20): the deep link itself stays
 * behind the same role_management right - an authenticated user without it is
 * sent home by testlinkInitPage() instead of being handed the modern URL.
 */
function checkRights(&$db, &$user)
{
    return $user->hasRight($db, 'role_management');
}
