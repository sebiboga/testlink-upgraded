<?php
// Fixture for #866 browser testing: multi-column sort in tplanWithCF.
// Project + 2 suites + TCs + test plan + 2 testplan-design custom fields
// (Owner, Tier) with per-tc design values, so multi-sort is meaningful.
// Run from repo root: php tmp/fixtures_866.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tsuiteMgr = new testsuite($db);
$tcaseMgr = new testcase($db);
$tplanMgr = new testplan($db);

// idempotency
$old = $tprojMgr->get_by_name('TPWCF866');
foreach ((array)$old as $row) {
    $oid = intval($row['id']);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $db->exec_query(
            " DELETE FROM custom_fields WHERE name IN ('TPWCF866_Owner','TPWCF866_Tier')");
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'TPWCF866';
$item->prefix = 'T866';
$item->notes = 'fixture for issue 866 (tplanWithCF multi sort)';
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

// two suites so group rows are meaningful
$idS1 = firstId($tsuiteMgr->create($idP, 'Suite Alpha', 'alpha suite', null, null, 1));
$idS2 = firstId($tsuiteMgr->create($idP, 'Suite Beta', 'beta suite', null, null, 1));
echo "suites=$idS1,$idS2\n";

// name => [owner, tier]
$tcs = [
    // suite alpha
    ['Alpha One',   'Bob',   'Low'],
    ['Alpha Two',   'Alice', 'High'],
    ['Alpha Three', 'Bob',   'High'],
    ['Alpha Four',  'Carol', 'Med'],
    // suite beta
    ['Beta One',    'Alice', 'Low'],
    ['Beta Two',    'Carol', 'High'],
    ['Beta Three',  'Bob',   'Med'],
    ['Beta Four',   'Alice', 'Med'],
];

$tcvById = []; // suiteId => list of [tcId, tcversionId]
foreach ([$idS1, $idS2] as $i => $idS) {
    $sel = array_slice($tcs, $i * 4, 4);
    foreach ($sel as [$nm, $owner, $tier]) {
        $idT = firstId($tcaseMgr->create($idS, $nm, 'summary', 'precond',
            [['step_number' => 1, 'actions' => 'action',
              'expected_results' => 'expected']], 1));
        $rs = $db->get_recordset(
            " SELECT NH.id FROM nodes_hierarchy NH" .
            " JOIN tcversions TV ON TV.id = NH.id" .
            " WHERE NH.parent_id = " . intval($idT) .
            " AND TV.active = 1 ORDER BY TV.version");
        $tv = intval($rs[0]['id']);
        $tcvById[$idS][] = ['tc' => $idT, 'tv' => $tv, 'owner' => $owner, 'tier' => $tier];
        echo "tc $idT ($nm) tv=$tv owner=$owner tier=$tier\n";
    }
}

// two testplan-design custom fields (string type)
$cfCols = [];
foreach ([['TPWCF866_Owner', 'Owner'], ['TPWCF866_Tier', 'Tier']] as [$cfName, $cfLabel]) {
    $db->exec_query(
        " INSERT INTO custom_fields" .
        " (name,label,type,possible_values,default_value,valid_regexp,length_min,length_max," .
        "  show_on_design,enable_on_design,show_on_execution,enable_on_execution," .
        "  show_on_testplan_design,enable_on_testplan_design)" .
        " VALUES ('$cfName','$cfLabel ', '" . 0 . "','','','',0,40," .
        "  0,0,0,0,1,1)");
    // trim label above (trailing space guard)
    $db->exec_query(" UPDATE custom_fields SET label = '${cfLabel}' WHERE name = '$cfName'");
    $cfId = intval($db->get_recordset(
        " SELECT id FROM custom_fields WHERE name = '$cfName'")[0]['id']);
    $db->exec_query(
        " INSERT IGNORE INTO cfield_testprojects (field_id, testproject_id, display_order, location, active)" .
        " VALUES (" . intval($cfId) . ", " . intval($idP) . ", 10, 1, 1)");
    $db->exec_query(
        " INSERT IGNORE INTO cfield_node_types (field_id, node_type_id)" .
        " VALUES (" . intval($cfId) . ", 3)");
    $cfCols[$cfName] = $cfId;
    echo "cfield $cfName = $cfId\n";
}

$idTP = $tplanMgr->create('TPWCF866 Plan', 'issue 866 test plan', $idP, 1, 1);
echo "tplan=$idTP\n";

// link all tcversions into the plan and record testplan_tcversions ids
$linkItems = ['items' => [], 'tcversion' => []];
foreach ($tcvById as $suiteTcs) {
    foreach ($suiteTcs as $ent) {
        $linkItems['tcversion'][$ent['tc']] = $ent['tv'];
        $linkItems['items'][$ent['tc']] = [0 => $ent['tv']];
    }
}
$tplanMgr->link_tcversions($idTP, $linkItems, 1,
    array('getTCPrefixFromTPlan' => true));

// find testplan_tcversions link ids, then store CF design values
$tptcById = [];
foreach ($tcvById as $suiteTcs) {
    foreach ($suiteTcs as $ent) {
        $rs = $db->get_recordset(
            " SELECT id FROM testplan_tcversions" .
            " WHERE testplan_id = " . intval($idTP) .
            " AND tcversion_id = " . intval($ent['tv']));
        $tptcById[$ent['tv']] = intval($rs[0]['id']);
    }
}

$i = 0;
foreach ($tcvById as $suiteTcs) {
    foreach ($suiteTcs as $ent) {
        $linkId = $tptcById[$ent['tv']];
        foreach ($cfCols as $cfName => $cfId) {
            $val = ($cfName === 'TPWCF866_Owner') ? $ent['owner'] : $ent['tier'];
            $db->exec_query(
                " INSERT INTO cfield_testplan_design_values (field_id, link_id, value)" .
                " VALUES (" . intval($cfId) . ", " . intval($linkId) . ", '" .
                $db->prepare_string($val) . "')");
        }
        $i++;
    }
}
echo "linked $i tcs with cf values\n";

echo "DONE tproject=$idP tplan=$idTP\n";