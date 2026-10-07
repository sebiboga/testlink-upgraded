<?php
// Fixture for issue #1262 — freeTestCases group-by / toolbar / filters / DESC sort.
// Creates tproject `FTC1262` (prefix `FTC`, priority enabled) with TWO suites
// (Alpha, Beta) and 4 test cases NOT linked to any test plan, with mixed
// importance, so the report has 2 groups x 2 rows to group/sort/filter.
// Run from repo root: php tmp/fixtures_1262.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$suiteMgr = new testsuite($db);
$userId = 1; // admin

foreach ((array)$tprojMgr->get_by_name('FTC1262') as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid);
    }
}

$item = new stdClass();
$item->name = 'FTC1262';
$item->prefix = 'FTC';
$item->notes = 'fixture for issue 1262 (freeTestCases ExtTable parity)';
$item->color = '';
$item->active = 1;
$item->is_public = 1;
$opts = new stdClass();
$opts->requirementsEnabled = 0;
$opts->testPriorityEnabled = 1; // importance column + filter
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

$mkSuite = function ($name) use ($suiteMgr, $idP) {
    $sid = $suiteMgr->create($idP, $name, 'suite for issue 1262');
    $sid = is_array($sid) && isset($sid['id']) ? intval($sid['id']) : intval($sid);
    if ($sid <= 0) { die("suite create failed for $name\n"); }
    return $sid;
};
$suiteA = $mkSuite('FTC Suite Alpha');
$suiteB = $mkSuite('FTC Suite Beta');
echo "suiteA=$suiteA suiteB=$suiteB\n";

$tc = new testcase($db);
$defs = array(
    // suite, name, summary, importance (cfg/const.inc.php: LOW=1 MEDIUM=2 HIGH=3)
    array($suiteA, 'FTC-1 Alpha login',   'Free case 1 in Alpha (high).',   HIGH),
    array($suiteA, 'FTC-2 Alpha logout',  'Free case 2 in Alpha (low).',    LOW),
    array($suiteB, 'FTC-3 Beta import',   'Free case 3 in Beta (high).',    HIGH),
    array($suiteB, 'FTC-4 Beta export',   'Free case 4 in Beta (medium).',  MEDIUM),
);
$created = [];
foreach ($defs as $def) {
    $idTC = $tc->create($def[0], $def[1], $def[2], '', '', $userId, '');
    $idTC = is_array($idTC) && isset($idTC['id']) ? intval($idTC['id']) : intval($idTC);
    if ($idTC <= 0) { die("tc create failed for {$def[1]}\n"); }
    $db->exec_query('UPDATE tcversions SET importance=' . intval($def[3]) .
                    ' WHERE id IN (SELECT id FROM nodes_hierarchy' .
                    ' WHERE parent_id=' . intval($idTC) . ')');
    $created[] = $idTC;
}
echo "testcases=" . implode(',', $created) . "\n";

file_put_contents('/tmp/fixture_1262.txt', json_encode(array(
    'tproject_id' => $idP,
    'suite_ids' => array($suiteA, $suiteB),
    'testcase_ids' => $created,
)));
echo "wrote /tmp/fixture_1262.txt\n";
echo "fixture ready: project $idP, 2 suites, " . count($created) . " free test cases\n";
