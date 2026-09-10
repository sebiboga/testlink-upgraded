<?php
// Fixture for Set Results popup (execute/execSetResults.html, row 85) analysis.
// Run from repo root: php tmp/fixtures_esr2.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tsuiteMgr = new testsuite($db);
$tcaseMgr = new testcase($db);
$tplanMgr = new testplan($db);
$buildMgr = new build($db);
$reqSpecMgr = new requirement_spec_mgr($db);
$reqMgr = new requirement_mgr($db);
$userId = 1; // admin

function firstId($r) {
    if (is_array($r)) {
        if (isset($r['id'])) { return intval($r['id']); }
        $k = array_keys($r);
        return intval($k[0]);
    }
    return intval($r);
}

// idempotency
$old = $tprojMgr->get_by_name('ESR2');
foreach ((array)$old as $row) {
    $oid = intval($row['id']);
    if ($oid > 0) { echo "deleting old project $oid\n"; $tprojMgr->delete($oid, 1); }
}
$db->exec_query("DELETE FROM custom_fields WHERE name='ESR2-ExecNote'");
$db->exec_query("DELETE FROM keywords WHERE keyword='Smoke' AND testproject_id NOT IN (SELECT id FROM testprojects)");

$item = new stdClass();
$item->name = 'ESR2';
$item->prefix = 'ESR2';
$item->notes = 'fixture for execSetResults popup analysis (row 85)';
$item->color = '';
$item->active = 1;
$item->is_public = 1;
$opts = new stdClass();
$opts->requirementsEnabled = 1;
$opts->testPriorityEnabled = 0;
$opts->automationEnabled = 0;
$opts->inventoryEnabled = 0;
$item->options = $opts;
$idP = $tprojMgr->create($item);
echo "tproject=$idP\n";
if ($idP <= 0) { die("project create failed\n"); }

$idS1 = firstId($tsuiteMgr->create($idP, 'Suite One', 'suite details text', null, null, 1));
echo "tsuite=$idS1\n";

// 2 TCs; TC-1 gets 2 steps + keyword + requirement + multiple executions
$tcv = [];
foreach ([['TC-1', 'First test case', 'precond one'],
           ['TC-2', 'Second test case', 'precond two']] as [$nm, $summ, $pre]) {
    $idT = firstId($tcaseMgr->create($idS1, $nm, $summ, $pre,
        [['step_number' => 1, 'actions' => 'step one action', 'expected_results' => 'step one expected'],
         ['step_number' => 2, 'actions' => 'step two action', 'expected_results' => 'step two expected']], 1));
    echo "tc $nm=$idT\n";
    $rs = $db->get_recordset(
        " SELECT NH.id FROM nodes_hierarchy NH JOIN tcversions TV ON TV.id = NH.id" .
        " WHERE NH.parent_id = " . intval($idT) . " AND TV.active = 1 ORDER BY TV.version");
    $tcv[$nm] = intval($rs[0]['id']);
}
echo "tcversions TC-1={$tcv['TC-1']} TC-2={$tcv['TC-2']}\n";

// keyword linked to TC-1
$db->exec_query("INSERT INTO keywords (keyword,testproject_id,notes) VALUES ('Smoke',$idP,'')");
$kw = intval($db->get_recordset("SELECT MAX(id) AS id FROM keywords WHERE testproject_id=$idP")[0]['id']);
$db->exec_query("INSERT INTO testcase_keywords (testcase_id,tcversion_id,keyword_id) VALUES ({$tcv['TC-1']},{$tcv['TC-1']},$kw)");
echo "keyword Smoke=$kw linked to TC-1\n";

// req spec + requirement REQ-1 linked to TC-1
$spec1 = $reqSpecMgr->create($idP, $idP, 'SRS-ESR2', 'ESR2 Spec', 'scope', 0, $userId, TL_REQ_SPEC_TYPE_USER_REQ_SPEC);
$spec1Id = $spec1['id'];
$r = $reqMgr->create($spec1Id, 'REQ-1', 'ESR2 Requirement', 'scope note', $userId, TL_REQ_STATUS_VALID, TL_REQ_TYPE_FEATURE, 1);
echo "req REQ-1=id{$r['id']} v{$r['version_id']}\n";
$tblRC = tlObjectWithDB::getDBTables(['req_coverage']);
$db->exec_query(" INSERT INTO {$tblRC['req_coverage']} (req_id, req_version_id, testcase_id, tcversion_id, link_status, is_active, author_id)" .
    " VALUES ({$r['id']}, {$r['version_id']}, {$tcv['TC-1']}, {$tcv['TC-1']}, 1, 1, $userId)");
echo "reqcov REQ-1 -> TC-1\n";

// test plan + links
$idTP = $tplanMgr->create('ESR2 Plan', 'plan for execSetResults popup analysis', $idP, 1, 1);
echo "tplan=$idTP\n";
$linkItems = ['items' => [], 'tcversion' => []];
$map = ['TC-1' => $tcv['TC-1'], 'TC-2' => $tcv['TC-2']];
foreach ($map as $nm => $tv) {
    $linkItems['items'][$tv] = [0 => $tv];
    $linkItems['tcversion'][$tv] = $tv;
}
$ret = $tplanMgr->link_tcversions($idTP, $linkItems, 1, array('getTCPrefixFromTPlan' => true));
echo "linkTcversions ret=" . var_export($ret, true) . "\n";

// builds
$bOpen = intval($buildMgr->create($idTP, 'B-OPEN', 'open build'));
$bClosed = intval($buildMgr->create($idTP, 'B-CLOSED', 'closed build'));
$db->exec_query("UPDATE builds SET is_open=0 WHERE id=$bClosed");
echo "build B-OPEN=$bOpen B-CLOSED=$bClosed (closed)\n";

// execution custom field 'ESR2-ExecNote' enabled on execution
$cfName = 'ESR2-ExecNote';
$cfieldId = 0;
try {
    $db->exec_query(
        " INSERT INTO custom_fields" .
        " (name,label,type,possible_values,default_value,valid_regexp,length_min,length_max," .
        "  show_on_design,enable_on_design,show_on_execution,enable_on_execution," .
        "  show_on_testplan_design,enable_on_testplan_design)" .
        " VALUES ('$cfName','ESR2 Execution Field',0,'','','',0,40," .
        "  0,0,1,1,0,0)");
    $cfieldId = intval($db->insert_id('custom_fields'));
    echo "custom field=$cfieldId\n";
    // execution-time custom fields are linked at the TESTCASE node type (3)
    $db->exec_query(
        " INSERT IGNORE INTO cfield_testprojects (field_id, testproject_id, display_order, location, active)" .
        " VALUES (" . intval($cfieldId) . ", " . intval($idP) . ", 1, 1, 1)");
    $db->exec_query(
        " INSERT IGNORE INTO cfield_node_types (field_id, node_type_id)" .
        " VALUES (" . intval($cfieldId) . ", 3)");
} catch (Exception $e) {
    echo "cfield error: " . $e->getMessage() . "\n";
}

// executions on TC-1: two on open build (different statuses/times), one on closed
$tblE = tlObjectWithDB::getDBTables(['executions']);
$sql = " INSERT INTO {$tblE['executions']} (build_id,tester_id,execution_ts,status,testplan_id,tcversion_id,tcversion_number,platform_id,notes,execution_duration)" .
       " VALUES ";
$db->exec_query($sql . "($bOpen,1,'2026-08-01 10:00:00','p',$idTP,{$tcv['TC-1']},1,0,'first pass note',5)");
$idEx1 = intval($db->get_recordset("SELECT MAX(id) AS id FROM {$tblE['executions']} WHERE testplan_id=$idTP AND tcversion_id={$tcv['TC-1']}")[0]['id']);
$db->exec_query($sql . "($bOpen,1,'2026-08-05 12:00:00','f',$idTP,{$tcv['TC-1']},1,0,'second failure note',8)");
$idEx2 = intval($db->get_recordset("SELECT MAX(id) AS id FROM {$tblE['executions']} WHERE testplan_id=$idTP AND tcversion_id={$tcv['TC-1']}")[0]['id']);
$db->exec_query($sql . "($bClosed,1,'2026-08-03 09:00:00','b',$idTP,{$tcv['TC-1']},1,0,'closed build blocked note',0)");
$idEx3 = intval($db->get_recordset("SELECT MAX(id) AS id FROM {$tblE['executions']} WHERE testplan_id=$idTP AND tcversion_id={$tcv['TC-1']}")[0]['id']);
echo "executions ex1=$idEx1 ex2=$idEx2 ex3=$idEx3\n";

// step results on exec2 (f) for both steps
$tblS = tlObjectWithDB::getDBTables(['execution_tcsteps']);
$steps = $db->get_recordset("SELECT id,step_number FROM " . DB_TABLE_PREFIX . "tcsteps WHERE id IN (SELECT id FROM " . DB_TABLE_PREFIX . "nodes_hierarchy WHERE parent_id={$tcv['TC-1']})" );
foreach ($steps as $st) {
    $db->exec_query(" INSERT INTO {$tblS['execution_tcsteps']} (execution_id,tcstep_id,notes,status)" .
        " VALUES ($idEx2,{$st['id']},'step note {$st['step_number']}','p')");
}
echo "step results written for exec $idEx2\n";

// execution custom field value on latest open-build exec (ex2)
$db->exec_query(" INSERT INTO cfield_execution_values (field_id,execution_id,testplan_id,tcversion_id,value)" .
    " VALUES ($cfieldId,$idEx2,$idTP,{$tcv['TC-1']},'my exec CF value')");

// a closed-build exec for TC-2 too (so modern popup has something on TC-2)
$db->exec_query($sql . "($bOpen,1,'2026-08-04 11:00:00','p',$idTP,{$tcv['TC-2']},1,0,'tc2 pass',0)");

echo "DONE tproject=$idP tplan=$idTP TC-1={$tcv['TC-1']} TC-2={$tcv['TC-2']} builds open=$bOpen closed=$bClosed\n";