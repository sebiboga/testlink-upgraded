<?php
// Fixture for Refs #1759 (Move/Reorder Test Suites: a 403 on a suite of
// another project leaks the existence of that suite).
//
// Creates TWO PRIVATE test projects, each with two top level test suites, plus
// two users:
//   sm1759a        - a custom project role holding only mgt_view_tc +
//                    mgt_modify_tc, granted on project A ONLY. Any answer
//                    that distinguishes "exists in project B" from "does not
//                    exist at all" is a cross-project existence oracle.
//   sm1759norights - role 3 <no rights>, no project role at all: exercises the
//                    403 that MUST survive the fix (insufficient rights).
//
// Run from repo root: php tmp/fixtures_1759.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tblU = tlObject::getDBTables('users');

// ---- re-runnable: drop a previous run --------------------------------------
foreach (array('SM1759A', 'SM1759B') as $nm) {
    $existing = $tprojMgr->get_by_name($nm);
    foreach ((array)$existing as $row) {
        $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
        if ($oid > 0) {
            echo "deleting old project $oid ($nm)\n";
            $tprojMgr->delete($oid, 1);
        }
    }
}
$uRows = $db->get_recordset("SELECT id FROM {$tblU['users']} WHERE login IN ('sm1759a','sm1759norights','sm1759view')");
foreach ((array)$uRows as $u) {
    $uid = intval($u['id']);
    $db->exec_query("DELETE FROM user_testproject_roles WHERE user_id = $uid");
    $db->exec_query("DELETE FROM {$tblU['users']} WHERE id = $uid");
    echo "deleting old user $uid\n";
}
$db->exec_query("DELETE FROM role_rights WHERE role_id IN " .
    "(SELECT id FROM roles WHERE description = 'SM1759 editor A')");
$db->exec_query("DELETE FROM role_rights WHERE role_id IN " .
    "(SELECT id FROM roles WHERE description = 'SM1759 viewer A')");
$db->exec_query("DELETE FROM roles WHERE description IN ('SM1759 editor A','SM1759 viewer A')");

// ---- the two test projects --------------------------------------------------
function makeProject($db, $tprojMgr, $name, $prefix, $isPublic)
{
    $item = new stdClass();
    $item->name = $name;
    $item->prefix = $prefix;
    $item->notes = "fixture for Refs #1759 (cross project suite existence leak)";
    $item->color = '';
    $item->active = 1;
    $item->is_public = $isPublic;
    $opts = new stdClass();
    $opts->requirementsEnabled = 0;
    $opts->testPriorityEnabled = 0;
    $opts->automationEnabled = 0;
    $opts->inventoryEnabled = 0;
    $opts->platformsEnabled = 0;
    $item->options = $opts;
    $id = intval($tprojMgr->create($item));
    if ($id <= 0) {
        die("project $name create failed\n");
    }
    $tprojMgr->setActive($id);
    return $id;
}

$idA = makeProject($db, $tprojMgr, 'SM1759A', 'S9A', 0);
$idB = makeProject($db, $tprojMgr, 'SM1759B', 'S9B', 0);

// ---- two top level suites in each project ----------------------------------
$suiteMgr = new testsuite($db);
function makeSuite($db, $suiteMgr, $projectId, $title)
{
    $op = $suiteMgr->create($projectId, $title, "fixture Refs #1759", null, 1);
    if (!is_array($op) || intval($op['id'] ?? 0) <= 0) {
        die("suite $title create failed: " . json_encode($op) . "\n");
    }
    return intval($op['id']);
}

$sA1 = makeSuite($db, $suiteMgr, $idA, 'A-suite-1');
$sA2 = makeSuite($db, $suiteMgr, $idA, 'A-suite-2');
$sB1 = makeSuite($db, $suiteMgr, $idB, 'B-suite-1');
$sB2 = makeSuite($db, $suiteMgr, $idB, 'B-suite-2');

// ---- one TEST CASE per project (Refs #1790) --------------------------------
// #1790 needs a non-suite node id to prove that a `new_parent_id` of the wrong
// node TYPE is not distinguishable from an id that exists nowhere. Until this
// existed the fixture held suites only, so the wrong-type branch could not be
// exercised with this fixture at all - the case had to be probed with another
// suite's fixture. A test case under a SUITE does not appear in any container's
// suite child list, so every suite-level assertion of the #1759 / #1779
// harnesses is unaffected.
$tcMgr = new testcase($db);
function makeTestcase($db, $tcMgr, $suiteId, $title)
{
    $step = new stdClass();
    $step->step_number = 1;
    $step->actions = "Do the one action of $title";
    $step->expected_results = "Expected result of step 1";
    $step->execution_type = TESTCASE_EXECUTION_TYPE_MANUAL;
    $ret = $tcMgr->create($suiteId, $title, "summary of $title", '',
        array($step), 1, '', testcase::DEFAULT_ORDER, testcase::AUTOMATIC_ID,
        TESTCASE_EXECUTION_TYPE_MANUAL);
    if (empty($ret['status_ok']) || intval($ret['id'] ?? 0) <= 0) {
        die("testcase $title create failed: " . json_encode($ret) . "\n");
    }
    return intval($ret['id']);
}
$tcA = makeTestcase($db, $tcMgr, $sA1, 'A-case-1');
$tcB = makeTestcase($db, $tcMgr, $sB1, 'B-case-1');

// ---- the project role granted on project A ONLY ----------------------------
$db->exec_query("INSERT INTO roles (description) VALUES ('SM1759 editor A')");
$roleId = intval($db->insert_id());
foreach (array('mgt_view_tc', 'mgt_modify_tc') as $rn) {
    $rows = $db->get_recordset("SELECT id FROM rights WHERE description = '$rn'");
    if (!$rows) {
        die("right '$rn' missing\n");
    }
    $db->exec_query("INSERT INTO role_rights (role_id, right_id) VALUES ($roleId, " .
        intval($rows[0]['id']) . ")");
}

// A VIEW-ONLY project role: mgt_view_tc but NOT mgt_modify_tc. No built-in role
// has exactly that combination, and it is the only way to reach the endpoint's
// genuine insufficient-rights 403 (a user with NO project role at all falls into
// the hasRight() branch that skips the private-project check, see #1759 notes).
$db->exec_query("INSERT INTO roles (description) VALUES ('SM1759 viewer A')");
$roleView = intval($db->insert_id());
$rows = $db->get_recordset("SELECT id FROM rights WHERE description = 'mgt_view_tc'");
$db->exec_query("INSERT INTO role_rights (role_id, right_id) VALUES ($roleView, " .
    intval($rows[0]['id']) . ")");

// ---- the users --------------------------------------------------------------
$h = password_hash('admin', PASSWORD_BCRYPT);

$cs = hash('sha256', uniqid('t1759', true));
$db->exec_query("INSERT INTO {$tblU['users']} " .
    "(login,password,email,first,last,locale,role_id,active,cookie_string,auth_method) " .
    "VALUES ('sm1759a','" . $h . "','sm1759a@localhost','Cross','Project','en_GB',3,1,'$cs','')");
$uid = intval($db->insert_id());
$db->exec_query("INSERT INTO user_testproject_roles (user_id, testproject_id, role_id) " .
    "VALUES ($uid, $idA, $roleId)");

$csNo = hash('sha256', uniqid('t1759n', true));
$db->exec_query("INSERT INTO {$tblU['users']} " .
    "(login,password,email,first,last,locale,role_id,active,cookie_string,auth_method) " .
    "VALUES ('sm1759norights','" . $h . "','sm1759norights@localhost','No','Rights','en_GB',3,1,'$csNo','')");
$uidNo = intval($db->insert_id());

$csV = hash('sha256', uniqid('t1759v', true));
$db->exec_query("INSERT INTO {$tblU['users']} " .
    "(login,password,email,first,last,locale,role_id,active,cookie_string,auth_method) " .
    "VALUES ('sm1759view','" . $h . "','sm1759view@localhost','View','Only','en_GB',3,1,'$csV','')");
$uidV = intval($db->insert_id());
$db->exec_query("INSERT INTO user_testproject_roles (user_id, testproject_id, role_id) " .
    "VALUES ($uidV, $idA, $roleView)");

echo "DONE tprojectA=$idA tprojectB=$idB\n";
echo "  suiteA1=$sA1 suiteA2=$sA2 suiteB1=$sB1 suiteB2=$sB2\n";
echo "  testcaseA=$tcA testcaseB=$tcB (Refs #1790, wrong-node-type axis)\n";
echo "  user sm1759a=$uid (role $roleId: mgt_modify_tc on project $idA only)\n";
echo "  user sm1759norights=$uidNo (role 3 <no rights>)\n";
echo "  user sm1759view=$uidV (role $roleView: mgt_view_tc only, on project $idA)\n";
