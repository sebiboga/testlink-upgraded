<?php
/**
 * Results by Keyword Bar Chart - REST BFF API
 * URL: /api/keywordbarchart/index.php
 * Plain PHP, no framework, no compilation
 *
 * Modern twin of lib/results/keywordBarChart.php (TestLink 1.9.20).
 *
 * WHAT THE LEGACY FILE DID
 * ------------------------
 * The 1.9.20 file was an orphan BYTE endpoint: it never rendered a Smarty
 * template, it included the vendored pChart wrapper (charts.inc.php ->
 * third_party/pchart) and answered with a PNG of a STACKED BAR chart, one bar
 * per keyword used by the test cases of the test plan, each bar stacked over
 * the execution-status totals (passed / failed / blocked / not run) of every
 * test-case version carrying that keyword (ALL_BUILDS + ALL_PLATFORMS). Data
 * came from tlTestPlanMetrics::getStatusTotalsByKeywordForRender().
 *
 * WHY IT NEEDED A MODERN TWIN
 * ---------------------------
 *  - the pChart render path (charts.inc.php) is retired from the modernized
 *    Charts screen (charts.html renders Chart.js from api/reports charts_data);
 *  - charts_data DOES return a by_keyword aggregate for the Chart.js canvas,
 *    but it exposes no standalone numbers/CSV report - unlike its two siblings
 *    Results by Priority (priorityBarChart.html, #1845) and Results by
 *    Top-Level Suite (topLevelSuitesBarChart.html, #1905);
 *  - the legacy endpoint's only gate was hasRight('testplan_metrics', ...)
 *    evaluated against the GLOBAL right with no ownership proof: the owning
 *    project is now derived from the plan and the right checked on it
 *    (getAccess = true), and tproject_id is a client-side assertion only.
 *
 * SEMANTICS OF THE AGGREGATE (kept byte-identical to the legacy class):
 *  - the unit is the test-case VERSION assigned to the test plan that carries
 *    the keyword; a version with several keywords is counted under EACH one;
 *  - each status bucket (passed/failed/blocked/not_run) is the count of test
 *    cases whose LATEST recorded execution (per test-case version + platform,
 *    across all builds) has that status; the status comes from the same
 *    tlTestPlanMetrics helper the legacy chart consumed
 *    (getStatusTotalsByKeywordForRender);
 *  - the keyword order is alphabetical by name (the legacy chart's x-axis);
 *  - a plan with no keywords (or no linked test cases) answers
 *    {context, items: [], totals: {}} with HTTP 200 - the legacy returned a
 *    blank image for that case (#634);
 *  - percentage_completed per keyword = executed/total, first decimal, from
 *    the same class method the legacy chart consumed.
 *
 * Rights gate: the plan is resolved first (404 tplan_not_found when it does
 * not exist), then testplan_metrics is checked on the OWNING test project AND
 * test plan with getAccess = true (legacy pageAccessCheck semantics: a right
 * held only globally no longer reaches a private test project -> 403). This
 * mirrors the accepted sibling api/toplevelsuitesbarchart.
 *
 * Contract: GET|HEAD ?action=init&tplan_id=N[&tproject_id=P]
 *   200 {status, code, context:{tplan_id,tplan_name,tproject_id,tproject_name},
 *        items:[{keyword_id,name,total_tc,percentage_completed,
 *                details:{passed,failed,blocked,not_run:{qty,percentage}}}],
 *        statuses:[{code,label,colour}], totals:{passed,failed,blocked,not_run}}
 *   400 invalid_request | 401 not_authenticated / session_expired
 *   403 no_right | 404 tplan_not_found / project_mismatch | 405 method_not_allowed
 *
 * Refs #1906
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
function kwbcOut($payload, $code = null)
{
    if ($code !== null) {
        http_response_code($code);
    }
    echo json_encode($payload);
    exit;
}

function kwbcFail($httpCode, $code, $message = '')
{
    kwbcOut(array(
        'status' => 'error',
        'code' => $code,
        'message' => $message !== '' ? $message : $code,
    ), $httpCode);
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    kwbcFail(405, 'method_not_allowed');
}

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    kwbcFail(401, 'not_authenticated', 'Not authenticated');
}

bffEnforceSession($db);

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    kwbcFail(401, 'not_authenticated', 'User not found');
}

$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if ($action !== 'init') {
    kwbcFail(400, 'invalid_request', 'Unknown action');
}

/** Strict positive integer reader - "1abc", "1.9", "-3" and "" are all refused. */
function kwbcPositiveInt($raw)
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

$tplanId = kwbcPositiveInt($_GET['tplan_id'] ?? null);
$assertedProjectId = kwbcPositiveInt($_GET['tproject_id'] ?? null);

if ($tplanId <= 0) {
    kwbcFail(400, 'invalid_request', 'Missing or invalid tplan_id');
}

// Order of operations (identical to api/prioritybarchart #1845 and
// api/toplevelsuitesbarchart #1905):
//   1. resolve the plan -> opaque 404 when it does not exist or has no owner
//   2. check the right on the OWNING project (getAccess = true)
//   3. only then honour the client's tproject_id assertion
$tbl = tlObjectWithDB::getDBTables(array('testplans'));
$planRow = $db->get_recordset(
    "SELECT id, testproject_id FROM {$tbl['testplans']} WHERE id = " . intval($tplanId));

if (!is_array($planRow) || count($planRow) === 0) {
    kwbcFail(404, 'tplan_not_found', 'Test plan not found');
}

$plan = $planRow[0];
$owningProjectId = intval($plan['testproject_id']);
if ($owningProjectId <= 0) {
    kwbcFail(404, 'tplan_not_found', 'Test plan has no owning test project');
}

if (!$user->hasRight($db, 'testplan_metrics', $owningProjectId, $tplanId, true)) {
    tLog('BFF keywordbarchart: user ' . intval($userId) .
         ' has no testplan_metrics right on tproject ' . $owningProjectId .
         ' (tplan ' . $tplanId . ')', 'ERROR');
    kwbcFail(403, 'no_right', 'testplan_metrics right required');
}

if ($assertedProjectId > 0 && $assertedProjectId !== $owningProjectId) {
    kwbcFail(404, 'project_mismatch', 'Test plan belongs to another test project');
}

$planName = testplan::getName($db, $tplanId);
$projectName = testproject::getName($db, $owningProjectId);
if (is_null($planName) || trim((string)$planName) === '') {
    kwbcFail(404, 'tplan_not_found', 'Test plan not found');
}

// ---------------------------------------------------------------------------
// Data: the EXACT legacy helper (tlTestPlanMetrics, 2.0.1-stable).
// ---------------------------------------------------------------------------
$resultsCfg = config_get('results');
$metricsMgr = new tlTestPlanMetrics($db);

$dummy = $metricsMgr->getStatusTotalsByKeywordForRender($tplanId);
$canDraw = !is_null($dummy) && !is_null($dummy->info) && count($dummy->info) > 0;

// Ordered statuses this chart ever draws, mirroring the legacy loop over
// $totals (the order data arrives in from the class).
$statusDomain = array('passed' => 'p', 'failed' => 'f', 'blocked' => 'b', 'not_run' => 'n');

$items = array();
$totals = array();
foreach ($statusDomain as $status => $dummyCode) {
    $totals[$status] = 0;
}

if ($canDraw) {
    // Legacy order: alphabetical by keyword name (the chart's x-axis).
    // getStatusTotalsByKeywordForRender() keys info by keyword_id; the legacy
    // controller built name => keyword_id, ksort()ed it and drew by name. We
    // reproduce exactly that inverted map.
    $name2id = array();
    foreach ($dummy->info as $keywordId => $elem) {
        $name2id[(string)$elem['name']] = intval($keywordId);
    }
    ksort($name2id);

    foreach ($name2id as $name => $keywordId) {
        $entry = array(
            'keyword_id' => $keywordId,
            'name' => html_entity_decode((string)$name, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'total_tc' => intval($dummy->info[$keywordId]['total_tc'] ?? 0),
            'percentage_completed' => floatval($dummy->info[$keywordId]['percentage_completed'] ?? 0),
            'details' => array(),
        );
        foreach ($statusDomain as $status => $dummyCode) {
            $entry['details'][$status] = array('qty' => 0, 'percentage' => 0);
        }

        $rmap = isset($dummy->info[$keywordId]['details'])
              ? $dummy->info[$keywordId]['details'] : array();
        foreach ($rmap as $status => $value) {
            if (!isset($statusDomain[$status]) || !is_array($value)) {
                continue; // same guard as the legacy chart (only draws known statuses)
            }
            $qty = intval($value['qty'] ?? 0);
            $entry['details'][$status]['qty'] = $qty;
            $totals[$status] += $qty;
        }

        $total = intval($entry['total_tc']);
        foreach ($statusDomain as $status => $dummyCode) {
            $entry['details'][$status]['percentage'] =
                ($total > 0)
                    ? round(100.0 * $entry['details'][$status]['qty'] / $total, 1)
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

kwbcOut(array(
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
