<?php
/**
 * api/platformsimport — Import Platforms from an XML file BFF
 *
 * Modernizes the standalone legacy import screen lib/platforms/platformsImport.php
 * (+ gui/templates/dashio/platforms/platformsImport.tpl). The Import button of
 * the modern Platforms Management screen (gui/templates/platforms/platformsView.html)
 * used to open an inline modal wired to POST /import on api/platforms/index.php,
 * which answers counts only; the legacy page — and every deep link to it — is
 * still a live Smarty render, and lib/functions/common.php had **no**
 * $actions->platformsImport (only platformsExport got a link switch in #1583).
 *
 * This BFF is the dedicated, deep-linkable twin of that page and it restores the
 * full legacy reporting contract: platformsImport.php printed one line per
 * imported/updated platform (lang_get('platform_imported'|'platform_updated')) and
 * one 'bad_line_skipped' line per <platform> node without a <name>, all built by
 * server-side lang_get() and therefore only translatable in the PHP locale. The
 * modern screen localizes client-side from TLi18n, so the per-platform messages
 * are returned here as {code, name} pairs and rendered through the i18n module.
 *
 * Legacy parity (lib/platforms/platformsImport.php):
 *   - testlinkInitPage($db,false,false,"checkRights") with
 *     checkRights() = $user->hasRightOnProj($db,"platform_management")
 *   - tproject_id must resolve to a real test project (init_args threw otherwise)
 *   - form fields: importType (XML only), targetFilename (file),
 *     MAX_FILE_SIZE = config_get('import_file_max_size_bytes'), doAction
 *   - doImport(): create-or-update-by-name — a <platform> whose trimmed <name>
 *     already exists in the project is UPDATED (notes, enable_on_design,
 *     enable_on_execution, is_open), otherwise it is CREATED
 *   - <platform> nodes without a <name> child are reported as 'bad_line_skipped'
 *   - a file that is not well-formed XML => lang_get('problems_loading_xml_content')
 *   - the temp copy is written as TL_TEMP_PATH . session_id() . "-import_platforms.tmp"
 *
 * Hardening over legacy (all failures were raw echoes / silent no-ops there):
 *   - the temp file is unlinked in a finally block, so a failed parse or a fatal
 *     in the import loop cannot leave a per-session copy of the upload behind
 *   - LIBXML_NONET is set explicitly (http://websec.io/2012/08/27/Preventing-XXE-in-PHP.html);
 *     libxml errors are collected so the client can show *why* the file was rejected
 *   - the upload cap is enforced server-side from config_get(), not only by the
 *     hidden MAX_FILE_SIZE input, and answers 413 instead of a generic message
 *   - every other PHP upload error (INI_SIZE, PARTIAL, TMPDIR, CANT_WRITE ...)
 *     is mapped to a distinct error_code so the screen can localize it
 *   - the 500 guard answers JSON even when a fatal happens after output started
 *
 * Routes:
 *   GET  ?action=init&tproject_id=N[&tplan_id=M]
 *   POST ?action=import   multipart: tproject_id, tplan_id, uploadedFile
 *
 * Session-based auth, JSON I/O, no Smarty.
 */
require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
require_once('xml.inc.php');

// Any fatal that happens after this point must still answer JSON, never a
// half-rendered page (same pattern as api/platformsexport).
$GLOBALS['platformImportResponseStarted'] = false;
ob_start();
register_shutdown_function(function () {
    if ($GLOBALS['platformImportResponseStarted']) {
        return;
    }
    $status = http_response_code();
    if (is_int($status) && $status >= 400) {
        return;
    }
    $error = error_get_last();
    $fatalTypes = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);
    $captured = (string)ob_get_contents();
    if (!in_array($error['type'] ?? null, $fatalTypes, true) && trim($captured) === '') {
        return;
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode(array(
        'status' => 'error',
        'error_code' => 'SERVER_ERROR',
        'message' => 'Unable to import platforms',
    ));
});

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

$dbResult = doDBConnect($db);
if (empty($dbResult['status'])) {
    pimJson(500, array(
        'status' => 'error',
        'error_code' => 'DATABASE_UNAVAILABLE',
        'message' => 'Database unavailable',
    ));
}

function pimJson($status, $payload)
{
    $GLOBALS['platformImportResponseStarted'] = true;
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = '';
if (isset($_GET['action']) && is_scalar($_GET['action'])) {
    $action = trim((string)$_GET['action']);
} elseif (isset($_POST['action']) && is_scalar($_POST['action'])) {
    // Multipart bodies carry the action in the POST part; a JSON body in the
    // php://input stream is read here too so the screen may use either.
    $action = trim((string)$_POST['action']);
    if ($action === '') {
        $raw = json_decode((string)file_get_contents('php://input'), true);
        if (is_array($raw) && isset($raw['action']) && is_scalar($raw['action'])) {
            $action = trim((string)$raw['action']);
        }
    }
}

$userId = isset($_SESSION['userID']) ? intval($_SESSION['userID']) : 0;
if ($userId <= 0) {
    pimJson(401, array('status' => 'error', 'message' => 'Not authenticated'));
}

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    pimJson(401, array('status' => 'error', 'message' => 'User not found'));
}

/**
 * Resolve + validate the test project id. Legacy init_args() pulled tproject_id
 * from the request and threw when it was 0; unknown ids are answered 404 so the
 * screen can tell "no project in context" from "that project is gone".
 */
function pimProjectId()
{
    $raw = null;
    foreach (array($_GET, $_POST) as $bag) {
        if (isset($bag['tproject_id']) && is_scalar($bag['tproject_id'])) {
            $raw = trim((string)$bag['tproject_id']);
            break;
        }
    }
    if ($raw === null || $raw === '' || !ctype_digit($raw)) {
        pimJson(400, array(
            'status' => 'error',
            'error_code' => 'INVALID_TPROJECT_ID',
            'message' => 'Invalid test project id',
        ));
    }
    $id = intval($raw);
    if ($id <= 0) {
        pimJson(400, array(
            'status' => 'error',
            'error_code' => 'INVALID_TPROJECT_ID',
            'message' => 'Invalid test project id',
        ));
    }
    return $id;
}

function pimTplanId()
{
    foreach (array($_GET, $_POST) as $bag) {
        if (isset($bag['tplan_id']) && is_scalar($bag['tplan_id'])) {
            $raw = trim((string)$bag['tplan_id']);
            if ($raw !== '' && ctype_digit($raw) && intval($raw) > 0) {
                return intval($raw);
            }
        }
    }
    return 0;
}

function pimProject($db, $tprojectId)
{
    $project = (new testproject($db))->get_by_id($tprojectId);
    if (!is_array($project) || !isset($project['id'])) {
        pimJson(404, array(
            'status' => 'error',
            'error_code' => 'TEST_PROJECT_NOT_FOUND',
            'message' => 'Test project not found',
        ));
    }
    return $project;
}

/**
 * Legacy checkRights(): hasRightOnProj($db,"platform_management").
 *
 * hasRight() falls back to the GLOBAL role rights for an unknown project id, so
 * a global holder would otherwise pass with a bogus tproject_id — the project
 * existence is proven before this runs (pimProject).
 */
function pimCanManage($db, $user, $tprojectId)
{
    return $user->hasRight($db, 'platform_management', $tprojectId);
}

function pimPlatformMgr($db, $tprojectId)
{
    return new tlPlatform($db, $tprojectId);
}

// ---------------------------------------------------------------------------
// GET ?action=init
// ---------------------------------------------------------------------------
if ($action === 'init') {
    if ($method !== 'GET') {
        pimJson(405, array('status' => 'error', 'error_code' => 'METHOD_NOT_ALLOWED', 'message' => 'Method not allowed'));
    }
    $tprojectId = pimProjectId();
    $project = pimProject($db, $tprojectId);

    $mgr = pimPlatformMgr($db, $tprojectId);
    $platforms = $mgr->getAllAsMap(array(
        'accessKey' => 'id',
        'output' => 'rows',
        'enable_on_design' => null,
        'enable_on_execution' => null,
        'is_open' => null,
    ));

    pimJson(200, array(
        'status' => 'ok',
        'tproject' => array(
            'id' => intval($project['id']),
            'name' => isset($project['name']) ? $project['name'] : '',
        ),
        'tplan_id' => pimTplanId(),
        'platform_count' => is_array($platforms) ? count($platforms) : 0,
        // Legacy $gui->importTypes: XML is the only supported serialization.
        'import_types' => array('XML'),
        'import_limit_bytes' => intval(config_get('import_file_max_size_bytes')),
        // PARTIAL_URL_TL_FILE_FORMATS_DOCUMENT is a partial path
        // ('docs/tl-file-formats.pdf'); legacy prefixed it with $basehref.
        // It must be ROOT-relative here, otherwise the browser resolves it
        // against the current screen directory and the link 404s.
        'file_formats_doc' => defined('PARTIAL_URL_TL_FILE_FORMATS_DOCUMENT')
            ? '/' . ltrim((string)constant('PARTIAL_URL_TL_FILE_FORMATS_DOCUMENT'), '/') : '',
        'grants' => array(
            'platform_management' => pimCanManage($db, $user, $tprojectId),
        ),
    ));
}

// ---------------------------------------------------------------------------
// POST ?action=import
// ---------------------------------------------------------------------------
if ($action === 'import') {
    if ($method !== 'POST') {
        pimJson(405, array('status' => 'error', 'error_code' => 'METHOD_NOT_ALLOWED', 'message' => 'Method not allowed'));
    }
    $tprojectId = pimProjectId();
    $tplanId = pimTplanId();
    pimProject($db, $tprojectId);

    if (!pimCanManage($db, $user, $tprojectId)) {
        pimJson(403, array(
            'status' => 'error',
            'error_code' => 'NO_RIGHTS',
            'message' => 'No permission to manage platforms on this test project',
        ));
    }

    // Legacy form field name was targetFilename; the modern Platforms
    // Management modal used uploadedFile, so both are accepted.
    $fInfo = isset($_FILES['uploadedFile']) ? $_FILES['uploadedFile']
           : (isset($_FILES['targetFilename']) ? $_FILES['targetFilename'] : null);

    if (!is_array($fInfo) || !isset($fInfo['error']) || $fInfo['error'] == UPLOAD_ERR_NO_FILE) {
        pimJson(422, array(
            'status' => 'error',
            'error_code' => 'NO_FILE',
            'message' => 'Please choose a platforms file',
        ));
    }
    if ($fInfo['error'] != UPLOAD_ERR_OK) {
        $uploadErrors = array(
            UPLOAD_ERR_INI_SIZE => 'UPLOAD_ERR_INI_SIZE',
            UPLOAD_ERR_FORM_SIZE => 'UPLOAD_ERR_FORM_SIZE',
            UPLOAD_ERR_PARTIAL => 'UPLOAD_ERR_PARTIAL',
            UPLOAD_ERR_NO_TMP_DIR => 'UPLOAD_ERR_NO_TMP_DIR',
            UPLOAD_ERR_CANT_WRITE => 'UPLOAD_ERR_CANT_WRITE',
            UPLOAD_ERR_EXTENSION => 'UPLOAD_ERR_EXTENSION',
        );
        $code = isset($uploadErrors[$fInfo['error']])
            ? $uploadErrors[$fInfo['error']] : 'UPLOAD_ERROR';
        $http = ($fInfo['error'] == UPLOAD_ERR_INI_SIZE || $fInfo['error'] == UPLOAD_ERR_FORM_SIZE)
            ? 413 : 422;
        pimJson($http, array(
            'status' => 'error',
            'error_code' => $code,
            'message' => 'File upload failed',
        ));
    }

    $maxSize = intval(config_get('import_file_max_size_bytes'));
    if (intval($fInfo['size']) > $maxSize) {
        pimJson(413, array(
            'status' => 'error',
            'error_code' => 'TOO_LARGE',
            'message' => 'File too large',
            'max_size' => $maxSize,
        ));
    }
    if (!is_uploaded_file($fInfo['tmp_name'])) {
        pimJson(422, array(
            'status' => 'error',
            'error_code' => 'UPLOAD_ERROR',
            'message' => 'File upload failed',
        ));
    }

    // Legacy temp name, kept verbatim so a stale copy from a crashed legacy
    // request is reused/overwritten rather than accumulating.
    $dest = TL_TEMP_PATH . session_id() . '-import_platforms.tmp';
    if (!move_uploaded_file($fInfo['tmp_name'], $dest)) {
        pimJson(500, array(
            'status' => 'error',
            'error_code' => 'CANNOT_STORE',
            'message' => 'Could not store uploaded file',
        ));
    }

    $xml = null;
    $xmlErrors = array();
    try {
        // LIBXML_NONET: http://websec.io/2012/08/27/Preventing-XXE-in-PHP.html
        // libxml_use_internal_errors() keeps the messages out of the JSON body
        // and lets the client show *why* a file was rejected.
        $prevInternal = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $xml = simplexml_load_file($dest, 'SimpleXMLElement', LIBXML_NONET);
        foreach (libxml_get_errors() as $le) {
            $xmlErrors[] = array(
                'line' => intval($le->line),
                'column' => intval($le->column),
                'message' => trim($le->message),
            );
        }
        libxml_clear_errors();
        libxml_use_internal_errors($prevInternal);
    } catch (Throwable $e) {
        $xml = null;
    } finally {
        if (file_exists($dest)) {
            @unlink($dest);
        }
    }

    if ($xml === false || $xml === null) {
        // Legacy: lang_get('problems_loading_xml_content')
        pimJson(422, array(
            'status' => 'error',
            'error_code' => 'WRONG_FORMAT',
            'message' => 'Problems loading XML content',
            'xml_errors' => $xmlErrors,
        ));
    }

    $mgr = pimPlatformMgr($db, $tprojectId);
    $platformsOnSystem = $mgr->getAllAsMap(array(
        'accessKey' => 'name',
        'output' => 'rows',
        'enable_on_design' => null,
        'enable_on_execution' => null,
        'is_open' => null,
    ));
    if (!is_array($platformsOnSystem)) {
        $platformsOnSystem = array();
    }

    $imported = 0;
    $updated = 0;
    $ok = array();
    $ko = array();
    $nodeCount = 0;

    foreach ($xml as $platform) {
        $nodeCount++;
        if (!property_exists($platform, 'name')) {
            // Legacy: lang_get('bad_line_skipped')
            $ko[] = array('code' => 'BAD_LINE', 'name' => '');
            continue;
        }
        $name = trim((string)$platform->name);
        if ($name === '') {
            $ko[] = array('code' => 'BAD_LINE', 'name' => '');
            continue;
        }
        $notes = property_exists($platform, 'notes') ? (string)$platform->notes : '';
        $onDesign = property_exists($platform, 'enable_on_design') ? intval($platform->enable_on_design) : 0;
        $onExec = property_exists($platform, 'enable_on_execution') ? intval($platform->enable_on_execution) : 0;
        $isOpen = property_exists($platform, 'is_open') ? intval($platform->is_open) : 1;

        try {
            if (isset($platformsOnSystem[$name])) {
                // Legacy: lang_get('platform_updated')
                $mgr->update(intval($platformsOnSystem[$name]['id']), $name, $notes, $onDesign, $onExec, $isOpen);
                $updated++;
                $ok[] = array('code' => 'UPDATED', 'name' => $name);
            } else {
                // Legacy: lang_get('platform_imported')
                $item = new stdClass();
                $item->name = $name;
                $item->notes = $notes;
                $item->enable_on_design = $onDesign;
                $item->enable_on_execution = $onExec;
                $item->is_open = $isOpen;
                $created = $mgr->create($item);
                // create() reports failures in its return value (duplicate name,
                // DB error) instead of throwing: honour it, otherwise a platform
                // that was never written would be reported as imported.
                if (!is_array($created) || !isset($created['status'])
                    || intval($created['status']) !== intval(tl::OK)
                    || intval($created['id']) <= 0) {
                    $ko[] = array('code' => 'IMPORT_FAILED', 'name' => $name);
                    continue;
                }
                $imported++;
                $ok[] = array('code' => 'IMPORTED', 'name' => $name);
                // Record the *real* new id: a file listing the same platform name
                // twice must update the row just created, not id 0.
                $platformsOnSystem[$name] = array('id' => intval($created['id']), 'name' => $name);
            }
        } catch (Throwable $e) {
            $ko[] = array('code' => 'IMPORT_FAILED', 'name' => $name);
        }
    }

    $platformsAfter = $mgr->getAllAsMap(array(
        'accessKey' => 'id',
        'output' => 'rows',
        'enable_on_design' => null,
        'enable_on_execution' => null,
        'is_open' => null,
    ));

    pimJson(200, array(
        'status' => 'ok',
        'filename' => isset($fInfo['name']) ? $fInfo['name'] : '',
        'tproject_id' => $tprojectId,
        'tplan_id' => $tplanId,
        'node_count' => $nodeCount,
        'imported' => $imported,
        'updated' => $updated,
        'skipped' => count($ko),
        'imported_total' => is_array($platformsAfter) ? count($platformsAfter) : 0,
        'ok' => $ok,
        'ko' => $ko,
    ));
}

pimJson(400, array('status' => 'error', 'error_code' => 'UNKNOWN_ACTION', 'message' => 'Unknown action'));
