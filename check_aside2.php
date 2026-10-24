<?php
require_once('config.inc.php');
require_once('lib/functions/database.class.php');
require_once('lib/functions/tlUser.class.php');
require_once('lib/functions/common.php');
require_once('lib/functions/object.class.php');

doSessionStart();
$db = new database(DB_TYPE);
doDBConnect($db);

$user = tlUser::getByID($db, 1);
$_SESSION['userID'] = $user->dbID;
$_SESSION['currentUser'] = $user;
$_SESSION['testprojectID'] = 1;
$_SESSION['testplanID'] = 1;

$ctx = new stdClass();
$ctx->tproject_id = 1;
$ctx->tplan_id = 1;
list($uxArgs, $ux) = initUserEnv($db, $ctx);
$uri = (property_exists($ux, 'uri') && is_object($ux->uri)) ? $ux->uri : new stdClass();

var_dump(get_object_vars($uri));
