<?php
// Fixture for #1833: project-scoped Requirement Specification launcher
// (api/projectreqspecmgmt). Creates a requirements-enabled test project with a
// NESTED specification tree and requirements at two different depths, so the
// BFF counters have to walk a real parent chain and must not mistake
// requirement_version nodes for requirements.
//
// Run from repo root: php tmp/fixtures_1833.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = 1; // admin
$tprojMgr = new testproject($db);
$nh = tlObjectWithDB::getDBTables(array('nodes_hierarchy', 'testprojects'));

// Idempotent: drop the fixture project by NAME (node in nodes_hierarchy).
$name = 'RSP Fixture Project';
$old = (array)$db->get_recordset(
    "SELECT id FROM {$nh['nodes_hierarchy']} WHERE name = '" . addslashes($name) . "'"
);
foreach ($old as $row) {
    $oid = intval($row['id']);
    if ($oid > 0) {
        $tprojMgr->delete($oid, 1);
        echo "deleted old project $oid\n";
    }
}

$item = new stdClass();
$item->name = $name;
$item->prefix = 'RSP';
$item->notes = 'fixture for issue #1833 (project-scoped req spec launcher)';
$item->color = '';
$item->active = 1;
$item->is_public = 1;
$opts = new stdClass();
$opts->requirementsEnabled = 1;
$opts->testPriorityEnabled = 1;
$opts->automationEnabled = 0;
$opts->inventoryEnabled = 0;
$opts->platformsEnabled = 0;
$opts->testcasecfEnabled = 0;
$opts->requirementcfEnabled = 0;
$item->options = $opts;

$tprojectId = intval($tprojMgr->create($item));
if ($tprojectId <= 0) {
    die("tproject create failed\n");
}
$tprojMgr->setActive($tprojectId);
// create() does NOT apply $item->options; they have to be written afterwards
// (same order as lib/project/projectEdit.php: create() then setOptions()).
$tprojMgr->setOptions($tprojectId, $opts);
echo "tproject=$tprojectId (requirementsEnabled="
    . (int)$tprojMgr->getOptions($tprojectId)->requirementsEnabled . ")\n";

function nodeTypeId($db, string $desc): int
{
    $rs = $db->get_recordset("SELECT id FROM node_types WHERE description = '" . $desc . "'");
    foreach ((array)$rs as $r) {
        return intval($r['id']);
    }
    return 0;
}

function addNode($db, string $table, int $parent, string $desc, string $nodeName, int $tprojectId): int
{
    $nt = nodeTypeId($db, $desc);
    if ($nt <= 0) {
        die("node type '$desc' missing\n");
    }
    $ok = $db->exec_query(
        "INSERT INTO {$table} (parent_id, node_type_id, name) VALUES ("
        . (int)$parent . ', ' . $nt . ", '" . addslashes($nodeName) . "')"
    );
    if (!$ok) {
        die("insert $desc '$nodeName' failed\n");
    }
    $id = intval($db->fetchOneValue('SELECT LAST_INSERT_ID()'));
    if ($id <= 0) {
        die("could not resolve id of $desc '$nodeName'\n");
    }
    echo "$desc $id = $nodeName\n";
    return $id;
}

$nhTable = $nh['nodes_hierarchy'];

// Top-level specification, directly under the project.
$specA = addNode($db, $nhTable, $tprojectId, 'requirement_spec', 'Functional Specification', $tprojectId);
// Nested specification under the first one.
$specB = addNode($db, $nhTable, $specA, 'requirement_spec', 'Functional Specification / Checkout', $tprojectId);
// One requirement under each level of the tree.
addNode($db, $nhTable, $specA, 'requirement', 'REQ-001 The cart keeps its contents', $tprojectId);
addNode($db, $nhTable, $specB, 'requirement', 'REQ-002 The payment is confirmed', $tprojectId);

// A third requirement, to prove the counter reports 3 rather than 2 (a
// requirement_version node is a DIFFERENT node type and is correctly never
// counted as a requirement, which is why none is planted here - planting one
// through this helper would require a version node parented on a requirement).
addNode($db, $nhTable, $specA, 'requirement', 'REQ-003 The order is confirmed', $tprojectId);
echo "fixture #1833 ready: project {$tprojectId}, 2 specs, 3 requirements\n";
