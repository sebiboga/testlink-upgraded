<?php
// Fixture for #1676 (usersAssignPlan.html Name column hides first/last names).
//
// Creates tproject `AN1676` (prefix NM1676) + a test plan + four active users
// with DISTINCT first/last names, so the "Login" and "Name" columns are clearly
// different values and a username_format that omits %first%/%last% visibly
// collapses them into a duplicate.
//
// Run from repo root: php tmp/fixtures_1676.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tplanMgr = new testplan($db);

foreach ((array)$tprojMgr->get_by_name('AN1676') as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'AN1676';
$item->prefix = 'NM1676';
$item->notes = 'fixture for issue 1676 (usersAssignPlan name column)';
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

$idPlan = intval($tplanMgr->create('AN1676PLAN', 'fixture plan for #1676', $idP, 1, 1));
echo "tplan=$idPlan\n";

$tables = tlObject::getDBTables('users');
$h = password_hash('admin', PASSWORD_BCRYPT);
$fixtureUsers = [
    ['an1676designer', 'Anna', 'Designer', 4],
    ['an1676leader',   'Lars', 'Leader',   9],
    ['an1676tester',   'Tina', 'Tester',   7],
    ['an1676guest',    'Gus',  'Guest',    5],
];
foreach ($fixtureUsers as $u) {
    list($login, $first, $last, $roleId) = $u;
    $ex = $db->get_recordset("SELECT id FROM {$tables['users']} WHERE login='{$login}'");
    if ($ex) {
        echo "user {$login} exists (id={$ex[0]['id']})\n";
        continue;
    }
    $cs = hash('sha256', uniqid('f1676', true));
    $db->exec_query("INSERT INTO {$tables['users']} " .
        "(login,password,email,first,last,locale,role_id,active,cookie_string,auth_method) " .
        "VALUES ('{$login}','{$h}','{$login}@localhost','{$first}','{$last}'," .
        "'en_GB',{$roleId},1,'{$cs}','')");
    echo "user {$login} id=" . intval($db->insert_id()) . "\n";
}

echo "DONE\n";
