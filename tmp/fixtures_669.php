<?php
// Fixture for #669: project + suite + TC(v1) + plan + build + link.
// Run from repo root: php tmp/fixtures_669.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tsuiteMgr = new testsuite($db);
$tcaseMgr = new testcase($db);
$tplanMgr = new testplan($db);

function firstActiveVersionId($db, $tcid) {
    $r = $db->get_recordset('SELECT nh.id, tv.version FROM nodes_hierarchy nh JOIN tcversions tv ON tv.id=nh.id WHERE nh.parent_id=' . intval($tcid) . ' ORDER BY tv.version');
    return intval($r[0]['id']);
}

// idempotency: drop previous run's project if present
$old = $tprojMgr->get_by_name('UPD669');
foreach ((array)$old as $row) {
    $oid = intval($row['id']);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'UPD669';
$item->prefix = 'U69';
$item->notes = 'fixture for issue 669';
$item->color = '';
$item->active = 1;
$item->is_public = 1;
$opts = new stdClass();
$opts->requirementsEnabled = 1;
$opts->testPriorityEnabled = 1;
$opts->automationEnabled = 1;
$item->options = $opts;
$idP = $tprojMgr->create($item);
echo "tproject=$idP\n";

$retS = $tsuiteMgr->create($idP, 'Suite669', 'details', null, 1);
$idS = is_array($retS) ? intval($retS['id']) : intval($retS);
echo "tsuite=$idS\n";

$ret = $tcaseMgr->create($idS, 'TC669', 'summary', 'precond',
    [['step_number' => 1, 'actions' => 'do it', 'expected_results' => 'works']], 1);
$idTC = is_array($ret) ? $ret['id'] : $ret;
echo "tcase=$idTC\n";

$idTP = $tplanMgr->create('Plan669', 'plan for 669', $idP, 1, 1);
echo "tplan=$idTP\n";

$buildMgr = new build($db);
$idB = $buildMgr->create($idTP, 'Build669', 'build for 669');
echo "build=$idB\n";

$tvId = firstActiveVersionId($db, $idTC);
echo "tcversion_id=$tvId\n";
$items = [
    'tcversion' => [$idTC => $tvId],
    'items'     => [$idTC => [0 => $tvId]],
];
$tplanMgr->link_tcversions($idTP, $items, 1);
echo "linked\n";
echo json_encode(compact('idP','idS','idTC','idTP','idB','tvId')) . "\n";
