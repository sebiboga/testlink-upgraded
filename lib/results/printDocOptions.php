<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * @filesource printDocOptions.php
 * @author     Martin Havlat
 *
 *  Settings for generated documents (print preferences navigator popup)
 *  - Legacy 1.9.20 screen; modernized Refs #1570 as the Dashio popup
 *    gui/templates/results/printDocOptions.html backed by the BFF
 *    api/printoptions  (Session-based auth, JSON I/O).
 *
 *  Keep only as a compatibility launcher: session-guarded redirect to the
 *  modern screen, forwarding type / tplan_id / format. Anonymous users are
 *  sent to the login screen (legacy testlinkInitPage behaviour). The
 *  'activity=addTC' navigator mode (used by legacy planAddTC.php) is covered
 *  by the modern planAddTCView.html.
 */
require_once("../../config.inc.php");
require_once('../functions/common.php');

// Anonymous -> login (same contract as the legacy testlinkInitPage call).
testlinkInitPage($db, FALSE, false, null, true);

$docType = 'testspec';
$tplan = '';
$format = '';
foreach (array_keys($_GET) as $k) {
    $v = $_GET[$k];
    if ($k === 'type') {
        $docType = preg_replace('/[^a-z_]/', '', $v);
    } elseif ($k === 'tplan_id') {
        $tplan = '&tplan_id=' . intval($v);
    } elseif ($k === 'format') {
        $format = '&format=' . intval($v);
    }
}
if (isset($_GET['activity']) && $_GET['activity'] !== '') {
    $url = '/gui/templates/plans/planAddTCView.html';
    if ($tplan !== '') {
        $url .= '?' . substr($tplan, 1);
    }
    header('Location: ' . $url, true, 302);
    exit;
}
if (!in_array($docType, array('testspec', 'reqspec', 'testplan',
        'testreport', 'testreport_onbuild'), true)) {
    $docType = 'testspec';
}
$query = 'type=' . $docType;
if ($tplan !== '') {
    $query .= '&' . substr($tplan, 1);
}
if ($format !== '') {
    $query .= '&' . substr($format, 1);
}
header('Location: /gui/templates/results/printDocOptions.html?' . $query, true, 302);
exit;