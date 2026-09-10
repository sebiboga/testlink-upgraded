<?php
// Fixture for Set Results popup (execSetResults.html + api/execsetresults, Refs #817)
// Run from repo root: php tmp/fixtures_817.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tsuiteMgr = new testsuite($db);
$tcaseMgr = new testcase($db);
$tplanMgr = new testplan($db);

function fid($r) {
    if (is_array($r)) {
        if (isset($r['id'])) { return intval($r['id']); }
        $k = array_keys($r);
        return intval($k[0]);
    }
    return intval($r);
}

// --- idempotent cleanup ---
$old = $tprojMgr->get_by_name('ESR817');
foreach ((array)$old as $row) {
    $o = intval($row['id']);
    if ($o > 0) { echo "deleting old ESR817 $o\n"; $tprojMgr->delete($o, 1); }
}

$item = new stdClass();
$item->name = 'ESR817';
$item->prefix = 'E817';
$item->notes = 'Set Results popup analysis fixture';
$item->color = '';
$item->active = 1;
$item->is_public = 1;
$opts = new stdClass();
$opts->requirementsEnabled = 0;
$opts->testPriorityEnabled = 0;
$opts->automationEnabled = 1;
$opts->inventoryEnabled = 0;
$item->options = $opts;
$idP = fid($tprojMgr->create($item));
echo "tproject=$idP\n";
$db->exec_query("UPDATE testprojects SET options='" .
    $db->prepare_string(serialize($opts)) . "' WHERE id=$idP");

$idS1 = fid($tsuiteMgr->create($idP, 'Suite One', 'suite one desc', null, null, 1));
$idS2 = fid($tsuiteMgr->create($idP, 'Suite Two', 'suite two desc', null, null, 1));
echo "tsuite1=$idS1 tsuite2=$idS2\n";

// --- test cases with 2 steps each ---
$tcv = [];
$tcid = [];
$stepsOf = [];
foreach ([['Case One', $idS1], ['Case Two', $idS1], ['Case Three', $idS2]] as $i => [$nm, $suit]) {
    $idTC = fid($tcaseMgr->create($suit, $nm, 'summary of ' . $nm, 'precond of ' . $nm,
        [['step_number' => 1, 'actions' => 'action ' . $nm,
          'expected_results' => 'expected ' . $nm],
         ['step_number' => 2, 'actions' => 'act2 ' . $nm,
          'expected_results' => 'exp2 ' . $nm]], 1));
    $rr = $db->get_recordset(
        " SELECT NH.id FROM nodes_hierarchy NH JOIN tcversions TV ON TV.id = NH.id" .
        " WHERE NH.parent_id = " . intval($idTC) . " AND TV.active = 1 ORDER BY TV.version");
    $tvid = intval($rr[0]['id']);
    $tcv[$nm] = $tvid;
    $tcid[$nm] = $idTC;
    $sr = $db->get_recordset(
        " SELECT TC.id FROM tcsteps TC JOIN nodes_hierarchy NH ON NH.id = TC.id" .
        " WHERE NH.parent_id = " . intval($tvid) . " ORDER BY TC.step_number");
    $stepsOf[$nm] = array_map(fn($r) => intval($r['id']), $sr);
}
echo "tcase nodes: " . json_encode($tcid) . "\n";
echo "tcversion ids: " . json_encode($tcv) . "\n";
echo "steps: " . json_encode($stepsOf) . "\n";

// --- platform ---
$db->exec_query("INSERT INTO platforms (name, notes, testproject_id) VALUES ('ESR-Android', 'android platform', $idP)");
$platId = intval($db->get_recordset("SELECT MAX(id) AS id FROM platforms WHERE testproject_id = $idP")[0]['id']);
echo "platform=$platId\n";

// --- two test plans, both linked to all TCs ---
$idTP1 = fid($tplanMgr->create('Plan817', 'set results plan one', $idP, 1, 1));
$idTP2 = fid($tplanMgr->create('Plan817B', 'set results plan two', $idP, 1, 1));
echo "tplan a=$idTP1 b=$idTP2\n";

$linkItems = ['items' => [], 'tcversion' => []];
foreach ($tcv as $nm => $tv) {
    $tci = intval($db->get_recordset(" SELECT parent_id AS id FROM nodes_hierarchy WHERE id = " . intval($tv))[0]['id']);
    $linkItems['items'][$tci] = [0 => $tv];
    $linkItems['tcversion'][$tci] = $tv;
}
$tplanMgr->link_tcversions($idTP1, $linkItems, 1, array('getTCPrefixFromTPlan' => true));
$tplanMgr->link_tcversions($idTP2, $linkItems, 1, array('getTCPrefixFromTPlan' => true));
echo "Tcs linked to both plans\n";

// --- link platform to plan1 ---
$db->exec_query("INSERT INTO testplan_platforms (testplan_id, platform_id) VALUES ($idTP1, $platId)");
echo "plan1 linked platform $platId\n";

// --- builds: open build per plan ---
$buildMgr = new build($db);
$b1 = fid($buildMgr->create($idTP1, 'REL-1', 'release 1'));
$b2 = fid($buildMgr->create($idTP2, 'REL-2', 'release 2'));
echo "builds b1=$b1 (plan1) b2=$b2 (plan2)\n";

// --- read-only role 10 'ro viewer' (only exec_ro_access=49) ---
$roleQ = $db->get_recordset("SELECT id FROM roles WHERE id = 10");
if (empty($roleQ)) {
    $db->exec_query("INSERT INTO roles (id, description) VALUES (10, 'ro viewer')");
    $db->exec_query("INSERT INTO role_rights (role_id, right_id) VALUES (10, 49)");
    echo "role 10 'ro viewer' created with exec_ro_access\n";
} else {
    echo "role 10 exists\n";
}

// --- read-only user ro817 (role 10) ---
$roQ = $db->get_recordset("SELECT id FROM users WHERE login = 'ro817'");
if (empty($roQ)) {
    $db->exec_query("INSERT INTO users (login, password, email, first, last, locale, active, role_id, cookie_string) VALUES ('ro817', '" .
        $db->prepare_string(md5('ro817')) . "', 'ro817@x.y', 'RO', '817', 'en_GB', 1, 10, '" .
        $db->prepare_string(bin2hex(random_bytes(16))) . "')");
    $roId = intval($db->get_recordset("SELECT MAX(id) AS id FROM users WHERE login = 'ro817'")[0]['id']);
    echo "user ro817 id=$roId\n";
} else {
    $roId = intval($roQ[0]['id']);
    echo "user ro817 exists id=$roId\n";
}
$db->exec_query("DELETE FROM user_testproject_roles WHERE user_id=$roId AND testproject_id=$idP");
$db->exec_query("INSERT INTO user_testproject_roles (user_id, testproject_id, role_id) VALUES ($roId, $idP, 10)");
echo "role 10 assigned to ro817 on project $idP\n";

echo "DONE\n";