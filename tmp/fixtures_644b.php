<?php
// Fixture for issue #644 part 2: create a second test case and an
// EXECUTE_TOGETHER (type 5) relation to TC-644 so relations.inc.tpl renders.
// Run from repo root: php tmp/fixtures_644b.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

function firstId($r) {
    if (is_array($r)) {
        if (isset($r['id'])) { return intval($r['id']); }
        $k = array_keys($r);
        return intval($k[0]);
    }
    return intval($r);
}

$tprojMgr = new testproject($db);
$tsuiteMgr = new testsuite($db);
$tcaseMgr = new testcase($db);

$old = $tprojMgr->get_by_name('LOC644');
$projId = 0;
foreach ((array)$old as $row) { $projId = intval($row['id']); break; }
echo "project=$projId\n";

// find the suite of project LOC644 by name
$rs = $db->get_recordset("SELECT id FROM nodes_hierarchy WHERE name='Suite 644' LIMIT 1");
$idS1 = intval($rs[0]['id']);

// second test case
$idT2 = firstId($tcaseMgr->create($idS1, 'TC-644B', 'summary b', 'precond b',
    [['step_number' => 1, 'actions' => 'do it', 'expected_results' => 'works']], 1));
$idTC2 = is_array($idT2) ? $idT2['id'] : $idT2;
echo "tcaseB=$idTC2\n";

// find the first TC (TC-644)
$rs = $db->get_recordset("SELECT id FROM nodes_hierarchy WHERE name='TC-644' LIMIT 1");
$idTC1 = intval($rs[0]['id']);
echo "tcaseA=$idTC1\n";

// relation: source=TC-644 version, destination=TC-644B version, type 5
$rsA = $db->get_recordset("SELECT id FROM nodes_hierarchy WHERE parent_id={$idTC1} ORDER BY id DESC");
$vA = intval($rsA[0]['id']);
$rsB = $db->get_recordset("SELECT id FROM nodes_hierarchy WHERE parent_id={$idTC2} ORDER BY id DESC");
$vB = intval($rsB[0]['id']);
$userRs = $db->get_recordset("SELECT id FROM users ORDER BY id LIMIT 1");
$uid = intval($userRs[0]['id']);
$sql = "INSERT INTO testcase_relations (source_id, destination_id, relation_type, author_id) " .
       "VALUES ($vA,$vB,5,$uid)";
$db->exec_query($sql);
echo "relation inserted: vA=$vA vB=$vB type=5 user=$uid\n";
echo "DONE\n";
