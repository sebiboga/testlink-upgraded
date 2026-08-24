<?php
/**
 * My Test Case Assignments BFF API
 * URL: /api/tcassignments/
 * Plain PHP, no framework, no compilation
 *
 * Mirrors lib/testcases/tcAssignedToUser.php (TestLink 1.9.20 behavior):
 * list test cases whose EXECUTION is assigned to a user, grouped per test
 * plan, with last execution status and quick-result actions.
 *
 * Rights (same as legacy screen):
 *   - reading rows  : any authenticated session; rows are limited to test
 *                     plans of one test project. Legacy only required a
 *                     valid session + tproject context (menu gate is
 *                     exec_testcases_assigned_to_me); the BFF enforces the
 *                     same menu right on every route.
 *   - quick results : testplan_execute on the target test plan (legacy UI
 *                     hid the icons without the right but its raw INSERT
 *                     endpoint never re-checked it — fixed here).
 *   - exec_mode->tester == 'assigned_to_me' additionally restricts quick
 *     results to assignments owned by the caller (legacy display parity).
 *
 * Routes:
 *   GET  /init?tproject_id=
 *   GET  /rows?tproject_id=&tplan_id=&build_id=&show_all_users=&show_closed_builds=&show_inactive_tplans=&user_id=
 *   POST /quick_result  {tproject_id,tplan_id,platform_id,build_id,tcversion_id,result}
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

$path = $_SERVER['PATH_INFO'] ?? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = preg_replace('#^/api/tcassignments(/index\.php)?#', '', $path);
$path = '/' . trim($path, '/');
$method = $_SERVER['REQUEST_METHOD'];
$segments = array_values(array_filter(explode('/', $path)));

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
function getBody() { return bffBody(); }
function getParam($key, $default = null) {
    if (isset($_GET[$key])) { return $_GET[$key]; }
    if (isset($_POST[$key])) { return $_POST[$key]; }
    $body = bffBody();
    if (isset($body[$key])) { return $body[$key]; }
    return $default;
}

/** Menu visibility right (aside.tpl: exec_testcases_assigned_to_me). */
function canView(&$user, &$db, $tproject_id) {
    return (bool)$user->hasRight($db, 'exec_testcases_assigned_to_me',
        $tproject_id);
}

/** Legacy right needed to write results (exec icons parity). */
function canExecute(&$user, &$db, $tproject_id, $tplan_id) {
    // 5th arg $getAccess=true = legacy parity (tcAssignedToUser.php):
    // without it the private-asset checks in tlUser::hasRight are skipped
    return (bool)$user->hasRight($db, 'testplan_execute',
        $tproject_id, $tplan_id, true);
}

function deny() {
    http_response_code(403);
    out(['status' => 'error', 'message' => 'Insufficient rights']);
}

function prioLevel($v) {
    if ($v <= 2) { return 'low'; }
    if ($v <= 4) { return 'medium'; }
    return 'high';
}

$tprojectMgr = new testproject($db);
$tcaseMgr = new testcase($db);
$resultsCfg = config_get('results');
$execCfg = config_get('exec_cfg');
$glueChar = config_get('testcase_cfg')->glue_character;

/* ------------------------------------------------------------------ */
/* Routes                                                              */
/* ------------------------------------------------------------------ */

// ---------------------------------------------------------------------
// GET /init?tproject_id=
// context for the screen: project name/flags, plans, users, session flag
// ---------------------------------------------------------------------
if ($method === 'GET' && count($segments) === 1 && $segments[0] === 'init') {
    $tproject_id = intval(getParam('tproject_id', 0));
    if ($tproject_id <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'tproject_id is required']);
    }
    if (!canView($user, $db, $tproject_id)) {
        deny();
    }
    $info = $tprojectMgr->get_by_id($tproject_id);
    if (!$info) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Test project not found']);
    }

    $opt = (array)$info['opt'];
    $priorityEnabled = !empty($opt['testPriorityEnabled']);

    // active plans first, then inactive ones (legacy default filters them out)
    // get_all_testplans() does not fetch is_open -> do not read it (E_WARNING)
    $planSet = $tprojectMgr->get_all_testplans($tproject_id);
    $plans = [];
    foreach ((array)$planSet as $pid => $tp) {
        $plans[] = ['id' => intval($pid), 'name' => $tp['name'],
                    'active' => intval($tp['active'] ?? 0) ? true : false,
                    'is_public' => intval($tp['is_public'] ?? 0) ? true : false];
    }

    // users with assignments potential: overview column shows logins
    $userNames = [];
    foreach ((array)$user->getNames($db) as $uid => $uinfo) {
        $userNames[] = ['id' => intval($uid), 'login' => $uinfo['login']];
    }

    out(['status' => 'ok', 'data' => [
        'tproject' => ['id' => intval($tproject_id), 'name' => $info['name'],
                       'priorityEnabled' => $priorityEnabled],
        'plans' => $plans,
        'users' => $userNames,
        'showClosedBuilds' =>
            isset($_SESSION['show_closed_builds'])
                ? (bool)intval($_SESSION['show_closed_builds']) : false,
        'me' => ['id' => intval($userId), 'login' => $user->getDisplayName()],
    ]]);
}

// ---------------------------------------------------------------------
// GET /rows?... -> assignment rows grouped per test plan
// ---------------------------------------------------------------------
if ($method === 'GET' && count($segments) === 1 && $segments[0] === 'rows') {
    $tproject_id = intval(getParam('tproject_id', 0));
    if ($tproject_id <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'tproject_id is required']);
    }
    if (!canView($user, $db, $tproject_id)) {
        deny();
    }
    $info = $tprojectMgr->get_by_id($tproject_id);
    if (!$info) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Test project not found']);
    }
    $opt = (array)$info['opt'];
    $priorityEnabled = !empty($opt['testPriorityEnabled']);

    $showAllUsers = intval(getParam('show_all_users', 0)) == 1;
    $showInactiveTplans = intval(getParam('show_inactive_tplans', 0)) == 1;
    $buildId = intval(getParam('build_id', 0));

    // legacy session persistence of show_closed_builds (init_args parity)
    if (!is_null(getParam('show_closed_builds', null))) {
        $selection = intval(getParam('show_closed_builds', 0)) == 1;
    } else if (isset($_SESSION['show_closed_builds'])) {
        $selection = intval($_SESSION['show_closed_builds']) ? true : false;
    } else {
        $selection = false;
    }
    $showClosedBuilds = $selection;
    $_SESSION['show_closed_builds'] = $showClosedBuilds;

    // legacy args parity: explicit user_id (direct links) or logged user;
    // overview mode queries ANYBODY.
    $filterUserId = intval(getParam('user_id', 0));
    if ($filterUserId <= 0) { $filterUserId = intval($userId); }
    if ($showAllUsers) { $filterUserId = TL_USER_ANYBODY; }

    $tplanId = intval(getParam('tplan_id', 0));
    $tplanParam = ($tplanId > 0) ? [$tplanId] : testcase::ALL_TESTPLANS;

    // initFilters() parity; an explicit tplan selection overrides the
    // tplan-status filter (same spirit as legacy build_id handling:
    // "show assignments regardless of build and tplan status")
    $filters = [];
    $filters['tplan_status'] =
        ($tplanId > 0 || $showInactiveTplans || $buildId > 0)
            ? 'all' : 'active';
    $filters['build_status'] = 'open';
    if ($showClosedBuilds && $buildId <= 0) { $filters['build_status'] = 'all'; }
    if ($buildId > 0) {
        $filters['build_id'] = $buildId;
        // show assignments regardless of build and tplan status
        $filters['build_status'] = 'all';
        $filters['tplan_status'] = 'all';
    }

    $rs = $tcaseMgr->get_assigned_to_user($filterUserId, $tproject_id,
        $tplanParam, ['mode' => 'full_path'], $filters);

    $groups = [];
    if (!is_null($rs)) {
        $tables = tlObjectWithDB::getDBTables(['nodes_hierarchy']);
        $tplanIds = array_keys($rs);
        $planNameRows = $db->fetchRowsIntoMap(
            "SELECT name,id FROM {$tables['nodes_hierarchy']} " .
            "WHERE id IN (" . implode(',', $tplanIds) . ")", 'id');

    $statusCodes = $resultsCfg['status_code'];
    $tplanMgrTmp = new testplan($db);

    foreach ($rs as $tplan_id => $tcaseSet) {
        $hasExecRight = canExecute($user, $db, $tproject_id, $tplan_id);

            $platformMap = $tplanMgrTmp->getPlatforms($tplan_id,
                ['outputFormat' => 'map']);
            // legacy checks !is_null(); an empty map means the plan has no
            // platforms at all -> no need to render an empty column
            $showPlatforms = !is_null($platformMap)
                && count((array)$platformMap) > 0;

            $rows = [];
            foreach ($tcaseSet as $tcasePlatform) {
                foreach ($tcasePlatform as $tcase) {
                    $tcaseId = intval($tcase['testcase_id']);
                    $tcversionId = intval($tcase['tcversion_id']);
                    $pId = intval($tcase['platform_id']);
                    $bId = intval($tcase['build_id']);

                    $lexec = $tcaseMgr->get_last_execution($tcaseId,
                        $tcversionId, $tplan_id, $bId, $pId, ['getSteps' => 0]);
                    $statusCode = isset($lexec[$tcversionId]['status'])
                        ? $lexec[$tcversionId]['status']
                        : $statusCodes['not_run'];
                    $statusKey = isset($resultsCfg['code_status'][$statusCode])
                        ? $resultsCfg['code_status'][$statusCode] : 'not_run';

                    $canExec = $hasExecRight;
                    if ($canExec
                        && $execCfg->exec_mode->tester == 'assigned_to_me') {
                        $canExec =
                            intval($tcase['user_id']) == intval($userId);
                    }

                    $creationTs = $tcase['creation_ts'];
                    $ts = is_string($creationTs) ? strtotime($creationTs)
                                                 : intval($creationTs);
                    $daysSince = ($ts > 0)
                        ? floor((time() - $ts) / 86400) : null;

                    $rows[] = [
                        'user_id' => intval($tcase['user_id']),
                        'user_login' => '',
                        'build_id' => $bId,
                        'build_name' => $tcase['build_name'],
                        'suite_path' => $tcase['tcase_full_path'],
                        'testcase_id' => $tcaseId,
                        'tcversion_id' => $tcversionId,
                        'prefix' => $tcase['prefix'],
                        'external_id' => intval($tcase['tc_external_id']),
                        'name' => $tcase['name'],
                        'version' => intval($tcase['version']),
                        'platform_id' => $pId,
                        'platform_name' => $tcase['platform_name'],
                        'priority_level' =>
                            prioLevel(intval($tcase['priority'])),
                        'status_key' => $statusKey,
                        'tester_login' =>
                            isset($lexec[$tcversionId]['tester_login'])
                                ? (string)$lexec[$tcversionId]['tester_login']
                                : '',
                        'days_since' => $daysSince,
                        'can_exec' => $canExec,
                    ];
                }
            }

            // resolve assignee logins in bulk (legacy used userSet map)
            if ($showAllUsers && count($rows) > 0) {
                $uids = [];
                foreach ($rows as $r) {
                    if ($r['user_id'] > 0) { $uids[$r['user_id']] = 1; }
                }
                $loginMap = [];
                if (count($uids) > 0) {
                    $uTables = tlObjectWithDB::getDBTables(['users']);
                    $ur = $db->fetchColumnsIntoMap(
                        "SELECT id,login FROM {$uTables['users']} " .
                        "WHERE id IN (" . implode(',', array_keys($uids)) . ")",
                        'id', 'login');
                    $loginMap = is_array($ur) ? $ur : [];
                }
                foreach ($rows as &$r) {
                    $r['user_login'] =
                        isset($loginMap[$r['user_id']])
                            ? $loginMap[$r['user_id']] : '';
                }
                unset($r);
            }

            $groups[] = [
                'tplan_id' => intval($tplan_id),
                'tplan_name' => isset($planNameRows[$tplan_id])
                    ? $planNameRows[$tplan_id]['name'] : '',
                'show_platforms' => $showPlatforms,
                'rows' => $rows,
            ];
        }
    }

    out(['status' => 'ok', 'data' => [
        'groups' => $groups,
        'priorityEnabled' => $priorityEnabled,
        'showAllUsers' => $showAllUsers,
        'showClosedBuilds' => $showClosedBuilds,
        'glueChar' => $glueChar,
        'statusKeys' => array_keys($statusCodes),
    ]]);
}

// ---------------------------------------------------------------------
// POST /quick_result -> write one execution row (legacy quick P/F/B).
// Legacy did an unchecked raw INSERT; here every id is validated against
// the plan linkage and the right is enforced server-side.
// ---------------------------------------------------------------------
if ($method === 'POST' && count($segments) === 1
    && $segments[0] === 'quick_result') {
    $b = getBody();
    $tproject_id = intval($b['tproject_id'] ?? 0);
    $tplan_id = intval($b['tplan_id'] ?? 0);
    $platform_id = intval($b['platform_id'] ?? 0);
    $build_id = intval($b['build_id'] ?? 0);
    $tcversion_id = intval($b['tcversion_id'] ?? 0);
    $resultKey = trim((string)($b['result'] ?? ''));

    if ($tproject_id <= 0 || $tplan_id <= 0 || $build_id <= 0
        || $tcversion_id <= 0) {
        http_response_code(400);
        out(['status' => 'error',
             'message' => 'tproject_id, tplan_id, build_id and tcversion_id'
                        . ' are required']);
    }
    if (!canExecute($user, $db, $tproject_id, $tplan_id)) {
        deny();
    }

    $statusCodes = $resultsCfg['status_code'];
    if (!isset($statusCodes[$resultKey])) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid result value']);
    }
    $statusCode = $statusCodes[$resultKey];

    // version must be linked to this plan (+ platform when given)
    $linkTables = tlObjectWithDB::getDBTables(['testplan_tcversions']);
    $sql = " SELECT id FROM {$linkTables['testplan_tcversions']} " .
           " WHERE testplan_id = {$tplan_id} " .
           " AND tcversion_id = {$tcversion_id} " .
           " AND platform_id = {$platform_id}";
    $link = $db->get_recordset($sql);
    if (!$link) {
        http_response_code(400);
        out(['status' => 'error',
             'message' => 'Version not linked to this test plan/platform']);
    }

    // build must belong to this plan
    $bTables = tlObjectWithDB::getDBTables(['builds']);
    $brow = $db->get_recordset(
        "SELECT id FROM {$bTables['builds']} " .
        " WHERE id = {$build_id} AND testplan_id = {$tplan_id}");
    if (!$brow) {
        http_response_code(400);
        out(['status' => 'error',
             'message' => 'Build does not belong to this test plan']);
    }

    $vTables = tlObjectWithDB::getDBTables(['tcversions']);
    $vrow = $db->get_recordset(
        " SELECT TCV.version FROM {$vTables['tcversions']} TCV" .
        " WHERE TCV.id = {$tcversion_id}");
    if (!$vrow) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Unknown tcversion_id']);
    }
    $versionNumber = intval($vrow[0]['version']);

    $eTables = tlObjectWithDB::getDBTables(['executions']);
    $safeStatus = str_replace("'", '', $statusCode);
    $sql = " INSERT INTO {$eTables['executions']} " .
           " (status,tester_id,execution_ts,tcversion_id,tcversion_number," .
           "  testplan_id,platform_id,build_id,execution_type,notes)" .
           " VALUES ('{$safeStatus}',{$userId}," . $db->db_now() .
           ",{$tcversion_id},{$versionNumber},{$tplan_id},{$platform_id}," .
           "{$build_id}," . TESTCASE_EXECUTION_TYPE_MANUAL . ",'')";
    $db->exec_query($sql);

    out(['status' => 'ok',
         'data' => ['result' => $resultKey, 'tcversion_id' => $tcversion_id]]);
}

http_response_code(404);
out(['status' => 'error', 'message' => 'Unknown route']);
