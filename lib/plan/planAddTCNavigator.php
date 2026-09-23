<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * @filesource planAddTCNavigator.php
 *
 *  Navigator for feature: add Test Cases to a Test Case Suite in Test Plan
 *  (legacy 1.9.20 left-pane frame).
 *  Modernized Refs #1572 as the standalone Dashio hub
 *    gui/templates/plans/planNav.html
 *  backed by the BFF api/plannav (Session-based auth, JSON I/O).
 *
 *  Keep only as a compatibility launcher: session-guarded redirect to the
 *  modern screen, forwarding testproject_id / testplan_id. Anonymous users
 *  are sent to the login screen (legacy testlinkInitPage behaviour). The
 *  group-by (mode_test_suite | mode_req_coverage) selector lives in the hub;
 *  the Add/Remove workframe is planAddTCView.html.
 */
require_once("../../config.inc.php");
require_once('../functions/common.php');

testlinkInitPage($db, FALSE, false, null, true);

$base = isset($_SESSION['basehref']) ? $_SESSION['basehref'] : '/';

$tproject = '';
$tplan = '';
foreach (array_keys($_GET) as $k) {
    $v = $_GET[$k];
    if (!is_scalar($v)) {
        continue;
    }
    if ($k === 'testproject_id' || $k === 'tproject_id') {
        $id = intval($v);
        if ($id > 0) {
            $tproject = "testproject_id={$id}";
        }
    } elseif ($k === 'testplan_id' || $k === 'tplan_id') {
        $id = intval($v);
        if ($id > 0) {
            $tplan = $tplan === '' ? "testplan_id={$id}"
                                   : $tplan . "&testplan_id={$id}";
        }
    }
}

$query = '';
if ($tproject !== '') {
    $query .= $tproject;
}
if ($tplan !== '') {
    $query .= ($query === '' ? '' : '&') . $tplan;
}

header('Location: ' . $base . 'gui/templates/plans/planNav.html'
       . ($query === '' ? '' : '?' . $query), true, 302);
exit;