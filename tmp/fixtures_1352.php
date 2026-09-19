<?php
// Fixture for #1352 browser testing: reqSpecView attachments upload/delete UI
// (gui/templates/requirements/reqSpecView.html). Creates tproject `RSV1352`
// (prefix `RVR` requirements on) + req spec `RSV-ATT` + two requirements
// (doc ids SRQ-001 / SRQ-002), exactly like the legacy fixture SRS-RSV-001
// used to browser-verify the legacy attachments block.
// Run from repo root: php tmp/fixtures_1352.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$reqSpecMgr = new requirement_spec_mgr($db);
$reqMgr = new requirement_mgr($db);
$userId = 1; // admin

// Reset on re-runs (repo rule: fixtures must be re-runnable).
foreach ((array)$tprojMgr->get_by_name('RSV1352') as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'RSV1352';
$item->prefix = 'RVR';
$item->notes = 'fixture for issue 1352 (reqSpecView attachments UI)';
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
echo "tproject=$idP (prefix RVR)\n";
$tprojMgr->setActive($idP);

$op = $reqSpecMgr->create($idP, $idP, 'RSV-ATT', 'Attachment Spec',
    'spec used by issue #1352 (reqSpecView attachments)', 1, $userId,
    TL_REQ_SPEC_TYPE_USER_REQ_SPEC);
if (!$op['status_ok'] || $op['id'] <= 0) { die("spec create failed: " . $op['msg'] . "\n"); }
$idS = intval($op['id']);
echo "spec=$idS\n";

$created = 0;
foreach (array(
    array('SRQ-001', 'First requirement',
          'Scope of SRQ-001. Used to test attachments UI.')
  , array('SRQ-002', 'Second requirement',
          'Scope of SRQ-002. Used to test attachments UI.')
) as $i => $def) {
    $op = $reqMgr->create($idS, $def[0], $def[1], $def[2], $userId,
        TL_REQ_STATUS_VALID, TL_REQ_TYPE_INFO, 1, $i + 1, $idP);
    if (!$op['status_ok'] || $op['id'] <= 0) { die("req {$def[0]} create failed: " . $op['msg'] . "\n"); }
    echo "req {$def[0]} = {$op['id']}\n";
    $created++;
}

file_put_contents('/tmp/fixture_1352.txt', json_encode(array(
    'tproject_id' => $idP,
    'spec_id' => $idS,
)));
echo "wrote /tmp/fixture_1352.txt\n";
echo "fixture ready: $created requirements\n";