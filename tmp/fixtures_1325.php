<?php
// Fixture for #1325 browser testing: suite-level TC export (testSpec.html +
// suiteView.html launchers). Project + root suite + sub-suite + TCs.
// Run from repo root: php tmp/fixtures_1325.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tsuiteMgr = new testsuite($db);
$tcaseMgr = new testcase($db);

$old = $tprojMgr->get_by_name('EXP1325');
foreach ((array)$old as $row) {
    $oid = intval($row['id']);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'EXP1325';
$item->prefix = 'E1325';
$item->notes = 'fixture for issue 1325 (suite-level export launcher)';
$item->color = '';
$item->active = 1;
$item->is_public = 1;
$opts = new stdClass();
$opts->requirementsEnabled = 1;
$opts->testPriorityEnabled = 1;
$opts->automationEnabled = 1;
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

$idS = firstId($tsuiteMgr->create($idP, 'EXP Suite', 'root suite for export', null, null, 1));
echo "tsuite=$idS\n";

$idSub = firstId($tsuiteMgr->create($idS, 'EXP Sub Suite', 'child suite', null, null, 1));
echo "subsuite=$idSub\n";

foreach ([['EXP Case A', 'case A summary'], ['EXP Case B', 'case B summary']] as [$nm, $sum]) {
    $idT = firstId($tcaseMgr->create($idS, $nm, $sum, 'precond ' . $nm,
        [['step_number' => 1, 'actions' => 'action ' . $nm,
          'expected_results' => 'expected ' . $nm]], 1));
    echo "tc in root: $idT\n";
}

foreach ([['EXP Case C', 'case C summary']] as [$nm, $sum]) {
    $idT = firstId($tcaseMgr->create($idSub, $nm, $sum, 'precond ' . $nm,
        [['step_number' => 1, 'actions' => 'action ' . $nm,
          'expected_results' => 'expected ' . $nm]], 1));
    echo "tc in sub: $idT\n";
}

echo "DONE tproject=$idP root=$idS sub=$idSub\n";