<?php
/**
 * Test Environment Management BFF API
 * URL: /api/environments/
 * Plain PHP, no framework, no compilation.
 *
 * Backs the modern screen gui/templates/environments/testEnvironments.html
 * (Refs #1928, parent feature #1070). TestLink 1.9.20 manages test plans,
 * builds and platforms but has no first-class TEST ENVIRONMENT asset (a
 * definition of where/how a test runs: base URL, browser/device/OS, owner,
 * availability). This is a net-new, project-scoped registry.
 *
 * Routes (session based, JSON I/O):
 *   GET  ?action=init&tproject_id=N
 *        -> { status, context, domains, stats, items }
 *   GET  ?action=list&tproject_id=N
 *        -> { status, items }                  (same rows as init.items)
 *   POST ?action=save { tproject_id, id?, name, env_type, base_url,
 *                       platform, app_version, owner, contact,
 *                       dependencies, notes, status }
 *        -> { status, id }
 *   POST ?action=delete { tproject_id, id }
 *        -> { status, deleted }
 *
 * Rights model (checked on the ADDRESSED test project BEFORE it is resolved,
 * the #1697 lesson: no existence oracle):
 *   - reads  require mgt_view_tc OR mgt_modify_tc on the project
 *   - writes require mgt_modify_tc on the project
 *   - unauthenticated -> 401; unknown action -> 400; wrong verb -> 405;
 *     an id that is not in the project -> 404
 *
 * Writes emit AUDIT events (TEST_ENV_CREATE / TEST_ENV_SAVE / TEST_ENV_DELETE)
 * so the Event Viewer tracks the module activity.
 *
 * The tc_test_environments table is created lazily (CREATE TABLE IF NOT EXISTS)
 * so a freshly imported database works unchanged; the matching DDL is also part
 * of the installer schema.
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

// Enforce the legacy session inactivity timeout (issue #1614): a tab left open
// past config_get('sessionInactivityTimeout') must not keep reading/writing.
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
$action = isset($_REQUEST['action']) ? trim((string)$_REQUEST['action']) : '';

/** Allowed environment kinds. */
function tenvTypes() {
    return array('dev', 'qa', 'staging', 'preprod', 'prod_like');
}

/** Allowed availability statuses. */
function tenvStatuses() {
    return array('available', 'unavailable', 'unstable', 'maintenance');
}

/** Table name handling (respects the optional DB_TABLE_PREFIX). */
function tenvTable() {
    $t = tlObjectWithDB::getDBTables(array('tc_test_environments'));
    return $t['tc_test_environments'];
}

/** Idempotent lazy schema migration so a freshly imported DB works unchanged. */
function tenvEnsureSchema($db) {
    $t = tenvTable();
    $db->exec_query(
        "CREATE TABLE IF NOT EXISTS {$t} (" .
        " id INT UNSIGNED NOT NULL AUTO_INCREMENT," .
        " testproject_id INT UNSIGNED NOT NULL DEFAULT 0," .
        " name VARCHAR(255) NOT NULL DEFAULT ''," .
        " env_type VARCHAR(16) NOT NULL DEFAULT 'qa'," .
        " base_url VARCHAR(255) NOT NULL DEFAULT ''," .
        " platform VARCHAR(255) NOT NULL DEFAULT ''," .
        " app_version VARCHAR(64) NOT NULL DEFAULT ''," .
        " owner VARCHAR(128) NOT NULL DEFAULT ''," .
        " contact VARCHAR(255) NOT NULL DEFAULT ''," .
        " dependencies TEXT NULL," .
        " notes TEXT NULL," .
        " status VARCHAR(16) NOT NULL DEFAULT 'available'," .
        " created_by INT UNSIGNED NULL," .
        " creation_ts TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP," .
        " update_ts DATETIME NULL," .
        " PRIMARY KEY (id)," .
        " UNIQUE KEY uq_tenv_project_name (testproject_id, name)," .
        " KEY idx_tenv_project (testproject_id)," .
        " KEY idx_tenv_status (testproject_id, status)" .
        " ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    return $t;
}

/** Read the JSON request body (POST routes). */
function tenvBody() {
    static $cached = null;
    if ($cached === null) {
        $cached = json_decode(file_get_contents('php://input'), true);
        if (!is_array($cached)) { $cached = array(); }
    }
    return $cached;
}

/** Trim + length-cap a scalar payload/get field. */
function tenvField($input, $key, $maxLen, $default = '') {
    $v = $input[$key] ?? $default;
    $v = is_scalar($v) ? trim((string)$v) : '';
    return mb_substr($v, 0, $maxLen);
}

/** Strict positive int from GET/body, 0 when absent/invalid/non-scalar. */
function tenvInt($input, $key) {
    $v = $input[$key] ?? 0;
    return is_scalar($v) ? intval($v) : 0;
}

/** Resolve the addressed test project: GET param wins, body, then session. */
function tenvResolveTproject() {
    $tpid = tenvInt($_GET, 'tproject_id');
    if ($tpid <= 0) {
        $tpid = tenvInt(tenvBody(), 'tproject_id');
    }
    if ($tpid <= 0) {
        $tpid = intval($_SESSION['testprojectID'] ?? 0);
    }
    return $tpid;
}

/** Whether the user may read environments of a project. */
function tenvCanView($user, $db, $tpid) {
    foreach (array('mgt_view_tc', 'mgt_modify_tc') as $r) {
        if ($user->hasRight($db, $r, $tpid) === 'yes') { return true; }
    }
    return false;
}

/** Whether the user may write environments of a project. */
function tenvCanModify($user, $db, $tpid) {
    return ($user->hasRight($db, 'mgt_modify_tc', $tpid) === 'yes');
}

/** Project display name + prefix, or null when the project does not exist. */
function tenvProjectInfo($db, $tpid) {
    $tbl = tlObjectWithDB::getDBTables(array('testprojects', 'nodes_hierarchy'));
    $tp = $tbl['testprojects'];
    $nh = $tbl['nodes_hierarchy'];
    $row = $db->fetchFirstRow(
        "SELECT p.prefix AS prefix, nh.name AS name " .
        " FROM {$tp} p JOIN {$nh} nh ON nh.id = p.id " .
        " WHERE p.id = " . intval($tpid) . " AND nh.node_type_id = 1");
    return (is_array($row) && $row) ? $row : null;
}

/** List environments of a project (newest first). */
function tenvList($db, $t, $tpid) {
    $rows = (array)$db->get_recordset(
        "SELECT * FROM {$t} WHERE testproject_id = " . intval($tpid) .
        " ORDER BY name ASC, id ASC");
    $items = array();
    foreach ((array)$rows as $r) {
        $items[] = array(
            'id' => intval($r['id']),
            'name' => (string)$r['name'],
            'env_type' => (string)$r['env_type'],
            'base_url' => (string)$r['base_url'],
            'platform' => (string)$r['platform'],
            'app_version' => (string)$r['app_version'],
            'owner' => (string)$r['owner'],
            'contact' => (string)$r['contact'],
            'dependencies' => (string)($r['dependencies'] ?? ''),
            'notes' => (string)($r['notes'] ?? ''),
            'status' => (string)$r['status'],
            'created_by' => intval($r['created_by']),
            'creation_ts' => (string)($r['creation_ts'] ?? ''),
            'update_ts' => (string)($r['update_ts'] ?? ''),
        );
    }
    return $items;
}

/** Compute inventory stats from a list. */
function tenvStats($items) {
    $s = array('total' => count($items), 'available' => 0, 'unavailable' => 0,
               'unstable' => 0, 'maintenance' => 0, 'dev' => 0, 'qa' => 0,
               'staging' => 0, 'preprod' => 0, 'prod_like' => 0);
    foreach ($items as $it) {
        if (isset($s[$it['status']])) { $s[$it['status']]++; }
        if (isset($s[$it['env_type']])) { $s[$it['env_type']]++; }
    }
    return $s;
}

// --------------------------------------------------------------------------
// Routing
// --------------------------------------------------------------------------

$t = tenvEnsureSchema($db);
$tpid = tenvResolveTproject();

if ($tpid <= 0) {
    http_response_code(400);
    echo json_encode(array('status' => 'error', 'code' => 'no_testproject',
        'message' => 'No test project selected'));
    exit;
}

// Verb gate BEFORE the rights check, so an unsupported verb (PUT/DELETE/PATCH)
// always answers 405 regardless of the caller's rights.
if ($method !== 'GET' && $method !== 'POST') {
    header('Allow: GET, HEAD, POST');
    http_response_code(405);
    echo json_encode(array('status' => 'error', 'code' => 'wrong_method',
        'message' => 'Method not allowed'));
    exit;
}

// rights BEFORE project resolution (no existence oracle - #1697).
$isWrite = ($method === 'POST');
if ($isWrite) {
    if (!tenvCanModify($user, $db, $tpid)) {
        http_response_code(403);
        echo json_encode(array('status' => 'error', 'code' => 'no_right',
            'message' => 'No permission to modify test environments'));
        exit;
    }
} else {
    if (!tenvCanView($user, $db, $tpid)) {
        http_response_code(403);
        echo json_encode(array('status' => 'error', 'code' => 'no_right',
            'message' => 'No permission to view test environments'));
        exit;
    }
}

if ($action === '') {
    $action = $isWrite ? 'save' : 'init';
}

// ---- reads -----------------------------------------------------------------

if (!$isWrite && ($action === 'init' || $action === 'list')) {
    $info = tenvProjectInfo($db, $tpid);
    if ($info === null) {
        http_response_code(404);
        echo json_encode(array('status' => 'error', 'code' => 'tproject_not_found',
            'message' => 'Test project not found'));
        exit;
    }
    $items = tenvList($db, $t, $tpid);
    $payload = array(
        'status' => 'ok',
        'context' => array(
            'tproject_id' => intval($tpid),
            'tproject_name' => (string)$info['name'],
            'tproject_prefix' => (string)$info['prefix'],
            'can_view' => true,
            'can_modify' => tenvCanModify($user, $db, $tpid),
        ),
        'domains' => array(
            'types' => tenvTypes(),
            'statuses' => tenvStatuses(),
        ),
        'stats' => tenvStats($items),
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
    $body = tenvBody();
    $name = tenvField($body, 'name', 255);
    if ($name === '') {
        http_response_code(400);
        echo json_encode(array('status' => 'error', 'code' => 'name_required',
            'message' => 'Name is mandatory'));
        exit;
    }
    $envType = tenvField($body, 'env_type', 16, 'qa');
    if (!in_array($envType, tenvTypes(), true)) {
        http_response_code(400);
        echo json_encode(array('status' => 'error', 'code' => 'bad_env_type',
            'message' => 'Invalid environment type'));
        exit;
    }
    $status = tenvField($body, 'status', 16, 'available');
    if (!in_array($status, tenvStatuses(), true)) {
        http_response_code(400);
        echo json_encode(array('status' => 'error', 'code' => 'bad_status',
            'message' => 'Invalid status'));
        exit;
    }
    $baseUrl = tenvField($body, 'base_url', 255);
    $platform = tenvField($body, 'platform', 255);
    $appVersion = tenvField($body, 'app_version', 64);
    $owner = tenvField($body, 'owner', 128);
    $contact = tenvField($body, 'contact', 255);
    $dependencies = tenvField($body, 'dependencies', 20000);
    $notes = tenvField($body, 'notes', 20000);
    $id = tenvInt($body, 'id');

    // name unique per project (own name allowed on update)
    $dupe = $db->fetchFirstRow(
        "SELECT id FROM {$t} WHERE testproject_id = " . intval($tpid) .
        " AND name = '" . $db->prepare_string($name) . "'" .
        " AND id != " . intval($id));
    if (is_array($dupe) && $dupe) {
        http_response_code(409);
        echo json_encode(array('status' => 'error', 'code' => 'duplicate_name',
            'message' => 'An environment with this name already exists'));
        exit;
    }

    $vals =
        "'" . $db->prepare_string($name) . "', '" .
        $db->prepare_string($envType) . "', '" .
        $db->prepare_string($baseUrl) . "', '" .
        $db->prepare_string($platform) . "', '" .
        $db->prepare_string($appVersion) . "', '" .
        $db->prepare_string($owner) . "', '" .
        $db->prepare_string($contact) . "', '" .
        $db->prepare_string($dependencies) . "', '" .
        $db->prepare_string($notes) . "', '" .
        $db->prepare_string($status) . "'";

    if ($id > 0) {
        $chk = $db->fetchFirstRow(
            "SELECT id FROM {$t} WHERE id = " . intval($id) .
            " AND testproject_id = " . intval($tpid));
        if (!is_array($chk) || !$chk) {
            http_response_code(404);
            echo json_encode(array('status' => 'error', 'code' => 'env_not_found',
                'message' => 'Environment not found'));
            exit;
        }
        try {
            $db->exec_query(
                "UPDATE {$t} SET name = '" . $db->prepare_string($name) . "'," .
                " env_type = '" . $db->prepare_string($envType) . "'," .
                " base_url = '" . $db->prepare_string($baseUrl) . "'," .
                " platform = '" . $db->prepare_string($platform) . "'," .
                " app_version = '" . $db->prepare_string($appVersion) . "'," .
                " owner = '" . $db->prepare_string($owner) . "'," .
                " contact = '" . $db->prepare_string($contact) . "'," .
                " dependencies = '" . $db->prepare_string($dependencies) . "'," .
                " notes = '" . $db->prepare_string($notes) . "'," .
                " status = '" . $db->prepare_string($status) . "'," .
                " update_ts = NOW()" .
                " WHERE id = " . intval($id) . " AND testproject_id = " . intval($tpid));
        } catch (Exception $e) {
            // Refs #1932: exec_query() throws on any failure (database.class.php).
            // error_num() CANNOT be used here to detect the duplicate: exec_query
            // logs the ERROR event (tLog -> events INSERT on the SAME connection,
            // logger.class.php writeEvent) BEFORE throwing, and that successful
            // query resets the mysqli errno to 0 (measured: 0 at catch time).
            // Re-check the unique key instead - identical semantics to the
            // pre-check above.
            $dupe = $db->fetchFirstRow(
                "SELECT id FROM {$t} WHERE testproject_id = " . intval($tpid) .
                " AND name = '" . $db->prepare_string($name) . "'" .
                " AND id != " . intval($id));
            if (is_array($dupe) && $dupe) {
                http_response_code(409);
                echo json_encode(array('status' => 'error', 'code' => 'duplicate_name',
                    'message' => 'An environment with this name already exists'));
                exit;
            }
            http_response_code(500);
            echo json_encode(array('status' => 'error', 'code' => 'save_failed',
                'message' => 'Could not save environment'));
            exit;
        }

        $event = new stdClass();
        $event->message = 'Test environment saved: ' . $name . ' (id ' . $id . ')';
        $event->logLevel = 'AUDIT';
        $event->source = 'GUI';
        $event->objectID = $tpid;
        $event->objectType = 'testprojects';
        $event->code = 'TEST_ENV_SAVE';
        logEvent($event);

        echo json_encode(array('status' => 'ok', 'id' => intval($id),
            'message' => 'Saved'));
        exit;
    }

    try {
        $db->exec_query(
            "INSERT INTO {$t} (testproject_id, name, env_type, base_url, platform," .
            " app_version, owner, contact, dependencies, notes, status," .
            " created_by, update_ts) VALUES (" .
            intval($tpid) . ", " . $vals . ", " . intval($userId) . ", NOW())");
    } catch (Exception $e) {
        // Refs #1932: a concurrent create may have won the race and tripped the
        // uq_tenv_project_name key; exec_query() throws (database.class.php)
        // before insert_id() is ever read, so the old insert_id() <= 0 re-check
        // below was unreachable for the very case it handled. error_num() is
        // useless here - the ERROR event INSERT that exec_query() performs on
        // the same connection (logger.class.php writeEvent) resets the mysqli
        // errno to 0 before the throw (measured: errno=0 at catch time) - so the
        // unique key is re-checked directly, exactly like the pre-check above.
        $dupe = $db->fetchFirstRow(
            "SELECT id FROM {$t} WHERE testproject_id = " . intval($tpid) .
            " AND name = '" . $db->prepare_string($name) . "'");
        if (is_array($dupe) && $dupe) {
            http_response_code(409);
            echo json_encode(array('status' => 'error', 'code' => 'duplicate_name',
                'message' => 'An environment with this name already exists'));
            exit;
        }
        http_response_code(500);
        echo json_encode(array('status' => 'error', 'code' => 'insert_failed',
            'message' => 'Could not create environment'));
        exit;
    }
    $newId = (int)$db->insert_id($t);
    if ($newId <= 0) {
        // Defensive fallback: exec_query() reported success but no id came
        // back - re-check the unique key before reporting a hard failure.
        $dupe = $db->fetchFirstRow(
            "SELECT id FROM {$t} WHERE testproject_id = " . intval($tpid) .
            " AND name = '" . $db->prepare_string($name) . "'");
        if (is_array($dupe) && $dupe) {
            http_response_code(409);
            echo json_encode(array('status' => 'error', 'code' => 'duplicate_name',
                'message' => 'An environment with this name already exists'));
            exit;
        }
        http_response_code(500);
        echo json_encode(array('status' => 'error', 'code' => 'insert_failed',
            'message' => 'Could not create environment'));
        exit;
    }

    $event = new stdClass();
    $event->message = 'Test environment created: ' . $name . ' (id ' . $newId . ')';
    $event->logLevel = 'AUDIT';
    $event->source = 'GUI';
    $event->objectID = $tpid;
    $event->objectType = 'testprojects';
    $event->code = 'TEST_ENV_CREATE';
    logEvent($event);

    echo json_encode(array('status' => 'ok', 'id' => $newId, 'message' => 'Created'));
    exit;
}

if ($action === 'delete') {
    $body = tenvBody();
    $id = tenvInt($body, 'id');
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(array('status' => 'error', 'code' => 'bad_id',
            'message' => 'id is mandatory'));
        exit;
    }
    $chk = $db->fetchFirstRow(
        "SELECT name FROM {$t} WHERE id = " . intval($id) .
        " AND testproject_id = " . intval($tpid));
    if (!is_array($chk) || !$chk) {
        http_response_code(404);
        echo json_encode(array('status' => 'error', 'code' => 'env_not_found',
            'message' => 'Environment not found'));
        exit;
    }
    $db->exec_query(
        "DELETE FROM {$t} WHERE id = " . intval($id) .
        " AND testproject_id = " . intval($tpid));

    $event = new stdClass();
    $event->message = 'Test environment deleted: ' . (string)$chk['name'] .
                      ' (id ' . $id . ')';
    $event->logLevel = 'AUDIT';
    $event->source = 'GUI';
    $event->objectID = $tpid;
    $event->objectType = 'testprojects';
    $event->code = 'TEST_ENV_DELETE';
    logEvent($event);

    echo json_encode(array('status' => 'ok', 'deleted' => 1, 'message' => 'Deleted'));
    exit;
}

http_response_code(400);
echo json_encode(array('status' => 'error', 'code' => 'unknown_action',
    'message' => 'Unknown action'));
