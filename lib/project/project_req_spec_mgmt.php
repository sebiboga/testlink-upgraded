<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * @filesource  project_req_spec_mgmt.php
 * @author      Martin Havlat
 *
 * 2010.1.shim - Refs #1833: the project-scoped Requirement Specification launcher
 * (this controller + gui/templates/dashio/requirements/project_req_spec_mgmt.tpl)
 * was replaced by the modern Dashio screen
 * gui/templates/requirements/projectReqSpecMgmt.html + the
 * api/projectreqspecmgmt BFF.
 *
 * It was the LAST project-scoped legacy controller left in the Requirements
 * area, and 2.0.1 had no equivalent of it: the sibling requirement-module
 * entry point (lib/requirements/reqSpecMgmt.php) is a different screen and was
 * already modernized as requirements/reqSpecMgmt.html.
 *
 * Kept as a session-guarded redirect shim so old bookmarks and any external link
 * still resolve: anonymous users are sent to the login screen (the legacy
 * testlinkInitPage behaviour) and authenticated users land on the modern screen
 * with the project id forwarded.
 *
 * The legacy screen took the project from $_SESSION['testprojectID'] only; the
 * modern screen additionally accepts an explicit tproject_id, so it is honoured
 * here when present.
 **/
require_once('../../config.inc.php');
require_once('common.php');

// Anonymous -> login (same contract as the legacy testlinkInitPage call).
testlinkInitPage($db, TRUE);

$tprojectID = isset($_REQUEST['tproject_id'])
    ? intval($_REQUEST['tproject_id'])
    : (isset($_SESSION['testprojectID']) ? intval($_SESSION['testprojectID']) : 0);

$url = $_SESSION['basehref'] . 'gui/templates/requirements/projectReqSpecMgmt.html';
$url .= '?tproject_id=' . $tprojectID;
header('Location: ' . $url);
exit;
