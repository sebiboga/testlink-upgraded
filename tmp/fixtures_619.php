<?php
// Fixtures for #619 browser testing: project + suite + 3 TCs (2 with newer
// versions) + plan + links. Run from repo root: php tmp/fixtures_619.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tsuiteMgr = new testsuite($db);
$tcaseMgr = new testcase($db);
$tplanMgr = new testplan($db);

// idempotency: drop previous run's project if present
$old = $tprojMgr->get_by_name('UPD619');
foreach ((array)$old as $row) {
    $oid = intval($row['id']);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'UPD619';
$item->prefix = 'UPD';
$item->notes = 'fixture for issue 619';
$item->color = '';
$item->active = 1;
$item->is_public = 1;
$opts = new stdClass();
$opts->requirementsEnabled = 1;
$opts->testPriorityEnabled = 1;
$opts->automationEnabled = 1;
$opts->inventoryEnabled = 1;
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
$idS2 = firstId($tsuiteMgr->create($idP, 'Suite B', 'details', null, null, 1));
echo "tsuite=$idS2\n";

// TC1: v1 linked, v2 exists -> updatable
$idTC1 = firstId($tcaseMgr->create($idS1, 'TC-one', 'summary', 'precond',
    [['step_number' => 1, 'actions' => 'do it', 'expected_results' => 'works']], 1));
$idTC1 = is_array($idTC1) ? $idTC1['id'] : $idTC1;
$v2a = $tcaseMgr->create_new_version($idTC1, 1);
echo "tc1=$idTC1 newver=" . (is_array($v2a) ? print_r($v2a, true) : $v2a) . "\n";

// TC2: v1 linked, v2 + v3 exist -> updatable to newest
$idTC2 = firstId($tcaseMgr->create($idS1, 'TC-two', 'summary', 'precond',
    [['step_number' => 1, 'actions' => 'do it', 'expected_results' => 'works']], 1));
$idTC2 = is_array($idTC2) ? $idTC2['id'] : $idTC2;
$v2b = $tcaseMgr->create_new_version($idTC2, 1);
$v3b = $tcaseMgr->create_new_version($idTC2, 1);
echo "tc2=$idTC2\n";

// TC3: only v1 linked, no newer version -> latest
$idTC3 = firstId($tcaseMgr->create($idS2, 'TC-three', 'summary', 'precond',
    [['step_number' => 1, 'actions' => 'do it', 'expected_results' => 'works']], 1));
$idTC3 = is_array($idTC3) ? $idTC3['id'] : $idTC3;
echo "tc3=$idTC3\n";

$idTP = $tplanMgr->create('PlanU', 'plan for 619', $idP, 1, 1);
echo "tplan=$idTP\n";

// link LATEST version of each tc? NO - link the OLDEST active version so
// newer versions are available as targets.
function firstActiveVersionId($db, $tcid) {
    $tbl = tlObjectWithDB::getDBTables(['nodes_hierarchy', 'tcversions']);
    $rs = $db->get_recordset(
        " SELECT NH.id, TV.version FROM {$tbl['nodes_hierarchy']} NH" .
        " JOIN {$tbl['tcversions']} TV ON TV.id = NH.id" .
        " WHERE NH.parent_id = " . intval($tcid) .
        " AND TV.active = 1 ORDER BY TV.version");
    return intval($rs[0]['id']);
}
$firsts = [
    $idTC1 => firstActiveVersionId($db, $idTC1),
    $idTC2 => firstActiveVersionId($db, $idTC2),
    $idTC3 => firstActiveVersionId($db, $idTC3),
];
$items = [
    'tcversion' => $firsts,
    'items' => [],
];
foreach ($firsts as $tc => $tv) {
    $items['items'][$tc] = [0 => $tv];   // 0 = no platform
}
$ret = $tplanMgr->link_tcversions($idTP, $items, 1);
var_dump(is_array($ret) || $ret === null ? 'linked' : $ret);

foreach ($firsts as $tc => $tv) {
    echo "linked tc=$tc -> tcv=$tv\n";
}
echo "DONE tproject=$idP tplan=$idTP\n";
