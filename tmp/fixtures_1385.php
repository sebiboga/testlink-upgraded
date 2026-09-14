<?php
// Fixture for Task #1385 (planExport 4results form_token testcases_to_show).
// Recreates the issue fixture: project ExportFixture, plan "PlanExport One",
// suite "ExportFixture Suite", 3 linked TCs EXP-1/2/3, build R1.
// Run from repo root: php tmp/fixtures_1385.php
require_once(__DIR__ . '/../config.inc.php');
require_once(__DIR__ . '/../lib/functions/common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tsuiteMgr = new testsuite($db);
$tcaseMgr = new testcase($db);
$tplanMgr = new testplan($db);
$buildMgr = new build($db);

function firstId($r) {
    if (is_array($r)) {
        if (isset($r['id'])) { return intval($r['id']); }
        $k = array_keys($r);
        return intval($k[0]);
    }
    return intval($r);
}

// idempotency
$old = $tprojMgr->get_by_name('ExportFixture');
foreach ((array)$old as $row) {
    $oid = intval($row['id']);
    if ($oid > 0) { echo "deleting old project $oid\n"; $tprojMgr->delete($oid, 1); }
}

$item = new stdClass();
$item->name = 'ExportFixture';
$item->prefix = 'EXP';
$item->notes = 'fixture for task 1385 (planExport 4results form_token scoping)';
$item->color = '';
$item->active = 1;
$item->is_public = 1;
$opts = new stdClass();
$opts->requirementsEnabled = 0;
$opts->testPriorityEnabled = 0;
$opts->automationEnabled = 0;
$opts->inventoryEnabled = 0;
$item->options = $opts;
$idP = $tprojMgr->create($item);
echo "tproject=$idP\n";
if ($idP <= 0) { die("project create failed\n"); }

$idS1 = firstId($tsuiteMgr->create($idP, 'ExportFixture Suite', 'suite details text', null, null, 1));
echo "tsuite=$idS1\n";

// 3 TCs EXP-1/2/3
$tcv = [];
foreach (['EXP-1', 'EXP-2', 'EXP-3'] as $nm) {
    $idT = firstId($tcaseMgr->create($idS1, $nm, "test case $nm", "precondition $nm",
        [['step_number' => 1, 'actions' => "$nm step action", 'expected_results' => "$nm step expected"]], 1));
    echo "tc $nm=$idT\n";
    $rs = $db->get_recordset(
        " SELECT NH.id FROM nodes_hierarchy NH JOIN tcversions TV ON TV.id = NH.id" .
        " WHERE NH.parent_id = " . intval($idT) . " AND TV.active = 1 ORDER BY TV.version");
    $tcv[$nm] = intval($rs[0]['id']);
    $tcaseIds[$nm] = intval($idT);
}
echo "tcversions EXP-1={$tcv['EXP-1']} EXP-2={$tcv['EXP-2']} EXP-3={$tcv['EXP-3']}\n";

// test plan + links
$idTP = $tplanMgr->create('PlanExport One', 'plan for task 1385 export scoping', $idP, 1, 1);
echo "tplan=$idTP\n";
$linkItems = ['items' => [], 'tcversion' => []];
foreach ($tcv as $nm => $tv) {
    $linkItems['items'][$tv] = [0 => $tv];
    $linkItems['tcversion'][$tv] = $tv;
}
$ret = $tplanMgr->link_tcversions($idTP, $linkItems, 1, array('getTCPrefixFromTPlan' => true));
echo "linkTcversions ret=" . var_export($ret, true) . "\n";

// build R1
$bR1 = intval($buildMgr->create($idTP, 'R1', 'build R1'));
echo "build R1=$bR1\n";

echo "TESTCASE_ID_MAP: EXP-1={$tcaseIds['EXP-1']} EXP-2={$tcaseIds['EXP-2']} EXP-3={$tcaseIds['EXP-3']}\n";
echo "PLAN_ID=$idTP TPROJECT_ID=$idP BUILD_ID=$bR1\n";