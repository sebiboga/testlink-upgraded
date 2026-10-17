<?php
/**
 * Risk-Based Testing registry BFF API
 * URL: /api/risk/
 * Plain PHP, no framework, no compilation.
 *
 * Backs the modern screen gui/templates/risk/riskRegister.html
 * (Refs #1930, parent feature #1066). TestLink 1.9.20 can trace requirements
 * and tests but has no first-class RISK asset, so risk-based prioritisation is
 * external/manual. This is a net-new, project-scoped risk registry: risks are
 * captured with impact / likelihood / severity / priority / mitigation / owner
 * / status and optionally linked to a requirement and a test case; the risk
 * level is DERIVED from impact x likelihood.
 *
 * Routes (session based, JSON I/O):
 *   GET  ?action=init&tproject_id=N
 *        -> { status, context, domains, stats, items }
 *   GET  ?action=list&tproject_id=N
 *        -> { status, items }                  (same rows as init.items)
 *   POST ?action=save { tproject_id, id?, name, impact, likelihood, severity,
 *                       priority, status, owner, mitigation, requirement_id,
 *                       tcase_id, notes }
 *        -> { status, id }
 *   POST ?action=delete { tproject_id, id }
 *        -> { status, deleted }
 *
 * Rights model (checked on the ADDRESSED test project BEFORE it is resolved,
 * the #1697 lesson: no existence oracle):
 *   - reads  require mgt_view_tc OR mgt_modify_tc on the project
 *   - writes require mgt_modify_tc on the project
 *   - unauthenticated -> 401; unknown action -> 400; wrong verb -> 405;
 *     an id that is not in the project -> 404; a foreign/unknown linked
 *     requirement or test case -> 400
 *
 * Writes emit AUDIT events (RISK_CREATE / RISK_SAVE / RISK_DELETE) so the
 * Event Viewer tracks the module activity.
 *
 * The tc_risks table is created lazily (CREATE TABLE IF NOT EXISTS) so a
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

/** Allowed risk statuses. */
function riskStatuses() {
    return array('open', 'mitigated', 'accepted', 'closed');
}

/** Allowed risk levels (derived). */
function riskLevels() {
    return array('low', 'medium', 'high', 'critical');
}

/** Table name handling (respects the optional DB_TABLE_PREFIX). */
function riskTable() {
    $t = tlObjectWithDB::getDBTables(array('tc_risks'));
    return $t['tc_risks'];
}

/** Idempotent lazy schema migration so a freshly imported DB works unchanged. */
function riskEnsureSchema($db) {
    $t = riskTable();
    $db->exec_query(
        "CREATE TABLE IF NOT EXISTS {$t} (" .
        " id INT UNSIGNED NOT NULL AUTO_INCREMENT," .
        " testproject_id INT UNSIGNED NOT NULL DEFAULT 0," .
        " name VARCHAR(255) NOT NULL DEFAULT ''," .
        " impact TINYINT UNSIGNED NOT NULL DEFAULT 3," .
        " likelihood TINYINT UNSIGNED NOT NULL DEFAULT 3," .
        " severity TINYINT UNSIGNED NOT NULL DEFAULT 3," .
        " priority TINYINT UNSIGNED NOT NULL DEFAULT 3," .
        " status VARCHAR(16) NOT NULL DEFAULT 'open'," .
        " owner VARCHAR(128) NOT NULL DEFAULT ''," .
        " mitigation TEXT NULL," .
        " requirement_id INT UNSIGNED NULL," .
        " tcase_id INT UNSIGNED NULL," .
        " notes TEXT NULL," .
        " created_by INT UNSIGNED NULL," .
        " creation_ts TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP," .
        " update_ts DATETIME NULL," .
        " PRIMARY KEY (id)," .
        " UNIQUE KEY uq_risk_project_name (testproject_id, name)," .
        " KEY idx_risk_project (testproject_id)," .
        " KEY idx_risk_status (testproject_id, status)" .
        " ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    return $t;
}

/** Read the JSON request body (POST routes). */
function riskBody() {
    static $cached = null;
    if ($cached === null) {
        $cached = json_decode(file_get_contents('php://input'), true);
        if (!is_array($cached)) { $cached = array(); }
    }
    return $cached;
}

/** Trim + length-cap a scalar payload/get field. */
function riskField($input, $key, $maxLen, $default = '') {
    $v = $input[$key] ?? $default;
    $v = is_scalar($v) ? trim((string)$v) : '';
    return mb_substr($v, 0, $maxLen);
}

/** Strict positive int from GET/body, 0 when absent/invalid/non-scalar. */
function riskInt($input, $key) {
    $v = $input[$key] ?? 0;
    return is_scalar($v) ? intval($v) : 0;
}

/** A 1..5 score field, clamped; invalid/non-scalar -> default. */
function riskScore($input, $key, $default = 3) {
    $v = $input[$key] ?? null;
    if (!is_scalar($v) || $v === '' ) { return $default; }
    $n = intval($v);
    if ($n < 1) { $n = 1; }
    if ($n > 5) { $n = 5; }
    return $n;
}

/** Derive level from impact x likelihood (1..25). */
function riskLevel($impact, $likelihood) {
    $score = intval($impact) * intval($likelihood);
    if ($score >= 15) { return 'critical'; }
    if ($score >= 10) { return 'high'; }
    if ($score >= 5) { return 'medium'; }
    return 'low';
}

/** Resolve the addressed test project: GET param wins, body, then session. */
function riskResolveTproject() {
    $tpid = riskInt($_GET, 'tproject_id');
    if ($tpid <= 0) {
        $tpid = riskInt(riskBody(), 'tproject_id');
    }
    if ($tpid <= 0) {
        $tpid = intval($_SESSION['testprojectID'] ?? 0);
    }
    return $tpid;
}

/** Whether the user may read risks of a project. */
function riskCanView($user, $db, $tpid) {
    foreach (array('mgt_view_tc', 'mgt_modify_tc') as $r) {
        if ($user->hasRight($db, $r, $tpid) === 'yes') { return true; }
    }
    return false;
}

/** Whether the user may write risks of a project. */
function riskCanModify($user, $db, $tpid) {
    return ($user->hasRight($db, 'mgt_modify_tc', $tpid) === 'yes');
}

/** Project display name + prefix, or null when the project does not exist. */
function riskProjectInfo($db, $tpid) {
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
 * When a nodes_hierarchy id is inside the addressed project, return the node's
 * OWN node_type_id; otherwise false. Returning the type lets callers reject a
 * cross-type link (e.g. a requirement id stored in the test-case column) - the
 * walk alone would accept any in-project node, including the project root.
 */
function riskNodeInProject($db, $nodeId, $tpid) {
    $nh = tlObjectWithDB::getDBTables(array('nodes_hierarchy'))['nodes_hierarchy'];
    $self = intval($nodeId);
    $selfType = 0;
    $cur = $self;
    $guard = 0;
    while ($cur > 0 && $guard < 64) {
        $row = $db->fetchFirstRow(
            "SELECT parent_id, node_type_id FROM {$nh} WHERE id = " . intval($cur));
        if (!is_array($row) || !$row) { return false; }
        if ($cur === $self) { $selfType = intval($row['node_type_id']); }
        if (intval($row['node_type_id']) === 1) {
            return (intval($cur) === intval($tpid)) ? $selfType : false;
        }
        $cur = intval($row['parent_id']);
        $guard++;
    }
    return false;
}

/** Display label for a requirement/test-case node id. */
function riskNodeLabel($db, $nodeId) {
    if (intval($nodeId) <= 0) { return ''; }
    $nh = tlObjectWithDB::getDBTables(array('nodes_hierarchy'))['nodes_hierarchy'];
    $row = $db->fetchFirstRow(
        "SELECT name FROM {$nh} WHERE id = " . intval($nodeId));
    return (is_array($row) && $row) ? (string)$row['name'] : '';
}

/** List risks of a project (highest score first, then name). */
function riskList($db, $t, $tpid) {
    $rows = (array)$db->get_recordset(
        "SELECT * FROM {$t} WHERE testproject_id = " . intval($tpid) .
        " ORDER BY (impact * likelihood) DESC, name ASC, id ASC");
    $items = array();
    foreach ((array)$rows as $r) {
        $impact = intval($r['impact']);
        $likelihood = intval($r['likelihood']);
        $reqId = intval($r['requirement_id'] ?? 0);
        $tcId = intval($r['tcase_id'] ?? 0);
        $items[] = array(
            'id' => intval($r['id']),
            'name' => (string)$r['name'],
            'impact' => $impact,
            'likelihood' => $likelihood,
            'severity' => intval($r['severity']),
            'priority' => intval($r['priority']),
            'risk_score' => $impact * $likelihood,
            'risk_level' => riskLevel($impact, $likelihood),
            'status' => (string)$r['status'],
            'owner' => (string)$r['owner'],
            'mitigation' => (string)($r['mitigation'] ?? ''),
            'requirement_id' => $reqId,
            'requirement_label' => $reqId > 0 ? riskNodeLabel($db, $reqId) : '',
            'tcase_id' => $tcId,
            'tcase_label' => $tcId > 0 ? riskNodeLabel($db, $tcId) : '',
            'notes' => (string)($r['notes'] ?? ''),
            'created_by' => intval($r['created_by']),
            'creation_ts' => (string)($r['creation_ts'] ?? ''),
            'update_ts' => (string)($r['update_ts'] ?? ''),
        );
    }
    return $items;
}

/** Compute registry stats from a list. */
function riskStats($items) {
    $s = array('total' => count($items), 'open' => 0, 'mitigated' => 0,
               'accepted' => 0, 'closed' => 0, 'low' => 0, 'medium' => 0,
               'high' => 0, 'critical' => 0, 'linked' => 0, 'unlinked' => 0);
    foreach ($items as $it) {
        if (isset($s[$it['status']])) { $s[$it['status']]++; }
        if (isset($s[$it['risk_level']])) { $s[$it['risk_level']]++; }
        if ($it['requirement_id'] > 0 || $it['tcase_id'] > 0) {
            $s['linked']++;
        } else {
            $s['unlinked']++;
        }
    }
    return $s;
}

/**
 * Linked-entity pickers for the create/edit form: every requirement (node type
 * 7) and test case (node type 8) in the addressed project subtree, resolved by
 * one recursive CTE over nodes_hierarchy (test cases are node type 3, and
 * requirements are node type 7 - all nodes whose ancestor chain reaches the
 * project root; type 8 nodes are requirement VERSIONS and are excluded).
 */
function riskRefs($db, $tpid) {
    $nh = tlObjectWithDB::getDBTables(array('nodes_hierarchy'))['nodes_hierarchy'];
    $reqs = array();
    $tcs = array();
    $sql = "WITH RECURSIVE subtree AS (" .
           " SELECT id, parent_id, node_type_id, name FROM {$nh} WHERE id = " . intval($tpid) .
           " UNION ALL" .
           " SELECT nh.id, nh.parent_id, nh.node_type_id, nh.name" .
           " FROM {$nh} nh JOIN subtree s ON nh.parent_id = s.id" .
           ") SELECT id, name, node_type_id FROM subtree" .
           " WHERE node_type_id IN (3, 7) AND name <> '' ORDER BY name ASC";
    $rows = (array)$db->get_recordset($sql);
    foreach ((array)$rows as $r) {
        $entry = array('id' => intval($r['id']), 'label' => (string)$r['name']);
        if (intval($r['node_type_id']) === 7) { $reqs[] = $entry; }
        else { $tcs[] = $entry; }
    }
    return array('requirements' => $reqs, 'tcases' => $tcs);
}

// --------------------------------------------------------------------------
// Routing
// --------------------------------------------------------------------------

$t = riskEnsureSchema($db);
$tpid = riskResolveTproject();

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
    if (!riskCanModify($user, $db, $tpid)) {
        http_response_code(403);
        echo json_encode(array('status' => 'error', 'code' => 'no_right',
            'message' => 'No permission to modify risks'));
        exit;
    }
} else {
    if (!riskCanView($user, $db, $tpid)) {
        http_response_code(403);
        echo json_encode(array('status' => 'error', 'code' => 'no_right',
            'message' => 'No permission to view risks'));
        exit;
    }
}

if ($action === '') {
    $action = $isWrite ? 'save' : 'init';
}

// ---- reads -----------------------------------------------------------------

if (!$isWrite && ($action === 'init' || $action === 'list')) {
    $info = riskProjectInfo($db, $tpid);
    if ($info === null) {
        http_response_code(404);
        echo json_encode(array('status' => 'error', 'code' => 'tproject_not_found',
            'message' => 'Test project not found'));
        exit;
    }
    $items = riskList($db, $t, $tpid);
    $payload = array(
        'status' => 'ok',
        'context' => array(
            'tproject_id' => intval($tpid),
            'tproject_name' => (string)$info['name'],
            'tproject_prefix' => (string)$info['prefix'],
            'can_view' => true,
            'can_modify' => riskCanModify($user, $db, $tpid),
        ),
        'domains' => array(
            'statuses' => riskStatuses(),
            'levels' => riskLevels(),
            'scales' => array(1, 2, 3, 4, 5),
        ),
        'stats' => riskStats($items),
        'refs' => riskRefs($db, $tpid),
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
    $body = riskBody();
    $name = riskField($body, 'name', 255);
    if ($name === '') {
        http_response_code(400);
        echo json_encode(array('status' => 'error', 'code' => 'name_required',
            'message' => 'Name is mandatory'));
        exit;
    }
    $status = riskField($body, 'status', 16, 'open');
    if (!in_array($status, riskStatuses(), true)) {
        http_response_code(400);
        echo json_encode(array('status' => 'error', 'code' => 'bad_status',
            'message' => 'Invalid status'));
        exit;
    }
    $impact = riskScore($body, 'impact', 3);
    $likelihood = riskScore($body, 'likelihood', 3);
    $severity = riskScore($body, 'severity', 3);
    $priority = riskScore($body, 'priority', 3);
    $owner = riskField($body, 'owner', 128);
    $mitigation = riskField($body, 'mitigation', 20000);
    $notes = riskField($body, 'notes', 20000);
    $reqId = riskInt($body, 'requirement_id');
    $tcId = riskInt($body, 'tcase_id');
    $id = riskInt($body, 'id');

    // Prove the linked nodes belong to this project AND are of the expected
    // node type (7/8 = requirement/requirement-version, 3 = test case); a bare
    // membership walk would also accept a suite, plan or the project root.
    if ($reqId > 0) {
        $reqType = riskNodeInProject($db, $reqId, $tpid);
        if ($reqType === false || !in_array($reqType, array(7, 8), true)) {
            http_response_code(400);
            echo json_encode(array('status' => 'error', 'code' => 'invalid_requirement',
                'message' => 'The linked requirement does not belong to this project'));
            exit;
        }
    }
    if ($tcId > 0) {
        $tcType = riskNodeInProject($db, $tcId, $tpid);
        if ($tcType !== 3) {
            http_response_code(400);
            echo json_encode(array('status' => 'error', 'code' => 'invalid_tcase',
                'message' => 'The linked test case does not belong to this project'));
            exit;
        }
    }

    // name unique per project (own name allowed on update)
    $dupe = $db->fetchFirstRow(
        "SELECT id FROM {$t} WHERE testproject_id = " . intval($tpid) .
        " AND name = '" . $db->prepare_string($name) . "'" .
        " AND id != " . intval($id));
    if (is_array($dupe) && $dupe) {
        http_response_code(409);
        echo json_encode(array('status' => 'error', 'code' => 'duplicate_name',
            'message' => 'A risk with this name already exists'));
        exit;
    }

    $reqVal = $reqId > 0 ? intval($reqId) : 'NULL';
    $tcVal = $tcId > 0 ? intval($tcId) : 'NULL';

    if ($id > 0) {
        $chk = $db->fetchFirstRow(
            "SELECT id FROM {$t} WHERE id = " . intval($id) .
            " AND testproject_id = " . intval($tpid));
        if (!is_array($chk) || !$chk) {
            http_response_code(404);
            echo json_encode(array('status' => 'error', 'code' => 'risk_not_found',
                'message' => 'Risk not found'));
            exit;
        }
        try {
            $db->exec_query(
                "UPDATE {$t} SET name = '" . $db->prepare_string($name) . "'," .
                " impact = " . intval($impact) . "," .
                " likelihood = " . intval($likelihood) . "," .
                " severity = " . intval($severity) . "," .
                " priority = " . intval($priority) . "," .
                " status = '" . $db->prepare_string($status) . "'," .
                " owner = '" . $db->prepare_string($owner) . "'," .
                " mitigation = '" . $db->prepare_string($mitigation) . "'," .
                " requirement_id = " . $reqVal . "," .
                " tcase_id = " . $tcVal . "," .
                " notes = '" . $db->prepare_string($notes) . "'," .
                " update_ts = NOW()" .
                " WHERE id = " . intval($id) . " AND testproject_id = " . intval($tpid));
        } catch (Exception $e) {
            // exec_query() throws on any failure (database.class.php) so a
            // concurrent create that tripped uq_risk_project_name must be
            // detected here, not by inspecting insert_id/rowcount.
            if (intval($db->error_num()) === 1062) {
                http_response_code(409);
                echo json_encode(array('status' => 'error', 'code' => 'duplicate_name',
                    'message' => 'A risk with this name already exists'));
                exit;
            }
            http_response_code(500);
            echo json_encode(array('status' => 'error', 'code' => 'save_failed',
                'message' => 'Could not save risk'));
            exit;
        }

        $event = new stdClass();
        $event->message = 'Risk saved: ' . $name . ' (id ' . $id . ')';
        $event->logLevel = 'AUDIT';
        $event->source = 'GUI';
        $event->objectID = $tpid;
        $event->objectType = 'testprojects';
        $event->code = 'RISK_SAVE';
        logEvent($event);

        echo json_encode(array('status' => 'ok', 'id' => intval($id),
            'message' => 'Saved'));
        exit;
    }

    try {
        $db->exec_query(
            "INSERT INTO {$t} (testproject_id, name, impact, likelihood, severity," .
            " priority, status, owner, mitigation, requirement_id, tcase_id, notes," .
            " created_by, update_ts) VALUES (" .
            intval($tpid) . ", '" . $db->prepare_string($name) . "', " .
            intval($impact) . ", " . intval($likelihood) . ", " . intval($severity) .
            ", " . intval($priority) . ", '" . $db->prepare_string($status) . "', '" .
            $db->prepare_string($owner) . "', '" . $db->prepare_string($mitigation) .
            "', " . $reqVal . ", " . $tcVal . ", '" .
            $db->prepare_string($notes) . "', " . intval($userId) . ", NOW())");
    } catch (Exception $e) {
        // A concurrent create may have won the race and tripped the
        // uq_risk_project_name key; exec_query() throws before insert_id().
        if (intval($db->error_num()) === 1062) {
            http_response_code(409);
            echo json_encode(array('status' => 'error', 'code' => 'duplicate_name',
                'message' => 'A risk with this name already exists'));
            exit;
        }
        http_response_code(500);
        echo json_encode(array('status' => 'error', 'code' => 'insert_failed',
            'message' => 'Could not create risk'));
        exit;
    }
    $newId = (int)$db->insert_id($t);
    if ($newId <= 0) {
        http_response_code(500);
        echo json_encode(array('status' => 'error', 'code' => 'insert_failed',
            'message' => 'Could not create risk'));
        exit;
    }

    $event = new stdClass();
    $event->message = 'Risk created: ' . $name . ' (id ' . $newId . ')';
    $event->logLevel = 'AUDIT';
    $event->source = 'GUI';
    $event->objectID = $tpid;
    $event->objectType = 'testprojects';
    $event->code = 'RISK_CREATE';
    logEvent($event);

    echo json_encode(array('status' => 'ok', 'id' => $newId, 'message' => 'Created'));
    exit;
}

if ($action === 'delete') {
    $body = riskBody();
    $id = riskInt($body, 'id');
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
        echo json_encode(array('status' => 'error', 'code' => 'risk_not_found',
            'message' => 'Risk not found'));
        exit;
    }
    try {
        $db->exec_query(
            "DELETE FROM {$t} WHERE id = " . intval($id) .
            " AND testproject_id = " . intval($tpid));
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(array('status' => 'error', 'code' => 'delete_failed',
            'message' => 'Could not delete risk'));
        exit;
    }

    $event = new stdClass();
    $event->message = 'Risk deleted: ' . (string)$chk['name'] . ' (id ' . $id . ')';
    $event->logLevel = 'AUDIT';
    $event->source = 'GUI';
    $event->objectID = $tpid;
    $event->objectType = 'testprojects';
    $event->code = 'RISK_DELETE';
    logEvent($event);

    echo json_encode(array('status' => 'ok', 'deleted' => 1, 'message' => 'Deleted'));
    exit;
}

http_response_code(400);
echo json_encode(array('status' => 'error', 'code' => 'unknown_action',
    'message' => 'Unknown action'));
