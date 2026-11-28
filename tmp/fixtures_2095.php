<?php
// Re-runnable fixture for issue #2095 (Test Plan Assignment Ageing screen).
// Run from the repo root: php tmp/fixtures_2095.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);
$tables = tlObjectWithDB::getDBTables(array(
    'nodes_hierarchy', 'testprojects', 'testplans', 'builds', 'platforms',
    'testplan_tcversions', 'user_assignments', 'executions', 'users',
));

$old = $db->get_recordset(
    "SELECT id FROM {$tables['nodes_hierarchy']} " .
    "WHERE name = 'AAG2095' AND node_type_id = 1");
foreach ((array)$old as $row) {
    $pid = intval($row['id']);
    $plans = $db->get_recordset(
        "SELECT id FROM {$tables['testplans']} WHERE testproject_id = " . $pid);
    foreach ((array)$plans as $pl) {
        $tpid = intval($pl['id']);
        $db->exec_query("DELETE FROM {$tables['user_assignments']} WHERE feature_id IN " .
            "(SELECT id FROM {$tables['testplan_tcversions']} WHERE testplan_id = " . $tpid . ")");
        $db->exec_query("DELETE FROM {$tables['executions']} WHERE testplan_id = " . $tpid);
    }
    $tp = new testproject($db);
    $tp->delete($pid, 1);
}

$tprojectMgr = new testproject($db);
$tplanMgr = new testplan($db);
$tcaseMgr = new testcase($db);
$tsuiteMgr = new testsuite($db);
$buildMgr = new build($db);
$platformMgr = new tlPlatform($db);
$adminId = 1;

$item = new stdClass();
$item->name = 'AAG2095';
$item->prefix = 'AAG';
$item->notes = 'fixture for #2095 assignment ageing';
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
$projectId = intval($tprojectMgr->create($item));
$tprojectMgr->setActive($projectId);
echo "project=$projectId\n";

function aagPlatform($db, $mgr, $projectId, $name)
{
    $mgr->setTestProjectID($projectId);
    $p = new stdClass();
    $p->name = $name;
    $p->notes = '';
    $p->testproject_id = $projectId;
    $p->enable_on_design = 1;
    $p->enable_on_execution = 1;
    $r = $mgr->create($p);
    if ($r['status'] != tl::OK || $r['id'] <= 0) {
        die("platform create failed: $name\n");
    }
    return intval($r['id']);
}
$platform1 = aagPlatform($db, $platformMgr, $projectId, 'Chrome');
$platform2 = aagPlatform($db, $platformMgr, $projectId, 'Firefox');

$planId = intval($tplanMgr->create('Aging Plan', 'plan for #2095', $projectId));
$suiteRet = $tsuiteMgr->create($projectId, 'Aging Suite', 'suite for #2095');
$suiteId = intval($suiteRet['id']);

function aagCase($mgr, $suiteId, $name, $importance)
{
    $step = new stdClass();
    $step->step_number = 1;
    $step->actions = 'check assignment';
    $step->expected_results = 'assignment visible';
    $step->execution_type = TESTCASE_EXECUTION_TYPE_MANUAL;
    $ret = $mgr->create($suiteId, $name, 'assignment ageing fixture', '', array($step), 1,
        '', testcase::DEFAULT_ORDER, testcase::AUTOMATIC_ID,
        TESTCASE_EXECUTION_TYPE_MANUAL, $importance);
    if (!$ret['status_ok'] || $ret['id'] <= 0) {
        die("testcase create failed: $name\n");
    }
    return array('id' => intval($ret['id']), 'tcversion_id' => intval($ret['tcversion_id']));
}

$cases = array(
    aagCase($tcaseMgr, $suiteId, 'Fresh passed case', 3),
    aagCase($tcaseMgr, $suiteId, 'Watch overdue case', 3),
    aagCase($tcaseMgr, $suiteId, 'Stale overdue case', 2),
    aagCase($tcaseMgr, $suiteId, 'Dormant failed case', 2),
    aagCase($tcaseMgr, $suiteId, 'Fresh never case', 1),
    aagCase($tcaseMgr, $suiteId, 'Any platform case', 1),
    aagCase($tcaseMgr, $suiteId, 'Closed assignment case', 1),
);

$links = null;
foreach ($cases as $i => $c) {
    $links['tcversion'][$c['id']] = $c['tcversion_id'];
    $plat = ($i === 1) ? $platform2 : $platform1;
    $links['items'][$c['id']][$plat] = $c['tcversion_id'];
}
$tplanMgr->link_tcversions($planId, $links, $adminId);

// One assignment is explicitly linked to platform 0 ("any platform").
$db->exec_query("UPDATE {$tables['testplan_tcversions']} SET platform_id = 0 " .
    "WHERE testplan_id = " . $planId . " AND tcversion_id = " . $cases[5]['tcversion_id']);

$openBuild = intval($buildMgr->create($planId, 'AAG-Build-Open', '', 1, 1, ''));
$closedBuild = intval($buildMgr->create($planId, 'AAG-Build-Closed', '', 1, 0, ''));

function aagUser($db, $tables, $login, $roleId)
{
    $rs = $db->get_recordset("SELECT id FROM {$tables['users']} WHERE login = '" . $login . "'");
    $hash = password_hash('aag123', PASSWORD_BCRYPT);
    if (is_array($rs) && count($rs) > 0) {
        $uid = intval($rs[0]['id']);
        $db->exec_query("UPDATE {$tables['users']} SET password = '" . $hash . "', " .
            "active = 1, auth_method = 'DB', role_id = " . intval($roleId) .
            " WHERE id = " . $uid);
        return $uid;
    }
    $db->exec_query("INSERT INTO {$tables['users']} " .
        "(login,password,email,first,last,role_id,locale,active,script_key,cookie_string,auth_method) " .
        "VALUES ('" . $login . "','" . $hash . "','" . $login . "@example.com','" .
        ucfirst($login) . "','Fixture'," . $roleId . ",'en_GB',1,'','" . $login . "cookie','DB')");
    return intval($db->insert_id($tables['users']));
}
$testerId = aagUser($db, $tables, 'aagtester', 7);
$viewerId = aagUser($db, $tables, 'aagviewer', 3);

$tptcv = $db->get_recordset("SELECT id, tcversion_id, platform_id " .
    "FROM {$tables['testplan_tcversions']} WHERE testplan_id = " . $planId);
$tptcvMap = array();
foreach ((array)$tptcv as $r) {
    $tptcvMap[$r['tcversion_id'] . '|' . $r['platform_id']] = intval($r['id']);
}

function aagAssign($db, $tables, $featureId, $userId, $buildId, $created, $deadline, $status = 1)
{
    $dl = $deadline === null ? 'NULL' : "'" . $deadline . "'";
    $db->exec_query("INSERT INTO {$tables['user_assignments']} " .
        "(type,feature_id,user_id,build_id,deadline_ts,assigner_id,creation_ts,status) " .
        "VALUES (1," . intval($featureId) . "," . intval($userId) . "," . intval($buildId) .
        "," . $dl . ",1,'" . $created . "'," . intval($status) . ")");
}
$daysAgo = function ($n) { return date('Y-m-d H:i:s', time() - 86400 * $n); };
$daysAhead = function ($n) { return date('Y-m-d H:i:s', time() + 86400 * $n); };

aagAssign($db, $tables, $tptcvMap[$cases[0]['tcversion_id'] . '|' . $platform1],
    $adminId, $openBuild, $daysAgo(2), $daysAhead(10));
aagAssign($db, $tables, $tptcvMap[$cases[1]['tcversion_id'] . '|' . $platform2],
    $testerId, $openBuild, $daysAgo(15), $daysAgo(5));
aagAssign($db, $tables, $tptcvMap[$cases[2]['tcversion_id'] . '|' . $platform1],
    $testerId, $openBuild, $daysAgo(45), $daysAgo(40));
aagAssign($db, $tables, $tptcvMap[$cases[3]['tcversion_id'] . '|' . $platform1],
    $testerId, $closedBuild, $daysAgo(120), null);
aagAssign($db, $tables, $tptcvMap[$cases[4]['tcversion_id'] . '|' . $platform1],
    $adminId, 0, $daysAgo(5), null);
aagAssign($db, $tables, $tptcvMap[$cases[5]['tcversion_id'] . '|0'],
    $testerId, $openBuild, $daysAgo(60), $daysAhead(3));
aagAssign($db, $tables, $tptcvMap[$cases[6]['tcversion_id'] . '|' . $platform1],
    $testerId, $openBuild, $daysAgo(3), null, 2);

function aagExec($db, $tables, $tcversionId, $platformId, $buildId, $testerId, $status, $when)
{
    $db->exec_query("INSERT INTO {$tables['executions']} " .
        "(status,tester_id,execution_ts,tcversion_id,tcversion_number,testplan_id,platform_id,build_id) " .
        "VALUES ('" . $status . "'," . intval($testerId) . ",'" . $when . "'," .
        intval($tcversionId) . ",1," . intval($GLOBALS['planId']) . "," .
        intval($platformId) . "," . intval($buildId) . ")");
}
aagExec($db, $tables, $cases[0]['tcversion_id'], $platform1, $openBuild, $adminId, 'p', $daysAgo(1));
aagExec($db, $tables, $cases[3]['tcversion_id'], $platform1, $closedBuild, $testerId, 'f', $daysAgo(100));

echo "project=$projectId plan=$planId build_open=$openBuild build_closed=$closedBuild " .
     "platform1=$platform1 platform2=$platform2 tester=$testerId viewer=$viewerId\n";
echo "seeded 7 assignments (1 closed), 2 executions\n";
