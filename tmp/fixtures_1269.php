<?php
// Fixture for Task — Issue #1269 (resultsBugs: per-TC execution-history +
// design/edit icons dropped vs legacy lib/results/resultsBugs.php:89-96).
//
// Creates everything the modern "Results by Issues" report needs to return
// rows (the report only produces rows when the project has an issue tracker
// ENABLED and executions carry linked bugs):
//   - a local "mantis_bug_table" (the DB-API tracker reads it directly), so
//     bug links + resolved state resolve without any remote BTS service
//   - issuetracker row (mantis / db) + testproject_issuetracker link
//   - test project (issue_tracker_enabled=1), test plan, open build
//   - TWO suites / THREE test cases so the grouped grid has 2 group headers:
//       RB-1  exec with bug 101 (status 10 = new  -> open)
//       RB-2  exec with bug 102 (status 80 = resolved) AND 101 -> mixed row
//       RB-3  no bug at all -> must NOT be listed (proves bug-only rows)
//
// Run from repo root: php tmp/fixtures_1269.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tplanMgr = new testplan($db);
$buildMgr = new build($db);
$tsuiteMgr = new testsuite($db);
$tcaseMgr = new testcase($db);
$itMgr = new tlIssueTracker($db);
$userId = intval($_SESSION['userID'] ?? 1);
if ($userId <= 0) {
    $userId = 1;
}

// ---- re-runnable: drop a previous run --------------------------------------
foreach ((array)$tprojMgr->get_by_name('RB1269') as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

$tables = tlObjectWithDB::getDBTables(array(
    'testprojects', 'testplans', 'builds', 'nodes_hierarchy', 'tcversions',
    'tcsteps', 'executions', 'execution_bugs', 'issuetrackers',
    'testproject_issuetracker'));

// ---- fake mantis BTS schema (same DB, no remote service) -------------------
$db->exec_query("DROP TABLE IF EXISTS mantis_bug_table");
$db->exec_query("CREATE TABLE mantis_bug_table (
    id int(11) NOT NULL PRIMARY KEY,
    status smallint(6) NOT NULL DEFAULT 10,
    summary varchar(128) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8");
$db->exec_query("INSERT INTO mantis_bug_table (id, status, summary) VALUES
    (101, 10, 'login button does nothing'),
    (102, 80, 'password reset mail not sent')");

// ---- issue tracker ----------------------------------------------------------
$db->exec_query("DELETE FROM {$tables['issuetrackers']} WHERE name = 'MantisFixture1269'");
// NOTE: issuetrackers.cfg holds an XML document (issueTrackerInterface::setCfg()
// does simplexml_load_string()), NOT json_encode() - see Issue #1282 notes there.
// setCfg() PREPENDS its own XML declaration, so the stored cfg must NOT carry
// one (two declarations = libxml parse error = tracker never connects).
$cfgXml = "<mantis><dbtype>mysql</dbtype>"
    . "<dbcharset>UTF-8</dbcharset>"
    . "<dbhost>127.0.0.1</dbhost>"
    . "<dbuser>testlink</dbuser>"
    . "<dbpassword>testlink</dbpassword>"
    . "<dbname>testlink</dbname>"
    . "<uriview>http://mantis.local/view_bug.php?bug_id=</uriview>"
    . "<uriadd>http://mantis.local/bug_report.php</uriadd>"
    . "<urimodify>http://mantis.local/bug_update.php?bug_id=</urimodify></mantis>";
$db->exec_query("INSERT INTO {$tables['issuetrackers']} (name, type, cfg) VALUES
    ('MantisFixture1269', 4, '" . $db->prepare_string($cfgXml) . "')");
$itId = intval($db->insert_id());
if ($itId <= 0) {
    $rs = $db->get_recordset("SELECT id FROM {$tables['issuetrackers']} WHERE name='MantisFixture1269'");
    $itId = intval($rs[0]['id']);
}
echo "issuetracker=$itId (mantis/db)\n";

// ---- test project -----------------------------------------------------------
$item = new stdClass();
$item->name = 'RB1269';
$item->prefix = 'RB';
$item->notes = 'fixture for Task Issue #1269 (resultsBugs TC icons)';
$item->color = '';
$item->active = 1;
$item->is_public = 1;
$opts = new stdClass();
$opts->requirementsEnabled = 0;
$opts->trackersEnabled = 1;
$opts->priorityEnabled = 0;
$opts->platformsEnabled = 0;
$opts->testcasecfEnabled = 0;
$opts->requirementcfEnabled = 0;
$opts->testplancfEnabled = 0;
$opts->executioncfEnabled = 0;
$item->options = $opts;
$idP = intval($tprojMgr->create($item));
$tprojMgr->setActive($idP);
$db->exec_query("UPDATE {$tables['testprojects']} SET issue_tracker_enabled=1 WHERE id=$idP");
$db->exec_query("DELETE FROM {$tables['testproject_issuetracker']} WHERE testproject_id=$idP");
$db->exec_query("INSERT INTO {$tables['testproject_issuetracker']} (testproject_id, issuetracker_id)
    VALUES ($idP, $itId)");
echo "tproject=$idP (prefix RB)\n";

// ---- plan + build -----------------------------------------------------------
$idPlan = intval($tplanMgr->create('RB Plan', '', $idP, 1, 1));
echo "testplan=$idPlan\n";
$idB = intval($buildMgr->create($idPlan, 'RB Build 1', '', 1, 1, '', $idP));
echo "build=$idB\n";

// ---- suites / test cases ----------------------------------------------------
function mkCase($tcaseMgr, $tsuiteMgr, $db, $tables, $idP, $suiteName, $tcName, $userId)
{
    $retS = $tsuiteMgr->create($idP, $suiteName, '', null, 0, 'allow_repeat');
    $sid = intval($retS['id'] ?? 0);
    $steps = array();
    $s = new stdClass();
    $s->step_number = 1;
    $s->actions = 'exercise ' . $tcName;
    $s->expected_results = 'it works';
    $s->execution_type = TESTCASE_EXECUTION_TYPE_MANUAL;
    $steps[] = $s;
    $ret = $tcaseMgr->create($sid, $tcName, 'fixture for #1269', '', $steps, $userId,
        '', testcase::DEFAULT_ORDER, testcase::AUTOMATIC_ID, TESTCASE_EXECUTION_TYPE_MANUAL);
    if (empty($ret['status_ok']) || $ret['id'] <= 0) {
        die('tcase create failed: ' . ($ret['message'] ?? '') . "\n");
    }
    $idTC = intval($ret['id']);
    $idTCV = intval($ret['tcversion_id']);
    $rs = $db->get_recordset("SELECT TCSTEPS.id FROM {$tables['tcsteps']} TCSTEPS " .
        "JOIN {$tables['nodes_hierarchy']} NH ON NH.id = TCSTEPS.id WHERE NH.parent_id=$idTCV");
    $stepId = intval($rs[0]['id'] ?? 0);
    return array($sid, $idTC, $idTCV, $stepId);
}

list($sid1, $idTC1, $idTCV1, $st1) = mkCase($tcaseMgr, $tsuiteMgr, $db, $tables, $idP, 'RB Suite A', 'login broken', $userId);
list($sid2, $idTC2, $idTCV2, $st2) = mkCase($tcaseMgr, $tsuiteMgr, $db, $tables, $idP, 'RB Suite B', 'reset mail missing', $userId);
list($sid2b, $idTC3, $idTCV3, $st3) = mkCase($tcaseMgr, $tsuiteMgr, $db, $tables, $idP, 'RB Suite B', 'no bug here', $userId);
echo "tc=$idTC1 (v$idTCV1, step $st1) / tc=$idTC2 (v$idTCV2, step $st2) / tc=$idTC3 (v$idTCV3, step $st3)\n";

// ---- link the tc versions to the test plan (required by both report paths:
// getAllExecutionsWithBugs JOINs testplan_tcversions, and getLTCVNewGeneration
// resolves the plan's active tc version set from the same table) -----------
$toLink = array(
    'tcversion' => array($idTCV1, $idTCV2, $idTCV3),
    'items'     => array($idTC1 => array(0 => $idTCV1),
                         $idTC2 => array(0 => $idTCV2),
                         $idTC3 => array(0 => $idTCV3)),
);
$tplanMgr->link_tcversions($idPlan, $toLink, $userId);
$rs = $db->get_recordset("SELECT COUNT(*) AS c FROM testplan_tcversions WHERE testplan_id=$idPlan");
echo "testplan_tcversions rows=" . intval($rs[0]['c'] ?? 0) . "\n";

// ---- executions + linked bugs ----------------------------------------------
$now = date('Y-m-d H:i:s');
function mkExec($db, $tables, $idB, $idPlan, $idTCV, $userId, $status, $step, $bugs, $now)
{
    $db->exec_query("INSERT INTO {$tables['executions']}
        (build_id, tester_id, execution_ts, status, testplan_id, tcversion_id,
         tcversion_number, platform_id, execution_type, execution_duration, notes)
        VALUES ($idB, $userId, '$now', '$status', $idPlan, $idTCV, 1, 0, 1, NULL, '')");
    $execId = intval($db->insert_id());
    if ($execId <= 0) {
        die("exec insert failed\n");
    }
    foreach ((array)$bugs as $bugId => $stepId) {
        $db->exec_query("INSERT INTO {$tables['execution_bugs']} (execution_id, bug_id, tcstep_id)
            VALUES ($execId, '" . $db->prepare_string((string)$bugId) . "', " . intval($stepId) . ")");
    }
    return $execId;
}

$e1 = mkExec($db, $tables, $idB, $idPlan, $idTCV1, $userId, 'f', 0, array(101 => $st1), $now);
$e2 = mkExec($db, $tables, $idB, $idPlan, $idTCV2, $userId, 'f', 0, array(101 => $st2, 102 => $st2), $now);
$e3 = mkExec($db, $tables, $idB, $idPlan, $idTCV3, $userId, 'p', 0, array(), $now);
echo "executions=$e1,$e2,$e3 (bugs: 101 open, 101+102 resolved-mix, none)\n";

echo "DONE tproject=$idP testplan=$idPlan build=$idB\n";