<?php
/**
 * Koa11y accessibility scan results BFF API
 * URL: /api/a11yscans/
 * Plain PHP, no framework, no compilation.
 *
 * Backs the modern screen gui/templates/accessibility/a11yScans.html
 * (Refs #1959, parent feature #1058). TestLink 1.9.20 has NO accessibility
 * scan integration at all: Koa11y (or any other scanner) results live outside
 * the platform, so there is no traceability from an accessibility finding to
 * the test project, plan, build, test case or target URL. This is a net-new,
 * project-scoped registry of imported scan results with their individual
 * findings and a pass / warn / fail verdict.
 *
 * Routes (session based, JSON I/O):
 *   GET|HEAD ?action=init&tproject_id=N
 *        -> { status, context, domains, refs, stats, items }
 *   GET|HEAD ?action=list&tproject_id=N
 *        -> { status, items }
 *   GET|HEAD ?action=scan&tproject_id=N&id=S
 *        -> { status, scan, findings }
 *   POST ?action=import { tproject_id, name, target_url, profile,
 *                         environment, browser, scan_date, tool_version,
 *                         report_url, notes, tplan_id?, build_id?, tcase_id?,
 *                         findings:[{severity, rule, title, page_url, element,
 *                                     description, remediation}] }
 *        -> { status, id, verdict }
 *   POST ?action=delete { tproject_id, id }
 *        -> { status, deleted }
 *
 * Rights model (checked on the ADDRESSED test project BEFORE it is resolved,
 * the #1697 lesson: no existence oracle):
 *   - reads  require mgt_view_tc OR mgt_modify_tc on the project
 *   - writes require mgt_modify_tc on the project
 *   - unauthenticated -> 401; unknown action -> 400; wrong verb -> 405;
 *     a scan id that is not in the project -> 404; a foreign/unknown linked
 *     plan, build or test case -> 400
 *
 * Writes emit AUDIT events (A11Y_SCAN_IMPORT / A11Y_SCAN_DELETE) so the
 * Event Viewer tracks the module activity.
 *
 * The two tables are created lazily (CREATE TABLE IF NOT EXISTS) so a freshly
 * imported database works unchanged; the matching DDL is also part of the
 * installer schema.
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

bffEnforceSession($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || $userId <= 0) {
    http_response_code(401);
    echo json_encode(array('status' => 'error', 'code' => 'not_authenticated',
        'message' => 'Not authenticated'));
    exit;
}

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    http_response_code(401);
    echo json_encode(array('status' => 'error', 'code' => 'user_not_found',
        'message' => 'User not found'));
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = isset($_REQUEST['action']) ? trim((string)$_REQUEST['action']) : '';

function a11ySeverities() {
    return array('error', 'warning', 'notice');
}

function a11yVerdicts() {
    return array('pass', 'warn', 'fail');
}

/** Table names, respecting the optional DB_TABLE_PREFIX. */
function a11yTables() {
    return tlObjectWithDB::getDBTables(array('tc_a11y_scans', 'tc_a11y_findings'));
}

/** Idempotent lazy schema so a freshly imported DB works unchanged. */
function a11yEnsureSchema($db) {
    $t = a11yTables();
    $scans = $t['tc_a11y_scans'];
    $findings = $t['tc_a11y_findings'];
    $db->exec_query(
        "CREATE TABLE IF NOT EXISTS {$scans} (" .
        " id int(10) unsigned NOT NULL auto_increment," .
        " testproject_id int(10) unsigned NOT NULL default 0," .
        " tplan_id int(10) unsigned default NULL," .
        " build_id int(10) unsigned default NULL," .
        " tcase_id int(10) unsigned default NULL," .
        " name varchar(255) NOT NULL default ''," .
        " target_url varchar(1024) NOT NULL default ''," .
        " profile varchar(100) NOT NULL default ''," .
        " environment varchar(128) NOT NULL default ''," .
        " browser varchar(128) NOT NULL default ''," .
        " scan_date datetime default NULL," .
        " tool_version varchar(64) NOT NULL default ''," .
        " errors int(10) unsigned NOT NULL default 0," .
        " warnings int(10) unsigned NOT NULL default 0," .
        " notices int(10) unsigned NOT NULL default 0," .
        " verdict varchar(8) NOT NULL default 'pass'," .
        " report_url varchar(1024) NOT NULL default ''," .
        " notes text," .
        " created_by int(10) unsigned default NULL," .
        " creation_ts TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP," .
        " update_ts datetime default NULL," .
        " PRIMARY KEY (id)," .
        " KEY idx_a11y_scans_project (testproject_id, scan_date)" .
        ") ENGINE=InnoDB DEFAULT CHARSET=utf8");
    $db->exec_query(
        "CREATE TABLE IF NOT EXISTS {$findings} (" .
        " id int(10) unsigned NOT NULL auto_increment," .
        " scan_id int(10) unsigned NOT NULL default 0," .
        " severity varchar(8) NOT NULL default 'notice'," .
        " rule_name varchar(255) NOT NULL default ''," .
        " title varchar(500) NOT NULL default ''," .
        " page_url varchar(1024) NOT NULL default ''," .
        " element varchar(500) NOT NULL default ''," .
        " description text," .
        " remediation text," .
        " sort_order int(10) unsigned NOT NULL default 0," .
        " PRIMARY KEY (id)," .
        " KEY idx_a11y_findings_scan (scan_id)" .
        ") ENGINE=InnoDB DEFAULT CHARSET=utf8");
    return $t;
}

/** Read the JSON request body (POST routes). */
function a11yBody() {
    static $cached = null;
    if ($cached === null) {
        $cached = json_decode(file_get_contents('php://input'), true);
        if (!is_array($cached)) { $cached = array(); }
    }
    return $cached;
}

/** Trim + length-cap a scalar payload/get field. */
function a11yField($input, $key, $maxLen, $default = '') {
    $v = $input[$key] ?? $default;
    $v = is_scalar($v) ? trim((string)$v) : '';
    return mb_substr($v, 0, $maxLen);
}

/** Strict positive int from GET/body, 0 when absent/invalid/non-scalar. */
function a11yInt($input, $key) {
    $v = $input[$key] ?? 0;
    return is_scalar($v) ? intval($v) : 0;
}

/** Resolve the addressed test project: GET param wins, body, then session. */
function a11yResolveTproject() {
    $tpid = a11yInt($_GET, 'tproject_id');
    if ($tpid <= 0) {
        $tpid = a11yInt(a11yBody(), 'tproject_id');
    }
    if ($tpid <= 0) {
        $tpid = intval($_SESSION['testprojectID'] ?? 0);
    }
    return $tpid;
}

function a11yCanView($user, $db, $tpid) {
    foreach (array('mgt_view_tc', 'mgt_modify_tc') as $r) {
        if ($user->hasRight($db, $r, $tpid) === 'yes') { return true; }
    }
    return false;
}

function a11yCanModify($user, $db, $tpid) {
    return ($user->hasRight($db, 'mgt_modify_tc', $tpid) === 'yes');
}

/** Project display name + prefix, or null when the project does not exist. */
function a11yProjectInfo($db, $tpid) {
    $tbl = tlObjectWithDB::getDBTables(array('testprojects', 'nodes_hierarchy'));
    $tp = $tbl['testprojects'];
    $nh = $tbl['nodes_hierarchy'];
    $row = $db->fetchFirstRow(
        "SELECT p.prefix AS prefix, nh.name AS name " .
        " FROM {$tp} p JOIN {$nh} nh ON nh.id = p.id " .
        " WHERE p.id = " . intval($tpid) . " AND nh.node_type_id = 1");
    return (is_array($row) && $row) ? $row : null;
}

/** Owning-project proof: the node type when the node lives in the project. */
function a11yNodeInProject($db, $nodeId, $tpid) {
    $nh = tlObjectWithDB::getDBTables(array('nodes_hierarchy'))['nodes_hierarchy'];
    $self = intval($nodeId);
    $selfType = 0;
    $cur = $self;
    $guard = 0;
    while ($cur > 0 && $guard < 64) {
        $row = $db->fetchFirstRow(
            "SELECT parent_id, node_type_id FROM {$nh} WHERE id = " . intval($cur));
        if (!is_array($row) || !$row) { return false; }
        if ($cur === $self) { $selfType = intval($row['node_type_id']); }
        if (intval($row['node_type_id']) === 1) {
            return (intval($cur) === intval($tpid)) ? $selfType : false;
        }
        $cur = intval($row['parent_id']);
        $guard++;
    }
    return false;
}

/** Map a scanner severity token onto our three levels. */
function a11ySeverity($raw) {
    $v = strtolower(trim((string)$raw));
    if (in_array($v, array('error', 'critical', 'blocker', 'serious', 'high'), true)) {
        return 'error';
    }
    if (in_array($v, array('warning', 'warn', 'moderate', 'medium'), true)) {
        return 'warning';
    }
    return 'notice';
}

/** Verdict thresholds: any error fails, warnings warn, notices are accepted. */
function a11yVerdict($errors, $warnings) {
    if (intval($errors) > 0) { return 'fail'; }
    if (intval($warnings) > 0) { return 'warn'; }
    return 'pass';
}

/**
 * Normalise the findings list coming from a Koa11y export (JSON parsed client
 * side) or a hand-written payload. Unknown keys are ignored, every string is
 * length-capped, and the list is capped so a hostile file cannot exhaust the
 * request. Supports the Koa11y / axe / generic aliases for each field.
 */
function a11yNormalizeFindings($list) {
    if (!is_array($list)) { return array(); }
    $out = array();
    $i = 0;
    foreach ($list as $f) {
        if (!is_array($f)) { continue; }
        if ($i >= 5000) { break; }
        $pick = function ($keys) use ($f) {
            foreach ($keys as $k) {
                if (isset($f[$k]) && is_scalar($f[$k]) && trim((string)$f[$k]) !== '') {
                    return trim((string)$f[$k]);
                }
            }
            return '';
        };
        $severity = a11ySeverity($pick(array('severity', 'type', 'level', 'impact')));
        $rule = mb_substr($pick(array('rule', 'check', 'rule_name', 'code', 'id')), 0, 255);
        $title = mb_substr($pick(array('title', 'message', 'description', 'text', 'name')), 0, 500);
        if ($title === '') { $title = $rule; }
        $out[] = array(
            'severity' => $severity,
            'rule_name' => $rule,
            'title' => $title,
            'page_url' => mb_substr($pick(array('page_url', 'page', 'url', 'location')), 0, 1024),
            'element' => mb_substr($pick(array('element', 'selector', 'xpath', 'target')), 0, 500),
            'description' => mb_substr($pick(array('description', 'detail', 'details', 'help')), 0, 20000),
            'remediation' => mb_substr($pick(array('remediation', 'guidance', 'recommendation', 'fix', 'how_to_fix')), 0, 20000),
            'sort_order' => $i,
        );
        $i++;
    }
    return $out;
}

/** Accept YYYY-MM-DD or an ISO timestamp; fall back to "now". */
function a11yDate($raw) {
    $v = is_scalar($raw) ? trim((string)$raw) : '';
    if ($v === '') { return date('Y-m-d H:i:s'); }
    $ts = strtotime($v);
    if ($ts === false) { return date('Y-m-d H:i:s'); }
    return date('Y-m-d H:i:s', $ts);
}

/** Plan / build / test-case pickers, all scoped to the addressed project. */
function a11yRefs($db, $tpid) {
    $tbl = tlObjectWithDB::getDBTables(
        array('testplans', 'builds', 'nodes_hierarchy'));
    $tp = $tbl['testplans'];
    $b = $tbl['builds'];
    $nh = $tbl['nodes_hierarchy'];
    $plans = array();
    $rows = (array)$db->get_recordset(
        "SELECT p.id AS id, nh.name AS name FROM {$tp} p" .
        " JOIN {$nh} nh ON nh.id = p.id" .
        " WHERE p.testproject_id = " . intval($tpid) .
        " ORDER BY nh.name ASC, p.id ASC");
    foreach ($rows as $r) {
        $plans[] = array('id' => intval($r['id']), 'label' => (string)$r['name']);
    }
    $builds = array();
    $rows = (array)$db->get_recordset(
        "SELECT id, name FROM {$b} WHERE testproject_id = " . intval($tpid) .
        " ORDER BY name ASC, id ASC");
    foreach ($rows as $r) {
        $builds[] = array('id' => intval($r['id']), 'label' => (string)$r['name']);
    }
    $tcases = array();
    $sql = "WITH RECURSIVE subtree AS (" .
           " SELECT id, parent_id, node_type_id, name FROM {$nh} WHERE id = " . intval($tpid) .
           " UNION ALL" .
           " SELECT nh.id, nh.parent_id, nh.node_type_id, nh.name" .
           " FROM {$nh} nh JOIN subtree s ON nh.parent_id = s.id" .
           ") SELECT id, name FROM subtree" .
           " WHERE node_type_id = 3 AND name <> '' ORDER BY name ASC";
    try {
        $rows = (array)$db->get_recordset($sql);
        foreach ($rows as $r) {
            $tcases[] = array('id' => intval($r['id']), 'label' => (string)$r['name']);
        }
    } catch (Exception $e) {
        $tcases = array();
    }
    return array('plans' => $plans, 'builds' => $builds, 'tcases' => $tcases);
}

/** List scans of a project, newest first, with resolved link labels. */
function a11yList($db, $t, $tpid) {
    $nh = tlObjectWithDB::getDBTables(array('nodes_hierarchy'))['nodes_hierarchy'];
    $b = tlObjectWithDB::getDBTables(array('builds'))['builds'];
    $rows = (array)$db->get_recordset(
        "SELECT s.*, nhp.name AS plan_name, b.name AS build_name, nht.name AS tcase_name" .
        " FROM {$t['tc_a11y_scans']} s" .
        " LEFT JOIN {$nh} nhp ON nhp.id = s.tplan_id" .
        " LEFT JOIN {$b} b ON b.id = s.build_id" .
        " LEFT JOIN {$nh} nht ON nht.id = s.tcase_id" .
        " WHERE s.testproject_id = " . intval($tpid) .
        " ORDER BY s.scan_date DESC, s.id DESC");
    $items = array();
    foreach ($rows as $r) {
        $items[] = array(
            'id' => intval($r['id']),
            'name' => (string)$r['name'],
            'target_url' => (string)($r['target_url'] ?? ''),
            'profile' => (string)($r['profile'] ?? ''),
            'environment' => (string)($r['environment'] ?? ''),
            'browser' => (string)($r['browser'] ?? ''),
            'scan_date' => (string)($r['scan_date'] ?? ''),
            'tool_version' => (string)($r['tool_version'] ?? ''),
            'errors' => intval($r['errors']),
            'warnings' => intval($r['warnings']),
            'notices' => intval($r['notices']),
            'total' => intval($r['errors']) + intval($r['warnings']) + intval($r['notices']),
            'verdict' => (string)$r['verdict'],
            'report_url' => (string)($r['report_url'] ?? ''),
            'notes' => (string)($r['notes'] ?? ''),
            'tplan_id' => intval($r['tplan_id']),
            'tplan_name' => (string)($r['plan_name'] ?? ''),
            'build_id' => intval($r['build_id']),
            'build_name' => (string)($r['build_name'] ?? ''),
            'tcase_id' => intval($r['tcase_id']),
            'tcase_name' => (string)($r['tcase_name'] ?? ''),
            'created_by' => intval($r['created_by']),
            'creation_ts' => (string)($r['creation_ts'] ?? ''),
        );
    }
    return $items;
}

/** Registry stats + a compact per-build trend used by the screen. */
function a11yStats($items) {
    $s = array('total' => 0, 'pass' => 0, 'warn' => 0, 'fail' => 0,
               'errors' => 0, 'warnings' => 0, 'notices' => 0,
               'linked_plan' => 0, 'linked_build' => 0, 'linked_tcase' => 0,
               'latest_scan' => '');
    $trend = array();
    foreach ($items as $it) {
        $s['total']++;
        if (isset($s[$it['verdict']])) { $s[$it['verdict']]++; }
        $s['errors'] += $it['errors'];
        $s['warnings'] += $it['warnings'];
        $s['notices'] += $it['notices'];
        if ($it['tplan_id'] > 0) { $s['linked_plan']++; }
        if ($it['build_id'] > 0) { $s['linked_build']++; }
        if ($it['tcase_id'] > 0) { $s['linked_tcase']++; }
        if ($s['latest_scan'] === '' && $it['scan_date'] !== '') {
            $s['latest_scan'] = $it['scan_date'];
        }
        if ($it['build_id'] > 0) {
            $key = (string)$it['build_id'];
            if (!isset($trend[$key])) {
                $trend[$key] = array('build_id' => $it['build_id'],
                    'build_name' => $it['build_name'], 'scans' => 0,
                    'errors' => 0, 'warnings' => 0, 'notices' => 0,
                    'fail' => 0, 'warn' => 0, 'pass' => 0);
            }
            $trend[$key]['scans']++;
            $trend[$key]['errors'] += $it['errors'];
            $trend[$key]['warnings'] += $it['warnings'];
            $trend[$key]['notices'] += $it['notices'];
            $trend[$key][$it['verdict']]++;
        }
    }
    $trend = array_values($trend);
    usort($trend, function ($a, $b) {
        if ($a['build_id'] === $b['build_id']) { return 0; }
        return ($a['build_id'] < $b['build_id']) ? -1 : 1;
    });
    return array('stats' => $s, 'trend' => $trend);
}

function a11yAudit($message, $code, $tpid) {
    $event = new stdClass();
    $event->message = $message;
    $event->logLevel = 'AUDIT';
    $event->source = 'GUI';
    $event->objectID = $tpid;
    $event->objectType = 'testprojects';
    $event->code = $code;
    logEvent($event);
}

// --------------------------------------------------------------------------
// Routing
// --------------------------------------------------------------------------

$t = a11yEnsureSchema($db);
$tpid = a11yResolveTproject();

if ($tpid <= 0) {
    http_response_code(400);
    echo json_encode(array('status' => 'error', 'code' => 'no_testproject',
        'message' => 'No test project selected'));
    exit;
}

if ($method !== 'GET' && $method !== 'POST' && $method !== 'HEAD') {
    header('Allow: GET, HEAD, POST');
    http_response_code(405);
    echo json_encode(array('status' => 'error', 'code' => 'wrong_method',
        'message' => 'Method not allowed'));
    exit;
}

$isWrite = ($method === 'POST');
if ($isWrite) {
    if (!a11yCanModify($user, $db, $tpid)) {
        http_response_code(403);
        echo json_encode(array('status' => 'error', 'code' => 'no_right',
            'message' => 'No permission to modify accessibility scans'));
        exit;
    }
} else {
    if (!a11yCanView($user, $db, $tpid)) {
        http_response_code(403);
        echo json_encode(array('status' => 'error', 'code' => 'no_right',
            'message' => 'No permission to view accessibility scans'));
        exit;
    }
}

if ($action === '') {
    $action = $isWrite ? 'import' : 'init';
}

// ---- reads -----------------------------------------------------------------

if (!$isWrite && ($action === 'init' || $action === 'list' || $action === 'scan')) {
    $info = a11yProjectInfo($db, $tpid);
    if ($info === null) {
        http_response_code(404);
        echo json_encode(array('status' => 'error', 'code' => 'tproject_not_found',
            'message' => 'Test project not found'));
        exit;
    }

    if ($action === 'scan') {
        $id = a11yInt($_GET, 'id');
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(array('status' => 'error', 'code' => 'bad_id',
                'message' => 'id is mandatory'));
            exit;
        }
        $row = $db->fetchFirstRow(
            "SELECT * FROM {$t['tc_a11y_scans']} WHERE id = " . intval($id) .
            " AND testproject_id = " . intval($tpid));
        if (!is_array($row) || !$row) {
            http_response_code(404);
            echo json_encode(array('status' => 'error', 'code' => 'scan_not_found',
                'message' => 'Accessibility scan not found'));
            exit;
        }
        $findings = array();
        $rows = (array)$db->get_recordset(
            "SELECT * FROM {$t['tc_a11y_findings']} WHERE scan_id = " . intval($id) .
            " ORDER BY sort_order ASC, id ASC");
        foreach ($rows as $f) {
            $findings[] = array(
                'id' => intval($f['id']),
                'severity' => (string)$f['severity'],
                'rule_name' => (string)$f['rule_name'],
                'title' => (string)$f['title'],
                'page_url' => (string)($f['page_url'] ?? ''),
                'element' => (string)($f['element'] ?? ''),
                'description' => (string)($f['description'] ?? ''),
                'remediation' => (string)($f['remediation'] ?? ''),
            );
        }
        echo json_encode(array(
            'status' => 'ok',
            'scan' => array(
                'id' => intval($row['id']),
                'name' => (string)$row['name'],
                'target_url' => (string)($row['target_url'] ?? ''),
                'profile' => (string)($row['profile'] ?? ''),
                'environment' => (string)($row['environment'] ?? ''),
                'browser' => (string)($row['browser'] ?? ''),
                'scan_date' => (string)($row['scan_date'] ?? ''),
                'errors' => intval($row['errors']),
                'warnings' => intval($row['warnings']),
                'notices' => intval($row['notices']),
                'verdict' => (string)$row['verdict'],
                'report_url' => (string)($row['report_url'] ?? ''),
                'notes' => (string)($row['notes'] ?? ''),
                'tplan_id' => intval($row['tplan_id']),
                'build_id' => intval($row['build_id']),
                'tcase_id' => intval($row['tcase_id']),
            ),
            'findings' => $findings,
        ));
        exit;
    }

    $items = a11yList($db, $t, $tpid);
    if ($action === 'list') {
        echo json_encode(array('status' => 'ok', 'items' => $items));
        exit;
    }
    $agg = a11yStats($items);
    echo json_encode(array(
        'status' => 'ok',
        'context' => array(
            'tproject_id' => intval($tpid),
            'tproject_name' => (string)$info['name'],
            'tproject_prefix' => (string)$info['prefix'],
            'can_view' => true,
            'can_modify' => a11yCanModify($user, $db, $tpid),
        ),
        'domains' => array(
            'severities' => a11ySeverities(),
            'verdicts' => a11yVerdicts(),
            'thresholds' => array(
                'fail' => 'errors > 0',
                'warn' => 'errors = 0 and warnings > 0',
                'pass' => 'errors = 0 and warnings = 0',
            ),
        ),
        'refs' => a11yRefs($db, $tpid),
        'stats' => $agg['stats'],
        'trend' => $agg['trend'],
        'items' => $items,
    ));
    exit;
}

if (!$isWrite) {
    http_response_code(400);
    echo json_encode(array('status' => 'error', 'code' => 'unknown_action',
        'message' => 'Unknown action'));
    exit;
}

// ---- writes ----------------------------------------------------------------

if ($action === 'import') {
    $body = a11yBody();
    $name = a11yField($body, 'name', 255);
    $target = a11yField($body, 'target_url', 1024);
    if ($name === '') {
        $name = $target !== '' ? $target : 'Koa11y scan';
    }
    $tplanId = a11yInt($body, 'tplan_id');
    $buildId = a11yInt($body, 'build_id');
    $tcaseId = a11yInt($body, 'tcase_id');

    // Prove every optional link belongs to the addressed project.
    if ($tplanId > 0) {
        $chk = $db->fetchFirstRow(
            "SELECT id FROM " . tlObjectWithDB::getDBTables(array('testplans'))['testplans'] .
            " WHERE id = " . intval($tplanId) .
            " AND testproject_id = " . intval($tpid));
        if (!is_array($chk) || !$chk) {
            http_response_code(400);
            echo json_encode(array('status' => 'error', 'code' => 'invalid_tplan',
                'message' => 'The linked test plan does not belong to this project'));
            exit;
        }
    }
    if ($buildId > 0) {
        $chk = $db->fetchFirstRow(
            "SELECT id FROM " . tlObjectWithDB::getDBTables(array('builds'))['builds'] .
            " WHERE id = " . intval($buildId) .
            " AND testproject_id = " . intval($tpid));
        if (!is_array($chk) || !$chk) {
            http_response_code(400);
            echo json_encode(array('status' => 'error', 'code' => 'invalid_build',
                'message' => 'The linked build does not belong to this project'));
            exit;
        }
    }
    if ($tcaseId > 0) {
        $type = a11yNodeInProject($db, $tcaseId, $tpid);
        if ($type !== 3) {
            http_response_code(400);
            echo json_encode(array('status' => 'error', 'code' => 'invalid_tcase',
                'message' => 'The linked test case does not belong to this project'));
            exit;
        }
    }

    $findings = a11yNormalizeFindings($body['findings'] ?? array());
    $errors = 0; $warnings = 0; $notices = 0;
    foreach ($findings as $f) {
        if ($f['severity'] === 'error') { $errors++; }
        else if ($f['severity'] === 'warning') { $warnings++; }
        else { $notices++; }
    }
    // Summary-only exports: accept the three counts when no findings are sent.
    if (count($findings) === 0) {
        $errors = max(0, a11yInt($body, 'errors'));
        $warnings = max(0, a11yInt($body, 'warnings'));
        $notices = max(0, a11yInt($body, 'notices'));
    }
    $verdict = a11yVerdict($errors, $warnings);

    $scans = $t['tc_a11y_scans'];
    $reportUrl = a11yField($body, 'report_url', 1024);
    $notes = a11yField($body, 'notes', 20000);
    $profile = a11yField($body, 'profile', 100);
    $environment = a11yField($body, 'environment', 128);
    $browser = a11yField($body, 'browser', 128);
    $toolVersion = a11yField($body, 'tool_version', 64);
    $scanDate = a11yDate($body['scan_date'] ?? '');

    $planVal = $tplanId > 0 ? intval($tplanId) : 'NULL';
    $buildVal = $buildId > 0 ? intval($buildId) : 'NULL';
    $tcaseVal = $tcaseId > 0 ? intval($tcaseId) : 'NULL';

    try {
        $db->exec_query(
            "INSERT INTO {$scans} (testproject_id, tplan_id, build_id, tcase_id," .
            " name, target_url, profile, environment, browser, scan_date," .
            " tool_version, errors, warnings, notices, verdict, report_url," .
            " notes, created_by, update_ts) VALUES (" .
            intval($tpid) . ", " . $planVal . ", " . $buildVal . ", " . $tcaseVal . ", '" .
            $db->prepare_string($name) . "', '" .
            $db->prepare_string($target) . "', '" .
            $db->prepare_string($profile) . "', '" .
            $db->prepare_string($environment) . "', '" .
            $db->prepare_string($browser) . "', '" .
            $db->prepare_string($scanDate) . "', '" .
            $db->prepare_string($toolVersion) . "', " .
            intval($errors) . ", " . intval($warnings) . ", " . intval($notices) . ", '" .
            $db->prepare_string($verdict) . "', '" .
            $db->prepare_string($reportUrl) . "', '" .
            $db->prepare_string($notes) . "', " . intval($userId) . ", NOW())");
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(array('status' => 'error', 'code' => 'insert_failed',
            'message' => 'Could not save the accessibility scan'));
        exit;
    }
    $newId = (int)$db->insert_id($scans);
    if ($newId <= 0) {
        http_response_code(500);
        echo json_encode(array('status' => 'error', 'code' => 'insert_failed',
            'message' => 'Could not save the accessibility scan'));
        exit;
    }

    if (count($findings) > 0) {
        $findTbl = $t['tc_a11y_findings'];
        try {
            foreach ($findings as $f) {
                $db->exec_query(
                    "INSERT INTO {$findTbl} (scan_id, severity, rule_name, title," .
                    " page_url, element, description, remediation, sort_order) VALUES (" .
                    intval($newId) . ", '" . $db->prepare_string($f['severity']) . "', '" .
                    $db->prepare_string($f['rule_name']) . "', '" .
                    $db->prepare_string($f['title']) . "', '" .
                    $db->prepare_string($f['page_url']) . "', '" .
                    $db->prepare_string($f['element']) . "', '" .
                    $db->prepare_string($f['description']) . "', '" .
                    $db->prepare_string($f['remediation']) . "', " .
                    intval($f['sort_order']) . ")");
            }
        } catch (Exception $e) {
            // Keep the registry consistent: a half-imported scan is removed.
            try {
                $db->exec_query(
                    "DELETE FROM {$findTbl} WHERE scan_id = " . intval($newId));
                $db->exec_query(
                    "DELETE FROM {$scans} WHERE id = " . intval($newId));
            } catch (Exception $ignored) { /* best effort */ }
            http_response_code(500);
            echo json_encode(array('status' => 'error', 'code' => 'findings_failed',
                'message' => 'Could not save the scan findings'));
            exit;
        }
    }

    a11yAudit('Accessibility scan imported: ' . $name . ' (id ' . $newId . ')',
        'A11Y_SCAN_IMPORT', $tpid);

    echo json_encode(array('status' => 'ok', 'id' => $newId,
        'verdict' => $verdict, 'errors' => $errors, 'warnings' => $warnings,
        'notices' => $notices, 'message' => 'Imported'));
    exit;
}

if ($action === 'delete') {
    $body = a11yBody();
    $id = a11yInt($body, 'id');
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(array('status' => 'error', 'code' => 'bad_id',
            'message' => 'id is mandatory'));
        exit;
    }
    $chk = $db->fetchFirstRow(
        "SELECT name FROM {$t['tc_a11y_scans']} WHERE id = " . intval($id) .
        " AND testproject_id = " . intval($tpid));
    if (!is_array($chk) || !$chk) {
        http_response_code(404);
        echo json_encode(array('status' => 'error', 'code' => 'scan_not_found',
            'message' => 'Accessibility scan not found'));
        exit;
    }
    try {
        $db->exec_query(
            "DELETE FROM {$t['tc_a11y_findings']} WHERE scan_id = " . intval($id));
        $db->exec_query(
            "DELETE FROM {$t['tc_a11y_scans']} WHERE id = " . intval($id) .
            " AND testproject_id = " . intval($tpid));
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(array('status' => 'error', 'code' => 'delete_failed',
            'message' => 'Could not delete the accessibility scan'));
        exit;
    }

    a11yAudit('Accessibility scan deleted: ' . (string)$chk['name'] . ' (id ' . $id . ')',
        'A11Y_SCAN_DELETE', $tpid);

    echo json_encode(array('status' => 'ok', 'deleted' => 1, 'message' => 'Deleted'));
    exit;
}

http_response_code(400);
echo json_encode(array('status' => 'error', 'code' => 'unknown_action',
    'message' => 'Unknown action'));
