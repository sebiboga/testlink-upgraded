<?php
// Fixture for issue #1111 - tcCreatedPerUserOnTestProject group-by-user grid +
// ExtGrid toolbar. Creates tproject `TCP1111` (prefix `TCP`, priority enabled)
// with TWO suites and 6 test-case versions created by TWO different users so the
// report renders 2 collapsible user groups.
// Run from repo root: php tmp/fixtures_1111.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$suiteMgr = new testsuite($db);
$tc = new testcase($db);
$userId = 1; // admin

foreach ((array)$tprojMgr->get_by_name('TCP1111') as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid);
    }
}

// second author so the grid has more than one user group
$hash = password_hash('tcptester', PASSWORD_DEFAULT);
$db->exec_query("INSERT INTO users (login,password,role_id,email,first,last,locale,default_testproject_id,active,cookie_string,auth_method) " .
    "VALUES ('tcptester','" . $hash . "',4,'tcptester@example.com','Tcp','Tester','en_GB',0,1,'ck_tcptester_1111','DB') " .
    "ON DUPLICATE KEY UPDATE password=VALUES(password), role_id=4, active=1, auth_method=VALUES(auth_method)");
$u2 = intval($db->get_recordset("SELECT id FROM users WHERE login='tcptester'")[0]['id']);
echo "user2=$u2\n";

$item = new stdClass();
$item->name = 'TCP1111';
$item->prefix = 'TCP';
$item->notes = 'fixture for issue 1111 (tcCreatedPerUserOnTestProject ExtTable parity)';
$item->color = '';
$item->active = 1;
$item->is_public = 1;
$opts = new stdClass();
$opts->requirementsEnabled = 0;
$opts->testPriorityEnabled = 1;
$opts->automationEnabled = 0;
$opts->inventoryEnabled = 0;
$opts->platformsEnabled = 0;
$opts->testcasecfEnabled = 0;
$opts->requirementcfEnabled = 0;
$item->options = $opts;
$idP = intval($tprojMgr->create($item));
echo "tproject=$idP\n";
$tprojMgr->setActive($idP);

$mkSuite = function ($name) use ($suiteMgr, $idP) {
    $sid = $suiteMgr->create($idP, $name, 'suite for issue 1111');
    return intval(is_array($sid) && isset($sid['id']) ? $sid['id'] : $sid);
};
$suiteA = $mkSuite('TCP Suite Alpha');
$suiteB = $mkSuite('TCP Suite Beta');
echo "suiteA=$suiteA suiteB=$suiteB\n";

$defs = array(
    // suite, name, author
    array($suiteA, 'TCP-1 Alpha login',  $userId),
    array($suiteA, 'TCP-2 Alpha logout', $u2),
    array($suiteB, 'TCP-3 Beta import',  $userId),
    array($suiteB, 'TCP-4 Beta export',  $u2),
    array($suiteA, 'TCP-5 Alpha report', $userId),
    array($suiteB, 'TCP-6 Beta audit',   $u2),
);
$created = [];
foreach ($defs as $def) {
    $idTC = $tc->create($def[0], $def[1], 'fixture case for issue 1111', '', '', $def[2], '');
    $idTC = intval(is_array($idTC) && isset($idTC['id']) ? $idTC['id'] : $idTC);
    if ($idTC <= 0) { die("tc create failed for {$def[1]}\n"); }
    $db->exec_query('UPDATE tcversions SET author_id=' . intval($def[2]) .
                    ', creation_ts=NOW(), importance=' . (($idTC % 3) + 1) .
                    ' WHERE id IN (SELECT id FROM nodes_hierarchy WHERE parent_id=' . intval($idTC) . ')');
    $created[] = $idTC;
}
echo "testcases=" . implode(',', $created) . "\n";

file_put_contents('/tmp/fixture_1111.txt', json_encode(array(
    'tproject_id' => $idP,
    'suite_ids' => array($suiteA, $suiteB),
    'testcase_ids' => $created,
)));
echo "done\n";
