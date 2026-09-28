<?php
// Fixture for Refs #1681 (Modernize: Requirement specification tree
// move/re-parent - reqTreeReorder). Creates a public test project with
// requirements ENABLED, two requirement specifications (RTR-SPEC-A with 3
// requirements, RTR-SPEC-B empty) so the move/reorder screen has a real
// target, plus a '<no rights>' user and a view-only user to exercise the
// permission paths.
// Run from repo root: php tmp/fixtures_1681.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tblU = tlObject::getDBTables('users');

// ---- re-runnable: drop a previous run --------------------------------------
// Self-heal first: the duplicate-document-id regression inserts a hand-made
// requirement row straight into `requirements` (a raw SQL shortcut, no
// application code involved). Such a row has no requirement_version child, and
// requirement_mgr::delete() then dereferences array_keys(null) on the empty
// node lookup. Remove those orphans here so the fixture stays re-runnable.
$orphans = $db->get_recordset(
    "SELECT R.id FROM requirements R " .
    "LEFT JOIN nodes_hierarchy RV ON RV.parent_id = R.id AND RV.node_type_id = 8 " .
    "WHERE RV.id IS NULL");
foreach ((array)$orphans as $o) {
    $oid = intval($o['id']);
    echo "removing orphan requirement $oid (no version node)\n";
    $db->exec_query("DELETE FROM requirements WHERE id = $oid");
    $db->exec_query("DELETE FROM nodes_hierarchy WHERE id = $oid");
}

$existing = $tprojMgr->get_by_name('TREE1681');
foreach ((array)$existing as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}
$uRows = $db->get_recordset("SELECT id FROM {$tblU['users']} WHERE login IN ('tr1681view','tr1681norights','tr1681readonly')");
if ($uRows) {
    foreach ($uRows as $u) {
        echo "deleting user " . $u['id'] . "\n";
        $db->exec_query("DELETE FROM {$tblU['users']} WHERE id = " . intval($u['id']));
    }
}

// ---- test project ----------------------------------------------------------
$item = new stdClass();
$item->name = 'TREE1681';
$item->prefix = 'TR1';
$item->notes = 'fixture for Refs #1681 (requirement spec tree move/reorder)';
$item->color = '';
$item->active = 1;
$item->is_public = 1;
$opts = new stdClass();
$opts->requirementsEnabled = 1;
$opts->testPriorityEnabled = 0;
$opts->automationEnabled = 0;
$opts->inventoryEnabled = 0;
$opts->platformsEnabled = 0;
$item->options = $opts;
$idP = intval($tprojMgr->create($item));
echo "tproject=$idP\n";
$tprojMgr->setActive($idP);

// ---- requirement specifications -------------------------------------------
$reqSpecMgr = new requirement_spec_mgr($db);
$reqMgr = new requirement_mgr($db);

function makeSpec($db, $reqSpecMgr, $idP, $userId, $docId, $title, $count) {
    $op = $reqSpecMgr->create($idP, $idP, $docId, $title,
        'Scope of ' . $docId . ' (fixture Refs #1681).', $count, $userId,
        TL_REQ_SPEC_TYPE_USER_REQ_SPEC);
    if (!$op['status_ok'] || $op['id'] <= 0) { die("spec $docId create failed: " . $op['msg'] . "\n"); }
    return intval($op['id']);
}

$userId = intval($_SESSION['userID'] ?? 1);

$idA = makeSpec($db, $reqSpecMgr, $idP, $userId, 'TR1-SPEC-A', 'Specification A', 3);
$idB = makeSpec($db, $reqSpecMgr, $idP, $userId, 'TR1-SPEC-B', 'Specification B', 0);
echo "specA=$idA specB=$idB\n";

// ---- requirements in spec A ----------------------------------------------
function makeReq($db, $reqMgr, $idP, $userId, $specId, $docId, $title, $order) {
    $op = $reqMgr->create($specId, $docId, $title,
        'Scope of ' . $docId . ' v1 (fixture Refs #1681).', $userId,
        TL_REQ_STATUS_VALID, TL_REQ_TYPE_INFO, 1, $order, $idP);
    if (!$op['status_ok'] || $op['id'] <= 0) { die("req $docId create failed: " . $op['msg'] . "\n"); }
    return intval($op['id']);
}

$r1 = makeReq($db, $reqMgr, $idP, $userId, $idA, 'TR1-1', 'First requirement', 0);
$r2 = makeReq($db, $reqMgr, $idP, $userId, $idA, 'TR1-2', 'Second requirement', 1);
$r3 = makeReq($db, $reqMgr, $idP, $userId, $idA, 'TR1-3', 'Third requirement', 2);
echo "reqs=$r1,$r2,$r3\n";

// ---- permission-path users -------------------------------------------------
// role 3 = '<no rights>' : exercises the 403 path of the BFF
$h = password_hash('admin', PASSWORD_BCRYPT);
$cs = hash('sha256', uniqid('t1681', true));
$db->exec_query("INSERT INTO {$tblU['users']} " .
    "(login,password,email,first,last,locale,role_id,active,cookie_string,auth_method) " .
    "VALUES ('tr1681norights','" . $h . "','tr1681norights@localhost','No','Rights'," .
    "'en_GB',3,1,'$cs','')");
$uNo = intval($db->insert_id());
echo "user tr1681norights=$uNo (role 3)\n";

// A custom VIEW-ONLY role: mgt_view_req but NOT mgt_modify_req, so the
// read-only path of the BFF/screen can be exercised. No built-in role has
// that combination.
$tblR = 'roles';
$tblRR = 'role_rights';
$tblRT = 'rights';
$db->exec_query("DELETE FROM {$tblRR} WHERE role_id IN (SELECT id FROM {$tblR} WHERE description='TL1681 view only')");
$db->exec_query("DELETE FROM {$tblR} WHERE description='TL1681 view only'");
$db->exec_query("INSERT INTO {$tblR} (description) VALUES ('TL1681 view only')");
$roId = intval($db->insert_id());
// NB: get_recordset() returns an ARRAY OF ROWS. intval() on a non-empty array
// is 1, which silently granted the wrong right (id 1 = testplan_execute) and
// made this fixture look like it had no view rights at all.
foreach (array('mgt_view_project', 'mgt_view_req') as $rn) {
    $rows = $db->get_recordset("SELECT id FROM {$tblRT} WHERE description='$rn'");
    if (!$rows) { echo "  (right '$rn' does not exist, skipped)\n"; continue; }
    $rid = intval($rows[0]['id'] ?? 0);
    $db->exec_query("INSERT INTO {$tblRR} (role_id,right_id) VALUES ($roId,$rid)");
    echo "  granted '$rn' (id $rid) to role $roId\n";
}
$cs2 = hash('sha256', uniqid('t1681r', true));
$db->exec_query("INSERT INTO {$tblU['users']} " .
    "(login,password,email,first,last,locale,role_id,active,cookie_string,auth_method) " .
    "VALUES ('tr1681readonly','" . $h . "','tr1681readonly@localhost','Read','Only'," .
    "'en_GB',$roId,1,'$cs2','')");
$uRo = intval($db->insert_id());
echo "user tr1681readonly=$uRo (view-only role $roId)\n";

echo "DONE tproject=$idP specA=$idA specB=$idB reqs=$r1,$r2,$r3 unorights=$uNo uro=$uRo role=$roId\n";
