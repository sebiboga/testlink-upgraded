<?php
// Fixture for #1780 browser/API testing: Requirement Monitors popup
// (gui/templates/requirements/reqMonitors.html + api/reqmonitors/index.php).
//
// Creates TWO test projects so the BFF's ownership proof can be exercised:
//
//   tproject MON (prefix RM1780, requirements enabled)  = $idP
//     RS-MON  "Monitors Spec"
//     REQ-MON-1 "Monitored requirement"  -> 2 versions, MONITORED by
//                admin (the caller) + monitor_b + monitor_a  (alphabetical
//                order proof, "you" marker proof)
//     REQ-MON-2 "Unmonitored requirement" -> 1 version, NO monitor row
//     REQ-MON-3 "Foreign monitor row"  -> 1 version, one monitor row whose
//                testproject_id is deliberately MON-ALT: the BFF must scope the
//                reader to the OWNING project, so this row must NOT be listed.
//
//   tproject MON-ALT (prefix RMA1780) = $idPA
//     RS-MON-ALT / REQ-MON-ALT  -> foreign requirement (project_mismatch test)
//
//   users: monitor_a, monitor_b (role 8), monnorights (role 3 = <no rights>)
//
// Run from repo root: php tmp/fixtures_1780.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$reqSpecMgr = new requirement_spec_mgr($db);
$reqMgr = new requirement_mgr($db);
$userId = 1; // admin

function dropProject($tprojMgr, $name)
{
    foreach ((array)$tprojMgr->get_by_name($name) as $row) {
        $oid = intval(is_array($row) ? (isset($row['id']) ? $row['id'] : 0) : $row);
        if ($oid > 0) {
            echo "deleting old project $name ($oid)\n";
            $tprojMgr->delete($oid, 1);
        }
    }
}
dropProject($tprojMgr, 'MON');
dropProject($tprojMgr, 'MON-ALT');

function makeProject($tprojMgr, $name, $prefix, $notes)
{
    $item = new stdClass();
    $item->name = $name;
    $item->prefix = $prefix;
    $item->notes = $notes;
    $item->color = '';
    $item->active = 1;
    $item->is_public = 1;
    $opts = new stdClass();
    $opts->requirementsEnabled = 1;
    $opts->testPriorityEnabled = 0;
    $opts->automationEnabled = 0;
    $opts->inventoryEnabled = 0;
    $opts->platformsEnabled = 0;
    $opts->testcasecfEnabled = 0;
    $opts->requirementcfEnabled = 0;
    $item->options = $opts;
    $id = intval($tprojMgr->create($item));
    if ($id <= 0) {
        die("tproject create failed for $name\n");
    }
    $tprojMgr->setActive($id);
    return $id;
}

function makeSpec($reqSpecMgr, $tprojectId, $docId, $title, $userId)
{
    $op = $reqSpecMgr->create($tprojectId, $tprojectId, $docId, $title,
        'scope of ' . $docId, 2, $userId, TL_REQ_SPEC_TYPE_USER_REQ_SPEC);
    if (empty($op['status_ok']) || intval($op['id']) <= 0) {
        die("spec create failed for $docId: " . $op['msg'] . "\n");
    }
    return intval($op['id']);
}

function makeReq($reqMgr, $srsId, $docId, $title, $userId, $tprojectId, $order)
{
    $r = $reqMgr->create($srsId, $docId, $title, 'scope of ' . $docId, $userId,
        TL_REQ_STATUS_VALID, TL_REQ_TYPE_INFO, 1, $order, $tprojectId);
    if (empty($r['status_ok']) || intval($r['id']) <= 0) {
        die("req create failed for $docId: " . $r['msg'] . "\n");
    }
    return intval($r['id']);
}

function mkUser($db, $login, $roleId)
{
    // Written with SQL (same shape as tmp/mkuser_norights.php): tlUser has no
    // create() writer and no getByLogin() reader in 2.0.1.
    $hash = password_hash('Passw0rd!1780', PASSWORD_DEFAULT);
    $db->exec_query("INSERT INTO users (login,password,role_id,email,first,last,locale," .
        "default_testproject_id,active,cookie_string,auth_method) VALUES ('" .
        $db->prepare_string($login) . "','" . $db->prepare_string($hash) . "'," .
        intval($roleId) . ",'" . $db->prepare_string($login . '@example.org') . "','" .
        $db->prepare_string(ucfirst($login)) . "','" . $db->prepare_string('Fixture') .
        "','en_GB',0,1,'" . $db->prepare_string('ck_' . $login . '_1780') . "','DB') " .
        "ON DUPLICATE KEY UPDATE password=VALUES(password), role_id=VALUES(role_id), " .
        "active=1, auth_method=VALUES(auth_method)");
    $rs = $db->get_recordset("SELECT id FROM users WHERE login = '" .
        $db->prepare_string($login) . "' LIMIT 1");
    if (empty($rs) || intval($rs[0]['id']) <= 0) {
        die("user create failed for $login\n");
    }
    return intval($rs[0]['id']);
}

// ---------------------------------------------------------------- users ----
$idMonitorA = mkUser($db, 'monitor_a', 8);
$idMonitorB = mkUser($db, 'monitor_b', 8);
$idNoRights = mkUser($db, 'monnorights', 3);
echo "users monitor_a=$idMonitorA monitor_b=$idMonitorB monnorights=$idNoRights\n";

// ------------------------------------------------------------- projects ----
$idP  = makeProject($tprojMgr, 'MON', 'RM1780', 'fixture for #1780 (requirement monitors)');
$idPA = makeProject($tprojMgr, 'MON-ALT', 'RMA1780', 'fixture for #1780 (cross-project guard)');
echo "tproject MON=$idP MON-ALT=$idPA\n";

$spec    = makeSpec($reqSpecMgr, $idP, 'RS-MON', 'Monitors Spec', $userId);
$specAlt = makeSpec($reqSpecMgr, $idPA, 'RS-MON-ALT', 'Alt Monitors Spec', $userId);
echo "spec RS-MON=$spec RS-MON-ALT=$specAlt\n";

// --------------------------------------------------------- requirements ----
$req1 = makeReq($reqMgr, $spec, 'REQ-MON-1', 'Monitored requirement', $userId, $idP, 1);
$req2 = makeReq($reqMgr, $spec, 'REQ-MON-2', 'Unmonitored requirement', $userId, $idP, 2);
$req3 = makeReq($reqMgr, $spec, 'REQ-MON-3', 'Foreign monitor row', $userId, $idP, 3);
$reqAlt = makeReq($reqMgr, $specAlt, 'REQ-MON-ALT', 'Alt requirement', $userId, $idPA, 1);
echo "req REQ-MON-1=$req1 REQ-MON-2=$req2 REQ-MON-3=$req3 REQ-MON-ALT=$reqAlt\n";

// A SECOND version of req1, so the popup's version chip is meaningful.
$v1 = 0;
$lrvi = $db->get_recordset("SELECT req_version_id FROM latest_req_version_id WHERE req_id = " . intval($req1));
if (!empty($lrvi)) { $v1 = intval($lrvi[0]['req_version_id']); }
if ($v1 > 0) {
    $db->exec_query("UPDATE req_versions SET active = 0 WHERE id = " . $v1);
    $ret = $reqMgr->create_version($req1, 2, 'scope of REQ-MON-1 v2', $userId,
        TL_REQ_STATUS_VALID, TL_REQ_TYPE_INFO, 1);
    if (empty($ret['status_ok'])) { die('create_version failed: ' . $ret['msg'] . "\n"); }
    $v2 = intval($ret['id']);
    echo "req REQ-MON-1 versions: v1=$v1 (closed) v2=$v2 (latest)\n";
} else {
    $v1 = 0;
    $v2 = 0;
}

// ------------------------------------------------------- monitor rows -----
// Inserted directly: req_monitor is keyed on (req_id, user_id, testproject_id)
// and requirement_mgr exposes no writer in 2.0.1.
$db->exec_query("INSERT INTO req_monitor (req_id, user_id, testproject_id) VALUES (" .
    intval($req1) . ", " . intval($userId) . ", " . intval($idP) . ")");
$db->exec_query("INSERT INTO req_monitor (req_id, user_id, testproject_id) VALUES (" .
    intval($req1) . ", " . intval($idMonitorA) . ", " . intval($idP) . ")");
$db->exec_query("INSERT INTO req_monitor (req_id, user_id, testproject_id) VALUES (" .
    intval($req1) . ", " . intval($idMonitorB) . ", " . intval($idP) . ")");
// Deliberately WRONG project on the row: must be filtered out by the BFF.
$db->exec_query("INSERT INTO req_monitor (req_id, user_id, testproject_id) VALUES (" .
    intval($req3) . ", " . intval($idMonitorA) . ", " . intval($idPA) . ")");
$db->exec_query("INSERT INTO req_monitor (req_id, user_id, testproject_id) VALUES (" .
    intval($reqAlt) . ", " . intval($idMonitorB) . ", " . intval($idPA) . ")");
echo "monitor rows inserted (req1 x3 owned, req3 x1 foreign-project, reqAlt x1)\n";

$rows = $db->get_recordset("SELECT req_id, user_id, testproject_id FROM req_monitor ORDER BY req_id, user_id");
foreach ((array)$rows as $r) {
    echo "  req_monitor req_id={$r['req_id']} user_id={$r['user_id']} tp={$r['testproject_id']}\n";
}

echo "FIXTURE_OK issue=1780 tproject=$idP alt=$idPA spec=$spec spec_alt=$specAlt" .
     " req1=$req1 req1_v1=$v1 req1_v2=$v2 req2=$req2 req3=$req3 req_alt=$reqAlt" .
     " monitor_a=$idMonitorA monitor_b=$idMonitorB norights=$idNoRights\n";