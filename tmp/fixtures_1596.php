<?php
// Fixture for issue #1596: "Assign Requirements" (test case view) and
// "Requirements Bulk Assignment" (suite view) buttons never render because
// testSpec.html gated them on ctx.reqEnabled, which init() never sets.
//
// Creates: tproject `REQ1596` (requirements ENABLED, prefix RQ96) with
//   * one requirement specification `RS1596` holding 2 requirements
//   * one test suite `Suite A` holding 1 test case `REQ1596 TC 01`
// Run from repo root:  php tmp/fixtures_1596.php
require_once('config.inc.php');
require_once('common.php');
require_once('cfg/const.inc.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$suiteMgr = new testsuite($db);
$tcaseMgr = new testcase($db);
$userId = 1; // admin

foreach ((array)$tprojMgr->get_by_name('REQ1596') as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'REQ1596';
$item->prefix = 'RQ96';
$item->notes = 'fixture for issue 1596 (requirement linking buttons never render)';
$item->color = '';
$item->active = 1;
$item->is_public = 1;
$opts = new stdClass();
$opts->requirementsEnabled = 1;   // <-- the flag under test
$opts->testPriorityEnabled = 0;
$opts->automationEnabled = 0;
$opts->inventoryEnabled = 0;
$opts->platformsEnabled = 0;
$opts->testcasecfEnabled = 0;
$opts->requirementcfEnabled = 0;
$opts->testScriptEnabled = 0;
$item->options = $opts;
$idP = intval($tprojMgr->create($item));
if ($idP <= 0) { fwrite(STDERR, "tproject create failed\n"); exit(1); }
$tprojMgr->setActive($idP, 1);
$_SESSION['testprojectID'] = $idP;
echo "tproject=$idP\n";

// requirement specification + 2 requirements
$specMgr = new requirement_spec_mgr($db);
$reqMgr = new requirement_mgr($db);
$srSpec = $specMgr->create($idP, $idP, 'RS1596', 'RS1596 Specification', 'Spec for #1596', 10, $userId, TL_REQ_SPEC_TYPE_USER_REQ_SPEC);
if (empty($srSpec['status_ok']) || intval($srSpec['id']) <= 0) {
    fwrite(STDERR, "req spec create failed: " . print_r($srSpec, true) . "\n");
    exit(1);
}
$idSpec = intval($srSpec['id']);
echo "reqspec=$idSpec\n";

$reqIds = [];
foreach ([['REQ-001', 'The system shall log in'], ['REQ-002', 'The system shall log out']] as $idx => $rq) {
    $ret = $reqMgr->create($idSpec, $rq[0], $rq[0] . ' ' . $rq[1], $rq[1], $userId);
    if (empty($ret['status_ok']) || intval($ret['id']) <= 0) {
        fwrite(STDERR, "requirement create failed: " . print_r($ret, true) . "\n");
        exit(1);
    }
    $reqIds[] = intval($ret['id']);
}
echo "reqs=" . implode(',', $reqIds) . "\n";

// test suite + 1 test case
$sr = $suiteMgr->create($idP, 'Suite A', 'fixture suite #1596', null);
if (empty($sr['status_ok']) || intval($sr['id']) <= 0) {
    fwrite(STDERR, "suite create failed: " . print_r($sr, true) . "\n");
    exit(1);
}
$idS = intval($sr['id']);
echo "suite=$idS\n";

$steps = [
    ['step_number' => 1, 'actions' => 'Open the app',
     'expected_results' => 'Login form shown', 'execution_type' => TESTCASE_EXECUTION_TYPE_MANUAL],
];
$tr = $tcaseMgr->create($idS, 'REQ1596 TC 01', 'Verify session #1596', '', $steps, $userId);
if (empty($tr['status_ok']) || intval($tr['id']) <= 0) {
    fwrite(STDERR, "tc create failed: " . print_r($tr, true) . "\n");
    exit(1);
}
$idTC = intval($tr['id']);
echo "tc=$idTC\n";

$state = "issue1596_idP=$idP idSpec=$idSpec idS=$idS idTC=$idTC reqs=" . implode(',', $reqIds) . "\n";
file_put_contents(__DIR__ . '/state_1596.env', $state);
echo "FIXTURE_OK $state";
