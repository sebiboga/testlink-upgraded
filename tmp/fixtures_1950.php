<?php
// Fixture for Apache SOLR integration screen (Refs #1950, parent #1087).
// Creates a public test project SOLR1950 and grants admin (id 1) the admin
// project role so the screen/BFF rights checks pass. Re-runnable.
// Run from repo root: php tmp/fixtures_1950.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tblTp = tlObject::getDBTables('testprojects');
$tblN = tlObject::getDBTables('nodes_hierarchy');
$tblR = tlObject::getDBTables('user_testproject_roles');

$rows = (array)$db->get_recordset(
    "SELECT p.id FROM {$tblTp['testprojects']} p JOIN {$tblN['nodes_hierarchy']} n ON n.id = p.id " .
    "WHERE n.name = 'SOLR1950' AND n.node_type_id = 1", -1, -1);
$idP = 0;
foreach ($rows as $r) {
    $idP = intval(is_array($r) ? ($r['id'] ?? 0) : $r);
    if ($idP > 0) { echo "reusing tproject=$idP\n"; break; }
}

if ($idP <= 0) {
    $item = new stdClass();
    $item->name = 'SOLR1950';
    $item->prefix = 'SLR';
    $item->notes = 'fixture for Apache SOLR integration screen (Refs #1950)';
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
    $tprojMgr->setActive($idP);
    echo "created tproject=$idP\n";
}

$db->exec_query("DELETE FROM {$tblR['user_testproject_roles']} " .
    "WHERE user_id = 1 AND testproject_id = " . intval($idP));
$db->exec_query("INSERT INTO {$tblR['user_testproject_roles']} " .
    "(user_id, testproject_id, role_id) VALUES (1, " . intval($idP) . ", 8)");
echo "admin role 8 granted on tproject=$idP\n";
echo "DONE tproject=$idP\n";
