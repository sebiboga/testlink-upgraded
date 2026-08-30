<?php
// Regression matrix for #629 fix: empty-steps create must not emit E_WARNING.
// Run from repo root: php tmp/regr_629.php  (expect zero level<=4 events)
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tsuiteMgr = new testsuite($db);
$tcaseMgr = new testcase($db);

function firstId($r) {
    if (is_array($r)) {
        if (isset($r['id'])) { return intval($r['id']); }
        $k = array_keys($r);
        return intval($k[0]);
    }
    return intval($r);
}

function stepRows($db, $tcase_id) {
    $r = $db->get_recordset(
        "SELECT s.id, s.step_number, s.execution_type, s.actions " .
        "FROM tcsteps s " .
        "JOIN nodes_hierarchy step_n ON step_n.id = s.id AND step_n.node_type_id = 9 " .
        "JOIN nodes_hierarchy ver_n ON ver_n.id = step_n.parent_id AND ver_n.node_type_id = 4 " .
        "WHERE ver_n.parent_id = " . intval($tcase_id) . " " .
        "ORDER BY s.step_number");
    return is_array($r) ? $r : [];
}

$pre = $db->get_recordset("SELECT COALESCE(MAX(id),0) AS mx FROM events");
$baseline = intval($pre[0]['mx']);
echo "baseline_event_id=$baseline\n";

$old = $tprojMgr->get_by_name('REG629');
foreach ((array)$old as $row) {
    $oid = intval($row['id']);
    if ($oid > 0) { echo "deleting old project $oid\n"; $tprojMgr->delete($oid, 1); }
}

$item = new stdClass();
$item->name = 'REG629';
$item->prefix = 'R63';
$item->notes = 'regression fixture for issue 629';
$item->color = '';
$item->active = 1;
$item->is_public = 1;
$opts = new stdClass();
$opts->requirementsEnabled = 1;
$opts->testPriorityEnabled = 1;
$opts->automationEnabled = 1;
$opts->inventoryEnabled = 1;
$item->options = $opts;
$idP = $tprojMgr->create($item);
echo "tproject=$idP\n";

$idS = firstId($tsuiteMgr->create($idP, 'Suite R', 'details', null, null, 1));
echo "tsuite=$idS\n";

$fail = 0;

// CASE 1: empty steps array
$t1 = firstId($tcaseMgr->create($idS, 'TC-empty', 'summary', 'precond', [], 1));
$s1 = stepRows($db, $t1);
echo "CASE1 empty-steps: tcase=$t1 step_rows=" . count($s1) . "\n";
if (count($s1) !== 0) { echo "  FAIL: expected 0 step rows\n"; $fail++; }

// CASE 2: steps key omitted (null)
$t2 = firstId($tcaseMgr->create($idS, 'TC-null', 'summary', 'precond', null, 1));
$s2 = stepRows($db, $t2);
echo "CASE2 null-steps: tcase=$t2 step_rows=" . count($s2) . "\n";

// CASE 3: non-empty steps with explicit execution_type (regression vs #628)
$t3 = firstId($tcaseMgr->create($idS, 'TC-steps', 'summary', 'precond',
    [['step_number' => 1, 'actions' => 'do it', 'expected_results' => 'works',
      'execution_type' => TESTCASE_EXECUTION_TYPE_MANUAL],
     ['step_number' => 2, 'actions' => 'again', 'expected_results' => 'ok',
      'execution_type' => 2]], 1));
$s3 = stepRows($db, $t3);
echo "CASE3 non-empty-steps: tcase=$t3 step_rows=" . count($s3) .
     " exec_types=" . implode(',', array_map(function($r){return $r['execution_type'];}, $s3)) . "\n";
if (count($s3) !== 2) { echo "  FAIL: expected 2 step rows\n"; $fail++; }

// events diff
$post = $db->get_recordset(
    "SELECT id, log_level, description FROM events WHERE id > $baseline ORDER BY id");
$bad = [];
foreach ((array)$post as $e) {
    if (intval($e['log_level']) <= 4) { $bad[] = "{$e['id']} level={$e['log_level']}: " . trim(substr($e['description'],0,120)); }
}
if (count($bad) === 0) {
    echo "NO_ERROR_WARNING_EVENTS\n";
} else {
    echo "FAIL: new level<=4 events:\n  " . implode("\n  ", $bad) . "\n";
    $fail++;
}

echo $fail === 0 ? "ALL_PASS\n" : "FAILURES=$fail\n";
exit($fail === 0 ? 0 : 1);