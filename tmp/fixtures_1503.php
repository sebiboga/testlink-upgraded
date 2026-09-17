<?php
// Fixture for #1503 browser testing: Create Requirements from Issues XML (Mantis).
// Creates tproject `WALK1503` + req spec `RS-WALK` + second spec `RS-OTHER`
// (for the cross-branch duplicate test) + a sample Mantis XML export file.
// Run from repo root: php tmp/fixtures_1503.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$reqSpecMgr = new requirement_spec_mgr($db);
$userId = 1; // admin

// Reset on re-runs (repo rule: fixtures must be re-runnable).
foreach ((array)$tprojMgr->get_by_name('WALK1503') as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'WALK1503';
$item->prefix = 'W3';
$item->notes = 'fixture for issue 1503 (Mantis issue XML -> requirements)';
$item->color = '';
$item->active = 1;
$item->is_public = 1;
$opts = new stdClass();
$opts->requirementsEnabled = 1;
$opts->testPriorityEnabled = 0;
$opts->automationEnabled = 0;
$opts->inventoryEnabled = 0;
$opts->platformsEnabled = 0;
$item->options = $opts;
$r = $tprojMgr->create($item);
$idP = intval($r);
echo "tproject=$idP\n";
$tprojMgr->setActive($idP);

$op = $reqSpecMgr->create($idP, $idP, 'RS-WALK', 'Walk Issue Import Spec',
    'spec used by suite #1503 (pristine import, FROZEN dup)', 3, $userId, TL_REQ_SPEC_TYPE_USER_REQ_SPEC);
if (!$op['status_ok'] || $op['id'] <= 0) { die("spec1 create failed: " . $op['msg'] . "\n"); }
$idS1 = intval($op['id']);
echo "spec1=$idS1\n";

$op = $reqSpecMgr->create($idP, $idP, 'RS-OTHER', 'Other Branch Spec',
    'spec for the cross-branch duplicate test', 3, $userId, TL_REQ_SPEC_TYPE_USER_REQ_SPEC);
if (!$op['status_ok'] || $op['id'] <= 0) { die("spec2 create failed: " . $op['msg'] . "\n"); }
$idS2 = intval($op['id']);
echo "spec2=$idS2\n";

// sample Mantis XML export file used by browser suite #1503
$xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<mantis version="1.2.14" urlbase="http://localhost/" issuelink="#" notelink="~" format="1">
  <issue>
    <id>201</id>
    <project id="1">WALK1503</project>
    <reporter id="1">admin</reporter>
    <category id="10">general</category>
    <priority id="30">normal</priority>
    <severity id="50">minor</severity>
    <status id="10">new</status>
    <resolution id="10">open</resolution>
    <summary>Requirement import drops leading spaces</summary>
    <description>The scope text is trimmed before the first paragraph join, losing leading whitespace.</description>
    <steps_to_reproduce>1. Export two issues; 2. Import under a spec; 3. Compare descriptions.</steps_to_reproduce>
    <additional_information>Reproduced on 1.9.20 and 2.0.x.</additional_information>
    <date_submitted>1365184300</date_submitted>
    <last_updated>1365184300</last_updated>
  </issue>
  <issue>
    <id>202</id>
    <project id="1">WALK1503</project>
    <reporter id="1">admin</reporter>
    <category id="20">general</category>
    <severity id="60">major</severity>
    <status id="50">closed</status>
    <resolution id="20">fixed</resolution>
    <summary>Export report crashes on empty project</summary>
    <description>Triggering the export on a project with no test cases throws a fatal error.</description>
    <date_submitted>1365184310</date_submitted>
    <last_updated>1365184320</last_updated>
  </issue>
</mantis>
XML;
file_put_contents('/tmp/mantis_import_1503.xml', $xml);
echo "sample xml written to /tmp/mantis_import_1503.xml\n";

// one pre-created requirement in RS-WALK used by the FROZEN duplicate test
if (file_exists('/tmp/pre_seed_1503.php')) {
    require('/tmp/pre_seed_1503.php');
}
echo "DONE\n";