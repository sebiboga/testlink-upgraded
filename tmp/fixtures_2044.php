<?php
// Fixture for Test Plan Defect Verification Gap (Refs #2044).
// DVG2044: one plan, 2 builds, 2 platforms, 4 cases, bug links with verification
// evidence: BUG-1 unverified, BUG-2 verified by a later pass, BUG-3 escaped
// (linked to a passed execution), BUG-4 platform-specific link.
// DVG2044 EMPTY: plan with no executions (no_data).
// dvg2044norights: role 3 user without testplan_metrics (403 path).
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);
$tprojMgr = new testproject($db);
$tplanMgr = new testplan($db);
$tsuiteMgr = new testsuite($db);
$tcaseMgr = new testcase($db);
$tables = tlObjectWithDB::getDBTables(array(
    'builds', 'platforms', 'testplan_platforms', 'testplan_tcversions',
    'executions', 'execution_bugs', 'users',
));

foreach ((array)$tprojMgr->get_by_name('DVG2044') as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) { $tprojMgr->delete($oid, 1); }
}
$item = new stdClass();
$item->name = 'DVG2044'; $item->prefix = 'DVG'; $item->notes = 'fixture #2044';
$item->color = ''; $item->active = 1; $item->is_public = 1;
$opts = new stdClass();
$opts->requirementsEnabled = 0; $opts->testPriorityEnabled = 0;
$opts->automationEnabled = 0; $opts->inventoryEnabled = 0;
$opts->platformsEnabled = 1; $opts->testcasecfEnabled = 0;
$opts->requirementcfEnabled = 0;
$item->options = $opts;
$idP = intval($tprojMgr->create($item));
if ($idP <= 0) { die("project create failed\n"); }
$tprojMgr->setActive($idP);
echo "tproject=$idP\n";

$idPlan = intval($tplanMgr->create('DVG Plan', '', $idP, 1, 1));
$idEmpty = intval($tplanMgr->create('DVG Empty Plan', '', $idP, 1, 1));
echo "plans=$idPlan,$idEmpty\n";

$db->exec_query("INSERT INTO {$tables['builds']}
  (testproject_id,name,notes,active,is_open,author_id,creation_ts)
  VALUES ($idP,'DVG Build 1','fixture',1,1,1,NOW()),
         ($idP,'DVG Build 2','fixture',1,1,1,NOW())");
$b1 = intval($db->get_recordset("SELECT id FROM {$tables['builds']} WHERE testproject_id=$idP ORDER BY id LIMIT 1")[0]['id']);
$b2 = intval($db->get_recordset("SELECT id FROM {$tables['builds']} WHERE testproject_id=$idP ORDER BY id DESC LIMIT 1")[0]['id']);

$db->exec_query("INSERT INTO {$tables['platforms']} (testproject_id,name,notes) VALUES
  ($idP,'DVG Platform A','fixture'),($idP,'DVG Platform B','fixture')");
$pA = intval($db->get_recordset("SELECT id FROM {$tables['platforms']} WHERE testproject_id=$idP ORDER BY id LIMIT 1")[0]['id']);
$pB = intval($db->get_recordset("SELECT id FROM {$tables['platforms']} WHERE testproject_id=$idP ORDER BY id DESC LIMIT 1")[0]['id']);
$db->exec_query("INSERT INTO {$tables['testplan_platforms']} (testplan_id,platform_id)
  VALUES ($idPlan,$pA),($idPlan,$pB)");

$steps = array();
$s = new stdClass();
$s->step_number = 1; $s->actions = 'Reproduce'; $s->expected_results = 'No defect';
$s->execution_type = TESTCASE_EXECUTION_TYPE_MANUAL;
$steps[] = $s;

$cases = array();
foreach (array('login' => 'DVG Login', 'search' => 'DVG Search',
               'export' => 'DVG Export', 'cleanup' => 'DVG Cleanup') as $key => $name) {
    $retS = $tsuiteMgr->create($idP, 'DVG Suite ' . $key, '', null, 0, 'allow_repeat');
    $suiteId = intval($retS['id'] ?? 0);
    $ret = $tcaseMgr->create($suiteId, $name, "summary $name", '', $steps,
        1, '', testcase::DEFAULT_ORDER, testcase::AUTOMATIC_ID,
        TESTCASE_EXECUTION_TYPE_MANUAL);
    if (!$ret['status_ok']) { die("tcase failed: $name\n"); }
    $cases[$key] = intval($ret['tcversion_id']);
    $db->exec_query("INSERT INTO {$tables['testplan_tcversions']}
        (testplan_id,tcversion_id,platform_id,author_id,creation_ts)
      VALUES ($idPlan,{$cases[$key]},0,1,NOW())");
    echo "tcase $key v{$cases[$key]}\n";
}

$mkExec = function ($key, $build, $plat, $status, $ts, $bugs) use ($db, $tables, $cases, $idPlan) {
    $db->exec_query("INSERT INTO {$tables['executions']}
        (testplan_id,build_id,tester_id,execution_ts,status,tcversion_id,
         tcversion_number,platform_id,execution_type,notes)
      VALUES ($idPlan,$build,1,'$ts','$status',{$cases[$key]},1,$plat,1,'fixture $key')");
    $execId = intval($db->insert_id($tables['executions']));
    foreach ($bugs as $bug) {
        $db->exec_query("INSERT INTO {$tables['execution_bugs']}
            (execution_id,bug_id,tcstep_id) VALUES ($execId,'$bug',0)");
    }
    return $execId;
};

$mkExec('login', $b1, 0, 'f', '2026-11-01 10:00:00', array('BUG-1', 'BUG-2'));
$mkExec('login', $b2, 0, 'p', '2026-11-05 10:00:00', array());
$mkExec('search', $b1, 0, 'f', '2026-11-02 11:00:00', array('BUG-3'));
$mkExec('search', $b2, $pA, 'p', '2026-11-03 11:00:00', array('BUG-3'));
$mkExec('export', $b1, $pB, 'b', '2026-11-04 12:00:00', array('BUG-4'));
$mkExec('cleanup', $b1, 0, 'p', '2026-11-06 12:00:00', array());

$hash = password_hash('dvg2044norights', PASSWORD_DEFAULT);
$db->exec_query("INSERT INTO {$tables['users']}
    (login,password,role_id,email,first,last,locale,default_testproject_id,
     active,cookie_string,auth_method)
  VALUES ('dvg2044norights','$hash',3,'n@no.no','No','Rights','en_GB',$idP,1,
          'ck_dvg2044norights','DB')
  ON DUPLICATE KEY UPDATE password=VALUES(password),role_id=3,active=1,
    default_testproject_id=$idP");
echo "FIXTURE READY plan=$idPlan empty=$idEmpty builds=$b1,$b2 platforms=$pA,$pB\n";
