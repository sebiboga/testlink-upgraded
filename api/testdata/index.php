<?php
/**
 * Test Data Management BFF API
 * URL: /api/testdata/
 * Plain PHP, no framework, no compilation.
 *
 * Backs the modern screen gui/templates/testdata/testDataSets.html (Refs #1927,
 * parent feature #1069). TestLink 1.9.20 treats test data as an afterthought:
 * no data-set entity exists at all (see the #1069 investigation), so this is a
 * net-new module: a project-scoped registry of VERSIONED test data sets, each
 * with a data-type (reusable / temporary / environment), a PII classification
 * plus a masking rule, a sample payload, free notes, and an optional link to a
 * test case so the set's usage is traceable.
 *
 * Routes (session based, JSON I/O):
 *   GET  ?action=init&tproject_id=N
 *        -> { status, context, domains, stats, items }
 *   GET  ?action=list&tproject_id=N
 *        -> { status, items }                  (same rows as init.items)
 *   GET  ?action=history&tproject_id=N&set_group=G
 *        -> { status, set_group, name, versions }
 *   POST ?action=save { tproject_id, set_group?, name, data_type,
 *                       classification, masking_rule, payload, notes, tcase_id }
 *        -> { status, id, set_group, version }
 *   POST ?action=delete { tproject_id, set_group }
 *        -> { status, deleted }
 *
 * Rights model (checked on the ADDRESSED test project BEFORE it is resolved,
 * the #1697 lesson: no existence oracle):
 *   - reads  require mgt_view_tc OR mgt_modify_tc on the project
 *   - writes require mgt_modify_tc on the project
 *   - unauthenticated -> 401; unknown action -> 400; wrong verb -> 405;
 *     a listed set_group that is not in the project -> 404
 *
 * Writes emit AUDIT events (TEST_DATA_CREATE / TEST_DATA_VERSION /
 * TEST_DATA_DELETE) so the Event Viewer tracks the module activity.
 *
 * The tc_test_data_sets table is created lazily (CREATE TABLE IF NOT EXISTS)
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

/** Allowed data-set kinds. */
function tdsDataTypes() {
    return array('reusable', 'temporary', 'environment');
}

/** Allowed data classifications (PII sensitivity). */
function tdsClassifications() {
    return array('public', 'internal', 'confidential', 'pii');
}

/** Table name handling (respects the optional DB_TABLE_PREFIX). */
function tdsTable() {
    $t = tlObjectWithDB::getDBTables(array('tc_test_data_sets'));
    return $t['tc_test_data_sets'];
}

/** Idempotent lazy schema migration so a freshly imported DB works unchanged. */
function tdsEnsureSchema($db) {
    $t = tdsTable();
    $db->exec_query(
        "CREATE TABLE IF NOT EXISTS {$t} (" .
        " id INT UNSIGNED NOT NULL AUTO_INCREMENT," .
        " testproject_id INT UNSIGNED NOT NULL DEFAULT 0," .
        " set_group INT UNSIGNED NOT NULL DEFAULT 0," .
        " version INT UNSIGNED NOT NULL DEFAULT 1," .
        " name VARCHAR(255) NOT NULL DEFAULT ''," .
        " data_type VARCHAR(16) NOT NULL DEFAULT 'reusable'," .
        " classification VARCHAR(16) NOT NULL DEFAULT 'internal'," .
        " masking_rule VARCHAR(255) NOT NULL DEFAULT ''," .
        " payload MEDIUMTEXT NULL," .
        " notes TEXT NULL," .
        " tcase_id INT UNSIGNED NOT NULL DEFAULT 0," .
        " created_by INT UNSIGNED NULL," .
        " creation_ts TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP," .
        " update_ts DATETIME NULL," .
        " PRIMARY KEY (id)," .
        " KEY idx_tds_project (testproject_id, set_group)," .
        " KEY idx_tds_tcase (tcase_id)" .
        " ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    return $t;
}

/** Read the JSON request body (POST routes). */
function tdsBody() {
    static $cached = null;
    if ($cached === null) {
        $cached = json_decode(file_get_contents('php://input'), true);
        if (!is_array($cached)) { $cached = array(); }
    }
    return $cached;
}

/** Trim + length-cap a scalar payload/get field. */
function tdsField($input, $key, $maxLen, $default = '') {
    $v = $input[$key] ?? $default;
    $v = is_scalar($v) ? trim((string)$v) : '';
    return mb_substr($v, 0, $maxLen);
}

/** Strict positive int from GET/body, 0 when absent/invalid/non-scalar. */
function tdsInt($input, $key) {
    $v = $input[$key] ?? 0;
    return is_scalar($v) ? intval($v) : 0;
}

/** Resolve the addressed test project: GET param wins, body, then session. */
function tdsResolveTproject() {
    $tpid = tdsInt($_GET, 'tproject_id');
    if ($tpid <= 0) {
        $tpid = tdsInt(tdsBody(), 'tproject_id');
    }
    if ($tpid <= 0) {
        $tpid = intval($_SESSION['testprojectID'] ?? 0);
    }
    return $tpid;
}

/** Whether the user may read test data of a project. */
function tdsCanView($user, $db, $tpid) {
    foreach (array('mgt_view_tc', 'mgt_modify_tc') as $r) {
        if ($user->hasRight($db, $r, $tpid) === 'yes') { return true; }
    }
    return false;
}

/** Whether the user may write test data of a project. */
function tdsCanModify($user, $db, $tpid) {
    return ($user->hasRight($db, 'mgt_modify_tc', $tpid) === 'yes');
}

/** Project display name + prefix, or null when the project does not exist. */
function tdsProjectInfo($db, $tpid) {
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
 * Prove a test case node id exists inside the addressed test project.
 * Test cases are nodes_hierarchy rows of node_type_id = 3 whose ancestry
 * climbs back to the project root (node_type_id = 1).
 */
function tdsTcaseInProject($db, $tcaseId, $tpid) {
    if ($tcaseId <= 0) { return true; }
    $tbl = tlObjectWithDB::getDBTables(array('nodes_hierarchy'));
    $nh = $tbl['nodes_hierarchy'];
    $first = $db->fetchFirstRow(
        "SELECT parent_id, node_type_id FROM {$nh} WHERE id = " . intval($tcaseId));
    if (!is_array($first) || !$first || intval($first['node_type_id']) !== 3) {
        return false;
    }
    $nodeId = intval($first['parent_id']);
    $guard = 0;
    while ($nodeId > 0 && $guard < 100) {
        $p = $db->fetchFirstRow(
            "SELECT parent_id, node_type_id FROM {$nh} WHERE id = " . $nodeId);
        if (!is_array($p) || !$p) { return false; }
        if (intval($p['node_type_id']) === 1) {
            return ($nodeId === intval($tpid));
        }
        $nodeId = intval($p['parent_id']);
        $guard++;
    }
    return false;
}

/** Batch-resolve linked test cases to {tcase_id: {name, external_id}}. */
function tdsTcaseMap($db, $tpid, $tcaseIds) {
    $out = array();
    $ids = array();
    foreach ($tcaseIds as $id) {
        $id = intval($id);
        if ($id > 0) { $ids[$id] = true; }
    }
    if (empty($ids)) { return $out; }
    $idList = implode(',', array_keys($ids));
    $tbl = tlObjectWithDB::getDBTables(array('nodes_hierarchy', 'tcversions', 'testprojects'));
    $nh = $tbl['nodes_hierarchy'];
    $tv = $tbl['tcversions'];
    $tp = $tbl['testprojects'];

    $prefix = '';
    $prow = $db->fetchFirstRow("SELECT prefix FROM {$tp} WHERE id = " . intval($tpid));
    if (is_array($prow) && $prow) { $prefix = (string)$prow['prefix']; }

    $names = (array)$db->get_recordset(
        "SELECT id, name FROM {$nh} WHERE node_type_id = 3 AND id IN ({$idList})");
    foreach ((array)$names as $r) {
        $cid = intval($r['id']);
        $out[$cid] = array('tcase_id' => $cid, 'name' => (string)$r['name'],
                           'external_id' => '', 'prefix' => $prefix);
    }
    // latest version external id per linked case (version nodes are node_type 4)
    $ext = (array)$db->get_recordset(
        "SELECT nh.parent_id AS tcase_id, tv.tc_external_id AS ext " .
        " FROM {$nh} nh JOIN {$tv} tv ON tv.id = nh.id " .
        " WHERE nh.node_type_id = 4 AND nh.parent_id IN ({$idList}) " .
        " AND nh.id = (SELECT MAX(nh2.id) FROM {$nh} nh2 " .
        "              WHERE nh2.parent_id = nh.parent_id AND nh2.node_type_id = 4)");
    foreach ((array)$ext as $r) {
        $cid = intval($r['tcase_id']);
        if (isset($out[$cid]) && $r['ext'] !== null && $r['ext'] !== '') {
            $out[$cid]['external_id'] = $prefix . '-' . intval($r['ext']);
        }
    }
    return $out;
}

/**
 * Latest-version rows of a project, optionally resolving linked test cases.
 * The unit of the registry is a set GROUP; the list shows the newest version.
 */
function tdsList($db, $t, $tpid) {
    $rows = (array)$db->get_recordset(
        "SELECT s.* FROM {$t} s " .
        " JOIN (SELECT set_group, MAX(version) AS v FROM {$t} " .
        "       WHERE testproject_id = " . intval($tpid) . " GROUP BY set_group) m " .
        "   ON s.set_group = m.set_group AND s.version = m.v " .
        " WHERE s.testproject_id = " . intval($tpid) . " " .
        " ORDER BY s.name ASC, s.set_group ASC");

    $tcIds = array();
    $counts = array();
    foreach ((array)$rows as $r) {
        if (intval($r['tcase_id']) > 0) { $tcIds[] = intval($r['tcase_id']); }
    }
    $tcMap = tdsTcaseMap($db, $tpid, $tcIds);

    // version count per group
    $vc = (array)$db->get_recordset(
        "SELECT set_group, COUNT(*) AS n FROM {$t} " .
        " WHERE testproject_id = " . intval($tpid) . " GROUP BY set_group");
    foreach ((array)$vc as $r) { $counts[intval($r['set_group'])] = intval($r['n']); }

    $items = array();
    foreach ((array)$rows as $r) {
        $gid = intval($r['set_group']);
        $tcid = intval($r['tcase_id']);
        $tc = ($tcid > 0 && isset($tcMap[$tcid])) ? $tcMap[$tcid] : null;
        $items[] = array(
            'id' => intval($r['id']),
            'set_group' => $gid,
            'version' => intval($r['version']),
            'versions_count' => $counts[$gid] ?? 1,
            'name' => (string)$r['name'],
            'data_type' => (string)$r['data_type'],
            'classification' => (string)$r['classification'],
            'masking_rule' => (string)$r['masking_rule'],
            'payload_len' => strlen((string)($r['payload'] ?? '')),
            'notes' => (string)($r['notes'] ?? ''),
            'tcase_id' => $tcid,
            'tcase' => $tc,
            'created_by' => intval($r['created_by']),
            'creation_ts' => (string)($r['creation_ts'] ?? ''),
            'update_ts' => (string)($r['update_ts'] ?? ''),
        );
    }
    return $items;
}

/** Compute inventory stats from a list. */
function tdsStats($items) {
    $s = array('sets' => count($items), 'versions' => 0, 'reusable' => 0,
               'temporary' => 0, 'environment' => 0, 'pii' => 0, 'linked' => 0);
    foreach ($items as $it) {
        $s['versions'] += intval($it['versions_count']);
        if (isset($s[$it['data_type']])) { $s[$it['data_type']]++; }
        if ($it['classification'] === 'pii' || $it['classification'] === 'confidential') {
            $s['pii']++;
        }
        if (intval($it['tcase_id']) > 0) { $s['linked']++; }
    }
    return $s;
}

// --------------------------------------------------------------------------
// Routing
// --------------------------------------------------------------------------

$t = tdsEnsureSchema($db);
$tpid = tdsResolveTproject();

if ($tpid <= 0) {
    http_response_code(400);
    echo json_encode(array('status' => 'error', 'code' => 'no_testproject',
        'message' => 'No test project selected'));
    exit;
}

// rights BEFORE project resolution (no existence oracle - #1697).
$isWrite = ($method === 'POST');
if ($isWrite) {
    if (!tdsCanModify($user, $db, $tpid)) {
        http_response_code(403);
        echo json_encode(array('status' => 'error', 'code' => 'no_right',
            'message' => 'No permission to modify test data'));
        exit;
    }
} else {
    if (!tdsCanView($user, $db, $tpid)) {
        http_response_code(403);
        echo json_encode(array('status' => 'error', 'code' => 'no_right',
            'message' => 'No permission to view test data'));
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
    $info = tdsProjectInfo($db, $tpid);
    if ($info === null) {
        http_response_code(404);
        echo json_encode(array('status' => 'error', 'code' => 'tproject_not_found',
            'message' => 'Test project not found'));
        exit;
    }
    $items = tdsList($db, $t, $tpid);
    $payload = array(
        'status' => 'ok',
        'context' => array(
            'tproject_id' => intval($tpid),
            'tproject_name' => (string)$info['name'],
            'tproject_prefix' => (string)$info['prefix'],
            'can_view' => tdsCanView($user, $db, $tpid),
            'can_modify' => tdsCanModify($user, $db, $tpid),
        ),
        'domains' => array(
            'data_types' => tdsDataTypes(),
            'classifications' => tdsClassifications(),
        ),
        'stats' => tdsStats($items),
        'items' => $items,
    );
    echo json_encode($payload);
    exit;
}

if (!$isWrite && $action === 'history') {
    $group = tdsInt($_GET, 'set_group');
    if ($group <= 0) {
        http_response_code(400);
        echo json_encode(array('status' => 'error', 'code' => 'bad_set_group',
            'message' => 'set_group is mandatory'));
        exit;
    }
    $rows = (array)$db->get_recordset(
        "SELECT * FROM {$t} WHERE testproject_id = " . intval($tpid) .
        " AND set_group = " . intval($group) . " ORDER BY version DESC");
    if (empty($rows)) {
        http_response_code(404);
        echo json_encode(array('status' => 'error', 'code' => 'set_not_found',
            'message' => 'Data set not found'));
        exit;
    }
    $versions = array();
    foreach ((array)$rows as $r) {
        $versions[] = array(
            'id' => intval($r['id']),
            'version' => intval($r['version']),
            'name' => (string)$r['name'],
            'data_type' => (string)$r['data_type'],
            'classification' => (string)$r['classification'],
            'masking_rule' => (string)$r['masking_rule'],
            'payload' => (string)($r['payload'] ?? ''),
            'payload_len' => strlen((string)($r['payload'] ?? '')),
            'notes' => (string)($r['notes'] ?? ''),
            'tcase_id' => intval($r['tcase_id']),
            'creation_ts' => (string)($r['creation_ts'] ?? ''),
        );
    }
    echo json_encode(array('status' => 'ok', 'set_group' => intval($group),
        'name' => (string)$rows[0]['name'], 'versions' => $versions));
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
    $body = tdsBody();
    $name = tdsField($body, 'name', 255);
    if ($name === '') {
        http_response_code(400);
        echo json_encode(array('status' => 'error', 'code' => 'name_required',
            'message' => 'Name is mandatory'));
        exit;
    }
    $dataType = tdsField($body, 'data_type', 16, 'reusable');
    if (!in_array($dataType, tdsDataTypes(), true)) {
        http_response_code(400);
        echo json_encode(array('status' => 'error', 'code' => 'bad_data_type',
            'message' => 'Invalid data type'));
        exit;
    }
    $classification = tdsField($body, 'classification', 16, 'internal');
    if (!in_array($classification, tdsClassifications(), true)) {
        http_response_code(400);
        echo json_encode(array('status' => 'error', 'code' => 'bad_classification',
            'message' => 'Invalid classification'));
        exit;
    }
    $masking = tdsField($body, 'masking_rule', 255);
    $payload = tdsField($body, 'payload', 60000);
    $notes = tdsField($body, 'notes', 60000);
    $tcaseId = tdsInt($body, 'tcase_id');
    if (!tdsTcaseInProject($db, $tcaseId, $tpid)) {
        http_response_code(400);
        echo json_encode(array('status' => 'error', 'code' => 'invalid_tcase',
            'message' => 'Test case does not belong to this project'));
        exit;
    }
    $group = tdsInt($body, 'set_group');

    $cols = "testproject_id, set_group, version, name, data_type, classification, " .
            "masking_rule, payload, notes, tcase_id, created_by, update_ts";
    $vTail = "'" . $db->prepare_string($name) . "', '" .
             $db->prepare_string($dataType) . "', '" .
             $db->prepare_string($classification) . "', '" .
             $db->prepare_string($masking) . "', '" .
             $db->prepare_string($payload) . "', '" .
             $db->prepare_string($notes) . "', " .
             intval($tcaseId) . ", " . intval($userId) . ", NOW()";

    if ($group <= 0) {
        // create: first version of a new group
        $db->exec_query(
            "INSERT INTO {$t} ({$cols}) VALUES (" .
            intval($tpid) . ", 0, 1, " . $vTail . ")");
        $newId = (int)$db->insert_id('tc_test_data_sets');
        if ($newId <= 0) {
            http_response_code(500);
            echo json_encode(array('status' => 'error', 'code' => 'insert_failed',
                'message' => 'Could not create data set'));
            exit;
        }
        $db->exec_query("UPDATE {$t} SET set_group = " . $newId . " WHERE id = " . $newId);
        $group = $newId;
        $version = 1;

        $event = new stdClass();
        $event->message = 'Test data set created: ' . $name . ' (id ' . $newId . ')';
        $event->logLevel = 'AUDIT';
        $event->source = 'GUI';
        $event->objectID = $tpid;
        $event->objectType = 'testprojects';
        $event->code = 'TEST_DATA_CREATE';
        logEvent($event);
    } else {
        // update -> new version in the same group; prove the group exists here
        $chk = $db->fetchFirstRow(
            "SELECT MAX(version) AS v FROM {$t} WHERE testproject_id = " . intval($tpid) .
            " AND set_group = " . intval($group));
        if (!is_array($chk) || $chk === null || $chk['v'] === null) {
            http_response_code(404);
            echo json_encode(array('status' => 'error', 'code' => 'set_not_found',
                'message' => 'Data set not found'));
            exit;
        }
        $version = intval($chk['v']) + 1;
        $db->exec_query(
            "INSERT INTO {$t} ({$cols}) VALUES (" .
            intval($tpid) . ", " . intval($group) . ", " .
            intval($version) . ", " . $vTail . ")");
        $newId = (int)$db->insert_id('tc_test_data_sets');
        if ($newId <= 0) {
            http_response_code(500);
            echo json_encode(array('status' => 'error', 'code' => 'insert_failed',
                'message' => 'Could not save data set version'));
            exit;
        }

        $event = new stdClass();
        $event->message = 'Test data set version ' . $version . ': ' . $name .
                          ' (group ' . $group . ')';
        $event->logLevel = 'AUDIT';
        $event->source = 'GUI';
        $event->objectID = $tpid;
        $event->objectType = 'testprojects';
        $event->code = 'TEST_DATA_VERSION';
        logEvent($event);
    }

    echo json_encode(array('status' => 'ok', 'id' => $newId,
        'set_group' => $group, 'version' => $version, 'message' => 'Saved'));
    exit;
}

if ($action === 'delete') {
    $body = tdsBody();
    $group = tdsInt($body, 'set_group');
    if ($group <= 0) {
        http_response_code(400);
        echo json_encode(array('status' => 'error', 'code' => 'bad_set_group',
            'message' => 'set_group is mandatory'));
        exit;
    }
    $chk = $db->fetchFirstRow(
        "SELECT COUNT(*) AS n FROM {$t} WHERE testproject_id = " . intval($tpid) .
        " AND set_group = " . intval($group));
    if (!is_array($chk) || intval($chk['n'] ?? 0) <= 0) {
        http_response_code(404);
        echo json_encode(array('status' => 'error', 'code' => 'set_not_found',
            'message' => 'Data set not found'));
        exit;
    }
    $db->exec_query(
        "DELETE FROM {$t} WHERE testproject_id = " . intval($tpid) .
        " AND set_group = " . intval($group));

    $event = new stdClass();
    $event->message = 'Test data set deleted (group ' . $group . ')';
    $event->logLevel = 'AUDIT';
    $event->source = 'GUI';
    $event->objectID = $tpid;
    $event->objectType = 'testprojects';
    $event->code = 'TEST_DATA_DELETE';
    logEvent($event);

    echo json_encode(array('status' => 'ok', 'deleted' => intval($chk['n']),
        'message' => 'Deleted'));
    exit;
}

http_response_code(400);
echo json_encode(array('status' => 'error', 'code' => 'unknown_action',
    'message' => 'Unknown action'));
