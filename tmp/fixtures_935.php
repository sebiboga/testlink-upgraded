<?php
// Fixture for issue #935 browser testing: access-rights check on the
// Assign Test Plan/Project Roles BFF read routes (api/roles/index.php
// meta/tplan-roles + meta/tproject-roles).
// Creates a public test project `ASSIGN` with one active test plan `RPlan`,
// plus a `guest1` user whose global role is `guest` (no assign rights) so the
// read routes can be exercised from a denied account (legacy parity: a guest
// is denied by lib/usermanagement/usersAssign.php checkRights()).
// Run from repo root: php tmp/fixtures_935.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);

// Reset on re-runs (repo rule: fixtures must be re-runnable).
foreach ((array)$tprojMgr->get_by_name('ASSIGN') as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'ASSIGN';
$item->prefix = 'AS';
$item->notes = 'fixture for issue 935 (assign roles read-routes rights check)';
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

$tplanMgr = new testplan($db);
$idT = intval($tplanMgr->create('RPlan', 'fixture plan for issue 935', $idP));
echo "tplan=$idT\n";

// guest1: global role `guest` (role_id 5) - no assign rights anywhere.
$tables = tlObject::getDBTables('users');
$existing = $db->get_recordset("SELECT id FROM {$tables['users']} WHERE login='guest1'");
if ($existing) {
    echo "user guest1 already exists (id={$existing[0]['id']})\n";
} else {
    $h = password_hash('admin', PASSWORD_BCRYPT);
    $cs = hash('sha256', uniqid('g935', true));
    $db->exec_query("INSERT INTO {$tables['users']} " .
        "(login,password,email,first,last,locale,role_id,active,cookie_string,auth_method) " .
        "VALUES ('guest1','" . $h . "','guest1@localhost','Guest','One'," .
        "'en_GB',5,1,'" . $cs . "','')");
    echo "user guest1 id=" . intval($db->insert_id()) . "\n";
}

echo "fixture ready: project $idP / plan $idT / guest1 (global role guest)\n";