<?php
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

function dsvOut($payload, $code = null)
{
    if ($code !== null) {
        http_response_code($code);
    }
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function dsvFail($httpCode, $code, $message = '')
{
    dsvOut(array('status' => 'error', 'code' => $code,
                 'message' => $message !== '' ? $message : $code), $httpCode);
}

function dsvPositiveInt($raw)
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

function dsvDecode($value)
{
    return html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function dsvText($value)
{
    return trim(strip_tags(dsvDecode($value)));
}

function dsvPct($part, $total)
{
    return $total > 0 ? round(100.0 * $part / $total, 1) : 0.0;
}

function dsvCsvCell($value)
{
    $s = dsvDecode($value);
    if ($s !== '' && preg_match('/^[\s]*[=+\-@]/', $s)) {
        $s = "'" . $s;
    }
    return str_replace(array("\r", "\n"), array(' ', ' '), $s);
}

function dsvTables()
{
    return tlObjectWithDB::getDBTables(array(
        'testplans', 'testprojects', 'nodes_hierarchy', 'tcversions',
        'builds', 'platforms', 'testplan_platforms', 'executions',
        'execution_bugs', 'tc_defect_severity',
    ));
}

function dsvEnsureSchema($db)
{
    $t = dsvTables();
    $table = $t['tc_defect_severity'];
    $db->exec_query(
        "CREATE TABLE IF NOT EXISTS {$table} (" .
        " id INT UNSIGNED NOT NULL AUTO_INCREMENT," .
        " testproject_id INT UNSIGNED NOT NULL DEFAULT 0," .
        " bug_id VARCHAR(255) NOT NULL DEFAULT ''," .
        " severity VARCHAR(16) NOT NULL DEFAULT 'unclassified'," .
        " status VARCHAR(16) NOT NULL DEFAULT 'open'," .
        " owner VARCHAR(128) NOT NULL DEFAULT ''," .
        " component VARCHAR(128) NOT NULL DEFAULT ''," .
        " notes TEXT NULL," .
        " creation_ts TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP," .
        " update_ts DATETIME NULL," .
        " PRIMARY KEY (id)," .
        " UNIQUE KEY idx_dsv_project_bug (testproject_id, bug_id)" .
        ") ENGINE=InnoDB DEFAULT CHARSET=utf8");
    return $table;
}

function dsvInferSeverity($bugId)
{
    $s = ' ' . strtolower(dsvText($bugId)) . ' ';
    $patterns = array(
        'critical' => '/(^|[^a-z])(blocker|critical|crit|s1|p0|showstopper)([^a-z]|$)/',
        'high' => '/(^|[^a-z])(high|major|maj|s2|p1)([^a-z]|$)/',
        'medium' => '/(^|[^a-z])(medium|med|normal|s3|p2)([^a-z]|$)/',
        'low' => '/(^|[^a-z])(low|minor|min|s4|p3|trivial)([^a-z]|$)/',
    );
    foreach ($patterns as $severity => $regex) {
        if (preg_match($regex, $s)) {
            return $severity;
        }
    }
    return 'unclassified';
}

function dsvPath($db, $tbl, $nodeId, $cache)
{
    $nodeId = intval($nodeId);
    if (isset($cache[$nodeId])) {
        return $cache[$nodeId];
    }
    $parts = array();
    $current = $nodeId;
    for ($depth = 0; $depth < 12 && $current > 0; $depth++) {
        $row = $db->get_recordset(
            "SELECT id, name, parent_id, node_type_id FROM {$tbl['nodes_hierarchy']}" .
            " WHERE id = " . $current);
        if (!is_array($row) || count($row) === 0) {
            break;
        }
        $r = $row[0];
        $parent = intval($r['parent_id']);
        if ($depth > 0 && intval($r['node_type_id']) !== 1) {
            $parts[] = dsvDecode($r['name']);
        }
        if (intval($r['node_type_id']) === 1 || $parent === $current) {
            break;
        }
        $current = $parent;
    }
    return implode(' / ', array_reverse($parts));
}

$method = strtoupper(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET');
if ($method !== 'GET' && $method !== 'HEAD' && $method !== 'POST') {
    header('Allow: GET, HEAD, POST');
    dsvFail(405, 'method_not_allowed');
}

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = isset($_SESSION['userID']) ? intval($_SESSION['userID']) : 0;
if ($userId <= 0) {
    dsvFail(401, 'not_authenticated', 'Not authenticated');
}
bffEnforceSession($db);

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    dsvFail(401, 'not_authenticated', 'User not found');
}

$action = strtolower(trim((string)(isset($_GET['action']) ? $_GET['action'] : 'init')));
if ($action === 'classify' && $method !== 'POST') {
    dsvFail(405, 'method_not_allowed', 'Classification requires POST');
}
if ($action !== 'classify' && $method === 'POST') {
    dsvFail(405, 'method_not_allowed', 'This action is read-only');
}
if (!in_array($action, array('init', 'report', 'detail', 'csv', 'classify'), true)) {
    dsvFail(400, 'invalid_request', 'Unknown action');
}

$tplanId = dsvPositiveInt(isset($_GET['tplan_id']) ? $_GET['tplan_id'] : null);
$assertedProjectId = dsvPositiveInt(isset($_GET['tproject_id']) ? $_GET['tproject_id'] : null);
$rawBuild = trim((string)(isset($_GET['build_id']) ? $_GET['build_id'] : '0'));
$rawPlatform = trim((string)(isset($_GET['platform_id']) ? $_GET['platform_id'] : '0'));
$severity = strtolower(trim((string)(isset($_GET['severity']) ? $_GET['severity'] : 'all')));
$status = strtolower(trim((string)(isset($_GET['status']) ? $_GET['status'] : 'all')));
$detailBugId = trim((string)(isset($_GET['bug_id']) ? $_GET['bug_id'] : ''));
$severities = array('all', 'critical', 'high', 'medium', 'low', 'unclassified');
$statuses = array('all', 'open', 'in_progress', 'fixed', 'closed', 'wontfix');
if (!in_array($severity, $severities, true) || !in_array($status, $statuses, true)) {
    dsvFail(400, 'invalid_request', 'Invalid filter');
}
if ($rawBuild !== '' && !preg_match('/^[0-9]+$/', $rawBuild)) {
    dsvFail(400, 'invalid_request', 'Invalid build_id');
}
if ($rawPlatform !== '' && !preg_match('/^[0-9]+$/', $rawPlatform)) {
    dsvFail(400, 'invalid_request', 'Invalid platform_id');
}
if ($detailBugId !== '' && $action !== 'detail' && $action !== 'classify') {
    dsvFail(400, 'invalid_request', 'bug_id is only valid for detail and classify');
}
if ($action === 'detail' && $detailBugId === '') {
    dsvFail(400, 'invalid_request', 'Missing bug_id');
}
$buildId = intval($rawBuild);
$platformId = intval($rawPlatform);

$body = array();
if ($action === 'classify') {
    $rawBody = file_get_contents('php://input');
    $decoded = json_decode($rawBody, true);
    if (is_array($decoded)) {
        $body = $decoded;
    }
    $tplanId = dsvPositiveInt(isset($body['tplan_id']) ? $body['tplan_id'] : $tplanId);
    $assertedProjectId = dsvPositiveInt(isset($body['tproject_id']) ? $body['tproject_id'] : $assertedProjectId);
}
if ($tplanId <= 0) {
    dsvFail(400, 'invalid_request', 'Missing or invalid tplan_id');
}

$planRow = $db->get_recordset(
    "SELECT id, testproject_id FROM testplans WHERE id = " . $tplanId);
if (!is_array($planRow) || count($planRow) === 0) {
    dsvFail(404, 'tplan_not_found', 'Test plan not found');
}
$owningProjectId = intval($planRow[0]['testproject_id']);
if ($owningProjectId <= 0) {
    dsvFail(404, 'tplan_not_found', 'Test plan has no owning test project');
}
$right = ($action === 'classify') ? 'mgt_modify_tc' : 'testplan_metrics';
if (!$user->hasRight($db, $right, $owningProjectId, $tplanId, true)) {
    tLog('BFF defectseverity: user ' . $userId . ' lacks ' . $right .
         ' on tproject ' . $owningProjectId, 'AUDIT');
    dsvFail(403, 'no_right', $right . ' right required');
}
if ($assertedProjectId > 0 && $assertedProjectId !== $owningProjectId) {
    dsvFail(404, 'project_mismatch', 'Test plan belongs to another test project');
}

$planName = testplan::getName($db, $tplanId);
$projectName = testproject::getName($db, $owningProjectId);
if (is_null($planName) || trim((string)$planName) === '' ||
    is_null($projectName) || trim((string)$projectName) === '') {
    dsvFail(404, 'tplan_not_found', 'Test plan not found');
}
$context = array(
    'tplan_id' => $tplanId,
    'tplan_name' => dsvDecode((string)$planName),
    'tproject_id' => $owningProjectId,
    'tproject_name' => dsvDecode((string)$projectName),
    'canClassify' => (bool)$user->hasRight($db, 'mgt_modify_tc', $owningProjectId, $tplanId, true),
);
$filter = array('build_id' => $buildId, 'platform_id' => $platformId,
                'severity' => $severity, 'status' => $status);

$tbl = dsvTables();
$sevTable = dsvEnsureSchema($db);

$builds = array();
$buildRs = $db->get_recordset(
    "SELECT id, name FROM {$tbl['builds']} WHERE testproject_id = " . $owningProjectId .
    " AND active = 1 ORDER BY id");
if (is_array($buildRs)) {
    foreach ($buildRs as $b) {
        $builds[] = array('id' => intval($b['id']), 'name' => dsvDecode($b['name']));
    }
}
$platforms = array();
$platformRs = $db->get_recordset(
    "SELECT p.id, p.name FROM {$tbl['platforms']} p" .
    " JOIN {$tbl['testplan_platforms']} tpp ON tpp.platform_id = p.id" .
    " WHERE tpp.testplan_id = " . $tplanId . " ORDER BY p.name");
if (is_array($platformRs)) {
    foreach ($platformRs as $p) {
        $platforms[] = array('id' => intval($p['id']), 'name' => dsvDecode($p['name']));
    }
}
$knownBuild = ($buildId === 0);
foreach ($builds as $b) { if ($b['id'] === $buildId) { $knownBuild = true; } }
if (!$knownBuild) {
    dsvFail(400, 'invalid_request', 'build_id does not belong to this test project');
}
$knownPlatform = ($platformId === 0);
foreach ($platforms as $p) { if ($p['id'] === $platformId) { $knownPlatform = true; } }
if (!$knownPlatform) {
    dsvFail(400, 'invalid_request', 'platform_id does not belong to this test plan');
}
$filtersPayload = array('builds' => $builds, 'platforms' => $platforms,
                        'severities' => $severities, 'statuses' => $statuses);

if ($action === 'init') {
    dsvOut(array('status' => 'ok', 'code' => 200, 'context' => $context,
                 'filters' => $filtersPayload, 'filter' => $filter));
}

if ($action === 'classify') {
    $bugId = dsvText(isset($body['bug_id']) ? $body['bug_id'] : '');
    $newSeverity = strtolower(trim((string)(isset($body['severity']) ? $body['severity'] : '')));
    $newStatus = strtolower(trim((string)(isset($body['status']) ? $body['status'] : '')));
    if ($bugId === '' || strlen($bugId) > 255 ||
        !in_array($newSeverity, array_slice($severities, 1), true) ||
        !in_array($newStatus, array_slice($statuses, 1), true)) {
        dsvFail(400, 'invalid_request', 'Invalid classification');
    }
    $owner = substr(dsvText(isset($body['owner']) ? $body['owner'] : ''), 0, 128);
    $component = substr(dsvText(isset($body['component']) ? $body['component'] : ''), 0, 128);
    $notes = substr(dsvText(isset($body['notes']) ? $body['notes'] : ''), 0, 4000);
    $esc = function ($v) use ($db) { return $db->prepare_string((string)$v); };
    $sql = "INSERT INTO {$sevTable} (testproject_id, bug_id, severity, status, owner, component, notes, update_ts)" .
           " VALUES (" . $owningProjectId . ", '" . $esc($bugId) . "', '" . $esc($newSeverity) .
           "', '" . $esc($newStatus) . "', '" . $esc($owner) . "', '" . $esc($component) .
           "', '" . $esc($notes) . "', NOW())" .
           " ON DUPLICATE KEY UPDATE severity = VALUES(severity), status = VALUES(status)," .
           " owner = VALUES(owner), component = VALUES(component), notes = VALUES(notes)," .
           " update_ts = NOW()";
    $db->exec_query($sql);
    tLog('BFF defectseverity: user ' . $userId . ' classified bug ' . $bugId .
         ' as ' . $newSeverity . '/' . $newStatus . ' on tproject ' . $owningProjectId, 'AUDIT');
    dsvOut(array('status' => 'ok', 'code' => 200, 'bug_id' => $bugId,
                 'severity' => $newSeverity, 'status' => $newStatus));
}

$execSql =
    "SELECT e.id, e.tcversion_id, e.status, e.build_id, e.platform_id," .
    " e.execution_ts, e.tester_id, e.notes, b.bug_id" .
    " FROM {$tbl['execution_bugs']} b" .
    " JOIN {$tbl['executions']} e ON e.id = b.execution_id" .
    " WHERE e.testplan_id = " . $tplanId;
if ($buildId > 0) {
    $execSql .= " AND e.build_id = " . $buildId;
}
if ($platformId > 0) {
    $execSql .= " AND (e.platform_id = " . $platformId . " OR e.platform_id = 0)";
}
$execRs = $db->get_recordset($execSql . " ORDER BY e.execution_ts ASC, e.id ASC");
if (!is_array($execRs)) {
    $execRs = array();
}
$metaRs = $db->get_recordset(
    "SELECT bug_id, severity, status, owner, component, notes FROM {$sevTable}" .
    " WHERE testproject_id = " . $owningProjectId);
$meta = array();
if (is_array($metaRs)) {
    foreach ($metaRs as $m) {
        $meta[dsvDecode($m['bug_id'])] = $m;
    }
}
$buildNames = array();
foreach ($builds as $b) { $buildNames[$b['id']] = $b['name']; }
$platformNames = array();
foreach ($platforms as $p) { $platformNames[$p['id']] = $p['name']; }
$pathCache = array();
$versionCache = array();

function dsvVersionInfo($db, $tbl, $tcversionId, &$cache, &$pathCache)
{
    $vid = intval($tcversionId);
    if (isset($cache[$vid])) {
        return $cache[$vid];
    }
    $rs = $db->get_recordset(
        "SELECT tv.version, tv.tc_external_id, nh_tc.id AS tcase_id," .
        " nh_tc.name AS tcase_name, tp.prefix" .
        " FROM {$tbl['tcversions']} tv" .
        " JOIN {$tbl['nodes_hierarchy']} nh_tv ON nh_tv.id = tv.id" .
        " JOIN {$tbl['nodes_hierarchy']} nh_tc ON nh_tc.id = nh_tv.parent_id" .
        " JOIN {$tbl['testprojects']} tp ON tp.id = " . intval($GLOBALS['dsvProjectId']) .
        " WHERE tv.id = " . $vid);
    if (!is_array($rs) || count($rs) === 0) {
        return array('version' => 0, 'external_id' => '', 'tcase_name' => '', 'path' => '');
    }
    $r = $rs[0];
    $info = array(
        'version' => intval($r['version']),
        'external_id' => trim((string)$r['prefix']) . '-' . intval($r['tc_external_id']),
        'tcase_name' => dsvDecode($r['tcase_name']),
        'path' => dsvPath($db, $tbl, intval($r['tcase_id']), $pathCache),
    );
    $pathCache[intval($r['tcase_id'])] = $info['path'];
    $cache[$vid] = $info;
    return $info;
}

$GLOBALS['dsvProjectId'] = $owningProjectId;
$bugs = array();
foreach ($execRs as $e) {
    $bugId = dsvText($e['bug_id']);
    if ($bugId === '') {
        continue;
    }
    if (!isset($bugs[$bugId])) {
        $sev = isset($meta[$bugId]) ? strtolower(trim((string)$meta[$bugId]['severity'])) : '';
        $stat = isset($meta[$bugId]) ? strtolower(trim((string)$meta[$bugId]['status'])) : 'open';
        if (!in_array($sev, array_slice($severities, 1), true)) {
            $sev = dsvInferSeverity($bugId);
        }
        if (!in_array($stat, array_slice($statuses, 1), true)) {
            $stat = 'open';
        }
        $bugs[$bugId] = array(
            'bug_id' => $bugId,
            'severity' => $sev,
            'severity_source' => (isset($meta[$bugId]) ? 'manual' : 'inferred'),
            'status' => $stat,
            'owner' => isset($meta[$bugId]) ? dsvText($meta[$bugId]['owner']) : '',
            'component' => isset($meta[$bugId]) ? dsvText($meta[$bugId]['component']) : '',
            'notes' => isset($meta[$bugId]) ? dsvText($meta[$bugId]['notes']) : '',
            'links' => 0, 'executions' => 0, 'passed' => 0, 'failed' => 0,
            'blocked' => 0, 'cases' => array(), 'suites' => array(),
            'builds' => array(), 'platforms' => array(),
            'first_ts' => '', 'last_ts' => '', 'evidence' => array(),
        );
    }
    $item = &$bugs[$bugId];
    $item['links']++;
    $item['executions']++;
    $state = strtolower(trim((string)$e['status']));
    if ($state === 'p') { $item['passed']++; }
    if ($state === 'f') { $item['failed']++; }
    if ($state === 'b') { $item['blocked']++; }
    $info = dsvVersionInfo($db, $tbl, intval($e['tcversion_id']), $versionCache, $pathCache);
    $item['cases'][$info['external_id'] . ' ' . $info['tcase_name']] = true;
    if ($info['path'] !== '') { $item['suites'][explode(' / ', $info['path'])[0]] = true; }
    $buildName = isset($buildNames[intval($e['build_id'])]) ? $buildNames[intval($e['build_id'])] : '';
    $platformName = intval($e['platform_id']) > 0 && isset($platformNames[intval($e['platform_id'])])
        ? $platformNames[intval($e['platform_id'])] : '';
    if ($buildName !== '') { $item['builds'][$buildName] = true; }
    if ($platformName !== '') { $item['platforms'][$platformName] = true; }
    $ts = (string)($e['execution_ts'] ?? '');
    if ($item['first_ts'] === '' || $ts < $item['first_ts']) { $item['first_ts'] = $ts; }
    if ($item['last_ts'] === '' || $ts > $item['last_ts']) { $item['last_ts'] = $ts; }
    $item['evidence'][] = array(
        'execution_id' => intval($e['id']),
        'status' => $state === 'p' ? 'passed' : ($state === 'f' ? 'failed' : ($state === 'b' ? 'blocked' : 'not_run')),
        'test_case' => $info['external_id'] . ' ' . $info['tcase_name'],
        'version' => $info['version'],
        'suite' => $info['path'],
        'build' => $buildName,
        'platform' => $platformName,
        'tester_id' => intval($e['tester_id']),
        'execution_ts' => $ts,
        'notes' => dsvText($e['notes']),
    );
    unset($item);
}

$allRows = array();
foreach ($bugs as $bugId => $item) {
    if ($severity !== 'all' && $item['severity'] !== $severity) { continue; }
    if ($status !== 'all' && $item['status'] !== $status) { continue; }
    $sevRank = array('critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1, 'unclassified' => 0);
    $verdict = 'low';
    if ($item['status'] === 'closed' || $item['status'] === 'wontfix' || $item['status'] === 'fixed') {
        $verdict = 'resolved';
    } elseif ($item['severity'] === 'critical' && $item['links'] >= 3) {
        $verdict = 'blocker';
    } elseif ($sevRank[$item['severity']] >= 3 && $item['status'] === 'open') {
        $verdict = 'high';
    } elseif ($item['severity'] === 'medium') {
        $verdict = 'watch';
    }
    $item['verdict'] = $verdict;
    $item['affected_cases'] = count($item['cases']);
    $item['affected_suites'] = count($item['suites']);
    $item['build_count'] = count($item['builds']);
    $item['platform_count'] = count($item['platforms']);
    $caseNames = array_keys($item['cases']);
    sort($caseNames);
    $suiteNames = array_keys($item['suites']);
    sort($suiteNames);
    $buildNamesRow = array_keys($item['builds']);
    sort($buildNamesRow);
    $platformNamesRow = array_keys($item['platforms']);
    sort($platformNamesRow);
    unset($item['cases'], $item['suites'], $item['builds'], $item['platforms']);
    $item['case_names'] = $caseNames;
    $item['suite_names'] = $suiteNames;
    $item['build_names'] = $buildNamesRow;
    $item['platform_names'] = $platformNamesRow;
    $evidence = $item['evidence'];
    unset($item['evidence']);
    $item['evidence'] = $evidence;
    $allRows[] = $item;
}
unset($bugs);

function dsvSortRows($a, $b)
{
    $rank = array('blocker' => 6, 'high' => 5, 'watch' => 4, 'low' => 3,
                  'resolved' => 2, 'unclassified' => 1);
    $cmp = $rank[$b['verdict']] - $rank[$a['verdict']];
    if ($cmp !== 0) { return $cmp; }
    $cmp = $b['links'] - $a['links'];
    if ($cmp !== 0) { return $cmp; }
    return strcmp($a['bug_id'], $b['bug_id']);
}
usort($allRows, 'dsvSortRows');

if ($action === 'detail') {
    $detail = null;
    foreach ($allRows as $r) {
        if ($r['bug_id'] === $detailBugId) { $detail = $r; break; }
    }
    if ($detail === null) {
        dsvFail(404, 'defect_not_found', 'Defect is not part of this report');
    }
    dsvOut(array('status' => 'ok', 'code' => 200, 'context' => $context,
                 'item' => $detail, 'executions' => $detail['evidence']));
}

$summary = array(
    'total' => 0, 'links' => 0, 'failed_runs' => 0, 'passed_runs' => 0,
    'affected_cases' => 0, 'affected_suites' => 0,
    'critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'unclassified' => 0,
    'open' => 0, 'in_progress' => 0, 'fixed' => 0, 'closed' => 0, 'wontfix' => 0,
    'blockers' => 0, 'open_high' => 0, 'severity_index' => 0.0,
);
$allCases = array();
$allSuites = array();
foreach ($allRows as $r) {
    $summary['total']++;
    $summary['links'] += $r['links'];
    $summary['failed_runs'] += $r['failed'];
    $summary['passed_runs'] += $r['passed'];
    $summary[$r['severity']]++;
    $summary[$r['status']]++;
    if ($r['verdict'] === 'blocker') { $summary['blockers']++; }
    if ($r['severity'] === 'critical' || $r['severity'] === 'high') {
        if ($r['status'] === 'open' || $r['status'] === 'in_progress') {
            $summary['open_high']++;
        }
    }
    foreach ($r['case_names'] as $name) { $allCases[$name] = true; }
    foreach ($r['suite_names'] as $name) { $allSuites[$name] = true; }
}
$summary['affected_cases'] = count($allCases);
$summary['affected_suites'] = count($allSuites);
$summary['severity_index'] = dsvPct($summary['critical'] + $summary['high'], $summary['total']);

if ($summary['total'] === 0) {
    $verdict = 'no_data';
} elseif ($summary['blockers'] > 0 || $summary['critical'] > 0) {
    $verdict = 'at_risk';
} elseif ($summary['high'] > 0 || $summary['unclassified'] > 0) {
    $verdict = 'watch';
} else {
    $verdict = 'clear';
}

$rows = array();
foreach ($allRows as $r) {
    unset($r['evidence']);
    $rows[] = $r;
}

if ($action === 'csv') {
    if (ob_get_level() > 0) { ob_clean(); }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="defect-severity.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, array('defect', 'severity', 'severity_source', 'status', 'owner',
        'component', 'links', 'failed_runs', 'passed_runs', 'affected_cases',
        'affected_suites', 'builds', 'platforms', 'first_ts', 'last_ts',
        'verdict', 'notes'), ';');
    foreach ($rows as $r) {
        fputcsv($out, array(
            dsvCsvCell($r['bug_id']), dsvCsvCell($r['severity']),
            dsvCsvCell($r['severity_source']), dsvCsvCell($r['status']),
            dsvCsvCell($r['owner']), dsvCsvCell($r['component']),
            $r['links'], $r['failed'], $r['passed'], $r['affected_cases'],
            $r['affected_suites'], dsvCsvCell(implode(' | ', $r['build_names'])),
            dsvCsvCell(implode(' | ', $r['platform_names'])),
            dsvCsvCell($r['first_ts']), dsvCsvCell($r['last_ts']),
            dsvCsvCell($r['verdict']), dsvCsvCell($r['notes']),
        ), ';');
    }
    fclose($out);
    exit;
}

dsvOut(array('status' => 'ok', 'code' => 200, 'context' => $context,
             'filter' => $filter, 'filters' => $filtersPayload,
             'summary' => $summary, 'rows' => $rows, 'verdict' => $verdict));
