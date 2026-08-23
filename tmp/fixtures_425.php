<?php
// Fixture for issue #425 browser testing: FLAT test spec — ONE top level suite
// with 2 TCs and NO sub-suites — plus plan without platforms, build, linked
// TCs, 1 PASSED / 1 FAILED execution.
// Run from repo root: php tmp/fixtures_425.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tsuiteMgr = new testsuite($db);
$tcaseMgr = new testcase($db);
$tplanMgr = new testplan($db);

// idempotency: drop previous run's project if present
$old = $tprojMgr->get_by_name('RPT425');
foreach ((array)$old as $row) {
    $oid = intval($row['id']);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'RPT425';
$item->prefix = 'R425';
$item->notes = 'fixture for issue 425 (flat spec)';
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

// FLAT: a single TOP LEVEL suite. No child suites anywhere.
$idS1 = firstId($tsuiteMgr->create($idP, 'Flat Suite', 'top level only', null, null, 1));
echo "tsuite=$idS1\n";

$idTCs = [];
foreach (['TC-pass', 'TC-fail'] as $nm) {
    $idT = firstId($tcaseMgr->create($idS1, $nm, 'summary', 'precond',
        [['step_number' => 1, 'actions' => 'do it', 'expected_results' => 'works']], 1));
    $idTCs[$nm] = is_array($idT) ? $idT['id'] : $idT;
}
echo "tcpass={$idTCs['TC-pass']} tcfail={$idTCs['TC-fail']}\n";

$idTP = $tplanMgr->create('Plan425', 'flat spec plan', $idP, 1, 1);
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
    $linkItems['items'][$idT] = [0 => $tv]; // 0 = no platform
    $tcv[$nm] = $tv;
}
$ret = $tplanMgr->link_tcversions($idTP, $linkItems, 1,
    array('getTCPrefixFromTPlan' => true));
var_dump(is_array($ret) || $ret === null ? 'linked' : $ret);

$buildMgr = new build($db);
$buildId = $buildMgr->create($idTP, 'B1', 'build for 425');
echo "build=$buildId\n";

// executions: TC-pass -> PASSED ('p'), TC-fail -> FAILED ('f')
$tblE = tlObjectWithDB::getDBTables(['executions']);
$now = date('Y-m-d H:i:s');
foreach ([[$tcv['TC-pass'], 'p'], [$tcv['TC-fail'], 'f']] as [$tv, $st]) {
    $sql = " INSERT INTO {$tblE['executions']} " .
           " (build_id,tester_id,execution_ts,status,testplan_id,tcversion_id," .
           "  tcversion_number,platform_id,notes)" .
           " VALUES ($buildId,1,'$now','$st',$idTP,$tv,1,0,'fixture')";
    $db->exec_query($sql);
}
echo "executions written\n";

echo "DONE tproject=$idP tplan=$idTP build=$buildId\n";
