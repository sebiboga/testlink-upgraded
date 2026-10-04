<?php
/**
 * Fixture for #1816 (Custom Fields - Test Project assignment, cfieldsTprojectAssign).
 *
 * Creates two test projects and custom fields of every shape the assignment
 * screen has to cope with:
 *   CFP 1812  - plain string CF on Test Case, design area (location dropdown shows)
 *   CFP1812EXEC - CF on Test Case, execution area (location dropdown hidden: the
 *                 legacy template printed &nbsp; for these, tpl:91-98)
 *   CFP1812REQ  - CF on Requirement Specification (different node type)
 *   CFP1812PLN  - CF on Test Plan (different node type again)
 *   CFP1812OTHER - only linked to the SECOND project, so the cross-project
 *                 unassign / save attack is measurable
 * plus a role-3 (<no rights>) user and a user with cfield_management on the
 * first project only, so the 403 path can be exercised for real.
 *
 * Run from repo root:  php tmp/fixtures_1816.php
 */
require_once('config.inc.php');
require_once('common.php');
require_once('users.inc.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$cfieldMgr = new cfield_mgr($db);

$made = array();
foreach (array('CFP Main', 'CFP Other') as $name) {
    foreach ((array) $tprojMgr->get_by_name($name) as $row) {
        $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
        if ($oid > 0) { $tprojMgr->delete($oid, 1); }
    }
    $item = new stdClass();
    $item->name = $name;
    $item->prefix = ($name === 'CFP Main') ? 'CFPM' : 'CFPO';
    $item->notes = 'fixture for issue 1816 (cfieldsTprojectAssign)';
    $item->color = '';
    $item->active = 1;
    $item->is_public = 1;
    $opts = new stdClass();
    $opts->requirementsEnabled = 1;
    $opts->testPriorityEnabled = 0;
    $opts->automationEnabled = 0;
    $opts->inventoryEnabled = 0;
    $opts->platformsEnabled = 0;
    $opts->testcasecfEnabled = 1;
    $opts->requirementcfEnabled = 1;
    $item->options = $opts;
    $id = intval($tprojMgr->create($item));
    if ($id <= 0) { die("tproject create failed for $name\n"); }
    $tprojMgr->setActive($id);
    $made[$name] = $id;
    echo "tproject '$name'=$id\n";
}
$idMain = $made['CFP Main'];
$idOther = $made['CFP Other'];

function cfMake1816($cfieldMgr, $name, $label, $type, $node, $enableOn, $pv = '')
{
    $cf = array(
        'name' => $name, 'label' => $label, 'type' => $type,
        'possible_values' => $pv, 'node_type_id' => $node,
        'show_on_design' => 0, 'enable_on_design' => 0,
        'show_on_execution' => 0, 'enable_on_execution' => 0,
        'show_on_testplan_design' => 0, 'enable_on_testplan_design' => 0,
    );
    $setter = array('design' => 0, 'execution' => 0, 'testplan_design' => 0);
    $setter[$enableOn] = 1;
    foreach ($setter as $a => $v) {
        $cf['enable_on_' . $a] = $v;
        if ($v) { $cf['show_on_' . $a] = 1; }
    }
    $ret = $cfieldMgr->create($cf);
    if (empty($ret['status_ok'])) { die("cfield create failed for $name\n"); }
    echo "cfield $name={$ret['id']}\n";
    return intval($ret['id']);
}

foreach (array('CFP1812', 'CFP1812EXEC', 'CFP1812REQ', 'CFP1812PLN', 'CFP1812OTHER')
         as $old) {
    $byName = $cfieldMgr->get_by_name($old);
    if (!is_null($byName)) {
        foreach (array_keys($byName) as $oid) { $cfieldMgr->delete(intval($oid)); }
        echo "removed old cfield $old\n";
    }
}

$cfPlain = cfMake1816($cfieldMgr, 'CFP1812', 'Plain string CF', 0, 3, 'design');
$cfExec  = cfMake1816($cfieldMgr, 'CFP1812EXEC', 'Execution CF', 0, 3, 'execution');
$cfReq   = cfMake1816($cfieldMgr, 'CFP1812REQ', 'CF on Requirement Spec', 0, 6, 'design');
$cfPlan  = cfMake1816($cfieldMgr, 'CFP1812PLN', 'CF on Test Plan', 0, 5, 'design');
$cfOther = cfMake1816($cfieldMgr, 'CFP1812OTHER', 'Only on the other project', 0, 3, 'design');

// Linked to MAIN: the plain + the execution one (the second proves the location
// dropdown is hidden). Linked to OTHER only: cfOther (cross-project attack).
$cfieldMgr->link_to_testproject($idMain, array($cfPlain, $cfExec));
$cfieldMgr->link_to_testproject($idOther, array($cfOther));
echo "linked CFP1812+CFP1812EXEC -> $idMain ; CFP1812OTHER -> $idOther\n";

/* ------------------------------------------------------------------ users
 * tlUser::hasRight() applies the GLOBAL role's rights in every project with no
 * user_testproject_roles row (see issue #1599), and a project role holding
 * EXACTLY ONE right is always denied. Both test users therefore use the
 * <no rights> global role (3) and differ only by their project role.
 */
function userEnsure1816($db, $login, $password)
{
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $db->exec_query("INSERT INTO users (login,password,role_id,email,first,last,locale,"
        . "default_testproject_id,active,cookie_string,auth_method) VALUES ('" . $login . "','"
        . $hash . "',3,'" . $login . "@testlink.local','No','Rights','en_GB',0,1,'ck_"
        . $login . "_1816','DB') ON DUPLICATE KEY UPDATE password=VALUES(password), "
        . "role_id=3, active=1, auth_method=VALUES(auth_method)");
    $rs = $db->get_recordset("SELECT id FROM users WHERE login = '" . $login . "'");
    $id = intval($rs[0]['id'] ?? 0);
    if ($id <= 0) { die("user $login not created\n"); }
    echo "user $login=$id (role 3 = no rights)\n";
    return $id;
}

$uidMgr  = userEnsure1816($db, 'cfp1816mgr', 'cfp1816mgr');
$uidNone = userEnsure1816($db, 'cfp1816none', 'cfp1816none');

// The manager gets a PROJECT role on the first project only: the built-in
// role 1 (Admin) is the only one holding cfield_management.
$roles = $db->get_recordset("SELECT id FROM roles WHERE description = 'admin' LIMIT 1");
$roleId = intval($roles[0]['id'] ?? 1);
if ($roleId <= 0) { die("role admin not found\n"); }

$tabs = tlObject::getDBTables(array('user_testproject_roles'));
$db->exec_query("DELETE FROM {$tabs['user_testproject_roles']} "
              . "WHERE user_id = {$uidMgr} AND testproject_id = {$idMain}");
$db->exec_query("INSERT INTO {$tabs['user_testproject_roles']} "
              . "(user_id, testproject_id, role_id) VALUES({$uidMgr}, {$idMain}, {$roleId})");
echo "cfp1816mgr got project role {$roleId} (Admin) on project {$idMain} only\n";

file_put_contents(__DIR__ . '/fixture_1816.json', json_encode(array(
    'tproject_main' => $idMain,
    'tproject_other' => $idOther,
    'cf_plain' => $cfPlain,
    'cf_exec' => $cfExec,
    'cf_req' => $cfReq,
    'cf_plan' => $cfPlan,
    'cf_other' => $cfOther,
    'uid_manager' => $uidMgr,
    'uid_norights' => $uidNone,
), JSON_PRETTY_PRINT));
echo "wrote tmp/fixture_1816.json\n";
