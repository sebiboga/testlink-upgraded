<?php
// Fixture for issue #1410: print.inc.php:1234 E_WARNING when printing a testplan
// report with step exec options (step_exec_status / step_exec_notes) and a TC
// linked on a platform that has NO execution.
//
// Structure: 1 project, 2 platforms, 1 test plan with both platforms,
// 1 TC with steps linked on BOTH platforms, executions ONLY on platform 1.
// Run from repo root: php tmp/fixtures_1410.php
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
$old = $tprojMgr->get_by_name('WPRINT1410');
foreach ((array)$old as $row) {
    $o = intval($row['id']);
    if ($o > 0) { echo "deleting old WPRINT1410 $o\n"; $tprojMgr->delete($o, 1); }
}

$item = new stdClass();
$item->name = 'WPRINT1410';
$item->prefix = 'W1410';
$item->notes = 'issue 1410 - print step exec options on no-execution platform';
$item->color = '';
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

$plat1 = mkPlatform($db, $idP, 'P-ONLY-EXEC');
$plat2 = mkPlatform($db, $idP, 'P-NO-EXEC');
echo "plat1=$plat1 plat2=$plat2\n";

$idS = fid($tsuiteMgr->create($idP, 'Suite1410', 'suite', null, null, 1));
echo "tsuite=$idS\n";

$idTC = fid($tcaseMgr->create($idS, 'Case1410', 'summary 1410', 'precond 1410',
    [['step_number' => 1, 'actions' => 'action one',
      'expected_results' => 'expected one'],
     ['step_number' => 2, 'actions' => 'action two',
      'expected_results' => 'expected two']], 1));
$rr = $db->get_recordset(
    " SELECT NH.id FROM nodes_hierarchy NH JOIN tcversions TV ON TV.id = NH.id" .
    " WHERE NH.parent_id = " . intval($idTC) . " AND TV.active = 1 ORDER BY TV.version");
$tv1410 = intval($rr[0]['id']);
echo "tc=$idTC tcversion=$tv1410\n";

$idTP = fid($tplanMgr->create('Plan1410', 'issue 1410 plan', $idP, 1, 1));
echo "tplan=$idTP\n";

$pm = new tlPlatform($db, $idP);
$pm->linkToTestplan([$plat1, $plat2], $idTP);

$linkItems = ['items' => [$idTC => [0 => $tv1410]], 'tcversion' => [$idTC => $tv1410]];
$tplanMgr->link_tcversions($idTP, $linkItems, 1, array('getTCPrefixFromTPlan' => true));
// ensure the platform links for the tcversion exist on BOTH platforms
$db->exec_query("REPLACE INTO testplan_tcversions (testplan_id,platform_id,author_id,creation_ts,tcversion_id)
                 VALUES ($idTP,$plat1,1,NOW(),$tv1410),($idTP,$plat2,1,NOW(),$tv1410)");
echo "linked tc on both platforms\n";

$bOpen = fid($buildMgr->create($idTP, 'Build1410-1', 'build one'));
echo "build open=$bOpen\n";

// execution ONLY on platform 1
$db->exec_query(
    "INSERT INTO executions (testplan_id, platform_id, build_id, tester_id," .
    " execution_type, tcversion_id, tcversion_number, status, notes, execution_ts)" .
    " VALUES ($idTP, $plat1, $bOpen, 1, 1, $tv1410, 1, 'p', 'exec on plat1 only', NOW())");
$eid = intval($db->get_recordset("SELECT MAX(id) AS id FROM executions")[0]['id']);
$stepRows = $db->get_recordset(" SELECT TC.id FROM tcsteps TC JOIN nodes_hierarchy NH ON NH.id = TC.id WHERE NH.parent_id = " . intval($tv1410));
foreach ($stepRows as $sr) {
    $db->exec_query("INSERT INTO execution_tcsteps (execution_id, tcstep_id, notes, status) VALUES ($eid, {$sr['id']}, 'step note p1', 'p')");
}
echo "execution=$eid on plat1 only\n";

echo "DONE\n";
echo "ids: tproject=$idP tplan=$idTP plat1=$plat1 plat2=$plat2 tc=$idTC tcversion=$tv1410 exec=$eid build=$bOpen\n";