<?php
// Fixture for Quality Objectives & Risk Traceability Matrix (Issue #1307)
// Run from repo root: php tmp/fixtures_1307.php
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
$old = $tprojMgr->get_by_name('QObjDemo');
foreach ((array)$old as $row) {
    $oid = intval($row['id']);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'QObjDemo';
$item->prefix = 'QOB';
$item->notes = 'fixture for issue 1307 (quality objectives matrix)';
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

// 3 TCs -> afterwards linked to requirements + some executions
$tcDefs = [
    'TC-A' => 'TC alpha (passed)',
    'TC-B' => 'TC beta (failed)',
    'TC-C' => 'TC gamma (not run)',
];
$idTCs = [];
foreach ($tcDefs as $nm => $summ) {
    $idT = $tcaseMgr->create($idS1, $nm, $summ, 'precond',
        [['step_number' => 1, 'actions' => 'do it', 'expected_results' => 'works']], 1);
    $idTCs[$nm] = firstId($idT);
    echo "tc $nm={$idTCs[$nm]}\n";
}

$idTP = $tplanMgr->create('QOB Plan', 'plan for issue 1307', $idP, 1, 1);
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
    if (!is_array($rs) || !isset($rs[0])) { die("no tcversion for $nm\n"); }
    $tv = intval($rs[0]['id']);
    $linkItems['tcversion'][$idT] = $tv;
    $linkItems['items'][$idT] = [0 => $tv];
    $tcv[$nm] = $tv;
    echo "tcversion $nm=$tv\n";
}
$ret = $tplanMgr->link_tcversions($idTP, $linkItems, 1,
    array('getTCPrefixFromTPlan' => true));
echo "linkTcversions ret ok\n";

$bid = $buildMgr->create($idTP, 'V1', 'build V1');
echo "build=$bid\n";

// req spec + requirements
$spec1 = $reqSpecMgr->create($idP, $idP, 'SRS-Q', 'Fixtures Spec',
    "scope", 0, $userId, TL_REQ_SPEC_TYPE_USER_REQ_SPEC);
$spec1Id = $spec1['id'];
echo "spec=$spec1Id\n";

$reqDefs = [
    ['QOS-1', 'Secure Transactions', TL_REQ_TYPE_FEATURE, TL_REQ_STATUS_VALID, 1],
    ['QOS-2', 'Fast Search',        TL_REQ_TYPE_FEATURE, TL_REQ_STATUS_VALID, 1],
    ['QOS-3', 'Audit Logs',         TL_REQ_TYPE_NON_FUNCTIONAL, TL_REQ_STATUS_REVIEW, 1],
];
$reqVersions = [];
foreach ($reqDefs as $i => $rd) {
    $r = $reqMgr->create($spec1Id, $rd[0], $rd[1], 'scope ' . $rd[0], $userId, $rd[3], $rd[2], $rd[4]);
    if ($r['status_ok'] && $r['id'] > 0) {
        $reqVersions[$i] = ['id' => $r['id'], 'version_id' => $r['version_id']];
        echo "req {$rd[0]}=id{$r['id']}\n";
    } else {
        echo "req {$rd[0]} FAILED: " . ($r['msg'] ?? '?') . "\n";
    }
}

// link requirements to TCs via req_coverage: QOS-1->TC-A, QOS-2->TC-B, QOS-3->TC-C
$reqTcMap = [0 => ['TC-A'], 1 => ['TC-B'], 2 => ['TC-C']];
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

// executions: TC-A passed, TC-B failed, TC-C none (=not run)
$tblE = tlObjectWithDB::getDBTables(['executions']);
$now = date('Y-m-d H:i:s');
$execDefs = [['TC-A', 'p'], ['TC-B', 'f']];
foreach ($execDefs as $idx => $ed) {
    list($nm, $st) = $ed;
    $sql = " INSERT INTO {$tblE['executions']} " .
           " (build_id,tester_id,execution_ts,status,testplan_id,tcversion_id," .
           "  tcversion_number,platform_id,notes)" .
           " VALUES (" . intval($bid) . ",1,'$now','$st',$idTP," . $tcv[$nm] . ",1,0,'fixture-1307-$idx')";
    $db->exec_query($sql);
    echo "exec $nm -> $st\n";
}

// quality objectives + links (schema via BFF lazy migration, create here too)
$q = tlObjectWithDB::getDBTables(['quality_objectives', 'quality_objective_links']);
$db->exec_query(
    "CREATE TABLE IF NOT EXISTS {$q['quality_objectives']} (" .
    " id INT UNSIGNED NOT NULL AUTO_INCREMENT," .
    " testproject_id INT UNSIGNED NOT NULL DEFAULT 0," .
    " name VARCHAR(255) NOT NULL DEFAULT ''," .
    " description TEXT NULL," .
    " risk_likelihood TINYINT NOT NULL DEFAULT 1," .
    " risk_impact TINYINT NOT NULL DEFAULT 1," .
    " is_active TINYINT NOT NULL DEFAULT 1," .
    " author_id INT UNSIGNED NULL," .
    " creation_ts TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP," .
    " PRIMARY KEY (id)," .
    " KEY idx_qo_tproject (testproject_id)" .
    " ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
$db->exec_query(
    "CREATE TABLE IF NOT EXISTS {$q['quality_objective_links']} (" .
    " id INT UNSIGNED NOT NULL AUTO_INCREMENT," .
    " qo_id INT UNSIGNED NOT NULL DEFAULT 0," .
    " tproject_id INT UNSIGNED NOT NULL DEFAULT 0," .
    " item_type VARCHAR(16) NOT NULL DEFAULT 'req'," .
    " item_id INT UNSIGNED NOT NULL DEFAULT 0," .
    " creation_ts TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP," .
    " PRIMARY KEY (id)," .
    " UNIQUE KEY uq_qo_item (qo_id, item_type, item_id)," .
    " KEY idx_qol_item (item_type, item_id)" .
    " ) ENGINE=InnoDB DEFAULT CHARSET=utf8");

$qoSql = " INSERT INTO {$q['quality_objectives']} " .
         " (testproject_id,name,description,risk_likelihood,risk_impact,is_active,author_id) VALUES" .
         " ($idP, 'Secure Transactions', 'All money movement is safe', 3, 5, 1, $userId)," .
         " ($idP, 'Fast Search', 'Users find results quickly', 1, 2, 1, $userId)," .
         " ($idP, 'Audit Ready', 'Full audit trail available', 2, 4, 1, $userId)";
$db->exec_query($qoSql);
$qoIds = [];
foreach (['Secure Transactions', 'Fast Search', 'Audit Ready'] as $nm) {
    $row = $db->fetchFirstRow(
        " SELECT id FROM {$q['quality_objectives']} WHERE testproject_id=$idP AND name='$nm'");
    $qoIds[$nm] = intval($row['id']);
    echo "qo $nm=" . $qoIds[$nm] . "\n";
}

// links: Secure Transactions -> QOS-1 (req) + direct TC-A ; Fast Search -> QOS-2
$qoLnk = [
    ['Secure Transactions', 'req', $reqVersions[0]['id']],
    ['Secure Transactions', 'tc',  $idTCs['TC-A']],
    ['Fast Search', 'req',          $reqVersions[1]['id']],
];
foreach ($qoLnk as $ql) {
    $db->exec_query(
        " INSERT INTO {$q['quality_objective_links']} (qo_id,tproject_id,item_type,item_id) VALUES (" .
        intval($qoIds[$ql[0]]) . ",$idP,'" . $ql[1] . "'," . intval($ql[2]) . ")");
}

echo "DONE tproject=$idP tplan=$idTP spec=$spec1Id\n";