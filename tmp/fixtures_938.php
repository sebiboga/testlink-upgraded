<?php
// Fixture for issue #938 browser testing: Assign Test Plan Roles bulk
// 'Set roles to <role> / Do'. Creates a public test project + one active test
// plan plus a set of non-admin users with different global roles and a couple
// of explicit plan-role overrides, so the bulk setter has editable rows to hit
// (admin row remains locked/disabled).
// Run from repo root: php tmp/fixtures_938.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tblU = tlObject::getDBTables('users');

// Reset on re-runs (repo rule: fixtures must be re-runnable).
$existingP = $tprojMgr->get_by_name('PLANROLES938');
foreach ((array)$existingP as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}
foreach ($db->get_recordset("SELECT id FROM {$tblU['users']} WHERE login LIKE 'u938%'") as $u) {
    echo "deleting user " . $u['id'] . "\n";
    $db->exec_query("DELETE FROM {$tblU['users']} WHERE id = " . intval($u['id']));
}

$item = new stdClass();
$item->name = 'PLANROLES938';
$item->prefix = '938';
$item->notes = 'fixture for issue 938 (assign test plan roles; bulk set-roles-to)';
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
$globals = [4 => 'designer', 6 => 'senior', 7 => 'tester', 9 => 'leader'];
$uidMap = [];
$h = password_hash('admin', PASSWORD_BCRYPT);
foreach ($globals as $rid => $slug) {
    $login = 'u938' . $slug;
    $existing = $db->get_recordset("SELECT id FROM {$tblU['users']} WHERE login='$login'");
    if ($existing) {
        $uidMap[$slug] = intval($existing[0]['id']);
        echo "user $login exists id={$uidMap[$slug]}\n";
        continue;
    }
    $cs = hash('sha256', uniqid('t938', true));
    $db->exec_query("INSERT INTO {$tblU['users']} " .
        "(login,password,email,first,last,locale,role_id,active,cookie_string,auth_method) " .
        "VALUES ('$login','" . $h . "','$login@localhost','938','$slug'," .
        "'en_GB',$rid,1,'$cs','')");
    $uidMap[$slug] = intval($db->insert_id());
    echo "user $login id={$uidMap[$slug]} role=$rid\n";
}

// One active test plan (is_active=1, is_public=1).
$tplMgr = new testplan($db);
$plan = intval($tplMgr->create('PLAN938-R1', 'fixture plan for issue 938 (bulk set-roles-to)', $idP, 1, 1));
echo "tplan=$plan\n";

// Explicit plan-role overrides for two users so the rows show a non-0 roleID:
//   senior -- senior tester (6), tester -- tester (7).
$tblR = tlObject::getDBTables('user_testplan_roles');
foreach ([['senior', 6], ['tester', 7]] as $pair) {
    $uid = $uidMap[$pair[0]];
    $rid = $pair[1];
    $db->exec_query("DELETE FROM {$tblR['user_testplan_roles']} WHERE user_id=$uid AND testplan_id=$plan");
    $db->exec_query("INSERT INTO {$tblR['user_testplan_roles']} (user_id,testplan_id,role_id) VALUES ($uid,$plan,$rid)");
    echo "plan override user=$uid role=$rid\n";
}

echo "DONE\n";