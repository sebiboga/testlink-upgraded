<?php
/**
 * api/keywordsedit — Keyword Create / Edit / Delete / Create-and-Link popup BFF
 * (Refs #1599, fixes #1008)
 *
 * Modernizes the last legacy keyword-management controller without a modern
 * twin: lib/keywords/keywordsEdit.php (+ gui/templates/dashio/keywords/
 * keywordsEdit.tpl) — the small dialog reached from the test case Keywords
 * panel ("Add keyword" / "Create and link") and from the Keyword Management
 * screen in its standalone `directAccess` form mode.
 *
 * Legacy parity — the exact same user flows of keywordsEdit.php:
 *   - mode=create        -> empty keyword form
 *   - mode=edit&id=K     -> edit form pre-filled with the keyword (name+notes)
 *   - mode=cfl&tcversion_id=V
 *                       -> "Create and link" form: creates the keyword and
 *                          links it to test case version V (do_cfl)
 *   - POST create        -> testproject::addKeyword()   (do_create)
 *   - POST update        -> testproject::updateKeyword() (do_update)
 *   - POST delete        -> testproject::deleteKeyword() (do_delete)
 *   - POST create_link   -> addKeyword() + testcase::addKeywords() (do_cfl)
 *
 * Rights: legacy initEnv() performs a project-scoped **AND** check on
 * mgt_modify_key + mgt_view_key before doing anything else and throws when
 * tproject_id <= 0. That exact check is reproduced here for every route
 * (this is the gap tracked as issue #1008, where the keywordsView BFF only
 * enforced mgt_modify_key globally and never mgt_view_key).
 *
 * Error mapping: the tlKeyword status codes are forwarded verbatim
 * (E_NAMENOTALLOWED -1, E_NAMELENGTH -2, E_NAMEALREADYEXISTS -4,
 * E_DBERROR -8) plus a message, so the client can render the same
 * localized feedback legacy getKeywordErrorMessage() produced.
 *
 * Routes:
 *   GET  ?action=init&tproject_id=N[&mode=create|edit|cfl][&id=K][&tcversion_id=V]
 *   POST ?action=create   {tproject_id, keyword, notes}
 *   POST ?action=update   {tproject_id, id, keyword, notes}
 *   POST ?action=create_link {tproject_id, keyword, notes, tcversion_id}
 *   POST ?action=delete   {tproject_id, id}
 *
 * Session-based auth, JSON I/O, no Smarty.
 */
require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json');

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || $userId <= 0) {
    http_response_code(401);
    out(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    http_response_code(401);
    out(['status' => 'error', 'message' => 'User not found']);
    exit;
}

function out($data) {
    if (!headers_sent()) {
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode($data);
    exit;
}

function getBody() {
    return json_decode(file_get_contents('php://input'), true) ?? [];
}

/**
 * Legacy initEnv() rights gate: AND-mode (mgt_modify_key AND mgt_view_key)
 * evaluated against the test project, plus the tproject_id > 0 precondition.
 */
function requireKeywordRights($db, $user, $tproject_id) {
    // hasRight() falls back to the GLOBAL role rights for an unknown project
    // id, so a global holder would otherwise pass with tproject_id=999999 and
    // createKeyword() would insert an orphan row (review finding).
    if ($tproject_id > 0) {
        static $knownProjects = array();
        if (!isset($knownProjects[$tproject_id])) {
            $tp = tlObject::getDBTables('testprojects');
            $rs = $db->get_recordset("SELECT id FROM {$tp['testprojects']} WHERE id = " . intval($tproject_id));
            if (is_null($rs) || count($rs) == 0) {
                http_response_code(404);
                out(['status' => 'error', 'message' => 'Test project not found']);
            }
            $knownProjects[$tproject_id] = true;
        }
    }
    if ($tproject_id <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test project id']);
    }
    $canModify = (bool)$user->hasRight($db, 'mgt_modify_key', $tproject_id);
    $canView = (bool)$user->hasRight($db, 'mgt_view_key', $tproject_id);
    if (!$canModify || !$canView) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission', 'error_code' => 'NO_RIGHT']);
    }
    return true;
}

/** legacy getKeywordErrorMessage() equivalent, server side (EN fallback). */
/**
 * Refs #1601: the rights are checked against the tproject_id supplied by the
 * caller, but the keyword itself is addressed by a bare id. Legacy
 * tlKeyword::writeToDB() (UPDATE ... WHERE id = X) and
 * testproject::deleteKeyword() (id only) never re-check testproject_id, so
 * without this guard a user with keyword rights in project A could rename,
 * re-own or delete a keyword of project B.
 *
 * Exits with 404 when the keyword does not exist or is owned by another
 * project - we never leak the existence of a foreign keyword.
 */
function requireKeywordOfProject($db, $keyword_id, $tproject_id) {
    $kw = tlKeyword::getByID($db, $keyword_id);
    if (is_null($kw) || $kw->dbID <= 0 || intval($kw->testprojectID) !== intval($tproject_id)) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Keyword not found']);
    }
    return $kw;
}

function keywordErrorMessage($code) {
    // Refs #1604: these were hardcoded English literals, so a Romanian user saw
    // English in the error box. lang_get() keys are the same ones the legacy
    // getKeywordErrorMessage() used.
    switch (intval($code)) {
        case tlKeyword::E_NAMENOTALLOWED:
            return lang_get('keywords_char_not_allowed');

        case tlKeyword::E_NAMELENGTH:
            return lang_get('empty_keyword_no');

        case tlKeyword::E_NAMEALREADYEXISTS:
            return lang_get('keyword_already_exists');

        case tlKeyword::E_WRONGFORMAT:
        default:
            return lang_get('kw_update_fails');
    }
}

function kwToJSON($kw) {
    return [
        'id' => intval($kw->dbID),
        'name' => (string)$kw->name,
        'notes' => (string)$kw->notes,
    ];
}

/**
 * Walk up the nodes_hierarchy parent chain (same helper as api/testcases).
 */
function kwParentChain($db, $nodesTable, $nodeId) {
    $chain = array();
    $cur = intval($nodeId);
    for ($i = 0; $i < 50 && $cur > 0; $i++) {
        $rs = $db->get_recordset(
            "SELECT id, name, parent_id, node_type_id FROM {$nodesTable} WHERE id = {$cur}");
        if (is_null($rs) || count($rs) == 0) {
            break;
        }
        $chain[] = $rs[0];
        $next = intval($rs[0]['parent_id']);
        if ($next <= 0 || $next === $cur) {
            break;
        }
        $cur = $next;
    }
    return array_reverse($chain);
}

/**
 * Resolve the test case version context used by the create-and-link flow.
 * Mirrors legacy do_cfl(): nodes_hierarchy.parent_id of the tcversion is the
 * owning test case.
 *
 * Refs #1603: the version id is caller supplied, so the whole chain is walked
 * and BOTH preconditions are enforced here:
 *   - the node must be a test case version (node_type_id 4) or a test case
 *     (node_type_id 3, legacy tolerated a bare tcase id) - a test suite or a
 *     project id is refused, and
 *   - the chain root must be a test project (node_type_id 1) equal to
 *     $tproject_id. Without this a keyword manager of project A could create
 *     a project-A keyword and link it to a test case version of project B,
 *     and could read the name of any test case in the installation.
 * Returns null in both cases (the caller answers 404, so nothing about a
 * foreign node is disclosed).
 */
function tcaseVersionContext($db, $tcversion_id, $tproject_id) {
    $tbl = tlObject::getDBTables('nodes_hierarchy');
    $chain = kwParentChain($db, $tbl['nodes_hierarchy'], $tcversion_id);
    if (count($chain) < 2) {
        return null;
    }

    // $chain is ordered root -> leaf (kwParentChain() walks leaf -> root and
    // reverses it), so chain[0] is the test project and the last element is
    // the node the caller asked for. Node types (cfg/const.inc.php):
    // 1 = test project, 2 = test suite, 3 = test case, 4 = test case version.
    $root = $chain[0];
    if (intval($root['node_type_id']) !== 1 || intval($root['id']) !== intval($tproject_id)) {
        return null;
    }

    $node = $chain[count($chain) - 1];
    $nodeType = intval($node['node_type_id']);
    if ($nodeType !== 4 && $nodeType !== 3) {
        return null;                       // a suite or the project itself
    }

    $tcase_id = 0;
    $tcaseName = '';
    for ($i = count($chain) - 1; $i >= 0; $i--) {
        if (intval($chain[$i]['node_type_id']) === 3) {
            $tcase_id = intval($chain[$i]['id']);
            $tcaseName = (string)$chain[$i]['name'];
            break;
        }
    }
    if ($tcase_id <= 0) {
        return null;
    }

    return [
        'tcversion_id' => intval($tcversion_id),
        'tcase_id' => $tcase_id,
        'tcase_name' => (string)$tcaseName,
    ];
}

$method = $_SERVER['REQUEST_METHOD'];
$action = strtolower(trim((string)($_GET['action'] ?? '')));
$tproject_mgr = new testproject($db);

// ---------------------------------------------------------------------------
// GET ?action=init — context + form data for the popup
// ---------------------------------------------------------------------------
if ($method === 'GET') {
    if ($action !== 'init') {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Unknown action']);
    }

    $tproject_id = intval($_GET['tproject_id'] ?? 0);
    requireKeywordRights($db, $user, $tproject_id);

    $mode = strtolower(trim((string)($_GET['mode'] ?? 'create')));
    if (!in_array($mode, ['create', 'edit', 'cfl'], true)) {
        $mode = 'create';
    }

    $payload = [
        'status' => 'ok',
        'mode' => $mode,
        'tproject' => [
            'id' => $tproject_id,
            'name' => (string)testproject::getName($db, $tproject_id),
        ],
        'keyword' => null,
        'tcase' => null,
        'rights' => [
            'mgt_view_events' => (bool)$user->hasRight($db, 'mgt_view_events', $tproject_id),
        ],
    ];

    if ($mode === 'edit') {
        $keyword_id = intval($_GET['id'] ?? 0);
        if ($keyword_id <= 0) {
            http_response_code(400);
            out(['status' => 'error', 'message' => 'Invalid keyword id']);
        }
        $kw = requireKeywordOfProject($db, $keyword_id, $tproject_id);
        $payload['keyword'] = kwToJSON($kw);
    }

    if ($mode === 'cfl') {
        $tcversion_id = intval($_GET['tcversion_id'] ?? 0);
        if ($tcversion_id <= 0) {
            http_response_code(400);
            out(['status' => 'error', 'message' => 'Invalid test case version id']);
        }
        $ctx = tcaseVersionContext($db, $tcversion_id, $tproject_id);
        if (is_null($ctx)) {
            http_response_code(404);
            out(['status' => 'error', 'message' => 'Test case version not found']);
        }
        $payload['tcase'] = $ctx;
    }

    out($payload);
}

if ($method !== 'POST') {
    http_response_code(405);
    out(['status' => 'error', 'message' => 'Method not allowed']);
}

$body = getBody();
$tproject_id = intval($body['tproject_id'] ?? 0);
requireKeywordRights($db, $user, $tproject_id);

// legacy initEnv() declared the keyword STRING_N 0..100 and the input parameter
// layer truncated it; keywords.keyword is varchar(100), so an over-long value
// sent straight to the API would be a DB error instead of a truncated name.
$keyword = substr(trim((string)($body['keyword'] ?? '')), 0, 100);
$notes = (string)($body['notes'] ?? '');

// ---------------------------------------------------------------------------
// POST ?action=create — legacy do_create
// ---------------------------------------------------------------------------
if ($action === 'create') {
    $op = $tproject_mgr->addKeyword($tproject_id, $keyword, $notes);
    if ($op['status'] >= tl::OK) {
        out(['status' => 'ok', 'id' => intval($op['id'])]);
    }
    http_response_code(422);
    out([
        'status' => 'error',
        'message' => keywordErrorMessage($op['status']),
        'error_code' => intval($op['status']),
    ]);
}

// ---------------------------------------------------------------------------
// POST ?action=update — legacy do_update
// ---------------------------------------------------------------------------
if ($action === 'update') {
    $keyword_id = intval($body['id'] ?? 0);
    if ($keyword_id <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid keyword id']);
    }
    requireKeywordOfProject($db, $keyword_id, $tproject_id);
    $result = $tproject_mgr->updateKeyword($tproject_id, $keyword_id, $keyword, $notes);
    if ($result >= tl::OK) {
        out(['status' => 'ok', 'id' => $keyword_id]);
    }
    http_response_code(422);
    out([
        'status' => 'error',
        'message' => keywordErrorMessage($result),
        'error_code' => intval($result),
    ]);
}

// ---------------------------------------------------------------------------
// POST ?action=create_link — legacy do_cfl (create + link to tcversion)
// ---------------------------------------------------------------------------
if ($action === 'create_link') {
    $tcversion_id = intval($body['tcversion_id'] ?? 0);
    if ($tcversion_id <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test case version id']);
    }
    $ctx = tcaseVersionContext($db, $tcversion_id, $tproject_id);
    if (is_null($ctx) || $ctx['tcase_id'] <= 0) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Test case version not found']);
    }

    $op = $tproject_mgr->addKeyword($tproject_id, $keyword, $notes);
    if ($op['status'] < tl::OK) {
        http_response_code(422);
        out([
            'status' => 'error',
            'message' => keywordErrorMessage($op['status']),
            'error_code' => intval($op['status']),
        ]);
    }

    $tcaseMgr = new testcase($db);
    $tcaseMgr->addKeywords($ctx['tcase_id'], $tcversion_id, array(intval($op['id'])));

    out([
        'status' => 'ok',
        'id' => intval($op['id']),
        'tcase' => $ctx,
    ]);
}

// ---------------------------------------------------------------------------
// POST ?action=delete — legacy do_delete
// ---------------------------------------------------------------------------
if ($action === 'delete') {
    $keyword_id = intval($body['id'] ?? 0);
    if ($keyword_id <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid keyword id']);
    }
    requireKeywordOfProject($db, $keyword_id, $tproject_id);
    $dko = array('context' => 'getTestProjectName', 'tproject_id' => $tproject_id);
    $result = $tproject_mgr->deleteKeyword($keyword_id, $dko);
    if ($result >= tl::OK) {
        out(['status' => 'ok', 'id' => $keyword_id]);
    }
    http_response_code(422);
    out([
        'status' => 'error',
        'message' => 'Keyword is linked to executed or frozen test case versions',
        'error_code' => 'DELETE_BLOCKED',
        'status_code' => intval($result),
    ]);
}

http_response_code(400);
out(['status' => 'error', 'message' => 'Unknown action']);
