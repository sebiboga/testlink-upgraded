<?php
// Fixture for #1770 browser/API testing: Test Case Tree navigator
// (gui/templates/testcases/tcProjectTree.html + api/tcprojecttree/index.php).
//
// Creates TWO test projects so the BFF's ownership proofs can be exercised:
//
//   tproject TREE1 (prefix TR1770)
//     suite "Tree Root"                 -> 1 direct case + 1 nested suite
//       sub-suite "Tree Sub"            -> 2 cases
//       sub-suite "Tree Empty"          -> 0 cases, 0 children (empty state)
//     suite "Tree <script>x</script>Y" -> name used for the XSS probe
//     suite "Tree Rich"                 -> 1 case with a SECOND version, so the
//                                          "latest tc_external_id" resolution is
//                                          exercised with more than one version
//   tproject TREE2 (prefix TR1771)      -> foreign project: its suite / case ids
//                                          must 404 through tproject_id=TREE1
//   user   treenorights (role 3)        -> the 403 path
//
// Run from repo root: php tmp/fixtures_1770.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tplanMgr = new testplan($db);
$tsuiteMgr = new testsuite($db);
$tcaseMgr = new testcase($db);
$TB = tlObjectWithDB::getDBTables();
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
dropProject($tprojMgr, 'TREE1');
dropProject($tprojMgr, 'TREE2');

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
    $opts->requirementsEnabled = 0;
    $opts->testPriorityEnabled = 0;
    $opts->automationEnabled = 0;
    $opts->inventoryEnabled = 0;
    $opts->platformsEnabled = 0;
    $opts->testcasecfEnabled = 0;
    $opts->requirementcfEnabled = 0;
    $item->options = $opts;
    $r = $tprojMgr->create($item);
    $id = intval($r);
    if ($id <= 0) {
        die("tproject create failed for $name\n");
    }
    $tprojMgr->setActive($id);
    return $id;
}

$idP = makeProject($tprojMgr, 'TREE1', 'TR1770', 'fixture for #1770 (tc tree navigator)');
$idAlt = makeProject($tprojMgr, 'TREE2', 'TR1771', 'fixture for #1770 (foreign project)');
echo "tproject TREE1=$idP  TREE2=$idAlt\n";

$idPlan = intval($tplanMgr->create('Tree Plan', '', $idP, 1, 1));
echo "testplan=$idPlan\n";

$idRoot = intval($tsuiteMgr->create($idP, 'Tree Root', '', null, 0, 'allow_repeat')['id']);
$idSub = intval($tsuiteMgr->create($idRoot, 'Tree Sub', '', null, 0, 'allow_repeat')['id']);
$idEmpty = intval($tsuiteMgr->create($idRoot, 'Tree Empty', '', null, 0, 'allow_repeat')['id']);
$idXss = intval($tsuiteMgr->create($idP, 'Tree <script>window.__xss=1;</script>Y', '', null, 0, 'allow_repeat')['id']);
$idRich = intval($tsuiteMgr->create($idP, 'Tree Rich', '', null, 0, 'allow_repeat')['id']);
$idAltSuite = intval($tsuiteMgr->create($idAlt, 'Foreign Root', '', null, 0, 'allow_repeat')['id']);
echo "suites: root=$idRoot sub=$idSub empty=$idEmpty xss=$idXss rich=$idRich alt=$idAltSuite\n";

function mkSteps($n)
{
    $steps = array();
    for ($i = 1; $i <= $n; $i++) {
        $t = new stdClass();
        $t->step_number = $i;
        $t->actions = 'Do action number ' . $i;
        $t->expected_results = 'Expected result of step ' . $i;
        $t->execution_type = TESTCASE_EXECUTION_TYPE_MANUAL;
        $steps[] = $t;
    }
    return $steps;
}

$makeTcase = function ($suiteId, $name) use ($tcaseMgr, $userId) {
    $ret = $tcaseMgr->create($suiteId, $name, 'summary of ' . $name, '', mkSteps(2),
        $userId, '', testcase::DEFAULT_ORDER, testcase::AUTOMATIC_ID,
        TESTCASE_EXECUTION_TYPE_MANUAL);
    if (!$ret['status_ok'] || $ret['id'] <= 0) {
        die("tcase create failed for $name: " . (isset($ret['message']) ? $ret['message'] : '') . "\n");
    }
    return array(intval($ret['id']), intval($ret['tcversion_id']));
};

$TCASES = array();
$TCASES['root_direct'] = $makeTcase($idRoot, 'TR1770 Direct Case');
$TCASES['sub_a'] = $makeTcase($idSub, 'TR1770 Sub Case A');
$TCASES['sub_b'] = $makeTcase($idSub, 'TR1770 Sub Case B');
$TCASES['rich'] = $makeTcase($idRich, 'TR1770 Rich Case');
$TCASES['foreign'] = $makeTcase($idAltSuite, 'TR1771 Foreign Case');
foreach ($TCASES as $k => $v) {
    echo "tcase $k: case={$v[0]} version={$v[1]}\n";
}

// A SECOND version of "TR1770 Rich Case" with a DIFFERENT tc_external_id, so
// api/tcprojecttree has to resolve the LATEST one (MAX version id) rather than
// picking whichever row the legacy "SELECT DISTINCT ... fetchRowsIntoMap" gave.
// testcase::create($tcase_id, ...) would add a SECOND test case node, not a
// version, and update() edits in place: in 2.0.1 new versions only come from
// the XML/CSV importer, so mirror what it writes.
list($idRichCase, $idRichV1) = $TCASES['rich'];
$db->exec_query("INSERT INTO {$TB['nodes_hierarchy']} (parent_id,node_type_id,name,node_order) "
    . "VALUES ({$idRichCase},4,'',1)");
$idRichV2 = intval($db->insert_id($TB['nodes_hierarchy']));
$db->exec_query(
    "INSERT INTO {$TB['tcversions']} (id,tc_external_id,version,layout,status,"
    . "summary,preconditions,importance,author_id,creation_ts,updater_id,"
    . "modification_ts,active,is_open,execution_type,estimated_exec_duration) "
    . "SELECT {$idRichV2},12345,2,layout,status,"
    . "'summary of version TWO - the newer one',preconditions,importance,"
    . "author_id,creation_ts,{$userId},NOW(),active,is_open,execution_type,"
    . "estimated_exec_duration FROM {$TB['tcversions']} WHERE id = {$idRichV1}");
echo "second version of rich case: node=$idRichV2 tc_external_id=12345\n";

// role-3 user: proves the 403 path with a real no-rights login.
$hash = password_hash('treenorights', PASSWORD_DEFAULT);
$db->exec_query("INSERT INTO users (login,password,role_id,email,first,last,locale,"
    . "default_testproject_id,active,cookie_string,auth_method) "
    . "VALUES ('treenorights','" . $hash . "',3,'t@t.no','Tree','NoRights','en_GB',0,1,"
    . "'ck_tree_1770','DB') ON DUPLICATE KEY UPDATE password=VALUES(password), "
    . "role_id=3, active=1, auth_method=VALUES(auth_method)");
$rs = $db->get_recordset("SELECT id,login,role_id FROM users WHERE login='treenorights'");
echo 'user treenorights: ' . json_encode($rs) . "\n";

echo "SUMMARY: tproject=$idP foreign=$idAlt suite_root=$idRoot suite_sub=$idSub "
    . "suite_empty=$idEmpty suite_xss=$idXss suite_rich=$idRich foreign_suite=$idAltSuite "
    . "foreign_case={$TCASES['foreign'][0]}\n";
