<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * @filesource  platformsImport.php
 *
 * 2010.1.shim - Refs #1632: the legacy Smarty Import Platforms page was
 * replaced by the modern Dashio screen
 * gui/templates/platforms/platformsImport.html + the api/platformsimport BFF.
 * This controller is kept as a session-guarded redirect shim so old deep links
 * resolve: anonymous users are sent to the login screen (legacy
 * testlinkInitPage behaviour) and authenticated users land on the modern
 * screen with the test project / test plan ids forwarded.
 *
 * The rights gate lives in the BFF: legacy checkRights() was
 * $user->hasRightOnProj($db,"platform_management") on the OWNING test project.
 *
 * The import itself is no longer performed here. Legacy doImport() wrote the
 * upload to TL_TEMP_PATH . session_id() . "-import_platforms.tmp" and only
 * unlinked it implicitly (never on a parse failure), printed its feedback
 * through server-side lang_get() and answered every failure with a raw
 * JavaScript alert. The BFF reproduces the create-or-update-by-name logic and
 * the per-platform imported/updated/skipped message lists, unlinks the temp
 * copy in a finally block and answers a 400/401/403/404/405/413/422/500 JSON
 * contract that the modern screen localizes client-side.
 */
require('../../config.inc.php');
require_once('common.php');

testlinkInitPage($db, false, false, null, true);

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

$url = $base . 'gui/templates/platforms/platformsImport.html';
if ($tprojectID > 0) {
    $url .= '?tproject_id=' . $tprojectID;
    if ($tplanID > 0) {
        $url .= '&tplan_id=' . $tplanID;
    }
}

header('Location: ' . $url, true, 302);
exit;
