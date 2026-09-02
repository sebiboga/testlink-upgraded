<?php
// Fixture for #825 browser testing: Edit Execution popup (editExecution.html).
// Project + suite + 3 TCs + plan + 2 builds + executions, plus an
// execution-level custom field so the CF editor path can be exercised.
// Run from repo root: php tmp/fixtures_825.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tsuiteMgr = new testsuite($db);
$tcaseMgr = new testcase($db);
$tplanMgr = new testplan($db);
$cfieldMgr = new cfield_mgr($db);

// idempotency: drop previous run's project if present
$old = $tprojMgr->get_by_name('EDX825');
foreach ((array)$old as $row) {
    $oid = intval($row['id']);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $cf = $db->get_recordset(
            " SELECT id FROM custom_fields WHERE name = 'EDX825_ExecNote'");
        if (is_array($cf) && count($cf) > 0) {
            $db->exec_query(" DELETE FROM custom_fields WHERE id=" . intval($cf[0]['id']));
        }
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'EDX825';
$item->prefix = 'E825';
$item->notes = 'fixture for issue 825 (edit execution)';
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

$idS = firstId($tsuiteMgr->create($idP, 'EDX Suite', 'edit execution suite', null, null, 1));
echo "tsuite=$idS\n";

$tcv = [];
foreach ([['EDX One', 'first case'], ['EDX Two', 'second case'],
          ['EDX Three', 'third case']] as $i => [$nm, $sum]) {
    $idT = firstId($tcaseMgr->create($idS, $nm, $sum, 'precond',
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

// create an execution-level custom field (string) linked to this project
$cfName = 'EDX825_ExecNote';
try {
    $db->exec_query(
        " INSERT INTO custom_fields" .
        " (name,label,type,possible_values,default_value,valid_regexp,length_min,length_max," .
        "  show_on_design,enable_on_design,show_on_execution,enable_on_execution," .
        "  show_on_testplan_design,enable_on_testplan_design)" .
        " VALUES ('$cfName','EDX825 Execution Field',0,'','','',0,40," .
        "  0,0,1,1,0,0)");
    $cfId = intval($db->insert_id('custom_fields'));
    echo "cfield=$cfId\n";
    // link to testproject (execution-time custom fields are linked at the
    // TESTCASE node type, node_type_id=3; see node_types table)
    $db->exec_query(
        " INSERT IGNORE INTO cfield_testprojects (field_id, testproject_id, display_order, location, active)" .
        " VALUES (" . intval($cfId) . ", " . intval($idP) . ", 1, 1, 1)");
    $db->exec_query(
        " INSERT IGNORE INTO cfield_node_types (field_id, node_type_id)" .
        " VALUES (" . intval($cfId) . ", 3)");
} catch (Exception $e) {
    echo "cfield error: " . $e->getMessage() . "\n";
}

$idTP = $tplanMgr->create('EDX Plan', 'edit execution plan', $idP, 1, 1);
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
$buildId = $buildMgr->create($idTP, 'EDX Build 1', 'open build');
$build2Id = $buildMgr->create($idTP, 'EDX Build 2 (closed)', 'closed build');
// close build 2
$db->exec_query(" UPDATE builds SET is_open=0 WHERE id=" . intval($build2Id));
echo "build1=$buildId build2_closed=$build2Id\n";

// insert executions directly (status char p/f/b/n)
$execIds = [];
$i = 0;
foreach ($tcv as $nm => $tv) {
    $st = ['p', 'f', 'b'][$i % 3];
    $build = ($i === 2) ? $build2Id : $buildId; // one exec on closed build
    $db->exec_query(
        " INSERT INTO executions" .
        " (build_id, tester_id, execution_ts, status, testplan_id, tcversion_id," .
        "  tcversion_number, platform_id, execution_type, execution_duration, notes)" .
        " VALUES (" . intval($build) . ", 1, NOW(), '$st', " . intval($idTP) .
        ", " . intval($tv) . ", 1, 0, 1, 1.0, 'initial notes for $nm')");
    $lid = $db->get_recordset("SELECT LAST_INSERT_ID() AS id");
    $execIds[$nm] = intval($lid[0]['id']);
    echo "exec ${nm} -> ${st}\n";
    $i++;
}

echo "DONE tproject=$idP tplan=$idTP build1=$buildId tcversions=" . json_encode($tcv) .
     " execs=" . json_encode($execIds) . "\n";
