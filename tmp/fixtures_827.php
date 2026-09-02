<?php
// Fixture for issue #503 sub-task #827: seed duplicate (testproject_id, name)
// builds across two test plans in one project, plus an execution on each,
// so the merge migration (tmp/migrate_827.php) can be exercised.
// Run from repo root: php tmp/fixtures_827.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);
$p = DB_TABLE_PREFIX;
$tp = 2000; $tpl1 = 2001; $tpl2 = 2002;

// idempotency: remove any previously-seeded rows
foreach (array(7101, 7001, 6002, 6001, 3001, 3002) as $id) {
    $db->exec_query(" DELETE FROM `{$p}cfield_build_design_values` WHERE node_id = $id ");
}
$db->exec_query(" DELETE FROM `{$p}user_assignments` WHERE id = 7101 ");
$db->exec_query(" DELETE FROM `{$p}execution_tcsteps_wip` WHERE id = 7001 ");
$db->exec_query(" DELETE FROM `{$p}executions` WHERE id IN (6001,6002) ");
$db->exec_query(" DELETE FROM `{$p}builds` WHERE id IN (3001,3002) ");
$db->exec_query(" DELETE FROM `{$p}testplans` WHERE id IN ($tpl1,$tpl2) ");
$db->exec_query(" DELETE FROM `{$p}nodes_hierarchy` WHERE id IN ($tpl1,$tpl2,$tp) ");
$db->exec_query(" DELETE FROM `{$p}testprojects` WHERE id = $tp ");

// create project
$db->exec_query(" INSERT INTO `{$p}testprojects` (id, prefix, notes, active, is_public) " .
                " VALUES ($tp, 'M827', 'fixture 827', 1, 1) ");
$db->exec_query(" INSERT INTO `{$p}nodes_hierarchy` (id, parent_id, node_type_id, name) VALUES ($tp, 0, 1, 'M827 Project') ");

// two test plans in the same project
$db->exec_query(" INSERT INTO `{$p}testplans` (id, testproject_id, notes, active, is_open, is_public, api_key) " .
                " VALUES ($tpl1, $tp, '', 1, 1, 1, 'k1'), ($tpl2, $tp, '', 1, 1, 1, 'k2') ");
$db->exec_query(" INSERT INTO `{$p}nodes_hierarchy` (id, parent_id, node_type_id, name) VALUES ($tpl1, $tp, 5, 'Plan A'), ($tpl2, $tp, 5, 'Plan B') ");

// duplicate build name 'v1.0' in BOTH plans -> same (testproject_id, name)
$db->exec_query(" INSERT INTO `{$p}builds` (id, testproject_id, testplan_id, name, notes, active, is_open, creation_ts, commit_id, branch) " .
                " VALUES (3001, $tp, $tpl1, 'v1.0', 'plan A build', 1, 1, '2026-01-01 00:00:00', 'abc', 'main') ");
$db->exec_query(" INSERT INTO `{$p}builds` (id, testproject_id, testplan_id, name, notes, active, is_open, creation_ts, commit_id, branch) " .
                " VALUES (3002, $tp, $tpl2, 'v1.0', 'plan B build', 1, 1, '2026-01-02 00:00:00', 'DIFFERENT', 'develop') ");

// one execution per build
$db->exec_query(" INSERT INTO `{$p}executions` (id, build_id, tester_id, execution_ts, status, testplan_id, tcversion_id, platform_id) " .
                " VALUES (6001, 3001, 1, '2026-01-01 10:00:00', 'p', $tpl1, 4001, 5001) ");
$db->exec_query(" INSERT INTO `{$p}executions` (id, build_id, tester_id, execution_ts, status, testplan_id, tcversion_id, platform_id) " .
                " VALUES (6002, 3002, 1, '2026-01-02 10:00:00', 'f', $tpl2, 4001, 5001) ");

// execution_tcsteps_wip + user_assignments referencing the victim build 3002
$db->exec_query(" INSERT INTO `{$p}execution_tcsteps_wip` (id, tcstep_id, testplan_id, platform_id, build_id, tester_id, status) " .
                " VALUES (7001, 4001, $tpl2, 5001, 3002, 1, 'p') ");
$db->exec_query(" INSERT INTO `{$p}user_assignments` (id, type, feature_id, user_id, build_id, status) " .
                " VALUES (7101, 1, 4000, 1, 3002, 1) ");

// cfield_build_design_values referencing victim build 3002
$db->exec_query(" INSERT INTO `{$p}cfield_build_design_values` (field_id, node_id, value) VALUES (1, 3002, 'x') ");

echo "Fixture seeded: testproject=$tp, plans $tpl1/$tpl2, builds 3001/3002 (dup 'v1.0'), executions 6001/6002\n";
