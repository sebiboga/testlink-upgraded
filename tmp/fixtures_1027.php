<?php
// Fixture for #1027 browser/curl testing: drag-and-drop reorder / move of
// requirement SPECIFICATIONS in the modern reqSpecMgmt screen
// (gui/templates/requirements/reqSpecMgmt.html + BFF api/reqspec/index.php,
// actions reorder_specs / move_spec).
//
// Creates tproject `REORDER1027` (prefix R1027) with
//   top level : R1027-A (3 requirements), R1027-B, R1027-C, R1027-D
//   nested    : R1027-A1, R1027-A2  (children of R1027-A)
// and two rights users:
//   ro1027norights  role 3 (<no rights>)
//   ro1027readonly  role 10 (custom role, mgt_view_req only)
// Run from repo root:  php tmp/fixtures_1027.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$reqSpecMgr = new requirement_spec_mgr($db);
$reqMgr = new requirement_mgr($db);
$tprojMgr = new testproject($db);
$userId = 1; // admin

// Reset on re-runs.
foreach ((array)$tprojMgr->get_by_name('REORDER1027') as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'REORDER1027';
$item->prefix = 'R1027';
$item->notes = 'fixture for issue 1027 (reqSpecMgmt spec reorder / move)';
$item->color = '';
$item->active = 1;
$item->is_public = 1;
$opts = new stdClass();
$opts->requirementsEnabled = 1;
$opts->testPriorityEnabled = 0;
$opts->automationEnabled = 0;
$opts->inventoryEnabled = 0;
$opts->platformsEnabled = 0;
$opts->testcasecfEnabled = 1;
$opts->requirementcfEnabled = 0;
$opts->testScriptEnabled = 0;
$item->options = $opts;
$idP = intval($tprojMgr->create($item));
if ($idP <= 0) {
    die("tproject create failed\n");
}
echo "tproject=$idP (prefix R1027)\n";
$tprojMgr->setActive($idP);

// specs: label => [parentSpecDocId|null, doc_id, title, scope]
$specDefs = array(
    'A'  => array(null, 'R1027-A',  'Spec A (3 requirements)', 'Top-level specification A.'),
    'B'  => array(null, 'R1027-B',  'Spec B',                 'Top-level specification B.'),
    'C'  => array(null, 'R1027-C',  'Spec C',                 'Top-level specification C.'),
    'D'  => array(null, 'R1027-D',  'Spec D',                 'Top-level specification D.'),
    'A1' => array('A',  'R1027-A1', 'Spec A1 (nested)',   'Child specification of A.'),
    'A2' => array('A',  'R1027-A2', 'Spec A2 (nested)',   'Child specification of A.'),
);
$specIds = array();
foreach ($specDefs as $key => $def) {
    $parent = ($def[0] === null) ? $idP : intval($specIds[$def[0]]);
    $op = $reqSpecMgr->create($idP, $parent, $def[1], $def[2], $def[3], 3, $userId,
                              TL_REQ_SPEC_TYPE_USER_REQ_SPEC);
    if (empty($op['status_ok']) || intval($op['id']) <= 0) {
        die("spec {$def[1]} create failed: " . (isset($op['msg']) ? $op['msg'] : '?') . "\n");
    }
    $specIds[$key] = intval($op['id']);
    echo "spec {$def[1]} = {$specIds[$key]} (parent node {$parent})\n";
}

// three requirements inside spec A
$reqDefs = array(
    array('R1027-1', 'Requirement 1 of A', 'Scope of R1027-1.', 0),
    array('R1027-2', 'Requirement 2 of A', 'Scope of R1027-2.', 1),
    array('R1027-3', 'Requirement 3 of A', 'Scope of R1027-3.', 2),
);
foreach ($reqDefs as $i => $def) {
    $op = $reqMgr->create($specIds['A'], $def[0], $def[1], $def[2], $userId,
                          TL_REQ_STATUS_VALID, TL_REQ_TYPE_INFO, 1, $i, $idP);
    if (empty($op['status_ok']) || intval($op['id']) <= 0) {
        die("req {$def[0]} create failed: " . (isset($op['msg']) ? $op['msg'] : '?') . "\n");
    }
    echo "req {$def[0]} = " . intval($op['id']) . "\n";
}

// rights users -------------------------------------------------------------
$hash = password_hash('admin', PASSWORD_DEFAULT);

// <no rights> role 3
$db->exec_query("INSERT INTO users (login,password,role_id,email,first,last,locale,"
    . "default_testproject_id,active,cookie_string,auth_method) "
    . "VALUES ('ro1027norights','" . $hash . "',3,'norights@tl.local','No','Rights',"
    . "'en_GB',0,1,'ck_ro1027norights','DB') "
    . "ON DUPLICATE KEY UPDATE password=VALUES(password), role_id=3, active=1, "
    . "auth_method=VALUES(auth_method), cookie_string=VALUES(cookie_string)");

// custom role 10: mgt_view_req only (no mgt_modify_req) -> read-only path
$roleId = intval($db->fetchOneValue("SELECT id FROM roles WHERE id = 10"));
if ($roleId <= 0) {
    $db->exec_query("INSERT INTO roles (id,description,notes) VALUES "
                    . "(10,'ro1027 readonly','fixture role for #1027')");
    $roleId = 10;
}
$db->exec_query("DELETE FROM role_rights WHERE role_id = " . intval($roleId));
$viewReq = intval($db->fetchOneValue(
    "SELECT id FROM rights WHERE description = 'mgt_view_req'"));
if ($viewReq > 0) {
    $db->exec_query("INSERT INTO role_rights (role_id,right_id) VALUES ("
                    . intval($roleId) . "," . $viewReq . ")");
}
echo "role {$roleId} rights: mgt_view_req=" . intval($viewReq) . " (no mgt_modify_req)\n";
$db->exec_query("INSERT INTO users (login,password,role_id,email,first,last,locale,"
    . "default_testproject_id,active,cookie_string,auth_method) "
    . "VALUES ('ro1027readonly','" . $hash . "',10,'readonly@tl.local','Read','Only',"
    . "'en_GB',0,1,'ck_ro1027readonly','DB') "
    . "ON DUPLICATE KEY UPDATE password=VALUES(password), role_id=10, active=1, "
    . "auth_method=VALUES(auth_method), cookie_string=VALUES(cookie_string)");

$projectRole = array('ro1027norights' => 3, 'ro1027readonly' => 10);
foreach ($projectRole as $login => $role) {
    $uid = intval($db->fetchOneValue("SELECT id FROM users WHERE login = '" . $login . "'"));
    if ($uid > 0) {
        $db->exec_query("INSERT INTO user_testproject_roles (user_id,testproject_id,role_id) "
            . "VALUES (" . $uid . "," . intval($idP) . "," . intval($role) . ") "
            . "ON DUPLICATE KEY UPDATE role_id=VALUES(role_id)");
        echo "user {$login} = {$uid} (project role {$role})\n";
    }
}

echo "fixture ready: project {$idP}; specs "
     . json_encode($specIds) . "\n";
echo "SPECS_JSON=" . json_encode($specIds) . " PROJECT={$idP}\n";
