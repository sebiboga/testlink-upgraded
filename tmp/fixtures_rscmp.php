<?php
// Fixture for Requirement Spec Revision Compare (api/reqspec spec_revision_compare @ #1362)
// Run from repo root: php tmp/fixtures_rscmp.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$reqSpecMgr = new requirement_spec_mgr($db);
$userId = 1; // admin

// ---- project -----------------------------------------------------------
$name = 'RCMP Spec Compare Project';
$rs = $tprojMgr->get_by_name($name);
if ($rs) {
    $tid = intval($rs[0]['id']);
    echo "project exists id=$tid\n";
} else {
    $item = new stdClass();
    $item->name = $name;
    $item->prefix = 'RSC';
    $item->notes = 'fixture for #1362';
    $item->color = '';
    $item->active = 1;
    $item->is_public = 1;
    $tid = intval($tprojMgr->create($item));
    echo "project=$tid\n";
}
$tprojMgr->setActive($tid);
$optObj = new stdClass();
$optObj->requirementsEnabled = 1;
$optObj->testPriorityEnabled = 1;
$optObj->automationEnabled = 1;
$optObj->inventoryEnabled = 0;
$optObj->freezeLinkOnNewReqVersion = 0;
$db->exec_query("UPDATE testprojects SET options='" .
    $db->prepare_string(serialize($optObj)) . "' WHERE id=$tid");

// ---- spec --------------------------------------------------------------
$specRows = $db->get_recordset(
    "SELECT id FROM req_specs WHERE testproject_id=$tid AND doc_id='RSC-SRS'");
if (!empty($specRows)) {
    $specId = intval($specRows[0]['id']);
    echo "spec exists id=$specId\n";
    // reset existing revisions so the fixture stays reproducible
    $db->exec_query("DELETE FROM req_specs_revisions WHERE parent_id=$specId");
} else {
    $op = $reqSpecMgr->create($tid, $tid, 'RSC-SRS', 'RSCMP Fixture Spec',
        "Spec scope for the revision compare ordering fixture.", 3, $userId,
        TL_REQ_SPEC_TYPE_USER_REQ_SPEC);
    if (!$op['status_ok'] || $op['id'] <= 0) { die("spec create failed: " . $op['msg'] . "\n"); }
    $specId = intval($op['id']);
    echo "spec=$specId\n";
}

// ---- force 3+ distinct revisions ---------------------------------------
$list = $db->get_recordset(
    "SELECT revision FROM req_specs_revisions WHERE parent_id=$specId ORDER BY revision ASC");
$revs = [];
foreach ((array)$list as $r) { $revs[] = intval($r['revision']); }
echo "existing revisions: " . json_encode($revs) . "\n";

$target = [1, 2, 3, 4];
$created = 0;
foreach ($target as $n) {
    if (in_array($n, $revs, true)) { continue; }
    // clone the current (max) revision N times to grow the chain up to N
    $labels = [1 => 'Initial spec', 2 => 'Second revision', 3 => 'Third revision', 4 => 'Fourth revision'];
    $op = $reqSpecMgr->clone_revision($specId, [
        'log_message' => $labels[$n],
        'author_id'   => $userId,
    ]);
    if ($op['status_ok']) {
        printf("cloned revision %d -> id=%d\n", $n, $op['id']);
        $created++;
    } else {
        printf("clone to %d FAILED: %s\n", $n, $op['msg']);
    }
}
// if we still don't have the target count, renumber via direct SQL update
// (simplest robust approach: drop and rebuild a clean chain 1..4)
$list2 = $db->get_recordset(
    "SELECT id, revision FROM req_specs_revisions WHERE parent_id=$specId ORDER BY revision ASC");
$cnt = count((array)$list2);
echo "now $cnt revisions\n";
if ($cnt < 2) {
    foreach ((array)$list2 as $r) {
        $db->exec_query("DELETE FROM req_specs_revisions WHERE id=" . intval($r['id']));
    }
    echo "rebuilt: need full chain via clone_revision\n";
    $base = $db->get_recordset("SELECT id FROM req_specs WHERE id=$specId");
    $clone = $reqSpecMgr->clone_revision($specId, ['log_message' => 'Second revision', 'author_id' => $userId]);
    $clone2 = $reqSpecMgr->clone_revision($specId, ['log_message' => 'Third revision', 'author_id' => $userId]);
    echo "chain rebuilt: " . var_export(['r2' => $clone, 'r3' => $clone2], true) . "\n";
}

// show final history (should be DESC in API but we print raw ASC for reference)
$hist = $reqSpecMgr->get_history($specId, ['output' => 'array', 'decode_user' => true, 'order_by_dir' => 'DESC']);
foreach ((array)$hist as $h) {
    printf("item_id=%d rev=%d ts=%s editor=%s log='%s'\n",
        $h['item_id'], $h['revision'], $h['timestamp'], $h['last_editor'], $h['log_message']);
}

echo "DONE specId=$specId tid=$tid\n";