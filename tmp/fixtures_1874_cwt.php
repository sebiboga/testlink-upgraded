<?php
// Fixture for CWT priority-badge repro (new bug, sibling of #1874).
// Links the 4 free TCs of project FTC1262 (id=1, priority enabled) to a
// NEW testplan + build WITHOUT executions and WITHOUT user_assignments, so
// getNotRunWOTesterAssigned() returns them -> cases_without_tester.
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);
$tplanMgr = new testplan($db);
$buildMgr = new build($db);
$userId = 1;

$idP = 1;
$name = 'CWT1874 Plan';
foreach ((array)$tplanMgr->get_by_name($name, $idP) as $row) {
    if (!empty($row['id']) && intval($row['id']) > 0) { $tplanMgr->delete_by_id(intval($row['id'])); }
}
$idPlan = intval($tplanMgr->create($name, 'fixture for CWT badge issue', $idP, 1, 1));
echo "testplan=$idPlan\n";
$idB = intval($buildMgr->create($idPlan, 'CWT Build 1', '', 1, 1, '', $idP));
echo "build=$idB\n";

$tcversions = array(5, 7, 9, 11);
$items = array(4 => array(0 => 5), 6 => array(0 => 7), 8 => array(0 => 9), 10 => array(0 => 11));
$toLink = array('tcversion' => $tcversions, 'items' => $items);
$tplanMgr->link_tcversions($idPlan, $toLink, $userId);
$rs = $db->get_recordset("SELECT COUNT(*) AS c FROM testplan_tcversions WHERE testplan_id=$idPlan");
echo "testplan_tcversions rows=" . intval($rs[0]['c'] ?? 0) . "\n";
echo "plan=$idPlan build=$idB\n";