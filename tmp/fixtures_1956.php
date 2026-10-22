<?php
// Fixture for the Test Specification Health report
// (gui/templates/testcases/tcSpecHealth.html + api/tcspechealth/index.php).
//
// Refs #1956.
//
// Creates TWO test projects:
//
//   tproject TSH1956 (prefix TH) -> the project under test
//     suite "TH Root"
//       tcase "TH Duplicate A"    -> duplicate name (shared with TH Child Case),
//                                    no requirement, never executed
//       tcase "TH Duplicate B"    -> renamed to "TH Duplicate A" after creation
//                                    (the creation itself de-duplicates)
//       tcase "TH Empty Summary"  -> the summary of the latest version is empty
//       tcase "TH Linked"         -> linked to a requirement, never executed
//       tcase "TH Executed"       -> linked + executed TODAY -> HEALTHY, must
//                                    NOT appear in the report rows
//       tcase "TH Stale"          -> linked + last execution 200 days ago
//       tcase "TH Never Run"      -> no requirement, never executed
//     suite "TH Child Suite" (nested)
//       tcase "TH Child Case"     -> named "TH Duplicate A" (the duplicate group
//                                    spans two suites)
//
//   tproject TSH1956 EMPTY (prefix TE) -> no test case at all -> empty state
//
//   user tshnorights (role 3) -> the 403 path
//
// Run from repo root: php tmp/fixtures_1956.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tplanMgr = new testplan($db);
$tsuiteMgr = new testsuite($db);
$tcaseMgr = new testcase($db);
$userId = 1; // admin

$tables = tlObject::getDBTables();

// ---------------------------------------------------------------------------
// Projects
// ---------------------------------------------------------------------------
function mk1956Project($tprojMgr, $name, $prefix, $notes)
{
    foreach ((array)$tprojMgr->get_by_name($name) as $row) {
        $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
        if ($oid > 0) {
            echo "deleting old project $name ($oid)\n";
            $tprojMgr->delete($oid, 1);
        }
    }
    $item = new stdClass();
    $item->name = $name;
    $item->prefix = $prefix;
    $item->notes = $notes;
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
    if ($idP <= 0) {
        die("tproject create failed for $name\n");
    }
    $tprojMgr->setActive($idP);
    echo "tproject $name=$idP\n";
    return $idP;
}

$idP = mk1956Project($tprojMgr, 'TSH1956', 'TH', 'fixture for #1956 (spec health)');
$idEmpty = mk1956Project($tprojMgr, 'TSH1956 EMPTY', 'TE', 'fixture for #1956 - empty project');

$idPlan = intval($tplanMgr->create('TH Plan', '', $idP, 1, 1));
echo "testplan TH Plan=$idPlan\n";

// ---------------------------------------------------------------------------
// Suites
// ---------------------------------------------------------------------------
$retS = $tsuiteMgr->create($idP, 'TH Root', '', null, 0, 'allow_repeat');
$idRoot = intval($retS['id'] ?? 0);
echo "suite TH Root=$idRoot\n";

$retS2 = $tsuiteMgr->create($idRoot, 'TH Child Suite', '', null, 0, 'allow_repeat');
$idChild = intval($retS2['id'] ?? 0);
echo "suite TH Child Suite=$idChild\n";

// ---------------------------------------------------------------------------
// Test cases
// ---------------------------------------------------------------------------
function mk1956Steps()
{
    $steps = array();
    for ($i = 1; $i <= 2; $i++) {
        $t = new stdClass();
        $t->step_number = $i;
        $t->actions = 'Do action number ' . $i;
        $t->expected_results = 'Expected result of step ' . $i;
        $t->execution_type = TESTCASE_EXECUTION_TYPE_MANUAL;
        $steps[] = $t;
    }
    return $steps;
}

$makeTcase = function ($parent, $name, $summary) use ($tcaseMgr, $userId) {
    $ret = $tcaseMgr->create($parent, $name, $summary, '', mk1956Steps(),
        $userId, '', testcase::DEFAULT_ORDER, testcase::AUTOMATIC_ID,
        TESTCASE_EXECUTION_TYPE_MANUAL);
    if (!$ret['status_ok'] || intval($ret['id'] ?? 0) <= 0) {
        die("tcase create failed for $name: " . ($ret['message'] ?? $ret['msg'] ?? '') . "\n");
    }
    if (intval($ret['tcversion_id'] ?? 0) <= 0) {
        die("tcase create returned no version for $name\n");
    }
    return array(intval($ret['id']), intval($ret['tcversion_id']));
};

$cases = array();
$cases['dup_a'] = $makeTcase($idRoot, 'TH Duplicate A', 'the first one');
$cases['dup_b'] = $makeTcase($idRoot, 'TH Duplicate B', 'the second one');
$cases['empty'] = $makeTcase($idRoot, 'TH Empty Summary', 'a summary that will be emptied');
$cases['linked'] = $makeTcase($idRoot, 'TH Linked', 'linked to a requirement');
$cases['executed'] = $makeTcase($idRoot, 'TH Executed', 'healthy: linked and executed today');
$cases['stale'] = $makeTcase($idRoot, 'TH Stale', 'linked, but not executed for a long time');
$cases['never'] = $makeTcase($idRoot, 'TH Never Run', 'never executed, not linked');
$cases['child'] = $makeTcase($idChild, 'TH Child Case', 'a case of the child suite');
foreach ($cases as $k => $v) {
    echo "tcase $k tc={$v[0]} version={$v[1]}\n";
}

// The creation de-duplicates the names, so the fixture FORCES the duplicates
// the report has to find (this is exactly the state a legacy import or a
// renamed case leaves behind).
$db->exec_query("UPDATE {$tables['nodes_hierarchy']} SET name = 'TH Duplicate A' " .
    "WHERE id = " . intval($cases['dup_b'][0]));
$db->exec_query("UPDATE {$tables['nodes_hierarchy']} SET name = 'TH Duplicate A' " .
    "WHERE id = " . intval($cases['child'][0]));
echo "duplicates forced (dup_b + child_case named 'TH Duplicate A')\n";

// The empty summary: the summary of the LATEST version is empty.
$db->exec_query("UPDATE {$tables['tcversions']} SET summary = '' " .
    "WHERE id = " . intval($cases['empty'][1]));
echo "empty summary forced on tcase {$cases['empty'][0]}\n";

// ---------------------------------------------------------------------------
// Requirement links (the report counts the links of req_coverage; the rows
// need no FK, so a synthetic requirement is enough for the fixture).
// ---------------------------------------------------------------------------
function mk1956ReqLink($db, $tables, $tcId, $tcvId, $reqId, $reqVersionId, $active = 1)
{
    $db->exec_query(
        "INSERT INTO {$tables['req_coverage']} " .
        "  (req_id, req_version_id, testcase_id, tcversion_id, link_status, is_active, author_id) " .
        "VALUES (" . intval($reqId) . ", " . intval($reqVersionId) . ", " . intval($tcId) . ", " .
        intval($tcvId) . ", 1, " . intval($active) . ", 1)");
}

mk1956ReqLink($db, $tables, $cases['linked'][0], $cases['linked'][1], 9001, 9001, 1);
mk1956ReqLink($db, $tables, $cases['executed'][0], $cases['executed'][1], 9002, 9002, 1);
mk1956ReqLink($db, $tables, $cases['stale'][0], $cases['stale'][1], 9003, 9003, 1);
echo "requirement links written: 3\n";

// ---------------------------------------------------------------------------
// Build + executions
// ---------------------------------------------------------------------------
$db->exec_query("INSERT INTO {$tables['builds']}
    (testproject_id, name, notes, active, is_open, author_id, creation_ts)
  VALUES ($idP, 'TH Build 1', 'fixture build', 1, 1, 1, NOW())");
$build1 = intval($db->insert_id($tables['builds']));
echo "build TH Build 1=$build1\n";

function mk1956Exec($db, $tables, $planId, $buildId, $tcv, $status, $ts)
{
    $db->exec_query(
        "INSERT INTO {$tables['executions']}
            (build_id, tester_id, execution_ts, status, testplan_id, tcversion_id,
             tcversion_number, platform_id, execution_type, execution_duration)
         VALUES (" . intval($buildId) . ", 1, '$ts', '$status', " . intval($planId) . ", " .
        intval($tcv) . ", 1, 0, 1, 0)");
}

// TH Executed -> executed today (healthy).
mk1956Exec($db, $tables, $idPlan, $build1, $cases['executed'][1], 'p', date('Y-m-d H:i:s'));
// TH Stale -> last execution 200 days ago.
$oldTs = date('Y-m-d H:i:s', time() - (200 * 86400));
mk1956Exec($db, $tables, $idPlan, $build1, $cases['stale'][1], 'p', $oldTs);
echo "executions written (executed=today, stale=$oldTs)\n";

// ---------------------------------------------------------------------------
// A user with NO rights at all (role 3) -> the 403 path.
// ---------------------------------------------------------------------------
$TBU = tlObjectWithDB::getDBTables(array('users'));
$hash = password_hash('tshnorights', PASSWORD_DEFAULT);
$db->exec_query("INSERT INTO {$TBU['users']}
    (login, password, role_id, email, first, last, locale,
     default_testproject_id, active, cookie_string, auth_method)
  VALUES ('tshnorights', '$hash', 3, 'n@no.no', 'No', 'Rights', 'en_GB', 0, 1,
          'ck_tshnorights_1956', 'DB')
  ON DUPLICATE KEY UPDATE password=VALUES(password), role_id=3, active=1,
          auth_method=VALUES(auth_method)");
echo "user tshnorights ready (role 3)\n";

echo "DONE. ids: project=$idP emptyProject=$idEmpty plan=$idPlan build1=$build1 " .
    "suiteRoot=$idRoot suiteChild=$idChild\n";
