<?php
/**
 * CI/CD Automated Result Publishing - REST BFF API
 * URL: /api/cicdpublish/index.php
 * Plain PHP, no framework, no compilation
 *
 * Refs #1064 (enhancement). Automated tests are normally run by a CI/CD
 * platform and their results stay isolated in the pipeline logs / framework
 * reports. 1.9.20 shipped no CI/CD-facing entry point at all: the only way to
 * get automated results into TestLink was the TestLink-XML "Import Results"
 * screen (lib/results/resultsImport.php), which expects TestLink's own XML
 * dialect - never the JUnit XML that GitHub Actions / GitLab / Jenkins /
 * Azure DevOps actually produce.
 *
 * The ledger's TODO section is empty (every ASIDE entry already maps to a
 * modern .html + BFF), so - exactly like #1061/#1062/#1063/#1065/#1067/#1068 -
 * this run builds the next coherent, genuinely-MISSING screen instead of a
 * legacy port: a CI/CD result PUBLISHING screen that accepts the ubiquitous
 * JUnit XML artifact (and a flat JSON equivalent the CLI/API connectors emit),
 * resolves each test back to the test case assigned to the chosen test plan
 * (by external id first, then by name) and records one execution per test on
 * the chosen build/platform.
 *
 * Contract:
 *   GET|HEAD ?action=init[&tproject_id=P][&tplan_id=N]
 *     200 {status, context, projects, plans, filters, formats, endpoint, limits}
 *   POST ?action=publish  (multipart: tplan_id, build_id[, platform_id],
 *                          format=junit|json, uploadedFile)
 *     200 {status, summary, rows}
 *   400 invalid_request / unsupported_format / missing_file / file_too_large
 *   401 not_authenticated / session_expired
 *   403 no_right
 *   404 tplan_not_found / project_mismatch
 *   405 method_not_allowed
 *   413 file_too_large | 422 parse_error
 *
 * SECURITY
 * Session auth + bffSameOriginGuard + bffEnforceSession; right testplan_execute
 * held on the OWNING test project (a write gate, matching the execution
 * screens). The owning project is resolved from the plan, never from a client
 * id, and an asserted tproject_id is only compared afterwards so the endpoint
 * cannot be used as a project-existence oracle (the #1697 lesson). Every id is
 * a strictly validated positive integer; no string interpolation into SQL. The
 * upload is size-capped and parsed with LIBXML_NONET (no XXE).
 *
 * Refs #1064
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

function cpOut($payload, $code = null)
{
    if ($code !== null) {
        http_response_code($code);
    }
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

function cpFail($httpCode, $code, $message = '')
{
    cpOut(array(
        'status' => 'error',
        'code' => $code,
        'message' => $message !== '' ? $message : $code,
    ), $httpCode);
}

/** Strict positive-int reader (rejects arrays, floats, negatives, garbage). */
function cpPosInt($key, $default = 0)
{
    $v = $_REQUEST[$key] ?? null;
    if ($v === null || is_array($v)) {
        return $default;
    }
    if (!is_string($v) && !is_int($v)) {
        return $default;
    }
    $s = trim((string)$v);
    if ($s === '' || !preg_match('/^[0-9]+$/', $s)) {
        return $default;
    }
    $n = (int)$s;
    return $n > 0 ? $n : $default;
}

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = isset($_SESSION['userID']) ? intval($_SESSION['userID']) : 0;
if ($userId <= 0) {
    cpFail(401, 'not_authenticated', 'Not authenticated');
}

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    cpFail(401, 'not_authenticated', 'User not found');
}

bffEnforceSession($db);

$action = isset($_REQUEST['action']) && is_string($_REQUEST['action'])
    ? $_REQUEST['action'] : 'init';

$tbl = tlObjectWithDB::getDBTables(array(
    'testplans', 'builds', 'platforms', 'testplan_tcversions', 'tcversions',
    'nodes_hierarchy', 'executions', 'users',
));

/**
 * Resolve the addressed test project (request or session) and prove the
 * current user holds testplan_execute on it. Returns the project id.
 */
function cpResolveProject($db, $user, $tbl)
{
    $tprojectId = cpPosInt('tproject_id', 0);
    if ($tprojectId <= 0) {
        $tprojectId = intval($_SESSION['testprojectID'] ?? 0);
    }
    if ($tprojectId <= 0) {
        cpFail(400, 'invalid_request', 'No test project selected');
    }
    if (!$user->hasRight($db, 'testplan_execute', $tprojectId)) {
        // Refs #1940: AUDIT, not ERROR - a denied read is a normal access
        // decision, and an ERROR row per denial would pollute the Event
        // Viewer on every permission probe (the #1833/#1807 lesson).
        tLog('BFF cicdpublish: user ' . intval($user->dbID ?? 0) .
             ' has no testplan_execute right on tproject ' . $tprojectId, 'AUDIT');
        cpFail(403, 'no_right', 'testplan_execute right required');
    }
    $name = testproject::getName($db, $tprojectId);
    if (is_null($name) || trim((string)$name) === '') {
        cpFail(404, 'tplan_not_found', 'Test project not found');
    }
    return array('id' => $tprojectId, 'name' => (string)$name);
}

/**
 * Resolve a test plan and prove the user may write executions on its OWNING
 * project (never the client-supplied tproject_id). Returns plan context.
 */
function cpResolvePlan($db, $user, $tbl, $tplanId)
{
    $row = $db->get_recordset(
        "SELECT id, testproject_id FROM {$tbl['testplans']} WHERE id = " . intval($tplanId));
    if (!is_array($row) || count($row) === 0) {
        cpFail(404, 'tplan_not_found', 'Test plan not found');
    }
    $owningProjectId = intval($row[0]['testproject_id']);
    if ($owningProjectId <= 0) {
        cpFail(404, 'tplan_not_found', 'Test plan has no owning test project');
    }
    if (!$user->hasRight($db, 'testplan_execute', $owningProjectId, $tplanId, true)) {
        tLog('BFF cicdpublish: user ' . intval($user->dbID ?? 0) .
             ' has no testplan_execute right on owning project ' . $owningProjectId .
             ' (tplan ' . intval($tplanId) . ')', 'AUDIT');
        cpFail(403, 'no_right', 'testplan_execute right required');
    }
    $asserted = cpPosInt('tproject_id', 0);
    if ($asserted > 0 && $asserted !== $owningProjectId) {
        cpFail(404, 'project_mismatch', 'Test plan belongs to another test project');
    }
    $planName = testplan::getName($db, $tplanId);
    if (is_null($planName) || trim((string)$planName) === '') {
        cpFail(404, 'tplan_not_found', 'Test plan not found');
    }
    return array(
        'id' => intval($tplanId),
        'name' => (string)$planName,
        'tproject_id' => $owningProjectId,
        'tproject_name' => (string)testproject::getName($db, $owningProjectId),
    );
}

function cpBuilds($db, $tbl, $tprojectId)
{
    $out = array();
    $rs = $db->get_recordset(
        "SELECT id, name FROM {$tbl['builds']} WHERE testproject_id = " . intval($tprojectId) .
        " AND active = 1 ORDER BY name");
    if (is_array($rs)) {
        foreach ($rs as $r) {
            $out[] = array(
                'id' => intval($r['id']),
                'name' => html_entity_decode((string)$r['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            );
        }
    }
    return $out;
}

function cpPlatforms($db, $tbl, $tprojectId)
{
    $out = array();
    $rs = $db->get_recordset(
        "SELECT id, name FROM {$tbl['platforms']} WHERE testproject_id = " . intval($tprojectId) .
        " ORDER BY name");
    if (is_array($rs)) {
        foreach ($rs as $r) {
            $out[] = array(
                'id' => intval($r['id']),
                'name' => html_entity_decode((string)$r['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            );
        }
    }
    return $out;
}

function cpPlans($db, $tbl, $tprojectId)
{
    $out = array();
    $rs = $db->get_recordset(
        "SELECT TP.id, NH.name FROM {$tbl['testplans']} TP " .
        "JOIN {$tbl['nodes_hierarchy']} NH ON NH.id = TP.id " .
        "WHERE TP.testproject_id = " . intval($tprojectId) . " AND TP.active = 1 " .
        "ORDER BY NH.name");
    if (is_array($rs)) {
        foreach ($rs as $r) {
            $out[] = array(
                'id' => intval($r['id']),
                'name' => html_entity_decode((string)$r['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            );
        }
    }
    return $out;
}

/** Map a JUnit/JSON status token to a TestLink status code (config domain). */
function cpStatusMap()
{
    $cfg = config_get('results');
    $codes = array();
    if (isset($cfg['status_code']) && is_array($cfg['status_code'])) {
        foreach ($cfg['status_code'] as $verbose => $code) {
            $codes[strtolower((string)$verbose)] = (string)$code;
            $codes[strtolower((string)$code)] = (string)$code;
        }
    }
    $aliases = array(
        'pass' => 'p', 'passed' => 'p', 'success' => 'p', 'successful' => 'p',
        'fail' => 'f', 'failed' => 'f', 'failure' => 'f', 'error' => 'f',
        'blocked' => 'b', 'block' => 'b',
        'skip' => 'n', 'skipped' => 'n', 'disabled' => 'n',
        'not_run' => 'n', 'notrun' => 'n', 'pending' => 'n', 'unknown' => 'n',
    );
    foreach ($aliases as $k => $v) {
        if (!isset($codes[$k])) {
            $codes[$k] = $v;
        }
    }
    return $codes;
}

// ===========================================================================
// init
// ===========================================================================
if ($action === 'init') {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'HEAD') {
        cpFail(405, 'method_not_allowed', 'Use GET');
    }
    $project = cpResolveProject($db, $user, $tbl);
    $plans = cpPlans($db, $tbl, $project['id']);

    $tplanId = cpPosInt('tplan_id', intval($_SESSION['testplanID'] ?? 0));
    $context = null;
    $builds = array();
    $platforms = array();
    if ($tplanId > 0) {
        $plan = cpResolvePlan($db, $user, $tbl, $tplanId);
        $context = $plan;
        $builds = cpBuilds($db, $tbl, $plan['tproject_id']);
        $platforms = cpPlatforms($db, $tbl, $plan['tproject_id']);
    }

    cpOut(array(
        'status' => 'ok',
        'code' => 200,
        'context' => $context,
        'projects' => array($project),
        'plans' => $plans,
        'filters' => array('builds' => $builds, 'platforms' => $platforms),
        'formats' => array(
            array('id' => 'junit', 'name' => 'JUnit XML'),
            array('id' => 'json', 'name' => 'JSON'),
        ),
        'endpoint' => '/api/cicdpublish/index.php?action=publish',
        'limits' => array(
            'file_size' => intval(config_get('import_file_max_size_bytes')),
        ),
    ));
}

// ===========================================================================
// publish
// ===========================================================================
if ($action === 'publish') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        cpFail(405, 'method_not_allowed', 'Use POST');
    }

    $tplanId = cpPosInt('tplan_id', 0);
    if ($tplanId <= 0) {
        cpFail(400, 'invalid_request', 'Missing or invalid tplan_id');
    }
    $plan = cpResolvePlan($db, $user, $tbl, $tplanId);

    $builds = cpBuilds($db, $tbl, $plan['tproject_id']);
    $buildId = cpPosInt('build_id', 0);
    $buildKnown = false;
    foreach ($builds as $b) {
        if ($b['id'] === $buildId) { $buildKnown = true; break; }
    }
    if ($buildId <= 0 || !$buildKnown) {
        cpFail(400, 'invalid_request', 'build_id is required and must belong to this test project');
    }

    $platforms = cpPlatforms($db, $tbl, $plan['tproject_id']);
    $platformId = cpPosInt('platform_id', 0);
    if ($platformId > 0) {
        $pKnown = false;
        foreach ($platforms as $p) {
            if ($p['id'] === $platformId) { $pKnown = true; break; }
        }
        if (!$pKnown) {
            cpFail(400, 'invalid_request', 'platform_id does not belong to this test project');
        }
    }

    $format = isset($_REQUEST['format']) && is_string($_REQUEST['format'])
        ? strtolower(trim($_REQUEST['format'])) : 'junit';
    if ($format !== 'junit' && $format !== 'json') {
        cpFail(400, 'unsupported_format', 'Unsupported result format');
    }

    if (!isset($_FILES['uploadedFile']) || !is_array($_FILES['uploadedFile'])
        || ($_FILES['uploadedFile']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        cpFail(400, 'missing_file', 'Please choose a results file to publish');
    }
    $tmpName = (string)($_FILES['uploadedFile']['tmp_name'] ?? '');
    $size = intval($_FILES['uploadedFile']['size'] ?? 0);
    $maxBytes = intval(config_get('import_file_max_size_bytes'));
    if ($maxBytes > 0 && $size > $maxBytes) {
        cpFail(413, 'file_too_large', 'The file exceeds the maximum allowed size');
    }
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        cpFail(400, 'missing_file', 'Invalid upload');
    }
    $raw = @file_get_contents($tmpName);
    if ($raw === false || trim($raw) === '') {
        cpFail(422, 'parse_error', 'The results file is empty or could not be read');
    }

    // ---- parse into a normalized list of entries ---------------------------
    $entries = array();
    if ($format === 'junit') {
        libxml_use_internal_errors(true);
        $xml = @simplexml_load_string($raw, 'SimpleXMLElement', LIBXML_NONET);
        libxml_clear_errors();
        if ($xml === false) {
            cpFail(422, 'parse_error', 'The JUnit XML could not be parsed');
        }
        $root = $xml->getName();
        if ($root !== 'testsuites' && $root !== 'testsuite') {
            cpFail(422, 'parse_error', 'The file is not a JUnit XML document');
        }
        $nodes = $xml->xpath('//testcase');
        if (!is_array($nodes)) { $nodes = array(); }
        foreach ($nodes as $tc) {
            $name = isset($tc['name']) ? (string)$tc['name'] : '';
            $classname = isset($tc['classname']) ? (string)$tc['classname'] : '';
            $extId = '';
            if (isset($tc->properties) && isset($tc->properties->property)) {
                foreach ($tc->properties->property as $prop) {
                    $pname = strtolower(trim((string)($prop['name'] ?? '')));
                    if ($pname === 'testlink.external_id' || $pname === 'testlink_external_id' || $pname === 'external_id') {
                        $extId = trim((string)($prop['value'] ?? ''));
                        break;
                    }
                }
            }
            $status = 'passed';
            $message = '';
            if (isset($tc->failure)) {
                $status = 'failed';
                $message = trim((string)$tc->failure);
            } elseif (isset($tc->error)) {
                $status = 'failed';
                $message = trim((string)$tc->error);
            } elseif (isset($tc->skipped)) {
                $status = 'skipped';
                $message = trim((string)$tc->skipped);
            }
            $entries[] = array(
                'name' => $name,
                'classname' => $classname,
                'external_id' => $extId,
                'status' => $status,
                'duration' => isset($tc['time']) ? (float)$tc['time'] : 0,
                'message' => $message,
            );
        }
    } else { // json
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            cpFail(422, 'parse_error', 'The JSON could not be parsed');
        }
        $list = null;
        foreach (array('tests', 'testcases', 'results') as $k) {
            if (isset($decoded[$k]) && is_array($decoded[$k])) { $list = $decoded[$k]; break; }
        }
        if ($list === null && isset($decoded[0]) && is_array($decoded[0])) {
            $list = $decoded;
        }
        if ($list === null) {
            cpFail(422, 'parse_error', 'The JSON has no tests array');
        }
        foreach ($list as $row) {
            if (!is_array($row)) { continue; }
            $entries[] = array(
                'name' => isset($row['name']) && is_scalar($row['name']) ? (string)$row['name'] : '',
                'classname' => isset($row['classname']) && is_scalar($row['classname']) ? (string)$row['classname'] : '',
                'external_id' => isset($row['external_id']) && is_scalar($row['external_id']) ? trim((string)$row['external_id']) : '',
                'status' => isset($row['status']) && is_scalar($row['status']) ? (string)$row['status'] : 'passed',
                'duration' => isset($row['duration']) && is_numeric($row['duration']) ? (float)$row['duration'] : 0,
                'message' => isset($row['message']) && is_scalar($row['message']) ? (string)$row['message'] : '',
            );
        }
    }

    if (count($entries) === 0) {
        cpFail(422, 'parse_error', 'No test cases were found in the file');
    }

    $statusCodes = cpStatusMap();
    $testerId = $userId;

    /**
     * Resolve a test case assigned to the plan. External id first (robust),
     * then name. Returns [tcversion_id, version, execution_type] or null.
     */
    $resolve = function ($entry) use ($db, $tbl, $tplanId, $plan) {
        $tplan = intval($tplanId);
        if ($entry['external_id'] !== '') {
            $ext = trim($entry['external_id']);
            // Accept "PREFIX-123" or a bare numeric id.
            if (preg_match('/([0-9]+)$/', $ext, $m)) {
                $num = intval($m[1]);
                $sql = "SELECT TVT.tcversion_id, TCV.version, TCV.execution_type " .
                       "FROM {$tbl['testplan_tcversions']} TVT " .
                       "JOIN {$tbl['tcversions']} TCV ON TCV.id = TVT.tcversion_id " .
                       "WHERE TVT.testplan_id = {$tplan} AND TCV.tc_external_id = {$num} " .
                       "ORDER BY TCV.version DESC LIMIT 1";
                $rs = $db->get_recordset($sql);
                if (is_array($rs) && count($rs) > 0) {
                    return $rs[0];
                }
            }
        }
        if ($entry['name'] !== '') {
            $name = $db->prepare_string($entry['name']);
            $sql = "SELECT TVT.tcversion_id, TCV.version, TCV.execution_type " .
                   "FROM {$tbl['testplan_tcversions']} TVT " .
                   "JOIN {$tbl['tcversions']} TCV ON TCV.id = TVT.tcversion_id " .
                   "JOIN {$tbl['nodes_hierarchy']} VN ON VN.id = TVT.tcversion_id " .
                   "JOIN {$tbl['nodes_hierarchy']} TN ON TN.id = VN.parent_id AND TN.node_type_id = 3 " .
                   "WHERE TVT.testplan_id = {$tplan} AND TN.name = '{$name}' " .
                   "ORDER BY TCV.version DESC LIMIT 1";
            $rs = $db->get_recordset($sql);
            if (is_array($rs) && count($rs) > 0) {
                return $rs[0];
            }
        }
        return null;
    };

    $summary = array('total' => count($entries), 'published' => 0, 'skipped' => 0, 'errors' => 0);
    $rows = array();
    foreach ($entries as $entry) {
        $token = strtolower(trim((string)$entry['status']));
        $code = isset($statusCodes[$token]) ? $statusCodes[$token] : 'n';
        $tc = $resolve($entry);
        if ($tc === null) {
            $summary['skipped']++;
            $rows[] = array(
                'name' => $entry['name'] !== '' ? $entry['name'] : $entry['external_id'],
                'classname' => $entry['classname'],
                'external_id' => $entry['external_id'],
                'status' => $code,
                'outcome' => 'not_found',
            );
            continue;
        }
        $tcversionId = intval($tc['tcversion_id']);
        $version = intval($tc['version']);
        $execType = intval($tc['execution_type']) > 0 ? intval($tc['execution_type']) : 1;
        $notes = $entry['message'] !== '' ? $db->prepare_string($entry['message']) : '';
        $duration = $entry['duration'];
        $addDuration = ($duration > 0) ? ',execution_duration' : '';
        $durationVal = ($duration > 0) ? ',' . (float)$duration : '';
        $sql = "INSERT INTO {$tbl['executions']} " .
               "(build_id,tester_id,execution_ts,status,testplan_id,tcversion_id," .
               "tcversion_number,platform_id,execution_type,notes{$addDuration}) VALUES (" .
               intval($buildId) . "," . intval($testerId) . "," . $db->db_now() . ",'{$code}'," .
               intval($tplanId) . "," . $tcversionId . "," . $version . "," . intval($platformId) . "," .
               $execType . ",'{$notes}'{$durationVal})";
        try {
            $db->exec_query($sql);
            $summary['published']++;
            $rows[] = array(
                'name' => $entry['name'] !== '' ? $entry['name'] : $entry['external_id'],
                'classname' => $entry['classname'],
                'external_id' => $entry['external_id'],
                'status' => $code,
                'outcome' => 'published',
            );
        } catch (\Throwable $e) {
            $summary['errors']++;
            $rows[] = array(
                'name' => $entry['name'] !== '' ? $entry['name'] : $entry['external_id'],
                'classname' => $entry['classname'],
                'external_id' => $entry['external_id'],
                'status' => $code,
                'outcome' => 'error',
            );
        }
    }

    cpOut(array(
        'status' => 'ok',
        'code' => 200,
        'context' => $plan,
        'build_id' => $buildId,
        'platform_id' => $platformId,
        'summary' => $summary,
        'rows' => $rows,
    ));
}

cpFail(400, 'invalid_request', 'Unknown action');
