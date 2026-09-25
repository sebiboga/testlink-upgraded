<?php
// Fixture for issue #1586: TLi18n locale selection not persisted when returning
// to a launcher (Platforms Export -> switch locale -> Cancel -> Platforms View).
// Creates test project `I1586-LOCALE` (prefix I1586) with one platform, so
// both platformsView.html (launcher) and platformsExport.html can be opened.
// Run from repo root: php tmp/fixtures_1586.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tplanMgr  = new testplan($db);

foreach ((array)$tprojMgr->get_by_name('I1586-LOCALE') as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'I1586-LOCALE';
$item->prefix = 'I1586';
$item->notes = 'fixture for issue 1586 (i18n locale persistence)';
$item->color = '';
$item->active = 1;
$item->is_public = 1;
$opts = new stdClass();
$opts->requirementsEnabled = 0;
$opts->testPriorityEnabled = 0;
$opts->automationEnabled = 0;
$opts->inventoryEnabled = 0;
$opts->platformsEnabled = 1;
$item->options = $opts;
$idP = intval($tprojMgr->create($item));
if ($idP <= 0) {
    die("fixture failed: test project not created\n");
}
echo "tproject=$idP\n";
$tprojMgr->setActive($idP);

$rp = $tplanMgr->create('I1586-TPLAN', 'plan used by issue 1586', $idP);
$idPlan = intval(is_array($rp) ? ($rp['id'] ?? 0) : $rp);
if ($idPlan <= 0) {
    die("fixture failed: test plan not created\n");
}
echo "tplan=$idPlan\n";
// testplan::setActive() takes the PLAN id only - passing the project id here
// would activate an unrelated plan whose id happens to equal it.
$tplanMgr->setActive($idPlan);

$platform = new stdClass();
$platform->name = 'I1586-PLATFORM';
$platform->notes = 'platform used by issue #1586';
$platform->enable_on_design = 1;
$platform->enable_on_execution = 1;
$platform->is_open = 1;
$platMgr = new tlPlatform($db, $idP);
$rop = $platMgr->create($platform);
$idPlat = intval($rop['id']);
if ($idPlat <= 0) {
    die("fixture failed: platform not created (status " . $rop['status'] . ")\n");
}
echo "platform=$idPlat\n";

file_put_contents('/tmp/fixture_1586.txt', json_encode(array(
    'tproject_id' => $idP,
    'tplan_id' => $idPlan,
    'platform_id' => $idPlat,
)));
echo "wrote /tmp/fixture_1586.txt\n";
