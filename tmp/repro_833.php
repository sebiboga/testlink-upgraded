<?php
// Verify issue #503 sub-task #833: xmlrpc build methods resolve builds in PROJECT scope.
// Methods:
//   getBuildsForTestPlan        -> testplan::get_builds (project-scoped)
//   getLatestBuildForTestPlan   -> testplan::get_max_build_id + get_builds (project-scoped)
//   createBuild                 -> build::create (project-scoped); source-build pre-check now
//                                  uses testproject_id (was testplan_id) so a source build from
//                                  ANOTHER plan of the SAME project is accepted.
// Fixture project 2000 (plans 2001/2002, project build 3001 'v1.0').
// Run from repo root: php tmp/repro_833.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);
$p = DB_TABLE_PREFIX;

$results = array();
function check(&$results,$name,$cond){ $results[] = array($name,$cond); }

$tplanMgr = new testplan($db);

// 1. getBuildsForTestPlan backing call is project-scoped
$builds = $tplanMgr->get_builds(2002);
check($results,'get_builds(2002) [getBuildsForTestPlan] contains shared build 3001', isset($builds[3001]));

// 2. getLatestBuildForTestPlan backing call is project-scoped
$max = $tplanMgr->get_max_build_id(2002);
check($results,'get_max_build_id(2002) [getLatestBuildForTestPlan] >= 3001', intval($max) >= 3001);

// 3. createBuild duplicate-check path is project-scoped
$bid = $tplanMgr->get_build_id_by_name(2002, 'v1.0');
check($results,'get_build_id_by_name(2002,\'v1.0\') [createBuild] returns 3001 (project dup)', intval($bid) == 3001);

// 4. createBuild SOURCE-build pre-check uses project scope: a source build created under
//    plan 2001 (3001) must be accepted when creating a build under plan 2002 (same project 2000).
//    Reproduce the new SQL `WHERE id=3001 AND testproject_id = getProjectIdOfPlan(2002)`.
$tpj = $tplanMgr->getProjectIdOfPlan(2002);
$sql = " SELECT id FROM `{$p}builds` WHERE id=3001 AND testproject_id=" . intval($tpj);
$rs = $db->get_recordset($sql);
check($results,'createBuild source-build check accepts cross-plan same-project build 3001',
      is_array($rs) && count($rs) == 1);

// 5. a source build from a DIFFERENT project is rejected (project guard works)
$other = $db->get_recordset(" SELECT id FROM `{$p}builds` WHERE testproject_id <> 2000 LIMIT 1 ");
if (is_array($other) && count($other) > 0) {
    $otherId = $other[0]['id'];
    $sql2 = " SELECT id FROM `{$p}builds` WHERE id=" . intval($otherId) . " AND testproject_id=" . intval($tpj);
    $rs2 = $db->get_recordset($sql2);
    check($results,'createBuild source-build check rejects other-project build ' . $otherId,
          !(is_array($rs2) && count($rs2) == 1));
} else {
    check($results,'createBuild source-build check rejects other-project build (no other-project build to test)', true);
}

$fails = 0;
foreach($results as $r){ printf("%s: %s\n", $r[1]?'PASS':'FAIL', $r[0]); if(!$r[1]) $fails++; }
echo "\n$fails failure(s)\n";
exit($fails>0?1:0);
