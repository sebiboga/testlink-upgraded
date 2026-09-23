<?php
// Fixture for #1563: Metrics & Reports navigator screen (resultsNavigator).
// Creates tproject `RSNAV` (prefix `RSN`) + test plan + one test case + a build.
// Run from repo root: php tmp/fixtures_1563.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$planMgr = new testplan($db);
$suiteMgr = new testsuite($db);
$userId = 1; // admin

foreach ((array)$tprojMgr->get_by_name('RSNAV') as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'RSNAV';
$item->prefix = 'RSN';
$item->notes = 'fixture for issue 1563 (metrics & reports navigator)';
$item->color = '';
$item->active = 1;
$item->is_public = 1;
$opts = new stdClass();
$opts->requirementsEnabled = 1;
$opts->testPriorityEnabled = 0;
$opts->automationEnabled = 0;
$opts->inventoryEnabled = 0;
$opts->platformsEnabled = 0;
$opts->testcasecfEnabled = 1;
$opts->requirementcfEnabled = 1;
$item->options = $opts;
$r = $tprojMgr->create($item);
$idP = intval($r);
echo "tproject=$idP\n";
$tprojMgr->setActive($idP);

$args = new stdClass();
$args->name = 'RSNAV Plan';
$args->notes = 'plan for issue 1563';
$args->active = 1;
$args->is_open = 1;
$args->option_automation = 0;
$args->option_priority = 0;
$op = $planMgr->create($args->name, $args->notes, $idP, 1);
if (!$op || intval($op) <= 0) { die("plan create failed\n"); }
$idT = intval($op);
echo "tplan=$idT\n";
$planMgr->setActive($idT);

foreach ((array)$suiteMgr->get_by_name('RSNAV Suite') as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) { $suiteMgr->delete($oid, 1); }
}
$suiteId = $suiteMgr->create($idP, 'RSNAV Suite', 'suite for issue 1563');
$suiteId = is_array($suiteId) && isset($suiteId['id']) ? intval($suiteId['id']) : intval($suiteId);
echo "suite=$suiteId\n";
echo "building tc with parent $suiteId\n";

$tc = new testcase($db);
$op = $tc->create($suiteId,
                  'RSNAV TC', 'summary for issue 1563', '', '',
                  $userId, '');
$idTC = intval(is_array($op) && isset($op['id']) ? $op['id'] : $op);
if ($idTC <= 0) { die("tc create failed\n"); }
echo "tc=$idTC\n";

$buildMgr = new build($db);
$buildMgr->create($idT, 'RSNAV Build 1', 'build for issue 1563', 1, 1, '', $idP);
echo "build created\n";

// link the newest tcversion to the plan so reports produce the full list
$tables = tlObjectWithDB::getDBTables(array('nodes_hierarchy', 'testplan_tcversions'));
$tcv = $db->fetchOneValue(
    " SELECT id FROM {$tables['nodes_hierarchy']} " .
    " WHERE parent_id = " . intval($idTC) . " AND node_type_id = 4 ORDER BY id LIMIT 1");
if ($tcv) {
    $db->exec_query(
        " INSERT INTO {$tables['testplan_tcversions']} " .
        " (testplan_id, author_id, creation_ts, tcversion_id, platform_id) " .
        " VALUES (" . intval($idT) . ", {$userId}, " . $db->db_now() . ", " . intval($tcv) . ", 0)");
    echo "linked tcversion $tcv to plan $idT\n";
}

echo "fixture ready: project $idP, plan $idT, tc $idTC\n";