<?php
// Fixture for #1570: Print Document Options popup (printDocOptions).
// Creates tproject `PDO` (prefix `PDO`) with requirements enabled + a req spec
// with one requirement, a test plan, a suite with 2 test cases (linked to the
// plan), and an open build. Also ensures the role-3 `norights` user exists for
// the 403 path.
// Run from repo root: php tmp/fixtures_1570.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$planMgr = new testplan($db);
$suiteMgr = new testsuite($db);
$userId = 1; // admin

foreach ((array)$tprojMgr->get_by_name('PDO') as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'PDO';
$item->prefix = 'PDO';
$item->notes = 'fixture for issue 1570 (print document options popup)';
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
echo "tproject=$idP\n";
$tprojMgr->setActive($idP);

$args = new stdClass();
$args->name = 'PDO Plan';
$args->notes = 'plan for issue 1570';
$args->active = 1;
$args->is_open = 1;
$args->option_automation = 0;
$args->option_priority = 0;
$op = $planMgr->create($args->name, $args->notes, $idP, 1);
if (!$op || intval($op) <= 0) { die("plan create failed\n"); }
$idT = intval($op);
echo "tplan=$idT\n";
$planMgr->setActive($idT);

foreach ((array)$suiteMgr->get_by_name('PDO Suite') as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) { $suiteMgr->delete($oid, 1); }
}
$suiteId = $suiteMgr->create($idP, 'PDO Suite', 'suite for issue 1570');
$suiteId = is_array($suiteId) && isset($suiteId['id']) ? intval($suiteId['id']) : intval($suiteId);
echo "suite=$suiteId\n";

$tc = new testcase($db);
$created = [];
foreach (array(array('PDO TC One', 'first tc'), array('PDO TC Two', 'second tc')) as $t) {
    $op = $tc->create($suiteId, $t[0], $t[1], '', '', $userId, '');
    $idTC = intval(is_array($op) && isset($op['id']) ? $op['id'] : $op);
    if ($idTC <= 0) { die("tc create failed for {$t[0]}\n"); }
    echo "tc={$t[0]}=$idTC\n";
    $created[] = $idTC;
}

$buildMgr = new build($db);
$buildMgr->create($idT, 'PDO Build 1', 'build for issue 1570', 1, 1, '', $idP);
$buildMgr->create($idT, 'PDO Build 2', 'second build for issue 1570', 1, 0, '', $idP);
echo "builds created\n";

$tables = tlObjectWithDB::getDBTables(array('nodes_hierarchy', 'testplan_tcversions'));
foreach ($created as $idTC) {
    $tcv = $db->fetchOneValue(
        " SELECT id FROM {$tables['nodes_hierarchy']} " .
        " WHERE parent_id = " . intval($idTC) . " AND node_type_id = 4 ORDER BY id LIMIT 1");
    if ($tcv) {
        $db->exec_query(
            " INSERT INTO {$tables['testplan_tcversions']} " .
            " (testplan_id, author_id, creation_ts, tcversion_id, platform_id) " .
            " VALUES (" . intval($idT) . ", {$userId}, " . $db->db_now() . ", " . intval($tcv) . ", 0)");
        echo "linked tcversion $tcv to plan $idT\n";
    }
}

// requirement spec + one requirement (so the reqspec doc type has content)
$reqSpecMgr = new requirement_spec_mgr($db);
$specId = $db->fetchOneValue(
    " SELECT id FROM req_specs WHERE doc_id='PRS-PDO' LIMIT 1");
if (!$specId || intval($specId) <= 0) {
    $op = $reqSpecMgr->create($idP, $idP, 'PRS-PDO', 'PDO ReqSpec',
        'spec for issue 1570', 3, $userId, TL_REQ_SPEC_TYPE_USER_REQ_SPEC);
    $specId = intval(is_array($op) && isset($op['id']) ? $op['id'] : 0);
    if ($specId <= 0) { die("req spec create failed: " . json_encode($op) . "\n"); }
}
echo "req_spec=$specId\n";

$reqMgr = new requirement_mgr($db);
$reqId = $db->fetchOneValue(
    " SELECT id FROM requirements WHERE req_doc_id='RQ-PDO-1' LIMIT 1");
if (!$reqId || intval($reqId) <= 0) {
    $op = $reqMgr->create($specId, 'RQ-PDO-1', 'PDO Req', 'scope of req', $userId,
                          TL_REQ_STATUS_VALID, TL_REQ_TYPE_INFO, 1, 0, $idP);
    echo "req create op=" . json_encode($op) . "\n";
}

// norights user for the 403 path
$hash = password_hash('norights', PASSWORD_DEFAULT);
$db->exec_query("INSERT INTO users (login,password,role_id,email,first,last,locale,default_testproject_id,active,cookie_string,auth_method) " .
    "VALUES ('norights','" . $hash . "',3,'n@n.no','No','Rights','en_GB',0,1,'ck_norights_1570','DB') " .
    "ON DUPLICATE KEY UPDATE password=VALUES(password), role_id=3, active=1, " .
    "auth_method=VALUES(auth_method)");
echo "norights user ensured\n";

echo "fixture ready: project $idP, plan $idT, req_spec $specId, tcs " . implode(',', $created) . "\n";