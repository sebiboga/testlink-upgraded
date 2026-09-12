<?php
// Fixture for Issue #1481 - reqCreateTestCases status-domain E_WARNING (Refs #1481)
// Run from repo root: php tmp/fixtures_1481.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojectMgr = new testproject($db);
$reqSpecMgr = new requirement_spec_mgr($db);
$reqMgr = new requirement_mgr($db);

$userId = 1; // admin

// ------------------------------------------------------------------------
// ensure test project exists (fresh DB has none)
// ------------------------------------------------------------------------
$tproject_id = null;
$rows = $db->get_recordset("SELECT id FROM testprojects WHERE prefix='RCB1481'");
if (!empty($rows)) {
    $tproject_id = intval($rows[0]['id']);
    echo "reusing testproject id=$tproject_id\n";
} else {
    $item = new stdClass();
    $item->name = 'ReqCreateTCBadStatus';
    $item->prefix = 'RCB1481';
    $item->notes = 'Issue #1481 fixture';
    $item->color = '#9BD';
    $item->active = 1;
    $item->is_public = 1;
    $item->options = array('option_priority' => 1, 'option_reqs' => 1);
    try {
        $tproject_id = $tprojectMgr->create($item, ['doChecks' => false]);
        echo "created testproject id=$tproject_id\n";
    } catch (Exception $e) {
        die("testproject create failed: " . $e->getMessage() . "\n");
    }
}

// ------------------------------------------------------------------------
// drop previous fixture specs (idempotency)
// ------------------------------------------------------------------------
$rows = $db->get_recordset(
    "SELECT id, doc_id FROM req_specs WHERE testproject_id=$tproject_id " .
    "AND doc_id IN ('SRS-1481','SRS-1481b')");
foreach ((array)$rows as $row) {
    if (intval($row['id']) > 0) {
        echo "deleting spec {$row['id']} ({$row['doc_id']})\n";
        $reqSpecMgr->delete_deep(intval($row['id']));
    }
}

// ------------------------------------------------------------------------
// req spec
// ------------------------------------------------------------------------
$op = $reqSpecMgr->create($tproject_id, $tproject_id, 'SRS-1481', '1481 Bad Status Spec',
    "Fixture spec for the reqCreateTestCases status-domain E_WARNING.",
    2, $userId, TL_REQ_SPEC_TYPE_USER_REQ_SPEC);
if (!$op['status_ok'] || $op['id'] <= 0) { die("spec create failed: " . $op['msg'] . "\n"); }
$specId = $op['id'];
echo "spec=$specId\n";

// ------------------------------------------------------------------------
// 2 requirements: R-GOOD (valid status) and R-BAD (out-of-domain status 'z')
// ------------------------------------------------------------------------
$reqGood = $reqMgr->create($specId, 'R-GOOD', 'Fixture Requirement GOOD',
    'Valid status row.', $userId, TL_REQ_STATUS_VALID, TL_REQ_TYPE_SYSTEM_FUNCTION);
if ($reqGood['status_ok'] && $reqGood['id'] > 0) {
    echo "req R-GOOD=id{$reqGood['id']} v{$reqGood['version_id']}\n";
} else {
    echo "req R-GOOD FAILED: " . $reqGood['msg'] . "\n";
}

$reqBad = $reqMgr->create($specId, 'R-BAD', 'Fixture Requirement BAD',
    'Out-of-domain status row.', $userId, 'z', TL_REQ_TYPE_SYSTEM_FUNCTION);
if ($reqBad['status_ok'] && $reqBad['id'] > 0) {
    echo "req R-BAD=id{$reqBad['id']} v{$reqBad['version_id']} (status=z)\n";
} else {
    echo "req R-BAD FAILED: " . $reqBad['msg'] . "\n";
}

echo "DONE\n";