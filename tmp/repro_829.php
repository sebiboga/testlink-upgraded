<?php
// Verify issue #503 sub-task #829: testplan.class.php build methods + metrics
// now resolve builds at the Test Project scope (shared across plans).
// Uses fixture project 2000 (plans 2001 & 2002, merged build 3001 'v1.0').
// Run from repo root: php tmp/repro_829.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);
$p = DB_TABLE_PREFIX;
$tp = 2000; $tpl1 = 2001; $tpl2 = 2002;

$tplanMgr = new testplan($db);

// ensure exactly build 3001 in project 2000 (drop any 3003 leftover)
$db->exec_query(" DELETE FROM `{$p}builds` WHERE id=3003 AND testproject_id=$tp ");
$rows = $db->get_recordset(" SELECT COUNT(*) AS n FROM `{$p}builds` WHERE testproject_id=$tp ");
$nBuilds = intval($rows[0]['n']);

$results = array();
function check(&$results,$name,$cond){ $results[] = array($name,$cond); }

// ---- get_builds(): both plans must see all project builds (shared) ----
$bsA = $tplanMgr->get_builds($tpl1);
$bsB = $tplanMgr->get_builds($tpl2);
$idsA = is_null($bsA) ? array() : array_keys($bsA);
$idsB = is_null($bsB) ? array() : array_keys($bsB);
check($results,'plan A get_builds returns project build 3001', in_array(3001,$idsA));
check($results,'plan B get_builds ALSO returns project build 3001 (shared)',
     in_array(3001,$idsB) && count($idsB) == $nBuilds);
check($results,'both plans see the same (shared) build set', $idsA == $idsB);

// If a 2nd shared build existed, both plans must see it too:
if (false) {} // place no-op

// ---- get_builds_for_html_options() ----
$ho = $tplanMgr->get_builds_for_html_options($tpl2);
check($results,'get_builds_for_html_options(plan B) includes 3001',
     is_array($ho) && isset($ho[3001]));

// ---- get_max_build_id() ----
$mx = $tplanMgr->get_max_build_id($tpl2);
check($results,'get_max_build_id(plan B) = 3001', intval($mx) == 3001);

// ---- getNumberOfBuilds() ----
$cnt = $tplanMgr->getNumberOfBuilds($tpl2);
check($results,'getNumberOfBuilds(plan B) = 1 (project build)', intval($cnt) == $nBuilds);

// ---- get_build_id_by_name() project-wide ----
$bid = $tplanMgr->get_build_id_by_name($tpl2, 'v1.0');
check($results,'get_build_id_by_name(plan B) finds 3001', $bid == 3001);

// ---- check_build_name_existence() project-wide ----
$ex = $tplanMgr->check_build_name_existence($tpl2, 'v1.0');
check($results,'check_build_name_existence(plan B) detects project-wide dup', $ex == 1);
$ex2 = $tplanMgr->check_build_name_existence($tpl2, 'NONEXISTENT');
check($results,'check_build_name_existence unique name ok', $ex2 == 0);

// ---- get_build_by_name() / get_build_by_id() project scope ----
$bn = $tplanMgr->get_build_by_name($tpl2, 'v1.0');
check($results,'get_build_by_name(plan B) finds 3001', is_array($bn) && intval($bn['id']) == 3001);
$bi = $tplanMgr->get_build_by_id($tpl2, 3001);
check($results,'get_build_by_id(plan B) finds 3001', is_array($bi) && intval($bi['id']) == 3001);

// ---- metrics build-set cascade (tlTestPlanMetrics extends testplan) ----
$mm = new tlTestPlanMetrics($db);
$set = $mm->get_builds($tpl2, testplan::ACTIVE_BUILDS, testplan::OPEN_BUILDS);
$setIds = is_null($set) ? array() : array_keys($set);
check($results,'metrics get_builds(plan B) is project-wide (contains 3001)',
     in_array(3001,$setIds) && count($setIds) == $nBuilds);

$fails = 0;
foreach($results as $r){ printf("%s: %s\n", $r[1]?'PASS':'FAIL', $r[0]); if(!$r[1]) $fails++; }
echo "\n$fails failure(s)\n";
exit($fails>0?1:0);
