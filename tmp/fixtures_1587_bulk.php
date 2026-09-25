<?php
// Bulk fixture for #1587: adds a suite with 210 test cases to the existing
// `AutoExec Demo` project so the 200-run truncation path of
// api/tcautoexec/index.php can be exercised. Run from repo root:
//   php tmp/fixtures_1587.php && php tmp/fixtures_1587_bulk.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tsuiteMgr = new testsuite($db);
$tcaseMgr = new testcase($db);

$proj = null;
foreach ((array)$tprojMgr->get_by_name('AutoExec Demo') as $row) {
    $proj = is_array($row) ? $row : null;
    if ($proj !== null) { break; }
}
if (empty($proj['id'])) {
    die("run tmp/fixtures_1587.php first\n");
}
$tid = intval($proj['testproject_id'] ?? $proj['id']);
$userId = 1;

// drop a previous bulk suite
foreach ((array)$tsuiteMgr->get_by_name('AX1587-BULK', $tid) as $s) {
    $sid = intval($s['id'] ?? 0);
    if ($sid > 0) { $tsuiteMgr->delete_deep($sid); }
}

$ret = $tsuiteMgr->create($tid, 'AX1587-BULK', '', null, 0, 'allow_repeat');
$bulk = intval($ret['id'] ?? 0);
if ($bulk <= 0) { die('suite create failed: ' . ($ret['message'] ?? '') . "\n"); }

$sub = $tsuiteMgr->create($bulk, 'AX1587-BULK-N', '', null, 0, 'allow_repeat');
$nested = intval($sub['id'] ?? 0);
$targets = array($bulk, $bulk, $bulk, $bulk, $bulk, $bulk, $bulk, $bulk, $bulk, $bulk, $nested);
// 210 cases spread over the suite AND its child (recursive collection)
for ($i = 1; $i <= 210; $i++) {
    $parent = $targets[($i - 1) % count($targets)];
    $steps = array();
    $steps[0] = new stdClass();
    $steps[0]->step_number = 1;
    $steps[0]->actions = 'bulk case ' . $i;
    $steps[0]->expected_results = 'ok';
    $steps[0]->execution_type = TESTCASE_EXECUTION_TYPE_MANUAL;
    $r = $tcaseMgr->create($parent, 'AX1587-B' . $i, 'bulk ' . $i, '', $steps, $userId,
                           '', testcase::DEFAULT_ORDER, testcase::AUTOMATIC_ID,
                           TESTCASE_EXECUTION_TYPE_MANUAL, 5);
    if (empty($r['status_ok'])) { die('tcase ' . $i . ' failed: ' . ($r['message'] ?? '') . "\n"); }
}
echo "project $tid bulk suite $bulk child $nested with 210 test cases\n";
