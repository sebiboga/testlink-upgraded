<?php
// Fixture for issue #946 browser testing: usersAssignGlobalRoleColoring row
// colour coding in Assign Test Plan Roles. Creates a public test project + one
// active test plan plus users with different GLOBAL roles (the $g_role_colour
// map in cfg/const.inc.php:536 keys), so the per-row login/name background can
// be verified visually (wheat = tester, #FFA = senior tester, cyan = test
// designer, pink = guest, white = admin, acqua(leader) = invalid CSS -> plain).
// Run from repo root: php tmp/fixtures_946.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tblU = tlObject::getDBTables('users');

// Reset on re-runs (repo rule: fixtures must be re-runnable).
$existingP = $tprojMgr->get_by_name('PLANROLES946');
foreach ((array)$existingP as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}
$uRows = $db->get_recordset("SELECT id FROM {$tblU['users']} WHERE login LIKE 'u946%'");
if ($uRows) {
    foreach ($uRows as $u) {
        echo "deleting user " . $u['id'] . "\n";
        $db->exec_query("DELETE FROM {$tblU['users']} WHERE id = " . intval($u['id']));
    }
}

$item = new stdClass();
$item->name = 'PLANROLES946';
$item->prefix = '946';
$item->notes = 'fixture for issue 946 (assign test plan roles; global-role colour coding)';
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

// Global roles mapped to $g_role_colour colours:
//   role 4 = test designer -> cyan, 5 = guest -> pink,
//   6 = senior tester -> #FFA, 7 = tester -> wheat, 9 = leader -> 'acqua'
//   (invalid CSS, legacy encodes it verbatim -> browsers ignore it, issue #946),
//   8 = admin -> white (visible as "no change" on a white grid),
//   3 = '<no rights>' -> grey (RAW description key; getDisplayName() would
//   rewrite it to '<no_rights>' and miss the map - parity bug caught in review).
$globals = [
    'tester' => 7,
    'senior' => 6,
    'designer' => 4,
    'guest' => 5,
    'leader' => 9,
    'norights' => 3,
];
$uidMap = [];
$h = password_hash('admin', PASSWORD_BCRYPT);
foreach ($globals as $slug => $rid) {
    $login = 'u946' . $slug;
    $existing = $db->get_recordset("SELECT id FROM {$tblU['users']} WHERE login='$login'");
    if ($existing) {
        $uidMap[$slug] = intval($existing[0]['id']);
        echo "user $login exists id={$uidMap[$slug]}\n";
        continue;
    }
    $cs = hash('sha256', uniqid('t946', true));
    $db->exec_query("INSERT INTO {$tblU['users']} " .
        "(login,password,email,first,last,locale,role_id,active,cookie_string,auth_method) " .
        "VALUES ('$login','" . $h . "','$login@localhost','946','$slug'," .
        "'en_GB',$rid,1,'$cs','')");
    $uidMap[$slug] = intval($db->insert_id());
    echo "user $login id={$uidMap[$slug]} role=$rid\n";
}

// One active test plan (active=1, is_public=1).
$tplMgr = new testplan($db);
$plan = intval($tplMgr->create('PLAN946-R1', 'fixture plan for issue 946 (global-role colour coding)', $idP, 1, 1));
echo "tplan=$plan\n";

// Project-level role assignments so the Inherited Role column shows real roles
// (colour coding depends on GLOBAL role, independent of the inherited one).
$tblR2 = tlObject::getDBTables('user_testproject_roles');
foreach ($globals as $slug => $rid) {
    $uid = $uidMap[$slug];
    $db->exec_query("DELETE FROM {$tblR2['user_testproject_roles']} WHERE user_id=$uid AND testproject_id=$idP");
    $db->exec_query("INSERT INTO {$tblR2['user_testproject_roles']} (user_id,testproject_id,role_id) VALUES ($uid,$idP,$rid)");
    echo "project role user=$uid role=$rid\n";
}

echo "DONE\n";