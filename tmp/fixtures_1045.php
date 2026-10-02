<?php
// Fixture for issue #1045 (tcView.html requirements section: legacy rights check).
//
// Creates the two PROJECTS the gap is about, each PRIVATE and each with
// requirements enabled, each holding the SAME spec / requirements / coverage:
//
//   REQ1045L  linked from  rl1045  -> project role with
//              mgt_view_tc + mgt_modify_tc + req_tcase_link_management
//              and DELIBERATELY NO mgt_view_req
//   REQ1045V  linked from  rv1045  -> project role with
//              mgt_view_tc + mgt_modify_tc + mgt_view_req (no linking right)
//
// Legacy tcView_viewer.tpl:513-515 renders the Requirements section for BOTH
// (view_req_rights == yes OR req_tcase_link_management); before the fix the
// modern BFF returned an empty `requirements` map for rl1045, so the section
// disappeared for the linker role although the DB rows were there.
//
// REQ-1045-1 gets a SECOND version and the coverage row points at that version,
// so the "Version N" column of the legacy list line is non-trivial (Version 2).
//
// Run from repo root: php tmp/fixtures_1045.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tblU = tlObject::getDBTables('users');
$userId = 1; // admin

function rm1045User($db, $tblU, $login)
{
    $rows = $db->get_recordset("SELECT id FROM {$tblU['users']} WHERE login = '$login'");
    foreach ((array)$rows as $u) {
        $uid = intval($u['id']);
        $db->exec_query("DELETE FROM user_testproject_roles WHERE user_id = $uid");
        $db->exec_query("DELETE FROM {$tblU['users']} WHERE id = $uid");
    }
}

// ---- re-runnable ------------------------------------------------------------
foreach (array('REQ1045L', 'REQ1045V') as $nm) {
    foreach ((array)$tprojMgr->get_by_name($nm) as $row) {
        $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
        if ($oid > 0) {
            echo "deleting old project $oid ($nm)\n";
            $tprojMgr->delete($oid, 1);
        }
    }
}
foreach (array('rl1045', 'rv1045') as $lg) {
    rm1045User($db, $tblU, $lg);
}
$db->exec_query("DELETE FROM role_rights WHERE role_id IN " .
    "(SELECT id FROM roles WHERE description LIKE 'REQ1045%')");
$db->exec_query("DELETE FROM roles WHERE description LIKE 'REQ1045%'");

function make1045Project($tprojMgr, $name, $prefix)
{
    $item = new stdClass();
    $item->name = $name;
    $item->prefix = $prefix;
    $item->notes = 'fixture for issue #1045 (requirements section rights)';
    $item->color = '';
    $item->active = 1;
    $item->is_public = 0;
    $opts = new stdClass();
    $opts->requirementsEnabled = 1;
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

function make1045Content($db, $userId, $idP, $tag)
{
    $suiteMgr = new testsuite($db);
    $tcMgr = new testcase($db);
    $reqSpecMgr = new requirement_spec_mgr($db);
    $reqMgr = new requirement_mgr($db);

    $op = $suiteMgr->create($idP, "Suite $tag", "fixture suite $tag");
    if (empty($op['status_ok']) || intval($op['id'] ?? 0) <= 0) {
        die("suite $tag failed: " . json_encode($op) . "\n");
    }
    $idS = intval($op['id']);

    $step = new stdClass();
    $step->step_number = 1;
    $step->actions = "Do the one action of $tag";
    $step->expected_results = 'Expected result of step 1';
    $step->execution_type = TESTCASE_EXECUTION_TYPE_MANUAL;
    $ret = $tcMgr->create($idS, "TC $tag login", "summary of $tag", '',
        array($step), 1, '', testcase::DEFAULT_ORDER, testcase::AUTOMATIC_ID,
        TESTCASE_EXECUTION_TYPE_MANUAL);
    if (empty($ret['status_ok']) || intval($ret['id'] ?? 0) <= 0) {
        die("testcase $tag failed: " . json_encode($ret) . "\n");
    }
    $idTC = intval($ret['id']);
    $idTCV = intval($ret['tcversion_id']);

    $op = $reqSpecMgr->create($idP, $idP, "RS-$tag", "Spec $tag", "spec $tag",
        3, $userId, TL_REQ_SPEC_TYPE_USER_REQ_SPEC);
    if (empty($op['status_ok']) || intval($op['id'] ?? 0) <= 0) {
        die("spec $tag failed: " . json_encode($op) . "\n");
    }
    $idSpec = intval($op['id']);

    $r1 = $reqMgr->create($idSpec, "REQ-$tag-1", 'First requirement',
        'scope of the first requirement', $userId, TL_REQ_STATUS_VALID,
        TL_REQ_TYPE_INFO, 1, 1, $idP);
    $r2 = $reqMgr->create($idSpec, "REQ-$tag-2", 'Second requirement',
        'scope of the second requirement', $userId, TL_REQ_STATUS_VALID,
        TL_REQ_TYPE_INFO, 1, 2, $idP);
    if (empty($r1['status_ok']) || empty($r2['status_ok'])) {
        die('req create failed: ' . json_encode(array($r1, $r2)) . "\n");
    }
    $idR1 = intval($r1['id']);
    $idR2 = intval($r2['id']);

    // second version of REQ-1 so the list line reads "Version 2"
    $reqMgr->create_new_version($idR1, $userId,
        array('log_message' => 'issue 1045 fixture: second version'));

    $tables = tlObject::getDBTables();
    $lrvi = $db->get_recordset(" SELECT req_id, req_version_id FROM latest_req_version_id" .
        " WHERE req_id IN ($idR1,$idR2)");
    $v1 = 0;
    $v2 = 0;
    foreach ((array)$lrvi as $row) {
        if (intval($row['req_id']) === $idR1) { $v1 = intval($row['req_version_id']); }
        if (intval($row['req_id']) === $idR2) { $v2 = intval($row['req_version_id']); }
    }
    if (!$v1 || !$v2) {
        die("req version ids not found for $tag\n");
    }
    $db->exec_query(" INSERT INTO {$tables['req_coverage']}" .
        " (req_id, req_version_id, testcase_id, tcversion_id, link_status, is_active, author_id)" .
        " VALUES ($idR1, $v1, $idTC, $idTCV, 1, 1, $userId)," .
        " ($idR2, $v2, $idTC, $idTCV, 1, 1, $userId)");

    return array('project' => $idP, 'suite' => $idS, 'tcase' => $idTC,
                 'tcversion' => $idTCV, 'spec' => $idSpec,
                 'req1' => $idR1, 'req1_version_id' => $v1,
                 'req2' => $idR2, 'req2_version_id' => $v2);
}

$L = make1045Content($db, $userId, make1045Project($tprojMgr, 'REQ1045L', 'R4L'), '1045L');
$V = make1045Content($db, $userId, make1045Project($tprojMgr, 'REQ1045V', 'R4V'), '1045V');
// negative control: requirements enabled, but the role holds NEITHER
// mgt_view_req NOR req_tcase_link_management -> legacy hides the section.
$N = make1045Content($db, $userId, make1045Project($tprojMgr, 'REQ1045N', 'R4N'), '1045N');
echo 'L: ' . json_encode($L) . "\n";
echo 'V: ' . json_encode($V) . "\n";
echo 'N: ' . json_encode($N) . "\n";

// ---- project roles ----------------------------------------------------------
function make1045Role($db, $description, $rights)
{
    $db->exec_query("INSERT INTO roles (description) VALUES ('$description')");
    $roleId = intval($db->insert_id());
    foreach ($rights as $rn) {
        $rows = $db->get_recordset("SELECT id FROM rights WHERE description = '$rn'");
        if (!$rows) {
            die("right '$rn' missing\n");
        }
        $db->exec_query("INSERT INTO role_rights (role_id, right_id) VALUES ($roleId, " .
            intval($rows[0]['id']) . ")");
    }
    return $roleId;
}

$roleLinker = make1045Role($db, 'REQ1045 linker', array(
    'mgt_view_tc', 'mgt_modify_tc', 'req_tcase_link_management', 'keyword_assignment'));
$roleViewer = make1045Role($db, 'REQ1045 reqviewer', array(
    'mgt_view_tc', 'mgt_modify_tc', 'mgt_view_req', 'keyword_assignment'));
$roleNone = make1045Role($db, 'REQ1045 noreq', array(
    'mgt_view_tc', 'mgt_modify_tc', 'keyword_assignment'));

function make1045User($db, $tblU, $login, $roleId)
{
    $hash = password_hash('admin', PASSWORD_DEFAULT);
    $db->exec_query("INSERT INTO {$tblU['users']} " .
        "(login,password,role_id,email,first,last,locale,default_testproject_id,active,cookie_string,auth_method) " .
        "VALUES ('$login','" . $hash . "',$roleId,'$login@example.org','Issue','1045','en_GB',0,1," .
        "MD5(CONCAT('ck1045','$login')),'DB')");
    return intval($db->insert_id());
}

$uidLinker = make1045User($db, $tblU, 'rl1045', $roleLinker);
$uidViewer = make1045User($db, $tblU, 'rv1045', $roleViewer);
$uidNone = make1045User($db, $tblU, 'rn1045', $roleNone);
$db->exec_query("INSERT INTO user_testproject_roles (user_id, testproject_id, role_id) VALUES " .
    "($uidLinker, {$L['project']}, $roleLinker)," .
    " ($uidViewer, {$V['project']}, $roleViewer)," .
    " ($uidNone, {$N['project']}, $roleNone)");

echo "roles: linker=$roleLinker viewer=$roleViewer none=$roleNone" .
    " users: rl1045=$uidLinker rv1045=$uidViewer rn1045=$uidNone\n";
echo "fixture ready\n";