<?php
// CLI fixtures for issue 422: project + suite + 2 cases + plan + build.
// Deliberately does NOT link tcversions to the plan: issue 422A is about
// the Add/Remove Test Cases screen (planAddTC) that performs that linking.
$_SESSION = array();
require_once(dirname(__DIR__) . '/config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tp = new testproject($db);
$ts = new testsuite($db);
$tc = new testcase($db);
$tplanMgr = new testplan($db);

$name = 'I422 Project';
$rs = $tp->get_by_name($name);
if ($rs) {
    $projId = intval($rs[0]['id']);
    echo "project exists id=$projId\n";
} else {
    $item = new stdClass();
    $item->name = $name;
    $item->prefix = 'I422';
    $item->notes = 'fixture';
    $item->options = new stdClass();
    $item->options->requirementsEnabled = 1;
    $item->options->testPriorityEnabled = 1;
    $item->options->automationEnabled = 1;
    $item->options->inventoryEnabled = 1;
    $item->color = '';
    $item->active = 1;
    $item->is_public = 1;
    $projId = intval($tp->create($item));
    echo "project=$projId\n";
}

$rows = $db->get_recordset(
    "SELECT NH.id FROM nodes_hierarchy NH WHERE NH.name='I422 Suite' AND NH.parent_id=$projId");
if ($rows) {
    $suiteId = intval($rows[0]['id']);
    echo "suite exists id=$suiteId\n";
} else {
    $sret = $ts->create($projId, 'I422 Suite', 'fixture suite');
    if (!$sret['status_ok']) { echo "SUITE FAIL: {$sret['msg']}\n"; exit(1); }
    $suiteId = intval($sret['id']);
    echo "suite=$suiteId\n";
}

$steps = array(array('step_number' => 1,
                     'actions' => '<p>Do the thing</p>',
                     'expected_results' => '<p>It works</p>'));
foreach (array('Case Gamma', 'Case Delta') as $cn) {
    $rows = $db->get_recordset(
      "SELECT id FROM nodes_hierarchy WHERE name='$cn' AND parent_id=$suiteId");
    if ($rows) { echo "tcase '$cn' exists id={$rows[0]['id']}\n"; continue; }
    $ret = $tc->create($suiteId, $cn, '<p>summary</p>', '', $steps, 1);
    if (!$ret['status_ok']) { echo "TC CREATE FAIL: {$ret['msg']}\n"; exit(1); }
    echo "tcase={$ret['id']} tcversion={$ret['tcversion_id']} name=$cn\n";
}

$rows = $db->get_recordset(
    "SELECT NH.id FROM nodes_hierarchy NH WHERE NH.name='I422 Plan'");
if ($rows) {
    $planId = intval($rows[0]['id']);
    echo "plan exists id=$planId\n";
} else {
    $planId = intval($tplanMgr->create('I422 Plan', 'fixture plan', $projId));
    echo "plan=$planId\n";
}
echo "linked_tcversions=" . intval(
    $db->fetchOneValue("SELECT COUNT(*) c FROM testplan_tcversions WHERE testplan_id=$planId")) . "\n";

$rows = $db->get_recordset("SELECT id FROM builds WHERE testplan_id=$planId AND name='I422 Build'");
if ($rows) {
    echo "build exists id=" . intval($rows[0]['id']) . "\n";
} else {
    $bm = new build($db);
    $buildId = intval($bm->create($planId, 'I422 Build', 'fixture build'));
    echo "build=$buildId\n";
}
