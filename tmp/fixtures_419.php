<?php
// CLI fixtures for issue 419 (EXDS execution navigator). Run: php tmp/fixtures_419.php
$_SESSION = array();
require_once(dirname(__DIR__) . '/config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tp = new testproject($db);
$ts = new testsuite($db);
$tc = new testcase($db);
$tplanMgr = new testplan($db);
$buildMgr = new build($db);

global $db;
function childByName($mgr, $parentId, $name) {
    global $db;
    $rows = $db->get_recordset(
        "SELECT NH.id FROM nodes_hierarchy NH " .
        "JOIN node_types NT ON NT.id = NH.node_type_id " .
        "WHERE NH.parent_id={$parentId} AND NH.name='" .
        $db->prepare_string($name) . "'");
    return $rows ? intval($rows[0]['id']) : 0;
}

$name = 'EXDS Demo Project';
$rs = $tp->get_by_name($name);
if ($rs) {
    echo "project exists id={$rs[0]['id']}\n";
    $projId = intval($rs[0]['id']);
} else {
    $item = new stdClass();
    $item->name = $name;
    $item->prefix = 'EXD';
    $item->notes = 'fixture for issue 419';
    $opts = new stdClass();
    $opts->requirementsEnabled = 0;
    $opts->testPriorityEnabled = 0;
    $opts->automationEnabled = 0;
    $opts->inventoryEnabled = 0;
    $item->options = $opts;
    $item->color = '';
    $item->active = 1;
    $item->is_public = 1;
    $projId = intval($tp->create($item));
    echo "project=$projId\n";
}

$s1 = childByName($tp, $projId, 'EXDS Top Suite');
if (!$s1) { $ret = $ts->create($projId, 'EXDS Top Suite', 'top suite'); $s1 = intval($ret['id']); }
echo "topSuite=$s1\n";

$s2 = childByName($ts, $s1, 'EXDS Child Suite');
if (!$s2) { $ret = $ts->create($s1, 'EXDS Child Suite', 'child suite'); $s2 = intval($ret['id']); }
echo "childSuite=$s2\n";

$authorId = 1;
$caseIds = array();
foreach (array(array($s1, 'Case T1'), array($s2, 'Case C1'), array($s2, 'Case C2')) as $spec) {
    list($parent, $cname) = $spec;
    $cid = childByName($tc, $parent, $cname);
    if (!$cid) {
        $steps = array(array('step_number' => 1, 'actions' => 'run it',
            'expected_results' => 'passes', 'execution_type' => TESTCASE_EXECUTION_TYPE_MANUAL));
        $cid = $tc->create($parent, $cname, 'summary', 'precon', $steps, $authorId);
        $cid = is_array($cid) ? intval($cid['id']) : intval($cid);
    }
    $caseIds[] = $cid;
}
print_r($caseIds);

// test plan
$rows = $db->get_recordset("SELECT NH.id FROM nodes_hierarchy NH WHERE NH.name = 'EXDS Plan'");
if ($rows) {
    $planId = intval($rows[0]['id']);
} else {
    $planId = intval($tplanMgr->create('EXDS Plan', 'fixture plan', $projId));
}
echo "plan=$planId\n";

// link cases to plan
$linked = $db->get_recordset("SELECT COUNT(*) AS c FROM testplan_tcversions WHERE testplan_id={$planId}");
if (intval($linked[0]['c']) === 0) {
    $items = array('items' => array(), 'tcversion' => array());
    foreach ($caseIds as $cid) {
        $v = $tc->get_last_version_info($cid, array('output' => 'thin', 'active' => 1));
        $tcv = intval($v['tcversion_id']);
        $items['items'][$cid][0] = $tcv;
        $items['tcversion'][$cid] = $tcv;
    }
    $tplanMgr->link_tcversions($planId, $items, $authorId);
    echo "cases linked\n";
} else {
    echo "already linked: {$linked[0]['c']}\n";
}

// build
$builds = $buildMgr->get_by_name('EXDS Build 1', array('tplan_id' => $planId));
if (!$builds) {
    $b = $buildMgr->create($planId, 'EXDS Build 1', 'fixture build');
    echo "build=$b\n";
} else {
    echo "build exists\n";
}
