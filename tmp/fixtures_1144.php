<?php
// Fixture for issue #1144 — restore legacy node_order row ordering in the
// modern testUrgency screen. Creates tproject `URG1144` (prefix `URG1`,
// priority enabled) with ONE suite holding TWO test cases whose tree order
// (nodes_hierarchy.node_order) is the REVERSE of their tcversion_id order:
//
//   TC-B "URG1-1 Case B"  created FIRST  -> lower tcversion_id, node_order 2
//   TC-A "URG1-2 Case A"  created SECOND -> higher tcversion_id, node_order 1
//
// This mimics a drag-drop reorder in testSpec: legacy getSuiteUrgency()
// ORDER BY NHB.node_order renders Case A then Case B; the modern BFF used
// to usort by tcversion_id and rendered Case B then Case A.
// Run from repo root: php tmp/fixtures_1144.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tplanMgr = new testplan($db);
$buildMgr = new build($db);
$suiteMgr = new testsuite($db);
$userId = 1; // admin

foreach ((array)$tprojMgr->get_by_name('URG1144') as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

// ---- test project ----------------------------------------------------------
$item = new stdClass();
$item->name = 'URG1144';
$item->prefix = 'URG1';
$item->notes = 'fixture for issue #1144 (testUrgency node_order row ordering)';
$item->color = '';
$item->active = 1;
$item->is_public = 1;
$opts = new stdClass();
$opts->requirementsEnabled = 0;
$opts->testPriorityEnabled = 1;
$opts->automationEnabled = 0;
$opts->inventoryEnabled = 0;
$opts->platformsEnabled = 0;
$opts->testcasecfEnabled = 0;
$opts->requirementcfEnabled = 0;
$item->options = $opts;
$idP = intval($tprojMgr->create($item));
echo "tproject=$idP\n";
$tprojMgr->setActive($idP);

// ---- test plan + active/open build ----------------------------------------
$idPlan = intval($tplanMgr->create('URG1144 Plan', '', $idP, 1, 1));
$idBuild = intval($buildMgr->create($idPlan, 'URG1144 Build 1', '', 1, 1, '', $idP));
echo "testplan=$idPlan build=$idBuild\n";

// ---- suite + 2 test cases (B created before A) ----------------------------
$sid = $suiteMgr->create($idP, 'URG1 Suite Order', 'suite for issue 1144');
$sid = is_array($sid) && isset($sid['id']) ? intval($sid['id']) : intval($sid);
if ($sid <= 0) { die("suite create failed\n"); }
echo "suite=$sid\n";

$tc = new testcase($db);
$mkTcase = function ($suiteId, $name, $summary) use ($tc, $userId) {
    $s = new stdClass();
    $s->step_number = 1;
    $s->actions = 'Do ' . $name;
    $s->expected_results = 'It works';
    $s->execution_type = TESTCASE_EXECUTION_TYPE_MANUAL;
    $ret = $tc->create($suiteId, $name, $summary, '', array($s), $userId, '',
        testcase::DEFAULT_ORDER, testcase::AUTOMATIC_ID,
        TESTCASE_EXECUTION_TYPE_MANUAL);
    if (!$ret['status_ok'] || $ret['id'] <= 0) {
        die('tcase create failed: ' . ($ret['message'] ?? '') . "\n");
    }
    return array(intval($ret['id']), intval($ret['tcversion_id']));
};

// Case B created first -> LOWER tcversion_id.
list($tcB, $tvB) = $mkTcase($sid, 'URG1-1 Case B',
    'inserted first (lower tcversion_id), tree-reordered to node_order 2');
// Case A created second -> HIGHER tcversion_id.
list($tcA, $tvA) = $mkTcase($sid, 'URG1-2 Case A',
    'inserted later (higher tcversion_id), tree-reordered to node_order 1');
echo "tcaseB=$tcB tvB=$tvB tcaseA=$tcA tvA=$tvA\n";

// Simulate the drag-drop reorder: Case A first in the tree, Case B second.
$nh = DB_TABLE_PREFIX . 'nodes_hierarchy';
$db->exec_query("UPDATE $nh SET node_order=2 WHERE id=$tcB");
$db->exec_query("UPDATE $nh SET node_order=1 WHERE id=$tcA");

// ---- link both cases to the plan -------------------------------------------
$ttv = DB_TABLE_PREFIX . 'testplan_tcversions';
foreach (array(array($tvB, 10), array($tvA, 20)) as $e) {
    $tv = intval($e[0]);
    $db->exec_query("DELETE FROM $ttv WHERE testplan_id=$idPlan AND tcversion_id=$tv");
    $db->exec_query("INSERT INTO $ttv (testplan_id, platform_id, tcversion_id," .
        " author_id, node_order, urgency) VALUES ($idPlan, 0, $tv, $userId," .
        " " . intval($e[1]) . ", 2)");
}

// ---- R2 regression suite: natural order (node_order follows insertion) -----
$sidN = $suiteMgr->create($idP, 'URG1 Suite Normal', 'R2 natural order');
$sidN = is_array($sidN) && isset($sidN['id']) ? intval($sidN['id']) : intval($sidN);
if ($sidN <= 0) { die("suite Normal create failed\n"); }
list($tcC, $tvC) = $mkTcase($sidN, 'URG1-3 Case C', 'R2 natural order - first');
list($tcD, $tvD) = $mkTcase($sidN, 'URG1-4 Case D', 'R2 natural order - second');
// tree order == insertion order (no reorder)
$db->exec_query("UPDATE $nh SET node_order=1 WHERE id=$tcC");
$db->exec_query("UPDATE $nh SET node_order=2 WHERE id=$tcD");
foreach (array(array($tvC, 10), array($tvD, 20)) as $e) {
    $tv = intval($e[0]);
    $db->exec_query("DELETE FROM $ttv WHERE testplan_id=$idPlan AND tcversion_id=$tv");
    $db->exec_query("INSERT INTO $ttv (testplan_id, platform_id, tcversion_id," .
        " author_id, node_order, urgency) VALUES ($idPlan, 0, $tv, $userId," .
        " " . intval($e[1]) . ", 2)");
}

// ---- R4 regression suite: empty (no cases linked) --------------------------
$sidE = $suiteMgr->create($idP, 'URG1 Suite Empty', 'R4 empty suite');
$sidE = is_array($sidE) && isset($sidE['id']) ? intval($sidE['id']) : intval($sidE);
if ($sidE <= 0) { die("suite Empty create failed\n"); }

// Publish ids for the verify script (never hardcode auto-increment values).
file_put_contents(__DIR__ . '/fixture_1144.json', json_encode([
    'tproject' => $idP,
    'tplan'    => $idPlan,
    'build'    => $idBuild,
    'suite'    => $sid,
    'tc_case_a'=> $tcA, 'tv_case_a' => $tvA,
    'tc_case_b'=> $tcB, 'tv_case_b' => $tvB,
    'suite_normal' => $sidN,
    'tc_case_c'=> $tcC, 'tv_case_c' => $tvC,
    'tc_case_d'=> $tcD, 'tv_case_d' => $tvD,
    'suite_empty'  => $sidE,
], JSON_PRETTY_PRINT) . "\n");

echo "DONE tproject=$idP testplan=$idPlan suite=$sid suiteNormal=$sidN suiteEmpty=$sidE\n";
echo "EXPECTED urgency/suite rows: URG1-2 Case A (tv=$tvA, node_order=1) FIRST,\n";
echo "                             URG1-1 Case B (tv=$tvB, node_order=2) SECOND\n";
