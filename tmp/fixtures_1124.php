<?php
// Fixture for #1124: buildsView edit modal closure-date render.
// Creates tproject `BVDemo` (prefix BVD) + test plan + 2 builds:
//   - `BVDemo Build Open`   -> is_open=1, closed_on_date NULL
//   - `BVDemo Build Closed` -> is_open=0, closed_on_date 2025-11-20
// Run from repo root: php tmp/fixtures_1124.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$planMgr = new testplan($db);
$userId = 1; // admin

foreach ((array)$tprojMgr->get_by_name('BVDemo') as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'BVDemo';
$item->prefix = 'BVD';
$item->notes = 'fixture for issue 1124 (buildsView closure date)';
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
$idP = intval($tprojMgr->create($item));
if ($idP <= 0) { die("tproject create failed\n"); }
echo "tproject=$idP\n";
$tprojMgr->setActive($idP);

$idT = intval($planMgr->create('BVDemo Plan', 'plan for issue 1124', $idP, 1));
if ($idT <= 0) { die("plan create failed\n"); }
echo "tplan=$idT\n";
$planMgr->setActive($idT);

$buildMgr = new build($db);
$buildMgr->create($idT, 'BVDemo Build Open', 'open build', 1, 1, '2025-10-01', $idP);
$buildMgr->create($idT, 'BVDemo Build Closed', 'closed build', 1, 1, '2025-09-15', $idP);

$tables = tlObjectWithDB::getDBTables(array('builds'));
// close the second build and stamp a historical closure date
$db->exec_query(
    " UPDATE {$tables['builds']} SET is_open = 0, closed_on_date = '2025-11-20'" .
    " WHERE testproject_id = " . intval($idP) .
    "   AND name = 'BVDemo Build Closed'");

$rows = (array)$db->fetchRowsIntoMap(
    " SELECT id, name, is_open, closed_on_date FROM {$tables['builds']}" .
    " WHERE testproject_id = " . intval($idP) . " ORDER BY id", 'id');
foreach ($rows as $r) {
    echo "build={$r['id']} name={$r['name']} is_open={$r['is_open']} closed_on_date={$r['closed_on_date']}\n";
}
echo "fixture ready: project $idP, plan $idT\n";
