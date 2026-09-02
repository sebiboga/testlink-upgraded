<?php
// Verify issue #503 sub-task #832: the execution pages (execSetResults/execDashboard)
// resolve builds in PROJECT scope. The underlying testplan build methods they use were
// project-scoped in #829: get_build_by_id, get_max_build_id, get_builds_for_html_options.
// A build created under plan 2001 must be resolvable/executable when working under plan 2002.
// Fixture project 2000 (plans 2001/2002, project build 3001 'v1.0').
// Run from repo root: php tmp/repro_832.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);
$p = DB_TABLE_PREFIX;

$results = array();
function check(&$results,$name,$cond){ $results[] = array($name,$cond); }

$tplanMgr = new testplan($db);

// 1. build info lookup used by execSetResults.php:235 / :352 (execution requests can reference
//    a build whose testplan_id belongs to ANOTHER plan of the same project).
$bi = $tplanMgr->get_build_by_id(2002, 3001);
check($results,'get_build_by_id(plan 2002, build 3001) finds project-shared build',
      is_array($bi) && intval(@$bi['id']) == 3001);

// 2. latest build pick used by execDashboard.php:85 default build.
$max = $tplanMgr->get_max_build_id(2002, 1, 1);
check($results,'get_max_build_id(plan 2002, active, open) returns project build >= 3001',
      intval($max) >= 3001);

// 3. build dropdown used by execSetResults:1566 / execDashboard:208 shows project builds for plan 2002.
$opt = $tplanMgr->get_builds_for_html_options(2002);
check($results,'get_builds_for_html_options(plan 2002) contains shared build 3001',
      isset($opt[3001]));

$fails = 0;
foreach($results as $r){ printf("%s: %s\n", $r[1]?'PASS':'FAIL', $r[0]); if(!$r[1]) $fails++; }
echo "\n$fails failure(s)\n";
exit($fails>0?1:0);
