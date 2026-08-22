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
    if (!$user->hasRight($db, 'mgt_view_key')) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }
    $kw = tlKeyword::getByID($db, intval($segments[0]));
    if (!$kw || $kw->dbID <= 0) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Keyword not found']);
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
    if (!$user->hasRight($db, 'mgt_modify_key')) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }
    $body = getBody();
    $tproject_id = intval($body['tproject_id'] ?? 0);
    if ($tproject_id <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test project id']);
    }
    $op = $tproject_mgr->addKeyword($tproject_id, (string)($body['name'] ?? ''), (string)($body['notes'] ?? ''));
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
    if (!$user->hasRight($db, 'mgt_modify_key')) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }
    $body = getBody();
    $tproject_id = intval($body['tproject_id'] ?? 0);
    if ($tproject_id <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test project id']);
    }
    $result = $tproject_mgr->updateKeyword(
        $tproject_id,
        intval($segments[0]),
        (string)($body['name'] ?? ''),
        (string)($body['notes'] ?? '')
    );
    if ($result >= tl::OK) {
        out(['status' => 'ok']);
    }
    http_response_code(422);
    out([
        'status' => 'error',
        'message' => tlKeyword::getErrorMessage($result),
        'error_code' => intval($result),
    ]);
}

// ---------------------------------------------------------------------------
// DELETE /{id}?tproject_id=N - delete keyword (mgt_modify_key)
// Legacy do_delete used checkBeforeDelete semantics via deleteKeyword().
// ---------------------------------------------------------------------------
if ($method === 'DELETE' && isset($segments[0]) && ctype_digit($segments[0])) {
    if (!$user->hasRight($db, 'mgt_modify_key')) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }
    $tproject_id = needTprojectId();
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
    if (!$user->hasRight($db, 'mgt_modify_key')) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }
    $tproject_id = intval($_POST['tproject_id'] ?? 0);
    if ($tproject_id <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test project id']);
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
