<?php
/**
 * Test Plan Test Case Version Churn (Rework) report - BFF (Refs #1986).
 *
 * Net-new modern screen (gui/templates/results/versionChurn.html). TestLink
 * has always kept EVERY design revision of a test case in `tcversions`
 * (`creation_ts`, `modification_ts`, `version`) and pins one or more of them
 * on the plan through `testplan_tcversions`, but 1.9.20/2.0.1 ships no screen
 * that reports how much the plan's specifications were REWRITTEN. A test case
 * redesigned eight times before the plan pinned it looks exactly like a
 * clean one, and a plan that keeps pinning an OLD design (behind the newest
 * version) is invisible. This screen exposes that churn/rework signal.
 *
 * Semantics (documented here because the screen relies on them):
 *   - the unit is the TEST CASE linked to the plan (`testplan_tcversions`),
 *     deduplicated: a plan may pin several versions of the same test case
 *     (one per platform), they all count as one case.
 *   - `total_versions` = every version of the test case (active and retired):
 *     that is the design history; `rework` = total_versions - 1 (the number
 *     of redesigns after v1).
 *   - `versions_in_window` = versions whose `creation_ts` falls inside the
 *     selected trailing window (1..3650 days, default 90).
 *   - `assigned_latest` = highest version number pinned by the plan;
 *     `newest` = highest version number that exists. `behind` = newest -
 *     assigned_latest > 0 (the plan pins an outdated design).
 *   - verdict precedence: `churning` when >= 2 versions were created inside
 *     the window OR rework >= 3; `watch` when any version was created in the
 *     window OR rework is 1..2 OR the plan is behind; `stable` otherwise.
 *     A case with a single version and no newer design is `stable`.
 *   - `last_execution` = latest execution timestamp of any ASSIGNED version
 *     inside the plan (informational only, never judged here).
 *
 * Actions
 *   GET|HEAD ?action=init&tplan_id=N[&tproject_id=P]
 *       -> context (plan / project) + the window default
 *   GET|HEAD ?action=report&tplan_id=N[&tproject_id=P][&window_days=D]
 *       -> summary KPIs + per-test-case rows + per-suite breakdown
 *
 * Security: session authentication, the shared BFF same-origin guard and the
 * `testplan_metrics` right on the OWNING test project. The client supplied
 * tproject_id is only an ASSERTION: it is checked against the plan's owning
 * project and never used to widen the query.
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

/** Single JSON exit point. */
function vcOut($payload, $code = null)
{
    if ($code !== null) {
        http_response_code($code);
    }
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function vcFail($httpCode, $code, $message = '')
{
    vcOut(array(
        'status' => 'error',
        'code' => $code,
        'message' => $message !== '' ? $message : $code,
    ), $httpCode);
}

/** Strict positive integer reader - "1abc", "1.9", "-3" and "" are all refused. */
function vcPositiveInt($raw)
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

function vcDecode($value)
{
    return html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function vcRound($value, $decimals = 1)
{
    return round((float)$value, $decimals);
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    vcFail(405, 'method_not_allowed');
}

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    vcFail(401, 'not_authenticated', 'Not authenticated');
}

bffEnforceSession($db);

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    vcFail(401, 'not_authenticated', 'User not found');
}

$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if ($action !== 'init' && $action !== 'report') {
    vcFail(400, 'invalid_request', 'Unknown action');
}

$tplanId = vcPositiveInt($_GET['tplan_id'] ?? null);
$assertedProjectId = vcPositiveInt($_GET['tproject_id'] ?? null);

if ($tplanId <= 0) {
    vcFail(400, 'invalid_request', 'Missing or invalid tplan_id');
}

// Trailing window for "versions created recently", bounded so a crafted value
// can never produce a nonsensical KPI.
$windowDays = 90;
if (isset($_GET['window_days'])) {
    $raw = is_array($_GET['window_days']) ? '' : trim((string)$_GET['window_days']);
    if ($raw === '' || !preg_match('/^[0-9]+$/', $raw)) {
        vcFail(400, 'invalid_request', 'Invalid window_days');
    }
    $windowDays = intval($raw);
    if ($windowDays < 1 || $windowDays > 3650) {
        vcFail(400, 'invalid_request', 'window_days out of range');
    }
}

// Order of operations (identical to api/execeffort, api/planforecast, ...):
//   1. resolve the plan -> opaque 404 when it does not exist or has no owner
//   2. check the right on the OWNING project
//   3. only then honour the client's tproject_id assertion
$tbl = tlObjectWithDB::getDBTables(array(
    'testplans', 'testplan_tcversions', 'tcversions', 'nodes_hierarchy',
    'executions', 'testprojects',
));

$planRow = $db->get_recordset(
    "SELECT id, testproject_id FROM {$tbl['testplans']} WHERE id = " . intval($tplanId));

if (!is_array($planRow) || count($planRow) === 0) {
    vcFail(404, 'tplan_not_found', 'Test plan not found');
}

$plan = $planRow[0];
$owningProjectId = intval($plan['testproject_id']);
if ($owningProjectId <= 0) {
    vcFail(404, 'tplan_not_found', 'Test plan has no owning test project');
}

if (!$user->hasRight($db, 'testplan_metrics', $owningProjectId, $tplanId, true)) {
    // A refused authorization is a normal outcome, not a system error: log it
    // at AUDIT so the Event Viewer close-out stays free of ERROR/WARNING rows.
    tLog('BFF versionchurn: user ' . intval($userId) .
         ' has no testplan_metrics right on tproject ' . $owningProjectId .
         ' (tplan ' . $tplanId . ')', 'AUDIT');
    vcFail(403, 'no_right', 'testplan_metrics right required');
}

if ($assertedProjectId > 0 && $assertedProjectId !== $owningProjectId) {
    vcFail(404, 'project_mismatch', 'Test plan belongs to another test project');
}

$planName = testplan::getName($db, $tplanId);
$projectName = testproject::getName($db, $owningProjectId);
if (is_null($planName) || trim((string)$planName) === '') {
    vcFail(404, 'tplan_not_found', 'Test plan not found');
}

$context = array(
    'tplan_id' => $tplanId,
    'tplan_name' => (string)$planName,
    'tproject_id' => $owningProjectId,
    'tproject_name' => (string)$projectName,
);
$filters = array('window_days' => $windowDays);

if ($action === 'init') {
    vcOut(array(
        'status' => 'ok',
        'code' => 200,
        'context' => $context,
        'filters' => $filters,
        'filter' => array('window_days' => $windowDays),
    ));
}

// ---------------------------------------------------------------------------
// Universe: DISTINCT test cases linked to the plan, with the version numbers
// the plan pins. A plan can pin several versions of the same test case (one
// per platform); they all collapse into one row.
// ---------------------------------------------------------------------------
$assignRs = $db->get_recordset(
    "SELECT DISTINCT tpt.tcversion_id, tv.version AS assigned_version, " .
    "       tn.id AS tcase_node_id, tn.name AS tcase_name, " .
    "       tv.tc_external_id " .
    "FROM {$tbl['testplan_tcversions']} tpt " .
    "JOIN {$tbl['tcversions']} tv ON tv.id = tpt.tcversion_id " .
    "JOIN {$tbl['nodes_hierarchy']} vn ON vn.id = tv.id AND vn.node_type_id = 4 " .
    "JOIN {$tbl['nodes_hierarchy']} tn ON tn.id = vn.parent_id AND tn.node_type_id = 3 " .
    "WHERE tpt.testplan_id = " . intval($tplanId) .
    " ORDER BY tn.name, tv.version");

$cases = array();
$assignedVersionIds = array();
if (is_array($assignRs)) {
    foreach ($assignRs as $r) {
        $nodeId = intval($r['tcase_node_id']);
        if ($nodeId <= 0) {
            continue;
        }
        if (!isset($cases[$nodeId])) {
            $cases[$nodeId] = array(
                'tcase_node_id' => $nodeId,
                'tcase_name' => vcDecode($r['tcase_name']),
                'external_id' => intval($r['tc_external_id']),
                'assigned_versions' => array(),
                'assigned_version_ids' => array(),
            );
        }
        $ver = intval($r['assigned_version']);
        $cases[$nodeId]['assigned_versions'][$ver] = $ver;
        $cases[$nodeId]['assigned_version_ids'][] = intval($r['tcversion_id']);
        $assignedVersionIds[] = intval($r['tcversion_id']);
        if ($cases[$nodeId]['external_id'] <= 0 && intval($r['tc_external_id']) > 0) {
            $cases[$nodeId]['external_id'] = intval($r['tc_external_id']);
        }
    }
}
$assignedVersionIds = array_values(array_unique($assignedVersionIds));
$caseNodeIds = array_keys($cases);

// ---------------------------------------------------------------------------
// Full design history of every involved test case: total versions, newest
// version, versions created inside the window, last design change.
// ---------------------------------------------------------------------------
$history = array();
$suitePaths = array();
if (count($caseNodeIds) > 0) {
    $nodeInList = implode(',', array_map('intval', $caseNodeIds));

    $histRs = $db->get_recordset(
        "SELECT vn.parent_id AS tcase_node_id, tv.id AS tcversion_id, " .
        "       tv.version, tv.creation_ts, tv.modification_ts " .
        "FROM {$tbl['tcversions']} tv " .
        "JOIN {$tbl['nodes_hierarchy']} vn ON vn.id = tv.id AND vn.node_type_id = 4 " .
        "WHERE vn.parent_id IN (" . $nodeInList . ")");
    if (is_array($histRs)) {
        foreach ($histRs as $r) {
            $nodeId = intval($r['tcase_node_id']);
            if (!isset($history[$nodeId])) {
                $history[$nodeId] = array(
                    'total_versions' => 0,
                    'newest_version' => 0,
                    'versions_in_window' => 0,
                    'last_design_ts' => '',
                );
            }
            $history[$nodeId]['total_versions']++;
            $ver = intval($r['version']);
            if ($ver > $history[$nodeId]['newest_version']) {
                $history[$nodeId]['newest_version'] = $ver;
            }
            $created = (string)$r['creation_ts'];
            $modified = (string)$r['modification_ts'];
            $changed = ($modified !== '' && $modified > $created) ? $modified : $created;
            if ($changed > $history[$nodeId]['last_design_ts']) {
                $history[$nodeId]['last_design_ts'] = $changed;
            }
            // Window membership is decided in SQL-free PHP to keep the
            // boundary deterministic across locales/timezones.
            $createdTs = strtotime($created);
            if ($createdTs !== false && $createdTs >= (time() - ($windowDays * 86400))) {
                $history[$nodeId]['versions_in_window']++;
            }
        }
    }

    $pathSql =
        "WITH RECURSIVE anc AS (" .
        "  SELECT tn.id AS tcase_node, sn.id AS node_id, sn.name AS node_name, " .
        "         sn.parent_id, 0 AS depth " .
        "  FROM {$tbl['nodes_hierarchy']} tn " .
        "  JOIN {$tbl['nodes_hierarchy']} sn ON sn.id = tn.parent_id AND sn.node_type_id = 2 " .
        "  WHERE tn.id IN (" . $nodeInList . ")" .
        "  UNION ALL " .
        "  SELECT a.tcase_node, pn.id, pn.name, pn.parent_id, a.depth + 1 " .
        "  FROM anc a " .
        "  JOIN {$tbl['nodes_hierarchy']} pn ON pn.id = a.parent_id AND pn.node_type_id = 2" .
        "  WHERE a.depth < 32" .
        ") " .
        "SELECT tcase_node, GROUP_CONCAT(node_name ORDER BY depth DESC SEPARATOR ' / ') AS suite_path " .
        "FROM (SELECT DISTINCT tcase_node, node_name, depth FROM anc) d " .
        "GROUP BY tcase_node";
    $pathRs = $db->get_recordset($pathSql);
    if (is_array($pathRs)) {
        foreach ($pathRs as $r) {
            $suitePaths[intval($r['tcase_node'])] = vcDecode($r['suite_path']);
        }
    }
}

// ---------------------------------------------------------------------------
// Latest execution of any ASSIGNED version inside the plan (informational).
// ---------------------------------------------------------------------------
$lastExec = array();
if (count($assignedVersionIds) > 0) {
    $verInList = implode(',', array_map('intval', $assignedVersionIds));
    $execRs = $db->get_recordset(
        "SELECT e.tcversion_id, MAX(e.execution_ts) AS last_ts " .
        "FROM {$tbl['executions']} e " .
        "WHERE e.testplan_id = " . intval($tplanId) .
        "  AND e.tcversion_id IN (" . $verInList . ") " .
        "  AND e.execution_ts IS NOT NULL " .
        "GROUP BY e.tcversion_id");
    if (is_array($execRs)) {
        foreach ($execRs as $r) {
            $lastExec[intval($r['tcversion_id'])] = (string)$r['last_ts'];
        }
    }
}

// ---------------------------------------------------------------------------
// Project prefix (external full id) - same helper contract as the siblings.
// ---------------------------------------------------------------------------
$prefixRs = $db->get_recordset(
    "SELECT prefix FROM {$tbl['testprojects']} WHERE id = " . intval($owningProjectId));
$prefix = (is_array($prefixRs) && count($prefixRs) > 0) ? (string)$prefixRs[0]['prefix'] : '';

// ---------------------------------------------------------------------------
// Rows + KPIs.
// ---------------------------------------------------------------------------
$rows = array();
$summary = array(
    'window_days' => $windowDays,
    'cases' => count($cases),
    'assignments' => count($assignedVersionIds),
    'total_versions' => 0,
    'avg_versions' => 0.0,
    'reworked_cases' => 0,
    'stable_cases' => 0,
    'watch_cases' => 0,
    'churning_cases' => 0,
    'high_churn' => 0,
    'versions_in_window' => 0,
    'behind_cases' => 0,
);

foreach ($cases as $nodeId => $c) {
    $h = $history[$nodeId] ?? array('total_versions' => 0, 'newest_version' => 0,
        'versions_in_window' => 0, 'last_design_ts' => '');
    $totalVersions = intval($h['total_versions']);
    $newest = intval($h['newest_version']);
    $assignedLatest = count($c['assigned_versions']) > 0
        ? max(array_values($c['assigned_versions'])) : 0;
    $assignedCount = count($c['assigned_versions']);
    $rework = max(0, $totalVersions - 1);
    $inWindow = intval($h['versions_in_window']);
    $behind = ($newest > $assignedLatest) ? ($newest - $assignedLatest) : 0;

    if ($inWindow >= 2 || $rework >= 3) {
        $verdict = 'churning';
        $summary['churning_cases']++;
        $summary['high_churn']++;
    } elseif ($inWindow >= 1 || $rework >= 1 || $behind > 0) {
        $verdict = 'watch';
        $summary['watch_cases']++;
    } else {
        $verdict = 'stable';
        $summary['stable_cases']++;
    }

    if ($rework > 0) {
        $summary['reworked_cases']++;
    }
    if ($behind > 0) {
        $summary['behind_cases']++;
    }
    $summary['total_versions'] += $totalVersions;
    $summary['versions_in_window'] += $inWindow;

    $versLabels = array_values($c['assigned_versions']);
    sort($versLabels, SORT_NUMERIC);

    $lastExecTs = '';
    foreach ($c['assigned_version_ids'] as $tcvId) {
        if (isset($lastExec[$tcvId]) && $lastExec[$tcvId] > $lastExecTs) {
            $lastExecTs = $lastExec[$tcvId];
        }
    }

    $rows[] = array(
        'tcase_node_id' => intval($nodeId),
        'tcase_name' => $c['tcase_name'],
        'external_id' => intval($c['external_id']),
        'external_full' => ($c['external_id'] > 0
            ? (($prefix !== '' ? $prefix . '-' : '') . intval($c['external_id']))
            : ''),
        'suite_path' => $suitePaths[$nodeId] ?? '',
        'assigned_versions' => $versLabels,
        'assigned_latest' => $assignedLatest,
        'assigned_version_count' => $assignedCount,
        'newest_version' => $newest,
        'total_versions' => $totalVersions,
        'rework' => $rework,
        'versions_in_window' => $inWindow,
        'behind' => $behind,
        'last_design_ts' => $h['last_design_ts'],
        'last_execution_ts' => $lastExecTs,
        'verdict' => $verdict,
    );
}

$summary['avg_versions'] = ($summary['cases'] > 0)
    ? vcRound($summary['total_versions'] / $summary['cases'], 2)
    : 0.0;

// Worst churn first: churning > watch > stable, then rework desc, then name.
$rank = array('churning' => 0, 'watch' => 1, 'stable' => 2);
usort($rows, function ($a, $b) use ($rank) {
    $ra = $rank[$a['verdict']] ?? 3;
    $rb = $rank[$b['verdict']] ?? 3;
    if ($ra !== $rb) {
        return ($ra < $rb) ? -1 : 1;
    }
    if ($a['rework'] !== $b['rework']) {
        return ($a['rework'] > $b['rework']) ? -1 : 1;
    }
    return strcmp($a['tcase_name'], $b['tcase_name']);
});

// ---------------------------------------------------------------------------
// Per-suite breakdown: cases / total versions / rework / churning cases.
// ---------------------------------------------------------------------------
$perSuite = array();
foreach ($rows as $r) {
    $sp = ($r['suite_path'] !== '') ? $r['suite_path'] : '(no suite)';
    if (!isset($perSuite[$sp])) {
        $perSuite[$sp] = array('suite' => $sp, 'cases' => 0, 'versions' => 0,
            'rework' => 0, 'churning' => 0);
    }
    $perSuite[$sp]['cases']++;
    $perSuite[$sp]['versions'] += $r['total_versions'];
    $perSuite[$sp]['rework'] += $r['rework'];
    if ($r['verdict'] === 'churning') {
        $perSuite[$sp]['churning']++;
    }
}
$suiteRows = array();
foreach ($perSuite as $s) {
    $suiteRows[] = array(
        'suite' => $s['suite'],
        'cases' => $s['cases'],
        'versions' => $s['versions'],
        'rework' => $s['rework'],
        'churning' => $s['churning'],
        'avg_versions' => ($s['cases'] > 0) ? vcRound($s['versions'] / $s['cases'], 2) : 0.0,
    );
}
usort($suiteRows, function ($a, $b) {
    if ($a['rework'] !== $b['rework']) {
        return ($a['rework'] > $b['rework']) ? -1 : 1;
    }
    return strcmp($a['suite'], $b['suite']);
});

// Verdict precedence: no_data > churning > watch > stable.
$verdict = 'no_data';
if ($summary['cases'] > 0) {
    if ($summary['churning_cases'] > 0) {
        $verdict = 'churning';
    } elseif ($summary['watch_cases'] > 0) {
        $verdict = 'watch';
    } else {
        $verdict = 'stable';
    }
}

vcOut(array(
    'status' => 'ok',
    'code' => 200,
    'context' => $context,
    'filters' => $filters,
    'filter' => array('window_days' => $windowDays),
    'summary' => $summary,
    'verdict' => $verdict,
    'rows' => $rows,
    'suites' => $suiteRows,
));
