<?php
// Fixture for issue #1607: gives tree::get_subtree() something to traverse.
// Creates test project `TQ1607` (prefix TQ1) with:
//   - test project -> 2 test suites -> 1 testcase each
//   - a test plan with both testcases
//   - a requirement spec (doc_id set) whose parent is the project
//   - a child requirement spec under the first req spec
// Re-runnable. Run from repo root: php tmp/fixtures_1607.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tplanMgr = new testplan($db);
$tcaseMgr = new testcase($db);
$tsuiteMgr = new testsuite($db);
$reqMgr = new requirement_spec_mgr($db);

$adminId = 1;

// ---------------------------------------------------------------- reset ----
foreach ((array)$tprojMgr->get_by_name('TQ1607') as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) {
        $tprojMgr->delete($oid, 1);
    }
}

// ---------------------------------------------------------------- project --
$item = new stdClass();
$item->name = 'TQ1607';
$item->notes = 'fixture for #1607 tree static leak';
$item->prefix = 'TQ1';
$item->color = '';
$item->active = 1;
$item->is_public = 1;
$opts = new stdClass();
$opts->requirementsEnabled = 1;
$opts->testPriorityEnabled = 0;
$opts->automationEnabled = 0;
$opts->inventoryEnabled = 0;
$opts->platformsEnabled = 0;
$opts->testcasecfEnabled = 0;
$opts->requirementcfEnabled = 0;
$item->options = $opts;
$idP = intval($tprojMgr->create($item));
if ($idP <= 0) {
    die("project create failed\n");
}

$opS1 = $tsuiteMgr->create($idP, 'TQ1607-S1', 'suite 1');
$opS2 = $tsuiteMgr->create($idP, 'TQ1607-S2', 'suite 2');
$idS1 = intval($opS1['id']);
$idS2 = intval($opS2['id']);
if (!$idS1 || !$idS2) {
    die("suite create failed\n");
}

function make_tc_1607($tcaseMgr, $parent, $name, $order) {
    $steps = array();
    $steps[0] = new stdClass();
    $steps[0]->step_number = 1;
    $steps[0]->actions = 'fixture step action for #1607';
    $steps[0]->expected_results = 'fixture expected result';
    $steps[0]->execution_type = TESTCASE_EXECUTION_TYPE_MANUAL;
    $ret = $tcaseMgr->create($parent, $name, 'fixture tc for #1607', '', $steps, 1,
        '', $order, testcase::AUTOMATIC_ID, TESTCASE_EXECUTION_TYPE_MANUAL, 2);
    if (empty($ret['status_ok']) || empty($ret['id'])) {
        die('tcase create failed: ' . $ret['message'] . "\n");
    }
    return array('id' => intval($ret['id']), 'tcversion_id' => intval($ret['tcversion_id']));
}
$idTP = intval($tplanMgr->create('TQ1607-P1', 'fixture plan for #1607', $idP));
if (!$idTP) {
    die("testplan create failed\n");
}
$tc1 = make_tc_1607($tcaseMgr, $idS1, 'TQ1607-TC1', 1);
$tc2 = make_tc_1607($tcaseMgr, $idS2, 'TQ1607-TC2', 1);
$idC1 = $tc1['id'];
$idC2 = $tc2['id'];


// ------------------------------------------------------------- req specs ---
$op = $reqMgr->create($idP, $idP, 'TQ1-REQ-1', 'TQ1607-RS1', '', 0, $adminId, 1);
$idRS1 = intval($op['id']);
if (!$idRS1) {
    die("reqspec create failed: {$op['msg']}\n");
}
$op2 = $reqMgr->create($idP, $idRS1, 'TQ1-REQ-1-1', 'TQ1607-RS1-CHILD', '', 0, $adminId, 1);
$idRS2 = intval($op2['id']);
if (!$idRS2) {
    die("child reqspec create failed: {$op2['msg']}\n");
}

echo "idP=$idP idS1=$idS1 idS2=$idS2 idC1=$idC1 idC2=$idC2 idTP=$idTP idRS1=$idRS1 idRS2=$idRS2\n";
