<?php
// CLI fixtures for issue-981 (testSpec create form keyword picker). Run: php tmp/fixtures_981.php
$_SESSION = array();
require_once(dirname(__DIR__) . '/config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tp = new testproject($db);
$ts = new testsuite($db);
$tc = new testcase($db);

function chByName($dbh, $parentId, $name)
{
    $rows = $dbh->get_recordset(
        "SELECT NH.id FROM nodes_hierarchy NH " .
        " JOIN node_types NT ON NT.id = NH.node_type_id " .
        " WHERE NH.parent_id = " . intval($parentId) .
        " AND NH.name = '" . $dbh->prepare_string($name) . "'" .
        " AND NT.description IN ('testsuite','testcase')");
    return empty($rows) ? 0 : intval($rows[0]['id']);
}

$name = 'Bugfix Project';
$rs = $tp->get_by_name($name);
if ($rs) {
    $id = intval($rs[0]['id']);
    echo "project exists id={$id}\n";
} else {
    $item = new stdClass();
    $item->name = $name;
    $item->prefix = 'BFX';
    $item->notes = 'fixture for issue 981';
    $item->options = new stdClass();
    $item->color = '';
    $item->active = 1;
    $item->is_public = 1;
    $id = intval($tp->create($item));
    echo "project=$id\n";

    foreach (array('Smoke Test', 'Performance') as $kw) {
        $tp->addKeyword($id, $kw, 'note ' . $kw);
        echo "kw '$kw' added\n";
    }
}

// grant admin role to admin user on this project
if ($id > 0) {
    $db->exec_query(
        "INSERT IGNORE INTO user_testproject_roles (user_id,testproject_id,role_id) " .
        "VALUES (1,{$id},8)");
}

$s1 = chByName($db, $id, 'Suite A');
if (!$s1) {
    $ts->create($id, 'Suite A', 'top suite');
    $s1 = chByName($db, $id, 'Suite A');
    echo "suiteA=$s1\n";
}

if ($s1 > 0 && !chByName($db, $s1, 'Case A1')) {
    $steps = array(array('step_number' => 1, 'actions' => 'do it',
        'expected_results' => 'works', 'execution_type' => TESTCASE_EXECUTION_TYPE_MANUAL));
    $cid = $tc->create($s1, 'Case A1', 'summary', 'precon', $steps, 1);
    echo "case => " . (is_array($cid) ? $cid['id'] : $cid) . "\n";
}

echo "DONE projectId=$id s1=$s1\n";