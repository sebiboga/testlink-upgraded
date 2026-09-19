<?php
// Fixture for #1544 browser testing: modern tcAssignedToUser screen
// (gui/templates/results/tcAssignedToUser.html + api/tcassigned/index.php).
// Creates tproject `TA2U` + testplan + 2 builds (open, closed) + platform +
// priority + testcases, links tcversions to the testplan on the platform and
// seeds user_assignments (type=1 testcase_execution) for admin + a tester.
// Run from repo root: php tmp/fixtures_1544.php
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

// Reset on re-runs (repo rule: fixtures must be re-runnable).
foreach ((array)$tprojMgr->get_by_name('TA2U') as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'TA2U';
$item->prefix = 'TA2';
$item->notes = 'fixture for issue 1544 (tcAssignedToUser modern screen)';
$item->color = '';
$item->active = 1;
$item->is_public = 1;
$opts = new stdClass();
$opts->requirementsEnabled = 0;
$opts->testPriorityEnabled = 1;
$opts->automationEnabled = 0;
$opts->inventoryEnabled = 0;
$opts->platformsEnabled = 1;
$opts->testcasecfEnabled = 1;
$opts->requirementcfEnabled = 0;
$item->options = $opts;
$r = $tprojMgr->create($item);
$idP = intval($r);
echo "tproject=$idP (prefix TA2)\n";
$tprojMgr->setActive($idP);

// platform (node under testproject)
$platformMgr->setTestProjectID($idP);
$plat = new stdClass();
$plat->name = 'Win11';
$plat->notes = '';
$plat->testproject_id = $idP;
$plat->enable_on_design = 1;
$plat->enable_on_execution = 1;
$opPlat = $platformMgr->create($plat);
if ($opPlat['status'] != tl::OK || $opPlat['id'] <= 0) { die('platform create failed' . "\n"); }
$idPlat = intval($opPlat['id']);
echo "platform=$idPlat\n";

// second platform to prove multi-platform rendering
$plat2 = new stdClass();
$plat2->name = 'MacOS';
$plat2->notes = '';
$plat2->testproject_id = $idP;
$plat2->enable_on_design = 1;
$plat2->enable_on_execution = 1;
$opPlat2 = $platformMgr->create($plat2);
if ($opPlat2['status'] != tl::OK || $opPlat2['id'] <= 0) { die('platform2 create failed' . "\n"); }
$idPlat2 = intval($opPlat2['id']);
echo "platform2=$idPlat2\n";

// testplan (node under testproject)
$idTp = intval($tplanMgr->create('Plan TA2U', 'fixture plan for #1544', $idP));
echo "testplan=$idTp (node parent=$idP)\n";

// testsuite under the testproject (design tree), then tcases under it
$tsuiteRet = $tsuiteMgr->create($idP, 'Fixture Suite', 'suite for #1544');
if (!$tsuiteRet['status_ok'] || $tsuiteRet['id'] <= 0) { die('testsuite create failed: ' . $tsuiteRet['msg'] . "\n"); }
$idTsuite = intval($tsuiteRet['id']);
echo "testsuite=$idTsuite (node parent=$idP)\n";

function makeTc($tcaseMgr, $parent, $name, $order, $imp) {
    $steps = array();
    $steps[0] = new stdClass();
    $steps[0]->step_number = 1;
    $steps[0]->actions = 'open app';
    $steps[0]->expected_results = 'app opens';
    $steps[0]->execution_type = TESTCASE_EXECUTION_TYPE_MANUAL;
    $tcase = new stdClass();
    $tcase->name = $name;
    $tcase->summary = 'tc used to exercise tcAssignedToUser';
    $tcase->preconditions = '';
    $tcase->steps = $steps;
    $ret = $tcaseMgr->create($parent, $tcase->name, $tcase->summary, $tcase->preconditions, $steps, 1,
        '', $order, testcase::AUTOMATIC_ID, TESTCASE_EXECUTION_TYPE_MANUAL, $imp);
    if (!$ret['status_ok'] || $ret['id'] <= 0) {
        die('tcase create failed: ' . $ret['message'] . "\n");
    }
    return array('id' => intval($ret['id']),
                 'tcversion_id' => intval($ret['tcversion_id']));
}

// Three testcases: TC-OpenAdmin (high prio, admin on open build, on Win11),
// TC-OpenTester (tester on open build, no execution yet), TC-ClosedTester
// (tester on closed build), TC-MacTester (tester on MacOS platform).
$tc1 = makeTc($tcaseMgr, $idTsuite, 'Open Admin Case', testcase::DEFAULT_ORDER, 3);
$tc2 = makeTc($tcaseMgr, $idTsuite, 'Open Tester Case', testcase::DEFAULT_ORDER, 2);
$tc3 = makeTc($tcaseMgr, $idTsuite, 'Closed Tester Case', testcase::DEFAULT_ORDER, 1);
$tc4 = makeTc($tcaseMgr, $idTsuite, 'Mac Tester Case', testcase::DEFAULT_ORDER, 2);
echo "tc1=$tc1[id] tcv1=$tc1[tcversion_id] | tc2=$tc2[id] tcv2=$tc2[tcversion_id] | tc3=$tc3[id] tcv3=$tc3[tcversion_id] | tc4=$tc4[id] tcv4=$tc4[tcversion_id]\n";

// link tcversions to testplan on both platforms
$items_to_link = null;
foreach (array($tc1, $tc2, $tc3, $tc4) as $tc) {
    $items_to_link['tcversion'][$tc['id']] = $tc['tcversion_id'];
    $items_to_link['items'][$tc['id']][$idPlat] = $tc['tcversion_id'];
}
// tc4 also linked on the second platform only
$items_to_link['platform'][$idPlat] = $idPlat;
$items_to_link['platform'][$idPlat2] = $idPlat2;
$items_to_link['items'][$tc4['id']][$idPlat2] = $tc4['tcversion_id'];
$tplanMgr->link_tcversions($idTp, $items_to_link, $userId);
echo "linked tcversions to tplan=$idTp\n";

// builds
$idBuildOpen = intval($buildMgr->create($idTp, 'BUILD-OPEN', 'fixture open build for #1544', 1, 1, ''));
$idBuildClosed = intval($buildMgr->create($idTp, 'BUILD-CLOSED', 'fixture closed build for #1544', 1, 0, ''));
echo "build_open=$idBuildOpen build_closed=$idBuildClosed\n";

// Ensure tester user exists (id 2 tester1) with known password (tester1).
$utables = tlObjectWithDB::getDBTables(array('users', 'user_assignments', 'testplan_tcversions', 'executions'));
$ex = $db->get_recordset(' SELECT id FROM ' . $utables['users'] . ' WHERE login=\'tester1\'');
if (!empty($ex)) {
    $testerId = intval($ex[0]['id']);
    echo "tester1 already exists id=$testerId\n";
} else {
    $db->exec_query(" INSERT INTO " . $utables['users'] .
        " (login,password,email,first,last,role_id,locale,active,script_key,cookie_string,auth_method)" .
        " VALUES ('tester1','\$2y\$10\$lhIIG2jMq1neLBjPFLlV2uGiP8YPYClGLpO6n1YYWAXNlUCStFBoC','tester1@example.com','Tester','One',8,'en_GB',1,'','tester1cookie','DB')");
    $testerId = intval($db->insert_id($utables['users']));
    echo "created tester1 id=$testerId\n";
}

// get testplan_tcversions rows to seed user_assignments (feature_id = TPTCV.id)
$tpTable = $utables['testplan_tcversions'];
$tpcv = $db->get_recordset(
    ' SELECT id,tcversion_id,platform_id FROM ' . $tpTable .
    ' WHERE testplan_id = ' . intval($idTp));
$tpcvMap = array(); // tcversion_id|platform_id => tptcv id
foreach ($tpcv as $row) {
    $tpcvMap[$row['tcversion_id'] . '|' . $row['platform_id']] = intval($row['id']);
}
echo "tptcv rows: " . count($tpcv) . "\n";

// user_assignments: admin -> tc1 on open build; tester1 -> tc2 open build,
// tc3 closed build, tc4 mac platform (open build), and a deadline on tc2.
$ua = $utables['user_assignments'];
function seedAssign($db, $ua, $featureId, $userId, $buildId, $deadline = null) {
    $dl = $deadline !== null ? ",'" . $deadline . "'" : ",NULL";
    $db->exec_query(" INSERT INTO " . $ua .
        " (type,feature_id,user_id,build_id,deadline_ts,assigner_id,creation_ts,status)" .
        " VALUES (1,$featureId,$userId,$buildId$dl,1,NOW(),1)");
}
seedAssign($db, $ua, $tpcvMap[$tc1['tcversion_id'] . '|' . $idPlat], $userId, $idBuildOpen);
seedAssign($db, $ua, $tpcvMap[$tc2['tcversion_id'] . '|' . $idPlat], $testerId, $idBuildOpen,
    date('Y-m-d H:i:s', time() + 86400 * 5));
seedAssign($db, $ua, $tpcvMap[$tc3['tcversion_id'] . '|' . $idPlat], $testerId, $idBuildClosed);
seedAssign($db, $ua, $tpcvMap[$tc4['tcversion_id'] . '|' . $idPlat2], $testerId, $idBuildOpen);
echo "seeded 4 user_assignments (admin/opener on open build, tester1 on open/closed/mac)\n";

// record a prior execution for tc1 (open build, admin passed it) so the
// last-execution status column is exercised (executions insert parity w/ quick_exec)
$tblExec = $utables['executions'];
$db->exec_query(" INSERT INTO " . $tblExec .
    " (status,tester_id,execution_ts,tcversion_id,tcversion_number,testplan_id,platform_id,build_id)" .
    " VALUES ('p',1,NOW()," . intval($tc1['tcversion_id']) . ",1," . intval($idTp) .
    "," . intval($idPlat) . "," . intval($idBuildOpen) . ")");
echo "seeded 1 prior execution (tc1 passed by admin)\n";

echo "fixture ready: tproject=$idP tplan=$idTp tc1=$tc1[id]/$tc1[tcversion_id] tc2=$tc2[id]/$tc2[tcversion_id] tc3=$tc3[id]/$tc3[tcversion_id] tc4=$tc4[id]/$tc4[tcversion_id] build_open=$idBuildOpen build_closed=$idBuildClosed platform=$idPlat platform2=$idPlat2 tester=$testerId\n";