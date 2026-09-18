<?php
// Fixture for issue #1365 browser testing: suiteView attachment download links.
// Creates test project `SUVW-ATT` (prefix SUVW) + a test suite `Downloads`
// under it, so the modern suiteView popup has an attachment-owning suite.
// The attachment row itself is created through the modern BFF upload endpoint
// (POST /api/suiteview/index.php?action=attachment_upload) so the file lands in
// the configured FS repository (upload_area/) exactly like a real user upload.
// Run from repo root: php tmp/fixtures_1365.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tsuiteMgr = new testsuite($db);

// Reset on re-runs (repo rule: fixtures must be re-runnable).
foreach ((array)$tprojMgr->get_by_name('SUVW-ATT') as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'SUVW-ATT';
$item->prefix = 'SUVW';
$item->notes = 'fixture for issue 1365 (suiteView attachment download links)';
$item->color = '';
$item->active = 1;
$item->is_public = 1;
$opts = new stdClass();
$opts->requirementsEnabled = 0;
$opts->testPriorityEnabled = 0;
$opts->automationEnabled = 0;
$opts->inventoryEnabled = 0;
$opts->platformsEnabled = 0;
$item->options = $opts;
$r = $tprojMgr->create($item);
$idP = intval($r);
echo "tproject=$idP\n";
$tprojMgr->setActive($idP);

$rs = $tsuiteMgr->create($idP, 'Downloads', 'suite used by issue #1365',
    null, config_get('check_names_for_duplicates'), 'block');
$idS = intval(is_array($rs) ? ($rs['id'] ?? 0) : $rs);
echo "suite=$idS\n";

file_put_contents('/tmp/fixture_1365.txt', json_encode(array(
    'tproject_id' => $idP,
    'suite_id' => $idS,
)));
echo "wrote /tmp/fixture_1365.txt\n";