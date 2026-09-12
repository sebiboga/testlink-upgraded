<?php
// Fixture for #1477 browser testing: Set Results popup requirement link.
// Creates a requirement spec + one requirement under the ESR1403 project
// (created by tmp/fixtures_1403.php) and links it to the root test case via
// req_coverage so the Set Results popup "Requirements" section lists it.
// If ESR1403 is missing, creates project/plan/TC too (re-runnable).
// Run from repo root: php tmp/fixtures_1477.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$reqSpecMgr = new requirement_spec_mgr($db);
$reqMgr = new requirement_mgr($db);
$userId = 1; // admin

$tpid = 0;
$rs = $tprojMgr->get_by_name('ESR1403');
if ($rs) {
    $tpid = intval($rs[0]['id']);
    echo "project exists id=$tpid\n";
} else {
    $item = new stdClass();
    $item->name = 'ESR1403';
    $item->prefix = 'E403';
    $item->notes = 'fixture for #1477';
    $item->color = '';
    $item->active = 1;
    $item->is_public = 1;
    $tpid = intval($tprojMgr->create($item));
    echo "project=$tpid\n";
}
$tprojMgr->setActive($tpid);
$optObj = new stdClass();
$optObj->requirementsEnabled = 1;
$optObj->testPriorityEnabled = 1;
$optObj->automationEnabled = 0;
$optObj->inventoryEnabled = 0;
$optObj->freezeLinkOnNewReqVersion = 0;
$db->exec_query("UPDATE testprojects SET options='" .
    $db->prepare_string(serialize($optObj)) . "' WHERE id=$tpid");

// reusable linkage table names
$tblRC = tlObjectWithDB::getDBTables(['req_coverage']);

$specRows = $db->get_recordset(
    "SELECT id FROM req_specs WHERE testproject_id=$tpid AND doc_id='E403-SRS'");
if (!empty($specRows)) {
    $specId = intval($specRows[0]['id']);
} else {
    $op = $reqSpecMgr->create($tpid, $tpid, 'E403-SRS', 'ESR1403 Req Spec',
        "Spec scope for the Set Results popup requirement link fixture.", 1, $userId,
        TL_REQ_SPEC_TYPE_USER_REQ_SPEC);
    if (!$op['status_ok'] || $op['id'] <= 0) { die("spec create failed: " . $op['msg'] . "\n"); }
    $specId = intval($op['id']);
    echo "spec=$specId\n";
}

$req = null;
$reqRows = $db->get_recordset(
    "SELECT id FROM requirements WHERE srs_id=$specId AND req_doc_id='E403-RQ100'");
if (!empty($reqRows)) {
    $req = ['id' => intval($reqRows[0]['id'])];
    $v = $db->get_recordset(
        "SELECT v.id FROM req_versions v JOIN nodes_hierarchy n ON n.id=v.id " .
        " WHERE n.parent_id=" . $req['id'] . " ORDER BY v.version LIMIT 1");
    if (!empty($v)) { $req['version_id'] = intval($v[0]['id']); }
} else {
    $r = $reqMgr->create($specId, 'E403-RQ100', 'ESR1403 Sample Requirement',
        'Requirement visible from the Set Results popup link.', $userId, 2, 0, 'V');
    if (!$r['status_ok'] || !isset($r['id'])) { die("req create failed: " . ($r['msg'] ?? '?') . "\n"); }
    $req = ['id' => intval($r['id']), 'version_id' => intval($r['version_id'] ?? 0)];
    echo "req={$req['id']} version={$req['version_id']}\n";
}

// link the requirement to the first test case of the project via req_coverage
$tcs = $db->get_recordset(
    "SELECT n.id FROM nodes_hierarchy n JOIN testprojects tp ON tp.id=n.id " .
    " WHERE n.node_type_id=3 AND n.parent_id IN " .
    " (SELECT id FROM nodes_hierarchy WHERE node_type_id=2 OR id=2) LIMIT 1");
if (empty($tcs)) {
    $tcs = $db->get_recordset("SELECT id FROM nodes_hierarchy WHERE node_type_id=3 LIMIT 1");
}
if (!empty($tcs)) {
    $tcId = intval($tcs[0]['id']);
    $tbl = tlObjectWithDB::getDBTables(['nodes_hierarchy', 'tcversions']);
    $tcv = $db->get_recordset(
        "SELECT NH.id FROM {$tbl['nodes_hierarchy']} NH " .
        "JOIN {$tbl['tcversions']} TV ON TV.id = NH.id " .
        "WHERE NH.parent_id = $tcId AND TV.active = 1 ORDER BY TV.version LIMIT 1");
    if (!empty($tcv)) {
        $tcvId = intval($tcv[0]['id']);
        if (!empty($req['version_id'])) {
            $db->exec_query(
                "INSERT INTO {$tblRC['req_coverage']} " .
                "(req_id, req_version_id, testcase_id, tcversion_id, link_status, is_active, author_id)" .
                " VALUES (" . $req['id'] . ", " . $req['version_id'] . ", $tcId, $tcvId, 1, 1, $userId)" .
                " ON DUPLICATE KEY UPDATE link_status=1, is_active=1");
            echo "reqcov req {$req['id']} -> tc $tcId\n";
        }
    }
}

echo "DONE: tproject=$tpid spec=$specId req={$req['id']} version={$req['version_id']}\n";