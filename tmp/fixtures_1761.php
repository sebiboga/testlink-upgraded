<?php
// Fixture for #1761 + #1762 (cross-project existence oracles in
// api/tcreorder and api/tcstepsreorder).
//
// Two PRIVATE test projects, each with two top level suites holding two test
// cases with three steps each, plus three users:
//
//   sm1761a        - custom project role with mgt_view_tc + mgt_modify_tc,
//                    granted on project A ONLY. This is the attacker: any
//                    answer that distinguishes "exists in project B" from
//                    "does not exist anywhere" is a cross-project existence +
//                    ownership oracle over the whole nodes_hierarchy id space.
//   sm1761view     - project role with mgt_view_tc + mgt_view_key (TWO rights on
//                    purpose: a project role holding EXACTLY ONE right is always
//                    denied by tlUser::hasRight()'s "special situation" branch,
//                    which would make a view-only user fail for the wrong
//                    reason). Lacks mgt_modify_tc, so it reaches the genuine
//                    insufficient-rights refusal on the write path.
//   sm1761norights - role 3 <no rights>, no project role at all: the worst
//                    case, and the caller the old code leaked the most to.
//
// Run from repo root: php tmp/fixtures_1761.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tsuiteMgr = new testsuite($db);
$tcaseMgr = new testcase($db);
$tblU = tlObject::getDBTables('users');
$adminId = 1;

// ---- re-runnable: drop a previous run --------------------------------------
foreach (array('OR1761A', 'OR1761B') as $nm) {
    foreach ((array)$tprojMgr->get_by_name($nm) as $row) {
        $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
        if ($oid > 0) {
            echo "deleting old project $oid ($nm)\n";
            $tprojMgr->delete($oid, 1);
        }
    }
}
$uRows = $db->get_recordset("SELECT id FROM {$tblU['users']} " .
    "WHERE login IN ('sm1761a','sm1761view','sm1761norights','sm1761designer')");
foreach ((array)$uRows as $u) {
    $uid = intval($u['id']);
    $db->exec_query("DELETE FROM user_testproject_roles WHERE user_id = $uid");
    $db->exec_query("DELETE FROM {$tblU['users']} WHERE id = $uid");
    echo "deleting old user $uid\n";
}
$db->exec_query("DELETE FROM role_rights WHERE role_id IN " .
    "(SELECT id FROM roles WHERE description IN ('OR1761 editor A','OR1761 viewer A'))");
$db->exec_query("DELETE FROM roles WHERE description IN ('OR1761 editor A','OR1761 viewer A')");

// ---- the two PRIVATE test projects ------------------------------------------
function makeProject($db, $tprojMgr, $name, $prefix)
{
    $item = new stdClass();
    $item->name = $name;
    $item->prefix = $prefix;
    $item->notes = 'fixture for #1761/#1762 (cross project existence leak)';
    $item->color = '';
    $item->active = 1;
    $item->is_public = 0;   // private: a global right must not reach it
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

$idA = makeProject($db, $tprojMgr, 'OR1761A', 'O1A');
$idB = makeProject($db, $tprojMgr, 'OR1761B', 'O1B');

// ---- suites + test cases with steps -----------------------------------------
function makeSuite($db, $suiteMgr, $projectId, $title)
{
    $op = $suiteMgr->create($projectId, $title, 'fixture #1761/#1762', null, 1);
    if (!is_array($op) || intval($op['id'] ?? 0) <= 0) {
        die("suite $title create failed: " . json_encode($op) . "\n");
    }
    return intval($op['id']);
}

function makeSteps($n)
{
    $steps = array();
    for ($i = 1; $i <= $n; $i++) {
        $t = new stdClass();
        $t->step_number = $i;
        $t->actions = "Do action number $i of " . $n;
        $t->expected_results = "Expected result of step $i";
        $t->execution_type = TESTCASE_EXECUTION_TYPE_MANUAL;
        $steps[] = $t;
    }
    return $steps;
}

$ids = array();
foreach (array('A' => $idA, 'B' => $idB) as $tag => $pid) {
    foreach (array(1, 2) as $n) {
        $suite = makeSuite($db, $tsuiteMgr, $pid, "$tag-suite-$n");
        foreach (array(1, 2) as $c) {
            $name = "$tag-tc-$n-$c";
            $ret = $tcaseMgr->create($suite, $name, "summary of $name", '',
                makeSteps(3), 1, '', testcase::DEFAULT_ORDER, testcase::AUTOMATIC_ID,
                TESTCASE_EXECUTION_TYPE_MANUAL);
            if (empty($ret['status_ok']) || intval($ret['id'] ?? 0) <= 0) {
                die("tcase $name create failed: " . json_encode($ret) . "\n");
            }
            $ids["suite$tag$n"] = $suite;
            $ids["tc$tag$n$c"] = intval($ret['id']);
            $ids["tcv$tag$n$c"] = intval($ret['tcversion_id']);
        }
    }
}

// ---- the project roles, granted on project A ONLY ---------------------------
function makeRole($db, $desc, $rights)
{
    $db->exec_query("INSERT INTO roles (description) VALUES ('$desc')");
    $roleId = intval($db->insert_id());
    foreach ($rights as $rn) {
        $rows = $db->get_recordset("SELECT id FROM rights WHERE description = '$rn'");
        if (empty($rows)) {
            die("right '$rn' missing\n");
        }
        $db->exec_query("INSERT INTO role_rights (role_id, right_id) VALUES ($roleId, " .
            intval($rows[0]['id']) . ")");
    }
    return $roleId;
}

$roleEdit = makeRole($db, 'OR1761 editor A', array('mgt_view_tc', 'mgt_modify_tc'));
$roleView = makeRole($db, 'OR1761 viewer A', array('mgt_view_tc', 'mgt_view_key'));

// ---- the users --------------------------------------------------------------
$h = password_hash('admin', PASSWORD_BCRYPT);

function makeUser($db, $tblU, $login, $hash, $roleId, $projectId = 0, $projectRole = 0)
{
    $cs = hash('sha256', uniqid($login, true));
    $db->exec_query("INSERT INTO {$tblU['users']} " .
        "(login,password,email,first,last,locale,role_id,active,cookie_string,auth_method) " .
        "VALUES ('$login','$hash','$login@localhost','$login','User','en_GB',$roleId,1,'$cs','')");
    $uid = intval($db->insert_id());
    if ($uid <= 0) {
        die("user $login create failed\n");
    }
    if ($projectId > 0 && $projectRole > 0) {
        $db->exec_query("INSERT INTO user_testproject_roles (user_id, testproject_id, role_id) " .
            "VALUES ($uid, $projectId, $projectRole)");
    }
    return $uid;
}

$uidA = makeUser($db, $tblU, 'sm1761a', $h, 3, $idA, $roleEdit);
$uidV = makeUser($db, $tblU, 'sm1761view', $h, 3, $idA, $roleView);
$uidN = makeUser($db, $tblU, 'sm1761norights', $h, 3);
// A user with the DEFAULT global role of a Test Designer (role_id 4) and no
// user_testproject_roles row at all. This one matters: tlUser::hasRight() does
// NOT deny an id that resolves to nothing, it falls through to the GLOBAL role
// and answers 'yes'. So for this user hasRight(right, <non-existent project>) is
// TRUE while hasRight(right, <existing private project with no role row>) is
// false. A fixture made only of global-<no rights> users can never see that
// asymmetry, which is exactly how the ?action=versions project-existence oracle
// slipped past the first 87-case run (cases S19-S21 were vacuous).
$uidD = makeUser($db, $tblU, 'sm1761designer', $h, 4);

if ($uidA <= 1 || $uidV <= 1 || $uidN <= 1 || $uidD <= 1) {
    die("REFUSING to continue: a fixture user got id <= 1 (the admin account)\n");
}

echo "DONE\n";
echo "FIXTURE_1761 tprojectA=$idA tprojectB=$idB\n";
foreach ($ids as $k => $v) {
    echo "  $k=$v\n";
}
echo "  roleEdit=$roleEdit roleView=$roleView\n";
echo "  sm1761a=$uidA sm1761view=$uidV sm1761norights=$uidN sm1761designer=$uidD\n";
