<?php
// CLI fixtures for baselinel1l2 (issue 601). Run: php tmp/fixtures_bll.php
$_SESSION = array();
require_once(dirname(__DIR__) . '/config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tp = new testproject($db);
$ts = new testsuite($db);
$tplanMgr = new testplan($db);

$name = 'BLL Demo Project';
$rs = $tp->get_by_name($name);
if ($rs) {
    echo "project exists id={$rs[0]['id']}\n";
    $projId = intval($rs[0]['id']);
} else {
    $item = new stdClass();
    $item->name = $name;
    $item->prefix = 'BLL';
    $item->notes = 'fixture';
    $item->options = new stdClass();
    $item->color = '';
    $item->active = 1;
    $item->is_public = 1;
    $projId = intval($tp->create($item));
    echo "project=$projId\n";
}

// find existing plan for this project
$planId = 0;
$rows = $db->get_recordset(
    "SELECT NH.id FROM nodes_hierarchy NH WHERE NH.name = 'BLL Plan'");
if ($rows) {
    $planId = intval($rows[0]['id']);
    echo "plan exists id=$planId\n";
} else {
    $planId = intval($tplanMgr->create('BLL Plan', 'fixture plan', $projId));
    echo "plan=$planId\n";
}

$s1 = intval($ts->create($projId, 'Top Suite One', 'ts1'));
$s2 = intval($ts->create($s1, 'Child Suite A', 'csa'));
echo "top=$s1 child=$s2\n";

// baseline context row
$db->exec_query(
    "INSERT INTO baseline_l1l2_context (testplan_id,platform_id," .
    "begin_exec_ts,end_exec_ts) VALUES ($planId,0,'2026-08-20 10:00:00'," .
    "'2026-08-21 12:00:00')");
$ctxId = $db->insert_id('baseline_l1l2_context', 'id');
echo "context=$ctxId\n";

$db->exec_query(
    "INSERT INTO baseline_l1l2_details (context_id,top_tsuite_id," .
    "child_tsuite_id,status,qty,total_tc) VALUES ($ctxId,$s1,$s2,'p',3,5)");
echo "detail inserted\n";
