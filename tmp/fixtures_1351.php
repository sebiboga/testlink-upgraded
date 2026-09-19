<?php
// Fixture for #1351 browser testing: reqSpecView.html revision log history tooltip.
// Creates tproject `RSV1351` (prefix RV51) + requirement spec SRS-RSV-1351 with TWO
// revisions carrying distinct log messages (rev 2 = "second revision of the spec",
// mirroring the legacy gadget fixture cited in the issue: getreqspeclog.php?item_id=
// 64 -> "second revision of the spec"). Run from repo root:
//   php tmp/fixtures_1351.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$reqSpecMgr = new requirement_spec_mgr($db);
$tprojMgr = new testproject($db);
$userId = 1; // admin

// Reset on re-runs.
foreach ((array)$tprojMgr->get_by_name('RSV1351') as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'RSV1351';
$item->prefix = 'RV51';
$item->notes = 'fixture for issue 1351 (reqSpecView revision log tooltip)';
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
echo "tproject=$idP (prefix RV51)\n";
$tprojMgr->setActive($idP);

// requirement spec: revision 1 auto-created, then re-logged explicitly
$ret = $reqSpecMgr->create($idP, $idP, 'SRS-RSV-1351', 'RSV1351 spec',
                           'Scope text of the RSV1351 spec fixture.', 0, $userId, '2');
if (empty($ret['status_ok']) || intval($ret['status_ok']) !== 1) {
    fwrite(STDERR, 'spec create failed: ' . (isset($ret['msg']) ? $ret['msg'] : 'unknown') . "\n");
    exit(1);
}
$specId = intval($ret['id']);
$rev1Id = intval($ret['revision_id']);
echo "reqspec=$specId rev1=$rev1Id\n";

// overwrite rev1 log so each revision carries a distinct, greppable message
$db->exec_query("UPDATE req_specs_revisions SET log_message = 'first revision of the spec' " .
                "WHERE id = " . intval($rev1Id));

// revision 2 with the exact message from the issue's legacy probe
$ret = $reqSpecMgr->clone_revision($specId, [
    'log_message' => "second revision of the spec\nExtra detail on a second line.",
    'author_id'   => $userId,
]);
if (empty($ret['status_ok']) || intval($ret['status_ok']) !== 1) {
    fwrite(STDERR, 'rev clone failed: ' . (isset($ret['msg']) ? $ret['msg'] : 'unknown') . "\n");
    exit(1);
}
echo 'rev2=' . intval($ret['id']) . "\n";

$rows = $db->get_recordset("SELECT RSV.id, RSV.revision, RSV.log_message " .
                           "FROM req_specs_revisions RSV WHERE RSV.parent_id = " . intval($specId) .
                           " ORDER BY RSV.revision ASC");
foreach (($rows ? $rows : []) as $row) {
    echo '  rev ' . $row['revision'] . ' (id ' . $row['id'] . ') log: ' . $row['log_message'] . "\n";
}
echo "fixture OK: spec SRS-RSV-1351 id=$specId\n";