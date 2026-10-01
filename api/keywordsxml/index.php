<?php
/**
 * api/keywordsxml — Keyword XML / CSV Export + Import gateway BFF
 * (Refs #1615)
 *
 * Modernizes the last legacy keyword screens without a modern twin: the pair
 *   - lib/keywords/keywordsExport.php  (+ gui/templates/dashio/keywords/keywordsExport.tpl)
 *   - lib/keywords/keywordsImport.php  (+ gui/templates/dashio/keywords/keywordsImport.tpl)
 * which are the keyword counterpart of the already modernized Custom Fields
 * XML gateway (gui/templates/cfields/cfieldsExchange.html, Refs #1487).
 * The modern Keyword Management screen (gui/templates/keywords/keywordsView.html)
 * had NO XML/CSV exchange at all: it could only list, create, edit, delete and
 * assign keywords.
 *
 * Legacy parity — the exact flows of the two controllers:
 *   - export type list  -> tlKeyword::getSupportedSerializationInterfaces()
 *   - export XML        -> testproject::exportKeywordsToXML($tproject_id)
 *   - export CSV        -> getKeywordsEnv(...'csvExport')->kwOnTCV
 *                          + exportKeywordsToCSV() (keyword,notes,tcv_qty)
 *   - import type list  -> getSupportedSerializationInterfaces() +
 *                          getSupportedSerializationFormatDescriptions()
 *   - import XML        -> testproject::importKeywordsFromXMLFile()
 *   - import CSV        -> testproject::importKeywordsFromCSV()
 *   - temp file         -> TL_TEMP_PATH . session_id() . "-importkeywords.<ext>"
 *                          (always unlinked afterwards, exactly as legacy)
 *   - upload size limit -> config_get('import_file_max_size_bytes')
 *
 * Rights (legacy checkRights of each controller, kept split per action):
 *   - export -> mgt_view_key    on the test project
 *   - import -> mgt_modify_key  on the test project
 * Both are evaluated on the project that OWNS the keywords, and the project is
 * proven to exist first (hasRight() falls back to the GLOBAL role rights for
 * an unknown project id, which would otherwise let a global holder export or
 * import into a phantom project).
 *
 * Hardening vs legacy:
 *   - the uploaded file never reaches a legacy path: it is written to a
 *     session-scoped temp name with a server-side extension (never the
 *     client-supplied one), size-capped server side and always unlinked;
 *   - the export download filename is sanitized (no path traversal, no header
 *     injection through the Content-Disposition value);
 *   - the same-origin guard rejects cross-site POSTs, which the legacy
 *     multipart form did not.
 *
 * Routes:
 *   GET  ?action=init&tproject_id=N
 *   GET  ?action=export&tproject_id=N&type=iSerializationToXML|iSerializationToCSV[&filename=]
 *   POST ?action=import   multipart: tproject_id, type, uploadedFile
 *        (or JSON {tproject_id, type, filename, content_base64})
 *
 * Session-based auth, JSON I/O, no Smarty.
 */
require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
require_once('csv.inc.php');
require_once('xml.inc.php');
require_once(__DIR__ . '/../../lib/keywords/keywordsEnv.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

$db = new database(DB_TYPE);
doDBConnect($db);

header('X-Content-Type-Options: nosniff');

$action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');

$userId = $_SESSION['userID'] ?? null;
if (!$userId || $userId <= 0) {
    out(['status' => 'error', 'message' => 'Not authenticated'], 401);
}

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    out(['status' => 'error', 'message' => 'User not found'], 401);
}

/**
 * out() only applies an HTTP status code when one is explicitly given, so a
 * guard/validation branch that already called http_response_code() is not
 * silently reset back to 200 (the api/reports convention, see #1544).
 */
function out($data, $code = null) {
    if (!headers_sent()) {
        if ($code !== null) {
            http_response_code($code);
        }
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($data);
    exit;
}

function getBody() {
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    return is_array($body) ? $body : array();
}

function param($name, $default = null) {
    if (array_key_exists($name, $_POST)) {
        return $_POST[$name];
    }
    if (array_key_exists($name, $_GET)) {
        return $_GET[$name];
    }
    $body = getBody();
    return array_key_exists($name, $body) ? $body[$name] : $default;
}

function testProjectName($db, $tproject_id) {
    $tp = tlObject::getDBTables('testprojects');
    $rs = $db->get_recordset(
        "SELECT id FROM {$tp['testprojects']} WHERE id = " . intval($tproject_id)
    );
    if (is_null($rs) || count($rs) == 0) {
        return null;
    }
    $dm = (new testproject($db))->get_by_id(intval($tproject_id), array('output' => 'name'));
    return is_array($dm) ? (string) ($dm['name'] ?? '') : null;
}

/**
 * Legacy checkRights() per action: mgt_view_key for the export flow,
 * mgt_modify_key for the import flow. Proven on the owning project.
 */
function requireKeywordRight($db, $user, $tproject_id, $right) {
    if ($tproject_id > 0) {
        $name = testProjectName($db, $tproject_id);
        if (is_null($name)) {
            out(['status' => 'error', 'message' => 'Unknown test project'], 404);
        }
    } else {
        out(['status' => 'error', 'message' => 'Invalid test project'], 400);
    }

    // hasRightOnProj() would check the SESSION project, not the target one;
    // $getAccess = true mirrors legacy pageAccessCheck() semantics so a
    // global-only right does not reach a PRIVATE project.
    if (!$user->hasRight($db, $right, intval($tproject_id), null, true)) {
        out(['status' => 'error', 'message' => 'Access denied', 'right' => $right], 403);
    }
    return $name;
}

/** Whitelist for the serialization interface id, default XML like legacy. */
function normalizeType($type) {
    $type = (string) $type;
    if ($type === 'iSerializationToCSV' || $type === 'iSerializationToXML') {
        return $type;
    }
    return 'iSerializationToXML';
}

/** Legacy downloadContentsToFile() header logic, with a safe filename. */
function safeDownloadName($name, $fallback) {
    $name = (string) $name;
    $name = str_replace(array("\r", "\n", '"', '\\', '/'), '', $name);
    $name = trim($name);
    if ($name === '' || $name === '.' || $name === '..') {
        return $fallback;
    }
    // Legacy capped the field with maxlength="{#FILENAME_MAXLEN#}" (255); keep the
    // same bound server-side so a scripted caller cannot push an unbounded name
    // into the Content-Disposition header.
    return substr($name, 0, 255);
}

switch ($action) {
    case 'init':
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            out(['status' => 'error', 'message' => 'Method not allowed'], 405);
        }
        $tproject_id = intval($_GET['tproject_id'] ?? 0);
        $canExport = false;
        $canImport = false;
        $tproject_name = null;
        if ($tproject_id > 0) {
            $tproject_name = testProjectName($db, $tproject_id);
            if (is_null($tproject_name)) {
                out(['status' => 'error', 'message' => 'Unknown test project'], 404);
            }
            $canExport = (bool) $user->hasRight($db, 'mgt_view_key', $tproject_id, null, true);
            $canImport = (bool) $user->hasRight($db, 'mgt_modify_key', $tproject_id, null, true);
            if (!$canExport && !$canImport) {
                // Do not hand the project name / keyword count to a caller that
                // may not touch the project at all (and do not let a 404-vs-200
                // answer be used to enumerate project ids).
                out(['status' => 'error', 'message' => 'Access denied'], 403);
            }
        }

        $kw = new tlKeyword();
        $tprojectMgr = new testproject($db);
        out(array(
            'status' => 'ok',
            'tproject_id' => $tproject_id,
            'tproject_name' => $tproject_name,
            'keyword_count' => $tproject_id > 0 ? keywordCount($db, $tproject_id) : 0,
            'exportTypes' => serializationInterfaces($kw),
            'importTypes' => serializationInterfaces($kw),
            'formatDescriptions' => (object) $kw->getSupportedSerializationFormatDescriptions(),
            'rights' => array('export' => $canExport, 'import' => $canImport),
            'limits' => array(
                // Never advertise more than PHP accepts: an oversize POST is
                // discarded by PHP before the BFF can answer, and the user must
                // not be told about a limit the runtime cannot honour.
                'import_file_max_size_bytes' => importCapBytes(),
            ),
            'default_filename' => 'keywords.xml',
        ));
    break;

    case 'export':
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            out(['status' => 'error', 'message' => 'Method not allowed'], 405);
        }
        $tproject_id = intval($_GET['tproject_id'] ?? 0);
        requireKeywordRight($db, $user, $tproject_id, 'mgt_view_key');

        $type = normalizeType($_GET['type'] ?? 'iSerializationToXML');
        $ext = exportExtension($type);
        $filename = safeDownloadName($_GET['filename'] ?? '', 'keywords.' . $ext);
        if (strpos($filename, '.') === false) {
            $filename .= '.' . $ext;
        }

        // exportKeywordsToXML() calls sizeof() on getKeywordIDsFor(), which
        // returns null for a project without keywords -> uncaught TypeError (500)
        // and exportKeywordsToCSV() logs an E_WARNING on the same null. Refuse
        // the empty project up front instead.
        if (keywordCount($db, $tproject_id) === 0) {
            out(['status' => 'error', 'message' => 'no_keywords_to_export'], 400);
        }

        $tprojectMgr = new testproject($db);
        if ($type === 'iSerializationToXML') {
            $content = $tprojectMgr->exportKeywordsToXML($tproject_id);
        } else {
            $cu = getKeywordsEnv($db, $user, $tproject_id, array('usage' => 'csvExport'));
            $content = exportKeywordsToCSV($cu->kwOnTCV);
        }

        if (is_null($content) || $content === '') {
            out(['status' => 'error', 'message' => 'No keywords to export'], 400);
        }

        $charSet = config_get('charset');
        header('Content-Type: ' . ($type === 'iSerializationToXML' ? 'text/xml' : 'text/csv')
            . "; charset={$charSet}; name={$filename}");
        header('Content-Transfer-Encoding: BASE64;');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo $content;
        exit;
    break;

    case 'import':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            out(['status' => 'error', 'message' => 'Method not allowed'], 405);
        }
        $tproject_id = intval(param('tproject_id', 0));
        requireKeywordRight($db, $user, $tproject_id, 'mgt_modify_key');

        $type = normalizeType(param('type', 'iSerializationToXML'));
        $ext = importExtension($type);
        $limit = importCapBytes();

        $tmpFile = null;
        $cleanup = false;
        $fInfo = isset($_FILES['uploadedFile']) ? $_FILES['uploadedFile'] : null;

        // A file rejected by PHP itself (over upload_max_filesize / post_max_size)
        // arrives with an empty tmp_name, so the error code has to be inspected
        // BEFORE the "is there a file at all" test - otherwise the user is told to
        // pick a file although they did (legacy read $_FILES[...]['error'] too).
        if (!is_null($fInfo) && isset($fInfo['error']) && $fInfo['error'] != UPLOAD_ERR_OK) {
            $uploadError = getFileUploadErrorMessage($fInfo);
            if (is_null($uploadError) || $uploadError === '') {
                $uploadError = 'please_choose_keywords_file';
            }
            out(['status' => 'error', 'message' => $uploadError, 'code' => 'upload_error'], 400);
        }

        if (!is_null($fInfo) && isset($fInfo['tmp_name']) && $fInfo['tmp_name'] !== '') {
            if ($limit > 0 && filesize($fInfo['tmp_name']) > $limit) {
                out(['status' => 'error', 'message' => 'File too large'], 413);
            }
            // Server-side extension, session-scoped name: the client filename
            // never influences the temp path (legacy used the same pattern).
            $tmpFile = TL_TEMP_PATH . session_id() . "-importkeywords." . $ext;
            $cleanup = true;
            if (!@copy($fInfo['tmp_name'], $tmpFile)) {
                out(['status' => 'error', 'message' => 'Cannot store upload'], 500);
            }
        } else {
            $body = getBody();
            if (!empty($body['content_base64'])) {
                $raw = base64_decode((string) $body['content_base64'], true);
                if ($raw === false || $raw === '') {
                    out(['status' => 'error', 'message' => 'Empty file content'], 400);
                }
                if ($limit > 0 && strlen($raw) > $limit) {
                    out(['status' => 'error', 'message' => 'File too large'], 413);
                }
                $tmpFile = TL_TEMP_PATH . session_id() . "-importkeywords." . $ext;
                $cleanup = true;
                if (@file_put_contents($tmpFile, $raw) === false) {
                    out(['status' => 'error', 'message' => 'Cannot store upload'], 500);
                }
            }
        }

        if (is_null($tmpFile)) {
            out(['status' => 'error', 'message' => 'please_choose_keywords_file', 'code' => 'please_choose_keywords_file'], 400);
        }

        $tproject = new testproject($db);
        $before = keywordCount($db, $tproject_id);
        $stats = array('rows' => 0, 'imported' => 0, 'skipped' => 0, 'errors' => array());
        if ($type === 'iSerializationToXML') {
            // Refs #1666: $stats carries the real per-row report of the XML arm
            // (rows / imported / skipped / errors[]), the same shape the CSV arm
            // reports since #1605.
            $result = $tproject->importKeywordsFromXMLFile($tproject_id, $tmpFile, $stats);
        } else {
            // Refs #1605: the out-param gives the dialog the real per-row
            // report (imported / skipped / errors[]) instead of a bare "ok".
            $result = $tproject->importKeywordsFromCSV($tproject_id, $tmpFile, ';', $stats);
        }
        $after = keywordCount($db, $tproject_id);
        if ($cleanup && is_file($tmpFile)) {
            @unlink($tmpFile);
        }

        // Captured BEFORE the guard below overwrites it: "the file was readable"
        // is what decides whether an empty/fully-rejected CSV is a row problem
        // or a genuinely unreadable file.
        $fileWasReadable = ($result == tl::OK);

        if ($result == tl::OK && $stats['imported'] <= 0
            && ($type === 'iSerializationToXML' ? $stats['rows'] <= 0 : $after === $before)) {
            // importKeywordsFromCSV() returns tl::OK as soon as fopen() on the
            // temp file succeeds, so a non-CSV file is "imported" with zero rows
            // and the screen would report success. Nothing landed => wrong file.
            //
            // Refs #1666: the XML arm used to be judged by the keyword COUNT
            // DELTA ($after === $before) for both arms, which cannot see an
            // import that updates rows in place: re-importing the project's own
            // export leaves the count flat, so a valid file was rejected as
            // 'wrong_keywords_file'. The XML arm is now gated on the rows the
            // importer actually reported ($stats['rows'] - a <keywords/> document
            // without a single <keyword> child), so only a file that yielded
            // nothing at all is refused. The CSV arm keeps its own signal
            // (unchanged semantics, #1605).
            $result = tl::ERROR;
        }

        if ($result != tl::OK) {
            // Refs #1605: "wrong_keywords_file" was all the user ever got, even
            // when the file was perfectly readable and every row was rejected
            // for a named reason. Name the actual outcome so the dialog can say
            // whether the file was empty, or every row failed. Gated on
            // $fileWasReadable so an unreadable file (fopen() failed) keeps the
            // legacy code, which is the accurate one there. XML keeps the legacy
            // code on purpose: this branch is reached for a document without any
            // usable <keyword> row (empty document, parse failure, or a row-level
            // rejection that the legacy $status still folds into the file verdict
            // - see #1784), and EMPTY_FILE / NO_KEYWORDS_IMPORTED would both be
            // wrong there. Rows that were read but only skipped are NOT an error
            // for XML: they travel back in the 200 payload below.
            $code = 'wrong_keywords_file';
            if ($type !== 'iSerializationToXML' && $fileWasReadable) {
                $code = ($stats['rows'] > 0) ? 'NO_KEYWORDS_IMPORTED' : 'EMPTY_FILE';
            }
            out(array(
                'status' => 'error',
                'message' => 'wrong_keywords_file',
                'code' => $code,
                'result' => $result,
                'imported' => $stats['imported'],
                'skipped' => $stats['skipped'],
                'rows' => $stats['rows'],
                'errors' => importErrorRows($stats['errors']),
            ), 400);
        }

        out(array(
            'status' => 'ok',
            'tproject_id' => $tproject_id,
            'keyword_count' => keywordCount($db, $tproject_id),
            'imported' => $stats['imported'],
            'skipped' => $stats['skipped'],
            'rows' => $stats['rows'],
            'errors' => importErrorRows($stats['errors']),
        ));
    break;

    default:
        out(['status' => 'error', 'message' => 'Unknown action'], 400);
}

/** Serialization interfaces as [{id, ext}] for the client selects. */
function serializationInterfaces($kw) {
    $out = array();
    foreach ((array) $kw->getSupportedSerializationInterfaces() as $id => $ext) {
        $out[] = array('id' => $id, 'ext' => $ext);
    }
    return $out;
}

function exportExtension($type) {
    $ext = (new tlKeyword())->getSupportedSerializationInterfaces();
    return isset($ext[$type]) ? $ext[$type] : 'xml';
}

function importExtension($type) {
    return exportExtension($type);
}

/** Effective upload cap: TestLink config clamped to the PHP runtime limits. */
function importCapBytes() {
    $limit = intval(config_get('import_file_max_size_bytes'));
    if ($limit <= 0) {
        $limit = 1048576;
    }
    foreach (array('upload_max_filesize', 'post_max_size') as $directive) {
        // ini_get() returns the shorthand form ('2M'), so it has to be expanded.
        $raw = parse_size((string) ini_get($directive));
        if ($raw > 0 && $raw < $limit) {
            $limit = $raw;
        }
    }
    return max(1, $limit);
}

/** '10M' / '2048K' / plain bytes -> bytes. */
function parse_size($value) {
    $value = trim((string) $value);
    if ($value === '') {
        return 0;
    }
    $unit = strtolower(substr($value, -1));
    $number = (float) $value;
    switch ($unit) {
        case 'g': return intval($number * 1024 * 1024 * 1024);
        case 'm': return intval($number * 1024 * 1024);
        case 'k': return intval($number * 1024);
        default:  return intval($number);
    }
}

/**
 * Row-level failure list: the importer reports the raw tlKeyword::E_*
 * constant, this maps it onto a stable short code the client translates with
 * the kwedit.err* / kwxml.errRow* i18n keys.
 */
function importErrorRows($errors) {
    $map = array(
        (int)tlKeyword::E_NAMENOTALLOWED => 'CHAR_NOT_ALLOWED',
        (int)tlKeyword::E_NAMELENGTH => 'EMPTY_NAME',
        (int)tlKeyword::E_NAMEALREADYEXISTS => 'ALREADY_EXISTS',
        (int)tlKeyword::E_DBERROR => 'DB_ERROR',
        (int)tlKeyword::E_WRONGFORMAT => 'WRONG_FORMAT',
    );
    $out = array();
    foreach ((array)$errors as $e) {
        $code = isset($e['code']) ? intval($e['code']) : 0;
        $out[] = array(
            'row' => isset($e['row']) ? intval($e['row']) : 0,
            'code' => isset($map[$code]) ? $map[$code] : 'REJECTED',
            'name' => isset($e['name']) ? (string)$e['name'] : '',
        );
    }
    return $out;
}

/** keywordCount(): testproject::getKeywordIDsFor() is protected, count directly. */
function keywordCount($db, $tproject_id) {
    $t = tlObject::getDBTables('keywords');
    $rs = $db->get_recordset(
        "SELECT COUNT(id) AS qty FROM {$t['keywords']} WHERE testproject_id = " . intval($tproject_id)
    );
    return is_null($rs) || count($rs) == 0 ? 0 : intval($rs[0]['qty']);
}

/** Legacy exportKeywordsToCSV() of keywordsExport.php. */
function exportKeywordsToCSV($kwSet) {
    $keys = array("keyword", "notes", "tcv_qty");
    return exportDataToCSV($kwSet, $keys, $keys, array('addHeader' => 1));
}
