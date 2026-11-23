<?php
require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();
require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function rgpOut($payload, $code = null)
{
    if ($code !== null) { http_response_code($code); }
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function rgpFail($httpCode, $code, $message = '')
{
    rgpOut(array('status' => 'error', 'code' => $code,
                 'message' => $message !== '' ? $message : $code), $httpCode);
}

function rgpPositiveInt($raw)
{
    if ($raw === null || is_array($raw)) { return 0; }
    $raw = trim((string)$raw);
    if ($raw === '' || strlen($raw) > 10 || !preg_match('/^[0-9]+$/', $raw)) { return 0; }
    $n = intval($raw);
    return $n > 0 ? $n : 0;
}

function rgpDecode($value)
{
    return html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function rgpText($value)
{
    return trim(preg_replace('/\s+/', ' ', strip_tags(rgpDecode($value))));
}

function rgpPct($part, $total)
{
    return $total > 0 ? round(100.0 * $part / $total, 1) : 0.0;
}

function rgpCsvCell($value)
{
    $s = str_replace(array("\r", "\n", "\u{00A0}"), array(' ', ' ', ' '), rgpDecode($value));
    if ($s !== '' && preg_match('/^\s*[=+\-@]/', $s)) { $s = "'" . $s; }
    return $s;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    rgpFail(405, 'method_not_allowed');
}

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) { rgpFail(401, 'not_authenticated', 'Not authenticated'); }
bffEnforceSession($db);
$user = tlUser::getByID($db, $userId);
if (is_null($user)) { rgpFail(401, 'not_authenticated', 'User not found'); }

$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if (!in_array($action, array('projects', 'init', 'report', 'detail', 'csv'), true)) {
    rgpFail(400, 'invalid_request', 'Unknown action');
}

$tbl = tlObjectWithDB::getDBTables(array(
    'testprojects', 'requirements', 'req_versions', 'req_specs', 'req_coverage',
    'nodes_hierarchy', 'tcversions',
));

if ($action === 'projects') {
    $items = array();
    $pRs = $db->get_recordset("SELECT id FROM {$tbl['testprojects']} ORDER BY id");
    if (is_array($pRs)) {
        foreach ($pRs as $p) {
            $pid = intval($p['id']);
            if (!$user->hasRight($db, 'mgt_view_req', $pid, null, true)) { continue; }
            $name = testproject::getName($db, $pid);
            if (is_null($name) || trim((string)$name) === '') { continue; }
            $items[] = array('id' => $pid, 'name' => rgpDecode($name));
        }
    }
    rgpOut(array('status' => 'ok', 'code' => 200, 'projects' => $items));
}

$projectId = rgpPositiveInt($_GET['tproject_id'] ?? null);
if ($projectId <= 0) { rgpFail(400, 'invalid_request', 'Missing or invalid tproject_id'); }
if (!$user->hasRight($db, 'mgt_view_req', $projectId, null, true)) {
    tLog('BFF reqTraceabilityGaps: user ' . intval($userId) .
         ' has no mgt_view_req right on tproject ' . $projectId, 'AUDIT');
    rgpFail(403, 'no_right', 'mgt_view_req right required');
}
$projectRow = $db->get_recordset("SELECT id, notes FROM {$tbl['testprojects']} WHERE id = " . intval($projectId));
if (!is_array($projectRow) || count($projectRow) === 0) {
    rgpFail(404, 'tproject_not_found', 'Test project not found');
}
$projectName = testproject::getName($db, $projectId);
if (is_null($projectName) || trim((string)$projectName) === '') {
    rgpFail(404, 'tproject_not_found', 'Test project not found');
}

$specFilter = rgpPositiveInt($_GET['spec_id'] ?? null);
$issueFilter = strtolower(trim((string)($_GET['issue'] ?? 'all')));
if (!in_array($issueFilter, array('all', 'uncovered', 'stale_links', 'closed_links', 'under_covered', 'ok'), true)) {
    rgpFail(400, 'invalid_request', 'Invalid issue filter');
}

$specs = array();
$specRs = $db->get_recordset("SELECT id, doc_id FROM {$tbl['req_specs']} WHERE testproject_id = " .
    intval($projectId) . " ORDER BY doc_id, id");
if (is_array($specRs)) {
    foreach ($specRs as $s) {
        $specs[] = array('id' => intval($s['id']),
                         'name' => rgpDecode($s['doc_id']) . ' (' . intval($s['id']) . ')');
    }
}
$knownSpec = ($specFilter === 0);
foreach ($specs as $s) { if ($s['id'] === $specFilter) { $knownSpec = true; break; } }
if (!$knownSpec) { rgpFail(400, 'invalid_request', 'spec_id does not belong to this project'); }
$specNames = array();
foreach ($specs as $s) { $specNames[$s['id']] = $s['name']; }

$context = array('tproject_id' => $projectId, 'tproject_name' => rgpDecode($projectName));
$filterEcho = array('spec_id' => $specFilter, 'issue' => $issueFilter);
if ($action === 'init') {
    rgpOut(array('status' => 'ok', 'code' => 200, 'context' => $context,
                 'filters' => array('specs' => $specs), 'filter' => $filterEcho));
}

$reqRs = $db->get_recordset(
    "SELECT R.id AS req_id, R.req_doc_id, RV.id AS version_id, RV.version, RV.active," .
    "       RV.expected_coverage, RV.scope, RV.status, RS.id AS spec_id, RS.doc_id AS spec_doc_id" .
    " FROM {$tbl['requirements']} R" .
    " JOIN {$tbl['req_versions']} RV ON RV.id = (SELECT MAX(RV2.id) FROM {$tbl['req_versions']} RV2" .
    "                                        WHERE RV2.req_id = R.id)" .
    " JOIN {$tbl['req_specs']} RS ON RS.id = R.srs_id" .
    " WHERE RS.testproject_id = " . intval($projectId) .
    ($specFilter > 0 ? " AND RS.id = " . intval($specFilter) : "") .
    " ORDER BY RS.doc_id, R.req_doc_id");
if (!is_array($reqRs)) { $reqRs = array(); }

$reqIds = array();
foreach ($reqRs as $r) { $reqIds[] = intval($r['req_id']); }
$linksByReq = array();
$latestByCase = array();
if (count($reqIds) > 0) {
    $linkRs = $db->get_recordset(
        "SELECT RC.req_id, RC.testcase_id, RC.tcversion_id, RC.link_status, RC.is_active," .
        "       NH.name AS tcase_name, TV.version AS tc_version, TV.tc_external_id," .
        "       PN.name AS suite_name" .
        " FROM {$tbl['req_coverage']} RC" .
        " LEFT JOIN {$tbl['nodes_hierarchy']} NH ON NH.id = RC.testcase_id" .
        " LEFT JOIN {$tbl['nodes_hierarchy']} PN ON PN.id = NH.parent_id" .
        " LEFT JOIN {$tbl['tcversions']} TV ON TV.id = RC.tcversion_id" .
        " WHERE RC.req_id IN (" . implode(',', $reqIds) . ")" .
        " ORDER BY NH.name, TV.version");
    if (!is_array($linkRs)) { $linkRs = array(); }
    foreach ($linkRs as $l) {
        $rid = intval($l['req_id']);
        if (!isset($linksByReq[$rid])) { $linksByReq[$rid] = array(); }
        $linksByReq[$rid][] = $l;
        $caseId = intval($l['testcase_id']);
        $version = intval($l['tc_version']);
        if (!isset($latestByCase[$caseId]) || $version > $latestByCase[$caseId]['version']) {
            $latestByCase[$caseId] = array('version' => $version, 'id' => intval($l['tcversion_id']));
        }
    }
}

$rows = array();
foreach ($reqRs as $r) {
    $reqId = intval($r['req_id']);
    $links = isset($linksByReq[$reqId]) ? $linksByReq[$reqId] : array();
    $open = 0; $closed = 0; $inactive = 0; $stale = 0;
    $cases = array(); $detail = array();
    foreach ($links as $l) {
        $caseId = intval($l['testcase_id']);
        $tcvId = intval($l['tcversion_id']);
        $isOpen = (intval($l['link_status']) === 1);
        $isActive = (intval($l['is_active']) === 1);
        $isStale = isset($latestByCase[$caseId]) &&
                   $latestByCase[$caseId]['version'] > intval($l['tc_version']);
        if ($isActive && $isOpen) { $open++; } else { $closed++; }
        if (!$isActive) { $inactive++; }
        if ($isStale) { $stale++; }
        $caseName = rgpDecode($l['tcase_name']);
        if ($caseName !== '') { $cases[$caseName] = $caseName; }
        $detail[] = array(
            'case' => $caseName,
            'external_id' => rgpDecode($l['tc_external_id']),
            'suite' => rgpDecode($l['suite_name']),
            'version' => intval($l['tc_version']),
            'link_status' => intval($l['link_status']),
            'is_active' => intval($l['is_active']),
            'stale' => $isStale,
        );
    }
    $expected = intval($r['expected_coverage']);
    if ($expected < 1) { $expected = 1; }
    $verdict = 'ok';
    if ($open === 0) { $verdict = 'uncovered'; }
    elseif ($stale > 0) { $verdict = 'stale_links'; }
    elseif ($closed > 0 || $inactive > 0) { $verdict = 'closed_links'; }
    elseif ($open < $expected) { $verdict = 'under_covered'; }
    $rows[] = array(
        'req_id' => $reqId,
        'req_doc_id' => rgpDecode($r['req_doc_id']),
        'spec' => isset($specNames[intval($r['spec_id'])]) ? $specNames[intval($r['spec_id'])] : '',
        'spec_id' => intval($r['spec_id']),
        'version' => intval($r['version']),
        'active' => intval($r['active']),
        'status' => rgpDecode($r['status']),
        'scope' => rgpText($r['scope']),
        'expected' => $expected,
        'links' => count($links),
        'open_links' => $open,
        'closed_links' => $closed,
        'inactive_links' => $inactive,
        'stale_links' => $stale,
        'coverage' => rgpPct($open, $expected),
        'cases' => array_values($cases),
        'verdict' => $verdict,
        'detail' => $detail,
    );
}

$allRows = $rows;
if ($issueFilter !== 'all') {
    $rows = array_values(array_filter($rows, function ($r) use ($issueFilter) {
        return $r['verdict'] === $issueFilter;
    }));
}
usort($rows, function ($a, $b) {
    $order = array('uncovered' => 0, 'stale_links' => 1, 'closed_links' => 2,
                   'under_covered' => 3, 'ok' => 4);
    if ($order[$a['verdict']] !== $order[$b['verdict']]) {
        return $order[$a['verdict']] - $order[$b['verdict']];
    }
    return strcmp($a['spec'] . $a['req_doc_id'], $b['spec'] . $b['req_doc_id']);
});

$kpi = array(
    'requirements' => count($allRows), 'gaps' => 0, 'uncovered' => 0,
    'stale_links' => 0, 'closed_links' => 0, 'under_covered' => 0, 'ok' => 0,
    'total_links' => 0, 'open_links' => 0, 'affected_specs' => 0,
    'coverage' => 0.0, 'verdict' => 'no_data',
);
$specsWithGaps = array();
$expectedTotal = 0;
foreach ($allRows as $r) {
    $kpi[$r['verdict']]++;
    $kpi['total_links'] += $r['links'];
    $kpi['open_links'] += $r['open_links'];
    $expectedTotal += $r['expected'];
    if ($r['verdict'] !== 'ok') {
        $kpi['gaps']++;
        $specsWithGaps[$r['spec_id']] = true;
    }
}
$kpi['affected_specs'] = count($specsWithGaps);
$kpi['coverage'] = rgpPct($kpi['open_links'], $expectedTotal);
if (count($allRows) === 0) { $kpi['verdict'] = 'no_data'; }
elseif ($kpi['uncovered'] > 0 || $kpi['stale_links'] > 0) { $kpi['verdict'] = 'at_risk'; }
elseif ($kpi['gaps'] > 0) { $kpi['verdict'] = 'watch'; }
else { $kpi['verdict'] = 'clear'; }

foreach ($rows as &$r) { unset($r['detail']); }
unset($r);

if ($action === 'detail') {
    $reqId = rgpPositiveInt($_GET['req_id'] ?? null);
    if ($reqId <= 0) { rgpFail(400, 'invalid_request', 'Missing req_id'); }
    $req = null;
    foreach ($allRows as $r) { if ($r['req_id'] === $reqId) { $req = $r; break; } }
    if (is_null($req)) { rgpFail(404, 'req_not_found', 'Requirement not found in this project'); }
    rgpOut(array('status' => 'ok', 'code' => 200, 'context' => $context,
                 'filter' => $filterEcho, 'requirement' => $req));
}

if ($action === 'csv') {
    $out = fopen('php://temp', 'r+');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, array('req_doc_id', 'spec', 'version', 'status', 'expected', 'links',
        'open_links', 'closed_links', 'inactive_links', 'stale_links', 'coverage',
        'verdict', 'cases'), ';');
    foreach ($rows as $r) {
        fputcsv($out, array(
            rgpCsvCell($r['req_doc_id']), rgpCsvCell($r['spec']), $r['version'],
            rgpCsvCell($r['status']), $r['expected'], $r['links'], $r['open_links'],
            $r['closed_links'], $r['inactive_links'], $r['stale_links'], $r['coverage'],
            $r['verdict'], rgpCsvCell(implode(', ', $r['cases'])),
        ), ';');
    }
    rewind($out);
    $csv = stream_get_contents($out);
    fclose($out);
    rgpOut(array('status' => 'ok', 'code' => 200, 'csv' => $csv, 'rows' => count($rows)));
}

rgpOut(array('status' => 'ok', 'code' => 200, 'context' => $context,
             'filters' => array('specs' => $specs), 'filter' => $filterEcho,
             'kpi' => $kpi, 'rows' => $rows));
