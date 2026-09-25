<?php
// Fixture for #1587 browser/curl testing: Remote Test Automation Execution
// modern screen (gui/templates/testcases/tcAutoExec.html + api/tcautoexec/index.php).
//
// Creates tproject `AutoExec Demo` (prefix AX1587) with:
//   - test plan + open build + platform
//   - suite tree:  AX1587-A / AX1587-A1 (2 TCs: one with tc_-prefixed
//     automation server custom fields, one without) / AX1587-B (1 TC)
//   - the three testcase-level custom fields  tc_server_host / tc_server_port /
//     tc_server_path  (type 2 = string) linked+active for the project, with a
//     value on one TC -> that TC resolves an endpoint, the others do not
//     (configProblems parity path)
//   - the three testsuite-level custom fields tsuite_server_host / _port / _path
//     with a value on suite A1 -> cascade path (server_source = testsuite)
//
// Re-runnable. Run from repo root:  php tmp/fixtures_1587.php
require_once('config.inc.php');
require_once('common.php');
require_once(TL_ABS_PATH . 'lib/functions/cfield_mgr.class.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tplanMgr = new testplan($db);
$tcaseMgr = new testcase($db);
$tsuiteMgr = new testsuite($db);
$buildMgr = new build($db);
$platformMgr = new tlPlatform($db);
$cfMgr = new cfield_mgr($db);
$userId = 1; // admin

// --- reset on re-runs ---------------------------------------------------
foreach ((array)$tprojMgr->get_by_name('AutoExec Demo') as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

// --- test project -------------------------------------------------------
$item = new stdClass();
$item->name = 'AutoExec Demo';
$item->prefix = 'AX1587';
$item->notes = 'fixture for issue 1587 (Remote Test Automation Execution)';
$item->color = '';
$item->active = 1;
$item->is_public = 1;
$item->option_reqs = 0;
$item->option_priority = 0;
$item->option_automation = 0;
$opts = new stdClass();
$opts->requirementsEnabled = 0;
$opts->testPriorityEnabled = 1;
$opts->automationEnabled = 0;
$opts->inventoryEnabled = 0;
$opts->platformsEnabled = 1;
$opts->testcasecfEnabled = 1;
$opts->requirementcfEnabled = 0;
$item->options = $opts;
$item->api_key = '1587000000000000000000000000000a';
$tid = intval($tprojMgr->create($item));
echo "project $tid\n";

// --- test plan + build + platform ---------------------------------------
$plid = intval($tplanMgr->create('AutoExec Plan', '', $tid, 1, 1));
echo "plan $plid\n";

$bdid = intval($buildMgr->create($plid, 'AutoExec Build 1', '', 1, 1, '', $tid));
echo "build $bdid\n";

$platformMgr->setTestProjectID($tid);
$pf = new stdClass();
$pf->name = 'AutoExec Win11';
$pf->notes = '';
$pf->testproject_id = $tid;
$pf->enable_on_design = 1;
$pf->enable_on_execution = 1;
$opPf = $platformMgr->create($pf);
if (intval($opPf['id'] ?? 0) <= 0) { die("platform create failed\n"); }
$pfid = intval($opPf['id']);
$platformMgr->linkToTestplan($pfid, $plid);
echo "platform $pfid\n";

// --- suite tree + test cases -------------------------------------------
$mkSuite = function ($name, $parent) use ($tsuiteMgr) {
    $r = $tsuiteMgr->create($parent, $name, '', null, 0, 'allow_repeat');
    return intval($r['id'] ?? 0);
};
$mkTc = function ($name, $parent) use ($tcaseMgr, $userId) {
    $steps = array();
    $steps[0] = new stdClass();
    $steps[0]->step_number = 1;
    $steps[0]->actions = 'open app for ' . $name;
    $steps[0]->expected_results = 'app opens';
    $steps[0]->execution_type = TESTCASE_EXECUTION_TYPE_MANUAL;
    $ret = $tcaseMgr->create($parent, $name, 'auto exec fixture ' . $name, '',
                             $steps, $userId, '', testcase::DEFAULT_ORDER,
                             testcase::AUTOMATIC_ID,
                             TESTCASE_EXECUTION_TYPE_MANUAL, 5);
    if (empty($ret['status_ok']) || intval($ret['id'] ?? 0) <= 0) {
        die('tcase create failed: ' . ($ret['message'] ?? '') . "\n");
    }
    return intval($ret['id']);
};

$sA = $mkSuite('AX1587-A', $tid);
$sA1 = $mkSuite('AX1587-A1', $sA);
$sB = $mkSuite('AX1587-B', $tid);
$tc1 = $mkTc('AX1587-1 auto server on TC', $sA1);
$tc2 = $mkTc('AX1587-2 inherits suite server', $sA1);
$tc3 = $mkTc('AX1587-3 no server anywhere', $sB);
echo "suites $sA/$sA1/$sB tcs $tc1/$tc2/$tc3\n";

// --- custom fields: tc_ and tsuite_ automation server trios -------------
// custom_fields.name is globally unique -> clean leftovers from earlier runs.
foreach (array('tc_server_host', 'tc_server_port', 'tc_server_path',
               'tsuite_server_host', 'tsuite_server_port', 'tsuite_server_path') as $cfName) {
    foreach ((array)$db->get_recordset("SELECT id FROM custom_fields WHERE name = '{$cfName}'") as $row) {
        $fid = intval($row['id']);
        $db->exec_query("DELETE FROM custom_fields WHERE id = {$fid}");
        $db->exec_query("DELETE FROM cfield_node_types WHERE field_id = {$fid}");
        $db->exec_query("DELETE FROM cfield_testprojects WHERE field_id = {$fid}");
        $db->exec_query("DELETE FROM cfield_design_values WHERE field_id = {$fid}");
        echo "deleted leftover cfield {$cfName} ({$fid})\n";
    }
}

$tb = tlObjectWithDB::getDBTables(array('nodes_hierarchy', 'tcversions'));
$tcv1 = intval($db->fetchOneValue("SELECT MAX(NH.id) FROM {$tb['nodes_hierarchy']} NH, {$tb['tcversions']} TCV WHERE NH.parent_id = {$tc1} AND NH.node_type_id = 4 AND TCV.id = NH.id AND TCV.active = 1"));
echo "tcversion of tc $tc1 => $tcv1\n";
if ($tcv1 <= 0) {
    die("cannot resolve tcversion of test case $tc1\n");
}

// $cf = array(field name, value, node id to write on, node_type_id)
$tcNodeTypeId = 3;   // testcase
$tsNodeTypeId = 2;   // testsuite
$defs = array(
    array('tc_server_host', '127.0.0.1', $tcv1, $tcNodeTypeId),
    array('tc_server_port', '9999', $tcv1, $tcNodeTypeId),
    array('tc_server_path', '/xmlrpc.php', $tcv1, $tcNodeTypeId),
    array('tsuite_server_host', '127.0.0.1', $sA1, $tsNodeTypeId),
    array('tsuite_server_port', '9999', $sA1, $tsNodeTypeId),
    array('tsuite_server_path', '/xmlrpc.php', $sA1, $tsNodeTypeId),
);
$cfIds = array();
foreach ($defs as $d) {
    list($cfName, $cfValue, $cfNode, $cfNodeType) = $d;
    $cf = array(
        'name' => $cfName, 'label' => $cfName, 'type' => 2, // string
        'possible_values' => '',
        'show_on_design' => 1, 'enable_on_design' => 1,
        'show_on_testplan_design' => 0, 'enable_on_testplan_design' => 0,
        'show_on_execution' => 0, 'enable_on_execution' => 0,
        'node_type_id' => $cfNodeType,
    );
    $cfRet = $cfMgr->create($cf);
    if (empty($cfRet['status_ok']) || intval($cfRet['id'] ?? 0) <= 0) {
        die("cfield create failed: {$cfName}\n");
    }
    $cfId = intval($cfRet['id']);
    $cfIds[$cfName] = $cfId;
    $db->exec_query("INSERT IGNORE INTO cfield_testprojects (field_id, testproject_id, display_order, location, active) " .
                    "VALUES ({$cfId}, {$tid}, 1, 1, 1)");
    $db->exec_query("INSERT IGNORE INTO cfield_design_values (field_id, node_id, value) " .
                    "VALUES ({$cfId}, {$cfNode}, '{$cfValue}')");
    echo "cfield {$cfName}={$cfId} on node {$cfNode} (type {$cfNodeType}) = {$cfValue}\n";
}

echo "DONE fixture 1587 (project $tid, plan $plid, build $bdid, platform $pfid)\n";
