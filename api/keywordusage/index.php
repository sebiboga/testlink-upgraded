<?php
require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
require_once('testproject.class.php');

doSessionStart();
require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function kupOut($payload, $code = null)
{
    if ($code !== null) {
        http_response_code($code);
    }
    echo json_encode($payload);
    exit;
}

function kupFail($httpCode, $code, $message = '')
{
    kupOut(array(
        'status' => 'error',
        'code' => $code,
        'message' => $message !== '' ? $message : $code,
    ), $httpCode);
}

function kupInt($raw)
{
    if ($raw === null || is_array($raw)) {
        return 0;
    }
    $raw = trim((string)$raw);
    if ($raw === '' || !preg_match('/^[0-9]+$/', $raw)) {
        return 0;
    }
    $n = intval($raw);
    return $n > 0 ? $n : 0;
}

function kupDecode($value)
{
    return html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function kupPct($part, $total)
{
    return $total > 0 ? round(100.0 * $part / $total, 1) : 0.0;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    kupFail(405, 'method_not_allowed');
}

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    kupFail(401, 'not_authenticated', 'Not authenticated');
}
bffEnforceSession($db);

$user = tlUser::getByID($db, intval($userId));
if (is_null($user)) {
    kupFail(401, 'not_authenticated', 'User not found');
}

$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if (!in_array($action, array('projects', 'init', 'report', 'detail'), true)) {
    kupFail(400, 'invalid_request', 'Unknown action');
}

$tbl = tlObjectWithDB::getDBTables(array(
    'testprojects', 'nodes_hierarchy', 'keywords', 'testcase_keywords',
    'tcversions', 'executions', 'testplan_tcversions', 'testplans', 'builds',
));

function kupReadableProjects($db, $user, $tables)
{
    $rows = $db->get_recordset(
        "SELECT tp.id, nh.name, tp.prefix FROM {$tables['testprojects']} tp" .
        " JOIN {$tables['nodes_hierarchy']} nh ON nh.id = tp.id" .
        " WHERE tp.active = 1 ORDER BY nh.name");
    $items = array();
    if (is_array($rows)) {
        foreach ($rows as $row) {
            $pid = intval($row['id']);
            if ($pid <= 0 || !$user->hasRight($db, 'mgt_view_tc', $pid, null, true)) {
                continue;
            }
            $items[] = array(
                'id' => $pid,
                'name' => kupDecode($row['name']),
                'prefix' => kupDecode($row['prefix']),
            );
        }
    }
    return $items;
}

if ($action === 'projects') {
    kupOut(array('status' => 'ok', 'code' => 200,
        'projects' => kupReadableProjects($db, $user, $tbl)));
}

$requested = kupInt($_GET['tproject_id'] ?? null);
if (empty($_GET['tproject_id']) === false && $requested <= 0) {
    kupFail(400, 'invalid_request', 'Invalid tproject_id');
}
$projectId = $requested > 0 ? $requested : kupInt($_SESSION['testprojectID'] ?? null);
if ($projectId <= 0) {
    $readable = kupReadableProjects($db, $user, $tbl);
    if (count($readable) > 0) {
        $projectId = intval($readable[0]['id']);
    }
}
if ($projectId <= 0) {
    kupFail(404, 'project_not_found', 'No readable test project');
}

if (!$user->hasRight($db, 'mgt_view_tc', $projectId, null, true)) {
    tLog('BFF keywordusage: user ' . intval($userId) .
         ' has no mgt_view_tc right on tproject ' . $projectId, 'AUDIT');
    kupFail(403, 'no_right', 'mgt_view_tc right required');
}

$projectRows = $db->get_recordset(
    "SELECT tp.id, nh.name, tp.prefix FROM {$tbl['testprojects']} tp" .
    " JOIN {$tbl['nodes_hierarchy']} nh ON nh.id = tp.id" .
    " WHERE tp.id = " . intval($projectId));
if (!is_array($projectRows) || count($projectRows) === 0) {
    kupFail(404, 'project_not_found', 'Test project not found');
}
$project = $projectRows[0];
$prefix = kupDecode($project['prefix']);
$context = array(
    'tproject_id' => $projectId,
    'tproject_name' => kupDecode($project['name']),
    'prefix' => $prefix,
);

$keywordId = kupInt($_GET['keyword_id'] ?? null);
if ($action === 'detail' && $keywordId <= 0) {
    kupFail(400, 'invalid_request', 'Missing or invalid keyword_id');
}

$projectMgr = new testproject($db);
$testcaseIds = array();
$projectMgr->get_all_testcases_id($projectId, $testcaseIds);
$testcaseSet = array();
foreach ((array)$testcaseIds as $tcId) {
    $testcaseSet[intval($tcId)] = true;
}

$keywordRows = $db->get_recordset(
    "SELECT id, keyword, notes FROM {$tbl['keywords']}" .
    " WHERE testproject_id = " . intval($projectId) . " ORDER BY keyword");
$keywords = array();
if (is_array($keywordRows)) {
    foreach ($keywordRows as $row) {
        $kid = intval($row['id']);
        $keywords[$kid] = array(
            'keyword_id' => $kid,
            'name' => kupDecode($row['keyword']),
            'notes' => kupDecode($row['notes']),
        );
    }
}

if ($keywordId > 0 && !isset($keywords[$keywordId])) {
    kupFail(404, 'keyword_not_found', 'Keyword not found in this test project');
}

$linkRows = $db->get_recordset(
    "SELECT tk.keyword_id, tk.testcase_id, tk.tcversion_id, tv.version," .
    " tv.tc_external_id, tv.modification_ts, nhtc.name AS tc_name," .
    " nhs.name AS suite_name" .
    " FROM {$tbl['testcase_keywords']} tk" .
    " JOIN {$tbl['keywords']} k ON k.id = tk.keyword_id" .
    " LEFT JOIN {$tbl['tcversions']} tv ON tv.id = tk.tcversion_id" .
    " LEFT JOIN {$tbl['nodes_hierarchy']} nhv ON nhv.id = tk.tcversion_id" .
    " LEFT JOIN {$tbl['nodes_hierarchy']} nhtc ON nhtc.id = nhv.parent_id" .
    " LEFT JOIN {$tbl['nodes_hierarchy']} nhs ON nhs.id = nhtc.parent_id" .
    " WHERE k.testproject_id = " . intval($projectId));

$links = array();
$versions = array();
if (is_array($linkRows)) {
    foreach ($linkRows as $row) {
        $tcId = intval($row['testcase_id']);
        $versionId = intval($row['tcversion_id']);
        if (!isset($testcaseSet[$tcId])) {
            continue;
        }
        $kid = intval($row['keyword_id']);
        if (!isset($keywords[$kid]) || $versionId <= 0) {
            continue;
        }
        $links[] = array(
            'keyword_id' => $kid,
            'testcase_id' => $tcId,
            'tcversion_id' => $versionId,
            'version' => intval($row['version']),
            'external_id' => $prefix . '-' . intval($row['tc_external_id']),
            'testcase_name' => kupDecode($row['tc_name']),
            'suite_name' => kupDecode($row['suite_name']),
            'modification_ts' => (string)$row['modification_ts'],
        );
        $versions[$versionId] = true;
    }
}

$executionStats = array();
if (count($versions) > 0) {
    $versionIds = implode(',', array_keys($versions));
    $execRows = $db->get_recordset(
        "SELECT e.tcversion_id, e.status, e.execution_ts, e.testplan_id," .
        " tk.keyword_id" .
        " FROM {$tbl['executions']} e" .
        " JOIN {$tbl['testcase_keywords']} tk ON tk.tcversion_id = e.tcversion_id" .
        " WHERE e.tcversion_id IN ({$versionIds})");
    if (is_array($execRows)) {
        foreach ($execRows as $row) {
            $kid = intval($row['keyword_id']);
            if (!isset($keywords[$kid])) {
                continue;
            }
            if (!isset($executionStats[$kid])) {
                $executionStats[$kid] = array('count' => 0, 'passed' => 0,
                    'failed' => 0, 'blocked' => 0, 'last' => '', 'plans' => array());
            }
            $s =& $executionStats[$kid];
            $s['count']++;
            if ((string)$row['status'] === 'p') { $s['passed']++; }
            if ((string)$row['status'] === 'f') { $s['failed']++; }
            if ((string)$row['status'] === 'b') { $s['blocked']++; }
            if ((string)$row['execution_ts'] > $s['last']) {
                $s['last'] = (string)$row['execution_ts'];
            }
            $s['plans'][intval($row['testplan_id'])] = true;
            unset($s);
        }
    }
}

if ($action === 'detail') {
    $detail = array();
    $byCase = array();
    foreach ($links as $link) {
        if ($link['keyword_id'] !== $keywordId) {
            continue;
        }
        $tcId = $link['testcase_id'];
        if (!isset($byCase[$tcId])) {
            $byCase[$tcId] = array(
                'testcase_id' => $tcId,
                'external_id' => $link['external_id'],
                'testcase_name' => $link['testcase_name'],
                'suite_name' => $link['suite_name'],
                'versions' => array(),
                'executions' => 0,
                'last_execution' => '',
            );
        }
        $byCase[$tcId]['versions'][$link['tcversion_id']] = $link['version'];
    }
    $caseVersionIds = array();
    foreach ($byCase as $tcId => $case) {
        foreach ($case['versions'] as $versionId => $number) {
            $caseVersionIds[intval($versionId)] = true;
        }
    }
    if (count($caseVersionIds) > 0) {
        $ids = implode(',', array_keys($caseVersionIds));
        $rows = $db->get_recordset(
            "SELECT e.tcversion_id, e.status, e.execution_ts FROM {$tbl['executions']} e" .
            " WHERE e.tcversion_id IN ({$ids})");
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $versionId = intval($row['tcversion_id']);
                foreach ($byCase as $tcId => $case) {
                    if (!isset($case['versions'][$versionId])) {
                        continue;
                    }
                    $byCase[$tcId]['executions']++;
                    if ((string)$row['execution_ts'] > $byCase[$tcId]['last_execution']) {
                        $byCase[$tcId]['last_execution'] = (string)$row['execution_ts'];
                    }
                }
            }
        }
    }
    foreach ($byCase as $case) {
        $case['versions'] = count($case['versions']);
        $detail[] = $case;
    }
    usort($detail, function ($a, $b) {
        return strcmp($a['testcase_name'], $b['testcase_name']);
    });
    kupOut(array('status' => 'ok', 'code' => 200, 'context' => $context,
        'keyword' => $keywords[$keywordId], 'cases' => $detail));
}

$byKeyword = array();
foreach ($links as $link) {
    $kid = $link['keyword_id'];
    if (!isset($byKeyword[$kid])) {
        $byKeyword[$kid] = array(
            'testcases' => array(), 'versions' => array(), 'suites' => array(),
            'last_activity' => '',
        );
    }
    $byKeyword[$kid]['testcases'][$link['testcase_id']] = true;
    $byKeyword[$kid]['versions'][$link['tcversion_id']] = true;
    $byKeyword[$kid]['suites'][$link['suite_name']] = true;
    if ($link['modification_ts'] > $byKeyword[$kid]['last_activity']) {
        $byKeyword[$kid]['last_activity'] = $link['modification_ts'];
    }
}

$totalTestcases = count($testcaseSet);
$rows = array();
$usedTestcases = array();
$usedKeywords = 0;
$linkCount = count($links);
$execCount = 0;
$planIds = array();
foreach ($keywords as $kid => $keyword) {
    $agg = isset($byKeyword[$kid]) ? $byKeyword[$kid] : array(
        'testcases' => array(), 'versions' => array(), 'suites' => array(),
        'last_activity' => '');
    $stats = isset($executionStats[$kid]) ? $executionStats[$kid] : array(
        'count' => 0, 'passed' => 0, 'failed' => 0, 'blocked' => 0,
        'last' => '', 'plans' => array());
    $tcCount = count($agg['testcases']);
    foreach ($agg['testcases'] as $tcId => $one) {
        $usedTestcases[$tcId] = true;
    }
    foreach ($stats['plans'] as $planId => $one) {
        $planIds[$planId] = true;
    }
    $execCount += intval($stats['count']);
    $coverage = kupPct($tcCount, $totalTestcases);
    if ($tcCount > 0) { $usedKeywords++; }
    if ($tcCount <= 0) {
        $verdict = 'unused';
    } elseif ($coverage < 10) {
        $verdict = 'low';
    } elseif ($coverage < 40) {
        $verdict = 'medium';
    } else {
        $verdict = 'high';
    }
    $rows[] = array(
        'keyword_id' => $kid,
        'name' => $keyword['name'],
        'notes' => $keyword['notes'],
        'testcases' => $tcCount,
        'versions' => count($agg['versions']),
        'suites' => count($agg['suites']),
        'plans' => count($stats['plans']),
        'executions' => intval($stats['count']),
        'passed' => intval($stats['passed']),
        'failed' => intval($stats['failed']),
        'blocked' => intval($stats['blocked']),
        'coverage_pct' => $coverage,
        'last_activity' => $agg['last_activity'],
        'last_execution' => (string)$stats['last'],
        'verdict' => $verdict,
    );
}

usort($rows, function ($a, $b) {
    if ($a['testcases'] !== $b['testcases']) {
        return $b['testcases'] - $a['testcases'];
    }
    return strcmp($a['name'], $b['name']);
});

$buildRows = $db->get_recordset(
    "SELECT COUNT(*) AS qty FROM {$tbl['builds']}" .
    " WHERE testproject_id = " . intval($projectId));
$buildCount = is_array($buildRows) && count($buildRows) > 0
    ? intval($buildRows[0]['qty']) : 0;

$keywordTotal = count($keywords);
$linkedTestcases = count($usedTestcases);
$coverage = kupPct($linkedTestcases, $totalTestcases);
$summary = array(
    'keywords_total' => $keywordTotal,
    'keywords_used' => $usedKeywords,
    'keywords_unused' => $keywordTotal - $usedKeywords,
    'testcases_total' => $totalTestcases,
    'testcases_tagged' => $linkedTestcases,
    'coverage_pct' => $coverage,
    'links_total' => $linkCount,
    'versions_total' => count($versions),
    'executions_total' => $execCount,
    'plans_total' => count($planIds),
    'builds_total' => $buildCount,
    'verdict' => $keywordTotal <= 0 ? 'no_keywords'
        : ($linkedTestcases <= 0 ? 'no_usage'
        : ($coverage < 25 ? 'sparse' : ($coverage < 70 ? 'partial' : 'broad'))),
);

kupOut(array('status' => 'ok', 'code' => 200, 'context' => $context,
    'summary' => $summary, 'rows' => $rows));
