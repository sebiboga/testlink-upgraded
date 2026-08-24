<?php
// extra fixture: plan whose ONLY build is closed -> legacy no_open_builds warning (Refs #677)
$_SESSION = array();
require_once(dirname(__DIR__) . '/config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);
$tplanMgr = new testplan($db);
$buildMgr = new build($db);

$rows = $db->get_recordset(
    "SELECT NH.id FROM nodes_hierarchy NH WHERE NH.name = 'Plan RBTP NoOpen'");
$planId = $rows ? intval($rows[0]['id'])
    : intval($tplanMgr->create('Plan RBTP NoOpen', 'no open builds', 1, 1, 1));
echo "plan=$planId\n";

$brows = $db->get_recordset("SELECT id FROM builds WHERE testplan_id=" . $planId);
if (!$brows) {
    $bId = intval($buildMgr->create($planId, 'Build Closed Only', 'closed', 1, 0));
    echo "build=$bId\n";
}
echo "ok\n";
