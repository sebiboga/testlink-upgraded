<?php
// Fixture for #1315: tcEdit.html Create New Version source + freeze gap.
// Creates tproject `TCEDDemo` (prefix TCDD) + suite `Demo Suite` + test case
// `tcA` with:
//   - v1 (no keywords, is_open=1) with distinctive summary "V1 SUMMARY"
//   - v2 (keyword 'smoke' assigned, is_open=1) with summary "V2 SUMMARY"
// So an agent editing v1 then hitting "Create New Version" can detect whether
// the new v3 was cloned from v1 (correct) or from v2 (the reported bug), and
// whether v1 got frozen (is_open=0).
// Run from repo root:  php tmp/fixtures_1315.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$suiteMgr = new testsuite($db);
$tcaseMgr = new testcase($db);
$userId = 1; // admin

foreach ((array)$tprojMgr->get_by_name('TCEDDemo') as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'TCEDDemo';
$item->prefix = 'TCDD';
$item->notes = 'fixture for issue 1315 (create new version clones wrong source + no freeze)';
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
$opts->testScriptEnabled = 0;
$item->options = $opts;
$idP = intval($tprojMgr->create($item));
echo "tproject=$idP\n";
$tprojMgr->setActive($idP, 1);
$_SESSION['testprojectID'] = $idP;

// suite
$sr = $suiteMgr->create($idP, 'Demo Suite', 'fixture suite #1315', null);
if (empty($sr['status_ok']) || intval($sr['id']) <= 0) {
    fwrite(STDERR, "suite create failed: " . print_r($sr, true) . "\n");
    exit(1);
}
$idS = intval($sr['id']);
echo "suite=$idS\n";

// test case tcA - v1
$steps = [
    ['step_number' => 1, 'actions' => 'Action one',
     'expected_results' => 'Result one', 'execution_type' => TESTCASE_EXECUTION_TYPE_MANUAL],
    ['step_number' => 2, 'actions' => 'Action two',
     'expected_results' => 'Result two', 'execution_type' => TESTCASE_EXECUTION_TYPE_MANUAL],
];
$tr = $tcaseMgr->create($idS, 'tcA', 'V1 SUMMARY', 'preconditions v1', $steps, $userId);
if (empty($tr['status_ok']) || intval($tr['id']) <= 0) {
    fwrite(STDERR, "tc create failed: " . print_r($tr, true) . "\n");
    exit(1);
}
$idTC = intval($tr['id']);

// v1 id
$rows = $db->get_recordset(
    "SELECT TC.id AS tcv_id, TC.version, TC.is_open FROM tcversions TC" .
    " JOIN nodes_hierarchy NH ON NH.id = TC.id" .
    " WHERE NH.parent_id = " . intval($idTC) . " ORDER BY TC.id ASC");
if (!$rows) { fwrite(STDERR, "no tcversions found\n"); exit(1); }
$v1 = intval($rows[0]['tcv_id']);
echo "tc=$idTC v1=$v1\n";

// v2 cloned from v1 (legacy class call, explicit source = v1)
$ret = $tcaseMgr->create_new_version($idTC, $userId, $v1);
$v2 = intval(is_array($ret) ? ($ret['id'] ?? 0) : $ret);
if ($v2 <= 0) { fwrite(STDERR, "v2 create failed\n"); exit(1); }
echo "v2=$v2\n";

// update the v2 summary so the two versions are distinguishable
$db->exec_query(
    "UPDATE tcversions SET summary = 'V2 SUMMARY' WHERE id = " . intval($v2));
$db->exec_query("UPDATE nodes_hierarchy SET name = 'tcA' " .
    "WHERE id IN (" . intval($idTC) . "," . intval($v1) . "," . intval($v2) . ")");

// keyword 'smoke' assigned ONLY to v2
$kwId = 0;
$rs = $db->exec_query(
    "INSERT INTO keywords (keyword, testproject_id, notes) VALUES ('smoke', {$idP}, 'fixture kw #1315')");
if ($rs) {
    $kwId = intval($db->insert_id('keywords'));
    $db->exec_query(
        "INSERT INTO testcase_keywords (testcase_id, tcversion_id, keyword_id) " .
        "VALUES ({$idTC}, {$v2}, {$kwId})");
}
echo "keyword=$kwId\n";

$state = "issue1315_idP=$idP idS=$idS idTC=$idTC v1=$v1 v2=$v2 kw=$kwId\n";
file_put_contents(__DIR__ . '/state_1315.env', $state);
echo "FIXTURE_OK $state";