<?php
/**
 * Per-type Non-Functional Requirements (NFR) BFF API
 * URL: /api/nfrtype/
 * Plain PHP, no framework, no compilation.
 *
 * Backs the modernized per-type NFR screen
 * gui/templates/requirements/nfrTypeView.html (Refs #1470, #1052, #1462).
 * One dedicated CRUD screen per NFR type (performance / security /
 * usability / accessibility / compatibility / reliability / maintainability),
 * backed by the shared nfr_requirements table. Each test project can define
 * NFR requirements grouped by type; each carries a target value, a threshold
 * value, a source/reference and a status.
 *
 * Routes (session based, JSON I/O):
 *   GET  ?action=projects&type=X                  -> project selector list
 *   GET  ?action=meta&type=X                      -> type info + status domain
 *   GET  ?action=list&tproject_id=N&type=X        -> project rows of that type
 *                                                        + counts
 *   GET  ?action=item&id=N                        -> single row for the edit
 *                                                        modal
 *   POST ?action=create {tproject_id,title,type,...}
 *   POST ?action=update {id,...}
 *   POST ?action=delete {id}
 *
 * Rights model (legacy parity with the Requirements area, same as api/nfr):
 *   - reads require 'mgt_view_req' on the OWNING test project (403 otherwise)
 *   - writes require 'mgt_modify_req' on the OWNING test project (403)
 *   - unauthenticated -> 401; unknown action / bad type -> 400
 *   - forged/foreign id (item not owned by the resolved project) -> 404
 *
 * Writes also emit AUDIT events (NFR_CREATE / NFR_UPDATE / NFR_DELETE) via
 * logEvent() so the Event Viewer tracks the module activity.
 */
require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');

$db = null;
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

$tprojectMgr = new testproject($db);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_REQUEST['action'] ?? '';
$projectId = isset($_REQUEST['tproject_id'])
    ? (int)$_REQUEST['tproject_id'] : 0;

/** The seven NFR types of the module (order = menu order). */
function nfrtTypes() {
    return array(
        array('code' => 'performance',     'icon' => 'fa-gauge-high'),
        array('code' => 'security',        'icon' => 'fa-shield-halved'),
        array('code' => 'usability',       'icon' => 'fa-hand-pointer'),
        array('code' => 'accessibility',   'icon' => 'fa-universal-access'),
        array('code' => 'compatibility',   'icon' => 'fa-cubes'),
        array('code' => 'reliability',     'icon' => 'fa-life-ring'),
        array('code' => 'maintainability', 'icon' => 'fa-screwdriver-wrench'),
    );
}

/** i18n key that renders the per-type focus hint on the screen. */
function nfrtFocusKey($type) {
    return 'nfrt.focus.' . $type;
}

/** Requirement statuses (workflow states) of the module. */
function nfrtStatuses() {
    return array('proposed', 'approved', 'in_scope', 'waived');
}

/** Table name handling (respects the optional DB_TABLE_PREFIX). */
function nfrtTable() {
    $t = tlObjectWithDB::getDBTables(array('nfr_requirements'));
    return $t['nfr_requirements'];
}

/** Idempotent lazy schema migration so a freshly imported DB works unchanged. */
function nfrtEnsureSchema($db) {
    $t = nfrtTable();
    $db->exec_query(
        "CREATE TABLE IF NOT EXISTS {$t} (" .
        " id INT UNSIGNED NOT NULL AUTO_INCREMENT," .
        " testproject_id INT UNSIGNED NOT NULL DEFAULT 0," .
        " nfr_type VARCHAR(32) NOT NULL DEFAULT 'performance'," .
        " title VARCHAR(255) NOT NULL DEFAULT ''," .
        " description TEXT NULL," .
        " target_value VARCHAR(255) NOT NULL DEFAULT ''," .
        " threshold_value VARCHAR(255) NOT NULL DEFAULT ''," .
        " source_ref VARCHAR(255) NOT NULL DEFAULT ''," .
        " req_status VARCHAR(24) NOT NULL DEFAULT 'proposed'," .
        " author_id INT UNSIGNED NULL," .
        " creation_ts TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP," .
        " updated_ts TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP" .
        " ON UPDATE CURRENT_TIMESTAMP," .
        " PRIMARY KEY (id)," .
        " KEY idx_nfr_tproject (testproject_id)," .
        " KEY idx_nfr_type (nfr_type, testproject_id)" .
        " ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
}

/** Validate + normalize the requested NFR type (single one per screen). */
function nfrtResolveType() {
    $type = trim((string)($_GET['type'] ?? ''));
    if ($type === '') {
        $type = trim((string)($_SESSION['nfrt_type'] ?? ''));
    }
    if (!in_array($type, array_column(nfrtTypes(), 'code'), true)) {
        http_response_code(400);
        echo json_encode(array('status' => 'error', 'message' => 'Unknown NFR type'));
        exit;
    }
    $_SESSION['nfrt_type'] = $type;
    return $type;
}

/** Resolve the owning test project: param wins, session is the fallback. */
function nfrtResolveTproject() {
    $tpid = intval($_GET['tproject_id'] ?? 0);
    if ($tpid <= 0) {
        $tpid = intval($_SESSION['testprojectID'] ?? 0);
    }
    if ($tpid <= 0) {
        http_response_code(400);
        echo json_encode(array('status' => 'error', 'message' => 'No test project selected'));
        exit;
    }
    return $tpid;
}

/** Read the JSON request body (POST routes). */
function nfrtBody() {
    return json_decode(file_get_contents('php://input'), true) ?? array();
}

/** Trim + length-cap a scalar payload field. */
function nfrtField($input, $key, $maxLen, $default = '') {
    $v = $input[$key] ?? $default;
    $v = is_scalar($v) ? trim((string)$v) : '';
    return mb_substr($v, 0, $maxLen);
}

/** Build one row for the JSON payload (left join for author login). */
function nfrtRow($row) {
    return array(
        'id'              => (int)$row['id'],
        'testproject_id'  => (int)$row['testproject_id'],
        'nfr_type'        => $row['nfr_type'],
        'title'           => $row['title'],
        'description'     => isset($row['description']) ? $row['description'] : '',
        'target_value'    => isset($row['target_value']) ? $row['target_value'] : '',
        'threshold_value' => isset($row['threshold_value']) ? $row['threshold_value'] : '',
        'source_ref'      => isset($row['source_ref']) ? $row['source_ref'] : '',
        'req_status'      => $row['req_status'],
        'author_id'       => isset($row['author_id']) ? (int)$row['author_id'] : 0,
        'author_login'    => isset($row['login']) ? $row['login'] : '',
        'creation_ts'     => $row['creation_ts'],
        'updated_ts'      => $row['updated_ts'],
    );
}

switch ($method) {
    case 'GET':
        if ($action === 'projects') {
            $type = nfrtResolveType();
            $sql = "SELECT tp.id, nh.name, tp.prefix FROM testprojects tp " .
                   "JOIN nodes_hierarchy nh ON nh.id = tp.id ORDER BY nh.name";
            $rows = (array)$db->get_recordset($sql);
            $items = array();
            foreach ($rows as $row) {
                $tpid = (int)$row['id'];
                if ($user->hasRight($db, 'mgt_view_req', $tpid) !== 'yes') {
                    continue;
                }
                $items[] = array(
                    'id'     => $tpid,
                    'name'   => $row['name'],
                    'prefix' => $row['prefix'],
                );
            }
            echo json_encode(array('status' => 'ok', 'type' => $type,
                'projects' => $items));
            exit;
        }

        if ($action === 'meta') {
            $type = nfrtResolveType();
            $icon = 'fa-sliders';
            foreach (nfrtTypes() as $t) {
                if ($t['code'] === $type) {
                    $icon = $t['icon'];
                    break;
                }
            }
            $statuses = array();
            foreach (nfrtStatuses() as $s) {
                $statuses[] = array('code' => $s);
            }
            echo json_encode(array('status' => 'ok',
                'type' => array('code' => $type, 'icon' => $icon,
                    'focusKey' => nfrtFocusKey($type)),
                'statuses' => $statuses));
            exit;
        }

        if ($action === 'item') {
            $itemId = (int)($_GET['id'] ?? 0);
            if ($itemId <= 0) {
                http_response_code(400);
                echo json_encode(array('status' => 'error', 'message' => 'Requirement ID required'));
                exit;
            }
            nfrtEnsureSchema($db);
            $row = $db->fetchFirstRow(
                " SELECT r.*, u.login FROM " . nfrtTable() . " r " .
                " LEFT JOIN users u ON u.id = r.author_id " .
                " WHERE r.id = " . $itemId);
            if (!$row || !is_array($row)) {
                http_response_code(404);
                echo json_encode(array('status' => 'error', 'message' => 'Requirement not found'));
                exit;
            }
            $tpid = (int)$row['testproject_id'];
            if ($user->hasRight($db, 'mgt_view_req', $tpid) !== 'yes') {
                http_response_code(403);
                echo json_encode(array('status' => 'error', 'message' => 'No permission'));
                exit;
            }
            echo json_encode(array(
                'status' => 'ok',
                'item' => nfrtRow($row),
                'canEdit' => ($user->hasRight($db, 'mgt_modify_req', $tpid) === 'yes'),
            ));
            exit;
        }

        if ($action === 'list') {
            $type = nfrtResolveType();
            $tpid = nfrtResolveTproject();
            if ($user->hasRight($db, 'mgt_view_req', $tpid) !== 'yes') {
                http_response_code(403);
                echo json_encode(array('status' => 'error', 'message' => 'No permission'));
                exit;
            }
            $project = $tprojectMgr->get_by_id($tpid);
            if (is_null($project)) {
                http_response_code(404);
                echo json_encode(array('status' => 'error', 'message' => 'Test project not found'));
                exit;
            }

            nfrtEnsureSchema($db);
            $rows = (array)$db->get_recordset(
                " SELECT r.*, u.login FROM " . nfrtTable() . " r " .
                " LEFT JOIN users u ON u.id = r.author_id " .
                " WHERE r.testproject_id = " . intval($tpid) .
                " AND r.nfr_type = '" . $db->prepare_string($type) . "'" .
                " ORDER BY r.id ASC");
            $items = array();
            foreach ($rows as $row) {
                $items[] = nfrtRow($row);
            }

            $countRow = $db->fetchFirstRow(
                " SELECT COUNT(*) AS c FROM " . nfrtTable() .
                " WHERE testproject_id = " . intval($tpid) .
                " AND nfr_type = '" . $db->prepare_string($type) . "'");
            $count = $countRow && is_array($countRow) ? (int)$countRow['c'] : count($items);

            $icon = 'fa-sliders';
            foreach (nfrtTypes() as $t) {
                if ($t['code'] === $type) {
                    $icon = $t['icon'];
                    break;
                }
            }

            echo json_encode(array(
                'status'    => 'ok',
                'type'      => array('code' => $type, 'icon' => $icon,
                    'focusKey' => nfrtFocusKey($type)),
                'project'   => array(
                    'id'     => (int)$project['id'],
                    'name'   => $project['name'],
                    'prefix' => $project['prefix'],
                ),
                'canEdit'   => ($user->hasRight($db, 'mgt_modify_req', $tpid) === 'yes'),
                'count'     => $count,
                'items'     => $items,
            ));
            exit;
        }

        http_response_code(400);
        echo json_encode(array('status' => 'error', 'message' => 'Unknown action'));
        break;

    case 'POST':
        $body = nfrtBody();
        $action = $action !== '' ? $action : ($body['action'] ?? '');
        $type = isset($body['type']) ? trim((string)$body['type']) : '';
        if (!in_array($type, array_column(nfrtTypes(), 'code'), true)) {
            $type = trim((string)($_GET['type'] ?? ($_SESSION['nfrt_type'] ?? '')));
            if (!in_array($type, array_column(nfrtTypes(), 'code'), true)) {
                http_response_code(400);
                echo json_encode(array('status' => 'error', 'message' => 'Unknown NFR type'));
                exit;
            }
        }
        nfrtEnsureSchema($db);

        if ($action === 'create') {
            $tpid = (int)($body['tproject_id'] ?? $projectId);
            if ($tpid <= 0) {
                $tpid = intval($_SESSION['testprojectID'] ?? 0);
            }
            if ($tpid <= 0) {
                http_response_code(400);
                echo json_encode(array('status' => 'error', 'message' => 'No test project selected'));
                exit;
            }
            $project = $tprojectMgr->get_by_id($tpid);
            if (is_null($project)) {
                http_response_code(404);
                echo json_encode(array('status' => 'error', 'message' => 'Test project not found'));
                exit;
            }
            if ($user->hasRight($db, 'mgt_modify_req', $tpid) !== 'yes') {
                http_response_code(403);
                echo json_encode(array('status' => 'error', 'message' => 'No permission'));
                exit;
            }

            $title = nfrtField($body, 'title', 255);
            if ($title === '') {
                http_response_code(400);
                echo json_encode(array('status' => 'error', 'message' => 'Title is required'));
                exit;
            }
            $reqStatus = nfrtField($body, 'req_status', 24, 'proposed');
            if (!in_array($reqStatus, nfrtStatuses(), true)) {
                $reqStatus = 'proposed';
            }
            $description = nfrtField($body, 'description', 5000);
            $targetValue = nfrtField($body, 'target_value', 255);
            $thresholdValue = nfrtField($body, 'threshold_value', 255);
            $sourceRef = nfrtField($body, 'source_ref', 255);

            $sql = " INSERT INTO " . nfrtTable() .
                   " (testproject_id, nfr_type, title, description, target_value," .
                   "  threshold_value, source_ref, req_status, author_id) VALUES (" .
                   intval($tpid) . ", '" . $db->prepare_string($type) . "', '" .
                   $db->prepare_string($title) . "', '" .
                   $db->prepare_string($description) . "', '" .
                   $db->prepare_string($targetValue) . "', '" .
                   $db->prepare_string($thresholdValue) . "', '" .
                   $db->prepare_string($sourceRef) . "', '" .
                   $db->prepare_string($reqStatus) . "', " .
                   (int)$userId . ")";
            $db->exec_query($sql);
            $newId = (int)$db->insert_id('nfr_requirements');

            $event = new stdClass();
            $event->message = 'NFR requirement created: ' . $title . ' (' . $type . ')';
            $event->logLevel = 'AUDIT';
            $event->source = 'GUI';
            $event->objectID = $tpid;
            $event->objectType = 'testprojects';
            $event->code = 'NFR_CREATE';
            logEvent($event);

            echo json_encode(array('status' => 'ok', 'id' => $newId,
                'message' => 'Requirement created'));
            exit;
        }

        if ($action === 'update') {
            $itemId = (int)($body['id'] ?? 0);
            if ($itemId <= 0) {
                http_response_code(400);
                echo json_encode(array('status' => 'error', 'message' => 'Requirement ID required'));
                exit;
            }
            $existing = $db->fetchFirstRow(
                " SELECT * FROM " . nfrtTable() . " WHERE id = " . $itemId);
            if (!$existing || !is_array($existing)) {
                http_response_code(404);
                echo json_encode(array('status' => 'error', 'message' => 'Requirement not found'));
                exit;
            }
            $tpid = (int)$existing['testproject_id'];
            if ($user->hasRight($db, 'mgt_modify_req', $tpid) !== 'yes') {
                http_response_code(403);
                echo json_encode(array('status' => 'error', 'message' => 'No permission'));
                exit;
            }

            $title = nfrtField($body, 'title', 255, $existing['title']);
            if ($title === '') {
                http_response_code(400);
                echo json_encode(array('status' => 'error', 'message' => 'Title is required'));
                exit;
            }
            $reqStatus = nfrtField($body, 'req_status', 24, $existing['req_status']);
            if (!in_array($reqStatus, nfrtStatuses(), true)) {
                $reqStatus = $existing['req_status'];
            }
            $description = nfrtField($body, 'description', 5000, $existing['description'] ?? '');
            $targetValue = nfrtField($body, 'target_value', 255, $existing['target_value'] ?? '');
            $thresholdValue = nfrtField($body, 'threshold_value', 255, $existing['threshold_value'] ?? '');
            $sourceRef = nfrtField($body, 'source_ref', 255, $existing['source_ref'] ?? '');

            $db->exec_query(
                " UPDATE " . nfrtTable() . " SET " .
                " title = '" . $db->prepare_string($title) . "'," .
                " description = '" . $db->prepare_string($description) . "'," .
                " target_value = '" . $db->prepare_string($targetValue) . "'," .
                " threshold_value = '" . $db->prepare_string($thresholdValue) . "'," .
                " source_ref = '" . $db->prepare_string($sourceRef) . "'," .
                " req_status = '" . $db->prepare_string($reqStatus) . "'" .
                " WHERE id = " . $itemId);

            $event = new stdClass();
            $event->message = 'NFR requirement updated: ' . $title;
            $event->logLevel = 'AUDIT';
            $event->source = 'GUI';
            $event->objectID = $tpid;
            $event->objectType = 'testprojects';
            $event->code = 'NFR_UPDATE';
            logEvent($event);

            echo json_encode(array('status' => 'ok', 'id' => $itemId,
                'message' => 'Requirement updated'));
            exit;
        }

        if ($action === 'delete') {
            $itemId = (int)($body['id'] ?? 0);
            if ($itemId <= 0) {
                http_response_code(400);
                echo json_encode(array('status' => 'error', 'message' => 'Requirement ID required'));
                exit;
            }
            $existing = $db->fetchFirstRow(
                " SELECT * FROM " . nfrtTable() . " WHERE id = " . $itemId);
            if (!$existing || !is_array($existing)) {
                http_response_code(404);
                echo json_encode(array('status' => 'error', 'message' => 'Requirement not found'));
                exit;
            }
            $tpid = (int)$existing['testproject_id'];
            if ($user->hasRight($db, 'mgt_modify_req', $tpid) !== 'yes') {
                http_response_code(403);
                echo json_encode(array('status' => 'error', 'message' => 'No permission'));
                exit;
            }

            $db->exec_query(
                " DELETE FROM " . nfrtTable() . " WHERE id = " . $itemId);

            $event = new stdClass();
            $event->message = 'NFR requirement deleted: ' . $existing['title'];
            $event->logLevel = 'AUDIT';
            $event->source = 'GUI';
            $event->objectID = $tpid;
            $event->objectType = 'testprojects';
            $event->code = 'NFR_DELETE';
            logEvent($event);

            echo json_encode(array('status' => 'ok', 'id' => $itemId,
                'message' => 'Requirement deleted'));
            exit;
        }

        http_response_code(400);
        echo json_encode(array('status' => 'error', 'message' => 'Unknown action'));
        break;

    default:
        http_response_code(405);
        echo json_encode(array('status' => 'error', 'message' => 'Method not allowed'));
}