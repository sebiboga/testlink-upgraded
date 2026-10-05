<?php
/**
 * Fixture for #1852 (Test Suite Create/Edit/Delete modern screen).
 * Creates test project SUITE52 (prefix SU52) with:
 *   - a parent suite "SU52 Root" with a CHILD suite and a test case,
 *   - design custom fields of the 'testsuite' type linked to the project
 *     (string, numeric, checkbox, list, multiselection, date, text area),
 *   - two keywords, one already assigned to the child suite,
 *   - a SECOND test project SUITE52B used for the cross-project IDOR proofs.
 * Run from repo root: php tmp/fixtures_1852.php
 */
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tplanMgr = new testplan($db);
$tcaseMgr = new testcase($db);
$tsuiteMgr = new testsuite($db);
$cfMgr = new cfield_mgr($db);
$kwMgr = new tlKeyword($db);
$userId = 1; // admin

function dropProject(&$tprojMgr, $name)
{
    foreach ((array)$tprojMgr->get_by_name($name) as $row) {
        $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
        if ($oid > 0) {
            echo "deleting old project $oid ($name)\n";
            $tprojMgr->delete($oid, 1);
        }
    }
}

dropProject($tprojMgr, 'SUITE52');
dropProject($tprojMgr, 'SUITE52B');

function makeProject(&$tprojMgr, $name, $prefix, $notes)
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
    $opts->testsuitecfEnabled = 1;
    $item->options = $opts;
    $id = intval($tprojMgr->create($item));
    $tprojMgr->setActive($id);
    return $id;
}

$idP = makeProject($tprojMgr, 'SUITE52', 'SU52', 'fixture #1852 suite edit');
$idPB = makeProject($tprojMgr, 'SUITE52B', 'S5B', 'fixture #1852 cross-project probe');
echo "tproject A=$idP  B=$idPB\n";

/* ---- design custom fields of type 'testsuite' on project A ----
   cfield_mgr has no create() in 2.0.1 (the legacy cfieldsEdit.php owns the
   insert), so the rows are written directly, exactly like tmp/fixtures_1791.php.
   node_type_id for 'testsuite' is 2 (node_types table). */
$cfDefs = array(
    //  name              type  label            required  possible_values
    array('su52_owner',    '0',  'Suite Owner',   0, ''),
    array('su52_kind',     '0',  'Suite Kind',    1, ''),
    array('su52_effort',   '1',  'Effort Days',   0, ''),
    array('su52_smoke',    '5',  'Smoke Suite',   0, ''),
    array('su52_tier',     '6',  'Suite Tier',    0, 'gold|silver|bronze'),
    array('su52_areas',    '7',  'Suite Areas',   0, 'ui|api|db'),
    array('su52_review',   '8',  'Review Date',   0, ''),
    array('su52_notes',    '20', 'Suite Notes',   0, ''),
);
$cfIds = array();
foreach ($cfDefs as $d) {
    $row = $db->get_recordset("SELECT id FROM custom_fields WHERE name='{$d[0]}'");
    if (!empty($row)) {
        $cfId = intval($row[0]['id']);
    } else {
        $sql = "INSERT INTO custom_fields
          (name,label,type,possible_values,default_value,valid_regexp,length_min,length_max,
           show_on_design,enable_on_design,show_on_execution,enable_on_execution,
           show_on_testplan_design,enable_on_testplan_design)
          VALUES ('{$d[0]}','{$d[2]}','{$d[1]}','{$d[4]}','','',0,0,1,1,0,0,0,0)";
        $db->exec_query($sql);
        $cfId = intval($db->insert_id());
    }
    if ($cfId <= 0) {
        die("cfield create failed for {$d[0]}\n");
    }
    $cfIds[$d[0]] = $cfId;
    $have = $db->get_recordset("SELECT field_id FROM cfield_node_types WHERE field_id=$cfId AND node_type_id=2");
    if (empty($have)) {
        $db->exec_query("INSERT INTO cfield_node_types (field_id,node_type_id) VALUES ($cfId,2)");
    }
    $have = $db->get_recordset("SELECT field_id FROM cfield_testprojects WHERE field_id=$cfId AND testproject_id=$idP");
    if (empty($have)) {
        $db->exec_query("INSERT INTO cfield_testprojects
          (field_id,testproject_id,display_order,location,active,required,required_on_design,required_on_execution,monitorable)
          VALUES ($cfId,$idP,0,1,1,{$d[3]},{$d[3]},0,0)");
    }
    echo "cfield {$d[0]} = $cfId (type {$d[1]})\n";
}

/* ---- suites ---- */
$ret = $tsuiteMgr->create($idP, 'SU52 Root', 'root suite of the fixture', null, 0, 'allow_repeat');
$idRoot = intval($ret['id'] ?? 0);
$ret = $tsuiteMgr->create($idRoot, 'SU52 Child', 'child suite, holds the test case', null, 0, 'allow_repeat');
$idChild = intval($ret['id'] ?? 0);
$ret = $tsuiteMgr->create($idRoot, 'SU52 Empty', 'suite with no test cases at all', null, 0, 'allow_repeat');
$idEmpty = intval($ret['id'] ?? 0);
// a suite in the OTHER project, for the cross-project 404 proof
$ret = $tsuiteMgr->create($idPB, 'S5B Foreign', 'must not be reachable from project A', null, 0, 'allow_repeat');
$idForeign = intval($ret['id'] ?? 0);
echo "suites root=$idRoot child=$idChild empty=$idEmpty foreign=$idForeign\n";

/* ---- keywords ---- */
/* tlKeyword exposes no create(): initialize() + writeToDB(), the legacy path. */
function makeKeyword(&$db, $kwMgr, $tprojectId, $name)
{
    $kw = new tlKeyword();
    $kw->initialize(null, intval($tprojectId), $name, 'fixture #1852');
    if ($kw->writeToDB($db) != tl::OK) {
        die("keyword create failed for $name\n");
    }
    return intval($kw->dbID);
}
$kwA = makeKeyword($db, $kwMgr, $idP, 'suite-kw-alpha');
$kwB = makeKeyword($db, $kwMgr, $idP, 'suite-kw-beta');
echo "keywords alpha=$kwA beta=$kwB\n";

$tsuiteMgr->addKeywords($idChild, array($kwA));

/* ---- test case inside the child suite ---- */
$steps = array();
$s = new stdClass();
$s->step_number = 1;
$s->actions = 'Open the suite under test';
$s->expected_results = 'The suite is listed';
$s->execution_type = TESTCASE_EXECUTION_TYPE_MANUAL;
$steps[] = $s;
$ret = $tcaseMgr->create($idChild, 'SU52 Login Case', 'a test case used by the delete preview',
    '', $steps, $userId, '', testcase::DEFAULT_ORDER, testcase::AUTOMATIC_ID,
    TESTCASE_EXECUTION_TYPE_MANUAL);
if (empty($ret['status_ok'])) {
    die('tcase create failed: ' . ($ret['message'] ?? '') . "\n");
}
$idVer = intval($ret['tcversion_id']);
echo "tcase=" . intval($ret['id']) . " version=$idVer\n";

/* ---- a design CF value stored for the child suite ---- */
$cfMap = $tsuiteMgr->get_linked_cfields_at_design($idChild, null, null, $idP);
if (count((array)$cfMap) == 0) {
    die("FATAL: no testsuite design cfields linked to project $idP\n");
}
echo "linked design cfields: " . count((array)$cfMap) . "\n";
$hash = array();
/* NOTE: never blanket-send custom_field_<t>_<id>_input - _build_cfield()
   treats EVERY key ending in _input as a DATE part, so a string field would
   keep an ARRAY cf_value and design_values_to_db() then calls
   tlStringLen(array) -> PHP 8 TypeError (see #1853). */
if (isset($cfIds['su52_review'])) {
    $fid = $cfIds['su52_review'];
    $d = date('Y-m-d', mktime(0, 0, 0, 3, 15, 2026));
    $fmt = str_replace('%', '', config_get('locales_date_format')[$_SESSION['locale'] ?? 'en_GB'] ?? 'd/m/Y');
    $hash['custom_field_8_' . $fid . '_input'] = strtr($fmt, array('d' => '15', 'm' => '03', 'Y' => '2026'));
}
if (isset($cfIds['string_single'])) {
    $fid = $cfIds['string_single'];
    $hash['custom_field_0_' . $fid] = 'stored owner value';
}
if (isset($cfIds['num_field'])) {
    $fid = $cfIds['num_field'];
    $hash['custom_field_1_' . $fid] = '7';
}
if (isset($cfIds['chk_field'])) {
    $fid = $cfIds['chk_field'];
    $hash['custom_field_5_' . $fid] = array('1');
}
if (isset($cfIds['list_field'])) {
    $fid = $cfIds['list_field'];
    $hash['custom_field_6_' . $fid] = 'silver';
}
if (isset($cfIds['multi_field'])) {
    $fid = $cfIds['multi_field'];
    $hash['custom_field_7_' . $fid] = array('ui', 'api');
}
if (isset($cfIds['ta_field'])) {
    $fid = $cfIds['ta_field'];
    $hash['custom_field_20_' . $fid] = 'stored rich text note';
}
$cfMgr->design_values_to_db($hash, $idChild, $cfMap, null, 'testsuite');
echo "cf values seeded on suite $idChild\n";

/* One EXECUTED test case under SU52 Child: it is what the legacy
   delete_executed_testcases gate keys on (build_del_testsuite_warning_msg /
   testsuite->delete_deep), so the delete-mode gate can be exercised. */
if (intval($idVer) > 0) {
    /* get_exec_status() (testcase.class.php:3193) INNER JOINs
       testplan_tcversions + nodes_hierarchy(tplan) + tcversions, so an
       "executed" case only exists through the real chain
       test plan -> testplan_tcversions -> tcversion -> executions row.
       An executions row alone reads back as no_links, which is why the
       legacy delete gate stayed open in the first attempt at this fixture. */
    $nodeTypes = tlObjectWithDB::getDBTables(array('nodes_hierarchy'));
    $db->exec_query(
        "INSERT INTO {$nodeTypes['nodes_hierarchy']} " .
        "(parent_id, node_type_id, node_order, name) " .
        "VALUES (" . intval($idP) . ", 4, 1, 'SU52 Plan')");
    $idTplan = intval($db->insert_id($nodeTypes['nodes_hierarchy']));

    $tct = tlObjectWithDB::getDBTables(array('testplan_tcversions'));
    $db->exec_query(
        "INSERT INTO {$tct['testplan_tcversions']} " .
        "(testplan_id, tcversion_id, node_order, urgency, platform_id) " .
        "VALUES (" . $idTplan . ", " . intval($idVer) . ", 1, 2, 0)");

    $db->exec_query(
        "INSERT INTO {$GLOBALS['DBTABLE_PREFIX']}executions " .
        "(tester_id, execution_ts, status, testplan_id, tcversion_id, tcversion_number, " .
        " platform_id, execution_type, execution_duration) " .
        "VALUES (1, NOW(), 'p', " . $idTplan . ", " . intval($idVer) . ", 1, 0, 1, 0)");
    echo "executed tcase version=" . intval($idVer) . " tplan=$idTplan\n";
}

echo "DONE\n";
echo "PROJECT=$idP\n";
echo "PROJECT_B=$idPB\n";
echo "SUITE_ROOT=$idRoot\n";
echo "SUITE_CHILD=$idChild\n";
echo "SUITE_EMPTY=$idEmpty\n";
echo "SUITE_FOREIGN=$idForeign\n";
