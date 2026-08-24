<?php
/**
 * CLI fixture seeder for Results by Test Suite (#671) browser testing.
 * Creates: project RBS, plan, 2 platforms, 1 build, L1/L2(+L3) suites,
 * test cases linked on both platforms and a mixed execution history.
 */
if (PHP_SAPI !== 'cli') { die("cli only\n"); }
require __DIR__ . '/../config.inc.php';
require_once 'common.php';

$_SESSION['userID'] = 1;
$db = new database(DB_TYPE);
doDBConnect($db);

$adminId = 1;

// ---- project ------------------------------------------------------------
$tprojMgr = new testproject($db);
$item = new stdClass();
$item->name = 'RBS Demo';
$item->prefix = 'RBS';
$item->color = '';
$item->notes = 'Fixtures for Results by Test Suite testing (#671)';
$item->active = 1;
$item->is_public = 1;
$opt = new stdClass();
$opt->requirementsEnabled = 0;
$opt->testPriorityEnabled = 1;
$opt->automationEnabled = 0;
$opt->inventoryEnabled = 0;
$item->options = $opt;
$tprojectId = $tprojMgr->create($item, ['doChecks' => false]);
if (!is_numeric($tprojectId) || $tprojectId <= 0) { die("project create failed\n"); }
echo "tproject_id=$tprojectId\n";

// ---- plan + build -------------------------------------------------------
$tplanMgr = new testplan($db);
$tplanId = $tplanMgr->create('RBS Plan', '#671 fixture plan', $tprojectId, 1, 1);
echo "tplan_id=$tplanId\n";
$T = tlObject::getDBTables();
$db->exec_query("INSERT INTO {$T['builds']} (testplan_id,name,notes,active,is_open,author_id) " .
    "VALUES({$tplanId},'B1','first build',1,1,{$adminId})");
$buildId = $db->insert_id($T['builds']);
echo "build_id=$buildId\n";

// ---- platforms ----------------------------------------------------------
$platMgr = new tlPlatform($db, $tprojectId);
$platIds = [];
foreach (['Linux', 'Windows'] as $pn) {
    $op = $platMgr->create((object)[
        'name' => $pn, 'notes' => '',
        'enable_on_design' => 1, 'enable_on_execution' => 1, 'is_open' => 1]);
    if ($op['status'] != tl::OK) { die("platform create failed: $pn\n"); }
    $platIds[$pn] = intval($op['id']);
    $db->exec_query("INSERT INTO {$T['testplan_platforms']} " .
        "(testplan_id,platform_id,active) VALUES({$tplanId},{$platIds[$pn]},0)");
}
print_r($platIds);

// ---- suites -------------------------------------------------------------
$tsMgr = new testsuite($db);
function mkSuite(&$tsMgr, $parent, $name) {
    $ret = $tsMgr->create($parent, $name, '');
    $id = is_array($ret) ? (isset($ret['id']) ? $ret['id'] : current($ret)) : $ret;
    echo "suite $name => " . print_r($id, true) . "\n";
    return intval(is_array($id) ? (isset($id['id']) ? $id['id'] : 0) : $id);
}
$topAlpha   = mkSuite($tsMgr, $tprojectId, 'Top Alpha');
$alphaOne   = mkSuite($tsMgr, $topAlpha, 'Alpha One');
$alphaTwo   = mkSuite($tsMgr, $topAlpha, 'Alpha Two');
$topBeta    = mkSuite($tsMgr, $tprojectId, 'Top Beta');
$topGamma   = mkSuite($tsMgr, $tprojectId, 'Top Gamma');
$gammaMid   = mkSuite($tsMgr, $topGamma, 'G Mid');
$gammaLeaf  = mkSuite($tsMgr, $gammaMid, 'G Leaf');

// ---- test cases + link + executions -------------------------------------
$tcMgr = new testcase($db);

function mkTC(&$tcMgr, &$db, &$T, $suiteId, $name, $tplanId, $platIds, $buildId,
              $authorId, array $resultsPerPlatform) {
    $steps = [[
        'step_number' => 1,
        'actions' => "Do the thing ($name)",
        'expected_results' => 'It works',
        'execution_type' => TESTCASE_EXECUTION_TYPE_MANUAL,
    ]];
    $ret = $tcMgr->create($suiteId, $name, 'summary of ' . $name,
        '', $steps, $authorId);
    if (!$ret || empty($ret['id'])) { die("tc create failed: $name\n"); }
    $tcId = intval($ret['id']);
    $row = $db->fetchFirstRow(
        "SELECT tcv.id AS tcversion_id, tcv.version FROM {$T['tcversions']} tcv " .
        " JOIN {$T['nodes_hierarchy']} nh ON nh.id = tcv.id" .
        " WHERE nh.parent_id = {$tcId} ORDER BY tcv.id DESC LIMIT 1");
    $tcvId = intval($row['tcversion_id']);
    foreach ($platIds as $pname => $pid) {
        $db->exec_query("INSERT INTO {$T['testplan_tcversions']} " .
            "(testplan_id,tcversion_id,node_order,urgency,platform_id,author_id) " .
            "VALUES({$tplanId},{$tcvId},0,2,{$pid},{$authorId})");
        $status = isset($resultsPerPlatform[$pname]) ? $resultsPerPlatform[$pname] : null;
        if ($status !== null) {
            $safeStatus = substr(preg_replace('/[^a-z]/i', '', $status), 0, 1);
            $db->exec_query("INSERT INTO {$T['executions']} " .
                "(build_id,tester_id,execution_ts,status,testplan_id," .
                "tcversion_id,tcversion_number,platform_id,execution_type,notes) " .
                "VALUES({$buildId},{$authorId},NOW(),'{$safeStatus}',{$tplanId}," .
                "{$tcvId},1,{$pid},1,'seeded')");
        }
    }
    return $tcId;
}

// Alpha One: mixed
mkTC($tcMgr, $db, $T, $alphaOne, 'Alpha One TC A', $tplanId, $platIds, $buildId, $adminId,
     ['Linux' => 'passed', 'Windows' => 'failed']);
mkTC($tcMgr, $db, $T, $alphaOne, 'Alpha One TC B', $tplanId, $platIds, $buildId, $adminId,
     ['Linux' => 'passed', 'Windows' => 'blocked']);
mkTC($tcMgr, $db, $T, $alphaOne, 'Alpha One TC C', $tplanId, $platIds, $buildId, $adminId, []);
// Alpha Two: not run everywhere
mkTC($tcMgr, $db, $T, $alphaTwo, 'Alpha Two TC A', $tplanId, $platIds, $buildId, $adminId, []);
mkTC($tcMgr, $db, $T, $alphaTwo, 'Alpha Two TC B', $tplanId, $platIds, $buildId, $adminId,
     ['Linux' => 'passed', 'Windows' => null]);
// Top Beta leaf suite: cases directly under an L1
mkTC($tcMgr, $db, $T, $topBeta, 'Beta Leaf TC A', $tplanId, $platIds, $buildId, $adminId,
     ['Linux' => 'failed', 'Windows' => 'passed']);
// Gamma depth-3 chain: G Mid aggregates G Leaf
mkTC($tcMgr, $db, $T, $gammaLeaf, 'G Leaf TC A', $tplanId, $platIds, $buildId, $adminId,
     ['Linux' => 'passed', 'Windows' => 'not run' === '' ? '' : 'n']);
mkTC($tcMgr, $db, $T, $gammaMid, 'G Mid TC A', $tplanId, $platIds, $buildId, $adminId,
     ['Linux' => 'b', 'Windows' => 'p']);

echo "DONE tproject_id={$tprojectId} tplan_id={$tplanId}\n";
