<?php
// Fixture for issue #941 browser testing: Assign Test Plan Roles admin-role
// dropdown parity. Creates a public test project + one active test plan plus
// non-admin users with different global roles, explicit plan-role overrides for
// a few (incl. an ADMIN role-8 override for one user), so the per-user role
// selects can be verified (admin option only on the row whose CURRENT explicit
// plan role IS admin) and the bulk 'Set roles to' select (which legacy keeps
// listing the admin role).
// Run from repo root: php tmp/fixtures_941.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tblU = tlObject::getDBTables('users');

// Reset on re-runs (repo rule: fixtures must be re-runnable).
$existingP = $tprojMgr->get_by_name('PLANROLES941');
foreach ((array)$existingP as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}
$uRows = $db->get_recordset("SELECT id FROM {$tblU['users']} WHERE login LIKE 'u941%'");
if ($uRows) {
    foreach ($uRows as $u) {
        echo "deleting user " . $u['id'] . "\n";
        $db->exec_query("DELETE FROM {$tblU['users']} WHERE id = " . intval($u['id']));
    }
}

$item = new stdClass();
$item->name = 'PLANROLES941';
$item->prefix = '941';
$item->notes = 'fixture for issue 941 (assign test plan roles; admin dropdown parity)';
$item->color = '';
$item->active = 1;
$item->is_public = 1;
$opts = new stdClass();
$opts->requirementsEnabled = 0;
$opts->testPriorityEnabled = 0;
$opts->automationEnabled = 0;
$opts->inventoryEnabled = 0;
$opts->platformsEnabled = 0;
$item->options = $opts;
$idP = intval($tprojMgr->create($item));
echo "tproject=$idP\n";
$tprojMgr->setActive($idP);

// Non-admin global roles to vary inherited roles across rows.
//   role 4 = test designer, 6 = senior tester, 7 = tester, 9 = leader
$globals = [
    'designer' => 4,
    'senior' => 6,
    'tester' => 7,
    'leader' => 9,
    'adminpl' => 7, // global tester, but explicit PLAN role admin (8) -> row that must offer it
];
$uidMap = [];
$h = password_hash('admin', PASSWORD_BCRYPT);
foreach ($globals as $slug => $rid) {
    $login = 'u941' . $slug;
    $existing = $db->get_recordset("SELECT id FROM {$tblU['users']} WHERE login='$login'");
    if ($existing) {
        $uidMap[$slug] = intval($existing[0]['id']);
        echo "user $login exists id={$uidMap[$slug]}\n";
        continue;
    }
    $cs = hash('sha256', uniqid('t941', true));
    $db->exec_query("INSERT INTO {$tblU['users']} " .
        "(login,password,email,first,last,locale,role_id,active,cookie_string,auth_method) " .
        "VALUES ('$login','" . $h . "','$login@localhost','941','$slug'," .
        "'en_GB',$rid,1,'$cs','')");
    $uidMap[$slug] = intval($db->insert_id());
    echo "user $login id={$uidMap[$slug]} role=$rid\n";
}

// One active test plan (active=1, is_public=1).
$tplMgr = new testplan($db);
$plan = intval($tplMgr->create('PLAN941-R1', 'fixture plan for issue 941 (admin dropdown parity)', $idP, 1, 1));
echo "tplan=$plan\n";

// Explicit plan-role overrides:
//   senior -> senior tester (6), tester -> tester (7),
//   adminpl -> ADMIN (8): the ONLY row whose per-user select legitimately
//   offers the admin role (current explicit assignment, usersAssign.tpl:259-271).
$tblR = tlObject::getDBTables('user_testplan_roles');
foreach (['senior' => 6, 'tester' => 7, 'adminpl' => 8] as $slug => $rid) {
    $uid = $uidMap[$slug];
    $db->exec_query("DELETE FROM {$tblR['user_testplan_roles']} WHERE user_id=$uid AND testplan_id=$plan");
    $db->exec_query("INSERT INTO {$tblR['user_testplan_roles']} (user_id,testplan_id,role_id) VALUES ($uid,$plan,$rid)");
    echo "plan override user=$uid role=$rid\n";
}

// Project-level role assignments so the Inherited Role column shows real roles.
$tblR2 = tlObject::getDBTables('user_testproject_roles');
foreach (['designer' => 4, 'senior' => 6, 'tester' => 7, 'leader' => 9, 'adminpl' => 7] as $slug => $rid) {
    $uid = $uidMap[$slug];
    $db->exec_query("DELETE FROM {$tblR2['user_testproject_roles']} WHERE user_id=$uid AND testproject_id=$idP");
    $db->exec_query("INSERT INTO {$tblR2['user_testproject_roles']} (user_id,testproject_id,role_id) VALUES ($uid,$idP,$rid)");
    echo "project role user=$uid role=$rid\n";
}

echo "DONE\n";