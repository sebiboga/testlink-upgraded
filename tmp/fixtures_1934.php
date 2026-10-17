<?php
// Fixture for issue #1934 - exploratory sessions registry.
// Fresh DB each run: creates project EXPLOR1934 (prefix E34).
// The 403 path reuses tmp/mkuser_norights.php (user norights / role 3).
// Run from repo root: php tmp/fixtures_1934.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);
$tprojMgr = new testproject($db);

$old = (array)$tprojMgr->get_by_name('EXPLOR1934');
if (!empty($old)) {
    echo "project exists id=" . intval($old[0]['id'] ?? $old[0]) . "\n";
    exit(0);
}

$item = new stdClass();
$item->name = 'EXPLOR1934';
$item->prefix = 'E34';
$item->notes = 'fixture for issue 1934 (exploratory sessions registry)';
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
echo "tproject=$idP (prefix E34)\n";
