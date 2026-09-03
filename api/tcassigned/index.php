<?php
/**
 * Test Cases Assigned to User BFF API
 * URL: /api/tcassigned/index.php
 * Plain PHP, no framework, no compilation
 *
 * Modernizes the legacy popup lib/testcases/tcAssignedToUser.php, as opened
 * (800x600 popup) from gui/templates/results/resultsByTesterPerBuild.html
 * when clicking a tester name. It shows the test cases whose execution is
 * assigned to ONE user, filtered by the current test plan and build, with
 * the last-execution status per row and the legacy quick-execution icons.
 *
 * Routes:
 *   GET  ?action=init&tproject_id=N&tplan_id=M&build_id=B&user_id=U
 *        -> the single-user assignment rows grouped per test plan, each row
 *           annotated with the last execution status on that build/platform,
 *           exec rights, platform/priority columns and the due-since age.
 *   POST ?action=quick_exec
 *        -> quick execution write-back (mirrors the legacy inline
 *           INSERT INTO executions for the exact tcversion/build/platform
 *           triple). Guards: session user, 'testplan_execute' on the owning
 *           (project, plan) and the standard status whitelist.
 *
 * Rights: 'testplan_metrics' on the owning test project for init (same right
 * that gates the ASIDE assigned_tc_overview report the popup is opened
 * from); 'testplan_execute' for quick_exec.
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || $userId <= 0) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'User not found']);
    exit;
}

function out($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}
function bffBody() {
    static $body = null;
    if ($body === null) {
        $j = json_decode(file_get_contents('php://input'), true);
        $body = is_array($j) ? $j : [];
    }
    return $body;
}
function getParam($key, $default = '') {
    if (isset($_GET[$key])) { return is_string($_GET[$key]) ? trim($_GET[$key]) : $_GET[$key]; }
    if (isset($_POST[$key])) { return is_string($_POST[$key]) ? trim($_POST[$key]) : $_POST[$key]; }
    $body = bffBody();
    if (isset($body[$key])) { return $body[$key]; }
    return $default;
}

$tprojectId = intval(getParam('tproject_id', 0));
$tplanId = intval(getParam('tplan_id', 0));
$buildId = intval(getParam('build_id', 0));
$action = getParam('action');

$tprojectMgr = new testproject($db);
$tplanMgr = new testplan($db);

if ($action === 'init') {
    if ($tprojectId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test project id']);
    }
    $proj = $tprojectMgr->get_by_id($tprojectId);
    if (is_null($proj) || !isset($proj['name'])) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test project id']);
    }

    if (!$user->hasRight($db, 'testplan_metrics', $tprojectId,
            $tplanId > 0 ? $tplanId : null)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }

    // Filter user: legacy init_args() resolves user_id -> tlUser else the
    // session user. TL_USER_ANYBODY (overview) is NOT used here: this popup
    // is always scoped to a single concrete user.
    $filterUserId = intval(getParam('user_id', 0));
    if ($filterUserId <= 0) {
        $filterUserId = intval($userId);
    }
    $filterUser = null;
    if ($filterUserId > 0) {
        $tmpUser = new tlUser($filterUserId);
        $tmpUser->readFromDB($db);
        $filterUser = $tmpUser->login;
    }

    // Mirror initFilters(): a specific build overrides build/tplan status.
    $filters = [
        'tplan_status' => 'active',
        'build_status' => 'open',
    ];
    if ($buildId > 0) {
        $filters['build_id'] = $buildId;
        $filters['build_status'] = 'all';
        $filters['tplan_status'] = 'all';
    }

    require_once(__DIR__ . '/../../lib/functions/testcase.class.php');
    $tcaseMgr = new testcase($db);
    $tplan_param = $tplanId > 0 ? [$tplanId] : testcase::ALL_TESTPLANS;
    $resultSet = $tcaseMgr->get_assigned_to_user(
        $filterUserId, $tprojectId, $tplan_param,
        ['mode' => 'full_path'], $filters);

    $execCfg = config_get('exec_cfg');
    $testerModeRestrict =
        (isset($execCfg->exec_mode->tester)
            && $execCfg->exec_mode->tester == 'assigned_to_me');

    $resultsCfg = config_get('results');
    $statusCodeMap = $resultsCfg['status_code'];
    $codeStatusLabel = [];
    foreach ($statusCodeMap as $stName => $stCode) {
        if (isset($resultsCfg['status_label'][$stName])) {
            $codeStatusLabel[$stCode] = $stName;
        }
    }

    $projOpts = $proj['opt'] ?? null;
    if (is_null($projOpts) && !empty($proj['options'])) {
        $projOpts = json_decode($proj['options']);
    }
    $priorityEnabled = is_object($projOpts)
        ? !empty($projOpts->testPriorityEnabled)
        : (is_array($projOpts) ? !empty($projOpts['testPriorityEnabled']) : false);

    $payload = [
        'status' => 'ok',
        'hasData' => false,
        'tproject_id' => $tprojectId,
        'tproject_name' => $proj['name'],
        'tplan_id' => $tplanId,
        'build_id' => $buildId,
        'user_id' => $filterUserId,
        'user_login' => (string)$filterUser,
        'glue_char' => config_get('testcase_cfg')->glue_character,
        'priority_enabled' => $priorityEnabled,
        'tplans' => [],
    ];

    if (!is_null($resultSet)) {
        $nhTable = tlObjectWithDB::getDBTables(['nodes_hierarchy']);
        $planIds = array_map('intval', array_keys($resultSet));
        $tplanNames = [];
        if (!empty($planIds)) {
            $sql = 'SELECT name,id FROM ' . $nhTable['nodes_hierarchy'] .
                   ' WHERE id IN (' . implode(',', $planIds) . ')';
            $tplanNames = $db->fetchRowsIntoMap($sql, 'id');
        }

        foreach ($resultSet as $tplan_id => $tcase_set) {
            $hasExecRight = ($user->hasRight(
                $db, 'testplan_execute', $tprojectId, $tplan_id, true) == 'yes');

            $platforms = $tplanMgr->getPlatforms($tplan_id, ['outputFormat' => 'map']);
            $showPlatforms = !is_null($platforms);

            $rowsOut = [];
            foreach ($tcase_set as $tcase_platform) {
                foreach ($tcase_platform as $tcase) {
                    $tcase_id = intval($tcase['testcase_id']);
                    $tcversion_id = intval($tcase['tcversion_id']);

                    $canExec = $hasExecRight;
                    if ($canExec && $testerModeRestrict) {
                        $canExec = (intval($tcase['user_id']) === intval($userId));
                    }

                    $lexec = $tcaseMgr->get_last_execution(
                        $tcase_id, $tcversion_id, $tplan_id,
                        $tcase['build_id'], $tcase['platform_id'],
                        ['getSteps' => 0]);
                    $status = isset($lexec[$tcversion_id]['status'])
                        ? $lexec[$tcversion_id]['status'] : '';
                    if (!isset($codeStatusLabel[$status])) {
                        $status = $statusCodeMap['not_run'];
                    }

                    $creationTs = $tcase['creation_ts'];
                    $tsEpoch = is_string($creationTs) ? strtotime($creationTs) : $creationTs;

                    $row = [
                        'build_id' => intval($tcase['build_id']),
                        'build_name' => $tcase['build_name'],
                        'suite_path' => $tcase['tcase_full_path'],
                        'tc_id' => $tcase_id,
                        'tcversion_id' => $tcversion_id,
                        'prefix' => $tcase['prefix'],
                        'tc_external_id' => intval($tcase['tc_external_id']),
                        'name' => $tcase['name'],
                        'version' => intval($tcase['version']),
                        'tplan_id' => intval($tcase['testplan_id']),
                        'status' => $status,
                        'status_key' => isset($codeStatusLabel[$status])
                            ? $codeStatusLabel[$status] : 'not_run',
                        'creation_ts_epoch' => $tsEpoch ? intval($tsEpoch) : 0,
                        'age_days' => $tsEpoch
                            ? intval(floor((time() - $tsEpoch) / 86400)) : 0,
                        'can_exec' => $canExec,
                    ];
                    if ($showPlatforms) {
                        $row['platform_id'] = intval($tcase['platform_id']);
                        $row['platform_name'] = $tcase['platform_name'];
                    }
                    if ($priorityEnabled) {
                        $prio = intval($tcase['priority']);
                        $level = ($prio >= HIGH) ? 'high'
                            : (($prio >= MEDIUM) ? 'medium' : 'low');
                        $row['priority'] = $prio;
                        $row['priority_level'] = $level;
                    }
                    $rowsOut[] = $row;
                }
            }

            $payload['tplans'][] = [
                'id' => intval($tplan_id),
                'name' => isset($tplanNames[$tplan_id]['name'])
                    ? $tplanNames[$tplan_id]['name'] : '',
                'show_platforms' => $showPlatforms,
                'priority_enabled' => $priorityEnabled,
                'has_exec_right' => $hasExecRight,
                'rows' => $rowsOut,
            ];
        }
        usort($payload['tplans'], function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });
        foreach ($payload['tplans'] as $tp) {
            if (count($tp['rows']) > 0) { $payload['hasData'] = true; break; }
        }
    }
    out($payload);
}

// ---------------------------------------------------------------------------
// Quick execution write-back (legacy inline INSERT into executions).
// Records a result row for the exact tcversion/build/platform triple of one
// assigned execution. The tester recorded is ALWAYS the clicking user.
// ---------------------------------------------------------------------------
if ($action === 'quick_exec') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        out(['status' => 'error', 'message' => 'POST required']);
    }

    $qeTplan = intval(getParam('tplan_id', 0));
    $qePlatform = intval(getParam('platform_id', 0));
    $qeBuild = intval(getParam('build_id', 0));
    $qeTcversion = intval(getParam('tcversion_id', 0));
    $qeResult = trim(strval(getParam('result', '')));

    if ($qeTplan <= 0 || $qeBuild <= 0 || $qeTcversion <= 0 || $qeResult === '') {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Missing parameters']);
    }

    $qePlanInfo = $tplanMgr->get_by_id($qeTplan);
    $qeProjId = intval($qePlanInfo['testproject_id'] ?? 0);
    if (!$user->hasRight($db, 'testplan_execute', $qeProjId, $qeTplan)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }

    $allowedStatus = ['p', 'f', 'b'];
    if (!in_array($qeResult, $allowedStatus, true)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid result status']);
    }

    $execCfgQe = config_get('exec_cfg');
    if (isset($execCfgQe->exec_mode->tester)
            && $execCfgQe->exec_mode->tester == 'assigned_to_me') {
        $tptcvTable = tlObjectWithDB::getDBTables(['testplan_tcversions']);
        $uaTable = tlObjectWithDB::getDBTables(['user_assignments']);
        $sql = "SELECT COUNT(*) AS cnt FROM " .
               $uaTable['user_assignments'] . " ua " .
               "JOIN " . $tptcvTable['testplan_tcversions'] . " tptcv " .
               "ON ua.feature_id = tptcv.id " .
               "WHERE ua.type = 1 AND ua.user_id = " . intval($userId) .
               " AND tptcv.testplan_id = " . intval($qeTplan) .
               " AND tptcv.tcversion_id = " . intval($qeTcversion) .
               " AND tptcv.platform_id = " . intval($qePlatform) .
               " AND ua.build_id = " . intval($qeBuild);
        $rs = $db->get_recordset($sql);
        if (empty($rs) || intval($rs[0]['cnt']) === 0) {
            http_response_code(403);
            out(['status' => 'error',
                 'message' => 'Execution restricted to assigned tester']);
        }
    }

    $tables = tlObjectWithDB::getDBTables(['nodes_hierarchy', 'executions', 'tcversions']);
    $xx = $db->get_recordset(
        ' SELECT TCV.version FROM ' . $tables['tcversions'] .
        ' TCV WHERE TCV.id = ' . intval($qeTcversion));
    if (empty($xx)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid tcversion id']);
    }

    $sql = ' INSERT INTO ' . $tables['executions'] .
           ' (status,tester_id,execution_ts,tcversion_id,tcversion_number,' .
           ' testplan_id,platform_id,build_id) VALUES ' .
           " ('" . $qeResult . "', " . intval($userId) . ", " . $db->db_now() .
           ", " . intval($qeTcversion) . ", " . intval($xx[0]['version']) .
           ", " . intval($qeTplan) . ", " . intval($qePlatform) .
           ", " . intval($qeBuild) . ")";
    $db->exec_query($sql);

    out(['status' => 'ok']);
}

http_response_code(400);
out(['status' => 'error', 'message' => 'Unknown action']);
