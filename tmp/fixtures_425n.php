<?php
// Control fixture for #425 positive path: NESTED spec — top suite > child
// suite > 2 TCs, so infoL2 is non-empty and the report must render fully.
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tsuiteMgr = new testsuite($db);
$tcaseMgr = new testcase($db);
$tplanMgr = new testplan($db);

$old = $tprojMgr->get_by_name('RPT425N');
foreach ((array)$old as $row) {
    $oid = intval($row['id']);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'RPT425N';
$item->prefix = 'R425N';
$item->notes = 'fixture for issue 425 positive path (nested)';
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

$idTop = firstId($tsuiteMgr->create($idP, 'Top Suite', 'parent', null, null, 1));
$idChild = firstId($tsuiteMgr->create($idTop, 'Child Suite', 'child', null, null, 1));
echo "top=$idTop child=$idChild\n";

$idTCs = [];
foreach (['NTC-pass', 'NTC-fail'] as $nm) {
    $idT = firstId($tcaseMgr->create($idChild, $nm, 'summary', 'precond',
        [['step_number' => 1, 'actions' => 'do it', 'expected_results' => 'works']], 1));
    $idTCs[$nm] = is_array($idT) ? $idT['id'] : $idT;
}
echo "ntcpass={$idTCs['NTC-pass']} ntcfail={$idTCs['NTC-fail']}\n";

$idTP = $tplanMgr->create('Plan425N', 'nested spec plan', $idP, 1, 1);
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
var_dump(is_array($ret) || $ret === null ? 'linked' : $ret);

$buildMgr = new build($db);
$buildId = $buildMgr->create($idTP, 'B1', 'build');
echo "build=$buildId\n";

$tblE = tlObjectWithDB::getDBTables(['executions']);
$now = date('Y-m-d H:i:s');
foreach ([[$tcv['NTC-pass'], 'p'], [$tcv['NTC-fail'], 'f']] as [$tv, $st]) {
    $sql = " INSERT INTO {$tblE['executions']} " .
           " (build_id,tester_id,execution_ts,status,testplan_id,tcversion_id," .
           "  tcversion_number,platform_id,notes)" .
           " VALUES ($buildId,1,'$now','$st',$idTP,$tv,1,0,'fixture')";
    $db->exec_query($sql);
}
echo "executions written\n";
echo "DONE tproject=$idP tplan=$idTP build=$buildId\n";
