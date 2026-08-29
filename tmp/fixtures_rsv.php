<?php
// Fixture for Requirement Specification Viewer (api/reqspec spec_view, Refs #755)
// Run from repo root: php tmp/fixtures_rsv.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$reqSpecMgr = new requirement_spec_mgr($db);
$reqMgr = new requirement_mgr($db);
$cfMgr = new cfield_mgr($db);

$tproject_id = 1; // ReqSpec Fixture Project (RSF) created via api/projects
$userId = 1;      // admin

// ------------------------------------------------------------------------
// idempotency: drop previous fixture specs (direct query: get_by_title() is
// broken on this upgraded schema - req_specs.scope moved to revisions)
// ------------------------------------------------------------------------
$rows = $db->get_recordset(
    "SELECT id, doc_id FROM req_specs WHERE testproject_id=$tproject_id " .
    "AND doc_id IN ('SRS-001','SRS-002')");
foreach ((array)$rows as $row) {
    if (intval($row['id']) > 0) {
        echo "deleting spec {$row['id']} ({$row['doc_id']})\n";
        $reqSpecMgr->delete_deep(intval($row['id']));
    }
}

// ------------------------------------------------------------------------
// spec 1 (parent, with scope + declared req count)
// ------------------------------------------------------------------------
$op1 = $reqSpecMgr->create($tproject_id, 0, 'SRS-001', 'Fixture Feature Spec',
    "Fixture scope: this specification describes the Fixture feature.\n\nSecond line of scope.",
    3, $userId, TL_REQ_SPEC_TYPE_USER_REQ_SPEC);
if (!$op1['status_ok'] || $op1['id'] <= 0) { die("spec1 create failed: " . $op1['msg'] . "\n"); }
$spec1Id = $op1['id'];
$spec1Rev1 = $op1['revision_id'];
echo "spec1=$spec1Id rev1=$spec1Rev1\n";

// ------------------------------------------------------------------------
// spec 2 (child)
// ------------------------------------------------------------------------
$op2 = $reqSpecMgr->create($tproject_id, $spec1Id, 'SRS-002', 'Fixture Child Spec',
    'Child scope for the nested-specialization case.', 0, $userId, TL_REQ_SPEC_TYPE_SECTION);
if (!$op2['status_ok'] || $op2['id'] <= 0) { die("spec2 create failed: " . $op2['msg'] . "\n"); }
$spec2Id = $op2['id'];
echo "spec2=$spec2Id\n";

// ------------------------------------------------------------------------
// 3 requirements under spec1
// ------------------------------------------------------------------------
$reqDocs = [
    ['REQ-001', 'Fixture Requirement 1', "Requirement 1 summary.\nDetail line."],
    ['REQ-002', 'Fixture Requirement 2', 'Requirement 2 summary.'],
    ['REQ-003', 'Fixture Requirement 3', 'Requirement 3 summary.'],
];
$reqIds = [];
foreach ($reqDocs as $i => $rd) {
    $r = $reqMgr->create($spec1Id, $rd[0], $rd[1], $rd[2], $userId,
        TL_REQ_STATUS_VALID, TL_REQ_TYPE_SYSTEM_FUNCTION);
    if ($r['status_ok'] && $r['id'] > 0) {
        $reqIds[] = $r['id'];
        echo "req {$rd[0]}=id{$r['id']} v{$r['version_id']}\n";
    } else {
        echo "req {$rd[0]} FAILED: " . $r['msg'] . "\n";
    }
}

// give REQ-001 a second version (latest stays open) -> tests latest-version logic
if (isset($reqIds[0])) {
    $vp = $reqMgr->create_new_version($reqIds[0], $userId, ['log_msg' => 'version bump']);
    echo "REQ-001 new version: " . var_export($vp, true) . "\n";
}

// ------------------------------------------------------------------------
// spec 1 second revision (tests revisions_count + revision history)
// ------------------------------------------------------------------------
$cnr = $reqSpecMgr->clone_revision($spec1Id, [
    'log_message' => 'Second revision via fixture',
    'author_id'   => $userId,
]);
echo "spec1 clone_revision: " . var_export($cnr, true) . "\n";
$spec1RevLast = $cnr['status_ok'] ? $cnr['id'] : $spec1Rev1;

// ------------------------------------------------------------------------
// custom field linked to requirement_spec + value on current revision
// ------------------------------------------------------------------------
$nt = $db->get_recordset("SELECT id FROM node_types WHERE description='requirement_spec'");
$reqSpecNodeId = intval($nt[0]['id']);

$existing = $db->get_recordset("SELECT id FROM custom_fields WHERE name='rsv_cf_milestone'");
if (!empty($existing)) {
    $cfId = intval($existing[0]['id']);
    $db->exec_query("DELETE FROM cfield_design_values WHERE field_id=$cfId");
    $db->exec_query("UPDATE custom_fields SET type=0 WHERE id=$cfId");
    echo "reusing cfield id=$cfId\n";
} else {
    $cf = [
        'name' => 'rsv_cf_milestone',
        'label' => 'Milestone',
        'type' => 0,
        'possible_values' => '',
        'show_on_design' => 1,
        'enable_on_design' => 1,
        'show_on_testplan_design' => 0,
        'enable_on_testplan_design' => 0,
        'show_on_execution' => 0,
        'enable_on_execution' => 0,
        'node_type_id' => $reqSpecNodeId,
    ];
    $cfo = $cfMgr->create($cf);
    $cfId = $cfo['id'];
    echo "cfield id=$cfId\n";
    $cfMgr->link_to_testproject($tproject_id, [$cfId]);
}
$db->exec_query("INSERT INTO cfield_design_values (field_id, node_id, value) " .
    "VALUES ($cfId, $spec1RevLast, 'Milestone 5.1')");
echo "cfield value seeded on revision $spec1RevLast\n";

// ------------------------------------------------------------------------
// attachment on spec1 (fake $_FILES entry, .doc is in allowed_files)
// ------------------------------------------------------------------------
$db->exec_query("DELETE FROM attachments WHERE fk_id=$spec1Id AND fk_table='req_specs'");
$tmpAtt = '/tmp/rsv_attach.doc';
file_put_contents($tmpAtt, "Fixture attachment content for the requirement spec viewer.\n");
$repo = tlAttachmentRepository::create($db);
$op = $repo->insertAttachment($spec1Id, 'req_specs', 'Feature Spec Attachment',
    ['name' => 'feat-spec.doc', 'type' => 'application/msword',
     'size' => filesize($tmpAtt), 'tmp_name' => $tmpAtt]);
echo "attachment: " . ($op->statusOK ? "OK id=" . $op->dbID : "FAIL " . $op->msg . ' code=' . $op->statusCode) . "\n";

// ------------------------------------------------------------------------
// limited user (guest role, no mgt_view_req) for permission tests
// ------------------------------------------------------------------------
$u = new tlUser();
$u->login = 'rsv_viewer';
$u->firstName = 'Rsv';
$u->lastName = 'Viewer';
$u->emailAddress = 'rsv_viewer@example.org';
$u->globalRoleID = 5; // guest -> no mgt rights
$u->locale = 'en_GB';
$u->isActive = 1;
$u->setPassword('rsv_viewer');
$res = $u->writeToDB($db);
echo "viewer user: " . ($res == tl::OK ? "OK id=" . $u->dbID : "FAIL($res)") . "\n";

echo "DONE\n";