<?php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tsuiteMgr = new testsuite($db);
$tcaseMgr = new testcase($db);
$tplanMgr = new testplan($db);

function firstId($r) {
    if (is_array($r)) {
        if (isset($r['id'])) { return intval($r['id']); }
        $k = array_keys($r);
        return intval($k[0]);
    }
    return intval($r);
}

$old = $tprojMgr->get_by_name('RTCF8');
foreach ((array)$old as $row) {
    $oid = intval($row['id']);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'RTCF8';
$item->prefix = 'R8';
$item->notes = 'fixture for issue 1221/1223 (8 active builds)';
$item->color = '';
$item->active = 1;
$item->is_public = 1;
$opts = new stdClass();
$opts->requirementsEnabled = 0;
$opts->testPriorityEnabled = 1;
$opts->automationEnabled = 0;
$opts->inventoryEnabled = 0;
$item->options = $opts;
$idP = $tprojMgr->create($item);
echo "tproject=$idP\n";

$idS1 = firstId($tsuiteMgr->create($idP, 'Suite 1', 'details', null, null, 1));
echo "tsuite=$idS1\n";

$idTCs = [];
foreach ([['TC-ok', 'p'], ['TC-ko', 'f']] as [$nm, ]) {
    $idT = firstId($tcaseMgr->create($idS1, $nm, 'summary', 'precond',
        [['step_number' => 1, 'actions' => 'do it', 'expected_results' => 'works']], 1));
    $idTCs[$nm] = is_array($idT) ? $idT['id'] : $idT;
}
echo "tcok={$idTCs['TC-ok']} tcko={$idTCs['TC-ko']}\n";

$idTP = $tplanMgr->create('Plan8', 'plan WITH 8 active builds', $idP, 1, 1);
echo "tplan=$idTP\n";

$tbl = tlObjectWithDB::getDBTables(['nodes_hierarchy', 'tcversions']);
$linkItems = ['items' => [], 'tcversion' => []];
$tcv = [];
foreach ($idTCs as $nm => $idT) {
    $rs = $db->get_recordset(
        " SELECT NH.id FROM {$tbl['nodes_hierarchy']} NH" .
        " JOIN {$tbl['tcversions']} TV ON TV.id = NH.id" .
        " WHERE NH.parent_id = " . intval($idT) .
        " AND TV.active = 1 ORDER BY TV.version");
    $tv = intval($rs[0]['id']);
    $linkItems['tcversion'][$idT] = $tv;
    $linkItems['items'][$idT] = [0 => $tv];
    $tcv[$nm] = $tv;
}
$ret = $tplanMgr->link_tcversions($idTP, $linkItems, 1,
    array('getTCPrefixFromTPlan' => true));
echo "linkTcversions ret=" . var_export($ret, true) . "\n";

$buildMgr = new build($db);
$buildIds = [];
for ($i = 1; $i <= 8; $i++) {
    $bid = $buildMgr->create($idTP, 'B' . $i, 'build ' . $i);
    $buildIds[] = intval($bid);
    echo "build B$i=$bid\n";
}

$tblE = tlObjectWithDB::getDBTables(['executions']);
$now = date('Y-m-d H:i:s');
$idx = 0;
foreach ([['TC-ok', 'p'], ['TC-ko', 'f']] as [$nm, $st]) {
    foreach ($buildIds as $bid) {
        $idx++;
        $sql = " INSERT INTO {$tblE['executions']} " .
               " (build_id,tester_id,execution_ts,status,testplan_id,tcversion_id," .
               "  tcversion_number,platform_id,notes)" .
               " VALUES ($bid,1,'$now','$st',$idTP," . $tcv[$nm] . ",1,0,'fixture-$idx')";
        $db->exec_query($sql);
    }
}
echo "executions written (2 TCs x 8 builds)\n";

echo "DONE tproject=$idP tplan=$idTP builds=" . implode(',', $buildIds) . "\n";