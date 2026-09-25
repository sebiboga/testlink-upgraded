<?php
// Fixture for issue #1585: populated Requirement Manager list + a read-only
// user that has ONLY reqmgrsystem_view (right id 34) and no
// reqmgrsystem_management (right id 33).
// Run: php tmp/fixtures_1585.php
require_once('config.inc.php');
require_once('common.php');
$db = new database(DB_TYPE);
doDBConnect($db);

// 1. test project so the session carries a testprojectID
$db->exec_query("INSERT INTO testprojects (id,notes,color,active,option_reqs,option_priority,option_automation,options,prefix,tc_counter,is_public,issue_tracker_enabled,code_tracker_enabled,reqmgr_integration_enabled,api_key)
  VALUES (1,'Issue 1585 repro project','#9BD',1,1,0,0,NULL,'RM1585',0,1,0,0,1,'rm1585apikey000000000000000000000000000')
  ON DUPLICATE KEY UPDATE active=1, reqmgr_integration_enabled=1");

// 2. two requirement manager systems (one linked to a SRS => link_count > 0)
$db->exec_query("INSERT INTO reqmgrsystems (id,name,type,cfg) VALUES
  (1,'ReqMgr 1585 unlinked',1,'<reqmgrsystem><uribase>http://localhost:9/</uribase><uriview>/x</uriview><uriapikey></uriapikey></reqmgrsystem>'),
  (2,'ReqMgr 1585 linked',1,'<reqmgrsystem><uribase>http://localhost:9/</uribase><uriview>/y</uriview><uriapikey></uriapikey></reqmgrsystem>')
  ON DUPLICATE KEY UPDATE name=VALUES(name), type=VALUES(type)");

// 3. read-only role: ONLY reqmgrsystem_view
$db->exec_query("INSERT INTO roles (id,description,notes) VALUES (20,'reqmgr view only','issue 1585 repro')
  ON DUPLICATE KEY UPDATE description=VALUES(description)");
$db->exec_query("DELETE FROM role_rights WHERE role_id=20");
$db->exec_query("INSERT INTO role_rights (role_id,right_id) VALUES (20,34)");

// 4. read-only user bound to that role
$hash = password_hash('rmreadonly', PASSWORD_DEFAULT);
$db->exec_query("INSERT INTO users (login,password,role_id,email,first,last,locale,default_testproject_id,active,cookie_string,auth_method)
  VALUES ('rmreadonly','" . $hash . "',20,'rm@1585.local','Read','Only','en_GB',1,1,'ck_rm1585','DB')
  ON DUPLICATE KEY UPDATE password=VALUES(password), role_id=20, active=1, default_testproject_id=1, auth_method='DB'");

echo "reqmgrsystems: " . json_encode($db->get_recordset("SELECT id,name FROM reqmgrsystems")) . "\n";
echo "user: " . json_encode($db->get_recordset("SELECT id,login,role_id FROM users WHERE login='rmreadonly'")) . "\n";
echo "role_rights(20): " . json_encode($db->get_recordset("SELECT right_id FROM role_rights WHERE role_id=20")) . "\n";

// 5. negative-control user: no reqmgrsystem rights at all (role 3 = <no rights>)
$hash2 = password_hash('rmguest', PASSWORD_DEFAULT);
$db->exec_query("INSERT INTO users (login,password,role_id,email,first,last,locale,default_testproject_id,active,cookie_string,auth_method)
  VALUES ('rmguest','" . $hash2 . "',3,'rmg@1585.local','No','ReqMgr','en_GB',1,1,'ck_rmg1585','DB')
  ON DUPLICATE KEY UPDATE password=VALUES(password), role_id=3, active=1, default_testproject_id=1, auth_method='DB'");
echo "guest: " . json_encode($db->get_recordset("SELECT id,login,role_id FROM users WHERE login='rmguest'")) . "\n";
