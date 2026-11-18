<?php
// Fixture for Test Plan Keyword Coverage (gui/templates/results/keywordCoverage.html
// + api/keywordcoverage/index.php). Refs #1995.
//
// KWC1995: plan with 5 assigned versions, 3 keywords (one gap), 2 untagged,
//          5 executed (3 passed / 1 failed / 1 blocked), 2 builds, 2 platforms.
// KWC1995 EMPTY: empty plan in the same project (no_data).
// KWC1995 NOKEY: project with a plan but no keywords (no_keywords).
// kwc1995norights: role 3 user without testplan_metrics (403 path).
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
    'executions', 'keywords', 'testcase_keywords', 'users', 'nodes_hierarchy',
));

function kwcProject($mgr, $name, $prefix)
{
    foreach ((array)$mgr->get_by_name($name) as $row) {
        $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
        if ($oid > 0) { $mgr->delete($oid, 1); }
    }
    $item = new stdClass();
    $item->name = $name; $item->prefix = $prefix; $item->notes = 'fixture #1995';
    $item->color = ''; $item->active = 1; $item->is_public = 1;
    $opts = new stdClass();
    $opts->requirementsEnabled = 0; $opts->testPriorityEnabled = 0;
    $opts->automationEnabled = 0; $opts->inventoryEnabled = 0;
    $opts->platformsEnabled = 1; $opts->testcasecfEnabled = 0;
    $opts->requirementcfEnabled = 0;
    $item->options = $opts;
    $id = intval($mgr->create($item));
    if ($id <= 0) { die("project create failed: $name\n"); }
    $mgr->setActive($id);
    echo "tproject $name=$id\n";
    return $id;
}

function kwcSteps()
{
    $s = new stdClass();
    $s->step_number = 1; $s->actions = 'Do it';
    $s->expected_results = 'It works';
    $s->execution_type = TESTCASE_EXECUTION_TYPE_MANUAL;
    return array($s);
}

$idP = kwcProject($tprojMgr, 'KWC1995', 'KW');
$idNoKey = kwcProject($tprojMgr, 'KWC1995 NOKEY', 'KN');
$idPlan = intval($tplanMgr->create('KWC Plan', '', $idP, 1, 1));
$idEmpty = intval($tplanMgr->create('KWC Empty Plan', '', $idP, 1, 1));
$idNoKeyPlan = intval($tplanMgr->create('KWC No-Keyword Plan', '', $idNoKey, 1, 1));
$db->exec_query("DELETE FROM {$tables['keywords']} WHERE testproject_id IN ($idP,$idNoKey)");

$ids = array();
foreach (array('smoke' => 'KW Smoke', 'regression' => 'KW Regression',
               'perf' => 'KW Performance', 'plain1' => 'KW Untagged One',
               'plain2' => 'KW Untagged Two') as $key => $name) {
    $retS = $tsuiteMgr->create($idP, 'KW Suite ' . $key, '', null, 0, 'allow_repeat');
    $suiteId = intval($retS['id'] ?? 0);
    $ret = $tcaseMgr->create($suiteId, $name, "summary $name", '', kwcSteps(),
        1, '', testcase::DEFAULT_ORDER, testcase::AUTOMATIC_ID,
        TESTCASE_EXECUTION_TYPE_MANUAL);
    if (!$ret['status_ok']) { die("tcase failed: $name\n"); }
    $ids[$key] = array(intval($ret['id']), intval($ret['tcversion_id']));
    echo "tcase $name={$ids[$key][0]} v{$ids[$key][1]}\n";
}

$db->exec_query("INSERT INTO {$tables['keywords']} (keyword,testproject_id,notes) VALUES
    ('smoke',$idP,'fixture'),('regression',$idP,'fixture'),
    ('performance',$idP,'fixture'),('security',$idP,'fixture gap')");
$kwIds = array();
$r = $db->get_recordset("SELECT id,keyword FROM {$tables['keywords']} WHERE testproject_id=$idP");
foreach ($r as $row) { $kwIds[$row['keyword']] = intval($row['id']); }

// Keyword links on the exact assigned version (TC1/TC2/TC3/TC4).
foreach (array('smoke' => array('smoke'),
               'regression' => array('smoke', 'regression'),
               'perf' => array('performance')) as $case => $keywords) {
    foreach ($keywords as $k) {
        $db->exec_query("INSERT INTO {$tables['testcase_keywords']}
            (testcase_id,tcversion_id,keyword_id) VALUES
            ({$ids[$case][0]},{$ids[$case][1]},{$kwIds[$k]})");
    }
}

$db->exec_query("INSERT INTO {$tables['builds']}
    (testproject_id,name,notes,active,is_open,author_id,creation_ts)
  VALUES ($idP,'KWC Build 1','fixture',1,1,1,NOW()),
         ($idP,'KWC Build 2','fixture',1,1,1,NOW())");
$b1 = intval($db->get_recordset("SELECT id FROM {$tables['builds']} WHERE testproject_id=$idP ORDER BY id LIMIT 1")[0]['id']);
$b2 = intval($db->get_recordset("SELECT id FROM {$tables['builds']} WHERE testproject_id=$idP ORDER BY id DESC LIMIT 1")[0]['id']);

$db->exec_query("INSERT INTO {$tables['platforms']} (testproject_id,name,notes) VALUES
    ($idP,'KWC Platform A','fixture'),($idP,'KWC Platform B','fixture')");
$pA = intval($db->get_recordset("SELECT id FROM {$tables['platforms']} WHERE testproject_id=$idP ORDER BY id LIMIT 1")[0]['id']);
$pB = intval($db->get_recordset("SELECT id FROM {$tables['platforms']} WHERE testproject_id=$idP ORDER BY id DESC LIMIT 1")[0]['id']);
$db->exec_query("INSERT INTO {$tables['testplan_platforms']} (testplan_id,platform_id)
    VALUES ($idPlan,$pA),($idPlan,$pB)");

foreach ($ids as $case => $v) {
    $plat = ($case === 'plain2') ? $pB : 0;
    $db->exec_query("INSERT INTO {$tables['testplan_tcversions']}
        (testplan_id,tcversion_id,platform_id,author_id,creation_ts)
      VALUES ($idPlan,{$v[1]},$plat,1,NOW())");
}

$mkExec = function ($tcv, $build, $plat, $status) use ($db, $tables, $idPlan) {
    $db->exec_query("INSERT INTO {$tables['executions']}
        (testplan_id,build_id,tester_id,execution_ts,status,tcversion_id,
         tcversion_number,platform_id,execution_type,notes)
      VALUES ($idPlan,$build,1,NOW(),'$status',$tcv,1,$plat,1,'fixture')");
    return intval($db->insert_id($tables['executions']));
};
$mkExec($ids['smoke'][1], $b1, 0, 'p');
$mkExec($ids['regression'][1], $b2, 0, 'f');
$mkExec($ids['perf'][1], $b2, 0, 'b');
$mkExec($ids['plain1'][1], $b1, 0, 'p');
$mkExec($ids['plain2'][1], $b1, $pB, 'p');

// One assigned version in a project with NO keywords -> no_keywords verdict.
$retNK = $tsuiteMgr->create($idNoKey, 'KW No-Keyword Suite', '', null, 0, 'allow_repeat');
$suiteNK = intval($retNK['id'] ?? 0);
$retNKC = $tcaseMgr->create($suiteNK, 'KW Case Without Keywords', 'summary', '', kwcSteps(),
    1, '', testcase::DEFAULT_ORDER, testcase::AUTOMATIC_ID,
    TESTCASE_EXECUTION_TYPE_MANUAL);
$tcvNK = intval($retNKC['tcversion_id'] ?? 0);
$db->exec_query("INSERT INTO {$tables['testplan_tcversions']}
    (testplan_id,tcversion_id,platform_id,author_id,creation_ts)
  VALUES ($idNoKeyPlan,$tcvNK,0,1,NOW())");
$db->exec_query("INSERT INTO {$tables['executions']}
    (testplan_id,build_id,tester_id,execution_ts,status,tcversion_id,
     tcversion_number,platform_id,execution_type,notes)
  VALUES ($idNoKeyPlan,0,1,NOW(),'p',$tcvNK,1,0,1,'fixture')");
echo "nokey tcase=$tcvNK\n";

$hash = password_hash('kwcnorights', PASSWORD_DEFAULT);
$db->exec_query("INSERT INTO {$tables['users']}
    (login,password,role_id,email,first,last,locale,default_testproject_id,
     active,cookie_string,auth_method)
  VALUES ('kwc1995norights','$hash',3,'n@no.no','No','Rights','en_GB',0,1,
          'ck_kwc1995norights','DB')
  ON DUPLICATE KEY UPDATE password=VALUES(password),role_id=3,active=1,
          auth_method=VALUES(auth_method)");

echo "DONE idP=$idP idNoKey=$idNoKey plan=$idPlan empty=$idEmpty noKeyPlan=$idNoKeyPlan " .
     "b1=$b1 b2=$b2 pA=$pA pB=$pB smoke={$ids['smoke'][1]} " .
     "regression={$ids['regression'][1]} perf={$ids['perf'][1]} " .
     "plain1={$ids['plain1'][1]} plain2={$ids['plain2'][1]}\n";
