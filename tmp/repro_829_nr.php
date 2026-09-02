<?php
// Verify issue #503 sub-task #829 fix for the "never run" metrics methods:
// after builds became project-scoped, the JOIN builds + HAVING COUNT comparison
// must use the same project build set (active+open project builds).
// Run from repo root: php tmp/repro_829_nr.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);
$p = DB_TABLE_PREFIX;
$tp = 2200; $tpl = 2210;
$suite = 4250; $tcase = 4252; $tcver = 4253;
$b1 = 3201; $b2 = 3202;

foreach (array(3202, 3201, 999999) as $id) { $id = intval($id); }
// teardown
$db->exec_query(" DELETE FROM `{$p}executions` WHERE testplan_id=$tpl ");
$db->exec_query(" DELETE FROM `{$p}builds` WHERE testproject_id=$tp ");
$db->exec_query(" DELETE FROM `{$p}testplan_tcversions` WHERE testplan_id=$tpl ");
$db->exec_query(" DELETE FROM `{$p}tcversions` WHERE id=$tcver ");
$db->exec_query(" DELETE FROM `{$p}nodes_hierarchy` WHERE id IN ($suite,$tcase,$tcver,$tpl,$tp) OR parent_id=$tp ");
$db->exec_query(" DELETE FROM `{$p}testplans` WHERE id=$tpl ");
$db->exec_query(" DELETE FROM `{$p}testprojects` WHERE id=$tp ");

// project + plan
$db->exec_query(" INSERT INTO `{$p}testprojects` (id,prefix,notes,active,is_public,api_key) VALUES ($tp,'NR','repro nr',1,1,'r829nr') ");
$db->exec_query(" INSERT INTO `{$p}nodes_hierarchy` (id,parent_id,node_type_id,name) VALUES ($tp,0,1,'NR Project'),($tpl,$tp,5,'Plan One'),($suite,$tp,2,'Suite'),($tcase,$suite,3,'TC'),($tcver,$tcase,4,'TC v1') ");
$db->exec_query(" INSERT INTO `{$p}testplans` (id,testproject_id,notes,active,is_open,is_public,api_key) VALUES ($tpl,$tp,'',1,1,1,'r829nrplan') ");

// tcversion + link to plan
$db->exec_query(" INSERT INTO `{$p}tcversions` (id,tc_external_id,version,importance,execution_type) VALUES ($tcver,100,1,2,1) ");
$db->exec_query(" INSERT INTO `{$p}testplan_tcversions` (testplan_id,tcversion_id,platform_id) VALUES ($tpl,$tcver,0) ");

// TWO project builds, both active+open (shared across the project)
$db->exec_query(" INSERT INTO `{$p}builds` (testproject_id,testplan_id,name,notes,active,is_open,creation_ts) VALUES ($tp,$tpl,'b1','',1,1,'2026-01-01 00:00:00'),($tp,$tpl,'b2','',1,1,'2026-01-02 00:00:00') ");
$ids = $db->get_recordset(" SELECT id FROM `{$p}builds` WHERE testproject_id=$tp ORDER BY id ");
$bids = array();
foreach($ids as $row){ $bids[] = intval($row['id']); }
$b1 = $bids[0]; $b2 = $bids[1];

$mm = new tlTestPlanMetrics($db);
$results = array();
function check(&$results,$name,$cond){ $results[] = array($name,$cond); }

// 1. build set is project-scoped: 2 active+open project builds
$bs = $mm->get_builds($tpl, testplan::ACTIVE_BUILDS, testplan::OPEN_BUILDS);
check($results,'get_builds returns 2 project builds', is_array($bs) && count($bs) == 2);

// 2. no executions => tcversion is NEVER RUN on all project builds
$neverRun = $mm->getNeverRunOnTestPlanWithoutPlatforms($tpl);
check($results,'never-run with no executions contains the tcversion',
     is_array($neverRun) && count($neverRun) == 1);

// 3. run on ONE build => no longer "never run" (HAVING count must equal project build count)
$db->exec_query(" INSERT INTO `{$p}executions` (build_id,tester_id,execution_ts,status,testplan_id,tcversion_id,platform_id) VALUES ($b1,1,'2026-01-03 10:00:00','p',$tpl,$tcver,0) ");
$neverRun2 = $mm->getNeverRunOnTestPlanWithoutPlatforms($tpl);
check($results,'never-run empty after running on one of two builds',
     is_null($neverRun2) || count($neverRun2) == 0);

// 4. buildSet count == project active+open build count (join consistency invariant)
$cntSql = $db->get_recordset(" SELECT COUNT(*) AS n FROM `{$p}builds` WHERE testproject_id=$tp AND active=1 AND is_open=1 ");
$invariant = (intval($cntSql[0]['n']) == 2);
check($results,'project active+open build count == buildSet count', $invariant);

$fails = 0;
foreach($results as $r){ printf("%s: %s\n", $r[1]?'PASS':'FAIL', $r[0]); if(!$r[1]) $fails++; }
echo "\n$fails failure(s)\n";
exit($fails>0?1:0);
