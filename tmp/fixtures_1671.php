<?php
// Fixture for #1671 browser/curl testing: Reorder Test Steps
// (gui/templates/testcases/tcStepReorder.html + api/tcstepsreorder/index.php).
//
// Creates tproject `StepReorder Demo` (prefix TSR1671), a test plan, a suite
// and three test cases:
//   * one version with 4 steps (the reorder target, incl. an automated step and
//     a step with a RichEdit <b> markup + a <br> to prove the plain-text
//     stripping of the payload),
//   * one version with 2 steps (a second project-less target + boundary moves),
//   * one version with a single step (the "< 2 steps" path).
// The steps are inserted through testcase::create so the node_hierarchy
// parent_id wiring (node_type 9 children of the version) is the real one.
// Reuses tmp/mkuser_norights.php for the 403 path.
// Run from repo root: php tmp/fixtures_1671.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tplanMgr = new testplan($db);
$tcaseMgr = new testcase($db);
$tsuiteMgr = new testsuite($db);
$userId = 1; // admin

// wipe any previous run of this fixture
foreach ((array)$tprojMgr->get_by_name('StepReorder Demo') as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'StepReorder Demo';
$item->prefix = 'TSR1671';
$item->notes = 'fixture for issue 1671 (reorder test case steps)';
$item->color = '';
$item->active = 1;
$item->is_public = 1;
$opts = new stdClass();
$opts->requirementsEnabled = 0;
$opts->testPriorityEnabled = 1;
$opts->automationEnabled = 1;
$opts->inventoryEnabled = 0;
$opts->platformsEnabled = 0;
$opts->testcasecfEnabled = 0;
$opts->requirementcfEnabled = 0;
$item->options = $opts;
$r = $tprojMgr->create($item);
$idP = intval($r);
echo "tproject=$idP (prefix TSR1671)\n";

$idPlan = intval($tplanMgr->create('StepReorder Plan', '', $idP, 1, 1));
echo "testplan=$idPlan\n";

$retS = $tsuiteMgr->create($idP, 'StepReorder Suite', '', null, 0, 'allow_repeat');
$idS = intval($retS['id'] ?? 0);
echo "suite=$idS\n";

function mkSteps($n, $rich = false)
{
    $steps = array();
    for ($i = 1; $i <= $n; $i++) {
        $t = new stdClass();
        $t->step_number = $i;
        $t->actions = ($rich && $i == 2)
            ? 'Check <b>bold</b> markup<br>second line ' . $i
            : 'Do action number ' . $i;
        $t->expected_results = ($rich && $i == 2)
            ? 'Expected <i>result</i> of step 2'
            : 'Expected result of step ' . $i;
        // step 3 of the 4-step version is an automated one
        $t->execution_type = ($i == 3) ? TESTCASE_EXECUTION_TYPE_AUTO : TESTCASE_EXECUTION_TYPE_MANUAL;
        $steps[] = $t;
    }
    return $steps;
}

$makeTcase = function ($name, $stepCount, $rich = false) use ($tcaseMgr, $idS, $userId) {
    $ret = $tcaseMgr->create($idS, $name, 'summary of ' . $name, '',
        mkSteps($stepCount, $rich), $userId, '',
        testcase::DEFAULT_ORDER, testcase::AUTOMATIC_ID, TESTCASE_EXECUTION_TYPE_MANUAL);
    if (!$ret['status_ok'] || $ret['id'] <= 0) {
        die('tcase create failed: ' . ($ret['message'] ?? '') . "\n");
    }
    return array(intval($ret['id']), intval($ret['tcversion_id']));
};

list($idTc4, $idTcv4) = $makeTcase('TSR1671 Four Steps', 4, true);
echo "tcase4=$idTc4 tcversion4=$idTcv4\n";
list($idTc2, $idTcv2) = $makeTcase('TSR1671 Two Steps', 2);
echo "tcase2=$idTc2 tcversion2=$idTcv2\n";
list($idTc1, $idTcv1) = $makeTcase('TSR1671 Single Step', 1);
echo "tcase1=$idTc1 tcversion1=$idTcv1\n";

// a second version of the 4-step test case, so the picker shows several entries
$ret2 = $tcaseMgr->create($idTc4, 'TSR1671 Four Steps', 'v2 summary', '',
    mkSteps(3), $userId, '', testcase::DEFAULT_ORDER, testcase::AUTOMATIC_ID,
    TESTCASE_EXECUTION_TYPE_MANUAL);
echo "tcversion4b=" . intval($ret2['tcversion_id'] ?? 0) . "\n";

// link the 4-step version to the plan (context only)
$tables = tlObjectWithDB::getDBTables(array('testplan_tcversions'));
$db->exec_query("INSERT INTO `{$tables['testplan_tcversions']}` (testplan_id, tcversion_id, node_order) VALUES (" .
                intval($idPlan) . "," . intval($idTcv4) . ",1)");

// no-rights user for the 403 path (role 3)
if (file_exists('tmp/mkuser_norights.php')) {
    include 'tmp/mkuser_norights.php';
} else {
    echo "WARN: tmp/mkuser_norights.php not found - 403 path needs a manual user\n";
}

echo "FIXTURE_DONE idP=$idP idPlan=$idPlan idTcv4=$idTcv4 idTcv2=$idTcv2 idTcv1=$idTcv1\n";
