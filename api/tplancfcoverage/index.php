<?php
/**
 * Test Plan Custom Fields Coverage - BFF (Refs #1994).
 *
 * Net-new cross-plan report (gui/templates/results/tplanCfCoverage.html).
 *
 * TestLink 1.9.20 / 2.0.1 already has a SINGLE-PLAN view of the test plan
 * design custom-field values (the modern tplanWithCF.html, legacy
 * lib/results/testPlanWithCF.php). Nothing shows whether those fields are
 * actually being FILLED across the plans of a test project. This BFF answers
 * that: for every plan of the addressed project it counts the assigned
 * test-case versions, the rows that carry at least one non-empty design
 * custom-field value (the legacy `$hasValue` rule), the filled values, the
 * suites touched and a coverage percentage.
 *
 * Semantics:
 *   - custom fields are the ones with `enable_on_testplan_design = 1`, linked
 *     and active on the test project and attached to the `testcase` node type
 *     (same definition set as the legacy report);
 *   - the unit is a `testplan_tcversions` row; a row counts as "with values"
 *     when at least one of its design CF values is non-empty;
 *   - `status`: empty (no assignments) / not_filled (no row with values) /
 *     partial (some rows) / complete (every assigned row has a value);
 *   - `coverage_pct` = rows_with_values / assigned * 100.
 *
 * Rights: session authentication, the shared BFF same-origin guard and
 * `testplan_metrics` on the ADDRESSED test project. The asserted project is
 * checked BEFORE its name is resolved (#1697), so this endpoint is not an
 * existence oracle. Denials are logged at AUDIT.
 *
 * Contract: GET|HEAD
 *   ?action=init[&tproject_id=P]    -> context + plans + cfield count
 *   ?action=report[&tproject_id=P]  -> summary + one row per plan
 *   400 invalid_request | 401 not_authenticated/session_expired
 *   403 no_right | 404 project_not_found | 405 method_not_allowed
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

function tpccOut($payload, $code = null)
{
    if ($code !== null) {
        http_response_code($code);
    }
    echo json_encode($payload);
    exit;
}

function tpccFail($httpCode, $code, $message = '')
{
    tpccOut(array(
        'status' => 'error',
        'code' => $code,
        'message' => $message !== '' ? $message : $code,
    ), $httpCode);
}

function tpccPositiveInt($raw)
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

function tpccDecode($value)
{
    return html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function tpccPct($part, $total)
{
    return ($total > 0) ? round(100.0 * $part / $total, 1) : 0.0;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    tpccFail(405, 'method_not_allowed');
}

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    tpccFail(401, 'not_authenticated', 'Not authenticated');
}

bffEnforceSession($db);

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    tpccFail(401, 'not_authenticated', 'User not found');
}

$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if ($action !== 'init' && $action !== 'report') {
    tpccFail(400, 'invalid_request', 'Unknown action');
}

$tbl = tlObjectWithDB::getDBTables(array(
    'testprojects', 'testplans', 'nodes_hierarchy', 'testplan_tcversions',
));

// ---------------------------------------------------------------------------
// Resolve the addressed project: explicit GET id, else the session project,
// else the first project the caller may read metrics on.
// ---------------------------------------------------------------------------
$assertedProject = tpccPositiveInt($_GET['tproject_id'] ?? null);
$projectId = $assertedProject;
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
    tpccFail(404, 'project_not_found', 'No readable test project');
}

// #1697: check the right on the ADDRESSED project before resolving its name.
if ($assertedProject > 0 && !$user->hasRight($db, 'testplan_metrics', $assertedProject, null, true)) {
    tLog('BFF tplancfcoverage: user ' . intval($userId) .
         ' has no testplan_metrics right on tproject ' . intval($assertedProject), 'AUDIT');
    tpccFail(403, 'no_right', 'testplan_metrics right required');
}

$projectRow = $db->get_recordset(
    "SELECT tp.id, nh.name, tp.prefix FROM {$tbl['testprojects']} tp" .
    " JOIN {$tbl['nodes_hierarchy']} nh ON nh.id = tp.id" .
    " WHERE tp.id = " . intval($projectId));
if (!is_array($projectRow) || count($projectRow) === 0) {
    tpccFail(404, 'project_not_found', 'Test project not found');
}
$projectName = tpccDecode($projectRow[0]['name']);

if (!$user->hasRight($db, 'testplan_metrics', $projectId, null, true)) {
    tLog('BFF tplancfcoverage: user ' . intval($userId) .
         ' has no testplan_metrics right on tproject ' . intval($projectId), 'AUDIT');
    tpccFail(403, 'no_right', 'testplan_metrics right required');
}

$context = array(
    'tproject_id' => $projectId,
    'tproject_name' => $projectName,
);

$cfieldMgr = new cfield_mgr($db);
$defs = $cfieldMgr->get_linked_cfields_at_testplan_design(
    $projectId, 1, 'testcase', null, null, null, 'name');
$defs = is_array($defs) ? $defs : array();
$columns = array();
foreach ($defs as $name => $def) {
    $columns[] = array(
        'name' => (string)$name,
        'label' => tpccDecode($def['label'] ?? $name),
    );
}

$planRows = $db->get_recordset(
    "SELECT tp.id, nh.name, tp.active, tp.is_open FROM {$tbl['testplans']} tp" .
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
        $plans[] = array(
            'tplan_id' => $pid,
            'tplan_name' => tpccDecode($r['name']),
            'active' => intval($r['active']),
            'is_open' => intval($r['is_open']),
        );
    }
}

if ($action === 'init') {
    tpccOut(array(
        'status' => 'ok',
        'code' => 200,
        'context' => $context,
        'plans' => $plans,
        'cfields' => count($columns),
        'columns' => $columns,
    ));
}

// ---------------------------------------------------------------------------
// report: aggregate per plan.
// ---------------------------------------------------------------------------
$warnings = array();
if (count($columns) === 0) {
    $warnings[] = 'no_linked_cfields';
}
if (count($plans) === 0) {
    $warnings[] = 'no_plans';
}

$tcaseMgr = new testcase($db);
$rows = array();
$summary = array(
    'plans_total' => count($plans),
    'plans_complete' => 0,
    'plans_partial' => 0,
    'plans_not_filled' => 0,
    'plans_empty' => 0,
    'assigned' => 0,
    'with_values' => 0,
    'coverage_pct' => 0.0,
    'cfields' => count($columns),
    'filled_values' => 0,
    'suites' => 0,
);

$suiteUnion = array();

foreach ($plans as $plan) {
    $planId = $plan['tplan_id'];

    $aRs = $db->get_recordset(
        "SELECT COUNT(*) AS c FROM {$tbl['testplan_tcversions']}" .
        " WHERE testplan_id = " . intval($planId));
    $assigned = 0;
    if (is_array($aRs) && count($aRs) > 0) {
        $assigned = intval($aRs[0]['c']);
    }

    $withValues = 0;
    $filledValues = 0;
    $planSuites = array();

    if ($assigned > 0 && count($columns) > 0) {
        $cfMap = $cfieldMgr->get_linked_cfields_at_testplan_design(
            $projectId, 1, 'testcase', null, null, $planId);
        if (is_array($cfMap)) {
            foreach ($cfMap as $info) {
                if (!is_array($info) || !isset($info[0])) {
                    continue;
                }
                $nonEmpty = 0;
                foreach ($info as $cf) {
                    $val = trim(tpccDecode($cf['value'] ?? ''));
                    if ($val !== '') {
                        $nonEmpty++;
                    }
                }
                if ($nonEmpty === 0) {
                    continue;
                }
                $withValues++;
                $filledValues += $nonEmpty;

                $tcaseId = intval($info[0]['tcase_id'] ?? 0);
                if ($tcaseId > 0) {
                    $path = $tcaseMgr->getPathLayered(array($tcaseId));
                    if (is_array($path) && isset($path[$tcaseId]['value'])) {
                        $suite = tpccDecode($path[$tcaseId]['value']);
                        $planSuites[$suite] = true;
                        $suiteUnion[$suite] = true;
                    }
                }
            }
        }
    }

    $coverage = tpccPct($withValues, $assigned);
    if ($assigned === 0) {
        $status = 'empty';
    } elseif ($withValues === 0) {
        $status = 'not_filled';
    } elseif ($withValues < $assigned) {
        $status = 'partial';
    } else {
        $status = 'complete';
    }

    $summary['assigned'] += $assigned;
    $summary['with_values'] += $withValues;
    $summary['filled_values'] += $filledValues;
    $summary['plans_' . $status] = ($summary['plans_' . $status] ?? 0) + 1;

    $rows[] = array(
        'tplan_id' => $planId,
        'tplan_name' => $plan['tplan_name'],
        'active' => $plan['active'],
        'is_open' => $plan['is_open'],
        'assigned' => $assigned,
        'rows_with_values' => $withValues,
        'coverage_pct' => $coverage,
        'filled_values' => $filledValues,
        'suites' => count($planSuites),
        'status' => $status,
    );
}

$summary['coverage_pct'] = tpccPct($summary['with_values'], $summary['assigned']);
$summary['suites'] = count($suiteUnion);

// Worst coverage first: the plans that need attention come first.
usort($rows, function ($a, $b) {
    if ($a['coverage_pct'] == $b['coverage_pct']) {
        return strcasecmp($a['tplan_name'], $b['tplan_name']);
    }
    return ($a['coverage_pct'] < $b['coverage_pct']) ? -1 : 1;
});

tpccOut(array(
    'status' => 'ok',
    'code' => 200,
    'context' => $context,
    'summary' => $summary,
    'columns' => $columns,
    'rows' => $rows,
    'warnings' => $warnings,
));
