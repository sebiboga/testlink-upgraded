<?php
// Fixture for Issue #895 browser testing: Dashboard "My Assigned Test Cases"
// widget (gui/templates/mainpage/mainPage.html + api/mainpage/index.php
// GET /assigned).
//   - test project ASG895 (prefix A895, testPriorityEnabled=1)
//   - one suite "ASG Suite" with three TCs
//   - one active plan "ASG Dashboard Plan" linking all three TCs
//   - one OPEN build B895 Open + one CLOSED build B895 Closed
//   - admin (user 1) is assigned TC One + TC Two on the OPEN build,
//     and TC Three on the CLOSED build (must NOT appear, build_status=open)
//   - TC One gets a pre-inserted PASSED execution (status column check)
//   - TC Two gets a deadline_ts in the past (overdue badge check)
// Run from repo root: php tmp/fixtures_895.php
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

function firstId895($r) {
    if (is_array($r)) {
        if (isset($r['id'])) { return intval($r['id']); }
        $k = array_keys($r);
        return intval($k[0]);
    }
    return intval($r);
}

// idempotency: drop previous fixture project (cascades to plan/tcversions)
$old = $tprojMgr->get_by_name('ASG895');
foreach ((array)$old as $row) {
    $oid = intval($row['id']);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'ASG895';
$item->prefix = 'A895';
$item->notes = 'fixture for issue 895 (assigned test cases on dashboard)';
$item->color = '';
$item->active = 1;
$item->is_public = 1;
$opts = new stdClass();
$opts->requirementsEnabled = 0;
$opts->testPriorityEnabled = 1;
$opts->automationEnabled = 0;
$opts->inventoryEnabled = 0;
$item->options = $opts;
$idP = $tprojMgr->create($item);
echo "tproject=$idP\n";
if ($idP <= 0) { die("project create failed\n"); }

$idS = firstId895($tsuiteMgr->create($idP, 'ASG Suite', 'suite details', null, null, 1));
echo "tsuite=$idS\n";

$tcDefs = [
    'Dashboard TC One'   => ['assigned + passed already' , 3, 3],
    'Dashboard TC Two'   => ['assigned + overdue deadline', 2, 1],
    'Dashboard TC Three' => ['assigned on closed build (hidden)', 1, 1],
];
$idTCs = [];
foreach ($tcDefs as $nm => $def) {
    $idT = $tcaseMgr->create($idS, $nm, $def[0], 'precond ' . $nm,
        [['step_number' => 1, 'actions' => 'do it', 'expected_results' => 'works']], 1);
    $idTCs[$nm] = firstId895($idT);
    echo "tc $nm={$idTCs[$nm]}\n";
}

$idTP = $tplanMgr->create('ASG Dashboard Plan', 'plan for issue 895', $idP, 1, 1);
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

$bOpen = $buildMgr->create($idTP, 'B895 Open', 'open build 895');
$bClosed = $buildMgr->create($idTP, 'B895 Closed', 'closed build 895');
echo "build open=$bOpen closed=$bClosed\n";

$buildTbl = tlObjectWithDB::getDBTables(['builds'])['builds'];
$db->exec_query("UPDATE {$buildTbl} SET is_open = 0 WHERE id = " . intval($bClosed));

// link urgency*importance per TC (via testplan_tcversions.urgency)
$tptcv = tlObjectWithDB::getDBTables(['testplan_tcversions'])['testplan_tcversions'];
foreach ($tcDefs as $nm => $def) {
    $db->exec_query(
        " UPDATE {$tptcv} SET urgency = " . intval($def[1]) .
        " WHERE testplan_id = {$idTP} AND tcversion_id = " . intval($tcv[$nm]) . " AND platform_id = 0");
}

$tables = tlObjectWithDB::getDBTables(['assignment_types', 'assignment_status']);
$atRes = $db->get_recordset("SELECT id FROM {$tables['assignment_types']} WHERE description = 'testcase_execution'");
$typeId = is_array($atRes) && isset($atRes[0]) ? intval($atRes[0]['id']) : 1;
$asRes = $db->get_recordset("SELECT id FROM {$tables['assignment_status']} WHERE description = 'open'");
$statusId = is_array($asRes) && isset($asRes[0]) ? intval($asRes[0]['id']) : 1;

$ua = tlObjectWithDB::getDBTables(['user_assignments'])['user_assignments'];
$featureOf = [];
foreach ($tcv as $nm => $tv) {
    $rows = $db->get_recordset(
        " SELECT id FROM {$tptcv} WHERE testplan_id = {$idTP} " .
        " AND tcversion_id = " . intval($tv) . " AND platform_id = 0");
    $fid = 0;
    if (is_array($rows) && isset($rows[0])) { $fid = intval($rows[0]['id']); }
    if ($fid <= 0) { die("no feature row for $nm\n"); }
    $featureOf[$nm] = $fid;
}

function assignUser895($db, $ua, $typeId, $statusId, $featureId, $userId, $buildId, $deadline = null) {
    $where = " feature_id = {$featureId} AND build_id = {$buildId} " .
             " AND type = {$typeId} AND user_id = {$userId}";
    $have = $db->get_recordset(" SELECT id FROM {$ua} WHERE {$where}");
    if (is_array($have) && isset($have[0])) {
        echo "assignment already exists (feature $featureId build $buildId)\n";
        return false;
    }
    $dl = is_null($deadline) ? 'NULL' : "'" . $deadline . "'";
    $sql = " INSERT INTO {$ua} (feature_id,user_id,assigner_id,type,status,creation_ts,build_id,deadline_ts) " .
           " VALUES ({$featureId},{$userId},{$userId},{$typeId},{$statusId}," . $db->db_now() . ",{$buildId},{$dl})";
    $db->exec_query($sql);
    echo "assignment created (feature=$featureId user=$userId build=$buildId deadline=$dl)\n";
    return true;
}

assignUser895($db, $ua, $typeId, $statusId, $featureOf['Dashboard TC One'], $userId, $bOpen);
assignUser895($db, $ua, $typeId, $statusId, $featureOf['Dashboard TC Two'], $userId, $bOpen,
    date('Y-m-d H:i:s', time() - 86400));
assignUser895($db, $ua, $typeId, $statusId, $featureOf['Dashboard TC Three'], $userId, $bClosed);

// Pre-insert a PASSED execution for TC One on the open build (status column).
$execTbl = tlObjectWithDB::getDBTables(['executions'])['executions'];
$haveExec = $db->get_recordset(
    " SELECT id FROM {$execTbl} WHERE testplan_id = {$idTP} AND tcversion_id = " .
    intval($tcv['Dashboard TC One']) . " AND build_id = {$bOpen} AND platform_id = 0");
if (!is_array($haveExec) || !isset($haveExec[0])) {
    $db->exec_query(
        " INSERT INTO {$execTbl} (build_id,tester_id,execution_ts,status,testplan_id,tcversion_id," .
        "   tcversion_number,platform_id,execution_type)" .
        " VALUES ({$bOpen},{$userId}," . $db->db_now() . ",'p',{$idTP}," .
        intval($tcv['Dashboard TC One']) . ",1,0,1)");
    echo "execution (passed) inserted for TC One\n";
} else {
    echo "execution already exists for TC One\n";
}

echo "DONE tproject=$idP plan=$idTP openBuild=$bOpen closedBuild=$bClosed suite=$idS\n";
foreach ($tcDefs as $nm => $def) {
    echo "TC '$nm'  tcase={$idTCs[$nm]}  tcversion={$tcv[$nm]}  feature={$featureOf[$nm]}\n";
}