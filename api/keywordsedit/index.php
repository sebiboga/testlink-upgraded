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
function keywordErrorMessage($code) {
    switch (intval($code)) {
        case tlKeyword::E_NAMENOTALLOWED:
            return 'Keywords: character not allowed.';
        case tlKeyword::E_NAMELENGTH:
            return 'Keyword name cannot be empty.';
        case tlKeyword::E_NAMEALREADYEXISTS:
            return 'Keyword already exists.';
        case tlKeyword::E_DBERROR:
        case ERROR:
            return 'Unable to process keyword update.';
    }
    return 'ok';
}

function kwToJSON($kw) {
    return [
        'id' => intval($kw->dbID),
        'name' => (string)$kw->name,
        'notes' => (string)$kw->notes,
    ];
}

/**
 * Resolve the test case version context used by the create-and-link flow.
 * Mirrors legacy do_cfl(): nodes_hierarchy.parent_id of the tcversion is the
 * owning test case.
 */
function tcaseVersionContext($db, $tcversion_id) {
    $tbl = tlObject::getDBTables('nodes_hierarchy');
    $sql = "SELECT id, parent_id FROM {$tbl['nodes_hierarchy']} WHERE id = " . intval($tcversion_id);
    $rs = $db->get_recordset($sql);
    if (is_null($rs) || count($rs) == 0) {
        return null;
    }
    $tcase_id = intval($rs[0]['parent_id']);
    $tcaseName = '';
    if ($tcase_id > 0) {
        $tcaseName = testcase::getName($db, $tcase_id);
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
        $kw = tlKeyword::getByID($db, $keyword_id);
        if (is_null($kw) || $kw->dbID <= 0) {
            http_response_code(404);
            out(['status' => 'error', 'message' => 'Keyword not found']);
        }
        $payload['keyword'] = kwToJSON($kw);
    }

    if ($mode === 'cfl') {
        $tcversion_id = intval($_GET['tcversion_id'] ?? 0);
        if ($tcversion_id <= 0) {
            http_response_code(400);
            out(['status' => 'error', 'message' => 'Invalid test case version id']);
        }
        $ctx = tcaseVersionContext($db, $tcversion_id);
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

$keyword = (string)($body['keyword'] ?? '');
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
    $ctx = tcaseVersionContext($db, $tcversion_id);
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
