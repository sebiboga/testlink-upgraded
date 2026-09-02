<?php
// Verify issue #503 sub-task #831: build UI pages operate in PROJECT scope.
// - buildCopyExecTaskAssignment::init_args resolves the project from the build's
//   testproject_id (no plan-node walk).
// - buildEdit/buildView render functions (get_builds / get_builds_for_html_options)
//   are project-scoped: a build is shared across the plans of its project.
// Fixture project 2000 (plans 2001/2002, project build 3001).
// Run from repo root: php tmp/repro_831.php
require_once('config.inc.php');
require_once('common.php');
require_once('lib/functions/reports.class.php');

$db = new database(DB_TYPE);
doDBConnect($db);
$p = DB_TABLE_PREFIX;

$results = array();
function check(&$results,$name,$cond){ $results[] = array($name,$cond); }

$buildMgr = new build($db);
$tplanMgr = new testplan($db);

// 1. build carries its project id (what buildCopyExecTaskAssignment::init_args now reads)
$bi = $buildMgr->get_by_id(3001);
check($results,'build 3001 carries testproject_id=2000', intval($bi['testproject_id']) == 2000);
check($results,'build 3001 testplan_id=2001 (dual-write, until #834)', intval($bi['testplan_id']) == 2001);

// 2. build UI listing (buildEdit/buildView) is project-scoped across plans
$setB = $tplanMgr->get_builds(2002);
check($results,'get_builds(plan 2002) contains project build 3001', isset($setB[3001]));

// 3. source-build selector (buildCopyExecTaskAssignment getBuildDomainForGUI) project-scoped
$optB = $tplanMgr->get_builds_for_html_options(2002,null,null,array('orderByDir'=>'id:DESC'));
check($results,'get_builds_for_html_options(plan 2002) contains build 3001', isset($optB[3001]));

// 4. duplicate-name check used by buildEdit crossChecks() is project-wide
$dup = $tplanMgr->check_build_name_existence(2002, 'v1.0');
check($results,'check_build_name_existence(plan 2002,\'v1.0\') is project-wide duplicate', intval($dup) == 1);

$fails = 0;
foreach($results as $r){ printf("%s: %s\n", $r[1]?'PASS':'FAIL', $r[0]); if(!$r[1]) $fails++; }
echo "\n$fails failure(s)\n";
exit($fails>0?1:0);
