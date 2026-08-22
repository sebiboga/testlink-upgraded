<?php
// CLI fixtures for keywordsAssign testing. Run: php tmp/fixtures_kwa.php
$_SESSION = array();
require_once(dirname(__DIR__) . '/config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tp = new testproject($db);
$ts = new testsuite($db);
$tc = new testcase($db);

function childByName($tcMgr, $parentId, $name)
{
    global $db;
    $rows = $db->get_recordset(
        "SELECT NH.id FROM nodes_hierarchy NH " .
        " JOIN node_types NT ON NT.id = NH.node_type_id " .
        " WHERE NH.parent_id = " . intval($parentId) .
        " AND NH.name = '" . $db->prepare_string($name) . "'" .
        " AND NT.description IN ('testsuite','testcase')");
    return empty($rows) ? 0 : intval($rows[0]['id']);
}

$name = 'KWA Demo Project';
$rs = $tp->get_by_name($name);
if ($rs) {
    echo "project exists id={$rs[0]['id']}\n";
    $id = intval($rs[0]['id']);
} else {
    $item = new stdClass();
    $item->name = $name;
    $item->prefix = 'KWA';
    $item->notes = 'fixture';
    $item->options = new stdClass();
    $item->color = '';
    $item->active = 1;
    $item->is_public = 1;
    $id = intval($tp->create($item));
    echo "project=$id\n";

    foreach (array('alpha', 'beta', 'gamma', 'delta') as $kw) {
        $tp->addKeyword($id, $kw, 'note ' . $kw);
        echo "kw $kw added\n";
    }
}

$s1 = childByName($tp, $id, 'Suite A');
if (!$s1) { $ts->create($id, 'Suite A', 'top suite'); $s1 = childByName($tp, $id, 'Suite A'); echo "suiteA=$s1\n"; }
$s2 = childByName($tp, $s1, 'Suite B');
if (!$s2) { $ts->create($s1, 'Suite B', 'nested suite'); $s2 = childByName($tp, $s1, 'Suite B'); echo "suiteB=$s2\n"; }

$authorId = 1;
foreach (array(array($s1, 'Case A1'), array($s1, 'Case A2'), array($s2, 'Case B1')) as $spec) {
    list($parent, $cname) = $spec;
    if (!childByName($tc, $parent, $cname)) {
        $steps = array(array('step_number' => 1, 'actions' => 'do it',
            'expected_results' => 'works', 'execution_type' => TESTCASE_EXECUTION_TYPE_MANUAL));
        $cid = $tc->create($parent, $cname, 'summary', 'precon', $steps, $authorId);
        echo "$cname => " . (is_array($cid) ? $cid['id'] : $cid) . "\n";
    }
}

// preassign alpha to Case A1
$kwMap = $tp->get_keywords_map($id);
$alphaId = 0;
if (!is_null($kwMap)) {
    foreach ($kwMap as $kid => $kv) {
        $kn = is_array($kv) ? $kv['keyword'] : $kv;
        if ($kn === 'alpha') { $alphaId = intval($kid); break; }
    }
}
$c1 = childByName($tc, $s1, 'Case A1');
if ($alphaId > 0 && $c1 > 0) {
    $ltcv = $tc->get_last_version_info($c1, array('output' => 'thin', 'active' => 1));
    $cur = $tc->get_keywords_map($c1, $ltcv['tcversion_id']);
    if (is_null($cur) || !isset($cur[$alphaId])) {
        $tc->setKeywords($c1, $ltcv['tcversion_id'], array($alphaId));
        echo "preassigned alpha to case $c1\n";
    } else {
        echo "alpha already assigned\n";
    }
}
echo "DONE s1=$s1 s2=$s2\n";
