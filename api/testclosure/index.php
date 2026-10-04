<?php
/**
 * Test Closure BFF API  (ISTQB test-closure phase, Refs #1278 / #1054)
 * URL: /api/testclosure/index.php
 * Plain PHP, no framework, no compilation
 *
 * TestLink 1.9.20 has NO test-closure twin (no lib/plan/planClosure*.php, no
 * Smarty closure template, no $actions->*closure* in common.php), so this is a
 * net-new module built with the same shape as the already-merged ISTQB
 * additions api/reviews (Refs #1279) and api/requirements quality-objectives
 * (Refs #1280): lazy CREATE TABLE IF NOT EXISTS so a freshly imported DB works
 * unchanged, session auth + same-origin CSRF guard, JSON in/out.
 *
 * It ports the three ISTQB closure activities onto the test plan:
 *   1. lessons learned   -> lessons_learned rows (CRUD)
 *   2. finalize archive  -> archive_ref + closed_ts on test_closure
 *   3. closure report    -> summary action: outcome metrics + checklist
 *   plus the freeze: closing a plan snapshots its outcome metrics into
 *   test_closure.metrics_snapshot, so the archived closure report keeps the
 *   numbers as of closure, and lesson writes are refused while frozen.
 *
 * Rights (milestones parity for the plan scope, api/milestones/index.php:83):
 *   read  -> testplan_planning OR exec_testcases OR exec_ro_access
 *   write -> testplan_planning OR exec_testcases
 * The plan's project is always resolved THROUGH the plan, so a forged
 * tplan_id can never reach another project's closure data.
 *
 * Actions:
 *   GET  ?action=summary         plan info, outcome metrics, closure state, rights
 *   GET  ?action=lessons         lessons-learned rows of the plan
 *   POST ?action=lesson_save     create (id absent) / update (id present)
 *   POST ?action=lesson_delete   delete one row
 *   POST ?action=closure_save    closure summary + archive ref + checklist
 *   POST ?action=closure_close   freeze: snapshot metrics, stamp closed_by/ts
 *   POST ?action=closure_reopen  unfreeze: clear the snapshot
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || $userId <= 0) {
    http_response_code(401);
    echo json_encode(array('status' => 'error', 'message' => 'Not authenticated'));
    exit;
}

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    http_response_code(401);
    echo json_encode(array('status' => 'error', 'message' => 'User not found'));
    exit;
}

function tcOut($data) { echo json_encode($data); exit; }
function tcBody() {
    $b = json_decode(file_get_contents('php://input'), true);
    return is_array($b) ? $b : array();
}
function tcFail($code, $msg) {
    http_response_code($code);
    tcOut(array('status' => 'error', 'message' => $msg));
}

/** Table names for the test-closure feature (respects DB_TABLE_PREFIX). */
function tcTables() {
    return tlObjectWithDB::getDBTables(array('test_closure', 'lessons_learned'));
}

/**
 * Idempotent lazy schema migration so a freshly imported DB (created from
 * install/sql/mysql/testlink_create_tables.sql) works unchanged - same
 * pattern as api/reviews/index.php:93 and api/requirements/index.php:3069.
 */
function tcEnsureSchema($db) {
    $t = tcTables();
    $db->exec_query(
        "CREATE TABLE IF NOT EXISTS {$t['test_closure']} (" .
        " id INT UNSIGNED NOT NULL AUTO_INCREMENT," .
        " testplan_id INT UNSIGNED NOT NULL DEFAULT 0," .
        " closure_status VARCHAR(16) NOT NULL DEFAULT 'open'," .
        " closure_summary TEXT NULL," .
        " archive_ref VARCHAR(255) NOT NULL DEFAULT ''," .
        " checklist TEXT NULL," .
        " metrics_snapshot TEXT NULL," .
        " closed_by INT UNSIGNED NULL," .
        " closed_ts DATETIME NULL," .
        " updated_by INT UNSIGNED NULL," .
        " update_ts TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP," .
        " creation_ts TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP," .
        " PRIMARY KEY (id)," .
        " UNIQUE KEY uq_tc_tplan (testplan_id)" .
        ") ENGINE=InnoDB DEFAULT CHARSET=utf8");

    $db->exec_query(
        "CREATE TABLE IF NOT EXISTS {$t['lessons_learned']} (" .
        " id INT UNSIGNED NOT NULL AUTO_INCREMENT," .
        " testplan_id INT UNSIGNED NOT NULL DEFAULT 0," .
        " lesson_category VARCHAR(16) NOT NULL DEFAULT 'improvement'," .
        " title VARCHAR(255) NOT NULL DEFAULT ''," .
        " description TEXT NULL," .
        " author_id INT UNSIGNED NULL," .
        " update_ts TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP," .
        " creation_ts TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP," .
        " PRIMARY KEY (id)," .
        " KEY idx_ll_tplan (testplan_id)," .
        " KEY idx_ll_cat (testplan_id, lesson_category)" .
        ") ENGINE=InnoDB DEFAULT CHARSET=utf8");
}

/** Allowed lesson categories (ISTQB closure: what worked / what didn't / actions). */
function tcCategories() {
    return array('worked_well', 'needs_improvement', 'to_repeat', 'to_avoid', 'other');
}

/** Closure states of a test plan. */
function tcClosureStatuses() {
    return array('open', 'closed');
}

/** Resolve the test plan + its project through the plan (milestones parity). */
function tcResolveTplan($db, $tplanId) {
    if ($tplanId <= 0) {
        tcFail(400, 'Invalid test plan id');
    }
    $tplanMgr = new testplan($db);
    $info = $tplanMgr->tree_manager->get_node_hierarchy_info(
        $tplanId, null, array('nodeType' => 'testplan'));
    if (is_null($info)) {
        tcFail(404, 'Invalid Test Plan ID');
    }
    return array(
        'tplan_id' => $tplanId,
        'tplan_name' => $info['name'],
        'tproject_id' => intval($info['parent_id']),
    );
}

function tcCanRead(&$user, &$db, $tprojectId) {
    return (bool)$user->hasRight($db, 'testplan_planning', $tprojectId)
        || (bool)$user->hasRight($db, 'exec_testcases', $tprojectId)
        || (bool)$user->hasRight($db, 'exec_ro_access', $tprojectId);
}

function tcCanWrite(&$user, &$db, $tprojectId) {
    return (bool)$user->hasRight($db, 'testplan_planning', $tprojectId)
        || (bool)$user->hasRight($db, 'exec_testcases', $tprojectId);
}

/** Requested test plan id: query string first, then JSON body, then session. */
function tcTplanIdParam() {
    $id = intval($_GET['tplan_id'] ?? 0);
    if ($id <= 0) {
        $id = intval($_POST['tplan_id'] ?? 0);
    }
    if ($id <= 0) {
        $b = tcBody();
        $id = intval($b['tplan_id'] ?? 0);
    }
    if ($id <= 0) {
        $id = intval($_SESSION['testplanID'] ?? 0);
    }
    return $id;
}

/** Audit event into the Event Viewer (mgt_view_events parity with milestones). */
function tcAudit(&$db, $message, $code, $objectId, $objectType) {
    $event = new stdClass();
    $event->message = $message;
    $event->logLevel = 'AUDIT';
    $event->source = 'GUI';
    $event->objectID = intval($objectId);
    $event->objectType = $objectType;
    $event->code = $code;
    logEvent($event);
}

/**
 * Outcome metrics of a test plan: assigned test cases, executions per status,
 * open bugs. Same aggregates the legacy Execution screen shows
 * (lib/results/resultsGeneral.php), computed plan-scoped.
 */
function tcPlanMetrics(&$db, $tplanId) {
    $tt = tcTables();
    $tbl = tlObjectWithDB::getDBTables(array('testplan_tcversions', 'executions',
                                             'execution_bugs'));
    $tcvt = $tbl['testplan_tcversions'];
    $ext = $tbl['executions'];
    $ebt = $tbl['execution_bugs'];

    $metrics = array(
        'total_assigned' => 0, 'total_executed' => 0, 'passed' => 0, 'failed' => 0,
        'blocked' => 0, 'not_run' => 0, 'incomplete' => 0, 'passed_pct' => 0,
        'failed_pct' => 0, 'executed_pct' => 0, 'open_bugs' => 0, 'by_status' => array(),
    );

    $rs = $db->get_recordset("SELECT COUNT(*) AS c FROM {$tcvt} WHERE testplan_id = " . intval($tplanId));
    if (!is_null($rs) && count($rs) > 0) {
        $metrics['total_assigned'] = intval($rs[0]['c']);
    }

    // executions.status stores the legacy short codes (tlExecStatus):
    // n = not run, p = passed, f = failed, b = blocked, i = incomplete.
    $statusMap = array('p' => 'passed', 'f' => 'failed', 'b' => 'blocked', 'i' => 'incomplete');
    $statuses = array('passed', 'failed', 'blocked', 'incomplete');
    $rs = $db->get_recordset(
        "SELECT e.status AS status, COUNT(*) AS c " .
        "FROM {$ext} e WHERE e.testplan_id = " . intval($tplanId) . " GROUP BY e.status");
    if (!is_null($rs)) {
        foreach ($rs as $row) {
            $raw = strtolower(trim((string)$row['status']));
            $s = isset($statusMap[$raw]) ? $statusMap[$raw] : $raw;
            $c = intval($row['c']);
            $metrics['by_status'][$s] = $c;
            if (in_array($s, $statuses, true)) {
                $metrics['total_executed'] += $c;
            }
            if ($s === 'passed') { $metrics['passed'] = $c; }
            if ($s === 'failed') { $metrics['failed'] = $c; }
            if ($s === 'blocked') { $metrics['blocked'] = $c; }
            if ($s === 'incomplete') { $metrics['incomplete'] = $c; }
        }
    }
    if ($metrics['total_executed'] === 0 && $metrics['total_assigned'] > 0) {
        $metrics['not_run'] = $metrics['total_assigned'];
    }

    $rs = $db->get_recordset("SELECT COUNT(DISTINCT eb.bug_id) AS c FROM {$ebt} eb " .
                             "JOIN {$ext} e ON e.id = eb.execution_id " .
                             "WHERE e.testplan_id = " . intval($tplanId) . " AND eb.bug_id <> 0");
    if (!is_null($rs) && count($rs) > 0) {
        $metrics['open_bugs'] = intval($rs[0]['c']);
    }

    $total = $metrics['total_assigned'];
    if ($total > 0) {
        $metrics['executed_pct'] = round(($metrics['total_executed'] / $total) * 100, 1);
        $metrics['passed_pct'] = round(($metrics['passed'] / $total) * 100, 1);
        $metrics['failed_pct'] = round(($metrics['failed'] / $total) * 100, 1);
    }

    // lessons-learned counters for the closure report
    $byCat = array();
    foreach (tcCategories() as $c) { $byCat[$c] = 0; }
    $rs = $db->get_recordset("SELECT lesson_category, COUNT(*) AS c FROM {$tt['lessons_learned']} " .
                             "WHERE testplan_id = " . intval($tplanId) . " GROUP BY lesson_category");
    if (!is_null($rs)) {
        foreach ($rs as $row) {
            $byCat[strtolower(trim((string)$row['lesson_category']))] = intval($row['c']);
        }
    }
    $metrics['lessons_by_category'] = $byCat;
    $metrics['lessons_total'] = array_sum($byCat);

    return $metrics;
}

/** Read (or lazily create) the closure record of a plan. */
function tcClosureRow(&$db, $tplanId, $create) {
    $t = tcTables();
    $rs = $db->get_recordset("SELECT * FROM {$t['test_closure']} WHERE testplan_id = " . intval($tplanId));
    if (!is_null($rs) && count($rs) > 0) {
        $row = $rs[0];
        $row['checklist'] = tcDecodeJson($row['checklist']);
        $row['metrics_snapshot'] = tcDecodeJson($row['metrics_snapshot']);
        return $row;
    }
    if (!$create) {
        return array(
            'id' => 0, 'testplan_id' => intval($tplanId), 'closure_status' => 'open',
            'closure_summary' => '', 'archive_ref' => '', 'checklist' => tcEmptyChecklist(),
            'metrics_snapshot' => null, 'closed_by' => null, 'closed_ts' => null,
            'creation_ts' => '', 'update_ts' => '',
        );
    }
    $db->exec_query("INSERT INTO {$t['test_closure']} (testplan_id) VALUES (" . intval($tplanId) . ")");
    return tcClosureRow($db, $tplanId, false);
}

function tcDecodeJson($s) {
    if (is_null($s) || trim((string)$s) === '') { return null; }
    $v = json_decode((string)$s, true);
    return is_array($v) ? $v : null;
}

/** ISTQB closure checklist: every item must be answered before closing. */
function tcEmptyChecklist() {
    $out = array();
    foreach (tcChecklistItems() as $key) {
        $out[$key] = false;
    }
    return $out;
}

function tcChecklistItems() {
    return array('archive_finalized', 'results_frozen', 'lessons_recorded',
                 'open_issues_handed_over', 'feedback_to_stakeholders');
}

function tcLoginsFor(&$db, $ids) {
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)$ids))));
    if (count($ids) === 0) { return array(); }
    $ut = tlObjectWithDB::getDBTables(array('users'));
    $in = implode(',', $ids);
    $rs = $db->get_recordset("SELECT id, login FROM {$ut['users']} WHERE id IN ({$in})");
    $out = array();
    if (!is_null($rs)) {
        foreach ($rs as $r) { $out[intval($r['id'])] = $r['login']; }
    }
    return $out;
}

tcEnsureSchema($db);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = trim((string)($_REQUEST['action'] ?? ''));
$ctx = tcResolveTplan($db, tcTplanIdParam());
$mayRead = tcCanRead($user, $db, $ctx['tproject_id']);
$mayWrite = tcCanWrite($user, $db, $ctx['tproject_id']);

if (!$mayRead && !$mayWrite) {
    tcFail(403, 'Insufficient rights');
}
if ($method !== 'GET' && !$mayWrite) {
    tcFail(403, 'Insufficient rights');
}

switch ($action) {

/* ---------------------------------------------------------------- summary */
case 'summary': {
    if ($method !== 'GET') { tcFail(405, 'Method not allowed'); }
    $closure = tcClosureRow($db, $ctx['tplan_id'], false);
    $live = tcPlanMetrics($db, $ctx['tplan_id']);
    $isClosed = ($closure['closure_status'] === 'closed');

    // Frozen plan: the archived report keeps the snapshot taken at closure.
    $frozen = $closure['metrics_snapshot'];
    $effective = $isClosed && !is_null($frozen) ? $frozen : $live;

    $logins = tcLoginsFor($db, array($closure['closed_by'], $closure['updated_by']));

    tcOut(array(
        'status' => 'ok',
        'tplan_id' => $ctx['tplan_id'],
        'tplan_name' => $ctx['tplan_name'],
        'tproject_id' => $ctx['tproject_id'],
        'rights' => array('canRead' => $mayRead, 'canWrite' => $mayWrite),
        'closure' => array(
            'id' => intval($closure['id']),
            'status' => $closure['closure_status'],
            'summary_text' => (string)$closure['closure_summary'],
            'archive_ref' => (string)$closure['archive_ref'],
            'checklist' => is_array($closure['checklist']) ? $closure['checklist'] : tcEmptyChecklist(),
            'closed_by_login' => ($closure['closed_by'] && isset($logins[intval($closure['closed_by'])]))
                ? $logins[intval($closure['closed_by'])] : '',
            'closed_ts' => (string)$closure['closed_ts'],
            'update_ts' => (string)$closure['update_ts'],
            'frozen' => $isClosed,
            'snapshot_ts' => $isClosed ? (string)$closure['closed_ts'] : '',
        ),
        'metrics' => $live,
        'report_metrics' => $effective,
    ));
}

/* ---------------------------------------------------------------- lessons */
case 'lessons': {
    if ($method !== 'GET') { tcFail(405, 'Method not allowed'); }
    $t = tcTables();
    $rs = $db->get_recordset("SELECT * FROM {$t['lessons_learned']} WHERE testplan_id = " .
                             intval($ctx['tplan_id']) .
                             " ORDER BY lesson_category ASC, creation_ts DESC, id DESC");
    $items = is_null($rs) ? array() : $rs;
    $logins = tcLoginsFor($db, array_map(function($r) { return $r['author_id']; }, $items));
    $out = array();
    foreach ($items as $r) {
        $aid = intval($r['author_id']);
        $r['author_login'] = ($aid > 0 && isset($logins[$aid])) ? $logins[$aid] : '';
        $out[] = $r;
    }
    tcOut(array(
        'status' => 'ok',
        'tplan_id' => $ctx['tplan_id'],
        'items' => $out,
        'categories' => tcCategories(),
        'closure_status' => tcClosureRow($db, $ctx['tplan_id'], false)['closure_status'],
        'rights' => array('canRead' => $mayRead, 'canWrite' => $mayWrite),
    ));
}

/* --------------------------------------------------------- lesson_save */
case 'lesson_save': {
    $body = tcBody();
    $closure = tcClosureRow($db, $ctx['tplan_id'], false);
    if ($closure['closure_status'] === 'closed') {
        tcFail(409, 'closure_frozen');
    }
    $id = intval($body['id'] ?? 0);
    $title = trim((string)($body['title'] ?? ''));
    if ($title === '') { tcFail(400, 'closure_msg.titleRequired'); }
    if (strlen($title) > 255) { tcFail(400, 'closure_msg.titleTooLong'); }
    $category = strtolower(trim((string)($body['category'] ?? '')));
    if (!in_array($category, tcCategories(), true)) {
        $category = 'other';
    }
    $desc = trim((string)($body['description'] ?? ''));
    if (strlen($desc) > 5000) { $desc = substr($desc, 0, 5000); }

    $t = tcTables();
    $lt = $t['lessons_learned'];
    if ($id > 0) {
        $rs = $db->get_recordset("SELECT id, testplan_id FROM {$lt} WHERE id = " . intval($id));
        if (is_null($rs) || count($rs) === 0) { tcFail(404, 'Lesson not found'); }
        if (intval($rs[0]['testplan_id']) !== intval($ctx['tplan_id'])) {
            tcFail(403, 'Insufficient rights');
        }
        $db->exec_query(
            "UPDATE {$lt} SET lesson_category = '" . $db->prepare_string($category) . "', " .
            "title = '" . $db->prepare_string($title) . "', " .
            "description = '" . $db->prepare_string($desc) . "', " .
            "author_id = " . intval($userId) . " WHERE id = " . intval($id));
        tcAudit($db, 'Lesson learned updated: ' . $title, 'CLOSURE_LESSON_SAVE', $id, 'lessons_learned');
        tcOut(array('status' => 'ok', 'id' => $id, 'message' => 'closure_msg.lessonUpdated'));
    }
    $db->exec_query(
        "INSERT INTO {$lt} (testplan_id, lesson_category, title, description, author_id) VALUES (" .
        intval($ctx['tplan_id']) . ", '" . $db->prepare_string($category) . "', '" .
        $db->prepare_string($title) . "', '" . $db->prepare_string($desc) . "', " . intval($userId) . ")");
    $newId = intval($db->insert_id($lt));
    tcAudit($db, 'Lesson learned created: ' . $title, 'CLOSURE_LESSON_CREATE', $newId, 'lessons_learned');
    tcOut(array('status' => 'ok', 'id' => $newId, 'message' => 'closure_msg.lessonCreated'));
}

/* -------------------------------------------------------- lesson_delete */
case 'lesson_delete': {
    $body = tcBody();
    $closure = tcClosureRow($db, $ctx['tplan_id'], false);
    if ($closure['closure_status'] === 'closed') {
        tcFail(409, 'closure_frozen');
    }
    $id = intval($body['id'] ?? 0);
    if ($id <= 0) { tcFail(400, 'Invalid lesson id'); }
    $t = tcTables();
    $lt = $t['lessons_learned'];
    $rs = $db->get_recordset("SELECT id, testplan_id, title FROM {$lt} WHERE id = " . intval($id));
    if (is_null($rs) || count($rs) === 0) { tcFail(404, 'Lesson not found'); }
    if (intval($rs[0]['testplan_id']) !== intval($ctx['tplan_id'])) {
        tcFail(403, 'Insufficient rights');
    }
    $db->exec_query("DELETE FROM {$lt} WHERE id = " . intval($id));
    tcAudit($db, 'Lesson learned deleted: ' . $rs[0]['title'], 'CLOSURE_LESSON_DELETE', $id, 'lessons_learned');
    tcOut(array('status' => 'ok', 'message' => 'closure_msg.lessonDeleted'));
}

/* -------------------------------------------------------- closure_save */
case 'closure_save': {
    $body = tcBody();
    tcClosureRow($db, $ctx['tplan_id'], true);
    $summaryText = trim((string)($body['summary_text'] ?? ''));
    if (strlen($summaryText) > 4000) { $summaryText = substr($summaryText, 0, 4000); }
    $archiveRef = trim((string)($body['archive_ref'] ?? ''));
    if (strlen($archiveRef) > 255) { $archiveRef = substr($archiveRef, 0, 255); }

    $check = tcEmptyChecklist();
    $incoming = $body['checklist'] ?? array();
    if (is_array($incoming)) {
        foreach (tcChecklistItems() as $key) {
            $check[$key] = !empty($incoming[$key]);
        }
    }

    $t = tcTables();
    $ct = $t['test_closure'];
    $db->exec_query(
        "UPDATE {$ct} SET closure_summary = '" . $db->prepare_string($summaryText) . "', " .
        "archive_ref = '" . $db->prepare_string($archiveRef) . "', " .
        "checklist = '" . $db->prepare_string(json_encode($check)) . "', " .
        "updated_by = " . intval($userId) . " WHERE testplan_id = " . intval($ctx['tplan_id']));
    tcAudit($db, 'Test plan closure data saved: ' . $ctx['tplan_name'], 'CLOSURE_SAVE',
            $ctx['tplan_id'], 'test_closure');
    tcOut(array('status' => 'ok', 'message' => 'closure_msg.closureSaved'));
}

/* -------------------------------------------------------- closure_close */
case 'closure_close': {
    $body = tcBody();
    $row = tcClosureRow($db, $ctx['tplan_id'], true);
    if ($row['closure_status'] === 'closed') {
        tcOut(array('status' => 'ok', 'message' => 'closure_msg.alreadyClosed'));
    }
    // Freeze: snapshot the live outcome metrics so the archived closure report
    // cannot change afterwards (ISTQB "finalize archive").
    $snapshot = tcPlanMetrics($db, $ctx['tplan_id']);
    $t = tcTables();
    $ct = $t['test_closure'];
    $db->exec_query(
        "UPDATE {$ct} SET closure_status = 'closed', " .
        "metrics_snapshot = '" . $db->prepare_string(json_encode($snapshot)) . "', " .
        "closed_by = " . intval($userId) . ", closed_ts = NOW(), " .
        "updated_by = " . intval($userId) . " WHERE testplan_id = " . intval($ctx['tplan_id']));
    tcAudit($db, 'Test plan CLOSED (results frozen): ' . $ctx['tplan_name'], 'CLOSURE_CLOSE',
            $ctx['tplan_id'], 'test_closure');
    tcOut(array('status' => 'ok', 'message' => 'closure_msg.planClosed'));
}

/* ------------------------------------------------------- closure_reopen */
case 'closure_reopen': {
    tcClosureRow($db, $ctx['tplan_id'], true);
    $t = tcTables();
    $ct = $t['test_closure'];
    $db->exec_query(
        "UPDATE {$ct} SET closure_status = 'open', metrics_snapshot = NULL, " .
        "closed_by = NULL, closed_ts = NULL, updated_by = " . intval($userId) .
        " WHERE testplan_id = " . intval($ctx['tplan_id']));
    tcAudit($db, 'Test plan REOPENED (closure unfrozen): ' . $ctx['tplan_name'], 'CLOSURE_REOPEN',
            $ctx['tplan_id'], 'test_closure');
    tcOut(array('status' => 'ok', 'message' => 'closure_msg.planReopened'));
}

default:
    tcFail(400, 'Unknown or missing action');
}
