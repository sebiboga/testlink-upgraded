<?php
// Verify issue #503 reader-conversion (pre-#834) work:
//   - assignment_mgr joins are project-scoped (via testplans subquery / testproject_id)
//   - cfield_mgr execution-details report join is project-scoped (B.testproject_id)
//   - testplan::delete no longer deletes (project-scoped) builds
//   - testproject::delete cleans up all plans AND project builds
// Uses existing fixture project 2000 (plans 2001/2002, builds 3001/3111).
// Run from repo root: php tmp/repro_readerconv.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);
$p = DB_TABLE_PREFIX;

$results = array();
function check(&$results,$name,$cond){ $results[] = array($name,$cond); }

$tplanMgr = new testplan($db);
$assignmentMgr = &$tplanMgr->assignment_mgr;
$cfieldMgr = new cfield_mgr($db);

// ---- SQL validity: the converted joins must parse against the real schema ----
$buildProject2 = $assignmentMgr->getPlanProjectID(2002);
check($results,'getPlanProjectID(2002) resolves to project 2000', $buildProject2 == 2000);

// get_not_run_tc_count_per_build converted join (per-build project subquery)
$sqlA = " SELECT count(0) qty FROM `{$p}user_assignments` UA " .
        " JOIN `{$p}builds` BU ON UA.build_id = BU.id " .
        " JOIN `{$p}testplan_tcversions` TPTCV " .
        " ON TPTCV.testplan_id IN (SELECT id FROM `{$p}testplans` WHERE testproject_id = BU.testproject_id) " .
        " AND TPTCV.id = UA.feature_id " .
        " WHERE UA.build_id = 3001 ";
$rsA = $db->get_recordset(" EXPLAIN " . $sqlA);
check($results,'get_not_run_tc_count_per_build join runs (per-build project subquery)', is_array($rsA));

// emailLinkToExecPlanning converted join (B.testproject_id)
$sqlB = " SELECT UA.user_id FROM `{$p}user_assignments` UA " .
        " JOIN `{$p}builds` B ON UA.build_id = B.id " .
        " WHERE B.testproject_id = {$buildProject2} AND B.id = 3001 ";
$rsB = $db->get_recordset(" EXPLAIN " . $sqlB);
check($results,'emailLinkToExecPlanning join runs (B.testproject_id)', is_array($rsB));

// cfield_mgr execution-details report join (B.testproject_id = tproject_id)
$sqlC = " SELECT B.id AS builds_id, B.name AS build_name, EXECU.id AS exec_id " .
        " FROM `{$p}custom_fields` CF " .
        " JOIN `{$p}cfield_execution_values` CFEV ON CFEV.field_id=CF.id " .
        " AND CFEV.testplan_id = 2001 " .
        " JOIN `{$p}executions` EXECU ON CFEV.tcversion_id = EXECU.tcversion_id " .
        " AND CFEV.execution_id = EXECU.id " .
        " JOIN `{$p}builds` B ON B.id = EXECU.build_id AND B.testproject_id = 2000 ";
$rsC = $db->get_recordset(" EXPLAIN " . $sqlC);
check($results,'cfield_mgr execution-details join runs (B.testproject_id)', is_array($rsC));

// ---- behavior: testplan::delete must NOT remove project-scoped builds ----
// Create a throwaway plan (9101) in project 2000 sharing the project builds.
$db->exec_query(" INSERT INTO `{$p}testplans` (id, testproject_id, notes, active, is_open, is_public) " .
                " VALUES (9101, 2000, '', 1, 1, 1) ");
$db->exec_query(" INSERT INTO `{$p}nodes_hierarchy` (id, parent_id, node_type_id, name) VALUES (9101, 2000, 5, 'ReaderConv Throwaway') ");
$buildIdsBefore = $db->get_recordset(" SELECT id FROM `{$p}builds` WHERE testproject_id = 2000 ");
$tplanBefore = $db->get_recordset(" SELECT id FROM `{$p}builds` WHERE testproject_id = 2000 ");

// delete the throwaway plan
$tp = new testplan($db);
try {
    $tp->delete(9101);
    $delOk = true;
} catch (Exception $e) {
    $delOk = false;
}
check($results,'testplan::delete(throwaway) executes without error', $delOk);

$buildIdsAfter = $db->get_recordset(" SELECT id FROM `{$p}builds` WHERE testproject_id = 2000 ");
$buildCountBefore = is_array($buildIdsBefore)?count($buildIdsBefore):0;
$buildCountAfter = is_array($buildIdsAfter)?count($buildIdsAfter):0;
check($results,'testplan::delete does not remove project builds (count unchanged '.$buildCountBefore.'='.$buildCountAfter.')',
      $buildCountBefore == $buildCountAfter);

// cleanup throwaway plan rows (delete() should already have removed the plan+roles)
$db->exec_query(" DELETE FROM `{$p}testplans` WHERE id = 9101 ");
$db->exec_query(" DELETE FROM `{$p}nodes_hierarchy` WHERE id = 9101 ");

// ---- behavior: testproject::delete removes ALL project builds ----
// Create a throwaway project 9200 with plan 9201 and a build 9301.
$db->exec_query(" INSERT INTO `{$p}testprojects` (id, prefix, notes, active, is_public, api_key) " .
                " VALUES (9200, 'RC', 'readerconv', 1, 1, MD5('rc-key-9200')) ");
$db->exec_query(" INSERT INTO `{$p}nodes_hierarchy` (id, parent_id, node_type_id, name) VALUES (9200, 0, 1, 'ReaderConv Project') ");
$db->exec_query(" INSERT INTO `{$p}testplans` (id, testproject_id, notes, active, is_open, is_public) " .
                " VALUES (9201, 9200, '', 1, 1, 1) ");
$db->exec_query(" INSERT INTO `{$p}nodes_hierarchy` (id, parent_id, node_type_id, name) VALUES (9201, 9200, 5, 'RC Plan') ");
$db->exec_query(" INSERT INTO `{$p}builds` (id, testproject_id, testplan_id, name) VALUES (9301, 9200, 9201, 'rc-v1') ");

$tp2 = new testproject($db);
$ret = $tp2->delete(9200);
$tpjBuilds = $db->get_recordset(" SELECT id FROM `{$p}builds` WHERE testproject_id = 9200 ");
$tpjPlans = $db->get_recordset(" SELECT id FROM `{$p}testplans` WHERE testproject_id = 9200 ");
check($results,'testproject::delete returns ok', $ret['status_ok'] == 1);
check($results,'testproject::delete removes project builds (left='.(is_array($tpjBuilds)?count($tpjBuilds):0).')',
      !(is_array($tpjBuilds) && count($tpjBuilds) > 0));
check($results,'testproject::delete removes project plans (left='.(is_array($tpjPlans)?count($tpjPlans):0).')',
      !(is_array($tpjPlans) && count($tpjPlans) > 0));

$fails = 0;
foreach($results as $r){ printf("%s: %s\n", $r[1]?'PASS':'FAIL', $r[0]); if(!$r[1]) $fails++; }
echo "\n$fails failure(s)\n";
exit($fails>0?1:0);
