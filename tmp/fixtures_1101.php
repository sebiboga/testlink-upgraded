<?php
/**
 * Fixture for #1101 (full legacy search-criteria form dropped in
 * searchQuickView.html): creates test project `QSDemo` (prefix QS) with
 * priority + requirements enabled, suites Alpha (with nested A1) + Beta,
 * 5 test cases covering the legacy search-criteria dimensions:
 *   QS-1  v1 high importance, keyword queen, CF qs_env='prod', status final
 *   QS-2  v1 medium importance, no keyword, status final
 *   QS-3  v1 low importance, draft status, keyword regression, step 'login'
 *   QS-4  v2 (v1 also exists) medium importance, status final
 *   QS-5  v1 medium importance, status final, linked to requirement REQ-QS1
 * keywords: queen, regression (assigned at tcversion level)
 * custom field: qs_env (string) on testcase design
 * requirement spec SRS-QS + requirement REQ-QS1 linked to QS-5
 *
 * Usage: php tmp/fixtures_1101.php
 */
require_once(__DIR__ . '/../config.inc.php');
require_once(__DIR__ . '/../lib/functions/common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$suiteMgr = new testsuite($db);
$tcaseMgr = new testcase($db);
$reqSpecMgr = new requirement_spec_mgr($db);
$reqMgr = new requirement_mgr($db);
$userId = 1; // admin

$tables = tlObject::getDBTables();

// idempotent re-run: delete an existing QSDemo project
foreach ((array)$tprojMgr->get_by_name('QSDemo') as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'QSDemo';
$item->prefix = 'QS';
$item->notes = 'fixture for issue 1101 (quick search advanced criteria)';
$item->color = '';
$item->active = 1;
$item->is_public = 1;
$opts = new stdClass();
$opts->requirementsEnabled = 1;
$opts->testPriorityEnabled = 1;
$opts->automationEnabled = 0;
$opts->inventoryEnabled = 0;
$opts->platformsEnabled = 0;
$opts->testcasecfEnabled = 1;
$opts->requirementcfEnabled = 0;
$item->options = $opts;
$idP = intval($tprojMgr->create($item));
if ($idP <= 0) { die("tproject create failed\n"); }
$tprojMgr->setActive($idP);
echo "tproject=$idP\n";

// suite Alpha + nested A1 + Beta
$os = $suiteMgr->create($idP, 'Alpha', 'fixture suite Alpha');
if (empty($os['status_ok']) || intval($os['id'] ?? 0) <= 0) { die('suite Alpha failed'); }
$idSAlpha = intval($os['id']);
$os = $suiteMgr->create($idP, 'A1', 'fixture nested suite A1', $idSAlpha);
if (empty($os['status_ok']) || intval($os['id'] ?? 0) <= 0) { die('suite A1 failed'); }
$idA1 = intval($os['id']);
$os = $suiteMgr->create($idP, 'Beta', 'fixture suite Beta');
if (empty($os['status_ok']) || intval($os['id'] ?? 0) <= 0) { die('suite Beta failed'); }
$idSBeta = intval($os['id']);
echo "suites: Alpha=$idSAlpha A1=$idA1 Beta=$idSBeta\n";

function makeTc($db, $tcaseMgr, $suiteId, $name, $summary, $preconditions,
                $importance, $userId, $status) {
    $step = new stdClass();
    $step->step_number = 1;
    $step->actions = "Do the " . $name . " action";
    $step->expected_results = 'Expected result of ' . $name;
    $step->execution_type = TESTCASE_EXECUTION_TYPE_MANUAL;
    $tr = $tcaseMgr->create($suiteId, $name, $summary, $preconditions,
        array($step), $userId, '', null, testcase::AUTOMATIC_ID,
        TESTCASE_EXECUTION_TYPE_MANUAL, $importance, null);
    if (empty($tr['status_ok']) || intval($tr['id']) <= 0) {
        die('tc create failed: ' . json_encode($tr) . "\n");
    }
    $idTC = intval($tr['id']);
    $idTCV = intval($tr['tcversion_id']);
    if ($status) {
        $db->exec_query("UPDATE tcversions SET status = " . intval($status) .
            " WHERE id = " . intval($idTCV));
    }
    return array('tc' => $idTC, 'tcv' => $idTCV);
}

// QS-1 high importance, keyword queen, CF qs_env=prod
$t1 = makeTc($db, $tcaseMgr, $idSAlpha, 'Login OK', 'queen says happy login',
    'user account exists', 3, $userId, 7);
// QS-2 medium importance
$t2 = makeTc($db, $tcaseMgr, $idSAlpha, 'Logout works', 'logout summary',
    'user session open', 2, $userId, 7);
// QS-3 low importance, draft status, keyword regression, step 'login'
$t3 = makeTc($db, $tcaseMgr, $idA1, 'Password rules', 'password policy queen rule',
    'password file present', 1, $userId, 1);
// QS-4: v1 then v2 (medium both)
$t4 = makeTc($db, $tcaseMgr, $idSBeta, 'Search queen cases', 'beta search summary',
    'search index built', 2, $userId, 7);
$ret = $tcaseMgr->create_new_version($t4['tc'], $userId, $t4['tcv']);
$t4v2 = intval(is_array($ret) && isset($ret['id']) ? $ret['id'] : $ret);
if ($t4v2 <= 0) { die('QS-4 v2 create failed'); }
$db->exec_query("UPDATE tcversions SET status = 7 WHERE id = " . intval($t4v2));
// QS-5 linked to requirement REQ-QS1
$t5 = makeTc($db, $tcaseMgr, $idSBeta, 'Requirement coverage check', 'req summary',
    'req book open', 2, $userId, 7);

echo "QSDemo test cases:\n";
foreach (array($t1, $t2, $t3, $t4, $t5) as $i => $t) {
    echo "  QS-" . ($i + 1) . " tc={$t['tc']} tcv={$t['tcv']}\n";
}
$tcvAll = array($t1['tcv'], $t2['tcv'], $t3['tcv'], $t4['tcv'], $t4v2, $t5['tcv']);

// keywords: queen (QS-1), regression (QS-3)  -- stored at TC + TCV level
$kwQId = 0; $kwRId = 0;
$db->exec_query("INSERT INTO keywords (keyword, testproject_id, notes) VALUES ('queen', {$idP}, 'fixture kw #1101')");
$kwQId = intval($db->insert_id('keywords'));
$db->exec_query("INSERT INTO keywords (keyword, testproject_id, notes) VALUES ('regression', {$idP}, 'fixture kw #1101')");
$kwRId = intval($db->insert_id('keywords'));
$db->exec_query("INSERT INTO testcase_keywords (testcase_id, tcversion_id, keyword_id) VALUES ({$t1['tc']}, {$t1['tcv']}, {$kwQId})");
$db->exec_query("INSERT INTO testcase_keywords (testcase_id, tcversion_id, keyword_id) VALUES ({$t3['tc']}, {$t3['tcv']}, {$kwRId})");
echo "keywords: queen=$kwQId regression=$kwRId\n";

// custom field qs_env (string) on testcase design, linked to project,
// value 'prod' on QS-1 tcversion
$cfieldMgr = new cfield_mgr($db);
$cfId = 0;
foreach ((array)$db->get_recordset("SELECT id FROM custom_fields WHERE name = 'qs_env'") as $row) {
    $db->exec_query("DELETE FROM custom_fields WHERE id = {$row['id']}");
}
$cf = array(
    'name' => 'qs_env', 'label' => 'qs_env', 'type' => 0,
    'possible_values' => null,
    'show_on_design' => 1, 'enable_on_design' => 1,
    'show_on_testplan_design' => 0, 'enable_on_testplan_design' => 0,
    'show_on_execution' => 0, 'enable_on_execution' => 0,
    'node_type_id' => 3, // testcase
);
$cFRet = $cfieldMgr->create($cf);
if (!empty($cFRet['status_ok']) && intval($cFRet['id']) > 0) {
    $cfId = intval($cFRet['id']);
    $db->exec_query("INSERT IGNORE INTO cfield_testprojects (field_id, testproject_id, display_order, location, active) VALUES ({$cfId}, {$idP}, 1, 1, 1)");
    $db->exec_query("INSERT IGNORE INTO cfield_design_values (field_id, node_id, value) VALUES ({$cfId}, {$t1['tcv']}, 'prod')");
}
echo "cfield=$cfId\n";

// requirement spec SRS-QS + REQ-QS1, linked to QS-5 via req_coverage
$op = $reqSpecMgr->create($idP, $idP, 'SRS-QS', 'QS spec', 'QS spec scope.',
    3, $userId, TL_REQ_SPEC_TYPE_USER_REQ_SPEC);
if (empty($op['status_ok']) || intval($op['id'] ?? 0) <= 0) { die('spec create failed'); }
$idSpec = intval($op['id']);
$op = $reqMgr->create($idSpec, 'REQ-QS1', 'First QS requirement',
    'scope of the first QS requirement', $userId, TL_REQ_STATUS_VALID,
    TL_REQ_TYPE_INFO, 1, 1, $idP);
if (empty($op['status_ok']) || intval($op['id'] ?? 0) <= 0) { die('req create failed'); }
$idReq = intval($op['id']);
$db->exec_query(" SELECT req_id, req_version_id FROM latest_req_version_id" .
    " WHERE req_id = " . intval($idReq));
$reqv = $db->get_recordset(" SELECT req_id, req_version_id FROM latest_req_version_id" .
    " WHERE req_id = " . intval($idReq));
$vReq = $reqv ? intval($reqv[0]['req_version_id']) : 0;
$db->exec_query("INSERT INTO {$tables['req_coverage']} (req_id, req_version_id, testcase_id, tcversion_id, link_status, is_active, author_id) VALUES ({$idReq}, $vReq, {$t5['tc']}, {$t5['tcv']}, 1, 1, {$userId})");
echo "req spec=$idSpec req=$idReq v=$vReq\n";

// verification queries
$q = "SELECT COUNT(DISTINCT NH_TC.id) c FROM nodes_hierarchy NH_TC" .
     " JOIN nodes_hierarchy NH_TCV ON NH_TCV.parent_id = NH_TC.id" .
     " JOIN tcversions TCV ON NH_TCV.id = TCV.id" .
     " WHERE NH_TC.id IN (" . implode(',', array($t1['tc'], $t2['tc'], $t3['tc'], $t4['tc'], $t5['tc'])) . ")";
echo "tc count: " . intval($db->fetchOneValue($q)) . "\n";
echo "kw queen count: " . intval($db->fetchOneValue(
    "SELECT COUNT(DISTINCT NH_TC.id) c FROM testcase_keywords KW" .
    " JOIN nodes_hierarchy NH_TC ON NH_TC.id = KW.testcase_id" .
    " WHERE KW.keyword_id = $kwQId")) . " (expect 1)\n";
echo "req_doc_id count: " . intval($db->fetchOneValue(
    "SELECT COUNT(DISTINCT RC.testcase_id) c FROM req_coverage RC" .
    " JOIN requirements REQ ON REQ.id = RC.req_id" .
    " WHERE REQ.req_doc_id = 'REQ-QS1'")) . " (expect 1)\n";
echo "cf count: " . intval($db->fetchOneValue(
    "SELECT COUNT(*) c FROM cfield_design_values WHERE field_id = $cfId AND value = 'prod'")) . " (expect 1)\n";
echo "FIXTURE_OK project=$idP\n";