<?php
// Fixture for #1532 browser testing: Direct-link resolver (linkto.php item=req).
// Creates tproject `DL1532` (prefix `DLS2`) + req spec `RS-DL` + two
// requirements (doc ids DLREQ-001 / DLREQ-002).
// Run from repo root: php tmp/fixtures_1532.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$reqSpecMgr = new requirement_spec_mgr($db);
$reqMgr = new requirement_mgr($db);
$userId = 1; // admin

// Reset on re-runs (repo rule: fixtures must be re-runnable).
foreach ((array)$tprojMgr->get_by_name('DL1532') as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'DL1532';
$item->prefix = 'DLS2';
$item->notes = 'fixture for issue 1532 (direct link resolver)';
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
echo "tproject=$idP (prefix DLS2)\n";
$tprojMgr->setActive($idP);

$op = $reqSpecMgr->create($idP, $idP, 'RS-DL', 'Direct-Link Spec',
    'spec used by suite #1532', 3, $userId, TL_REQ_SPEC_TYPE_USER_REQ_SPEC);
if (!$op['status_ok'] || $op['id'] <= 0) { die("spec create failed: " . $op['msg'] . "\n"); }
$idS = intval($op['id']);
echo "spec=$idS\n";

$created = 0;
foreach (array(
    array('DLREQ-001', 'Alpha requirement reached via direct link',
          'Scope of DLREQ-001. Used to verify the modern resolver.')
  , array('DLREQ-002', 'Beta requirement reached via direct link',
          'Scope of DLREQ-002. Secondary item for the not-found / other checks.')
) as $i => $def) {
    $op = $reqMgr->create($idS, $def[0], $def[1], $def[2], $userId,
        TL_REQ_STATUS_VALID, TL_REQ_TYPE_INFO, 1, $i + 1, $idP);
    if (!$op['status_ok'] || $op['id'] <= 0) { die("req {$def[0]} create failed: " . $op['msg'] . "\n"); }
    echo "req {$def[0]} = {$op['id']}\n";
    $created++;
}
echo "fixture ready: $created requirements\n";