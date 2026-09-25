<?php
/**
 * api/reqtcbassign — Requirements Bulk Assignment BFF (Refs #1595)
 *
 * Modern twin of the last Requirements screen still only reachable through the
 * legacy controller:
 *   lib/requirements/reqTcAssign.php  (edit=testsuite / doAction=bulkassign)
 *   gui/templates/dashio/requirements/reqTcBulkAssignment.tpl
 *
 * Legacy behavior ported 1:1:
 *  - page rights  : checkRights() → pageAccessCheck rightsAnd=['req_tcase_link_management']
 *                   (hasRightOnProj on the session test project)
 *  - context      : a TEST SUITE (tsuite_id) — the target is every test case
 *                   DEEP under that suite (getTargetTestCases() →
 *                   testsuite::get_testcases_deep($id,'only_id')).
 *  - req specs    : testproject::genComboReqSpec($tproject_id,'dotted','&nbsp;'),
 *                   selection remembered in $_SESSION['currentSrsId'].
 *  - requirements : requirement_spec_mgr::getAllLatestRQVOnReqSpec($spec,['output'=>'array'])
 *  - warning msg  : bulk_req_assign_msg (N test cases) / bulk_req_assign_no_test_cases
 *  - counters     : req_on_req_spec (%d children on spec) + bulk_assigment_done (%s done)
 *  - write        : requirement_mgr::bulkAssignLatestREQVTCV($reqIds,$tcaseIds,$userId)
 *                   which links the LATEST requirement version to the LATEST
 *                   test case version and skips existing links (idempotent).
 *
 * Modern supersets (no legacy capability removed):
 *  - 'unassign'  : bulk removal of the very same coverage rows (the legacy
 *                  bulk grid only exposed Assign; unlinking happened one link
 *                  at a time from the single-test-case screen).
 *  - 'linked_count' per requirement so the user can see how many of the
 *                  suite test cases are already covered before assigning.
 *
 *  GET  ?action=init&tproject_id=N&tsuite_id=N[&idSRS=N]
 *       -> suite context + req spec combo + requirement grid + counters
 *  POST ?action=bulkassign {tproject_id,tsuite_id,idSRS,req_id:[..]}
 *  POST ?action=unassign  {tproject_id,tsuite_id,idSRS,req_id:[..]}
 *
 * 401 anon · 403 no right / same-origin · 404 unknown suite · 400 bad params
 *
 * Session-based auth, JSON I/O. No Smarty.
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

$userId = $_SESSION['userID'] ?? null;
if (!$userId || $userId <= 0) {
    http_response_code(401);
    out(['status' => 'error', 'message' => 'Not authenticated']);
}

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    http_response_code(401);
    out(['status' => 'error', 'message' => 'User not found']);
}

function out($data) {
    echo json_encode($data);
    exit;
}

function getBody() {
    return json_decode(file_get_contents('php://input'), true) ?? [];
}

function denyForbidden() {
    http_response_code(403);
    out(['status' => 'error',
         'message' => 'You do not have rights to manage requirement / test case links']);
}

function badRequest($msg) {
    http_response_code(400);
    out(['status' => 'error', 'message' => $msg]);
}

function notFound($msg) {
    http_response_code(404);
    out(['status' => 'error', 'message' => $msg]);
}

/** Normalize an incoming list of ids (array or csv string) to unique positive ints */
function toIdSet($raw) {
    if (is_string($raw)) {
        $raw = explode(',', $raw);
    }
    if (!is_array($raw)) {
        $raw = ($raw === null || $raw === '') ? [] : array($raw);
    }
    $out = [];
    foreach ($raw as $v) {
        if (!is_scalar($v)) {
            continue;
        }
        $n = intval(trim((string) $v));
        if ($n > 0) {
            $out[$n] = $n;
        }
    }
    return array_values($out);
}

/**
 * Resolve + validate the (tproject_id, tsuite_id) pair and check the legacy
 * right. Returns the resolved context.
 */
function resolveCtx($tprojectId, $tsuiteId) {
    global $db, $user;

    if ($tprojectId <= 0) {
        badRequest('Invalid test project id');
    }
    if ($tsuiteId <= 0) {
        badRequest('Invalid test suite id');
    }
    if (!$user->hasRight($db, 'req_tcase_link_management', $tprojectId, null, true)) {
        // legacy pageAccessCheck() logged the refused access before blocking
        logAuditEvent(TLS('audit_security_user_right_missing',
                          'req_tcase_link_management'),
                      'SECURITY', $tprojectId, 'testprojects');
        denyForbidden();
    }

    $tprojectMgr = new testproject($db);
    $tp = $tprojectMgr->get_by_id($tprojectId);
    if (is_null($tp) || count($tp) === 0) {
        notFound('Test project not found');
    }
    $opt = $tprojectMgr->getOptions($tprojectId);
    $opt = is_object($opt) ? $opt : new stdClass();
    if (empty($opt->requirementsEnabled)) {
        badRequest('Requirements are not enabled on this test project');
    }

    $suite = $tprojectMgr->tree_manager->get_node_hierarchy_info($tsuiteId);
    if (is_null($suite) || intval($suite['node_type_id'] ?? 0) <= 0) {
        notFound('Test suite not found');
    }
    // a legacy edit=testcase deep link passes a TEST CASE node id: it must not
    // silently resolve to the parent suite grid
    $suiteType = $tprojectMgr->tree_manager->getNodeType($tsuiteId);
    if (is_null($suiteType) || $suiteType['node_type'] !== 'testsuite') {
        notFound('Only a test suite can be bulk assigned');
    }

    // The suite must belong to the requested test project (walk up the tree
    // until the testproject root). Guards cross-project bulk assignment.
    $guard = 0;
    $cursor = intval($suite['parent_id'] ?? 0);
    $owner = 0;
    while ($cursor > 0 && $guard++ < 200) {
        if ($cursor == $tprojectId) {
            $owner = $tprojectId;
            break;
        }
        $row = $db->get_recordset(
            "SELECT parent_id FROM nodes_hierarchy WHERE id=" . intval($cursor));
        if (is_null($row) || count($row) === 0) {
            break;
        }
        $cursor = intval($row[0]['parent_id']);
    }
    if ($owner === 0) {
        notFound('Test suite not found in this test project');
    }

    return [
        'tproject_id' => $tprojectId,
        'tproject_name' => strval($tp['name'] ?? ''),
        'tsuite_id' => $tsuiteId,
        'tsuite_name' => strval($suite['name'] ?? ''),
    ];
}

/** Req spec combo (dotted paths) for the project — legacy genComboReqSpec() */
function reqSpecCombo($tprojectId) {
    global $db;
    $tprojectMgr = new testproject($db);
    $combo = $tprojectMgr->genComboReqSpec($tprojectId, 'dotted', '&nbsp;');
    $items = [];
    if (!is_null($combo)) {
        foreach ((array) $combo as $id => $name) {
            $items[] = [
                'id' => intval($id),
                'name' => trim(strval($name), "&nbsp; \t"),
            ];
        }
    }
    return $items;
}

/**
 * Resolve a requirement specification id that really belongs to $tprojectId.
 * Without this a caller could pass the idSRS of another project (or of a
 * project that has requirements disabled) and read/assign its requirements.
 */
function resolveSpecId($tprojectId, $specId) {
    $specs = reqSpecCombo($tprojectId);
    foreach ($specs as $s) {
        if ($s['id'] === intval($specId)) {
            return $s['id'];
        }
    }
    return 0;
}

/** Latest requirements of a spec — legacy getAllLatestRQVOnReqSpec() */
function specRequirements($specId) {
    global $db;
    if ($specId <= 0) {
        return [];
    }
    $mgr = new requirement_spec_mgr($db);
    $rs = $mgr->getAllLatestRQVOnReqSpec($specId, ['output' => 'array']);
    $rows = [];
    if (!is_null($rs)) {
        foreach ((array) $rs as $r) {
            $rows[] = [
                'id' => intval($r['id']),
                'req_version_id' => intval($r['req_version_id']),
                'version' => intval($r['version']),
                'doc_id' => strval($r['req_doc_id'] ?? ''),
                'title' => strval($r['title'] ?? ''),
                'scope' => strval($r['scope'] ?? ''),
                'linked_count' => 0,
            ];
        }
    }
    return $rows;
}

/** Test case ids DEEP under a suite — legacy getTargetTestCases() */
function suiteTestCaseIds($tsuiteId) {
    global $db;
    $mgr = new testsuite($db);
    $ids = $mgr->get_testcases_deep($tsuiteId, 'only_id');
    if (is_null($ids)) {
        return [];
    }
    $out = [];
    foreach ((array) $ids as $v) {
        $n = intval($v);
        if ($n > 0) {
            $out[$n] = $n;
        }
    }
    return array_values($out);
}

$action = isset($_REQUEST['action']) && is_scalar($_REQUEST['action'])
        ? strtolower(trim((string) $_REQUEST['action'])) : '';
$method = $_SERVER['REQUEST_METHOD'];

// ---------------------------------------------------------------------------
// GET ?action=init
// ---------------------------------------------------------------------------
if ($action === 'init') {
    if ($method !== 'GET') {
        http_response_code(405);
        out(['status' => 'error', 'message' => 'GET required']);
    }
    $tprojectId = intval($_REQUEST['tproject_id'] ?? 0);
    $tsuiteId = intval($_REQUEST['tsuite_id'] ?? 0);
    $ctx = resolveCtx($tprojectId, $tsuiteId);

    $specs = reqSpecCombo($tprojectId);

    // Legacy selection order: ?idSRS wins, then the session memory, then the
    // first combo entry.
    $requested = 0;
    if (isset($_REQUEST['idSRS']) && is_scalar($_REQUEST['idSRS'])) {
        $requested = intval($_REQUEST['idSRS']);
    }
    $sessionSrs = intval($_SESSION['currentSrsId'] ?? 0);
    $selected = 0;
    if ($requested > 0) {
        // a spec id of ANOTHER test project must not be read or remembered
        $selected = resolveSpecId($tprojectId, $requested);
        if ($selected === 0) {
            notFound('Requirement specification not found in this test project');
        }
    } elseif ($sessionSrs > 0) {
        $selected = resolveSpecId($tprojectId, $sessionSrs);
    }
    if ($selected <= 0 && count($specs) > 0) {
        $selected = $specs[0]['id'];
    }
    $selectedName = '';
    foreach ($specs as $s) {
        if ($s['id'] === $selected) {
            $selectedName = $s['name'];
            break;
        }
    }
    if ($selected > 0) {
        $_SESSION['currentSrsId'] = $selected;
    }

    $requirements = ($selected > 0) ? specRequirements($selected) : [];
    $tcaseIds = suiteTestCaseIds($ctx['tsuite_id']);

    // per-requirement already-linked coverage inside this suite
    if (count($requirements) > 0 && count($tcaseIds) > 0) {
        $reqVersionIds = [];
        foreach ($requirements as $r) {
            $reqVersionIds[] = $r['req_version_id'];
        }
        $t = tlObjectWithDB::getDBTables('req_coverage');
        $inReqV = implode(',', array_map('intval', $reqVersionIds));
        $inTc = implode(',', array_map('intval', $tcaseIds));
        $rs = $db->get_recordset(
            "SELECT DISTINCT RCOV.req_version_id, RCOV.testcase_id " .
            "FROM {$t['req_coverage']} RCOV " .
            "WHERE RCOV.req_version_id IN ({$inReqV}) " .
            "AND RCOV.link_status = " . intval(LINK_TC_REQ_OPEN) . " " .
            "AND RCOV.testcase_id IN ({$inTc})");
        $counter = [];
        if (!is_null($rs)) {
            foreach ($rs as $r) {
                $rv = intval($r['req_version_id']);
                $counter[$rv] = (isset($counter[$rv]) ? $counter[$rv] : 0) + 1;
            }
        }
        foreach ($requirements as $k => $r) {
            $rv = $r['req_version_id'];
            $requirements[$k]['linked_count'] = intval($counter[$rv] ?? 0);
        }
    }

    out([
        'status' => 'ok',
        'action' => 'init',
        'tproject_id' => $ctx['tproject_id'],
        'tproject_name' => $ctx['tproject_name'],
        'tsuite' => ['id' => $ctx['tsuite_id'], 'name' => $ctx['tsuite_name']],
        'req_specs' => $specs,
        'has_req_spec' => count($specs) > 0,
        'selected_req_spec' => ['id' => $selected, 'name' => $selectedName],
        'requirements' => $requirements,
        'tcase_count' => count($tcaseIds),
        'grants' => ['assign' => true],
    ]);
}

// ---------------------------------------------------------------------------
// POST ?action=bulkassign  /  ?action=unassign
// ---------------------------------------------------------------------------
if ($action === 'bulkassign' || $action === 'unassign') {
    if ($method !== 'POST') {
        http_response_code(405);
        out(['status' => 'error', 'message' => 'POST required']);
    }
    $payload = getBody();
    if (!is_array($payload)) {
        badRequest('Invalid JSON body');
    }
    $ctx = resolveCtx(intval($payload['tproject_id'] ?? 0),
                      intval($payload['tsuite_id'] ?? 0));

    $specId = 0;
    if (isset($payload['idSRS']) && is_scalar($payload['idSRS'])) {
        $specId = intval($payload['idSRS']);
    }
    if ($specId <= 0) {
        $specId = intval($_SESSION['currentSrsId'] ?? 0);
    }
    $specId = resolveSpecId($ctx['tproject_id'], $specId);
    if ($specId <= 0) {
        badRequest('Requirement specification not found in this test project');
    }

    $reqIds = toIdSet($payload['req_id'] ?? null);
    if (count($reqIds) === 0) {
        // legacy: check_action_precondition() blocked with "please_select_a_req"
        badRequest('Nothing selected');
    }

    // The target test cases are ALWAYS the ones deep under the suite
    // (legacy getTargetTestCases). A client-supplied filter set may only
    // narrow that set, never widen it.
    $tcaseIds = suiteTestCaseIds($ctx['tsuite_id']);
    if (count($tcaseIds) === 0) {
        badRequest('There are no test cases inside the selected test suite');
    }
    $requested = toIdSet($payload['tcase_id'] ?? null);
    if (count($requested) > 0) {
        $tcaseIds = array_values(array_intersect($tcaseIds, $requested));
        if (count($tcaseIds) === 0) {
            badRequest('None of the selected test cases belong to this test suite');
        }
    }

    // Only requirements that really live on the selected spec of this project
    // may be touched (cross-spec / cross-project request hardening).
    $valid = [];
    foreach (specRequirements($specId) as $r) {
        $valid[$r['id']] = $r;
    }
    $accepted = [];
    $rejected = [];
    foreach ($reqIds as $id) {
        if (isset($valid[$id])) {
            $accepted[] = $id;
        } else {
            $rejected[] = $id;
        }
    }
    if (count($accepted) === 0) {
        badRequest('None of the selected requirements belong to the selected requirement specification');
    }

    $reqMgr = new requirement_mgr($db);

    if ($action === 'bulkassign') {
        $done = $reqMgr->bulkAssignLatestREQVTCV($accepted, $tcaseIds, $userId);
        // legacy bulkAssignLatestREQVTCV() writes no audit by itself; we add
        // one aggregated entry so the Event Viewer keeps a trace. Nothing is
        // logged for a no-op (every link already existed), so re-running the
        // operation does not pollute the audit trail.
        if ($done > 0) {
            logAuditEvent(
                TLS('audit_req_assigned_tc',
                    count($accepted) . ' requirement(s) of Req Spec #' . $specId,
                    $ctx['tsuite_name'] . ' (' . count($tcaseIds) . ' test cases)'),
                'ASSIGN', $ctx['tsuite_id'], 'testsuites');
        }
        out([
            'status' => 'ok',
            'action' => 'bulkassign',
            'assigned' => intval($done),
            'rejected' => $rejected,
            'tcase_count' => count($tcaseIds),
            'message' => sprintf(lang_get('bulk_assigment_done'), $done),
        ]);
    }

    // unassign — remove exactly the coverage rows the bulk grid created:
    // latest requirement version x latest test case version, still "open".
    $t = tlObjectWithDB::getDBTables('req_coverage');
    $reqVersionIds = [];
    foreach ($accepted as $rid) {
        $reqVersionIds[] = $valid[$rid]['req_version_id'];
    }
    // NOTE: the database wrapper has no transaction support and the legacy
    // controller had none either; each coverage row is deleted individually.
    $removed = 0;
    foreach ($reqVersionIds as $reqV) {
        $reqV = intval($reqV);
        $affected = [];
        foreach ($tcaseIds as $tcid) {
            $affected[] = intval($tcid);
        }
        $inTc = implode(',', $affected);
        $chk = $db->get_recordset(
            "SELECT RCOV.id FROM {$t['req_coverage']} RCOV " .
            "WHERE RCOV.req_version_id = {$reqV} " .
            "AND RCOV.link_status = " . intval(LINK_TC_REQ_OPEN) . " " .
            "AND RCOV.testcase_id IN ({$inTc})");
        if (is_null($chk) || count($chk) === 0) {
            continue;
        }
        $ids = [];
        foreach ($chk as $row) {
            $ids[] = intval($row['id']);
        }
        $db->exec_query(
            "DELETE FROM {$t['req_coverage']} WHERE id IN (" . implode(',', $ids) . ")");
        $removed += count($ids);
    }
    if ($removed > 0) {
        logAuditEvent(
            TLS('audit_req_assignment_removed_tc',
                count($accepted) . ' requirement(s) of Req Spec #' . $specId,
                $ctx['tsuite_name'] . ' (' . $removed . ' links)'),
            'UNASSIGN', $ctx['tsuite_id'], 'testsuites');
    }
    out([
        'status' => 'ok',
        'action' => 'unassign',
        'unassigned' => intval($removed),
        'rejected' => $rejected,
        'tcase_count' => count($tcaseIds),
        'message' => sprintf(lang_get('bulk_assigment_done'), $removed),
    ]);
}

badRequest('Unknown or missing action');
