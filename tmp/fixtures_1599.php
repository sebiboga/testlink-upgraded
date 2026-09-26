<?php
// Fixture for #1599 browser testing: the modern keyword create/edit/create-and-link
// popup (gui/templates/keywords/keywordsEdit.html + api/keywordsedit/index.php).
// Creates test project `KW1599` (prefix KW1) with one suite, two test cases and a
// test plan, seeds one keyword already linked to the first test case version, and
// creates two users that exercise the legacy AND-mode rights gate:
//   kwviewonly - project role with mgt_view_key ONLY (no mgt_modify_key) -> 403
//   kwnorights - global role 3 (<no rights>) -> 403
// Re-runnable. Run from repo root: php tmp/fixtures_1599.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tplanMgr = new testplan($db);
$tcaseMgr = new testcase($db);
$tsuiteMgr = new testsuite($db);

$adminId = 1; // admin

// ---------------------------------------------------------------- reset ----
foreach ((array)$tprojMgr->get_by_name('KW1599') as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) {
        $tprojMgr->delete($oid, 1);
    }
}

// ---------------------------------------------------------------- project --
$item = new stdClass();
$item->name = 'KW1599';
$item->notes = 'fixture for #1599 keyword dialog';
$item->prefix = 'KW1';
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
$item->options = $opts;
$idP = intval($tprojMgr->create($item));
if ($idP <= 0) {
    die("project create failed\n");
}
$tprojMgr->setActive($idP);
echo "tproject={$idP} (prefix KW1)\n";

// ----------------------------------------------------------------- suite ---
$op = $tsuiteMgr->create($idP, 'KW1599 Suite', 'fixture suite for #1599');
if (!$op['status_ok'] || $op['id'] <= 0) {
    die('suite create failed: ' . $op['msg'] . "\n");
}
$idSuite = intval($op['id']);

// -------------------------------------------------------------- testcases --
function makeTc($tcaseMgr, $parent, $name, $order) {
    $steps = array();
    $steps[0] = new stdClass();
    $steps[0]->step_number = 1;
    $steps[0]->actions = 'fixture step action for #1599';
    $steps[0]->expected_results = 'fixture expected result';
    $steps[0]->execution_type = TESTCASE_EXECUTION_TYPE_MANUAL;
    $ret = $tcaseMgr->create($parent, $name, 'fixture tc for #1599', '', $steps, 1,
        '', $order, testcase::AUTOMATIC_ID, TESTCASE_EXECUTION_TYPE_MANUAL, 2);
    if (!$ret['status_ok'] || $ret['id'] <= 0) {
        die('tcase create failed: ' . $ret['message'] . "\n");
    }
    return array('id' => intval($ret['id']), 'tcversion_id' => intval($ret['tcversion_id']));
}

$tc1 = makeTc($tcaseMgr, $idSuite, 'Keyword dialog case 1', 1);
$tc2 = makeTc($tcaseMgr, $idSuite, 'Keyword dialog case 2', 2);
echo "suite={$idSuite} tc1={$tc1['id']}/{$tc1['tcversion_id']} tc2={$tc2['id']}/{$tc2['tcversion_id']}\n";

// ------------------------------------------------------------------- plan --
$idTp = intval($tplanMgr->create('KW1599 Plan', 'fixture plan for #1599', $idP));
echo "tplan={$idTp}\n";

// --------------------------------------------------------------- keywords --
$kwOp = $tprojMgr->addKeyword($idP, 'seeded', 'seed keyword for #1599');
$kwId = intval($kwOp['id'] ?? 0);
if ($kwId > 0) {
    $tcaseMgr->addKeywords($tc1['id'], $tc1['tcversion_id'], array($kwId));
    echo "keyword 'seeded' id={$kwId} linked to tcversion {$tc1['tcversion_id']}\n";
}

// ----------------------------------------------------------------- rights --
// project role holding mgt_view_key only -> the legacy AND-mode gate must deny
$roleT = tlObject::getDBTables('roles');
$roleId = 0;
$rr = $db->get_recordset("SELECT id FROM {$roleT['roles']} WHERE description = 'kw view only (1599)'");
if ($rr && count($rr)) {
    $roleId = intval($rr[0]['id']);
} else {
    $db->exec_query("INSERT INTO {$roleT['roles']} (description, notes) VALUES " .
        "('kw view only (1599)', 'fixture for #1599: mgt_view_key only')");
    $rr = $db->get_recordset("SELECT id FROM {$roleT['roles']} WHERE description = 'kw view only (1599)'");
    $roleId = intval($rr[0]['id']);
}
$rightsT = tlObject::getDBTables('rights');
$rrt = $db->get_recordset("SELECT id FROM {$rightsT['rights']} WHERE description = 'mgt_view_key'");
$viewKeyRight = intval($rrt[0]['id']);
$roleRightsT = tlObject::getDBTables('role_rights');
$db->exec_query("DELETE FROM {$roleRightsT['role_rights']} WHERE role_id = {$roleId}");
$db->exec_query("INSERT INTO {$roleRightsT['role_rights']} (role_id, right_id) VALUES ({$roleId}, {$viewKeyRight})");

$usersT = tlObject::getDBTables('users');
$hash = password_hash('kw1599', PASSWORD_DEFAULT);
// global role must be '<no rights>' (3): tlUser::hasRight() applies the GLOBAL
// role's rights in EVERY project where the user has no project role, so a
// global 'test designer' would make every project-scoped assertion below
// pass for the wrong reason.
foreach (array('kwviewonly' => 3, 'kwnorights' => 3) as $login => $globalRole) {
    $db->exec_query("INSERT INTO {$usersT['users']} " .
        "(login,password,role_id,email,first,last,locale,default_testproject_id,active,cookie_string,auth_method) VALUES " .
        "('{$login}','" . $hash . "',{$globalRole},'{$login}@1599.no','K','W','en_GB',0,1,'ck_{$login}_1599','DB') " .
        "ON DUPLICATE KEY UPDATE password=VALUES(password), role_id=VALUES(role_id), active=1, auth_method=VALUES(auth_method)");
}
$urT = tlObject::getDBTables('user_testproject_roles');
foreach (array('kwviewonly' => $roleId, 'kwnorights' => 3) as $login => $projectRole) {
    $r = $db->get_recordset("SELECT id FROM {$usersT['users']} WHERE login = '{$login}'");
    $uid = intval($r[0]['id']);
    $db->exec_query("DELETE FROM {$urT['user_testproject_roles']} WHERE user_id = {$uid} AND testproject_id = {$idP}");
    $db->exec_query("INSERT INTO {$urT['user_testproject_roles']} (user_id, testproject_id, role_id) " .
        "VALUES ({$uid}, {$idP}, {$projectRole})");
    echo "user {$login} uid={$uid} project-role={$projectRole}\n";
}
echo "role 'kw view only (1599)' id={$roleId} (mgt_view_key only)\n";

echo "DONE project={$idP} plan={$idTp} tc={$tc1['id']}/{$tc1['tcversion_id']} kw={$kwId}\n";
