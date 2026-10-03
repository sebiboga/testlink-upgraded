<?php
/**
 * Fixture for issue #1792 - api/builds: resolveTplan() answers 404 BEFORE
 * canManage() answers 403, so a user with no rights at all can enumerate every
 * test plan id in the instance by walking tplan_id upwards and reading the
 * 403/404 split.
 *
 * It creates its OWN isolated project + plan + build (never mutating tp1 or any
 * other suite's fixture) plus a `role_id = 3` (<no rights>) user, because the
 * oracle is only observable by a caller who is NOT entitled to the project: an
 * entitled caller always gets 200 and the split never shows.
 *
 * Idempotent: re-running reuses everything it finds. Prints one SUMMARY line
 * that tmp/verify_1792.php parses.
 *
 * Usage: php tmp/fixtures_1792.php
 */
require_once('config.inc.php');
require_once('common.php');
require_once('testproject.class.php');
require_once('testplan.class.php');
require_once('build.class.php');
require_once('tree.class.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$urow = $db->get_recordset("SELECT id FROM users WHERE login='admin' AND active=1");
$adminId = !empty($urow) ? intval($urow[0]['id']) : 0;
if ($adminId <= 0) {
    fwrite(STDERR, "FATAL: no 'admin' user\n");
    exit(2);
}

// ---------------------------------------------------------------- test project
$tprojMgr = new testproject($db);
$prefix = 'T1792';
$row = $db->get_recordset("SELECT id FROM testprojects WHERE prefix='$prefix'");
if (!empty($row)) {
    $pid = intval($row[0]['id']);
    echo "project $prefix already exists id=$pid\n";
} else {
    $item = new stdClass();
    $item->name = 'T1792 project - issue #1792';
    $item->prefix = $prefix;
    $item->notes = 'fixture for issue #1792 (api/builds tplan_id oracle)';
    $item->color = '';
    $item->active = 1;
    // private: a caller holding no role on it must not even learn the plan exists
    $item->is_public = 0;
    $item->reqmgrintegration = 0;
    $item->option_automation = 0;
    $item->option_priority = 0;
    $item->option_reqs = 0;
    $opts = new stdClass();
    $opts->requirementsEnabled = 0;
    $opts->testPriorityEnabled = 0;
    $opts->automationEnabled = 0;
    $pid = intval($tprojMgr->create($item, $opts));
    if ($pid <= 0) { fwrite(STDERR, "FATAL: could not create the test project\n"); exit(4); }
    echo "created project $prefix id=$pid\n";
}

// ------------------------------------------------------------------- test plan
$tplanMgr = new testplan($db);
$utp = tlObjectWithDB::getDBTables(array('testplans', 'nodes_hierarchy'));
$plans = $db->get_recordset(' SELECT testplans.id FROM ' . $utp['testplans'] . ' testplans, ' .
    $utp['nodes_hierarchy'] . " NH WHERE testplans.id = NH.id AND NH.parent_id=$pid AND NH.name='T1792-PLAN'");
if (!empty($plans)) {
    $planId = intval($plans[0]['id']);
    echo "plan T1792-PLAN already exists id=$planId\n";
} else {
    $pi = new stdClass();
    $pi->name = 'T1792-PLAN';
    $pi->notes = 'fixture #1792';
    $pi->active = 1;
    $pi->is_public = 0;
    $pi->testProjectID = $pid;
    $planId = intval($tplanMgr->createFromObject($pi, array('doChecks' => false)));
    if ($planId <= 0) { fwrite(STDERR, "FATAL: could not create the test plan\n"); exit(7); }
    echo "created plan T1792-PLAN id=$planId\n";
}

// ----------------------------------------------------------------------- build
$buildMgr = new build($db);
$brow = $db->get_recordset("SELECT id FROM builds WHERE testproject_id=$pid AND name='T1792-BUILD'");
if (!empty($brow)) {
    $buildId = intval($brow[0]['id']);
    echo "build T1792-BUILD already exists id=$buildId\n";
} else {
    $bi = new stdClass();
    $bi->name = 'T1792-BUILD';
    $bi->notes = 'fixture #1792';
    $bi->active = 1;
    $bi->is_open = 1;
    $bi->tplan_id = $planId;
    $bi->author_id = $adminId;
    $bi->creation_ts = date('Y-m-d H:i:s');
    $bi->release_date = null;
    $bi->tproject_id = $pid;
    $buildId = intval($buildMgr->createFromObject($bi));
    if ($buildId <= 0) { fwrite(STDERR, "FATAL: could not create the build\n"); exit(6); }
    echo "created build T1792-BUILD id=$buildId\n";
}

// ------------------------------------------------------- user with NO rights
// role_id = 3 is the global `<no rights>` role: zero rows in role_rights, and
// no user_testproject_roles row - i.e. "any logged-in user", the caller the
// report describes.
$tblU = tlObject::getDBTables('users');
$old = $db->get_recordset("SELECT id FROM {$tblU['users']} WHERE login='sm1792norights'");
if (!empty($old)) {
    $oldId = intval($old[0]['id']);
    $db->exec_query("DELETE FROM user_testproject_roles WHERE user_id=$oldId");
    $db->exec_query("DELETE FROM {$tblU['users']} WHERE id=$oldId");
    echo "deleting old user sm1792norights id=$oldId\n";
}
$h = password_hash('admin', PASSWORD_BCRYPT);
$cs = hash('sha256', uniqid('t1792', true));
$db->exec_query("INSERT INTO {$tblU['users']} " .
    "(login,password,email,first,last,locale,role_id,active,cookie_string,auth_method,default_testproject_id) " .
    "VALUES ('sm1792norights','$h','sm1792norights@localhost','No','Rights','en_GB',3,1,'$cs','',NULL)");
$uid = intval($db->insert_id());
if ($uid <= 0) { fwrite(STDERR, "FATAL: could not create the user\n"); exit(8); }
echo "created user sm1792norights id=$uid role_id=3\n";

// ------------------------------------------------------------------- assertion
// The oracle is only real if the caller truly lacks the right on the project.
$nrUser = tlUser::getByID($db, $uid);
$canManage = (bool)$nrUser->hasRight($db, 'testplan_create_build', $pid);
echo "no-rights user canManage(testproject $pid) = " . ($canManage ? 'TRUE' : 'false') .
     " (must be false or the bug hides)\n";
if ($canManage) {
    fwrite(STDERR, "FATAL: the no-rights user is entitled; fixture invalid\n");
    exit(3);
}
// ...and the plan must really exist, else both branches answer the same 404.
$nodeInfo = $tplanMgr->tree_manager->get_node_hierarchy_info(
    $planId, null, array('nodeType' => 'testplan'));
echo "plan $planId resolves as a testplan node: " .
     (is_null($nodeInfo) ? 'NO' : 'yes') . " (must be yes or the bug hides)\n";
if (is_null($nodeInfo)) {
    fwrite(STDERR, "FATAL: the plan node does not resolve; fixture invalid\n");
    exit(5);
}

echo "SUMMARY pid=$pid planId=$planId buildId=$buildId uid=$uid\n";
