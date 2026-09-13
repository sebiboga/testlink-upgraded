<?php
/**
 * Notifications BFF API (Refs #896)
 * URL: /api/notifications/
 * Plain PHP, no framework, no compilation.
 *
 * GitHub-style per-user notifications built LIVE from the data already stored
 * by TestLink 1.9.20 (there is no legacy notification subsystem to port, so
 * this screen derives everything from existing tables):
 *
 *   1. assignment       - test cases assigned to the current user
 *                         (user_assignments JOIN testplan_tcversions)
 *   2. milestone        - milestones whose target_date is approaching or
 *                         already overdue (milestones JOIN testplans)
 *   3. plan_completed   - test plans whose linked test cases are all executed
 *                         (testplan_tcversions vs executions)
 *   4. bug              - bug links filed from executions in the last 14 days
 *                         (execution_bugs JOIN executions)
 *
 * Read/unread state is kept per login in $_SESSION (the DB is re-imported on
 * every CI run and no schema migration is allowed, so no new table is used).
 * This mirrors how the Dashio bell widget (gui/templates/dashio/index.html
 * lines 172-215) is rendered, but with real TestLink data behind it.
 *
 * Routes:
 *   GET  /        -> { status, notifications[], totals{total,unread}, by_type{} }
 *   GET  /count   -> { status, total, unread, by_type{} }
 *   POST /read    -> mark notification ids as read (JSON body: {"ids": [...]}) or all
 *                    returns updated totals
 *
 * Backs gui/templates/notifications/notifications.html.
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json');

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
$path = preg_replace('#^/api/notifications(/index\.php)?#', '', $path);
$path = '/' . trim($path, '/');
$method = $_SERVER['REQUEST_METHOD'];
$segments = array_values(array_filter(explode('/', $path)));

function nout($data) { echo json_encode($data); exit; }

if (!isset($_SESSION['tl_notif_read']) || !is_array($_SESSION['tl_notif_read'])) {
    $_SESSION['tl_notif_read'] = array();
}

// Session-scoped read-state helpers (see header comment - no schema changes).
function notifMarkRead($ids)
{
    foreach ((array) $ids as $id) {
        $id = (string) $id;
        if ($id !== '') {
            $_SESSION['tl_notif_read'][$id] = true;
        }
    }
}

// Drop read-flags for notification ids that no longer exist in the current
// list so the session store cannot grow without bound.
function notifPruneStale($items)
{
    $valid = array();
    foreach ((array) $items as $it) {
        $valid[$it['id']] = true;
    }
    foreach (array_keys($_SESSION['tl_notif_read']) as $k) {
        if (!isset($valid[$k])) {
            unset($_SESSION['tl_notif_read'][$k]);
        }
    }
}

function notifTotals($items)
{
    $total = 0;
    $unread = 0;
    $byType = array();
    foreach ($items as $it) {
        $total++;
        $t = $it['type'];
        if (!isset($byType[$t])) {
            $byType[$t] = 0;
        }
        $byType[$t]++;
        if (!$it['read']) {
            $unread++;
        }
    }
    return array('total' => $total, 'unread' => $unread, 'by_type' => $byType);
}

// Route: GET / - full notification list for the current user.
if ($method === 'GET' && empty($segments)) {
    $items = buildNotificationsList($db, $userId);
    notifPruneStale($items);
    nout(array(
        'status' => 'ok',
        'notifications' => $items,
        'totals' => notifTotals($items),
    ));
}

// Route: GET /count - lightweight unread badge value for the topbar bell.
if ($method === 'GET' && $segments === ['count']) {
    $items = buildNotificationsList($db, $userId);
    notifPruneStale($items);
    nout(array(
        'status' => 'ok',
        'totals' => notifTotals($items),
    ));
}

// Route: POST /read - mark notifications as read.
if ($method === 'POST' && $segments === ['read']) {
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    $ids = isset($body['ids']) ? (array) $body['ids'] : array();
    $markAll = !empty($body['all']);
    if ($markAll) {
        $items = buildNotificationsList($db, $userId);
        $ids = array_map(function ($it) { return $it['id']; }, $items);
    }
    if (count($ids) === 0) {
        http_response_code(400);
        nout(array('status' => 'error', 'message' => 'No notification ids given'));
    }
    notifMarkRead($ids);
    $items = buildNotificationsList($db, $userId);
    notifPruneStale($items);
    nout(array(
        'status' => 'ok',
        'notifications' => $items,
        'totals' => notifTotals($items),
    ));
}

http_response_code(404);
nout(array('status' => 'error', 'message' => 'Not found'));

/**
 * Build the full notification list for one user, newest first.
 *
 * All four groups are derived live from existing TestLink tables; nothing is
 * persisted (fresh DB import on every run, no schema migration allowed).
 */
function buildNotificationsList(&$dbHandler, $userId)
{
    $T = tlObjectWithDB::getDBTables(array(
        'user_assignments', 'assignment_types', 'assignment_status',
        'testplan_tcversions', 'tcversions', 'nodes_hierarchy', 'testplans',
        'testprojects', 'builds', 'platforms', 'milestones', 'executions',
        'execution_bugs', 'users',
    ));

    $notifications = array();

    // Cross-project rights scoping (security, code-review #896): groups 2-4
    // must not leak milestone / plan / bug data from projects the user cannot
    // see (same rules as legacy get_accessible_for_user: global ADMIN sees all,
    // other users are limited by is_public + user_testproject_roles).
    $tprojectMgr = new testproject($dbHandler);
    $accessible = (array) $tprojectMgr->get_accessible_for_user(intval($userId));
    $projectIds = array_keys($accessible);
    $projectFilter = '';
    if (count($projectIds)) {
        $projectFilter = ' AND NHTPLAN.parent_id IN (' .
            implode(',', array_map('intval', $projectIds)) . ')';
    } else {
        $projectFilter = ' AND 1=0 ';
    }

    // ---- 1. assignments: test cases assigned to the current user ---------
    $at = $dbHandler->fetchFirstRow(
        "SELECT id FROM {$T['assignment_types']} " .
        "WHERE description='testcase_execution'");
    $assignType = intval($at['id'] ?? 1);
    $rs = $dbHandler->get_recordset(
        "SELECT UA.id AS assignment_id, UA.creation_ts, UA.deadline_ts, " .
        "       UA.assigner_id, TCV.id AS tcversion_id, TCV.tc_external_id, " .
        "       NHTC.id AS tc_id, NHTC.name AS tc_name, " .
        "       TPROJ.prefix, TPROJ.id AS tproject_id, " .
        "       NHTPLAN.id AS tplan_id, NHTPLAN.name AS tplan_name, " .
        "       BUILDS.name AS build_name, " .
        "       ASIGNER.first AS assigner_first, ASIGNER.last AS assigner_last " .
        "FROM {$T['user_assignments']} UA " .
        "JOIN {$T['testplan_tcversions']} TPTCV ON TPTCV.id = UA.feature_id " .
        "JOIN {$T['tcversions']} TCV ON TCV.id = TPTCV.tcversion_id " .
        "JOIN {$T['nodes_hierarchy']} NHTCV ON NHTCV.id = TCV.id " .
        "JOIN {$T['nodes_hierarchy']} NHTC ON NHTC.id = NHTCV.parent_id " .
        "JOIN {$T['nodes_hierarchy']} NHTPLAN ON NHTPLAN.id = TPTCV.testplan_id " .
        "JOIN {$T['testprojects']} TPROJ ON TPROJ.id = NHTPLAN.parent_id " .
        "JOIN {$T['testplans']} TPLAN ON TPLAN.id = TPTCV.testplan_id " .
        "JOIN {$T['builds']} BUILDS ON BUILDS.id = UA.build_id " .
        "LEFT JOIN {$T['users']} ASIGNER ON ASIGNER.id = UA.assigner_id " .
        "WHERE UA.type = " . intval($assignType) . " " .
        "  AND UA.user_id = " . intval($userId) . " " .
        "  AND UA.creation_ts >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) " .
        $projectFilter .
        "ORDER BY UA.creation_ts DESC");

    foreach ((array) $rs as $r) {
        $ts = strtotime($r['creation_ts']);
        $deadline = (!empty($r['deadline_ts'])) ? strtotime($r['deadline_ts']) : 0;
        $assigner = trim(($r['assigner_first'] ?? '') . ' ' . ($r['assigner_last'] ?? ''));
        $notifications[] = array(
            'id' => 'assignment-' . intval($r['assignment_id']),
            'type' => 'assignment',
            'icon' => 'fa-user-check',
            'color' => '#4ECDC4',
            'time_epoch' => $ts ? intval($ts) : 0,
            'read' => notifIsRead('assignment-' . intval($r['assignment_id'])),
            'tc_id' => intval($r['tc_id']),
            'tcversion_id' => intval($r['tcversion_id']),
            'tc_name' => (string) $r['tc_name'],
            'prefix' => (string) $r['prefix'],
            'tc_external_id' => intval($r['tc_external_id']),
            'tplan_id' => intval($r['tplan_id']),
            'tplan_name' => (string) $r['tplan_name'],
            'tproject_id' => intval($r['tproject_id']),
            'build_name' => (string) $r['build_name'],
            'assigner' => $assigner,
            'deadline_epoch' => $deadline ? intval($deadline) : 0,
            'url' => '/gui/templates/execute/execHistory.html?tcase_id='
                . intval($r['tc_id']) . '&tproject_id='
                . intval($r['tproject_id']),
        );
    }

    // ---- 2. milestones: approaching / overdue target_date ---------------
    $today = strtotime(date('Y-m-d'));
    $rs = $dbHandler->get_recordset(
        "SELECT M.id AS milestone_id, M.name AS milestone_name, " .
        "       M.start_date, M.target_date, " .
        "       NHTPLAN.id AS tplan_id, NHTPLAN.name AS tplan_name, " .
        "       NHTPLAN.parent_id AS tproject_id " .
        "FROM {$T['milestones']} M " .
        "JOIN {$T['nodes_hierarchy']} NHTPLAN ON NHTPLAN.id = M.testplan_id " .
        "JOIN {$T['testplans']} TPLAN ON TPLAN.id = M.testplan_id " .
        "WHERE M.target_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) " .
        "  AND M.target_date <= DATE_ADD(CURDATE(), INTERVAL 14 DAY) " .
        $projectFilter .
        "ORDER BY M.target_date ASC");

    foreach ((array) $rs as $r) {
        $target = strtotime($r['target_date']);
        $daysLeft = intval(floor(($target - $today) / 86400));
        $overdue = $target < $today;
        $notifications[] = array(
            'id' => 'milestone-' . intval($r['milestone_id']),
            'type' => 'milestone',
            'icon' => 'fa-calendar-check',
            'color' => ($overdue ? '#e6605e' : '#f0ad4e'),
            'time_epoch' => $target ? intval($target) : 0,
            'read' => notifIsRead('milestone-' . intval($r['milestone_id'])),
            'milestone_id' => intval($r['milestone_id']),
            'milestone_name' => (string) $r['milestone_name'],
            'tplan_id' => intval($r['tplan_id']),
            'tplan_name' => (string) $r['tplan_name'],
            'tproject_id' => intval($r['tproject_id']),
            'target_date' => (string) $r['target_date'],
            'days_left' => $daysLeft,
            'overdue' => $overdue,
            'url' => '/gui/templates/plans/planMilestones.html?tplan_id='
                . intval($r['tplan_id']),
        );
    }

    // ---- 3. plan_completed: fully executed test plans --------------------
    $rs = $dbHandler->get_recordset(
        "SELECT TPTCV.testplan_id, NHTPLAN.name AS tplan_name, " .
        "       NHTPLAN.parent_id AS tproject_id, COUNT(*) AS linked, " .
        "       COUNT(DISTINCT CONCAT(EX.tcversion_id, ':', EX.platform_id)) AS executed, " .
        "       MAX(EX.execution_ts) AS last_execution_ts " .
        "FROM {$T['testplan_tcversions']} TPTCV " .
        "JOIN {$T['nodes_hierarchy']} NHTPLAN ON NHTPLAN.id = TPTCV.testplan_id " .
        "JOIN {$T['testplans']} TPLAN ON TPLAN.id = TPTCV.testplan_id " .
        "LEFT JOIN {$T['executions']} EX ON EX.testplan_id = TPTCV.testplan_id " .
        "   AND EX.tcversion_id = TPTCV.tcversion_id " .
        "   AND EX.platform_id = TPTCV.platform_id " .
        "   AND EX.status <> 'n' " .
        "WHERE 1=1 " . $projectFilter .
        "GROUP BY TPTCV.testplan_id, NHTPLAN.name, NHTPLAN.parent_id " .
        "HAVING linked > 0 AND linked = executed " .
        "   AND MAX(EX.execution_ts) >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)");

    foreach ((array) $rs as $r) {
        $linked = intval($r['linked']);
        $ts = strtotime($r['last_execution_ts']);
        $notifications[] = array(
            'id' => 'plan_completed-' . intval($r['testplan_id']),
            'type' => 'plan_completed',
            'icon' => 'fa-check-double',
            'color' => '#5cb85c',
            'time_epoch' => $ts ? intval($ts) : 0,
            'read' => notifIsRead('plan_completed-' . intval($r['testplan_id'])),
            'tplan_id' => intval($r['testplan_id']),
            'tplan_name' => (string) $r['tplan_name'],
            'tproject_id' => intval($r['tproject_id']),
            'executed' => $linked,
            'total' => $linked,
            'url' => '/gui/templates/results/generalMetrics.html?tplan_id='
                . intval($r['testplan_id']),
        );
    }

    // ---- 4. bug: bug links filed from executions in the last 14 days -----
    if (notifTableHasColumn($dbHandler, 'execution_bugs', 'execution_id')) {
        $rs = $dbHandler->get_recordset(
            "SELECT EB.bug_id, EX.execution_ts, EX.testplan_id AS tplan_id, EX.tcversion_id, " .
            "       NHTC.id AS tc_id, NHTC.name AS tc_name, " .
            "       NHTPLAN.name AS tplan_name, NHTPLAN.parent_id AS tproject_id, " .
            "       TPROJ.prefix, TCV.tc_external_id " .
            "FROM {$T['execution_bugs']} EB " .
            "JOIN {$T['executions']} EX ON EX.id = EB.execution_id " .
            "JOIN {$T['nodes_hierarchy']} NHTPLAN ON NHTPLAN.id = EX.testplan_id " .
            "JOIN {$T['testprojects']} TPROJ ON TPROJ.id = NHTPLAN.parent_id " .
            "JOIN {$T['tcversions']} TCV ON TCV.id = EX.tcversion_id " .
            "JOIN {$T['nodes_hierarchy']} NHTCV ON NHTCV.id = TCV.id " .
            "JOIN {$T['nodes_hierarchy']} NHTC ON NHTC.id = NHTCV.parent_id " .
            "WHERE EX.execution_ts >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) " .
            $projectFilter .
            "ORDER BY EX.execution_ts DESC");
        foreach ((array) $rs as $r) {
            $ts = strtotime($r['execution_ts']);
            $bugId = (string) $r['bug_id'];
            $key = 'bug-' . hash('sha1', $bugId);
            $notifications[] = array(
                'id' => $key,
                'type' => 'bug',
                'icon' => 'fa-bug',
                'color' => '#e6605e',
                'time_epoch' => $ts ? intval($ts) : 0,
                'read' => notifIsRead($key),
                'bug_id' => $bugId,
                'tc_id' => intval($r['tc_id']),
                'tc_name' => (string) $r['tc_name'],
                'prefix' => (string) $r['prefix'],
                'tc_external_id' => intval($r['tc_external_id']),
                'tplan_id' => intval($r['tplan_id']),
                'tplan_name' => (string) $r['tplan_name'],
                'tproject_id' => intval($r['tproject_id']),
                'url' => '/gui/templates/mainpage/mainPage.html?tplan_id='
                    . intval($r['tplan_id']),
            );
        }
    }

    // Newest first across all groups (epoch desc, unknown epoch sinks last).
    usort($notifications, function ($a, $b) {
        return ($b['time_epoch'] ?? 0) - ($a['time_epoch'] ?? 0);
    });

    return $notifications;
}

function notifIsRead($id)
{
    return !empty($_SESSION['tl_notif_read'][$id]);
}

/**
 * Schema-drift guard (same pattern as api/mainpage::bffBuildsSchemaOk).
 * database::exec_query() DIES with an HTML trace on SQL failure, which for a
 * JSON BFF means the client receives HTML. Each probe reads INFORMATION_SCHEMA
 * only (never the probed column) so it cannot fail itself; when the column is
 * missing the group is skipped instead of killing the request.
 */
function notifTableHasColumn(&$dbHandler, $tableName, $columnName)
{
    static $cache = array();
    $key = $tableName . '.' . $columnName;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $sql = " SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS " .
           " WHERE TABLE_SCHEMA = '" . DB_NAME . "' " .
           "   AND TABLE_NAME = '" . DB_TABLE_PREFIX . $tableName . "' " .
           "   AND COLUMN_NAME = '" . $columnName . "' LIMIT 1";
    $rows = $dbHandler->get_recordset($sql);
    $cache[$key] = (is_array($rows) && count($rows) > 0);
    return $cache[$key];
}