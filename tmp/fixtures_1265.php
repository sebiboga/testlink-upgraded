<?php
// Fixture for issue #1265 — generated-by-TestLink-on footer in freeTestCases.html.
// Creates tproject `FTC1265` (prefix `FTC`, priority enabled) with a suite and
// 3 test cases NOT linked to any test plan, so the free-test-cases report has
// rows to render (allfree = true).
// Run from repo root: php tmp/fixtures_1265.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$suiteMgr = new testsuite($db);
$userId = 1; // admin

foreach ((array)$tprojMgr->get_by_name('FTC1265') as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'FTC1265';
$item->prefix = 'FTC';
$item->notes = 'fixture for issue 1265 (freeTestCases generated-on footer)';
$item->color = '';
$item->active = 1;
$item->is_public = 1;
$opts = new stdClass();
$opts->requirementsEnabled = 0;
$opts->testPriorityEnabled = 1; // importance column in the report
$opts->automationEnabled = 0;
$opts->inventoryEnabled = 0;
$opts->platformsEnabled = 0;
$opts->testcasecfEnabled = 0;
$opts->requirementcfEnabled = 0;
$item->options = $opts;
$r = $tprojMgr->create($item);
$idP = intval($r);
echo "tproject=$idP\n";
$tprojMgr->setActive($idP);

$suiteId = $suiteMgr->create($idP, 'FTC Suite', 'suite for issue 1265');
$suiteId = is_array($suiteId) && isset($suiteId['id']) ? intval($suiteId['id']) : intval($suiteId);
echo "suite=$suiteId\n";

$tc = new testcase($db);
$created = [];
foreach (array(
    array('FTC Login check', 'Free case 1 — never assigned to a test plan.'),
    array('FTC Logout check', 'Free case 2 — never assigned to a test plan.'),
    array('FTC Profile update', 'Free case 3 — never assigned to a test plan.'),
) as $def) {
    $idTC = $tc->create($suiteId, $def[0], $def[1], '', '', $userId, '');
    $idTC = is_array($idTC) && isset($idTC['id']) ? intval($idTC['id']) : intval($idTC);
    if ($idTC <= 0) { die("tc create failed for {$def[0]}\n"); }
    $created[] = $idTC;
}
echo "testcases=" . implode(',', $created) . "\n";

file_put_contents('/tmp/fixture_1265.txt', json_encode(array(
    'tproject_id' => $idP,
    'suite_id' => $suiteId,
    'testcase_ids' => $created,
)));
echo "wrote /tmp/fixture_1265.txt\n";
echo "fixture ready: project $idP, suite $suiteId, " . count($created) . " free test cases\n";
