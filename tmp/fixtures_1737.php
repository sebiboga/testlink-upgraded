<?php
// Fixture for #1737: reqSpecCommands::deleteFile() + bulkReqMon() E_WARNINGs.
// Creates tproject `RSI7` (requirements enabled) with ONE req spec that has NO
// requirements yet (the common "just created" case that triggers :877), plus a
// throwaway spec for the destructive deleteFile verb.
//
// Run from repo root: php tmp/fixtures_1737.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$reqSpecMgr = new requirement_spec_mgr($db);
$userId = 1; // admin

function dropProject($tprojMgr, $name) {
    foreach ((array)$tprojMgr->get_by_name($name) as $row) {
        $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
        if ($oid > 0) { echo "deleting old project $name ($oid)\n"; $tprojMgr->delete($oid, 1); }
    }
}
dropProject($tprojMgr, 'RSI7');

$item = new stdClass();
$item->name = 'RSI7';
$item->prefix = 'RI7';
$item->notes = 'fixture for #1737';
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
echo "tproject RSI7=$idP\n";

function makeSpec($reqSpecMgr, $tprojectId, $docId, $title, $userId) {
    $op = $reqSpecMgr->create($tprojectId, $tprojectId, $docId, $title,
        'scope of ' . $docId, 2, $userId, TL_REQ_SPEC_TYPE_USER_REQ_SPEC);
    if (!$op['status_ok'] || $op['id'] <= 0) { die("spec create failed for $docId: " . $op['msg'] . "\n"); }
    return intval($op['id']);
}

$specEmpty = makeSpec($reqSpecMgr, $idP, 'RS-RSI7', 'Empty Spec', $userId);
echo "spec RS-RSI7 (no requirements)=$specEmpty\n";

// A throwaway spec registered under this project's doc-id context so the
// destructive deleteFile verb has something to act on (file_id is bogus).
$throw = makeSpec($reqSpecMgr, $idP, 'RS-RSI7-DEL', 'Throwaway Spec', $userId);
echo "spec RS-RSI7-DEL (throwaway)=$throw\n";

echo "FIXTURE_OK issue=1737 tproject=$idP spec_empty=$specEmpty spec_throwaway=$throw\n";
