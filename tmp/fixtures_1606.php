<?php
// Fixture for issue #1606: tree::getNodeTable() / node_tables_by['id'] lacks
// the two tableless PSEUDO node types testcase_step (9) and build (12).
// Creates test project `TQ1606` (prefix TQ1) with:
//   test project -> 1 test suite -> 1 test case WITH 2 STEPS -> 1 test plan
// The 2 steps give 2 nodes_hierarchy rows with node_type_id = 9, which is what
// the 5 raw readers of node_tables_by['id'] could not resolve.
// Re-runnable. Run from repo root: php tmp/fixtures_1606.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tplanMgr = new testplan($db);
$tcaseMgr = new testcase($db);
$tsuiteMgr = new testsuite($db);

$adminId = 1;

// ---------------------------------------------------------------- reset ----
foreach ((array)$tprojMgr->get_by_name('TQ1606') as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) {
        $tprojMgr->delete($oid, 1);
    }
}

// ---------------------------------------------------------------- project --
$item = new stdClass();
$item->name = 'TQ1606';
$item->notes = 'fixture for #1606 node_tables_by[id]';
$item->prefix = 'TQ1';
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
    die("project create failed\n");
}

$opS1 = $tsuiteMgr->create($idP, 'TQ1606-S1', 'suite 1');
$idS1 = intval($opS1['id']);
if (!$idS1) {
    die("suite create failed\n");
}

$idTP = intval($tplanMgr->create('TQ1606-P1', 'fixture plan for #1606', $idP));
if (!$idTP) {
    die("testplan create failed\n");
}

// ----------------------------------------------- test case WITH 2 STEPS ----
$steps = array();
foreach (array(1, 2) as $n) {
    $steps[$n - 1] = new stdClass();
    $steps[$n - 1]->step_number = $n;
    $steps[$n - 1]->actions = "fixture step $n action for #1606";
    $steps[$n - 1]->expected_results = "fixture step $n expected result";
    $steps[$n - 1]->execution_type = TESTCASE_EXECUTION_TYPE_MANUAL;
}
$ret = $tcaseMgr->create($idS1, 'TQ1606-TC1', 'fixture tc for #1606', '', $steps, 1,
    '', 1, testcase::AUTOMATIC_ID, TESTCASE_EXECUTION_TYPE_MANUAL, 2);
if (empty($ret['status_ok']) || empty($ret['id'])) {
    die('tcase create failed: ' . $ret['message'] . "\n");
}
$idC1 = intval($ret['id']);
$idV1 = intval($ret['tcversion_id']);

// node_type_id = 9 rows (the tableless pseudo type)
$stepNodes = array_keys($db->fetchRowsIntoMap(
    "SELECT id FROM nodes_hierarchy WHERE node_type_id = 9 AND parent_id = " . $db->prepare_int($idV1) . " ORDER BY id", 'id'));

file_put_contents('tmp/fixture_1606.json', json_encode(array(
    'idP' => $idP, 'idS1' => $idS1, 'idC1' => $idC1, 'idV1' => $idV1, 'idTP' => $idTP,
    'step_nodes' => array_map('intval', $stepNodes),
)));

echo "idP=$idP idS1=$idS1 idC1=$idC1 idV1=$idV1 idTP=$idTP step_nodes=" .
    implode(',', $stepNodes) . "\n";
