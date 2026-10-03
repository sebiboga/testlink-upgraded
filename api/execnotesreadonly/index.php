<?php
/**
 * api/execnotesreadonly — read-only Execution Notes viewer BFF (Refs #1807)
 *
 * Replaces the legacy read-only Execution Notes page
 * (lib/execute/getExecNotes.php + gui/templates/dashio/execute/getExecNotes.tpl),
 * which is still loaded as an AJAX fragment by 4 legacy call sites:
 *   gui/templates/{dashio,tl-classic}/execute/include/execSetResultsUtils.inc.tpl
 *   gui/templates/{dashio,tl-classic}/execute/include/execSetResultsJS.inc.tpl
 *   gui/templates/{dashio,tl-classic}/execute/execHistory.tpl
 * all as `url2load=fRoot+'lib/execute/getExecNotes.php?readonly=1&exec_id=' + exec_id`.
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
bffSameOriginGuard();

require_once(__DIR__ . '/../../lib/functions/exec.inc.php');

// nodes_hierarchy.node_type_id literals (2.0.1 dropped the TLO_* constants):
// 1 = test project root, 3 = test case, 4 = test case version.
define('ENRO_NODE_TESTPROJECT', 1);

$db = new database(DB_TYPE);
doDBConnect($db);

header('X-Content-Type-Options: nosniff');

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

/**
 * Stable machine-coded JSON failure. The modern screen keys its state cards off
 * `code`, never off the English message, so the message can be reworded freely.
 */
function fail($status, $code, $message) {
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

if ($method !== 'GET' && $method !== 'HEAD') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    header('Allow: GET, HEAD');
    echo json_encode(array(
        'status' => 'error',
        'code' => 'method_not_allowed',
        'message' => 'This endpoint is read-only; use GET',
    ));
    exit;
}

$action = trim((string)($_GET['action'] ?? 'view'));
if ($action === '') {
    $action = 'view';
}
if ($action !== 'view' && $action !== 'fragment') {
    fail(400, 'unknown_action', 'Unknown action');
}

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    fail(401, 'not_authenticated', 'Not authenticated');
}

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    fail(401, 'not_authenticated', 'Not authenticated');
}

// Enforces the legacy session inactivity window (same note as every other BFF).
// Runs BEFORE the object is resolved so a stale session cannot be used as a
// read oracle.
if (function_exists('bffEnforceSession')) {
    bffEnforceSession($db);
}

$execIdRaw = trim((string)($_GET['exec_id'] ?? ''));
if ($execIdRaw === '' || !preg_match('/^[0-9]+$/', $execIdRaw) || intval($execIdRaw) <= 0) {
    fail(400, 'invalid_exec_id', 'A positive exec_id is required');
}
$execId = intval($execIdRaw);

// ---------------------------------------------------------------------------
// Resolve the execution and PROVE the owning test plan + test project.
// ---------------------------------------------------------------------------
$tables = tlObjectWithDB::getDBTables(array('executions', 'testplans'));

$rs = get_execution($db, $execId);
if (!$rs || count($rs) === 0) {
    fail(404, 'exec_not_found', 'Execution not found');
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
    fail(404, 'exec_not_found', 'Execution not found');
}
$tprojectId = intval($tpRs[0]['testproject_id']);

// Read grant, identical to api/execnotes (Refs #1551) so the standalone viewer
// and the in-page popup can never disagree about who may read the notes.
$readGrant = $user->hasRight($db, 'exec_ro_access', $tprojectId, $tplanId)
    || $user->hasRight($db, 'exec_edit_notes', $tprojectId, $tplanId)
    || $user->hasRight($db, 'testplan_execute', $tprojectId, $tplanId);

if (!$readGrant) {
    tLog('BFF: user ' . intval($userId) . ' refused execution notes for execution ' .
         $execId . ' (testproject ' . $tprojectId . ', testplan ' . $tplanId . ') - no right',
         'WARNING');
    fail(403, 'no_right', 'You do not have rights on this execution');
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
    $s = preg_replace('/^[\x{FEFF}\x{200B}]+/u', '', $s);
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
$audit = get_execution($db, $execId, array('output' => 'audit'));
$auditRow = ($audit && count($audit) > 0) ? $audit[0] : array();

// Prove the executed test case version really lives in the execution's own test
// project before its name is exposed next to the notes.
$nhTables = tlObjectWithDB::getDBTables('nodes_hierarchy');
$tcNode = enro_tc_node($db, intval($row['tcversion_id']), $nhTables, $tprojectId);
if (is_null($tcNode)) {
    fail(404, 'exec_not_found', 'Execution not found');
}

header('Content-Type: application/json; charset=utf-8');
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
));

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
            $tcaseName = (string)$n['name'];
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
