<?php
// Fixture for screen modernization #660: My Test Case Assignments.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
// Project with priority enabled, 2 plans (active+inactive), open+closed
// builds, platforms, 4 test cases linked to plans, execution assignments
// for admin + tester1. Run from repo root: php tmp/fixtures_660.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tsuiteMgr = new testsuite($db);
$tcaseMgr = new testcase($db);
$tplanMgr = new testplan($db);
$platMgr = null;
$assignMgr = new assignment_mgr($db);

function firstId($r) {
    if (is_array($r)) {
        if (isset($r['id'])) { return intval($r['id']); }
        $k = array_keys($r);
        return intval($k[0]);
    }
    return intval($r);
}

// idempotency: drop previous run's projects
foreach (['LOC660', 'LOC660B'] as $nm) {
    $old = $tprojMgr->get_by_name($nm);
    foreach ((array)$old as $row) {
        $oid = intval($row['id']);
        if ($oid > 0) {
            echo "deleting old project $oid ($nm)\n";
            $tprojMgr->delete($oid, 1);
        }
    }
}

$item = new stdClass();
$item->name = 'LOC660';
$item->prefix = 'L660';
$item->notes = 'fixture for 660';
$item->color = '';
$item->active = 1;
$item->is_public = 1;
$opts = new stdClass();
$opts->requirementsEnabled = 0;
$opts->testPriorityEnabled = 1;   // priority column ON
$opts->automationEnabled = 1;
$opts->inventoryEnabled = 0;
$item->options = $opts;
$idP = $tprojMgr->create($item);
echo "tproject=$idP\n";

$idS1 = firstId($tsuiteMgr->create($idP, 'Suite 660', 'details', null, null, 1));
$idS2 = firstId($tsuiteMgr->create($idS1, 'Sub 660', 'details', null, null, 1));
echo "tsuites=$idS1/$idS2\n";

function mkTc($tcaseMgr, $suite, $name) {
    $idT = $tcaseMgr->create($suite, $name, 'summary', 'precond',
        [['step_number' => 1, 'actions' => 'do it', 'expected_results' => 'works']], 1);
    $idTC = is_array($idT) ? $idT['id'] : firstId($idT);
    echo "tcase $name=$idTC\n";
    return $idTC;
}
$idTCa = mkTc($tcaseMgr, $idS1, 'TC-alpha');   // low prio
$idTCb = mkTc($tcaseMgr, $idS2, 'TC-beta');    // high prio
$idTCc = mkTc($tcaseMgr, $idS1, 'TC-gamma');
$idTCd = mkTc($tcaseMgr, $idS2, 'TC-delta');

// importance: LOW=1 MEDIUM=2 HIGH=3 (set beta high so prio column varies)
$db->exec_query("UPDATE tcversions SET importance=3 WHERE id IN (
  SELECT id FROM nodes_hierarchy WHERE parent_id={$idTCb})");
$db->exec_query("UPDATE tcversions SET importance=1 WHERE id IN (
  SELECT id FROM nodes_hierarchy WHERE parent_id={$idTCa})");

function activeVersionId($db, $tcid) {
    $tbl = tlObjectWithDB::getDBTables(['nodes_hierarchy', 'tcversions']);
    $rs = $db->get_recordset(
        " SELECT NH.id FROM {$tbl['nodes_hierarchy']} NH" .
        " JOIN {$tbl['tcversions']} TV ON TV.id = NH.id" .
        " WHERE NH.parent_id = " . intval($tcid) .
        " AND TV.active = 1 ORDER BY TV.version");
    return intval($rs[0]['id']);
}
$vA = activeVersionId($db, $idTCa);
$vB = activeVersionId($db, $idTCb);
$vC = activeVersionId($db, $idTCc);
$vD = activeVersionId($db, $idTCd);

// platforms
$platMgr = new tlPlatform($db, $idP);
$pObj = new stdClass();
$pObj->name = 'PLAT-X';
$pObj->notes = '';
$pObj->enable_on_design = 1;
$pObj->enable_on_execution = 1;
$pOp = $platMgr->create($pObj);
$idPlatX = intval($pOp['id']);
echo "platform=$idPlatX\n";

// PLAN A: active, has platforms; PLAN B: INACTIVE, no platforms
$idTPA = $tplanMgr->create('Plan660A', 'active plan', $idP, 1, 1);
$idTPB = $tplanMgr->create('Plan660B', 'inactive plan', $idP, 0, 1);
echo "tplans=$idTPA/$idTPB\n";

$platMgr2 = new tlPlatform($db, $idP);
$platMgr2->linkToTestplan($idPlatX, $idTPA);

// link TCs: plan A gets alpha(beta? no) alpha,beta,gamma with platform X on beta
$itemsA = ['tcversion' => [$vA => $vA, $vB => $vB, $vC => $vC], 'items' => []];
$itemsA['items'][$idTCa] = [0 => $vA];
$itemsA['items'][$idTCb] = [$idPlatX => $vB];
$itemsA['items'][$idTCc] = [0 => $vC];
$tplanMgr->link_tcversions($idTPA, $itemsA, 1);

// plan B (inactive) gets delta
$itemsB = ['tcversion' => [$vD => $vD], 'items' => []];
$itemsB['items'][$idTCd] = [0 => $vD];
$tplanMgr->link_tcversions($idTPB, $itemsB, 1);
echo "linked\n";

// builds on plan A: B1 open, B2 closed
$buildObj = new build($db);
$idB1 = firstId($buildObj->create($idTPA, 'BUILD-OPEN', 'open build'));
$idB2 = firstId($buildObj->create($idTPA, 'BUILD-CLOSED', 'closed build', 1, 0));
$btbl = tlObjectWithDB::getDBTables(['builds']);
$db->exec_query("UPDATE {$btbl['builds']} SET is_open=0 WHERE id={$idB2}");
echo "builds=$idB1(open)/$idB2(closed)\n";

// second user tester1
$uObj = new tlUser();
$uObj->login = 'tester1';
$uObj->emailAddress = 'tester1@example.com';
$uObj->firstName = 'Test';
$uObj->lastName = 'Er';
$res = $uObj->writeToDB($db);
$idU2 = intval($uObj->dbID ?? $res);
echo "tester1=$idU2 res=" . var_export($res, true) . "\n";

// features: find testplan_tcversions rows for our links
$tpt = tlObjectWithDB::getDBTables(['testplan_tcversions'])['testplan_tcversions'];
$fA = intval($db->fetchFirstRow(
    "SELECT id FROM {$tpt} WHERE testplan_id={$idTPA} AND tcversion_id={$vA} AND platform_id=0")['id']);
$fB = intval($db->fetchFirstRow(
    "SELECT id FROM {$tpt} WHERE testplan_id={$idTPA} AND tcversion_id={$vB} AND platform_id={$idPlatX}")['id']);
$fC = intval($db->fetchFirstRow(
    "SELECT id FROM {$tpt} WHERE testplan_id={$idTPA} AND tcversion_id={$vC} AND platform_id=0")['id']);
$fD = intval($db->fetchFirstRow(
    "SELECT id FROM {$tpt} WHERE testplan_id={$idTPB} AND tcversion_id={$vD} AND platform_id=0")['id']);
echo "features A/B/C/D=$fA/$fB/$fC/$fD\n";

// assignments:
// - admin assigned on alpha @ BUILD-OPEN (due long ago)
// - tester1 assigned on beta @ BUILD-OPEN
// - tester1 assigned on gamma @ CLOSED build (hidden unless show closed)
// - admin assigned on delta in inactive plan B
$types = $assignMgr->get_available_types();
$exType = intval($types['testcase_execution']['id']);
$statuses = $assignMgr->get_available_status();
$opStatus = intval($statuses['open']['id']);
$ua = tlObjectWithDB::getDBTables(['user_assignments'])['user_assignments'];
$now = $db->db_now();
$oldTs = 'DATE_SUB(NOW(), INTERVAL 45 DAY)';
$ins = function($uid, $build, $feat, $old) use ($db, $ua, $exType, $opStatus, $now, $oldTs) {
    $ts = $old ? $oldTs : $now;
    $sql = "INSERT INTO {$ua} (type,status,user_id,build_id,feature_id,assigner_id,creation_ts)" .
           " VALUES ({$exType},{$opStatus},{$uid},{$build},{$feat},1,{$ts})";
    $db->exec_query($sql);
};
$ins(1, $idB1, $fA, true);   // alpha: admin, old
$ins($idU2, $idB1, $fB, false); // beta: tester1
$ins($idU2, $idB2, $fC, true);  // gamma: tester1 @ closed build
$ins(1, $idB1, $fD, false);     // delta: admin in INACTIVE plan B
echo "assignments done\n";

// one prior execution on alpha -> status passed visible
$ex = tlObjectWithDB::getDBTables(['executions']);
$db->exec_query("INSERT INTO {$ex['executions']} " .
    "(testplan_id,platform_id,build_id,tester_id,execution_type,tcversion_id," .
    "execution_ts,status,notes) VALUES ({$idTPA},0,{$idB1},1,1,{$vA},NOW(),'p','ok')");

echo "DONE tproject=$idP tplans=$idTPA,$idTPB builds=$idB1,$idB2 plat=$idPlatX users=1,$idU2\n";
