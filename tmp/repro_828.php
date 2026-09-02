<?php
// Repro/verify for issue #503 sub-task #828: build.class.php project scope.
//  - create() writes testproject_id (derived from testplan_id) + testplan_id
//  - createFromObject() rejects a name already used ANYWHERE in the project
//  - checkNameExistence() is project-scoped
//  - get_by_name()/get_by_id() resolve by project
// Run from repo root: php tmp/repro_828.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);
$p = DB_TABLE_PREFIX;
$bm = new build($db);

$tp = 2100; $tpl1 = 2101; $tpl2 = 2102;

// teardown previous run
$db->exec_query("DELETE FROM `{$p}builds` WHERE testproject_id=$tp");
$db->exec_query("DELETE FROM `{$p}testplans` WHERE testproject_id=$tp");
$db->exec_query("DELETE FROM `{$p}nodes_hierarchy` WHERE id IN ($tpl1,$tpl2,$tp) OR parent_id=$tp");
$db->exec_query("DELETE FROM `{$p}testprojects` WHERE id=$tp");

// project with two plans
$db->exec_query("INSERT INTO `{$p}testprojects` (id,prefix,notes,active,is_public,api_key) VALUES ($tp,'M828','repro 828',1,1,'r828proj')");
$db->exec_query("INSERT INTO `{$p}nodes_hierarchy` (id,parent_id,node_type_id,name) VALUES ($tp,0,1,'M828 Project')");
$db->exec_query("INSERT INTO `{$p}testplans` (id,testproject_id,notes,active,is_open,is_public,api_key) ".
                "VALUES ($tpl1,$tp,'',1,1,1,'r828a'),($tpl2,$tp,'',1,1,1,'r828b')");
$db->exec_query("INSERT INTO `{$p}nodes_hierarchy` (id,parent_id,node_type_id,name) VALUES ($tpl1,$tp,5,'P-A'),($tpl2,$tp,5,'P-B')");

$results = array();
function check($results,$name,$cond){ $results[] = array($name,$cond); return $results; }

// 1. create() writes testproject_id + testplan_id
$id1 = $bm->create($tpl1,'v2.0','notes');
$r = $db->get_recordset("SELECT testproject_id,testplan_id FROM `{$p}builds` WHERE id=$id1");
$results = check($results,'create() sets testproject_id',$r[0]['testproject_id']==$tp);
$results = check($results,'create() keeps testplan_id',$r[0]['testplan_id']==$tpl1);

// 2. checkNameExistence project-scoped: same name differs on plan B but SAME project -> exists
$st = $bm->checkNameExistence($tp,'v2.0');
$results = check($results,'checkNameExistence finds project-wide dup (status_ok=0)',$st['status_ok']==0);

// 3. createFromObject on plan B with same name -> must throw
$o = new stdClass();
$o->name = 'v2.0';
$o->tplan_id = $tpl2;
$o->notes = 'dup attempt';
$threw = false;
try { $bm->createFromObject($o); } catch (Exception $e) { $threw = true; }
$results = check($results,'createFromObject rejects project-wide duplicate name',$threw);

// 4. createFromObject on plan B with NEW name -> succeeds, writes project
$o2 = new stdClass();
$o2->name = 'v2.1';
$o2->tplan_id = $tpl2;
$o2->notes = 'ok';
$id2 = $bm->createFromObject($o2);
$r = $db->get_recordset("SELECT testproject_id,testplan_id FROM `{$p}builds` WHERE id=$id2");
$results = check($results,'createFromObject writes testproject_id',$r[0]['testproject_id']==$tp);
$results = check($results,'createFromObject keeps testplan_id',$r[0]['testplan_id']==$tpl2);

// 5. get_by_name resolves by project
$g = $bm->get_by_name('v2.0',array('tproject_id'=>$tp,'output'=>'minimun'));
$results = check($results,'get_by_name finds project-scoped build',is_array($g) && intval($g[0]['id'])==$id1);

// 6. get_by_id with tproject_id scope
$gb = $bm->get_by_id($id1,array('tproject_id'=>$tp,'output'=>'minimun'));
$results = check($results,'get_by_id with project scope',is_array($gb) && intval($gb['id'])==$id1);

$fails=0;
foreach($results as $row){ printf("%s: %s\n", $row[1]?'PASS':'FAIL', $row[0]); if(!$row[1]) $fails++; }
echo "\n$fails failure(s)\n";
exit($fails>0?1:0);
