<?php
/**
 * Editable Test Strategy document BFF API
 * URL: /api/strategydoc/
 * Plain PHP, no framework, no compilation.
 *
 * Backs the modern screen gui/templates/strategy/strategyDoc.html
 * (Refs #1943, parent feature #1052). TestLink 1.9.20 (and 2.0.1 so far)
 * ships only the STATIC Test Strategy reference hub, the GENERATED strategy
 * report (#1925), and the exit-criteria/environments registries: a team can
 * never AUTHOR and MAINTAIN its own Test Strategy. This is the missing
 * project-scoped Test Strategy document: name + status workflow
 * (draft -> review -> approved -> archived) + 9 structured sections stored as
 * a JSON blob; every content save bumps the version counter.
 *
 * Routes (session based, JSON I/O):
 *   GET  ?action=init&tproject_id=N
 *        -> { status, context, domains, stats, items }
 *   GET  ?action=list&tproject_id=N
 *        -> { status, items }                  (same rows as init.items)
 *   GET  ?action=doc&tproject_id=N&id=M
 *        -> { status, doc }                    (full sections)
 *   POST ?action=save { tproject_id, id?, name, status?, sections{...} }
 *        -> { status, id, version }
 *   POST ?action=status { tproject_id, id, status }
 *        -> { status, id, status: <new> }
 *   POST ?action=delete { tproject_id, id }
 *        -> { status, deleted }
 *
 * Rights model (checked on the ADDRESSED test project BEFORE it is resolved,
 * the #1697 lesson: no existence oracle):
 *   - reads  require mgt_view_tc OR mgt_modify_tc on the project
 *   - writes require mgt_modify_tc on the project
 *   - unauthenticated -> 401; unknown action -> 400; wrong verb -> 405;
 *     an id that is not in the project -> 404; duplicate name -> 409
 *
 * Writes emit AUDIT events (STRATEGY_DOC_CREATE / STRATEGY_DOC_SAVE /
 * STRATEGY_DOC_STATUS / STRATEGY_DOC_DELETE) so the Event Viewer tracks the
 * module activity.
 *
 * The testproject_strategy_docs table is created lazily (CREATE TABLE IF NOT
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
$action = isset($_REQUEST['action']) ? trim((string)$_REQUEST['action']) : '';

/** Allowed document statuses (the #1052 Draft/Review/Approved/Archived flow). */
function sdocStatuses() {
    return array('draft', 'review', 'approved', 'archived');
}

/** The 9 structured sections, stored as one JSON object. */
function sdocSectionKeys() {
    return array('objectives', 'scope', 'approach', 'entry_criteria',
        'exit_criteria', 'environments', 'tools', 'roles', 'risks');
}

/** Table name handling (respects the optional DB_TABLE_PREFIX). */
function sdocTable() {
    $t = tlObjectWithDB::getDBTables(array('testproject_strategy_docs'));
    return $t['testproject_strategy_docs'];
}

/** Idempotent lazy schema migration so a freshly imported DB works unchanged. */
function sdocEnsureSchema($db) {
    $t = sdocTable();
    $db->exec_query(
        "CREATE TABLE IF NOT EXISTS {$t} (" .
        " id INT UNSIGNED NOT NULL AUTO_INCREMENT," .
        " testproject_id INT UNSIGNED NOT NULL DEFAULT 0," .
        " name VARCHAR(255) NOT NULL DEFAULT ''," .
        " status VARCHAR(16) NOT NULL DEFAULT 'draft'," .
        " version INT UNSIGNED NOT NULL DEFAULT 1," .
        " sections TEXT NULL," .
        " created_by INT UNSIGNED NULL," .
        " author_id INT UNSIGNED NULL," .
        " status_by INT UNSIGNED NULL," .
        " creation_ts TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP," .
        " update_ts DATETIME NULL," .
        " status_ts DATETIME NULL," .
        " PRIMARY KEY (id)," .
        " UNIQUE KEY uq_sdoc_project_name (testproject_id, name)," .
        " KEY idx_sdoc_project (testproject_id)," .
        " KEY idx_sdoc_status (testproject_id, status)" .
        " ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    return $t;
}

/** Read the JSON request body (POST routes). */
function sdocBody() {
    static $cached = null;
    if ($cached === null) {
        $cached = json_decode(file_get_contents('php://input'), true);
        if (!is_array($cached)) { $cached = array(); }
    }
    return $cached;
}

/** Trim + length-cap a scalar payload/get field. */
function sdocField($input, $key, $maxLen, $default = '') {
    $v = $input[$key] ?? $default;
    $v = is_scalar($v) ? trim((string)$v) : '';
    return mb_substr($v, 0, $maxLen);
}

/** Strict positive int from GET/body, 0 when absent/invalid/non-scalar. */
function sdocInt($input, $key) {
    $v = $input[$key] ?? 0;
    return is_scalar($v) ? intval($v) : 0;
}

/** Resolve the addressed test project: GET param wins, body, then session. */
function sdocResolveTproject() {
    $tpid = sdocInt($_GET, 'tproject_id');
    if ($tpid <= 0) {
        $tpid = sdocInt(sdocBody(), 'tproject_id');
    }
    if ($tpid <= 0) {
        $tpid = intval($_SESSION['testprojectID'] ?? 0);
    }
    return $tpid;
}

/** Whether the user may read strategy documents of a project. */
function sdocCanView($user, $db, $tpid) {
    foreach (array('mgt_view_tc', 'mgt_modify_tc') as $r) {
        if ($user->hasRight($db, $r, $tpid) === 'yes') { return true; }
    }
    return false;
}

/** Whether the user may write strategy documents of a project. */
function sdocCanModify($user, $db, $tpid) {
    return ($user->hasRight($db, 'mgt_modify_tc', $tpid) === 'yes');
}

/** Project display name + prefix, or null when the project does not exist. */
function sdocProjectInfo($db, $tpid) {
    $tbl = tlObjectWithDB::getDBTables(array('testprojects', 'nodes_hierarchy'));
    $tp = $tbl['testprojects'];
    $nh = $tbl['nodes_hierarchy'];
    $row = $db->fetchFirstRow(
        "SELECT p.prefix AS prefix, nh.name AS name " .
        " FROM {$tp} p JOIN {$nh} nh ON nh.id = p.id " .
        " WHERE p.id = " . intval($tpid) . " AND nh.node_type_id = 1");
    return (is_array($row) && $row) ? $row : null;
}

/** Login of a user id, resolved once per request. */
function sdocLogin($db, $uid) {
    static $cache = array();
    $uid = intval($uid);
    if ($uid <= 0) { return ''; }
    if (!array_key_exists($uid, $cache)) {
        $ut = tlObjectWithDB::getDBTables(array('users'))['users'];
        $row = $db->fetchFirstRow(
            "SELECT login FROM {$ut} WHERE id = " . $uid);
        $cache[$uid] = (is_array($row) && $row) ? (string)$row['login'] : '';
    }
    return $cache[$uid];
}

/** Decode the sections JSON blob into the canonical key set. */
function sdocDecodeSections($raw) {
    $out = array();
    foreach (sdocSectionKeys() as $k) { $out[$k] = ''; }
    $decoded = json_decode((string)$raw, true);
    if (is_array($decoded)) {
        foreach (sdocSectionKeys() as $k) {
            $v = $decoded[$k] ?? '';
            $out[$k] = is_scalar($v) ? (string)$v : '';
        }
    }
    return $out;
}

/** Build the sections JSON from a POST body `sections` object (utf8-safe cap). */
function sdocEncodeSections($body) {
    $in = $body['sections'] ?? null;
    if (!is_array($in)) { $in = array(); }
    $out = array();
    foreach (sdocSectionKeys() as $k) {
        $v = $in[$k] ?? '';
        $v = is_scalar($v) ? (string)$v : '';
        $out[$k] = mb_substr($v, 0, 20000);
    }
    return json_encode($out, JSON_UNESCAPED_UNICODE);
}

/** List documents of a project (newest updated first, then name). */
function sdocList($db, $t, $tpid) {
    $rows = (array)$db->get_recordset(
        "SELECT id, name, status, version, created_by, author_id, status_by," .
        " creation_ts, update_ts, status_ts" .
        " FROM {$t} WHERE testproject_id = " . intval($tpid) .
        " ORDER BY COALESCE(update_ts, creation_ts) DESC, name ASC, id ASC");
    $items = array();
    foreach ((array)$rows as $r) {
        $items[] = array(
            'id' => intval($r['id']),
            'name' => (string)$r['name'],
            'status' => (string)$r['status'],
            'version' => intval($r['version']),
            'created_by' => intval($r['created_by']),
            'created_by_login' => sdocLogin($db, $r['created_by']),
            'author_id' => intval($r['author_id']),
            'author_login' => sdocLogin($db, $r['author_id']),
            'status_by_login' => sdocLogin($db, $r['status_by']),
            'creation_ts' => (string)($r['creation_ts'] ?? ''),
            'update_ts' => (string)($r['update_ts'] ?? ''),
            'status_ts' => (string)($r['status_ts'] ?? ''),
        );
    }
    return $items;
}

/** Compute registry stats from a list. */
function sdocStats($items) {
    $s = array('total' => count($items), 'draft' => 0, 'review' => 0,
               'approved' => 0, 'archived' => 0);
    foreach ($items as $it) {
        if (isset($s[$it['status']])) { $s[$it['status']]++; }
    }
    return $s;
}

/** Fetch one document row of the project, or null. */
function sdocFetch($db, $t, $tpid, $id) {
    $row = $db->fetchFirstRow(
        "SELECT * FROM {$t} WHERE id = " . intval($id) .
        " AND testproject_id = " . intval($tpid));
    return (is_array($row) && $row) ? $row : null;
}

/** Map a document row to the full doc payload (with sections). */
function sdocFull($db, $r) {
    return array(
        'id' => intval($r['id']),
        'name' => (string)$r['name'],
        'status' => (string)$r['status'],
        'version' => intval($r['version']),
        'sections' => sdocDecodeSections($r['sections'] ?? ''),
        'created_by_login' => sdocLogin($db, $r['created_by']),
        'author_login' => sdocLogin($db, $r['author_id']),
        'status_by_login' => sdocLogin($db, $r['status_by']),
        'creation_ts' => (string)($r['creation_ts'] ?? ''),
        'update_ts' => (string)($r['update_ts'] ?? ''),
        'status_ts' => (string)($r['status_ts'] ?? ''),
    );
}

// --------------------------------------------------------------------------
// Routing
// --------------------------------------------------------------------------

$t = sdocEnsureSchema($db);
$tpid = sdocResolveTproject();

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
    if (!sdocCanModify($user, $db, $tpid)) {
        http_response_code(403);
        echo json_encode(array('status' => 'error', 'code' => 'no_right',
            'message' => 'No permission to modify test strategy documents'));
        exit;
    }
} else {
    if (!sdocCanView($user, $db, $tpid)) {
        http_response_code(403);
        echo json_encode(array('status' => 'error', 'code' => 'no_right',
            'message' => 'No permission to view test strategy documents'));
        exit;
    }
}

if ($action === '') {
    $action = $isWrite ? 'save' : 'init';
}

// ---- reads -----------------------------------------------------------------

if (!$isWrite && ($action === 'init' || $action === 'list')) {
    $info = sdocProjectInfo($db, $tpid);
    if ($info === null) {
        http_response_code(404);
        echo json_encode(array('status' => 'error', 'code' => 'tproject_not_found',
            'message' => 'Test project not found'));
        exit;
    }
    $items = sdocList($db, $t, $tpid);
    $payload = array(
        'status' => 'ok',
        'context' => array(
            'tproject_id' => intval($tpid),
            'tproject_name' => (string)$info['name'],
            'tproject_prefix' => (string)$info['prefix'],
            'can_view' => true,
            'can_modify' => sdocCanModify($user, $db, $tpid),
        ),
        'domains' => array(
            'statuses' => sdocStatuses(),
            'sections' => sdocSectionKeys(),
        ),
        'stats' => sdocStats($items),
        'items' => $items,
    );
    echo json_encode($payload);
    exit;
}

if (!$isWrite && $action === 'doc') {
    $id = sdocInt($_GET, 'id');
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(array('status' => 'error', 'code' => 'bad_id',
            'message' => 'id is mandatory'));
        exit;
    }
    $r = sdocFetch($db, $t, $tpid, $id);
    if ($r === null) {
        http_response_code(404);
        echo json_encode(array('status' => 'error', 'code' => 'doc_not_found',
            'message' => 'Test strategy document not found'));
        exit;
    }
    echo json_encode(array('status' => 'ok', 'doc' => sdocFull($db, $r)));
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
    $body = sdocBody();
    $name = sdocField($body, 'name', 255);
    if ($name === '') {
        http_response_code(400);
        echo json_encode(array('status' => 'error', 'code' => 'name_required',
            'message' => 'Name is mandatory'));
        exit;
    }
    $status = sdocField($body, 'status', 16, 'draft');
    if (!in_array($status, sdocStatuses(), true)) {
        http_response_code(400);
        echo json_encode(array('status' => 'error', 'code' => 'bad_status',
            'message' => 'Invalid status'));
        exit;
    }
    $sectionsJson = sdocEncodeSections($body);
    $id = sdocInt($body, 'id');

    // name unique per project (own name allowed on update)
    $dupe = $db->fetchFirstRow(
        "SELECT id FROM {$t} WHERE testproject_id = " . intval($tpid) .
        " AND name = '" . $db->prepare_string($name) . "'" .
        " AND id != " . intval($id));
    if (is_array($dupe) && $dupe) {
        http_response_code(409);
        echo json_encode(array('status' => 'error', 'code' => 'duplicate_name',
            'message' => 'A test strategy document with this name already exists'));
        exit;
    }

    if ($id > 0) {
        $chk = sdocFetch($db, $t, $tpid, $id);
        if ($chk === null) {
            http_response_code(404);
            echo json_encode(array('status' => 'error', 'code' => 'doc_not_found',
                'message' => 'Test strategy document not found'));
            exit;
        }
        try {
            $db->exec_query(
                "UPDATE {$t} SET name = '" . $db->prepare_string($name) . "'," .
                " status = '" . $db->prepare_string($status) . "'," .
                " sections = '" . $db->prepare_string($sectionsJson) . "'," .
                " version = version + 1," .
                " author_id = " . intval($userId) . "," .
                " update_ts = NOW()" .
                " WHERE id = " . intval($id) . " AND testproject_id = " . intval($tpid));
        } catch (Exception $e) {
            $dupe = $db->fetchFirstRow(
                "SELECT id FROM {$t} WHERE testproject_id = " . intval($tpid) .
                " AND name = '" . $db->prepare_string($name) . "'" .
                " AND id != " . intval($id));
            if (is_array($dupe) && $dupe) {
                http_response_code(409);
                echo json_encode(array('status' => 'error', 'code' => 'duplicate_name',
                    'message' => 'A test strategy document with this name already exists'));
                exit;
            }
            http_response_code(500);
            echo json_encode(array('status' => 'error', 'code' => 'save_failed',
                'message' => 'Could not save test strategy document'));
            exit;
        }
        $fresh = sdocFetch($db, $t, $tpid, $id);

        $event = new stdClass();
        $event->message = 'Test strategy document saved: ' . $name .
            ' (id ' . $id . ', v' . intval($fresh['version']) . ')';
        $event->logLevel = 'AUDIT';
        $event->source = 'GUI';
        $event->objectID = $tpid;
        $event->objectType = 'testprojects';
        $event->code = 'STRATEGY_DOC_SAVE';
        logEvent($event);

        echo json_encode(array('status' => 'ok', 'id' => intval($id),
            'version' => intval($fresh['version']), 'message' => 'Saved'));
        exit;
    }

    try {
        $db->exec_query(
            "INSERT INTO {$t} (testproject_id, name, status, version, sections," .
            " created_by, author_id, status_by, update_ts, status_ts) VALUES (" .
            intval($tpid) . ", '" . $db->prepare_string($name) . "', '" .
            $db->prepare_string($status) . "', 1, '" .
            $db->prepare_string($sectionsJson) . "', " . intval($userId) . ", " .
            intval($userId) . ", " . intval($userId) . ", NOW(), NOW())");
    } catch (Exception $e) {
        $dupe = $db->fetchFirstRow(
            "SELECT id FROM {$t} WHERE testproject_id = " . intval($tpid) .
            " AND name = '" . $db->prepare_string($name) . "'");
        if (is_array($dupe) && $dupe) {
            http_response_code(409);
            echo json_encode(array('status' => 'error', 'code' => 'duplicate_name',
                'message' => 'A test strategy document with this name already exists'));
            exit;
        }
        http_response_code(500);
        echo json_encode(array('status' => 'error', 'code' => 'insert_failed',
            'message' => 'Could not create test strategy document'));
        exit;
    }
    $newId = (int)$db->insert_id($t);
    if ($newId <= 0) {
        http_response_code(500);
        echo json_encode(array('status' => 'error', 'code' => 'insert_failed',
            'message' => 'Could not create test strategy document'));
        exit;
    }

    $event = new stdClass();
    $event->message = 'Test strategy document created: ' . $name .
        ' (id ' . $newId . ')';
    $event->logLevel = 'AUDIT';
    $event->source = 'GUI';
    $event->objectID = $tpid;
    $event->objectType = 'testprojects';
    $event->code = 'STRATEGY_DOC_CREATE';
    logEvent($event);

    echo json_encode(array('status' => 'ok', 'id' => $newId, 'version' => 1,
        'message' => 'Created'));
    exit;
}

if ($action === 'status') {
    $body = sdocBody();
    $id = sdocInt($body, 'id');
    $status = sdocField($body, 'status', 16);
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(array('status' => 'error', 'code' => 'bad_id',
            'message' => 'id is mandatory'));
        exit;
    }
    if (!in_array($status, sdocStatuses(), true)) {
        http_response_code(400);
        echo json_encode(array('status' => 'error', 'code' => 'bad_status',
            'message' => 'Invalid status'));
        exit;
    }
    $chk = sdocFetch($db, $t, $tpid, $id);
    if ($chk === null) {
        http_response_code(404);
        echo json_encode(array('status' => 'error', 'code' => 'doc_not_found',
            'message' => 'Test strategy document not found'));
        exit;
    }
    try {
        $db->exec_query(
            "UPDATE {$t} SET status = '" . $db->prepare_string($status) . "'," .
            " status_by = " . intval($userId) . ", status_ts = NOW()" .
            " WHERE id = " . intval($id) . " AND testproject_id = " . intval($tpid));
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(array('status' => 'error', 'code' => 'status_failed',
            'message' => 'Could not change status'));
        exit;
    }

    $event = new stdClass();
    $event->message = 'Test strategy document status: ' . (string)$chk['name'] .
        ' (id ' . $id . ') ' . (string)$chk['status'] . ' -> ' . $status;
    $event->logLevel = 'AUDIT';
    $event->source = 'GUI';
    $event->objectID = $tpid;
    $event->objectType = 'testprojects';
    $event->code = 'STRATEGY_DOC_STATUS';
    logEvent($event);

    echo json_encode(array('status' => 'ok', 'id' => intval($id),
        'new_status' => $status, 'message' => 'Status changed'));
    exit;
}

if ($action === 'delete') {
    $body = sdocBody();
    $id = sdocInt($body, 'id');
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(array('status' => 'error', 'code' => 'bad_id',
            'message' => 'id is mandatory'));
        exit;
    }
    $chk = sdocFetch($db, $t, $tpid, $id);
    if ($chk === null) {
        http_response_code(404);
        echo json_encode(array('status' => 'error', 'code' => 'doc_not_found',
            'message' => 'Test strategy document not found'));
        exit;
    }
    try {
        $db->exec_query(
            "DELETE FROM {$t} WHERE id = " . intval($id) .
            " AND testproject_id = " . intval($tpid));
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(array('status' => 'error', 'code' => 'delete_failed',
            'message' => 'Could not delete test strategy document'));
        exit;
    }

    $event = new stdClass();
    $event->message = 'Test strategy document deleted: ' . (string)$chk['name'] .
        ' (id ' . $id . ')';
    $event->logLevel = 'AUDIT';
    $event->source = 'GUI';
    $event->objectID = $tpid;
    $event->objectType = 'testprojects';
    $event->code = 'STRATEGY_DOC_DELETE';
    logEvent($event);

    echo json_encode(array('status' => 'ok', 'deleted' => 1, 'message' => 'Deleted'));
    exit;
}

http_response_code(400);
echo json_encode(array('status' => 'error', 'code' => 'unknown_action',
    'message' => 'Unknown action'));
