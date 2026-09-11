<?php
// Fixture for #1403 browser testing: Set Results popup closed-build handling.
// Creates a project + root suite + a TC with steps + a test plan linked to
// the TC + TWO builds: one open (B-OPEN) and one closed (B-CLOSED), both
// scoped to the project. Prior execution recorded on the OPEN build only.
// Run from repo root: php tmp/fixtures_1403.php
require_once('config.inc.php');
require_once('common.php');
require_once('exec.inc.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tsuiteMgr = new testsuite($db);
$tcaseMgr = new testcase($db);
$tplanMgr = new testplan($db);
$buildMgr = new build($db);

// Reset on re-runs (repo rule: fixtures must be re-runnable).
foreach ((array)$tprojMgr->get_by_name('ESR1403') as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'ESR1403';
$item->prefix = 'E403';
$item->notes = 'fixture for issue 1403 (closed build in Set Results popup)';
$item->color = '';
$item->active = 1;
$item->is_public = 1;
$opts = new stdClass();
$opts->requirementsEnabled = 0;
$opts->testPriorityEnabled = 1;
$opts->automationEnabled = 0;
$opts->inventoryEnabled = 0;
$opts->platformsEnabled = 0;
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

$idS = firstId($tsuiteMgr->create($idP, 'ESR Suite', 'root suite for 1403', null, null, 1));
echo "tsuite=$idS\n";

$steps = [
    ['step_number' => 1, 'actions' => 'open set results popup', 'expected_results' => 'build selector shows all builds', 'execution_type' => TESTCASE_EXECUTION_TYPE_MANUAL],
    ['step_number' => 2, 'actions' => 'select the closed build', 'expected_results' => '"Build is closed" banner, controls disabled', 'execution_type' => TESTCASE_EXECUTION_TYPE_MANUAL],
];
$idT = firstId($tcaseMgr->create($idS, 'ESR Closed Build TC', 'summary for closed build case',
    'preconditions: none', $steps, 1));
echo "tc=$idT\n";

$rows = $tcaseMgr->get_by_id($idT);
$v1Id = intval($rows[0]['id']);
echo "tcversion_id=$v1Id version={$rows[0]['version']}\n";

// Test plan
$idPlan = $tplanMgr->create('ESR1403 Plan', 'plan for closed build popup test', $idP, 1, 1);
echo "tplan=$idPlan\n";

// Link the TC version to the plan (platform 0 = no platform)
$item2link = [];
$item2link['tcversion'][$idT] = $v1Id;
$item2link['platform'][0] = 0;
$item2link['items'][$idT][0] = $v1Id;
$userId = 1;
$cur = $db->fetchFirstRow("SELECT id FROM users WHERE login = 'admin'");
if (is_array($cur) && isset($cur['id'])) { $userId = intval($cur['id']); }
$tplanMgr->link_tcversions($idPlan, $item2link, $userId);

// Builds: project-scoped (builds.testproject_id). One open, one closed.
$bOpen = $buildMgr->create($idPlan, 'B-OPEN', 'open build', 1, 1, '', $idP);
$bClosed = $buildMgr->create($idPlan, 'B-CLOSED', 'closed build', 1, 0, '', $idP);
echo "build_open=$bOpen build_closed=$bClosed\n";

// Record a prior execution on the OPEN build so the popup shows the prior box.
$execSign = new stdClass();
$execSign->tproject_id = $idP;
$execSign->tplan_id = $idPlan;
$execSign->build_id = $bOpen;
$execSign->platform_id = 0;
$execSign->user_id = $userId;

$execData = array();
$execData['statusSingle'][$v1Id] = 'p';
$execData['tc_version'][$v1Id] = $idT;
$execData['version_number'][$v1Id] = intval($rows[0]['version']);
$execData['notes'][$v1Id] = 'prior run on open build';
$execData['execution_duration'] = '2';
$stepNotes = array();
$stepStatus = array();
foreach ($steps as $i => $st) {
    $stepRows = $tcaseMgr->get_steps($v1Id);
    foreach ($stepRows as $s) {
        if (intval($s['step_number']) === $st['step_number']) {
            $stepNotes[intval($s['id'])] = 'step done';
            $stepStatus[intval($s['id'])] = 'p';
            break;
        }
    }
}
if (count($stepNotes) > 0) {
    $execData['step_notes'] = $stepNotes;
    $execData['step_status'] = $stepStatus;
    $_REQUEST['step_notes'] = $stepNotes;
}

$issueTracker = null;
write_execution($db, $execSign, $execData, $issueTracker);
echo "prior execution recorded on build $bOpen\n";

echo "DONE: project=$idP plan=$idPlan tc=$idT tcversion=$v1Id build_open=$bOpen build_closed=$bClosed\n";
?>