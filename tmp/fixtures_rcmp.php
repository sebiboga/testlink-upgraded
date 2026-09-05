<?php
// Fixture for Requirement Version Compare (api/reqcompare, Refs #1017)
// Run from repo root: php tmp/fixtures_rcmp.php
$_SESSION = array();
require_once(dirname(__DIR__) . '/config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$reqSpecMgr = new requirement_spec_mgr($db);
$reqMgr = new requirement_mgr($db);
$cfMgr = new cfield_mgr($db);

$userId = 1; // admin

// ---- project -----------------------------------------------------------
$name = 'RCMP Demo Project';
$rs = $tprojMgr->get_by_name($name);
if ($rs) {
    $tid = intval($rs[0]['id']);
    echo "project exists id=$tid\n";
} else {
    $item = new stdClass();
    $item->name = $name;
    $item->prefix = 'RCMP';
    $item->notes = 'fixture for #1017';
    $item->color = '';
    $item->active = 1;
    $item->is_public = 1;
    $tid = intval($tprojMgr->create($item));
    echo "project=$tid\n";
}
$tprojMgr->setActive($tid);
// enableRequirements() is broken on this upgraded schema (options blob 'N;'
// makes getOptions() return null) -> write the flags blob directly
$optObj = new stdClass();
$optObj->requirementsEnabled = 1;
$optObj->testPriorityEnabled = 1;
$optObj->automationEnabled = 1;
$optObj->inventoryEnabled = 0;
$db->exec_query("UPDATE testprojects SET options='" .
    $db->prepare_string(serialize($optObj)) . "' WHERE id=$tid");
// confirm requirements are enabled on the project
$opt = $tprojMgr->getOptions($tid);
echo "requirementsEnabled=" . (isset($opt->requirementsEnabled) ? $opt->requirementsEnabled : 0) . "\n";

// ---- spec --------------------------------------------------------------
$specRows = $db->get_recordset(
    "SELECT id FROM req_specs WHERE testproject_id=$tid AND doc_id='RC-SRS'");
$specId = 0;
if (!empty($specRows)) {
    $specId = intval($specRows[0]['id']);
    echo "spec exists id=$specId\n";
} else {
    $op = $reqSpecMgr->create($tid, $tid, 'RC-SRS', 'RCMP Fixture Spec',
        "Spec fixture scope for requirement version compare.", 3, $userId,
        TL_REQ_SPEC_TYPE_USER_REQ_SPEC);
    if (!$op['status_ok'] || $op['id'] <= 0) { die("spec create failed: " . $op['msg'] . "\n"); }
    $specId = intval($op['id']);
    echo "spec=$specId\n";
}

// ---- custom field (requirement) ---------------------------------------
$nt = $db->get_recordset("SELECT id FROM node_types WHERE description='requirement'");
$reqNodeType = intval($nt[0]['id']);
$cfRows = $db->get_recordset("SELECT id FROM custom_fields WHERE name='rcmp_milestone'");
if (!empty($cfRows)) {
    $cfId = intval($cfRows[0]['id']);
    $db->exec_query("DELETE FROM cfield_design_values WHERE field_id=$cfId");
    echo "reusing cfield id=$cfId\n";
} else {
    $cf = [
        'name' => 'rcmp_milestone',
        'label' => 'Milestone',
        'type' => 0,
        'possible_values' => '',
        'show_on_design' => 1,
        'enable_on_design' => 1,
        'show_on_testplan_design' => 0,
        'enable_on_testplan_design' => 0,
        'show_on_execution' => 0,
        'enable_on_execution' => 0,
        'node_type_id' => $reqNodeType,
    ];
    $cfo = $cfMgr->create($cf);
    $cfId = $cfo['id'];
    echo "cfield id=$cfId\n";
    $cfMgr->link_to_testproject($tid, [$cfId]);
}

// ---- requirement --------------------------------------------------------
$reqId = null;
$reqRows = $db->get_recordset("SELECT id FROM requirements WHERE req_doc_id='REQ-100'");
if (!empty($reqRows)) {
    $reqId = intval($reqRows[0]['id']);
    echo "req exists id=$reqId\n";
} else {
    $r = $reqMgr->create($specId, 'REQ-100', 'RCMP Fixture Requirement',
        "Original scope paragraph.\nSecond original paragraph.", $userId,
        TL_REQ_STATUS_VALID, TL_REQ_TYPE_SYSTEM_FUNCTION, 3);
    if (!$r['status_ok'] && !isset($r['id'])) { die("req create failed: " . $r['msg'] . "\n"); }
    $reqId = intval($r['id']);
    echo "req REQ-100=id$reqId version={$r['version_id']}\n";
}
if ($reqId === null || $reqId <= 0) { die("no req id\n"); }

$verRows = $db->get_recordset(
    "SELECT REQV.id, REQV.version, REQV.revision FROM req_versions REQV " .
    "JOIN nodes_hierarchy NH ON NH.id = REQV.id WHERE NH.parent_id = $reqId ORDER BY REQV.version ASC");
$verList = [];
foreach ((array)$verRows as $vr) { $verList[intval($vr['version'])] = intval($vr['id']); }
echo "existing versions: " . json_encode($verList) . "\n";

// give v2 with different scope/status/type + cfield
if (!isset($verList[2])) {
    $vp = $reqMgr->create_new_version($reqId, $userId, ['log_msg' => 'Add v2 for comparison']);
    echo "created v2: " . var_export($vp, true) . "\n";
    $verList[2] = intval($vp['id']);
}
$v2Id = $verList[2];
// set v2 scope/type/status distinctly
$db->exec_query("UPDATE req_versions SET scope='" . $db->prepare_string(
    "Updated scope paragraph.\nBrand new second paragraph.") .
    "', type='" . TL_REQ_TYPE_FEATURE . "', status='" . TL_REQ_STATUS_VALID .
    "' WHERE id=$v2Id");
$db->exec_query("DELETE FROM cfield_design_values WHERE field_id=$cfId AND node_id=$v2Id");
$db->exec_query("INSERT INTO cfield_design_values (field_id, node_id, value) VALUES ($cfId, $v2Id, '5.0')");
echo "v2=$v2Id updated (type informative, scope changed, cf=5.0)\n";

// revision r2 of v2 with yet another scope + cf value
$revRows = $db->get_recordset(
    "SELECT id, revision FROM req_revisions WHERE parent_id=$v2Id ORDER BY revision DESC LIMIT 1");
$revId = null;
if (count($verList) >= 2) {
    // create one revision of v2 so compare shows a version/revision mixed list
    $req = ['title' => 'RCMP Fixture Requirement', 'req_doc_id' => 'REQ-100'];
    $rv = $reqMgr->create_new_revision($v2Id, $userId, $tid, $req, 'Revise v2 scope');
    $revId = intval($rv['id']);
    echo "created revision: " . var_export($rv, true) . "\n";
    $db->exec_query("UPDATE req_revisions SET scope='" . $db->prepare_string(
        "Final scope paragraph.\nBrand new second paragraph.") .
        "' WHERE id=$revId");
    $db->exec_query("DELETE FROM cfield_design_values WHERE field_id=$cfId AND node_id=$revId");
    $db->exec_query("INSERT INTO cfield_design_values (field_id, node_id, value) VALUES ($cfId, $revId, '5.1')");
    echo "revision=$revId updated (scope changed, cf=5.1)\n";
}

// ---- user with no mgt_view_req (permission test) -----------------------
$guestRows = $db->get_recordset("SELECT id FROM users WHERE login='rcmp_guest'");
if (!empty($guestRows)) {
    echo "guest user: reusing id=" . intval($guestRows[0]['id']) . "\n";
} else {
    $u = new tlUser();
    $u->login = 'rcmp_guest';
    $u->firstName = 'Rcmp';
    $u->lastName = 'Guest';
    $u->emailAddress = 'rcmp_guest@example.org';
    $u->globalRoleID = 5; // guest -> no mgt rights
    $u->locale = 'en_GB';
    $u->isActive = 1;
    $u->setPassword('rcmp_guest');
    $res = $u->writeToDB($db);
    echo "guest user: " . ($res == tl::OK ? "OK id=" . $u->dbID : "FAIL($res)") . "\n";
}

// show resulting history for review
$hist = $reqMgr->get_history($reqId, ['output' => 'array', 'decode_user' => true]);
foreach ((array)$hist as $h) {
    printf("hist item_id=%d v=%d r=%d status=%s type=%d cov=%d editor=%s ts=%s log='%s'\n",
        $h['item_id'], $h['version'], $h['revision'], $h['status'], $h['type'],
        $h['expected_coverage'], $h['last_editor'], $h['timestamp'], $h['log_message']);
}

echo "DONE reqId=$reqId\n";