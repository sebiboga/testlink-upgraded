<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 *
 * @filesource  tcExecute.php
 *
 * 2.0.1.shim - Refs #1587: the legacy "Execute test case on a remote
 * automation server" page (TestLink 1.9.20, last modified 2010) was replaced
 * by the modern Dashio screen gui/templates/testcases/tcAutoExec.html backed
 * by the api/tcautoexec BFF. The legacy controller:
 *   - had no rights check at all (any logged in user could trigger remote
 *     execution on any test project),
 *   - had no CSRF / same-origin proof,
 *   - called executeTestCase() with the 1.9.20 signature
 *     executeTestCase($tcase_id,$tree_manager,$cfield_manager) while
 *     lib/functions/remote_exec.php now expects
 *     executeTestCase($tcaseInfo,$serverCfg,$context) - i.e. it was dead code
 *     throwing a TypeError,
 *   - did not recurse into the test case subtree for the testsuite /
 *     testproject levels.
 * All of that now lives in the BFF (mgt_view_tc gate, bffSameOriginGuard,
 * server resolution with suite inheritance, recursive collection).
 *
 * This file is kept as a session-guarded redirect shim so old deep links and
 * bookmarks keep working: anonymous users are sent to the login screen (the
 * legacy testlinkInitPage contract) and authenticated users land on the modern
 * screen with their test project / plan / build / platform context forwarded.
**/
require_once("../../config.inc.php");
require_once("../functions/common.php");

// Anonymous -> login (same contract as the legacy testlinkInitPage call).
testlinkInitPage($db, FALSE, false, null, true);

$tprojectID = isset($_SESSION['testprojectID']) ? intval($_SESSION['testprojectID']) : 0;
$tplanID = isset($_SESSION['testplanID']) ? intval($_SESSION['testplanID']) : 0;
$buildID = isset($_SESSION['buildID']) ? intval($_SESSION['buildID']) : 0;
$platformID = isset($_SESSION['platformID']) ? intval($_SESSION['platformID']) : 0;

// Legacy request parameters (init_args + the $_REQUEST lookups of the
// testsuite / testproject branches) are accepted so the old links keep their
// context; the modern screen resolves the node itself.
$q = array();
if (isset($_REQUEST['testproject_id'])) { $q['tproject_id'] = intval($_REQUEST['testproject_id']); }
if (isset($_REQUEST['tplan_id'])) { $q['tplan_id'] = intval($_REQUEST['tplan_id']); }
if (isset($_REQUEST['build_id'])) { $q['build_id'] = intval($_REQUEST['build_id']); }
if (isset($_REQUEST['platform_id'])) { $q['platform_id'] = intval($_REQUEST['platform_id']); }
if (isset($_REQUEST['level'])) {
    $level = preg_replace('/[^a-z]/', '', strval($_REQUEST['level']));
    if (in_array($level, array('testcase', 'testsuite', 'testproject'))) { $q['level'] = $level; }
}
foreach (array('testcase_id', 'testsuite_id', 'testproject_id') as $legacyLevelKey) {
    if (isset($_REQUEST[$legacyLevelKey])) {
        $q[$legacyLevelKey] = intval($_REQUEST[$legacyLevelKey]);
        break;
    }
}

if (!isset($q['tproject_id']) && $tprojectID > 0) { $q['tproject_id'] = $tprojectID; }
if (!isset($q['tplan_id']) && $tplanID > 0) { $q['tplan_id'] = $tplanID; }
if (!isset($q['build_id']) && $buildID > 0) { $q['build_id'] = $buildID; }
if (!isset($q['platform_id']) && $platformID > 0) { $q['platform_id'] = $platformID; }

$url = $_SESSION['basehref'] . 'gui/templates/testcases/tcAutoExec.html';
if (count($q) > 0) {
    $url .= '?' . http_build_query($q, '', '&');
}
header('Location: ' . $url);
exit;
