<?php
// Fixture for #1019: metricsDashboard show_test_plan_status per-status breakdown.
// Creates tproject `MD Demo` (prefix `MDD`) + test plan + build + 8 test cases,
// 4 of them executed with status passed / failed / blocked, the rest not_run.
// Run from repo root: php tmp/fixtures_1019.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$planMgr = new testplan($db);
$suiteMgr = new testsuite($db);
$userId = 1; // admin

foreach ((array)$tprojMgr->get_by_name('MD Demo') as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'MD Demo';
$item->prefix = 'MDD';
$item->notes = 'fixture for issue 1019 (metricsDashboard per-status breakdown)';
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
$idP = intval($r);
if ($idP <= 0) { die("tproject create failed\n"); }
echo "tproject=$idP\n";
$tprojMgr->setActive($idP);

$op = $planMgr->create('MD Demo Plan', 'plan for issue 1019', $idP, 1);
if (!$op || intval($op) <= 0) { die("plan create failed\n"); }
$idT = intval($op);
echo "tplan=$idT\n";
$planMgr->setActive($idT);

foreach ((array)$suiteMgr->get_by_name('MD Demo Suite') as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) { $suiteMgr->delete($oid, 1); }
}
$suiteId = $suiteMgr->create($idP, 'MD Demo Suite', 'suite for issue 1019');
$suiteId = is_array($suiteId) && isset($suiteId['id']) ? intval($suiteId['id']) : intval($suiteId);
echo "suite=$suiteId\n";

$buildMgr = new build($db);
$buildMgr->create($idT, 'MD Demo Build 1', 'build for issue 1019', 1, 1, '', $idP);
$tables = tlObjectWithDB::getDBTables(array('nodes_hierarchy', 'testplan_tcversions', 'executions', 'builds'));
// builds live in their own table since #834, there is no build node in nodes_hierarchy
$buildId = intval($db->fetchOneValue(
    " SELECT id FROM {$tables['builds']} WHERE testproject_id = " . intval($idP) .
    " ORDER BY id DESC LIMIT 1"));
echo "build=$buildId\n";
if ($buildId <= 0) { die("build create failed\n"); }

// 4 executed (passed x2, failed x1, blocked x1) + 4 not_run  =>  active = 8
// executions.status stores the 1-char CODE: config results.status_code[verbose] => code
$statusCode = config_get('results')['status_code'];
$plan = array('passed', 'passed', 'failed', 'blocked');
$tc = new testcase($db);
$linked = 0;
for ($i = 1; $i <= 8; $i++) {
    $op = $tc->create($suiteId, 'MD Demo TC' . $i, 'summary ' . $i, '', '', $userId, '');
    $idTC = intval(is_array($op) && isset($op['id']) ? $op['id'] : $op);
    if ($idTC <= 0) { die("tc create failed\n"); }
    $tcv = intval($db->fetchOneValue(
        " SELECT id FROM {$tables['nodes_hierarchy']} " .
        " WHERE parent_id = " . intval($idTC) . " AND node_type_id = 4 ORDER BY id DESC LIMIT 1"));
    if (!$tcv) { die("no tcversion for tc $idTC\n"); }
    $db->exec_query(
        " INSERT INTO {$tables['testplan_tcversions']} " .
        " (testplan_id, author_id, creation_ts, tcversion_id, platform_id) " .
        " VALUES (" . intval($idT) . ", {$userId}, " . $db->db_now() . ", " . intval($tcv) . ", 0)");
    $linked++;
    if ($i <= 4) {
        $db->exec_query(
            " INSERT INTO {$tables['executions']} " .
            " (build_id, tester_id, execution_ts, status, testplan_id, tcversion_id, " .
            "  tcversion_number, platform_id, execution_type, execution_duration) " .
            " VALUES (" . intval($buildId) . ", {$userId}, " . $db->db_now() . ", '" .
            $statusCode[$plan[$i - 1]] . "', " . intval($idT) . ", " . intval($tcv) . ", 1, 0, 1, NULL)");
    }
}
echo "linked=$linked (4 executed: 2 passed / 1 failed / 1 blocked, 4 not_run)\n";
echo "fixture ready: project $idP, plan $idT, build $buildId\n";
