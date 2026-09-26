<?php
/**
 * Keywords BFF API
 * URL: /api/keywords/
 * Plain PHP, no framework, no compilation
 *
 * Mirrors lib/keywords/keywordsView.php + keywordsEdit.php +
 * keywordsExport.php + keywordsImport.php (TestLink 1.9.20 behavior).
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
require_once('csv.inc.php');
require_once('xml.inc.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();


header('Content-Type: application/json');

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || $userId <= 0) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'User not found']);
    exit;
}

$path = $_SERVER['PATH_INFO'] ?? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = preg_replace('#^/api/keywords(/index\.php)?#', '', $path);
$path = '/' . trim($path, '/');
$method = $_SERVER['REQUEST_METHOD'];
$segments = array_values(array_filter(explode('/', $path)));

function out($data) { echo json_encode($data); exit; }
function getParam($key, $default = null) { return $_GET[$key] ?? $default; }
function getBody() { return json_decode(file_get_contents('php://input'), true) ?? []; }

$tproject_mgr = new testproject($db);

// Rights checks - view needs mgt_view_key, manage needs mgt_modify_key
// (same split as the legacy screens).
function needTprojectId() {
    $id = intval($_GET['tproject_id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test project id']);
    }
    return $id;
}

/**
 * Refs #1599 (fixes #1008): legacy lib/keywords/keywordsEdit.php::initEnv()
 * performed an AND-mode rights check on the test project
 * (mgt_modify_key AND mgt_view_key) before ANY keyword write. The modern BFF
 * used to check only the global mgt_modify_key right, so a project-scoped role
 * holding mgt_modify_key but not mgt_view_key could still write keywords.
 */
/**
 * Refs #1600: tlKeyword::getErrorMessage() no longer exists after the 2.0.1
 * refactor (it became tlKeyword::getError(), which returns the symbolic code).
 * Localized mapping, legacy parity with lib/keywords/keywordsEdit.php::
 * getKeywordErrorMessage() and the tlKeyword::E_* codes.
 */
/**
 * Legacy parity: initEnv() declared the keyword STRING_N 0..100 and the input
 * parameter layer truncated it with tlSubStr($value, 0, 100).
 */
function kwTrimName($name) {
    if (is_array($name)) {
        return '';
    }
    return substr(trim((string)$name), 0, 100);
}

function kwErrorMessage($code) {
    switch (intval($code)) {
        case tlKeyword::E_NAMENOTALLOWED:
            return lang_get('keywords_char_not_allowed');
        case tlKeyword::E_NAMELENGTH:
            return lang_get('empty_keyword_no');
        case tlKeyword::E_NAMEALREADYEXISTS:
            return lang_get('keyword_already_exists');
        case tlKeyword::E_WRONGFORMAT:
        case tlKeyword::E_DBERROR:
        default:
            return lang_get('kw_update_fails');
    }
}

/**
 * Refs #1601: rights are checked for the tproject_id supplied by the caller,
 * but the keyword is addressed by a bare id, and neither
 * tlKeyword::writeToDB() (UPDATE ... WHERE id = X) nor
 * testproject::deleteKeyword() (id only) re-checks testproject_id. Without
 * this guard a user with keyword rights in project A could rename, re-own or
 * delete a keyword of project B. 404 (never 403) so the existence of a
 * foreign keyword is not disclosed.
 */
function requireKeywordOfProject($db, $keyword_id, $tproject_id) {
    $kw = tlKeyword::getByID($db, $keyword_id);
    if (is_null($kw) || $kw->dbID <= 0 || intval($kw->testprojectID) !== intval($tproject_id)) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Keyword not found', 'error_code' => 'KW_NOT_FOUND']);
    }
    return $kw;
}

/**
 * Refs #1599 / #1008: the legacy keywordsEdit.php worked in AND mode at test
 * project level - mgt_modify_key AND mgt_view_key. This BFF only required
 * mgt_modify_key, so a *view-only* keyword manager could POST a write; every
 * write route (single keyword + import) now goes through this gate.
 * Refs #1601: also applied before delete/update, see requireKeywordOfProject().
 */
function kwWriteRights($user, $db, $tproject_id) {
    return (bool)$user->hasRight($db, 'mgt_modify_key', $tproject_id)
        && (bool)$user->hasRight($db, 'mgt_view_key', $tproject_id);
}

function kwToJSON($kw) {
    // tlKeyword object or stdClass row from getByIDs()
    return [
        'id' => intval($kw->dbID),
        'name' => $kw->name,
        'notes' => (string)$kw->notes,
    ];
}

// ---------------------------------------------------------------------------
// GET /{id} - single keyword (mgt_view_key)
// ---------------------------------------------------------------------------
if ($method === 'GET' && isset($segments[0]) && ctype_digit($segments[0]) && !isset($segments[1])) {
    // Refs #1604: this route used to be gated on the GLOBAL mgt_view_key only
    // and returned any keyword of the installation, so a global holder could
    // read the name+notes of keywords of projects they are not a member of -
    // the read-side twin of the #1601 write IDOR. The keyword's owning project
    // is now taken from the row itself and the right is checked on it.
    $kw = tlKeyword::getByID($db, intval($segments[0]));
    if (!$kw || $kw->dbID <= 0) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Keyword not found', 'error_code' => 'KW_NOT_FOUND']);
    }
    $kwTprojectId = intval($kw->testprojectID);
    if ($kwTprojectId <= 0 || !$user->hasRight($db, 'mgt_view_key', $kwTprojectId)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission', 'error_code' => 'NO_RIGHT']);
    }
    out(['status' => 'ok', 'item' => kwToJSON($kw)]);
}

// ---------------------------------------------------------------------------
// GET /?tproject_id=N - list with usage counts + delete-block status + rights
// ---------------------------------------------------------------------------
if ($method === 'GET' && count($segments) === 0) {
    $tproject_id = needTprojectId();
    if (!$user->hasRight($db, 'mgt_view_key', $tproject_id)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }

    $keywords = $tproject_mgr->getKeywords($tproject_id);
    $items = [];
    if (!is_null($keywords)) {
        // usage counts (how many tcversions use each keyword)
        $usage = (array)$tproject_mgr->countKeywordUsageInTCVersions($tproject_id);

        // delete blockers, only when configured like the legacy screen
        $kwCfg = config_get('keywords');
        $execStatus = null;
        $freshStatus = null;
        $kws = [];
        foreach ($keywords as $kwo) { $kws[] = $kwo->dbID; }
        if ($kwCfg->onDeleteCheckExecutedTCVersions) {
            $execStatus = $tproject_mgr->getKeywordsExecStatus($kws, $tproject_id);
        }
        if ($kwCfg->onDeleteCheckFrozenTCVersions) {
            $freshStatus = $tproject_mgr->getKeywordsFreezeStatus($kws, $tproject_id);
        }

        foreach ($keywords as $kwo) {
            $kid = $kwo->dbID;
            $executed = false;
            $frozen = false;
            if ($execStatus && isset($execStatus[$kid]) && $execStatus[$kid]['exec_or_not'] == 'EXECUTED') {
                $executed = true;
            }
            if ($freshStatus && isset($freshStatus[$kid]) && $freshStatus[$kid]['fresh_or_frozen'] == 'FROZEN') {
                $frozen = true;
            }
            $items[] = [
                'id' => intval($kid),
                'name' => $kwo->name,
                'notes' => (string)$kwo->notes,
                'tcv_qty' => isset($usage[$kid]) ? intval($usage[$kid]['tcv_qty']) : 0,
                'deletable' => !($executed || $frozen),
                'block_reason' => $executed ? 'EXECUTED' : ($frozen ? 'FROZEN' : null),
            ];
        }
    }

    out([
        'status' => 'ok',
        'items' => $items,
        'tproject' => ['id' => $tproject_id, 'name' => testproject::getName($db, $tproject_id)],
        'rights' => [
            'canManage' => (bool)$user->hasRight($db, 'mgt_modify_key', $tproject_id),
            'canAssign' => (bool)$user->hasRight($db, 'keyword_assignment', $tproject_id),
        ],
    ]);
}

// ---------------------------------------------------------------------------
// POST / - create keyword (mgt_modify_key)
// ---------------------------------------------------------------------------
if ($method === 'POST' && count($segments) === 0) {
    $body = getBody();
    $tproject_id = intval($body['tproject_id'] ?? 0);
    if ($tproject_id <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test project id']);
    }
    // Refs #1599 (fixes #1008): legacy keywordsEdit.php gated every write with
    // an AND-mode project-scoped check on mgt_modify_key + mgt_view_key.
    if (!kwWriteRights($user, $db, $tproject_id)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission', 'error_code' => 'NO_RIGHT']);
    }
    // legacy initEnv() declared the keyword as STRING_N 0..100 and
    // inputparameter.class.php truncated it; keywords.keyword is varchar(100),
    // so an over-long value from a direct API call would be a DB error
    $op = $tproject_mgr->addKeyword($tproject_id, kwTrimName($body['name'] ?? ''), (string)($body['notes'] ?? ''));
    if ($op['status'] >= tl::OK) {
        out(['status' => 'ok', 'id' => intval($op['id'])]);
    }
    http_response_code(422);
    out(['status' => 'error', 'message' => $op['msg'], 'error_code' => intval($op['status'])]);
}

// ---------------------------------------------------------------------------
// PUT /{id} - update keyword (mgt_modify_key)
// ---------------------------------------------------------------------------
if ($method === 'PUT' && isset($segments[0]) && ctype_digit($segments[0])) {
    $body = getBody();
    $tproject_id = intval($body['tproject_id'] ?? 0);
    if ($tproject_id <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test project id']);
    }
    if (!kwWriteRights($user, $db, $tproject_id)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission', 'error_code' => 'NO_RIGHT']);
    }
    requireKeywordOfProject($db, intval($segments[0]), $tproject_id);
    $result = $tproject_mgr->updateKeyword(
        $tproject_id,
        intval($segments[0]),
        kwTrimName($body['name'] ?? ''),
        (string)($body['notes'] ?? '')
    );
    if ($result >= tl::OK) {
        out(['status' => 'ok']);
    }
    http_response_code(422);
    out([
        'status' => 'error',
        'message' => kwErrorMessage($result),
        'error_code' => intval($result),
    ]);
}

// ---------------------------------------------------------------------------
// DELETE /{id}?tproject_id=N - delete keyword (mgt_modify_key)
// Legacy do_delete used checkBeforeDelete semantics via deleteKeyword().
// ---------------------------------------------------------------------------
if ($method === 'DELETE' && isset($segments[0]) && ctype_digit($segments[0])) {
    $tproject_id = needTprojectId();
    if (!kwWriteRights($user, $db, $tproject_id)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission', 'error_code' => 'NO_RIGHT']);
    }
    requireKeywordOfProject($db, intval($segments[0]), $tproject_id);
    $dko = array('context' => 'getTestProjectName', 'tproject_id' => $tproject_id);
    $result = $tproject_mgr->deleteKeyword(intval($segments[0]), $dko);
    if ($result >= tl::OK) {
        out(['status' => 'ok']);
    }
    http_response_code(422);
    out(['status' => 'error', 'message' => 'Keyword is linked to executed or frozen test case versions',
         'error_code' => 'DELETE_BLOCKED']);
}

// ---------------------------------------------------------------------------
// GET /export?tproject_id=N&type=xml|csv&filename=... (mgt_view_key)
// Same output flavors as legacy keywordsExport.php:
//   xml -> <keywords>...</keywords> document
//   csv -> header "keyword,notes,tcv_qty" + one row per keyword (usage counts)
// ---------------------------------------------------------------------------
if ($method === 'GET' && isset($segments[0]) && $segments[0] === 'export') {
    $tproject_id = needTprojectId();
    if (!$user->hasRight($db, 'mgt_view_key', $tproject_id)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }
    $type = getParam('type', 'xml');
    $filename = basename(getParam('filename', $type === 'csv' ? 'keywords.csv' : 'keywords.xml'));
    if ($filename === '') {
        $filename = $type === 'csv' ? 'keywords.csv' : 'keywords.xml';
    }

    if ($type === 'csv') {
        // same data set as legacy exportKeywordsToCSV(kwOnTCV) in keywordsExport.php
        $env = new stdClass();
        $env->keywords = $tproject_mgr->getKeywords($tproject_id);
        $content = '';
        if (!is_null($env->keywords)) {
            $kwNames = [];
            $kwNotes = [];
            $kws = [];
            foreach ($env->keywords as $kwo) {
                $kws[] = $kwo->dbID;
                $kwNames[$kwo->dbID] = $kwo->name;
                $kwNotes[$kwo->dbID] = $kwo->notes;
            }
            $usage = (array)$tproject_mgr->countKeywordUsageInTCVersions($tproject_id);
            foreach ($usage as $kk => $dummy) {
                $usage[$kk]['keyword'] = $kwNames[$kk];
                $usage[$kk]['notes'] = $kwNotes[$kk];
            }
            $keys = ['keyword', 'notes', 'tcv_qty'];
            $content = exportDataToCSV($usage, $keys, $keys, ['addHeader' => 1]);
        }
        $mime = 'text/csv';
    } else {
        $content = $tproject_mgr->exportKeywordsToXML($tproject_id);
        $mime = 'application/xml';
    }

    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($content));
    echo $content;
    exit;
}

// ---------------------------------------------------------------------------
// POST /import - multipart upload (mgt_modify_key)
// fields: tproject_id, type=xml|csv, uploadedFile
// ---------------------------------------------------------------------------
if ($method === 'POST' && isset($segments[0]) && $segments[0] === 'import') {
    $tproject_id = intval($_POST['tproject_id'] ?? 0);
    if ($tproject_id <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test project id']);
    }
    // Refs #1604: a bulk keyword import is a write, so it has to obey the very
    // same AND-mode gate as the single-keyword writes (#1008) instead of the
    // global mgt_modify_key only.
    if (!kwWriteRights($user, $db, $tproject_id)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission', 'error_code' => 'NO_RIGHT']);
    }

    $type = $_POST['type'] ?? 'xml';
    if (!in_array($type, ['xml', 'csv'], true)) {
        $type = 'xml';
    }

    $fInfo = $_FILES['uploadedFile'] ?? null;
    if (is_null($fInfo) || $fInfo['error'] == UPLOAD_ERR_NO_FILE) {
        http_response_code(422);
        out(['status' => 'error', 'message' => 'Please choose a keywords file', 'error_code' => 'NO_FILE']);
    }
    if ($fInfo['error'] != UPLOAD_ERR_OK) {
        http_response_code(422);
        out(['status' => 'error', 'message' => 'File upload failed', 'error_code' => 'UPLOAD_ERROR']);
    }
    $maxSize = config_get('import_file_max_size_bytes');
    if ($fInfo['size'] > $maxSize) {
        http_response_code(422);
        out(['status' => 'error', 'message' => 'File too large', 'error_code' => 'TOO_LARGE']);
    }

    $ext = $type === 'csv' ? 'csv' : 'xml';
    $dest = TL_TEMP_PATH . session_id() . '-importkeywords.' . $ext;
    if (!move_uploaded_file($fInfo['tmp_name'], $dest)) {
        http_response_code(500);
        out(['status' => 'error', 'message' => 'Could not store uploaded file']);
    }

    $pfn = $type === 'csv' ? 'importKeywordsFromCSV' : 'importKeywordsFromXMLFile';
    $result = $tproject_mgr->$pfn($tproject_id, $dest);
    @unlink($dest);

    if ($result != tl::OK) {
        http_response_code(422);
        out(['status' => 'error', 'message' => 'Wrong keywords file', 'error_code' => 'WRONG_FORMAT']);
    }
    out(['status' => 'ok']);
}

http_response_code(404);
out(['status' => 'error', 'message' => 'Not found']);
