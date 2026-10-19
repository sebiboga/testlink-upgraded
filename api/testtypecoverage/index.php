<?php
/**
 * Test Type Coverage matrix BFF API
 * URL: /api/testtypecoverage/
 * Plain PHP, no framework, no compilation.
 *
 * Backs the modern screen gui/templates/strategy/testTypeCoverage.html
 * (Refs #1945, parent feature #1052). TestLink 1.9.20 ships no test-type
 * tracking entity at all: the Test Strategy area only has the STATIC prose
 * chapter gui/templates/strategy/testTypes.html, so "which test types does
 * this project actually apply?" was never capturable. This is a net-new,
 * project-scoped coverage matrix over the 13 canonical test types of the
 * #1052 catalogue (6 functional + 7 non-functional); each type carries a
 * status (not_planned / planned / in_progress / done / not_applicable), a
 * description and an owner. The canonical catalogue is emitted by the BFF
 * (defaults()), stored rows OVERLAY it, and a stored row can be reset
 * (delete) to fall back to its default values.
 *
 * Routes (session based, JSON I/O):
 *   GET  ?action=init&tproject_id=N
 *        -> { status, context, domains, stats, items }
 *   GET  ?action=list&tproject_id=N
 *        -> { status, items }                  (same rows as init.items)
 *   POST ?action=save { tproject_id, type_key, status, description, owner }
 *        -> { status, type_key }               (upsert by type_key)
 *   POST ?action=delete { tproject_id, type_key }
 *        -> { status, deleted }                (reset to defaults)
 *
 * Rights model (checked on the ADDRESSED test project BEFORE it is resolved,
 * the #1697 lesson: no existence oracle):
 *   - reads  require mgt_view_tc OR mgt_modify_tc on the project
 *   - writes require mgt_modify_tc on the project
 *   - unauthenticated -> 401; unknown action -> 400; wrong verb -> 405;
 *     an unknown/non-catalogue type_key -> 404; bad status -> 400;
 *     a nonexistent test project -> 404 (reads and writes alike)
 *
 * Writes are a true upsert: when the INSERT loses the race on
 * uq_ttc_project_type the save falls back to the UPDATE path, and any uncaught
 * exception is mapped by set_exception_handler() to a JSON 500 server_error.
 *
 * Writes emit AUDIT events (TESTTYPE_SAVE / TESTTYPE_RESET) so the Event
 * Viewer tracks the module activity.
 *
 * The tc_test_type_coverage table is created lazily (CREATE TABLE IF NOT
 * EXISTS) so a freshly imported database works unchanged; the matching DDL is
 * also part of the installer schema.
 */
require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

// Same convention as the other BFFs: any uncaught DB/PHP exception must answer
// the documented JSON contract instead of an HTML 500 (database.class.php
// throws whenever X-Requested-With is set on the request).
set_exception_handler(function ($e) {
    error_log('api/testtypecoverage: ' . $e->getMessage());
    if (!headers_sent()) { http_response_code(500); }
    echo json_encode(array('status' => 'error', 'code' => 'server_error',
        'message' => 'Internal error'));
    exit;
});

$db = null;
doDBConnect($db);

// Enforce the legacy session inactivity timeout (issue #1614).
bffEnforceSession($db);

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
// is_scalar(): action[]=x would otherwise emit a PHP 8 "Array to string
// conversion" warning INTO the JSON body.
$action = (isset($_REQUEST['action']) && is_scalar($_REQUEST['action']))
    ? trim((string)$_REQUEST['action']) : '';

/** Allowed coverage statuses. */
function ttcStatuses() {
    return array('not_planned', 'planned', 'in_progress', 'done', 'not_applicable');
}

/**
 * The canonical #1052 test-type catalogue: key -> { group, default label }.
 * 6 functional + 7 non-functional types, in the order of the issue text.
 */
function ttcCatalog() {
    return array(
        'functional'      => array('group' => 'functional',      'label' => 'Functional testing'),
        'integration'     => array('group' => 'functional',      'label' => 'Integration testing'),
        'system'          => array('group' => 'functional',      'label' => 'System testing'),
        'e2e'             => array('group' => 'functional',      'label' => 'End-to-end testing'),
        'regression'      => array('group' => 'functional',      'label' => 'Regression testing'),
        'uat'             => array('group' => 'functional',      'label' => 'User Acceptance Testing (UAT)'),
        'performance'     => array('group' => 'non_functional',  'label' => 'Performance testing'),
        'security'        => array('group' => 'non_functional',  'label' => 'Security testing'),
        'usability'       => array('group' => 'non_functional',  'label' => 'Usability testing'),
        'accessibility'   => array('group' => 'non_functional',  'label' => 'Accessibility testing'),
        'compatibility'   => array('group' => 'non_functional',  'label' => 'Compatibility testing'),
        'reliability'     => array('group' => 'non_functional',  'label' => 'Reliability testing'),
        'maintainability' => array('group' => 'non_functional',  'label' => 'Maintainability testing'),
    );
}

/** Table name handling (respects the optional DB_TABLE_PREFIX). */
function ttcTable() {
    $t = tlObjectWithDB::getDBTables(array('tc_test_type_coverage'));
    return $t['tc_test_type_coverage'];
}

/** Idempotent lazy schema migration so a freshly imported DB works unchanged. */
function ttcEnsureSchema($db) {
    $t = ttcTable();
    $db->exec_query(
        "CREATE TABLE IF NOT EXISTS {$t} (" .
        " id INT UNSIGNED NOT NULL AUTO_INCREMENT," .
        " testproject_id INT UNSIGNED NOT NULL DEFAULT 0," .
        " type_key VARCHAR(64) NOT NULL DEFAULT ''," .
        " status VARCHAR(16) NOT NULL DEFAULT 'not_planned'," .
        " description TEXT NULL," .
        " owner VARCHAR(128) NOT NULL DEFAULT ''," .
        " created_by INT UNSIGNED NULL," .
        " creation_ts TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP," .
        " update_ts DATETIME NULL," .
        " PRIMARY KEY (id)," .
        " UNIQUE KEY uq_ttc_project_type (testproject_id, type_key)," .
        " KEY idx_ttc_project (testproject_id)," .
        " KEY idx_ttc_status (testproject_id, status)" .
        " ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    return $t;
}

/** Read the JSON request body (POST routes). */
function ttcBody() {
    static $cached = null;
    if ($cached === null) {
        $cached = json_decode(file_get_contents('php://input'), true);
        if (!is_array($cached)) { $cached = array(); }
    }
    return $cached;
}

/** Trim + length-cap a scalar payload/get field. */
function ttcField($input, $key, $maxLen, $default = '') {
    $v = $input[$key] ?? $default;
    $v = is_scalar($v) ? trim((string)$v) : '';
    return mb_substr($v, 0, $maxLen);
}

/** Strict positive int from GET/body, 0 when absent/invalid/non-scalar. */
function ttcInt($input, $key) {
    $v = $input[$key] ?? 0;
    return is_scalar($v) ? intval($v) : 0;
}

/** Resolve the addressed test project: GET param wins, body, then session. */
function ttcResolveTproject() {
    $tpid = ttcInt($_GET, 'tproject_id');
    if ($tpid <= 0) {
        $tpid = ttcInt(ttcBody(), 'tproject_id');
    }
    if ($tpid <= 0) {
        $tpid = intval($_SESSION['testprojectID'] ?? 0);
    }
    return $tpid;
}

/** Whether the user may read the coverage matrix of a project. */
function ttcCanView($user, $db, $tpid) {
    foreach (array('mgt_view_tc', 'mgt_modify_tc') as $r) {
        if ($user->hasRight($db, $r, $tpid) === 'yes') { return true; }
    }
    return false;
}

/** Whether the user may write the coverage matrix of a project. */
function ttcCanModify($user, $db, $tpid) {
    return ($user->hasRight($db, 'mgt_modify_tc', $tpid) === 'yes');
}

/** Project display name + prefix, or null when the project does not exist. */
function ttcProjectInfo($db, $tpid) {
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
 * The coverage matrix: canonical catalogue rows in catalogue order, each
 * overlaid with the project's stored row when one exists.
 */
function ttcList($db, $t, $tpid) {
    $catalog = ttcCatalog();
    $stored = array();
    $rows = (array)$db->get_recordset(
        "SELECT * FROM {$t} WHERE testproject_id = " . intval($tpid));
    foreach ((array)$rows as $r) {
        $stored[(string)$r['type_key']] = $r;
    }
    $items = array();
    foreach ($catalog as $key => $meta) {
        $s = $stored[$key] ?? null;
        $is_stored = (is_array($s) && $s);
        $status = $is_stored ? (string)$s['status'] : 'not_planned';
        if (!in_array($status, ttcStatuses(), true)) { $status = 'not_planned'; }
        $items[] = array(
            'id' => $is_stored ? intval($s['id']) : 0,
            'type_key' => $key,
            'group' => (string)$meta['group'],
            'label' => (string)$meta['label'],
            'status' => $status,
            'description' => $is_stored ? (string)($s['description'] ?? '') : '',
            'owner' => $is_stored ? (string)($s['owner'] ?? '') : '',
            'is_stored' => $is_stored ? 1 : 0,
            'created_by' => $is_stored ? intval($s['created_by']) : 0,
            'creation_ts' => $is_stored ? (string)($s['creation_ts'] ?? '') : '',
            'update_ts' => $is_stored ? (string)($s['update_ts'] ?? '') : '',
        );
    }
    return $items;
}

/** Compute matrix stats from a list. */
function ttcStats($items) {
    $s = array('total' => count($items), 'not_planned' => 0, 'planned' => 0,
               'in_progress' => 0, 'done' => 0, 'not_applicable' => 0,
               'tracked' => 0, 'functional' => 0, 'non_functional' => 0);
    foreach ($items as $it) {
        if (isset($s[$it['status']])) { $s[$it['status']]++; }
        if ($it['status'] !== 'not_planned') { $s['tracked']++; }
        if (isset($s[$it['group']])) { $s[$it['group']]++; }
    }
    return $s;
}

/** UPDATE an existing coverage row (shared by the save and the race-fallback). */
function ttcUpdateRow($db, $t, $id, $tpid, $status, $description, $owner) {
    $db->exec_query(
        "UPDATE {$t} SET status = '" . $db->prepare_string($status) . "'," .
        " description = '" . $db->prepare_string($description) . "'," .
        " owner = '" . $db->prepare_string($owner) . "'," .
        " update_ts = NOW()" .
        " WHERE id = " . intval($id) .
        " AND testproject_id = " . intval($tpid));
}

/** Fire the AUDIT event of a successful save/reset. */
function ttcLog($code, $message, $tpid) {
    $event = new stdClass();
    $event->message = $message;
    $event->logLevel = 'AUDIT';
    $event->source = 'GUI';
    $event->objectID = intval($tpid);
    $event->objectType = 'testprojects';
    $event->code = $code;
    logEvent($event);
}

// --------------------------------------------------------------------------
// Routing
// --------------------------------------------------------------------------

$t = ttcEnsureSchema($db);
$tpid = ttcResolveTproject();

// Verb gate FIRST, so an unsupported verb (PUT/DELETE/PATCH) always answers
// 405 regardless of the caller's rights or the selected project (doc contract
// says wrong verb -> 405). HEAD is advertised in Allow and is a plain read.
if ($method !== 'GET' && $method !== 'HEAD' && $method !== 'POST') {
    header('Allow: GET, HEAD, POST');
    http_response_code(405);
    echo json_encode(array('status' => 'error', 'code' => 'wrong_method',
        'message' => 'Method not allowed'));
    exit;
}

if ($tpid <= 0) {
    http_response_code(400);
    echo json_encode(array('status' => 'error', 'code' => 'no_testproject',
        'message' => 'No test project selected'));
    exit;
}

// rights BEFORE project resolution (no existence oracle - #1697).
$isWrite = ($method === 'POST');
if ($isWrite) {
    if (!ttcCanModify($user, $db, $tpid)) {
        http_response_code(403);
        echo json_encode(array('status' => 'error', 'code' => 'no_right',
            'message' => 'No permission to modify the test type coverage matrix'));
        exit;
    }
} else {
    if (!ttcCanView($user, $db, $tpid)) {
        http_response_code(403);
        echo json_encode(array('status' => 'error', 'code' => 'no_right',
            'message' => 'No permission to view the test type coverage matrix'));
        exit;
    }
}

if ($action === '') {
    $action = $isWrite ? 'save' : 'init';
}

// Writes must address a REAL project too (rights were checked above, so this
// leaks nothing): without it a bogus id would insert an orphan row and answer ok.
if ($isWrite && ttcProjectInfo($db, $tpid) === null) {
    http_response_code(404);
    echo json_encode(array('status' => 'error', 'code' => 'tproject_not_found',
        'message' => 'Test project not found'));
    exit;
}

// ---- reads -----------------------------------------------------------------

if (!$isWrite && ($action === 'init' || $action === 'list')) {
    $info = ttcProjectInfo($db, $tpid);
    if ($info === null) {
        http_response_code(404);
        echo json_encode(array('status' => 'error', 'code' => 'tproject_not_found',
            'message' => 'Test project not found'));
        exit;
    }
    $items = ttcList($db, $t, $tpid);
    $payload = array(
        'status' => 'ok',
        'context' => array(
            'tproject_id' => intval($tpid),
            'tproject_name' => (string)$info['name'],
            'tproject_prefix' => (string)$info['prefix'],
            'can_view' => true,
            'can_modify' => ttcCanModify($user, $db, $tpid),
        ),
        'domains' => array(
            'statuses' => ttcStatuses(),
            'groups' => array('functional', 'non_functional'),
        ),
        'stats' => ttcStats($items),
        'items' => $items,
    );
    echo json_encode($payload);
    exit;
}

if (!$isWrite) {
    http_response_code(400);
    echo json_encode(array('status' => 'error', 'code' => 'unknown_action',
        'message' => 'Unknown action'));
    exit;
}

// ---- writes ----------------------------------------------------------------

if ($action === 'save') {
    $body = ttcBody();
    $typeKey = ttcField($body, 'type_key', 64);
    $catalog = ttcCatalog();
    if ($typeKey === '' || !isset($catalog[$typeKey])) {
        http_response_code(404);
        echo json_encode(array('status' => 'error', 'code' => 'type_not_found',
            'message' => 'Unknown test type'));
        exit;
    }
    $status = ttcField($body, 'status', 16, 'not_planned');
    if (!in_array($status, ttcStatuses(), true)) {
        http_response_code(400);
        echo json_encode(array('status' => 'error', 'code' => 'bad_status',
            'message' => 'Invalid status'));
        exit;
    }
    // TEXT column = 65535 BYTES, not chars: a 4-byte emoji makes a naive
    // 20000-char cap overflow the column and raise a strict-mode error.
    $description = ttcField($body, 'description', 15000);
    if (strlen($description) > 65000) {
        $description = mb_strcut($description, 0, 65000, 'UTF-8');
    }
    $owner = ttcField($body, 'owner', 128);

    $existing = $db->fetchFirstRow(
        "SELECT id FROM {$t} WHERE testproject_id = " . intval($tpid) .
        " AND type_key = '" . $db->prepare_string($typeKey) . "'");

    if (is_array($existing) && $existing) {
        try {
            ttcUpdateRow($db, $t, $existing['id'], $tpid, $status, $description, $owner);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(array('status' => 'error', 'code' => 'save_failed',
                'message' => 'Could not save the test type'));
            exit;
        }

        ttcLog('TESTTYPE_SAVE', 'Test type saved: ' . $typeKey . ' -> ' . $status .
            ' (project ' . $tpid . ')', $tpid);

        echo json_encode(array('status' => 'ok', 'type_key' => $typeKey,
            'id' => intval($existing['id']), 'message' => 'Saved'));
        exit;
    }

    try {
        $db->exec_query(
            "INSERT INTO {$t} (testproject_id, type_key, status, description," .
            " owner, created_by, update_ts) VALUES (" .
            intval($tpid) . ", '" . $db->prepare_string($typeKey) . "', '" .
            $db->prepare_string($status) . "', '" .
            $db->prepare_string($description) . "', '" .
            $db->prepare_string($owner) . "', " . intval($userId) . ", NOW())");
    } catch (Exception $e) {
        // A concurrent create may have won the race on uq_ttc_project_type.
        // This endpoint IS an upsert, so fall back to the UPDATE path instead
        // of failing a perfectly valid double-submitted Save with 409.
        $dupe = $db->fetchFirstRow(
            "SELECT id FROM {$t} WHERE testproject_id = " . intval($tpid) .
            " AND type_key = '" . $db->prepare_string($typeKey) . "'");
        if (is_array($dupe) && $dupe) {
            try {
                ttcUpdateRow($db, $t, $dupe['id'], $tpid, $status, $description, $owner);
            } catch (Exception $e2) {
                http_response_code(500);
                echo json_encode(array('status' => 'error', 'code' => 'save_failed',
                    'message' => 'Could not save the test type'));
                exit;
            }
            ttcLog('TESTTYPE_SAVE', 'Test type saved: ' . $typeKey . ' -> ' . $status .
                ' (project ' . $tpid . ')', $tpid);
            echo json_encode(array('status' => 'ok', 'type_key' => $typeKey,
                'id' => intval($dupe['id']), 'message' => 'Saved'));
            exit;
        }
        http_response_code(500);
        echo json_encode(array('status' => 'error', 'code' => 'insert_failed',
            'message' => 'Could not create the test type row'));
        exit;
    }
    $newId = (int)$db->insert_id($t);
    if ($newId <= 0) {
        http_response_code(500);
        echo json_encode(array('status' => 'error', 'code' => 'insert_failed',
            'message' => 'Could not create the test type row'));
        exit;
    }

    ttcLog('TESTTYPE_SAVE', 'Test type tracked: ' . $typeKey . ' -> ' . $status .
        ' (project ' . $tpid . ')', $tpid);

    echo json_encode(array('status' => 'ok', 'type_key' => $typeKey,
        'id' => $newId, 'message' => 'Created'));
    exit;
}

if ($action === 'delete') {
    $body = ttcBody();
    $typeKey = ttcField($body, 'type_key', 64);
    $catalog = ttcCatalog();
    if ($typeKey === '' || !isset($catalog[$typeKey])) {
        http_response_code(404);
        echo json_encode(array('status' => 'error', 'code' => 'type_not_found',
            'message' => 'Unknown test type'));
        exit;
    }
    $chk = $db->fetchFirstRow(
        "SELECT id FROM {$t} WHERE testproject_id = " . intval($tpid) .
        " AND type_key = '" . $db->prepare_string($typeKey) . "'");
    if (!is_array($chk) || !$chk) {
        http_response_code(404);
        echo json_encode(array('status' => 'error', 'code' => 'not_tracked',
            'message' => 'This test type is not tracked yet'));
        exit;
    }
    try {
        $db->exec_query(
            "DELETE FROM {$t} WHERE id = " . intval($chk['id']) .
            " AND testproject_id = " . intval($tpid));
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(array('status' => 'error', 'code' => 'delete_failed',
            'message' => 'Could not reset the test type'));
        exit;
    }

    ttcLog('TESTTYPE_RESET', 'Test type reset to defaults: ' . $typeKey .
        ' (project ' . $tpid . ')', $tpid);

    echo json_encode(array('status' => 'ok', 'deleted' => 1,
        'type_key' => $typeKey, 'message' => 'Reset'));
    exit;
}

http_response_code(400);
echo json_encode(array('status' => 'error', 'code' => 'unknown_action',
    'message' => 'Unknown action'));
