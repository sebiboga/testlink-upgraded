<?php
// Fixture for #1319 browser/curl testing: tcAssign2Tplan Cancel-button
// visibility (gui/templates/testcases/tcAssign2Tplan.html + BFF
// api/tcassign2tplan/index.php). Creates tproject `A2PDemo` (prefix A2P,
// platforms enabled), testplan `Plan A`, platform `Win10`, testsuite
// `Suite 1319`, and two test cases:
//   - A2P-1  (v1, NOT linked to any plan)  -> can_do=true scenario
//   - A2P-2  (v1 AND v2; v1 linked to Plan A on Win10) -> viewing v2 the
//     plan is linked to a DIFFERENT version -> can_do=false scenario
// Run from repo root: php tmp/fixtures_1319.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tplanMgr = new testplan($db);
$tcaseMgr = new testcase($db);
$tsuiteMgr = new testsuite($db);
$platformMgr = new tlPlatform($db);
$userId = 1; // admin

foreach ((array)$tprojMgr->get_by_name('A2PDemo') as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'A2PDemo';
$item->prefix = 'A2P';
$item->notes = 'fixture for issue 1319 (tcAssign2Tplan cancel visibility)';
$item->color = '';
$item->active = 1;
$item->is_public = 1;
$opts = new stdClass();
$opts->requirementsEnabled = 0;
$opts->testPriorityEnabled = 1;
$opts->automationEnabled = 0;
$opts->inventoryEnabled = 0;
$opts->platformsEnabled = 1;
$opts->testcasecfEnabled = 1;
$opts->requirementcfEnabled = 0;
$item->options = $opts;
$idP = intval($tprojMgr->create($item));
if ($idP <= 0) {
    die("tproject create failed\n");
}
echo "tproject=$idP (prefix A2P)\n";
$tprojMgr->setActive($idP);

$platformMgr->setTestProjectID($idP);
$plat = new stdClass();
$plat->name = 'Win10';
$plat->notes = '';
$plat->testproject_id = $idP;
$plat->enable_on_design = 1;
$plat->enable_on_execution = 1;
$opPlat = $platformMgr->create($plat);
if ($opPlat['status'] != tl::OK || $opPlat['id'] <= 0) {
    die("platform create failed\n");
}
$idPlat = intval($opPlat['id']);
echo "platform=$idPlat\n";

$idTp = intval($tplanMgr->create('Plan A', 'fixture plan for #1319', $idP));
if ($idTp <= 0) {
    die("testplan create failed\n");
}
echo "testplan=$idTp\n";

$platformMgr->linkToTestplan($idPlat, $idTp);
echo "platform linked to testplan\n";

$tsuiteRet = $tsuiteMgr->create($idP, 'Suite 1319', 'suite for #1319');
if (!$tsuiteRet['status_ok'] || $tsuiteRet['id'] <= 0) {
    die('testsuite create failed: ' . $tsuiteRet['msg'] . "\n");
}
$idTsuite = intval($tsuiteRet['id']);
echo "testsuite=$idTsuite\n";

function mkSteps() {
    $steps = array();
    $steps[0] = new stdClass();
    $steps[0]->step_number = 1;
    $steps[0]->actions = 'open app';
    $steps[0]->expected_results = 'app opens';
    $steps[0]->execution_type = TESTCASE_EXECUTION_TYPE_MANUAL;
    return $steps;
}

// A2P-1: unlinked -> can_do=true scenario
$ret = $tcaseMgr->create($idTsuite, 'A2P-1', 'unlinked tc for #1319 (can_do=true)', '',
    mkSteps(), $userId, '', testcase::DEFAULT_ORDER, testcase::AUTOMATIC_ID,
    TESTCASE_EXECUTION_TYPE_MANUAL);
if (!$ret['status_ok'] || $ret['id'] <= 0) {
    die('A2P-1 create failed: ' . ($ret['msg'] ?? 'unknown') . "\n");
}
$idTcase1 = intval($ret['id']);
$idTcver1 = intval($ret['tcversion_id']);
echo "A2P-1 tcase=$idTcase1 tcversion=$idTcver1\n";

// A2P-2: v1 + v2, v1 linked to Plan A on Win10 -> viewing v2 = can_do=false
$ret2 = $tcaseMgr->create($idTsuite, 'A2P-2', 'two-version tc for #1319 (can_do=false)', '',
    mkSteps(), $userId, '', testcase::DEFAULT_ORDER, testcase::AUTOMATIC_ID,
    TESTCASE_EXECUTION_TYPE_MANUAL);
if (!$ret2['status_ok'] || $ret2['id'] <= 0) {
    die('A2P-2 create failed: ' . ($ret2['msg'] ?? 'unknown') . "\n");
}
$idTcase2 = intval($ret2['id']);
$idTcver2_v1 = intval($ret2['tcversion_id']);
echo "A2P-2 v1 tcase=$idTcase2 tcversion=$idTcver2_v1\n";

$newVer = $tcaseMgr->create_new_version($idTcase2, $userId);
$idTcver2_v2 = intval($newVer['id']);
echo "A2P-2 v2 tcversion=$idTcver2_v2\n";

$items_to_link = null;
$items_to_link['tcversion'][$idTcase2] = $idTcver2_v1;
$items_to_link['platform'][$idPlat] = $idPlat;
$items_to_link['items'][$idTcase2][$idPlat] = $idTcver2_v1;
$tplanMgr->link_tcversions($idTp, $items_to_link, $userId);
echo "linked A2P-2 v1 ($idTcver2_v1) to tplan=$idTp platform=$idPlat\n";

echo "fixture ready: tproject=$idP tplan=$idTp tcaseA2P1=$idTcase1 tcaseA2P2=$idTcase2 "
    . "tcverA2P2_v1=$idTcver2_v1 tcverA2P2_v2=$idTcver2_v2 platform=$idPlat\n";