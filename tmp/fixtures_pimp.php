<?php
// Fixture for Test Plan Import (planImport.html + api/planimport, Refs #1389)
// Run from repo root: php tmp/fixtures_pimp.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tsuiteMgr = new testsuite($db);
$tcaseMgr = new testcase($db);
$tplanMgr = new testplan($db);

function fid($r) {
    if (is_array($r)) {
        if (isset($r['id'])) { return intval($r['id']); }
        $k = array_keys($r);
        return intval($k[0]);
    }
    return intval($r);
}

// --- idempotent cleanup ---
$old = $tprojMgr->get_by_name('PIMP');
foreach ((array)$old as $row) {
    $o = intval($row['id']);
    if ($o > 0) { echo "deleting old PIMP $o\n"; $tprojMgr->delete($o, 1); }
}

$item = new stdClass();
$item->name = 'PIMP';
$item->prefix = 'PIMP';
$item->notes = 'Test Plan Import fixture';
$item->color = '';
$item->active = 1;
$item->is_public = 1;
$opts = new stdClass();
$opts->requirementsEnabled = 0;
$opts->testPriorityEnabled = 0;
$opts->automationEnabled = 0;
$opts->inventoryEnabled = 0;
$item->options = $opts;
$idP = fid($tprojMgr->create($item));
echo "tproject=$idP\n";
$db->exec_query("UPDATE testprojects SET options='" .
    $db->prepare_string(serialize($opts)) . "' WHERE id=$idP");

$idS1 = fid($tsuiteMgr->create($idP, 'Import Suite', 'suite desc', null, null, 1));
echo "tsuite=$idS1\n";

// --- test cases: 2 TC, no plan link yet ---
$tcv = [];
$tcid = [];
foreach ([['Login', $idS1], ['Logout', $idS1], ['Settings', $idS1]] as $i => [$nm, $suit]) {
    $idTC = fid($tcaseMgr->create($suit, $nm, 'summary of ' . $nm, 'precond of ' . $nm,
        [['step_number' => 1, 'actions' => 'action ' . $nm,
          'expected_results' => 'expected ' . $nm]], 1));
    $rr = $db->get_recordset(
        " SELECT NH.id FROM nodes_hierarchy NH JOIN tcversions TV ON TV.id = NH.id" .
        " WHERE NH.parent_id = " . intval($idTC) . " AND TV.active = 1 ORDER BY TV.version");
    $tvid = intval($rr[0]['id']);
    $tcv[$nm] = $tvid;
    $tcid[$nm] = $idTC;
}
echo "tcase nodes: " . json_encode($tcid) . "\n";
echo "tcversion ids: " . json_encode($tcv) . "\n";

// --- one test plan, NOT linked to any TC ---
$idTP = fid($tplanMgr->create('PIMP-Plan', 'import target plan', $idP, 1, 1));
echo "tplan=$idTP\n";

// --- a second platform for mixed import ---
$db->exec_query("INSERT INTO platforms (name, notes, testproject_id, enable_on_design, enable_on_execution, is_open) VALUES ('PIMP-Android', 'android', $idP, 1, 1, 1)");
$platId = intval($db->get_recordset("SELECT MAX(id) AS id FROM platforms WHERE testproject_id = $idP")[0]['id']);
echo "platform=$platId\n";

file_put_contents('tmp/pimp_tpid.txt', "$idP\n$idTP\n$platId\n");
echo "ids written to tmp/pimp_tpid.txt\n";
echo "DONE\n";