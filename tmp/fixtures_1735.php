<?php
// Fixture for #1735: reqSpecSearch.php count($itemSet) on null.
// Creates tproject `REQ1735` (prefix R1735) with requirements + ReqMgr
// integration enabled and two requirement specifications, so both the retired
// legacy controller and the modern BFF reqspec-search path can be exercised.
// Run from repo root: php tmp/fixtures_1735.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$reqSpecMgr = new requirement_spec_mgr($db);
$tprojMgr = new testproject($db);
$userId = 1;

foreach ((array)$tprojMgr->get_by_name('REQ1735') as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'REQ1735';
$item->prefix = 'R1735';
$item->notes = 'fixture for issue 1735 (reqSpecSearch count(null))';
$item->color = '';
$item->active = 1;
$item->is_public = 1;
$opts = new stdClass();
$opts->requirementsEnabled = 1;
$opts->testPriorityEnabled = 0;
$opts->automationEnabled = 0;
$opts->inventoryEnabled = 0;
$opts->platformsEnabled = 0;
$opts->testcasecfEnabled = 0;
$opts->requirementcfEnabled = 0;
$opts->testScriptEnabled = 0;
$item->options = $opts;
$idP = intval($tprojMgr->create($item));
if ($idP <= 0) {
    die("tproject create failed\n");
}
echo "tproject=$idP\n";
$tprojMgr->setActive($idP);

// ReqMgr integration on
$db->exec_query("UPDATE testprojects SET reqmgr_integration_enabled=1 WHERE id=" . intval($idP));

$specDefs = array(
    'A' => array('R1735-A', 'Spec A', 'Top-level specification A.'),
    'B' => array('R1735-B', 'Spec B', 'Top-level specification B.'),
);
$specIds = array();
foreach ($specDefs as $key => $def) {
    $op = $reqSpecMgr->create($idP, $idP, $def[0], $def[1], $def[2], 3, $userId,
                              TL_REQ_SPEC_TYPE_USER_REQ_SPEC);
    if (empty($op['status_ok']) || intval($op['id']) <= 0) {
        die("spec {$def[0]} create failed: " . (isset($op['msg']) ? $op['msg'] : '?') . "\n");
    }
    $specIds[$key] = intval($op['id']);
    echo "spec {$def[0]} = {$specIds[$key]}\n";
}

echo "PROJECT={$idP} SPECS=" . json_encode($specIds) . "\n";
