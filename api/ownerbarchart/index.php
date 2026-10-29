<?php
/**
 * Results by Tester Bar Chart - REST BFF API
 * URL: /api/ownerbarchart/index.php
 *
 * Net-new standalone chart (Refs #1972). cfg/const.inc.php still declares the
 * 'ownerBarChart' dimension ('results_by_tester') but the modern Charts
 * launcher never exposed it. The per-build table twin is
 * gui/templates/results/resultsByTesterPerBuild.html; this screen is the
 * at-a-glance aggregate across ALL builds.
 *
 * SEMANTICS
 *  - unit: the LATEST recorded execution per (test-case version, platform) of
 *    the plan, across all builds (ties broken by the highest execution id);
 *  - only versions still assigned to the plan are considered;
 *  - rows are grouped by the tester who recorded that latest execution; an
 *    execution with a NULL tester is counted in context.unattributed and is
 *    never invented into a fake bar;
 *  - statuses drawn: passed / failed / blocked (an executions row always has a
 *    recorded status; 'not run' is the ABSENCE of an execution, so it cannot
 *    be attributed to a tester and is not drawn here);
 *  - percent = pass rate (passed / total) for that tester, one decimal;
 *  - testers are ordered by execution count desc, then login asc.
 *
 * Contract: GET|HEAD ?action=init&tplan_id=N[&tproject_id=P]
 *   200 {status, code, context:{tplan_id,tplan_name,tproject_id,tproject_name,
 *        testers,executions,unattributed},
 *        items:[{tester_id,login,name,total,passed,failed,blocked,percent}],
 *        statuses:[{code,label,colour}],
 *        totals:{total,passed,failed,blocked,percent}}
 *   400 invalid_request | 401 not_authenticated / session_expired
 *   403 no_right | 404 tplan_not_found / project_mismatch | 405 method_not_allowed
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
require_once('testplan.class.php');
require_once('testproject.class.php');

doSessionStart();

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    obcFail(405, 'method_not_allowed');
}

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function obcOut($payload, $code = null)
{
    if ($code !== null) {
        http_response_code($code);
    }
    echo json_encode($payload);
    exit;
}

function obcFail($httpCode, $code, $message = '')
{
    obcOut(array(
        'status' => 'error',
        'code' => $code,
        'message' => $message !== '' ? $message : $code,
    ), $httpCode);
}

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    obcFail(401, 'not_authenticated', 'Not authenticated');
}

bffEnforceSession($db);

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    obcFail(401, 'not_authenticated', 'User not found');
}

$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if ($action !== 'init') {
    obcFail(400, 'invalid_request', 'Unknown action');
}

function obcPositiveInt($raw)
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

$tplanId = obcPositiveInt($_GET['tplan_id'] ?? null);
$assertedProjectId = obcPositiveInt($_GET['tproject_id'] ?? null);

if ($tplanId <= 0) {
    obcFail(400, 'invalid_request', 'Missing or invalid tplan_id');
}

$tbl = tlObjectWithDB::getDBTables(array('testplans'));
$planRow = $db->get_recordset(
    "SELECT id, testproject_id FROM {$tbl['testplans']} WHERE id = " . intval($tplanId));

if (!is_array($planRow) || count($planRow) === 0) {
    obcFail(404, 'tplan_not_found', 'Test plan not found');
}

$plan = $planRow[0];
$owningProjectId = intval($plan['testproject_id']);
if ($owningProjectId <= 0) {
    obcFail(404, 'tplan_not_found', 'Test plan has no owning test project');
}

if (!$user->hasRight($db, 'testplan_metrics', $owningProjectId, $tplanId, true)) {
    tLog('BFF ownerbarchart: user ' . intval($userId) .
         ' has no testplan_metrics right on tproject ' . $owningProjectId .
         ' (tplan ' . $tplanId . ')', 'ERROR');
    obcFail(403, 'no_right', 'testplan_metrics right required');
}

if ($assertedProjectId > 0 && $assertedProjectId !== $owningProjectId) {
    obcFail(404, 'project_mismatch', 'Test plan belongs to another test project');
}

$planName = testplan::getName($db, $tplanId);
$projectName = testproject::getName($db, $owningProjectId);
if (is_null($planName) || trim((string)$planName) === '') {
    obcFail(404, 'tplan_not_found', 'Test plan not found');
}

// ---------------------------------------------------------------------------
// Latest execution per (tcversion, platform) across all builds.
// ---------------------------------------------------------------------------
$t = tlObjectWithDB::getDBTables(array('executions', 'testplan_tcversions', 'users'));
$ex = $t['executions'];
$tpv = $t['testplan_tcversions'];
$us = $t['users'];
$tp = intval($tplanId);

$sql =
  "SELECT e.id, e.tester_id, e.status, u.login, u.first, u.last " .
  "FROM {$ex} e " .
  "LEFT JOIN {$us} u ON u.id = e.tester_id " .
  "WHERE e.testplan_id = {$tp} " .
  "  AND e.status IN ('p','f','b') " .
  "  AND EXISTS (SELECT 1 FROM {$tpv} t WHERE t.testplan_id = {$tp} " .
  "              AND t.tcversion_id = e.tcversion_id " .
  "              AND t.platform_id = e.platform_id) " .
  "  AND NOT EXISTS (SELECT 1 FROM {$ex} e2 " .
  "                  WHERE e2.testplan_id = e.testplan_id " .
  "                    AND e2.tcversion_id = e.tcversion_id " .
  "                    AND e2.platform_id = e.platform_id " .
  "                    AND (e2.execution_ts > e.execution_ts " .
  "                         OR (e2.execution_ts = e.execution_ts AND e2.id > e.id))) " .
  "ORDER BY e.id";

$rows = $db->get_recordset($sql);
if (!is_array($rows)) {
    $rows = array();
}

$byTester = array();
$totals = array('total' => 0, 'passed' => 0, 'failed' => 0, 'blocked' => 0);
$unattributed = 0;

foreach ($rows as $r) {
    $status = (string)$r['status'];
    $bucket = ($status === 'p') ? 'passed' : (($status === 'f') ? 'failed' : 'blocked');
    $tid = intval($r['tester_id']);

    $totals['total']++;
    $totals[$bucket]++;

    if ($tid <= 0) {
        $unattributed++;
        continue;
    }
    if (!isset($byTester[$tid])) {
        $login = trim((string)($r['login'] ?? ''));
        $name = trim(((string)($r['first'] ?? '')) . ' ' . ((string)($r['last'] ?? '')));
        if ($name === '') {
            $name = ($login !== '') ? $login : ('#' . $tid);
        }
        $byTester[$tid] = array(
            'tester_id' => $tid,
            'login' => $login,
            'name' => $name,
            'total' => 0,
            'passed' => 0,
            'failed' => 0,
            'blocked' => 0,
            'percent' => 0,
        );
    }
    $byTester[$tid]['total']++;
    $byTester[$tid][$bucket]++;
}

foreach ($byTester as $tid => $entry) {
    $byTester[$tid]['percent'] = ($entry['total'] > 0)
        ? round(100.0 * $entry['passed'] / $entry['total'], 1)
        : 0;
}

$items = array_values($byTester);
usort($items, function ($a, $b) {
    if ($a['total'] === $b['total']) {
        return strcmp($a['login'], $b['login']);
    }
    return ($a['total'] > $b['total']) ? -1 : 1;
});

$totals['percent'] = ($totals['total'] > 0)
    ? round(100.0 * $totals['passed'] / $totals['total'], 1)
    : 0;

$resultsCfg = config_get('results');
$statusDomain = array('passed' => 'p', 'failed' => 'f', 'blocked' => 'b');
$statuses = array();
foreach ($statusDomain as $status => $code) {
    $statuses[] = array(
        'code' => $status,
        'label' => lang_get($resultsCfg['status_label'][$status] ?? ('test_status_' . $status)),
        'colour' => (string)($resultsCfg['charts']['status_colour'][$status] ?? '999999'),
    );
}

obcOut(array(
    'status' => 'ok',
    'code' => 200,
    'context' => array(
        'tplan_id' => $tplanId,
        'tplan_name' => (string)$planName,
        'tproject_id' => $owningProjectId,
        'tproject_name' => (string)$projectName,
        'testers' => count($items),
        'executions' => $totals['total'],
        'unattributed' => $unattributed,
        'can_draw' => (count($items) > 0),
    ),
    'items' => $items,
    'statuses' => $statuses,
    'totals' => $totals,
));
