<?php
// Fixture for #1767 browser/API testing: Test Case Summary viewer
// (gui/templates/testcases/tcSummary.html + api/tcsummary/index.php).
//
// Creates TWO test projects so the BFF's cross-project guards can be exercised:
//
//   tproject SUM1 (prefix TS1767), plan "Summary Plan"
//     suite "Summary Root"
//       sub-suite "Summary Sub"
//         tcase  TS1767 Rich      -> v1 summary is a RichEdit blob with markup
//         tcase  TS1767 Empty     -> v1 summary is an EMPTY string
//         tcase  TS1767 TwoVers   -> v1 + v2 (different summaries)
//   tproject SUM2 (prefix TS1768)  -> foreign project, must 403/404
//   user   sumnorights (role 3)    -> the 403 path
//
// Run from repo root: php tmp/fixtures_1767.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tplanMgr = new testplan($db);
$tsuiteMgr = new testsuite($db);
$tcaseMgr = new testcase($db);
$userId = 1; // admin

function dropProject($tprojMgr, $name)
{
    foreach ((array)$tprojMgr->get_by_name($name) as $row) {
        $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
        if ($oid > 0) {
            echo "deleting old project $name ($oid)\n";
            $tprojMgr->delete($oid, 1);
        }
    }
}
dropProject($tprojMgr, 'SUM1');
dropProject($tprojMgr, 'SUM2');

function makeProject($tprojMgr, $name, $prefix, $notes)
{
    $item = new stdClass();
    $item->name = $name;
    $item->prefix = $prefix;
    $item->notes = $notes;
    $item->color = '';
    $item->active = 1;
    $item->is_public = 1;
    $opts = new stdClass();
    $opts->requirementsEnabled = 0;
    $opts->testPriorityEnabled = 0;
    $opts->automationEnabled = 0;
    $opts->inventoryEnabled = 0;
    $opts->platformsEnabled = 0;
    $opts->testcasecfEnabled = 0;
    $opts->requirementcfEnabled = 0;
    $item->options = $opts;
    $r = $tprojMgr->create($item);
    $id = intval($r);
    if ($id <= 0) {
        die("tproject create failed for $name\n");
    }
    $tprojMgr->setActive($id);
    return $id;
}

$idP = makeProject($tprojMgr, 'SUM1', 'TS1767', 'fixture for #1767 (tc summary viewer)');
$idPAlt = makeProject($tprojMgr, 'SUM2', 'TS1768', 'fixture for #1767 (foreign project)');
echo "tproject SUM1=$idP  SUM2=$idPAlt\n";

$idPlan = intval($tplanMgr->create('Summary Plan', '', $idP, 1, 1));
echo "testplan=$idPlan\n";

$retS = $tsuiteMgr->create($idP, 'Summary Root', '', null, 0, 'allow_repeat');
$idRoot = intval($retS['id'] ?? 0);
$retSub = $tsuiteMgr->create($idRoot, 'Summary Sub', '', null, 0, 'allow_repeat');
$idSub = intval($retSub['id'] ?? 0);
echo "suite root=$idRoot sub=$idSub\n";

function mkSteps($n)
{
    $steps = array();
    for ($i = 1; $i <= $n; $i++) {
        $t = new stdClass();
        $t->step_number = $i;
        $t->actions = 'Do action number ' . $i;
        $t->expected_results = 'Expected result of step ' . $i;
        $t->execution_type = TESTCASE_EXECUTION_TYPE_MANUAL;
        $steps[] = $t;
    }
    return $steps;
}

$makeTcase = function ($name, $summary) use ($tcaseMgr, $idSub, $userId) {
    $ret = $tcaseMgr->create($idSub, $name, $summary, '', mkSteps(2), $userId, '',
        testcase::DEFAULT_ORDER, testcase::AUTOMATIC_ID, TESTCASE_EXECUTION_TYPE_MANUAL);
    if (!$ret['status_ok'] || $ret['id'] <= 0) {
        die("tcase create failed for $name: " . ($ret['message'] ?? '') . "\n");
    }
    return array(intval($ret['id']), intval($ret['tcversion_id']));
};

// RichEdit blob with markup + a script-ish payload: proves the BFF does NOT
// ship markup as HTML to the caller (the legacy endpoint echoed it raw).
list($idRich, $idRichV1) = $makeTcase(
    'TS1767 Rich Summary',
    '<p>Verify the <b>login</b> form with <i>special</i> chars &amp; entities.</p>'
    . '<p><img src=x onerror="window.__xss=1">second paragraph</p>'
    . '<script>window.__xss=1;<\/script>');
echo "tcase rich=$idRich v1=$idRichV1\n";

list($idEmpty, $idEmptyV1) = $makeTcase('TS1767 Empty Summary', '');
echo "tcase empty=$idEmpty v1=$idEmptyV1\n";

list($idTwo, $idTwoV1) = $makeTcase('TS1767 Two Versions', 'summary of version one');
$ret2 = $tcaseMgr->create($idTwo, 'TS1767 Two Versions',
    'summary of version TWO - the newer one', '', mkSteps(1), $userId, '',
    testcase::DEFAULT_ORDER, testcase::AUTOMATIC_ID, TESTCASE_EXECUTION_TYPE_MANUAL);
$idTwoV2 = intval($ret2['tcversion_id'] ?? 0);
echo "tcase two=$idTwo v1=$idTwoV1 v2=$idTwoV2\n";

// foreign-project test case
$retSAlt = $tsuiteMgr->create($idPAlt, 'Foreign Suite', '', null, 0, 'allow_repeat');
$idSAlt = intval($retSAlt['id'] ?? 0);
$retAlt = $tcaseMgr->create($idSAlt, 'TS1768 Foreign Case', 'secret summary of another project',
    '', mkSteps(1), $userId, '', testcase::DEFAULT_ORDER, testcase::AUTOMATIC_ID,
    TESTCASE_EXECUTION_TYPE_MANUAL);
$idAlt = intval($retAlt['id'] ?? 0);
$idAltV = intval($retAlt['tcversion_id'] ?? 0);
echo "tcase foreign=$idAlt v1=$idAltV\n";

// no-rights user for the 403 path (global role 3)
if (file_exists('tmp/mkuser_norights.php')) {
    include 'tmp/mkuser_norights.php';
} else {
    echo "WARN: tmp/mkuser_norights.php not found - 403 path needs a manual user\n";
}

echo json_encode(array(
    'tproject' => $idP,
    'tproject_alt' => $idPAlt,
    'tplan' => $idPlan,
    'suite_root' => $idRoot,
    'suite_sub' => $idSub,
    'tcase_rich' => $idRich,
    'tcversion_rich_v1' => $idRichV1,
    'tcase_empty' => $idEmpty,
    'tcversion_empty_v1' => $idEmptyV1,
    'tcase_two' => $idTwo,
    'tcversion_two_v1' => $idTwoV1,
    'tcversion_two_v2' => $idTwoV2,
    'tcase_foreign' => $idAlt,
    'tcversion_foreign_v1' => $idAltV,
)) . "\n";
