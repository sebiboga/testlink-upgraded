<?php
// Fixture for #1845 browser/API testing: Priority Bar Chart
// (gui/templates/results/priorityBarChart.html + api/prioritybarchart/index.php).
//
// Creates TWO test projects so the BFF's cross-project guards can be exercised:
//
//   tproject PBC1 (prefix PBC)  -> the project under test
//     keywords: "login", "checkout", "unused-keyword" (linked to nothing)
//     suite "PBC Root"
//       tcase  PBC Login Pass    (kw: login)          -> executed p
//       tcase  PBC Login Fail    (kw: login)          -> executed f
//       tcase  PBC Login Blocked (kw: login)          -> executed b
//       tcase  PBC Login NotRun  (kw: login)          -> NO execution
//       tcase  PBC Checkout OK   (kw: checkout)       -> executed p
//       tcase  PBC Checkout New  (kw: checkout)       -> NO execution
//     plan "PBC Plan" with all 6 versions assigned, one open build,
//     the default platform (0)
//   tproject PBC2 (prefix PBD)  -> foreign project + its own plan/keyword,
//                                   used for the 404 project_mismatch proof
//   user pbcnorights (role 3)   -> the 403 path
//
// Run from repo root: php tmp/fixtures_1845.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tplanMgr = new testplan($db);
$tsuiteMgr = new testsuite($db);
$tcaseMgr = new testcase($db);
$tkmgr = new testproject($db);
$userId = 1; // admin

function dropProject($tprojMgr, $name)
{
    foreach ((array)$tprojMgr->get_by_name($name) as $row) {
        $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
        if ($oid > 0) {
            echo "deleting old project $name ($oid)\n";
            $tprojMgr->delete($oid, 1);
        }
    }
}
dropProject($tprojMgr, 'PBC1');
dropProject($tprojMgr, 'PBC2');

function makeProject($tprojMgr, $name, $prefix, $notes)
{
    $item = new stdClass();
    $item->name = $name;
    $item->prefix = $prefix;
    $item->notes = $notes;
    $item->color = '';
    $item->active = 1;
    $item->is_public = 1;
    $opts = new stdClass();
    $opts->requirementsEnabled = 0;
    $opts->testPriorityEnabled = 0;
    $opts->automationEnabled = 0;
    $opts->inventoryEnabled = 0;
    $opts->platformsEnabled = 0;
    $opts->testcasecfEnabled = 0;
    $opts->requirementcfEnabled = 0;
    $item->options = $opts;
    $r = $tprojMgr->create($item);
    $id = intval($r);
    if ($id <= 0) {
        die("tproject create failed for $name\n");
    }
    $tprojMgr->setActive($id);
    return $id;
}

$idP = makeProject($tprojMgr, 'PBC1', 'PBC', 'fixture for #1845 (priority bar chart)');
$idPAlt = makeProject($tprojMgr, 'PBC2', 'PBD', 'fixture for #1845 (foreign project)');
echo "tproject PBC1=$idP  PBC2=$idPAlt\n";

$idPlan = intval($tplanMgr->create('PBC Plan', '', $idP, 1, 1));
$idPlanAlt = intval($tplanMgr->create('PBD Plan', '', $idPAlt, 1, 1));
echo "testplan PBC=$idPlan  PBD=$idPlanAlt\n";

$retS = $tsuiteMgr->create($idP, 'PBC Root', '', null, 0, 'allow_repeat');
$idRoot = intval($retS['id'] ?? 0);
echo "suite root=$idRoot\n";

function mkSteps($n)
{
    $steps = array();
    for ($i = 1; $i <= $n; $i++) {
        $t = new stdClass();
        $t->step_number = $i;
        $t->actions = 'Do action number ' . $i;
        $t->expected_results = 'Expected result of step ' . $i;
        $t->execution_type = TESTCASE_EXECUTION_TYPE_MANUAL;
        $steps[] = $t;
    }
    return $steps;
}

$makeTcase = function ($name) use ($tcaseMgr, $idRoot, $userId) {
    $ret = $tcaseMgr->create($idRoot, $name, 'summary of ' . $name, '', mkSteps(2),
        $userId, '', testcase::DEFAULT_ORDER, testcase::AUTOMATIC_ID,
        TESTCASE_EXECUTION_TYPE_MANUAL);
    if (!$ret['status_ok'] || $ret['id'] <= 0) {
        die("tcase create failed for $name: " . ($ret['message'] ?? '') . "\n");
    }
    return array(intval($ret['id']), intval($ret['tcversion_id']));
};

$cases = array(
    array('PBC Login Pass', 'login'),
    array('PBC Login Fail', 'login'),
    array('PBC Login Blocked', 'login'),
    array('PBC Login NotRun', 'login'),
    array('PBC Checkout OK', 'checkout'),
    array('PBC Checkout New', 'checkout'),
);

$versions = array();
foreach ($cases as $c) {
    list($tc, $tcv) = $makeTcase($c[0]);
    $versions[$c[0]] = array('tc' => $tc, 'tcv' => $tcv, 'kw' => $c[1]);
    echo "tcase {$c[0]} tc=$tc version=$tcv kw={$c[1]}\n";
}

// Keywords -----------------------------------------------------------------
$TB = tlObjectWithDB::getDBTables();
$kws = array();
foreach (array('login', 'checkout', 'unused-keyword') as $kwName) {
    $opKw = $tkmgr->addKeyword($idP, $kwName, 'fixture keyword');
    $kid = intval($opKw['id'] ?? 0);
    if ($kid <= 0) {
        die("keyword create failed for $kwName\n");
    }
    $kws[$kwName] = $kid;
    echo "keyword $kwName=$kid\n";
}
// One keyword in the FOREIGN project, so a cross-project leak would be visible.
$opFk = $tkmgr->addKeyword($idPAlt, 'foreign-keyword', '');
$fkId = intval($opFk['id'] ?? 0);
echo "foreign keyword foreign-keyword=$fkId (project $idPAlt)\n";

foreach ($versions as $cname => $v) {
    $db->exec_query(
        "INSERT INTO {$TB['testcase_keywords']} (testcase_id, tcversion_id, keyword_id)
         VALUES (" . intval($v['tc']) . ", " . intval($v['tcv']) . ", " . intval($kws[$v['kw']]) . ")");
}
echo "linked keywords\n";

// Test plan assignment ------------------------------------------------------
// 2.0.1 has no assign_tcversion_to_plan(): the plan<->version link is a plain
// testplan_tcversions row (testplan.class.php:698 does the same INSERT).
$assigned = array();
foreach ($versions as $cname => $v) {
    $db->exec_query(
        "INSERT INTO {$TB['testplan_tcversions']}
            (testplan_id, author_id, creation_ts, tcversion_id, platform_id, node_order)
         VALUES ($idPlan, $userId, NOW(), " . intval($v['tcv']) . ", 0, 1)");
    $assigned[] = intval($v['tcv']);
}
echo "assigned " . count($assigned) . " versions to plan $idPlan\n";

// Open build + executions ---------------------------------------------------
$db->exec_query("INSERT INTO {$TB['builds']}
    (testproject_id, name, notes, active, is_open, author_id, creation_ts)
  VALUES ($idP, 'PBC Build 1', 'fixture build', 1, 1, $userId, NOW())");
$buildId = intval($db->insert_id($TB['builds']));
echo "build=$buildId\n";

function mkExec($db, $TB, $planId, $buildId, $tcv, $status, $ts)
{
    $db->exec_query(
        "INSERT INTO {$TB['executions']}
            (build_id, tester_id, execution_ts, status, testplan_id, tcversion_id,
             tcversion_number, platform_id, execution_type, execution_duration)
         VALUES (" . intval($buildId) . ", 1, '" . $ts . "', '" . $status . "', "
        . intval($planId) . ", " . intval($tcv) . ", 1, 0, 1, 0)");
}

$ts1 = '2026-01-10 10:00:00';
$ts2 = '2026-01-11 11:00:00';

mkExec($db, $TB, $idPlan, $buildId, $versions['PBC Login Pass']['tcv'], 'p', $ts1);
mkExec($db, $TB, $idPlan, $buildId, $versions['PBC Login Fail']['tcv'], 'f', $ts1);
mkExec($db, $TB, $idPlan, $buildId, $versions['PBC Login Blocked']['tcv'], 'b', $ts1);

// A SECOND execution of the passing version on a LATER timestamp, with status f:
// the bucket must follow the LATEST recorded result (f), which is what the
// "latest result wins" rule of the rebuilt aggregate is pinned by.
mkExec($db, $TB, $idPlan, $buildId, $versions['PBC Login Pass']['tcv'], 'f', $ts2);
mkExec($db, $TB, $idPlan, $buildId, $versions['PBC Checkout OK']['tcv'], 'p', $ts1);
echo "executions: login=1 pass + 1 fail(latest) + 1 blocked, checkout=1 pass\n";

// Foreign plan gets one version of its own so the mismatch 404 is meaningful.
$retS2 = $tsuiteMgr->create($idPAlt, 'PBD Root', '', null, 0, 'allow_repeat');
$idRoot2 = intval($retS2['id'] ?? 0);
$retT = $tcaseMgr->create($idRoot2, 'PBD Foreign Case', 'foreign', '', mkSteps(1),
    $userId, '', testcase::DEFAULT_ORDER, testcase::AUTOMATIC_ID,
    TESTCASE_EXECUTION_TYPE_MANUAL);
$foreignV = intval($retT['tcversion_id'] ?? 0);
$db->exec_query("INSERT INTO {$TB['testplan_tcversions']}
    (testplan_id, author_id, creation_ts, tcversion_id, platform_id, node_order)
  VALUES ($idPlanAlt, $userId, NOW(), $foreignV, 0, 1)");
$db->exec_query("INSERT INTO {$TB['testcase_keywords']} (testcase_id, tcversion_id, keyword_id)
                  VALUES (" . intval($retT['id']) . ", $foreignV, $fkId)");
echo "foreign plan $idPlanAlt version=$foreignV keyword=$fkId\n";

// An EMPTY plan: no test case version assigned at all -> the screen must show
// its "nothing to plot" state, not an empty chart box.
$idPlanEmpty = intval($tplanMgr->create('PBC Empty Plan', '', $idP, 1, 1));
echo "empty plan=$idPlanEmpty\n";

// A user with NO rights at all (role 3) -> the 403 path.
// tlUser::create() is an empty stub on 2.0.1, so the row is inserted directly
// (same idiom as tmp/mkuser_norights.php); auth_method DB + password_hash.
$TBU = tlObjectWithDB::getDBTables(array('users'));
$hash = password_hash('pbcnorights', PASSWORD_DEFAULT);
$db->exec_query("INSERT INTO {$TBU['users']}
    (login, password, role_id, email, first, last, locale,
     default_testproject_id, active, cookie_string, auth_method)
  VALUES ('pbcnorights', '$hash', 3, 'n@no.no', 'No', 'Rights', 'en_GB', 0, 1,
          'ck_pbcnorights_1845', 'DB')
  ON DUPLICATE KEY UPDATE password=VALUES(password), role_id=3, active=1,
          auth_method=VALUES(auth_method)");
$rsU = $db->get_recordset("SELECT id,login,role_id FROM users WHERE login='pbcnorights'");
echo 'user pbcnorights: ' . json_encode($rsU) . "\n";

echo "DONE. ids: project=$idP plan=$idPlan build=$buildId foreignProject=$idPAlt "
   . "foreignPlan=$idPlanAlt emptyPlan=$idPlanEmpty\n";