<?php
// Fixture for Requirement Spec Document Print (api/reqdoc, Refs #755)
// Run from repo root: php tmp/fixtures_printdoc.php
require_once('config.inc.php');
require_once('common.php');


$db = new database(DB_TYPE);
doDBConnect($db);

$tprojectMgr = new testproject($db);
$userId = 1;

// idempotency: drop the fixture project if present (prefix is unique)
$rows = $db->get_recordset("SELECT id FROM testprojects WHERE prefix='PRI'");
foreach ((array)$rows as $row) {
    $tprojectMgr->delete(intval($row['id']));
}

$item = new stdClass();
$item->name = 'PrintDoc Fixture';
$item->notes = 'Fixture project for the modernized requirements print screen.';
$item->prefix = 'PRI';
$item->tcCounter = null;
$item->color = '#9BD1BA';
$item->active = 1;
$item->is_public = 1;
$item->options = (object) array(
    'testPriorityEnabled' => 1,
    'requirementEnabled'  => 1,
    'testCaseEnabled'     => 1,
    'testAutomationEnabled' => 0,
    'inventoryEnabled'      => 0,
);

try {
    $tproject_id = $tprojectMgr->create($item, array('doChecks' => true));
    echo "project id=$tproject_id\n";
} catch (Exception $e) {
    $row = $db->get_recordset("SELECT id FROM testprojects WHERE prefix='PRI'");
    if ($row) { $tproject_id = intval($row[0]['id']); echo "project (existing) id=$tproject_id\n"; }
    else { echo "PROJECT CREATE FAILED: " . $e->getMessage() . "\n"; exit(1); }
}

$reqSpecMgr = new requirement_spec_mgr($db);
$reqMgr = new requirement_mgr($db);

$op1 = $reqSpecMgr->create($tproject_id, $tproject_id, 'PD-SRS-001', 'PrintDoc SRS Alpha',
    "Scope of SRS Alpha specification.", 2, $userId, TL_REQ_SPEC_TYPE_USER_REQ_SPEC);
if (!$op1['status_ok'] || $op1['id'] <= 0) { die("spec1 create failed: " . $op1['msg'] . "\n"); }
$specId1 = $op1['id'];
echo "spec1 id=$specId1\n";

$op2 = $reqSpecMgr->create($tproject_id, $tproject_id, 'PD-SRS-002', 'PrintDoc SRS Beta',
    'Scope of SRS Beta specification.', 1, $userId, TL_REQ_SPEC_TYPE_USER_REQ_SPEC);
if (!$op2['status_ok'] || $op2['id'] <= 0) { die("spec2 create failed: " . $op2['msg'] . "\n"); }
$specId2 = $op2['id'];
echo "spec2 id=$specId2\n";

$reqDocs = array(
    array($specId1, 'PD-REQ-001', 'Requirement One', "First requirement of Alpha.\nWith detail."),
    array($specId1, 'PD-REQ-002', 'Requirement Two', 'Second requirement of Alpha.'),
    array($specId2, 'PD-REQ-003', 'Requirement Three', 'Third requirement of Beta.'),
);
foreach ($reqDocs as $rd) {
    $r = $reqMgr->create($rd[0], $rd[1], $rd[2], $rd[3], $userId,
        TL_REQ_STATUS_VALID, TL_REQ_TYPE_FEATURE);
    if ($r['status_ok'] && $r['id'] > 0) {
        echo "req {$rd[1]}=id{$r['id']}\n";
    } else {
        echo "req {$rd[1]} FAILED: " . $r['msg'] . "\n";
    }
}

echo "TPROJECT_ID=$tproject_id\n";
echo "SPEC1=$specId1\n";
echo "SPEC2=$specId2\n";
echo "DONE\n";
// low-rights guest for the 403 path (guest role has no testplan_metrics)
$db->exec_query("INSERT IGNORE INTO users (login, password, cookie_string, locale, first, last, role_id, active) VALUES ('pdguest','" . password_hash('pdguest', PASSWORD_DEFAULT) . "','" . bin2hex(random_bytes(16)) . "','en_GB','PD','Guest',1,1)");
$g = $db->get_recordset("SELECT id FROM users WHERE login='pdguest'");
if ($g) {
    $gid = intval($g[0]['id']);
    $role = $db->get_recordset("SELECT id FROM roles WHERE description='guest'");
    $db->exec_query("INSERT IGNORE INTO user_testproject_roles (user_id,testproject_id,role_id) VALUES ($gid,$tproject_id," . intval($role[0]['id']) . ")");
    echo "guest pdguest ready\n";
}
