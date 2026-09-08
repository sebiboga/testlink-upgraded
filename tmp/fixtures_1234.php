<?php
// Fixture for Requirements Coverage per-column filters (Issue #1234)
// Run from repo root: php tmp/fixtures_1234.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tsuiteMgr = new testsuite($db);
$tcaseMgr = new testcase($db);
$tplanMgr = new testplan($db);
$reqSpecMgr = new requirement_spec_mgr($db);
$reqMgr = new requirement_mgr($db);
$buildMgr = new build($db);
$userId = 1; // admin

function firstId($r) {
    if (is_array($r)) {
        if (isset($r['id'])) { return intval($r['id']); }
        $k = array_keys($r);
        return intval($k[0]);
    }
    return intval($r);
}

// idempotency: drop previous fixture project/plan leftovers
$old = $tprojMgr->get_by_name('ReqCovFilt');
foreach ((array)$old as $row) {
    $oid = intval($row['id']);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'ReqCovFilt';
$item->prefix = 'RCF';
$item->notes = 'fixture for issue 1234 (per-column filters reqs coverage)';
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
if ($idP <= 0) { die("project create failed\n"); }

$idS1 = firstId($tsuiteMgr->create($idP, 'Suite 1', 'details', null, null, 1));
echo "tsuite=$idS1\n";

// 4 TCs -> afterwards linked to requirements so their exec status drives eval
$tcDefs = [
    'REQ-A' => 'TC alpha (passed)',
    'REQ-B' => 'TC beta (failed)',
    'REQ-C' => 'TC gamma (blocked)',
    'REQ-D' => 'TC delta (not run)',
];
$idTCs = [];
foreach ($tcDefs as $nm => $summ) {
    $idT = $tcaseMgr->create($idS1, $nm, $summ, 'precond',
        [['step_number' => 1, 'actions' => 'do it', 'expected_results' => 'works']], 1);
    $idTCs[$nm] = firstId($idT);
    echo "tc $nm={$idTCs[$nm]}\n";
}

$idTP = $tplanMgr->create('PlanFilt', 'plan for issue 1234 per-column filters', $idP, 1, 1);
echo "tplan=$idTP\n";

$tbl = tlObjectWithDB::getDBTables(['nodes_hierarchy', 'tcversions']);
$linkItems = ['items' => [], 'tcversion' => []];
$tcv = [];
foreach ($idTCs as $nm => $idT) {
    $rs = $db->get_recordset(
        " SELECT NH.id FROM {$tbl['nodes_hierarchy']} NH" .
        " JOIN {$tbl['tcversions']} TV ON TV.id = NH.id" .
        " WHERE NH.parent_id = " . intval($idT) .
        " AND TV.active = 1 ORDER BY TV.version");
    $tv = intval($rs[0]['id']);
    $linkItems['tcversion'][$idT] = $tv;
    $linkItems['items'][$idT] = [0 => $tv];
    $tcv[$nm] = $tv;
    echo "tcversion $nm=$tv\n";
}
$ret = $tplanMgr->link_tcversions($idTP, $linkItems, 1,
    array('getTCPrefixFromTPlan' => true));
echo "linkTcversions ret=" . var_export($ret, true) . "\n";

// 2 builds for executions
$buildIds = [];
foreach (['B1', 'B2'] as $bn) {
    $bid = $buildMgr->create($idTP, $bn, 'build ' . $bn);
    $buildIds[] = intval($bid);
    echo "build $bn=$bid\n";
}

// req specs + requirements with varied type/status
$spec1 = $reqSpecMgr->create($idP, $idP, 'SRS-F1', 'Fixture Spec F1',
    "scope for filters", 0, $userId, TL_REQ_SPEC_TYPE_USER_REQ_SPEC);
$spec1Id = $spec1['id'];
echo "spec1=$spec1Id\n";

$reqDefs = [
    // doc_id, title, type, status, expected_coverage
    ['R-001', 'Req one - feature valid',   TL_REQ_TYPE_FEATURE,         TL_REQ_STATUS_VALID, 1],
    ['R-002', 'Req two - use case draft',  TL_REQ_TYPE_USE_CASE,        TL_REQ_STATUS_DRAFT, 1],
    ['R-003', 'Req three - nf review',     TL_REQ_TYPE_NON_FUNCTIONAL,  TL_REQ_STATUS_REVIEW, 2],
    ['R-004', 'Req four - interface valid',TL_REQ_TYPE_INTERFACE,       TL_REQ_STATUS_VALID, 1],
];
$reqVersions = [];
foreach ($reqDefs as $i => $rd) {
    $r = $reqMgr->create($spec1Id, $rd[0], $rd[1], 'scope ' . $rd[0], $userId, $rd[3], $rd[2], $rd[4]);
    if ($r['status_ok'] && $r['id'] > 0) {
        $reqVersions[$i] = ['id' => $r['id'], 'version_id' => $r['version_id']];
        echo "req {$rd[0]}=id{$r['id']} version_id={$r['version_id']}\n";
    } else {
        echo "req {$rd[0]} FAILED: " . ($r['msg'] ?? '?') . "\n";
    }
}

// link requirements to TCs via req_coverage
// map: req0->TC-A(passed), req1->TC-B(failed), req2->TC-C(blocked)+TC-D, req3->TC-D(not run)
$reqTcMap = [
    0 => ['REQ-A'],
    1 => ['REQ-B'],
    2 => ['REQ-C', 'REQ-D'],
    3 => ['REQ-D'],
];
$tblRC = tlObjectWithDB::getDBTables(['req_coverage']);
foreach ($reqTcMap as $ri => $tcNames) {
    foreach ($tcNames as $nm) {
        $sql = " INSERT INTO {$tblRC['req_coverage']} " .
               " (req_id, req_version_id, testcase_id, tcversion_id, link_status, is_active, author_id)" .
               " VALUES (" . intval($reqVersions[$ri]['id']) . ", " .
               " " . intval($reqVersions[$ri]['version_id']) . ", " .
               " " . intval($idTCs[$nm]) . ", " . intval($tcv[$nm]) . ", 1, 1, $userId)";
        $db->exec_query($sql);
        echo "reqcov req$ri -> $nm\n";
    }
}

// executions: TC-A passed on B1+B2, TC-B failed on B1, TC-C blocked on B1, TC-D none (=not run)
$tblE = tlObjectWithDB::getDBTables(['executions']);
$now = date('Y-m-d H:i:s');
$execDefs = [
    ['REQ-A', 'p', 0], ['REQ-A', 'p', 1],
    ['REQ-B', 'f', 0],
    ['REQ-C', 'b', 0],
];
foreach ($execDefs as $idx => $ed) {
    list($nm, $st, $bi) = $ed;
    $sql = " INSERT INTO {$tblE['executions']} " .
           " (build_id,tester_id,execution_ts,status,testplan_id,tcversion_id," .
           "  tcversion_number,platform_id,notes)" .
           " VALUES (" . $buildIds[$bi] . ",1,'$now','$st',$idTP," . $tcv[$nm] . ",1,0,'fixture-1234-$idx')";
    $db->exec_query($sql);
    echo "exec $nm -> $st on build " . $buildIds[$bi] . "\n";
}

echo "DONE tproject=$idP tplan=$idTP spec=$spec1Id\n";