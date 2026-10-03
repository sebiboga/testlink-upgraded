<?php
/**
 * Test Review / Static-testing Workflow BFF API
 * URL: /api/reviews/
 * Plain PHP, no framework, no compilation.
 *
 * Backs the modernized screen gui/templates/reviews/reviews.html (Refs #1279).
 * ISTQB static-testing: gives Test Cases and Requirements a peer-review
 * lifecycle on top of their existing content status, with an assigned
 * reviewer, review comments and a pending-review dashboard. No legacy
 * controller exists for this (the 1.9.20 code base has no reviewer/review
 * entity at all - see the issue investigation), so this is a net-new module.
 *
 * Routes (session based, JSON I/O):
 *   GET  ?action=projects                    -> projects the user may review in
 *   GET  ?action=meta&tproject_id=N          -> status domain, entity types,
 *                                               rights, reviewer candidates
 *   GET  ?action=entities&tproject_id=N&entity_type=tcase|requirement
 *                                            -> selectable entities for a new
 *                                               review request (latest version)
 *   GET  ?action=list&tproject_id=N[&entity_type=..][&status=..][&reviewer_id=..]
 *                                            -> review requests of the project
 *   POST ?action=create {tproject_id,entity_type,entity_id,version_id,
 *                        reviewer_id,comments}
 *   POST ?action=decide {id,decision:approved|rejected,comments}
 *
 * Rights model:
 *   - reads require view_tc OR mgt_view_tc OR mgt_view_req on the project
 *   - creating a request requires mgt_modify_tc (tcase) or mgt_modify_req
 *     (requirement) on the owning project
 *   - deciding requires the assigned reviewer OR the matching modify right
 *   - unauthenticated -> 401; unknown action -> 400; foreign id -> 404
 *
 * Writes emit AUDIT events (REVIEW_REQUEST / REVIEW_APPROVE / REVIEW_REJECT)
 * so the Event Viewer tracks the module activity.
 *
 * The tc_reviews table is created lazily (CREATE TABLE IF NOT EXISTS) so a
 * freshly imported 1.9.20 database works unchanged; the matching DDL is also
 * part of the installer schema.
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
$action = isset($_REQUEST['action']) ? trim((string)$_REQUEST['action']) : '';

/** Review lifecycle states of a review request. */
function reviewStatuses() {
    return array('in_review', 'approved', 'rejected', 'cancelled');
}

/** Reviewable entity types. */
function reviewEntityTypes() {
    return array('tcase', 'requirement');
}

/** Table name handling (respects the optional DB_TABLE_PREFIX). */
function reviewTable() {
    $t = tlObjectWithDB::getDBTables(array('tc_reviews'));
    return $t['tc_reviews'];
}

/** Idempotent lazy schema migration so a freshly imported DB works unchanged. */
function reviewEnsureSchema($db) {
    $t = reviewTable();
    $db->exec_query(
        "CREATE TABLE IF NOT EXISTS {$t} (" .
        " id INT UNSIGNED NOT NULL AUTO_INCREMENT," .
        " testproject_id INT UNSIGNED NOT NULL DEFAULT 0," .
        " entity_type VARCHAR(16) NOT NULL DEFAULT 'tcase'," .
        " entity_id INT UNSIGNED NOT NULL DEFAULT 0," .
        " version_id INT UNSIGNED NOT NULL DEFAULT 0," .
        " entity_title VARCHAR(255) NOT NULL DEFAULT ''," .
        " entity_doc_id VARCHAR(64) NOT NULL DEFAULT ''," .
        " review_status VARCHAR(16) NOT NULL DEFAULT 'in_review'," .
        " requested_by INT UNSIGNED NULL," .
        " reviewer_id INT UNSIGNED NULL," .
        " comments TEXT NULL," .
        " decision_comment TEXT NULL," .
        " decided_by INT UNSIGNED NULL," .
        " creation_ts TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP," .
        " decision_ts DATETIME NULL," .
        " PRIMARY KEY (id)," .
        " KEY idx_review_tproject (testproject_id, review_status)," .
        " KEY idx_review_entity (entity_type, entity_id)," .
        " KEY idx_review_reviewer (reviewer_id)" .
        " ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
}

/** Resolve the owning test project: param wins, session is the fallback. */
function reviewResolveTproject() {
    $tpid = intval($_GET['tproject_id'] ?? 0);
    if ($tpid <= 0) {
        $body = reviewBody();
        $tpid = intval($body['tproject_id'] ?? 0);
    }
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
function reviewBody() {
    static $cached = null;
    if ($cached === null) {
        $cached = json_decode(file_get_contents('php://input'), true);
        if (!is_array($cached)) { $cached = array(); }
    }
    return $cached;
}

/** Trim + length-cap a scalar payload field. */
function reviewField($input, $key, $maxLen, $default = '') {
    $v = $input[$key] ?? $default;
    $v = is_scalar($v) ? trim((string)$v) : '';
    return mb_substr($v, 0, $maxLen);
}

/** Whether the user may see reviews of a project. */
function reviewCanView($user, $db, $tpid) {
    foreach (array('mgt_view_tc', 'mgt_view_req', 'mgt_modify_tc', 'mgt_modify_req') as $r) {
        if ($user->hasRight($db, $r, $tpid) === 'yes') { return true; }
    }
    return false;
}

/** Whether the user may open a review request of a given entity type. */
function reviewCanRequest($user, $db, $tpid, $entityType) {
    $right = ($entityType === 'requirement') ? 'mgt_modify_req' : 'mgt_modify_tc';
    return ($user->hasRight($db, $right, $tpid) === 'yes');
}

/** Whether the user may decide a review request. */
function reviewCanDecide($user, $db, $row) {
    if (intval($row['reviewer_id']) === intval($_SESSION['userID'])) { return true; }
    $right = ($row['entity_type'] === 'requirement') ? 'mgt_modify_req' : 'mgt_modify_tc';
    return ($user->hasRight($db, $right, intval($row['testproject_id'])) === 'yes');
}

/**
 * Resolve an entity of the given type inside the test project and return its
 * authoritative (title, doc_id, version_id, version) tuple, or null when the
 * entity does not belong to that project. Critically, the client-supplied
 * title/doc-id/version are NEVER trusted: without this check a user with
 * manage rights on project A could open (and later decide) a review against an
 * entity of project B, and the decide path would then write project B's entity
 * status (object-level authorization defect).
 */
function reviewFindEntity($db, $tprojectMgr, $tpid, $entityType, $entityId) {
    $tpid = intval($tpid);
    $entityId = intval($entityId);
    if ($tpid <= 0 || $entityId <= 0) { return null; }
    if ($entityType === 'tcase') {
        $tcIds = array();
        $tprojectMgr->get_all_testcases_id($tpid, $tcIds);
        $tcIds = array_values(array_unique(array_map('intval', (array)$tcIds)));
        if (!in_array($entityId, $tcIds, true)) { return null; }
        $t = tlObjectWithDB::getDBTables(array('nodes_hierarchy', 'tcversions'));
        $sql = " SELECT NHT.id AS entity_id, NHT.name AS title, " .
               " TCV.id AS version_id, TCV.version AS version, " .
               " TCV.tc_external_id AS external_id " .
               " FROM {$t['nodes_hierarchy']} NHT " .
               " JOIN {$t['nodes_hierarchy']} NHV ON NHV.parent_id = NHT.id " .
               " JOIN {$t['tcversions']} TCV ON TCV.id = NHV.id " .
               " JOIN (SELECT NHV2.parent_id AS pid, MAX(TCV2.version) AS mv " .
               "       FROM {$t['nodes_hierarchy']} NHV2 " .
               "       JOIN {$t['tcversions']} TCV2 ON TCV2.id = NHV2.id " .
               "       GROUP BY NHV2.parent_id) LV " .
               "   ON LV.pid = NHT.id AND LV.mv = TCV.version " .
               " WHERE NHT.id = {$entityId}";
        $r = $db->fetchFirstRow($sql);
        if (!$r || !is_array($r)) { return null; }
        return array(
            'entity_id' => (int)$r['entity_id'],
            'version_id' => (int)$r['version_id'],
            'title' => (string)$r['title'],
            'doc_id' => (string)($r['external_id'] ?? ''),
            'version' => (int)$r['version'],
        );
    }
    $t = tlObjectWithDB::getDBTables(
        array('requirements', 'req_specs', 'nodes_hierarchy', 'req_versions'));
    $sql = " SELECT R.id AS entity_id, NH.name AS title, R.req_doc_id AS doc_id, " .
           " RV.id AS version_id, RV.version AS version " .
           " FROM {$t['requirements']} R " .
           " JOIN {$t['req_specs']} RS ON RS.id = R.srs_id " .
           " JOIN {$t['nodes_hierarchy']} NH ON NH.id = R.id " .
           " JOIN {$t['nodes_hierarchy']} NHV ON NHV.parent_id = R.id " .
           " JOIN {$t['req_versions']} RV ON RV.id = NHV.id " .
           " JOIN (SELECT NHV2.parent_id AS pid, MAX(RV2.version) AS mv " .
           "       FROM {$t['nodes_hierarchy']} NHV2 " .
           "       JOIN {$t['req_versions']} RV2 ON RV2.id = NHV2.id " .
           "       GROUP BY NHV2.parent_id) LV " .
           "   ON LV.pid = R.id AND LV.mv = RV.version " .
           " WHERE R.id = {$entityId} AND RS.testproject_id = {$tpid}";
    $r = $db->fetchFirstRow($sql);
    if (!$r || !is_array($r)) { return null; }
    return array(
        'entity_id' => (int)$r['entity_id'],
        'version_id' => (int)$r['version_id'],
        'title' => (string)$r['title'],
        'doc_id' => (string)$r['doc_id'],
        'version' => (int)$r['version'],
    );
}

/** Map a raw review row to the JSON payload. */
function reviewRow($row) {
    return array(
        'id'               => (int)$row['id'],
        'testproject_id'   => (int)$row['testproject_id'],
        'entity_type'      => $row['entity_type'],
        'entity_id'        => (int)$row['entity_id'],
        'version_id'       => (int)$row['version_id'],
        'entity_title'     => $row['entity_title'],
        'entity_doc_id'    => $row['entity_doc_id'],
        'review_status'    => $row['review_status'],
        'requested_by'     => (int)$row['requested_by'],
        'requester_login'  => isset($row['requester_login']) ? $row['requester_login'] : '',
        'reviewer_id'      => (int)$row['reviewer_id'],
        'reviewer_login'   => isset($row['reviewer_login']) ? $row['reviewer_login'] : '',
        'comments'         => isset($row['comments']) ? $row['comments'] : '',
        'decision_comment' => isset($row['decision_comment']) ? $row['decision_comment'] : '',
        'decided_by'       => isset($row['decided_by']) ? (int)$row['decided_by'] : 0,
        'decider_login'    => isset($row['decider_login']) ? $row['decider_login'] : '',
        'creation_ts'      => $row['creation_ts'],
        'decision_ts'      => isset($row['decision_ts']) ? $row['decision_ts'] : null,
    );
}

/** Reviewer / user candidate list for a project (unique logins). */
function reviewCandidates($db, $tpid) {
    $t = tlObjectWithDB::getDBTables(array('users', 'user_testproject_roles'));
    $sql = " SELECT DISTINCT U.id, U.login, U.first, U.last " .
           " FROM {$t['users']} U " .
           " LEFT JOIN {$t['user_testproject_roles']} R ON R.user_id = U.id " .
           " WHERE U.active = 1 AND (R.testproject_id = " . intval($tpid) .
           " OR U.id = 1) ORDER BY U.login";
    $rows = (array)$db->get_recordset($sql);
    $items = array();
    foreach ($rows as $r) {
        $name = trim((string)($r['first'] ?? '') . ' ' . (string)($r['last'] ?? ''));
        $items[] = array(
            'id'    => (int)$r['id'],
            'login' => $r['login'],
            'name'  => $name,
        );
    }
    return $items;
}

// ---------------------------------------------------------------------------
switch ($method) {
    case 'GET':
        if ($action === 'projects') {
            $sql = "SELECT tp.id, nh.name, tp.prefix FROM testprojects tp " .
                   "JOIN nodes_hierarchy nh ON nh.id = tp.id ORDER BY nh.name";
            $rows = (array)$db->get_recordset($sql);
            $items = array();
            foreach ($rows as $row) {
                $tpid = (int)$row['id'];
                if (!reviewCanView($user, $db, $tpid)) { continue; }
                $items[] = array('id' => $tpid, 'name' => $row['name'], 'prefix' => $row['prefix']);
            }
            echo json_encode(array('status' => 'ok', 'projects' => $items));
            exit;
        }

        if ($action === 'meta') {
            $tpid = reviewResolveTproject();
            if (!reviewCanView($user, $db, $tpid)) {
                http_response_code(403);
                echo json_encode(array('status' => 'error', 'message' => 'No permission'));
                exit;
            }
            $statuses = array();
            foreach (reviewStatuses() as $s) { $statuses[] = array('code' => $s); }
            $types = array();
            foreach (reviewEntityTypes() as $t) { $types[] = array('code' => $t); }
            echo json_encode(array(
                'status' => 'ok',
                'statuses' => $statuses,
                'entity_types' => $types,
                'candidates' => reviewCandidates($db, $tpid),
                'canRequestTc' => reviewCanRequest($user, $db, $tpid, 'tcase'),
                'canRequestReq' => reviewCanRequest($user, $db, $tpid, 'requirement'),
            ));
            exit;
        }

        if ($action === 'entities') {
            $tpid = reviewResolveTproject();
            $entityType = trim((string)($_GET['entity_type'] ?? 'tcase'));
            if (!in_array($entityType, reviewEntityTypes(), true)) {
                http_response_code(400);
                echo json_encode(array('status' => 'error', 'message' => 'Unknown entity type'));
                exit;
            }
            if (!reviewCanRequest($user, $db, $tpid, $entityType)) {
                http_response_code(403);
                echo json_encode(array('status' => 'error', 'message' => 'No permission'));
                exit;
            }
            $items = array();
            if ($entityType === 'tcase') {
                $tcIds = array();
                $tprojectMgr->get_all_testcases_id($tpid, $tcIds);
                $tcIds = array_values(array_unique(array_map('intval', (array)$tcIds)));
                if (count($tcIds) > 0) {
                    $t = tlObjectWithDB::getDBTables(array('nodes_hierarchy', 'tcversions'));
                    $inList = implode(',', $tcIds);
                    $sql = " SELECT NHT.id AS entity_id, NHT.name AS title, " .
                           " TCV.id AS version_id, TCV.version AS version, " .
                           " TCV.tc_external_id AS external_id " .
                           " FROM {$t['nodes_hierarchy']} NHT " .
                           " JOIN {$t['nodes_hierarchy']} NHV ON NHV.parent_id = NHT.id " .
                           " JOIN {$t['tcversions']} TCV ON TCV.id = NHV.id " .
                           " JOIN (SELECT NHV2.parent_id AS pid, MAX(TCV2.version) AS mv " .
                           "       FROM {$t['nodes_hierarchy']} NHV2 " .
                           "       JOIN {$t['tcversions']} TCV2 ON TCV2.id = NHV2.id " .
                           "       GROUP BY NHV2.parent_id) LV " .
                           "   ON LV.pid = NHT.id AND LV.mv = TCV.version " .
                           " WHERE NHT.id IN ({$inList}) " .
                           " ORDER BY NHT.name";
                    $rows = (array)$db->get_recordset($sql);
                    foreach ($rows as $r) {
                        $items[] = array(
                            'entity_id' => (int)$r['entity_id'],
                            'version_id' => (int)$r['version_id'],

                            'title' => $r['title'],
                            'doc_id' => (string)($r['external_id'] ?? ''),
                            'version' => (int)$r['version'],
                        );
                    }
                }
            } else {
                $t = tlObjectWithDB::getDBTables(
                    array('requirements', 'req_specs', 'nodes_hierarchy', 'req_versions'));
                $sql = " SELECT R.id AS entity_id, NH.name AS title, R.req_doc_id AS doc_id, " .
                       " RV.id AS version_id, RV.version AS version " .
                       " FROM {$t['requirements']} R " .
                       " JOIN {$t['req_specs']} RS ON RS.id = R.srs_id " .
                       " JOIN {$t['nodes_hierarchy']} NH ON NH.id = R.id " .
                       " JOIN {$t['nodes_hierarchy']} NHV ON NHV.parent_id = R.id " .
                       " JOIN {$t['req_versions']} RV ON RV.id = NHV.id " .
                       " JOIN (SELECT NHV2.parent_id AS pid, MAX(RV2.version) AS mv " .
                       "       FROM {$t['nodes_hierarchy']} NHV2 " .
                       "       JOIN {$t['req_versions']} RV2 ON RV2.id = NHV2.id " .
                       "       GROUP BY NHV2.parent_id) LV " .
                       "   ON LV.pid = R.id AND LV.mv = RV.version " .
                       " WHERE RS.testproject_id = " . intval($tpid) .
                       " ORDER BY NH.name";
                $rows = (array)$db->get_recordset($sql);
                foreach ($rows as $r) {
                    $items[] = array(
                        'entity_id' => (int)$r['entity_id'],
                        'version_id' => (int)$r['version_id'],
                        'title' => $r['title'],
                        'doc_id' => (string)($r['doc_id'] ?? ''),
                        'version' => (int)$r['version'],
                    );
                }
            }
            echo json_encode(array('status' => 'ok', 'entity_type' => $entityType,
                'entities' => $items));
            exit;
        }

        if ($action === 'list') {
            $tpid = reviewResolveTproject();
            if (!reviewCanView($user, $db, $tpid)) {
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
            $nhTables = tlObjectWithDB::getDBTables(array('nodes_hierarchy'));
            $projRow = $db->fetchFirstRow(
                "SELECT name FROM {$nhTables['nodes_hierarchy']} WHERE id = " . intval($tpid));
            $projectName = (is_array($projRow) && isset($projRow['name'])) ? $projRow['name'] : '';
            reviewEnsureSchema($db);
            $t = reviewTable();
            $where = " WHERE r.testproject_id = " . intval($tpid);
            $entityFilter = trim((string)($_GET['entity_type'] ?? ''));
            if ($entityFilter !== '' && in_array($entityFilter, reviewEntityTypes(), true)) {
                $where .= " AND r.entity_type = '" . $db->prepare_string($entityFilter) . "'";
            }
            $statusFilter = trim((string)($_GET['status'] ?? ''));
            if ($statusFilter !== '' && in_array($statusFilter, reviewStatuses(), true)) {
                $where .= " AND r.review_status = '" . $db->prepare_string($statusFilter) . "'";
            }
            $reviewerFilter = intval($_GET['reviewer_id'] ?? 0);
            if ($reviewerFilter > 0) {
                $where .= " AND r.reviewer_id = " . $reviewerFilter;
            }
            $sql = " SELECT r.*, RU.login AS reviewer_login, QU.login AS requester_login, " .
                   " DU.login AS decider_login " .
                   " FROM {$t} r " .
                   " LEFT JOIN users RU ON RU.id = r.reviewer_id " .
                   " LEFT JOIN users QU ON QU.id = r.requested_by " .
                   " LEFT JOIN users DU ON DU.id = r.decided_by " .
                   $where . " ORDER BY r.creation_ts DESC, r.id DESC";
            $rows = (array)$db->get_recordset($sql);
            $items = array();
            foreach ($rows as $r) { $items[] = reviewRow($r); }

            // per-status counters for the dashboard tiles
            $counts = array('in_review' => 0, 'approved' => 0, 'rejected' => 0, 'cancelled' => 0);
            foreach ($items as $it) {
                if (isset($counts[$it['review_status']])) { $counts[$it['review_status']]++; }
            }
            echo json_encode(array(
                'status' => 'ok',
                'tproject_id' => $tpid,
                'tproject_name' => $projectName,
                'items' => $items,
                'counts' => $counts,
                'canModerate' => (reviewCanRequest($user, $db, $tpid, 'tcase') ||
                                  reviewCanRequest($user, $db, $tpid, 'requirement')),
            ));
            exit;
        }

        http_response_code(400);
        echo json_encode(array('status' => 'error', 'message' => 'Unknown or missing action'));
        exit;

    case 'POST':
        reviewEnsureSchema($db);
        $body = reviewBody();
        $t = reviewTable();

        if ($action === 'create') {
            $tpid = intval($body['tproject_id'] ?? 0);
            if ($tpid <= 0) { $tpid = intval($_SESSION['testprojectID'] ?? 0); }
            if ($tpid <= 0) {
                http_response_code(400);
                echo json_encode(array('status' => 'error', 'message' => 'Test project is mandatory'));
                exit;
            }
            $entityType = trim((string)($body['entity_type'] ?? ''));
            if (!in_array($entityType, reviewEntityTypes(), true)) {
                http_response_code(400);
                echo json_encode(array('status' => 'error', 'message' => 'Unknown entity type'));
                exit;
            }
            if (!reviewCanRequest($user, $db, $tpid, $entityType)) {
                http_response_code(403);
                echo json_encode(array('status' => 'error', 'message' => 'No permission'));
                exit;
            }
            $entityId = intval($body['entity_id'] ?? 0);
            $versionId = intval($body['version_id'] ?? 0);
            if ($entityId <= 0) {
                http_response_code(400);
                echo json_encode(array('status' => 'error', 'message' => 'Entity is mandatory'));
                exit;
            }
            $reviewerId = intval($body['reviewer_id'] ?? 0);
            if ($reviewerId <= 0) {
                http_response_code(400);
                echo json_encode(array('status' => 'error', 'message' => 'Reviewer is mandatory'));
                exit;
            }
            $comments = reviewField($body, 'comments', 2000);

            $projectMgr = $tprojectMgr->get_by_id($tpid);
            if (is_null($projectMgr)) {
                http_response_code(404);
                echo json_encode(array('status' => 'error', 'message' => 'Test project not found'));
                exit;
            }

            // Authoritative entity lookup (object-level authorization): the
            // client-supplied title/doc-id/version are IGNORED and the entity
            // must resolve inside $tpid.
            $entity = reviewFindEntity($db, $tprojectMgr, $tpid, $entityType, $entityId);
            if ($entity === null) {
                http_response_code(404);
                echo json_encode(array('status' => 'error',
                    'message' => 'Entity not found in this test project'));
                exit;
            }
            $versionId = (int)$entity['version_id'];
            $title = (string)$entity['title'];
            $docId = (string)$entity['doc_id'];

            // Reviewer must be an assignable member of the test project.
            $allowedReviewer = false;
            foreach ((array)reviewCandidates($db, $tpid) as $cand) {
                if (intval($cand['id']) === $reviewerId) { $allowedReviewer = true; break; }
            }
            if (!$allowedReviewer) {
                http_response_code(400);
                echo json_encode(array('status' => 'error',
                    'message' => 'Reviewer is not a member of this test project'));
                exit;
            }

            $sql = "INSERT INTO {$t} " .
                   " (testproject_id, entity_type, entity_id, version_id, entity_title, " .
                   "  entity_doc_id, review_status, requested_by, reviewer_id, comments) " .
                   " VALUES (" . intval($tpid) . ", '" .
                   $db->prepare_string($entityType) . "', " . intval($entityId) . ", " .
                   intval($versionId) . ", '" . $db->prepare_string($title) . "', '" .
                   $db->prepare_string($docId) . "', 'in_review', " . intval($userId) . ", " .
                   intval($reviewerId) . ", '" . $db->prepare_string($comments) . "')";
            $db->exec_query($sql);
            $newId = (int)$db->insert_id('tc_reviews');

            $event = new stdClass();
            $event->message = 'Review requested: ' . $entityType . ' #' . $entityId .
                              ' (reviewer id ' . $reviewerId . ')';
            $event->logLevel = 'AUDIT';
            $event->source = 'GUI';
            $event->objectID = $tpid;
            $event->objectType = 'testprojects';
            $event->code = 'REVIEW_REQUEST';
            logEvent($event);

            echo json_encode(array('status' => 'ok', 'id' => $newId,
                'message' => 'Review requested'));
            exit;
        }

        if ($action === 'decide') {
            $id = intval($body['id'] ?? 0);
            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(array('status' => 'error', 'message' => 'Review id is mandatory'));
                exit;
            }
            $row = $db->fetchFirstRow("SELECT * FROM {$t} WHERE id = " . $id);
            if (!$row || !is_array($row)) {
                http_response_code(404);
                echo json_encode(array('status' => 'error', 'message' => 'Review request not found'));
                exit;
            }
            if (!reviewCanDecide($user, $db, $row)) {
                http_response_code(403);
                echo json_encode(array('status' => 'error', 'message' => 'No permission'));
                exit;
            }
            if ($row['review_status'] !== 'in_review') {
                http_response_code(409);
                echo json_encode(array('status' => 'error', 'message' => 'Review already decided'));
                exit;
            }
            $decision = trim((string)($body['decision'] ?? ''));
            if (!in_array($decision, array('approved', 'rejected'), true)) {
                http_response_code(400);
                echo json_encode(array('status' => 'error', 'message' => 'Invalid decision'));
                exit;
            }
            $comments = reviewField($body, 'comments', 2000);
            $sql = "UPDATE {$t} SET review_status = '" . $db->prepare_string($decision) . "', " .
                   " decision_comment = '" . $db->prepare_string($comments) . "', " .
                   " decided_by = " . intval($userId) . ", " .
                   " decision_ts = NOW() WHERE id = " . $id;
            $db->exec_query($sql);

            // keep the reviewed entity's content status in sync with the review
            // outcome: approved -> Final (7), rejected -> Rework (4) for test
            // cases; requirement status F (finish) / W (rework).
            if ($row['entity_type'] === 'tcase') {
                $newStatus = ($decision === 'approved') ? 7 : 4;
                $tcTables = tlObjectWithDB::getDBTables(array('tcversions'));
                $vid = intval($row['version_id']);
                if ($vid > 0) {
                    $db->exec_query("UPDATE {$tcTables['tcversions']} SET status = {$newStatus} " .
                                    "WHERE id = {$vid}");
                }
            } else {
                $newStatus = ($decision === 'approved') ? 'F' : 'W';
                $reqTables = tlObjectWithDB::getDBTables(array('req_versions'));
                $vid = intval($row['version_id']);
                if ($vid > 0) {
                    $db->exec_query("UPDATE {$reqTables['req_versions']} SET status = '" .
                                    $db->prepare_string($newStatus) . "' WHERE id = {$vid}");
                }
            }

            $event = new stdClass();
            $event->message = 'Review ' . $decision . ': ' . $row['entity_type'] .
                              ' #' . intval($row['entity_id']) . ' (review id ' . $id . ')';
            $event->logLevel = 'AUDIT';
            $event->source = 'GUI';
            $event->objectID = intval($row['testproject_id']);
            $event->objectType = 'testprojects';
            $event->code = ($decision === 'approved') ? 'REVIEW_APPROVE' : 'REVIEW_REJECT';
            logEvent($event);

            echo json_encode(array('status' => 'ok', 'id' => $id,
                'review_status' => $decision, 'message' => 'Review updated'));
            exit;
        }

        http_response_code(400);
        echo json_encode(array('status' => 'error', 'message' => 'Unknown or missing action'));
        exit;

    default:
        http_response_code(405);
        echo json_encode(array('status' => 'error', 'message' => 'Method not allowed'));
        exit;
}
