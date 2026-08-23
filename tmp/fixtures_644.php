<?php
// Fixture for issue #644: project with one test case + one platform linked to
// its version, so that tcView renders platforms.inc.tpl.
// Run from repo root: php tmp/fixtures_644.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tsuiteMgr = new testsuite($db);
$tcaseMgr = new testcase($db);
$platMgr = new tlPlatform($db, $tprojMgr->db->db->database); // placeholder, replaced below

// idempotency: drop previous run's project if present
$old = $tprojMgr->get_by_name('LOC644');
foreach ((array)$old as $row) {
    $oid = intval($row['id']);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'LOC644';
$item->prefix = 'L644';
$item->notes = 'fixture for issue 644';
$item->color = '';
$item->active = 1;
$item->is_public = 1;
$opts = new stdClass();
$opts->requirementsEnabled = 0;
$opts->testPriorityEnabled = 0;
$opts->automationEnabled = 1;
$opts->inventoryEnabled = 1;
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

$idS1 = firstId($tsuiteMgr->create($idP, 'Suite 644', 'details', null, null, 1));
echo "tsuite=$idS1\n";

$idT = firstId($tcaseMgr->create($idS1, 'TC-644', 'summary', 'precond',
    [['step_number' => 1, 'actions' => 'do it', 'expected_results' => 'works']], 1));
$idTC = is_array($idT) ? $idT['id'] : $idT;
echo "tcase=$idTC\n";

$platMgr = new tlPlatform($db, $idP);
$platObj = new stdClass();
$platObj->name = 'PLAT-644';
$platObj->notes = 'platform for 644';
$platObj->enable_on_design = 1;
$platObj->enable_on_execution = 1;
$platOp = $platMgr->create($platObj);
$idPlat = intval($platOp['id']);
echo "platform=" . var_export($platOp, true) . "\n";

// get the active version id of the test case and link platform to it
$rs = $db->get_recordset("SELECT id FROM nodes_hierarchy " .
    "WHERE parent_id={$idTC} ORDER BY id DESC");
$vid = intval($rs[0]['id']);
echo "tcversion=$vid\n";
// link platform to the tc version (table testcase_platforms)
$sql = "INSERT INTO testcase_platforms " .
       "(testcase_id, tcversion_id, platform_id) VALUES ({$idTC},{$vid},{$idPlat})";
$db->exec_query($sql);
echo "linked platform $idPlat -> tcversion $vid\n";

echo "DONE tproject=$idP tcase=$idTC tcversion=$vid platform=$idPlat\n";
