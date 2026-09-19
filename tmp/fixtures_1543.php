<?php
// Fixture for #1543 browser testing: Test Scripts screen (tcScripts.html).
// Creates tproject `TS1543` (prefix TS43) + suite SUITE-TS1543 + test case
// TC-TS1543 (version 1) + a GitHub code tracker `GH-TS1543` linked to the
// project (githubrest, repository = public sebiboga/testlink-upgraded).
// A token can be supplied at runtime: GITHUB_TOKEN=xxx php tmp/fixtures_1543.php
// (never stored in the repo/SQL).
// Run from repo root: php tmp/fixtures_1543.php [--add-token]
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tsuiteMgr = new testsuite($db);
$tcaseMgr = new testcase($db);
$ctMgr = new tlCodeTracker($db);
$userId = 1; // admin

// Reset on re-runs.
foreach ((array)$tprojMgr->get_by_name('TS1543') as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'TS1543';
$item->prefix = 'TS43';
$item->notes = 'fixture for issue 1543 (test scripts / code tracker links)';
$item->color = '';
$item->active = 1;
$item->is_public = 1;
$opts = new stdClass();
$opts->requirementsEnabled = 1;
$opts->testPriorityEnabled = 0;
$opts->automationEnabled = 0;
$opts->inventoryEnabled = 0;
$opts->platformsEnabled = 0;
$opts->testcasecfEnabled = 1;
$opts->requirementcfEnabled = 1;
$opts->testScriptEnabled = 1;
$item->options = $opts;
$r = $tprojMgr->create($item);
$idP = intval($r);
echo "tproject=$idP (prefix TS43)\n";
$tprojMgr->setActive($idP);

// code tracker (github, type 200), cfg XML following the codetracker config format
$token = getenv('GITHUB_TOKEN');
$repo = 'https://github.com/sebiboga/testlink-upgraded';
$cfg = '<codetracker><repository>' . $repo . '</repository><branch>sebiboga</branch>'
     . ($token !== false && $token !== '' ? '<token>' . $token . '</token>' : '')
     . '<apibase>https://api.github.com/</apibase>'
     . '</codetracker>';

$ctItem = new stdClass();
$ctItem->name = 'GH-TS1543';
$ctItem->type = 200; // github
$ctItem->cfg = $cfg;
$opTracker = $ctMgr->create($ctItem);
$trackerId = intval($opTracker['id'] ?? 0);
if ($trackerId <= 0) { echo "tracker create failed: " . print_r($opTracker, true) . "\n"; }
echo "codetracker=$trackerId\n";

// link tracker to the project + enable code_tracker_enabled flag
$tables = tlObjectWithDB::getDBTables(array('testproject_codetracker'));
$db->exec_query("INSERT INTO {$tables['testproject_codetracker']} " .
    "(testproject_id, codetracker_id) VALUES ($idP, $trackerId)");
$db->exec_query("UPDATE testprojects SET code_tracker_enabled = 1 WHERE id = $idP");

// suite + test case (+ its first version)
$op = $tsuiteMgr->create($idP, 'SUITE-TS1543', 'suite for issue 1543');
$suiteId = 0;
if (is_array($op)) { $suiteId = intval($op['id'] ?? $op['status_ok'] === false ? 0 : floatval(current($op))); }
if ($suiteId <= 0 && is_array($op) && isset($op['id'])) { $suiteId = intval($op['id']); }
if ($suiteId <= 0 && is_numeric($op)) { $suiteId = intval($op); }
if (is_array($op) && is_numeric(key($op))) { $suiteId = intval(current($op)); }
if ($suiteId <= 0) { echo "suite id lookup: " . print_r($op, true) . "\n"; }
echo "suite=$suiteId\n";

$op2 = $tcaseMgr->create($suiteId, 'TC-TS1543', 'test case used by suite #1543',
    'preconditions for 1543', array(array('step_number'=>1,'actions'=>'do it','expected_results'=>'done')),
    $userId, '', testcase::DEFAULT_ORDER, testcase::AUTOMATIC_ID,
    TESTCASE_EXECUTION_TYPE_MANUAL, 2, array('status'=>1, 'active'=>null, 'is_open'=>1));
$tcaseId = 0;
if (is_array($op2) && isset($op2['id'])) { $tcaseId = intval($op2['id']); }
if (is_numeric($op2)) { $tcaseId = intval($op2); }
echo "tcase=$tcaseId\n";

// resolve the first version id
$tvTables = tlObjectWithDB::getDBTables(array('nodes_hierarchy', 'tcversions'));
$nrow = $db->fetchFirstRow(
    " SELECT TCV.id AS tcversion_id FROM {$tvTables['tcversions']} TCV " .
    " JOIN {$tvTables['nodes_hierarchy']} NH ON NH.id = TCV.id " .
    " WHERE NH.parent_id = $tcaseId ORDER BY TCV.version DESC LIMIT 1");
echo "tcversion=" . intval($nrow['tcversion_id'] ?? 0) . "\n";

echo "fixture ready\n";