<?php
// Fixture for the Exit Criteria evaluation dashboard
// (gui/templates/strategy/exitCriteriaEvaluation.html + api/exitcriteriaevaluation/index.php).
//
// Refs #1953 (parent #1088).
//
// Creates TWO test projects:
//
//   tproject ECV1953 (prefix EV) -> the project under test
//     suite "EV Root"
//       tcase EV Pass        -> exec p (b1)
//       tcase EV Pass 2      -> exec p (b1)
//       tcase EV Fail        -> exec f (b1)
//       tcase EV Blocked     -> exec b (b1)
//       tcase EV Not Run     -> NO execution
//     plan "EV Plan" with all 5 versions assigned, platform 0
//     builds: EV Build 1 (open), EV Build 2 (open)
//     platform "EV Platform 1" -> 2 versions re-assigned with platform_id
//     exit criteria (7 rows, one per metric + one per level):
//       unit        EV Progress    metric=progress      target=80   -> met
//       unit        EV Pass Rate   metric=pass_rate     target=60   -> met
//       integration EV Failed Max  metric=failed        target=1    -> met (1 failed)
//       system      EV Blocked Max metric=blocked       target=0    -> not met (1 blocked)
//       system      EV Manual      metric=none          target=NULL -> manual
//       acceptance  EV No Target   metric=progress      target=NULL -> N/A
//       release     EV Coverage    metric=req_coverage  target=50   -> N/A (reqs disabled)
//   tproject ECV1953 EMPTY (prefix EM) -> no criteria at all -> empty state
//
//   user ecvnorights (role 3) -> the 403 path
//
// Run from repo root: php tmp/fixtures_1953.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$tplanMgr = new testplan($db);
$tsuiteMgr = new testsuite($db);
$tcaseMgr = new testcase($db);
$userId = 1; // admin

// ---------------------------------------------------------------------------
// Projects
// ---------------------------------------------------------------------------
function mk1953Project($tprojMgr, $name, $prefix, $notes, $reqsEnabled)
{
    foreach ((array)$tprojMgr->get_by_name($name) as $row) {
        $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
        if ($oid > 0) {
            echo "deleting old project $name ($oid)\n";
            $tprojMgr->delete($oid, 1);
        }
    }
    $item = new stdClass();
    $item->name = $name;
    $item->prefix = $prefix;
    $item->notes = $notes;
    $item->color = '';
    $item->active = 1;
    $item->is_public = 1;
    $opts = new stdClass();
    $opts->requirementsEnabled = $reqsEnabled;
    $opts->testPriorityEnabled = 0;
    $opts->automationEnabled = 0;
    $opts->inventoryEnabled = 0;
    $opts->platformsEnabled = 1;
    $opts->testcasecfEnabled = 0;
    $opts->requirementcfEnabled = 0;
    $item->options = $opts;
    $idP = intval($tprojMgr->create($item));
    if ($idP <= 0) {
        die("tproject create failed for $name\n");
    }
    $tprojMgr->setActive($idP);
    echo "tproject $name=$idP\n";
    return $idP;
}

$idP = mk1953Project($tprojMgr, 'ECV1953', 'EV',
    'fixture for #1953 (exit criteria evaluation)', 0);
$idEmpty = mk1953Project($tprojMgr, 'ECV1953 EMPTY', 'EM',
    'fixture for #1953 - project without exit criteria', 0);

$idPlan = intval($tplanMgr->create('EV Plan', '', $idP, 1, 1));
echo "testplan EV Plan=$idPlan\n";

$idPlanEmpty = intval($tplanMgr->create('EM Plan', '', $idEmpty, 1, 1));
echo "testplan EM Plan=$idPlanEmpty\n";

// ---------------------------------------------------------------------------
// Test cases
// ---------------------------------------------------------------------------
$retS = $tsuiteMgr->create($idP, 'EV Root', '', null, 0, 'allow_repeat');
$idRoot = intval($retS['id'] ?? 0);
echo "suite EV Root=$idRoot\n";

function mk1953Steps()
{
    $steps = array();
    for ($i = 1; $i <= 2; $i++) {
        $t = new stdClass();
        $t->step_number = $i;
        $t->actions = 'Do action number ' . $i;
        $t->expected_results = 'Expected result of step ' . $i;
        $t->execution_type = TESTCASE_EXECUTION_TYPE_MANUAL;
        $steps[] = $t;
    }
    return $steps;
}

$makeTcase = function ($name) use ($tcaseMgr, $idRoot, $userId) {
    $ret = $tcaseMgr->create($idRoot, $name, 'summary of ' . $name, '', mk1953Steps(),
        $userId, '', testcase::DEFAULT_ORDER, testcase::AUTOMATIC_ID,
        TESTCASE_EXECUTION_TYPE_MANUAL);
    if (!$ret['status_ok'] || $ret['id'] <= 0) {
        die("tcase create failed for $name: " . ($ret['message'] ?? '') . "\n");
    }
    return array(intval($ret['id']), intval($ret['tcversion_id']));
};

$cases = array('EV Pass', 'EV Pass 2', 'EV Fail', 'EV Blocked', 'EV Not Run');
$versions = array();
foreach ($cases as $c) {
    list($tc, $tcv) = $makeTcase($c);
    $versions[$c] = array('tc' => $tc, 'tcv' => $tcv);
    echo "tcase $c tc=$tc version=$tcv\n";
}

$tables = tlObject::getDBTables();

// ---------------------------------------------------------------------------
// Platform
// ---------------------------------------------------------------------------
$db->exec_query("INSERT INTO {$tables['platforms']}
    (name, testproject_id, notes, enable_on_design, enable_on_execution, is_open)
  VALUES ('EV Platform 1', $idP, 'fixture platform', 1, 1, 1)");
$platform1 = intval($db->insert_id($tables['platforms']));
echo "platform EV Platform 1=$platform1\n";

// ---------------------------------------------------------------------------
// Plan assignment: 5 versions on platform 0, 2 of them ALSO on platform 1
// (so the platform filter changes the universe).
// ---------------------------------------------------------------------------
foreach ($versions as $cname => $v) {
    $db->exec_query(
        "INSERT INTO {$tables['testplan_tcversions']}
            (testplan_id, author_id, creation_ts, tcversion_id, platform_id, node_order)
         VALUES ($idPlan, $userId, NOW(), " . intval($v['tcv']) . ", 0, 1)");
}
$db->exec_query(
    "INSERT INTO {$tables['testplan_tcversions']}
        (testplan_id, author_id, creation_ts, tcversion_id, platform_id, node_order)
     VALUES ($idPlan, $userId, NOW(), " . intval($versions['EV Pass']['tcv']) . ", $platform1, 1)");
$db->exec_query(
    "INSERT INTO {$tables['testplan_tcversions']}
        (testplan_id, author_id, creation_ts, tcversion_id, platform_id, node_order)
     VALUES ($idPlan, $userId, NOW(), " . intval($versions['EV Fail']['tcv']) . ", $platform1, 1)");
echo "assigned " . (count($versions) + 2) . " assignment rows to plan $idPlan\n";

// ---------------------------------------------------------------------------
// Builds + executions
// ---------------------------------------------------------------------------
function mk1953Build($db, $tables, $idP, $name)
{
    $db->exec_query("INSERT INTO {$tables['builds']}
        (testproject_id, name, notes, active, is_open, author_id, creation_ts)
      VALUES ($idP, '$name', 'fixture build', 1, 1, 1, NOW())");
    return intval($db->insert_id($tables['builds']));
}
$build1 = mk1953Build($db, $tables, $idP, 'EV Build 1');
$build2 = mk1953Build($db, $tables, $idP, 'EV Build 2');
echo "builds b1=$build1 b2=$build2\n";

function mk1953Exec($db, $tables, $planId, $buildId, $tcv, $status, $ts, $platformId = 0)
{
    $db->exec_query(
        "INSERT INTO {$tables['executions']}
            (build_id, tester_id, execution_ts, status, testplan_id, tcversion_id,
             tcversion_number, platform_id, execution_type, execution_duration)
         VALUES (" . intval($buildId) . ", 1, '$ts', '$status', " . intval($planId) . ", "
        . intval($tcv) . ", 1, " . intval($platformId) . ", 1, 0)");
    return intval($db->insert_id($tables['executions']));
}

$T = $tables;
mk1953Exec($db, $T, $idPlan, $build1, $versions['EV Pass']['tcv'], 'p', '2026-01-05 10:00:00');
mk1953Exec($db, $T, $idPlan, $build1, $versions['EV Pass 2']['tcv'], 'p', '2026-01-05 10:05:00');
mk1953Exec($db, $T, $idPlan, $build1, $versions['EV Fail']['tcv'], 'f', '2026-01-05 10:10:00');
mk1953Exec($db, $T, $idPlan, $build1, $versions['EV Blocked']['tcv'], 'b', '2026-01-05 10:15:00');
// EV Not Run has NO execution -> not_run.
// Platform 1 executions (the platform-filter universe).
mk1953Exec($db, $T, $idPlan, $build1, $versions['EV Pass']['tcv'], 'p', '2026-01-05 11:00:00', $platform1);
mk1953Exec($db, $T, $idPlan, $build1, $versions['EV Fail']['tcv'], 'p', '2026-01-05 11:05:00', $platform1);
// On build 2 the "EV Pass" version FAILS -> the build filter must flip the result.
mk1953Exec($db, $T, $idPlan, $build2, $versions['EV Pass']['tcv'], 'f', '2026-01-06 11:00:00');
echo "executions written\n";

// ---------------------------------------------------------------------------
// Exit criteria (the table is created lazily by the BFF, so create it here
// with the same DDL when it is still missing).
// ---------------------------------------------------------------------------
$t = tlObjectWithDB::getDBTables(array('tc_exit_criteria'));
$tct = $t['tc_exit_criteria'];
$db->exec_query(
    "CREATE TABLE IF NOT EXISTS {$tct} (" .
    " id INT UNSIGNED NOT NULL AUTO_INCREMENT," .
    " testproject_id INT UNSIGNED NOT NULL DEFAULT 0," .
    " name VARCHAR(255) NOT NULL DEFAULT ''," .
    " test_level VARCHAR(32) NOT NULL DEFAULT 'system'," .
    " entry_criteria TEXT NULL," .
    " exit_criteria TEXT NULL," .
    " metric VARCHAR(32) NOT NULL DEFAULT 'none'," .
    " target_value DECIMAL(10,2) NULL," .
    " created_by INT UNSIGNED NULL," .
    " creation_ts TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP," .
    " update_ts DATETIME NULL," .
    " PRIMARY KEY (id)," .
    " KEY idx_ec_project (testproject_id)" .
    ") ENGINE=InnoDB DEFAULT CHARSET=utf8");

$criteria = array(
    array('EV Progress',    'unit',        'progress',     '80', 'Entry: design reviewed', 'Exit: 80% of the plan executed'),
    array('EV Pass Rate',   'unit',        'pass_rate',    '60', 'Entry: suite ready',    'Exit: 60% of executed cases pass'),
    array('EV Failed Max',  'integration', 'failed',       '1',  'Entry: API deployed',   'Exit: at most 1 failed case'),
    array('EV Blocked Max', 'system',      'blocked',      '0',  'Entry: env stable',     'Exit: no blocked case'),
    array('EV Manual',      'system',      'none',         null, 'Entry: sign-off',       'Exit: the board signs off'),
    array('EV No Target',   'acceptance',  'progress',     null, 'Entry: UAT ready',      'Exit: UAT executed'),
    array('EV Coverage',    'release',     'req_coverage', '50', 'Entry: release candidate', 'Exit: 50% requirement coverage'),
);
$sql = array();
foreach ($criteria as $c) {
    $target = ($c[3] === null) ? 'NULL' : floatval($c[3]);
    $sql[] = "($idP, '" . $db->prepare_string($c[0]) . "', '" . $db->prepare_string($c[1]) .
        "', '" . $db->prepare_string($c[4]) . "', '" . $db->prepare_string($c[5]) . "', '" .
        $db->prepare_string($c[2]) . "', $target, $userId, NOW())";
}
$db->exec_query("INSERT INTO {$tct} (testproject_id, name, test_level, entry_criteria, " .
    "exit_criteria, metric, target_value, created_by, update_ts) VALUES " . implode(',', $sql));
echo "exit criteria rows: " . count($criteria) . "\n";

// ---------------------------------------------------------------------------
// A user with NO rights at all (role 3) -> the 403 path.
// ---------------------------------------------------------------------------
$TBU = tlObjectWithDB::getDBTables(array('users'));
$hash = password_hash('ecvnorights', PASSWORD_DEFAULT);
$db->exec_query("INSERT INTO {$TBU['users']}
    (login, password, role_id, email, first, last, locale,
     default_testproject_id, active, cookie_string, auth_method)
  VALUES ('ecvnorights', '$hash', 3, 'n@no.no', 'No', 'Rights', 'en_GB', 0, 1,
          'ck_ecvnorights_1953', 'DB')
  ON DUPLICATE KEY UPDATE password=VALUES(password), role_id=3, active=1,
          auth_method=VALUES(auth_method)");
echo "user ecvnorights ready (role 3)\n";

echo "DONE. ids: project=$idP emptyProject=$idEmpty plan=$idPlan planEmpty=$idPlanEmpty " .
    "build1=$build1 build2=$build2 platform1=$platform1\n";
