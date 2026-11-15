<?php
/**
 * Test Plan with Custom Fields - BFF (Refs #1994).
 *
 * Modern twin of the legacy standalone report controller
 * lib/results/testPlanWithCF.php (+ gui/templates/dashio/results/testPlanWithCF.tpl),
 * still registered as an ACTIVE Reports entry (`tplan_with_cf`) in
 * cfg/reports.cfg.php.
 *
 * Semantics (legacy parity):
 *   - only custom fields that have `enable_on_testplan_design = 1`, are linked
 *     to the test project (CFTP.active = 1) and are attached to the `testcase`
 *     node type are rendered, ordered by display_order, id;
 *   - the unit is a `testplan_tcversions` row (link_id) of the addressed plan,
 *     carrying its design custom-field values from
 *     `cfield_testplan_design_values`;
 *   - a row is shown ONLY when at least one custom-field value is non-empty
 *     (the legacy `$hasValue` filter);
 *   - a plan with no custom fields linked reports `no_linked_cfields`; a plan
 *     with fields but no values reports `no_values` (legacy warning message).
 *
 * Rights: session authentication, the shared BFF same-origin guard and
 * `testplan_metrics` on the OWNING test project of the addressed plan. The
 * asserted `tproject_id` is checked BEFORE project resolution (#1697) so this
 * endpoint is not an existence oracle. Denials are logged at AUDIT.
 *
 * Contract: GET|HEAD
 *   ?action=init[&tproject_id=P][&tplan_id=N]
 *     -> {status, code, context:{tproject_id,tproject_name,tplan_id,tplan_name},
 *         plans:[{tplan_id,tplan_name}]}
 *   ?action=report[&tproject_id=P][&tplan_id=N]
 *     -> {status, code, context, summary:{assigned,rows_shown,with_values,
 *         cfields,suites,total_values}, columns:[{name,label}],
 *         rows:[{link_id,tcase_id,tc_external_id,tcase_name,suite_path,
 *         cfields:{name:value}}], warnings:[...]}
 *   400 invalid_request | 401 not_authenticated/session_expired
 *   403 no_right | 404 project_not_found/tplan_not_found | 405 method_not_allowed
 */
require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
require_once('testproject.class.php');
require_once('testcase.class.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function tpcfOut($payload, $code = null)
{
    if ($code !== null) {
        http_response_code($code);
    }
    echo json_encode($payload);
    exit;
}

function tpcfFail($httpCode, $code, $message = '')
{
    tpcfOut(array(
        'status' => 'error',
        'code' => $code,
        'message' => $message !== '' ? $message : $code,
    ), $httpCode);
}

function tpcfPositiveInt($raw)
{
    if ($raw === null || is_array($raw)) {
        return 0;
    }
    $raw = trim((string)$raw);
    if ($raw === '' || !preg_match('/^[0-9]+$/', $raw)) {
        return 0;
    }
    $n = intval($raw);
    return ($n > 0) ? $n : 0;
}

function tpcfDecode($value)
{
    return html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    tpcfFail(405, 'method_not_allowed');
}

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    tpcfFail(401, 'not_authenticated', 'Not authenticated');
}

bffEnforceSession($db);

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    tpcfFail(401, 'not_authenticated', 'User not found');
}

$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if ($action !== 'init' && $action !== 'report') {
    tpcfFail(400, 'invalid_request', 'Unknown action');
}

$tbl = tlObjectWithDB::getDBTables(array(
    'testprojects', 'testplans', 'nodes_hierarchy', 'testplan_tcversions',
));

// ---------------------------------------------------------------------------
// Resolve the addressed project: explicit GET id, else the plan owning project,
// else the session project, else the first readable project.
// ---------------------------------------------------------------------------
$projectId = tpcfPositiveInt($_GET['tproject_id'] ?? null);
$planId = tpcfPositiveInt($_GET['tplan_id'] ?? null);
if ($planId <= 0) {
    $planId = intval($_SESSION['testplanID'] ?? 0);
}

$assertedProject = $projectId;

if ($projectId <= 0 && $planId > 0) {
    $own = $db->get_recordset(
        "SELECT testproject_id FROM {$tbl['testplans']} WHERE id = " . intval($planId));
    if (is_array($own) && count($own) > 0) {
        $projectId = intval($own[0]['testproject_id']);
    }
}

if ($projectId <= 0) {
    $projectId = intval($_SESSION['testprojectID'] ?? 0);
}

if ($projectId <= 0) {
    $sql = "SELECT tp.id FROM {$tbl['testprojects']} tp" .
           " JOIN {$tbl['nodes_hierarchy']} nh ON nh.id = tp.id" .
           " WHERE tp.active = 1 ORDER BY nh.name";
    $rows = $db->get_recordset($sql);
    if (is_array($rows)) {
        foreach ($rows as $row) {
            $pid = intval($row['id']);
            if ($pid > 0 && $user->hasRight($db, 'testplan_metrics', $pid, null, true)) {
                $projectId = $pid;
                break;
            }
        }
    }
}

if ($projectId <= 0) {
    tpcfFail(404, 'project_not_found', 'No readable test project');
}

// #1697: check the right on the ADDRESSED project before resolving its name.
if ($assertedProject > 0 && !$user->hasRight($db, 'testplan_metrics', $assertedProject, null, true)) {
    tLog('BFF testplanwithcf: user ' . intval($userId) .
         ' has no testplan_metrics right on tproject ' . intval($assertedProject), 'AUDIT');
    tpcfFail(403, 'no_right', 'testplan_metrics right required');
}

$projectRow = $db->get_recordset(
    "SELECT tp.id, nh.name FROM {$tbl['testprojects']} tp" .
    " JOIN {$tbl['nodes_hierarchy']} nh ON nh.id = tp.id" .
    " WHERE tp.id = " . intval($projectId));
if (!is_array($projectRow) || count($projectRow) === 0) {
    tpcfFail(404, 'project_not_found', 'Test project not found');
}
$projectName = tpcfDecode($projectRow[0]['name']);

if (!$user->hasRight($db, 'testplan_metrics', $projectId, null, true)) {
    tLog('BFF testplanwithcf: user ' . intval($userId) .
         ' has no testplan_metrics right on tproject ' . intval($projectId), 'AUDIT');
    tpcfFail(403, 'no_right', 'testplan_metrics right required');
}

$context = array(
    'tproject_id' => $projectId,
    'tproject_name' => $projectName,
    'tplan_id' => 0,
    'tplan_name' => '',
);

// ---------------------------------------------------------------------------
// Plans of the addressed project (selector). Newest activity first is not
// meaningful here; keep the legacy alphabetical order.
// ---------------------------------------------------------------------------
$planRows = $db->get_recordset(
    "SELECT tp.id, nh.name FROM {$tbl['testplans']} tp" .
    " JOIN {$tbl['nodes_hierarchy']} nh ON nh.id = tp.id" .
    " WHERE tp.testproject_id = " . intval($projectId) .
    " ORDER BY nh.name");
$plans = array();
if (is_array($planRows)) {
    foreach ($planRows as $r) {
        $pid = intval($r['id']);
        if ($pid <= 0) {
            continue;
        }
        $plans[] = array('tplan_id' => $pid, 'tplan_name' => tpcfDecode($r['name']));
    }
}

if ($planId > 0) {
    $planRow = $db->get_recordset(
        "SELECT tp.id, nh.name FROM {$tbl['testplans']} tp" .
        " JOIN {$tbl['nodes_hierarchy']} nh ON nh.id = tp.id" .
        " WHERE tp.id = " . intval($planId) .
        " AND tp.testproject_id = " . intval($projectId));
    if (!is_array($planRow) || count($planRow) === 0) {
        tpcfFail(404, 'tplan_not_found', 'Test plan not found in the addressed project');
    }
    $context['tplan_id'] = intval($planRow[0]['id']);
    $context['tplan_name'] = tpcfDecode($planRow[0]['name']);
} elseif (count($plans) > 0) {
    $context['tplan_id'] = $plans[0]['tplan_id'];
    $context['tplan_name'] = $plans[0]['tplan_name'];
    $planId = $context['tplan_id'];
}

if ($action === 'init') {
    tpcfOut(array(
        'status' => 'ok',
        'code' => 200,
        'context' => $context,
        'plans' => $plans,
    ));
}

// ---------------------------------------------------------------------------
// report
// ---------------------------------------------------------------------------
if ($planId <= 0) {
    tpcfOut(array(
        'status' => 'ok',
        'code' => 200,
        'context' => $context,
        'summary' => array(
            'assigned' => 0, 'rows_shown' => 0, 'with_values' => 0,
            'cfields' => 0, 'suites' => 0, 'total_values' => 0,
        ),
        'columns' => array(),
        'rows' => array(),
        'warnings' => array('no_plans'),
    ));
}

$cfieldMgr = new cfield_mgr($db);
$warnings = array();

// Field definitions: display label + stable column key.
$defs = $cfieldMgr->get_linked_cfields_at_testplan_design(
    $projectId, 1, 'testcase', null, null, null, 'name');
$columns = array();
if (is_array($defs)) {
    foreach ($defs as $name => $def) {
        $columns[] = array(
            'name' => (string)$name,
            'label' => tpcfDecode($def['label'] ?? $name),
        );
    }
}

$assignedRs = $db->get_recordset(
    "SELECT COUNT(*) AS c FROM {$tbl['testplan_tcversions']}" .
    " WHERE testplan_id = " . intval($planId));
$assigned = 0;
if (is_array($assignedRs) && count($assignedRs) > 0) {
    $assigned = intval($assignedRs[0]['c']);
}

$rows = array();
$suites = array();
$totalValues = 0;

if (count($columns) === 0) {
    $warnings[] = 'no_linked_cfields';
} elseif ($assigned === 0) {
    $warnings[] = 'no_assigned_testcases';
} else {
    $cfMap = $cfieldMgr->get_linked_cfields_at_testplan_design(
        $projectId, 1, 'testcase', null, null, $planId);
    if (!is_array($cfMap) || count($cfMap) === 0) {
        $warnings[] = 'no_values';
    } else {
        $tcaseMgr = new testcase($db);
        foreach ($cfMap as $linkId => $info) {
            if (!is_array($info) || !isset($info[0])) {
                continue;
            }
            $base = $info[0];
            $tcaseId = intval($base['tcase_id'] ?? 0);
            if ($tcaseId <= 0) {
                continue;
            }

            $values = array();
            $hasValue = false;
            $nonEmpty = 0;
            foreach ($info as $cf) {
                $name = (string)($cf['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $val = tpcfDecode($cf['value'] ?? '');
                $values[$name] = $val;
                if (trim($val) !== '') {
                    $hasValue = true;
                    $nonEmpty++;
                }
            }

            // Legacy parity: never show a row without a single non-empty value.
            if (!$hasValue) {
                continue;
            }

            $suitePath = '';
            $path = $tcaseMgr->getPathLayered(array($tcaseId));
            if (is_array($path) && isset($path[$tcaseId]['value'])) {
                $suitePath = tpcfDecode($path[$tcaseId]['value']);
            }

            $totalValues += $nonEmpty;
            $suites[$suitePath] = true;
            $rows[] = array(
                'link_id' => intval($linkId),
                'tcase_id' => $tcaseId,
                'tc_external_id' => intval($base['tc_external_id'] ?? 0),
                'tcase_name' => tpcfDecode($base['tcase_name'] ?? ''),
                'suite_path' => $suitePath,
                'cfields' => $values,
            );
        }
        if (count($rows) === 0 && !in_array('no_values', $warnings, true)) {
            $warnings[] = 'no_values';
        }
    }
}

tpcfOut(array(
    'status' => 'ok',
    'code' => 200,
    'context' => $context,
    'summary' => array(
        'assigned' => $assigned,
        'rows_shown' => count($rows),
        'with_values' => count($rows),
        'cfields' => count($columns),
        'suites' => count($suites),
        'total_values' => $totalValues,
    ),
    'columns' => $columns,
    'rows' => $rows,
    'warnings' => $warnings,
));
