<?php
// Fixture for Refs #1717 (Modernize: Test Cases Not Run on Any Platform
// report - tcNotRunAnyPlatform). Creates a public test project with
// platforms AND priorities enabled, TWO platforms linked to the test
// plan, an active+open build, a suite and four test cases that exercise
// every branch of the report:
//
//   TC-1  linked to BOTH platforms, no execution at all      -> REPORTED
//   TC-2  linked to BOTH platforms, execution only on plat A  -> excluded
//   TC-3  linked to BOTH platforms, execution on both, last  -> excluded
//         execution on plat A is 'not_run' but plat B is passed
//   TC-4  linked to plat A only, never run there            -> REPORTED
//         (proves the per-case platform scope, not a global
//          "has any execution" test)
//
// Also creates a '<no rights>' user (role 3) to exercise the 403 path.
// Run from repo root: php tmp/fixtures_1717.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tplanMgr = new testplan($db);
$buildMgr = new build($db);
$platformMgr = new tlPlatform($db);
$tsuiteMgr = new testsuite($db);
$tcaseMgr = new testcase($db);
$userId = intval($_SESSION['userID'] ?? 1);
if ($userId <= 0) {
    die("no session user - run through the app or set \$_SESSION['userID']\n");
}

// ---- re-runnable: drop a previous run --------------------------------------
foreach ((array)$tprojMgr->get_by_name('TNRAP1717') as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}
$tblU = tlObject::getDBTables('users');
$uRows = $db->get_recordset(
    "SELECT id FROM {$tblU['users']} WHERE login = 'tnrap1717norights'");
foreach ((array)$uRows as $u) {
    echo "deleting old user {$u['id']}\n";
    $db->exec_query("DELETE FROM {$tblU['users']} WHERE id = " . intval($u['id']));
}

// ---- test project ----------------------------------------------------------
$item = new stdClass();
$item->name = 'TNRAP1717';
$item->prefix = 'TNR1717';
$item->notes = 'fixture for Refs #1717 (tcNotRunAnyPlatform modern screen)';
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
$idP = intval($tprojMgr->create($item));
echo "tproject=$idP (prefix TNR1717)\n";
$tprojMgr->setActive($idP);

// ---- two platforms ---------------------------------------------------------
$platformMgr->setTestProjectID($idP);
$platIds = array();
foreach (array('Windows 11', 'Linux Ubuntu 22') as $pname) {
    $plat = new stdClass();
    $plat->name = $pname;
    $plat->notes = '';
    $plat->testproject_id = $idP;
    $plat->enable_on_design = 1;
    $plat->enable_on_execution = 1;
    $op = $platformMgr->create($plat);
    if ($op['status'] != tl::OK || $op['id'] <= 0) {
        die("platform create failed for $pname\n");
    }
    $platIds[] = intval($op['id']);
}
list($platA, $platB) = $platIds;
echo "platformA=$platA platformB=$platB\n";

// ---- test plan (active) + active/open build -------------------------------
$idPlan = intval($tplanMgr->create('TNRAP Plan', '', $idP, 1, 1));
$idBuild = intval($buildMgr->create($idPlan, 'TNRAP Build 1', '', 1, 1, '', $idP));
// A CLOSED build: the report is defined over active+OPEN builds only, so an
// execution here must NOT remove a test case from the report (regression for
// the code-review BLOCKER: the last-status query used to ignore is_open and
// painted a Passed badge inside a "Not Run" report).
$idClosedBuild = intval($buildMgr->create($idPlan, 'TNRAP Closed Build', '', 1, 0, '', $idP));
echo "testplan=$idPlan build=$idBuild closedBuild=$idClosedBuild\n";

// link BOTH platforms to the plan
$tpPlat = DB_TABLE_PREFIX . 'testplan_platforms';
foreach ($platIds as $pid) {
    $db->exec_query("DELETE FROM $tpPlat WHERE testplan_id=$idPlan AND platform_id=$pid");
    $db->exec_query("INSERT INTO $tpPlat (testplan_id, platform_id) VALUES ($idPlan, $pid)");
}

// ---- suite + 4 test cases --------------------------------------------------
$retS = $tsuiteMgr->create($idP, 'TNRAP Suite', '', null, 0, 'allow_repeat');
$idS = intval($retS['id'] ?? 0);
if ($idS <= 0) { die("suite create failed\n"); }

$makeTcase = function ($name, $summary) use ($tcaseMgr, $idS, $userId) {
    $s = new stdClass();
    $s->step_number = 1;
    $s->actions = 'Do something for ' . $name;
    $s->expected_results = 'It works';
    $s->execution_type = TESTCASE_EXECUTION_TYPE_MANUAL;
    $ret = $tcaseMgr->create($idS, $name, $summary, '', array($s), $userId, '',
        testcase::DEFAULT_ORDER, testcase::AUTOMATIC_ID, TESTCASE_EXECUTION_TYPE_MANUAL);
    if (!$ret['status_ok'] || $ret['id'] <= 0) {
        die('tcase create failed: ' . ($ret['message'] ?? '') . "\n");
    }
    return array(intval($ret['id']), intval($ret['tcversion_id']));
};

list($tc1, $tv1) = $makeTcase('TNR1717-1 Never run anywhere',
    'no execution at all - must be reported');
list($tc2, $tv2) = $makeTcase('TNR1717-2 Run on Windows only',
    'executed on platform A only - must be excluded');
list($tc3, $tv3) = $makeTcase('TNR1717-3 Last exec not_run on A, passed on B',
    'mixed statuses - must be excluded');
list($tc4, $tv4) = $makeTcase('TNR1717-4 Linked to platform A only',
    'never run on its single platform - must be reported');
// TC-5: passed, but only on a CLOSED build. getNeverRunByPlatform() looks at
// active+open builds, so the case IS still reported - and its cell must read
// Not Run, not Passed.
list($tc5, $tv5) = $makeTcase('TNR1717-5 Passed on a CLOSED build only',
    'executed only on a closed build - still reported, cell must stay Not Run');
echo "tcases=$tc1,$tc2,$tc3,$tc4,$tc5\n";

// ---- link the cases to the plan -------------------------------------------
// TC-1/2/3 on BOTH platforms, TC-4 on platform A only.
$tables = tlObjectWithDB::getDBTables(array('executions', 'tcversions'));
$tables['testplan_tcversions'] = DB_TABLE_PREFIX . 'testplan_tcversions';
$linkMap = array(
    array($tv1, array($platA, $platB)),
    array($tv2, array($platA, $platB)),
    array($tv3, array($platA, $platB)),
    array($tv4, array($platA)),
    array($tv5, array($platA)),
);
$order = 0;
foreach ($linkMap as $entry) {
    $tv = $entry[0];
    foreach ($entry[1] as $pid) {
        $db->exec_query("DELETE FROM {$tables['testplan_tcversions']} " .
            "WHERE testplan_id=$idPlan AND tcversion_id=$tv AND platform_id=$pid");
        $db->exec_query("INSERT INTO {$tables['testplan_tcversions']} " .
            "(testplan_id, platform_id, tcversion_id, author_id, node_order, urgency) " .
            "VALUES ($idPlan, $pid, $tv, $userId, " . ($order * 10) . ", 2)");
    }
    $order++;
}

// ---- seed executions -------------------------------------------------------
$now = date('Y-m-d H:i:s');
$addExec = function ($tv, $pid, $status, $ts) use ($db, $tables, $idBuild, $idPlan, $userId, $now) {
    $when = $ts ?? $now;
    $db->exec_query("INSERT INTO {$tables['executions']} " .
        "(build_id, tester_id, execution_ts, status, testplan_id, tcversion_id, " .
        " tcversion_number, platform_id, execution_type, execution_duration, notes) " .
        "VALUES ($idBuild, $userId, '$when', '$status', $idPlan, $tv, 1, $pid, 1, NULL, NULL)");
    return intval($db->insert_id());
};

// TC-2: passed on A only.
$e1 = $addExec($tv2, $platA, 'p', null);
// TC-3: passed on B, then a LATER not_run on A -> A's last real status is
// not_run but the case is executed on B, so it must be excluded.
$e2 = $addExec($tv3, $platB, 'p', '2020-01-01 10:00:00');
$e3 = $addExec($tv3, $platA, 'n', null);
// TC-5: passed on the CLOSED build only.
$db->exec_query("INSERT INTO {$tables['executions']} " .
    "(build_id, tester_id, execution_ts, status, testplan_id, tcversion_id, " .
    " tcversion_number, platform_id, execution_type, execution_duration, notes) " .
    "VALUES ($idClosedBuild, $userId, '$now', 'p', $idPlan, $tv5, 1, $platA, 1, NULL, NULL)");
$e4 = intval($db->insert_id());
echo "executions=$e1,$e2,$e3,$e4 (last one on the CLOSED build)\n";

// ---- role-3 no-rights user (403 path) -------------------------------------
$h = password_hash('admin', PASSWORD_BCRYPT);
$cs = hash('sha256', uniqid('t1717', true));
$db->exec_query("INSERT INTO {$tblU['users']} " .
    "(login,password,email,first,last,locale,role_id,active,cookie_string,auth_method) " .
    "VALUES ('tnrap1717norights','$h','tnrap1717norights@localhost','No','Rights'," .
    "'en_GB',3,1,'$cs','')");
$uNo = intval($db->insert_id());
echo "user tnrap1717norights=$uNo (role 3)\n";

// Publish the ids so tmp/test_1717.php never hardcodes auto-increment values
// (the suite must stay re-runnable on a database that already holds data).
file_put_contents(__DIR__ . '/fixture_1717.json', json_encode([
    'tproject' => intval($idP),
    'tplan'    => intval($idPlan),
    'build'    => intval($idBuild),
    'plat_a'   => intval($platA),
    'plat_b'   => intval($platB),
    'tc_never_both'  => $tc1,
    'tc_never_both2' => $tc4,
    'tc_pass_a'      => $tc2,
    'tc_mixed'       => $tc3,
    'tc_closed_build' => $tc5,
    'unorights' => $uNo,
], JSON_PRETTY_PRINT) . "\n");

echo "DONE tproject=$idP testplan=$idPlan build=$idBuild platA=$platA platB=$platB " .
     "unorights=$uNo\n";
echo "EXPECTED rows=3 (TNR1717-1, TNR1717-4, TNR1717-5 - the last one only\n";
echo "          because its only execution is on a CLOSED build)\n";
