<?php
// Fixture for issue #781 browser testing: modernized Dashboard widget rendering.
// Project with TCs + plan with platforms + build + recorded executions so the
// execution-status pie, status table, monthly growth and bugs widgets populate.
// Run from repo root: php tmp/fixtures_781.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tsuiteMgr = new testsuite($db);
$tcaseMgr = new testcase($db);
$tplanMgr = new testplan($db);

// idempotency: drop previous run's project if present
$old = $tprojMgr->get_by_name('DASH781');
foreach ((array)$old as $row) {
    $oid = intval($row['id']);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'DASH781';
$item->prefix = 'D781';
$item->notes = 'fixture for issue 781 (dashboard i18n)';
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

$idS1 = firstId($tsuiteMgr->create($idP, 'Suite One', 'one cases', null, null, 1));
$idS2 = firstId($tsuiteMgr->create($idP, 'Suite Two', 'two cases', null, null, 1));
echo "tsuite_a=$idS1 tsuite_b=$idS2\n";

$tcv = [];
foreach ([['Case A1', $idS1], ['Case A2', $idS1], ['Case A3', $idS1],
          ['Case B1', $idS2], ['Case B2', $idS2]] as $i => [$nm, $suit]) {
    $idT = $tcaseMgr->create($suit, $nm, 'summary of ' . $nm, 'precond',
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

$idTP = $tplanMgr->create('Plan781', 'dashboard fixture plan', $idP, 1, 1);
echo "tplan=$idTP\n";

$linkItems = ['items' => [], 'tcversion' => []];
foreach ($tcv as $nm => $tv) {
    $idTC = $db->get_recordset(
        " SELECT parent_id AS id FROM nodes_hierarchy WHERE id = " . intval($tv));
    $idTC = intval($idTC[0]['id']);
    $linkItems['tcversion'][$idTC] = $tv;
    $linkItems['items'][$idTC] = [0 => $tv]; // 0 = no platform
}
$ret = $tplanMgr->link_tcversions($idTP, $linkItems, 1,
    array('getTCPrefixFromTPlan' => true));
echo 'linked: ' . var_export($ret === null, true) . "\n";

$buildMgr = new build($db);
$buildId = $buildMgr->create($idTP, 'B781', 'build for 781');
echo "build=$buildId\n";

// Record executions directly so the dashboard has data to draw.
// status char p=passed f=failed b=blocked n=not_run
$statuses = ['p', 'p', 'p', 'f', 'b'];
$i = 0;
foreach ($tcv as $nm => $tv) {
    $st = $statuses[$i % count($statuses)];
    $idTC = intval($db->get_recordset(
        " SELECT parent_id AS id FROM nodes_hierarchy WHERE id = " . intval($tv))[0]['id']);
    $db->exec_query(
        " INSERT INTO executions" .
        " (build_id, tester_id, execution_ts, status, testplan_id, tcversion_id," .
        "  tcversion_number, platform_id, execution_type, execution_duration)" .
        " VALUES (" . intval($buildId) . ", 1, NOW(), '$st', " . intval($idTP) .
        ", " . intval($tv) . ", 1, 0, 1, 1.0)");
    echo "exec ${nm} -> ${st}\n";
    $i++;
}

echo "DONE tproject=$idP tplan=$idTP build=$buildId\n";
