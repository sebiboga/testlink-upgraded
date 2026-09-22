<?php
// Fixture for #1562 browser/curl testing: modern Execution Navigator
// (gui/templates/execute/execNavigator.html + api/execnavigator/index.php).
// Creates tproject `ExecNav Demo` (prefix ENAV1562, platforms ENABLED,
// priorities enabled) + active testplan + one OPEN and one CLOSED build + one
// linked platform + a suite with 2 test cases (one seeded PASSED execution,
// one never-run), both linked to the plan, so the legacy execTree pipeline
// renders counters + exec-status colouring on the selected build.
// Also re-creates the role-3 `norights` user (mkuser_norights.php) for the 403
// path.
// Run from repo root: php tmp/fixtures_1562.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tplanMgr = new testplan($db);
$tcaseMgr = new testcase($db);
$tsuiteMgr = new testsuite($db);
$buildMgr = new build($db);
$platformMgr = new tlPlatform($db);
$userId = 1; // admin

foreach ((array)$tprojMgr->get_by_name('ExecNav Demo') as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'ExecNav Demo';
$item->prefix = 'ENAV1562';
$item->notes = 'fixture for issue 1562 (Execution Navigator modern screen)';
$item->color = '';
$item->active = 1;
$item->is_public = 1;
$opts = new stdClass();
$opts->requirementsEnabled = 0;
$opts->testPriorityEnabled = 1;
$opts->automationEnabled = 0;
$opts->inventoryEnabled = 0;
$opts->platformsEnabled = 1;
$opts->testcasecfEnabled = 0;
$opts->requirementcfEnabled = 0;
$item->options = $opts;
$r = $tprojMgr->create($item);
$idP = intval($r);
echo "tproject=$idP (prefix ENAV1562)\n";
$tprojMgr->setActive($idP);

// ---- platform linked to the test plan ----
$platformMgr->setTestProjectID($idP);
$plat = new stdClass();
$plat->name = 'Win11';
$plat->notes = '';
$plat->testproject_id = $idP;
$plat->enable_on_design = 1;
$plat->enable_on_execution = 1;
$opPlat = $platformMgr->create($plat);
if ($opPlat['status'] != tl::OK || $opPlat['id'] <= 0) {
    die('platform create failed' . "\n");
}
$linkedPlatform = intval($opPlat['id']);
echo "platform=$linkedPlatform (Win11)\n";

// ---- testplan (active) + open + closed build ----
$idPlan = intval($tplanMgr->create('ExecNav Plan', '', $idP, 1, 1));
echo "testplan=$idPlan\n";

$idBuildOpen = intval($buildMgr->create($idPlan, 'ExecNav Build 1', '', 1, 1, '', $idP));
echo "build_open=$idBuildOpen\n";
$idBuildClosed = intval($buildMgr->create($idPlan, 'ExecNav Build 2 (closed)', '', 0, 0, '', $idP));
echo "build_closed=$idBuildClosed\n";

// link the platform to the test plan
$tpPlatTable = DB_TABLE_PREFIX . 'testplan_platforms';
$db->exec_query("DELETE FROM $tpPlatTable WHERE testplan_id=$idPlan AND platform_id=$linkedPlatform");
$db->exec_query("INSERT INTO $tpPlatTable (testplan_id, platform_id) VALUES ($idPlan, $linkedPlatform)");
echo "platform_linked=$linkedPlatform\n";

// ---- suite + 2 test cases ----
$retS = $tsuiteMgr->create($idP, 'ExecNav Suite', '', null, 0, 'allow_repeat');
$idS = intval($retS['id'] ?? 0);
echo "suite=$idS\n";

$makeTcase = function ($name, $summary, $stepAction) use ($tcaseMgr, $idS, $userId) {
    $steps = array();
    $s = new stdClass();
    $s->step_number = 1;
    $s->actions = $stepAction;
    $s->expected_results = 'Expected result for ' . $name;
    $s->execution_type = TESTCASE_EXECUTION_TYPE_MANUAL;
    $steps[] = $s;
    $ret = $tcaseMgr->create($idS, $name, $summary, '', $steps, $userId, '',
        testcase::DEFAULT_ORDER, testcase::AUTOMATIC_ID, TESTCASE_EXECUTION_TYPE_MANUAL);
    if (!$ret['status_ok'] || $ret['id'] <= 0) {
        die('tcase create failed: ' . ($ret['message'] ?? '') . "\n");
    }
    return array(intval($ret['id']), intval($ret['tcversion_id']));
};

list($idTcPassed, $idTcvPassed) = $makeTcase('ENAV1562 Login Check', 'verify styled-tree passed row',
    'Open the login page');
echo "tcase_passed=$idTcPassed tcversion=$idTcvPassed\n";
list($idTcNotRun, $idTcvNotRun) = $makeTcase('ENAV1562 Logout Check', 'verify styled-tree not-run row',
    'Click logout');
echo "tcase_notrun=$idTcNotRun tcversion=$idTcvNotRun\n";

// ---- link to plan + seed an execution on the OPEN build (platform 0) ----
$tables = tlObjectWithDB::getDBTables(array('testplans', 'builds', 'nodes_hierarchy',
    'tcversions', 'tcsteps', 'executions', 'testprojects'));
$tables['testplan_tcversions'] = DB_TABLE_PREFIX . 'testplan_tcversions';
foreach (array(array($idTcvPassed, 1), array($idTcvNotRun, 2)) as $i => $pair) {
    $db->exec_query("DELETE FROM {$tables['testplan_tcversions']} " .
        "WHERE testplan_id=$idPlan AND tcversion_id={$pair[0]} AND platform_id=0");
    $db->exec_query("INSERT INTO {$tables['testplan_tcversions']} " .
        "(testplan_id, platform_id, tcversion_id, author_id, node_order, urgency) " .
        "VALUES ($idPlan, 0, {$pair[0]}, $userId, {$pair[1]}, 2)");
}

$now = date('Y-m-d H:i:s');
$db->exec_query("INSERT INTO {$tables['executions']} " .
    "(build_id, tester_id, execution_ts, status, testplan_id, tcversion_id, " .
    " tcversion_number, platform_id, execution_type, execution_duration, notes) " .
    "VALUES ($idBuildOpen, $userId, '$now', 'p', $idPlan, $idTcvPassed, 1, 0, 1, NULL, NULL)");
$eid = intval($db->insert_id());
echo "execution_passed=$eid\n";

// ---- role-3 no-rights user (403 path) ----
require_once('tmp/mkuser_norights.php');

echo "DONE\n";
echo "tproject_id=$idP\n";
echo "testplan_id=$idPlan\n";
echo "build_open=$idBuildOpen\n";
echo "build_closed=$idBuildClosed\n";
echo "platform_id=$linkedPlatform\n";
echo "tcase_passed=$idTcPassed tcversion=$idTcvPassed\n";
echo "tcase_notrun=$idTcNotRun tcversion=$idTcvNotRun\n";