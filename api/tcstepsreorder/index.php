<?php
/**
 * Test Case STEP Reorder - BFF API
 * URL: /api/tcstepsreorder/index.php
 * Plain PHP, no framework, no compilation.
 *
 * Modernizes the one capability of the Test Specification area that had NO
 * addressable URL at all in 1.9.20: re-ordering the STEPS of a test case
 * version. In 1.9.20 there was no screen for it - the steps of the version
 * being edited were re-ordered with a TableDnD drag-and-drop
 * (https://github.com/isocra/TableDnD, gui/templates/tl-classic/testcases/
 * steps_horizontal.inc.tpl) that POSTed
 *
 *      stepSeq=<step_node_id>&<step_node_id>&...
 *
 * to lib/ajax/stepReorder.php. That endpoint was, and still is, a security
 * hole (it is a live file on disk, and its only caller - the tl-classic
 * template - is dead):
 *
 *   1. "// No authorization checks" - the endpoint performs NONE. Any
 *      authenticated user - including one with no test-case management right
 *      whatsoever - could renumber the steps of any test case version of any
 *      test project.
 *   2. it read $_REQUEST, so a plain GET mutated state: CSRF-able.
 *   3. the submitted step ids were never proven to belong to anything: only
 *      the FIRST id was read (to fetch parent_id) and that result was then
 *      never even used, so ids of unrelated versions / projects / node types
 *      (suites, test cases, requirements, ...) could be renumbered at will.
 *   4. it wrote a hardcoded debug line to /var/testlink/logs/stepReorder.log.
 *
 * api/tcreorder (Refs #1660) modernized re-ordering of the SPECIFICATION TREE
 * (suites / test cases). This endpoint covers the other half: the steps inside
 * a single version. Both write step_number through the very same legacy
 * primitive (testcase::set_step_number()), so 2.0.1 rows stay byte-compatible
 * with 1.9.20 and with every modern consumer that does
 * "ORDER BY TCSTEPS.step_number".
 *
 * Hardening vs legacy, all enforced here:
 *   - mgt_view_tc on the OWNING test project for the read, mgt_modify_tc for
 *     every write (the owning project is proved by walking nodes_hierarchy
 *     parent_id upwards to the node_type 1 root, never taken from the request);
 *   - writes are POST-only and go through bffSameOriginGuard() (CSRF);
 *   - bffEnforceSession() (idle-tab protection, legacy checkSessionValid);
 *   - every submitted step id must be a node_type 9 (testcase_step) child of
 *     the addressed version, and the submitted list must be a permutation of
 *     exactly that child set - no partial, foreign or duplicated lists.
 *
 * Endpoints (JSON out):
 *   GET  ?action=versions&[tproject_id=<pid>]  - version picker for the hub
 *   GET  ?action=init&tcversion_id=<v>[&tproject_id=<pid>]
 *   POST ?action=move     {tcversion_id, step_id, position: top|bottom|up|down}
 *   POST ?action=reorder  {tcversion_id, order: "1,2,3" | [1,2,3]}
 *   POST ?action=normalize{tcversion_id}
 *
 * Status contract: 401 anon / 401 session_expired / 403 no-right (+ CSRF) /
 * 400 bad param / 404 unknown-or-foreign version / step / 405 non-GET on write.
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$db = new database(DB_TYPE);
doDBConnect($db);

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();
// Legacy parity: testlinkInitPage() ran checkSessionValid() on every page, so an
// idle tab was thrown back to the login screen. This endpoint WRITES, so without
// this a tab left open past sessionInactivityTimeout would keep renumbering
// steps (the issue #1614 class). It must run after $db exists -
// checkSessionValid() takes the handle by reference.
bffEnforceSession($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || $userId <= 0) {
    http_response_code(401);
    echo json_encode(array('status' => 'error', 'message' => 'Not authenticated'));
    exit;
}

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    http_response_code(401);
    echo json_encode(array('status' => 'error', 'message' => 'User not found'));
    exit;
}

function out($data, $code = null)
{
    if (!is_null($code)) {
        http_response_code($code);
    }
    echo json_encode($data);
    exit;
}

function bffBody()
{
    static $body = null;
    if ($body === null) {
        $j = json_decode(file_get_contents('php://input'), true);
        $body = is_array($j) ? $j : array();
    }
    return $body;
}

function getParam($key, $default = null)
{
    $b = bffBody();
    if (isset($b[$key])) {
        return $b[$key];
    }
    if (isset($_POST[$key])) {
        return $_POST[$key];
    }
    if (isset($_GET[$key])) {
        return $_GET[$key];
    }
    return $default;
}

function getInt($key, $default = 0)
{
    $v = getParam($key, $default);
    if (is_array($v) || is_object($v)) {
        return $default;
    }
    if (is_string($v)) {
        $v = trim($v);
    }
    if ($v === '' || $v === null) {
        return $default;
    }
    if (!is_numeric($v)) {
        return $default;
    }
    return intval($v);
}

function getStr($key, $default = '')
{
    $v = getParam($key, $default);
    if (is_array($v) || is_object($v)) {
        return $default;
    }
    return trim((string)$v);
}

/**
 * Node type ids, resolved from node_types by DESCRIPTION instead of being
 * hardcoded (same defensive approach as api/tcreorder / api/suiteview).
 */
function tsroNodeTypes(&$db)
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
        // Numeric fallbacks, only used when node_types cannot be read at all
        // (this is the 1.9.20 ordering).
        $n += array('testproject' => 1, 'testcase' => 3,
                    'testcase_version' => 4, 'testcase_step' => 9);
    }
    return $n;
}

function tsroTables()
{
    static $t = null;
    if ($t === null) {
        // 2.0.1 has no table-prefix global, so interpolating one raised
        // "Undefined global variable" on every query - an E_WARNING storm in
        // the events table. tlObjectWithDB::getDBTables() is the only accessor
        // that honours a configured DB prefix.
        $t = tlObjectWithDB::getDBTables(
            array('nodes_hierarchy', 'tcsteps', 'tcversions', 'testprojects'));
    }
    return $t;
}

/**
 * Resolve a test case version node: its row, its test case (parent) and the
 * test project that owns the whole chain.
 *
 * The owning project is PROVED by walking parent_id upwards to the node_type 1
 * root - it is never taken from the request - so a caller cannot present
 * project A's rights while mutating project B's version.
 */
function tsroVersion(&$db, $tcverId)
{
    $T = tsroTables();
    $n = tsroNodeTypes($db);
    $tcverId = intval($tcverId);

    $row = $db->get_recordset(
        "SELECT NH.id, NH.name, NH.parent_id, NH.node_type_id" .
        " FROM {$T['nodes_hierarchy']} NH" .
        " WHERE NH.id = {$tcverId} AND NH.node_type_id = {$n['testcase_version']}");
    if (is_null($row) || count($row) == 0) {
        out(array('status' => 'error', 'code' => 'not_found',
                  'message' => 'Test case version not found'), 404);
    }
    $version = $row[0];

    // parent = the test case node
    $tcaseRow = $db->get_recordset(
        "SELECT id, name, parent_id, node_type_id FROM {$T['nodes_hierarchy']}" .
        " WHERE id = " . intval($version['parent_id']) .
        " AND node_type_id = {$n['testcase']}");
    if (is_null($tcaseRow) || count($tcaseRow) == 0) {
        out(array('status' => 'error', 'code' => 'not_found',
                  'message' => 'Test case of this version not found'), 404);
    }
    $tcase = $tcaseRow[0];

    $tprojectId = tsroOwningProject($db, $tcase);
    if ($tprojectId <= 0) {
        out(array('status' => 'error', 'code' => 'not_found',
                  'message' => 'Test case has no owning test project'), 404);
    }

    return array($version, $tcase, $tprojectId);
}

/** Walk up nodes_hierarchy until the test project root (node_type 1) is reached. */
function tsroOwningProject(&$db, $node)
{
    $T = tsroTables();
    $n = tsroNodeTypes($db);
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

/** Full "Project / Suite / Sub-suite" path of the test case, for the context card. */
function tsroSuitePath(&$db, $tcase)
{
    $T = tsroTables();
    $n = tsroNodeTypes($db);
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
            "SELECT id, name, parent_id, node_type_id FROM {$T['nodes_hierarchy']}" .
            " WHERE id = {$parentId}");
        if (is_null($row) || count($row) == 0) {
            break;
        }
        $node = $row[0];
        if (intval($node['node_type_id']) == $n['testproject']) {
            break;
        }
        if (intval($node['node_type_id']) == $n['testsuite']) {
            array_unshift($parts, (string)$node['name']);
        }
        $current = $node;
    }

    return implode(' / ', $parts);
}

/**
 * 2.0.1 stores the test project NAME in the nodes_hierarchy row, not in
 * testprojects (reading ->name off the testproject object would emit an
 * undefined-property warning and render an empty header).
 */
function tsroProjectName(&$db, $tprojectId)
{
    $T = tsroTables();
    $n = tsroNodeTypes($db);
    $row = $db->get_recordset(
        "SELECT name FROM {$T['nodes_hierarchy']} WHERE id = " .
        intval($tprojectId) . " AND node_type_id = {$n['testproject']}");
    if (!is_null($row) && count($row) > 0) {
        return (string)$row[0]['name'];
    }
    return '';
}

/** The external-id prefix is the only column testprojects still owns. */
function tsroProjectPrefix(&$db, $tprojectId)
{
    $T = tsroTables();
    $row = $db->get_recordset(
        "SELECT prefix FROM {$T['testprojects']} WHERE id = " . intval($tprojectId));
    if (!is_null($row) && count($row) > 0) {
        return (string)$row[0]['prefix'];
    }
    return '';
}

/**
 * The ordered steps of a version: the node_type 9 children of the version node
 * joined to their tcsteps row (in 2.0.1 the step node id IS tcsteps.id).
 */
function tsroSteps(&$db, $tcverId)
{
    $T = tsroTables();
    $n = tsroNodeTypes($db);
    $rows = $db->get_recordset(
        "SELECT TS.id, TS.step_number, TS.actions, TS.expected_results," .
        "       TS.execution_type, TS.upload_on_execution_enabled," .
        "       TS.upload_on_execution_mandatory, NH.name AS node_name" .
        " FROM {$T['nodes_hierarchy']} NH" .
        " JOIN {$T['tcsteps']} TS ON NH.id = TS.id" .
        " WHERE NH.parent_id = " . intval($tcverId) .
        " AND NH.node_type_id = {$n['testcase_step']}" .
        " ORDER BY TS.step_number, TS.id");
    return is_null($rows) ? array() : $rows;
}

/** execution_type int -> stable machine code (mirrors api/testcasesedit). */
function tsroExecTypeCode($type)
{
    switch (intval($type)) {
        case 1:
            return 'manual';
        case 2:
            return 'automated';
        case 3:
            return 'automated_proceed_on_block';
        case 4:
            return 'automated_audited';
        default:
            return 'manual';
    }
}

/**
 * Step rows as the table payload.
 *
 * actions / expected_results are RichEdit HTML blobs. The table only ever needs
 * plain text, so the markup is stripped HERE, server-side: shipping the raw
 * blob and stripping it in the browser would put an attacker-controlled
 * HTML string into the page (the same class as the stored-XSS the legacy
 * tooltip readers had, Refs #1652).
 */
function tsroStepsPayload($rows)
{
    $out = array();
    $i = 0;
    foreach ($rows as $r) {
        $i++;
        $out[] = array(
            'step_id' => intval($r['id']),
            'step_number' => intval($r['step_number']),
            'position' => $i,
            'actions' => tsroPlainText($r['actions']),
            'expected_results' => tsroPlainText($r['expected_results']),
            'execution_type' => intval($r['execution_type']),
            'execution_type_code' => tsroExecTypeCode($r['execution_type']),
            'upload_enabled' => (intval($r['upload_on_execution_enabled']) == 1),
            'upload_mandatory' => (intval($r['upload_on_execution_mandatory']) == 1),
        );
    }
    return $out;
}

function tsroPlainText($html)
{
    $text = strip_tags(str_replace(array('<br>', '<br/>', '<br />'), "\n", (string)$html));
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    $text = preg_replace("/[ \t]+/", ' ', $text);
    $text = preg_replace("/\n{2,}/", "\n", (string)$text);
    return trim($text);
}

function tsroIdsOf($rows)
{
    $ids = array();
    foreach ($rows as $r) {
        $ids[] = intval($r['id']);
    }
    return $ids;
}

/** The stored step_number sequence, in display order. */
function tsroNumbersOf($rows)
{
    $nums = array();
    foreach ($rows as $r) {
        $nums[] = intval($r['step_number']);
    }
    return $nums;
}

/**
 * Prove every submitted id is a step of THIS version.
 *
 * A submitted id that is not a node_type 9 child of the addressed version is
 * refused with 404 - the check the legacy stepReorder.php never performed. If
 * the list is foreign-free but merely incomplete, the caller is told 400: the
 * stored order is a permutation, so a partial list would silently renumber the
 * omitted steps into a gap.
 */
function tsroRequireOwnSteps(&$db, $ids, $tcverId, $rows)
{
    $n = tsroNodeTypes($db);
    $T = tsroTables();

    foreach ($ids as $id) {
        $row = $db->get_recordset(
            "SELECT id FROM {$T['nodes_hierarchy']}" .
            " WHERE id = " . intval($id) .
            " AND node_type_id = {$n['testcase_step']}" .
            " AND parent_id = " . intval($tcverId));
        if (is_null($row) || count($row) == 0) {
            out(array('status' => 'error', 'code' => 'not_found',
                      'message' => 'Step does not belong to this test case version'), 404);
        }
    }

    $known = tsroIdsOf($rows);
    sort($known);
    $given = array_values($ids);
    sort($given);
    return ($known === $given);
}

/**
 * Emit the result of a write, flagging a genuine no-op.
 *
 * "Changed" means the STORED sequence changed - either which step sits at which
 * position, or the step_number values themselves. Both have to be compared: the
 * ordered id sequence alone is identical for ?action=normalize, which only
 * rewrites the numbers (11,12,13,14 -> 1,2,3,4) and would otherwise be reported
 * as a no-op, leaving the user with a "nothing to do" toast for a real fix.
 * The id sequence alone is likewise not enough, because a write can restore the
 * very same order the user started from.
 */
function tsroWriteResult(&$db, $tcverId, $before, $extra = array())
{
    $after = tsroSteps($db, $tcverId);
    $afterIds = tsroIdsOf($after);
    $afterNumbers = tsroNumbersOf($after);

    $changed = ($before['ids'] !== $afterIds) || ($before['numbers'] !== $afterNumbers);

    $payload = array(
        'status' => $changed ? 'ok' : 'no_change',
        'count' => count($after),
        'steps' => tsroStepsPayload($after),
    );
    if (!$changed) {
        $payload['message'] = 'Order already up to date';
    }
    return array_merge($payload, $extra);
}

/**
 * Resolve + authorize the screen context.
 *
 * $right is what the caller needs: 'mgt_view_tc' for the read, 'mgt_modify_tc'
 * for a write. The test project named in the request is never trusted: it must
 * either be 0 or exactly the owning project, otherwise 403 - silently
 * retargeting would let an admin unknowingly reorder another project's steps.
 */
/**
 * Has this version ever been executed?
 *
 * testcase::set_step_number() is the very write the Test Case Editor performs,
 * and the editor refuses it on an executed version without
 * `testproject_edit_executed_testcases` (or the global canEditExecuted config) -
 * api/testcasesedit/index.php:580-588. Without this check the reorder screen
 * was a second, unguarded door to the same write: `execution_tcsteps` links a
 * result to a step by tcstep_id and not to a snapshot of its number, so
 * renumbering an executed version silently re-labels every historical result
 * ("step 1" becomes "step 3") in all past reports.
 */
function tsroHasExecutions(&$db, $tcverId)
{
    $t = tlObjectWithDB::getDBTables(array('executions'));
    $row = $db->fetchFirstRow(
        "SELECT id FROM {$t['executions']} WHERE tcversion_id = " . intval($tcverId) . " LIMIT 1");
    return (is_array($row) && isset($row['id']) && intval($row['id']) > 0);
}

/** mgt_modify_tc AND (not executed OR edit-executed right OR canEditExecuted). */
function tsroMayWrite(&$db, &$user, $tprojectId, $tcverId)
{
    if (!tsroHasExecutions($db, $tcverId)) {
        return true;
    }
    if ($user->hasRight($db, 'testproject_edit_executed_testcases', $tprojectId)) {
        return true;
    }
    $cfg = config_get('testcase_cfg');
    return intval($cfg->canEditExecuted ?? 0) > 0;
}

function tsroContext(&$db, &$user, $tcverId, $requestedProject, $right)
{
    list($version, $tcase, $tprojectId) = tsroVersion($db, $tcverId);

    if (intval($requestedProject) > 0 && intval($requestedProject) !== $tprojectId) {
        out(array('status' => 'error', 'code' => 'forbidden',
                  'message' => 'Test case version belongs to another test project'), 403);
    }

    if (!$user->hasRight($db, $right, $tprojectId)) {
        out(array('status' => 'error', 'code' => 'forbidden',
                  'message' => 'Insufficient rights on this test project'), 403);
    }

    // An executed version is protected by the Test Case Editor's own rule, and
    // this screen writes the very same step_number column through the very same
    // testcase::set_step_number() (see tsroMayWrite()).
    if ($right === 'mgt_modify_tc' && !tsroMayWrite($db, $user, $tprojectId, $tcverId)) {
        out(array('status' => 'error', 'code' => 'forbidden',
                  'message' => 'This version has executions: re-ordering requires special permission'), 403);
    }

    return array($version, $tcase, $tprojectId);
}

/**
 * Every test case version of the project that owns at least one step, for the
 * screen's version picker. The picker is needed because the screen is reachable
 * without a version id: the $actions->tcStepReorder route (the same context-only
 * pattern as the sibling tcReorder route) and a legacy stepReorder.php bookmark
 * both land here, and the per-version entry in tcView.html is rights-gated.
 *
 * The list is built by walking DOWN from the project root - the same proof
 * api/tcreorder uses - so a test case re-parented under a foreign project
 * (which the legacy unauthenticated drag-drop could actually do) can never leak
 * in. Versions with no step are excluded: there is nothing to re-order there.
 */
function tsroVersions(&$db, $requestedProject)
{
    $T = tsroTables();
    $n = tsroNodeTypes($db);

    $tprojectId = intval($requestedProject);
    if ($tprojectId <= 0) {
        $tprojectId = intval($_SESSION['testprojectID'] ?? 0);
    }
    if ($tprojectId <= 0) {
        out(array('status' => 'error', 'code' => 'no_context',
                  'message' => 'No test project in context'), 400);
    }

    $projRow = $db->get_recordset(
        "SELECT name, prefix FROM {$T['nodes_hierarchy']} NH" .
        " LEFT JOIN {$T['testprojects']} TP ON TP.id = NH.id" .
        " WHERE NH.id = " . intval($tprojectId) .
        " AND NH.node_type_id = {$n['testproject']}");
    if (is_null($projRow) || count($projRow) == 0) {
        out(array('status' => 'error', 'code' => 'not_found',
                  'message' => 'Test project not found'), 404);
    }

    $tprojectMgr = new testproject($db);
    $glue = config_get('testcase_cfg')->glue_character;
    $prefix = $tprojectMgr->getTestCasePrefix($tprojectId);
    if ($prefix === '' || $prefix === null) {
        $prefix = (string)$projRow[0]['prefix'];
    }

    $out = array();
    $queue = array($tprojectId);
    $seen = array($tprojectId => true);
    $guard = 0;

    while (!empty($queue) && $guard < 2000) {
        $guard++;
        $id = intval(array_shift($queue));
        $rows = $db->get_recordset(
            "SELECT id, name, node_type_id, parent_id FROM {$T['nodes_hierarchy']}" .
            " WHERE parent_id = {$id} ORDER BY node_order, id");
        if (is_null($rows)) {
            continue;
        }
        foreach ($rows as $r) {
            $childId = intval($r['id']);
            if (isset($seen[$childId])) {
                continue;
            }
            $seen[$childId] = true;
            if (intval(tsroOwningProject($db, $r)) !== $tprojectId) {
                continue;
            }
            if (intval($r['node_type_id']) == $n['testsuite']) {
                $queue[] = $childId;
            } elseif (intval($r['node_type_id']) == $n['testcase']) {
                $queue[] = $childId;
                $vers = $db->get_recordset(
                    "SELECT NH.id, TCVER.version, TCVER.tc_external_id," .
                    " (SELECT COUNT(1) FROM {$T['tcsteps']} TS" .
                    "    JOIN {$T['nodes_hierarchy']} SNH ON SNH.id = TS.id" .
                    "   WHERE SNH.parent_id = NH.id" .
                    "     AND SNH.node_type_id = {$n['testcase_step']}) AS step_qty" .
                    " FROM {$T['nodes_hierarchy']} NH" .
                    " JOIN {$T['tcversions']} TCVER ON TCVER.id = NH.id" .
                    " WHERE NH.parent_id = {$childId}" .
                    " AND NH.node_type_id = {$n['testcase_version']}" .
                    " ORDER BY TCVER.version");
                if (is_null($vers)) {
                    continue;
                }
                foreach ($vers as $v) {
                    $out[] = array(
                        'tcversion_id' => intval($v['id']),
                        'version' => intval($v['version']),
                        'external_id' => $prefix . $glue . (string)$v['tc_external_id'],
                        'tcase_name' => (string)$r['name'],
                        'step_qty' => intval($v['step_qty']),
                    );
                }
            }
        }
    }

    return array($tprojectId, (string)$projRow[0]['name'], $prefix, $out);
}

$action = getStr('action', 'init');
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method !== 'GET' && $action === 'init') {
    out(array('status' => 'error', 'code' => 'method_not_allowed',
              'message' => 'Method not allowed'), 405);
}

if ($action === 'versions') {
    if ($method !== 'GET') {
        out(array('status' => 'error', 'code' => 'method_not_allowed',
              'message' => 'Method not allowed'), 405);
    }
    $requestedProject = getInt('tproject_id', 0);
    if ($requestedProject <= 0) {
        $requestedProject = intval($_SESSION['testprojectID'] ?? 0);
    }
    // Right first: an unauthorised request must not pay for the tree walk. The
    // project's own resolution (session fallback / no_context) happens inside.
    if ($requestedProject > 0 && !$user->hasRight($db, 'mgt_view_tc', $requestedProject)) {
        out(array('status' => 'error', 'code' => 'forbidden',
                  'message' => 'Insufficient rights on this test project'), 403);
    }
    list($tprojectId, $tprojectName, $prefix, $versions) =
        tsroVersions($db, $requestedProject);

    if (!$user->hasRight($db, 'mgt_view_tc', $tprojectId)) {
        out(array('status' => 'error', 'code' => 'forbidden',
                  'message' => 'Insufficient rights on this test project'), 403);
    }

    out(array(
        'status' => 'ok',
        'context' => array(
            'tproject_id' => $tprojectId,
            'tproject_name' => $tprojectName,
            'tproject_prefix' => $prefix,
        ),
        'count' => count($versions),
        'versions' => $versions,
        'rights' => array(
            'mgt_view_tc' => true,
            'mgt_modify_tc' => $user->hasRight($db, 'mgt_modify_tc', $tprojectId),
        ),
    ));
}

if ($action === 'init') {
    $tcverId = getInt('tcversion_id', 0);
    if ($tcverId <= 0) {
        out(array('status' => 'error', 'code' => 'no_version',
                  'message' => 'tcversion_id is required'), 400);
    }

    list($version, $tcase, $tprojectId) =
        tsroContext($db, $user, $tcverId, getInt('tproject_id', 0), 'mgt_view_tc');

    $T = tsroTables();
    $tprojectMgr = new testproject($db);
    $glue = config_get('testcase_cfg')->glue_character;
    $prefix = $tprojectMgr->getTestCasePrefix($tprojectId);

    $verRow = $db->get_recordset(
        "SELECT tc_external_id, version FROM {$T['tcversions']}" .
        " WHERE id = " . intval($tcverId));
    $externalId = '';
    $versionNo = 0;
    if (!is_null($verRow) && count($verRow) > 0) {
        $externalId = (string)$verRow[0]['tc_external_id'];
        $versionNo = intval($verRow[0]['version']);
    }

    $steps = tsroSteps($db, $tcverId);

    out(array(
        'status' => 'ok',
        'context' => array(
            'tproject_id' => $tprojectId,
            'tproject_name' => tsroProjectName($db, $tprojectId),
            'tproject_prefix' => $prefix !== '' ? $prefix : tsroProjectPrefix($db, $tprojectId),
            'tplan_id' => intval($_SESSION['testplanID'] ?? 0),
            'tcase_id' => intval($tcase['id']),
            'tcversion_id' => intval($version['id']),
            'version' => $versionNo,
            'tcase_name' => (string)$tcase['name'],
            'suite_path' => tsroSuitePath($db, $tcase),
            'external_id' => $prefix . $glue . $externalId,
        ),
        'count' => count($steps),
        'steps' => tsroStepsPayload($steps),
        'rights' => array(
            'mgt_view_tc' => true,
            'mgt_modify_tc' => $user->hasRight($db, 'mgt_modify_tc', $tprojectId),
        ),
    ));
}

if ($method !== 'POST') {
    out(array('status' => 'error', 'code' => 'method_not_allowed',
              'message' => 'Method not allowed'), 405);
}

$tcverId = getInt('tcversion_id', 0);
if ($tcverId <= 0) {
    out(array('status' => 'error', 'code' => 'bad_param',
              'message' => 'tcversion_id is required'), 400);
}

list($version, $tcase, $tprojectId) =
    tsroContext($db, $user, $tcverId, getInt('tproject_id', 0), 'mgt_modify_tc');

$steps = tsroSteps($db, $tcverId);
// Snapshot of the STORED order + numbering, used to tell a real write from a
// no-op in the answer (see tsroWriteResult).
$before = array('ids' => tsroIdsOf($steps), 'numbers' => tsroNumbersOf($steps));

if (count($steps) < 2 && $action !== 'normalize') {
    // Nothing to re-order: answering 400 keeps the screen honest instead of
    // reporting a successful write that changed no row.
    out(array('status' => 'error', 'code' => 'bad_param',
              'message' => 'This test case version has fewer than two steps'), 400);
}

$tcaseMgr = new testcase($db);

if ($action === 'move') {
    $stepId = getInt('step_id', 0);
    $position = strtolower(getStr('position', ''));
    if (!in_array($position, array('top', 'bottom', 'up', 'down'), true)) {
        out(array('status' => 'error', 'code' => 'bad_param',
                  'message' => 'Invalid position'), 400);
    }

    $ids = $before['ids'];
    $idx = array_search($stepId, $ids, true);
    if ($idx === false) {
        out(array('status' => 'error', 'code' => 'not_found',
                  'message' => 'Step does not belong to this test case version'), 404);
    }

    if ($position === 'top') {
        array_unshift($ids, array_splice($ids, $idx, 1)[0]);
    } elseif ($position === 'bottom') {
        $ids[] = array_splice($ids, $idx, 1)[0];
    } else {
        $swap = ($position === 'up') ? $idx - 1 : $idx + 1;
        if ($swap < 0 || $swap >= count($ids)) {
            out(array('status' => 'no_change', 'message' => 'Already at the boundary',
                      'count' => count($steps),
                      'steps' => tsroStepsPayload($steps)), 200);
        }
        $tmp = $ids[$idx];
        $ids[$idx] = $ids[$swap];
        $ids[$swap] = $tmp;
    }

    // The legacy primitive: one UPDATE per step, renumbering 1..n. Kept as-is so
    // the written rows are byte-compatible with 1.9.20.
    $renumbered = array();
    $point = 1;
    foreach ($ids as $id) {
        $renumbered[$id] = $point++;
    }
    $tcaseMgr->set_step_number($renumbered);

    out(tsroWriteResult($db, $tcverId, $before));
}

if ($action === 'reorder') {
    $raw = getParam('order', getParam('stepSeq', ''));
    if (is_array($raw)) {
        $list = $raw;
    } else {
        $list = explode(',', (string)$raw);
    }

    $ids = array();
    foreach ($list as $v) {
        $v = trim((string)$v);
        if ($v === '') {
            continue;
        }
        if (!ctype_digit($v)) {
            out(array('status' => 'error', 'code' => 'bad_param',
                      'message' => 'Step list must be numeric ids'), 400);
        }
        $ids[] = intval($v);
    }
    if (count($ids) < 2) {
        out(array('status' => 'error', 'code' => 'bad_param',
                  'message' => 'At least two step ids are required'), 400);
    }
    if (count(array_unique($ids)) !== count($ids)) {
        out(array('status' => 'error', 'code' => 'bad_param',
                  'message' => 'Duplicate step ids in list'), 400);
    }

    if (!tsroRequireOwnSteps($db, $ids, $tcverId, $steps)) {
        out(array('status' => 'error', 'code' => 'bad_param',
                  'message' => 'Step list must contain every step of this version exactly once'), 400);
    }

    $renumbered = array();
    $point = 1;
    foreach ($ids as $id) {
        $renumbered[$id] = $point++;
    }
    $tcaseMgr->set_step_number($renumbered);

    out(tsroWriteResult($db, $tcverId, $before));
}

if ($action === 'normalize') {
    // Legacy parity: stepReorder.php always renumbered 1..n from the submitted
    // list, so gaps / duplicates left behind by an import are repairable.
    $renumbered = array();
    $point = 1;
    foreach ($before['ids'] as $id) {
        $renumbered[$id] = $point++;
    }
    $tcaseMgr->set_step_number($renumbered);

    out(tsroWriteResult($db, $tcverId, $before));
}

out(array('status' => 'error', 'code' => 'unknown_action',
          'message' => 'Unknown action'), 400);
