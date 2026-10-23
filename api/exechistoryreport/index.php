<?php
/**
 * Test Plan Execution History report - BFF (Refs #1961).
 *
 * 1.9.20 exposed the execution history of a SINGLE test case only
 * (lib/execute/execHistory.php -> gui/templates/dashio/execute/execHistory.tpl,
 * modernized as gui/templates/execute/execHistory.html in #1806). There was no
 * report answering "what was executed in this test plan, when, by whom, on
 * which build/platform, and which defects were filed?". This BFF backs that
 * plan-wide report; it is read-only.
 *
 * Actions
 *   GET|HEAD ?action=init&tplan_id=N[&tproject_id=P]
 *       -> context (project / plan) + filter choices (builds, platforms,
 *          testers that really executed in this plan, statuses)
 *   GET|HEAD ?action=report&tplan_id=N[&tproject_id=P][&build_id=B]
 *             [&platform_id=PL][&status=p|f|b][&tester_id=T]
 *       -> summary + the execution rows (newest first, capped)
 *   GET|HEAD ?action=detail&tplan_id=N&exec_id=E
 *       -> one execution with its notes, test case metadata and defects
 *
 * Semantics
 *   - the unit is ONE EXECUTION ROW (`executions.id`), not a test case: an
 *     execution history is a list of events, so every run is listed;
 *   - the test case name comes from the TEST CASE node (type 3), the version
 *     number from `tcversions.version` and the external id is prefixed with
 *     the project prefix exactly like the other reports;
 *   - the suite path walks the suite tree (type 2) upwards, capped at depth 32;
 *   - defects are DISTINCT `execution_bugs.bug_id` values (varchar, never
 *     compared numerically - the #1068 lesson);
 *   - the filters narrow the LIST only; a build/platform that does not belong
 *     to the owning project is refused with 400 instead of being ignored.
 *
 * Security: session authentication, the shared BFF same-origin guard and the
 * `testplan_metrics` right on the OWNING test project (getAccess = true). The
 * client supplied tproject_id is only an assertion: it is compared with the
 * plan's owning project and never widens the query. The plan is resolved
 * BEFORE the rights check so a caller without rights learns nothing about the
 * existence of a plan (the #1697 no-oracle lesson).
 *
 * Status contract: 400 invalid_request / invalid_parameter / unknown_action,
 * 401 not_authenticated / session_expired, 403 no_right,
 * 404 tplan_not_found / project_mismatch / execution_not_found,
 * 405 method_not_allowed, 500 server_error.
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
function ehrOut($payload, $code = null)
{
    if ($code !== null) {
        http_response_code($code);
    }
    echo json_encode($payload);
    exit;
}

function ehrFail($httpCode, $code, $message = '')
{
    ehrOut(array(
        'status' => 'error',
        'code' => $code,
        'message' => $message !== '' ? $message : $code,
    ), $httpCode);
}

/** Strict positive integer reader - "1abc", "1.9", "-3", "" and arrays are refused. */
function ehrPositiveInt($raw)
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

function ehrDecode($value)
{
    return html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function ehrStatusKey($raw)
{
    switch ((string)$raw) {
        case 'p': return 'passed';
        case 'f': return 'failed';
        case 'b': return 'blocked';
        default:  return 'other';
    }
}

/** RichEdit blobs are flattened to plain text (same contract as execNotesReadonly). */
function ehrPlainText($value)
{
    $s = (string)$value;
    $s = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $s);
    $s = preg_replace('/<\/\s*(p|div|li|tr|h[1-6])\s*>/i', "\n", $s);
    $s = strip_tags($s);
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = preg_replace("/[ \t]+\n/", "\n", $s);
    $s = preg_replace("/\n{3,}/", "\n\n", $s);
    return trim($s);
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    ehrFail(405, 'method_not_allowed');
}

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    ehrFail(401, 'not_authenticated', 'Not authenticated');
}

bffEnforceSession($db);

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    ehrFail(401, 'not_authenticated', 'User not found');
}

$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if ($action !== 'init' && $action !== 'report' && $action !== 'detail') {
    ehrFail(400, 'invalid_request', 'Unknown action');
}

$tplanId = ehrPositiveInt($_GET['tplan_id'] ?? null);
$assertedProjectId = ehrPositiveInt($_GET['tproject_id'] ?? null);
$buildId = ehrPositiveInt($_GET['build_id'] ?? null);
$platformId = ehrPositiveInt($_GET['platform_id'] ?? null);
$testerId = ehrPositiveInt($_GET['tester_id'] ?? null);
$execId = ehrPositiveInt($_GET['exec_id'] ?? null);

if ($tplanId <= 0) {
    ehrFail(400, 'invalid_request', 'Missing or invalid tplan_id');
}

$statusFilter = '';
if (isset($_GET['status']) && !is_array($_GET['status'])) {
    $statusFilter = strtolower(trim((string)$_GET['status']));
    if ($statusFilter !== '' && $statusFilter !== 'p' && $statusFilter !== 'f' && $statusFilter !== 'b') {
        ehrFail(400, 'invalid_parameter', 'status must be p, f or b');
    }
}

$tbl = tlObjectWithDB::getDBTables(array(
    'testplans', 'builds', 'platforms', 'testplan_tcversions', 'tcversions',
    'nodes_hierarchy', 'executions', 'execution_bugs', 'testprojects', 'users',
));

// 1. resolve the plan, 2. check the right on the OWNING project, 3. only then
// honour the client's tproject_id assertion.
$planRows = $db->get_recordset(
    "SELECT id, testproject_id FROM {$tbl['testplans']} WHERE id = " . intval($tplanId));
if (!is_array($planRows) || count($planRows) === 0) {
    ehrFail(404, 'tplan_not_found', 'Test plan not found');
}
$owningProjectId = intval($planRows[0]['testproject_id']);
if ($owningProjectId <= 0) {
    ehrFail(404, 'tplan_not_found', 'Test plan has no owning test project');
}

if (!$user->hasRight($db, 'testplan_metrics', $owningProjectId, $tplanId, true)) {
    tLog('BFF exechistoryreport: user ' . intval($userId) .
         ' has no testplan_metrics right on tproject ' . $owningProjectId .
         ' (tplan ' . $tplanId . ')', 'ERROR');
    ehrFail(403, 'no_right', 'testplan_metrics right required');
}

if ($assertedProjectId > 0 && $assertedProjectId !== $owningProjectId) {
    ehrFail(404, 'project_mismatch', 'Test plan belongs to another test project');
}

$planName = testplan::getName($db, $tplanId);
$projectName = testproject::getName($db, $owningProjectId);
if (is_null($planName) || trim((string)$planName) === '') {
    ehrFail(404, 'tplan_not_found', 'Test plan not found');
}

$prefixRows = $db->get_recordset(
    "SELECT prefix FROM {$tbl['testprojects']} WHERE id = " . intval($owningProjectId));
$prefix = (is_array($prefixRows) && count($prefixRows) > 0)
    ? (string)$prefixRows[0]['prefix'] : '';

$context = array(
    'tplan_id' => $tplanId,
    'tplan_name' => (string)$planName,
    'tproject_id' => $owningProjectId,
    'tproject_name' => (string)$projectName,
    'prefix' => $prefix,
);

// ---------------------------------------------------------------------------
// Filter choices.
// ---------------------------------------------------------------------------
$builds = array();
$rb = $db->get_recordset(
    "SELECT id, name, active FROM {$tbl['builds']} " .
    "WHERE testproject_id = " . intval($owningProjectId) . " ORDER BY id");
$buildNames = array();
if (is_array($rb)) {
    foreach ($rb as $r) {
        $buildNames[intval($r['id'])] = ehrDecode($r['name']);
        if (intval($r['active']) === 1) {
            $builds[] = array('id' => intval($r['id']), 'name' => ehrDecode($r['name']));
        }
    }
}

if ($buildId > 0 && !isset($buildNames[$buildId])) {
    ehrFail(400, 'invalid_parameter', 'build_id does not belong to this test project');
}

$platforms = array();
$platformNames = array();
$rp = $db->get_recordset(
    "SELECT id, name FROM {$tbl['platforms']} " .
    "WHERE testproject_id = " . intval($owningProjectId) . " ORDER BY name");
if (is_array($rp)) {
    foreach ($rp as $r) {
        $platformNames[intval($r['id'])] = ehrDecode($r['name']);
        $platforms[] = array('id' => intval($r['id']), 'name' => ehrDecode($r['name']));
    }
}
if ($platformId > 0 && !isset($platformNames[$platformId])) {
    ehrFail(400, 'invalid_parameter', 'platform_id does not belong to this test project');
}

$testerNames = array();
$rt = $db->get_recordset(
    "SELECT DISTINCT u.id, u.login, u.first, u.last " .
    "FROM {$tbl['executions']} e JOIN {$tbl['users']} u ON u.id = e.tester_id " .
    "WHERE e.testplan_id = " . intval($tplanId) . " AND e.tester_id IS NOT NULL " .
    "ORDER BY u.login");
$testers = array();
if (is_array($rt)) {
    foreach ($rt as $r) {
        $name = trim(ehrDecode($r['first'] ?? '') . ' ' . ehrDecode($r['last'] ?? ''));
        if ($name === '') {
            $name = (string)$r['login'];
        }
        $testerNames[intval($r['id'])] = $name;
        $testers[] = array(
            'id' => intval($r['id']),
            'login' => (string)$r['login'],
            'name' => $name,
        );
    }
}
if ($testerId > 0 && !isset($testerNames[$testerId])) {
    ehrFail(400, 'invalid_parameter', 'tester_id has no execution in this test plan');
}

$filters = array(
    'builds' => $builds,
    'platforms' => $platforms,
    'testers' => $testers,
    'statuses' => array('p', 'f', 'b'),
);

if ($action === 'init') {
    ehrOut(array(
        'status' => 'ok',
        'code' => 200,
        'context' => $context,
        'filters' => $filters,
    ));
}

// ---------------------------------------------------------------------------
// Suite paths (type 2 tree under the project root, depth-capped).
// ---------------------------------------------------------------------------
$suitePaths = array();
$pathSql =
    "WITH RECURSIVE s AS (" .
    "  SELECT n.id, n.name, n.parent_id, 0 AS depth, " .
    "         CAST(n.name AS CHAR(2000)) AS path " .
    "  FROM {$tbl['nodes_hierarchy']} n " .
    "  WHERE n.node_type_id = 2 AND n.parent_id = " . intval($owningProjectId) .
    "  UNION ALL " .
    "  SELECT n.id, n.name, n.parent_id, s.depth + 1, " .
    "         CONCAT(s.path, ' / ', n.name) " .
    "  FROM {$tbl['nodes_hierarchy']} n JOIN s ON n.parent_id = s.id " .
    "  WHERE n.node_type_id = 2 AND s.depth < 32" .
    ") SELECT id, path FROM s";
$rs = $db->get_recordset($pathSql);
if (is_array($rs)) {
    foreach ($rs as $r) {
        $suitePaths[intval($r['id'])] = ehrDecode($r['path']);
    }
}

// ---------------------------------------------------------------------------
// detail: one execution of THIS plan only.
// ---------------------------------------------------------------------------
if ($action === 'detail') {
    if ($execId <= 0) {
        ehrFail(400, 'invalid_request', 'Missing or invalid exec_id');
    }

    $dSql =
        "SELECT e.id, e.execution_ts, e.status, e.build_id, e.platform_id, " .
        "       e.execution_duration, e.notes, e.tcversion_number, e.testplan_id, e.tester_id, e.tcversion_id, " .
        "       tv.tc_external_id, tv.version, " .
        "       tn.id AS tcase_node_id, tn.name AS tcase_name, tn.parent_id AS suite_id, " .
        "       b.name AS build_name, p.name AS platform_name, " .
        "       u.login AS tester_login, u.first AS tester_first, u.last AS tester_last " .
        "FROM {$tbl['executions']} e " .
        "JOIN {$tbl['tcversions']} tv ON tv.id = e.tcversion_id " .
        "JOIN {$tbl['nodes_hierarchy']} vn ON vn.id = tv.id AND vn.node_type_id = 4 " .
        "JOIN {$tbl['nodes_hierarchy']} tn ON tn.id = vn.parent_id AND tn.node_type_id = 3 " .
        "LEFT JOIN {$tbl['builds']} b ON b.id = e.build_id " .
        "LEFT JOIN {$tbl['platforms']} p ON p.id = e.platform_id " .
        "LEFT JOIN {$tbl['users']} u ON u.id = e.tester_id " .
        "WHERE e.id = " . intval($execId) . " AND e.testplan_id = " . intval($tplanId);
    $dRs = $db->get_recordset($dSql);
    if (!is_array($dRs) || count($dRs) === 0) {
        ehrFail(404, 'execution_not_found', 'Execution not found in this test plan');
    }
    $d = $dRs[0];

    $bugs = array();
    $bRs = $db->get_recordset(
        "SELECT DISTINCT bug_id FROM {$tbl['execution_bugs']} " .
        "WHERE execution_id = " . intval($execId) . " ORDER BY bug_id");
    if (is_array($bRs)) {
        foreach ($bRs as $r) {
            $bug = trim((string)$r['bug_id']);
            if ($bug !== '' && $bug !== '0') {
                $bugs[] = $bug;
            }
        }
    }

    $testerName = trim((string)($d['tester_first'] ?? '') . ' ' . (string)($d['tester_last'] ?? ''));
    if ($testerName === '') {
        $testerName = (string)($d['tester_login'] ?? '');
    }

    ehrOut(array(
        'status' => 'ok',
        'code' => 200,
        'context' => $context,
        'execution' => array(
            'id' => intval($d['id']),
            'execution_ts' => (string)$d['execution_ts'],
            'status' => ehrStatusKey($d['status']),
            'status_raw' => (string)$d['status'],
            'duration' => round((float)$d['execution_duration'], 2),
            'tester_id' => intval($d['tester_id'] ?? 0),
            'tester_name' => ehrDecode($testerName),
            'build_id' => intval($d['build_id']),
            'build_name' => ehrDecode($d['build_name'] ?? ''),
            'platform_id' => intval($d['platform_id']),
            'platform_name' => ehrDecode($d['platform_name'] ?? ''),
            'tcversion_id' => intval($d['tcversion_id'] ?? 0),
            'tcversion_number' => intval($d['version'] ?? $d['tcversion_number']),
            'external_id' => (intval($d['tc_external_id']) > 0 && $prefix !== '')
                ? ($prefix . '-' . intval($d['tc_external_id']))
                : (string)intval($d['tc_external_id']),
            'tcase_name' => ehrDecode($d['tcase_name']),
            'suite_path' => $suitePaths[intval($d['suite_id'])] ?? '',
            'notes' => ehrPlainText($d['notes'] ?? ''),
            'bugs' => $bugs,
        ),
    ));
}

// ---------------------------------------------------------------------------
// report: the execution rows of the analysed scope.
// ---------------------------------------------------------------------------
$where = "e.testplan_id = " . intval($tplanId);
if ($buildId > 0) {
    $where .= " AND e.build_id = " . intval($buildId);
}
if ($platformId > 0) {
    $where .= " AND e.platform_id = " . intval($platformId);
}
if ($statusFilter !== '') {
    $where .= " AND e.status = '" . $db->prepare_string($statusFilter) . "'";
}
if ($testerId > 0) {
    $where .= " AND e.tester_id = " . intval($testerId);
}

$summary = array(
    'executions' => 0,
    'passed' => 0,
    'failed' => 0,
    'blocked' => 0,
    'other' => 0,
    'test_cases' => 0,
    'testers' => 0,
    'builds' => 0,
    'defects' => 0,
    'first_ts' => '',
    'last_ts' => '',
    'avg_duration' => 0.0,
    'rows' => 0,
    'rows_truncated' => false,
);

$sSql =
    "SELECT COUNT(*) AS executions, " .
    "       SUM(CASE WHEN e.status = 'p' THEN 1 ELSE 0 END) AS passed, " .
    "       SUM(CASE WHEN e.status = 'f' THEN 1 ELSE 0 END) AS failed, " .
    "       SUM(CASE WHEN e.status = 'b' THEN 1 ELSE 0 END) AS blocked, " .
    "       SUM(CASE WHEN e.status NOT IN ('p','f','b') THEN 1 ELSE 0 END) AS other, " .
    "       COUNT(DISTINCT tn.id) AS test_cases, " .
    "       COUNT(DISTINCT e.tester_id) AS testers, " .
    "       COUNT(DISTINCT e.build_id) AS builds, " .
    "       MIN(e.execution_ts) AS first_ts, MAX(e.execution_ts) AS last_ts, " .
    "       AVG(e.execution_duration) AS avg_duration " .
    "FROM {$tbl['executions']} e " .
    "JOIN {$tbl['tcversions']} tv ON tv.id = e.tcversion_id " .
    "JOIN {$tbl['nodes_hierarchy']} vn ON vn.id = tv.id AND vn.node_type_id = 4 " .
    "JOIN {$tbl['nodes_hierarchy']} tn ON tn.id = vn.parent_id AND tn.node_type_id = 3 " .
    "WHERE " . $where;
$sRs = $db->get_recordset($sSql);
if (is_array($sRs) && count($sRs) > 0) {
    $s = $sRs[0];
    $summary['executions'] = intval($s['executions']);
    $summary['passed'] = intval($s['passed']);
    $summary['failed'] = intval($s['failed']);
    $summary['blocked'] = intval($s['blocked']);
    $summary['other'] = intval($s['other']);
    $summary['test_cases'] = intval($s['test_cases']);
    $summary['testers'] = intval($s['testers']);
    $summary['builds'] = intval($s['builds']);
    $summary['first_ts'] = (string)($s['first_ts'] ?? '');
    $summary['last_ts'] = (string)($s['last_ts'] ?? '');
    $summary['avg_duration'] = round((float)($s['avg_duration'] ?? 0), 2);
}

$defRs = $db->get_recordset(
    "SELECT COUNT(DISTINCT eb.bug_id) AS defects " .
    "FROM {$tbl['executions']} e " .
    "JOIN {$tbl['execution_bugs']} eb ON eb.execution_id = e.id " .
    "WHERE " . $where . " AND eb.bug_id <> '' AND eb.bug_id <> '0'");
if (is_array($defRs) && count($defRs) > 0) {
    $summary['defects'] = intval($defRs[0]['defects']);
}

$maxRows = 5000;
$rowSql =
    "SELECT e.id, e.execution_ts, e.status, e.build_id, e.platform_id, " .
    "       e.execution_duration, e.tester_id, " .
    "       tv.tc_external_id, tv.version, " .
    "       tn.id AS tcase_node_id, tn.name AS tcase_name, tn.parent_id AS suite_id, " .
    "       b.name AS build_name, p.name AS platform_name, " .
    "       u.login AS tester_login, u.first AS tester_first, u.last AS tester_last, " .
    "       (SELECT COUNT(DISTINCT eb.bug_id) FROM {$tbl['execution_bugs']} eb " .
    "         WHERE eb.execution_id = e.id AND eb.bug_id <> '' AND eb.bug_id <> '0') AS defect_count " .
    "FROM {$tbl['executions']} e " .
    "JOIN {$tbl['tcversions']} tv ON tv.id = e.tcversion_id " .
    "JOIN {$tbl['nodes_hierarchy']} vn ON vn.id = tv.id AND vn.node_type_id = 4 " .
    "JOIN {$tbl['nodes_hierarchy']} tn ON tn.id = vn.parent_id AND tn.node_type_id = 3 " .
    "LEFT JOIN {$tbl['builds']} b ON b.id = e.build_id " .
    "LEFT JOIN {$tbl['platforms']} p ON p.id = e.platform_id " .
    "LEFT JOIN {$tbl['users']} u ON u.id = e.tester_id " .
    "WHERE " . $where . " " .
    "ORDER BY e.execution_ts DESC, e.id DESC LIMIT " . intval($maxRows + 1);
$rowRs = $db->get_recordset($rowSql);

$rows = array();
if (is_array($rowRs)) {
    foreach ($rowRs as $r) {
        if (count($rows) >= $maxRows) {
            $summary['rows_truncated'] = true;
            break;
        }
        $testerName = trim((string)($r['tester_first'] ?? '') . ' ' . (string)($r['tester_last'] ?? ''));
        if ($testerName === '') {
            $testerName = (string)($r['tester_login'] ?? '');
        }
        $extId = intval($r['tc_external_id']);
        $rows[] = array(
            'id' => intval($r['id']),
            'execution_ts' => (string)$r['execution_ts'],
            'status' => ehrStatusKey($r['status']),
            'status_raw' => (string)$r['status'],
            'duration' => round((float)$r['execution_duration'], 2),
            'tester_id' => intval($r['tester_id'] ?? 0),
            'tester_name' => ehrDecode($testerName),
            'build_id' => intval($r['build_id']),
            'build_name' => ehrDecode($r['build_name'] ?? ''),
            'platform_id' => intval($r['platform_id']),
            'platform_name' => ehrDecode($r['platform_name'] ?? ''),
            'external_id' => ($extId > 0 && $prefix !== '') ? ($prefix . '-' . $extId) : (string)$extId,
            'tcase_name' => ehrDecode($r['tcase_name']),
            'suite_path' => $suitePaths[intval($r['suite_id'])] ?? '',
            'version' => intval($r['version']),
            'defects' => intval($r['defect_count']),
        );
    }
}
$summary['rows'] = count($rows);

ehrOut(array(
    'status' => 'ok',
    'code' => 200,
    'context' => $context,
    'filters' => $filters,
    'filter' => array(
        'build_id' => $buildId,
        'platform_id' => $platformId,
        'tester_id' => $testerId,
        'status' => $statusFilter,
    ),
    'summary' => $summary,
    'rows' => $rows,
));
