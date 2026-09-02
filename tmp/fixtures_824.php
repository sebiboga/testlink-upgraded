<?php
// Fixture for issue #824 browser verification: modernized Search Test Cases
// screen URL deep-link prefill. Project with priority enabled + TCs + plan so
// the context endpoint loads and the prefill code path (which reads `p`) runs.
// Run from repo root: php tmp/fixtures_824.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tsuiteMgr = new testsuite($db);
$tcaseMgr = new testcase($db);
$tplanMgr = new testplan($db);

$old = $tprojMgr->get_by_name('SEARCH824');
foreach ((array)$old as $row) {
    $oid = intval($row['id']);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'SEARCH824';
$item->prefix = 'S824';
$item->notes = 'fixture for issue 824 (search prefill)';
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

function firstId($r) {
    if (is_array($r)) {
        if (isset($r['id'])) { return intval($r['id']); }
        $k = array_keys($r);
        return intval($k[0]);
    }
    return intval($r);
}

$idS = firstId($tsuiteMgr->create($idP, 'Suite 824', 'search fixture suite', null, null, 1));
echo "tsuite=$idS\n";

$tcv = [];
foreach (['API Key Display and Regenerate', 'Dashboard Statistics'] as $i => $nm) {
    $idT = $tcaseMgr->create($idS, $nm, 'summary of ' . $nm, 'precond',
        [['step_number' => 1, 'actions' => 'action ' . $nm,
          'expected_results' => 'expected ' . $nm]], 1);
    $idTC = is_array($idT) ? $idT['id'] : $idT;
    $rs = $db->get_recordset(
        " SELECT NH.id FROM nodes_hierarchy NH" .
        " JOIN tcversions TV ON TV.id = NH.id" .
        " WHERE NH.parent_id = " . intval($idTC) .
        " AND TV.active = 1 ORDER BY TV.version");
    $tcv[$nm] = intval($rs[0]['id']);
}
echo "tcversions: " . json_encode($tcv) . "\n";

$idTP = $tplanMgr->create('Plan824', 'search fixture plan', $idP, 1, 1);
echo "tplan=$idTP\n";

$linkItems = ['items' => [], 'tcversion' => []];
foreach ($tcv as $nm => $tv) {
    $idTC = $db->get_recordset(
        " SELECT parent_id AS id FROM nodes_hierarchy WHERE id = " . intval($tv));
    $idTC = intval($idTC[0]['id']);
    $linkItems['tcversion'][$idTC] = $tv;
    $linkItems['items'][$idTC] = [0 => $tv];
}
$ret = $tplanMgr->link_tcversions($idTP, $linkItems, 1,
    array('getTCPrefixFromTPlan' => true));
echo 'linked: ' . var_export($ret === null, true) . "\n";

echo "DONE tproject=$idP tplan=$idTP\n";