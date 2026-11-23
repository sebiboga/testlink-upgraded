<?php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);
$tprojMgr = new testproject($db);
$tsuiteMgr = new testsuite($db);
$tcaseMgr = new testcase($db);
$reqSpecMgr = new requirement_spec_mgr($db);
$reqMgr = new requirement_mgr($db);
$userId = 1;
$tables = tlObjectWithDB::getDBTables(array('req_coverage', 'users', 'nodes_hierarchy', 'testprojects'));
$views = tlObjectWithDB::getDBViews(array('latest_req_version_id'));
$tbl = $tables;

foreach ((array)$tprojMgr->get_by_name('RTG2047') as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) { $tprojMgr->delete($oid, 1); }
}
$item = new stdClass();
$item->name = 'RTG2047'; $item->prefix = 'RTG'; $item->notes = 'fixture #2047';
$item->color = ''; $item->active = 1; $item->is_public = 1;
$opts = new stdClass();
$opts->requirementsEnabled = 1; $opts->testPriorityEnabled = 0;
$opts->automationEnabled = 0; $opts->inventoryEnabled = 0;
$opts->platformsEnabled = 0; $opts->testcasecfEnabled = 0;
$opts->requirementcfEnabled = 0; $opts->testScriptEnabled = 0;
$item->options = $opts;
$idP = intval($tprojMgr->create($item));
if ($idP <= 0) { die("project create failed\n"); }
$tprojMgr->setActive($idP, 1);
$_SESSION['testprojectID'] = $idP;
echo "tproject=$idP\n";

$retS = $tsuiteMgr->create($idP, 'RTG Suite', 'fixture suite', null, 0, 'allow_repeat');
$suiteId = intval($retS['id'] ?? 0);
if ($suiteId <= 0) { die("suite create failed\n"); }
$steps = array();
$s = new stdClass();
$s->step_number = 1; $s->actions = 'Do it'; $s->expected_results = 'Works';
$s->execution_type = TESTCASE_EXECUTION_TYPE_MANUAL;
$steps[] = $s;
$cases = array();
foreach (array('alpha', 'beta', 'gamma') as $key) {
    $ret = $tcaseMgr->create($suiteId, 'RTG Case ' . $key, 'summary ' . $key, '', $steps,
        1, '', testcase::DEFAULT_ORDER, testcase::AUTOMATIC_ID,
        TESTCASE_EXECUTION_TYPE_MANUAL);
    if (!$ret['status_ok']) { die("tcase failed: $key\n"); }
    $tcv = intval($ret['tcversion_id']);
    $parent = $db->get_recordset("SELECT parent_id FROM {$tbl['nodes_hierarchy']} WHERE id = " . intval($tcv));
    $cases[$key] = array('tc' => intval($parent[0]['parent_id'] ?? 0), 'tcv' => $tcv);
    echo "case $key={$cases[$key]['tc']} v={$tcv}\n";
}
$ret = $tcaseMgr->create_new_version($cases['beta']['tc'], $userId, $cases['beta']['tcv']);
$cases['beta']['v2'] = intval(is_array($ret) ? ($ret['id'] ?? 0) : $ret);
if ($cases['beta']['v2'] <= 0) { die("beta v2 failed\n"); }
echo "beta v2={$cases['beta']['v2']}\n";

$specs = array();
foreach (array('A' => 'RTG SPEC A', 'B' => 'RTG SPEC B') as $key => $name) {
    $op = $reqSpecMgr->create($idP, $idP, 'RTG-RS-' . $key, $name, 'fixture spec',
        3, $userId, TL_REQ_SPEC_TYPE_USER_REQ_SPEC);
    if (empty($op['status_ok']) || intval($op['id'] ?? 0) <= 0) {
        die('spec create failed: ' . json_encode($op) . "\n");
    }
    $specs[$key] = intval($op['id']);
    echo "spec $key={$specs[$key]}\n";
}
$mkr = function ($specKey, $docId, $expected) use ($reqMgr, $specs, $userId, $idP) {
    $r = $reqMgr->create($specs[$specKey], $docId, 'Title ' . $docId, 'Scope ' . $docId,
        $userId, TL_REQ_STATUS_VALID, TL_REQ_TYPE_INFO, $expected, 1, $idP);
    if (empty($r['status_ok']) || intval($r['id'] ?? 0) <= 0) {
        die('req create failed ' . $docId . ': ' . json_encode($r) . "\n");
    }
    return intval($r['id']);
};
$reqs = array(
    'ok' => $mkr('A', 'RTG-OK', 1),
    'stale' => $mkr('A', 'RTG-STALE', 1),
    'closed' => $mkr('A', 'RTG-CLOSED', 1),
    'uncovered' => $mkr('B', 'RTG-UNCOVERED', 1),
    'under' => $mkr('B', 'RTG-UNDER', 3),
    'na' => $mkr('B', 'RTG-NA', 0),
);
$lrvi = $db->get_recordset('SELECT req_id, req_version_id FROM ' . $views['latest_req_version_id'] . ' WHERE req_id IN (' .
    implode(',', $reqs) . ')');
$rv = array();
foreach ((array)$lrvi as $row) { $rv[intval($row['req_id'])] = intval($row['req_version_id']); }
$links = array(
    array($reqs['ok'], $cases['alpha'], 1, 1),
    array($reqs['stale'], $cases['beta'], 1, 1),
    array($reqs['closed'], $cases['gamma'], 4, 0),
    array($reqs['under'], $cases['alpha'], 1, 1),
    array($reqs['na'], $cases['alpha'], 1, 1),
    array($reqs['ok'], $cases['beta'], 3, 0),
);
$rows = array();
foreach ($links as $l) {
    $rid = intval($l[0]); $case = $l[1];
    $rows[] = '(' . $rid . ',' . intval($rv[$rid]) . ',' . intval($case['tc']) . ',' .
              intval($case['tcv']) . ',' . intval($l[2]) . ',' . intval($l[3]) . ',' . $userId . ')';
}
$db->exec_query('INSERT INTO ' . $tables['req_coverage'] .
    ' (req_id,req_version_id,testcase_id,tcversion_id,link_status,is_active,author_id) VALUES ' .
    implode(',', $rows));
echo 'links=' . count($rows) . "\n";

$hash = password_hash('rtg2047norights', PASSWORD_DEFAULT);
$db->exec_query("INSERT INTO {$tables['users']}
    (login,password,role_id,email,first,last,locale,default_testproject_id,
     active,cookie_string,auth_method)
  VALUES ('rtg2047norights','$hash',3,'n@no.no','No','Rights','en_GB',$idP,1,
          'ck_rtg2047norights','DB')
  ON DUPLICATE KEY UPDATE password=VALUES(password),role_id=3,active=1,
    default_testproject_id=$idP");
$emptyRows = $db->get_recordset("SELECT id FROM {$tbl['testprojects']} WHERE prefix = 'RTE' ORDER BY id LIMIT 1");
$idEmpty = intval($emptyRows[0]['id'] ?? 2);
echo 'FIXTURE READY tproject=' . $idP . ' empty=' . $idEmpty . "\n";
