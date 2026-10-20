<?php
// Fixture for #1946 browser/API testing: Webhook subscriptions manager
// (gui/templates/system/webhooks.html + api/webhooks/index.php).
//
// Creates TWO test projects so the BFF's cross-project guards can be
// exercised, and a role-3 user for the 403 path:
//
//   tproject WEB1 (prefix WH1)  -> the project under test
//   tproject WEB2 (prefix WH2)  -> foreign project (404 webhook_not_found
//                                  when an id of WEB1 is addressed at WEB2)
//   user whknorights (role 3)   -> the 403 path
//
// Run from repo root: php tmp/fixtures_1946.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$userId = 1; // admin

function dropProject($tprojMgr, $name)
{
    foreach ((array)$tprojMgr->get_by_name($name) as $row) {
        $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
        if ($oid > 0) {
            echo "deleting old project $name ($oid)\n";
            $tprojMgr->delete($oid, 1);
        }
    }
}
dropProject($tprojMgr, 'WEB1');
dropProject($tprojMgr, 'WEB2');

function makeProject($tprojMgr, $name, $prefix, $notes)
{
    $item = new stdClass();
    $item->name = $name;
    $item->prefix = $prefix;
    $item->notes = $notes;
    $item->color = '';
    $item->active = 1;
    $item->is_public = 1;
    $opts = new stdClass();
    $opts->requirementsEnabled = 0;
    $opts->testPriorityEnabled = 0;
    $opts->automationEnabled = 0;
    $opts->inventoryEnabled = 0;
    $opts->platformsEnabled = 0;
    $opts->testcasecfEnabled = 0;
    $opts->requirementcfEnabled = 0;
    $item->options = $opts;
    $r = $tprojMgr->create($item);
    $id = intval($r);
    if ($id <= 0) {
        die("tproject create failed for $name\n");
    }
    $tprojMgr->setActive($id);
    return $id;
}

$idP = makeProject($tprojMgr, 'WEB1', 'WH1', 'fixture for #1946 (webhooks)');
$idPAlt = makeProject($tprojMgr, 'WEB2', 'WH2', 'fixture for #1946 (foreign)');
echo "tproject WEB1=$idP  WEB2=$idPAlt\n";

// A user with NO rights at all (role 3) -> the 403 path.
$TBU = tlObjectWithDB::getDBTables(array('users'));
$hash = password_hash('whknorights', PASSWORD_DEFAULT);
$db->exec_query("INSERT INTO {$TBU['users']}
    (login, password, role_id, email, first, last, locale,
     default_testproject_id, active, cookie_string, auth_method)
  VALUES ('whknorights', '$hash', 3, 'n@no.no', 'No', 'Rights', 'en_GB', 0, 1,
          'ck_whknorights_1946', 'DB')
  ON DUPLICATE KEY UPDATE password=VALUES(password), role_id=3, active=1,
          auth_method=VALUES(auth_method)");
$rsU = $db->get_recordset("SELECT id,login,role_id FROM users WHERE login='whknorights'");
echo 'user whknorights: ' . json_encode($rsU) . "\n";

echo "DONE. ids: project=$idP foreignProject=$idPAlt\n";
