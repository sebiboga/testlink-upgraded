<?php
// Fixture for issue #1645: `not_authorized_user` row marker in
// gui/templates/usermanagement/usersAssignPlan.html (legacy parity with
// gui/templates/dashio/usermanagement/usersAssign.tpl:234-237, deleted in
// ab387af72).
//
// Creates:
//   * PUBLIC test project ALPHA1645 with two plans:
//       - A-PUBLIC-1645  (is_public=1)  -> users inherit the global role
//       - A-PRIVATE-1645 (is_public=0)  -> per get_tplan_effective_role()
//         every non-admin user gets effective role 3 (<no rights>) UNLESS an
//         explicit user_testplan_roles row exists.
//   * 3 non-admin users (designer / guest / tester) plus one of them given an
//     EXPLICIT plan role on the private plan (control row: must NOT be marked).
// Run from repo root: php tmp/fixtures_1645.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tplanMgr = new testplan($db);
$utables = tlObjectWithDB::getDBTables(array('users', 'user_testplan_roles'));

// --- users (idempotent) -------------------------------------------------
function ensureUser($db, $utables, $login, $roleId, $first, $last) {
    $ex = $db->get_recordset(' SELECT id FROM ' . $utables['users'] .
        " WHERE login='" . $login . "'");
    if (!empty($ex)) {
        return intval($ex[0]['id']);
    }
    $db->exec_query(" INSERT INTO " . $utables['users'] .
        " (login,password,email,first,last,role_id,locale,active,script_key,cookie_string,auth_method)" .
        " VALUES ('" . $login . "','\$2y\$10\$lhIIG2jMq1neLBjPFLlV2uGiP8YPYClGLpO6n1YYWAXNlUCStFBoC','" .
        $login . "@example.com','" . $first . "','" . $last . "'," . intval($roleId) . ",'en_GB',1,'','" .
        $login . "cookie','DB')");
    $id = intval($db->insert_id($utables['users']));
    echo "created user $login id=$id\n";
    return $id;
}

$uidDesigner = ensureUser($db, $utables, 'ua1645designer', 4, 'Dee', 'Signer');
$uidGuest = ensureUser($db, $utables, 'ua1645guest', 5, 'Gues', 'Ter');
$uidTester = ensureUser($db, $utables, 'ua1645tester', 7, 'Tes', 'Ter');

// --- project (idempotent) ----------------------------------------------
$existing = $tprojMgr->get_by_name('ALPHA1645');
if (!empty($existing)) {
    $pid = intval($existing[0]['id']);
    echo "project ALPHA1645 already exists id=$pid\n";
} else {
    $item = new stdClass();
    $item->name = 'ALPHA1645';
    $item->prefix = 'A1645';
    $item->notes = 'fixture for issue #1645 (usersAssignPlan not_authorized_user marker)';
    $item->color = '';
    $item->active = 1;
    $item->is_public = 1;
    $item->reqmgrintegration = 0;
    $item->option_automation = 0;
    $item->option_priority = 0;
    $item->option_reqs = 0;
    $opts = new stdClass();
    $opts->requirementsEnabled = 0;
    $opts->testPriorityEnabled = 0;
    $opts->automationEnabled = 0;
    $pid = intval($tprojMgr->create($item, $opts));
    echo "created project ALPHA1645 id=$pid\n";
}

// --- plans (idempotent) -------------------------------------------------
$utp = tlObjectWithDB::getDBTables(array('testplans', 'nodes_hierarchy'));
$plans = $db->get_recordset(' SELECT testplans.id, NH.name FROM ' . $utp['testplans'] .
    " testplans, " . $utp['nodes_hierarchy'] . ' NH WHERE testplans.id = NH.id' .
    " AND NH.parent_id=$pid");
$have = array();
foreach ((array)$plans as $p) {
    $have[$p['name']] = intval($p['id']);
}
$planRows = array(
    'A-PUBLIC-1645' => 1,
    'A-PRIVATE-1645' => 0,
);
$planIds = array();
foreach ($planRows as $pname => $isPublic) {
    if (isset($have[$pname])) {
        $planIds[$pname] = $have[$pname];
        echo "plan $pname already exists id={$planIds[$pname]}\n";
        continue;
    }
    $pi = new stdClass();
    $pi->name = $pname;
    $pi->notes = 'fixture #1645';
    $pi->active = 1;
    $pi->is_public = $isPublic;
    $pi->testProjectID = $pid;
    $planIds[$pname] = intval($tplanMgr->createFromObject($pi, array('doChecks' => false)));
    echo "created plan $pname id={$planIds[$pname]} is_public=$isPublic\n";
}

$privatePlan = $planIds['A-PRIVATE-1645'];

// --- one EXPLICIT plan role (control row: must NOT get the marker) -----
$ex = $db->get_recordset(' SELECT role_id FROM ' . $utables['user_testplan_roles'] .
    " WHERE testplan_id=$privatePlan AND user_id=$uidTester");
if (!empty($ex)) {
    echo "explicit plan role for tester already exists role={$ex[0]['role_id']}\n";
} else {
    $db->exec_query(" INSERT INTO " . $utables['user_testplan_roles'] .
        " (testplan_id,user_id,role_id) VALUES ($privatePlan,$uidTester,7)");
    echo "explicit plan role tester->role7 on private plan\n";
}

echo "SUMMARY project=$pid public_plan={$planIds['A-PUBLIC-1645']} " .
    "private_plan=$privatePlan users=$uidDesigner,$uidGuest,$uidTester\n";
