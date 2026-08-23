<?php
// Fixture for issue #423 browser testing: project + suite + 1 TC + plan +
// build + TC linked to plan, so resultsNavigator shows the reports menu.
// Run from repo root: php tmp/fixtures_423.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tsuiteMgr = new testsuite($db);
$tcaseMgr = new testcase($db);
$tplanMgr = new testplan($db);

// idempotency: drop previous run's project if present
$old = $tprojMgr->get_by_name('RPT423');
foreach ((array)$old as $row) {
    $oid = intval($row['id']);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'RPT423';
$item->prefix = 'R423';
$item->notes = 'fixture for issue 423';
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

$idS1 = firstId($tsuiteMgr->create($idP, 'Suite A', 'details', null, null, 1));
echo "tsuite=$idS1\n";

$idTC1 = firstId($tcaseMgr->create($idS1, 'TC-one', 'summary', 'precond',
    [['step_number' => 1, 'actions' => 'do it', 'expected_results' => 'works']], 1));
$idTC1 = is_array($idTC1) ? $idTC1['id'] : $idTC1;
echo "tc1=$idTC1\n";

$idTP = $tplanMgr->create('Plan423', 'plan for 423', $idP, 1, 1);
echo "tplan=$idTP\n";

$tbl = tlObjectWithDB::getDBTables(['nodes_hierarchy', 'tcversions']);
$rs = $db->get_recordset(
    " SELECT NH.id FROM {$tbl['nodes_hierarchy']} NH" .
    " JOIN {$tbl['tcversions']} TV ON TV.id = NH.id" .
    " WHERE NH.parent_id = " . intval($idTC1) .
    " AND TV.active = 1 ORDER BY TV.version");
$tv = intval($rs[0]['id']);

$items = [
    'tcversion' => [$idTC1 => $tv],          // flat map for audit info
    'items'     => [$idTC1 => [0 => $tv]],   // 0 = no platform
];
$ret = $tplanMgr->link_tcversions($idTP, $items, 1,
    array('getTCPrefixFromTPlan' => true));
var_dump(is_array($ret) || $ret === null ? 'linked' : $ret);

// build so reports are enabled
$buildMgr = new build($db);
$bres = $buildMgr->create($idTP, 'B1', 'build for 423');
echo "build=$bres\n";

echo "DONE tproject=$idP tplan=$idTP tcv=$tv\n";
