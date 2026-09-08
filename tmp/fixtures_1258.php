<?php
// Fixture for #1258 browser/API testing: charts apikey / public-link
// anonymous access on results/charts.html (BFF charts_data).
// Project + 2 suites + TCs + keywords + platforms + test plan + builds +
// executions, plus a 64-char testplan api_key (public/anonymous) and a
// 32-char admin script_key (remote user). Mirrors the #1246 generalMetrics
// fixture layout so the same apikey matrices can be exercised.
// Run from repo root: php tmp/fixtures_1258.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);
$p = DB_TABLE_PREFIX;

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
$old = $tprojMgr->get_by_name('CHDI1258');
foreach ((array)$old as $row) {
    $oid = intval($row['id']);
    if ($oid > 0) { echo "deleting old project $oid\n"; $tprojMgr->delete($oid, 1); }
}

$item = new stdClass();
$item->name = 'CHDI1258';
$item->prefix = 'C1258';
$item->notes = 'fixture for issue 1258 (charts apikey / public link)';
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

// two root suites
$idS1 = firstId($tsuiteMgr->create($idP, 'Alpha Suite', 'alpha', null, null, 1));
$idS2 = firstId($tsuiteMgr->create($idP, 'Beta Suite', 'beta', null, null, 1));
echo "suites=$idS1,$idS2\n";

// 6 test cases, CHD-1..6
$tcs = [
    [$idS1, 'CHD One'],   [$idS1, 'CHD Two'],  [$idS1, 'CHD Three'],
    [$idS2, 'CHD Four'],  [$idS2, 'CHD Five'], [$idS2, 'CHD Six'],
];
$tcvById = [];
foreach ($tcs as [$idS, $nm]) {
    $idT = firstId($tcaseMgr->create($idS, $nm, 'summary', 'precond',
        [['step_number' => 1, 'actions' => 'act', 'expected_results' => 'exp']], 1));
    $rs = $db->get_recordset(
        " SELECT NH.id FROM nodes_hierarchy NH" .
        " JOIN tcversions TV ON TV.id = NH.id" .
        " WHERE NH.parent_id = " . intval($idT) . " AND TV.active = 1 ORDER BY TV.version");
    $tv = intval($rs[0]['id']);
    $tcvById[$tv] = ['tc' => $idT, 'suite' => $idS];
    echo "tc $idT ($nm) tv=$tv\n";
}

// keywords: Smoke on CHD-1/2, Regression on CHD-3/4
$db->exec_query(" INSERT INTO keywords (keyword,testproject_id,notes) VALUES ('Smoke',$idP,'') ");
$kwSmoke = intval($db->get_recordset("SELECT MAX(id) AS id FROM keywords WHERE testproject_id=$idP")[0]['id']);
$db->exec_query(" INSERT INTO keywords (keyword,testproject_id,notes) VALUES ('Regression',$idP,'') ");
$kwRegr  = intval($db->get_recordset("SELECT MAX(id) AS id FROM keywords WHERE testproject_id=$idP")[0]['id']);
echo "keywords smoke=$kwSmoke regression=$kwRegr\n";

$kwMap = [$kwSmoke => [1, 2], $kwRegr => [3, 4]]; // by positional index into $tcs (0-based)
foreach ($kwMap as $kw => $idxList) {
    foreach ($idxList as $i) {
        $tv = array_keys($tcvById)[$i - 1];
        $tc = $tcvById[$tv]['tc'];
        $db->exec_query(
            " INSERT INTO testcase_keywords (testcase_id,tcversion_id,keyword_id)" .
            " VALUES (" . intval($tc) . "," . intval($tv) . "," . intval($kw) . ")");
    }
}
echo "keywords linked\n";

// platforms Linux + Windows, linked to the plan later
$db->exec_query(" INSERT INTO platforms (name,testproject_id,notes,enable_on_design,enable_on_execution,is_open) VALUES ('CH Charts Linux',$idP,'',1,1,1) ");
$platLinux = intval($db->get_recordset("SELECT MAX(id) AS id FROM platforms WHERE testproject_id=$idP")[0]['id']);
$db->exec_query(" INSERT INTO platforms (name,testproject_id,notes,enable_on_design,enable_on_execution,is_open) VALUES ('CH Charts Windows',$idP,'',1,1,1) ");
$platWin   = intval($db->get_recordset("SELECT MAX(id) AS id FROM platforms WHERE testproject_id=$idP")[0]['id']);
echo "platforms=$platLinux,$platWin\n";

// test plan with fixed 64-char api_key (public-link / anonymous)
$idTP = $tplanMgr->create('CH Charts Plan', 'issue 1258 charts plan', $idP, 1, 1);
echo "tplan=$idTP\n";
$planKey = str_repeat('c', 62) . '58';
$db->exec_query(" UPDATE testplans SET api_key='$planKey' WHERE id=" . intval($idTP));

// admin 32-char script_key (remote-user apikey)
$db->exec_query(" UPDATE users SET script_key='abcdef0123456789abcdef0123456789' WHERE login='admin'");

// link all tcversions into the plan
$linkItems = ['items' => [], 'tcversion' => []];
foreach ($tcvById as $tv => $ent) {
    $linkItems['tcversion'][$ent['tc']] = $tv;
    $linkItems['items'][$ent['tc']] = [0 => $tv];
}
$tplanMgr->link_tcversions($idTP, $linkItems, 1, ['getTCPrefixFromTPlan' => true]);

// link platforms to plan
foreach ([$platLinux, $platWin] as $plat) {
    $db->exec_query(
        " INSERT IGNORE INTO testplan_platforms (testplan_id,platform_id,active)" .
        " VALUES (" . intval($idTP) . "," . intval($plat) . ",1)");
}

// two builds: one open, one closed
$db->exec_query(
    " INSERT INTO builds (testproject_id,name,notes,active,is_open,author_id,creation_ts)" .
    " VALUES ($idP,'CH Build Open','',1,1,1,'2026-01-01 00:00:00') ");
$bOpenId = intval($db->get_recordset("SELECT MAX(id) AS id FROM builds WHERE testproject_id=$idP")[0]['id']);
$db->exec_query(
    " INSERT INTO builds (testproject_id,name,notes,active,is_open,author_id,creation_ts)" .
    " VALUES ($idP,'CH Build Closed','',1,0,1,'2026-01-02 00:00:00') ");
$bClosedId = intval($db->get_recordset("SELECT MAX(id) AS id FROM builds WHERE testproject_id=$idP")[0]['id']);
echo "builds open=$bOpenId closed=$bClosedId\n";

// testplan_tcversions rows created by link_tcversions (platform_id=0); the
// plan is platform-split (Linux+Windows), so create the per-platform link
// rows like real legacy usage: Linux keeps the existing rows, add Windows.
foreach ($tcvById as $tv => $ent) {
    $db->exec_query(
        " UPDATE testplan_tcversions SET platform_id=$platLinux " .
        " WHERE testplan_id=$idTP AND tcversion_id=$tv");
    $db->exec_query(
        " INSERT INTO testplan_tcversions (testplan_id,tcversion_id,platform_id,node_order,urgency) " .
        " SELECT testplan_id,tcversion_id,$platWin,node_order,urgency" .
        " FROM testplan_tcversions WHERE testplan_id=$idTP AND tcversion_id=$tv");
}
$tptc = [];
foreach ($tcvById as $tv => $ent) {
    $rs = $db->get_recordset(
        " SELECT id FROM testplan_tcversions WHERE testplan_id=" . intval($idTP) .
        " AND tcversion_id=" . intval($tv) . " AND platform_id=" . intval($platLinux));
    $tptc[$tv] = intval($rs[0]['id']);
}

// executions matching the SCREEN-COMPARE analysis counts:
// overall: Blocked 1 / Failed 2 / Not Run 6 / Passed 3 (12 tplan_tcversions)
$exec = [
    // [tc index (1-based), build, platform, status, tester]
    [1, $bOpenId, $platLinux, 'p', 1],
    [2, $bOpenId, $platLinux, 'p', 1],
    [3, $bOpenId, $platLinux, 'f', 1],
    [4, $bOpenId, $platLinux, 'b', 1],
    [1, $bClosedId, $platWin, 'p', 1],
    [3, $bClosedId, $platWin, 'f', 1],
];
foreach ($exec as [$i, $bid, $plat, $status, $tester]) {
    $tv = array_keys($tcvById)[$i - 1];
    $db->exec_query(
        " INSERT INTO executions (build_id,tester_id,execution_ts,status,testplan_id,tcversion_id,tcversion_number,platform_id) " .
        " VALUES ($bid,$tester,'2026-01-0$i 10:00:00','$status',$idTP,$tv,1,$plat)");
}
echo "executions seeded\n";

echo "DONE tproject=$idP tplan=$idTP plan_key=$planKey\n";