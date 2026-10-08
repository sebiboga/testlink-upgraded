<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * @filesource  usersEdit.php
 *
 * 2010.1.shim - Refs #1880: the legacy Smarty User Create/Edit form (the LAST
 * full legacy renderer still served in the User Management area, together with
 * its doCreate / doUpdate / resetPassword / genAPIKey actions executed straight
 * from the request) was replaced by the modern Dashio screen
 * gui/templates/usermanagement/usersEdit.html + the api/usersedit BFF.
 * This controller is kept as a session-guarded redirect shim so the legacy
 * deep links (operation=edit|create with user_id) resolve: anonymous users are
 * sent to the login screen (legacy testlinkInitPage behaviour) and
 * authenticated users land on the modern screen with the user id and the test
 * project / test plan ids forwarded.
 *
 * The rights gate lives in the BFF: legacy checkRights() was
 * $user->hasRight($db, 'mgt_users') and api/usersedit re-checks it on every
 * route (403 + audit trace without it).
 *
 * The create/update/reset-password/generate-apikey actions are no longer
 * performed here. The BFF reproduces the legacy behaviour (default-role
 * fallback, tlUser::setPassword() single-hash storage, expiration date, the
 * session refresh / self-logout of doUpdate, the SMTP-hostname guard of
 * resetPassword and genAPIKey, demo-mode write blocking) and answers a
 * 400/401/403/404/405/422 JSON contract that the modern screen localizes
 * client-side.
 */
require_once('../../config.inc.php');
require_once('users.inc.php');
require_once('email_api.php');
require_once('Zend/Validate/Hostname.php');

// Legacy parity (usersEdit.php:482-485 of 1.9.20): the deep link itself stays
// behind the same mgt_users right - an authenticated user without it is sent
// home by testlinkInitPage() instead of being handed the modern screen URL.
function checkRights(&$db, &$user)
{
    return $user->hasRight($db, 'mgt_users');
}

testlinkInitPage($db, false, false, 'checkRights');

// Refs #1880: nothing here is state-changing, so refuse anything but a safe
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

// Legacy init_args() contract: operation=create|edit (usersEdit.tpl:322 also
// posts doAction=create|update), user_id in edit mode.
$mode = 'create';
foreach (array('operation', 'doAction') as $key) {
    if (isset($_REQUEST[$key]) && is_scalar($_REQUEST[$key])) {
        $value = strtolower(trim((string)$_REQUEST[$key]));
        if (in_array($value, array('edit', 'update', 'doupdate'), true)) {
            $mode = 'edit';
            break;
        }
        if (in_array($value, array('create', 'docreate'), true)) {
            $mode = 'create';
            break;
        }
    }
}

$userID = 0;
foreach (array('user_id', 'id') as $key) {
    if (isset($_REQUEST[$key]) && is_scalar($_REQUEST[$key])) {
        $candidate = intval($_REQUEST[$key]);
        if ($candidate > 0) {
            $userID = $candidate;
            break;
        }
    }
}
// A deep link that names a user but no operation is an edit link (the legacy
// links built by usersView always carried both).
if ($userID > 0 && $mode === 'create' && !isset($_REQUEST['operation']) && !isset($_REQUEST['doAction'])) {
    $mode = 'edit';
}
if ($userID <= 0) {
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

$url = $base . 'gui/templates/usermanagement/usersEdit.html';
$params = array('mode=' . $mode);
if ($userID > 0) {
    $params[] = 'user_id=' . $userID;
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
