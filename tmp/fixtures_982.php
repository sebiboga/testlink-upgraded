<?php
// Fixture for #982 browser testing: Print Test Specification (printTestSpec.html).
// Project with 2 suites (one nested) + 4 test cases + test plan, so the
// suite tree and whole-project/`suite print paths are exercised.
// Run from repo root: php tmp/fixtures_982.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tsuiteMgr = new testsuite($db);
$tcaseMgr = new testcase($db);
$tplanMgr = new testplan($db);

// idempotency: drop previous run's project if present
$old = $tprojMgr->get_by_name('PTS982');
foreach ((array)$old as $row) {
    $oid = intval($row['id']);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'PTS982';
$item->prefix = 'P982';
$item->notes = 'fixture for issue 982 (print test spec)';
$item->color = '';
$item->active = 1;
$item->is_public = 1;
$opts = new stdClass();
$opts->requirementsEnabled = 0;
$opts->testPriorityEnabled = 1;
$opts->automationEnabled = 0;
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

$idS1 = firstId($tsuiteMgr->create($idP, 'PTS Suite Alpha', 'first suite', null, null, 1));
echo "suite_alpha=$idS1\n";
$idS1b = firstId($tsuiteMgr->create($idS1, 'PTS Suite Alpha.Sub', 'nested suite', null, null, 1));
echo "suite_alpha_sub=$idS1b\n";
$idS2 = firstId($tsuiteMgr->create($idP, 'PTS Suite Beta', 'second suite', null, null, 1));
echo "suite_beta=$idS2\n";

$tcv = [];
foreach ([
    [$idS1,  'PTS Login Succeeds', 'valid credentials allow sign in'],
    [$idS1b, 'PTS Sub Nested TC', 'a tc deep in the tree'],
    [$idS2,  'PTS Logout', 'signing out ends session'],
    [$idS2,  'PTS Search TC', 'search by name'],
] as $i => [$sid, $nm, $sum]) {
    $idT = firstId($tcaseMgr->create($sid, $nm, $sum, 'precond',
        [['step_number' => 1, 'actions' => 'action ' . $nm,
          'expected_results' => 'expected ' . $nm]], 1));
    $rs = $db->get_recordset(
        " SELECT NH.id FROM nodes_hierarchy NH" .
        " JOIN tcversions TV ON TV.id = NH.id" .
        " WHERE NH.parent_id = " . intval($idT) .
        " AND TV.active = 1 ORDER BY TV.version");
    $tcv[$nm] = intval($rs[0]['id']);
}
echo "tcversions: " . json_encode($tcv) . "\n";

echo "DONE\n";