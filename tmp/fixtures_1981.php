<?php
// Fixture for the Test Plan Blocked Tests Ageing report
// (gui/templates/results/blockedAgeing.html + api/blockedageing/index.php).
// Refs #1981.
//
// Creates:
//   - project BAG1981 (prefix BG), platforms enabled, plan "BAG Plan"
//     with 2 platforms linked, 2 builds, 7 test cases:
//       TC1 blocked 35 days ago (build 1, note + 1 linked bug)  -> older_30
//       TC2 blocked 10 days ago (build 2)                        -> older_7
//       TC3 blocked 2 days ago  (build 2, note)                   -> fresh
//       TC4 blocked 40 days ago THEN re-executed PASSED (latest) -> NOT listed
//       TC5 never executed                                       -> NOT listed
//       TC6 blocked 12 days ago on platform 1
//       TC7 blocked 12 days ago on platform 2
//   - project BAG1981 EMPTY with an empty plan (blocked = 0 state)
//   - user bage1981norights (role 3, no testplan_metrics) for the 403 path
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);
$tprojMgr = new testproject($db);
$tplanMgr = new testplan($db);
$tsuiteMgr = new testsuite($db);
$tcaseMgr = new testcase($db);
$tables = tlObject::getDBTables();

function mk1981Project($mgr, $name, $prefix, $platforms)
{
    foreach ((array)$mgr->get_by_name($name) as $row) {
        $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
        if ($oid > 0) { $mgr->delete($oid, 1); }
    }
    $item = new stdClass();
    $item->name = $name; $item->prefix = $prefix; $item->notes = 'fixture #1981';
    $item->color = ''; $item->active = 1; $item->is_public = 1;
    $opts = new stdClass();
    $opts->requirementsEnabled = 0; $opts->testPriorityEnabled = 0;
    $opts->automationEnabled = 0; $opts->inventoryEnabled = 0;
    $opts->platformsEnabled = $platforms; $opts->testcasecfEnabled = 0;
    $opts->requirementcfEnabled = 0;
    $item->options = $opts;
    $id = intval($mgr->create($item));
    if ($id <= 0) { die("project create failed: $name\n"); }
    $mgr->setActive($id);
    echo "tproject $name=$id\n";
    return $id;
}

$idP = mk1981Project($tprojMgr, 'BAG1981', 'BG', 1);
$idEmpty = mk1981Project($tprojMgr, 'BAG1981 EMPTY', 'BZ', 0);
$idPlan = intval($tplanMgr->create('BAG Plan', '', $idP, 1, 1));
$idPlanEmpty = intval($tplanMgr->create('BAG Empty Plan', '', $idEmpty, 1, 1));
echo "plans=$idPlan,$idPlanEmpty\n";

$retS = $tsuiteMgr->create($idP, 'BG Root Suite', '', null, 0, 'allow_repeat');
$idRoot = intval($retS['id'] ?? 0);
$retS2 = $tsuiteMgr->create($idP, 'BG Child Suite', '', null, $idRoot, 'allow_repeat');
$idChild = intval($retS2['id'] ?? 0);

function mk1981Steps()
{
    $s = array();
    $t = new stdClass();
    $t->step_number = 1;
    $t->actions = 'Do the thing';
    $t->expected_results = 'It works';
    $t->execution_type = TESTCASE_EXECUTION_TYPE_MANUAL;
    $s[] = $t;
    return $s;
}
$cases = array();
foreach (array('BG TC one blocked long', 'BG TC two blocked medium',
    'BG TC three blocked fresh', 'BG TC four recovered',
    'BG TC five never run', 'BG TC six platform one',
    'BG TC seven platform two') as $i => $nm) {
    $parent = ($i % 2 === 0) ? $idRoot : $idChild;
    $ret = $tcaseMgr->create($parent, $nm, "summary $nm", '', mk1981Steps(),
        1, '', testcase::DEFAULT_ORDER, testcase::AUTOMATIC_ID,
        TESTCASE_EXECUTION_TYPE_MANUAL);
    if (!$ret['status_ok']) { die("tcase failed: $nm\n"); }
    $cases[] = array(intval($ret['id']), intval($ret['tcversion_id']));
    echo "tcase $nm = {$ret['id']} v {$ret['tcversion_id']}\n";
}

$db->exec_query("INSERT INTO {$tables['builds']}
    (testproject_id, name, notes, active, is_open, author_id, creation_ts)
  VALUES ($idP, 'BAG Build 1', 'fixture build', 1, 1, 1, NOW())");
$build1 = intval($db->insert_id($tables['builds']));
$db->exec_query("INSERT INTO {$tables['builds']}
    (testproject_id, name, notes, active, is_open, author_id, creation_ts)
  VALUES ($idP, 'BAG Build 2', 'fixture build 2', 1, 1, 1, NOW())");
$build2 = intval($db->insert_id($tables['builds']));
echo "builds=$build1,$build2\n";

$db->exec_query("INSERT INTO {$tables['platforms']}
    (testproject_id, name, notes) VALUES ($idP, 'BAG Platform 1', 'fixture')");
$plat1 = intval($db->insert_id($tables['platforms']));
$db->exec_query("INSERT INTO {$tables['platforms']}
    (testproject_id, name, notes) VALUES ($idP, 'BAG Platform 2', 'fixture')");
$plat2 = intval($db->insert_id($tables['platforms']));
$db->exec_query("INSERT INTO {$tables['testplan_platforms']}
    (testplan_id, platform_id) VALUES ($idPlan, $plat1), ($idPlan, $plat2)");
echo "platforms=$plat1,$plat2\n";

// Assign TC1-TC5 to the plan without platform (legacy-style link), TC6 on
// platform 1, TC7 on platform 2.
foreach ($cases as $i => $c) {
    $linkPlat = 0;
    if ($i === 5) { $linkPlat = $plat1; }
    if ($i === 6) { $linkPlat = $plat2; }
    $db->exec_query("INSERT INTO {$tables['testplan_tcversions']}
        (testplan_id, tcversion_id, platform_id, author_id, creation_ts)
      VALUES ($idPlan, {$c[1]}, $linkPlat, 1, NOW())");
}

// Executions: (tcv, build, platform, status, days_ago, notes)
$mkExec = function ($tcv, $build, $plat, $status, $days, $notes) use ($db, $tables, $idPlan, $build1) {
    $db->exec_query("INSERT INTO {$tables['executions']}
        (testplan_id, build_id, tester_id, execution_ts, status, tcversion_id,
         tcversion_number, platform_id, execution_type, notes)
      VALUES ($idPlan, $build, 1, DATE_SUB(NOW(), INTERVAL $days DAY), '$status',
              $tcv, 1, $plat, 1, " . ($notes !== '' ? "'" . $db->prepare_string($notes) . "'" : 'NULL') . ")");
    return intval($db->insert_id($tables['executions']));
};

$e1 = $mkExec($cases[0][1], $build1, 0, 'b', 35, 'Database locked by migration');
$e2 = $mkExec($cases[1][1], $build2, 0, 'b', 10, '');
$e3 = $mkExec($cases[2][1], $build2, 0, 'b', 2, 'Waiting for env restart');
$e4a = $mkExec($cases[3][1], $build1, 0, 'b', 40, '');
$e4b = $mkExec($cases[3][1], $build2, 0, 'p', 1, 'fixed and re-run');
$e6 = $mkExec($cases[5][1], $build1, $plat1, 'b', 12, 'Only on platform one');
$e7 = $mkExec($cases[6][1], $build1, $plat2, 'b', 12, '');
echo "executions=$e1,$e2,$e3,$e4a,$e4b,$e6,$e7\n";

$db->exec_query("INSERT INTO {$tables['execution_bugs']}
    (execution_id, bug_id, tcstep_id) VALUES ($e1, 'BAG-1001', 0)");
$db->exec_query("INSERT INTO {$tables['execution_bugs']}
    (execution_id, bug_id, tcstep_id) VALUES ($e6, 'BAG-1002', 0)");

$TBU = tlObjectWithDB::getDBTables(array('users'));
$hash = password_hash('bagenorights', PASSWORD_DEFAULT);
$db->exec_query("INSERT INTO {$TBU['users']}
    (login, password, role_id, email, first, last, locale,
     default_testproject_id, active, cookie_string, auth_method)
  VALUES ('bage1981norights', '$hash', 3, 'n@no.no', 'No', 'Rights', 'en_GB', 0, 1,
          'ck_bagenorights_1981', 'DB')
  ON DUPLICATE KEY UPDATE password=VALUES(password), role_id=3, active=1,
          auth_method=VALUES(auth_method)");
echo "user bage1981norights ready\n";
echo "DONE project=$idP empty=$idEmpty plan=$idPlan planEmpty=$idPlanEmpty "
   . "build1=$build1 build2=$build2 plat1=$plat1 plat2=$plat2\n";
