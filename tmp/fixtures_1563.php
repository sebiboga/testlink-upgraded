<?php
// Fixture for #1563 browser/curl testing: cfieldsAssignView Location dropdown
// (gui/templates/cfields/cfieldsAssignView.html + api/cfields/index.php GET
// /assignment). Creates tproject `Demo Project` (prefix DMP1563, testcase
// custom fields enabled) + testcase custom field `assigned_cf` linked to it
// (location 5 = after_title) so the assignment screen renders the Location
// dropdown with all 8 location codes.
// Run from repo root: php tmp/fixtures_1563.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$userId = 1; // admin

foreach ((array)$tprojMgr->get_by_name('Demo Project') as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid);
    }
}

// remove a leftover custom field from a previous run of this fixture
$old = $db->fetchRowsIntoMap("SELECT id FROM custom_fields WHERE name='assigned_cf'", 'id');
foreach (array_keys((array)$old) as $fid) {
    $fid = intval($fid);
    $db->exec_query("DELETE FROM cfield_node_types WHERE field_id=$fid");
    $db->exec_query("DELETE FROM cfield_testprojects WHERE field_id=$fid");
    $db->exec_query("DELETE FROM custom_fields WHERE id=$fid");
    echo "deleted old custom field $fid\n";
}

$item = new stdClass();
$item->name = 'Demo Project';
$item->prefix = 'DMP1563';
$item->notes = 'fixture for issue 1563 (cfieldsAssignView Location LOCALIZE labels)';
$item->color = '';
$item->active = 1;
$item->is_public = 1;
$opts = new stdClass();
$opts->requirementsEnabled = 0;
$opts->testPriorityEnabled = 0;
$opts->automationEnabled = 0;
$opts->inventoryEnabled = 0;
$opts->platformsEnabled = 0;
$opts->testcasecfEnabled = 1;
$opts->requirementcfEnabled = 0;
$item->options = $opts;
$r = $tprojMgr->create($item);
$idP = intval($r);
if ($idP <= 0) {
    die("tproject create failed\n");
}
echo "tproject=$idP (prefix DMP1563)\n";
$tprojMgr->setActive($idP);

$cfMgr = new cfield_mgr($db);
$cf = array(
    'name' => 'assigned_cf',
    'label' => 'Assigned CF (issue 1563)',
    'type' => 0, // string
    'possible_values' => '',
    'show_on_design' => 1,
    'enable_on_design' => 1,
    'show_on_testplan_design' => 0,
    'enable_on_testplan_design' => 0,
    'show_on_execution' => 0,
    'enable_on_execution' => 0, // location support requires 0
    'node_type_id' => 3, // testcase
);
$ret = $cfMgr->create($cf);
if (!$ret['status_ok']) {
    die("custom field create failed\n");
}
$fieldId = intval($ret['id']);
echo "custom_field=$fieldId\n";

$cfMgr->link_to_testproject($idP, array($fieldId));
// location 5 = after_title (the dropdown entry the issue calls out)
$db->exec_query("UPDATE cfield_testprojects SET location=5, display_order=1, active=1" .
    " WHERE testproject_id=$idP AND field_id=$fieldId");

echo "DONE\n";
echo "tproject_id=$idP\n";
echo "custom_field_id=$fieldId\n";
