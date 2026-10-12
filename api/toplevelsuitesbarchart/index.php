<?php
/**
 * Top Level Suites Bar Chart - REST BFF API
 * URL: /api/toplevelsuitesbarchart/index.php
 * Plain PHP, no framework, no compilation
 *
 * Modern twin of lib/results/topLevelSuitesBarChart.php (TestLink 1.9.20).
 *
 * WHAT THE LEGACY FILE DID
 * ------------------------
 * The 1.9.20 file was an orphan BYTE endpoint: it never rendered a Smarty
 * template, it included the vendored pChart wrapper (charts.inc.php ->
 * third_party/pchart) and answered with a PNG of a STACKED BAR chart, one bar
 * per TOP-LEVEL test suite of the test plan, each bar stacked over the
 * execution-status totals (passed / failed / blocked / not run) of every
 * test-case version under it (ALL_BUILDS + ALL_PLATFORMS). Data came from
 * tlTestPlanMetrics::getRootTestSuites() + getStatusTotalsByTopLevelTestSuiteForRender().
 *
 * WHY IT NEEDED A MODERN TWIN
 * ---------------------------
 *  - the pChart render path (charts.inc.php) is retired from the modernized
 *    Charts screen (charts.html renders Chart.js from api/reports charts_data);
 *  - charts_data returns only overall / platforms / by_keyword aggregates -
 *    the legacy 4th chart (results_top_level_suites) is served by NO modern
 *    screen, and charts.html omits its section entirely (the gap this BFF +
 *    gui/templates/results/topLevelSuitesBarChart.html close);
 *  - the legacy endpoint's only gate was hasRight('testplan_metrics', ...)
 *    evaluated against the GLOBAL right with no ownership proof: the owning
 *    project is now derived from the plan and the right checked on it
 *    (getAccess = true), and tproject_id is a client-side assertion only.
 *
 * SEMANTICS OF THE AGGREGATE (kept byte-identical to the legacy class):
 *  - the unit is the test-case VERSION assigned to the test plan; every
 *    version is counted under its TOP-LEVEL suite (its root-suite ancestor);
 *  - each status bucket (passed/failed/blocked/not_run) is the raw count of
 *    execution rows of that status under the suite, summed over all builds
 *    and platforms (launch totals, the convention tlTestPlanMetrics reports
 *    in resultsGeneral), NOT latest-wins;
 *  - a plan with zero linked test cases answers {context, items: [], totals: {}}
 *    with HTTP 200 - the legacy returned a blank image for that case (#634);
 *  - percentage_completed per suite = executed/total, first decimal, from the
 *    same class method the legacy chart consumed.
 *
 * Rights gate: testplan_metrics on the OWNING test project AND test plan, with
 * getAccess = true (legacy pageAccessCheck semantics: a right held only
 * globally no longer reaches a private test project).
 *
 * Contract: GET|HEAD ?action=init&tplan_id=N[&tproject_id=P]
 *   200 {status, code, context:{tplan_id,tplan_name,tproject_id,tproject_name},
 *        items:[{tsuite_id,name,total_tc,percentage_completed,
 *                details:{passed,failed,blocked,not_run:{qty,percentage}}}],
 *        statuses:[{code,label,colour}], totals:{passed,failed,blocked,not_run}}
 *   400 invalid_request | 401 not_authenticated / session_expired
 *   403 no_right | 404 tplan_not_found / project_mismatch | 405 method_not_allowed
 *
 * Refs #1905
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
require_once('testplan.class.php');
require_once('testproject.class.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

/**
 * Single JSON exit point. $code is applied ONLY when explicitly given, so a
 * guard/validation branch that already called http_response_code() is not
 * reset back to 200 (the api/reports convention).
 */
function tlsbcOut($payload, $code = null)
{
    if ($code !== null) {
        http_response_code($code);
    }
    echo json_encode($payload);
    exit;
}

function tlsbcFail($httpCode, $code, $message = '')
{
    tlsbcOut(array(
        'status' => 'error',
        'code' => $code,
        'message' => $message !== '' ? $message : $code,
    ), $httpCode);
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    tlsbcFail(405, 'method_not_allowed');
}

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    tlsbcFail(401, 'not_authenticated', 'Not authenticated');
}

bffEnforceSession($db);

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    tlsbcFail(401, 'not_authenticated', 'User not found');
}

$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if ($action !== 'init') {
    tlsbcFail(400, 'invalid_request', 'Unknown action');
}

/** Strict positive integer reader - "1abc", "1.9", "-3" and "" are all refused. */
function tlsbcPositiveInt($raw)
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

$tplanId = tlsbcPositiveInt($_GET['tplan_id'] ?? null);
$assertedProjectId = tlsbcPositiveInt($_GET['tproject_id'] ?? null);

if ($tplanId <= 0) {
    tlsbcFail(400, 'invalid_request', 'Missing or invalid tplan_id');
}

// Order of operations (identical to api/prioritybarchart, the #1845 twin):
//   1. resolve the plan -> opaque 404 when it does not exist or has no owner
//   2. check the right on the OWNING project (getAccess = true)
//   3. only then honour the client's tproject_id assertion
$tbl = tlObjectWithDB::getDBTables(array('testplans'));
$planRow = $db->get_recordset(
    "SELECT id, testproject_id FROM {$tbl['testplans']} WHERE id = " . intval($tplanId));

if (!is_array($planRow) || count($planRow) === 0) {
    tlsbcFail(404, 'tplan_not_found', 'Test plan not found');
}

$plan = $planRow[0];
$owningProjectId = intval($plan['testproject_id']);
if ($owningProjectId <= 0) {
    tlsbcFail(404, 'tplan_not_found', 'Test plan has no owning test project');
}

if (!$user->hasRight($db, 'testplan_metrics', $owningProjectId, $tplanId, true)) {
    tLog('BFF toplevelsuitesbarchart: user ' . intval($userId) .
         ' has no testplan_metrics right on tproject ' . $owningProjectId .
         ' (tplan ' . $tplanId . ')', 'ERROR');
    tlsbcFail(403, 'no_right', 'testplan_metrics right required');
}

if ($assertedProjectId > 0 && $assertedProjectId !== $owningProjectId) {
    tlsbcFail(404, 'project_mismatch', 'Test plan belongs to another test project');
}

$planName = testplan::getName($db, $tplanId);
$projectName = testproject::getName($db, $owningProjectId);
if (is_null($planName) || trim((string)$planName) === '') {
    tlsbcFail(404, 'tplan_not_found', 'Test plan not found');
}

// ---------------------------------------------------------------------------
// Data: the EXACT legacy helpers (tlTestPlanMetrics, 2.0.1-stable).
// ---------------------------------------------------------------------------
$resultsCfg = config_get('results');
$metricsMgr = new tlTestPlanMetrics($db);

// name => tsuite_id of the plan's top-level suites (legacy chart's x-axis).
$rootNames = $metricsMgr->getRootTestSuites($tplanId, $owningProjectId);
$dummy = $metricsMgr->getStatusTotalsByTopLevelTestSuiteForRender($tplanId);
$canDraw = is_array($rootNames) && count($rootNames) > 0 &&
           !is_null($dummy) && !is_null($dummy->info);

// Ordered statuses this chart ever draws, mirroring the legacy loop over
// $totals (the order data arrives in from the class).
$statusDomain = array('passed' => 'p', 'failed' => 'f', 'blocked' => 'b', 'not_run' => 'n');

$items = array();
$totals = array();
foreach ($statusDomain as $status => $dummyCode) {
    $totals[$status] = 0;
}

if ($canDraw) {
    // Legacy order: alphabetical by suite name (the chart's x-axis).
    $name2id = array();
    foreach ($rootNames as $name => $suiteId) {
        $name2id[(string)$name] = intval($suiteId);
    }
    ksort($name2id);

    foreach ($name2id as $name => $tsuiteId) {
        $entry = array(
            'tsuite_id' => $tsuiteId,
            'name' => html_entity_decode((string)$name, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'total_tc' => 0,
            'percentage_completed' => 0,
            'details' => array(),
        );
        foreach ($statusDomain as $status => $dummyCode) {
            $entry['details'][$status] = array('qty' => 0, 'percentage' => 0);
        }

        if (isset($dummy->info[$tsuiteId])) {
            $rmap = $dummy->info[$tsuiteId]['details'];
            foreach ($rmap as $status => $value) {
                if (!isset($statusDomain[$status]) || !is_array($value)) {
                    continue; // same guard as the legacy chart (only draws known statuses)
                }
                $qty = intval($value['qty'] ?? 0);
                $entry['details'][$status]['qty'] = $qty;
                $entry['total_tc'] += $qty;
                $totals[$status] += $qty;
            }
            $pct = floatval($dummy->info[$tsuiteId]['percentage_completed'] ?? 0);
            $entry['percentage_completed'] = $pct;
        } else {
            // "make things work" branch: legacy logged it as DEBUG and drew 0.
            tLog(__FILE__ . '::buildData Missing item: name/id:' .
                 (string)$name . '/' . $tsuiteId, 'DEBUG');
        }

        foreach ($statusDomain as $status => $dummyCode) {
            $entry['details'][$status]['percentage'] =
                ($entry['total_tc'] > 0)
                    ? round(100.0 * $entry['details'][$status]['qty'] / $entry['total_tc'], 1)
                    : 0;
        }

        $items[] = $entry;
    }
}

// Series presentation: labels from status_label, colours from charts
// status_colour (RGB strings kept in 'RRGGBB' - the client prepends '#').
$statuses = array();
foreach ($statusDomain as $status => $code) {
    $statuses[] = array(
        'code' => $status,
        'label' => lang_get($resultsCfg['status_label'][$status] ?? ('test_status_' . $status)),
        'colour' => (string)($resultsCfg['charts']['status_colour'][$status] ?? '999999'),
    );
}

tlsbcOut(array(
    'status' => 'ok',
    'code' => 200,
    'context' => array(
        'tplan_id' => $tplanId,
        'tplan_name' => (string)$planName,
        'tproject_id' => $owningProjectId,
        'tproject_name' => (string)$projectName,
        'can_draw' => $canDraw,
    ),
    'items' => $items,
    'statuses' => $statuses,
    'totals' => $totals,
));