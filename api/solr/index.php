<?php
/**
 * Apache SOLR integration BFF API
 * URL: /api/solr/
 * Plain PHP, no framework, no compilation.
 *
 * Backs the modern screen gui/templates/system/solrIntegration.html (Refs
 * #1950, parent feature #1087 - "Integration with Apache SOLR"). TestLink
 * 1.9.20 and 2.0.1 shipped no SOLR support at all, so this is a net-new
 * module: a project-scoped SOLR connection (enabled flag, endpoint, core,
 * timeout), a real connection test and a search preview over the configured
 * core - the smallest coherent slice of the parent feature.
 *
 * Routes (session based, JSON I/O):
 *   GET  ?action=init&tproject_id=N
 *        -> { status, context, settings, lastTest, limits }
 *   POST ?action=save { tproject_id, enabled, endpoint, core, timeout_ms }
 *        -> { status, settings }
 *   POST ?action=test { tproject_id }
 *        -> { status, test }  |  502 connection_failed / solr_error
 *   POST ?action=preview { tproject_id, q }
 *        -> { status, num_found, docs, latency_ms }
 *
 * Rights model (checked on the ADDRESSED test project BEFORE it is resolved,
 * the #1697 lesson: no existence oracle):
 *   - reads  require mgt_view_tc OR mgt_modify_tc on the project
 *   - writes require mgt_modify_tc on the project
 *   - unauthenticated -> 401; unknown action -> 400; wrong verb -> 405;
 *     a project that does not exist -> 404 tproject_not_found
 *
 * Security: the endpoint is http/https only, credentials in the URL are
 * refused, redirects are never followed and loopback/link-local/metadata
 * targets are refused (SSRF) unless $tlCfg->solrAllowLocalTargets opts in.
 * Responses are capped at 512 KB.
 *
 * Writes emit AUDIT events (SOLR_CONFIG_SAVE / SOLR_TEST) so the Event Viewer
 * tracks the module activity. The tc_solr_config table is created lazily
 * (CREATE TABLE IF NOT EXISTS) so a freshly imported database works
 * unchanged; the matching DDL is also part of the installer schema.
 */
require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$db = null;
doDBConnect($db);

// Failed queries throw (database.class.php) under X-Requested-With; make sure
// every uncaught error leaves the JSON contract intact and leaks nothing.
set_exception_handler(function ($e) {
    error_log('api/solr: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(array('status' => 'error', 'code' => 'server_error',
        'message' => 'Internal error'));
    exit;
});

$userId = $_SESSION['userID'] ?? null;
if (!$userId || $userId <= 0) {
    solrFail(401, 'not_authenticated', 'Not authenticated');
}

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    solrFail(401, 'user_not_found', 'User not found');
}

// Session inactivity timeout (#1614).
bffEnforceSession($db);

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$action = (isset($_REQUEST['action']) && is_scalar($_REQUEST['action']))
    ? trim((string)$_REQUEST['action']) : '';

/** Table name handling (respects the optional DB_TABLE_PREFIX). */
function solrTable() {
    $t = tlObjectWithDB::getDBTables(array('tc_solr_config'));
    return $t['tc_solr_config'];
}

/** Idempotent lazy schema migration so a freshly imported DB works unchanged. */
function solrEnsureSchema($db) {
    $t = solrTable();
    $db->exec_query(
        "CREATE TABLE IF NOT EXISTS {$t} (" .
        " testproject_id INT UNSIGNED NOT NULL," .
        " enabled TINYINT(1) NOT NULL DEFAULT 0," .
        " endpoint VARCHAR(500) NOT NULL DEFAULT ''," .
        " core_select VARCHAR(64) NOT NULL DEFAULT ''," .
        " timeout_ms INT UNSIGNED NOT NULL DEFAULT 3000," .
        " update_ts DATETIME NULL," .
        " last_test_ts DATETIME NULL," .
        " last_test_code INT NOT NULL DEFAULT 0," .
        " last_test_ok TINYINT(1) NOT NULL DEFAULT 0," .
        " last_test_latency_ms INT NOT NULL DEFAULT 0," .
        " PRIMARY KEY (testproject_id)" .
        " ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    return $t;
}

/** Read the JSON request body (POST routes). */
function solrBody() {
    static $cached = null;
    if ($cached === null) {
        $cached = json_decode(file_get_contents('php://input'), true);
        if (!is_array($cached)) { $cached = array(); }
    }
    return $cached;
}

/** Trim + length-cap a scalar payload field. */
function solrField($input, $key, $maxLen, $default = '') {
    $v = $input[$key] ?? $default;
    $v = is_scalar($v) ? trim((string)$v) : '';
    return mb_substr($v, 0, $maxLen);
}

/** Strict positive int from an input array, 0 when absent/invalid. */
function solrInt($input, $key) {
    $v = $input[$key] ?? 0;
    return is_scalar($v) ? intval($v) : 0;
}

/** Resolve the addressed test project: GET param wins, body, then session. */
function solrResolveTproject() {
    $tpid = solrInt($_GET, 'tproject_id');
    if ($tpid <= 0) {
        $tpid = solrInt(solrBody(), 'tproject_id');
    }
    if ($tpid <= 0) {
        $tpid = intval($_SESSION['testprojectID'] ?? 0);
    }
    return $tpid;
}

/** Whether the user may read the SOLR settings of a project. */
function solrCanView($user, $db, $tpid) {
    foreach (array('mgt_view_tc', 'mgt_modify_tc') as $r) {
        if ($user->hasRight($db, $r, $tpid) === 'yes') { return true; }
    }
    return false;
}

/** Whether the user may write the SOLR settings of a project. */
function solrCanModify($user, $db, $tpid) {
    return ($user->hasRight($db, 'mgt_modify_tc', $tpid) === 'yes');
}

/** Project display name + prefix, or null when the project does not exist. */
function solrProjectInfo($db, $tpid) {
    $tbl = tlObjectWithDB::getDBTables(array('testprojects', 'nodes_hierarchy'));
    $tp = $tbl['testprojects'];
    $nh = $tbl['nodes_hierarchy'];
    $row = $db->fetchFirstRow(
        "SELECT p.prefix AS prefix, nh.name AS name " .
        " FROM {$tp} p JOIN {$nh} nh ON nh.id = p.id " .
        " WHERE p.id = " . intval($tpid) . " AND nh.node_type_id = 1");
    return (is_array($row) && $row) ? $row : null;
}

/**
 * Validate a SOLR base endpoint: http/https only, no embedded credentials,
 * no query/fragment, sane length. Returns the normalized URL or null.
 */
function solrCleanEndpoint($url) {
    if ($url === '' || strlen($url) > 500) { return null; }
    $parts = parse_url($url);
    if (!is_array($parts)) { return null; }
    $scheme = strtolower($parts['scheme'] ?? '');
    if ($scheme !== 'http' && $scheme !== 'https') { return null; }
    if (empty($parts['host'])) { return null; }
    if (isset($parts['user']) || isset($parts['pass'])) { return null; }
    if (isset($parts['query']) || isset($parts['fragment'])) { return null; }
    return rtrim($url, '/');
}

/** True when the host literal is loopback / link-local / unspecified. */
function solrHostIsLocal($host) {
    $host = strtolower(trim((string)$host, "[]"));
    if ($host === '') { return true; }
    // Strip a single trailing dot (FQDN root) so "localhost." cannot slip by.
    $host = rtrim($host, '.');
    if ($host === '' || $host === 'localhost' || strpos($host, '.localhost') !== false) {
        return true;
    }
    // IPv6 literal: normalize with inet_pton and inspect the packed bytes so
    // IPv4-mapped (::ffff:127.0.0.1), loopback (::1), link-local (fe80::/10),
    // unique-local (fc00::/7) and the AWS IMDS ULA (fd00:ec2::254) are all
    // caught regardless of textual form.
    if (strpos($host, ':') !== false) {
        $packed = @inet_pton($host);
        if ($packed === false || strlen($packed) !== 16) { return false; }
        $b = array_values(unpack('C*', $packed));
        $isV6Zero = true;
        for ($i = 0; $i < 15; $i++) { if ($b[$i] !== 0) { $isV6Zero = false; break; } }
        if ($isV6Zero && ($b[15] === 0 || $b[15] === 1)) { return true; } // :: / ::1
        if ($b[0] === 0xfe && ($b[1] & 0xc0) === 0x80) { return true; }   // fe80::/10 link-local
        if (($b[0] & 0xfe) === 0xfc) { return true; }                     // fc00::/7 unique-local (incl. fd00:ec2::254)
        // IPv4-mapped ::ffff:a.b.c.d -> re-check the embedded IPv4.
        if ($b[0] === 0 && $b[1] === 0 && $b[2] === 0 && $b[3] === 0 &&
            $b[4] === 0 && $b[5] === 0 && $b[6] === 0 && $b[7] === 0 &&
            $b[8] === 0 && $b[9] === 0 && $b[10] === 0xff && $b[11] === 0xff) {
            $ip4 = $b[12] . '.' . $b[13] . '.' . $b[14] . '.' . $b[15];
            return solrIp4IsLocal($ip4);
        }
        return false;
    }
    // Bare IPv4 literal, incl. decimal (2130706433) and hex (0x7f000001)
    // notations getaddrinfo/curl accept. inet_pton only takes dotted-quad, so
    // normalize the alternative forms first.
    if (preg_match('/^\d+$/', $host) || preg_match('/^0x[0-9a-f]+$/', $host)) {
        $n = (int)$host; // base-10, or PHP parses 0x.. as hex
        if ($n <= 0) { return $n === 0; }
        return solrIp4IsLocal(long2ip($n));
    }
    if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return false;
    }
    return solrIp4IsLocal($host);
}

/** True when a dotted-quad IPv4 is loopback / link-local / unspecified. */
function solrIp4IsLocal($ip) {
    $long = ip2long($ip);
    if ($long === false) { return false; }
    $long = sprintf('%u', $long);
    return (($long >= sprintf('%u', ip2long('127.0.0.0')) && $long <= sprintf('%u', ip2long('127.255.255.255'))))
        || ($long >= sprintf('%u', ip2long('169.254.0.0')) && $long <= sprintf('%u', ip2long('169.254.255.255')))
        || ($long >= sprintf('%u', ip2long('0.0.0.0')) && $long <= sprintf('%u', ip2long('0.255.255.255')));
}

/**
 * Guard an outbound SOLR request against loopback/link-local/metadata
 * targets (SSRF). LAN targets stay allowed on purpose; an install that needs
 * loopback can opt in with $tlCfg->solrAllowLocalTargets.
 *
 * Scope note: this guards the host LITERAL only. A DNS name that resolves to a
 * private/loopback address (DNS-rebinding style) is intentionally out of scope
 * - LAN hostnames such as `solr.internal` must keep working, and pinning the
 * resolved IP is not possible without an in-process connect hook.
 */
function solrAssertRemoteHost($url) {
    global $tlCfg;
    $parts = parse_url($url);
    $host = is_array($parts) ? ($parts['host'] ?? '') : '';
    if (solrHostIsLocal($host)) {
        return isset($tlCfg->solrAllowLocalTargets) && $tlCfg->solrAllowLocalTargets;
    }
    return true;
}

/** Normalized settings payload (empty row -> defaults). */
function solrSettings($row) {
    $row = is_array($row) ? $row : array();
    return array(
        'enabled' => intval($row['enabled'] ?? 0) ? 1 : 0,
        'endpoint' => (string)($row['endpoint'] ?? ''),
        'core' => (string)($row['core_select'] ?? ''),
        'timeout_ms' => intval($row['timeout_ms'] ?? 3000),
        'update_ts' => (string)($row['update_ts'] ?? ''),
    );
}

/** Last test result payload. */
function solrLastTest($row) {
    $row = is_array($row) ? $row : array();
    return array(
        'ts' => (string)($row['last_test_ts'] ?? ''),
        'code' => intval($row['last_test_code'] ?? 0),
        'ok' => intval($row['last_test_ok'] ?? 0) ? 1 : 0,
        'latency_ms' => intval($row['last_test_latency_ms'] ?? 0),
    );
}

/** Fetch the settings row of a project (or null). */
function solrFetch($db, $t, $tpid) {
    $row = $db->fetchFirstRow(
        "SELECT * FROM {$t} WHERE testproject_id = " . intval($tpid));
    return (is_array($row) && $row) ? $row : null;
}

/** Emit a machine-coded error and stop. */
function solrFail($http, $code, $message, $extra = array()) {
    http_response_code($http);
    echo json_encode(array_merge(
        array('status' => 'error', 'code' => $code, 'message' => $message),
        $extra));
    exit;
}

/** AUDIT event helper. */
function solrLog($message, $code, $tpid) {
    $event = new stdClass();
    $event->message = $message;
    $event->logLevel = 'AUDIT';
    $event->source = 'GUI';
    $event->objectID = $tpid;
    $event->objectType = 'testprojects';
    $event->code = $code;
    logEvent($event);
}

/**
 * Perform one SOLR request and return array(latency_ms, decoded, http_code)
 * or array(latency_ms, null, http_code) with an error string in the 4th slot.
 */
function solrRequest($endpoint, $core, $timeoutMs, $query, $rows) {
    if (!function_exists('curl_init')) {
        return array(0, null, 0, 'curl_not_available');
    }
    $url = rtrim($endpoint, '/') . '/' . rawurlencode($core) . '/select?' .
        http_build_query(array('q' => $query, 'wt' => 'json',
            'rows' => intval($rows), 'fl' => 'id,title'));
    $ch = curl_init($url);
    $capped = '';
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT_MS => min(2000, $timeoutMs),
        CURLOPT_TIMEOUT_MS => $timeoutMs,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_USERAGENT => 'TestLink/2.0.1 SOLR integration',
        CURLOPT_HTTPHEADER => array('Accept: application/json'),
        // Stream the body so a hostile endpoint cannot buffered-DoS us: abort
        // as soon as more than 512 KB has arrived.
        CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$capped) {
            $capped .= $chunk;
            return (strlen($capped) > 524288) ? 0 : strlen($chunk);
        },
    ));
    $started = microtime(true);
    $execOk = curl_exec($ch);
    $latency = (int)round((microtime(true) - $started) * 1000);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if (strlen($capped) > 524288) {
        return array($latency, null, $httpCode, 'response_too_large');
    }
    if ($execOk === false || $err !== '') {
        return array($latency, null, $httpCode, 'connection_failed');
    }
    $raw = $capped;
    if ($httpCode < 200 || $httpCode >= 300) {
        return array($latency, null, $httpCode, 'solr_error');
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return array($latency, null, $httpCode, 'invalid_response');
    }
    return array($latency, $decoded, $httpCode, '');
}

// --------------------------------------------------------------------------
// Routing
// --------------------------------------------------------------------------
$readActions = array('init');
$writeActions = array('save', 'test', 'preview');
$safeMethod = in_array($method, array('GET', 'HEAD'), true);

if ($safeMethod && !in_array($action, $readActions, true)) {
    header('Allow: POST');
    solrFail(405, 'method_not_allowed', 'Action requires POST', array('allow' => 'POST'));
}
if (!$safeMethod && $method !== 'POST') {
    header('Allow: GET, HEAD, POST');
    solrFail(405, 'method_not_allowed', 'Only GET and POST are supported', array('allow' => 'GET, HEAD, POST'));
}
if ($action === '') {
    solrFail(400, 'unknown_action', 'Missing action');
}
if (!$safeMethod && !in_array($action, $writeActions, true)) {
    solrFail(400, 'unknown_action', 'Unknown action');
}

$tpid = solrResolveTproject();
if ($tpid <= 0) {
    solrFail(400, 'no_testproject', 'No test project addressed');
}

// Rights are checked on the ADDRESSED project BEFORE it is resolved.
$mayWrite = !$safeMethod;
$allowed = $mayWrite ? solrCanModify($user, $db, $tpid)
                     : solrCanView($user, $db, $tpid);
if (!$allowed) {
    solrFail(403, 'no_right', 'Insufficient rights on this test project');
}

$project = solrProjectInfo($db, $tpid);
if ($project === null) {
    solrFail(404, 'tproject_not_found', 'Test project not found');
}

$t = solrEnsureSchema($db);

// --------------------------------------------------------------------------
// init
// --------------------------------------------------------------------------
if ($action === 'init') {
    $row = solrFetch($db, $t, $tpid);
    echo json_encode(array(
        'status' => 'ok',
        'context' => array(
            'tproject_id' => $tpid,
            'name' => (string)$project['name'],
            'prefix' => (string)$project['prefix'],
            'can_write' => solrCanModify($user, $db, $tpid) ? 1 : 0,
        ),
        'settings' => solrSettings($row),
        'lastTest' => solrLastTest($row),
        'limits' => array('timeout_min_ms' => 500, 'timeout_max_ms' => 15000,
            'default_timeout_ms' => 3000),
    ));
    exit;
}

// --------------------------------------------------------------------------
// save
// --------------------------------------------------------------------------
if ($action === 'save') {
    $body = solrBody();
    $enabled = solrInt($body, 'enabled') ? 1 : 0;
    $endpoint = solrField($body, 'endpoint', 500);
    $core = solrField($body, 'core', 64);
    $timeout = solrInt($body, 'timeout_ms');

    $cleanEndpoint = $endpoint === '' ? '' : solrCleanEndpoint($endpoint);
    if ($endpoint !== '' && $cleanEndpoint === null) {
        solrFail(400, 'bad_endpoint', 'Endpoint must be an absolute http(s) URL without credentials');
    }
    if ($core !== '' && !preg_match('/^[A-Za-z0-9_.\-]{1,64}$/', $core)) {
        solrFail(400, 'bad_core', 'Core name may only contain letters, digits, dot, dash and underscore');
    }
    if ($enabled && ($cleanEndpoint === null || $cleanEndpoint === '')) {
        solrFail(400, 'bad_endpoint', 'An endpoint is required when the integration is enabled');
    }
    if ($enabled && $core === '') {
        solrFail(400, 'bad_core', 'A core is required when the integration is enabled');
    }
    if ($enabled && !solrAssertRemoteHost($cleanEndpoint)) {
        solrFail(400, 'blocked_target', 'Refusing to store a loopback/link-local endpoint');
    }
    if ($timeout <= 0) { $timeout = 3000; }
    if ($timeout < 500 || $timeout > 15000) {
        solrFail(400, 'bad_timeout', 'Timeout must be between 500 and 15000 ms');
    }

    $tpidSql = intval($tpid);
    $enabledSql = intval($enabled);
    $endpointSql = $db->prepare_string((string)$cleanEndpoint);
    $coreSql = $db->prepare_string((string)$core);
    $timeoutSql = intval($timeout);

    $existing = solrFetch($db, $t, $tpid);
    if ($existing === null) {
        $db->exec_query("INSERT INTO {$t} (testproject_id, enabled, endpoint," .
            " core_select, timeout_ms, update_ts) VALUES ({$tpidSql}, {$enabledSql}," .
            " '{$endpointSql}', '{$coreSql}', {$timeoutSql}, NOW())");
    } else {
        $db->exec_query("UPDATE {$t} SET enabled = {$enabledSql}," .
            " endpoint = '{$endpointSql}', core_select = '{$coreSql}'," .
            " timeout_ms = {$timeoutSql}, update_ts = NOW()" .
            " WHERE testproject_id = {$tpidSql}");
    }

    solrLog('SOLR configuration saved (' . ($enabled ? 'enabled' : 'disabled') . ')',
        'SOLR_CONFIG_SAVE', $tpid);

    $row = solrFetch($db, $t, $tpid);
    echo json_encode(array('status' => 'ok', 'settings' => solrSettings($row)));
    exit;
}

// --------------------------------------------------------------------------
// test + preview share the configured connection
// --------------------------------------------------------------------------
$row = solrFetch($db, $t, $tpid);
$settings = solrSettings($row);
if ($settings['endpoint'] === '' || $settings['core'] === '') {
    solrFail(400, 'not_configured', 'Save an endpoint and core before using the connection');
}
if (!solrAssertRemoteHost($settings['endpoint'])) {
    solrFail(400, 'blocked_target', 'Refusing to contact a loopback/link-local endpoint');
}

$body = solrBody();

$timeoutMs = intval($settings['timeout_ms']);
if ($timeoutMs < 500)  { $timeoutMs = 500; }
if ($timeoutMs > 15000) { $timeoutMs = 15000; }

if ($action === 'test') {
    list($latency, $decoded, $httpCode, $err) = solrRequest(
        $settings['endpoint'], $settings['core'], $timeoutMs,
        '*:*', 0);

    $ok = ($err === '' && is_array($decoded)) ? 1 : 0;
    $numFound = $ok ? intval($decoded['response']['numFound'] ?? 0) : 0;

    $db->exec_query("UPDATE {$t} SET last_test_ts = NOW()," .
        " last_test_code = " . intval($ok ? 200 : ($httpCode > 0 ? $httpCode : 0)) . "," .
        " last_test_ok = {$ok}, last_test_latency_ms = " . intval($latency) .
        " WHERE testproject_id = " . intval($tpid));

    solrLog('SOLR connection test: ' . ($ok ? 'ok' : 'failed') .
        ' (latency ' . intval($latency) . ' ms)', 'SOLR_TEST', $tpid);

    if (!$ok) {
        solrFail(502, $err === '' ? 'solr_error' : $err,
            'The SOLR core did not answer a valid query', array(
                'test' => array('ok' => 0, 'latency_ms' => $latency,
                    'http_code' => $httpCode, 'error' => $err)));
    }
    echo json_encode(array('status' => 'ok',
        'test' => array('ok' => 1, 'latency_ms' => $latency,
            'http_code' => $httpCode, 'num_found' => $numFound)));
    exit;
}

// preview
$query = solrField($body, 'q', 200);
if ($query === '') { $query = '*:*'; }
list($latency, $decoded, $httpCode, $err) = solrRequest(
    $settings['endpoint'], $settings['core'], $settings['timeout_ms'],
    $query, 10);

if ($err !== '' || !is_array($decoded)) {
    solrFail(502, $err === '' ? 'invalid_response' : $err,
        'The SOLR core did not answer a valid query', array(
            'latency_ms' => $latency, 'http_code' => $httpCode));
}

$docs = array();
$response = isset($decoded['response']) && is_array($decoded['response'])
    ? $decoded['response'] : array();
foreach ((array)($response['docs'] ?? array()) as $doc) {
    if (!is_array($doc)) { continue; }
    $id = isset($doc['id']) && is_scalar($doc['id']) ? (string)$doc['id'] : '';
    $title = isset($doc['title']) && is_scalar($doc['title'])
        ? (string)$doc['title'] : '';
    if ($id === '' && $title === '') { continue; }
    $docs[] = array('id' => $id, 'title' => $title);
}

echo json_encode(array(
    'status' => 'ok',
    'num_found' => intval($response['numFound'] ?? 0),
    'docs' => $docs,
    'latency_ms' => $latency,
));
exit;
