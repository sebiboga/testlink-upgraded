<?php
/**
 * Performance Test Reports / JMeter JTL Viewer - REST BFF API
 * URL: /api/perfreports/index.php
 * Plain PHP, no framework, no compilation.
 *
 * Modern twin of the (net-new) "Performance Test Reports" hub requested by
 * enhancement #1063. TestLink 1.9.20 shipped no JMeter/JTL viewer, and the
 * schema has no automation/performance result tables, so this slice is
 * deliberately STATELESS: the caller uploads a JTL file (Apache JMeter's
 * result log, CSV or XML), the BFF parses it in a streaming fashion and
 * answers a performance report (summary + per-label aggregates + a capped
 * sample table + CSV-ready data). Nothing is persisted.
 *
 * Contract:
 *   GET|HEAD ?action=init[&tproject_id=N]
 *       -> 200 {status,code,context:{tproject_id,tproject_name}}
 *   POST ?action=parse  multipart: jtlFile=<file>[&tproject_id=N]
 *       -> 200 {status,code,format,context:{...},
 *               summary:{samples,errors,success_rate,error_rate,
 *                        total_bytes,min_elapsed,max_elapsed,avg_elapsed,
 *                        p50,p90,p95,p99,start_ts,end_ts,duration_sec,
 *                        throughput},
 *               labels:[{label,samples,errors,error_rate,avg,min,max,p90,
 *                        p95,total_bytes,throughput}],
 *               samples:[{ts,elapsed,label,response_code,response_message,
 *                         success,bytes,thread}],
 *               samples_total, samples_shown, labels_total, truncated,
 *               empty:false}
 *       -> 200 {..., empty:true} for a valid but sample-less JTL
 *   Errors: 400 invalid_request | 401 not_authenticated / session_expired
 *           403 no_right | 404 tproject_not_found | 405 method_not_allowed
 *           413 file_too_large | 422 empty_file / unsupported_format / unparsable_file
 *
 * Security: session auth + bffSameOriginGuard + bffEnforceSession; the
 * addressed test project is proved to exist and testplan_metrics is required
 * on it (the read/metrics right used by every sibling report). XML is parsed
 * with the streaming XMLReader + LIBXML_NONET, so a hostile JTL cannot trigger
 * XXE or entity expansion. Every scalar parameter is validated before use.
 *
 * Refs #1063
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

/** Hard cap on the uploaded JTL size (bytes). */
define('PERF_MAX_FILE_BYTES', 32 * 1024 * 1024);
/** Hard cap on parsed samples (bounds memory; sets the truncated flag). */
define('PERF_MAX_SAMPLES', 300000);
/** Rows returned to the screen's raw-samples table. */
define('PERF_MAX_SAMPLE_ROWS', 200);

function prOut($payload, $code = null)
{
    if ($code !== null) {
        http_response_code($code);
    }
    echo json_encode($payload);
    exit;
}

function prFail($httpCode, $code, $message = '')
{
    prOut(array(
        'status' => 'error',
        'code' => $code,
        'message' => $message !== '' ? $message : $code,
    ), $httpCode);
}

/** Strict positive integer reader - "1abc", "1.9", "-3" and "" are all refused. */
function prPositiveInt($raw)
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

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD' && $method !== 'POST') {
    header('Allow: GET, HEAD, POST');
    prFail(405, 'method_not_allowed');
}

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    prFail(401, 'not_authenticated', 'Not authenticated');
}

bffEnforceSession($db);

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    prFail(401, 'not_authenticated', 'User not found');
}

$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if (is_array($_GET['action'] ?? null)) {
    prFail(400, 'invalid_request', 'Unknown action');
}

/**
 * Resolve the addressed test project and prove the caller may read its
 * metrics. A missing/foreign/unknown project all fail closed.
 */
function prResolveProject($db, $user)
{
    $tprojectMgr = new testproject($db);
    $tprojectId = prPositiveInt($_REQUEST['tproject_id'] ?? null);
    if ($tprojectId <= 0) {
        $tprojectId = prPositiveInt($_SESSION['testprojectID'] ?? null);
    }
    if ($tprojectId <= 0) {
        prFail(400, 'invalid_request', 'No test project given');
    }

    if (!$user->hasRight($db, 'testplan_metrics', $tprojectId)) {
        prFail(403, 'no_right', 'No permission');
    }

    $info = $tprojectMgr->get_by_id($tprojectId);
    if (is_null($info) || !isset($info['name'])) {
        prFail(404, 'tproject_not_found', 'Test project not found');
    }

    return array(
        'tproject_id' => $tprojectId,
        'tproject_name' => (string)$info['name'],
    );
}

/**
 * Percentile from an ALREADY SORTED numeric array (nearest-rank).
 */
function prPercentile($sorted, $p)
{
    $n = count($sorted);
    if ($n === 0) {
        return 0;
    }
    $rank = (int)ceil(($p / 100) * $n) - 1;
    if ($rank < 0) {
        $rank = 0;
    }
    if ($rank >= $n) {
        $rank = $n - 1;
    }
    return $sorted[$rank];
}

/**
 * Peek the first non-whitespace bytes to pick the parser.
 */
function prDetectFormat($path)
{
    $fh = @fopen($path, 'rb');
    if ($fh === false) {
        return 'csv';
    }
    $head = fread($fh, 4096);
    fclose($fh);
    if ($head === false) {
        return 'csv';
    }
    $head = ltrim($head, "\xEF\xBB\xBF \t\r\n");
    return (isset($head[0]) && $head[0] === '<') ? 'xml' : 'csv';
}

/**
 * Parse a CSV JTL into the accumulator. Header-driven when present, so column
 * order/extra columns never break the reader.
 */
function prParseCsv($path, &$acc)
{
    $fh = @fopen($path, 'rb');
    if ($fh === false) {
        return false;
    }

    $idx = null;
    $line = 0;
    while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
        $line++;
        if ($row === array(null)) {
            continue; // blank line
        }
        if ($idx === null) {
            // Ignore a UTF-8 BOM on the header's first cell.
            $row[0] = ltrim((string)$row[0], "\xEF\xBB\xBF");
            $map = array();
            foreach ($row as $i => $name) {
                $map[strtolower(trim((string)$name))] = $i;
            }
            if (!isset($map['elapsed']) || !isset($map['label'])) {
                fclose($fh);
                return false; // not a JTL CSV
            }
            $idx = array(
                'ts' => $map['timestamp'] ?? (isset($map['time']) ? $map['time'] : null),
                'elapsed' => $map['elapsed'],
                'label' => $map['label'],
                'rc' => $map['responsecode'] ?? null,
                'rm' => $map['responsemessage'] ?? null,
                'success' => $map['success'] ?? null,
                'bytes' => $map['bytes'] ?? null,
                'thread' => $map['threadname'] ?? null,
            );
            continue;
        }

        if (!isset($row[$idx['elapsed']]) || !isset($row[$idx['label']])) {
            continue;
        }
        $elapsedRaw = trim((string)$row[$idx['elapsed']]);
        if ($elapsedRaw === '' || !is_numeric($elapsedRaw)) {
            continue;
        }
        $elapsed = (int)round((float)$elapsedRaw);
        $label = (string)$row[$idx['label']];
        $rc = ($idx['rc'] !== null && isset($row[$idx['rc']])) ? (string)$row[$idx['rc']] : '';
        $rm = ($idx['rm'] !== null && isset($row[$idx['rm']])) ? (string)$row[$idx['rm']] : '';
        $successRaw = ($idx['success'] !== null && isset($row[$idx['success']])) ? strtolower(trim((string)$row[$idx['success']])) : '';
        // JMeter writes "true"/"false"; a missing column falls back to the
        // response code (a 4xx/5xx count as an error).
        if ($successRaw === '') {
            $success = ($rc === '' || (int)$rc < 400);
        } else {
            $success = ($successRaw === 'true' || $successRaw === '1');
        }
        $ts = 0;
        if ($idx['ts'] !== null && isset($row[$idx['ts']]) && is_numeric(trim((string)$row[$idx['ts']]))) {
            $ts = (int)round((float)trim((string)$row[$idx['ts']]));
        }
        $bytes = 0;
        if ($idx['bytes'] !== null && isset($row[$idx['bytes']]) && is_numeric(trim((string)$row[$idx['bytes']]))) {
            $bytes = (int)round((float)trim((string)$row[$idx['bytes']]));
        }
        $thread = ($idx['thread'] !== null && isset($row[$idx['thread']])) ? (string)$row[$idx['thread']] : '';
        prAccumulate($acc, $elapsed, $label, $rc, $rm, $success, $bytes, $ts, $thread);
    }
    fclose($fh);
    return true;
}

/**
 * Parse an XML JTL (JMeter's XML result log) with a streaming reader.
 */
function prParseXml($path, &$acc)
{
    $reader = new XMLReader();
    if (!@$reader->open($path, null, LIBXML_NONET | LIBXML_COMPACT)) {
        return false;
    }
    $any = false;
    while (@$reader->read()) {
        if ($reader->nodeType !== XMLReader::ELEMENT) {
            continue;
        }
        $name = strtolower($reader->localName);
        if ($name !== 'sample' && $name !== 'httpsample') {
            continue;
        }
        $any = true;

        $elapsed = 0;
        if (is_numeric((string)$reader->getAttribute('t'))) {
            $elapsed = (int)round((float)$reader->getAttribute('t'));
        } else {
            continue;
        }
        $label = trim((string)$reader->getAttribute('lb'));
        $rc = trim((string)$reader->getAttribute('rc'));
        $rm = trim((string)$reader->getAttribute('rm'));
        $sRaw = strtolower(trim((string)$reader->getAttribute('s')));
        if ($sRaw === '') {
            $success = ($rc === '' || (int)$rc < 400);
        } else {
            $success = ($sRaw === 'true' || $sRaw === '1');
        }
        $ts = is_numeric((string)$reader->getAttribute('ts')) ? (int)round((float)$reader->getAttribute('ts')) : 0;
        $bytes = is_numeric((string)$reader->getAttribute('by')) ? (int)round((float)$reader->getAttribute('by')) : 0;
        $thread = trim((string)$reader->getAttribute('tn'));
        prAccumulate($acc, $elapsed, $label, $rc, $rm, $success, $bytes, $ts, $thread);
    }
    $reader->close();
    return $any;
}

/**
 * Fold one sample into the running accumulators.
 */
function prAccumulate(&$acc, $elapsed, $label, $rc, $rm, $success, $bytes, $ts, $thread)
{
    if ($acc['samples'] >= PERF_MAX_SAMPLES) {
        $acc['truncated'] = true;
        return;
    }
    $acc['samples']++;
    $acc['elapsed'][] = $elapsed;
    $acc['total_bytes'] += $bytes;
    if (!$success) {
        $acc['errors']++;
    }
    if ($ts > 0) {
        if ($acc['start_ts'] === 0 || $ts < $acc['start_ts']) {
            $acc['start_ts'] = $ts;
        }
        if ($ts > $acc['end_ts']) {
            $acc['end_ts'] = $ts;
        }
    }
    if (count($acc['samples_rows']) < PERF_MAX_SAMPLE_ROWS) {
        $acc['samples_rows'][] = array(
            'ts' => $ts,
            'elapsed' => $elapsed,
            'label' => $label,
            'response_code' => $rc,
            'response_message' => $rm,
            'success' => $success ? 1 : 0,
            'bytes' => $bytes,
            'thread' => $thread,
        );
    }
    if (!isset($acc['labels'][$label])) {
        $acc['labels'][$label] = array(
            'samples' => 0, 'errors' => 0, 'total_bytes' => 0, 'elapsed' => array(),
        );
    }
    $acc['labels'][$label]['samples']++;
    $acc['labels'][$label]['total_bytes'] += $bytes;
    $acc['labels'][$label]['elapsed'][] = $elapsed;
    if (!$success) {
        $acc['labels'][$label]['errors']++;
    }
}

// ---------------------------------------------------------------------------
if ($action === 'init') {
    if ($method === 'POST') {
        header('Allow: GET, HEAD');
        prFail(405, 'method_not_allowed');
    }
    $ctx = prResolveProject($db, $user);
    prOut(array(
        'status' => 'ok',
        'code' => 'ok',
        'context' => $ctx,
        'limits' => array(
            'max_file_bytes' => PERF_MAX_FILE_BYTES,
            'max_samples' => PERF_MAX_SAMPLES,
        ),
    ));
}

if ($action !== 'parse') {
    prFail(400, 'invalid_request', 'Unknown action');
}

if ($method !== 'POST') {
    header('Allow: POST');
    prFail(405, 'method_not_allowed');
}

$ctx = prResolveProject($db, $user);

$file = $_FILES['jtlFile'] ?? null;
if (!is_array($file) || !isset($file['error'])) {
    prFail(400, 'invalid_request', 'No file uploaded');
}

$err = intval($file['error']);
if ($err === UPLOAD_ERR_NO_FILE) {
    prFail(400, 'invalid_request', 'No file uploaded');
}
if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
    prFail(413, 'file_too_large', 'File exceeds the upload limit');
}
if ($err !== UPLOAD_ERR_OK) {
    prFail(400, 'invalid_request', 'Upload failed');
}
if (!is_uploaded_file($file['tmp_name'] ?? '')) {
    prFail(400, 'invalid_request', 'Invalid upload');
}

$size = (int)($file['size'] ?? 0);
if ($size <= 0) {
    prFail(422, 'empty_file', 'Empty file');
}
if ($size > PERF_MAX_FILE_BYTES) {
    prFail(413, 'file_too_large', 'File exceeds the limit');
}

$format = prDetectFormat($file['tmp_name']);
$acc = array(
    'samples' => 0,
    'errors' => 0,
    'total_bytes' => 0,
    'elapsed' => array(),
    'labels' => array(),
    'samples_rows' => array(),
    'start_ts' => 0,
    'end_ts' => 0,
    'truncated' => false,
);

if ($format === 'xml') {
    $ok = prParseXml($file['tmp_name'], $acc);
} else {
    $ok = prParseCsv($file['tmp_name'], $acc);
}

if (!$ok) {
    prFail(422, 'unparsable_file', 'Not a recognized JMeter JTL file (CSV or XML)');
}

if ($acc['samples'] === 0) {
    prOut(array(
        'status' => 'ok',
        'code' => 'ok',
        'format' => $format,
        'context' => $ctx,
        'empty' => true,
        'truncated' => $acc['truncated'],
        'summary' => null,
        'labels' => array(),
        'samples' => array(),
        'samples_total' => 0,
        'samples_shown' => 0,
        'labels_total' => 0,
    ));
}

$elapsed = $acc['elapsed'];
sort($elapsed);
$n = count($elapsed);
$sum = array_sum($elapsed);

$durationSec = 0;
if ($acc['start_ts'] > 0 && $acc['end_ts'] >= $acc['start_ts']) {
    $durationSec = ($acc['end_ts'] - $acc['start_ts']) / 1000.0;
}
$throughput = ($durationSec > 0) ? round($acc['samples'] / $durationSec, 2) : 0.0;

$summary = array(
    'samples' => $acc['samples'],
    'errors' => $acc['errors'],
    'success_rate' => round((($acc['samples'] - $acc['errors']) / $acc['samples']) * 100, 2),
    'error_rate' => round(($acc['errors'] / $acc['samples']) * 100, 2),
    'total_bytes' => $acc['total_bytes'],
    'min_elapsed' => $elapsed[0],
    'max_elapsed' => $elapsed[$n - 1],
    'avg_elapsed' => round($sum / $n, 2),
    'p50' => prPercentile($elapsed, 50),
    'p90' => prPercentile($elapsed, 90),
    'p95' => prPercentile($elapsed, 95),
    'p99' => prPercentile($elapsed, 99),
    'start_ts' => $acc['start_ts'],
    'end_ts' => $acc['end_ts'],
    'duration_sec' => round($durationSec, 3),
    'throughput' => $throughput,
);

$labels = array();
foreach ($acc['labels'] as $label => $data) {
    $le = $data['elapsed'];
    sort($le);
    $ln = count($le);
    $labels[] = array(
        'label' => (string)$label,
        'samples' => $data['samples'],
        'errors' => $data['errors'],
        'error_rate' => round(($data['errors'] / max(1, $data['samples'])) * 100, 2),
        'avg' => round(array_sum($le) / max(1, $ln), 2),
        'min' => $ln ? $le[0] : 0,
        'max' => $ln ? $le[$ln - 1] : 0,
        'p90' => prPercentile($le, 90),
        'p95' => prPercentile($le, 95),
        'total_bytes' => $data['total_bytes'],
        'throughput' => ($durationSec > 0) ? round($data['samples'] / $durationSec, 2) : 0.0,
    );
}
// Worst first: most errors, then slowest average.
usort($labels, function ($a, $b) {
    if ($a['errors'] !== $b['errors']) {
        return $b['errors'] - $a['errors'];
    }
    return ($a['avg'] < $b['avg']) ? 1 : (($a['avg'] > $b['avg']) ? -1 : 0);
});

prOut(array(
    'status' => 'ok',
    'code' => 'ok',
    'format' => $format,
    'context' => $ctx,
    'empty' => false,
    'truncated' => $acc['truncated'],
    'summary' => $summary,
    'labels' => $labels,
    'samples' => $acc['samples_rows'],
    'samples_total' => $acc['samples'],
    'samples_shown' => count($acc['samples_rows']),
    'labels_total' => count($labels),
));
