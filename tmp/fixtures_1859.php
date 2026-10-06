<?php
// Fixture for issue #1859: tcEdit.php:327 key(get_last_active_version()) fatal
// when a test case has NO active tcversions row (and for a non-existent id).
//
// Creates tproject `TC Edit 1859` (prefix TC1859) with one suite and two test
// cases:
//   TC1859-NOACTIVE  -> its ONLY version is deactivated (active=0)
//   TC1859-ACTIVE    -> stays active (positive control)
// Prints the ids on stdout (one line each) so a verify script can pick them up.
//
// Run from repo root: php tmp/fixtures_1859.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tcaseMgr = new testcase($db);
$tsuiteMgr = new testsuite($db);
$userId = 1; // admin

foreach ((array)$tprojMgr->get_by_name('TC Edit 1859') as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid);
    }
}

$item = new stdClass();
$item->name = 'TC Edit 1859';
$item->prefix = 'TC1859';
$item->notes = 'fixture for issue 1859 (tcEdit.php no-active-version fatal)';
$item->color = '';
$item->active = 1;
$item->is_public = 1;
$opts = new stdClass();
$opts->requirementsEnabled = 0;
$opts->testPriorityEnabled = 0;
$opts->automationEnabled = 0;
$opts->inventoryEnabled = 0;
$opts->platformsEnabled = 0;
$opts->testcasecfEnabled = 0;
$opts->requirementcfEnabled = 0;
$item->options = $opts;
$idP = intval($tprojMgr->create($item));
if ($idP <= 0) {
    die("tproject create failed\n");
}
echo "tproject=$idP\n";

$idS = intval($tsuiteMgr->create($idP, 'TC Edit 1859 Suite', '', null, 0, 'allow_repeat')['id'] ?? 0);
if ($idS <= 0) {
    die("suite create failed\n");
}
echo "suite=$idS\n";

function makeTcase($tcaseMgr, $idS, $name, $userId)
{
    $steps = array();
    $t = new stdClass();
    $t->step_number = 1;
    $t->actions = 'actions for ' . $name;
    $t->expected_results = 'expected for ' . $name;
    $t->execution_type = TESTCASE_EXECUTION_TYPE_MANUAL;
    $steps[] = $t;
    $ret = $tcaseMgr->create($idS, $name, 'summary for ' . $name, '', $steps, $userId, '',
        testcase::DEFAULT_ORDER, testcase::AUTOMATIC_ID, TESTCASE_EXECUTION_TYPE_MANUAL);
    if (empty($ret['status_ok']) || intval($ret['id']) <= 0) {
        die('tcase create failed: ' . ($ret['message'] ?? '') . "\n");
    }
    return array(intval($ret['id']), intval($ret['tcversion_id']));
}

list($idNoActive, $tcvNoActive) = makeTcase($tcaseMgr, $idS, 'TC1859-NOACTIVE', $userId);
echo "tcase_noactive=$idNoActive\n";
echo "tcversion_noactive=$tcvNoActive\n";

// deactivate the only version -> the test case now has zero active versions
$db->exec_query("UPDATE tcversions SET active = 0 WHERE id = " . intval($tcvNoActive));
echo "deactivated tcv=$tcvNoActive\n";

list($idActive, $tcvActive) = makeTcase($tcaseMgr, $idS, 'TC1859-ACTIVE', $userId);
echo "tcase_active=$idActive\n";
echo "tcversion_active=$tcvActive\n";

echo "DONE\n";
