<?php
// Repro for suspected sibling bug (issue #1860 candidate): requirement_mgr:1119
// current(get_last_active_version()) unguarded -> PHP 8 TypeError when the test
// case has NO active version. Uses tcase 3 from tmp/fixtures_1859.php.
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$reqSpecMgr = new requirement_spec_mgr($db);
$reqMgr = new requirement_mgr($db);
$userId = 1; // admin
$tprojectId = 1; // 'TC Edit 1859'

$docId = 'RS-NOACT' . date('His');
$spec = $reqSpecMgr->create($tprojectId, $tprojectId, $docId, $docId, 'scope', 2, $userId,
    TL_REQ_SPEC_TYPE_USER_REQ_SPEC);
$specId = intval($spec['id'] ?? 0);
printf("req_spec=%d\n", $specId);

$r = $reqMgr->create($specId, 'REQ-NOACT', 'requirement for no-active repro', 'scope',
    $userId, TL_REQ_STATUS_VALID, TL_REQ_TYPE_INFO, 1, 1, $tprojectId);
$reqId = intval($r['id'] ?? 0);
printf("req=%d\n", $reqId);

echo "calling assign_to_tcase(req=$reqId, tcase=3 (NO active version))...\n";
flush();
$out = $reqMgr->assign_to_tcase($reqId, 3, $userId);
printf("assign_to_tcase returned: %s\n", var_export($out, true));
echo "NO FATAL - survived\n";