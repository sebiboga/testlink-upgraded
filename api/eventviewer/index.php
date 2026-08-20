<?php
/**
 * Events BFF API
 * URL: /api/eventviewer/
 * Plain PHP, no framework, no compilation
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

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

$em = tlEventManager::create($db);
$path = $_SERVER['PATH_INFO'] ?? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = preg_replace('#^/api/eventviewer(/index\.php)?#', '', $path);
$path = '/' . trim($path, '/');
$method = $_SERVER['REQUEST_METHOD'];

function out($data) { echo json_encode($data); exit; }

function getParam($key, $default = null) { return $_GET[$key] ?? $default; }

function getFilters() {
    $logLevels = $users = $startTime = $endTime = null;

    $rl = getParam('logLevel');
    if ($rl !== null) {
        $logLevels = is_array($rl) ? $rl : explode(',', $rl);
        $logLevels = array_map('intval', $logLevels);
    }

    $u = getParam('user');
    if ($u !== null) {
        $users = is_array($u) ? $u : explode(',', $u);
        $users = implode(',', array_map('intval', $users));
    }

    $dateFormat = config_get('date_format');
    $sd = getParam('startDate');
    if ($sd !== null && $sd !== '') {
        $d = split_localized_date($sd, $dateFormat);
        if ($d) { $startTime = strtotime($d['month'] . '/' . $d['day'] . '/' . $d['year']); }
    }
    $ed = getParam('endDate');
    if ($ed !== null && $ed !== '') {
        $d = split_localized_date($ed, $dateFormat);
        if ($d) { $endTime = strtotime($d['month'] . '/' . $d['day'] . '/' . $d['year'] . ', 23:59:59'); }
    }

    return [$logLevels, $users, $startTime, $endTime];
}

function eventToJSON(tlEvent $event, &$db) {
    $userName = $userDisplayName = null;
    if ($event->userID > 0) {
        $u = tlUser::getByID($db, $event->userID);
        if ($u) { $userName = $u->login; $userDisplayName = $u->getDisplayName(); }
    }
    return [
        'id' => intval($event->dbID),
        'timestamp' => $event->timestamp,
        'timestampFormatted' => date('d/m/Y H:i:s', intval($event->timestamp)),
        'logLevelCode' => intval($event->logLevel),
        'logLevel' => tlLogger::$logLevels[$event->logLevel] ?? null,
        'description' => (string)$event->description,
        'source' => $event->source,
        'userID' => $event->userID ? intval($event->userID) : null,
        'userName' => $userName,
        'userDisplayName' => $userDisplayName,
        'transactionID' => $event->transactionID ? intval($event->transactionID) : null,
        'objectID' => $event->objectID ? intval($event->objectID) : null,
        'objectType' => $event->objectType,
        'activityCode' => $event->activityCode,
    ];
}

// --- ROUTING ---

if ($method === 'GET' && $path === '/events/meta/logLevels') {
    out(['status' => 'ok', 'items' => tlLogger::$logLevels]);
}

if ($method === 'GET' && $path === '/events/meta/users') {
    $allUsers = tlUser::getAll($db);
    $items = [];
    foreach ((array) $allUsers as $id => $u) {
        $items[] = ['id' => intval($id), 'login' => $u->login, 'displayName' => $u->getDisplayName()];
    }
    out(['status' => 'ok', 'items' => $items]);
}

if ($method === 'GET' && $path === '/events/meta/rights') {
    out([
        'status' => 'ok',
        'canDelete' => $user->hasRight($db, 'events_mgt'),
        'canView' => $user->hasRight($db, 'mgt_view_events'),
    ]);
}

// Aggregate in SQL. Loading every matching event as a full tlEvent object just
// to count them exhausts PHP's memory limit once the log grows (the events
// table reaches five figures quickly on a busy instance).
function statsWhere($db, $users, $startTime, $endTime, $extraWhere = null) {
    $t = tlObject::getDBTables(['events', 'transactions']);
    $join = '';
    if (!is_null($users) && $users !== '') {
        $safe = implode(',', array_map('intval', explode(',', $users)));
        if ($safe !== '') {
            $join = " JOIN {$t['transactions']} T ON T.id = E.transaction_id" .
                    " AND T.user_id IN ({$safe}) ";
        }
    }
    $clauses = [];
    if (!is_null($startTime)) { $clauses[] = 'E.fired_at >= ' . intval($startTime); }
    if (!is_null($endTime))   { $clauses[] = 'E.fired_at <= ' . intval($endTime); }
    if (!is_null($extraWhere)) { $clauses[] = $extraWhere; }
    $where = $clauses ? ' WHERE ' . implode(' AND ', $clauses) : '';
    return [$t['events'], $join, $where];
}

if ($method === 'GET' && $path === '/events/stats/byLevel') {
    [$logLevels, $users, $startTime, $endTime] = getFilters();
    [$eventsTable, $join, $where] = statsWhere($db, $users, $startTime, $endTime);

    $counts = [];
    foreach (tlLogger::$logLevels as $name) { $counts[$name] = 0; }

    $sql = "SELECT E.log_level AS lvl, COUNT(*) AS qty" .
           " FROM {$eventsTable} E {$join}{$where} GROUP BY E.log_level";
    foreach ((array) $db->fetchRowsIntoMap($sql, 'lvl') as $lvl => $row) {
        $name = tlLogger::$logLevels[$lvl] ?? null;
        if ($name) { $counts[$name] = intval($row['qty']); }
    }
    out(['status' => 'ok', 'items' => $counts]);
}

if ($method === 'GET' && $path === '/events/stats/perDay') {
    [$logLevels, $users, $startTime, $endTime] = getFilters();

    $daily = [];
    $now = new DateTime();
    for ($i = 29; $i >= 0; $i--) {
        $d = clone $now; $d->modify("-{$i} days");
        $daily[$d->format('Y-m-d')] = 0;
    }

    // Only the charted window has to come back from the database.
    $windowStart = (clone $now)->modify('-29 days')->setTime(0, 0, 0)->getTimestamp();
    [$eventsTable, $join, $where] =
        statsWhere($db, $users, $startTime, $endTime, 'E.fired_at >= ' . $windowStart);

    $sql = "SELECT DATE(FROM_UNIXTIME(E.fired_at)) AS d, COUNT(*) AS qty" .
           " FROM {$eventsTable} E {$join}{$where} GROUP BY d";
    foreach ((array) $db->fetchRowsIntoMap($sql, 'd') as $day => $row) {
        if (isset($daily[$day])) { $daily[$day] = intval($row['qty']); }
    }
    out(['status' => 'ok', 'labels' => array_keys($daily), 'data' => array_values($daily)]);
}

if ($method === 'DELETE' && $path === '/events') {
    if (!$user->hasRight($db, 'events_mgt')) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'Insufficient rights']);
    }
    $logLevels = null;
    $body = file_get_contents('php://input');
    if ($body) {
        $data = json_decode($body, true);
        if (!empty($data['logLevel'])) {
            $logLevels = is_array($data['logLevel']) ? $data['logLevel'] : [$data['logLevel']];
        }
    }
    $em->deleteEventsFor($logLevels);
    out(['status' => 'ok', 'message' => 'Events deleted']);
}

if ($method === 'GET' && preg_match('#^/events/(\d+)$#', $path, $m)) {
    $event = new tlEvent(intval($m[1]));
    if ($event->readFromDB($db, tlEvent::TLOBJ_O_GET_DETAIL_TRANSACTION) < tl::OK) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Event not found']);
    }
    out(['status' => 'ok', 'item' => eventToJSON($event, $db)]);
}

if ($method === 'GET' && ($path === '/events' || $path === '/events/')) {
    [$logLevels, $users, $startTime, $endTime] = getFilters();
    $limit = intval(getParam('limit', 500));
    $events = $em->getEventsFor($logLevels, null, null, null, $limit, $startTime, $endTime, $users);
    $items = [];
    foreach ((array) $events as $ev) { $items[] = eventToJSON($ev, $db); }
    out(['status' => 'ok', 'items' => $items, 'total' => count($items)]);
}

http_response_code(404);
out(['status' => 'error', 'message' => 'Not found']);
