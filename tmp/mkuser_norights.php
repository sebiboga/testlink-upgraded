<?php
// Creates the role-3 (no rights) user used to verify the 403 path of the
// Bug Delete BFF/screen. Run: php tmp/mkuser_norights.php
require_once('config.inc.php');
require_once('common.php');
$db = new database(DB_TYPE);
doDBConnect($db);
$hash = password_hash('norights', PASSWORD_DEFAULT);
$db->exec_query("INSERT INTO users (login,password,role_id,email,first,last,locale,default_testproject_id,active,cookie_string,auth_method) " .
    "VALUES ('norights','" . $hash . "',3,'n@n.no','No','Rights','en_GB',0,1,'ck_norights_1559','TestLink') " .
    "ON DUPLICATE KEY UPDATE password=VALUES(password), role_id=3, active=1");
$rs = $db->get_recordset("SELECT id,login,role_id,active FROM users WHERE login='norights'");
echo 'user norights: ' . json_encode($rs) . "\n";