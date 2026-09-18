<?php
// Fixture for #1538 browser testing: execSetResults.php deep-link settings fallback
// (getSettingsAndFilters). Creates tproject `DLS2` + testplan `Plan DL` + one
// testcase (2 versions, latest active) + platform + build, and links the tcversion
// to the testplan on that platform — the minimal dataset execSetResults needs to
// resolve a deep link via setting_testplan/setting_build/setting_platform.
// Run from repo root: php tmp/fixtures_1538.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tplanMgr = new testplan($db);
$tcaseMgr = new testcase($db);
$tsuiteMgr = new testsuite($db);
$buildMgr = new build($db);
$platformMgr = new tlPlatform($db);
$userId = 1; // admin

// Reset on re-runs (repo rule: fixtures must be re-runnable).
foreach ((array)$tprojMgr->get_by_name('DLS2') as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'DLS2';
$item->prefix = 'DL';
$item->notes = 'fixture for issue 1538 (exec deep-link settings fallback)';
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
$r = $tprojMgr->create($item);
$idP = intval($r);
echo "tproject=$idP (prefix DL)\n";
$tprojMgr->setActive($idP);

// platform (node under testproject)
$platformMgr->setTestProjectID($idP);
$plat = new stdClass();
$plat->name = 'Win10';
$plat->notes = '';
$plat->testproject_id = $idP;
$plat->enable_on_design = 1;
$plat->enable_on_execution = 1;
$opPlat = $platformMgr->create($plat);
if ($opPlat['status'] != tl::OK || $opPlat['id'] <= 0) { die('platform create failed' . "\n"); }
$idPlat = intval($opPlat['id']);
echo "platform=$idPlat\n";

// testplan (node under testproject)
$idTp = intval($tplanMgr->create('Plan DL', 'fixture plan for #1538', $idP));
echo "testplan=$idTp (node parent=$idP)\n";

// testsuite under the testproject (design tree), then tcase under it
$tsuiteRet = $tsuiteMgr->create($idP, 'Fixture Suite', 'suite for #1538');
if (!$tsuiteRet['status_ok'] || $tsuiteRet['id'] <= 0) { die('testsuite create failed: ' . $tsuiteRet['msg'] . "\n"); }
$idTsuite = intval($tsuiteRet['id']);
echo "testsuite=$idTsuite (node parent=$idP)\n";

// testcase with a version
$steps = array();
$steps[0] = new stdClass();
$steps[0]->step_number = 1;
$steps[0]->actions = 'open app';
$steps[0]->expected_results = 'app opens';
$steps[0]->execution_type = TESTCASE_EXECUTION_TYPE_MANUAL;
$tcase = new stdClass();
$tcase->name = 'Deep Link Case';
$tcase->summary = 'tc used to exercise exec deep link settings fallback';
$tcase->preconditions = '';
$tcase->steps = $steps;
$ret = $tcaseMgr->create($idTsuite, $tcase->name, $tcase->summary, $tcase->preconditions, $steps, $userId,
    '', testcase::DEFAULT_ORDER, testcase::AUTOMATIC_ID, TESTCASE_EXECUTION_TYPE_MANUAL);
if (!$ret['status_ok'] || $ret['id'] <= 0) {
    die('tcase create failed: ' . $ret['message'] . "\n");
}
$idTcase = intval($ret['id']);
echo "tcase=$idTcase\n";

// get the active tcversion (the one just created by create())
if (!$ret['status_ok'] || !isset($ret['tcversion_id']) || $ret['tcversion_id'] <= 0) {
    die("no active tcversion returned\n");
}
$idTcver = intval($ret['tcversion_id']);
echo "tcversion=$idTcver\n";

// link tcversion to testplan on platform
$items_to_link = null;
$items_to_link['tcversion'][$idTcase] = $idTcver;
$items_to_link['platform'][$idPlat] = $idPlat;
$items_to_link['items'][$idTcase][$idPlat] = $idTcver;
$tplanMgr->link_tcversions($idTp, $items_to_link, $userId);
echo "linked tcversion=$idTcver to tplan=$idTp platform=$idPlat\n";

// build
$idBuild = intval($buildMgr->create($idTp, 'Build 8', 'fixture build for #1538', 1, 1, ''));
echo "build=$idBuild\n";

echo "fixture ready: tproject=$idP tplan=$idTp tcase=$idTcase tcversion=$idTcver platform=$idPlat build=$idBuild\n";