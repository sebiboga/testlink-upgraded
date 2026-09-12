<?php
// Fixture for Requirement Revision Viewer (api/reqrevision, Refs #1435)
// Adds a linked test case to REQ-100 so the coverage DataTable renders.
$_SESSION = array();
require_once(dirname(__DIR__) . '/config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);
$userId = 1;
$tprojMgr = new testproject($db);
$tsuiteMgr = new testsuite($db);
$tcaseMgr = new testcase($db);
$reqMgr = new requirement_mgr($db);

$rs = $tprojMgr->get_by_name('RCMP Demo Project');
$tid = intval($rs[0]['id']);
echo "project=$tid\n";

$rs = $db->get_recordset("SELECT id FROM nodes_hierarchy WHERE parent_id=$tid AND node_type_id=2 LIMIT 1");
if (empty($rs)) {
    $res = $tsuiteMgr->create($tid, 'RCMP Suite', 'suite details', null, null, 1);
    $sId = is_array($res['id']) ? intval($res['id'][0]) : intval($res['id']);
    echo "tsuite=$sId\n";
} else {
    $sId = intval($rs[0]['id']);
    echo "tsuite(reused)=$sId\n";
}

$rs = $db->get_recordset(
    "SELECT NH.id FROM nodes_hierarchy NH JOIN tcversions TV ON TV.id=NH.id " .
    "WHERE NH.parent_id=$sId AND TV.active=1 LIMIT 1");
if (empty($rs)) {
    $res = $tcaseMgr->create($sId, 'RCMP-TC-1', 'Linked test case for revision viewer',
        'precondition', [['step_number' => 1, 'actions' => 'do', 'expected_results' => 'ok']], 1);
    $idT = is_array($res['id']) ? intval($res['id'][0]) : intval($res['id']);
    echo "tc=$idT\n";
} else {
    $idT = intval($rs[0]['id']);
    echo "tc(reused)=$idT\n";
}

$tvRs = $db->get_recordset(
    "SELECT NH.id FROM nodes_hierarchy NH JOIN tcversions TV ON TV.id=NH.id " .
    "WHERE NH.parent_id=$idT AND TV.active=1 ORDER BY TV.version LIMIT 1");
$tvId = intval($tvRs[0]['id']);
echo "tcversion=$tvId\n";

$covRs = $db->get_recordset("SELECT id FROM req_coverage WHERE req_version_id=6 AND tcversion_id=$tvId");
if (empty($covRs)) {
    $db->exec_query("INSERT INTO req_coverage (req_id, req_version_id, testcase_id, tcversion_id, link_status, is_active) VALUES (4, 6, $idT, $tvId, 1, 1)");
    echo "linked REQ-100 v2(6) <- tcversion $tvId\n";
} else {
    echo "already linked\n";
}

$expRs = $db->get_recordset("SELECT expected_coverage FROM req_versions WHERE id=6");
$exp = intval($expRs[0]['expected_coverage']);
$act = $db->get_recordset("SELECT COUNT(*) c FROM req_coverage WHERE req_version_id=6");
echo "expected=$exp actual={$act[0]['c']}\n";
echo "DONE\n";