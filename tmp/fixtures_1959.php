<?php
// Fixture for the Koa11y accessibility scan results screen
// (gui/templates/accessibility/a11yScans.html + api/a11yscans/index.php).
// Refs #1959 / #1058.
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);
$tprojMgr = new testproject($db);
$tplanMgr = new testplan($db);
$tsuiteMgr = new testsuite($db);
$tcaseMgr = new testcase($db);
$tables = tlObject::getDBTables();

function mk1959Project($mgr, $name, $prefix)
{
    foreach ((array)$mgr->get_by_name($name) as $row) {
        $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
        if ($oid > 0) { $mgr->delete($oid, 1); }
    }
    $item = new stdClass();
    $item->name = $name; $item->prefix = $prefix; $item->notes = 'fixture #1959';
    $item->color = ''; $item->active = 1; $item->is_public = 1;
    $opts = new stdClass();
    $opts->requirementsEnabled = 0; $opts->testPriorityEnabled = 0;
    $opts->automationEnabled = 0; $opts->inventoryEnabled = 0;
    $opts->platformsEnabled = 0; $opts->testcasecfEnabled = 0;
    $opts->requirementcfEnabled = 0;
    $item->options = $opts;
    $id = intval($mgr->create($item));
    if ($id <= 0) { die("project create failed: $name\n"); }
    $mgr->setActive($id);
    echo "tproject $name=$id\n";
    return $id;
}

$idP = mk1959Project($tprojMgr, 'A11Y1959', 'AY');
$idEmpty = mk1959Project($tprojMgr, 'A11Y1959 EMPTY', 'AZ');
$idPlan = intval($tplanMgr->create('A11Y Plan', '', $idP, 1, 1));
echo "plan=$idPlan\n";

$retS = $tsuiteMgr->create($idP, 'AY Root', '', null, 0, 'allow_repeat');
$idRoot = intval($retS['id'] ?? 0);

function mk1959Steps() {
    $s = array();
    $t = new stdClass();
    $t->step_number = 1;
    $t->actions = 'Open the target page';
    $t->expected_results = 'Page is accessible';
    $t->execution_type = TESTCASE_EXECUTION_TYPE_MANUAL;
    $s[] = $t;
    return $s;
}
$ret = $tcaseMgr->create($idRoot, 'AY Login page a11y', 'scan the login page', '',
    mk1959Steps(), 1, '', testcase::DEFAULT_ORDER, testcase::AUTOMATIC_ID,
    TESTCASE_EXECUTION_TYPE_MANUAL);
if (!$ret['status_ok']) { die("tcase failed\n"); }
$tcId = intval($ret['id']); $tcvId = intval($ret['tcversion_id']);
echo "tcase=$tcId version=$tcvId\n";

$db->exec_query("INSERT INTO {$tables['builds']}
    (testproject_id, name, notes, active, is_open, author_id, creation_ts)
  VALUES ($idP, 'A11Y Build 1', 'fixture build', 1, 1, 1, NOW())");
$build1 = intval($db->insert_id($tables['builds']));
$db->exec_query("INSERT INTO {$tables['builds']}
    (testproject_id, name, notes, active, is_open, author_id, creation_ts)
  VALUES ($idP, 'A11Y Build 2', 'fixture build 2', 1, 1, 1, NOW())");
$build2 = intval($db->insert_id($tables['builds']));
echo "builds=$build1,$build2\n";

$TBU = tlObjectWithDB::getDBTables(array('users'));
$hash = password_hash('a11ynorights', PASSWORD_DEFAULT);
$db->exec_query("INSERT INTO {$TBU['users']}
    (login, password, role_id, email, first, last, locale,
     default_testproject_id, active, cookie_string, auth_method)
  VALUES ('a11ynorights', '$hash', 3, 'n@no.no', 'No', 'Rights', 'en_GB', 0, 1,
          'ck_a11ynorights_1959', 'DB')
  ON DUPLICATE KEY UPDATE password=VALUES(password), role_id=3, active=1,
          auth_method=VALUES(auth_method)");
echo "user a11ynorights ready\n";
echo "DONE project=$idP empty=$idEmpty plan=$idPlan build1=$build1 build2=$build2 tc=$tcId tcv=$tcvId\n";
