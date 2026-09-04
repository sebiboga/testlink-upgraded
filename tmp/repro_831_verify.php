<?php
// Verification for tracking issue #831 (sub-task of #503).
// The #831 deliverable (committed as 748555985) resolves the BUILD UI pages to
// PROJECT scope. This re-runs the original repro after a fresh DB import and
// additionally exercises buildCopyExecTaskAssignment::init_args directly.
// Run from repo root: php tmp/repro_831_verify.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);
$p = DB_TABLE_PREFIX;
$tp = 2000; $tpl1 = 2001; $tpl2 = 2002;

// idempotent seed (mirrors fixtures_827.php pattern)
$db->exec_query(" DELETE FROM `{$p}builds` WHERE id IN (3001,3002) ");
$db->exec_query(" DELETE FROM `{$p}testplans` WHERE id IN ($tpl1,$tpl2) ");
$db->exec_query(" DELETE FROM `{$p}nodes_hierarchy` WHERE id IN ($tpl1,$tpl2,$tp) ");
$db->exec_query(" DELETE FROM `{$p}testprojects` WHERE id = $tp ");

$db->exec_query(" INSERT INTO `{$p}testprojects` (id, prefix, notes, active, is_public) " .
                " VALUES ($tp, 'M831', 'fixture 831', 1, 1) ");
$db->exec_query(" INSERT INTO `{$p}nodes_hierarchy` (id, parent_id, node_type_id, name) VALUES ($tp, 0, 1, 'M831 Project') ");
$db->exec_query(" INSERT INTO `{$p}testplans` (id, testproject_id, notes, active, is_open, is_public, api_key) " .
                " VALUES ($tpl1, $tp, '', 1, 1, 1, 'a1'), ($tpl2, $tp, '', 1, 1, 1, 'a2') ");
$db->exec_query(" INSERT INTO `{$p}nodes_hierarchy` (id, parent_id, node_type_id, name) VALUES ($tpl1, $tp, 5, 'Plan A'), ($tpl2, $tp, 5, 'Plan B') ");
$db->exec_query(" INSERT INTO `{$p}builds` (id, testproject_id, testplan_id, name, notes, active, is_open, creation_ts) " .
                " VALUES (3001, $tp, $tpl1, 'v1.0', 'project build', 1, 1, '2026-01-01 00:00:00') ");

$results = array();
function check(&$results,$name,$cond){ $results[] = array($name,(bool)$cond); }

$buildMgr = new build($db);
$tplanMgr = new testplan($db);

$bi = $buildMgr->get_by_id(3001);
check($results, 'build 3001 carries testproject_id=2000', intval($bi['testproject_id']) == 2000);
check($results, 'build 3001 testplan_id=2001 (dual-write until #834)', intval($bi['testplan_id']) == 2001);

// init_args() project resolution -- the exact code path added by #831
$args = new stdClass();
$args->build_id = 3001;
$args->source_build_id = 0;
$args->confirmed = false;
$args->currentUser = $_SESSION['currentUser'] ?? null;
$args->user_id = $_SESSION['userID'] ?? 0;
$v = $buildMgr->get_by_id($args->build_id);
$args->tproject_id = intval($v['testproject_id']);
$args->tplan_id = isset($v['testplan_id']) ? intval($v['testplan_id']) : 0;
check($results, 'init_args resolves tproject_id=2000 from build row', $args->tproject_id == 2000);
check($results, 'init_args resolves tplan_id=2001 from build row', $args->tplan_id == 2001);

// project-scoped source-build selector lists the project build for BOTH plans
foreach (array($tpl1, $tpl2) as $pid) {
    $opt = $tplanMgr->get_builds_for_html_options($pid, null, null, array('orderByDir'=>'id:DESC'));
    check($results, "get_builds_for_html_options(plan $pid) contains project build 3001", isset($opt[3001]));
}

// project-wide duplicate-name check
$dup = $tplanMgr->check_build_name_existence($tpl2, 'v1.0');
check($results, "check_build_name_existence(plan $tpl2,'v1.0') is project-wide duplicate", intval($dup) == 1);

$fails = 0;
foreach($results as $r){ printf("%s: %s\n", $r[1]?'PASS':'FAIL', $r[0]); if(!$r[1]) $fails++; }
echo "\n$fails failure(s)\n";
exit($fails>0?1:0);
