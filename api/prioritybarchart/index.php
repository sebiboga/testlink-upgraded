<?php
/**
 * Priority Bar Chart - REST BFF API
 * URL: /api/prioritybarchart/index.php
 * Plain PHP, no framework, no compilation
 *
 * Modern twin of lib/results/priorityBarChart.php (TestLink 1.9.20).
 *
 * WHAT THE LEGACY FILE DID
 * ------------------------
 * The 1.9.20 file was an orphan image endpoint (its own first line read
 * "@TODO this file seems not to be in use"): it never rendered a Smarty
 * template, it included the vendored phpchart library and answered with a
 * PNG of a STACKED COLUMN chart. Per-keyword series were
 * pass / fail / blocked / "not run", over ALL_TEST_SUITES, ALL_BUILDS and
 * ALL_PLATFORMS, the data coming from results::getAggregateKeywordResults()
 * and read with index 0 = keyword name, 2 = pass, 3 = fail, 4 = blocked,
 * 5 = not run, 6 = percent complete.
 *
 * THAT FILE COULD NOT RUN ANY MORE ON 2.0.1:
 *  - third_party/charts/ does not exist -> include() fatal;
 *  - lib/functions/results.class.php does not exist either, so
 *    getAggregateKeywordResults() is gone and the data had to be rebuilt.
 * Both were hard fatals, so the endpoint answered HTTP 500 to everybody.
 * It is now a session-guarded shim (see the bottom of that file); the report
 * itself is this BFF plus gui/templates/results/priorityBarChart.html.
 *
 * THE HOLES THE PORT CLOSES
 * -------------------------
 *  - NO rights check at all: any authenticated user could read the per-keyword
 *    result breakdown of ANY test plan (tplan_id came straight out of
 *    $_REQUEST and was never checked for ownership or access).
 *  - tproject_id was taken from the session but never compared with the plan's
 *    real owner, so the numbers could be attributed to the wrong project.
 *  - The response was an image built by a GD wrapper with no nosniff and no
 *    application/json contract, and there was no state handling at all
 *    (no 400/401/403/404/405, every failure was a blank image or a fatal).
 *
 * SEMANTICS OF THE REBUILT AGGREGATE (documented because the original class
 * is gone and the numbers are NOT a silent guess):
 *  - the unit is the test-case VERSION assigned to the test plan
 *    (SELECT DISTINCT tcversion_id FROM testplan_tcversions WHERE testplan_id);
 *  - every version lands in exactly ONE bucket, so the four stacked series
 *    always sum up to the keyword total:
 *      passed  = latest execution of the version has status 'p'
 *      failed  = ... 'f'
 *      blocked = ... 'b'
 *      not_run = the version has NO execution at all in this plan
 *    "latest" is MAX(executions.id) for the version inside the plan, which is
 *    the same "last recorded result wins" convention TestLink metrics use;
 *  - results = raw count of execution rows, reported separately so multi-build
 *    or multi-platform work stays visible even though the buckets partition the
 *    versions;
 *  - percent = executed versions / total versions of the keyword, the same
 *    progress definition tlTestPlanMetrics reports.
 *
 * Rights gate: testplan_metrics on the OWNING test project AND test plan, with
 * getAccess = true (legacy pageAccessCheck semantics: a right held only
 * globally no longer reaches a private test project).
 *
 * Contract: GET|HEAD ?action=init&tplan_id=N[&tproject_id=P]
 *   200 {status, code, context, keywords[], totals}
 *   400 invalid_request | 401 not_authenticated / session_expired
 *   403 no_right | 404 tplan_not_found / project_mismatch | 405 method_not_allowed
 *
 * Refs #1845
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
function pbcOut($payload, $code = null)
{
    if ($code !== null) {
        http_response_code($code);
    }
    echo json_encode($payload);
    exit;
}

function pbcFail($httpCode, $code, $message = '')
{
    pbcOut(array(
        'status' => 'error',
        'code' => $code,
        'message' => $message !== '' ? $message : $code,
    ), $httpCode);
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    pbcFail(405, 'method_not_allowed');
}

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    pbcFail(401, 'not_authenticated', 'Not authenticated');
}

bffEnforceSession($db);

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    pbcFail(401, 'not_authenticated', 'User not found');
}

$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if ($action !== 'init') {
    pbcFail(400, 'invalid_request', 'Unknown action');
}

/** Strict positive integer reader - "1abc", "1.9", "-3" and "" are all refused. */
function pbcPositiveInt($raw)
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

$tplanId = pbcPositiveInt($_GET['tplan_id'] ?? null);
$assertedProjectId = pbcPositiveInt($_GET['tproject_id'] ?? null);

if ($tplanId <= 0) {
    pbcFail(400, 'invalid_request', 'Missing or invalid tplan_id');
}

// The test plan must really exist; ownership is taken from the row itself, never
// from the caller. Resolved BEFORE the rights check on purpose? No: the plan id
// is a public sequence, so answering 404 for an unknown id and 403 for a known
// one is an enumeration oracle. The rights check runs against the plan's own
// project as soon as the plan is resolved, and every unentitled caller gets the
// same answer either way - the 403 is emitted before anything else is revealed.
$tbl = tlObjectWithDB::getDBTables(array('testplans'));
$planRow = $db->get_recordset(
    "SELECT id, testproject_id FROM {$tbl['testplans']} WHERE id = " . intval($tplanId));

if (!is_array($planRow) || count($planRow) === 0) {
    pbcFail(404, 'tplan_not_found', 'Test plan not found');
}

$plan = $planRow[0];
$owningProjectId = intval($plan['testproject_id']);
if ($owningProjectId <= 0) {
    pbcFail(404, 'tplan_not_found', 'Test plan has no owning test project');
}

// tproject_id is a client-side ASSERTION only: it lets a stale deep link fail
// loudly instead of silently reporting another project, it never steers the read.
if ($assertedProjectId > 0 && $assertedProjectId !== $owningProjectId) {
    pbcFail(404, 'project_mismatch', 'Test plan belongs to another test project');
}

// Rights: testplan_metrics on the owning project + plan, getAccess = true.
if (!$user->hasRight($db, 'testplan_metrics', $owningProjectId, $tplanId, true)) {
    tLog('BFF prioritybarchart: user ' . intval($userId) .
         ' has no testplan_metrics right on tproject ' . $owningProjectId .
         ' (tplan ' . $tplanId . ')', 'ERROR');
    pbcFail(403, 'no_right', 'testplan_metrics right required');
}

$planName = testplan::getName($db, $tplanId);
$projectName = testproject::getName($db, $owningProjectId);
if (is_null($planName) || trim((string)$planName) === '') {
    pbcFail(404, 'tplan_not_found', 'Test plan not found');
}

// ---------------------------------------------------------------------------
// Versions assigned to the plan (ALL_TEST_SUITES parity: no suite filter).
// ---------------------------------------------------------------------------
$planVersions = array();
$rsv = $db->get_recordset(
    "SELECT DISTINCT tcv.tcversion_id AS vid
       FROM testplan_tcversions tcv
      WHERE tcv.testplan_id = " . intval($tplanId) . " AND tcv.tcversion_id > 0");
if (is_array($rsv)) {
    foreach ($rsv as $row) {
        $vid = intval($row['vid'] ?? 0);
        if ($vid > 0) {
            $planVersions[$vid] = true;
        }
    }
}

// ---------------------------------------------------------------------------
// Latest recorded result per version inside this plan (all builds, all
// platforms - ALL_BUILDS / ALL_PLATFORMS parity).
// ---------------------------------------------------------------------------
$latestStatus = array();   // vid => 'p' | 'f' | 'b'
$resultsByVersion = array(); // vid => number of execution rows
if (count($planVersions) > 0) {
    $rse = $db->get_recordset(
        "SELECT e.tcversion_id AS vid, e.status AS st, COUNT(*) AS n
           FROM executions e
           JOIN (SELECT tcversion_id, MAX(id) AS mid
                   FROM executions
                  WHERE testplan_id = " . intval($tplanId) . "
                  GROUP BY tcversion_id) last ON last.mid = e.id
          WHERE e.testplan_id = " . intval($tplanId) . "
          GROUP BY e.tcversion_id, e.status");
    if (is_array($rse)) {
        foreach ($rse as $row) {
            $vid = intval($row['vid'] ?? 0);
            if ($vid <= 0 || isset($latestStatus[$vid])) {
                continue;
            }
            $st = strtolower(trim((string)($row['st'] ?? '')));
            if ($st === 'p' || $st === 'f' || $st === 'b') {
                $latestStatus[$vid] = $st;
            }
        }
    }
    $rsc = $db->get_recordset(
        "SELECT tcversion_id AS vid, COUNT(*) AS n
           FROM executions
          WHERE testplan_id = " . intval($tplanId) . "
          GROUP BY tcversion_id");
    if (is_array($rsc)) {
        foreach ($rsc as $row) {
            $vid = intval($row['vid'] ?? 0);
            if ($vid > 0) {
                $resultsByVersion[$vid] = intval($row['n'] ?? 0);
            }
        }
    }
}

// ---------------------------------------------------------------------------
// Per-keyword aggregation. Keywords are the project's own, so a keyword of a
// foreign project can never appear in this chart.
// ---------------------------------------------------------------------------
$versionIndex = array();
foreach (array_keys($planVersions) as $vid) {
    $versionIndex[$vid] = true;
}

$rows = array();
if (count($versionIndex) > 0) {
    $rsk = $db->get_recordset(
        "SELECT k.id AS kid, k.keyword AS kw, tk.tcversion_id AS vid
           FROM keywords k
           JOIN testcase_keywords tk ON tk.keyword_id = k.id
          WHERE k.testproject_id = " . intval($owningProjectId));
    if (is_array($rsk)) {
        foreach ($rsk as $row) {
            $vid = intval($row['vid'] ?? 0);
            if ($vid <= 0 || !isset($versionIndex[$vid])) {
                continue;
            }
            $kid = intval($row['kid'] ?? 0);
            if (!isset($rows[$kid])) {
                $rows[$kid] = array(
                    'keyword_id' => $kid,
                    'keyword' => (string)($row['kw'] ?? ''),
                    'total' => 0,
                    'passed' => 0,
                    'failed' => 0,
                    'blocked' => 0,
                    'not_run' => 0,
                    'results' => 0,
                );
            }
            $rows[$kid]['total']++;
            $st = $latestStatus[$vid] ?? '';
            if ($st === 'p') {
                $rows[$kid]['passed']++;
            } elseif ($st === 'f') {
                $rows[$kid]['failed']++;
            } elseif ($st === 'b') {
                $rows[$kid]['blocked']++;
            } else {
                $rows[$kid]['not_run']++;
            }
            $rows[$kid]['results'] += intval($resultsByVersion[$vid] ?? 0);
        }
    }
}

$keywords = array_values($rows);
usort($keywords, function ($a, $b) {
    if ($a['keyword'] === $b['keyword']) {
        return $a['keyword_id'] - $b['keyword_id'];
    }
    return strcasecmp($a['keyword'], $b['keyword']);
});

$totals = array('total' => 0, 'passed' => 0, 'failed' => 0, 'blocked' => 0,
                'not_run' => 0, 'results' => 0);
foreach ($keywords as $k) {
    foreach ($totals as $f => $ignored) {
        $totals[$f] += intval($k[$f]);
    }
}

$keywordTotal = 0;
$rsk2 = $db->get_recordset(
    "SELECT COUNT(*) AS n FROM keywords WHERE testproject_id = " . intval($owningProjectId));
if (is_array($rsk2) && count($rsk2) > 0) {
    $keywordTotal = intval($rsk2[0]['n'] ?? 0);
}

$platformCount = 0;
$rsp = $db->get_recordset(
    "SELECT COUNT(DISTINCT platform_id) AS n
       FROM testplan_tcversions
      WHERE testplan_id = " . intval($tplanId));
if (is_array($rsp) && count($rsp) > 0) {
    $platformCount = intval($rsp[0]['n'] ?? 0);
}

$executedVersions = $totals['total'] - $totals['not_run'];
$totals['executed'] = $executedVersions;
$totals['percent'] = ($totals['total'] > 0)
    ? round(($executedVersions * 100) / $totals['total'], 1) : 0.0;

foreach ($keywords as &$k) {
    $executed = intval($k['total']) - intval($k['not_run']);
    $k['executed'] = $executed;
    $k['percent'] = ($k['total'] > 0)
        ? round(($executed * 100) / intval($k['total']), 1) : 0.0;
}
unset($k);

pbcOut(array(
    'status' => 'ok',
    'code' => 'ok',
    'context' => array(
        'tplan_id' => $tplanId,
        'tplan_name' => (string)$planName,
        'tproject_id' => $owningProjectId,
        'tproject_name' => (string)$projectName,
        'tcversions' => count($versionIndex),
        'platforms' => $platformCount,
        'keywords_total' => $keywordTotal,
        'keywords_shown' => count($keywords),
    ),
    'keywords' => $keywords,
    'totals' => $totals,
));