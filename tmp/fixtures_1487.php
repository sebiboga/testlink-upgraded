<?php
// Fixture for #1487 browser testing: Custom Fields Exchange (cfieldsExchange).
// Creates a project + root suite + a TC + test plan + build so the screen gets
// real app context, plus TWO system-level custom fields (cf_ticket string,
// cf_priority string w/ possible_values) exported by the Exchange screen.
// Import exercises (valid / duplicate / bad XML / no file) reuse this base.
// Run from repo root: php tmp/fixtures_1487.php
require_once('config.inc.php');
require_once('common.php');
require_once('exec.inc.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tsuiteMgr = new testsuite($db);
$tcaseMgr = new testcase($db);
$tplanMgr = new testplan($db);
$buildMgr = new build($db);
$cfieldMgr = new cfield_mgr($db);

function fid1487($r) {
    if (is_array($r)) {
        if (isset($r['id'])) { return intval($r['id']); }
        $k = array_keys($r);
        return intval($k[0]);
    }
    return intval($r);
}

// --- idempotent cleanup ---
foreach ((array)$tprojMgr->get_by_name('CFX1487') as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}
// remove previously created fixture custom fields (name-based)
foreach (array('cf_ticket', 'cf_priority', 'cf_owner2') as $cfname) {
    $existing = $cfieldMgr->get_by_name($cfname);
    if (!empty($existing)) {
        foreach ((array)$existing as $cfrow) {
            $fid = intval(is_array($cfrow) ? ($cfrow['id'] ?? 0) : $cfrow);
            if ($fid > 0) {
                echo "deleting old custom field $cfname ($fid)\n";
                $db->exec_query("DELETE FROM cfield_node_types WHERE field_id=$fid");
                $db->exec_query("DELETE FROM custom_fields WHERE id=$fid");
            }
        }
    }
}

$item = new stdClass();
$item->name = 'CFX1487';
$item->prefix = 'C487';
$item->notes = 'fixture for issue 1487 (Custom Fields Exchange)';
$item->color = '';
$item->active = 1;
$item->is_public = 1;
$opts = new stdClass();
$opts->requirementsEnabled = 0;
$opts->testPriorityEnabled = 1;
$opts->automationEnabled = 0;
$opts->inventoryEnabled = 0;
$opts->platformsEnabled = 0;
$item->options = $opts;
$idP = fid1487($tprojMgr->create($item));
echo "tproject=$idP\n";

$idS = fid1487($tsuiteMgr->create($idP, 'CFX1487 Suite', 'root suite for 1487', null, null, 1));

// --- a test case (tcversion) so linking CFs has a target subject ---
$steps = array(
    array('step_number' => 1, 'actions' => 'do something',
          'expected_results' => 'all good', 'execution_type' => TESTCASE_EXECUTION_TYPE_MANUAL),
);
$tcData = array();
$idT = fid1487($tcaseMgr->create($idS, 'CFX TC1', 'exchange fixture test case', '', $steps, 1));
echo "tcase=$idT\n";
$rowsT = $tcaseMgr->get_by_id($idT);
$tcversionID = isset($rowsT[0]['id']) ? intval($rowsT[0]['id']) : 0;

// --- test plan + build ---
$idTP = fid1487($tplanMgr->create('CFX1487 Plan', 'plan for exchange fixture', $idP, 1, 1));
echo "tplan_create=$idTP\n";
$idTP = (int)$idTP > 0 ? $idTP : fid1487($tplanMgr->get_by_name('CFX1487 Plan'));
// link TC to plan
$item2link = array(
    'tcversion' => array($idT => $tcversionID),
    'platform' => array(0 => 0),
    'items' => array($idT => array(0 => $tcversionID)),
);
$tplanMgr->link_tcversions($idTP, $item2link, 1);
$idB = fid1487($buildMgr->create($idTP, 'B-OPEN-1487', 'open build for exchange fixture', 1, 1, '', $idP));
echo "build=$idB tplan=$idTP\n";

// --- two system-level custom fields (export subjects) ---
$cfa = array(
    'name' => 'cf_ticket', 'label' => 'Ticket',
    'type' => 1, 'possible_values' => '',
    'show_on_design' => 1, 'enable_on_design' => 1,
    'show_on_testplan_design' => 1, 'enable_on_testplan_design' => 1,
    'show_on_execution' => 1, 'enable_on_execution' => 1,
    'node_type_id' => 3, // testcase
);
$r1 = $cfieldMgr->create($cfa);
echo "cf_ticket create: " . json_encode($r1) . "\n";

$cfb = array(
    'name' => 'cf_priority', 'label' => 'Priority',
    'type' => 1, 'possible_values' => 'low,med,high',
    'show_on_design' => 1, 'enable_on_design' => 1,
    'show_on_testplan_design' => 1, 'enable_on_testplan_design' => 1,
    'show_on_execution' => 1, 'enable_on_execution' => 1,
    'node_type_id' => 3, // testcase
);
$r2 = $cfieldMgr->create($cfb);
echo "cf_priority create: " . json_encode($r2) . "\n";

echo "DONE\n";