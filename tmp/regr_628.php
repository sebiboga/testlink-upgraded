<?php
// Regression matrix for #628 fix.
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
        $k = array_keys($r); return intval($k[0]);
    }
    return intval($r);
}
function stepsOf($db, $tcid) {
    $t = tlObjectWithDB::getDBTables(['nodes_hierarchy','tcsteps']);
    $rs = $db->get_recordset(
        " SELECT S.step_number, S.execution_type FROM {$t['nodes_hierarchy']} NH" .
        " JOIN {$t['nodes_hierarchy']} NV ON NV.parent_id = NH.id" .   // tcversions
        " JOIN {$t['tcsteps']} S ON S.id IN (" .
        "   SELECT NS.id FROM {$t['nodes_hierarchy']} NS WHERE NS.parent_id = NV.id)" .
        " WHERE NH.id = " . intval($tcid) . " ORDER BY S.step_number");
    $out = [];
    foreach ((array)$rs as $r) { $out[] = $r; }
    return $out;
}

$pre = $db->get_recordset("SELECT COALESCE(MAX(id),0) AS mx FROM events");
$baseline = intval($pre[0]['mx']);

$old = $tprojMgr->get_by_name('REG628');
foreach ((array)$old as $row) {
    $oid = intval($row['id']);
    if ($oid > 0) { $tprojMgr->delete($oid, 1); }
}

$item = new stdClass();
$item->name = 'REG628'; $item->prefix = 'G62'; $item->notes = 'regression 628';
$item->color = ''; $item->active = 1; $item->is_public = 1;
$opts = new stdClass();
$opts->requirementsEnabled = 1; $opts->testPriorityEnabled = 1;
$opts->automationEnabled = 1; $opts->inventoryEnabled = 1;
$item->options = $opts;
$idP = $tprojMgr->create($item);
$idS = firstId($tsuiteMgr->create($idP, 'Suite G', 'details', null, null, 1));

// CASE 1: no execution_type key -> must default to 1 (MANUAL), no warnings
$c1 = firstId($tcaseMgr->create($idS, 'TC-missing-key', 's', 'p',
    [['step_number' => 1, 'actions' => 'a', 'expected_results' => 'e']], 1));

// CASE 2: explicit execution_type=2 (AUTOMATED) -> must be preserved
$c2 = firstId($tcaseMgr->create($idS, 'TC-explicit-2', 's', 'p',
    [['step_number' => 1, 'actions' => 'a', 'expected_results' => 'e',
      'execution_type' => 2]], 1));

// CASE 3: invalid value 99 -> create_step() sanitize keeps it 1 (unchanged behavior)
$c3 = firstId($tcaseMgr->create($idS, 'TC-invalid-99', 's', 'p',
    [['step_number' => 1, 'actions' => 'a', 'expected_results' => 'e',
      'execution_type' => 99]], 1));

echo "CASE1 tc=$c1 steps=" . json_encode(stepsOf($db, $c1)) . "\n";
echo "CASE2 tc=$c2 steps=" . json_encode(stepsOf($db, $c2)) . "\n";
echo "CASE3 tc=$c3 steps=" . json_encode(stepsOf($db, $c3)) . "\n";

$post = $db->get_recordset(
    "SELECT id, log_level, description FROM events " .
    "WHERE id > $baseline AND log_level <= 4 ORDER BY id");
if (count((array)$post) === 0) {
    echo "NO_ERROR_WARNING_EVENTS\n";
} else {
    foreach ((array)$post as $e) {
        echo "EVENT {$e['id']} level={$e['log_level']}: " .
             trim(str_replace("\n", ' | ', substr($e['description'],0,200))) . "\n";
    }
}
