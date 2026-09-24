<?php
// Fixture for #1572 browser testing: Test Plan Navigator hub
// (gui/templates/plans/planNav.html + api/plannav/index.php).
// Creates tproject `NAV1572` (prefix NA7, requirements enabled) + test plan +
// suites (Suite A with child Suite A1, Suite B) + 5 test cases + req spec
// RS-NAV with 2 requirements, seeds req_coverage rows and links tcversions to
// the testplan so both group-by modes (test_suite / req_coverage) have data:
//   plan-linked:  A1, B1, B2      plan-unlinked: A2, A1x
//   req coverage: req1 -> tc A1,  req2 -> tc B1   (both linked in plan)
// Expected suite counts: Suite A 1/3 (deep), Suite A1 0/1, Suite B 2/2.
// Expected coverage: spec 2/2 covered; req1 1 linked 1 covered; req2 likewise.
// Run from repo root: php tmp/fixtures_1572.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tplanMgr = new testplan($db);
$tcaseMgr = new testcase($db);
$tsuiteMgr = new testsuite($db);
$reqSpecMgr = new requirement_spec_mgr($db);
$reqMgr = new requirement_mgr($db);
$userId = 1; // admin

// Reset on re-runs (repo rule: fixtures must be re-runnable).
foreach ((array)$tprojMgr->get_by_name('NAV1572') as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'NAV1572';
$item->prefix = 'NA7';
$item->notes = 'fixture for issue 1572 (test plan navigator)';
$item->color = '';
$item->active = 1;
$item->is_public = 1;
$opts = new stdClass();
$opts->requirementsEnabled = 1;
$opts->testPriorityEnabled = 0;
$opts->automationEnabled = 0;
$opts->inventoryEnabled = 0;
$opts->platformsEnabled = 0;
$opts->testcasecfEnabled = 0;
$opts->requirementcfEnabled = 0;
$item->options = $opts;
$r = $tprojMgr->create($item);
$idP = intval($r);
echo "tproject=$idP (prefix NA7)\n";
$tprojMgr->setActive($idP);

function makeTc($tcaseMgr, $parent, $name, $order) {
    $steps = array();
    $steps[0] = new stdClass();
    $steps[0]->step_number = 1;
    $steps[0]->actions = 'fixture step action';
    $steps[0]->expected_results = 'fixture expected result';
    $steps[0]->execution_type = TESTCASE_EXECUTION_TYPE_MANUAL;
    $ret = $tcaseMgr->create($parent, $name, 'fixture tc for #1572', '', $steps, 1,
        '', $order, testcase::AUTOMATIC_ID, TESTCASE_EXECUTION_TYPE_MANUAL, 2);
    if (!$ret['status_ok'] || $ret['id'] <= 0) {
        die('tcase create failed: ' . $ret['message'] . "\n");
    }
    return array('id' => intval($ret['id']),
                 'tcversion_id' => intval($ret['tcversion_id']));
}

$op = $tsuiteMgr->create($idP, 'Suite A', 'fixture suite A for #1572');
if (!$op['status_ok'] || $op['id'] <= 0) { die('suite A failed: ' . $op['msg'] . "\n"); }
$idSA = intval($op['id']);
$op = $tsuiteMgr->create($idP, 'Suite B', 'fixture suite B for #1572');
if (!$op['status_ok'] || $op['id'] <= 0) { die('suite B failed: ' . $op['msg'] . "\n"); }
$idSB = intval($op['id']);
$op = $tsuiteMgr->create($idSA, 'Suite A1', 'child suite under A for #1572');
if (!$op['status_ok'] || $op['id'] <= 0) { die('suite A1 failed: ' . $op['msg'] . "\n"); }
$idSA1 = intval($op['id']);

$tcA1 = makeTc($tcaseMgr, $idSA, 'Alpha One', 1);
$tcA2 = makeTc($tcaseMgr, $idSA, 'Alpha Two', 2);
$tcA1x = makeTc($tcaseMgr, $idSA1, 'Alpha One Child', 1);
$tcB1 = makeTc($tcaseMgr, $idSB, 'Beta One', 1);
$tcB2 = makeTc($tcaseMgr, $idSB, 'Beta Two', 2);
echo "suiteA=$idSA tcA1={$tcA1['id']}/{$tcA1['tcversion_id']} tcA2={$tcA2['id']}/{$tcA2['tcversion_id']} suiteA1=$idSA1 tcA1x={$tcA1x['id']}/{$tcA1x['tcversion_id']} suiteB=$idSB tcB1={$tcB1['id']}/{$tcB1['tcversion_id']} tcB2={$tcB2['id']}/{$tcB2['tcversion_id']}\n";

$idTp = intval($tplanMgr->create('Plan NAV1572', 'fixture plan for #1572 (navigator)', $idP));
$idTp2 = intval($tplanMgr->create('Plan NAV Alt', 'second plan for #1572 (navigator plan-switch)', $idP));
echo "tplan=$idTp tplan2=$idTp2\n";

$items_to_link = null;
foreach (array($tcA1, $tcB1, $tcB2) as $tc) {
    $items_to_link['tcversion'][$tc['id']] = $tc['tcversion_id'];
    $items_to_link['items'][$tc['id']][0] = $tc['tcversion_id'];
}
$tplanMgr->link_tcversions($idTp, $items_to_link, $userId);
echo "linked tcversions to tplan=$idTp (A1, B1, B2)\n";

$op = $reqSpecMgr->create($idP, $idP, 'RS-NAV', 'Navigator Spec', 'spec for #1572', 3, $userId,
    TL_REQ_SPEC_TYPE_USER_REQ_SPEC);
if (!$op['status_ok'] || $op['id'] <= 0) { die('spec create failed: ' . $op['msg'] . "\n"); }
$idS = intval($op['id']);

$req1 = $reqMgr->create($idS, 'REQ-NAV-1', 'First requirement', 'scope of REQ-NAV-1', $userId,
    TL_REQ_STATUS_VALID, TL_REQ_TYPE_INFO, 1, 1, $idP);
$req2 = $reqMgr->create($idS, 'REQ-NAV-2', 'Second requirement', 'scope of REQ-NAV-2', $userId,
    TL_REQ_STATUS_VALID, TL_REQ_TYPE_INFO, 1, 2, $idP);
if (!$req1['status_ok'] || $req1['id'] <= 0 || !$req2['status_ok'] || $req2['id'] <= 0) {
    die('req create failed: ' . $req1['msg'] . ' / ' . $req2['msg'] . "\n");
}
$idR1 = intval($req1['id']);
$idR2 = intval($req2['id']);
echo "spec=$idS req1=$idR1 req2=$idR2\n";

$tables = tlObject::getDBTables();
$lrvi = $db->get_recordset(" SELECT req_id, req_version_id FROM latest_req_version_id" .
    " WHERE req_id IN ($idR1,$idR2)");
$rv1 = 0; $rv2 = 0;
foreach ($lrvi as $row) {
    if (intval($row['req_id']) === $idR1) { $rv1 = intval($row['req_version_id']); }
    if (intval($row['req_id']) === $idR2) { $rv2 = intval($row['req_version_id']); }
}
if (!$rv1 || !$rv2) { die('req version ids not found' . "\n"); }
echo "req_version1=$rv1 req_version2=$rv2\n";

$rc = $tables['req_coverage'];
$db->exec_query(" INSERT INTO {$rc}" .
    " (req_id, req_version_id, testcase_id, tcversion_id, link_status, is_active, author_id)" .
    " VALUES ($idR1, $rv1, {$tcA1['id']}, {$tcA1['tcversion_id']}, 1, 1, $userId)," .
    " ($idR2, $rv2, {$tcB1['id']}, {$tcB1['tcversion_id']}, 1, 1, $userId)");
echo "seeded req_coverage: req1 -> tcA1, req2 -> tcB1\n";

echo "fixture ready: tproject=$idP tplan=$idTp spec=$idS req1=$idR1 req2=$idR2 suiteA=$idSA suiteA1=$idSA1 suiteB=$idSB\n";