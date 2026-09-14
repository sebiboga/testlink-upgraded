<?php
// Fixture for Task #1381 (reqEdit requirement-template scope prefill on create).
// Recreates: project ReqTemplateFixture (requirements enabled), req spec SPEC-TPL,
// one requirement REQ-TPL-1 (so edit mode can be exercised too).
// Run from repo root: php tmp/fixtures_1381.php
require_once(__DIR__ . '/../config.inc.php');
require_once(__DIR__ . '/../lib/functions/common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);

// idempotency
$old = $tprojMgr->get_by_name('ReqTemplateFixture');
foreach ((array)$old as $row) {
    $oid = intval($row['id']);
    if ($oid > 0) { echo "deleting old project $oid\n"; $tprojMgr->delete($oid, 1); }
}

$item = new stdClass();
$item->name = 'ReqTemplateFixture';
$item->prefix = 'RTMPL';
$item->notes = 'fixture for issue 1381 (requirement template scope prefill)';
$item->color = '';
$item->active = 1;
$item->is_public = 1;
$opts = new stdClass();
$opts->requirementsEnabled = 1;
$opts->testPriorityEnabled = 0;
$opts->automationEnabled = 0;
$opts->inventoryEnabled = 0;
$item->options = $opts;
$idP = $tprojMgr->create($item);
echo "tproject=$idP\n";
if ($idP <= 0) { die("project create failed\n"); }

$reqSpecMgr = new requirement_spec_mgr($db);
$opSpec = $reqSpecMgr->create($idP, $idP, 'SPEC-TPL', 'Template Spec', 'spec scope', 0, 1,
                              TL_REQ_SPEC_TYPE_SYSTEM_REQ_SPEC);
$specId = intval($opSpec['id']);
echo "req_spec=$specId status_ok=" . var_export($opSpec['status_ok'], true) . "\n";
if ($specId <= 0) { die("req spec create failed: " . var_export($opSpec, true) . "\n"); }

$reqMgr = new requirement_mgr($db);
$opReq = $reqMgr->create($specId, 'REQ-TPL-1', 'Template Req One', 'initial scope', 1,
                         TL_REQ_STATUS_VALID, TL_REQ_TYPE_FEATURE, 1);
echo "req=" . var_export($opReq, true) . "\n";
if (intval($opReq['id']) <= 0) { die("req create failed\n"); }

echo "DONE: tproject=$idP spec=$specId req={$opReq['id']}\n";