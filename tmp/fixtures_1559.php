<?php
// Fixture for #1559 browser/curl testing: modern Bug Delete popup
// (gui/templates/execute/bugDelete.html + api/bugdelete/index.php).
// Creates tproject `B1559` (prefix BUG) + testplan + open build + testcase
// with one step, seeds two executions (one with 2 linked bugs: execution-level
// + step-level, one with no bugs). Issue tracker DISABLED so the raw
// execution_bugs fallback list path is exercised.
// Run from repo root: php tmp/fixtures_1559.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tplanMgr = new testplan($db);
$tcaseMgr = new testcase($db);
$tsuiteMgr = new testsuite($db);
$buildMgr = new build($db);
$userId = 1; // admin

// Reset on re-runs (repo rule: fixtures must be re-runnable).
foreach ((array)$tprojMgr->get_by_name('B1559') as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'B1559';
$item->prefix = 'BUG';
$item->notes = 'fixture for issue 1559 (Bug Delete popup modern screen)';
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
$idP = intval($r);
echo "tproject=$idP (prefix BUG)\n";
$tprojMgr->setActive($idP);

$tables = tlObjectWithDB::getDBTables(array('testplans', 'builds', 'nodes_hierarchy',
    'tcversions', 'tcsteps', 'executions', 'execution_bugs'));

// ---- testplan (active) + open build ----
$idPlan = intval($tplanMgr->create('BUG Plan', '', $idP, 1, 1));
echo "testplan=$idPlan\n";

$idB = intval($buildMgr->create($idPlan, 'BUG Build 1', '', 1, 1, '', $idP));
echo "build=$idB\n";

// ---- testcase with one step (direct mgr API) ----
$retS = $tsuiteMgr->create($idP, 'BUG Suite', '', null, 0, 'allow_repeat');
$idS = intval($retS['id'] ?? 0);
echo "suite=$idS\n";

$steps = array();
for ($i = 1; $i <= 2; $i++) {
    $s = new stdClass();
    $s->step_number = $i;
    $s->actions = ($i === 1) ? 'Open login page' : 'Submit wrong password';
    $s->expected_results = ($i === 1) ? 'Page loads' : 'Error shown';
    $s->execution_type = TESTCASE_EXECUTION_TYPE_MANUAL;
    $steps[] = $s;
}
$ret = $tcaseMgr->create($idS, 'BUG Login Check', 'verify login with a linked bug', '', $steps, $userId,
    '', testcase::DEFAULT_ORDER, testcase::AUTOMATIC_ID, TESTCASE_EXECUTION_TYPE_MANUAL);
if (!$ret['status_ok'] || $ret['id'] <= 0) {
    die('tcase create failed: ' . ($ret['message'] ?? '') . "\n");
}
$idTC = intval($ret['id']);
$idTCVersion = intval($ret['tcversion_id']);
echo "tcase=$idTC tcversion=$idTCVersion\n";

// fetch the first tcstep ids (steps are linked via nodes_hierarchy.parent_id)
$rs = $db->get_recordset("SELECT TCSTEPS.id, TCSTEPS.step_number " .
    "FROM {$tables['tcsteps']} TCSTEPS " .
    "JOIN {$tables['nodes_hierarchy']} NH_STEPS ON NH_STEPS.id = TCSTEPS.id " .
    "WHERE NH_STEPS.parent_id=$idTCVersion ORDER BY step_number ASC");
$step1 = intval($rs[0]['id'] ?? 0);
$step2 = intval($rs[1]['id'] ?? 0);
echo "tcsteps: $step1, $step2\n";

// ---- seed executions + execution_bugs (raw SQL; fixture-only) ----
$now = date('Y-m-d H:i:s');
$execs = array();
$rows = array(
    array('build_id' => $idB, 'tester_id' => $userId, 'status' => 'p', 'tcstep' => 0,
          'bug' => 'BUG-101'),
    array('build_id' => $idB, 'tester_id' => $userId, 'status' => 'f', 'tcstep' => $step1,
          'bug' => 'BUG-202'),
    array('build_id' => $idB, 'tester_id' => $userId, 'status' => 'b', 'tcstep' => 0,
          'bug' => null), // no bugs => empty state
);
foreach ($rows as $k => $rr) {
    $sql = "INSERT INTO {$tables['executions']} " .
           "(build_id, tester_id, execution_ts, status, testplan_id, tcversion_id, " .
           " tcversion_number, platform_id, execution_type, execution_duration, notes) " .
           "VALUES (" . $rr['build_id'] . ", " . $rr['tester_id'] . ", '" . $now . "', " .
           "'" . $rr['status'] . "', $idPlan, $idTCVersion, 1, 0, 1, NULL, NULL)";
    $db->exec_query($sql);
    $eid = intval($db->insert_id());
    $execs[] = $eid;
    echo "execution=$eid status={$rr['status']}\n";
    if ($rr['bug']) {
        $sql = "INSERT INTO {$tables['execution_bugs']} " .
               "(execution_id, tcstep_id, bug_id) VALUES ($eid, " .
               $rr['tcstep'] . ", '" . $rr['bug'] . "')";
        $db->exec_query($sql);
        echo "  linked bug {$rr['bug']} (tcstep {$rr['tcstep']})\n";
    }
}

echo "DONE\n";
echo "execution_with_bugs=" . $execs[0] . "\n";
echo "execution_step_bug=" . $execs[1] . "\n";
echo "execution_empty=" . $execs[2] . "\n";