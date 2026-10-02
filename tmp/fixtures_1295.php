<?php
/**
 * Fixture for #1295 (reqExport parity audit): creates test project `RX1295`
 * with one req spec `SRS-1295` (child spec `SRS-1295B` nested) and one
 * requirement `REQ-200`, plus REAL attachments on BOTH the req spec and the
 * requirement (fk_table 'req_specs' / 'requirements').
 *
 * Purpose: the #1295 audit only compared exports on a fixture WITHOUT
 * attachments, so `exportAttachments=1` produced byte-identical output and the
 * "Export attachments" checkbox was effectively never exercised. This fixture
 * makes the ATTACHMENTS branch of requirement_spec_mgr::exportReqSpecToXML()
 * observable, so legacy vs modern output can be diffed for real.
 *
 * Usage: php tmp/fixtures_1295.php
 */
require_once(__DIR__ . '/../config.inc.php');
require_once(__DIR__ . '/../lib/functions/attachments.inc.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$reqSpecMgr = new requirement_spec_mgr($db);
$reqMgr = new requirement_mgr($db);
$userId = 1; // admin

// idempotent re-run
foreach ((array)$tprojMgr->get_by_name('RX1295') as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'RX1295';
$item->prefix = 'RX95';
$item->notes = 'fixture for issue 1295 (reqExport attachments parity)';
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
$item->options = $opts;
$idP = intval($tprojMgr->create($item));
if ($idP <= 0) { die("tproject create failed\n"); }
$tprojMgr->setActive($idP);
echo "tproject=$idP\n";

$op = $reqSpecMgr->create($idP, $idP, 'SRS-1295', 'RX1295 parent spec',
    'Parent spec scope.', 0, $userId, TL_REQ_SPEC_TYPE_USER_REQ_SPEC);
if (!$op['status_ok']) { die("spec create failed: {$op['msg']}\n"); }
$idSpecA = intval($op['id']);
echo "specA=$idSpecA\n";

$op = $reqSpecMgr->create($idP, $idSpecA, 'SRS-1295B', 'RX1295 nested spec',
    'Nested spec scope.', 0, $userId, TL_REQ_SPEC_TYPE_USER_REQ_SPEC);
if (!$op['status_ok']) { die("nested spec create failed: {$op['msg']}\n"); }
$idSpecB = intval($op['id']);
echo "specB=$idSpecB\n";

$op = $reqMgr->create($idSpecA, 'REQ-200', 'RX1295 requirement',
    'Requirement scope for attachment parity check.', $userId,
    TL_REQ_STATUS_VALID, TL_REQ_TYPE_INFO, 1, 1, $idP);
if (!$op['status_ok']) { die("req create failed: {$op['msg']}\n"); }
$idReq = intval($op['id']);
echo "req=$idReq\n";

// ---- real attachments -----------------------------------------------------
$repoDir = sys_get_temp_dir() . '/tlu_att_1295';
if (!is_dir($repoDir)) { mkdir($repoDir, 0777, true); }
file_put_contents($repoDir . '/spec-note.txt', "ISSUE-1295 spec attachment payload\n");
file_put_contents($repoDir . '/req-note.txt', "ISSUE-1295 requirement attachment payload\n");

$attTables = tlObjectWithDB::getDBTables(array('attachments'));
$db->exec_query("DELETE FROM {$attTables['attachments']} "
    . "WHERE fk_table IN ('req_specs','requirements') "
    . "AND fk_id IN ({$idSpecA}, {$idReq})");

$targets = array(
    array($idSpecA, 'req_specs',     'spec-note.txt', 'text/plain', 'spec attachment'),
    array($idReq,  'requirements',  'req-note.txt',  'text/plain', 'req attachment'),
);
foreach ($targets as $t) {
    list($fkId, $table, $file, $mime, $title) = $t;
    $fin = array(
        'name' => $file, 'type' => $mime,
        'tmp_name' => $repoDir . '/' . $file,
        'size' => filesize($repoDir . '/' . $file), 'error' => 0,
    );
    $op = tlAttachmentRepository::create($db)->insertAttachment($fkId, $table, $title, $fin);
    if (!$op->statusOK) {
        die("insertAttachment FAILED {$table}/{$fkId}: " . valToString($op->msg) . "\n");
    }
    echo "attachment {$table}={$fkId} id={$op->id}\n";
}

echo "DONE\n";
echo "TPROJECT=$idP\nSPEC_A=$idSpecA\nSPEC_B=$idSpecB\nREQ=$idReq\n";