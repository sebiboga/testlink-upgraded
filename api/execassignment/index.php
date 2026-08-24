<?php
/**
 * Assign Test Case Execution BFF API
 * URL: /api/execassignment/
 * Plain PHP, no framework, no compilation
 *
 * Mirrors lib/plan/tc_exec_assignment.php (TestLink 1.9.20 behavior):
 * list linked test cases of a test plan with their per-build tester
 * assignments, assign/remove execution tasks.
 *
 * Rights (same as legacy screen):
 *   everything -> exec_assign_testcases (pageAccessCheck rightsAnd gate)
 *
 * Assignment semantics copied from the legacy controller:
 *   - assign      : assignment_mgr::assign() rows with type=testcase_execution,
 *                   status=open, creation_ts=NOW, assigner=current user
 *   - remove      : assignment_mgr::deleteBySignature()
 *   - removeAll   : assignment_mgr::delete_by_feature_id_and_build_id()
 *   - bulkRemove  : deleteBySignature() for each checked-feature x user combo
 *   - sendLink    : assignment_mgr::emailLinkToExecPlanning()
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
$path = preg_replace('#^/api/execassignment(/index\.php)?#', '', $path);
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

/**
 * Resolve context exactly like the legacy screen session settings:
 * plan must live inside the given project.
 */
function needCtx(&$tplanMgr) {
    $tproject_id = intval(getParam('tproject_id', 0));
    $tplan_id = intval(getParam('tplan_id', 0));
    if ($tproject_id <= 0 || $tplan_id <= 0) {
        http_response_code(400);
        out(['status' => 'error',
             'message' => 'tproject_id and tplan_id are required']);
    }
    $info = $tplanMgr->get_by_id($tplan_id);
    if (!$info || intval($info['testproject_id']) != $tproject_id) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Test plan not found']);
    }
    return [$tproject_id, $tplan_id, $info];
}

/** Legacy right enforced on every route (tc_exec_assignment.php parity). */
function canAssign(&$user, &$db, $tproject_id, $tplan_id) {
    return (bool)$user->hasRight($db, 'exec_assign_testcases',
        $tproject_id, $tplan_id);
}

function deny() {
    http_response_code(403);
    out(['status' => 'error', 'message' => 'Insufficient rights']);
}

/** priority level label from importance x urgency (1..6). */
function prioLevel($v) {
    if ($v <= 2) { return 'low'; }
    if ($v <= 4) { return 'medium'; }
    return 'high';
}

function userLabel($u) {
    $full = trim((string)$u['first'] . ' ' . (string)$u['last']);
    return $full !== '' ? $u['login'] . ' (' . $full . ')' : (string)$u['login'];
}

$tplanMgr = new testplan($db);
$assignMgr = new assignment_mgr($db);
$typesMap = $assignMgr->get_available_types();
$statusMap = $assignMgr->get_available_status();
$execType = intval($typesMap['testcase_execution']['id']);
$openStatus = intval($statusMap['open']['id']);

/* ------------------------------------------------------------------ */
/* Routes                                                              */
/* ------------------------------------------------------------------ */

// ---------------------------------------------------------------------
// GET /init?tproject_id=&tplan_id=
// picker data: builds (active+open), platforms, testers, rights, flags
// ---------------------------------------------------------------------
if ($method === 'GET' && count($segments) === 1 && $segments[0] === 'init') {
    list($tproject_id, $tplan_id, $tplanInfo) = needCtx($tplanMgr);
    if (!canAssign($user, $db, $tproject_id, $tplan_id)) {
        deny();
    }

    $platformMgr = new tlPlatform($db, $tproject_id);

    $usePlatforms = $platformMgr->platformsActiveForTestplan($tplan_id);
    $platSet = $platformMgr->getLinkedToTestplanAsMap($tplan_id);
    $platforms = [];
    foreach ((array)$platSet as $pid => $pname) {
        $platforms[] = ['id' => intval($pid), 'name' => (string)$pname];
    }

    // active & open builds, newest first (legacy defaults to the newest
    // open build chosen in the execution navigator panel)
    $buildSet = $tplanMgr->get_builds($tplan_id, 1, 1);
    $builds = [];
    foreach ((array)$buildSet as $bid => $binfo) {
        // get_builds() exposes 'is_open' only; closed == !is_open
        $closed = isset($binfo['is_closed'])
            ? intval($binfo['is_closed']) : intval(empty($binfo['is_open']));
        $builds[] = ['id' => intval($bid), 'name' => (string)$binfo['name'],
                     'closed' => $closed];
    }

    $tprojMgr = new testproject($db);
    $tprojOpt = $tprojMgr->getOptions($tproject_id);
    $priorityEnabled = false;
    if (!is_null($tprojOpt) && isset($tprojOpt->testPriorityEnabled)) {
        $priorityEnabled = (bool)$tprojOpt->testPriorityEnabled;
    }

    // get_tplan_effective_role() needs the FULL tproject record, not a
    // minimal ['id','name'] array — otherwise tplan-scoped role resolution
    // silently drops users and the tester pickers end up empty.
    $tprojFull = $tprojMgr->get_by_id($tproject_id);
    if (is_null($tprojFull)) {
        out(['status' => 'error', 'message' => 'testproject not found'], 404);
    }
    $allUsers = tlUser::getAll($db, null, "id", null);
    $testerMap = getTestersForHtmlOptions($db, $tplan_id, $tprojFull,
                                          $allUsers);
    $testers = [];
    foreach ((array)$testerMap as $tid => $label) {
        if (intval($tid) <= 0) { continue; } // skip "please select" entry
        $testers[] = ['id' => intval($tid), 'label' => (string)$label];
    }

    $tcaseCfg = config_get('testcase_cfg');
    $prefix = $tprojMgr->getTestCasePrefix($tproject_id) .
              $tcaseCfg->glue_character;

    out([
        'status' => 'ok',
        'rights' => ['canAssign' => true],
        'tproject' => ['id' => $tproject_id,
                       'name' => testproject::getName($db, $tproject_id)],
        'tplan' => ['id' => $tplan_id, 'name' => (string)$tplanInfo['name']],
        'usePlatforms' => (bool)$usePlatforms,
        'platforms' => $platforms,
        'builds' => $builds,
        'testers' => $testers,
        'priorityEnabled' => $priorityEnabled,
        'testCasePrefix' => $prefix,
    ]);
}

// ---------------------------------------------------------------------
// GET /items?tproject_id=&tplan_id=&build_id=[&platform_id=]
// flat list of linked test cases with per-row assignment info
// ---------------------------------------------------------------------
if ($method === 'GET' && count($segments) === 1 && $segments[0] === 'items') {
    list($tproject_id, $tplan_id, $tplanInfo) = needCtx($tplanMgr);
    if (!canAssign($user, $db, $tproject_id, $tplan_id)) {
        deny();
    }
    $build_id = intval(getParam('build_id', 0));
    if ($build_id <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid build id']);
    }
    // platform filter 0 = all platforms (legacy bulk_platforms "All")
    $platformFilter = intval(getParam('platform_id', 0));

    $T = tlObject::getDBTables();
    $nh = $T['nodes_hierarchy'];

    $platJoin = '';
    $platWhere = '';
    if ($platformFilter > 0) {
        $platWhere = " AND TPTCV.platform_id = {$platformFilter}";
    }

    $sql = "SELECT TPTCV.id AS feature_id, TPTCV.tcversion_id, " .
           " TPTCV.platform_id, TPTCV.node_order AS link_order, " .
           " COALESCE(TPTCV.urgency,2) AS urgency, " .
           " NHTCV.parent_id AS tcase_id, NHTC.name AS tcase_name, " .
           " NHTC.node_order AS tc_order, " .
           " TCVA.version, TCVA.active, TCVA.importance, " .
           " TCVA.tc_external_id, " .
           " NHTS.id AS tsuite_id, NHTS.name AS tsuite_name, " .
           " UA.user_id AS assigned_user_id, " .
           " COALESCE(UA.id, -TPTCV.id) AS ua_row " .
           " FROM {$T['testplan_tcversions']} TPTCV " .
           " JOIN {$nh} NHTCV ON NHTCV.id = TPTCV.tcversion_id " .
           " JOIN {$T['tcversions']} TCVA ON TCVA.id = TPTCV.tcversion_id " .
           " JOIN {$nh} NHTC ON NHTC.id = NHTCV.parent_id " .
           " LEFT JOIN {$nh} NHTS ON NHTS.id = NHTC.parent_id " .
           " LEFT JOIN {$T['user_assignments']} UA ON " .
           "   UA.feature_id = TPTCV.id AND UA.build_id = {$build_id} " .
           "   AND UA.type = {$execType} AND UA.status = {$openStatus} " .
           " WHERE TPTCV.testplan_id = {$tplan_id}" . $platWhere .
           " ORDER BY NHTS.node_order, NHTS.id, NHTC.node_order, NHTC.id," .
           " TPTCV.platform_id";

    $rows = $db->fetchRowsIntoMap($sql, 'ua_row');
    if (is_null($rows)) { $rows = []; }

    // user display names (single cheap query)
    $userNames = [];
    $urs = $db->fetchRowsIntoMap(
        "SELECT id, login, first, last FROM {$T['users']}", 'id');
    foreach ((array)$urs as $uid => $u) {
        $userNames[intval($uid)] = userLabel($u);
    }

    // group into one row per tcase+platform with assigned-user set;
    // remember the LATEST ACTIVE version per tcase for the version badge
    $grouped = [];
    $orderKey = 0;
    foreach ($rows as $r) {
        $key = intval($r['tcase_id']) . '_' . intval($r['platform_id']);
        if (!isset($grouped[$key])) {
            $grouped[$key] = [
                'feature_id' => intval($r['feature_id']),
                'tcase_id' => intval($r['tcase_id']),
                'tcase_name' => (string)$r['tcase_name'],
                'tc_order' => intval($r['tc_order']),
                'tsuite_id' => intval($r['tsuite_id']),
                'tsuite_name' => $r['tsuite_name'] !== null
                    ? (string)$r['tsuite_name'] : '',
                'platform_id' => intval($r['platform_id']),
                'tcversion_id' => intval($r['tcversion_id']),
                'version' => intval($r['version']),
                'active' => intval($r['active']),
                'urgency' => intval($r['urgency']),
                'importance' => intval($r['importance']),
                'external_id' => intval($r['tc_external_id']),
                'assigned' => [],
                '_ord' => $orderKey++,
            ];
        }
        $au = intval($r['assigned_user_id']);
        if ($au > 0) {
            $grouped[$key]['assigned'][$au] =
                isset($userNames[$au]) ? $userNames[$au] : ('#' . $au);
        }
    }

    $items = array_values(array_map(function ($g) {
        unset($g['_ord']);
        $g['assigned'] = array_values(array_map(function ($uid, $label) {
            return ['user_id' => $uid, 'label' => $label];
        }, array_keys($g['assigned']), $g['assigned']));
        $g['priority_value'] = $g['urgency'] * $g['importance'];
        $g['priority_level'] = prioLevel($g['priority_value']);
        return $g;
    }, $grouped));

    out([
        'status' => 'ok',
        'items' => $items,
        'build_id' => $build_id,
    ]);
}

// ---------------------------------------------------------------------
// POST /assign  body: {tproject_id,tplan_id,build_id,
//                      assignments:[{feature_id,tcase_id,tcversion_id,
//                                    platform_id,user_ids:[..]}],
//                      send_mail:bool}
// legacy doAction='std'
// ---------------------------------------------------------------------
if ($method === 'POST' && count($segments) === 1 && $segments[0] === 'assign') {
    list($tproject_id, $tplan_id) = needCtx($tplanMgr);
    if (!canAssign($user, $db, $tproject_id, $tplan_id)) {
        deny();
    }
    $body = getBody();
    $build_id = intval($body['build_id'] ?? 0);
    if ($build_id <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid build id']);
    }
    $assignments = (array)($body['assignments'] ?? []);
    if (count($assignments) == 0) {
        http_response_code(422);
        out(['status' => 'error', 'message' => 'No test case selected',
             'error_code' => 'NO_SELECTION']);
    }

    // validate features belong to this plan/build context
    $T = tlObject::getDBTables();
    $featIds = array_values(array_filter(array_map(function ($a) {
        return intval($a['feature_id'] ?? 0);
    }, $assignments), function ($v) { return $v > 0; }));

    $dbNow = $db->db_now();
    $assignedQty = 0;

    // legacy groups by platform before calling assign()
    $byPlatform = [];
    foreach ($assignments as $a) {
        $fid = intval($a['feature_id'] ?? 0);
        $uids = array_values(array_filter(array_map('intval',
            (array)($a['user_ids'] ?? [])), function ($v) {
                return $v > 0;
            }));
        if ($fid <= 0 || count($uids) == 0) { continue; }
        $plat = intval($a['platform_id'] ?? 0);
        foreach ($uids as $uid) {
            $byPlatform[$plat][$fid]['user_id'] = $uid;
            $byPlatform[$plat][$fid]['type'] = $execType;
            $byPlatform[$plat][$fid]['status'] = $openStatus;
            $byPlatform[$plat][$fid]['creation_ts'] = $dbNow;
            $byPlatform[$plat][$fid]['assigner_id'] = $userId;
            $byPlatform[$plat][$fid]['build_id'] = $build_id;
            $byPlatform[$plat][$fid]['tcase_id'] =
                intval($a['tcase_id'] ?? 0);
            $byPlatform[$plat][$fid]['tcversion_id'] =
                intval($a['tcversion_id'] ?? 0);
        }
        $assignedQty++;
    }

    foreach ($byPlatform as $plat => $values) {
        $assignMgr->assign($values);
    }

    $mailInfo = null;
    if (!empty($body['send_mail'])) {
        $mailInfo = notifyAssignees($db, $tplanMgr, $tproject_id, $tplan_id,
            $build_id, 'ins', $byPlatform, $userId);
    }

    out([
        'status' => 'ok',
        'assigned_qty' => $assignedQty,
        'mail' => $mailInfo,
    ]);
}

// ---------------------------------------------------------------------
// POST /remove  body: {tproject_id,tplan_id,build_id,feature_id,user_id}
// legacy doAction='doRemove' (per-assigned-user remove icon)
// ---------------------------------------------------------------------
if ($method === 'POST' && count($segments) === 1 && $segments[0] === 'remove') {
    list($tproject_id, $tplan_id) = needCtx($tplanMgr);
    if (!canAssign($user, $db, $tproject_id, $tplan_id)) {
        deny();
    }
    $body = getBody();
    $build_id = intval($body['build_id'] ?? 0);
    $feature_id = intval($body['feature_id'] ?? 0);
    $targetUser = intval($body['user_id'] ?? 0);
    if ($build_id <= 0 || $feature_id <= 0 || $targetUser <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid arguments']);
    }

    $signature = [];
    $signature[] = ['type' => $execType, 'user_id' => $targetUser,
                    'feature_id' => $feature_id, 'build_id' => $build_id];
    $assignMgr->deleteBySignature($signature);

    $mailInfo = null;
    if (!empty($body['send_mail'])) {
        $byPlatform = [];
        $feature = current($tplanMgr->getFeatureByID($feature_id));
        $lnk = [];
        $lnk['previous_user_id'] = [$targetUser];
        $lnk['tcase_id'] = intval($feature['tcase_id']);
        $lnk['tcversion_id'] = intval($feature['tcversion_id']);
        $byPlatform[intval($feature['platform_id'])][$feature_id] = $lnk;
        $mailInfo = notifyAssignees($db, $tplanMgr, $tproject_id, $tplan_id,
            $build_id, 'del', $byPlatform, $userId);
    }

    out(['status' => 'ok', 'removed' => 1, 'mail' => $mailInfo]);
}

// ---------------------------------------------------------------------
// POST /removeall  body: {tproject_id,tplan_id,build_id,feature_ids:[..]}
// legacy doAction='doRemoveAll'
// ---------------------------------------------------------------------
if ($method === 'POST' && count($segments) === 1 &&
    $segments[0] === 'removeall') {
    list($tproject_id, $tplan_id) = needCtx($tplanMgr);
    if (!canAssign($user, $db, $tproject_id, $tplan_id)) {
        deny();
    }
    $body = getBody();
    $build_id = intval($body['build_id'] ?? 0);
    if ($build_id <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid build id']);
    }
    $fids = array_values(array_filter(array_map('intval',
        (array)($body['feature_ids'] ?? [])), function ($v) {
            return $v > 0;
        }));
    if (count($fids) == 0) {
        http_response_code(422);
        out(['status' => 'error', 'message' => 'No test case selected',
             'error_code' => 'NO_SELECTION']);
    }

    $features2 = [];
    foreach ($fids as $fid) {
        $features2[$fid]['type'] = $execType;
        $features2[$fid]['build_id'] = $build_id;
    }
    $assignMgr->delete_by_feature_id_and_build_id($features2);

    out(['status' => 'ok', 'removed_features' => count($fids)]);
}

// ---------------------------------------------------------------------
// POST /bulkuserremove  body: {tproject_id,tplan_id,build_id,
//                              feature_ids:[..], user_ids:[..]}
// legacy doAction='doBulkUserRemove'
// ---------------------------------------------------------------------
if ($method === 'POST' && count($segments) === 1 &&
    $segments[0] === 'bulkuserremove') {
    list($tproject_id, $tplan_id) = needCtx($tplanMgr);
    if (!canAssign($user, $db, $tproject_id, $tplan_id)) {
        deny();
    }
    $body = getBody();
    $build_id = intval($body['build_id'] ?? 0);
    if ($build_id <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid build id']);
    }
    $fids = array_values(array_filter(array_map('intval',
        (array)($body['feature_ids'] ?? [])), function ($v) {
            return $v > 0;
        }));
    $uids = array_values(array_filter(array_map('intval',
        (array)($body['user_ids'] ?? [])), function ($v) {
            return $v > 0;
        }));
    if (count($fids) == 0 || count($uids) == 0) {
        http_response_code(422);
        out(['status' => 'error', 'message' =>
                 'No test case or no user selected',
             'error_code' => 'NO_SELECTION']);
    }

    $feat = [];
    foreach ($fids as $fid) {
        foreach ($uids as $uid) {
            $feat[$fid . '_' . $uid]['type'] = $execType;
            $feat[$fid . '_' . $uid]['feature_id'] = $fid;
            $feat[$fid . '_' . $uid]['build_id'] = $build_id;
            $feat[$fid . '_' . $uid]['user_id'] = $uid;
        }
    }
    $assignMgr->deleteBySignature($feat);

    out(['status' => 'ok',
         'removed_signatures' => count($fids) * count($uids)]);
}

// ---------------------------------------------------------------------
// POST /sendlink  body: {tproject_id,tplan_id,build_id,user_ids:[..]}
// legacy doAction='linkByMail'
// ---------------------------------------------------------------------
if ($method === 'POST' && count($segments) === 1 &&
    $segments[0] === 'sendlink') {
    list($tproject_id, $tplan_id) = needCtx($tplanMgr);
    if (!canAssign($user, $db, $tproject_id, $tplan_id)) {
        deny();
    }
    $body = getBody();
    $build_id = intval($body['build_id'] ?? 0);
    $uids = array_values(array_unique(array_filter(array_map('intval',
        (array)($body['user_ids'] ?? [])), function ($v) {
            return $v > 0;
        })));
    if ($build_id <= 0 || count($uids) == 0) {
        http_response_code(422);
        out(['status' => 'error', 'message' =>
                 'Build and at least one recipient are required',
             'error_code' => 'NO_SELECTION']);
    }

    $userSet = [];
    foreach ($uids as $uid) { $userSet[$uid] = $uid; }
    $context = ['tplan_id' => $tplan_id, 'build_id' => $build_id];
    try {
        $assignMgr->emailLinkToExecPlanning($context, $userSet);
        out(['status' => 'ok', 'sent_to' => count($userSet)]);
    } catch (Exception $e) {
        out(['status' => 'ok', 'sent_to' => 0,
             'warning' => 'mail transport failed']);
    }
}

http_response_code(404);
out(['status' => 'error', 'message' => 'Unknown route']);

/* ==================================================================== */
/* helpers                                                              */
/* ==================================================================== */

/**
 * Best-effort notification mail to testers (parity with the legacy
 * send_mail_to_testers()). Only runs when an SMTP host is configured,
 * otherwise returns skipped:true so the UI stays honest without spamming
 * the Event Viewer with mail failures in sandboxes without MTA.
 */
function notifyAssignees(&$db, &$tplanMgr, $tproject_id, $tplan_id,
                         $build_id, $operation, $byPlatform, $assignerId) {

    if (trim((string)config_get('smtp_host')) === '') {
        return ['skipped' => true];
    }

    $useNew = ($operation != 'del');
    $useOld = ($operation != 'ins');

    $T = tlObject::getDBTables();

    $tcaseSet = [];
    $tcversionSet = [];
    $testers = ['new' => [], 'old' => []];
    foreach ($byPlatform as $platform_id => $items) {
        foreach ($items as $value) {
            if ($useNew && isset($value['user_id'])) {
                $testers['new'][intval($value['user_id'])][] = $value;
            }
            if ($useOld && isset($value['previous_user_id'])) {
                foreach ((array)$value['previous_user_id'] as $puid) {
                    $testers['old'][intval($puid)][] = $value;
                }
            }
            $tcid = intval($value['tcase_id'] ?? 0);
            $tvid = intval($value['tcversion_id'] ?? 0);
            if ($tcid > 0) { $tcaseSet[$tcid] = $tcid; }
            if ($tvid > 0) { $tcversionSet[$tvid] = $tvid; }
        }
    }

    if (count($tcaseSet) == 0) { return ['skipped' => true]; }

    $nh = $T['nodes_hierarchy'];
    $idList = implode(',', $tcaseSet);
    $tvList = implode(',', $tcversionSet ?: [0]);

    // names + full paths
    $paths = [];
    try {
        $tcaseMgr = new testcase($db);
        $pathInfo = $tcaseMgr->tree_manager->get_full_path_verbose($tcaseSet);
        foreach ((array)$pathInfo as $tcid => $pieces) {
            $paths[$tcid] = implode('/', (array)$pieces);
        }
    } catch (Exception $e) {
        $paths = [];
    }

    $sql = "SELECT NHTCV.parent_id AS testcase_id, TCVA.tc_external_id, " .
           " NHTC.name FROM {$T['tcversions']} TCVA " .
           " JOIN {$nh} NHTCV ON NHTCV.id = TCVA.id " .
           " JOIN {$nh} NHTC ON NHTC.id = NHTCV.parent_id " .
           " WHERE NHTCV.parent_id IN ({$idList})";
    $nameRows = $db->fetchRowsIntoMap($sql, 'testcase_id');
    $tcnames = [];
    $extByTc = [];
    foreach ((array)$nameRows as $nr) {
        $extByTc[intval($nr['testcase_id'])] =
            intval($nr['tc_external_id']);
        $tcnames[intval($nr['testcase_id'])] = (string)$nr['name'];
    }

    $tprojMgr = new testproject($db);
    $tcaseCfg = config_get('testcase_cfg');
    $prefix = $tprojMgr->getTestCasePrefix($tproject_id) .
              $tcaseCfg->glue_character;

    $tplanInfo = $tplanMgr->get_by_id($tplan_id);
    $buildInfo = $tplanMgr->get_build_by_id($tplan_id, $build_id);
    $buildName = is_array($buildInfo) ? $buildInfo['name'] : $build_id;
    $assignerObj = tlUser::getByID($db, $assignerId);
    $assignerName = $assignerObj ? $assignerObj->firstName . ' ' .
                     $assignerObj->lastName : '';

    $lblProject = lang_get('testproject');
    $lblPlan = lang_get('testplan');
    $lblBuild = lang_get('build');
    $lblPlatform = lang_get('platform');

    $bodyHeader = $lblProject . ': ' .
                  testproject::getName($db, $tproject_id) . '<br />' .
                  $lblPlan . ': ' . $tplanInfo['name'] . '<br />' .
                  $lblBuild . ': ' . $buildName . '<br /><br />';

    $sent = 0;
    $failed = 0;
    $fromEmail = config_get('from_email');

    foreach (['new', 'old'] as $kind) {
        if (($kind === 'new' && !$useNew) ||
            ($kind === 'old' && !$useOld)) {
            continue;
        }
        foreach ($testers[$kind] as $uid => $rowsSet) {
            if ($uid <= 0) { continue; }
            $uobj = tlUser::getByID($db, $uid);
            if (is_null($uobj)) { continue; }
            $to = trim((string)$uobj->emailAddress);
            if ($to === '' || strpos($to, '@') === false) { continue; }

            if ($kind === 'new') {
                $subject = lang_get('mail_subject_testcase_assigned') .
                           ' ' . $tplanInfo['name'];
                $intro = sprintf(lang_get('mail_testcase_assigned'),
                                 $uobj->firstName . ' ' . $uobj->lastName,
                                 $assignerName);
            } else {
                $subject = lang_get(
                    'mail_subject_testcase_assignment_removed') .
                    ' ' . $tplanInfo['name'];
                $intro = sprintf(
                    lang_get('mail_testcase_assignment_removed'),
                    $uobj->firstName . ' ' . $uobj->lastName,
                    $assignerName);
            }

            $html = $bodyHeader . $intro . '<br /><br />';
            foreach ($rowsSet as $row) {
                $tcid = intval($row['tcase_id'] ?? 0);
                if ($tcid <= 0) { continue; }
                $ext = isset($extByTc[$tcid]) ? $extByTc[$tcid] : 0;
                $line = isset($paths[$tcid]) ? $paths[$tcid] . '/' : '';
                $line .= $prefix . $ext . ' ' .
                         (isset($tcnames[$tcid]) ? $tcnames[$tcid] : '');
                $html .= esc_html_line($line) . '<br />';
            }
            $html .= '<br />' . date(DATE_RFC1123);

            try {
                $okMail = @email_send($fromEmail, $to, $subject, $html,
                                      null, null, false, true);
                if ($okMail) { $sent++; } else { $failed++; }
            } catch (Exception $e) {
                $failed++;
            }
        }
    }

    return ['skipped' => false, 'sent' => $sent, 'failed' => $failed];
}

function esc_html_line($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
