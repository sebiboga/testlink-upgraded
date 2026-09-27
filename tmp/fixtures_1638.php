<?php
// Fixture for issue #1638 browser testing: Attachment Delete popup.
// Creates a test project + plan + suite + test case + execution and three
// real attachment rows:
//   - attached to the TEST CASE (fk_table=nodes_hierarchy) -> normal case
//   - attached to the EXECUTION  (fk_table=executions)      -> exec owner label
//   - attached to the TEST PLAN  (fk_table=testplans)      -> plan owner label
// so the popup can be exercised for every owner kind, for the not-found /
// not-allowed / no-id states and for the audit event.
// Also creates a role-less user (adelguest) for the rights paths.
// Run from repo root: php tmp/fixtures_1638.php
require_once('config.inc.php');
require_once('common.php');
require_once('lib/functions/attachments.inc.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tplanMgr = new testplan($db);
$tsuiteMgr = new testsuite($db);
$tcaseMgr = new testcase($db);
$tables = tlObjectWithDB::getDBTables([
    'testprojects', 'testplans', 'executions', 'attachments', 'users',
]);

// ---- re-runnable reset -----------------------------------------------------
foreach ((array)$tprojMgr->get_by_name('ADEL1638') as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}
$stale = $db->get_recordset(
    "SELECT id, file_path FROM {$tables['attachments']} WHERE title LIKE 'ADEL-%'");
foreach ((array)$stale as $s) {
    $fp = strval($s['file_path'] ?? '');
    if ($fp !== '' && is_file($fp)) {
        @unlink($fp);
    }
    $db->exec_query("DELETE FROM {$tables['attachments']} WHERE id=" .
        intval($s['id']));
}

// ---- test project ----------------------------------------------------------
$item = new stdClass();
$item->name = 'ADEL1638';
$item->prefix = 'ADL';
$item->notes = 'Attachment Delete popup demo';
$item->color = '';
$item->active = 1;
$item->is_public = 1;
$item->options = new stdClass();
$item->options->requirementsEnabled = 1;
$item->options->testPriorityEnabled = 0;
$item->options->automationEnabled = 0;
$item->options->inventoryEnabled = 0;
$item->options->platformsEnabled = 0;
$item->options->testcasecfEnabled = 0;
$item->options->requirementcfEnabled = 0;
$tprojectId = intval($tprojMgr->create($item));
if ($tprojectId <= 0) {
    die("tproject create failed\n");
}
$tprojMgr->setActive($tprojectId);
echo "tproject_id = $tprojectId\n";

// ---- test plan -------------------------------------------------------------
$planId = intval($tplanMgr->create('ADEL Plan', 'plan for the attachment delete popup',
    $tprojectId, 1, 1));
echo "tplan_id = $planId\n";

// ---- suite + test case + version -------------------------------------------
$retS = $tsuiteMgr->create($tprojectId, 'ADEL Suite', '', null, 0, 'allow_repeat');
$suiteId = intval($retS['id'] ?? 0);
if ($suiteId <= 0) {
    die("suite create failed\n");
}
echo "suite_id = $suiteId\n";

$steps = array();
$steps[0] = new stdClass();
$steps[0]->step_number = 1;
$steps[0]->actions = 'fixture step action for #1638';
$steps[0]->expected_results = 'fixture expected result for #1638';
$steps[0]->execution_type = TESTCASE_EXECUTION_TYPE_MANUAL;
$userId = intval($db->get_recordset(
    "SELECT MIN(id) AS id FROM {$tables['users']} WHERE active = 1")[0]['id'] ?? 1);
$ret = $tcaseMgr->create($suiteId, 'ADEL test case', 'fixture tc for #1638', '',
    $steps, $userId, '', testcase::DEFAULT_ORDER, testcase::AUTOMATIC_ID,
    TESTCASE_EXECUTION_TYPE_MANUAL);
if (empty($ret['status_ok'])) {
    die('tcase create failed: ' . ($ret['message'] ?? '') . "\n");
}
$tcId = intval($ret['id']);
$tcvId = intval($ret['tcversion_id']);
echo "tcase_id = $tcId  tcversion_id = $tcvId\n";

// ---- one execution (owner kind #2) -----------------------------------------
$db->exec_query("DELETE FROM {$tables['executions']} WHERE testplan_id=" .
    intval($planId));
$db->exec_query("INSERT INTO {$tables['executions']} (testplan_id, tcversion_id, " .
    "tester_id, status, build_id, platform_id, execution_ts) VALUES (" .
    intval($planId) . "," . $tcvId . "," . $userId . ",'',0,0,NULL)");
$execId = intval($db->get_recordset(
    "SELECT MAX(id) AS id FROM {$tables['executions']} WHERE testplan_id=" .
    intval($planId))[0]['id']);
echo "exec_id = $execId\n";

// ---- three real attachment rows --------------------------------------------
// file_path is stored RELATIVE to the repository root (TL_REPOSITORY_TYPE_FS:
// tlAttachmentRepository::deleteAttachmentFromFS() unlinks
// repositoryPath . '/' . file_path), so the fixture must place the files inside
// the real upload_area, in the legacy per-table/per-id folder layout.
$repoRoot = rtrim(config_get('repositoryPath'), '/\\') . '/';
$dir = $repoRoot . 'adel1638';
if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}
echo "repository = $repoRoot\n";
$specs = array(
    array('ADEL-1638-testcase', 'nodes_hierarchy', $tcId,
        'attached to the test case', "test case attachment\n"),
    array('ADEL-1638-exec', 'executions', $execId,
        'attached to the execution', "execution attachment\n"),
    array('ADEL-1638-plan', 'testplans', $planId,
        'attached to the test plan', "test plan attachment\n"),
);
$ids = array();
foreach ($specs as $sp) {
    $body = $sp[4];
    $relPath = 'adel1638/' . $sp[0] . '.txt';
    $file = $dir . '/' . $sp[0] . '.txt';
    file_put_contents($file, $body);
    $q = function ($v) use ($db) {
        return "'" . $db->prepare_string($v) . "'";
    };
    $db->exec_query("INSERT INTO {$tables['attachments']} (fk_id, fk_table, " .
        "title, description, file_name, file_path, file_size, file_type, " .
        "date_added, compression_type) VALUES (" . intval($sp[2]) . "," .
        $q($sp[1]) . "," . $q($sp[0]) . "," . $q($sp[3]) . "," .
        $q($sp[0] . '.txt') . "," . $q($relPath) . "," . strlen($body) . "," .
        $q('text/plain') . ",NOW(),0)");
    $aid = intval($db->get_recordset("SELECT MAX(id) AS id FROM " .
        "{$tables['attachments']} WHERE title=" . $q($sp[0]))[0]['id']);
    $ids[$sp[1]] = $aid;
    echo "attachment({$sp[1]}) = $aid\n";
}

// ---- a role-less user, to exercise the rights paths ------------------------
$uRows = $db->get_recordset(
    "SELECT id FROM {$tables['users']} WHERE login = 'adelguest'");
if (!$uRows) {
    $db->exec_query("INSERT INTO {$tables['users']} (login, password, " .
        "first, last, email, role_id, active, creation_ts, cookie_string) " .
        "VALUES ('adelguest'," .
        "'" . $db->prepare_string(md5('adelguest123')) . "'" .
        ",'Adel','Guest','adelguest@example.org',5,1,NOW()," .
        "'" . $db->prepare_string(md5('adelguest' . rand())) . "')");
    echo "user adelguest created\n";
}

echo "FIXTURE_OK tproject={$tprojectId} tplan={$planId} suite={$suiteId} " .
    "tcase={$tcId} tcv={$tcvId} exec={$execId} " .
    "att_tc={$ids['nodes_hierarchy']} att_exec={$ids['executions']} " .
    "att_plan={$ids['testplans']}\n";
