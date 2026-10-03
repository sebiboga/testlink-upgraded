<?php
/**
 * Fixture for #1812 (Custom Field Editor cfieldsEdit).
 *
 * Creates two test projects and a custom field of each shape the editor has to
 * cope with:
 *   CFE 1812  - plain string CF on Test Case, design area, UNUSED  (editable type)
 *   CFE1812USE- checkbox CF on Test Case, execution area, USED     (type locked)
 *   CFE1812REQ- CF on Requirement Specification                  (different node type)
 * Run from repo root:  php tmp/fixtures_1812.php
 */
require_once('config.inc.php');
require_once('common.php');
require_once('users.inc.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$cfieldMgr = new cfield_mgr($db);

$made = array();
foreach (array('CFE Main', 'CFE Other') as $name) {
    foreach ((array)$tprojMgr->get_by_name($name) as $row) {
        $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
        if ($oid > 0) { $tprojMgr->delete($oid, 1); }
    }
    $item = new stdClass();
    $item->name = $name;
    $item->prefix = ($name === 'CFE Main') ? 'CFEM' : 'CFEO';
    $item->notes = 'fixture for issue 1812 (cfieldsEdit editor)';
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
$idMain = $made['CFE Main'];
$idOther = $made['CFE Other'];

function cfMake($db, $cfieldMgr, $name, $label, $type, $node, $enableOn, $pv = '')
{
    $cf = array(
        'name' => $name, 'label' => $label, 'type' => $type,
        'possible_values' => $pv, 'node_type_id' => $node,
        'enable_on' => $enableOn,
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

foreach (array('CFE1812A', 'CFE1812USE', 'CFE1812REQ') as $old) {
    $byName = $cfieldMgr->get_by_name($old);
    if (!is_null($byName)) {
        foreach (array_keys($byName) as $oid) { $cfieldMgr->delete(intval($oid)); }
        echo "removed old cfield $old\n";
    }
}

$idPlain = cfMake($db, $cfieldMgr, 'CFE1812A', 'Plain string CF', 0, 3, 'design');
$idUsed  = cfMake($db, $cfieldMgr, 'CFE1812USE', 'Used checkbox CF', 5, 3, 'execution', "1\n0");
$idReq   = cfMake($db, $cfieldMgr, 'CFE1812REQ', 'CF on Requirement Spec', 0, 6, 'design');

// make CFE1812USE "used" by inserting a value row directly (no test case needed:
// the is_used() probe unions the four cfield_*_values tables)
$tables = tlObject::getDBTables(array('cfield_execution_values'));
$db->exec_query("INSERT INTO {$tables['cfield_execution_values']} (field_id,execution_id,value) "
              . "VALUES({$idUsed}, 1, '1')");
echo "CFE1812USE is_used=" . $cfieldMgr->is_used($idUsed) . "\n";

// link the plain CF to both projects so the editor shows the linked-projects list
$cfieldMgr->link_to_testproject($idMain, array($idPlain));
$cfieldMgr->link_to_testproject($idOther, array($idPlain));
echo "linked CFE1812A to $idMain and $idOther\n";

file_put_contents(__DIR__ . '/fixture_1812.json', json_encode(array(
    'tproject_main' => $idMain,
    'tproject_other' => $idOther,
    'cf_plain' => $idPlain,
    'cf_used' => $idUsed,
    'cf_req' => $idReq,
), JSON_PRETTY_PRINT));
echo "wrote tmp/fixture_1812.json\n";
