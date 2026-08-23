<?php
// Fixture for issue #427 regression R2/R3: plan Plan427B with TWO platforms
// and saved baselines on both; plan Plan427C with ONE platform + baseline.
// Run from repo root: php tmp/fixtures_427b.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tsuiteMgr = new testsuite($db);
$tplanMgr = new testplan($db);

$old = $tprojMgr->get_by_name('RPT427B');
foreach ((array)$old as $row) {
    $oid = intval($row['id']);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'RPT427B';
$item->prefix = 'R427B';
$item->notes = 'multi-platform baseline fixture for issue 427';
$item->color = '';
$item->active = 1;
$item->is_public = 1;
$opts = new stdClass();
$opts->requirementsEnabled = 0;
$opts->testPriorityEnabled = 0;
$opts->automationEnabled = 1;
$opts->inventoryEnabled = 0;
$item->options = $opts;
$idP = $tprojMgr->create($item);
echo "tproject=$idP\n";

function firstId($r) {
    if (is_array($r)) {
        if (isset($r['id'])) { return intval($r['id']); }
        $k = array_keys($r);
        return intval($k[0]);
    }
    return intval($r);
}

// suite tree: TOP -> CHILD (L1/L2 as the report expects top/child pairs)
$idTOP = firstId($tsuiteMgr->create($idP, 'TOP-1', 'd', null, null, 1));
$idCHI = firstId($tsuiteMgr->create($idTOP, 'CHILD-1', 'd', null, null, 1));
echo "top=$idTOP child=$idCHI\n";

function mkPlatform($db, $tproject_id, $name) {
    $pm = new tlPlatform($db, $tproject_id);
    $p = new stdClass();
    $p->name = $name;
    $p->notes = 'fixture';
    $p->enable_on_design = 1;
    $p->enable_on_execution = 1;
    $op = $pm->create($p);
    if (is_array($op) && isset($op['id']) && $op['id'] > 0) { return intval($op['id']); }
    // fallback to raw insert
    $tbl = tlObjectWithDB::getDBTables(['platforms']);
    $db->exec_query("INSERT INTO {$tbl['platforms']} (name,testproject_id,notes,enable_on_design,enable_on_execution,is_open)
                     VALUES ('$name',$tproject_id,'fixture',1,1,1)");
    $rs = $db->get_recordset("SELECT id FROM {$tbl['platforms']} WHERE testproject_id=$tproject_id AND name='$name'");
    return intval($rs[0]['id']);
}

$plat1 = mkPlatform($db, $idP, 'P-ONE');
$plat2 = mkPlatform($db, $idP, 'P-TWO');
echo "plat1=$plat1 plat2=$plat2\n";

// ---- Plan427B: BOTH platforms linked, baselines saved on both ----------
$idTPB = $tplanMgr->create('Plan427B', 'two platforms with baselines', $idP, 1, 1);
$pm = new tlPlatform($db, $idP);
$pm->linkToTestplan([$plat1, $plat2], $idTPB);
echo "tplanB=$idTPB\n";

// ---- Plan427C: ONE platform linked, baseline saved on it ----------------
$idTPC = $tplanMgr->create('Plan427C', 'one platform with baseline', $idP, 1, 1);
$pm->linkToTestplan([$plat1], $idTPC);
echo "tplanC=$idTPC\n";

// ---- baseline rows ------------------------------------------------------
$tbl = tlObjectWithDB::getDBTables(['baseline_l1l2_context','baseline_l1l2_details']);

function saveBaseline($db, $tbl, $tplan_id, $platform_id, $top, $child) {
    $ctxSql = " INSERT INTO {$tbl['baseline_l1l2_context']} " .
              " (testplan_id,platform_id,begin_exec_ts,end_exec_ts,creation_ts) VALUES " .
              " ($tplan_id,$platform_id,'2026-08-01 08:00:00','2026-08-10 18:00:00','2026-08-11 09:00:00')";
    $db->exec_query($ctxSql);
    $cid = $db->insert_id();
    // two statuses per child: passed qty 7 / not_run qty 3 of total_tc 10
    foreach ([['p',7],['n',3]] as [$st,$qty]) {
        $db->exec_query(" INSERT INTO {$tbl['baseline_l1l2_details']} " .
            " (context_id,top_tsuite_id,child_tsuite_id,status,qty,total_tc) VALUES " .
            " ($cid,$top,$child,'$st',$qty,10)");
    }
    echo "  baseline ctx=$cid tplan=$tplan_id plat=$platform_id\n";
}

saveBaseline($db, $tbl, $idTPB, $plat1, $idTOP, $idCHI);
saveBaseline($db, $tbl, $idTPB, $plat2, $idTOP, $idCHI);
saveBaseline($db, $tbl, $idTPC, $plat1, $idTOP, $idCHI);

// sanity
$rs = $db->get_recordset("SELECT testplan_id,platform_id,id FROM {$tbl['baseline_l1l2_context']} ORDER BY id");
var_export($rs); echo "\n";
echo "DONE tproject=$idP tplanB=$idTPB tplanC=$idTPC plat1=$plat1 plat2=$plat2\n";
