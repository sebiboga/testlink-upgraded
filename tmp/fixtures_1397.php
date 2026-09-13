<?php
// Fixture for Issue #1397 browser testing: Set Results popup
// (execSetResults.html) assigned-user display + assign-task-to-me +
// has-no-assignment warning.
//   - test project ASG1397 (prefix A1397)
//   - one suite with two TCs (Case One, Case Two)
//   - one plan "ASG Plan" linking both TCs
//   - one open build
//   - extra user ro817 (tester role)
//   - Case One is assigned to ro817 for the build; Case Two is unassigned
// Run from repo root: php tmp/fixtures_1397.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tsuiteMgr = new testsuite($db);
$tcaseMgr = new testcase($db);
$tplanMgr = new testplan($db);
$buildMgr = new build($db);
$userId = 1; // admin

function firstId($r) {
    if (is_array($r)) {
        if (isset($r['id'])) { return intval($r['id']); }
        $k = array_keys($r);
        return intval($k[0]);
    }
    return intval($r);
}

// idempotency: drop previous fixture project (cascades to plan/tcversions)
$old = $tprojMgr->get_by_name('ASG1397');
foreach ((array)$old as $row) {
    $oid = intval($row['id']);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'ASG1397';
$item->prefix = 'A1397';
$item->notes = 'fixture for issue 1397 (set results assignment)';
$item->color = '';
$item->active = 1;
$item->is_public = 1;
$opts = new stdClass();
$opts->requirementsEnabled = 0;
$opts->testPriorityEnabled = 0;
$opts->automationEnabled = 0;
$opts->inventoryEnabled = 0;
$item->options = $opts;
$idP = $tprojMgr->create($item);
echo "tproject=$idP\n";
if ($idP <= 0) { die("project create failed\n"); }

$idS = firstId($tsuiteMgr->create($idP, 'ASG Suite', 'suite details', null, null, 1));
echo "tsuite=$idS\n";

$tcDefs = ['Case One' => 'assigned TC', 'Case Two' => 'unassigned TC'];
$idTCs = [];
foreach ($tcDefs as $nm => $summ) {
    $idT = $tcaseMgr->create($idS, $nm, $summ, 'precond ' . $nm,
        [['step_number' => 1, 'actions' => 'do it', 'expected_results' => 'works']], 1);
    $idTCs[$nm] = firstId($idT);
    echo "tc $nm={$idTCs[$nm]}\n";
}

$idTP = $tplanMgr->create('ASG Plan', 'plan for issue 1397', $idP, 1, 1);
echo "tplan=$idTP\n";

$tbl = tlObjectWithDB::getDBTables(['nodes_hierarchy', 'tcversions']);
$linkItems = ['items' => [], 'tcversion' => []];
$tcv = [];
foreach ($idTCs as $nm => $idT) {
    $rs = $db->get_recordset(
        " SELECT NH.id FROM {$tbl['nodes_hierarchy']} NH" .
        " JOIN {$tbl['tcversions']} TV ON TV.id = NH.id" .
        " WHERE NH.parent_id = " . intval($idT) .
        " AND TV.active = 1 ORDER BY TV.version");
    if (!is_array($rs) || !isset($rs[0])) { die("no tcversion for $nm\n"); }
    $tv = intval($rs[0]['id']);
    $linkItems['tcversion'][$idT] = $tv;
    $linkItems['items'][$idT] = [0 => $tv];
    $tcv[$nm] = $tv;
    echo "tcversion $nm=$tv\n";
}
$tplanMgr->link_tcversions($idTP, $linkItems, 1, array('getTCPrefixFromTPlan' => true));
echo "linkTcversions ok\n";

$bid = $buildMgr->create($idTP, 'B1397', 'build 1397');
echo "build=$bid\n";

// extra user ro817 (tester role 7)
$uTbl = tlObjectWithDB::getDBTables(['users']);
$uRs = $db->get_recordset("SELECT id FROM {$uTbl['users']} WHERE login = 'ro817'");
$roId = 0;
if (is_array($uRs) && isset($uRs[0])) {
    $roId = intval($uRs[0]['id']);
    echo "user ro817 exists id=$roId\n";
} else {
    $sql = "INSERT INTO {$uTbl['users']} " .
           "(login,password,role_id,email,first,last,locale,active,cookie_string,auth_method,creation_ts) " .
           " VALUES ('ro817','" . md5('ro817') . "',7,'ro817@example.com','ro','817','en_GB',1," .
           "'" . md5('ro817' . time()) . "',''," . $db->db_now() . ")";
    $db->exec_query($sql);
    $roId = intval($db->insert_id($uTbl['users']));
    echo "user ro817 created id=$roId\n";
}

// assign Case One to ro817 for build B1397 (feature_id = testplan_tcversions.id)
$tptcv = tlObjectWithDB::getDBTables(['testplan_tcversions'])['testplan_tcversions'];
$tables = tlObjectWithDB::getDBTables(['assignment_types', 'assignment_status']);
$atRes = $db->get_recordset("SELECT id FROM {$tables['assignment_types']} WHERE description = 'testcase_execution'");
$typeId = is_array($atRes) && isset($atRes[0]) ? intval($atRes[0]['id']) : 1;
$asRes = $db->get_recordset("SELECT id FROM {$tables['assignment_status']} WHERE description = 'open'");
$statusId = is_array($asRes) && isset($asRes[0]) ? intval($asRes[0]['id']) : 1;

$feature1 = 0;
$rows = $db->get_recordset(
    " SELECT id FROM {$tptcv} WHERE testplan_id = {$idTP} " .
    " AND tcversion_id = " . intval($tcv['Case One']) . " AND platform_id = 0");
if (is_array($rows) && isset($rows[0])) {
    $feature1 = intval($rows[0]['id']);
}
if ($feature1 <= 0) { die("no feature row for Case One\n"); }

$ua = tlObjectWithDB::getDBTables(['user_assignments'])['user_assignments'];
$have = $db->get_recordset(
    " SELECT id FROM {$ua} WHERE feature_id = {$feature1} " .
    " AND build_id = {$bid} AND type = {$typeId} AND user_id = {$roId}");
if (is_array($have) && isset($have[0])) {
    echo "assignment already exists\n";
} else {
    $db->exec_query(
        " INSERT INTO {$ua} (feature_id,user_id,assigner_id,type,status,creation_ts,build_id) " .
        " VALUES ({$feature1},{$roId},{$userId},{$typeId},{$statusId}," . $db->db_now() . ",{$bid})");
    echo "assignment created (feature=$feature1 user=$roId build=$bid)\n";
}

echo "DONE tproject=$idP plan=$idTP build=$bid suite=$idS\n";
echo "Case One  tcase={$idTCs['Case One']}  tcversion={$tcv['Case One']}  feature=$feature1\n";
echo "Case Two  tcase={$idTCs['Case Two']}  tcversion={$tcv['Case Two']}\n";