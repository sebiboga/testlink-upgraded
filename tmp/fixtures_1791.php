<?php
/**
 * Fixture for issue #1791 - build::getCustomFieldsValues() fatal in
 * lib/plan/planView.php and lib/plan/buildView.php.
 *
 * The fatal is only reachable when the LEGACY controller sees at least one
 * linked design-time custom field, because both controllers guard the call with
 *   $availableCF = (array)$tplan_mgr->get_linked_cfields_at_design(current($set), $tproject_id);
 *   $hasCF = count($availableCF);
 * and the removed method is only called inside `if ($hasCF)`. With no linked
 * field the legacy screen renders fine and the bug hides - which is exactly why
 * it survived the 2.0.1 modernization: it needs a test project that has a
 * design-time custom field on BOTH the testplan node type (5) and the build node
 * type (12).
 *
 * The stock fixture only has testplan-design fields (tp1/tp11: testplan=1,
 * build=0), so planView.php is live but buildView.php is NOT. This fixture
 * therefore creates its OWN isolated test project + plan + build + custom field
 * instead of mutating tp1/tp11, so no other suite's fixture state changes.
 *
 * Idempotent: re-running reuses everything it finds. It prints one SUMMARY line
 * that tmp/verify_1791.php parses.
 */
require_once('config.inc.php');
require_once('common.php');
require_once('testproject.class.php');
require_once('testplan.class.php');
require_once('build.class.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$urow = $db->get_recordset("SELECT id FROM users WHERE login='admin' AND active=1");
$adminId = !empty($urow) ? intval($urow[0]['id']) : 0;
if ($adminId <= 0) {
    fwrite(STDERR, "FATAL: no 'admin' user\n");
    exit(2);
}
$admin = tlUser::getByID($db, $adminId);

// ---------------------------------------------------------------- test project
$tprojMgr = new testproject($db);
$prefix = 'T1791';
$row = $db->get_recordset("SELECT id FROM testprojects WHERE prefix='$prefix'");
if (!empty($row)) {
    $pid = intval($row[0]['id']);
    echo "project $prefix already exists id=$pid\n";
} else {
    $item = new stdClass();
    $item->name = 'T1791 project - issue #1791';
    $item->prefix = $prefix;
    $item->notes = 'fixture for issue #1791 (planView/buildView legacy fatal)';
    $item->color = '';
    $item->active = 1;
    $item->is_public = 1;
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
    $utp['nodes_hierarchy'] . " NH WHERE testplans.id = NH.id AND NH.parent_id=$pid AND NH.name='T1791-PLAN'");
if (!empty($plans)) {
    $planId = intval($plans[0]['id']);
    echo "plan T1791-PLAN already exists id=$planId\n";
} else {
    $pi = new stdClass();
    $pi->name = 'T1791-PLAN';
    $pi->notes = 'fixture #1791';
    $pi->active = 1;
    $pi->is_public = 1;
    $pi->testProjectID = $pid;
    $planId = intval($tplanMgr->createFromObject($pi, array('doChecks' => false)));
    if ($planId <= 0) { fwrite(STDERR, "FATAL: could not create the test plan\n"); exit(7); }
    echo "created plan T1791-PLAN id=$planId\n";
}

// ----------------------------------------------------------------------- build
// Builds are PROJECT scoped since #503/#834: build::create() takes the plan id only
// to derive the project, and builds.testplan_id no longer exists. A build row is
// therefore found by (testproject_id, name).
$buildMgr = new build($db);
$brow = $db->get_recordset("SELECT id FROM builds WHERE testproject_id=$pid AND name='T1791-BUILD'");
if (!empty($brow)) {
    $buildId = intval($brow[0]['id']);
    echo "build T1791-BUILD already exists id=$buildId\n";
} else {
    $bi = new stdClass();
    $bi->name = 'T1791-BUILD';
    $bi->notes = 'fixture #1791';
    $bi->active = 1;
    $bi->is_open = 1;
    $bi->tplan_id = $planId;
    $bi->author_id = $adminId;
    $bi->creation_ts = date('Y-m-d H:i:s');
    $bi->release_date = null;
    $bi->tproject_id = $pid;
    $buildId = intval($buildMgr->createFromObject($bi));
    if ($buildId <= 0) { fwrite(STDERR, "FATAL: could not create the build\n"); exit(6); }
    echo "created build T1791-BUILD id=$buildId\n";
}

// --------------------------------------------------------- design custom field
// get_available_node_types() decodes testplan=5 and build=12, so those are the
// node_type_id values the legacy lookup joins on.
$cfName = 'T1791_role';
$crow = $db->get_recordset("SELECT id FROM custom_fields WHERE name='$cfName'");
if (!empty($crow)) {
    $cfId = intval($crow[0]['id']);
    echo "cfield $cfName already exists id=$cfId\n";
} else {
    $db->exec_query("INSERT INTO custom_fields
        (name,label,type,possible_values,default_value,valid_regexp,length_min,length_max,
         show_on_design,enable_on_design,show_on_execution,enable_on_execution,
         show_on_testplan_design,enable_on_testplan_design)
        VALUES ('$cfName','T1791 Role','0','','','',0,0,1,1,0,0,1,1)");
    $cfId = intval($db->insert_id());
    if ($cfId <= 0) { fwrite(STDERR, "FATAL: could not create the custom field\n"); exit(5); }
    echo "created cfield $cfName id=$cfId\n";
}

foreach (array(5, 12) as $nodeTypeId) {
    $have = $db->get_recordset("SELECT field_id FROM cfield_node_types WHERE field_id=$cfId AND node_type_id=$nodeTypeId");
    if (empty($have)) {
        $db->exec_query("INSERT INTO cfield_node_types (field_id,node_type_id) VALUES ($cfId,$nodeTypeId)");
        echo "linked cfield $cfId -> node type $nodeTypeId\n";
    }
}
$have = $db->get_recordset("SELECT field_id FROM cfield_testprojects WHERE field_id=$cfId AND testproject_id=$pid");
if (empty($have)) {
    $db->exec_query("INSERT INTO cfield_testprojects
        (field_id,testproject_id,display_order,location,active,required,required_on_design,required_on_execution,monitorable)
        VALUES ($cfId,$pid,0,1,1,0,0,0,0)");
    echo "linked cfield $cfId to project $pid\n";
}

// ----------------------------------------------------------------------- user
// The legacy planView.php takes the test project from $_SESSION['testprojectID']
// (lib/plan/planView.php init_args(), pre-fix) and IGNORES ?tproject_id=, and
// common.php:setSessionProject() picks the session project at LOGIN from the
// user's default_testproject_id. So reproducing its fatal needs a user whose
// default project is this fixture's project - otherwise the request silently
// renders whatever project the session already had and $hasCF stays 0.
$tblU = tlObject::getDBTables('users');
$old = $db->get_recordset("SELECT id FROM {$tblU['users']} WHERE login='t1791a'");
if (!empty($old)) {
    $oldId = intval($old[0]['id']);
    $db->exec_query("DELETE FROM user_testproject_roles WHERE user_id=$oldId");
    $db->exec_query("DELETE FROM {$tblU['users']} WHERE id=$oldId");
    echo "deleting old user t1791a id=$oldId\n";
}
$h = password_hash('admin', PASSWORD_BCRYPT);
$cs = hash('sha256', uniqid('t1791', true));
$db->exec_query("INSERT INTO {$tblU['users']} " .
    "(login,password,email,first,last,locale,role_id,active,cookie_string,auth_method,default_testproject_id) " .
    "VALUES ('t1791a','$h','t1791a@localhost','T1791','Admin','en_GB',8,1,'$cs','',$pid)");
$uid = intval($db->insert_id());
if ($uid <= 0) { fwrite(STDERR, "FATAL: could not create the user\n"); exit(8); }
$db->exec_query("INSERT INTO user_testproject_roles (user_id, testproject_id, role_id) VALUES ($uid,$pid,10)");
echo "created user t1791a id=$uid default_testproject_id=$pid\n";

// ------------------------------------------------------------------- assertion
require_once('tree.class.php');
require_once('cfield_mgr.class.php');
$cm = new cfield_mgr($db);
$forPlan = count((array)$cm->get_linked_cfields_at_design($pid, cfield_mgr::CF_ENABLED, null, 'testplan', $planId, 'id'));
$forBuild = count((array)$cm->get_linked_cfields_at_design($pid, cfield_mgr::CF_ENABLED, null, 'build', $buildId, 'id'));
echo "linked design cfields: testplan=$forPlan build=$forBuild (both must be > 0 or the legacy bug hides)\n";
if ($forPlan < 1 || $forBuild < 1) {
    fwrite(STDERR, "FATAL: fixture did not reach the buggy branch\n");
    exit(3);
}

echo "SUMMARY pid=$pid planId=$planId buildId=$buildId cfId=$cfId uid=$uid\n";