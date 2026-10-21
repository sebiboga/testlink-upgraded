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
$url = $base . 'gui/templates/requirements/projectReqSpecMgmt.html';
$url .= '?tproject_id=' . $tprojectID;
header('Location: ' . $url);
exit;
