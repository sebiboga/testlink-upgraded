<?php
// Fixture for #1309 browser testing: reqCompare.html revision-popup link on the
// "Last change" timestamp cell (Legacy reqCompareVersions.tpl:228-230 onclick
// openReqRevisionWindow(item_id) -> requirement revision viewer popup).
// Creates tproject `RC1309` (prefix `RC09`) + req spec `SRS-1309` + ONE
// requirement REQ-100 with TWO versions (v1 frozen, v2 open) carrying distinct
// scope/log_message, so reqCompare.html renders >=2 rows in its version table
// (each Last change cell must become a clickable link opening the popup).
// Run from repo root: php tmp/fixtures_1309.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$reqSpecMgr = new requirement_spec_mgr($db);
$reqMgr = new requirement_mgr($db);
$userId = 1; // admin

// Reset on re-runs (repo rule: fixtures must be re-runnable).
foreach ((array)$tprojMgr->get_by_name('RC1309') as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'RC1309';
$item->prefix = 'RC09';
$item->notes = 'fixture for issue 1309 (reqCompare revision-popup link)';
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
$r = $tprojMgr->create($item);
$idP = intval($r);
echo "tproject=$idP (prefix RC09)\n";
$tprojMgr->setActive($idP);

$op = $reqSpecMgr->create($idP, $idP, 'SRS-1309', 'RC1309 spec',
    'Scope of the RC1309 spec fixture.', 0, $userId, TL_REQ_SPEC_TYPE_USER_REQ_SPEC);
if (!$op['status_ok'] || $op['id'] <= 0) { die("spec create failed: " . $op['msg'] . "\n"); }
$idS = intval($op['id']);
echo "spec=$idS\n";

// requirement REQ-100, version 1
$op = $reqMgr->create($idS, 'REQ-100', 'RC1309 requirement',
    'Scope of REQ-100 v1 (original).', $userId,
    TL_REQ_STATUS_VALID, TL_REQ_TYPE_INFO, 1, 1, $idP);
if (!$op['status_ok'] || $op['id'] <= 0) { die("req create failed: " . $op['msg'] . "\n"); }
$reqId = intval($op['id']);
$v1Id = intval($op['version_id']);
echo "req REQ-100=$reqId v1=$v1Id\n";

// give v1 a deterministic log message + editor timestamp (modifier admin)
$db->exec_query("UPDATE req_versions SET log_message='v1 initial version', " .
    "modifier_id=$userId, modification_ts='2026-01-05 09:30:00' WHERE id=$v1Id");

// create v2 (copies v1, freezes source)
$op = $reqMgr->create_new_version($reqId, $userId, array(
    'log_msg' => 'v2 created for compare fixture',
));
if ($op['msg'] !== 'ok' || $op['id'] <= 0) { die("create_new_version failed: " . $op['msg'] . "\n"); }
$v2Id = intval($op['id']);
echo "v2=$v2Id (version " . $op['version'] . ")\n";

// give v2 a distinct scope + deterministic log / editor timestamp
$db->exec_query("UPDATE req_versions SET scope='Scope of REQ-100 v2 (edited for compare).', " .
    "log_message='v2 updated scope', " .
    "modifier_id=$userId, modification_ts='2026-02-10 14:45:00' WHERE id=$v2Id");

$rows = $db->get_recordset("SELECT REQV.id AS version_id, REQV.version, REQV.revision, " .
    "REQV.scope, REQV.log_message, REQV.modification_ts " .
    "FROM req_versions REQV JOIN nodes_hierarchy NH ON NH.id = REQV.id " .
    "WHERE NH.parent_id = " . intval($reqId) . " ORDER BY REQV.version");
foreach (($rows ? $rows : array()) as $row) {
    echo "  v" . $row['version'] . " (version_id " . $row['version_id'] .
        ", revision " . $row['revision'] . ") log: " . $row['log_message'] . " | " . $row['modification_ts'] . "\n";
}
echo "fixture OK: project RC1309 (id=$idP), spec SRS-1309 (id=$idS), req REQ-100 (id=$reqId)\n";