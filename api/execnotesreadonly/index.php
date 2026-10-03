<?php
/**
 * api/execnotesreadonly — read-only Execution Notes viewer BFF (Refs #1807)
 *
 * Replaces the legacy read-only Execution Notes page
 * (lib/execute/getExecNotes.php + gui/templates/dashio/execute/getExecNotes.tpl),
 * which is still loaded as an AJAX fragment by 5 legacy call sites:
 *   gui/templates/dashio/execute/include/execSetResultsUtils.inc.tpl
 *   gui/templates/dashio/execute/include/execSetResultsJS.inc.tpl
 *   gui/templates/dashio/execute/execHistory.tpl
 *   gui/templates/tl-classic/execute/include/execSetResultsUtils.inc.tpl
 *   gui/templates/tl-classic/execute/execSetResults.tpl
 *   gui/templates/tl-classic/execute/execHistory.tpl
 * all as `url2load=fRoot+'lib/execute/getExecNotes.php?readonly=1&exec_id=' + exec_id`.
 * (Six files carry the line; `tl-classic/execute/include/execSetResultsJS.inc.tpl`
 * does not exist, so the count of DISTINCT call sites is 5.)
 *
 * Routes (both safe verbs only - this endpoint NEVER writes):
 *   GET|HEAD ?action=view&exec_id=N     -> JSON payload for the modern screen
 *   GET|HEAD ?action=fragment&exec_id=N -> ESCAPED plain-text HTML fragment,
 *                                          the contract the legacy url2load
 *                                          call sites assign into innerHTML
 *
 * Security, versus the legacy controller (Refs #1807):
 *   - legacy called only testlinkInitPage($db): a session, NO right and NO
 *     ownership, so any authenticated user (role 3 `<no rights>` included) could
 *     read the notes of ANY execution on ANY test project by enumerating ?exec_id=;
 *   - here the right is enforced on the OWNING test project, resolved by walking
 *     executions -> testplans -> testproject, never from a client-supplied id;
 *   - exec_id is validated as a strictly positive integer (legacy INT_N accepted
 *     0 and negatives, which get_execution() then matched nothing and $map[0]
 *     was dereferenced anyway -> E_WARNING + fatal 500);
 *   - the stored RichEdit blob is never handed to the web editor / injected as
 *     markup: it is flattened server-side to PLAIN TEXT and the fragment branch
 *     html-escapes it, so a stored payload cannot execute in the app origin.
 *
 * Session-based auth, JSON/plain-text I/O. No Smarty.
 */
require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');

// The verb check is deliberately BEFORE bffSameOriginGuard(): this endpoint has
// no write path at all, so the guard protects nothing here, while running first
// it would swallow the documented 405 and answer 403 for every plain POST. Order
// matters only for the code a caller sees, not for safety.
enro_require_safe_verb();

bffSameOriginGuard();

require_once(__DIR__ . '/../../lib/functions/exec.inc.php');

// nodes_hierarchy.node_type_id literals (2.0.1 dropped the TLO_* constants):
// 1 = test project root, 3 = test case, 4 = test case version.
if (!defined('ENRO_NODE_TESTPROJECT')) {
    define('ENRO_NODE_TESTPROJECT', 1);
}

$db = new database(DB_TYPE);
doDBConnect($db);

header('X-Content-Type-Options: nosniff');

/**
 * Refuse every write verb explicitly, so a POST here can never be mistaken for a
 * supported action (and so the 405/Allow contract holds for every caller).
 */
function enro_require_safe_verb() {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method !== 'GET' && $method !== 'HEAD') {
        header('Allow: GET, HEAD');
        http_response_code(405);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array(
            'status' => 'error',
            'code' => 'method_not_allowed',
            'message' => 'This endpoint is read-only; use GET',
        ));
        exit;
    }
}

/**
 * Stable machine-coded JSON failure. The modern screen keys its state cards off
 * `code`, never off the English message, so the message can be reworded freely.
 */
function enro_fail($status, $code, $message) {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        http_response_code($status);
    }
    echo json_encode(array(
        'status' => 'error',
        'code' => $code,
        'message' => $message,
    ));
    exit;
}

/**
 * Require a same-origin caller for action=fragment (Refs #1808).
 *
 * Semantics follow the shared guard api/_guard.php:126-145
 * (bffSameOriginGuard), which the private check in lib/execute/getExecNotes.php
 * mirrors and is SLIGHTLY STRICTER than - it requires EVERY present header to
 * match, while this function (and bffSameOriginGuard) returns on the first
 * match. The only combination where the two answers differ is a same-origin
 * `Origin` paired with a FOREIGN `Referer`, and there the shim - which runs
 * first and `require`s this file - is the stricter one, so the effective verdict
 * never changes and the 5 legacy call sites cannot break. Not exploitable
 * either way: a cross-origin browser always presents a foreign `Origin` first,
 * and a matching `Origin` already means the caller is same-origin.
 *
 *   - a PRESENT Origin OR Referer is AUTHORITATIVE: unparseable (file://, a
 *     malformed host, a "null" origin) or foreign -> 403 outright, and the XRW
 *     hint NEVER overrides it (same ruling as issue #1679). Without this, any
 *     client could add X-Requested-With to a cross-origin request and pass;
 *   - NEITHER header present -> fall back to the browser's own same-origin
 *     marker for this very call, which is what a same-origin page's $.ajax /
 *     $.getJSON sets. A same-origin GET sends neither header by default, so
 *     without this fallback the 5 legacy url2load() call sites would break;
 *   - anything else -> 403.
 *
 * Default-port normalisation matches api/_guard.php:110-121: Origin/Referer omit
 * the scheme default port while HTTP_HOST keeps it, so Host "localhost:80" and
 * Origin "http://localhost" are the same origin. Only the DEFAULT port is ever
 * stripped, so a real mismatch (Origin :80 vs Host :8082) is still rejected.
 */
function enro_require_same_origin_fragment() {
    $https = strtolower(trim((string)($_SERVER['HTTPS'] ?? '')));
    $defaultPort = ':' . (($https !== '' && $https !== 'off') ? '443' : '80');
    $hostKey = bffStripDefaultPort(bffAuthority($_SERVER['HTTP_HOST'] ?? ''), $defaultPort);

    foreach (array('HTTP_ORIGIN', 'HTTP_REFERER') as $hdr) {
        $val = trim((string)($_SERVER[$hdr] ?? ''));
        if ($val === '') {
            continue;
        }
        $parts = parse_url($val);
        if (empty($parts['host'])) {
            enro_forbid_origin('unparseable_origin');
        }
        $authority = strtolower($parts['host']);
        if (!empty($parts['port'])) {
            $authority .= ':' . $parts['port'];
        }
        if ($hostKey !== '' &&
            strcasecmp(bffStripDefaultPort($authority, $defaultPort), $hostKey) === 0) {
            return;   // proven same-origin by the authoritative header
        }
        enro_forbid_origin('cross_origin');
    }

    // Reached only when the browser sent neither Origin nor Referer.
    $xrw = trim((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    if (strcasecmp($xrw, 'XMLHttpRequest') === 0) {
        return;
    }

    enro_forbid_origin('cross_origin');
}

/**
 * Same-origin refusal with a machine code, so a caller can tell a foreign Origin
 * from an unparseable one, and so the refusal can never be mistaken for the BFF's
 * own answers (which use the enro_fail() envelope).
 */
function enro_forbid_origin($code) {
    // headers_sent() guard, exactly like enro_fail() and bffRejectForbidden():
    // without it a refusal that follows any bootstrap output would ship as a 200
    // with a "Cannot modify header information" warning - a security refusal
    // reported to the caller as success.
    if (!headers_sent()) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
    }
    echo json_encode(array(
        'status' => 'error',
        'code' => $code,
        'message' => 'Forbidden: same-origin required',
    ));
    exit;
}

// is_scalar() first (Refs #1808): `?action[]=fragment` would otherwise reach the
// (string) cast, which emits "Array to string conversion" as an E_WARNING - and
// watchPHPErrors() (lib/functions/logger.class.php:1483) writes that into the
// EVENTS TABLE. It fired UNAUTHENTICATED, because this line runs before the
// session check, so any anonymous caller could add a Warning to the Event Viewer.
//
// A non-scalar action is refused outright rather than normalised: coercing it to
// '' would fall through to the `if ($action === '') $action = 'view';` default
// below and silently SERVE THE VIEW PAYLOAD for a malformed parameter, where the
// plain (string) cast used to land on the 400 unknown_action path.
$actionArg = $_GET['action'] ?? 'view';
if (!is_scalar($actionArg)) {
    enro_fail(400, 'unknown_action', 'Unknown action');
}
$action = trim((string)$actionArg);
if ($action === '') {
    $action = 'view';
}
if ($action !== 'view' && $action !== 'fragment') {
    enro_fail(400, 'unknown_action', 'Unknown action');
}

// Same-origin requirement for the innerHTML-sink route ONLY (Refs #1808).
// Runs BEFORE the user row is resolved so a foreign caller learns nothing at all,
// and deliberately before bffEnforceSession() so it cannot be used to keep a stale
// session alive.
//
// Rationale: action=fragment is a PUBLIC route that emits an HTML fragment built
// for innerHTML (5 legacy url2load() call sites). bffSameOriginGuard() above is a
// WRITE-VERB guard and returns immediately for GET/HEAD, so on this read-only
// endpoint it never ran - the requirement was enforced only inside the shim
// lib/execute/getExecNotes.php, i.e. only for callers that happened to use that
// URL, while this route produced the SAME bytes with no check at all.
//
// action=view is deliberately NOT gated: it is JSON, its only caller is the
// same-origin $.getJSON at gui/templates/execute/execNotesReadonly.html:169, and
// the endpoint sends no Access-Control-Allow-Origin, so it is already unreadable
// cross-origin.
if ($action === 'fragment') {
    enro_require_same_origin_fragment();
}

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    enro_fail(401, 'not_authenticated', 'Not authenticated');
}

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    enro_fail(401, 'not_authenticated', 'Not authenticated');
}

// Enforces the legacy session inactivity window (same note as every other BFF).
// Runs after the user row is resolved but BEFORE any execution data is touched, so
// a stale session cannot be used as a read oracle.
if (function_exists('bffEnforceSession')) {
    bffEnforceSession($db);
}

// Same is_scalar() guard, same reasoning, as for $action above (Refs #1808).
$execIdArg = $_GET['exec_id'] ?? '';
if (!is_scalar($execIdArg)) {
    enro_fail(400, 'invalid_exec_id', 'A positive exec_id is required');
}
$execIdRaw = trim((string)$execIdArg);
if ($execIdRaw === '' || !preg_match('/^[0-9]+$/', $execIdRaw) || intval($execIdRaw) <= 0) {
    enro_fail(400, 'invalid_exec_id', 'A positive exec_id is required');
}
$execId = intval($execIdRaw);

// ---------------------------------------------------------------------------
// Resolve the execution and PROVE the owning test plan + test project.
// ---------------------------------------------------------------------------
$tables = tlObjectWithDB::getDBTables('testplans');

$rs = get_execution($db, $execId);
if (!$rs || count($rs) === 0) {
    enro_fail(404, 'exec_not_found', 'Execution not found');
}
$row = $rs[0];

$tplanId = intval($row['testplan_id']);
$tpRs = ($tplanId > 0)
    ? $db->get_recordset("SELECT id, testproject_id FROM {$tables['testplans']} WHERE id=" . $tplanId)
    : null;
if (!$tpRs || count($tpRs) === 0) {
    // An execution whose plan vanished cannot be authorized - fail closed
    // instead of falling back to the SESSION project (that would be a
    // confused-deputy read).
    enro_fail(404, 'exec_not_found', 'Execution not found');
}
$tprojectId = intval($tpRs[0]['testproject_id']);

// Read grant, identical to api/execnotes (Refs #1551) so the standalone viewer
// and the in-page popup can never disagree about who may read the notes.
$readGrant = $user->hasRight($db, 'exec_ro_access', $tprojectId, $tplanId)
    || $user->hasRight($db, 'exec_edit_notes', $tprojectId, $tplanId)
    || $user->hasRight($db, 'testplan_execute', $tprojectId, $tplanId);

if (!$readGrant) {
    // AUDIT, not WARNING: a refused read is a security-relevant event worth
    // recording, but logging it as a WARNING would make every denied user show up
    // in the Event Viewer as a new Warning (rule: the Event Viewer must stay free
    // of new Error/Warning entries from ordinary use).
    tLog('BFF: user ' . intval($userId) . ' refused execution notes for execution ' .
         $execId . ' (testproject ' . $tprojectId . ', testplan ' . $tplanId . ') - no right',
         'AUDIT');
    // BYTE-IDENTICAL to the not-found answer on purpose. Answering 403 here while
    // answering 404 for a missing execution is an existence oracle: any
    // authenticated user, role 3 `<no rights>` included, could enumerate
    // ?exec_id=1..N and learn WHICH executions exist across every test project.
    // Same ruling as issue #1792 (api/builds did exactly this on tplan_id), so the
    // two refusals are collapsed into one opaque answer.
    enro_fail(404, 'exec_not_found', 'Execution not found');
}

// ---------------------------------------------------------------------------
// Flatten the stored RichEdit/HTML blob to PLAIN TEXT.
// Shared by both branches so the page and the legacy fragment can never show
// different content for the same row.
// ---------------------------------------------------------------------------
function enro_notes_to_text($html) {
    $s = (string)$html;
    if (trim($s) === '') {
        return '';
    }
    // Normalise line endings first, so the <br> -> newline rewrite below cannot
    // produce a mixed CRLF/LF blob in the fragment.
    $s = str_replace(array("\r\n", "\r"), "\n", $s);
    // Block boundaries become newlines BEFORE tag stripping, otherwise every
    // <p> collapses the paragraph structure of a rich text note.
    $s = preg_replace('#<br\s*/?>#i', "\n", $s);
    $s = preg_replace('#</(p|div|li|tr|h[1-6])\s*>#i', "\n", $s);
    // Everything else is markup and must not survive into textContent.
    $s = strip_tags($s);
    // Decode entities ONCE - the stored blob is already decoded, decoding twice
    // would surface the raw markup of a doubly-escaped note.
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // Strip a zero-width / BOM prefix that RichEdit sometimes stores.
    // Deliberately BYTE-mode, no /u: preg_replace() returns NULL when the subject
    // is not valid UTF-8 (a Latin-1 byte or a truncated sequence, which is exactly
    // what a 1.9.20 database carries), and that NULL used to propagate into
    // trim() below - so a note that DOES exist rendered as "No execution notes
    // recorded.".
    $bom = preg_replace('/^(\xEF\xBB\xBF|\xE2\x80\x8B)+/', '', $s);
    if ($bom !== null) {
        $s = $bom;
    }
    // Collapse 3+ blank lines and trim trailing space per line.
    $s = preg_replace("/[ \t]+\n/", "\n", $s);
    $s = preg_replace("/\n{3,}/", "\n\n", $s);
    return trim($s);
}

$notesRaw = isset($row['notes']) ? (string)$row['notes'] : '';
$notesText = enro_notes_to_text($notesRaw);

// ---------------------------------------------------------------------------
// Branch: fragment - the contract the legacy url2load call sites need.
// ---------------------------------------------------------------------------
if ($action === 'fragment') {
    // Legacy templates assign this into innerHTML of an Ext panel, so the
    // answer MUST be an HTML fragment. Every byte that came from the database
    // is html-escaped here; the only markup emitted is the read-only pre
    // wrapper the legacy .tpl rendered.
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: private, no-store');
    $escaped = htmlspecialchars($notesText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo '<pre class="execnotes-readonly-fragment">' . $escaped . '</pre>';
    exit;
}

// ---------------------------------------------------------------------------
// Branch: view - the JSON payload for the modern Dashio screen.
// ---------------------------------------------------------------------------
// Prove the executed test case version really lives in the execution's own test
// project BEFORE the audit join: the join is the expensive query, and on the
// fail-closed 404 path it would be pure waste.
$nhTables = tlObjectWithDB::getDBTables('nodes_hierarchy');
$tcNode = enro_tc_node($db, intval($row['tcversion_id']), $nhTables, $tprojectId);
if (is_null($tcNode)) {
    enro_fail(404, 'exec_not_found', 'Execution not found');
}

$audit = get_execution($db, $execId, array('output' => 'audit'));
$auditRow = ($audit && count($audit) > 0) ? $audit[0] : array();

header('Content-Type: application/json; charset=utf-8');
// JSON_INVALID_UTF8_SUBSTITUTE so one bad byte cannot make json_encode() return
// false, which would print an EMPTY body and degrade the screen to a generic
// http_200 card.
echo json_encode(array(
    'status' => 'ok',
    'execution' => array(
        'id' => intval($row['id']),
        'tcversion_id' => intval($row['tcversion_id']),
        'testplan_id' => $tplanId,
        'testproject_id' => $tprojectId,
        'build_id' => intval($row['build_id']),
        'status_char' => isset($row['status']) ? (string)$row['status'] : '',
        'execution_ts' => isset($row['execution_ts']) ? (string)$row['execution_ts'] : '',
        'notes' => $notesText,
        'notes_empty' => ($notesText === ''),
        'testcase_name' => $tcNode['name'],
        'testcase_external_id' => $tcNode['external_id'],
        'testcase_suite' => $tcNode['suite'],
        'testplan_name' => isset($auditRow['testplan_name']) ? (string)$auditRow['testplan_name'] : '',
        'testproject_name' => isset($auditRow['testproject_name']) ? (string)$auditRow['testproject_name'] : '',
        'build_name' => isset($auditRow['build_name']) ? (string)$auditRow['build_name'] : '',
        'platform_name' => isset($auditRow['platform_name']) ? (string)$auditRow['platform_name'] : '',
    ),
    'legacy' => array(
        'controller' => 'lib/execute/getExecNotes.php',
        'params' => '?readonly=1&exec_id=' . $execId,
        'status_char' => isset($row['status']) ? (string)$row['status'] : '',
    ),
    // hasRight() answers the string 'yes'/'' in 2.0.1 - normalise to a bool
    // so the client can use it as a truthy value without guessing the literal.
    'can_edit' => ($user->hasRight($db, 'exec_edit_notes', $tprojectId, $tplanId) === 'yes'),
), JSON_INVALID_UTF8_SUBSTITUTE);

/**
 * Resolve the test case node behind an executed test case version and PROVE it
 * belongs to the execution's own test project by walking nodes_hierarchy.parent_id
 * up to the node_type_id = 1 root.
 *
 * Returns array('external_id' => string, 'name' => string) or null. Returns null
 * for an orphan/unknown version AND for a version whose test case lives in another
 * project - the caller then fails closed with 404 instead of rendering a
 * foreign-project test case name next to the notes.
 *
 * Note: TestLink 2.0.1 has NO testcase_version_holder table (the tcversion node's
 * parent IS the test case), which is why this walks the hierarchy directly.
 */
function enro_tc_node($db, $tcversionId, $tables, $tprojectId) {
    if ($tcversionId <= 0) {
        return null;
    }
    $rs = $db->get_recordset(
        "SELECT n.parent_id AS tcase_id FROM {$tables['nodes_hierarchy']} n " .
        "WHERE n.id = " . intval($tcversionId) . " AND n.node_type_id = 4");
    if (!$rs || count($rs) === 0) {
        return null;
    }
    $tcaseId = intval($rs[0]['tcase_id']);
    if ($tcaseId <= 0) {
        return null;
    }

    // Walk up to the test project root. nodes_hierarchy in 2.0.1 carries ONLY
    // id/name/parent_id/node_type_id/node_order (tc_external_id, tcversion_number,
    // active and is_open were dropped onto tcversions), so the walk selects those
    // four columns and nothing else.
    $cursor   = $tcaseId;
    $tcaseName = '';
    $suiteName = '';
    for ($guard = 0; $guard < 64; $guard++) {
        $node = $db->get_recordset(
            "SELECT id, parent_id, node_type_id, name FROM {$tables['nodes_hierarchy']} " .
            "WHERE id = " . $cursor);
        if (!$node || count($node) === 0) {
            return null;
        }
        $n     = $node[0];
        $ntype = intval($n['node_type_id']);
        if ($ntype === 3) {
            // The FIRST node walked is the version's parent and MUST be a test
            // case: a parent_id pointing at a suite would otherwise answer 200
            // with an empty testcase_name.
            if ($cursor === $tcaseId && $tcaseName === '') {
                $tcaseName = (string)$n['name'];
            } else {
                return null;
            }
        } elseif ($ntype === 2) {
            $suiteName = (string)$n['name'];
        } elseif ($ntype === ENRO_NODE_TESTPROJECT) {
            if (intval($n['id']) !== $tprojectId) {
                return null;   // foreign project -> fail closed
            }
            return array(
                'external_id' => enro_external_id($db, $tcversionId, $tprojectId),
                'name'        => $tcaseName,
                'suite'       => $suiteName,
            );
        }
        $parent = intval($n['parent_id']);
        if ($parent <= 0 || $parent === $cursor) {
            return null;
        }
        $cursor = $parent;
    }
    return null;
}

/**
 * Compose the #PREFIX-N chip of an executed test case version.
 *
 * 2.0.1 keeps the test case NUMBER in tcversions.tc_external_id (a mis-named
 * column: it holds the numeric part only) and the string part in
 * testprojects.prefix. Returns '' when either cannot be resolved, so the caller
 * shows a placeholder instead of a wrong id.
 */
function enro_external_id($db, $tcversionId, $tprojectId) {
    $tvt = tlObjectWithDB::getDBTables(array('tcversions', 'testprojects'));
    $rs = $db->get_recordset(
        "SELECT tv.tc_external_id AS num, tp.prefix AS prefix FROM {$tvt['tcversions']} tv " .
        "LEFT OUTER JOIN {$tvt['testprojects']} tp ON tp.id = " . intval($tprojectId) . " " .
        "WHERE tv.id = " . intval($tcversionId));
    if (!$rs || count($rs) === 0) {
        return '';
    }
    $num    = intval($rs[0]['num']);
    $prefix = trim((string)$rs[0]['prefix']);
    if ($num <= 0 || $prefix === '') {
        return '';
    }
    return $prefix . '-' . $num;
}
