<?php
// Fixture for issue #1415: print.inc.php E_WARNING Undefined variable $buildCfields
// when generating a test report on a build where some TCs have no execution.
// Run from repo root: php tmp/fixtures_1415.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tsuiteMgr = new testsuite($db);
$tcaseMgr = new testcase($db);
$tplanMgr = new testplan($db);
$buildMgr = new build($db);

function fid($r) {
    if (is_array($r)) {
        if (isset($r['id'])) { return intval($r['id']); }
        $k = array_keys($r);
        return intval($k[0]);
    }
    return intval($r);
}

// --- idempotent cleanup ---
$old = $tprojMgr->get_by_name('WPRINT1415');
foreach ((array)$old as $row) {
    $o = intval($row['id']);
    if ($o > 0) { echo "deleting old WPRINT1415 $o\n"; $tprojMgr->delete($o, 1); }
}

$item = new stdClass();
$item->name = 'WPRINT1415';
$item->prefix = 'W1415';
$item->notes = 'issue 1415 - buildCfields undefined warning on no-exec TC';
$item->color = '#9BD1BA';
$item->active = 1;
$item->is_public = 1;
$opts = new stdClass();
$opts->requirementsEnabled = 0;
$opts->testPriorityEnabled = 1;
$opts->automationEnabled = 0;
$opts->inventoryEnabled = 0;
$item->options = $opts;
$idP = fid($tprojMgr->create($item));
echo "tproject=$idP\n";
$db->exec_query("UPDATE testprojects SET options='" .
    $db->prepare_string(serialize($opts)) . "' WHERE id=$idP");

function mkPlatform($db, $tproject_id, $name) {
    $pm = new tlPlatform($db, $tproject_id);
    $p = new stdClass();
    $p->name = $name;
    $p->notes = 'fixture';
    $p->enable_on_design = 1;
    $p->enable_on_execution = 1;
    $op = $pm->create($p);
    if (is_array($op) && isset($op['id']) && $op['id'] > 0) { return intval($op['id']); }
    $tbl = tlObjectWithDB::getDBTables(['platforms']);
    $db->exec_query("INSERT INTO {$tbl['platforms']} (name,testproject_id,notes,enable_on_design,enable_on_execution,is_open)
                     VALUES ('$name',$tproject_id,'fixture',1,1,1)");
    $rs = $db->get_recordset("SELECT id FROM {$tbl['platforms']} WHERE testproject_id=$tproject_id AND name='$name'");
    return intval($rs[0]['id']);
}

$plat1 = mkPlatform($db, $idP, 'P1-1415');
echo "plat1=$plat1\n";

// Create a testsuite
$idSuite = fid($tsuiteMgr->create($idP, 'Suite1415', 'suite for 1415', null, null, 1));
echo "tsuite=$idSuite\n";

// Create 3 TCs
$tcIds = array();
$tvIds = array();
for ($i = 1; $i <= 3; $i++) {
    $idTC = fid($tcaseMgr->create($idSuite, "Case1415-$i", "summary $i", 'precond',
        [['step_number' => 1, 'actions' => "action $i",
          'expected_results' => "expected $i"]], 1));
    $rr = $db->get_recordset(
        " SELECT NH.id FROM nodes_hierarchy NH JOIN tcversions TV ON TV.id = NH.id" .
        " WHERE NH.parent_id = " . intval($idTC) . " AND TV.active = 1 ORDER BY TV.version");
    $tv = intval($rr[0]['id']);
    $tcIds[] = $idTC;
    $tvIds[] = $tv;
    echo "tc$i=$idTC tv$i=$tv\n";
}

// Create a test plan
$idTP = fid($tplanMgr->create('Plan1415', 'issue 1415 plan', $idP, 1, 1));
echo "tplan=$idTP\n";

// Link platform to plan
$pm = new tlPlatform($db, $idP);
$pm->linkToTestplan([$plat1], $idTP);

// Link all 3 TCs to the plan
foreach ($tcIds as $idx => $tcId) {
    $tvId = $tvIds[$idx];
    $linkItems = ['items' => [$tcId => [0 => $tvId]], 'tcversion' => [$tcId => $tvId]];
    $tplanMgr->link_tcversions($idTP, $linkItems, 1, array('getTCPrefixFromTPlan' => true));
}
$db->exec_query("DELETE FROM testplan_tcversions WHERE testplan_id=$idTP");
foreach ($tcIds as $idx => $tcId) {
    $tvId = $tvIds[$idx];
    $db->exec_query("INSERT INTO testplan_tcversions (testplan_id,platform_id,author_id,creation_ts,tcversion_id)
                     VALUES ($idTP,$plat1,1,NOW(),$tvId)");
}
echo "linked 3 TCs to plan on platform 1\n";

// Create build 1 (no executions on either) and build 2 (execution only for TC3)
$b1 = fid($buildMgr->create($idTP, 'Build1415-1', 'build 1'));
$b2 = fid($buildMgr->create($idTP, 'Build1415-2', 'build 2'));
echo "build1=$b1 build2=$b2\n";

// Exec only TC3 on build 2
$db->exec_query(
    "INSERT INTO executions (testplan_id, platform_id, build_id, tester_id," .
    " execution_type, tcversion_id, tcversion_number, status, notes, execution_ts)" .
    " VALUES ($idTP, $plat1, $b2, 1, 1, {$tvIds[2]}, 1, 'p', 'exec tc3 build2', NOW())");
$eid = intval($db->get_recordset("SELECT MAX(id) AS id FROM executions WHERE testplan_id = $idTP AND build_id = $b2")[0]['id']);
echo "execution=$eid on build2 for tc3 only\n";

$projectId = $db->get_recordset("SELECT api_key FROM testprojects WHERE id = $idP")[0]['api_key'];
echo "APIKEY=$projectId\n";
echo "DONE\n";
echo "ids: tproject=$idP tplan=$idTP plat=$plat1 tc1={$tcIds[0]} tc2={$tcIds[1]} tc3={$tcIds[2]} b1=$b1 b2=$b2\n";
