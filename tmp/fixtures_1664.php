<?php
// Fixture for #1664 (usersAssignPlan.html role select must pre-select the
// EFFECTIVE plan role, incl. TL_ROLES_NO_RIGHTS / id 3 on a PRIVATE plan).
//
// Creates public tproject AN1664 with two plans:
//   AN1664-PUBLIC   (is_public = 1)
//   AN1664-PRIVATE  (is_public = 0)
// plus users: designer (global role 4, no plan role), guest (5), tester (7),
// leader (9, explicit plan role 6) and an inactive one.
//
// On the PRIVATE plan a non-admin user without an explicit plan role gets
// is_inherited = 0 + effective_role_id = 3 (<no rights>), so the legacy
// $applySelected rule pre-selects the id-3 option.
//
// Run from repo root: php tmp/fixtures_1664.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tplanMgr = new testplan($db);

foreach ((array)$tprojMgr->get_by_name('AN1664') as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'AN1664';
$item->prefix = 'NM1664';
$item->notes = 'fixture for issue 1664 (usersAssignPlan effective-role preselect)';
$item->color = '';
$item->active = 1;
$item->is_public = 1;
$opts = new stdClass();
$opts->requirementsEnabled = 0;
$opts->testPriorityEnabled = 1;
$opts->automationEnabled = 1;
$opts->inventoryEnabled = 0;
$opts->platformsEnabled = 0;
$opts->testcasecfEnabled = 0;
$opts->requirementcfEnabled = 0;
$item->options = $opts;
$idP = intval($tprojMgr->create($item));
$tprojMgr->setActive($idP);
echo "tproject=$idP\n";

$idPub = intval($tplanMgr->create('AN1664-PUBLIC', 'public plan', $idP, 1, 1));
$idPriv = intval($tplanMgr->create('AN1664-PRIVATE', 'private plan', $idP, 1, 0));
echo "tplan_public=$idPub tplan_private=$idPriv\n";

$tables = tlObject::getDBTables('users');
$h = password_hash('admin', PASSWORD_BCRYPT);
$fixtureUsers = [
    ['an1664designer', 'Dana', 'Designer', 4, 1],
    ['an1664guest',    'Gus',  'Guest',    5, 1],
    ['an1664tester',   'Tina', 'Tester',   7, 1],
    ['an1664leader',   'Lars', 'Leader',   9, 1],
    ['an1664dormant',  'Dorm', 'Dormant',  7, 0],
];
foreach ($fixtureUsers as $u) {
    list($login, $first, $last, $roleId, $active) = $u;
    $ex = $db->get_recordset("SELECT id FROM {$tables['users']} WHERE login='{$login}'");
    if ($ex) {
        $uid = intval($ex[0]['id']);
    } else {
        $cs = hash('sha256', uniqid('f1664', true));
        $db->exec_query("INSERT INTO {$tables['users']} " .
            "(login,password,email,first,last,locale,role_id,active,cookie_string,auth_method) " .
            "VALUES ('{$login}','{$h}','{$login}@localhost','{$first}','{$last}'," .
            "'en_GB',{$roleId},{$active},'{$cs}','')");
        $uid = intval($db->insert_id());
    }
    echo "user {$login} id={$uid} role={$roleId} active={$active}\n";
    // an1664leader gets an EXPLICIT plan role (id 6 = "tester" by name in the
    // stock role set? use role id 6) on BOTH plans so the explicit branch is
    // covered too.
    if ($login === 'an1664leader') {
        foreach ([$idPub, $idPriv] as $pid) {
            $db->exec_query("DELETE FROM user_testplan_roles WHERE testplan_id={$pid} AND user_id={$uid}");
            $db->exec_query("INSERT INTO user_testplan_roles (testplan_id,user_id,role_id) VALUES ({$pid},{$uid},6)");
        }
    }
}

echo "DONE\n";
