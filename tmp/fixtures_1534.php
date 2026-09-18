<?php
// Fixture for #1534 browser testing: Reorder Requirements screen
// (gui/templates/requirements/reqReorder.html). Creates tproject `RE1534`
// (prefix `RS34`) + req spec `RS-REORD` + three requirements
// (doc ids RRQ-001 / RRQ-002 / RRQ-003) with distinct orders.
// Run from repo root: php tmp/fixtures_1534.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$reqSpecMgr = new requirement_spec_mgr($db);
$reqMgr = new requirement_mgr($db);
$userId = 1; // admin

// Reset on re-runs (repo rule: fixtures must be re-runnable).
foreach ((array)$tprojMgr->get_by_name('RE1534') as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'RE1534';
$item->prefix = 'RS34';
$item->notes = 'fixture for issue 1534 (reorder requirements)';
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
echo "tproject=$idP (prefix RS34)\n";
$tprojMgr->setActive($idP);

$op = $reqSpecMgr->create($idP, $idP, 'RS-REORD', 'Reorder Spec',
    'spec used by suite #1534', 3, $userId, TL_REQ_SPEC_TYPE_USER_REQ_SPEC);
if (!$op['status_ok'] || $op['id'] <= 0) { die("spec create failed: " . $op['msg'] . "\n"); }
$idS = intval($op['id']);
echo "spec=$idS\n";

$created = 0;
foreach (array(
    array('RRQ-001', 'First requirement to be reordered',
          'Scope of RRQ-001. Initial node_order 0.')
  , array('RRQ-002', 'Second requirement to be reordered',
          'Scope of RRQ-002. Initial node_order 1.')
  , array('RRQ-003', 'Third requirement to be reordered',
          'Scope of RRQ-003. Initial node_order 2.')
) as $i => $def) {
    $op = $reqMgr->create($idS, $def[0], $def[1], $def[2], $userId,
        TL_REQ_STATUS_VALID, TL_REQ_TYPE_INFO, 1, $i + 1, $idP);
    if (!$op['status_ok'] || $op['id'] <= 0) { die("req {$def[0]} create failed: " . $op['msg'] . "\n"); }
    echo "req {$def[0]} = {$op['id']}\n";
    $created++;
}
echo "fixture ready: $created requirements\n";