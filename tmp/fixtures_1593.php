<?php
// Fixture for issue #1593 (lib/functions/common.php:122 unguarded include_once
// of the not-shipped contoursoapInterface.class.php).
//
// Creates:
//  - reqmgrsystems row type=1 (contour/soap) => implementation contoursoapInterface
//  - a testproject with reqmgr_integration_enabled=1 LINKED to that system
//    (tlReqMgrSystem::link()), which is what makes
//    tlReqMgrSystem::getInterfaceObject() reachable unguarded from
//    reqSpecCommands::__construct().
//
// Run from repo root: php tmp/fixtures_1593.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$sysMgr = new tlReqMgrSystem($db);

foreach ((array)$tprojMgr->get_by_name('ContourDemo') as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}
$sysMgr->unlink(1, 1);
$tabs = tlObjectWithDB::getDBTables(array('reqmgrsystems','testprojects','testproject_reqmgrsystem'));
$db->exec_query("DELETE FROM {$tabs['reqmgrsystems']} WHERE name='Contour Demo'");

$item = new stdClass();
$item->name = 'ContourDemo';
$item->prefix = 'CD1593';
$item->notes = 'fixture for issue 1593 (reqmgr integration enabled + contour type)';
$item->color = '';
$item->active = 1;
$item->is_public = 1;
$opts = new stdClass();
$opts->requirementsEnabled = 1;
$opts->testPriorityEnabled = 0;
$opts->automationEnabled = 0;
$opts->inventoryEnabled = 0;
$idP = intval($tprojMgr->create($item, $opts));
echo "tproject=$idP\n";

$db->exec_query("UPDATE {$tabs['testprojects']} "
    . "SET reqmgr_integration_enabled=1 WHERE id=$idP");
echo "reqmgr_integration_enabled=1\n";

$sys = new stdClass();
$sys->name = 'Contour Demo';
$sys->type = 1;
$sys->cfg = '{}';
$retS = $sysMgr->create($sys);
$idS = intval($retS['id']);
echo "reqmgrsystem=$idS (type=1 => contoursoapInterface, NOT shipped)\n";

$sysMgr->link($idS, $idP);
$linked = $sysMgr->getLinkedTo($idP);
echo "linked_to=" . $linked['reqmgrsystem_name'] . " (reqmgrsystem_id={$linked['reqmgrsystem_id']})\n";
