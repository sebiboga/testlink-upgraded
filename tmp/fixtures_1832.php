<?php
/**
 * tmp/fixtures_1832.php — fixture for the planUrgency modernization (Refs #1832)
 *
 * Creates:
 *   test project  "URG1832"  (prefix URG)
 *   test plan     "Urgency Plan 1832"  (1 build open, 1 build closed)
 *   suite tree:  /Suite A  (3 direct test cases + 1 sub-suite with 1 test case)
 *                /Suite B  (0 test cases -> empty state)
 *   platforms:   "URG Platform 1" / "URG Platform 2"
 *   one test case linked on BOTH platforms, one only on platform 1
 *   an execution assignment for build 1 on one test case
 *
 * Idempotent-ish: drops and recreates the project by name.
 */
require_once(__DIR__ . '/../config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
$conn = doDBConnect($db);
if (!empty($conn) && isset($conn['status']) && !$conn['status']) {
    fwrite(STDERR, "DB connect failed\n");
    exit(1);
}

$T = tlObjectWithDB::getDBTables([
    'testprojects', 'nodes_hierarchy', 'testplans', 'testplan_tcversions',
    'tcversions', 'platforms', 'builds', 'user_assignments',
    'users', 'node_types',
]);

function nt($db, $tbl, $desc)
{
    static $m = null;
    if (is_null($m)) {
        $m = [];
        foreach ((array)$db->get_recordset("SELECT id, description FROM {$tbl}") as $r) {
            $m[strtolower($r['description'])] = (int)$r['id'];
        }
    }
    return (int)($m[strtolower($desc)] ?? 0);
}

$NT_PROJECT = nt($db, $T['node_types'], 'testproject');
$NT_SUITE = nt($db, $T['node_types'], 'testsuite');
$NT_CASE = nt($db, $T['node_types'], 'testcase');
$NT_TCVER = nt($db, $T['node_types'], 'testcase_version');
$NT_PLAN = nt($db, $T['node_types'], 'testplan');

// ---- clean previous run -------------------------------------------------
$old = $db->fetchOneValue("SELECT id FROM {$T['testprojects']} WHERE prefix='URG'");
if ($old) {
    $tid = (int)$old;
    $db->exec_query("DELETE FROM {$T['user_assignments']} WHERE feature_id BETWEEN 90000 AND 90999");
    $db->exec_query("DELETE FROM {$T['testplan_tcversions']} WHERE tcversion_id BETWEEN 90000 AND 90999");
    $db->exec_query("DELETE FROM {$T['tcversions']} WHERE id BETWEEN 90000 AND 90999");
    foreach (['builds', 'testplans', 'platforms', 'nodes_hierarchy', 'testprojects'] as $tbl) {
        $db->exec_query("DELETE FROM {$T[$tbl]} WHERE id BETWEEN 90000 AND 90999");
    }
    $db->exec_query("DELETE FROM {$T['users']} WHERE login='urg1832user'");
    echo "dropped leftover fixture rows (90000-90999)\n";
}

// ---- project -------------------------------------------------------------
$db->exec_query("INSERT INTO {$T['testprojects']} (id, notes, color, active, option_reqs, prefix, tc_counter, is_public, issue_tracker_enabled, api_key)
                 VALUES (90001, 'fixture for #1832', '#3fbdb8', 1, 1, 'URG', 4, 1, 1, 'urg1832fixture')");
echo "project 90001 URG1832\n";

// ---- nodes ---------------------------------------------------------------
function addNode($db, $T, $id, $name, $typeId, $parentId, $order = 1)
{
    $db->exec_query("INSERT INTO {$T['nodes_hierarchy']} (id, name, node_type_id, parent_id, node_order)
                     VALUES ($id, '$name', $typeId, $parentId, $order)");
}
// project root node (the testprojects row above only holds the attributes)
addNode($db, $T, 90001, 'URG1832', $NT_PROJECT, 0);
addNode($db, $T, 90002, 'Suite A', $NT_SUITE, 90001);
addNode($db, $T, 90003, 'Suite A - Sub', $NT_SUITE, 90002, 2);
addNode($db, $T, 90004, 'Suite B', $NT_SUITE, 90001, 2);

addNode($db, $T, 90010, 'TC-A1 first', $NT_CASE, 90002, 1);
addNode($db, $T, 90011, 'TC-A2 both platforms', $NT_CASE, 90002, 2);
addNode($db, $T, 90012, 'TC-A3 sub-suite child', $NT_CASE, 90003, 1);
addNode($db, $T, 90013, 'TC-A4 not in plan', $NT_CASE, 90002, 3);

// tcversion nodes
addNode($db, $T, 90020, 'v1 TC-A1', $NT_TCVER, 90010);
addNode($db, $T, 90021, 'v1 TC-A2', $NT_TCVER, 90011);
addNode($db, $T, 90022, 'v1 TC-A3', $NT_TCVER, 90012);
addNode($db, $T, 90023, 'v1 TC-A4', $NT_TCVER, 90013);

// ---- tcversions ----------------------------------------------------------
$db->exec_query("INSERT INTO {$T['tcversions']} (id, tc_external_id, version, importance, status, summary, author_id, execution_type)
                 VALUES (90020, 1, 1, 3, 1, 'TC-A1 first', 1, 1),
                        (90021, 2, 1, 2, 1, 'TC-A2 both platforms', 1, 1),
                        (90022, 3, 1, 1, 1, 'TC-A3 sub-suite child', 1, 1),
                        (90023, 4, 1, 2, 1, 'TC-A4 not in plan', 1, 1)");

// ---- plan ----------------------------------------------------------------
addNode($db, $T, 90100, 'Urgency Plan 1832', $NT_PLAN, 90001);
$db->exec_query("INSERT INTO {$T['testplans']} (id, testproject_id, notes, active, is_open, is_public, api_key)
                 VALUES (90100, 90001, '', 1, 1, 1, 'urg1832plan')");

// ---- platforms -----------------------------------------------------------
$db->exec_query("INSERT INTO {$T['platforms']} (id, name, testproject_id, notes, enable_on_design, enable_on_execution, is_open) VALUES (90301, 'URG Platform 1', 90001, '', 1, 1, 1)");
$db->exec_query("INSERT INTO {$T['platforms']} (id, name, testproject_id, notes, enable_on_design, enable_on_execution, is_open) VALUES (90302, 'URG Platform 2', 90001, '', 1, 1, 1)");

// ---- links ---------------------------------------------------------------
// urgency values: 1 LOW, 2 MEDIUM, 3 HIGH
$db->exec_query("INSERT INTO {$T['testplan_tcversions']} (testplan_id, tcversion_id, urgency, platform_id, node_order)
                 VALUES (90100, 90020, 1, 90301, 1),
                        (90100, 90021, 2, 90301, 2),
                        (90100, 90021, 3, 90302, 3),
                        (90100, 90022, 2, 90301, 4)");

// ---- builds --------------------------------------------------------------
$db->exec_query("INSERT INTO {$T['builds']} (id, testproject_id, name, notes, active, is_open, author_id)
                 VALUES (90401, 90001, 'Build 1832-A', '', 1, 1, 1),
                        (90402, 90001, 'Build 1832-B closed', '', 0, 0, 1)");

// ---- user + assignment ---------------------------------------------------
$db->exec_query("INSERT INTO {$T['users']} (id, login, password, role_id, active, email, first, last, cookie_string)
                 VALUES (90400, 'urg1832user', 'x', 3, 1, 'urg1832@local', 'Urg', 'User', 'fixture1832')
                 ON DUPLICATE KEY UPDATE cookie_string='fixture1832'");

$tcase = new testcase($db);
$execTaskId = (int)$tcase->assignment_types['testcase_execution']['id'];
// NOTE: the legacy join is `UA.feature_id = TPTCV.id` (testPlanUrgency.class.php),
// i.e. the testplan_tcversions ROW id, not the tcversion id.
$tpTcvId = (int)$db->fetchOneValue("SELECT id FROM {$T['testplan_tcversions']} WHERE testplan_id=90100 AND tcversion_id=90020 AND platform_id=90301");
$db->exec_query("INSERT INTO {$T['user_assignments']} (build_id, feature_id, type, user_id, assigner_id, status)
                 VALUES (90401, $tpTcvId, $execTaskId, 90400, 1, 1)");

echo "done\n";