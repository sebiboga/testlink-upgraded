<?php
/**
 * Move / Copy Test Cases to another Test Suite BFF API
 * URL: /api/tcmovecopy/
 * Plain PHP, no framework. Refs #1724.
 *
 * Modern replacement for the 1.9.20 `containerMoveTC.tpl` slice served by
 * `lib/testcases/containerEdit.php` for these actions:
 *
 *   - `move_testcases_viewer`  -> the selection + target screen
 *   - `do_move_tcase_set`       -> tree::change_parent($tcaseSet, $containerID)
 *   - `do_copy_tcase_set`      -> testcase::copy_to(...) per selected case
 *   - `do_copy_tcase_set_ghost`-> same, with `stepAsGhost` (copy into the
 *                                 "ghost zone": only the LATEST version of
 *                                 every case is carried over)
 *
 * Legacy parity preserved:
 *   - target list = `testproject::gen_combo_test_suites($tprojectID)`, i.e. the
 *     dotted suite hierarchy WITHOUT test cases. The legacy combo does not
 *     exclude the source suite, so moving onto itself was reachable in 1.9.20;
 *     the target == source case is a no-op and is rejected here with 409
 *     (`same_target`) rather than silently "succeeding".
 *   - the two copy-only checkboxes map to `copy_to(copy_also.keyword_assignments`
 *     / `.requirement_assignments)`.
 *   - copy messages: `one_testcase_copied` (1 case) / `testcase_set_copied`
 *     (n cases); move failure message `move_testcases_failed`.
 *
 * Hardened relative to 1.9.20 (which trusted the form completely):
 *   - live session (401) + inactivity window (bffEnforceSession, 401);
 *   - `bffSameOriginGuard()` (403 on cross-site XHR);
 *   - `mgt_modify_tc` on the OWNING project of the source suite (403);
 *   - every selected id is PROVEN to be a test case of the addressed suite
 *     (404 `foreign_testcase`), so ids cannot be harvested from another suite
 *     or project;
 *   - the target suite is PROVEN to live in the same test project
 *     (404 `unknown_target_suite`);
 *   - 405 verb policy, 400 unknown action / empty selection / bad params,
 *     409 same-target / copy failure.
 *
 * Routes:
 *   GET  /?action=init&tproject_id=N&suite_id=M[&locale=xx]
 *   POST /?action=move          {suite_id, target_suite_id, tcase_ids:[...]}
 *   POST /?action=copy          {suite_id, target_suite_id, tcase_ids:[...],
 *                                copy_keywords:0|1, copy_requirements:0|1}
 *   POST /?action=copy_ghost    (same as copy; stepAsGhost = true)
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$db = new database(DB_TYPE);
doDBConnect($db);

/**
 * Emit a JSON payload and stop. Mirrors the api/namecheck convention: an
 * explicit status code is only applied when the caller passes one, so the
 * guards that already set http_response_code() are not masked by a default.
 */
function tmvc_out($data, $code = null) {
    if (!headers_sent()) {
        if ($code !== null) {
            http_response_code($code);
        }
    }
    echo json_encode($data);
    exit;
}

function tmvc_fail($code_name, $message, $http = 400, $extra = null) {
    $payload = array('status' => 'error', 'code' => $code_name, 'message' => $message);
    if (!is_null($extra)) {
        $payload = array_merge($payload, $extra);
    }
    tmvc_out($payload, $http);
}

// ---------------------------------------------------------------- auth -----
// Auth FIRST, verb second: an anonymous caller must not learn the route's
// verb policy.
$userId = isset($_SESSION['userID']) ? $_SESSION['userID'] : null;
if (!$userId || $userId <= 0) {
    tmvc_fail('not_authenticated', 'Not authenticated', 401);
}
$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    tmvc_fail('not_authenticated', 'User not found', 401);
}

$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_REQUEST['action']) ? (string)$_REQUEST['action'] : '';
$readActions = array('init');
$writeActions = array('move', 'copy', 'copy_ghost');

if (in_array($action, $readActions)) {
    if ($method !== 'GET') {
        tmvc_fail('method_not_allowed', 'Method not allowed', 405);
    }
} elseif (in_array($action, $writeActions)) {
    if ($method !== 'POST') {
        tmvc_fail('method_not_allowed', 'Method not allowed', 405);
    }
} else {
    tmvc_fail('unknown_action', 'Unknown action', 400);
}

bffEnforceSession($db);

// -------------------------------------------------------------- helpers ----
$tprojectMgr = new testproject($db);
$tsuiteMgr = new testsuite($db);
$tcaseMgr = new testcase($db);

/**
 * node_type description => id, read from the node_types table. Never
 * hardcoded: a non-default node_types fixture must keep working (the same
 * lesson as bug #1725 / api/namecheck).
 */
function tmvc_node_types(&$dbh) {
    $m = new tree($dbh);
    $map = $m->get_available_node_types();
    return is_array($map) ? $map : array();
}

function tmvc_type_id($typeMap, $name) {
    if (!isset($typeMap[$name])) {
        tmvc_fail('unknown_suite', 'Test suite not found', 404);
    }
    return intval($typeMap[$name]);
}

/**
 * Owning test project of a node id, walking the nodes_hierarchy parent chain.
 * The node's OWN type is tested before stepping to the parent, so a test suite
 * (a direct child of the project root) resolves correctly (bug #1725).
 * Returns 0 when the node does not exist or the chain is broken.
 */
function tmvc_owning_project(&$dbh, $nodeId, $typeMap) {
    $nodeId = intval($nodeId);
    if ($nodeId <= 0) {
        return 0;
    }
    $nh = tlObjectWithDB::getDBTables('nodes_hierarchy');
    $projectTypeId = tmvc_type_id($typeMap, 'testproject');
    $cur = $nodeId;
    $guard = 64;
    while ($cur > 0 && $guard-- > 0) {
        $row = $dbh->fetchFirstRow(
            "SELECT parent_id, node_type_id FROM {$nh['nodes_hierarchy']} WHERE id = " . intval($cur));
        if (empty($row) || !array_key_exists('parent_id', $row)) {
            return 0;
        }
        if (intval($row['node_type_id']) === $projectTypeId) {
            return intval($cur);
        }
        $parent = intval($row['parent_id']);
        if ($parent === 0) {
            return 0;
        }
        $cur = $parent;
    }
    return 0;
}

/**
 * node_type_id of a node, 0 when unknown.
 */
function tmvc_node_type(&$dbh, $nodeId) {
    $nh = tlObjectWithDB::getDBTables('nodes_hierarchy');
    $row = $dbh->fetchFirstRowSingleColumn(
        "SELECT node_type_id FROM {$nh['nodes_hierarchy']} WHERE id = " . intval($nodeId),
        'node_type_id');
    return is_null($row) ? 0 : intval($row);
}

/**
 * Resolve + authorize the addressed suite. Rights are ALWAYS checked on the
 * OWNING project, never on the caller-supplied tproject_id, and the returned
 * owning project overrides whatever the caller asked for.
 */
function tmvc_resolve_suite(&$dbh, &$tprojectMgr, $tprojectId, $suiteId, $typeMap) {
    $suiteId = intval($suiteId);
    if ($suiteId <= 0) {
        tmvc_fail('missing_suite_id', 'Test suite is required', 400);
    }
    if (tmvc_node_type($dbh, $suiteId) != tmvc_type_id($typeMap, 'testsuite')) {
        tmvc_fail('unknown_suite', 'Test suite not found', 404);
    }
    $owning = tmvc_owning_project($dbh, $suiteId, $typeMap);
    if ($owning <= 0) {
        tmvc_fail('unknown_suite', 'Test suite not found', 404);
    }
    // Rights on the OWNING project. The caller-supplied id is only a hint: if
    // it disagrees we silently use the truth (the legacy controller did the
    // same via the session project), we do not fail, because a stale deep link
    // must not become an existence oracle.
    if ($GLOBALS['tmvc_user']->hasRight($dbh, 'mgt_modify_tc', $owning) !== 'yes') {
        tmvc_fail('access_denied', 'Access Denied', 403);
    }
    return $owning;
}

/**
 * The test cases of a suite, with the columns the legacy DataTable showed.
 */
function tmvc_suite_cases(&$dbh, &$tsuiteMgr, &$tcaseMgr, $suiteId, $tprojectId) {
    $rows = array();
    $children = $tsuiteMgr->get_children_testcases($suiteId, 'simple');
    if (!is_array($children)) {
        return $rows;
    }
    $tproj = new testproject($dbh);
    $prefix = $tproj->getTestCasePrefix($tprojectId);
    foreach ($children as $node) {
        $tcid = isset($node['id']) ? intval($node['id']) : 0;
        if ($tcid <= 0) {
            continue;
        }
        $info = $tcaseMgr->get_by_id($tcid, testcase::LATEST_VERSION);
        if (!is_null($info) && isset($info[0])) {
            // testcase::get_by_id() row keys: id = the tcversion id,
            // importance (NOT priority), testsuite_id, is_open (NOT open).
            $rows[] = array(
                'tcase_id' => $tcid,
                'tcversion_id' => intval($info[0]['id']),
                'tcexternalid' => $info[0]['tc_external_id'],
                'tcname' => $info[0]['name'],
                'summary' => $info[0]['summary'],
                'status' => intval($info[0]['status']),
                'importance' => intval($info[0]['importance']),
                'execution_type' => intval($info[0]['execution_type']),
                'tcprefix' => $prefix,
            );
        }
    }
    return $rows;
}

/**
 * Post-write feedback, legacy wording preserved (lang_get keys
 * testcase_set_copied / one_testcase_copied already exist in every locale).
 * The MOVE wording has no legacy key in 1.9.20 - the move page only rendered
 * an empty feedback string on success - so these two are new server keys that
 * are localized client-side by the screen (tmvc.moved / tmvc.movedOne).
 */
// No 1.9.20 locale key exists for "N test cases moved" (only the copy ones
// below), so the screen localizes the move result itself: tmvc.moveDone.
function tmvc_tcase_name(&$tcaseMgr, $tcaseId) {
    if ($tcaseId < 1) {
        return '';
    }
    $info = $tcaseMgr->get_by_id($tcaseId, testcase::LATEST_VERSION);
    return (!is_null($info) && isset($info[0]['name'])) ? $info[0]['name'] : '';
}

function tmvc_move_message($qty) {
    return '';
}
function tmvc_copy_message($qty) {
    return $qty == 1 ? lang_get('one_testcase_copied')
                     : sprintf(lang_get('testcase_set_copied'), $qty);
}

// ----------------------------------------------------------------- init ----
if ($action === 'init') {
    $GLOBALS['tmvc_user'] = $user;
    $tprojectId = isset($_REQUEST['tproject_id']) ? intval($_REQUEST['tproject_id']) : 0;
    $suiteId = isset($_REQUEST['suite_id']) ? intval($_REQUEST['suite_id']) : 0;

    $typeMap = tmvc_node_types($db);
    $owning = tmvc_resolve_suite($db, $tprojectMgr, $tprojectId, $suiteId, $typeMap);

    $tp = $tprojectMgr->get_by_id($owning);
    $suite = $tsuiteMgr->get_by_id($suiteId);
    if (is_null($tp) || is_null($suite)) {
        tmvc_fail('unknown_suite', 'Test suite not found', 404);
    }

    // Dotted suite hierarchy WITHOUT test cases (legacy gen_combo_test_suites).
    $targets = $tprojectMgr->gen_combo_test_suites($owning);
    $targetList = array();
    foreach ($targets as $id => $label) {
        $targetList[] = array('id' => intval($id), 'name' => $label);
    }
    if (count($targetList) == 0) {
        // A project with a single suite has no sibling to move/copy into:
        // offer the project root path exactly like the legacy combo did.
        $targetList[] = array('id' => $owning, 'name' => $tp['name']);
    }

    // $tprojOpts must come from the MANAGER (config_get('testproject_options')
    // returns a config KEY name, not the options object - a trap that fatals
    // on property access).
    $tprojOpts = $tprojectMgr->getOptions($owning);

    // Label domains come from the same sources the legacy screen used
    // (containerEdit.php:1030-1040) so the modern table shows the very same
    // localized Status / Importance / Execution labels.
    $stLbl = getConfigAndLabels('testCaseStatus', 'code');
    $imLbl = getConfigAndLabels('execution_type', 'code');
    $domains = array(
        'status' => array(0 => ''),
        'importance' => array(
            0 => '',
            HIGH => lang_get('high_importance'),
            MEDIUM => lang_get('medium_importance'),
            LOW => lang_get('low_importance'),
        ),
        'execution' => array(0 => ''),
    );
    foreach ($stLbl['lbl'] as $code => $label) {
        $domains['status'][(int)$code] = $label;
    }
    foreach ($imLbl['lbl'] as $code => $label) {
        $domains['execution'][(int)$code] = $label;
    }

    tmvc_out(array(
        'status' => 'ok',
        'domains' => $domains,
        'context' => array(
            'tproject_id' => $owning,
            'tproject_name' => $tp['name'],
            'suite_id' => $suiteId,
            'suite_name' => $suite['name'],
        ),
        'targets' => $targetList,
        'testcases' => tmvc_suite_cases($db, $tsuiteMgr, $tcaseMgr, $suiteId, $owning),
        'options' => array(
            // keyword + requirement copy checkboxes (legacy defaults: both on)
            'copy_keywords' => true,
            'copy_requirements' => true,
            'testPriorityEnabled' => !empty($tprojOpts) && !empty($tprojOpts->testPriorityEnabled),
        ),
    ));
}

// ---------------------------------------------------------------- writes ---
$GLOBALS['tmvc_user'] = $user;

$suiteId = isset($_REQUEST['suite_id']) ? intval($_REQUEST['suite_id']) : 0;
$targetId = isset($_REQUEST['target_suite_id']) ? intval($_REQUEST['target_suite_id']) : 0;
$typeMap = tmvc_node_types($db);
$owning = tmvc_resolve_suite($db, $tprojectMgr, 0, $suiteId, $typeMap);

if ($targetId <= 0) {
    tmvc_fail('missing_target', 'Please choose a target test suite', 400);
}

// The target must be a suite of the SAME project. target_suite_id == tproject_id
// is a legitimate legacy value (the combo seeds the project root), so it is
// accepted here and resolved to the project root node below.
$targetType = tmvc_node_type($db, $targetId);
$projectTypeId = tmvc_type_id($typeMap, 'testproject');
$suiteTypeId = tmvc_type_id($typeMap, 'testsuite');
$caseTypeId = tmvc_type_id($typeMap, 'testcase');
if ($targetType == $projectTypeId) {
    if ($targetId != $owning) {
        tmvc_fail('unknown_target_suite', 'Target test suite not found', 404);
    }
} elseif ($targetType == $suiteTypeId) {
    $targetOwning = tmvc_owning_project($db, $targetId, $typeMap);
    if ($targetOwning != $owning) {
        tmvc_fail('unknown_target_suite', 'Target test suite not found', 404);
    }
} else {
    tmvc_fail('unknown_target_suite', 'Target test suite not found', 404);
}

// Selection
$tcaseSet = array();
if (isset($_REQUEST['tcase_ids']) && is_array($_REQUEST['tcase_ids'])) {
    foreach ($_REQUEST['tcase_ids'] as $raw) {
        if (is_array($raw)) {
            tmvc_fail('bad_selection', 'Malformed test case selection', 400);
        }
        $tcaseSet[] = intval($raw);
    }
} elseif (isset($_REQUEST['tcase_ids']) && !is_array($_REQUEST['tcase_ids'])) {
    tmvc_fail('bad_selection', 'Malformed test case selection', 400);
}
$tcaseSet = array_values(array_unique(array_filter($tcaseSet, function ($v) {
    return $v > 0;
})));

if (count($tcaseSet) == 0) {
    tmvc_fail('no_selection', 'Please select at least one test case', 400);
}

// PROVE every selected id is a test case of the addressed suite.
$valid = array();
foreach ($tcaseSet as $tcid) {
    if (tmvc_node_type($db, $tcid) != $caseTypeId) {
        tmvc_fail('foreign_testcase', 'Test case does not belong to this test suite', 404);
    }
    $owner = tmvc_owning_project($db, $tcid, $typeMap);
    if ($owner != $owning) {
        tmvc_fail('foreign_testcase', 'Test case does not belong to this test suite', 404);
    }
    $info = $tcaseMgr->get_by_id($tcid, testcase::ALL_VERSIONS);
    if (is_null($info) || count($info) < 1) {
        tmvc_fail('foreign_testcase', 'Test case does not belong to this test suite', 404);
    }
    // get_by_id() rows carry testsuite_id, NOT parent_id - and it is the
    // testsuite the case is linked to, which is what the screen must prove.
    if (intval($info[0]['testsuite_id']) != $suiteId) {
        tmvc_fail('foreign_testcase', 'Test case does not belong to this test suite', 404);
    }
    $valid[] = $tcid;
}
$tcaseSet = $valid;

// Re-parenting a node onto itself / onto its own parent is a no-op. The legacy
// tree::change_parent() would run the UPDATE and answer "ok"; here it is a
// 409 so the UI can say something truthful.
if ($action === 'move') {
    if ($targetId == $suiteId || $targetId == $owning) {
        tmvc_fail('same_target',
            'The test cases already belong to the target test suite', 409);
    }
    foreach ($tcaseSet as $tcid) {
        $info = $tcaseMgr->get_by_id($tcid, testcase::LATEST_VERSION);
        if (!is_null($info) && isset($info[0]) && intval($info[0]['testsuite_id']) == $targetId) {
            tmvc_fail('same_target',
                'The test cases already belong to the target test suite', 409);
        }
    }

    $treeMgr = new tree($db);
    $ok = $treeMgr->change_parent($tcaseSet, $targetId);
    if (!$ok) {
        // lang_get('move_testcases_failed') has no key in ANY 1.9.20 locale file
        // (it is a dead legacy reference in containerEdit.php:1119 that renders
        // the literal key), so the screen localizes this itself: tmvc.moveFailed.
        tmvc_fail('move_failed', 'The test cases could not be moved', 409);
    }


    tmvc_out(array(
        'status' => 'ok',
        'action' => 'move',
        'moved' => count($tcaseSet),
        'source_suite_id' => $suiteId,
        'target_suite_id' => $targetId,
        'message' => tmvc_move_message(count($tcaseSet)),
        'refresh_tree' => true,
    ));
}

// copy / copy_ghost
$copyKeywords = isset($_REQUEST['copy_keywords'])
    ? (intval($_REQUEST['copy_keywords']) ? true : false) : true;
$copyRequirements = isset($_REQUEST['copy_requirements'])
    ? (intval($_REQUEST['copy_requirements']) ? true : false) : true;
$stepAsGhost = ($action === 'copy_ghost');

$copyOpt = array(
    'check_duplicate_name' => config_get('check_names_for_duplicates'),
    'action_on_duplicate_name' => config_get('action_on_duplicate_name'),
    'stepAsGhost' => $stepAsGhost,
    'copy_also' => array(
        'keyword_assignments' => $copyKeywords,
        'requirement_assignments' => $copyRequirements,
    ),
);

$copied = 0;
$created = array();
$failed = array();
foreach ($tcaseSet as $tcid) {
    $res = $tcaseMgr->copy_to($tcid, $targetId, $userId, $copyOpt);
    if (isset($res['status_ok']) && $res['status_ok']) {
        $copied++;
        $created[] = array(
            'source_tcase_id' => $tcid,
            'tcase_id' => isset($res['id']) ? intval($res['id']) : 0,
            // copy_to() returns only the new id, so the name is read back
            // from the copy so the screen can list what was created.
            'name' => tmvc_tcase_name($tcaseMgr, isset($res['id']) ? intval($res['id']) : 0),
        );
    } else {
        $failed[] = array(
            'tcase_id' => $tcid,
            'msg' => isset($res['msg']) ? $res['msg'] : 'error',
        );
    }
}

if ($copied == 0) {
    tmvc_fail('copy_failed', 'No test case could be copied', 409,
        array('failed' => $failed));
}

tmvc_out(array(
    'status' => 'ok',
    'action' => $stepAsGhost ? 'copy_ghost' : 'copy',
    'copied' => $copied,
    'requested' => count($tcaseSet),
    'source_suite_id' => $suiteId,
    'target_suite_id' => $targetId,
    'created' => $created,
    'failed' => $failed,
    'message' => tmvc_copy_message($copied),
    'refresh_tree' => true,
));