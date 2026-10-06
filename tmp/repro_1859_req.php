<?php
// Repro for sibling bug issue #1861: requirement_mgr:1119
// current(get_last_active_version()) unguarded -> PHP 8 TypeError when the test
// case has NO active version. Uses TC1859-NOACTIVE from tmp/fixtures_1859.php
// (id resolved by name: ids grow across fixture re-runs).
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$reqSpecMgr = new requirement_spec_mgr($db);
$reqMgr = new requirement_mgr($db);
$userId = 1; // admin
$row = $db->get_recordset("SELECT id FROM nodes_hierarchy WHERE name='TC1859-NOACTIVE' AND node_type_id=3 ORDER BY id DESC LIMIT 1");
$tcaseId = intval($row[0]['id'] ?? 0);
if ($tcaseId === 0) {
  fwrite(STDERR, "FATAL: TC1859-NOACTIVE not found (run php tmp/fixtures_1859.php first)\n");
  exit(2);
}
$tprojectId = 1; // 'TC Edit 1859' (id grows across re-runs; project 1 on a fresh DB)

$docId = 'RS-NOACT' . date('His');
$spec = $reqSpecMgr->create($tprojectId, $tprojectId, $docId, $docId, 'scope', 2, $userId,
    TL_REQ_SPEC_TYPE_USER_REQ_SPEC);
$specId = intval($spec['id'] ?? 0);
printf("req_spec=%d\n", $specId);

$r = $reqMgr->create($specId, 'REQ-NOACT', 'requirement for no-active repro', 'scope',
    $userId, TL_REQ_STATUS_VALID, TL_REQ_TYPE_INFO, 1, 1, $tprojectId);
$reqId = intval($r['id'] ?? 0);
printf("req=%d\n", $reqId);

echo "calling assign_to_tcase(req=$reqId, tcase=$tcaseId (NO active version))...\n";
flush();
$out = $reqMgr->assign_to_tcase($reqId, $tcaseId, $userId);
printf("assign_to_tcase returned: %s\n", var_export($out, true));
echo "NO FATAL - survived\n";