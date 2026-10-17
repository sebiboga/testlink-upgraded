<?php
/**
 * Exploratory Testing Sessions registry - REST BFF API
 * URL: /api/exploratory/
 * Plain PHP, no framework, no compilation.
 *
 * Backs the modern screen gui/templates/exploratory/exploratorySessions.html
 * (Refs #1934, parent feature #1071). 1.9.20 has NO exploratory-testing entity
 * at all, so this is a net-new module: a project-scoped registry of
 * exploratory testing sessions, each carrying a test charter, a status, a
 * time box, a tester, a session date, an environment, a purpose, free notes
 * and optional defect references.
 *
 * Routes (session based, JSON I/O):
 *   GET  ?action=init&tproject_id=N
 *        -> { status, context, domains, stats, items }
 *   GET  ?action=list&tproject_id=N
 *        -> { status, items }                  (same rows as init.items)
 *   POST ?action=save { tproject_id, id?, name, charter, status,
 *                       timebox_min, tester, session_date, environment,
 *                       purpose, notes, defect_refs }
 *        -> { status, id }
 *   POST ?action=delete { tproject_id, id }
 *        -> { status, deleted }
 *
 * Rights model (checked on the ADDRESSED test project BEFORE it is resolved,
 * the #1697 lesson: no existence oracle):
 *   - reads  require mgt_view_tc OR mgt_modify_tc on the project
 *   - writes require mgt_modify_tc on the project
 *   - unauthenticated -> 401; unknown action -> 400; wrong verb -> 405;
 *     a listed id that is not in the project -> 404
 *
 * Writes emit AUDIT events (EXPLORATORY_CREATE / EXPLORATORY_SAVE /
 * EXPLORATORY_DELETE) so the Event Viewer tracks the module activity.
 *
 * The tc_exploratory_sessions table is created lazily (CREATE TABLE IF NOT
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

/** Allowed session statuses. */
function explStatuses() {
    return array('planned', 'in_progress', 'completed', 'aborted');
}

/** Table name handling (respects the optional DB_TABLE_PREFIX). */
function explTable() {
    $t = tlObjectWithDB::getDBTables(array('tc_exploratory_sessions'));
    return $t['tc_exploratory_sessions'];
}

/** Idempotent lazy schema migration so a freshly imported DB works unchanged. */
function explEnsureSchema($db) {
    $t = explTable();
    $db->exec_query(
        "CREATE TABLE IF NOT EXISTS {$t} (" .
        " id INT UNSIGNED NOT NULL AUTO_INCREMENT," .
        " testproject_id INT UNSIGNED NOT NULL DEFAULT 0," .
        " name VARCHAR(255) NOT NULL DEFAULT ''," .
        " charter TEXT NULL," .
        " status VARCHAR(20) NOT NULL DEFAULT 'planned'," .
        " timebox_min INT UNSIGNED NOT NULL DEFAULT 0," .
        " tester VARCHAR(100) NOT NULL DEFAULT ''," .
        " session_date DATE NULL," .
        " environment VARCHAR(255) NOT NULL DEFAULT ''," .
        " purpose TEXT NULL," .
        " notes TEXT NULL," .
        " defect_refs VARCHAR(1000) NOT NULL DEFAULT ''," .
        " created_by INT UNSIGNED NULL," .
        " creation_ts TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP," .
        " update_ts DATETIME NULL," .
        " PRIMARY KEY (id)," .
        " KEY idx_expl_project (testproject_id, status)" .
        " ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    return $t;
}

/** Read the JSON request body (POST routes). */
function explBody() {
    static $cached = null;
    if ($cached === null) {
        $cached = json_decode(file_get_contents('php://input'), true);
        if (!is_array($cached)) { $cached = array(); }
    }
    return $cached;
}

/** Trim + length-cap a scalar payload/get field. */
function explField($input, $key, $maxLen, $default = '') {
    $v = $input[$key] ?? $default;
    $v = is_scalar($v) ? trim((string)$v) : '';
    return mb_substr($v, 0, $maxLen);
}

/** Strict int from GET/body, 0 when absent/invalid/non-scalar. */
function explInt($input, $key) {
    $v = $input[$key] ?? 0;
    return is_scalar($v) ? intval($v) : 0;
}

/** Resolve the addressed test project: GET param wins, body, then session. */
function explResolveTproject() {
    $tpid = explInt($_GET, 'tproject_id');
    if ($tpid <= 0) {
        $tpid = explInt(explBody(), 'tproject_id');
    }
    if ($tpid <= 0) {
        $tpid = intval($_SESSION['testprojectID'] ?? 0);
    }
    return $tpid;
}

/** Whether the user may read exploratory sessions of a project. */
function explCanView($user, $db, $tpid) {
    foreach (array('mgt_view_tc', 'mgt_modify_tc') as $r) {
        if ($user->hasRight($db, $r, $tpid) === 'yes') { return true; }
    }
    return false;
}

/** Whether the user may write exploratory sessions of a project. */
function explCanModify($user, $db, $tpid) {
    return ($user->hasRight($db, 'mgt_modify_tc', $tpid) === 'yes');
}

/** Project display name + prefix, or null when the project does not exist. */
function explProjectInfo($db, $tpid) {
    $tbl = tlObjectWithDB::getDBTables(array('testprojects', 'nodes_hierarchy'));
    $tp = $tbl['testprojects'];
    $nh = $tbl['nodes_hierarchy'];
    $row = $db->fetchFirstRow(
        "SELECT p.prefix AS prefix, nh.name AS name " .
        " FROM {$tp} p JOIN {$nh} nh ON nh.id = p.id " .
        " WHERE p.id = " . intval($tpid) . " AND nh.node_type_id = 1");
    return (is_array($row) && $row) ? $row : null;
}

/** Session rows of a project, newest activity first. */
function explList($db, $t, $tpid) {
    $rows = (array)$db->get_recordset(
        "SELECT * FROM {$t} WHERE testproject_id = " . intval($tpid) . " " .
        " ORDER BY session_date IS NULL, session_date DESC, id DESC");

    $items = array();
    foreach ((array)$rows as $r) {
        $items[] = array(
            'id' => intval($r['id']),
            'name' => (string)$r['name'],
            'charter' => (string)($r['charter'] ?? ''),
            'status' => (string)$r['status'],
            'timebox_min' => intval($r['timebox_min']),
            'tester' => (string)$r['tester'],
            'session_date' => (string)($r['session_date'] ?? ''),
            'environment' => (string)$r['environment'],
            'purpose' => (string)($r['purpose'] ?? ''),
            'notes' => (string)($r['notes'] ?? ''),
            'defect_refs' => (string)$r['defect_refs'],
            'created_by' => intval($r['created_by']),
            'creation_ts' => (string)($r['creation_ts'] ?? ''),
            'update_ts' => (string)($r['update_ts'] ?? ''),
        );
    }
    return $items;
}

/** Compute registry stats from a list. */
function explStats($items) {
    $s = array('total' => count($items), 'planned' => 0, 'in_progress' => 0,
               'completed' => 0, 'aborted' => 0, 'with_defects' => 0,
               'timebox_total' => 0);
    foreach ($items as $it) {
        if (isset($s[$it['status']])) { $s[$it['status']]++; }
        if ($it['defect_refs'] !== '') { $s['with_defects']++; }
        $s['timebox_total'] += intval($it['timebox_min']);
    }
    return $s;
}

/** Reject a duplicate session name inside the same project (409). */
function explDuplicateGuard($db, $t, $tpid, $name, $ownId) {
    $q = "SELECT COUNT(*) AS n FROM {$t} WHERE testproject_id = " . intval($tpid) .
         " AND name = '" . $db->prepare_string($name) . "'";
    if ($ownId > 0) { $q .= " AND id <> " . intval($ownId); }
    $chk = $db->fetchFirstRow($q);
    if (is_array($chk) && intval($chk['n'] ?? 0) > 0) {
        http_response_code(409);
        echo json_encode(array('status' => 'error', 'code' => 'duplicate_name',
            'message' => 'A session with this name already exists'));
        exit;
    }
}

// --------------------------------------------------------------------------
// Routing
// --------------------------------------------------------------------------

$t = explEnsureSchema($db);
$tpid = explResolveTproject();

if ($tpid <= 0) {
    http_response_code(400);
    echo json_encode(array('status' => 'error', 'code' => 'no_testproject',
        'message' => 'No test project selected'));
    exit;
}

// rights BEFORE project resolution (no existence oracle - #1697).
$isWrite = ($method === 'POST');
if ($isWrite) {
    if (!explCanModify($user, $db, $tpid)) {
        http_response_code(403);
        echo json_encode(array('status' => 'error', 'code' => 'no_right',
            'message' => 'No permission to modify exploratory sessions'));
        exit;
    }
} else {
    if (!explCanView($user, $db, $tpid)) {
        http_response_code(403);
        echo json_encode(array('status' => 'error', 'code' => 'no_right',
            'message' => 'No permission to view exploratory sessions'));
        exit;
    }
}

if ($method !== 'GET' && $method !== 'POST') {
    header('Allow: GET, HEAD, POST');
    http_response_code(405);
    echo json_encode(array('status' => 'error', 'code' => 'wrong_method',
        'message' => 'Method not allowed'));
    exit;
}

if ($action === '') {
    $action = $isWrite ? 'save' : 'init';
}

// ---- reads -----------------------------------------------------------------

if (!$isWrite && ($action === 'init' || $action === 'list')) {
    $info = explProjectInfo($db, $tpid);
    if ($info === null) {
        http_response_code(404);
        echo json_encode(array('status' => 'error', 'code' => 'tproject_not_found',
            'message' => 'Test project not found'));
        exit;
    }
    $items = explList($db, $t, $tpid);
    $payload = array(
        'status' => 'ok',
        'context' => array(
            'tproject_id' => intval($tpid),
            'tproject_name' => (string)$info['name'],
            'tproject_prefix' => (string)$info['prefix'],
            'can_view' => explCanView($user, $db, $tpid),
            'can_modify' => explCanModify($user, $db, $tpid),
        ),
        'domains' => array(
            'statuses' => explStatuses(),
            'user_login' => (string)($user->login ?? ''),
            'user_name' => trim((string)($user->firstName ?? '') . ' ' .
                                (string)($user->lastName ?? '')),
        ),
        'stats' => explStats($items),
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

// Existence check AFTER the rights gate above (never before — #1697), so writes
// mirror the read path's tproject_not_found instead of creating orphan rows.
if (explProjectInfo($db, $tpid) === null) {
    http_response_code(404);
    echo json_encode(array('status' => 'error', 'code' => 'tproject_not_found',
        'message' => 'Test project not found'));
    exit;
}

if ($action === 'save') {
    $body = explBody();
    $name = explField($body, 'name', 255);
    if ($name === '') {
        http_response_code(400);
        echo json_encode(array('status' => 'error', 'code' => 'name_required',
            'message' => 'Name is mandatory'));
        exit;
    }
    $status = explField($body, 'status', 20, 'planned');
    if (!in_array($status, explStatuses(), true)) {
        http_response_code(400);
        echo json_encode(array('status' => 'error', 'code' => 'bad_status',
            'message' => 'Invalid status'));
        exit;
    }
    $rawTimebox = $body['timebox_min'] ?? 0;
    if (!is_scalar($rawTimebox) || !preg_match('/^\d{1,7}$/', trim((string)$rawTimebox))) {
        http_response_code(400);
        echo json_encode(array('status' => 'error', 'code' => 'bad_timebox',
            'message' => 'Timebox must be a non-negative number of minutes'));
        exit;
    }
    $timebox = intval($rawTimebox);
    if ($timebox > 1000000) {
        http_response_code(400);
        echo json_encode(array('status' => 'error', 'code' => 'bad_timebox',
            'message' => 'Timebox is out of range'));
        exit;
    }
    $sessionDate = explField($body, 'session_date', 10);
    if ($sessionDate !== '') {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $sessionDate, $m) ||
            !checkdate(intval($m[2]), intval($m[3]), intval($m[1]))) {
            http_response_code(400);
            echo json_encode(array('status' => 'error', 'code' => 'bad_session_date',
                'message' => 'Session date must be YYYY-MM-DD'));
            exit;
        }
    }
    $charter = explField($body, 'charter', 60000);
    $tester = explField($body, 'tester', 100);
    $environment = explField($body, 'environment', 255);
    $purpose = explField($body, 'purpose', 60000);
    $notes = explField($body, 'notes', 60000);
    $defectRefs = explField($body, 'defect_refs', 1000);
    $id = explInt($body, 'id');

    explDuplicateGuard($db, $t, $tpid, $name, $id);

    $dateSql = ($sessionDate === '') ? 'NULL'
             : "'" . $db->prepare_string($sessionDate) . "'";
    $vTail = "'" . $db->prepare_string($name) . "', " .
             "'" . $db->prepare_string($charter) . "', " .
             "'" . $db->prepare_string($status) . "', " .
             $timebox . ", " .
             "'" . $db->prepare_string($tester) . "', " .
             $dateSql . ", " .
             "'" . $db->prepare_string($environment) . "', " .
             "'" . $db->prepare_string($purpose) . "', " .
             "'" . $db->prepare_string($notes) . "', " .
             "'" . $db->prepare_string($defectRefs) . "', " .
             intval($userId) . ", NOW()";

    if ($id <= 0) {
        $db->exec_query(
            "INSERT INTO {$t} (testproject_id, name, charter, status, timebox_min, " .
            "tester, session_date, environment, purpose, notes, defect_refs, " .
            "created_by, update_ts) VALUES (" . intval($tpid) . ", " . $vTail . ")");
        $newId = (int)$db->insert_id($t);
        if ($newId <= 0) {
            http_response_code(500);
            echo json_encode(array('status' => 'error', 'code' => 'insert_failed',
                'message' => 'Could not create session'));
            exit;
        }

        $event = new stdClass();
        $event->message = 'Exploratory session created: ' . $name . ' (id ' . $newId . ')';
        $event->logLevel = 'AUDIT';
        $event->source = 'GUI';
        $event->objectID = $tpid;
        $event->objectType = 'testprojects';
        $event->code = 'EXPLORATORY_CREATE';
        logEvent($event);

        echo json_encode(array('status' => 'ok', 'id' => $newId, 'message' => 'Created'));
        exit;
    }

    // update - prove the row exists and belongs to the addressed project
    $chk = $db->fetchFirstRow(
        "SELECT id FROM {$t} WHERE id = " . intval($id) .
        " AND testproject_id = " . intval($tpid));
    if (!is_array($chk) || !$chk) {
        http_response_code(404);
        echo json_encode(array('status' => 'error', 'code' => 'session_not_found',
            'message' => 'Session not found'));
        exit;
    }
    $db->exec_query(
        "UPDATE {$t} SET name = " .
        "'" . $db->prepare_string($name) . "', " .
        "charter = " . "'" . $db->prepare_string($charter) . "', " .
        "status = " . "'" . $db->prepare_string($status) . "', " .
        "timebox_min = " . $timebox . ", " .
        "tester = " . "'" . $db->prepare_string($tester) . "', " .
        "session_date = " . $dateSql . ", " .
        "environment = " . "'" . $db->prepare_string($environment) . "', " .
        "purpose = " . "'" . $db->prepare_string($purpose) . "', " .
        "notes = " . "'" . $db->prepare_string($notes) . "', " .
        "defect_refs = " . "'" . $db->prepare_string($defectRefs) . "', " .
        "update_ts = NOW() " .
        "WHERE id = " . intval($id) . " AND testproject_id = " . intval($tpid));

    $event = new stdClass();
    $event->message = 'Exploratory session saved: ' . $name . ' (id ' . $id . ')';
    $event->logLevel = 'AUDIT';
    $event->source = 'GUI';
    $event->objectID = $tpid;
    $event->objectType = 'testprojects';
    $event->code = 'EXPLORATORY_SAVE';
    logEvent($event);

    echo json_encode(array('status' => 'ok', 'id' => intval($id), 'message' => 'Saved'));
    exit;
}

if ($action === 'delete') {
    $body = explBody();
    $id = explInt($body, 'id');
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
        echo json_encode(array('status' => 'error', 'code' => 'session_not_found',
            'message' => 'Session not found'));
        exit;
    }
    $db->exec_query(
        "DELETE FROM {$t} WHERE id = " . intval($id) .
        " AND testproject_id = " . intval($tpid));

    $event = new stdClass();
    $event->message = 'Exploratory session deleted: ' . (string)$chk['name'] .
                      ' (id ' . $id . ')';
    $event->logLevel = 'AUDIT';
    $event->source = 'GUI';
    $event->objectID = $tpid;
    $event->objectType = 'testprojects';
    $event->code = 'EXPLORATORY_DELETE';
    logEvent($event);

    echo json_encode(array('status' => 'ok', 'deleted' => 1, 'message' => 'Deleted'));
    exit;
}

http_response_code(400);
echo json_encode(array('status' => 'error', 'code' => 'unknown_action',
    'message' => 'Unknown action'));
