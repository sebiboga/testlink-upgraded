<?php
// Fixture for #1574 browser/curl testing: Execution Script edit popup
// (gui/templates/testcases/scriptEdit.html + api/scriptedit/index.php).
// Creates tproject `ScriptEdit Demo` (prefix SED1574, code tracker ENABLED),
// links a GITHUB code tracker against the public repo sebiboga/testlink-upgraded
// (branch sebiboga — real contents API, no token needed for public data) and
// a testplan + suite + 2 test cases; one tcversion gets a pre-inserted
// testcase_script_links row (for the delete path), the other stays empty
// (for the create path). Reuses tmp/mkuser_norights.php for the 403 path.
// Run from repo root: php tmp/fixtures_1574.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tplanMgr = new testplan($db);
$tcaseMgr = new testcase($db);
$tsuiteMgr = new testsuite($db);
$ctmgr = new tlCodeTracker($db);
$userId = 1; // admin

// wipe any previous run of this fixture
foreach ((array)$tprojMgr->get_by_name('ScriptEdit Demo') as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'ScriptEdit Demo';
$item->prefix = 'SED1574';
$item->notes = 'fixture for issue 1574 (execution script edit popup)';
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
echo "tproject=$idP (prefix SED1574)\n";

// grab the created project row and set code_tracker_enabled = 1
$info = $tprojMgr->get_by_id($idP);
$tbl = tlObjectWithDB::getDBTables(array('testprojects'));
$db->exec_query("UPDATE `{$tbl['testprojects']}` SET code_tracker_enabled = 1 WHERE id = " . intval($idP));
echo "code_tracker_enabled=1\n";

// ---- code tracker (github type=200) against the public repo ----
$ctName = 'ScriptEdit-GH-' . date('His');
$ctCfg = '<codetracker><type>github</type><name>ScriptEditGH</name>' .
         '<repository>sebiboga/testlink-upgraded</repository><branch>sebiboga</branch>' .
         '<uribase>https://github.com</uribase><apibase>https://api.github.com</apibase>' .
         '</codetracker>';
$cobj = new stdClass();
$cobj->name = $ctName;
$cobj->type = 200;
$cobj->cfg = $ctCfg;
$ctr = $ctmgr->create($cobj);
$ctId = intval($ctr['id'] ?? 0);
if ($ctId <= 0) {
    die('codetracker create failed' . "\n");
}
$tbk = tlObjectWithDB::getDBTables(array('testproject_codetracker'));
$db->exec_query("DELETE FROM `{$tbk['testproject_codetracker']}` WHERE testproject_id=" . intval($idP));
$db->exec_query("INSERT INTO `{$tbk['testproject_codetracker']}` (testproject_id, codetracker_id) VALUES (" .
                intval($idP) . "," . intval($ctId) . ")");
echo "codetracker=$ctId ($ctName), linked to project\n";

// ---- testplan ----
$idPlan = intval($tplanMgr->create('ScriptEdit Plan', '', $idP, 1, 1));
echo "testplan=$idPlan\n";

// ---- suite + 2 test cases ----
$retS = $tsuiteMgr->create($idP, 'ScriptEdit Suite', '', null, 0, 'allow_repeat');
$idS = intval($retS['id'] ?? 0);
echo "suite=$idS\n";

$makeTcase = function ($name, $summary) use ($tcaseMgr, $idS, $userId) {
    $steps = array();
    $t = new stdClass();
    $t->step_number = 1;
    $t->actions = 'Open file ' . $name;
    $t->expected_results = 'Expected for ' . $name;
    $t->execution_type = TESTCASE_EXECUTION_TYPE_MANUAL;
    $steps[] = $t;
    $ret = $tcaseMgr->create($idS, $name, $summary, '', $steps, $userId, '',
        testcase::DEFAULT_ORDER, testcase::AUTOMATIC_ID, TESTCASE_EXECUTION_TYPE_MANUAL);
    if (!$ret['status_ok'] || $ret['id'] <= 0) {
        die('tcase create failed: ' . ($ret['message'] ?? '') . "\n");
    }
    return array(intval($ret['id']), intval($ret['tcversion_id']));
};

list($idTcLink, $idTcvLink) = $makeTcase('SED1574 Scripted TC', 'has a pre-existing script link');
echo "tcase_linked=$idTcLink tcversion=$idTcvLink\n";
list($idTcEmpty, $idTcvEmpty) = $makeTcase('SED1574 Empty TC', 'no script link yet');
echo "tcase_empty=$idTcEmpty tcversion=$idTcvEmpty\n";

// ---- pre-insert a script link row on the first tcversion ----
$tblSl = tlObjectWithDB::getDBTables(array('testcase_script_links'));
$db->exec_query("DELETE FROM `{$tblSl['testcase_script_links']}` WHERE tcversion_id=" . intval($idTcvLink));
$db->exec_query("INSERT INTO `{$tblSl['testcase_script_links']}` (tcversion_id, project_key, repository_name, code_path, branch_name) VALUES (" .
                intval($idTcvLink) . ", 'sebiboga', 'testlink-upgraded', 'README.md', 'sebiboga')");
echo "script_link=sebiboga&&testlink-upgraded&&README.md\n";

// ---- link to plan (not strictly needed by the popup, kept for context) ----
$tables = tlObjectWithDB::getDBTables(array('testplan_tcversions'));
foreach (array($idTcvLink, $idTcvEmpty) as $i => $tcv) {
    $db->exec_query("DELETE FROM `{$tables['testplan_tcversions']}` WHERE testplan_id=" . intval($idPlan) . " AND tcversion_id=" . intval($tcv));
    $db->exec_query("INSERT INTO `{$tables['testplan_tcversions']}` (testplan_id, tcversion_id, node_order) VALUES (" .
                    intval($idPlan) . "," . intval($tcv) . "," . ($i + 1) . ")");
}

// ---- no-rights user for the 403 path (role 3) ----
if (file_exists('tmp/mkuser_norights.php')) {
    include 'tmp/mkuser_norights.php';
} else {
    echo "WARN: tmp/mkuser_norights.php not found — 403 path will need manual user\n";
}

echo "FIXTURE_DONE idP=$idP idPlan=$idPlan idTcvLink=$idTcvLink idTcvEmpty=$idTcvEmpty\n";