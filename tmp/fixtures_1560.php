<?php
// Fixture for #1560 browser/curl testing: modern Bug Add/Link popup
// (gui/templates/execute/bugAdd.html + api/bugadd/index.php).
// Creates tproject `BugAdd Demo` (prefix BAD1560, issue tracker ENABLED via the
// session-backed mantisrestInterface test double, type 24) + testplan + open
// build + testcase with two steps, seeds one execution WITH notes (so the bug
// note default prefill is observable) plus one second execution used by the
// no-bug/empty flows. Also re-creates the role-3 `norights` user
// (mkuser_norights.php) for the 403 path.
// Run from repo root: php tmp/fixtures_1560.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tplanMgr = new testplan($db);
$tcaseMgr = new testcase($db);
$tsuiteMgr = new testsuite($db);
$buildMgr = new build($db);
$itMgr = new tlIssueTracker($db);
$userId = 1; // admin

// Reset on re-runs (repo rule: fixtures must be re-runnable).
foreach ((array)$tprojMgr->get_by_name('BugAdd Demo') as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'BugAdd Demo';
$item->prefix = 'BAD1560';
$item->notes = 'fixture for issue 1560 (Bug Add/Link popup modern screen, tracker-enabled)';
$item->color = '';
$item->active = 1;
$item->is_public = 1;
$opts = new stdClass();
$opts->requirementsEnabled = 0;
$opts->testPriorityEnabled = 1;
$opts->automationEnabled = 0;
$opts->inventoryEnabled = 0;
$opts->platformsEnabled = 0;
$opts->testcasecfEnabled = 0;
$opts->requirementcfEnabled = 0;
$item->options = $opts;
$r = $tprojMgr->create($item);
$idP = intval($r);
echo "tproject=$idP (prefix BAD1560)\n";
$tprojMgr->setActive($idP);

// ---- issue tracker row (type 24 = mantis / rest -> mantisrestInterface) ----
$itCfgXml = '<configuration><userinteraction>1</userinteraction></configuration>';
$existing = $itMgr->getByName('TLU Mantis Double');
if (is_array($existing) && intval($existing['id'] ?? 0) > 0) {
    $itMgr->delete(intval($existing['id']));
}
$it = new stdClass();
$it->name = 'TLU Mantis Double';
$it->type = 24;
$it->cfg = $itCfgXml;
$retIt = $itMgr->create($it);
if (empty($retIt['status_ok'])) {
    die('tracker create failed: ' . ($retIt['msg'] ?? '') . "\n");
}
$idIt = intval($retIt['id']);
echo "issuetracker=$idIt (type 24, mantisrestInterface)\n";
$itMgr->link($idIt, $idP);

// enable the project's issue_tracker (legacy flag on testprojects)
$tables = tlObjectWithDB::getDBTables(array('testplans', 'builds', 'nodes_hierarchy',
    'tcversions', 'tcsteps', 'executions', 'execution_bugs', 'testprojects'));
$db->exec_query("UPDATE {$tables['testprojects']} SET issue_tracker_enabled=1 WHERE id=$idP");

// ---- testplan (active) + open build ----
$idPlan = intval($tplanMgr->create('BugAdd Plan', '', $idP, 1, 1));
echo "testplan=$idPlan\n";

$idB = intval($buildMgr->create($idPlan, 'BugAdd Build 1', '', 1, 1, '', $idP));
echo "build=$idB\n";

// ---- testcase with two steps (direct mgr API) ----
$retS = $tsuiteMgr->create($idP, 'BugAdd Suite', '', null, 0, 'allow_repeat');
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
$ret = $tcaseMgr->create($idS, 'BugAdd Login Check', 'verify login flow for bug link/create/note flows',
    '', $steps, $userId, '', testcase::DEFAULT_ORDER, testcase::AUTOMATIC_ID,
    TESTCASE_EXECUTION_TYPE_MANUAL);
if (!$ret['status_ok'] || $ret['id'] <= 0) {
    die('tcase create failed: ' . ($ret['message'] ?? '') . "\n");
}
$idTC = intval($ret['id']);
$idTCVersion = intval($ret['tcversion_id']);
echo "tcase=$idTC tcversion=$idTCVersion\n";

$rs = $db->get_recordset("SELECT TCSTEPS.id, TCSTEPS.step_number " .
    "FROM {$tables['tcsteps']} TCSTEPS " .
    "JOIN {$tables['nodes_hierarchy']} NH_STEPS ON NH_STEPS.id = TCSTEPS.id " .
    "WHERE NH_STEPS.parent_id=$idTCVersion ORDER BY step_number ASC");
$step1 = intval($rs[0]['id'] ?? 0);
$step2 = intval($rs[1]['id'] ?? 0);
echo "tcsteps: $step1, $step2\n";

// ---- seed executions ----
$now = date('Y-m-d H:i:s');

// the executions table requires a live testplan_tcversions link for the legacy
// generateIssueText() audit-signature join (E -> TPTCV on plan/version/platform)
$tables['testplan_tcversions'] = DB_TABLE_PREFIX . 'testplan_tcversions';
$db->exec_query("DELETE FROM {$tables['testplan_tcversions']} " .
    "WHERE testplan_id=$idPlan AND tcversion_id=$idTCVersion AND platform_id=0");
$db->exec_query("INSERT INTO {$tables['testplan_tcversions']} " .
    "(testplan_id, platform_id, tcversion_id, author_id, node_order, urgency) " .
    "VALUES ($idPlan, 0, $idTCVersion, $userId, 1, 2)");
$execs = array();
$rows = array(
    array('status' => 'p', 'tcstep' => 0, 'notes' => 'First login attempt logged for bug flow.'),
    array('status' => 'f', 'tcstep' => $step1, 'notes' => ''),
);
foreach ($rows as $rr) {
    $sql = "INSERT INTO {$tables['executions']} " .
           "(build_id, tester_id, execution_ts, status, testplan_id, tcversion_id, " .
           " tcversion_number, platform_id, execution_type, execution_duration, notes) " .
           "VALUES (" . $idB . ", " . $userId . ", '" . $now . "', " .
           "'" . $rr['status'] . "', $idPlan, $idTCVersion, 1, 0, 1, NULL, " .
           ($rr['notes'] === '' ? 'NULL' : "'" . $rr['notes'] . "'") . ")";
    $db->exec_query($sql);
    $eid = intval($db->insert_id());
    $execs[] = $eid;
    echo "execution=$eid status={$rr['status']}\n";
}

// ---- role-3 no-rights user (403 path) ----
require_once('tmp/mkuser_norights.php');

echo "DONE\n";
echo "execution_with_notes=" . $execs[0] . "\n";
echo "execution_plain=" . $execs[1] . "\n";
echo "issuetracker_id=$idIt\n";