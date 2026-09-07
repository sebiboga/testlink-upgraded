<?php
// Fixture for analysis of modern execute/execTest.html vs legacy
// lib/execute/execSetResults.php + execNavigator.php.
// Projects with reqs + priority + automation; suites; TCs with steps;
// plan + open/closed builds; keywords; requirements; relation; executions.
// Run from repo root: php tmp/fixtures_exectest.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tsuiteMgr = new testsuite($db);
$tcaseMgr = new testcase($db);
$tplanMgr = new testplan($db);
$reqSpecMgr = new requirement_spec_mgr($db);
$reqMgr = new requirement_mgr($db);

function fid($r) {
    if (is_array($r)) {
        if (isset($r['id'])) { return intval($r['id']); }
        $k = array_keys($r);
        return intval($k[0]);
    }
    return intval($r);
}

// --- idempotent cleanup ---
$old = $tprojMgr->get_by_name('EXET');
foreach ((array)$old as $row) {
    $o = intval($row['id']);
    if ($o > 0) { echo "deleting old EXET $o\n"; $tprojMgr->delete($o, 1); }
}

$item = new stdClass();
$item->name = 'EXET';
$item->prefix = 'EXT';
$item->notes = 'execTest analysis fixture (reqs+priority+automation)';
$item->color = '';
$item->active = 1;
$item->is_public = 1;
$opts = new stdClass();
$opts->requirementsEnabled = 1;
$opts->testPriorityEnabled = 1;
$opts->automationEnabled = 1;
$opts->inventoryEnabled = 0;
$item->options = $opts;
$idP = fid($tprojMgr->create($item));
echo "tproject=$idP\n";
// write option flags directly into the options blob (setActive is broken on this schema)
$db->exec_query("UPDATE testprojects SET options='" .
    $db->prepare_string(serialize($opts)) . "' WHERE id=$idP");

$idS1 = fid($tsuiteMgr->create($idP, 'Suite Alpha', 'alpha desc', null, null, 1));
$idS2 = fid($tsuiteMgr->create($idP, 'Suite Beta', 'beta desc', null, null, 1));
echo "tsuite_a=$idS1 tsuite_b=$idS2\n";

$tcv = [];
$tcid = [];
foreach ([['Case A1', $idS1, 'p'], ['Case A2', $idS1, 'f'],
          ['Case A3', $idS1, null], ['Case B1', $idS2, 'b']] as $i => [$nm, $suit, $st]) {
    $idTC = fid($tcaseMgr->create($suit, $nm, 'summary of ' . $nm, 'precond of ' . $nm,
        [['step_number' => 1, 'actions' => 'action ' . $nm,
          'expected_results' => 'expected ' . $nm],
         ['step_number' => 2, 'actions' => 'act2 ' . $nm,
          'expected_results' => 'exp2 ' . $nm]], 1));
    $rr = $db->get_recordset(
        " SELECT NH.id FROM nodes_hierarchy NH JOIN tcversions TV ON TV.id = NH.id" .
        " WHERE NH.parent_id = " . intval($idTC) . " AND TV.active = 1 ORDER BY TV.version");
    $tvid = intval($rr[0]['id']);
    $tcv[$nm] = $tvid;
    $tcid[$nm] = $idTC;
    // execution types: Case A3 -> automated (2)
    if ($nm === 'Case A3') {
        $db->exec_query("UPDATE tcversions SET execution_type = 2 WHERE id = $tvid");
    }
}
echo "tcversions: " . json_encode($tcv) . "\n";

// --- keywords ---
$db->exec_query("INSERT INTO keywords (keyword, testproject_id) VALUES ('smoke', $idP)");
$kw1 = intval($db->get_recordset("SELECT MAX(id) AS id FROM keywords WHERE testproject_id = $idP")[0]['id']);
$db->exec_query("INSERT INTO keywords (keyword, testproject_id) VALUES ('regression', $idP)");
$kw2 = intval($db->get_recordset("SELECT MAX(id) AS id FROM keywords WHERE testproject_id = $idP")[0]['id']);
$db->exec_query("INSERT INTO testcase_keywords (testcase_id, tcversion_id, keyword_id) VALUES ({$tcid['Case A1']}, {$tcv['Case A1']}, $kw1)");
$db->exec_query("INSERT INTO testcase_keywords (testcase_id, tcversion_id, keyword_id) VALUES ({$tcid['Case B1']}, {$tcv['Case B1']}, $kw2)");
echo "keywords smoke=$kw1 regression=$kw2\n";

// --- requirements (so exec form can show req_details) ---
$specQ = $db->get_recordset("SELECT id FROM req_specs WHERE testproject_id = $idP");
if (!empty($specQ)) {
    $reqSpec = intval($specQ[0]['id']);
} else {
    $op = $reqSpecMgr->create($idP, $idP, 'EXT-SRS', 'Exec Spec', 'A spec', 3, 1, 3);
    if (!$op['status_ok'] || $op['id'] <= 0) { die("spec create failed: " . $op['msg'] . "\n"); }
    $reqSpec = intval($op['id']);
}
echo "reqspec=$reqSpec\n";
$reqQ = $db->get_recordset("SELECT id FROM requirements WHERE req_doc_id = 'EXT-REQ-1'");
if (empty($reqQ)) {
    $r = $reqMgr->create($reqSpec, 'EXT-REQ-1', 'Req One', 'scope text', 1,
        TL_REQ_STATUS_VALID, TL_REQ_TYPE_SYSTEM_FUNCTION, 100);
    if (!$r['status_ok'] && !isset($r['id'])) { die("req create failed: " . $r['msg'] . "\n"); }
    $reqId = intval($r['id']);
} else {
    $reqId = intval($reqQ[0]['id']);
}
// link requirement <-> tcversion A1 (req_coverage)
$db->exec_query("DELETE FROM req_coverage WHERE req_id = $reqId AND tcversion_id = {$tcv['Case A1']}");
$db->exec_query("INSERT INTO req_coverage (req_id, req_version_id, testcase_id, tcversion_id) VALUES ($reqId, 0, {$tcid['Case A1']}, {$tcv['Case A1']})");
echo "req=$reqId linked to Case A1\n";

// --- relation between A1 and B1 ---
$db->exec_query("INSERT INTO testcase_relations (source_id, destination_id, link_status, relation_type, author_id) VALUES ({$tcv['Case A1']}, {$tcv['Case B1']}, 1, 1, 1)");
echo "relation added\n";

// --- test plan + builds ---
$idTP = fid($tplanMgr->create('PlanEXET', 'exec test plan', $idP, 1, 1));
echo "tplan=$idTP\n";

$linkItems = ['items' => [], 'tcversion' => []];
foreach ($tcv as $nm => $tv) {
    $tci = intval($db->get_recordset(" SELECT parent_id AS id FROM nodes_hierarchy WHERE id = " . intval($tv))[0]['id']);
    $linkItems['items'][$tci] = [0 => $tv];
    $linkItems['tcversion'][$tci] = $tv;
}
$tplanMgr->link_tcversions($idTP, $linkItems, 1, array('getTCPrefixFromTPlan' => true));
echo "linked TCs to plan\n";

$buildMgr = new build($db);
$bOpen = fid($buildMgr->create($idTP, 'Build OPEN', 'open build'));
$bClosed = fid($buildMgr->create($idTP, 'Build CLOSED', 'closed build'));
$db->exec_query("UPDATE builds SET is_open = 0 WHERE id = $bClosed");
echo "builds open=$bOpen closed=$bClosed\n";

// --- executions on open build ---
$tcaseIdA1 = $tcid['Case A1'];
$write = function ($tvi, $status, $notes, $buildId) use ($db, $idTP, $bOpen) {
    $uid = 1;
    $db->exec_query(
        "INSERT INTO executions (testplan_id, platform_id, build_id, tester_id," .
        " execution_type, tcversion_id, status, notes, execution_ts)" .
        " VALUES ($idTP, 0, $buildId, $uid, 1, $tvi, '$status', '$notes', NOW())");
    $rid = intval($db->get_recordset("SELECT MAX(id) AS id FROM executions")[0]['id']);
    return $rid;
};
$eA1 = $write($tcv['Case A1'], 'p', 'passed note', $bOpen);
$write($tcv['Case A2'], 'f', 'failed note', $bOpen);
$write($tcv['Case B1'], 'b', 'blocked note', $bOpen);
// step results for A1 execution
$stepRows = $db->get_recordset(" SELECT TC.id FROM tcsteps TC JOIN nodes_hierarchy NH ON NH.id = TC.id WHERE NH.parent_id = " . intval($tcv['Case A1']));
foreach ($stepRows as $sr) {
    $db->exec_query("INSERT INTO execution_tcsteps (execution_id, tcstep_id, notes, status) VALUES ($eA1, {$sr['id']}, 'step note', 'p')");
}

echo "executions written (A1=$eA1 p, A2 f, B1 b)\n";
echo "DONE\n";
