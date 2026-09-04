<?php
// Fixture for #845 browser testing: Test Plan Report print (reportPrint.html).
// Project + suite + 3 TCs + plan + 2 builds + executions so the legacy
// printDocument.php pipeline has content to render (testplan/testreport/
// testreport_onbuild + per-build link).
// Run from repo root: php tmp/fixtures_845.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tsuiteMgr = new testsuite($db);
$tcaseMgr = new testcase($db);
$tplanMgr = new testplan($db);

// idempotency: drop previous run's project if present
$old = $tprojMgr->get_by_name('RP845');
foreach ((array)$old as $row) {
    $oid = intval($row['id']);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'RP845';
$item->prefix = 'RP8';
$item->notes = 'fixture for issue 845 (test plan report print)';
$item->color = '';
$item->active = 1;
$item->is_public = 1;
$opts = new stdClass();
$opts->requirementsEnabled = 0;
$opts->testPriorityEnabled = 0;
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

$idS = firstId($tsuiteMgr->create($idP, 'RP Suite A', 'report print suite', null, null, 1));
$idS2 = firstId($tsuiteMgr->create($idP, 'RP Suite B', 'second suite', null, null, 1));
echo "tsuiteA=$idS tsuiteB=$idS2\n";

$tcv = [];
foreach ([['RP One', 'first case', $idS], ['RP Two', 'second case', $idS],
          ['RP Three', 'third case', $idS2]] as $i => [$nm, $sum, $sid]) {
    $idT = firstId($tcaseMgr->create($sid, $nm, $sum, 'precond ' . $nm,
        [['step_number' => 1, 'actions' => 'action ' . $nm,
          'expected_results' => 'expected ' . $nm]], 1));
    $rs = $db->get_recordset(
        " SELECT NH.id FROM nodes_hierarchy NH" .
        " JOIN tcversions TV ON TV.id = NH.id" .
        " WHERE NH.parent_id = " . intval($idT) .
        " AND TV.active = 1 ORDER BY TV.version");
    $tcv[$nm] = intval($rs[0]['id']);
}
echo "tcversions: " . json_encode($tcv) . "\n";

$idTP = $tplanMgr->create('RP Plan', 'report print plan', $idP, 1, 1);
echo "tplan=$idTP\n";

$linkItems = ['items' => [], 'tcversion' => []];
foreach ($tcv as $nm => $tv) {
    $idTC = intval($db->get_recordset(
        " SELECT parent_id AS id FROM nodes_hierarchy WHERE id = " . intval($tv))[0]['id']);
    $linkItems['tcversion'][$idTC] = $tv;
    $linkItems['items'][$idTC] = [0 => $tv];
}
$tplanMgr->link_tcversions($idTP, $linkItems, 1,
    array('getTCPrefixFromTPlan' => true));

$buildMgr = new build($db);
$buildId = $buildMgr->create($idTP, 'RP Build 1', 'open build');
$build2Id = $buildMgr->create($idTP, 'RP Build 2', 'second build');
$db->exec_query(" UPDATE builds SET is_open=0 WHERE id=" . intval($build2Id));
echo "build1=$buildId build2_closed=$build2Id\n";

// insert executions directly (status char p/f/b/n)
$i = 0;
foreach ($tcv as $nm => $tv) {
    $st = ['p', 'f', 'b'][$i % 3];
    $build = ($i === 2) ? $build2Id : $buildId;
    $db->exec_query(
        " INSERT INTO executions" .
        " (build_id, tester_id, execution_ts, status, testplan_id, tcversion_id," .
        "  tcversion_number, platform_id, execution_type, execution_duration, notes)" .
        " VALUES (" . intval($build) . ", 1, NOW(), '$st', " . intval($idTP) .
        ", " . intval($tv) . ", 1, 0, 1, 1.0, 'notes for $nm')");
    echo "exec ${nm} -> ${st}\n";
    $i++;
}

echo "DONE tproject=$idP tplan=$idTP build1=$buildId build2_closed=$build2Id" .
     " tcversions=" . json_encode($tcv) . "\n";
