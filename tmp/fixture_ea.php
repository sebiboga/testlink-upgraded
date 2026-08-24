<?php
// Fixture builder for #655 (tcExecAssignment) browser testing.
// DB is re-imported fresh on every CI run, so run this again as needed:
//   php tmp/fixture_ea.php
require_once(dirname(__DIR__) . '/config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);
$T = tlObject::getDBTables();

function rootSuiteOf(&$db, $T, $tprojectId) {
    $nt = $db->fetchFirstRow(
        "SELECT id FROM {$T['node_types']} WHERE description='testsuite'");
    $row = $db->fetchFirstRow(
        "SELECT id FROM {$T['nodes_hierarchy']} " .
        "WHERE parent_id=" . intval($tprojectId) .
        " AND node_type_id=" . intval($nt['id']));
    // TL allows TCs directly under the project node (no implicit root suite)
    return $row ? intval($row['id']) : intval($tprojectId);
}

/* ---- 1. users: clone admin password hash for tester + low-rights user --- */
$admin = $db->fetchFirstRow("SELECT * FROM {$T['users']} WHERE login='admin'");
$roles = $db->fetchRowsIntoMap(
    "SELECT id,description FROM {$T['roles']}", 'description');
$testerRoleId = intval($roles['tester']['id'] ?? 8);

function ensureUser(&$db, $T, $admin, $login, $first, $last, $roleId) {
    $u = $db->fetchFirstRow(
        "SELECT id FROM {$T['users']} WHERE login='" . $login . "'");
    if ($u) { return intval($u['id']); }
    $cookie = md5($login . microtime());
    $db->exec_query("INSERT INTO {$T['users']} " .
        "(login,password,first,last,email,role_id,locale,active,cookie_string) VALUES (" .
        "'" . $login . "','" . $db->prepare_string($admin['password']) . "'," .
        "'" . $first . "','" . $last . "','" . $login . "@example.com'," .
        intval($roleId) . ",'en_GB',1,'" . $cookie . "')");
    $u = $db->fetchFirstRow(
        "SELECT id FROM {$T['users']} WHERE login='" . $login . "'");
    return intval($u['id']);
}
$tester1 = ensureUser($db, $T, $admin, 'etester', 'Ella', 'Tester', $testerRoleId);
$plain1  = ensureUser($db, $T, $admin, 'eplain', 'Paul', 'Plain', 3); // guest=3? low rights
echo "users etester=$tester1 eplain=$plain1\n";

/* ---- 2. test project ---------------------------------------------------- */
$tprojMgr = new testproject($db);
$existing = $tprojMgr->get_by_name('EA Demo');
$existing = is_array($existing) && isset($existing[0]) ? $existing[0] : null;
if ($existing && intval($existing['id']) > 0) {
    $tpid = intval($existing['id']);
} else {
    $opts = new stdClass();
    foreach (array('requirementsEnabled','testPriorityEnabled',
                   'automationEnabled','inventoryEnabled') as $k) {
        $opts->$k = 0;
    }
    $opts->testPriorityEnabled = 1;
    $item = new stdClass();
    $item->name = 'EA Demo';
    $item->color = '';
    $item->notes = 'fixture for #655';
    $item->prefix = 'EAD';
    $item->options = $opts;
    $item->is_public = 1;
    $item->active = 1;
    $tpid = intval($tprojMgr->create($item));
}
echo "tproject=$tpid\n";
// TCs must live under a testsuite (create_tcase_only resolves the project
// through get_path(), which is empty for the root node itself)
$tsMgr = new testsuite($db);
$rootSuite = 0;
$nt = $db->fetchFirstRow(
    "SELECT id FROM {$T['node_types']} WHERE description='testsuite'");
foreach ((array)$db->fetchRowsIntoMap(
        "SELECT id,name FROM {$T['nodes_hierarchy']} WHERE parent_id={$tpid}" .
        " AND node_type_id=" . intval($nt['id']), 'id') as $row) {
    if ($row['name'] == 'EA Suite') { $rootSuite = intval($row['id']); }
}
if ($rootSuite <= 0) {
    $res = $tsMgr->create($tpid, 'EA Suite', 'fixture suite');
    $rootSuite = intval(is_array($res) ? $res['id'] : $res);
}
echo "rootSuite=$rootSuite\n";

/* ---- 3. test cases ------------------------------------------------------ */
$tcaseMgr = new testcase($db);
$tcIds = [];
foreach (array(array('Login works', 3), array('Logout works', 2),
               array('Search works', 1)) as $ix => $def) {
    $found = $db->fetchFirstRow(
        "SELECT NHTC.id FROM {$T['nodes_hierarchy']} NHTC " .
        " WHERE NHTC.parent_id={$rootSuite} AND NHTC.name='" .
        $def[0] . "'");
    if ($found) { $tcIds[] = intval($found['id']); continue; }
    $ret = $tcaseMgr->create($rootSuite, $def[0], 'summary ' . $def[0],
                             '', array(), $admin['id'], '',
                             testcase::DEFAULT_ORDER,
                             testcase::AUTOMATIC_ID,
                             TESTCASE_EXECUTION_TYPE_MANUAL, $def[1]);
    $tcIds[] = is_array($ret) ? intval($ret['id']) : intval($ret);
}
echo "tcases=" . implode(',', $tcIds) . "\n";

/* ---- 4. test plan ------------------------------------------------------- */
$tplanMgr = new testplan($db);
$planId = 0;
$ntPlan = $db->fetchFirstRow(
    "SELECT id FROM {$T['node_types']} WHERE description='testplan'");
foreach ((array)$db->fetchRowsIntoMap(
        "SELECT id,name FROM {$T['nodes_hierarchy']} WHERE parent_id={$tpid}" .
        " AND node_type_id=" . intval($ntPlan['id']), 'id') as $p) {
    if ($p['name'] == 'EA Plan') { $planId = intval($p['id']); }
}
if ($planId <= 0) {
    $planId = intval($tplanMgr->create('EA Plan', 'fixture #655', $tpid, 1, 1));
}
echo "tplan=$planId\n";

/* ---- 5. platforms + link to plan ---------------------------------------- */
$platMgr = new tlPlatform($db, $tpid);
$platIds = [];
foreach (array('Chrome', 'Firefox') as $pname) {
    $pid = $platMgr->getID($pname);
    if (!$pid) {
        $po = new stdClass();
        $po->name = $pname;
        $po->notes = '';
        $po->enable_on_design = 1;
        $po->enable_on_execution = 1;
        $po->is_open = 1;
        $res = $platMgr->create($po);
        $pid = intval(is_array($res) ? $res['id'] : $res);
    }
    $db->exec_query(
        "INSERT INTO {$T['testplan_platforms']} " .
        "(testplan_id,platform_id,active) VALUES ({$planId},{$pid},1) " .
        "ON DUPLICATE KEY UPDATE active=1");
    $platIds[] = intval($pid);
}
echo "platforms=" . implode(',', $platIds) . "\n";

/* ---- 6. builds (two open active ones) ----------------------------------- */
$bset = $tp = $tplanMgr->get_builds($planId, null, null);
$haveNames = array();
foreach ((array)$bset as $b) { $haveNames[$b['name']] = true; }
foreach (array('B1', 'B2') as $bname) {
    if (!isset($haveNames[$bname])) {
        $sql = "INSERT INTO {$T['builds']} " .
            "(testplan_id,name,notes,active,is_open,author_id,creation_ts) VALUES (" .
            "{$planId},'{$bname}','fixture',1,1,{$admin['id']}," .
            $db->db_now() . ")";
        $db->exec_query($sql);
    }
}

/* ---- 7. link test cases to plan on both platforms ----------------------- */
$latest = array();
foreach ($tcIds as $tid) {
    $r = $db->fetchFirstRow(
        "SELECT TCV.id FROM {$T['tcversions']} TCV " .
        " JOIN {$T['nodes_hierarchy']} NH ON NH.id=TCV.id" .
        " WHERE NH.parent_id={$tid} ORDER BY TCV.version DESC");
    $latest[$tid] = intval($r['id']);
}
$already = array();
$links = $db->fetchRowsIntoMap(
    "SELECT tcversion_id, platform_id FROM {$T['testplan_tcversions']} " .
    "WHERE testplan_id={$planId}", 'tcversion_id');
$toLink = array('tcversion' => array(), 'platform' => array(),
                'items' => array());
foreach ($tcIds as $tid) {
    foreach ($platIds as $pid) {
        if (!isset($links[$latest[$tid]])) {
            $toLink['items'][$tid][$pid] = $latest[$tid];
            $toLink['tcversion'][$tid] = $latest[$tid];
            $toLink['platform'][$pid] = $pid;
        } elseif (!isset($links[$latest[$tid]][$pid])) {
            $toLink['items'][$tid][$pid] = $latest[$tid];
            $toLink['tcversion'][$tid] = $latest[$tid];
            $toLink['platform'][$pid] = $pid;
        }
    }
}
if (count($toLink['items']) > 0) {
    $tplanMgr->link_tcversions($planId, $toLink, $admin['id']);
}
echo "linked OK\n";

/* ---- 8. pre-assign one tester on B1/Chrome/TC1 via legacy manager -------- */
$buildRow = $db->fetchFirstRow(
    "SELECT id FROM {$T['builds']} WHERE testplan_id={$planId} AND name='B1'");
$buildId = intval($buildRow['id']);
$assignMgr = new assignment_mgr($db);
$typesMap = $assignMgr->get_available_types();
$statusMap = $assignMgr->get_available_status();
$execType = intval($typesMap['testcase_execution']['id']);
$openStatus = intval($statusMap['open']['id']);
$feat = $db->fetchFirstRow(
    "SELECT TPTCV.id FROM {$T['testplan_tcversions']} TPTCV" .
    " JOIN {$T['platforms']} P ON P.id=TPTCV.platform_id AND P.name='Chrome'" .
    " JOIN {$T['nodes_hierarchy']} NH ON NH.id=TPTCV.tcversion_id" .
    " WHERE TPTCV.testplan_id={$planId}" .
    " ORDER BY NH.parent_id ASC LIMIT 1");
if ($feat) {
    $v = array();
    $v[intval($feat['id'])]['user_id'] = $tester1;
    $v[intval($feat['id'])]['type'] = $execType;
    $v[intval($feat['id'])]['status'] = $openStatus;
    $v[intval($feat['id'])]['creation_ts'] = $db->db_now();
    $v[intval($feat['id'])]['assigner_id'] = $admin['id'];
    $v[intval($feat['id'])]['build_id'] = $buildId;
    $v[intval($feat['id'])]['tcase_id'] = $tcIds[0];
    $v[intval($feat['id'])]['tcversion_id'] = $latest[$tcIds[0]];
    $assignMgr->assign($v);
}
echo "DONE fixture: tproject=$tpid tplan=$planId build(B1)=$buildId tcases=" .
     implode(',', $tcIds) . " testers(etester=$tester1,eplain=$plain1)\n";
