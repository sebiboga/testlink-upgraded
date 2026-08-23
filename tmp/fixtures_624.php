<?php
// Fixtures for #624 browser/API testing. Run from repo root:
// php tmp/fixtures_624.php
// Creates project UPD624 with suite + 4 TCs + plan P624:
//  TC-upd    : v1 linked, v2+v3 exist            -> updatable
//  TC-latest : v1 linked, no other version       -> latest
//  TC-edge1  : v1,v2,v3 created; middle version RENUMBERED to 5 and LINKED
//              -> id-order says "newer sibling exists", version-order says NO
//                 (legacy: NOT updatable; buggy BFF: offers downgrade)
//  TC-edge2  : v1,v2,v3 created; v3 linked then DEACTIVATED
//              -> buggy BFF: green Latest hides inactive state
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tsuiteMgr = new testsuite($db);
$tcaseMgr = new testcase($db);
$tplanMgr = new testplan($db);

$old = $tprojMgr->get_by_name('UPD624');
foreach ((array)$old as $row) {
    $oid = intval($row['id']);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'UPD624';
$item->prefix = 'U62';
$item->notes = 'fixture for issue 624';
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

function mkTC($tcaseMgr, $suiteId, $name) {
    $id = firstId($tcaseMgr->create($suiteId, $name, 'summary', 'precond',
        [['step_number' => 1, 'actions' => 'do it', 'expected_results' => 'works']], 1));
    return intval(is_array($id) ? $id['id'] : $id);
}

function versionsOf($db, $tcid) {
    // returns [version => ['id'=>..,'active'=>..]] ordered by version
    $tbl = tlObjectWithDB::getDBTables(['nodes_hierarchy', 'tcversions']);
    $rs = $db->get_recordset(
        " SELECT NH.id, TV.version, TV.active FROM {$tbl['nodes_hierarchy']} NH" .
        " JOIN {$tbl['tcversions']} TV ON TV.id = NH.id" .
        " WHERE NH.parent_id = " . intval($tcid) . " ORDER BY NH.id");
    $out = [];
    foreach ((array)$rs as $r) { $out[] = $r; }
    return $out;
}

// --- normal updatable -------------------------------------------------------
$tcUpd = mkTC($tcaseMgr, $idS1, 'TC-upd');
$tcaseMgr->create_new_version($tcUpd, 1);
$tcaseMgr->create_new_version($tcUpd, 1);
echo "TC-upd=$tcUpd\n";

// --- latest -----------------------------------------------------------------
$tcLat = mkTC($tcaseMgr, $idS1, 'TC-latest');
echo "TC-latest=$tcLat\n";

// --- edge1: renumber middle version to 5, link it ---------------------------
$tcE1 = mkTC($tcaseMgr, $idS1, 'TC-edge1');
$tcaseMgr->create_new_version($tcE1, 1);
$tcaseMgr->create_new_version($tcE1, 1);
$vs = versionsOf($db, $tcE1);           // ordered by node id: v?,v?,v?
$tblNH = tlObjectWithDB::getDBTables(['nodes_hierarchy', 'tcversions']);
$mid = $vs[1];                          // middle node id
$db->exec_query("UPDATE {$tblNH['tcversions']} SET version=5 WHERE id=" .
    intval($mid['id']));
echo "TC-edge1=$tcE1 versions=" . json_encode(versionsOf($db, $tcE1)) . "\n";

// --- edge2: link newest (v3), then deactivate it ----------------------------
$tcE2 = mkTC($tcaseMgr, $idS1, 'TC-edge2');
$v3 = $tcaseMgr->create_new_version($tcE2, 1);
echo "TC-edge2=$tcE2 versions=" . json_encode(versionsOf($db, $tcE2)) . "\n";

$idTP = $tplanMgr->create('P624', 'plan for 624', $idP, 1, 1);
echo "tplan=$idTP\n";

function versionRow($db, $tcid, $ver) {
    $tbl = tlObjectWithDB::getDBTables(['nodes_hierarchy', 'tcversions']);
    $rs = $db->get_recordset(
        " SELECT NH.id FROM {$tbl['nodes_hierarchy']} NH" .
        " JOIN {$tbl['tcversions']} TV ON TV.id = NH.id" .
        " WHERE NH.parent_id = " . intval($tcid) .
        " AND TV.version = " . intval($ver));
    return intval($rs[0]['id']);
}

$linkMap = [
    $tcUpd => versionRow($db, $tcUpd, 1),
    $tcLat => versionRow($db, $tcLat, 1),
    $tcE1  => versionRow($db, $tcE1, 5),
];
// edge2: link the HIGHEST-NODE-ID version then deactivate it
$vsE2 = versionsOf($db, $tcE2);
$e2newest = $vsE2[count($vsE2) - 1]['id'];
$linkMap[$tcE2] = intval($e2newest);
$items = ['tcversion' => [], 'items' => []];
foreach ($linkMap as $tc => $tv) {
    $items['tcversion'][$tc] = $tv;
    $items['items'][$tc] = [0 => $tv];
}
$ret = $tplanMgr->link_tcversions($idTP, $items, 1);
var_dump(is_array($ret) || $ret === null ? 'linked' : $ret);

// deactivate linked v3 of TC-edge2
$db->exec_query(
    "UPDATE {$tblNH['tcversions']} SET active=0 WHERE id=" . $linkMap[$tcE2]);
foreach ($linkMap as $tc => $tv) {
    echo "linked tc=$tc -> tcv=$tv\n";
}
echo "DONE tproject=$idP tplan=$idTP\n";
