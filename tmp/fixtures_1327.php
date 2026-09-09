<?php
// Fixture for #1327 browser testing: Test Case Version Compare context
// handling. Project + root suite + a TC with TWO versions whose content
// differs (summary / preconditions / steps) so the inline diff shows changes.
// Run from repo root: php tmp/fixtures_1327.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tsuiteMgr = new testsuite($db);
$tcaseMgr = new testcase($db);

// Reset on re-runs (repo rule: fixtures must be re-runnable).
foreach ((array)$tprojMgr->get_by_name('CMP1327') as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'CMP1327';
$item->prefix = 'C727';
$item->notes = 'fixture for issue 1327 (tcCompare context handling)';
$item->color = '';
$item->active = 1;
$item->is_public = 1;
$opts = new stdClass();
$opts->requirementsEnabled = 0;
$opts->testPriorityEnabled = 1;
$opts->automationEnabled = 0;
$opts->inventoryEnabled = 0;
$item->options = $opts;
$idP = $tprojMgr->create($item);
echo "tproject=$idP\n";

function firstId($r) {
    if (is_array($r)) {
        if (isset($r['id'])) { return intval($r['id']); }
        $k = array_keys($r);
        return intval($k[0]);
    }
    return intval($r);
}

$idS = firstId($tsuiteMgr->create($idP, 'CMP Suite', 'root suite for compare', null, null, 1));
echo "tsuite=$idS\n";

$stepsV1 = [
    ['step_number' => 1, 'actions' => 'login as admin', 'expected_results' => 'dashboard shown', 'execution_type' => TESTCASE_EXECUTION_TYPE_MANUAL],
    ['step_number' => 2, 'actions' => 'click Reports', 'expected_results' => 'report list opens', 'execution_type' => TESTCASE_EXECUTION_TYPE_MANUAL],
    ['step_number' => 3, 'actions' => 'old third step', 'expected_results' => 'old expect', 'execution_type' => TESTCASE_EXECUTION_TYPE_MANUAL],
];
$idT = firstId($tcaseMgr->create($idS, 'CMP Login Test', 'summary version one - initial',
    'preconditions version one', $stepsV1, 1));
echo "tc=$idT\n";

// Fetch the created tcversions to get v1 id.
$rows = $tcaseMgr->get_by_id($idT);
$v1Id = intval($rows[0]['id']);
echo "v1 tcversion_id=$v1Id version={$rows[0]['version']}\n";
$adminId = intval($_SESSION['userID'] ?? 1);
// Fallback: lookup admin user id directly.
if ($adminId <= 0) {
    $u = $db->fetchFirstRow("SELECT id FROM users WHERE login = 'admin'");
    $adminId = intval($u['id'] ?? 1);
}
echo "user=$adminId\n";

$ret = $tcaseMgr->create_new_version($idT, $adminId, $v1Id);
$v2Id = intval($ret['id']);
echo "v2 tcversion_id=$v2Id version={$ret['version']}\n";

$stepsV2 = [
    ['step_number' => 1, 'actions' => 'login as admin', 'expected_results' => 'dashboard shown', 'execution_type' => TESTCASE_EXECUTION_TYPE_MANUAL],
    ['step_number' => 2, 'actions' => 'click Reports then Execution', 'expected_results' => 'report list opens, execution submenu', 'execution_type' => TESTCASE_EXECUTION_TYPE_MANUAL],
    ['step_number' => 3, 'actions' => 'brand new third step', 'expected_results' => 'new expect', 'execution_type' => TESTCASE_EXECUTION_TYPE_MANUAL],
    ['step_number' => 4, 'actions' => 'extra fourth step', 'expected_results' => 'fourth result', 'execution_type' => TESTCASE_EXECUTION_TYPE_MANUAL],
];
$attr = array('status' => 1, 'estimatedExecDuration' => '');
$ret2 = $tcaseMgr->update($idT, $v2Id, 'CMP Login Test',
    'summary version two - edited', 'preconditions version two', $stepsV2,
    $adminId, '', testcase::DEFAULT_ORDER, TESTCASE_EXECUTION_TYPE_MANUAL, 2, $attr);
echo 'update v2: ' . (var_export($ret2['status_ok'] ?? null, true)) . "\n";

// Sanity: list versions as the compare BFF sees them.
$vrows = $tcaseMgr->get_by_id($idT);
foreach ($vrows as $v) {
    echo 'version ' . $v['version'] . " id={$v['id']} active=" . (isset($v['is_active']) ? $v['is_active'] : 'n/a') . " open={$v['is_open']}\n";
}
echo "FIXTURE_READY tproject=$idP tc=$idT v1=$v1Id v2=$v2Id\n";