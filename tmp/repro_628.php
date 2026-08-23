<?php
// Repro for #628: testcase::createVersion() E_WARNING "Undefined array key
// execution_type" at testcase.class.php:804 when steps omit execution_type.
// Run from repo root: php tmp/repro_628.php
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

// baseline: last event id before the repro calls
$pre = $db->get_recordset("SELECT COALESCE(MAX(id),0) AS mx FROM events");
$baseline = intval($pre[0]['mx']);
echo "baseline_event_id=$baseline\n";

$old = $tprojMgr->get_by_name('REP628');
foreach ((array)$old as $row) {
    $oid = intval($row['id']);
    if ($oid > 0) { echo "deleting old project $oid\n"; $tprojMgr->delete($oid, 1); }
}

$item = new stdClass();
$item->name = 'REP628';
$item->prefix = 'R62';
$item->notes = 'repro fixture for issue 628';
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

// THE CALL UNDER TEST: steps WITHOUT execution_type key
for ($i = 1; $i <= 2; $i++) {
    $idTC = firstId($tcaseMgr->create($idS, "TC-noexectype-$i", 'summary', 'precond',
        [['step_number' => 1, 'actions' => 'do it', 'expected_results' => 'works']], 1));
    echo "tcase=$idTC\n";
}

$post = $db->get_recordset(
    "SELECT id, log_level, transaction_id, source, description " .
    "FROM events WHERE id > $baseline ORDER BY id");
if (count((array)$post) === 0) {
    echo "NO_NEW_EVENTS\n";
} else {
    foreach ((array)$post as $e) {
        echo "EVENT {$e['id']} level={$e['log_level']} source={$e['source']}\n" .
             "  desc: " . trim(str_replace("\n", ' | ', substr($e['description'],0,300))) . "\n";
    }
}
