<?php
/**
 * api/tcsummary — Test Case Summary viewer BFF (Refs #1767)
 *
 * Replaces the legacy ExtJS tooltip backend lib/ajax/gettestcasesummary.php
 * (46 lines), the server side of the tTip(tcID,vID) function in
 * gui/templates/dashio/plan/planAddTC_m1.tpl:58 and
 * gui/templates/dashio/plan/planAddTCJS.inc.tpl:37 — the "Add Test Cases to
 * Test Plan" workframe showed the summary of the hovered test case in an
 * Ext.ToolTip that autoLoaded
 *
 *     lib/ajax/gettestcasesummary.php?tcase_id=<id>&tcversion_id=<id>
 *
 * Legacy behaviour that is ported here verbatim:
 *   - tcase_id only          -> the LAST version of the case
 *     (testcase::get_last_version_info())
 *   - tcase_id + tcversion_id -> that exact version, and only if it really
 *     belongs to the case (testcase::get_by_id())
 *   - a blank summary answers lang_get('empty_tc_summary'), an existing one is
 *     rendered under a bold "Summary" label
 *
 * Hardening vs legacy (the legacy endpoint is a live file on disk and is
 * reachable by ANY authenticated session, so these are real holes, not
 * theoretical ones - same class as #1696 / #1679):
 *   1. NO rights check at all in 1.9.20: testlinkInitPage($db) is
 *      session-only and tcase_id came straight from $_REQUEST, so any
 *      authenticated user could read the summary of a test case belonging to
 *      ANY test project. Here mgt_view_tc is enforced on the OWNING project.
 *   2. the summary (a RichEdit HTML blob) was echoed RAW into the caller DOM,
 *      which made the tooltip an XSS sink. The BFF returns the summary as a
 *      plain-text field and the screen escapes it client-side; the historical
 *      <p>..</p> -> <br> shaping of the legacy tooltip is reproduced on the
 *      client so no markup crosses the wire.
 *   3. testcase::get_last_version_info() / get_by_id() can both return NULL and
 *      legacy dereferenced the result unconditionally ($tcase['summary'] on a
 *      null) - a PHP 8 warning plus an empty tooltip. Every branch is now
 *      null-guarded and answers a proper 404.
 *   4. intval('1abc') is truthy in the legacy `if ($info == "")` chain and the
 *      caller silently fell back to the last version; ids are validated here.
 *
 * Endpoint (JSON out):
 *   GET ?action=summary&tcase_id=<id>[&tcversion_id=<v>][&tproject_id=<pid>]
 *
 * Status contract:
 *   200 ok
 *   400 invalid_tcase_id / invalid_tcversion_id / unknown_action / bad_method
 *   401 not_authenticated / session_expired
 *   403 no_right (mgt_view_tc on the owning project) / csrf (write verbs)
 *   404 tcase_not_found / version_not_in_case / project_not_found
 *   405 method_not_allowed (the endpoint is read-only)
 *   500 server_error (guarded)
 *
 * The optional tproject_id is only a CLIENT-SIDE ASSERTION: when present it
 * must match the project that really owns the test case, otherwise the request
 * is refused. That is what lets a deep link survive a project switch without
 * turning into a cross-project existence oracle (the #1697 class).
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate');

$db = new database(DB_TYPE);
doDBConnect($db);

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();
// Legacy parity: testlinkInitPage() ran checkSessionValid() on every page load,
// so an idle tab was thrown back to login.php. This endpoint only READS, but a
// test case summary is project data, so the idle-tab window is enforced here too
// (it needs $db, hence after doDBConnect).
bffEnforceSession($db);

$userId = isset($_SESSION['userID']) ? intval($_SESSION['userID']) : 0;
if ($userId <= 0) {
    out(array('status' => 'error', 'code' => 'not_authenticated',
              'message' => 'Not authenticated'), 401);
}

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    out(array('status' => 'error', 'code' => 'not_authenticated',
              'message' => 'User not found'), 401);
}

function out($data, $code = 200)
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

/** Tables used by this endpoint (2.0.1 has no global table-prefix accessor). */
function tcsumTables()
{
    static $t = null;
    if ($t === null) {
        $t = tlObjectWithDB::getDBTables(
            array('nodes_hierarchy', 'tcversions', 'testprojects'));
    }
    return $t;
}

/**
 * Node type ids resolved from node_types by DESCRIPTION rather than hardcoded
 * (same defensive approach as api/tcreorder / api/tcstepsreorder).
 */
function tcsumNodeTypes(&$db)
{
    static $n = null;
    if ($n === null) {
        $T = tlObjectWithDB::getDBTables(array('node_types'));
        $rows = $db->get_recordset("SELECT id, description FROM {$T['node_types']}");
        $n = array();
        if (!is_null($rows)) {
            foreach ($rows as $r) {
                $n[strtolower((string)$r['description'])] = intval($r['id']);
            }
        }
        $n += array('testproject' => 1, 'testsuite' => 2,
                    'testcase' => 3, 'testcase_version' => 4);
    }
    return $n;
}

/**
 * Read a strictly positive integer query parameter.
 *
 * Returns null when the parameter is absent, and false when it is present but
 * not a plain positive integer - legacy silently accepted "1abc" (PHP intval()
 * truncation) and "0" alike, which made a wrong test case answer a plausible
 * looking tooltip instead of an error.
 */
function tcsumId($key)
{
    if (!isset($_GET[$key])) {
        return null;
    }
    $v = $_GET[$key];
    if (is_array($v) || is_object($v)) {
        return false;
    }
    $v = trim((string)$v);
    if ($v === '' || !preg_match('/^[0-9]+$/', $v)) {
        return false;
    }
    $n = intval($v);
    return $n > 0 ? $n : false;
}

/**
 * PROVE the test project that owns a node by walking parent_id upwards to the
 * node_type 1 root. The project is never taken from the request, so a caller
 * cannot present project A's rights while reading project B's test case.
 */
function tcsumOwningProject(&$db, $node)
{
    $T = tcsumTables();
    $n = tcsumNodeTypes($db);
    if (intval($node['node_type_id']) == $n['testproject']) {
        return intval($node['id']);
    }

    $parentId = intval($node['parent_id']);
    $guard = 0;
    while ($parentId > 0 && $guard < 64) {
        $guard++;
        $row = $db->get_recordset(
            "SELECT id, parent_id, node_type_id FROM {$T['nodes_hierarchy']}" .
            " WHERE id = {$parentId}");
        if (is_null($row) || count($row) == 0) {
            return 0;
        }
        if (intval($row[0]['node_type_id']) == $n['testproject']) {
            return intval($row[0]['id']);
        }
        $parentId = intval($row[0]['parent_id']);
    }
    return 0;
}

/** Human "Project / Suite / Sub-suite" path of the test case's suite chain. */
function tcsumSuitePath(&$db, $tcase)
{
    $T = tcsumTables();
    $n = tcsumNodeTypes($db);
    $parts = array();
    $current = $tcase;
    $guard = 0;

    while ($guard < 32) {
        $guard++;
        $parentId = intval($current['parent_id']);
        if ($parentId <= 0) {
            break;
        }
        $row = $db->get_recordset(
            "SELECT id, name, parent_id, node_type_id" .
            " FROM {$T['nodes_hierarchy']} WHERE id = {$parentId}");
        if (is_null($row) || count($row) == 0) {
            break;
        }
        // The test project root is already shown in the context card, so the
        // suite path stops below it.
        if (intval($row[0]['node_type_id']) == $n['testproject']) {
            break;
        }
        array_unshift($parts, (string)$row[0]['name']);
        $current = $row[0];
    }
    return implode(' / ', $parts);
}

function tcsumProjectName(&$db, $tprojectId)
{
    $T = tcsumTables();
    $n = tcsumNodeTypes($db);
    // 2.0.1 dropped the denormalized `name` column from `testprojects`; the
    // project's name lives on its nodes_hierarchy row (node_type testproject).
    $row = $db->get_recordset(
        "SELECT NH.name AS name, TP.prefix FROM {$T['testprojects']} TP" .
        " JOIN {$T['nodes_hierarchy']} NH ON NH.id = TP.id" .
        " WHERE TP.id = " . intval($tprojectId) .
        " AND NH.node_type_id = {$n['testproject']}");
    if (is_null($row) || count($row) == 0) {
        return array('', '');
    }
    return array((string)$row[0]['name'], (string)$row[0]['prefix']);
}

/**
 * Locate the addressed version of a test case.
 *
 * @return array the tcversions row (id, name, summary, version, ...)
 */
/**
 * Every "this does not exist for you" case answers the SAME opaque 404, so a
 * caller that is not entitled to the resource cannot distinguish a missing
 * test case from one it is simply not allowed to see (the #1697 oracle class).
 * The distinct codes (project_not_found, project_mismatch, version_not_in_case)
 * are only ever reached AFTER the rights check has already passed.
 */
function tcsumOpaqueNotFound()
{
    out(array('status' => 'error', 'code' => 'tcase_not_found',
              'message' => 'Test case not found'), 404);
}

function tcsumVersion(&$db, $tcaseId, $tcversionId)
{
    $T = tcsumTables();
    $n = tcsumNodeTypes($db);

    if ($tcversionId !== null && $tcversionId !== false) {
        // Explicit version: legacy testcase::get_by_id($tcase_id,$tcver_id),
        // which only answered when the version really belongs to the case.
        $row = $db->get_recordset(
            "SELECT TV.id, TV.summary, TV.version, TV.tc_external_id," .
            "       NH.parent_id, NH.node_type_id" .
            " FROM {$T['tcversions']} TV" .
            " JOIN {$T['nodes_hierarchy']} NH ON NH.id = TV.id" .
            " WHERE TV.id = " . intval($tcversionId) .
            " AND NH.node_type_id = {$n['testcase_version']}" .
            " AND NH.parent_id = " . intval($tcaseId));
        if (is_null($row) || count($row) == 0) {
            out(array('status' => 'error', 'code' => 'version_not_in_case',
                      'message' => 'Version does not belong to this test case'),
                404);
        }
        return $row[0];
    }

    // No version requested: the LAST version (legacy get_last_version_info()).
    $row = $db->get_recordset(
        "SELECT TV.id, TV.summary, TV.version, TV.tc_external_id," .
        "       NH.parent_id, NH.node_type_id" .
        " FROM {$T['tcversions']} TV" .
        " JOIN {$T['nodes_hierarchy']} NH ON NH.id = TV.id" .
        " WHERE NH.node_type_id = {$n['testcase_version']}" .
        " AND NH.parent_id = " . intval($tcaseId) .
        " ORDER BY TV.version DESC, TV.id DESC LIMIT 1");
    if (is_null($row) || count($row) == 0) {
        out(array('status' => 'error', 'code' => 'tcase_not_found',
                  'message' => 'Test case has no version'), 404);
    }
    return $row[0];
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    out(array('status' => 'error', 'code' => 'method_not_allowed',
              'message' => 'This endpoint is read-only; use GET'), 405);
}

$action = isset($_GET['action']) ? strtolower(trim((string)$_GET['action'])) : '';
if ($action === '') {
    $action = 'summary';
}
if ($action !== 'summary') {
    out(array('status' => 'error', 'code' => 'unknown_action',
              'message' => 'Unknown action'), 400);
}

$tcaseId = tcsumId('tcase_id');
if ($tcaseId === false) {
    out(array('status' => 'error', 'code' => 'invalid_tcase_id',
              'message' => 'tcase_id must be a positive integer'), 400);
}
if ($tcaseId === null) {
    out(array('status' => 'error', 'code' => 'invalid_tcase_id',
              'message' => 'tcase_id is required'), 400);
}

$tcversionId = tcsumId('tcversion_id');
if ($tcversionId === false) {
    out(array('status' => 'error', 'code' => 'invalid_tcversion_id',
              'message' => 'tcversion_id must be a positive integer'), 400);
}

$assertedTprojectId = tcsumId('tproject_id');
if ($assertedTprojectId === false) {
    out(array('status' => 'error', 'code' => 'invalid_tproject_id',
              'message' => 'tproject_id must be a positive integer'), 400);
}

try {
    $T = tcsumTables();
    $n = tcsumNodeTypes($db);

    // 1. The test case node itself.
    $tcaseRow = $db->get_recordset(
        "SELECT id, name, parent_id, node_type_id" .
        " FROM {$T['nodes_hierarchy']}" .
        " WHERE id = {$tcaseId} AND node_type_id = {$n['testcase']}");
    if (is_null($tcaseRow) || count($tcaseRow) == 0) {
        tcsumOpaqueNotFound();
    }
    $tcase = $tcaseRow[0];

    // 2. The OWNING test project, proved by the parent chain - never trusted
    //    from the request.
    $tprojectId = tcsumOwningProject($db, $tcase);
    if ($tprojectId <= 0) {
        tcsumOpaqueNotFound();
    }

    // 3. Rights on the OWNING project (legacy had none at all). This MUST come
    //    before the version lookup: resolving the version first would let any
    //    authenticated user - including one with no right anywhere - tell an
    //    existing test case node from a missing one (404 version_not_in_case
    //    vs 404 tcase_not_found) and enumerate the whole installation.
    if (!$user->hasRight($db, 'mgt_view_tc', $tprojectId)) {
        out(array('status' => 'error', 'code' => 'no_right',
                  'message' => 'You do not have rights on this test project'),
            403);
    }

    // 4. Optional client-side project assertion: a deep link that still points
    //    at the old project is refused instead of silently answering with the
    //    new project's data.
    if ($assertedTprojectId !== null && $assertedTprojectId !== $tprojectId) {
        out(array('status' => 'error', 'code' => 'project_mismatch',
                  'message' => 'The test case does not belong to that test project'),
            404);
    }

    // 5. The version actually addressed (last version when none was given).
    //    Only now that the caller is entitled to this test case, so the answer
    //    cannot be used as a probe.
    $version = tcsumVersion($db, $tcaseId, $tcversionId);

    list($tprojectName, $prefix) = tcsumProjectName($db, $tprojectId);
    if ($tprojectName === '') {
        out(array('status' => 'error', 'code' => 'project_not_found',
                  'message' => 'Owning test project not found'), 404);
    }

    $summary = isset($version['summary']) ? (string)$version['summary'] : '';
    // Legacy "empty_tc_summary" parity is applied CLIENT side (the screen knows
    // the locale); the BFF only reports whether there is anything to show.
    $isEmpty = (trim(strip_tags($summary)) === '');

    out(array(
        'status' => 'ok',
        'summary' => $summary,
        'summary_empty' => $isEmpty,
        'testcase' => array(
            'id' => intval($tcase['id']),
            'name' => (string)$tcase['name'],
        ),
        'version' => array(
            'id' => intval($version['id']),
            'version' => intval($version['version']),
            // 2.0.1 leaves the nodes_hierarchy name of a version node EMPTY
            // (the display name is the test case name), so no name is shipped;
            // the external id is rendered as PREFIX-N by the screen.
            'tc_external_id' => isset($version['tc_external_id'])
                               ? intval($version['tc_external_id']) : 0,
        ),
        'testproject' => array(
            'id' => $tprojectId,
            'name' => $tprojectName,
            'prefix' => $prefix,
        ),
        'suite_path' => tcsumSuitePath($db, $tcase),
        'is_last_version' => ($tcversionId === null),
    ));
} catch (Throwable $e) {
    tLog('api/tcsummary: ' . $e->getMessage(), 'ERROR');
    out(array('status' => 'error', 'code' => 'server_error',
              'message' => 'Unexpected server error'), 500);
}
