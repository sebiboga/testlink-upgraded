<?php
// Fixture for #1065 browser/API testing: Requirements Traceability Matrix
// (gui/templates/results/rtm.html + api/rtm/index.php).
//
// Creates ONE test project with requirements enabled:
//
//   tproject RTM1065 (prefix R6)  -> the project under test
//     suite "R6 Root"
//       tcase R6 Login Pass     -> linked to R6-REQ-1  -> exec p (b1), f (b2)
//       tcase R6 Login Fail     -> linked to R6-REQ-1  -> exec f (b1) + BUG-1
//       tcase R6 Coverage X     -> linked to R6-REQ-1  -> NO execution
//       tcase R6 Checkout Blocked -> linked to R6-REQ-2 -> exec b (b1)
//       tcase R6 Report Export  -> linked to NOTHING (orphan TC) -> exec p (b1)
//     req spec "R6 SPEC A" (doc-id RS-R6)
//       R6-REQ-1 Login validation        -> linked: Login Pass, Login Fail, Coverage X
//       R6-REQ-2 Checkout totals         -> linked: Checkout Blocked
//       R6-REQ-3 Orphan requirement      -> NO link at all
//     plan "R6 Plan" with all 5 versions assigned, platform 0
//     builds: R6 Build 1 (open), R6 Build 2 (open)
//   user rtmnorights (role 3)  -> the 403 path
//
// Run from repo root: php tmp/fixtures_1065.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tplanMgr = new testplan($db);
$tsuiteMgr = new testsuite($db);
$tcaseMgr = new testcase($db);
$reqSpecMgr = new requirement_spec_mgr($db);
$reqMgr = new requirement_mgr($db);
$userId = 1; // admin

foreach ((array)$tprojMgr->get_by_name('RTM1065') as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) {
        echo "deleting old project RTM1065 ($oid)\n";
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'RTM1065';
$item->prefix = 'R6';
$item->notes = 'fixture for #1065 (requirements traceability matrix)';
$item->color = '';
$item->active = 1;
$item->is_public = 1;
$opts = new stdClass();
$opts->requirementsEnabled = 1;
$opts->testPriorityEnabled = 0;
$opts->automationEnabled = 0;
$opts->inventoryEnabled = 0;
$opts->platformsEnabled = 0;
$opts->testcasecfEnabled = 0;
$opts->requirementcfEnabled = 0;
$item->options = $opts;
$idP = intval($tprojMgr->create($item));
if ($idP <= 0) {
    die("tproject create failed\n");
}
$tprojMgr->setActive($idP);
echo "tproject RTM1065=$idP\n";

$idPlan = intval($tplanMgr->create('R6 Plan', '', $idP, 1, 1));
echo "testplan R6 Plan=$idPlan\n";

$retS = $tsuiteMgr->create($idP, 'R6 Root', '', null, 0, 'allow_repeat');
$idRoot = intval($retS['id'] ?? 0);
echo "suite R6 Root=$idRoot\n";

function mk1065Steps()
{
    $steps = array();
    for ($i = 1; $i <= 2; $i++) {
        $t = new stdClass();
        $t->step_number = $i;
        $t->actions = 'Do action number ' . $i;
        $t->expected_results = 'Expected result of step ' . $i;
        $t->execution_type = TESTCASE_EXECUTION_TYPE_MANUAL;
        $steps[] = $t;
    }
    return $steps;
}

$makeTcase = function ($name) use ($tcaseMgr, $idRoot, $userId) {
    $ret = $tcaseMgr->create($idRoot, $name, 'summary of ' . $name, '', mk1065Steps(),
        $userId, '', testcase::DEFAULT_ORDER, testcase::AUTOMATIC_ID,
        TESTCASE_EXECUTION_TYPE_MANUAL);
    if (!$ret['status_ok'] || $ret['id'] <= 0) {
        die("tcase create failed for $name: " . ($ret['message'] ?? '') . "\n");
    }
    return array(intval($ret['id']), intval($ret['tcversion_id']));
};

$cases = array(
    'R6 Login Pass',
    'R6 Login Fail',
    'R6 Coverage X',
    'R6 Checkout Blocked',
    'R6 Report Export',
    'R6 No Trace',
);
$versions = array();
foreach ($cases as $c) {
    list($tc, $tcv) = $makeTcase($c);
    $versions[$c] = array('tc' => $tc, 'tcv' => $tcv);
    echo "tcase $c tc=$tc version=$tcv\n";
}

// Requirements ---------------------------------------------------------------
$op = $reqSpecMgr->create($idP, $idP, 'RS-R6', 'R6 SPEC A', 'fixture spec for 1065',
    3, $userId, TL_REQ_SPEC_TYPE_USER_REQ_SPEC);
if (empty($op['status_ok']) || intval($op['id'] ?? 0) <= 0) {
    die('spec create failed: ' . json_encode($op) . "\n");
}
$idSpec = intval($op['id']);
echo "req spec R6 SPEC A=$idSpec\n";

$mkReq = function ($docId, $title) use ($reqMgr, $idSpec, $userId, $idP) {
    $r = $reqMgr->create($idSpec, $docId, $title, 'scope of ' . $title, $userId,
        TL_REQ_STATUS_VALID, TL_REQ_TYPE_INFO, 1, 1, $idP);
    if (empty($r['status_ok']) || intval($r['id'] ?? 0) <= 0) {
        die('req create failed for ' . $docId . ': ' . json_encode($r) . "\n");
    }
    return intval($r['id']);
};

$idR1 = $mkReq('R6-REQ-1', 'Login validation');
$idR2 = $mkReq('R6-REQ-2', 'Checkout totals');
$idR3 = $mkReq('R6-REQ-3', 'Orphan requirement');
$idR4 = $mkReq('R6-REQ-4', 'Report export covered');
echo "reqs R6-REQ-1=$idR1 R6-REQ-2=$idR2 R6-REQ-3=$idR3 R6-REQ-4=$idR4\n";

$tables = tlObject::getDBTables();
$lrvi = $db->get_recordset(' SELECT req_id, req_version_id FROM latest_req_version_id' .
    " WHERE req_id IN ($idR1,$idR2,$idR3,$idR4)");
$v = array();
foreach ((array)$lrvi as $row) {
    $v[intval($row['req_id'])] = intval($row['req_version_id']);
}
foreach (array($idR1, $idR2, $idR3, $idR4) as $rid) {
    if (empty($v[$rid])) {
        die("req version id not found for req $rid\n");
    }
}

// req_coverage links: REQ-1 -> Login Pass, Login Fail, Coverage X
//                    REQ-2 -> Checkout Blocked
//                    REQ-4 -> Report Export (fully covered on b1)
$links = array(
    array($idR1, $versions['R6 Login Pass']),
    array($idR1, $versions['R6 Login Fail']),
    array($idR1, $versions['R6 Coverage X']),
    array($idR2, $versions['R6 Checkout Blocked']),
    array($idR4, $versions['R6 Report Export']),
);
$sql = array();
foreach ($links as $l) {
    $sql[] = "(" . intval($l[0]) . ", " . intval($v[$l[0]]) . ", " . intval($l[1]['tc'])
        . ", " . intval($l[1]['tcv']) . ", 1, 1, $userId)";
}
$db->exec_query(' INSERT INTO ' . $tables['req_coverage'] .
    ' (req_id, req_version_id, testcase_id, tcversion_id, link_status, is_active, author_id) VALUES ' .
    implode(',', $sql));
echo "linked req_coverage rows: " . count($sql) . "\n";

// Test plan assignment -------------------------------------------------------
// No assign_tcversion_to_plan(): insert the plain testplan_tcversions row.
foreach ($versions as $cname => $v) {
    $db->exec_query(
        "INSERT INTO {$tables['testplan_tcversions']}
            (testplan_id, author_id, creation_ts, tcversion_id, platform_id, node_order)
         VALUES ($idPlan, $userId, NOW(), " . intval($v['tcv']) . ", 0, 1)");
}
echo "assigned " . count($versions) . " versions to plan $idPlan\n";

// Builds + executions --------------------------------------------------------
function mk1065Build($db, $tables, $idP, $name)
{
    $db->exec_query("INSERT INTO {$tables['builds']}
        (testproject_id, name, notes, active, is_open, author_id, creation_ts)
      VALUES ($idP, '$name', 'fixture build', 1, 1, 1, NOW())");
    return intval($db->insert_id($tables['builds']));
}
$build1 = mk1065Build($db, $tables, $idP, 'R6 Build 1');
$build2 = mk1065Build($db, $tables, $idP, 'R6 Build 2');
echo "builds b1=$build1 b2=$build2\n";

function mk1065Exec($db, $tables, $planId, $buildId, $tcv, $status, $ts)
{
    $db->exec_query(
        "INSERT INTO {$tables['executions']}
            (build_id, tester_id, execution_ts, status, testplan_id, tcversion_id,
             tcversion_number, platform_id, execution_type, execution_duration)
         VALUES (" . intval($buildId) . ", 1, '$ts', '$status', " . intval($planId) . ", "
        . intval($tcv) . ", 1, 0, 1, 0)");
    return intval($db->insert_id($tables['executions']));
}

$T = $tables;
$eLoginPassB1 = mk1065Exec($db, $T, $idPlan, $build1, $versions['R6 Login Pass']['tcv'], 'p', '2026-01-05 10:00:00');
mk1065Exec($db, $T, $idPlan, $build1, $versions['R6 Login Fail']['tcv'], 'f', '2026-01-05 10:10:00');
mk1065Exec($db, $T, $idPlan, $build1, $versions['R6 Checkout Blocked']['tcv'], 'b', '2026-01-05 10:20:00');
mk1065Exec($db, $T, $idPlan, $build1, $versions['R6 Report Export']['tcv'], 'p', '2026-01-05 10:30:00');
mk1065Exec($db, $T, $idPlan, $build1, $versions['R6 No Trace']['tcv'], 'p', '2026-01-05 10:40:00');
// Latest result on b2 for Login Pass is FAIL -> a build filter must flip it.
mk1065Exec($db, $T, $idPlan, $build2, $versions['R6 Login Pass']['tcv'], 'f', '2026-01-06 11:00:00');
echo "executions written\n";

// One defect linked to the failed Login Fail execution on b1 (tcstep 0 = whole case).
$db->exec_query("INSERT INTO {$T['execution_bugs']} (execution_id, bug_id, tcstep_id)
    VALUES (" . intval($eLoginPassB1) . ", 'BUG-1065', 1)");
echo "execution_bugs: execution $eLoginPassB1 BUG-1065\n";

// Nonexistent requirement id is NOT created - req R6-REQ-3 has zero links.

// A user with NO rights at all (role 3) -> the 403 path.
$TBU = tlObjectWithDB::getDBTables(array('users'));
$hash = password_hash('rtmnorights', PASSWORD_DEFAULT);
$db->exec_query("INSERT INTO {$TBU['users']}
    (login, password, role_id, email, first, last, locale,
     default_testproject_id, active, cookie_string, auth_method)
  VALUES ('rtmnorights', '$hash', 3, 'n@no.no', 'No', 'Rights', 'en_GB', 0, 1,
          'ck_rtmnorights_1065', 'DB')
  ON DUPLICATE KEY UPDATE password=VALUES(password), role_id=3, active=1,
          auth_method=VALUES(auth_method)");
echo "user rtmnorights ready (role 3)\n";

echo "DONE. ids: project=$idP plan=$idPlan build1=$build1 build2=$build2 spec=$idSpec "
   . "reqs=($idR1,$idR2,$idR3) execLoginPassB1=$eLoginPassB1\n";