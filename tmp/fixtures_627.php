<?php
// Fixture for #627 browser/CLI testing. Run from repo root:
// php tmp/fixtures_627.php
// Creates project TRACK627 (issue tracker ENABLED) + mantisdb tracker,
// suite + 2 TCs with steps, plan PLAN627 + build, links everything,
// grants admin the admin role on project and plan.
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tsuiteMgr = new testsuite($db);
$tcaseMgr = new testcase($db);
$tplanMgr = new testplan($db);
$buildMgr = new build($db);

function firstId($r) {
    if (is_array($r)) {
        if (isset($r['id'])) { return intval($r['id']); }
        $k = array_keys($r);
        return intval($k[0]);
    }
    return intval($r);
}

// idempotency
$old = $tprojMgr->get_by_name('TRACK627');
foreach ((array)$old as $row) {
    $oid = intval($row['id']);
    if ($oid > 0) { echo "deleting old project $oid\n"; $tprojMgr->delete($oid, 1); }
}
$db->exec_query("DELETE FROM issuetrackers WHERE name='MANTIS627'");

// ---- mantis tables hosted INSIDE testlink schema (testlink MySQL user
// only has grants on the testlink database) ----
$db->exec_query("DROP TABLE IF EXISTS mantis_bug_table");
$db->exec_query("CREATE TABLE mantis_bug_table (
   id int(11) NOT NULL,
   status int(11) NOT NULL DEFAULT 10,
   summary varchar(255) NOT NULL DEFAULT '',
   PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8");
$db->exec_query("INSERT INTO mantis_bug_table (id,status,summary) VALUES (1,80,'sample resolved issue')");
echo "mantis tables ready\n";

// ---- issue tracker row (type 4 = mantis/db) ----
$cfg = "<issuetracker>\n" .
       "<dbhost>127.0.0.1</dbhost><dbuser>testlink</dbuser>" .
       "<dbpassword>testlink</dbpassword><dbname>testlink</dbname>" .
       "<dbtype>mysql</dbtype><dbcharset>UTF-8</dbcharset>\n" .
       "<uriview>http://tracker.local/view.php?id=</uriview>\n" .
       "<uricreate>http://tracker.local/bug_report_page.php</uricreate>\n" .
       "</issuetracker>";
$c = $db->db->qstr($cfg);
$db->exec_query("INSERT INTO issuetrackers (name,type,cfg) VALUES ('MANTIS627',4,{$c})");
$tid = intval($db->get_recordset("SELECT id FROM issuetrackers WHERE name='MANTIS627'")[0]['id']);
echo "issuetracker=$tid\n";

// ---- project with tracker ----
$item = new stdClass();
$item->name = 'TRACK627';
$item->prefix = 'T62';
$item->notes = 'fixture for issue 627';
$item->color = '';
$item->active = 1;
$item->is_public = 1;
$opts = new stdClass();
$opts->requirementsEnabled = 0;
$opts->testPriorityEnabled = 0;
$opts->automationEnabled = 0;
$opts->inventoryEnabled = 0;
$item->options = $opts;
$idP = $tprojMgr->create($item);
echo "tproject=$idP\n";
$tprojMgr->setIssueTrackerEnabled($idP, 1);
$itMgr = new tlIssueTracker($db);
$itMgr->link($tid, $idP);
echo "tracker linked\n";
$ret = $tprojMgr->get_by_id($idP);
echo "issue_tracker_enabled=" . $ret['issue_tracker_enabled'] . "\n";

// ---- suite + TCs with steps ----
$idS = firstId($tsuiteMgr->create($idP, 'Suite627', 'details', null, null, 1));
echo "tsuite=$idS\n";
$idTCs = [];
foreach (['TC627-A', 'TC627-B'] as $nm) {
    $idT = firstId($tcaseMgr->create($idS, $nm, 'summary', 'precond',
        [['step_number' => 1, 'actions' => 'step one action', 'expected_results' => 'step one expected'],
         ['step_number' => 2, 'actions' => 'step two action', 'expected_results' => 'step two expected']], 1));
    $idTCs[$nm] = $idT;
}
echo "tcs=" . json_encode($idTCs) . "\n";

// ---- plan + build + link ----
$idTP = $tplanMgr->create('PLAN627', 'plan for 627', $idP, 1, 1);
echo "tplan=$idTP\n";

$tbl = tlObjectWithDB::getDBTables(['nodes_hierarchy', 'tcversions']);
$linkItems = ['items' => [], 'tcversion' => []];
$tcv = [];
foreach ($idTCs as $nm => $idT) {
    $rs = $db->get_recordset(
        " SELECT NH.id FROM {$tbl['nodes_hierarchy']} NH" .
        " JOIN {$tbl['tcversions']} TV ON TV.id = NH.id" .
        " WHERE NH.parent_id = " . intval($idT) .
        " AND TV.active = 1 ORDER BY TV.version");
    $tv = intval($rs[0]['id']);
    $linkItems['tcversion'][$idT] = $tv;
    $linkItems['items'][$idT] = [0 => $tv];
    $tcv[$nm] = $tv;
}
$ret = $tplanMgr->link_tcversions($idTP, $linkItems, 1,
    array('getTCPrefixFromTPlan' => true));
var_dump($ret === null || is_array($ret) ? 'linked' : $ret);

$buildId = $buildMgr->create($idTP, 'B1', 'build for 627');
echo "build=$buildId\n";

// ---- grant admin role on project and plan ----
$roles = $db->get_recordset("SELECT id,description FROM roles WHERE description='admin'");
$adminRole = intval($roles[0]['id']);
$db->exec_query("INSERT IGNORE INTO user_testproject_roles (user_id,testproject_id,role_id) VALUES (1,$idP,$adminRole)");
$db->exec_query("INSERT IGNORE INTO user_testplan_roles (user_id,testplan_id,role_id) VALUES (1,$idTP,$adminRole)");
echo "roles granted\n";

echo "DONE tproject=$idP tplan=$idTP build=$buildId tcs=" . json_encode($tcv) . "\n";