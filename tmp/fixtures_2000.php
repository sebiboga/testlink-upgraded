<?php
// Fixture for Test Cases Created per User (gui/templates/results/tcCreatedPerUser.html
// + api/tccreatedperuser/index.php). Refs #2000.
//
// TCPU2000: 4 test cases / 5 versions by 3 authors across 2 suites, with
//           explicit creation timestamps for date-window tests.
// TCPU2000 EMPTY: project with no test cases (no_data).
// tpcu2000alice / tpcu2000bob: users with test designer rights.
// tpcu2000norights: role 3 user without testplan_metrics (403 path).
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);
$tprojMgr = new testproject($db);
$tsuiteMgr = new testsuite($db);
$tcaseMgr = new testcase($db);
$tables = tlObjectWithDB::getDBTables(array(
    'users', 'nodes_hierarchy', 'tcversions',
));

function tpcuProject($mgr, $name, $prefix)
{
    foreach ((array)$mgr->get_by_name($name) as $row) {
        $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
        if ($oid > 0) { $mgr->delete($oid, 1); }
    }
    $item = new stdClass();
    $item->name = $name; $item->prefix = $prefix; $item->notes = 'fixture #2000';
    $item->color = ''; $item->active = 1; $item->is_public = 1;
    $opts = new stdClass();
    $opts->requirementsEnabled = 0; $opts->testPriorityEnabled = 1;
    $opts->automationEnabled = 0; $opts->inventoryEnabled = 0;
    $opts->platformsEnabled = 0; $opts->testcasecfEnabled = 0;
    $opts->requirementcfEnabled = 0;
    $item->options = $opts;
    $id = intval($mgr->create($item));
    if ($id <= 0) { die("project create failed: $name\n"); }
    $mgr->setActive($id);
    echo "tproject $name=$id\n";
    return $id;
}

function tpcuUser($db, $tables, $login, $roleId, $first, $last)
{
    $hash = password_hash($login, PASSWORD_DEFAULT);
    $db->exec_query("INSERT INTO {$tables['users']}
        (login,password,role_id,email,first,last,locale,default_testproject_id,
         active,cookie_string,auth_method)
      VALUES ('$login','$hash',$roleId,'$login@example.test','$first','$last','en_GB',0,1,
              'ck_$login','DB')
      ON DUPLICATE KEY UPDATE password=VALUES(password),role_id=$roleId,active=1,
              first=VALUES(first),last=VALUES(last),auth_method=VALUES(auth_method)");
    $r = $db->get_recordset("SELECT id FROM {$tables['users']} WHERE login='$login'");
    return intval($r[0]['id']);
}

function tpcuSteps()
{
    $s = new stdClass();
    $s->step_number = 1; $s->actions = 'Do it';
    $s->expected_results = 'It works';
    $s->execution_type = TESTCASE_EXECUTION_TYPE_MANUAL;
    return array($s);
}

$idP = tpcuProject($tprojMgr, 'TCPU2000', 'TP');
$idEmpty = tpcuProject($tprojMgr, 'TCPU2000 EMPTY', 'TE');

$alice = tpcuUser($db, $tables, 'tpcu2000alice', 4, 'Alice', 'Designer');
$bob = tpcuUser($db, $tables, 'tpcu2000bob', 4, 'Bob', 'Designer');
tpcuUser($db, $tables, 'tpcu2000norights', 3, 'No', 'Rights');

$suiteA = intval($tsuiteMgr->create($idP, 'TCPU Suite Alpha', '', null, 0, 'allow_repeat')['id']);
$suiteB = intval($tsuiteMgr->create($idP, 'TCPU Suite Beta', '', null, 0, 'allow_repeat')['id']);

$cases = array(
    array($suiteA, 'TCPU Login smoke', $alice, '2026-11-12 08:15:00', 1),
    array($suiteA, 'TCPU Search regression', $bob, '2026-11-13 11:45:00', 2),
    array($suiteB, 'TCPU Checkout happy path', $alice, '2026-11-15 16:30:00', 3),
    array($suiteB, 'TCPU Reporting export', $bob, '2026-11-18 09:05:00', 2),
);
$caseIds = array();
foreach ($cases as $idx => $c) {
    $ret = $tcaseMgr->create($c[0], $c[1], "summary {$c[1]}", '', tpcuSteps(),
        $c[4], '', testcase::DEFAULT_ORDER, testcase::AUTOMATIC_ID,
        TESTCASE_EXECUTION_TYPE_MANUAL);
    if (!$ret['status_ok']) { die("tcase failed: {$c[1]}\n"); }
    $tcv = intval($ret['tcversion_id']);
    $db->exec_query("UPDATE {$tables['tcversions']}
        SET author_id={$c[2]}, creation_ts='{$c[3]}', modification_ts='{$c[3]}'
        WHERE id=$tcv");
    $caseIds[$idx] = intval($ret['id']);
    echo "tcase {$c[1]}={$ret['id']} v$tcv author={$c[2]} at {$c[3]}\n";
}

// Extra version of an existing case: the report must show one row per version.
$newVersion = $tcaseMgr->create_new_version($caseIds[2], $bob, null);
if (!is_array($newVersion) || empty($newVersion['id'])) {
    die("new version failed: " . json_encode($newVersion) . "\n");
}
$newTcv = intval($newVersion['id']);
$db->exec_query("UPDATE {$tables['tcversions']}
    SET author_id=$bob, creation_ts='2026-11-17 13:20:00', modification_ts='2026-11-17 13:20:00'
    WHERE id=$newTcv");
echo "extra version tcv=$newTcv\n";

echo "DONE idP=$idP idEmpty=$idEmpty alice=$alice bob=$bob suiteA=$suiteA suiteB=$suiteB\n";
