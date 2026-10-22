<?php
/**
 * API Access Tokens BFF API (Refs #1958, parent feature #1072).
 * URL: /api/apitokens/
 *
 * Central management of the two API credentials TestLink already ships:
 *   - the caller's personal token (users.script_key, 32 hex chars) used by
 *     XML-RPC / REST remote access;
 *   - each test plan's API key (testplans.api_key, 64 hex chars) used by the
 *     public share-link / automation endpoints.
 * 1.9.20 exposed neither: the personal token had no UI at all, the plan key
 * only appeared inside the plan Edit form. This screen lets a user see, copy
 * and rotate their own token and lets a plan manager rotate a plan key.
 *
 * Routes (session based, JSON I/O):
 *   GET|HEAD ?action=init
 *        -> { status, context, stats, plans:[{id,name,project_id,project,
 *             key,can_rotate,active}], rights }
 *   POST ?action=rotate_user
 *        -> { status, token }
 *   POST ?action=rotate_plan  { tplan_id }
 *        -> { status, tplan_id, api_key }
 *
 * Security: session auth + bffSameOriginGuard() + bffEnforceSession();
 * the personal token can only be rotated for the CALLER (never a user id from
 * the request); a plan key is only listed/rotated for a caller holding
 * testplan_planning on the plan's owning project. Unknown plans are an opaque 404
 * after the rights probe (no existence oracle, #1697 lesson). All writes emit
 * AUDIT events. Stable machine codes with 400/401/403/404/405/500.
 */
require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

set_exception_handler(function ($e) {
    error_log('api/apitokens: ' . $e->getMessage());
    if (!headers_sent()) { http_response_code(500); }
    echo json_encode(array('status' => 'error', 'code' => 'server_error',
        'message' => 'Internal error'));
    exit;
});

function out($data, $code = null) {
    if ($code !== null && !headers_sent()) { http_response_code($code); }
    echo json_encode($data);
    exit;
}

function tokBody() {
    static $cached = null;
    if ($cached === null) {
        $cached = json_decode(file_get_contents('php://input'), true);
        if (!is_array($cached)) { $cached = array(); }
    }
    return $cached;
}

function tokInt($input, $key) {
    $v = $input[$key] ?? 0;
    return is_scalar($v) ? intval($v) : 0;
}

/** Plan/project display names live in nodes_hierarchy (2.0.1 schema). */
function tokPlanRows($db) {
    $t = tlObjectWithDB::getDBTables(array('testplans', 'nodes_hierarchy'));
    $tp = $t['testplans'];
    $nh = $t['nodes_hierarchy'];
    return (array)$db->get_recordset(
        "SELECT tp.id, tp.testproject_id, tp.active, tp.is_public," .
        " tp.api_key, nh.name AS plan_name, pnh.name AS project_name" .
        " FROM {$tp} tp" .
        " LEFT JOIN {$nh} nh ON nh.id = tp.id" .
        " LEFT JOIN {$nh} pnh ON pnh.id = tp.testproject_id AND pnh.node_type_id = 1" .
        " ORDER BY pnh.name, nh.name, tp.id");
}

function tokLog($code, $message, $objectId, $objectType) {
    $event = new stdClass();
    $event->message = $message;
    $event->logLevel = 'AUDIT';
    $event->source = 'GUI';
    $event->objectID = intval($objectId);
    $event->objectType = $objectType;
    $event->code = $code;
    logEvent($event);
}

function tokNewUserToken() {
    return bin2hex(random_bytes(16));
}

$db = null;
doDBConnect($db);
bffEnforceSession($db);

$userId = intval($_SESSION['userID'] ?? 0);
if ($userId <= 0) {
    out(array('status' => 'error', 'code' => 'not_authenticated',
              'message' => 'Not authenticated'), 401);
}
$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    out(array('status' => 'error', 'code' => 'user_not_found',
              'message' => 'User not found'), 401);
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = (isset($_REQUEST['action']) && is_scalar($_REQUEST['action']))
    ? trim((string)$_REQUEST['action']) : '';

if ($method !== 'GET' && $method !== 'HEAD' && $method !== 'POST') {
    if (!headers_sent()) { header('Allow: GET, HEAD, POST'); }
    out(array('status' => 'error', 'code' => 'method_not_allowed',
              'message' => 'Method not allowed'), 405);
}

if ($method === 'GET' || $method === 'HEAD') {
    if ($action !== 'init') {
        out(array('status' => 'error', 'code' => 'unknown_action',
                  'message' => 'Unknown action'), 400);
    }

    $tables = tlObjectWithDB::getDBTables(array('users'));
    $row = $db->fetchFirstRow(
        "SELECT script_key FROM {$tables['users']} WHERE id = " . intval($userId));
    $token = is_array($row) ? trim((string)($row['script_key'] ?? '')) : '';
    if ($token === '') {
        $token = trim((string)($user->userApiKey ?? ''));
    }

    $plans = array();
    foreach (tokPlanRows($db) as $r) {
        $tpid = intval($r['testproject_id']);
        $tplanId = intval($r['id']);
        $canRotate = ($user->hasRight($db, 'testplan_planning', $tpid, $tplanId) === 'yes');
        if (!$canRotate) { continue; }
        $plans[] = array(
            'id' => $tplanId,
            'name' => (string)($r['plan_name'] ?? ('#' . $tplanId)),
            'project_id' => $tpid,
            'project' => (string)($r['project_name'] ?? ('#' . $tpid)),
            'active' => intval($r['active']),
            'is_public' => intval($r['is_public']),
            'api_key' => (string)($r['api_key'] ?? ''),
            'can_rotate' => true,
        );
    }

    out(array(
        'status' => 'ok',
        'context' => array(
            'user_id' => $userId,
            'user_login' => (string)($user->login ?? ''),
            'email' => (string)($user->emailAddress ?? ''),
        ),
        'stats' => array(
            'plans' => count($plans),
            'personal_token_present' => ($token !== ''),
        ),
        'user_token' => $token,
        'plans' => $plans,
        'rights' => array('can_rotate_user' => true),
    ));
}

// ---- POST writes ---------------------------------------------------------
$body = tokBody();
if ($action === 'rotate_user') {
    $token = tokNewUserToken();
    $tables = tlObjectWithDB::getDBTables(array('users'));
    $db->exec_query(
        "UPDATE {$tables['users']} SET script_key = '" .
        $db->prepare_string($token) . "' WHERE id = " . intval($userId));
    tokLog('API_TOKEN_ROTATE_USER', 'Personal API token rotated', $userId, 'users');
    out(array('status' => 'ok', 'token' => $token));
}

if ($action === 'rotate_plan') {
    $tplanId = tokInt($body, 'tplan_id');
    if ($tplanId <= 0) { $tplanId = tokInt($_GET, 'tplan_id'); }
    $tpid = tokInt($body, 'project_id');
    if ($tpid <= 0) { $tpid = tokInt($_GET, 'project_id'); }
    if ($tplanId <= 0 || $tpid <= 0) {
        out(array('status' => 'error', 'code' => 'invalid_request',
                  'message' => 'Missing tplan_id or project_id'), 400);
    }

    // Rights are probed on the ADDRESSED project BEFORE the plan is resolved
    // (the #1697 no-oracle lesson): without the right, the answer is an opaque
    // 404 whether or not the plan exists.
    if ($user->hasRight($db, 'testplan_planning', $tpid, $tplanId) !== 'yes') {
        out(array('status' => 'error', 'code' => 'tplan_not_found',
                  'message' => 'Test plan not found'), 404);
    }

    $t = tlObjectWithDB::getDBTables(array('testplans', 'nodes_hierarchy'));
    $row = $db->fetchFirstRow(
        "SELECT tp.id, tp.testproject_id FROM {$t['testplans']} tp" .
        " WHERE tp.id = " . intval($tplanId) .
        " AND tp.testproject_id = " . intval($tpid));
    if (!is_array($row) || intval($row['id']) <= 0) {
        out(array('status' => 'error', 'code' => 'tplan_not_found',
                  'message' => 'Test plan not found'), 404);
    }
    $tpid = intval($row['testproject_id']);

    // testplans.api_key is UNIQUE; retry on the (astronomically unlikely)
    // collision instead of surfacing a DB error.
    $key = '';
    for ($i = 0; $i < 5; $i++) {
        $candidate = bin2hex(random_bytes(32));
        $hit = $db->fetchFirstRow(
            "SELECT COUNT(*) AS n FROM {$t['testplans']}" .
            " WHERE api_key = '" . $db->prepare_string($candidate) . "'");
        if (!is_array($hit) || intval($hit['n'] ?? 0) === 0) { $key = $candidate; break; }
    }
    if ($key === '') {
        out(array('status' => 'error', 'code' => 'server_error',
                  'message' => 'Could not allocate a unique key'), 500);
    }
    $db->exec_query(
        "UPDATE {$t['testplans']} SET api_key = '" . $db->prepare_string($key) .
        "' WHERE id = " . intval($tplanId));
    tokLog('API_TOKEN_ROTATE_PLAN', 'Test plan API key rotated', $tplanId, 'testplans');
    out(array('status' => 'ok', 'tplan_id' => $tplanId, 'api_key' => $key));
}

out(array('status' => 'error', 'code' => 'unknown_action',
          'message' => 'Unknown action'), 400);
