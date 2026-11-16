<?php
/**
 * Test Plan Keyword Coverage - BFF (Refs #1995).
 *
 * Net-new read-only report (gui/templates/results/keywordCoverage.html).
 *
 * WHY IT EXISTS
 * -------------
 * TestLink 1.9.20 shipped only a stacked-bar PNG (lib/results/keywordBarChart.php,
 * modern twin api/keywordbarchart) that counted execution statuses per keyword.
 * It never showed the COVERAGE of a keyword (executed / assigned), never showed
 * the pass rate, never listed the project keywords that have NO test case
 * assigned to the plan ("gap"), and never surfaced the plan-assigned test cases
 * that carry no keyword at all ("untagged"). This report answers those manager
 * questions for one test plan.
 *
 * SEMANTICS
 * ---------
 *   - the unit is a test-case VERSION assigned to the plan
 *     (`testplan_tcversions.tcversion_id`, deduplicated across platform pins);
 *     an optional `platform_id` narrows the assignment set and also accepts
 *     `platform_id = 0` ("all platforms") rows;
 *   - keywords are the testproject keywords linked through `testcase_keywords`
 *     (test-case level, so a keyword applies to every assigned version of the
 *     test case); a version with several keywords is counted under EACH one;
 *   - the latest result is the newest `executions.id` for the (version) inside
 *     the plan, optionally narrowed by `build_id` / `platform_id`;
 *     `p` = passed, `f` = failed, `b` = blocked, no row = not run;
 *   - `coverage_pct` = executed / cases; `pass_pct` = passed / executed;
 *   - `gap` = a project keyword with zero plan-assigned versions;
 *   - `untagged` = plan-assigned versions whose test case has no keyword.
 * Plan verdict precedence: no_data (no assignments) > no_keywords (project has
 * no keywords) > at_risk (coverage < 50 or pass < 80) > watch (coverage < 80
 * or pass < 90) > ready.
 *
 * SECURITY
 * --------
 * Session authentication, the shared BFF same-origin guard, and the
 * `testplan_metrics` right on the OWNING test project (proved from the plan).
 * The plan is resolved first (opaque 404), the right is checked on its owning
 * project BEFORE the client `tproject_id` assertion is honoured, and denials
 * are logged at AUDIT (#1697 no-oracle convention).
 *
 * Contract: GET|HEAD
 *   ?action=init&tplan_id=N[&tproject_id=P]
 *        -> context, builds, platforms, assigned/keyword counters
 *   ?action=report&tplan_id=N[&tproject_id=P][&build_id=B][&platform_id=PL]
 *                   [&keyword_id=K]
 *        -> summary + one row per keyword + untagged list
 *   400 invalid_request | 401 not_authenticated/session_expired
 *   403 no_right | 404 tplan_not_found/project_mismatch | 405 method_not_allowed
 */
require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function kwcOut($payload, $code = null)
{
    if ($code !== null) {
        http_response_code($code);
    }
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function kwcFail($httpCode, $code, $message = '')
{
    kwcOut(array(
        'status' => 'error',
        'code' => $code,
        'message' => $message !== '' ? $message : $code,
    ), $httpCode);
}

function kwcInt($raw)
{
    if ($raw === null || is_array($raw)) {
        return 0;
    }
    $raw = trim((string)$raw);
    if ($raw === '' || !preg_match('/^[0-9]+$/', $raw)) {
        return 0;
    }
    return intval($raw);
}

function kwcDecode($value)
{
    return html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function kwcPct($part, $total)
{
    return ($total > 0) ? round(100.0 * $part / $total, 1) : 0.0;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    kwcFail(405, 'method_not_allowed');
}

$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if ($action !== 'init' && $action !== 'report') {
    kwcFail(400, 'invalid_request', 'Unknown action');
}

$tplanId = kwcInt($_GET['tplan_id'] ?? '');
if ($tplanId <= 0) {
    kwcFail(400, 'invalid_request', 'Missing tplan_id');
}

$rawAsserted = trim((string)($_GET['tproject_id'] ?? ''));
$assertedProject = kwcInt($rawAsserted);
if ($rawAsserted !== '' && $assertedProject <= 0) {
    kwcFail(400, 'invalid_request', 'Invalid tproject_id');
}

$buildId = kwcInt($_GET['build_id'] ?? '');
$platformId = kwcInt($_GET['platform_id'] ?? '');
$keywordFilter = kwcInt($_GET['keyword_id'] ?? '');

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    kwcFail(401, 'not_authenticated', 'Not authenticated');
}

bffEnforceSession($db);

$user = tlUser::getByID($db, intval($userId));
if (is_null($user)) {
    kwcFail(401, 'not_authenticated', 'User not found');
}

$tbl = tlObjectWithDB::getDBTables(array(
    'testprojects', 'testplans', 'nodes_hierarchy', 'testplan_tcversions',
    'tcversions', 'keywords', 'testcase_keywords', 'executions', 'builds',
    'platforms',
));

// ---------------------------------------------------------------------------
// Resolve the plan FIRST (opaque 404: no existence oracle for the project).
// ---------------------------------------------------------------------------
$planRow = $db->get_recordset(
    "SELECT tp.id, nh.name, tp.testproject_id" .
    " FROM {$tbl['testplans']} tp" .
    " JOIN {$tbl['nodes_hierarchy']} nh ON nh.id = tp.id" .
    " WHERE tp.id = " . intval($tplanId));
if (!is_array($planRow) || count($planRow) === 0) {
    kwcFail(404, 'tplan_not_found', 'Test plan not found');
}
$owningProject = intval($planRow[0]['testproject_id']);
$planName = kwcDecode($planRow[0]['name']);

// Rights on the OWNING project, before honouring the client assertion.
if (!$user->hasRight($db, 'testplan_metrics', $owningProject, null, true)) {
    tLog('BFF keywordcoverage: user ' . intval($userId) .
         ' has no testplan_metrics right on tproject ' . intval($owningProject), 'AUDIT');
    kwcFail(403, 'no_right', 'testplan_metrics right required');
}

if ($assertedProject > 0 && $assertedProject !== $owningProject) {
    kwcFail(404, 'project_mismatch', 'The test plan does not belong to that test project');
}

$projectRow = $db->get_recordset(
    "SELECT nh.name FROM {$tbl['testprojects']} tp" .
    " JOIN {$tbl['nodes_hierarchy']} nh ON nh.id = tp.id" .
    " WHERE tp.id = " . intval($owningProject));
$projectName = (is_array($projectRow) && count($projectRow) > 0)
    ? kwcDecode($projectRow[0]['name']) : '';

$context = array(
    'tplan_id' => $tplanId,
    'tplan_name' => $planName,
    'tproject_id' => $owningProject,
    'tproject_name' => $projectName,
);

// ---------------------------------------------------------------------------
// Filters vocabulary (init + report).
// ---------------------------------------------------------------------------
$builds = array();
$bRs = $db->get_recordset(
    "SELECT id, name, active, is_open FROM {$tbl['builds']}" .
    " WHERE testproject_id = " . intval($owningProject) . " ORDER BY name");
if (is_array($bRs)) {
    foreach ($bRs as $b) {
        $builds[] = array(
            'build_id' => intval($b['id']),
            'name' => kwcDecode($b['name']),
            'active' => intval($b['active']),
            'is_open' => intval($b['is_open']),
        );
    }
}

$platforms = array();
$pRs = $db->get_recordset(
    "SELECT id, name, is_open FROM {$tbl['platforms']}" .
    " WHERE testproject_id = " . intval($owningProject) . " ORDER BY name");
if (is_array($pRs)) {
    foreach ($pRs as $p) {
        $platforms[] = array(
            'platform_id' => intval($p['id']),
            'name' => kwcDecode($p['name']),
            'is_open' => intval($p['is_open']),
        );
    }
}

// Project keywords.
$keywordList = array();
$kRs = $db->get_recordset(
    "SELECT id, keyword FROM {$tbl['keywords']}" .
    " WHERE testproject_id = " . intval($owningProject) . " ORDER BY keyword");
if (is_array($kRs)) {
    foreach ($kRs as $k) {
        $keywordList[intval($k['id'])] = kwcDecode($k['keyword']);
    }
}

// Plan-assigned versions (deduplicated across platform pins when no platform
// filter is given).
$platformClause = '';
if ($platformId > 0) {
    $platformClause = ' AND tptv.platform_id IN (0,' . intval($platformId) . ')';
}
$versionRows = $db->get_recordset(
    "SELECT DISTINCT tptv.tcversion_id, tvnh.parent_id AS testcase_id" .
    " FROM {$tbl['testplan_tcversions']} tptv" .
    " JOIN {$tbl['tcversions']} tv ON tv.id = tptv.tcversion_id" .
    " JOIN {$tbl['nodes_hierarchy']} tvnh ON tvnh.id = tv.id" .
    " WHERE tptv.testplan_id = " . intval($tplanId) . $platformClause);
$versions = array();      // tcversion_id => testcase_id
if (is_array($versionRows)) {
    foreach ($versionRows as $v) {
        $tcv = intval($v['tcversion_id']);
        if ($tcv > 0) {
            $versions[$tcv] = intval($v['testcase_id']);
        }
    }
}
$assignedVersions = count($versions);

if ($action === 'init') {
    kwcOut(array(
        'status' => 'ok',
        'code' => 200,
        'context' => $context,
        'builds' => $builds,
        'platforms' => $platforms,
        'keywords' => count($keywordList),
        'assigned_versions' => $assignedVersions,
    ));
}

// ---------------------------------------------------------------------------
// report.
// ---------------------------------------------------------------------------
$latest = array(); // tcversion_id => status
if ($assignedVersions > 0) {
    $execWhere = ' WHERE e.testplan_id = ' . intval($tplanId);
    if ($buildId > 0) {
        $execWhere .= ' AND e.build_id = ' . intval($buildId);
    }
    if ($platformId > 0) {
        $execWhere .= ' AND e.platform_id IN (0,' . intval($platformId) . ')';
    }
    $execSql =
        "SELECT e.tcversion_id, e.status FROM {$tbl['executions']} e" .
        " JOIN (SELECT tcversion_id, MAX(id) AS mid FROM {$tbl['executions']} e2" .
        $execWhere . " GROUP BY tcversion_id) x ON x.mid = e.id";
    $eRs = $db->get_recordset($execSql);
    if (is_array($eRs)) {
        foreach ($eRs as $e) {
            $latest[intval($e['tcversion_id'])] = strtolower((string)$e['status']);
        }
    }
}

// Keywords per plan-assigned test case.
$versionKeywords = array(); // tcversion_id => [keyword_id, ...]
$taggedVersions = array();
if ($assignedVersions > 0 && count($keywordList) > 0) {
    $tcIds = array_unique(array_values($versions));
    $tcIds = array_filter($tcIds, function ($v) { return $v > 0; });
    if (count($tcIds) > 0) {
        $tkRs = $db->get_recordset(
            "SELECT DISTINCT tk.testcase_id, tk.keyword_id" .
            " FROM {$tbl['testcase_keywords']} tk" .
            " JOIN {$tbl['keywords']} k ON k.id = tk.keyword_id" .
            " WHERE k.testproject_id = " . intval($owningProject) .
            " AND tk.testcase_id IN (" . implode(',', array_map('intval', $tcIds)) . ")");
        $tcKeywordMap = array();
        if (is_array($tkRs)) {
            foreach ($tkRs as $tk) {
                $tcKeywordMap[intval($tk['testcase_id'])][] = intval($tk['keyword_id']);
            }
        }
        foreach ($versions as $tcv => $tcId) {
            if (!empty($tcKeywordMap[$tcId])) {
                $versionKeywords[$tcv] = array_values(array_unique($tcKeywordMap[$tcId]));
                $taggedVersions[$tcv] = true;
            }
        }
    }
}

// Aggregate per keyword.
$agg = array();
foreach ($keywordList as $kid => $kname) {
    $agg[$kid] = array(
        'keyword_id' => $kid, 'name' => $kname,
        'cases' => 0, 'executed' => 0, 'passed' => 0, 'failed' => 0,
        'blocked' => 0, 'not_run' => 0,
    );
}
$totExecuted = 0; $totPassed = 0; $totFailed = 0; $totBlocked = 0; $totNotRun = 0;
foreach ($versionKeywords as $tcv => $kids) {
    $hasExec = array_key_exists($tcv, $latest);
    $st = $hasExec ? $latest[$tcv] : '';
    $isPass = ($st === 'p');
    $isFail = ($st === 'f');
    $isBlocked = ($st === 'b');
    foreach ($kids as $kid) {
        if (!isset($agg[$kid])) {
            continue;
        }
        $agg[$kid]['cases']++;
        if ($hasExec) { $agg[$kid]['executed']++; }
        if ($isPass) { $agg[$kid]['passed']++; }
        if ($isFail) { $agg[$kid]['failed']++; }
        if ($isBlocked) { $agg[$kid]['blocked']++; }
        if (!$hasExec) { $agg[$kid]['not_run']++; }
    }
    if ($hasExec) { $totExecuted++; }
    if ($isPass) { $totPassed++; }
    if ($isFail) { $totFailed++; }
    if ($isBlocked) { $totBlocked++; }
    if (!$hasExec) { $totNotRun++; }
}

$rows = array();
$keywordsUsed = 0; $keywordsGap = 0;
foreach ($agg as $kid => $a) {
    $a['coverage_pct'] = kwcPct($a['executed'], $a['cases']);
    $a['pass_pct'] = kwcPct($a['passed'], $a['executed']);
    $a['is_gap'] = ($a['cases'] === 0);
    if ($a['is_gap']) { $keywordsGap++; } else { $keywordsUsed++; }
    if ($keywordFilter > 0 && $kid !== $keywordFilter) {
        continue;
    }
    $rows[] = $a;
}

// Attention order: gaps first, then lowest coverage, then name.
usort($rows, function ($a, $b) {
    if ($a['is_gap'] !== $b['is_gap']) {
        return $a['is_gap'] ? -1 : 1;
    }
    if ($a['coverage_pct'] == $b['coverage_pct']) {
        return strcasecmp($a['name'], $b['name']);
    }
    return ($a['coverage_pct'] < $b['coverage_pct']) ? -1 : 1;
});

// Untagged plan-assigned versions.
$untagged = array();
$untaggedCount = 0;
foreach ($versions as $tcv => $tcId) {
    if (isset($taggedVersions[$tcv])) {
        continue;
    }
    $untaggedCount++;
    if (count($untagged) < 100) {
        $st = array_key_exists($tcv, $latest) ? $latest[$tcv] : '';
        $untagged[] = array(
            'tcversion_id' => $tcv,
            'testcase_id' => $tcId,
            'status' => ($st === '' ? 'not_run' : $st),
        );
    }
}
// Resolve untagged test-case names in one query.
if (count($untagged) > 0) {
    $tcaseIds = array();
    foreach ($untagged as $u) {
        if ($u['testcase_id'] > 0) { $tcaseIds[] = $u['testcase_id']; }
    }
    $names = array();
    if (count($tcaseIds) > 0) {
        $nRs = $db->get_recordset(
            "SELECT id, name FROM {$tbl['nodes_hierarchy']}" .
            " WHERE id IN (" . implode(',', array_map('intval', array_unique($tcaseIds))) . ")");
        if (is_array($nRs)) {
            foreach ($nRs as $n) {
                $names[intval($n['id'])] = kwcDecode($n['name']);
            }
        }
    }
    foreach ($untagged as $i => $u) {
        $untagged[$i]['name'] = $names[$u['testcase_id']] ?? '';
    }
}

$summary = array(
    'keywords_total' => count($keywordList),
    'keywords_used' => $keywordsUsed,
    'keywords_gap' => $keywordsGap,
    'assigned_versions' => $assignedVersions,
    'tagged_versions' => count($taggedVersions),
    'untagged_versions' => $untaggedCount,
    'executed' => $totExecuted,
    'passed' => $totPassed,
    'failed' => $totFailed,
    'blocked' => $totBlocked,
    'not_run' => $totNotRun,
    'coverage_pct' => kwcPct($totExecuted, $assignedVersions),
    'pass_pct' => kwcPct($totPassed, $totExecuted),
);

if ($assignedVersions === 0) {
    $summary['verdict'] = 'no_data';
} elseif (count($keywordList) === 0) {
    $summary['verdict'] = 'no_keywords';
} elseif ($summary['coverage_pct'] < 50.0 || $summary['pass_pct'] < 80.0) {
    $summary['verdict'] = 'at_risk';
} elseif ($summary['coverage_pct'] < 80.0 || $summary['pass_pct'] < 90.0) {
    $summary['verdict'] = 'watch';
} else {
    $summary['verdict'] = 'ready';
}

$warnings = array();
if ($assignedVersions === 0) { $warnings[] = 'no_assigned'; }
if (count($keywordList) === 0) { $warnings[] = 'no_keywords'; }
if ($untaggedCount > 0 && count($keywordList) > 0) { $warnings[] = 'untagged'; }
if ($keywordsGap > 0 && count($keywordList) > 0) { $warnings[] = 'gaps'; }

kwcOut(array(
    'status' => 'ok',
    'code' => 200,
    'context' => $context,
    'filters' => array(
        'build_id' => $buildId,
        'platform_id' => $platformId,
        'keyword_id' => $keywordFilter,
    ),
    'summary' => $summary,
    'rows' => $rows,
    'untagged' => $untagged,
    'builds' => $builds,
    'platforms' => $platforms,
    'warnings' => $warnings,
));
