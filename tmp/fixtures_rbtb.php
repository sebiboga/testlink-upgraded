<?php
// CLI fixtures for Results by Tester per Build (Refs #677).
// Run: php tmp/fixtures_rbtb.php
$_SESSION = array();
require_once(dirname(__DIR__) . '/config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tsuiteMgr = new testsuite($db);
$tcaseMgr = new testcase($db);
$tplanMgr = new testplan($db);
$buildMgr = new build($db);

function childByName($db, $parentId, $name) {
    $row = $db->get_recordset(
        "SELECT id,name FROM nodes_hierarchy WHERE parent_id=" .
        intval($parentId) . " AND name='" . $db->prepare_string($name) . "'");
    return $row ? intval($row[0]['id']) : 0;
}

function firstActiveVersionId($db, $tcaseId) {
    $row = $db->get_recordset(
        "SELECT TCVERSION.id FROM tcversions AS TCVERSION " .
        "JOIN nodes_hierarchy NH ON NH.id = TCVERSION.id " .
        "WHERE NH.parent_id = " . intval($tcaseId) . " AND TCVERSION.active = 1 " .
        "ORDER BY TCVERSION.id ASC LIMIT 1");
    return $row ? intval($row[0]['id']) : 0;
}

// ---- users -------------------------------------------------------------
function ensureUser($db, $login, $roleId) {
    $row = $db->get_recordset("SELECT id FROM users WHERE login='" .
        $db->prepare_string($login) . "'");
    if ($row) { return intval($row[0]['id']); }
    // tlUser::create() is a no-op stub -> plain SQL insert
    $cookie = 'rbtb-' . md5($login . microtime(true));
    $hash = password_hash('password123', PASSWORD_BCRYPT);
    $ok = $db->exec_query(
        "INSERT INTO users (login,password,cookie_string,first,last,email," .
        "role_id,locale,active,creation_ts) VALUES ('" .
        $db->prepare_string($login) . "','" . $db->prepare_string($hash) .
        "','" . $cookie . "','Fixture','Rbtb','" . $db->prepare_string('rbtb_' . $login . '@example.com') .
        "'," . intval($roleId) . ",'en_GB',1,NOW())");
    if (!$ok) { echo "user create failed\n"; exit(1); }
    $row = $db->get_recordset("SELECT id FROM users WHERE login='" .
        $db->prepare_string($login) . "'");
    echo "user=$login id={$row[0]['id']}\n";
    return intval($row[0]['id']);
}

$userA = ensureUser($db, 'testerA', 6); // senior tester
$userB = ensureUser($db, 'testerB', 7); // tester
$noperm = ensureUser($db, 'noinv', 3);  // no rights

// ---- project -----------------------------------------------------------
$name = 'RBTP Demo Project';
$rs = $tprojMgr->get_by_name($name);
if ($rs) {
    $projId = intval($rs[0]['id']);
    echo "project exists id=$projId\n";
} else {
    $item = new stdClass();
    $item->name = $name;
    $item->prefix = 'RBTP';
    $item->notes = 'fixture for #677';
    $item->options = new stdClass();
    $item->color = '';
    $item->active = 1;
    $item->is_public = 1;
    $projId = intval($tprojMgr->create($item));
    echo "project=$projId\n";
}

$idS = childByName($db, $projId, 'Suite RBTP');
if (!$idS) {
    $retS = $tsuiteMgr->create($projId, 'Suite RBTP', 'details', null, 1);
    $idS = is_array($retS) ? intval($retS['id']) : intval($retS);
}
echo "tsuite=$idS\n";

// ---- test cases --------------------------------------------------------
$tcs = array();
for ($i = 1; $i <= 6; $i++) {
    $cname = 'RBTC' . $i;
    $cid = childByName($db, $idS, $cname);
    if (!$cid) {
        $steps = [['step_number' => 1, 'actions' => 'do step', 'expected_results' => 'ok']];
        $ret = $tcaseMgr->create($idS, $cname, 'summary ' . $cname, 'precond',
            $steps, 1);
        $cid = is_array($ret) ? intval($ret['id']) : intval($ret);
    }
    $tcs[$i] = $cid;
}
echo "tcases=" . implode(',', $tcs) . "\n";

// ---- plan + builds -----------------------------------------------------
$planId = 0;
$rows = $db->get_recordset(
    "SELECT NH.id FROM nodes_hierarchy NH WHERE NH.name = 'Plan RBTP'");
if ($rows) { $planId = intval($rows[0]['id']); }
else { $planId = intval($tplanMgr->create('Plan RBTP', 'plan for #677', $projId, 1, 1)); }
echo "plan=$planId\n";

$buildOpen = 0; $buildClosed = 0;
$brows = $db->get_recordset(
    "SELECT id,is_open,active FROM builds WHERE testplan_id=" . intval($planId));
if ($brows) {
    foreach ($brows as $b) {
        if (intval($b['is_open'])) { $buildOpen = intval($b['id']); }
        else { $buildClosed = intval($b['id']); }
    }
}
if (!$buildOpen) { $buildOpen = intval($buildMgr->create($planId, 'Build Open One', 'open build', 1, 1)); }
if (!$buildClosed) { $buildClosed = intval($buildMgr->create($planId, 'Build Closed Two', 'closed build', 1, 0)); }
echo "buildOpen=$buildOpen buildClosed=$buildClosed\n";

// ---- link versions + assignments + executions -------------------------
$linkMap = array();
foreach ($tcs as $i => $tcId) {
    $tvId = firstActiveVersionId($db, $tcId);
    $items = ['tcversion' => [$tcId => $tvId], 'items' => [$tcId => [0 => $tvId]]];
    $tplanMgr->link_tcversions($planId, $items, 1);
    $row = $db->get_recordset(
        "SELECT TPTCV.id AS feature_id, TPTCV.tcversion_id FROM testplan_tcversions TPTCV " .
        "WHERE TPTCV.testplan_id=" . intval($planId) .
        " AND TPTCV.tcversion_id=" . intval($tvId));
    $linkMap[$i] = ['feature' => intval($row[0]['feature_id']),
                    'tcversion' => intval($tvId)];
}
echo "linked features\n";

function ensureAssignment($db, $featureId, $buildId, $userId, $assignerId) {
    $row = $db->get_recordset(
        "SELECT id FROM user_assignments WHERE feature_id=" . intval($featureId) .
        " AND build_id=" . intval($buildId) . " AND user_id=" . intval($userId) .
        " AND type=1");
    if ($row) { return intval($row[0]['id']); }
    $db->exec_query(
        "INSERT INTO user_assignments (type,feature_id,user_id,build_id," .
        "deadline_ts,assigner_id,creation_ts,status) VALUES (1," .
        intval($featureId) . "," . intval($userId) . "," . intval($buildId) .
        ",NULL," . intval($assignerId) . ",NOW(),1)");
    return intval($db->insert_id('user_assignments', 'id'));
}

function ensureExecution($db, $buildId, $planId, $platId, $tcversionId,
                         $testerId, $status, $duration) {
    // one execution per (build,platform,tcversion): LEBBP picks the latest
    $row = $db->get_recordset(
        "SELECT id FROM executions WHERE build_id=" . intval($buildId) .
        " AND platform_id=" . intval($platId) .
        " AND tcversion_id=" . intval($tcversionId));
    if ($row) { return intval($row[0]['id']); }
    $db->exec_query(
        "INSERT INTO executions (build_id,tester_id,execution_ts,status," .
        "testplan_id,tcversion_id,tcversion_number,platform_id,execution_type," .
        "execution_duration,notes) VALUES (" . intval($buildId) . "," .
        intval($testerId) . ",NOW(),'" . $status . "'," . intval($planId) . "," .
        intval($tcversionId) . ",1," . intval($platId) . ",1," .
        floatval($duration) . ",'fixture')");
    return intval($db->insert_id('executions', 'id'));
}

// Build Open One: A -> f1 passed(10.50) f2 passed(20.25) f3 failed(15)
//                 f4 failed(12.75); B -> f5 blocked(45.10); f6 unassigned+not run
ensureAssignment($db, $linkMap[1]['feature'], $buildOpen, $userA, 1);
ensureAssignment($db, $linkMap[2]['feature'], $buildOpen, $userA, 1);
ensureAssignment($db, $linkMap[3]['feature'], $buildOpen, $userA, 1);
ensureAssignment($db, $linkMap[4]['feature'], $buildOpen, $userA, 1);
ensureAssignment($db, $linkMap[5]['feature'], $buildOpen, $userB, 1);
ensureAssignment($db, $linkMap[6]['feature'], $buildOpen, $userB, 1);

ensureExecution($db, $buildOpen, $planId, 0, $linkMap[1]['tcversion'], $userA, 'p', 10.50);
ensureExecution($db, $buildOpen, $planId, 0, $linkMap[2]['tcversion'], $userA, 'p', 20.25);
ensureExecution($db, $buildOpen, $planId, 0, $linkMap[3]['tcversion'], $userA, 'f', 15.00);
ensureExecution($db, $buildOpen, $planId, 0, $linkMap[4]['tcversion'], $userA, 'f', 12.75);
ensureExecution($db, $buildOpen, $planId, 0, $linkMap[5]['tcversion'], $userB, 'b', 45.10);

// Build Closed Two: only A assigned on f1+f2, f1 executed passed
ensureAssignment($db, $linkMap[1]['feature'], $buildClosed, $userA, 1);
ensureAssignment($db, $linkMap[2]['feature'], $buildClosed, $userA, 1);
ensureExecution($db, $buildClosed, $planId, 0, $linkMap[1]['tcversion'], $userA, 'p', 30.00);

echo json_encode(compact('projId','idS','planId','buildOpen','buildClosed',
    'userA','userB','noperm')) . "\n";
