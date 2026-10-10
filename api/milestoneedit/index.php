<?php
/**
 * Test Milestone Create/Edit standalone BFF API
 * URL: /api/milestoneedit/?action=init|create|update|delete
 * Plain PHP, no framework, no compilation.
 *
 * Refs #1894 - modernizes lib/plan/planMilestonesEdit.php (the standalone
 * 1.9.20 milestone editor) + gui/templates/dashio/plan/planMilestonesEdit.tpl.
 *
 * Mirrors the legacy planMilestonesCommands.class.php semantics 1:1:
 *   - rights  : testplan_planning on the OWNING test project (legacy
 *               checkRights() rightsAnd gate), re-checked on EVERY route
 *   - name    : non-empty + unique inside the test plan
 *               (check_name_existence(), own id excluded on update)
 *   - dates   : target_date required, start_date optional, both validated
 *               against config date_format (ISO in/out here), target not in
 *               the past on create (on update only when the date CHANGED),
 *               target >= start (BUGID 3716/3829)
 *   - percents: integer 0..100 (legacy validateForm range check), missing -> 0
 *   - column mapping: body high_percentage -> a (legacy form's
 *     low_priority_tcases input -> column a -> read back as high_percentage),
 *     exactly like api/milestones.
 *
 * Hardened vs legacy (#1739/#1886 die with the shim): every request
 * parameter is scalar-checked before use (an array parameter answers 400,
 * never an uncaught TypeError), writes are POST-only behind the same-origin
 * proof, and no action is dispatched through method_exists().
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

// Session gate BEFORE the DB connect (#1677 lesson): an anonymous caller
// must never receive a raw dbms_msg from a down database.
if (empty($_SESSION['userID']) || intval($_SESSION['userID']) <= 0) {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        http_response_code(401);
    }
    echo json_encode(['status' => 'error', 'code' => 'not_authenticated',
                      'message' => 'not_authenticated']);
    exit;
}

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');

$db = new database(DB_TYPE);
doDBConnect($db);
bffEnforceSession($db);

$userId = intval($_SESSION['userID']);
$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'code' => 'not_authenticated',
                      'message' => 'not_authenticated']);
    exit;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$action = isset($_GET['action']) ? $_GET['action']
       : (isset($_POST['action']) ? $_POST['action'] : 'init');

function out($data) {
    echo json_encode($data);
    exit;
}

function fail($http, $code, $message, $extra = []) {
    http_response_code($http);
    out(array_merge(['status' => 'error', 'code' => $code, 'message' => $message], $extra));
}

/** Array-aware parameter read: an array parameter is a 400, never a cast. */
function scalarParam($src, $key, $default = null) {
    if (!isset($src[$key])) {
        return $default;
    }
    $v = $src[$key];
    return is_scalar($v) ? $v : $default;
}

/**
 * #1697: an unentitled caller must never learn whether a plan or milestone
 * exists. A resolve failure answers the same 403 no_right as a real foreign
 * target unless the caller holds testplan_planning globally, in which case a
 * 404 leaks nothing he is not already entitled to.
 */
function opaqueNotFound($code) {
    global $user, $db;
    if (!canManage($user, $db, 0)) {
        fail(403, 'no_right', 'no_right');
    }
    fail(404, $code, $code);
}

function getBody() {
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Resolve the owning test project of a test plan node (api/milestones
 * parity): null when the id is not a testplan node.
 */
function resolveTplan($db, $tplanId) {
    $tplanMgr = new testplan($db);
    $info = $tplanMgr->tree_manager->get_node_hierarchy_info(
        $tplanId, null, ['nodeType' => 'testplan']);
    if (is_null($info)) {
        return null;
    }
    $tprojMgr = new testproject($db);
    $pinfo = $tprojMgr->get_by_id(intval($info['parent_id']));
    return [
        'tplan_id' => intval($tplanId),
        'tplan_name' => $info['name'],
        'tproject_id' => intval($info['parent_id']),
        'tproject_name' => (is_array($pinfo) && isset($pinfo['name'])) ? $pinfo['name'] : '',
    ];
}

/** Legacy right (planMilestonesEdit.php checkRights). */
function canManage($user, $db, $tprojectId) {
    return (bool)$user->hasRight($db, 'testplan_planning', $tprojectId);
}

/**
 * Prove the addressed project, then the right, THEN (optionally) the
 * client-side project assertion - so the endpoint is never an existence
 * oracle for a caller without the right (#1697 lesson).
 */
function requirePlanRights($user, $db, $ctx, $assertedTproject) {
    if (!canManage($user, $db, $ctx['tproject_id'])) {
        fail(403, 'no_right', 'no_right');
    }
    if ($assertedTproject !== null && $assertedTproject > 0 &&
        $assertedTproject !== $ctx['tproject_id']) {
        fail(404, 'project_mismatch', 'project_mismatch');
    }
}

/** Validate an ISO date (YYYY-MM-DD); empty allowed when not required. */
function isoDate($body, $key, $required) {
    $v = trim((string)scalarParam($body, $key, ''));
    if ($v === '') {
        return $required ? [false, 'warning_invalid_date'] : ['', null];
    }
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) ||
        !checkdate(intval($m[2]), intval($m[3]), intval($m[1]))) {
        return [false, 'warning_invalid_date'];
    }
    return [$v, null];
}

/** Percentage field: integer 0..100 (legacy JS validateForm check). */
function pctField($body, $key) {
    $v = trim((string)scalarParam($body, $key, ''));
    if ($v === '') {
        $v = '0';
    }
    if (!is_numeric($v)) {
        return [false, 'warning_invalid_percentage'];
    }
    $n = intval(round(floatval($v)));
    if ($n < 0 || $n > 100) {
        return [false, 'warning_invalid_percentage'];
    }
    return [$n, null];
}

/** Common write-input validation shared by create and update. */
function validateWrite($body, $milestoneMgr, $tplanId, $excludeId) {
    $name = trim((string)scalarParam($body, 'name', ''));
    if ($name === '') {
        fail(400, 'warning_empty_milestone_name', 'warning_empty_milestone_name');
    }
    if ($milestoneMgr->check_name_existence($tplanId, $name,
            $excludeId > 0 ? $excludeId : null)) {
        fail(409, 'milestone_name_already_exists', 'milestone_name_already_exists',
             ['detail' => $name]);
    }
    list($targetDate, $err) = isoDate($body, 'target_date', true);
    if ($err !== null) {
        fail(400, $err, $err);
    }
    list($startDate, $err) = isoDate($body, 'start_date', false);
    if ($err !== null) {
        fail(400, $err, $err);
    }
    list($highPct, $err) = pctField($body, 'high_percentage');
    if ($err !== null) {
        fail(400, $err, $err);
    }
    list($mediumPct, $err) = pctField($body, 'medium_percentage');
    if ($err !== null) {
        fail(400, $err, $err);
    }
    list($lowPct, $err) = pctField($body, 'low_percentage');
    if ($err !== null) {
        fail(400, $err, $err);
    }
    if ($startDate !== '' &&
        strtotime($targetDate . ' 23:59:59') < strtotime($startDate . ' 23:59:59')) {
        fail(400, 'warning_target_before_start', 'warning_target_before_start');
    }
    return [$name, $targetDate, $startDate, $highPct, $mediumPct, $lowPct];
}

$milestoneMgr = new milestone($db);

/* ------------------------------------------------------------------ */
/* Routes                                                              */
/* ------------------------------------------------------------------ */

if (!is_scalar($action)) {
    fail(400, 'invalid_parameter', 'invalid_parameter');
}
$action = (string)$action;

/* ---- GET|HEAD ?action=init --------------------------------------- */
if (($method === 'GET' || $method === 'HEAD') && $action === 'init') {
    $mode = (string)scalarParam($_GET, 'mode', 'create');
    if ($mode !== 'create' && $mode !== 'edit') {
        fail(400, 'invalid_parameter', 'invalid_parameter');
    }
    $assertedProject = intval(scalarParam($_GET, 'tproject_id', 0));

    if ($mode === 'edit') {
        $mid = intval(scalarParam($_GET, 'milestone_id', 0));
        if ($mid <= 0) {
            fail(400, 'invalid_parameter', 'invalid_parameter');
        }
        $dummy = $milestoneMgr->get_by_id($mid);
        if (is_null($dummy) || !isset($dummy[$mid])) {
            opaqueNotFound('milestone_not_found');
        }
        $row = $dummy[$mid];
        $ctx = resolveTplan($db, intval($row['testplan_id']));
        if (is_null($ctx)) {
            opaqueNotFound('plan_not_found');
        }
        requirePlanRights($user, $db, $ctx, $assertedProject);
        $tprojMgr = new testproject($db);
        $tprojOpt = $tprojMgr->getOptions($ctx['tproject_id']);
        $prio = !is_null($tprojOpt) && isset($tprojOpt->testPriorityEnabled) &&
                (bool)$tprojOpt->testPriorityEnabled;
        $startDate = (string)$row['start_date'];
        out([
            'status' => 'ok',
            'rights' => [
                'canManage' => true,
                'canViewEvents' => (bool)$user->hasRight($db, 'mgt_view_events',
                                                         $ctx['tproject_id']),
            ],
            'data' => [
                'mode' => 'edit',
                'tplan_id' => $ctx['tplan_id'],
                'tplan_name' => $ctx['tplan_name'],
                'tproject_id' => $ctx['tproject_id'],
                'tproject_name' => $ctx['tproject_name'],
                'testPriorityEnabled' => $prio,
                'milestone' => [
                    'id' => intval($row['id']),
                    'name' => (string)$row['name'],
                    'target_date' => (string)$row['target_date'],
                    'start_date' => ($startDate === '' || $startDate === '0000-00-00')
                                    ? '' : $startDate,
                    'high_percentage' => intval($row['high_percentage']),
                    'medium_percentage' => intval($row['medium_percentage']),
                    'low_percentage' => intval($row['low_percentage']),
                ],
            ],
        ]);
    }

    // mode = create
    $tplanId = intval(scalarParam($_GET, 'tplan_id', 0));
    if ($tplanId <= 0) {
        fail(400, 'invalid_parameter', 'invalid_parameter');
    }
    $ctx = resolveTplan($db, $tplanId);
    if (is_null($ctx)) {
        opaqueNotFound('plan_not_found');
    }
    requirePlanRights($user, $db, $ctx, $assertedProject);
    $tprojMgr = new testproject($db);
    $tprojOpt = $tprojMgr->getOptions($ctx['tproject_id']);
    $prio = !is_null($tprojOpt) && isset($tprojOpt->testPriorityEnabled) &&
            (bool)$tprojOpt->testPriorityEnabled;
    out([
        'status' => 'ok',
        'rights' => ['canManage' => true, 'canViewEvents' => false],
        'data' => [
            'mode' => 'create',
            'tplan_id' => $ctx['tplan_id'],
            'tplan_name' => $ctx['tplan_name'],
            'tproject_id' => $ctx['tproject_id'],
            'tproject_name' => $ctx['tproject_name'],
            'testPriorityEnabled' => $prio,
            'milestone' => null,
        ],
    ]);
}

/* ---- POST ?action=create ----------------------------------------- */
if ($method === 'POST' && $action === 'create') {
    $tplanId = intval(scalarParam($_POST, 'tplan_id',
                     intval(scalarParam($_GET, 'tplan_id', 0))));
    if ($tplanId <= 0) {
        fail(400, 'invalid_parameter', 'invalid_parameter');
    }
    $ctx = resolveTplan($db, $tplanId);
    if (is_null($ctx)) {
        opaqueNotFound('plan_not_found');
    }
    requirePlanRights($user, $db, $ctx, intval(scalarParam($_POST, 'tproject_id',
                     intval(scalarParam($_GET, 'tproject_id', 0)))));

    $body = getBody();
    list($name, $targetDate, $startDate, $highPct, $mediumPct, $lowPct) =
        validateWrite($body, $milestoneMgr, $tplanId, 0);

    // Target must not lie in the past (create: always checked).
    if (strtotime($targetDate . ' 23:59:59') < time()) {
        fail(400, 'warning_milestone_date', 'warning_milestone_date');
    }

    $mi = new stdClass();
    $mi->tplan_id = $tplanId;
    $mi->name = $name;
    $mi->target_date = $targetDate;
    $mi->start_date = $startDate;
    $mi->low_priority = $highPct;
    $mi->medium_priority = $mediumPct;
    $mi->high_priority = $lowPct;

    $newId = $milestoneMgr->create($mi);
    if ($newId <= 0) {
        fail(500, 'milestone_create_failed', 'milestone_create_failed');
    }
    logAuditEvent(TLS('audit_milestone_created', $ctx['tplan_name'], $name),
                  'CREATE', $newId, 'milestones');
    out(['status' => 'ok', 'code' => 'ok', 'id' => $newId, 'name' => $name,
         'message' => 'milestone_created']);
}

/* ---- POST ?action=update ----------------------------------------- */
if ($method === 'POST' && $action === 'update') {
    $mid = intval(scalarParam($_POST, 'milestone_id',
              intval(scalarParam($_GET, 'milestone_id', 0))));
    if ($mid <= 0) {
        fail(400, 'invalid_parameter', 'invalid_parameter');
    }
    $dummy = $milestoneMgr->get_by_id($mid);
    if (is_null($dummy) || !isset($dummy[$mid])) {
        opaqueNotFound('milestone_not_found');
    }
    $original = $dummy[$mid];
    $ctx = resolveTplan($db, intval($original['testplan_id']));
    if (is_null($ctx)) {
        opaqueNotFound('plan_not_found');
    }
    requirePlanRights($user, $db, $ctx, intval(scalarParam($_POST, 'tproject_id',
                     intval(scalarParam($_GET, 'tproject_id', 0)))));

    $body = getBody();

    // A partial update body must not silently zero the stored percentages:
    // any percentage field the caller does not send keeps its DB value.
    // (The screen always sends all three; this only guards API callers.)
    foreach (['high_percentage', 'medium_percentage', 'low_percentage'] as $pk) {
        if (!array_key_exists($pk, $body)) {
            $stored = isset($original[$pk]) ? intval($original[$pk]) : 0;
            if ($stored < 0 || $stored > 100) {
                $stored = 0;
            }
            $body[$pk] = $stored;
        }
    }

    list($name, $targetDate, $startDate, $highPct, $mediumPct, $lowPct) =
        validateWrite($body, $milestoneMgr, $ctx['tplan_id'], $mid);

    // Update: reject a past target only when the date actually CHANGED.
    if (strtotime($targetDate . ' 23:59:59') <
            strtotime($original['target_date'] . ' 23:59:59') &&
        strtotime($targetDate . ' 23:59:59') < time()) {
        fail(400, 'warning_milestone_date', 'warning_milestone_date');
    }

    // BUGID 3907 - empty start date -> default timestamp.
    if ($startDate === '') {
        $startDate = '0000-00-00';
    }

    $ok = $milestoneMgr->update($mid, $name, $targetDate, $startDate,
                                $highPct, $mediumPct, $lowPct);
    if (!$ok) {
        fail(500, 'milestone_update_failed', 'milestone_update_failed');
    }
    logAuditEvent(TLS('audit_milestone_saved', $ctx['tplan_name'], $name),
                  'SAVE', $mid, 'milestones');
    out(['status' => 'ok', 'code' => 'ok', 'id' => $mid, 'name' => $name,
         'message' => 'milestone_saved']);
}

/* ---- POST ?action=delete ----------------------------------------- */
if ($method === 'POST' && $action === 'delete') {
    $mid = intval(scalarParam($_POST, 'milestone_id',
              intval(scalarParam($_GET, 'milestone_id', 0))));
    if ($mid <= 0) {
        fail(400, 'invalid_parameter', 'invalid_parameter');
    }
    $dummy = $milestoneMgr->get_by_id($mid);
    if (is_null($dummy) || !isset($dummy[$mid])) {
        opaqueNotFound('milestone_not_found');
    }
    $row = $dummy[$mid];
    $ctx = resolveTplan($db, intval($row['testplan_id']));
    if (is_null($ctx)) {
        opaqueNotFound('plan_not_found');
    }
    requirePlanRights($user, $db, $ctx, intval(scalarParam($_POST, 'tproject_id',
                     intval(scalarParam($_GET, 'tproject_id', 0)))));

    $milestoneMgr->delete($mid);
    logAuditEvent(TLS('audit_milestone_deleted', $ctx['tplan_name'], $row['name']),
                  'DELETE', $mid, 'milestones');
    out(['status' => 'ok', 'code' => 'ok', 'id' => $mid,
         'name' => (string)$row['name'], 'message' => 'milestone_deleted']);
}

/* ---- verb / action fallbacks -------------------------------------- */
if ($method !== 'GET' && $method !== 'HEAD') {
    http_response_code(405);
    header('Allow: GET, HEAD');
    out(['status' => 'error', 'code' => 'wrong_method', 'message' => 'wrong_method']);
}
fail(400, 'unknown_action', 'unknown_action');
