<?php
/**
 * Exit Criteria BFF API
 * URL: /api/exitcriteria/
 * Plain PHP, no framework, no compilation.
 *
 * Backs the modern screen gui/templates/strategy/exitCriteria.html (Refs #1936,
 * parent feature #1088 "Implement definition of EXIT CRITERIA"). TestLink
 * 1.9.20 never had an exit-criteria entity at all: #1088 asks for a section
 * where exit criteria are DEFINED (part of the Test Strategy per the owner
 * comment), a later report will evaluate a build as Release Candidate, and a
 * later dashboard will show achievement per build. This module is slice (1):
 * a project-scoped registry of exit-criteria DEFINITIONS - each with a test
 * level, entry/exit text and an optional measurable metric + target value so
 * the future evaluator has machine-readable criteria to work with.
 *
 * Routes (session based, JSON I/O):
 *   GET  ?action=init&tproject_id=N
 *        -> { status, context, domains, stats, items }
 *   GET  ?action=list&tproject_id=N
 *        -> { status, items }                  (same rows as init.items)
 *   POST ?action=save { tproject_id, id?, name, level, entry_criteria,
 *                       exit_criteria, metric, target_value }
 *        -> { status, id }
 *   POST ?action=delete { tproject_id, id }
 *        -> { status, deleted }
 *
 * Rights model (checked on the ADDRESSED test project BEFORE it is resolved,
 * the #1697 lesson: no existence oracle):
 *   - reads  require mgt_view_tc OR mgt_modify_tc on the project
 *   - writes require mgt_modify_tc on the project
 *   - unauthenticated -> 401; unknown action -> 400; wrong verb -> 405;
 *     an id not in the project -> 404; a duplicate name -> 409
 *
 * Writes emit AUDIT events (EXIT_CRITERIA_CREATE / EXIT_CRITERIA_SAVE /
 * EXIT_CRITERIA_DELETE) so the Event Viewer tracks the module activity.
 *
 * The tc_exit_criteria table is created lazily (CREATE TABLE IF NOT EXISTS)
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

// verb whitelist BEFORE any parameter probing so PUT/DELETE always answer 405
if ($method !== 'GET' && $method !== 'HEAD' && $method !== 'POST') {
    header('Allow: GET, HEAD, POST');
    ecFail(405, 'wrong_method', 'Method not allowed');
}

/** Allowed test levels. */
function ecLevels() {
    return array('unit', 'integration', 'system', 'acceptance', 'regression', 'release');
}

/**
 * Allowed measurable metrics.
 * 'none' = free-text only criterion; the rest are computable from plan data.
 */
function ecMetrics() {
    return array('none', 'progress', 'pass_rate', 'failed', 'blocked', 'req_coverage');
}

/** Percentage metrics: target must be within 0..100. */
function ecPercentMetrics() {
    return array('progress', 'pass_rate', 'req_coverage');
}

/** Table name handling (respects the optional DB_TABLE_PREFIX). */
function ecTable() {
    $t = tlObjectWithDB::getDBTables(array('tc_exit_criteria'));
    return $t['tc_exit_criteria'];
}

/** Idempotent lazy schema migration so a freshly imported DB works unchanged. */
function ecEnsureSchema($db) {
    $t = ecTable();
    $db->exec_query(
        "CREATE TABLE IF NOT EXISTS {$t} (" .
        " id INT UNSIGNED NOT NULL AUTO_INCREMENT," .
        " testproject_id INT UNSIGNED NOT NULL DEFAULT 0," .
        " name VARCHAR(255) NOT NULL DEFAULT ''," .
        " test_level VARCHAR(32) NOT NULL DEFAULT 'system'," .
        " entry_criteria TEXT NULL," .
        " exit_criteria TEXT NULL," .
        " metric VARCHAR(32) NOT NULL DEFAULT 'none'," .
        " target_value DECIMAL(10,2) NULL," .
        " created_by INT UNSIGNED NULL," .
        " creation_ts TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP," .
        " update_ts DATETIME NULL," .
        " PRIMARY KEY (id)," .
        " KEY idx_ec_project (testproject_id)" .
        " ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    return $t;
}

/** Read the JSON request body (POST routes). */
function ecBody() {
    static $cached = null;
    if ($cached === null) {
        $cached = json_decode(file_get_contents('php://input'), true);
        if (!is_array($cached)) { $cached = array(); }
    }
    return $cached;
}

/** Trim + length-cap a scalar payload/get field. */
function ecField($input, $key, $maxLen, $default = '') {
    $v = $input[$key] ?? $default;
    $v = is_scalar($v) ? trim((string)$v) : '';
    return mb_substr($v, 0, $maxLen);
}

/** Strict positive int from GET/body, 0 when absent/invalid/non-scalar. */
function ecInt($input, $key) {
    $v = $input[$key] ?? 0;
    return is_scalar($v) ? intval($v) : 0;
}

/** Resolve the addressed test project: GET param wins, body, then session. */
function ecResolveTproject() {
    $tpid = ecInt($_GET, 'tproject_id');
    if ($tpid <= 0) {
        $tpid = ecInt(ecBody(), 'tproject_id');
    }
    if ($tpid <= 0) {
        $tpid = intval($_SESSION['testprojectID'] ?? 0);
    }
    return $tpid;
}

/** Whether the user may read exit criteria of a project. */
function ecCanView($user, $db, $tpid) {
    foreach (array('mgt_view_tc', 'mgt_modify_tc') as $r) {
        if ($user->hasRight($db, $r, $tpid) === 'yes') { return true; }
    }
    return false;
}

/** Whether the user may write exit criteria of a project. */
function ecCanModify($user, $db, $tpid) {
    return ($user->hasRight($db, 'mgt_modify_tc', $tpid) === 'yes');
}

/** Project display name + prefix, or null when the project does not exist. */
function ecProjectInfo($db, $tpid) {
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
 * List the project's criteria. Unit of the registry is a single row
 * (no versioning): ORDER BY level (legacy level order), then name.
 */
function ecList($db, $t, $tpid) {
    $levelOrder = "'" . implode("','", ecLevels()) . "'";
    $rows = (array)$db->get_recordset(
        "SELECT * FROM {$t} WHERE testproject_id = " . intval($tpid) . " " .
        " ORDER BY FIELD(test_level, {$levelOrder}), name ASC");
    $items = array();
    foreach ((array)$rows as $r) {
        $items[] = ecItem($r);
    }
    return $items;
}

 /** Normalize one DB row for the JSON payload. */
function ecItem($r) {
    $metric = (string)$r['metric'];
    $target = $r['target_value'];
    return array(
        'id' => intval($r['id']),
        'name' => (string)$r['name'],
        'level' => (string)$r['test_level'],
        'entry_criteria' => (string)($r['entry_criteria'] ?? ''),
        'exit_criteria' => (string)($r['exit_criteria'] ?? ''),
        'metric' => $metric,
        'target_value' => ($target === null || $target === '') ? null : (float)$target,
        'created_by' => intval($r['created_by']),
        'creation_ts' => (string)($r['creation_ts'] ?? ''),
        'update_ts' => (string)($r['update_ts'] ?? ''),
    );
}

/** Compute registry stats from a list. */
function ecStats($items) {
    $s = array('total' => count($items), 'entry' => 0, 'exit' => 0,
               'measurable' => 0);
    foreach ($items as $it) {
        if ($it['entry_criteria'] !== '') { $s['entry']++; }
        if ($it['exit_criteria'] !== '') { $s['exit']++; }
        if ($it['metric'] !== 'none') { $s['measurable']++; }
    }
    return $s;
}

/** Emit a machine-code error and stop. */
function ecFail($http, $code, $message) {
    http_response_code($http);
    echo json_encode(array('status' => 'error', 'code' => $code,
        'message' => $message));
    exit;
}

// --------------------------------------------------------------------------
// Routing
// --------------------------------------------------------------------------

$t = ecEnsureSchema($db);
$tpid = ecResolveTproject();

if ($tpid <= 0) {
    ecFail(400, 'no_testproject', 'No test project selected');
}

// rights BEFORE project resolution (no existence oracle - #1697).
$isWrite = ($method === 'POST');
if ($isWrite) {
    if (!ecCanModify($user, $db, $tpid)) {
        ecFail(403, 'no_right', 'No permission to modify exit criteria');
    }
} else {
    if (!ecCanView($user, $db, $tpid)) {
        ecFail(403, 'no_right', 'No permission to view exit criteria');
    }
}

if ($action === '') {
    $action = $isWrite ? 'save' : 'init';
}

// ---- reads -----------------------------------------------------------------

if (!$isWrite && ($action === 'init' || $action === 'list')) {
    $info = ecProjectInfo($db, $tpid);
    if ($info === null) {
        ecFail(404, 'tproject_not_found', 'Test project not found');
    }
    $items = ecList($db, $t, $tpid);
    $payload = array(
        'status' => 'ok',
        'context' => array(
            'tproject_id' => intval($tpid),
            'tproject_name' => (string)$info['name'],
            'tproject_prefix' => (string)$info['prefix'],
            'can_view' => ecCanView($user, $db, $tpid),
            'can_modify' => ecCanModify($user, $db, $tpid),
        ),
        'domains' => array(
            'levels' => ecLevels(),
            'metrics' => ecMetrics(),
            'percent_metrics' => ecPercentMetrics(),
        ),
        'stats' => ecStats($items),
        'items' => $items,
    );
    echo json_encode($payload);
    exit;
}

if (!$isWrite) {
    ecFail(400, 'unknown_action', 'Unknown action');
}

// ---- writes ----------------------------------------------------------------

if ($action === 'save') {
    $body = ecBody();
    $name = ecField($body, 'name', 255);
    if ($name === '') {
        ecFail(400, 'name_required', 'Name is mandatory');
    }
    $level = ecField($body, 'level', 32, 'system');
    if (!in_array($level, ecLevels(), true)) {
        ecFail(400, 'bad_level', 'Invalid test level');
    }
    $metric = ecField($body, 'metric', 32, 'none');
    if (!in_array($metric, ecMetrics(), true)) {
        ecFail(400, 'bad_metric', 'Invalid metric');
    }
    $entry = ecField($body, 'entry_criteria', 20000);
    $exit = ecField($body, 'exit_criteria', 20000);
    if ($entry === '' && $exit === '') {
        ecFail(400, 'criteria_required', 'Entry or exit criteria text is mandatory');
    }

    // target value: mandatory + numeric when a metric is chosen
    $target = null;
    if ($metric !== 'none') {
        $raw = $body['target_value'] ?? null;
        if (!is_scalar($raw) || trim((string)$raw) === '' || !is_numeric($raw)) {
            ecFail(400, 'bad_target', 'A numeric target value is required for a measurable criterion');
        }
        $target = (float)$raw;
        if (in_array($metric, ecPercentMetrics(), true) && ($target < 0 || $target > 100)) {
            ecFail(400, 'bad_target', 'Percentage targets must be between 0 and 100');
        }
        if (!in_array($metric, ecPercentMetrics(), true) && $target < 0) {
            ecFail(400, 'bad_target', 'Target value must not be negative');
        }
    }

    $id = ecInt($body, 'id');

    // duplicate name inside the same project (own name allowed on update)
    $dup = $db->fetchFirstRow(
        "SELECT id FROM {$t} WHERE testproject_id = " . intval($tpid) .
        " AND name = '" . $db->prepare_string($name) . "'" .
        ($id > 0 ? (" AND id <> " . intval($id)) : ''));
    if (is_array($dup) && $dup) {
        ecFail(409, 'duplicate_name', 'An exit criterion with this name already exists');
    }

    $targetSql = ($target === null) ? 'NULL' : sprintf('%.2f', $target);

    if ($id <= 0) {
        // create
        $db->exec_query(
            "INSERT INTO {$t} (testproject_id, name, test_level, entry_criteria, " .
            "exit_criteria, metric, target_value, created_by, update_ts) VALUES (" .
            intval($tpid) . ", '" . $db->prepare_string($name) . "', '" .
            $db->prepare_string($level) . "', '" . $db->prepare_string($entry) .
            "', '" . $db->prepare_string($exit) . "', '" .
            $db->prepare_string($metric) . "', " . $targetSql . ", " .
            intval($userId) . ", NOW())");
        $newId = (int)$db->insert_id('tc_exit_criteria');
        if ($newId <= 0) {
            ecFail(500, 'insert_failed', 'Could not create exit criterion');
        }

        $event = new stdClass();
        $event->message = 'Exit criterion created: ' . $name . ' (id ' . $newId . ')';
        $event->logLevel = 'AUDIT';
        $event->source = 'GUI';
        $event->objectID = $tpid;
        $event->objectType = 'testprojects';
        $event->code = 'EXIT_CRITERIA_CREATE';
        logEvent($event);

        echo json_encode(array('status' => 'ok', 'id' => $newId, 'message' => 'Saved'));
        exit;
    }

    // update: prove the row belongs to the addressed project
    $chk = $db->fetchFirstRow(
        "SELECT id FROM {$t} WHERE id = " . intval($id) .
        " AND testproject_id = " . intval($tpid));
    if (!is_array($chk) || !$chk) {
        ecFail(404, 'criterion_not_found', 'Exit criterion not found');
    }
    $db->exec_query(
        "UPDATE {$t} SET name = '" . $db->prepare_string($name) . "', " .
        "test_level = '" . $db->prepare_string($level) . "', " .
        "entry_criteria = '" . $db->prepare_string($entry) . "', " .
        "exit_criteria = '" . $db->prepare_string($exit) . "', " .
        "metric = '" . $db->prepare_string($metric) . "', " .
        "target_value = " . $targetSql . ", update_ts = NOW() " .
        "WHERE id = " . intval($id) . " AND testproject_id = " . intval($tpid));

    $event = new stdClass();
    $event->message = 'Exit criterion saved: ' . $name . ' (id ' . intval($id) . ')';
    $event->logLevel = 'AUDIT';
    $event->source = 'GUI';
    $event->objectID = $tpid;
    $event->objectType = 'testprojects';
    $event->code = 'EXIT_CRITERIA_SAVE';
    logEvent($event);

    echo json_encode(array('status' => 'ok', 'id' => intval($id), 'message' => 'Saved'));
    exit;
}

if ($action === 'delete') {
    $body = ecBody();
    $id = ecInt($body, 'id');
    if ($id <= 0) {
        ecFail(400, 'bad_id', 'id is mandatory');
    }
    $chk = $db->fetchFirstRow(
        "SELECT name FROM {$t} WHERE id = " . intval($id) .
        " AND testproject_id = " . intval($tpid));
    if (!is_array($chk) || !$chk) {
        ecFail(404, 'criterion_not_found', 'Exit criterion not found');
    }
    $db->exec_query(
        "DELETE FROM {$t} WHERE id = " . intval($id) .
        " AND testproject_id = " . intval($tpid));

    $event = new stdClass();
    $event->message = 'Exit criterion deleted: ' . (string)$chk['name'] .
                      ' (id ' . intval($id) . ')';
    $event->logLevel = 'AUDIT';
    $event->source = 'GUI';
    $event->objectID = $tpid;
    $event->objectType = 'testprojects';
    $event->code = 'EXIT_CRITERIA_DELETE';
    logEvent($event);

    echo json_encode(array('status' => 'ok', 'deleted' => 1, 'message' => 'Deleted'));
    exit;
}

ecFail(400, 'unknown_action', 'Unknown action');
