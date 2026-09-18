<?php
// Fixture for issue #930 browser testing: Assign Test Project Roles pagination.
// Creates a public test project `PAGING` plus 25 regular (non-admin) users so
// the DataTables pagination (20/40/60/All) and "User" search box are
// exercisable. One of the users carries an inherited global role to exercise
// the value-0 "<inherited> <role>" select option under pagination.
// Run from repo root: php tmp/fixtures_930.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);

foreach ((array)$tprojMgr->get_by_name('PAGING') as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'PAGING';
$item->prefix = 'PG';
$item->notes = 'fixture for issue 930 (assign test project roles pagination)';
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
$r = $tprojMgr->create($item);
$idP = intval($r);
echo "tproject=$idP\n";
$tprojMgr->setActive($idP);

$tables = tlObject::getDBTables('users');
$h = password_hash('admin', PASSWORD_BCRYPT);
for ($n = 1; $n <= 25; $n++) {
    $login = sprintf('pager%02d', $n);
    $existing = $db->get_recordset("SELECT id FROM {$tables['users']} WHERE login='$login'");
    if ($existing) {
        echo "user $login already exists (id={$existing[0]['id']})\n";
        continue;
    }
    $cs = hash('sha256', uniqid('t930', true));
    // users run to 25k: <=20 inherit global role 9 (no explicit project role) so
    // they render with the "<inherited> <role>" value-0 option under pagination.
    $db->exec_query("INSERT INTO {$tables['users']} " .
        "(login,password,email,first,last,locale,role_id,active,cookie_string,auth_method) " .
        "VALUES ('$login','" . $h . "','$login@localhost','Page','Tester$n'," .
        "'en_GB',9,1,'" . $cs . "','')");
    $uid = intval($db->insert_id());
    echo "user $login id=$uid\n";
}

echo "DONE\n";