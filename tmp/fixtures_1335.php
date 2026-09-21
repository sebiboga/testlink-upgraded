<?php
// Fixture for #1335 verification: tcPrint.html parity.
// Creates tproject `TP1335` (prefix TP35) + suite `Suite Alpha` + test case
// `TC Login Test` (2 steps, importance High, exec duration 5.00) + keyword
// `smoke` + platform `Linux` + custom field `Severity=High` (design).
// Run from repo root:  php tmp/fixtures_1335.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$suiteMgr = new testsuite($db);
$tcaseMgr = new testcase($db);
$cfieldMgr = new cfield_mgr($db);
$userId = 1; // admin

// cleanup CF from previous runs (custom_fields.name is unique)
foreach ((array)$db->get_recordset("SELECT id FROM custom_fields WHERE name = 'Severity'") as $row) {
    $fid = intval($row['id']);
    echo "deleting old cfield $fid\n";
    $db->exec_query("DELETE FROM custom_fields WHERE id = {$fid}");
    $db->exec_query("DELETE FROM cfield_node_types WHERE field_id = {$fid}");
    $db->exec_query("DELETE FROM cfield_testprojects WHERE field_id = {$fid}");
    $db->exec_query("DELETE FROM cfield_design_values WHERE field_id = {$fid}");
}

foreach ((array)$tprojMgr->get_by_name('TP1335') as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'TP1335';
$item->prefix = 'TP35';
$item->notes = 'fixture for issue 1335 (tcPrint parity verification)';
$item->color = '';
$item->active = 1;
$item->is_public = 1;
$opts = new stdClass();
$opts->requirementsEnabled = 1;
$opts->testPriorityEnabled = 0;
$opts->automationEnabled = 0;
$opts->inventoryEnabled = 0;
$opts->platformsEnabled = 1;   // platforms shown in the print doc
$opts->testcasecfEnabled = 1;  // cfields shown in the print doc
$opts->requirementcfEnabled = 0;
$opts->testScriptEnabled = 0;
$item->options = $opts;
$idP = intval($tprojMgr->create($item));
echo "tproject=$idP\n";
$tprojMgr->setActive($idP, 1);
$_SESSION['testprojectID'] = $idP;

// suite
$sr = $suiteMgr->create($idP, 'Suite Alpha', 'fixture suite #1335', null);
if (empty($sr['status_ok']) || intval($sr['id']) <= 0) {
    fwrite(STDERR, "suite create failed: " . print_r($sr, true) . "\n");
    exit(1);
}
$idS = intval($sr['id']);
echo "suite=$idS\n";

// test case
$steps = [
    ['step_number' => 1, 'actions' => 'Enter username and password',
     'expected_results' => 'Login succeeds', 'execution_type' => TESTCASE_EXECUTION_TYPE_MANUAL],
    ['step_number' => 2, 'actions' => 'Click logout',
     'expected_results' => 'Session closed', 'execution_type' => TESTCASE_EXECUTION_TYPE_MANUAL],
];
$tr = $tcaseMgr->create($idS, 'TC Login Test', 'Verify login flow (fixture #1335).',
    'User account exists.', $steps, $userId, '', null, testcase::AUTOMATIC_ID,
    TESTCASE_EXECUTION_TYPE_MANUAL, 3, ['estimatedExecDuration' => 5.0]);
if (empty($tr['status_ok']) || intval($tr['id']) <= 0) {
    fwrite(STDERR, "tc create failed: " . print_r($tr, true) . "\n");
    exit(1);
}
$idTC = intval($tr['id']);
$rows = $db->get_recordset(
    "SELECT TC.id AS tcv_id, TC.version FROM tcversions TC" .
    " JOIN nodes_hierarchy NH ON NH.id = TC.id" .
    " WHERE NH.parent_id = " . intval($idTC) . " ORDER BY TC.id DESC LIMIT 1");
$idTCV = $rows ? intval($rows[0]['tcv_id']) : 0;
echo "tc=$idTC tcversion=$idTCV\n";

// keyword
$kwId = 0;
$rs = $db->exec_query(
    "INSERT INTO keywords (keyword, testproject_id, notes) VALUES ('smoke', {$idP}, 'fixture kw #1335')");
if ($rs) {
    $kwId = intval($db->insert_id('keywords'));
    $db->exec_query(
        "INSERT INTO testcase_keywords (testcase_id, tcversion_id, keyword_id) " .
        "VALUES ({$idTC}, {$idTCV}, {$kwId})");
}
echo "keyword=$kwId\n";

// platform
$pfId = 0;
$pfRows = $db->get_recordset(
    "SELECT id FROM platforms WHERE testproject_id = {$idP} AND name = 'Linux'");
if (is_array($pfRows) && count($pfRows) > 0) {
    $pfId = intval($pfRows[0]['id']);
}
if ($pfId <= 0) {
    $rs = $db->exec_query(
        "INSERT INTO platforms (name, testproject_id, notes, enable_on_design, enable_on_execution) " .
        "VALUES ('Linux', {$idP}, 'fixture platform #1335', 1, 1)");
    if ($rs) {
        $pfId = intval($db->insert_id('platforms'));
    }
}
if ($pfId > 0 && $idTCV > 0) {
    $db->exec_query(
        "INSERT INTO testcase_platforms (testcase_id, tcversion_id, platform_id) " .
        "VALUES ({$idTC}, {$idTCV}, {$pfId})");
}
echo "platform=$pfId\n";

// custom field Severity (list) on testcase design
$cfId = 0;
$cf = [
    'name' => 'Severity', 'label' => 'Severity', 'type' => 6,
    'possible_values' => "High\nMedium\nLow",
    'show_on_design' => 1, 'enable_on_design' => 1,
    'show_on_testplan_design' => 1, 'enable_on_testplan_design' => 1,
    'show_on_execution' => 0, 'enable_on_execution' => 0,
    'node_type_id' => 3, // testcase
];
$cFRet = $cfieldMgr->create($cf);
if (!empty($cFRet['status_ok']) && intval($cFRet['id']) > 0) {
    $cfId = intval($cFRet['id']);
    $db->exec_query(
        "INSERT IGNORE INTO cfield_testprojects (field_id, testproject_id, display_order, location, active) " .
        "VALUES ({$cfId}, {$idP}, 1, 1, 1)");
    if ($idTC > 0) {
        $db->exec_query(
            "INSERT IGNORE INTO cfield_design_values (field_id, node_id, value) VALUES ({$cfId}, {$idTC}, 'High')");
        if ($idTCV > 0) {
            $db->exec_query(
                "INSERT IGNORE INTO cfield_design_values (field_id, node_id, value) VALUES ({$cfId}, {$idTCV}, 'High')");
        }
    }
}
echo "cfield=$cfId\n";

echo "FIXTURE_OK idP=$idP idS=$idS idTC=$idTC idTCV=$idTCV kw=$kwId pf=$pfId cf=$cfId\n";