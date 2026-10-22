<?php
/**
 * Test Specification Health report - BFF (Refs #1956).
 *
 * Net-new modern screen (gui/templates/testcases/tcSpecHealth.html).
 *
 * TestLink 1.9.20 had no screen that answered "how healthy is my test
 * specification?". The only related thing was the *Duplicate Name Check*
 * (lib/testcases/nameCheck.php), a helper that asks "does this name already
 * exist?" for ONE case while it is being created. Nothing ever showed the
 * project-wide picture: which test cases are duplicated, which have no
 * summary, which are not linked to any requirement and which were never
 * executed at all.
 *
 * This BFF answers that question for ONE test project. The unit is the
 * TEST CASE (not the version): a case is listed once, with the list of the
 * health checks it fails.
 *
 * The checks (documented here because the screen relies on them):
 *   - duplicate_name  : another test case of the SAME project carries the same
 *                       name (comparison is case-insensitive, trimmed and
 *                       whitespace-collapsed). The group is computed over the
 *                       whole project, so two cases of different suites that
 *                       share a name are reported as well.
 *   - empty_summary   : the summary of the LATEST version of the case is
 *                       empty (a case without any version is not flagged).
 *   - no_requirement  : no requirement is linked to any version of the case.
 *   - never_executed  : no execution row exists for any version of the case,
 *                       in any test plan of the project.
 *   - stale           : the case HAS been executed but the last execution is
 *                       older than the configured window (default 90 days).
 *
 * Actions
 *   GET|HEAD ?action=init&tproject_id=N
 *       -> context (project / prefix) + the suite filter + the check catalogue
 *   GET|HEAD ?action=report&tproject_id=N[&suite_id=S][&check=C][&stale_days=D]
 *       -> summary + the per-test-case rows (only the cases with an issue)
 *
 * Security: session authentication, the shared BFF same-origin guard and the
 * `mgt_view_tc` right on the addressed test project (getAccess = true, so a
 * global-only right does not reach a PRIVATE project). The right is checked
 * BEFORE the project is resolved, so a caller without the right cannot use
 * the answer to learn whether the project exists (the #1697 no-oracle rule).
 * The endpoint is read-only: it never writes.
 */
require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
require_once('testproject.class.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

/** Single JSON exit point. */
function tsOut($payload, $code = null)
{
    if ($code !== null) {
        http_response_code($code);
    }
    echo json_encode($payload);
    exit;
}

function tsFail($httpCode, $code, $message = '')
{
    tsOut(array(
        'status' => 'error',
        'code' => $code,
        'message' => $message !== '' ? $message : $code,
    ), $httpCode);
}

/** Strict positive integer reader - "1abc", "1.9", "-3" and "" are all refused. */
function tsPositiveInt($raw)
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

function tsDecode($value)
{
    return html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/** Name comparison key: trimmed, whitespace collapsed, case-insensitive. */
function tsNormName($name)
{
    $n = preg_replace('/\s+/u', ' ', trim((string)$name));
    if ($n === null) {
        $n = trim((string)$name);
    }
    return mb_strtolower($n, 'UTF-8');
}

function tsPct($num, $den)
{
    if ($den <= 0) {
        return 0.0;
    }
    return round(100.0 * $num / $den, 1);
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    tsFail(405, 'method_not_allowed');
}

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    tsFail(401, 'not_authenticated', 'Not authenticated');
}

bffEnforceSession($db);

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    tsFail(401, 'not_authenticated', 'User not found');
}

$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if ($action !== 'init' && $action !== 'report') {
    tsFail(400, 'invalid_request', 'Unknown action');
}

$tprojectId = tsPositiveInt($_GET['tproject_id'] ?? null);
if ($tprojectId <= 0) {
    // The screen always sends the project; a hand-typed deep link may not, so
    // the session project is the fallback (the same fallback the legacy
    // controllers used).
    $tprojectId = tsPositiveInt($_SESSION['testprojectID'] ?? null);
}
if ($tprojectId <= 0) {
    tsFail(400, 'invalid_request', 'Missing or invalid tproject_id');
}

// The right is checked on the ADDRESSED project BEFORE the project is
// resolved: an unauthorized caller gets the same answer (403 no_right) for an
// existing and for a non-existing project, so the endpoint is not an
// existence oracle (the #1697 lesson).
if (!$user->hasRight($db, 'mgt_view_tc', $tprojectId, 0, true)) {
    tLog('BFF tcspechealth: user ' . intval($userId) .
         ' has no mgt_view_tc right on tproject ' . $tprojectId, 'ERROR');
    tsFail(403, 'no_right', 'mgt_view_tc right required');
}

$tbl = tlObjectWithDB::getDBTables(array(
    'testprojects', 'nodes_hierarchy', 'tcversions', 'executions', 'req_coverage',
));

$projRs = $db->get_recordset(
    "SELECT p.id, p.prefix, n.name AS project_name " .
    "FROM {$tbl['testprojects']} p " .
    "JOIN {$tbl['nodes_hierarchy']} n ON n.id = p.id " .
    "WHERE p.id = " . intval($tprojectId));
if (!is_array($projRs) || count($projRs) === 0) {
    tsFail(404, 'tproject_not_found', 'Test project not found');
}
$project = $projRs[0];
$projectName = tsDecode($project['project_name']);
$projectPrefix = (string)$project['prefix'];

$context = array(
    'tproject_id' => intval($tprojectId),
    'tproject_name' => $projectName,
    'prefix' => $projectPrefix,
);

// ---------------------------------------------------------------------------
// The tree of the project: test suites (node_type_id 2) and test cases
// (node_type_id 3). 2.0.1 dropped the testproject_id column from
// nodes_hierarchy, so the ownership is PROVED by walking parent_id up from
// the project node.
// ---------------------------------------------------------------------------
$treeSql =
    "WITH RECURSIVE tree AS (" .
    "  SELECT n.id, n.name, n.parent_id, n.node_type_id, 0 AS depth " .
    "  FROM {$tbl['nodes_hierarchy']} n " .
    "  WHERE n.parent_id = " . intval($tprojectId) . " AND n.node_type_id IN (2,3) " .
    "  UNION ALL " .
    "  SELECT c.id, c.name, c.parent_id, c.node_type_id, t.depth + 1 " .
    "  FROM {$tbl['nodes_hierarchy']} c " .
    "  JOIN tree t ON c.parent_id = t.id AND c.node_type_id IN (2,3) " .
    "  WHERE t.depth < 32" .
    ") SELECT id, name, parent_id, node_type_id, depth FROM tree";

$suites = array();   // suite id => array(name, parent)
$cases = array();    // case id  => array(name, suite)
$treeRs = $db->get_recordset($treeSql);
if (is_array($treeRs)) {
    foreach ($treeRs as $r) {
        $id = intval($r['id']);
        $nodeType = intval($r['node_type_id']);
        if ($nodeType === 2) {
            $suites[$id] = array(
                'name' => tsDecode($r['name']),
                'parent' => intval($r['parent_id']),
            );
        } elseif ($nodeType === 3) {
            $cases[$id] = array(
                'name' => tsDecode($r['name']),
                'suite' => intval($r['parent_id']),
            );
        }
    }
}

// The suite path of every suite (memoised walk up the parent chain).
$suitePath = array();
$tsWalk = function ($id) use (&$suitePath, &$suites) {
    if (isset($suitePath[$id])) {
        return $suitePath[$id];
    }
    $parts = array();
    $cur = $id;
    $guard = 0;
    while ($cur > 0 && isset($suites[$cur]) && $guard < 32) {
        $parts[] = $suites[$cur]['name'];
        $cur = $suites[$cur]['parent'];
        $guard++;
    }
    $path = implode(' / ', array_reverse($parts));
    $suitePath[$id] = $path;
    return $path;
};

$suiteList = array();
foreach ($suites as $id => $s) {
    $suiteList[] = array(
        'id' => $id,
        'name' => $s['name'],
        'path' => $tsWalk($id),
    );
}
usort($suiteList, function ($a, $b) {
    return strcasecmp($a['path'], $b['path']);
});

$checkCatalogue = array(
    'duplicate_name' => array('severity' => 'high'),
    'never_executed' => array('severity' => 'high'),
    'no_requirement' => array('severity' => 'medium'),
    'empty_summary'  => array('severity' => 'medium'),
    'stale'          => array('severity' => 'low'),
);
$severityRank = array('high' => 0, 'medium' => 1, 'low' => 2);

$filters = array(
    'suites' => $suiteList,
    'checks' => array_keys($checkCatalogue),
);

if ($action === 'init') {
    tsOut(array(
        'status' => 'ok',
        'code' => 200,
        'context' => $context,
        'filters' => $filters,
        'limits' => array('stale_days' => 90),
    ));
}

// ---------------------------------------------------------------------------
// report
// ---------------------------------------------------------------------------
$suiteId = tsPositiveInt($_GET['suite_id'] ?? null);
$checkFilter = isset($_GET['check']) && !is_array($_GET['check'])
    ? strtolower(trim((string)$_GET['check'])) : '';
if ($checkFilter === '' || $checkFilter === 'all' || $checkFilter === '0') {
    $checkFilter = '';
}
if ($checkFilter !== '' && !isset($checkCatalogue[$checkFilter])) {
    tsFail(400, 'invalid_request', 'Unknown check');
}
if ($suiteId > 0 && !isset($suites[$suiteId])) {
    tsFail(400, 'invalid_request', 'suite_id does not belong to this test project');
}

$staleDays = 90;
if (isset($_GET['stale_days']) && !is_array($_GET['stale_days'])) {
    $raw = trim((string)$_GET['stale_days']);
    if ($raw === '' || !preg_match('/^[0-9]+$/', $raw)) {
        tsFail(400, 'invalid_request', 'Invalid stale_days');
    }
    $staleDays = intval($raw);
    if ($staleDays < 1 || $staleDays > 3650) {
        tsFail(400, 'invalid_request', 'stale_days out of range');
    }
}

// The scope: every test case of the project, or only the ones of one suite.
$scope = array();
foreach ($cases as $id => $c) {
    if ($suiteId > 0 && $c['suite'] !== $suiteId) {
        continue;
    }
    $scope[$id] = $c;
}
$scopeIds = array_keys($scope);

// ---------------------------------------------------------------------------
// The versions of the scoped test cases.
// ---------------------------------------------------------------------------
$versions = array();        // tcversion id => row
$caseVersions = array();    // case id => array of tcversion ids (ordered)
$latestVersion = array();   // case id => tcversion id
$externalId = array();      // case id => tc_external_id of the latest version
if (count($scopeIds) > 0) {
    $inCases = implode(',', array_map('intval', $scopeIds));
    $verRs = $db->get_recordset(
        "SELECT vn.id AS tcversion_id, vn.parent_id AS tcase_id, tv.tc_external_id, " .
        "       tv.version, tv.summary, tv.is_open, tv.active " .
        "FROM {$tbl['nodes_hierarchy']} vn " .
        "JOIN {$tbl['tcversions']} tv ON tv.id = vn.id " .
        "WHERE vn.node_type_id = 4 AND vn.parent_id IN (" . $inCases . ") " .
        "ORDER BY tv.version, vn.id");
    if (is_array($verRs)) {
        foreach ($verRs as $r) {
            $tvid = intval($r['tcversion_id']);
            $cid = intval($r['tcase_id']);
            $versions[$tvid] = array(
                'version' => intval($r['version']),
                'summary' => (string)$r['summary'],
                'external_id' => intval($r['tc_external_id']),
                'is_open' => intval($r['is_open']),
                'active' => intval($r['active']),
            );
            $caseVersions[$cid][] = $tvid;
            $latestVersion[$cid] = $tvid;
            $externalId[$cid] = intval($r['tc_external_id']);
        }
    }
}

// ---------------------------------------------------------------------------
// The executions and the requirement links of the scoped cases (chunked, so a
// huge specification cannot blow the max_allowed_packet of the IN list).
// ---------------------------------------------------------------------------
$versionIds = array_keys($versions);
$execCount = array();   // tcversion id => number of executions
$lastTs = array();      // tcversion id => last execution timestamp
$reqActive = array();   // tcversion id => number of ACTIVE requirement links
$reqAny = array();      // tcversion id => number of requirement links (any state)

$chunks = array_chunk($versionIds, 500);
foreach ($chunks as $chunk) {
    $in = implode(',', array_map('intval', $chunk));

    $execRs = $db->get_recordset(
        "SELECT tcversion_id, COUNT(*) AS exec_count, MAX(execution_ts) AS last_ts " .
        "FROM {$tbl['executions']} WHERE tcversion_id IN (" . $in . ") GROUP BY tcversion_id");
    if (is_array($execRs)) {
        foreach ($execRs as $r) {
            $tvid = intval($r['tcversion_id']);
            $execCount[$tvid] = intval($r['exec_count']);
            $lastTs[$tvid] = (string)$r['last_ts'];
        }
    }

    $reqRs = $db->get_recordset(
        "SELECT tcversion_id, " .
        "       SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_links, " .
        "       COUNT(DISTINCT req_id) AS any_links " .
        "FROM {$tbl['req_coverage']} WHERE tcversion_id IN (" . $in . ") GROUP BY tcversion_id");
    if (is_array($reqRs)) {
        foreach ($reqRs as $r) {
            $tvid = intval($r['tcversion_id']);
            $reqActive[$tvid] = intval($r['active_links']);
            $reqAny[$tvid] = intval($r['any_links']);
        }
    }
}

// ---------------------------------------------------------------------------
// The duplicate name groups (the comparison key is computed in PHP so it is
// independent of the SQL collation and of the locale). The groups are built
// over the WHOLE project: two cases of different suites that share a name
// are a duplicate, no matter which suite is selected in the filter.
// ---------------------------------------------------------------------------
$nameGroups = array();
foreach ($cases as $id => $c) {
    $key = tsNormName($c['name']);
    if ($key === '') {
        continue;
    }
    if (!isset($nameGroups[$key])) {
        $nameGroups[$key] = array();
    }
    $nameGroups[$key][] = $id;
}
$duplicateOf = array();   // case id => number of cases sharing its name
$duplicateGroups = 0;
foreach ($nameGroups as $members) {
    if (count($members) > 1) {
        $duplicateGroups++;
        foreach ($members as $id) {
            $duplicateOf[$id] = count($members);
        }
    }
}

// ---------------------------------------------------------------------------
// The rows: one per test case that fails at least one check.
// ---------------------------------------------------------------------------
$rows = array();
$summary = array(
    'total_cases' => count($scope),
    'cases_with_issues' => 0,
    'issue_rate_pct' => 0.0,
    'duplicate_groups' => 0,
    'duplicate_cases' => 0,
    'empty_summary' => 0,
    'no_requirement' => 0,
    'never_executed' => 0,
    'stale' => 0,
);

$staleBefore = time() - ($staleDays * 86400);

foreach ($scope as $id => $c) {
    $checks = array();

    if (isset($duplicateOf[$id])) {
        $checks[] = 'duplicate_name';
    }

    $latestId = $latestVersion[$id] ?? 0;
    $summaryText = '';
    if ($latestId > 0) {
        $summaryText = trim((string)$versions[$latestId]['summary']);
    }
    if ($latestId > 0 && $summaryText === '') {
        $checks[] = 'empty_summary';
    }

    $caseExec = 0;
    $caseLast = '';
    $caseReq = 0;
    foreach ($caseVersions[$id] ?? array() as $tvid) {
        if (isset($execCount[$tvid])) {
            $caseExec += $execCount[$tvid];
        }
        if (isset($lastTs[$tvid]) && $lastTs[$tvid] !== '') {
            if ($caseLast === '' || strcmp($lastTs[$tvid], $caseLast) > 0) {
                $caseLast = $lastTs[$tvid];
            }
        }
        $links = $reqActive[$tvid] ?? 0;
        if ($links <= 0) {
            $links = $reqAny[$tvid] ?? 0;
        }
        $caseReq += $links;
    }

    if ($caseReq <= 0) {
        $checks[] = 'no_requirement';
    }
    if ($caseExec <= 0) {
        $checks[] = 'never_executed';
    } elseif ($caseLast !== '' && strtotime($caseLast) < $staleBefore) {
        $checks[] = 'stale';
    }

    if (count($checks) === 0) {
        continue;
    }
    if ($checkFilter !== '' && !in_array($checkFilter, $checks, true)) {
        continue;
    }

    $severity = 'low';
    foreach ($checks as $k) {
        $sev = $checkCatalogue[$k]['severity'];
        if ($severityRank[$sev] < $severityRank[$severity]) {
            $severity = $sev;
        }
    }

    $ext = intval($externalId[$id] ?? 0);
    $rows[] = array(
        'tcase_id' => $id,
        'tcase_name' => $c['name'],
        'external_id' => ($projectPrefix !== '' && $ext > 0)
            ? ($projectPrefix . '-' . $ext)
            : (string)$ext,
        'suite_path' => $tsWalk($c['suite']),
        'version' => $latestId > 0 ? intval($versions[$latestId]['version']) : 0,
        'version_id' => $latestId,
        'checks' => $checks,
        'severity' => $severity,
        'executions' => $caseExec,
        'last_ts' => $caseLast,
        'requirements' => $caseReq,
    );

    foreach ($checks as $k) {
        switch ($k) {
            case 'duplicate_name':  $summary['duplicate_cases']++; break;
            case 'empty_summary':   $summary['empty_summary']++; break;
            case 'no_requirement':  $summary['no_requirement']++; break;
            case 'never_executed':  $summary['never_executed']++; break;
            case 'stale':           $summary['stale']++; break;
        }
    }
    $summary['cases_with_issues']++;
}

// The duplicate groups are counted over the WHOLE project (not only over the
// selected suite), so the tile does not change when a suite is filtered.
$summary['duplicate_groups'] = $duplicateGroups;

usort($rows, function ($a, $b) use ($severityRank) {
    $sa = $severityRank[$a['severity']] ?? 9;
    $sb = $severityRank[$b['severity']] ?? 9;
    if ($sa !== $sb) {
        return $sa - $sb;
    }
    if (count($a['checks']) !== count($b['checks'])) {
        return count($b['checks']) - count($a['checks']);
    }
    return strcasecmp($a['tcase_name'], $b['tcase_name']);
});

$summary['issue_rate_pct'] = tsPct($summary['cases_with_issues'], $summary['total_cases']);

tsOut(array(
    'status' => 'ok',
    'code' => 200,
    'context' => $context,
    'filters' => $filters,
    'filter' => array(
        'suite_id' => $suiteId,
        'check' => $checkFilter,
        'stale_days' => $staleDays,
    ),
    'summary' => $summary,
    'rows' => $rows,
));
