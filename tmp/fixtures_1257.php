<?php
// Fixture for #1257 regression: minimal valid testproject + testplan so the
// healthy General Test Plan Metrics report path can be exercised after the
// null-guard fix. Run from repo root: php tmp/fixtures_1257.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tsuiteMgr = new testsuite($db);
$tcaseMgr = new testcase($db);
$tplanMgr = new testplan($db);

function firstId($r) {
    if (is_array($r)) {
        if (isset($r['id'])) { return intval($r['id']); }
        $k = array_keys($r);
        return intval($k[0]);
    }
    return intval($r);
}

// idempotency
$old = $tprojMgr->get_by_name('RF1257');
foreach ((array)$old as $row) {
    $oid = intval($row['id']);
    if ($oid > 0) { echo "deleting old project $oid\n"; $tprojMgr->delete($oid, 1); }
}

$item = new stdClass();
$item->name = 'RF1257';
$item->prefix = 'R1257';
$item->notes = 'fixture for issue 1257 (resultsGeneral null-guard)';
$item->color = '';
$item->active = 1;
$item->is_public = 1;
$opts = new stdClass();
$opts->requirementsEnabled = 1;
$opts->testPriorityEnabled = 1;
$opts->automationEnabled = 0;
$opts->inventoryEnabled = 0;
$item->options = $opts;
$idP = $tprojMgr->create($item);
echo "tproject=$idP\n";

$idS = firstId($tsuiteMgr->create($idP, 'Suite 1257', 'suit', null, null, 1));
echo "suite=$idS\n";

$idT = firstId($tcaseMgr->create($idS, 'TC 1257', 'summary', 'precond',
    [['step_number' => 1, 'actions' => 'act', 'expected_results' => 'exp']], 1));
$rs = $db->get_recordset(
    " SELECT NH.id FROM nodes_hierarchy NH" .
    " JOIN tcversions TV ON TV.id = NH.id" .
    " WHERE NH.parent_id = " . intval($idT) . " AND TV.active = 1 ORDER BY TV.version");
$tv = intval($rs[0]['id']);
echo "tc $idT tv=$tv\n";

// test plan with fixed 64-char api_key (public-link / anonymous)
$idTP = $tplanMgr->create('RF Plan 1257', 'issue 1257 plan', $idP, 1, 1);
echo "tplan=$idTP\n";
$planKey = str_repeat('r', 62) . '57';
$db->exec_query(" UPDATE testplans SET api_key='$planKey' WHERE id=" . intval($idTP));

// admin 32-char script_key (remote-user apikey)
$db->exec_query(" UPDATE users SET script_key='abcdef0123456789abcdef0123456789' WHERE login='admin'");

// link tcversion into the plan
$linkItems = ['items' => [$idT => [0 => $tv]], 'tcversion' => [$idT => $tv]];
$tplanMgr->link_tcversions($idTP, $linkItems, 1, ['getTCPrefixFromTPlan' => true]);

// one open build + one passed execution so the report has data
$db->exec_query(
    " INSERT INTO builds (testproject_id,name,notes,active,is_open,author_id,creation_ts)" .
    " VALUES ($idP,'Build 1257','',1,1,1,'2026-01-01 00:00:00') ");
$bId = intval($db->get_recordset("SELECT MAX(id) AS id FROM builds WHERE testproject_id=$idP")[0]['id']);
$tptc = intval($db->get_recordset(
    " SELECT id FROM testplan_tcversions WHERE testplan_id=$idTP AND tcversion_id=$tv")[0]['id']);
$db->exec_query(
    " INSERT INTO executions (build_id,tester_id,execution_ts,status,testplan_id,tcversion_id,tcversion_number,platform_id) " .
    " VALUES ($bId,1,'2026-01-01 10:00:00','p',$idTP,$tv,1,0)");
echo "build=$bId tptc=$tptc execution seeded\n";

echo "DONE tproject=$idP tplan=$idTP plan_key=$planKey admin_script_key=abcdef0123456789abcdef0123456789\n";