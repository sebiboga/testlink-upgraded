<?php
/**
 * Test Plan Milestones BFF API
 * URL: /api/milestones/
 * Plain PHP, no framework, no compilation
 *
 * Mirrors lib/plan/planMilestonesView.php + lib/plan/planMilestonesEdit.php +
 * lib/plan/planMilestonesCommands.class.php (TestLink 1.9.20 behavior):
 * list milestones of a test plan with their live completion metrics,
 * create/update/delete milestones.
 *
 * Rights (same as legacy screens):
 *   everything -> testplan_planning (planMilestonesView.php / Edit.php
 *   checkGUISecurityClearance rightsAnd gate)
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
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'User not found']);
    exit;
}

$path = $_SERVER['PATH_INFO'] ?? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = preg_replace('#^/api/milestones(/index\.php)?#', '', $path);
$path = '/' . trim($path, '/');
$method = $_SERVER['REQUEST_METHOD'];
$segments = array_values(array_filter(explode('/', $path)));

function out($data) { echo json_encode($data); exit; }
function getBody() { return json_decode(file_get_contents('php://input'), true) ?? []; }

/**
 * Resolve the test plan context exactly like legacy initContext(): plan must
 * be a testplan node; tproject_id comes from its parent.
 */
function resolveTplan(&$db, $tplanId) {
    $tplanMgr = new testplan($db);
    $info = $tplanMgr->tree_manager->get_node_hierarchy_info(
        $tplanId, null, array('nodeType' => 'testplan'));
    if (is_null($info)) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Invalid Test Plan ID']);
    }
    return [
        'tplan_mgr' => $tplanMgr,
        'tplan_id' => $tplanId,
        'tplan_name' => $info['name'],
        'tproject_id' => intval($info['parent_id']),
    ];
}

function needTplanId() {
    $id = intval($_GET['tplan_id'] ?? ($_POST['tplan_id'] ?? 0));
    if ($id <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test plan id']);
    }
    return $id;
}

/** Legacy right enforced on every route (planMilestonesView.php parity). */
function canManage(&$user, &$db, $tprojectId) {
    return (bool)$user->hasRight($db, 'testplan_planning', $tprojectId);
}

function deny() {
    http_response_code(403);
    out(['status' => 'error', 'message' => 'Insufficient rights']);
}

/** Validate ISO date (YYYY-MM-DD); empty allowed only when $required=false. */
function isoDate($body, $key, $required) {
    $v = isset($body[$key]) ? trim((string)$body[$key]) : '';
    if ($v === '') {
        return $required ? [false, 'warning_invalid_date'] : ['', null];
    }
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) ||
        !checkdate(intval($m[2]), intval($m[3]), intval($m[1]))) {
        return [false, 'warning_invalid_date'];
    }
    return [$v, null];
}

/**
 * Percentage field: integer 0..100 (legacy JS validateForm range check).
 * Missing -> 0 (edit form posts hidden 0 when priorities are disabled).
 */
function pctField($body, $key) {
    $v = isset($body[$key]) ? trim((string)$body[$key]) : '';
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

$tplanMgr = new testplan($db);
$milestoneMgr = new milestone($db);

/* ------------------------------------------------------------------ */
/* Routes                                                              */
/* ------------------------------------------------------------------ */

if ($method === 'GET' && $path === '/list') {
    $tplanId = needTplanId();
    $ctx = resolveTplan($db, $tplanId);
    if (!canManage($user, $db, $ctx['tproject_id'])) {
        deny();
    }

    $items = $milestoneMgr->get_all_by_testplan($tplanId);
    if (is_null($items)) {
        $items = [];
    }

    // Live metrics - same computation as the legacy view screen.
    $metrics = [];
    if (count($items) > 0) {
        $metricsMgr = new tlTestPlanMetrics($db);
        $metrics = $metricsMgr->getMilestonesMetrics($tplanId, $items);
        if (is_null($metrics)) {
            $metrics = [];
        }
    }

    // Column layout depends on the project option, exactly like the tpl.
    $tprojMgr = new testproject($db);
    $tprojOpt = $tprojMgr->getOptions($ctx['tproject_id']);
    $priorityEnabled = false;
    if (!is_null($tprojOpt) && isset($tprojOpt->options) &&
        isset($tprojOpt->options->testPriorityEnabled)) {
        $priorityEnabled = (bool)$tprojOpt->options->testPriorityEnabled;
    } elseif (!is_null($tprojOpt) && isset($tprojOpt->testPriorityEnabled)) {
        $priorityEnabled = (bool)$tprojOpt->testPriorityEnabled;
    }

    out([
        'status' => 'ok',
        'rights' => [
            'canManage' => canManage($user, $db, $ctx['tproject_id']),
        ],
        'data' => [
            'tplan_id' => $tplanId,
            'tplan_name' => $ctx['tplan_name'],
            'tproject_id' => $ctx['tproject_id'],
            'testPriorityEnabled' => $priorityEnabled,
            'milestones' => array_values($items),
            'metrics' => array_values($metrics),
        ],
    ]);
}

if ($method === 'POST' && ($path === '/create')) {
    $tplanId = needTplanId();
    $ctx = resolveTplan($db, $tplanId);
    if (!canManage($user, $db, $ctx['tproject_id'])) {
        deny();
    }
    $body = getBody();

    $name = trim((string)($body['name'] ?? ''));
    if ($name === '') {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'warning_empty_milestone_name']);
    }

    // Legacy parity: unique name inside the test plan.
    if ($milestoneMgr->check_name_existence($tplanId, $name)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'milestone_name_already_exists',
             'detail' => $name]);
    }

    list($targetDate, $err) = isoDate($body, 'target_date', true);
    if ($err !== null) {
        http_response_code(400);
        out(['status' => 'error', 'message' => $err]);
    }
    list($startDate, $err) = isoDate($body, 'start_date', false);
    if ($err !== null) {
        http_response_code(400);
        out(['status' => 'error', 'message' => $err]);
    }

    // Target must not lie in the past (create: always checked).
    if (strtotime($targetDate . ' 23:59:59') < time()) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'warning_milestone_date']);
    }

    // Target must be chronologically after start.
    if ($startDate !== '' &&
        strtotime($targetDate . ' 23:59:59') < strtotime($startDate . ' 23:59:59')) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'warning_target_before_start']);
    }

    list($highPct, $err) = pctField($body, 'high_percentage');
    if ($err !== null) { http_response_code(400); out(['status' => 'error', 'message' => $err]); }
    list($mediumPct, $err) = pctField($body, 'medium_percentage');
    if ($err !== null) { http_response_code(400); out(['status' => 'error', 'message' => $err]); }
    list($lowPct, $err) = pctField($body, 'low_percentage');
    if ($err !== null) { http_response_code(400); out(['status' => 'error', 'message' => $err]); }

    // Legacy parity note: milestone::create()/update() write their arguments
    // into DB columns (a,b,c) while get_all_by_testplan() aliases
    // a AS high_percentage / b AS medium / c AS low. The legacy edit form
    // feeds the "High priority %" input into the low_priority argument
    // (planMilestonesEdit.tpl labels th_perc_a_prio but posts it as
    // low_priority_tcases). To reproduce the same USER-VISIBLE round-trip
    // (enter X under High -> read back X as high_percentage), the body values
    // are mapped exactly like the legacy controller does.
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
        http_response_code(500);
        out(['status' => 'error', 'message' => 'milestone_create_failed']);
    }

    logAuditEvent(TLS('audit_milestone_created', $ctx['tplan_name'], $name),
                  'CREATE', $newId, 'milestones');

    out(['status' => 'ok', 'id' => $newId,
         'message' => sprintf(lang_get('milestone_created'), $name)]);
}

if (($method === 'POST' || $method === 'PUT') && isset($segments[0]) &&
    $segments[0] === 'update' && isset($segments[1])) {
    $id = intval($segments[1]);
    $dummy = $milestoneMgr->get_by_id($id);
    if (is_null($dummy) || !isset($dummy[$id])) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Milestone not found']);
    }
    $original = $dummy[$id];
    $ctx = resolveTplan($db, intval($original['testplan_id']));
    if (!canManage($user, $db, $ctx['tproject_id'])) {
        deny();
    }
    $body = getBody();

    $name = trim((string)($body['name'] ?? ''));
    if ($name === '') {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'warning_empty_milestone_name']);
    }

    if ($milestoneMgr->check_name_existence(intval($original['testplan_id']), $name, $id)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'milestone_name_already_exists',
             'detail' => $name]);
    }

    list($targetDate, $err) = isoDate($body, 'target_date', true);
    if ($err !== null) {
        http_response_code(400);
        out(['status' => 'error', 'message' => $err]);
    }
    list($startDate, $err) = isoDate($body, 'start_date', false);
    if ($err !== null) {
        http_response_code(400);
        out(['status' => 'error', 'message' => $err]);
    }

    // Update: reject past target only when the date actually changed.
    if (strtotime($targetDate . ' 23:59:59') <
            strtotime($original['target_date'] . ' 23:59:59') &&
        strtotime($targetDate . ' 23:59:59') < time()) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'warning_milestone_date']);
    }

    if ($startDate !== '' &&
        strtotime($targetDate . ' 23:59:59') < strtotime($startDate . ' 23:59:59')) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'warning_target_before_start']);
    }

    list($highPct, $err) = pctField($body, 'high_percentage');
    if ($err !== null) { http_response_code(400); out(['status' => 'error', 'message' => $err]); }
    list($mediumPct, $err) = pctField($body, 'medium_percentage');
    if ($err !== null) { http_response_code(400); out(['status' => 'error', 'message' => $err]); }
    list($lowPct, $err) = pctField($body, 'low_percentage');
    if ($err !== null) { http_response_code(400); out(['status' => 'error', 'message' => $err]); }

    // Optional start date -> default timestamp, BUGID 3907 parity.
    if ($startDate === '') {
        $startDate = '0000-00-00';
    }

    // Same argument mapping as the create route (legacy user-visible
    // round-trip parity - see the comment there).
    $ok = $milestoneMgr->update($id, $name, $targetDate, $startDate,
                                $highPct, $mediumPct, $lowPct);
    if (!$ok) {
        http_response_code(500);
        out(['status' => 'error', 'message' => 'milestone_update_failed']);
    }

    logAuditEvent(TLS('audit_milestone_saved', $ctx['tplan_name'], $name),
                  'SAVE', $id, 'milestones');

    out(['status' => 'ok', 'message' => 'ok']);
}

if (($method === 'POST' || $method === 'DELETE') && isset($segments[0]) &&
    $segments[0] === 'delete' && isset($segments[1])) {
    $id = intval($segments[1]);
    $dummy = $milestoneMgr->get_by_id($id);
    if (is_null($dummy) || !isset($dummy[$id])) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Milestone not found']);
    }
    $ms = $dummy[$id];
    $ctx = resolveTplan($db, intval($ms['testplan_id']));
    if (!canManage($user, $db, $ctx['tproject_id'])) {
        deny();
    }

    $milestoneMgr->delete($id);
    logAuditEvent(TLS('audit_milestone_deleted', $ctx['tplan_name'], $ms['name']),
                  'DELETE', $id, 'milestones');

    out(['status' => 'ok',
         'message' => sprintf(lang_get('milestone_deleted'), $ms['name'])]);
}

http_response_code(404);
out(['status' => 'error', 'message' => 'Unknown route']);
