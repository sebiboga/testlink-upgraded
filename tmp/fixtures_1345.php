<?php
// Fixture for #1345 browser testing: reqSpecView.html spec lifecycle toolbar
// (New Req Spec / Edit / Delete in the viewer, gap vs legacy).
// Creates tproject `RSV1345` (prefix RV45) + parent spec SRS-PARENT-001.
// Run from repo root:  php tmp/fixtures_1345.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$reqSpecMgr = new requirement_spec_mgr($db);
$tprojMgr = new testproject($db);
$userId = 1; // admin

foreach ((array)$tprojMgr->get_by_name('RSV1345') as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'RSV1345';
$item->prefix = 'RV45';
$item->notes = 'fixture for issue 1345 (reqSpecView spec lifecycle toolbar)';
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
$opts->testScriptEnabled = 0;
$item->options = $opts;
$r = $tprojMgr->create($item);
$idP = intval($r);
echo "tproject=$idP (prefix RV45)\n";
$tprojMgr->setActive($idP);

$ret = $reqSpecMgr->create($idP, $idP, 'SRS-PARENT-001', 'Parent spec RSV1345',
                           'Scope of the parent spec.', 0, $userId, '2');
if (empty($ret['status_ok']) || intval($ret['status_ok']) !== 1) {
    fwrite(STDERR, 'spec create failed: ' . (isset($ret['msg']) ? $ret['msg'] : 'unknown') . "\n");
    exit(1);
}
$specId = intval($ret['id']);
echo "reqspec=$specId (SRS-PARENT-001)\n";

$rows = $db->get_recordset(
    "SELECT NH.id, NH.parent_id, RS.doc_id FROM nodes_hierarchy NH" .
    " JOIN req_specs RS ON RS.id = NH.id" .
    " WHERE RS.testproject_id = " . intval($idP) . " ORDER BY NH.id ASC");
foreach (($rows ? $rows : []) as $row) {
    echo "  spec node id=" . $row['id'] . " parent=" . $row['parent_id'] . " doc=" . $row['doc_id'] . "\n";
}
echo "fixture OK: spec SRS-PARENT-001 id=$specId\n";