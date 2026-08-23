<?php
// CLI fixtures for issue 614: project + suite + 2 cases + plan + build + links
$_SESSION = array();
require_once(dirname(__DIR__) . '/config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);
if (isset($argv[1])) { $db->db->debug = true; }

$tp = new testproject($db);
$ts = new testsuite($db);
$tc = new testcase($db);
$tplanMgr = new testplan($db);

$name = 'I614 Project';
$rs = $tp->get_by_name($name);
if ($rs) {
    $projId = intval($rs[0]['id']);
    echo "project exists id=$projId\n";
} else {
    $item = new stdClass();
    $item->name = $name;
    $item->prefix = 'I614';
    $item->notes = 'fixture';
    $item->options = new stdClass();
    $item->options->requirementsEnabled = 1;
    $item->options->testPriorityEnabled = 1;
    $item->options->automationEnabled = 1;
    $item->options->inventoryEnabled = 1;
    $item->color = '';
    $item->active = 1;
    $item->is_public = 1;
    $projId = intval($tp->create($item));
    echo "project=$projId\n";
}

// reuse existing suite or create one
$rows = $db->get_recordset(
    "SELECT NH.id FROM nodes_hierarchy NH WHERE NH.name='I614 Suite' AND NH.parent_id=$projId");
if ($rows) {
    $suiteId = intval($rows[0]['id']);
    echo "suite exists id=$suiteId\n";
} else {
    $sret = $ts->create($projId, 'I614 Suite', 'fixture suite');
    if (!$sret['status_ok']) { echo "SUITE FAIL: {$sret['msg']}\n"; exit(1); }
    $suiteId = intval($sret['id']);
    echo "suite=$suiteId\n";
}

$steps = array(array('step_number' => 1,
                     'actions' => '<p>Do the thing</p>',
                     'expected_results' => '<p>It works</p>'));
$tcIds = array();
foreach (array('Case Alpha', 'Case Beta') as $cn) {
    // skip if already present under this suite
    $rows = $db->get_recordset(
      "SELECT id FROM nodes_hierarchy WHERE name='$cn' AND parent_id=$suiteId");
    if ($rows) { $tcIds[] = intval($rows[0]['id']); continue; }
    $ret = $tc->create($suiteId, $cn, '<p>summary</p>', '', $steps, 1);
    if (!$ret['status_ok']) { echo "TC CREATE FAIL: {$ret['msg']}\n"; exit(1); }
    $tcIds[] = intval($ret['id']);
    echo "tcase={$ret['id']} tcversion={$ret['tcversion_id']}\n";
}

// test plan
$rows = $db->get_recordset(
    "SELECT NH.id FROM nodes_hierarchy NH WHERE NH.name='I614 Plan'");
if ($rows) {
    $planId = intval($rows[0]['id']);
    echo "plan exists id=$planId\n";
} else {
    $planId = intval($tplanMgr->create('I614 Plan', 'fixture plan', $projId));
    echo "plan=$planId\n";
}

// link tcversions (platform_id 0), skip when already linked
$rows = $db->get_recordset("SELECT COUNT(*) c FROM testplan_tcversions WHERE testplan_id=$planId");
if ($rows && intval($rows[0]['c']) > 0) {
    echo "already linked (" . $rows[0]['c'] . ")\n";
} else {
    $linkSet = array('items' => array(), 'tcversion' => array());
    foreach ($tcIds as $tid) {
        $vinfo = $tc->get_basic_info($tid, array('output' => 'minimun'));
        $lastV = end($vinfo);
        $vid = is_array($lastV) ? intval($lastV['tcversion_id']) : intval($lastV['id']);
        $linkSet['items'][$tid][0] = $vid;
        $linkSet['tcversion'][] = $vid;
    }
    $tplanMgr->link_tcversions($planId, $linkSet, 1);
    echo "linked: " . json_encode($linkSet['items']) . "\n";
}

// build (only once)
$rows = $db->get_recordset("SELECT id FROM builds WHERE testplan_id=$planId AND name='I614 Build'");
if ($rows) {
    $buildId = intval($rows[0]['id']);
    echo "build exists id=$buildId\n";
} else {
    $bm = new build($db);
    $buildId = intval($bm->create($planId, 'I614 Build', 'fixture build'));
    echo "build=$buildId\n";
}
