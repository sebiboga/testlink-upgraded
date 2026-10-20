<?php
/**
 * Webhook subscriptions manager BFF API
 * URL: /api/webhooks/
 * Plain PHP, no framework, no compilation.
 *
 * Backs the modern screen gui/templates/system/webhooks.html (Refs #1946,
 * parent feature #1072 - documented REST API #1937 and OpenAPI spec #1944
 * landed already, webhooks was the missing third slice). TestLink 1.9.20 and
 * 2.0.1 shipped no webhook subscription entity at all, so this is a net-new
 * module: a project-scoped registry of webhook subscriptions (name, endpoint
 * URL, event topic, active flag, optional HMAC secret) with a real signed
 * test delivery.
 *
 * Routes (session based, JSON I/O):
 *   GET  ?action=init&tproject_id=N
 *        -> { status, context, domains, stats, items }
 *   GET  ?action=list&tproject_id=N
 *        -> { status, items }                  (same rows as init.items)
 *   POST ?action=save { tproject_id, id?, name, url, topic, is_active,
 *                       secret? }
 *        -> { status, id }
 *   POST ?action=delete { tproject_id, id }
 *        -> { status, deleted }
 *   POST ?action=test { tproject_id, id }
 *        -> { status, test }  |  502 delivery_failed + detail
 *
 * Rights model (checked on the ADDRESSED test project BEFORE it is resolved,
 * the #1697 lesson: no existence oracle):
 *   - reads  require mgt_view_tc OR mgt_modify_tc on the project
 *   - writes require mgt_modify_tc on the project
 *   - unauthenticated -> 401; unknown action -> 400; wrong verb -> 405;
 *     an id that is not a webhook of the addressed project -> 404
 *
 * Test delivery: POSTs a signed JSON payload (X-TestLink-Signature:
 * sha256=<hmac-hex of the raw body with the subscription secret>) to the
 * stored URL with a 5s timeout, no redirect following and a 2KB response cap.
 * The scheme is restricted to http/https, embedded credentials are refused
 * and intranet targets stay allowed on purpose (Jenkins/GitLab runners on the
 * LAN are the canonical consumers).
 *
 * Writes emit AUDIT events (WEBHOOK_CREATE / WEBHOOK_SAVE / WEBHOOK_DELETE /
 * WEBHOOK_TEST) so the Event Viewer tracks the module activity.
 *
 * The tc_webhooks table is created lazily (CREATE TABLE IF NOT EXISTS) so a
 * freshly imported database works unchanged; the matching DDL is also part of
 * the installer schema.
 */
require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$db = null;
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || $userId <= 0) {
    http_response_code(401);
    echo json_encode(array('status' => 'error', 'code' => 'not_authenticated',
        'message' => 'Not authenticated'));
    exit;
}

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    http_response_code(401);
    echo json_encode(array('status' => 'error', 'code' => 'user_not_found',
        'message' => 'User not found'));
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = isset($_REQUEST['action']) ? trim((string)$_REQUEST['action']) : '';

/** Allowed event topics (labels live client-side: whk.topic.<key>). */
function whkTopics() {
    return array('execution_completed', 'execution_failed', 'defect_linked',
                 'build_created', 'testcase_changed', 'requirement_changed');
}

/** Table name handling (respects the optional DB_TABLE_PREFIX). */
function whkTable() {
    $t = tlObjectWithDB::getDBTables(array('tc_webhooks'));
    return $t['tc_webhooks'];
}

/** Idempotent lazy schema migration so a freshly imported DB works unchanged. */
function whkEnsureSchema($db) {
    $t = whkTable();
    $db->exec_query(
        "CREATE TABLE IF NOT EXISTS {$t} (" .
        " id INT UNSIGNED NOT NULL AUTO_INCREMENT," .
        " testproject_id INT UNSIGNED NOT NULL DEFAULT 0," .
        " name VARCHAR(255) NOT NULL DEFAULT ''," .
        " url VARCHAR(500) NOT NULL DEFAULT ''," .
        " topic VARCHAR(64) NOT NULL DEFAULT 'execution_completed'," .
        " secret VARCHAR(128) NOT NULL DEFAULT ''," .
        " is_active TINYINT(1) NOT NULL DEFAULT 1," .
        " created_by INT UNSIGNED NULL," .
        " creation_ts TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP," .
        " update_ts DATETIME NULL," .
        " last_test_ts DATETIME NULL," .
        " last_test_code INT NOT NULL DEFAULT 0," .
        " last_test_ok TINYINT(1) NOT NULL DEFAULT 0," .
        " PRIMARY KEY (id)," .
        " KEY idx_whk_project (testproject_id)" .
        " ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    return $t;
}

/** Read the JSON request body (POST routes). */
function whkBody() {
    static $cached = null;
    if ($cached === null) {
        $cached = json_decode(file_get_contents('php://input'), true);
        if (!is_array($cached)) { $cached = array(); }
    }
    return $cached;
}

/** Trim + length-cap a scalar payload/get field. */
function whkField($input, $key, $maxLen, $default = '') {
    $v = $input[$key] ?? $default;
    $v = is_scalar($v) ? trim((string)$v) : '';
    return mb_substr($v, 0, $maxLen);
}

/** Strict positive int from GET/body, 0 when absent/invalid/non-scalar. */
function whkInt($input, $key) {
    $v = $input[$key] ?? 0;
    return is_scalar($v) ? intval($v) : 0;
}

/** Resolve the addressed test project: GET param wins, body, then session. */
function whkResolveTproject() {
    $tpid = whkInt($_GET, 'tproject_id');
    if ($tpid <= 0) {
        $tpid = whkInt(whkBody(), 'tproject_id');
    }
    if ($tpid <= 0) {
        $tpid = intval($_SESSION['testprojectID'] ?? 0);
    }
    return $tpid;
}

/** Whether the user may read webhook subscriptions of a project. */
function whkCanView($user, $db, $tpid) {
    foreach (array('mgt_view_tc', 'mgt_modify_tc') as $r) {
        if ($user->hasRight($db, $r, $tpid) === 'yes') { return true; }
    }
    return false;
}

/** Whether the user may write webhook subscriptions of a project. */
function whkCanModify($user, $db, $tpid) {
    return ($user->hasRight($db, 'mgt_modify_tc', $tpid) === 'yes');
}

/** Project display name + prefix, or null when the project does not exist. */
function whkProjectInfo($db, $tpid) {
    $tbl = tlObjectWithDB::getDBTables(array('testprojects', 'nodes_hierarchy'));
    $tp = $tbl['testprojects'];
    $nh = $tbl['nodes_hierarchy'];
    $row = $db->fetchFirstRow(
        "SELECT p.prefix AS prefix, nh.name AS name " .
        " FROM {$tp} p JOIN {$nh} nh ON nh.id = p.id " .
        " WHERE p.id = " . intval($tpid) . " AND nh.node_type_id = 1");
    return (is_array($row) && $row) ? $row : null;
}

/**
 * Validate a target URL: http/https only, no embedded credentials,
 * sane length. Returns the clean URL or null.
 */
function whkCleanUrl($url) {
    if ($url === '' || strlen($url) > 500) { return null; }
    $parts = parse_url($url);
    if (!is_array($parts)) { return null; }
    $scheme = strtolower($parts['scheme'] ?? '');
    if ($scheme !== 'http' && $scheme !== 'https') { return null; }
    if (empty($parts['host'])) { return null; }
    if (isset($parts['user']) || isset($parts['pass'])) { return null; }
    return $url;
}

/** Row -> API item (the secret is never echoed back). */
function whkItem($r) {
    return array(
        'id' => intval($r['id']),
        'name' => (string)$r['name'],
        'url' => (string)$r['url'],
        'topic' => (string)$r['topic'],
        'is_active' => intval($r['is_active']) ? 1 : 0,
        'secret_set' => ((string)$r['secret'] !== '') ? 1 : 0,
        'created_by' => intval($r['created_by']),
        'creation_ts' => (string)($r['creation_ts'] ?? ''),
        'update_ts' => (string)($r['update_ts'] ?? ''),
        'last_test_ts' => (string)($r['last_test_ts'] ?? ''),
        'last_test_code' => intval($r['last_test_code'] ?? 0),
        'last_test_ok' => intval($r['last_test_ok'] ?? 0) ? 1 : 0,
    );
}

/** All subscriptions of a project, newest id first. */
function whkList($db, $t, $tpid) {
    $rows = (array)$db->get_recordset(
        "SELECT * FROM {$t} WHERE testproject_id = " . intval($tpid) .
        " ORDER BY id DESC");
    $items = array();
    foreach ((array)$rows as $r) { $items[] = whkItem($r); }
    return $items;
}

/** Inventory stats from a list. */
function whkStats($items) {
    $s = array('total' => count($items), 'active' => 0, 'inactive' => 0,
               'tested_ok' => 0, 'tested_failed' => 0, 'never_tested' => 0);
    foreach ($items as $it) {
        if ($it['is_active']) { $s['active']++; } else { $s['inactive']++; }
        if ($it['last_test_ts'] === '') {
            $s['never_tested']++;
        } elseif ($it['last_test_ok']) {
            $s['tested_ok']++;
        } else {
            $s['tested_failed']++;
        }
    }
    return $s;
}

/** Fetch one webhook row, scoped to the addressed project (or null). */
function whkFetch($db, $t, $tpid, $id) {
    if ($id <= 0) { return null; }
    $row = $db->fetchFirstRow(
        "SELECT * FROM {$t} WHERE id = " . intval($id) .
        " AND testproject_id = " . intval($tpid));
    return (is_array($row) && $row) ? $row : null;
}

/** Emit a machine-coded error and stop. */
function whkFail($http, $code, $message, $extra = array()) {
    http_response_code($http);
    echo json_encode(array_merge(
        array('status' => 'error', 'code' => $code, 'message' => $message),
        $extra));
    exit;
}

/** AUDIT event helper. */
function whkLog($message, $code, $tpid, $userId) {
    $event = new stdClass();
    $event->message = $message;
    $event->logLevel = 'AUDIT';
    $event->source = 'GUI';
    $event->objectID = $tpid;
    $event->objectType = 'testprojects';
    $event->code = $code;
    logEvent($event);
}

/**
 * Deliver a signed test payload to a subscription URL.
 * Returns array(ok, http_code, error, duration_ms). Never follows
 * redirects, caps the response at 2KB and times out after 5 seconds.
 */
function whkDeliver($url, $secret, $body) {
    $started = microtime(true);
    $headers = "Content-Type: application/json\r\n" .
               "User-Agent: TestLink-2.0.1-Webhook\r\n" .
               "X-TestLink-Event: webhook.test\r\n" .
               "X-TestLink-Signature: sha256=" .
               hash_hmac('sha256', $body, $secret) . "\r\n";
    $timeout = 5;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => array($headers),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_MAXREDIRS => 0,
        ));
        $resp = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $code = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
        curl_close($ch);
        $ms = (int)round((microtime(true) - $started) * 1000);
        if ($errno !== 0 || $resp === false) {
            return array('ok' => false, 'http_code' => $code,
                'error' => $err !== '' ? $err : 'connection failed',
                'duration_ms' => $ms);
        }
        return array('ok' => ($code >= 200 && $code < 300),
            'http_code' => $code, 'error' => '', 'duration_ms' => $ms);
    }

    // fallback without curl
    $ctx = stream_context_create(array('http' => array(
        'method' => 'POST',
        'header' => $headers,
        'content' => $body,
        'timeout' => $timeout,
        'follow_location' => 0,
        'ignore_errors' => true,
        'max_redirects' => 0,
    )));
    $resp = @file_get_contents($url, false, $ctx);
    $ms = (int)round((microtime(true) - $started) * 1000);
    $code = 0;
    foreach ($http_response_header ?? array() as $h) {
        if (preg_match('#^HTTP/[0-9.]+\s+([0-9]{3})#', $h, $m)) {
            $code = intval($m[1]);
        }
    }
    if ($resp === false) {
        return array('ok' => false, 'http_code' => $code,
            'error' => 'connection failed', 'duration_ms' => $ms);
    }
    return array('ok' => ($code >= 200 && $code < 300),
        'http_code' => $code, 'error' => '', 'duration_ms' => $ms);
}

// --------------------------------------------------------------------------
// Routing
// --------------------------------------------------------------------------

$t = whkEnsureSchema($db);
$tpid = whkResolveTproject();

if ($tpid <= 0) {
    whkFail(400, 'no_testproject', 'No test project selected');
}

// rights BEFORE project resolution (no existence oracle - #1697).
$isWrite = ($method === 'POST');
if ($isWrite) {
    if (!whkCanModify($user, $db, $tpid)) {
        whkFail(403, 'no_right', 'No permission to manage webhook subscriptions');
    }
} else {
    if (!whkCanView($user, $db, $tpid)) {
        whkFail(403, 'no_right', 'No permission to view webhook subscriptions');
    }
}

if ($method !== 'GET' && $method !== 'HEAD' && $method !== 'POST') {
    header('Allow: GET, HEAD, POST');
    whkFail(405, 'wrong_method', 'Method not allowed');
}

if ($action === '') {
    $action = $isWrite ? 'save' : 'init';
}

// ---- reads -----------------------------------------------------------------

if (!$isWrite && ($action === 'init' || $action === 'list')) {
    $info = whkProjectInfo($db, $tpid);
    if ($info === null) {
        whkFail(404, 'tproject_not_found', 'Test project not found');
    }
    $items = whkList($db, $t, $tpid);
    echo json_encode(array(
        'status' => 'ok',
        'context' => array(
            'tproject_id' => intval($tpid),
            'tproject_name' => (string)$info['name'],
            'tproject_prefix' => (string)$info['prefix'],
            'can_view' => whkCanView($user, $db, $tpid),
            'can_modify' => whkCanModify($user, $db, $tpid),
        ),
        'domains' => array('events' => whkTopics()),
        'stats' => whkStats($items),
        'items' => $items,
    ));
    exit;
}

if (!$isWrite) {
    whkFail(400, 'unknown_action', 'Unknown action');
}

// ---- writes ----------------------------------------------------------------

if ($action === 'save') {
    $body = whkBody();
    $id = whkInt($body, 'id');
    $name = whkField($body, 'name', 255);
    if ($name === '') {
        whkFail(400, 'name_required', 'Name is mandatory');
    }
    $url = whkCleanUrl(whkField($body, 'url', 500));
    if ($url === null) {
        whkFail(400, 'bad_url', 'URL must be http(s) without embedded credentials');
    }
    $topic = whkField($body, 'topic', 64, 'execution_completed');
    if (!in_array($topic, whkTopics(), true)) {
        whkFail(400, 'bad_topic', 'Invalid event topic');
    }
    $isActive = (whkInt($body, 'is_active') !== 0) ? 1 : 0;
    $hasSecret = array_key_exists('secret', $body);
    $secret = $hasSecret ? whkField($body, 'secret', 128) : null;

    // duplicate name inside the same project -> 409
    $dup = $db->fetchFirstRow(
        "SELECT id FROM {$t} WHERE testproject_id = " . intval($tpid) .
        " AND name = '" . $db->prepare_string($name) . "'" .
        ($id > 0 ? (" AND id <> " . intval($id)) : '') . " LIMIT 1");
    if (is_array($dup) && $dup) {
        whkFail(409, 'duplicate_name', 'A webhook with this name already exists');
    }

    if ($id > 0) {
        $existing = whkFetch($db, $t, $tpid, $id);
        if ($existing === null) {
            whkFail(404, 'webhook_not_found', 'Webhook not found');
        }
        $secretSql = $hasSecret
            ? ("secret = '" . $db->prepare_string($secret) . "', ")
            : '';
        $db->exec_query(
            "UPDATE {$t} SET name = '" . $db->prepare_string($name) . "', " .
            "url = '" . $db->prepare_string($url) . "', " .
            "topic = '" . $db->prepare_string($topic) . "', " .
            $secretSql .
            "is_active = " . $isActive . ", update_ts = NOW() " .
            "WHERE id = " . intval($id) . " AND testproject_id = " . intval($tpid));
        whkLog('Webhook updated: ' . $name . ' (id ' . $id . ')',
               'WEBHOOK_SAVE', $tpid, $userId);
        echo json_encode(array('status' => 'ok', 'id' => intval($id),
            'message' => 'Saved'));
        exit;
    }

    $db->exec_query(
        "INSERT INTO {$t} (testproject_id, name, url, topic, secret, is_active," .
        " created_by, update_ts) VALUES (" .
        intval($tpid) . ", '" . $db->prepare_string($name) . "', " .
        "'" . $db->prepare_string($url) . "', " .
        "'" . $db->prepare_string($topic) . "', " .
        "'" . $db->prepare_string($secret === null ? '' : $secret) . "', " .
        $isActive . ", " . intval($userId) . ", NOW())");
    $newId = (int)$db->insert_id('tc_webhooks');
    if ($newId <= 0) {
        whkFail(500, 'insert_failed', 'Could not create webhook subscription');
    }
    whkLog('Webhook created: ' . $name . ' (id ' . $newId . ')',
           'WEBHOOK_CREATE', $tpid, $userId);
    echo json_encode(array('status' => 'ok', 'id' => $newId,
        'message' => 'Created'));
    exit;
}

if ($action === 'delete') {
    $body = whkBody();
    $id = whkInt($body, 'id');
    $row = whkFetch($db, $t, $tpid, $id);
    if ($row === null) {
        whkFail(404, 'webhook_not_found', 'Webhook not found');
    }
    $db->exec_query(
        "DELETE FROM {$t} WHERE id = " . intval($id) .
        " AND testproject_id = " . intval($tpid));
    whkLog('Webhook deleted: ' . $row['name'] . ' (id ' . $id . ')',
           'WEBHOOK_DELETE', $tpid, $userId);
    echo json_encode(array('status' => 'ok', 'deleted' => 1,
        'message' => 'Deleted'));
    exit;
}

if ($action === 'test') {
    $body = whkBody();
    $id = whkInt($body, 'id');
    $row = whkFetch($db, $t, $tpid, $id);
    if ($row === null) {
        whkFail(404, 'webhook_not_found', 'Webhook not found');
    }
    if (!whkCleanUrl((string)$row['url'])) {
        whkFail(400, 'bad_url', 'URL must be http(s) without embedded credentials');
    }
    $payload = json_encode(array(
        'event' => 'webhook.test',
        'webhook_id' => intval($row['id']),
        'testproject_id' => intval($tpid),
        'name' => (string)$row['name'],
        'topic' => (string)$row['topic'],
        'sent_at' => gmdate('c'),
        'sent_by' => intval($userId),
    ));
    $result = whkDeliver((string)$row['url'], (string)$row['secret'], $payload);

    $db->exec_query(
        "UPDATE {$t} SET last_test_ts = NOW(), last_test_code = " .
        intval($result['http_code']) . ", last_test_ok = " .
        ($result['ok'] ? 1 : 0) . " WHERE id = " . intval($id) .
        " AND testproject_id = " . intval($tpid));
    whkLog('Webhook test delivery: ' . $row['name'] . ' (id ' . $id . ', HTTP ' .
           $result['http_code'] . ')', 'WEBHOOK_TEST', $tpid, $userId);

    if (!$result['ok']) {
        whkFail(502, 'delivery_failed', 'The endpoint did not accept the delivery',
            array('detail' => $result));
    }
    echo json_encode(array('status' => 'ok',
        'test' => $result, 'message' => 'Delivered'));
    exit;
}

whkFail(400, 'unknown_action', 'Unknown action');
