<?php
// Fixture for #1502 browser testing: Create Test Cases from Issues XML (Mantis).
// Creates tproject `IssueImportFixture` (prefix IIFXT) + root suite
// `Suite From Issues` + second suite `Suite Two Issues`.
// Run from repo root: php tmp/fixtures_1502.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tsuiteMgr = new testsuite($db);

// Reset on re-runs (repo rule: fixtures must be re-runnable).
foreach ((array)$tprojMgr->get_by_name('IssueImportFixture') as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'IssueImportFixture';
$item->prefix = 'IIFXT';
$item->notes = 'fixture for issue 1502 (Mantis issue XML -> test cases)';
$item->color = '';
$item->active = 1;
$item->is_public = 1;
$opts = new stdClass();
$opts->requirementsEnabled = 0;
$opts->testPriorityEnabled = 1;
$opts->automationEnabled = 1;
$opts->inventoryEnabled = 0;
$opts->platformsEnabled = 0;
$item->options = $opts;
$idP = $tprojMgr->create($item);
echo "tproject=$idP\n";

function firstId($r) {
    if (is_array($r)) {
        if (isset($r['id'])) { return intval($r['id']); }
        $k = array_keys($r);
        return intval($k[0]);
    }
    return intval($r);
}

$idS1 = firstId($tsuiteMgr->create($idP, 'Suite From Issues', 'root suite for 1502', null, null, 1));
echo "suite1=$idS1\n";
$idS2 = firstId($tsuiteMgr->create($idP, 'Suite Two Issues', 'second suite for 1502', null, null, 1));
echo "suite2=$idS2\n";

// sample Mantis XML export file used by browser suite #1502
$xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<mantis version="1.2.14" urlbase="http://localhost/" issuelink="#" notelink="~" format="1">
  <issue>
    <id>101</id>
    <project id="1">IssueImportFixture</project>
    <reporter id="1">admin</reporter>
    <priority id="30">normal</priority>
    <severity id="50">minor</severity>
    <status id="10">new</status>
    <resolution id="10">open</resolution>
    <summary>Login fails on Firefox without cookies</summary>
    <description>Reproducible on a fresh profile; only when cookies are blocked.</description>
    <steps_to_reproduce>1. Open Firefox with cookies disabled; 2. Try to log in; 3. Error 500.</steps_to_reproduce>
    <additional_information>Works on Chrome.</additional_information>
    <date_submitted>1365184242</date_submitted>
    <last_updated>1365184242</last_updated>
  </issue>
  <issue>
    <id>102</id>
    <project id="1">IssueImportFixture</project>
    <reporter id="1">admin</reporter>
    <severity id="60">major</severity>
    <status id="20">assigned</status>
    <summary>Export report crashes on empty project</summary>
    <description>Triggering the export on a project with no test cases throws a fatal.</description>
    <date_submitted>1365184250</date_submitted>
    <last_updated>1365184250</last_updated>
  </issue>
</mantis>
XML;
file_put_contents('/tmp/mantis_import_sample.xml', $xml);
echo "sample xml written to /tmp/mantis_import_sample.xml\n";