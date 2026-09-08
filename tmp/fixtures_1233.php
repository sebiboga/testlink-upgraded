<?php
// Fixture for Requirements Coverage empty/warning states (Issue #1233)
// Run from repo root: php tmp/fixtures_1233.php
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
$old = $tprojMgr->get_by_name('ReqCovEmpty');
foreach ((array)$old as $row) {
    $oid = intval($row['id']);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'ReqCovEmpty';
$item->prefix = 'RCE';
$item->notes = 'fixture for issue 1233 (empty/warning states reqs coverage)';
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

// 2 TCs, one per plan scenario
$idTCs = [];
foreach (['TC-F' => 'TC for full plan (normal coverage)',
          'TC-M' => 'TC for no-match plan (coverage link obsolete)'] as $nm => $summ) {
    $idT = firstId($tcaseMgr->create($idS1, $nm, $summ, 'precond',
        [['step_number' => 1, 'actions' => 'do it', 'expected_results' => 'works']], 1));
    $idTCs[$nm] = $idT;
    echo "tc $nm=$idT\n";
}

// 3 plans: PFull (rows), PNoMatch (reqs exist but all coverage dropped), PNoSpec (no TCs linked)
$planIds = [];
foreach (['PFull', 'PNoMatch', 'PNoSpec'] as $pn) {
    $idTP = $tplanMgr->create($pn, 'plan for issue 1233 ' . $pn, $idP, 1, 1);
    $planIds[$pn] = $idTP;
    echo "tplan $pn=$idTP\n";
}

// link TC versions into plans: PFull gets TC-F, PNoMatch gets TC-M, PNoSpec gets none
$tbl = tlObjectWithDB::getDBTables(['nodes_hierarchy', 'tcversions']);
$tcv = [];
foreach ($idTCs as $nm => $idT) {
    $rs = $db->get_recordset(
        " SELECT NH.id FROM {$tbl['nodes_hierarchy']} NH" .
        " JOIN {$tbl['tcversions']} TV ON TV.id = NH.id" .
        " WHERE NH.parent_id = " . intval($idT) .
        " AND TV.active = 1 ORDER BY TV.version");
    $tcv[$nm] = intval($rs[0]['id']);
    echo "tcversion $nm=" . $tcv[$nm] . "\n";
}

function linkTcversions($tplanMgr, $idTP, $idP, $tcversionIds) {
    // $tcversionIds: list of tcversion ids
    $linkItems = ['items' => [], 'tcversion' => []];
    foreach ($tcversionIds as $tcvId) {
        // find the parent tcase id
        $linkItems['tcversion'][$tcvId] = $tcvId;
    }
    return $tplanMgr->link_tcversions($idTP, $linkItems, 1,
        array('getTCPrefixFromTPlan' => true));
}

$linkItemsFull = ['items' => [ $idTCs['TC-F'] => [0 => $tcv['TC-F']] ], 'tcversion' => [ $idTCs['TC-F'] => $tcv['TC-F'] ]];
$ret = $tplanMgr->link_tcversions($planIds['PFull'], $linkItemsFull, 1,
    array('getTCPrefixFromTPlan' => true));
echo "linkTCs PFull ret=" . var_export($ret, true) . "\n";

$linkItemsNoMatch = ['items' => [ $idTCs['TC-M'] => [0 => $tcv['TC-M']] ], 'tcversion' => [ $idTCs['TC-M'] => $tcv['TC-M'] ]];
$ret = $tplanMgr->link_tcversions($planIds['PNoMatch'], $linkItemsNoMatch, 1,
    array('getTCPrefixFromTPlan' => true));
echo "linkTCs PNoMatch ret=" . var_export($ret, true) . "\n";

// build for executions on PFull
$bidPFull = $buildMgr->create($planIds['PFull'], 'B1', 'build B1');
echo "build PFull B1=$bidPFull\n";

// req spec + 2 requirements
$spec1 = $reqSpecMgr->create($idP, $idP, 'SRS-EMPTY', 'Fixture Spec Empty',
    "scope for empty states", 0, $userId, TL_REQ_SPEC_TYPE_USER_REQ_SPEC);
$spec1Id = $spec1['id'];
echo "spec1=$spec1Id\n";
if (!$spec1['status_ok'] || $spec1Id <= 0) { die("spec create failed: " . $spec1['msg'] . "\n"); }

$reqDefs = [
    ['R-F', 'Req full (has normal coverage TC in plan)', TL_REQ_TYPE_FEATURE, TL_REQ_STATUS_VALID, 1, 'TC-F'],
    ['R-M', 'Req no-match (coverage TC set obsolete)',    TL_REQ_TYPE_FEATURE, TL_REQ_STATUS_VALID, 1, 'TC-M'],
];
$reqInfo = [];
foreach ($reqDefs as $i => $rd) {
    list($doc, $tit, $type, $status, $cov, $tcName) = $rd;
    $r = $reqMgr->create($spec1Id, $doc, $tit, 'scope ' . $doc, $userId, $status, $type, $cov);
    if ($r['status_ok'] && $r['id'] > 0) {
        $reqInfo[$i] = ['id' => $r['id'], 'version_id' => $r['version_id'], 'tc' => $tcName];
        echo "req {$doc}=id{$r['id']} version_id={$r['version_id']} tc=$tcName\n";
    } else {
        echo "req {$doc} FAILED: " . ($r['msg'] ?? '?') . "\n";
    }
}

// link requirements to TCs via req_coverage (link_status=1 normal)
$tblRC = tlObjectWithDB::getDBTables(['req_coverage']);
$linkStatus = [0 => 1, 1 => 3]; // 1 = normal, 3 = LINK_TC_REQ_CLOSED_BY_NEW_TCVERSION (obsolete)
foreach ($reqInfo as $i => $ri) {
    $tcName = $ri['tc'];
    $ls = $linkStatus[$i];
    $sql = " INSERT INTO {$tblRC['req_coverage']} " .
           " (req_id, req_version_id, testcase_id, tcversion_id, link_status, is_active, author_id)" .
           " VALUES (" . intval($ri['id']) . ", " .
           " " . intval($ri['version_id']) . ", " .
           " " . intval($idTCs[$tcName]) . ", " . intval($tcv[$tcName]) . ", $ls, 1, $userId)";
    $db->exec_query($sql);
    echo "reqcov req{$i} -> $tcName link_status=$ls\n";
}

// execution: TC-F passed on PFull B1
$tblE = tlObjectWithDB::getDBTables(['executions']);
$now = date('Y-m-d H:i:s');
$sql = " INSERT INTO {$tblE['executions']} " .
       " (build_id,tester_id,execution_ts,status,testplan_id,tcversion_id," .
       "  tcversion_number,platform_id,notes)" .
       " VALUES ($bidPFull,1,'$now','p',{$planIds['PFull']}," . $tcv['TC-F'] . ",1,0,'fixture-1233-pfull')";
$db->exec_query($sql);
echo "exec TC-F -> p on PFull\n";

echo "DONE tproject=$idP tplans=" . implode(',', $planIds) . " spec=$spec1Id\n";