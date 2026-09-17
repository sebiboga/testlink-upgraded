<?php
// Fixture for issue #927 browser testing: Assign Test Project Roles.
// Creates a public test project `CATALOG` plus a regular (non-admin) user
// `tester927`, so the screen has both a global-admin row (disabled select +
// hint) and a normal row (editable select).
// Run from repo root: php tmp/fixtures_927.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);

// Reset on re-runs (repo rule: fixtures must be re-runnable).
foreach ((array)$tprojMgr->get_by_name('CATALOG') as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'CATALOG';
$item->prefix = 'CA';
$item->notes = 'fixture for issue 927 (assign test project roles; admin lock)';
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

// Second active user with a NON-admin global role, so the modern screen has a
// fully editable row next to the locked admin row. Reuses admin's password hash
// (plaintext 'admin') so the account can be used for logins if needed.
$tables = tlObject::getDBTables('users');
$existing = $db->get_recordset("SELECT id FROM {$tables['users']} WHERE login='tester927'");
if ($existing) {
    echo "user tester927 already exists (id={$existing[0]['id']})\n";
} else {
    $h = password_hash('admin', PASSWORD_BCRYPT);
    $cs = hash('sha256', uniqid('t927', true));
    $db->exec_query("INSERT INTO {$tables['users']} " .
        "(login,password,email,first,last,locale,role_id,active,cookie_string,auth_method) " .
        "VALUES ('tester927','" . $h . "','tester927@localhost','927','Tester'," .
        "'en_GB',9,1,'" . $cs . "','')");
    $uid = intval($db->insert_id());
    echo "user tester927 id=$uid\n";
}

$tplMgr = new testplan($db);
$plan = $tplMgr->create('CATALOG-R1', 'fixture plan for issue 927', $idP, 1, 1);
echo "tplan id=$plan\n";

echo "DONE\n";