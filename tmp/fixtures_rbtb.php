<?php
// CLI fixtures for "Results by Tester per Build" parity analysis (issue #1191).
// Seeds: project RBTB + plan, 6 TCs in 2 suites, open + closed builds,
// user_assignments (exec-tasks), executions by tester2@admin with a spread
// of statuses, plus a no-rights user for the 403 check.
// Run from repo root: php tmp/fixtures_rbtb.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tsuiteMgr = new testsuite($db);
$tcaseMgr = new testcase($db);
$tplanMgr = new testplan($db);
$buildMgr = new build($db);

function fid($r) {
    if (is_array($r)) {
        if (isset($r['id'])) { return intval($r['id']); }
        $k = array_keys($r);
        return intval($k[0]);
    }
    return intval($r);
}

$adminId = intval($db->get_recordset("SELECT id FROM users WHERE login='admin'")[0]['id']);
echo "admin_id=$adminId\n";

// --- idempotent cleanup ---
$old = $tprojMgr->get_by_name('RBTB');
foreach ((array)$old as $row) {
    $o = intval($row['id']);
    if ($o > 0) { echo "deleting old RBTB $o\n"; $tprojMgr->delete($o, 1); }
}

$opts = new stdClass();
$opts->requirementsEnabled = 0;
$opts->testPriorityEnabled = 0;
$opts->automationEnabled = 0;
$item = new stdClass();
$item->name = 'RBTB';
$item->prefix = 'RBT';
$item->notes = 'Results by Tester per Build fixture (issue #1191)';
$item->options = $opts;
$item->active = 1;
$item->is_public = 1;
$idP = fid($tprojMgr->create($item));
$db->exec_query("UPDATE testprojects SET options='" .
    $db->prepare_string(serialize($opts)) . "' WHERE id=$idP");
echo "tproject=$idP\n";

$idS1 = fid($tsuiteMgr->create($idP, 'RBTB Suite One', 'suite one'));
$idS2 = fid($tsuiteMgr->create($idP, 'RBTB Suite Two', 'suite two'));
echo "tsuite_one=$idS1 tsuite_two=$idS2\n";

$tcv = [];
$tcid = [];
foreach ([['TC1', $idS1], ['TC2', $idS1], ['TC3', $idS1],
          ['TC4', $idS2], ['TC5', $idS2], ['TC6', $idS2]] as [$nm, $suit]) {
    $idTC = fid($tcaseMgr->create($suit, $nm, 'summary ' . $nm, '', [[
        'step_number' => 1, 'actions' => 'act ' . $nm,
        'expected_results' => 'exp ' . $nm]], 1));
    $rr = $db->get_recordset(
        " SELECT NH.id FROM nodes_hierarchy NH JOIN tcversions TV ON TV.id = NH.id" .
        " WHERE NH.parent_id = " . intval($idTC) . " AND TV.active = 1 ORDER BY TV.version");
    $tvid = intval($rr[0]['id']);
    $tcv[$nm] = $tvid;
    $tcid[$nm] = $idTC;
}
echo "tcversions: " . json_encode($tcv) . "\n";

// --- plan + builds ---
$idTP = fid($tplanMgr->create('PlanRBTB', 'results by tester per build plan', $idP, 1, 1));
echo "tplan=$idTP\n";

$linkItems = ['items' => [], 'tcversion' => []];
foreach ($tcv as $nm => $tv) {
    $tci = intval($db->get_recordset(
        " SELECT parent_id AS id FROM nodes_hierarchy WHERE id = " . intval($tv))[0]['id']);
    $linkItems['items'][$tci] = [0 => $tv];
    $linkItems['tcversion'][$tci] = $tv;
}
$tplanMgr->link_tcversions($idTP, $linkItems, 1, array('getTCPrefixFromTPlan' => true));

// testplan_tcversions ids: use latest rows of the plan
$tptcv = [];
foreach ($tcv as $nm => $tv) {
    $rr = $db->get_recordset(
        " SELECT id FROM testplan_tcversions WHERE testplan_id = $idTP" .
        " AND tcversion_id = " . intval($tv));
    $tptcv[$nm] = intval($rr[0]['id']);
}
echo "testplan_tcversions: " . json_encode($tptcv) . "\n";

$bOpen = fid($buildMgr->create($idTP, 'RBTB Open Build', 'open'));
$bClosed = fid($buildMgr->create($idTP, 'RBTB Closed Build', 'closed'));
$db->exec_query("UPDATE builds SET is_open = 0 WHERE id = $bClosed");
echo "build_open=$bOpen build_closed=$bClosed\n";

// --- second tester ---
$t2Rows = $db->get_recordset("SELECT id FROM users WHERE login='rbtb_tester'");
if (!empty($t2Rows)) {
    $t2Id = intval($t2Rows[0]['id']);
    echo "tester2: reusing id=$t2Id\n";
} else {
    $u = new tlUser();
    $u->login = 'rbtb_tester';
    $u->firstName = 'Rbtb';
    $u->lastName = 'Tester';
    $u->emailAddress = 'rbtb_tester@example.org';
    $u->globalRoleID = 4; // senior tester - rights granted per-project below
    $u->locale = 'en_GB';
    $u->isActive = 1;
    $u->setPassword('rbtb_tester');
    $res = $u->writeToDB($db);
    if ($res != tl::OK) { die('tester create failed ' . $res . "\n"); }
    $t2Id = intval($u->dbID);
    echo "tester2 created id=$t2Id\n";
}

// --- no-rights user (403 check) ---
$norgRows = $db->get_recordset("SELECT id FROM users WHERE login='rbtb_norg'");
if (!empty($norgRows)) {
    $norgId = intval($norgRows[0]['id']);
    echo "norg: reusing id=$norgId\n";
} else {
    $u = new tlUser();
    $u->login = 'rbtb_norg';
    $u->firstName = 'Rbtb';
    $u->lastName = 'Norg';
    $u->emailAddress = 'rbtb_norg@example.org';
    $u->globalRoleID = 5; // guest
    $u->locale = 'en_GB';
    $u->isActive = 1;
    $u->setPassword('rbtb_norg');
    $res = $u->writeToDB($db);
    if ($res != tl::OK) { die('norg create failed ' . $res . "\n"); }
    $norgId = intval($u->dbID);
    echo "norg created id=$norgId\n";
}

// --- user_assignments (type=1 => testcase_execution) ---
// open build: tester2 -> TC1,TC2,TC5 ; admin -> TC3,TC4
// closed build: tester2 -> TC1,TC2 ; admin -> TC4,TC6
$ua = [
    [$bOpen, $t2Id, ['TC1', 'TC2', 'TC5']],
    [$bOpen, $adminId, ['TC3', 'TC4']],
    [$bClosed, $t2Id, ['TC1', 'TC2']],
    [$bClosed, $adminId, ['TC4', 'TC6']],
];
foreach ($ua as [$bid, $uid, $tcs]) {
    foreach ($tcs as $nm) {
        $db->exec_query(
            "INSERT INTO user_assignments (type, feature_id, user_id, build_id)" .
            " VALUES (1, {$tptcv[$nm]}, $uid, $bid)");
    }
}
echo "user_assignments inserted\n";

// --- executions (latest per tcversion+build = the ones the metric reads) ---
$exec = [
    [$bOpen, 'TC1', $t2Id, 'p', 10.50],
    [$bOpen, 'TC2', $t2Id, 'f', 5.25],
    [$bOpen, 'TC3', $adminId, 'p', 2.00],
    [$bOpen, 'TC4', $adminId, 'b', 3.00],
    [$bClosed, 'TC1', $t2Id, 'p', 4.00],
    [$bClosed, 'TC4', $adminId, 'f', 6.50],
];
foreach ($exec as [$bid, $nm, $uid, $st, $dur]) {
    $db->exec_query(
        "INSERT INTO executions (testplan_id, platform_id, build_id, tester_id," .
        " execution_type, tcversion_id, execution_duration, status, notes, execution_ts)" .
        " VALUES ($idTP, 0, $bid, $uid, 1, {$tcv[$nm]}, $dur, '$st', 'rbtb $st', NOW())");
}
echo "executions inserted\n";

echo "DONE tplan=$idTP open=$bOpen closed=$bClosed t2=$t2Id norg=$norgId\n";