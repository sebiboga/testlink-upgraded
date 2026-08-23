<?php
// Fixture for issue #427 browser testing:
//  - project RPT427 + plan Plan427A: NO baseline saved, NO platforms
//    (reproduces the E_WARNING foreach over null $rsu)
// Run from repo root: php tmp/fixtures_427.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tsuiteMgr = new testsuite($db);
$tcaseMgr = new testcase($db);
$tplanMgr = new testplan($db);

// idempotency: drop previous run's project if present
$old = $tprojMgr->get_by_name('RPT427');
foreach ((array)$old as $row) {
    $oid = intval($row['id']);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'RPT427';
$item->prefix = 'R427';
$item->notes = 'fixture for issue 427';
$item->color = '';
$item->active = 1;
$item->is_public = 1;
$opts = new stdClass();
$opts->requirementsEnabled = 0;
$opts->testPriorityEnabled = 0;
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

$idS1 = firstId($tsuiteMgr->create($idP, 'Suite A', 'details', null, null, 1));
$idTC1 = firstId($tcaseMgr->create($idS1, 'TC-a', 'summary', 'precond',
    [['step_number' => 1, 'actions' => 'do it', 'expected_results' => 'works']], 1));
echo "tsuite=$idS1 tcase=$idTC1\n";

$idTP = $tplanMgr->create('Plan427A', 'plan WITHOUT baseline', $idP, 1, 1);
echo "tplan=$idTP\n";

// sanity: plan must have zero platforms and zero baseline rows
$plat = $tplanMgr->getPlatforms($idTP, array('outputFormat' => 'map'));
echo "platformSet=" . var_export($plat, true) . "\n";
$tbl = tlObjectWithDB::getDBTables(['baseline_l1l2_context']);
$rs = $db->get_recordset("SELECT COUNT(*) AS c FROM {$tbl['baseline_l1l2_context']} WHERE testplan_id=" . intval($idTP));
echo "baseline_rows={$rs[0]['c']}\n";

echo "DONE tproject=$idP tplan=$idTP\n";
