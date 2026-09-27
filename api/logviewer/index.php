<?php
/**
 * Requirement log-message viewer BFF API
 * URL: /api/logviewer/index.php
 * Plain PHP, no framework, no compilation.
 *
 * Modern twin of the two legacy AJAX log readers that the (already modernized)
 * requirement screens still referenced:
 *
 *   - lib/ajax/getreqspeclog.php  -> requirement SPEC version log
 *     (req_specs_revisions.log_message)
 *   - lib/ajax/getreqlog.php      -> requirement VERSION log (req_versions.log_message)
 *                                     or, when the node is not a requirement_version,
 *                                     the requirement REVISION log (req_revisions.log_message)
 *
 * Both legacy endpoints `echo`ed a raw HTML fragment built with nl2br() +
 * str_replace('<p>','',...) straight out of the log_message column, with no
 * session gate beyond testlinkInitPage()'s page bootstrap, no rights check and
 * no escaping. This BFF never emits HTML: it returns the log as PLAIN TEXT and
 * the modern screen renders it, so a log containing markup can no longer be
 * injected into the DOM. It also proves that the id really is a node of the
 * declared type and that it lives in the requested test project, which the
 * legacy SQL (SELECT log_message ... WHERE id=<intval>) did not.
 *
 * Endpoints (JSON in/out):
 *   GET ?action=log&type=<requirement_spec_version|requirement_version|requirement>&id=N
 *       [&tproject_id=M]
 *       -> { context: {...}, log: { text, is_empty, raw_length } }
 *
 * Auth/contract: 401 anon | 401 session_expired | 403 no mgt_view_req |
 *                 400 bad/missing type or id | 404 unknown id / wrong node type
 *                 / foreign project | 405 non-GET | 500 guarded.
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
require_once(__DIR__ . '/../../lib/functions/requirements.inc.php');
require_once(__DIR__ . '/../../lib/functions/requirement_mgr.class.php');
require_once(__DIR__ . '/../../lib/functions/requirement_spec_mgr.class.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

/**
 * Guarded 500: a DB error or an unexpected PHP fatal must still leave a valid
 * JSON contract for the modern screen instead of TestLink's raw HTML error
 * page (which is what an un-guarded exec_query() failure used to return).
 */
function lvShutdownGuard() {
    $err = error_get_last();
    if ($err === null) {
        return;
    }
    if (!in_array($err['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
        return;
    }
    if (headers_sent()) {
        return;
    }
    tLog('BFF logviewer: ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line'],
         'ERROR');
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    http_response_code(500);
    echo json_encode(array(
        'status' => 'error',
        'code' => 'internal_error',
        'message' => 'Internal error while reading the log message',
    ));
}
register_shutdown_function('lvShutdownGuard');

$db = new database(DB_TYPE);
doDBConnect($db);

function lvOut($data, $code = 200) {
    if (!headers_sent()) {
        http_response_code($code);
    }
    echo json_encode($data);
    exit;
}

function lvError($code, $msg, $machine = null) {
    $payload = array('status' => 'error', 'message' => $msg);
    if ($machine !== null) {
        $payload['code'] = $machine;
    }
    lvOut($payload, $code);
}

/**
 * Legacy parity: the log is stored as a RichEdit/TinyMCE HTML blob. The legacy
 * readers stripped a wrapping <p> and turned </p> into a line break before
 * nl2br'ing the result. We keep the same normalization but operate on the
 * DECODED text so the payload stays plain text.
 *
 * @param string|null $raw column value
 * @return string normalized plain text ('' when there is no log at all)
 */
function lvNormalizeLog($raw) {
    if ($raw === null) {
        return '';
    }
    $s = (string)$raw;
    // legacy: str_replace('<p>','',$info) then str_replace('</p>','<br>',$info)
    $s = preg_replace('#</?p\s*>#i', "\n", $s);
    // remaining line-oriented markup -> newlines instead of markup
    $s = preg_replace('#<br\s*/?>#i', "\n", $s);
    // any other residual tag (fonts, divs, spans from the legacy editor)
    $s = strip_tags($s);
    // html entities were rendered by the browser in the legacy fragment
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = str_replace(array("\r\n", "\r"), "\n", $s);
    $s = preg_replace('/[ \t]+\n/', "\n", $s);
    $s = preg_replace('/\n{3,}/', "\n\n", $s);
    return trim($s);
}

// ---------------------------------------------------------------- auth ----
bffEnforceSession($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || $userId <= 0) {
    lvError(401, 'Not authenticated');
}

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    lvError(401, 'User not found');
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET') {
    lvError(405, 'Method not allowed');
}

$action = isset($_REQUEST['action']) ? trim((string)$_REQUEST['action']) : '';
if ($action === '') {
    lvError(400, 'Missing action');
}
if ($action !== 'log') {
    lvError(400, 'Unknown action: ' . $action);
}

// ------------------------------------------------------------- params ----
$type = isset($_REQUEST['type']) ? trim((string)$_REQUEST['type']) : '';
$typeMap = array(
    'requirement_spec_version' => 11, // node_types.requirement_spec_revision
    'requirement_version'      => 8,  // node_types.requirement_version
    'requirement'              => 10, // node_types.requirement_revision
);
if ($type === '') {
    lvError(400, 'Missing type');
}
if (!isset($typeMap[$type])) {
    lvError(400, 'Unknown type: ' . $type);
}

$id = isset($_REQUEST['id']) ? trim((string)$_REQUEST['id']) : '';
if ($id === '' || !ctype_digit((string)$id) || intval($id) <= 0) {
    lvError(400, 'Invalid id');
}
$id = intval($id);

$reqProjectId = isset($_REQUEST['tproject_id']) ? intval($_REQUEST['tproject_id']) : 0;
if ($reqProjectId < 0) {
    lvError(400, 'Invalid tproject_id');
}

// ------------------------------------------------------------- resolve ----
try {
$context = array();
$logRaw = null;

if ($type === 'requirement_spec_version') {
    // NB: neither req_specs nor req_specs_revisions is a reliable title source
    // in this schema (a revision row can carry a stale/empty name), so the spec
    // title is read from its nodes_hierarchy node (node_type_id 6) - the same
    // place the modern reqSpecView BFF reads it from.
    $row = $db->get_recordset(
        "SELECT RSREV.id, RSREV.parent_id, RSREV.revision, RSREV.doc_id, " .
        "       RSREV.name AS rev_name, RSREV.log_message, " .
        "       RSPEC.testproject_id, RSPEC.doc_id AS spec_doc_id, " .
        "       RSH.name AS spec_name " .
        " FROM req_specs_revisions RSREV " .
        " JOIN req_specs RSPEC ON RSPEC.id = RSREV.parent_id " .
        " LEFT JOIN nodes_hierarchy RSH ON RSH.id = RSPEC.id AND RSH.node_type_id = 6 " .
        " WHERE RSREV.id = " . $id);
    if (empty($row)) {
        lvError(404, 'Requirement spec version not found');
    }
    $r = $row[0];
    $context = array(
        'type'          => $type,
        'item_id'       => intval($r['id']),
        'testproject_id' => intval($r['testproject_id']),
        // Prefer the SPEC title (from its tree node); fall back to the title
        // stored on the revision row itself.
        'object_label'  => (string)((string)$r['spec_name'] !== ''
                                   ? $r['spec_name'] : $r['rev_name']),
        'object_id'     => (string)$r['doc_id'],
        'version_label' => 'rev#' . intval($r['revision']),
        'revision'      => intval($r['revision']),
        'parent_id'     => intval($r['parent_id']),
        'parent_doc_id' => (string)$r['spec_doc_id'],
    );
    $logRaw = $r['log_message'];
} else {
    // requirement_version and requirement both live in nodes_hierarchy
    $expectedNodeType = $typeMap[$type];
    $nh = $db->get_recordset(
        "SELECT id, parent_id, node_type_id FROM nodes_hierarchy WHERE id = " . $id);
    if (empty($nh)) {
        lvError(404, 'Node not found');
    }
    if (intval($nh[0]['node_type_id']) !== $expectedNodeType) {
        lvError(404, 'Node ' . $id . ' is not a ' . $type);
    }

    if ($type === 'requirement_version') {
        $row = $db->get_recordset(
            "SELECT RV.id, RV.version, RV.revision, RV.log_message " .
            " FROM req_versions RV WHERE RV.id = " . $id);
        if (empty($row)) {
            lvError(404, 'Requirement version not found');
        }
        // the version's parent node is the requirement itself
        $reqNhId = intval($nh[0]['parent_id']);
        // NB: the requirements table carries no title column - a requirement's
        // title is the name of its nodes_hierarchy node (node_type_id 7).
        $reqRow = $db->get_recordset(
            "SELECT R.id, R.req_doc_id, RSPEC.testproject_id, RSPEC.doc_id AS spec_doc_id, " .
            "       RNH.name AS req_name " .
            " FROM requirements R " .
            " JOIN req_specs RSPEC ON RSPEC.id = R.srs_id " .
            " LEFT JOIN nodes_hierarchy RNH ON RNH.id = R.id AND RNH.node_type_id = 7 " .
            " WHERE R.id = " . $reqNhId);
        if (empty($reqRow)) {
            lvError(404, 'Owning requirement not found');
        }
        $rq = $reqRow[0];
        $versionLabel = 'v' . intval($row[0]['version']) . ' / rev#' . intval($row[0]['revision']);
        $logRaw = $row[0]['log_message'];
        $context = array(
            'type'          => $type,
            'item_id'       => intval($row[0]['id']),
            'testproject_id' => intval($rq['testproject_id']),
            'object_label'  => (string)$rq['req_name'],
            'object_id'     => (string)$rq['req_doc_id'],
            'version_label' => $versionLabel,
            'revision'      => intval($row[0]['revision']),
            'parent_id'     => $reqNhId,
            'parent_doc_id' => (string)$rq['spec_doc_id'],
        );
    } else {
        $row = $db->get_recordset(
            "SELECT RR.id, RR.revision, RR.req_doc_id, RR.name, RR.log_message " .
            " FROM req_revisions RR WHERE RR.id = " . $id);
        if (empty($row)) {
            lvError(404, 'Requirement revision not found');
        }
        $reqNhId = intval($nh[0]['parent_id']);
        // NB: the requirements table carries no title column - a requirement's
        // title is the name of its nodes_hierarchy node (node_type_id 7).
        $reqRow = $db->get_recordset(
            "SELECT R.id, R.req_doc_id, RSPEC.testproject_id, RSPEC.doc_id AS spec_doc_id, " .
            "       RNH.name AS req_name " .
            " FROM requirements R " .
            " JOIN req_specs RSPEC ON RSPEC.id = R.srs_id " .
            " LEFT JOIN nodes_hierarchy RNH ON RNH.id = R.id AND RNH.node_type_id = 7 " .
            " WHERE R.id = " . $reqNhId);
        if (empty($reqRow)) {
            lvError(404, 'Owning requirement not found');
        }
        $rq = $reqRow[0];
        $logRaw = $row[0]['log_message'];
        $context = array(
            'type'          => $type,
            'item_id'       => intval($row[0]['id']),
            'testproject_id' => intval($rq['testproject_id']),
            'object_label'  => (string)$rq['req_name'],
            'object_id'     => (string)$rq['req_doc_id'],
            'version_label' => 'rev#' . intval($row[0]['revision']),
            'revision'      => intval($row[0]['revision']),
            'parent_id'     => $reqNhId,
            'parent_doc_id' => (string)$rq['spec_doc_id'],
        );
    }
}

// -------------------------------------------------------------- rights ----
$owningProject = intval($context['testproject_id']);
// testprojects has no name column: the display name lives on the
// nodes_hierarchy testproject node (testproject::getName() reads it from there).
$context['parent_name'] = (string)testproject::getName($db, $owningProject);
// When the caller states a project, refuse ids belonging to another one instead
// of silently answering - the legacy readers happily returned a log from any
// project to any authenticated user.
if ($reqProjectId > 0 && $reqProjectId !== $owningProject) {
    lvError(404, 'Node not found in the requested test project');
}
if (!$user->hasRight($db, 'mgt_view_req', $owningProject)) {
    lvError(403, 'No permission');
}

// -------------------------------------------------------------- answer ----
$text = lvNormalizeLog($logRaw);
$rawLength = strlen((string)$logRaw);

lvOut(array(
    'status'  => 'ok',
    'context' => $context,
    'log'     => array(
        'text'       => $text,
        'is_empty'   => ($text === ''),
        'raw_length' => $rawLength,
        'legacy_note_empty' => 'empty_log_message',
    ),
));
} catch (Throwable $e) {
    // database::exec_query() throws on a failed query; exec_query() already
    // logged the DB error, so just close the JSON contract cleanly instead of
    // letting the raw HTML error page escape.
    tLog('BFF logviewer: ' . $e->getMessage() . ' - answering 500 db_error.', 'ERROR');
    http_response_code(500);
    lvOut(array(
        'status' => 'error',
        'code' => 'db_error',
        'message' => 'Database error while reading the log message',
    ), 500);
}
