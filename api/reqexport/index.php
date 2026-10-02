<?php
/**
 * Requirement Export BFF API
 * URL: /api/reqexport/
 * Plain PHP, no framework, no compilation
 *
 * Mirrors lib/requirements/reqExport.php (TestLink 1.9.20): allows exporting
 * requirements as XML (optionally with attachments base64-encoded), using the
 * same legacy requirement_spec_mgr::exportReqSpecToXML() machinery so the
 * produced XML format is identical to the legacy download.
 *
 * Scopes (same as legacy):
 *   tree   - all top-level requirement specs of the test project
 *   branch - one req spec and its whole sub-tree (recursive)
 *   items  - direct requirement children of one req spec (non-recursive)
 *
 * Routes:
 *   GET  ?action=options&scope=...&tproject_id=N&req_spec_id=N
 *                     -> { tproject:{id,name}, scope, req_spec_id,
 *                          req_spec_title, types, filename, rights }
 *   POST ?action=export (X-Requested-With: XMLHttpRequest)
 *                     [&exportType=XML|csv][&scope=...][&export_filename=NAME]
 *                     [&exportAttachments=1]
 *                     -> streams the file download (application/xml or text/csv)
 *
 * Rights parity with legacy reqExport.php checkRights(): mgt_view_req on the
 * target test project (rightsAnd).
 *
 * Self-contained: does not depend on api/reqspec or api/reqimport.
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
require_once(__DIR__ . '/../../lib/functions/xml.inc.php');
require_once(__DIR__ . '/../../lib/functions/csv.inc.php');
require_once(__DIR__ . '/../../lib/functions/requirements.inc.php');

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
}

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    http_response_code(401);
    out(['status' => 'error', 'message' => 'User not found']);
}

function out($data) { echo json_encode($data); exit; }

function getIntParam($key, $default = 0) {
    $v = $_REQUEST[$key] ?? $default;
    return is_numeric($v) ? intval($v) : $default;
}

function getStrParam($key, $default = '') {
    $v = $_REQUEST[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

/**
 * Sanitize scope exactly like legacy init_args() default ('items'); only
 * tree|branch|items pass through.
 */
function sanitizeScope($value) {
    $v = isset($value) ? substr((string)$value, 0, 6) : 'items';
    if (!in_array($v, ['tree', 'branch', 'items'], true)) {
        $v = 'items';
    }
    return $v;
}

function sanitizeExportType($value) {
    $v = strtoupper((string)$value);
    return ($v === 'XML' || $v === 'CSV') ? $v : 'XML';
}

/**
 * Sanitize the requested attachment flag the same way the legacy checkbox
 * works (present = truthy).
 */
function sanitizeAttachments($value) {
    return (isset($value) && !empty($value)) ? '1' : '';
}

/**
 * Legacy input dimensions for the export file name field.
 *
 * The legacy Smarty template (gui/templates/dashio/requirements/reqExport.tpl
 * :65-69) loaded gui/templates/conf/input_dimensions.conf with
 * {config_load file="input_dimensions.conf" section=$cfg_section} and rendered
 *
 *     maxlength="{#FILENAME_MAXLEN#}" ... size="{#FILENAME_SIZE#}"
 *
 * FILENAME_MAXLEN / FILENAME_SIZE are global values in that file (50/50), i.e.
 * they sit OUTSIDE any [section] block, so the legacy substitution never
 * depended on the section name. That browser-side maxlength was the ONLY
 * enforcement point of the limit: legacy reqExport.php::doExport() passes
 * $_REQUEST['export_filename'] straight through to the
 * Content-Disposition header, so any HTTP client bypassing the browser could
 * emit an unbounded header.
 *
 * Read here with the same safe-parsing pattern already used in
 * api/reqtcassign/index.php (scopeShortTruncate(), reading
 * SCOPE_SHORT_TRUNCATE) so a changed input_dimensions.conf propagates to the
 * modern screen instead of being hardcoded in the HTML.
 */
function filenameDimensions() {
    static $cached = null;
    if (!is_null($cached)) {
        return $cached;
    }
    $cached = array('maxlen' => 50, 'size' => 50);
    $conf = __DIR__ . '/../../gui/templates/conf/input_dimensions.conf';
    if (is_readable($conf)) {
        $lines = @file($conf, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (is_array($lines)) {
            foreach ($lines as $line) {
                if (preg_match('/^\s*FILENAME_MAXLEN\s*=\s*(\d+)/', $line, $m)) {
                    $n = intval($m[1]);
                    if ($n > 0) { $cached['maxlen'] = $n; }
                    break;
                }
            }
            foreach ($lines as $line) {
                if (preg_match('/^\s*FILENAME_SIZE\s*=\s*(\d+)/', $line, $m)) {
                    $n = intval($m[1]);
                    if ($n > 0) { $cached['size'] = $n; }
                    break;
                }
            }
        }
    }
    return $cached;
}

/**
 * Clamp the requested export file name to the legacy FILENAME_MAXLEN.
 * Legacy enforced this only through the template maxlength attribute; here it
 * is enforced again on the server (defence in depth, no behaviour change for
 * users who stay inside the limit).
 *
 * The budget is counted in CHARACTERS, like the browser maxlength attribute
 * does, not in bytes: a name of 50 accented characters is accepted by the
 * legacy field and must not be truncated here. When mbstring is unavailable
 * the cut falls back to a UTF-8 aware byte loop, so a multi-byte character is
 * never split in half.
 */
function clampExportFilename($name, $maxlen) {
    $name = (string)$name;
    if ($maxlen <= 0 || $name === '') {
        return $name;
    }
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return (mb_strlen($name, 'UTF-8') <= $maxlen)
            ? $name : mb_substr($name, 0, $maxlen, 'UTF-8');
    }
    if (strlen($name) <= $maxlen) {
        return $name;
    }
    $out = substr($name, 0, $maxlen);
    // do not leave a broken UTF-8 sequence at the end
    while (strlen($out) > 0 && !preg_match('//u', $out)) {
        $out = substr($out, 0, -1);
    }
    return $out;
}

/**
 * Build the default export filename exactly like legacy initializeGui():
 *   tree   -> all-req.xml
 *   branch -> <title>-req-spec.xml
 *   items  -> <title>-child_req.xml
 */
function defaultExportFilename($scope, $specTitle = null) {
    switch ($scope) {
        case 'tree':
            return 'all-req.xml';
        case 'branch':
            return ($specTitle ? $specTitle : 'req-spec') . '-req-spec.xml';
        case 'items':
        default:
            return ($specTitle ? $specTitle : 'req-spec') . '-child_req.xml';
    }
}

function checkReqExportRight($db, $user, $tprojectId) {
    if ($tprojectId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test project id']);
    }
    if (!$user->hasRight($db, 'mgt_view_req', $tprojectId)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }
    return true;
}

/**
 * Existence check without touching requirement_spec_mgr::get_by_id() on a
 * non-existent id: that method runs a query that the legacy database layer
 * aborts (CWE-200 backtrace) when the node is missing. A flat nodes_hierarchy
 * lookup returns NULL cleanly for missing ids.
 */
function specExists($db, $reqSpecMgr, $id) {
    $type = $reqSpecMgr->node_types_descr_id['requirement_spec'] ?? null;
    if ($type === null) {
        return true; // cannot determine the node type; let the caller proceed
    }
    $rs = $db->get_recordset('SELECT id FROM nodes_hierarchy WHERE id = ' . intval($id)
        . ' AND node_type_id = ' . intval($type));
    return !is_null($rs) && count($rs) > 0;
}

function reqSpecContext(&$reqSpecMgr, $scope, $req_spec_id, $tproject_id = 0) {
    $spec = null;
    if ($scope !== 'tree') {
        if ($req_spec_id <= 0) {
            http_response_code(400);
            out(['status' => 'error', 'message' => 'Invalid requirement specification id']);
        }
        if (!specExists($GLOBALS['db'], $reqSpecMgr, $req_spec_id)) {
            http_response_code(404);
            out(['status' => 'error', 'message' => 'Requirement specification not found']);
        }
        $spec = $reqSpecMgr->get_by_id($req_spec_id);
        if (is_null($spec) || empty($spec)) {
            http_response_code(404);
            out(['status' => 'error', 'message' => 'Requirement specification not found']);
        }
        if ($tproject_id > 0 && (int)$spec['testproject_id'] !== (int)$tproject_id) {
            // the spec must belong to the test project the user has rights on
            http_response_code(404);
            out(['status' => 'error', 'message' => 'Requirement specification not found']);
        }
    }
    return $spec;
}

// ---------------------------------------------------------------------------
// GET ?action=options - export form context
// ---------------------------------------------------------------------------
if (($_GET['action'] ?? '') === 'options') {
    $scope = sanitizeScope($_GET['scope'] ?? null);
    $tproject_id = getIntParam('tproject_id');
    if ($tproject_id <= 0) {
        $tproject_id = intval($_SESSION['testprojectID'] ?? 0);
    }

    $reqSpecMgr = new requirement_spec_mgr($db);

    // Gate the discovery route too, BEFORE any data access, so unprivileged
    // users cannot probe spec existence/ids (no 404-vs-403 oracle).
    checkReqExportRight($db, $user, $tproject_id);

    $req_spec_id = 0;
    $specTitle = lang_get('all_reqspecs_in_tproject');
    if ($scope !== 'tree') {
        $req_spec_id = getIntParam('req_spec_id');
        $spec = reqSpecContext($reqSpecMgr, $scope, $req_spec_id, $tproject_id);
        // legacy remember - makes subsequent requests easier
        $_SESSION['req_spec_id'] = $req_spec_id;
        $specTitle = (string)$spec['title'];
    }

    $tproject_name = trim((string)($_SESSION['testprojectName'] ?? ''));
    if ($tproject_name === '') {
        $tproject_name = (string)testproject::getName($db, $tproject_id);
    }

    $dims = filenameDimensions();

    out(array(
        'status' => 'ok',
        'tproject' => array('id' => $tproject_id, 'name' => $tproject_name),
        'scope' => $scope,
        'req_spec_id' => $req_spec_id,
        'req_spec_title' => $specTitle,
        'types' => array('XML' => 'XML'),
        'filename' => defaultExportFilename($scope, $specTitle),
        // legacy template input dimensions for the export file name field
        'filename_maxlen' => $dims['maxlen'],
        'filename_size' => $dims['size'],
        'rights' => array(
            'mgt_view_req' => $user->hasRight($db, 'mgt_view_req', $tproject_id) ? 1 : 0,
        ),
    ));
}

// ---------------------------------------------------------------------------
// POST ?action=export - stream the file download
// ---------------------------------------------------------------------------
if (($_POST['action'] ?? '') === 'export') {
    $tproject_id = getIntParam('tproject_id');
    if ($tproject_id <= 0) {
        $tproject_id = intval($_SESSION['testprojectID'] ?? 0);
    }
    checkReqExportRight($db, $user, $tproject_id);

    $scope = sanitizeScope($_POST['scope'] ?? null);
    $exportType = sanitizeExportType($_POST['exportType'] ?? null);
    $req_spec_id = getIntParam('req_spec_id');

    if ($scope !== 'tree') {
        if ($req_spec_id <= 0) {
            http_response_code(400);
            out(['status' => 'error', 'message' => 'Invalid requirement specification id']);
        }
    }

    $reqSpecMgr = new requirement_spec_mgr($db);
    $specTitle = null;
    if ($scope !== 'tree') {
        $spec = reqSpecContext($reqSpecMgr, $scope, $req_spec_id, $tproject_id);
        $specTitle = (string)$spec['title'];
    }

    $pfn = null;
    switch ($exportType) {
        case 'CSV':
            $requirements_map = $reqSpecMgr->get_requirements($req_spec_id);
            $pfn = 'exportReqDataToCSV';
            $content = $pfn($requirements_map);
            break;

        case 'XML':
        default:
            $pfn = 'exportReqSpecToXML';
            $content = TL_XMLEXPORT_HEADER;
            $optionsForExport['RECURSIVE'] = ($scope === 'items') ? false : true;
            $optionsForExport['ATTACHMENTS'] = sanitizeAttachments($_POST['exportAttachments'] ?? null);

            $openTag = ($scope === 'items')
                ? 'requirements>'
                : 'requirement-specification>';

            switch ($scope) {
                case 'tree':
                    $reqSpecSet = $reqSpecMgr->getFirstLevelInTestProject($tproject_id);
                    $reqSpecSet = array_keys($reqSpecSet);
                    break;
                case 'branch':
                case 'items':
                default:
                    $reqSpecSet = array($req_spec_id);
                    break;
            }

            $content .= '<' . $openTag . "\n";
            if (!is_null($reqSpecSet)) {
                foreach ($reqSpecSet as $oneSpecID) {
                    $content .= $reqSpecMgr->$pfn($oneSpecID, $tproject_id, $optionsForExport);
                }
            }
            $content .= '</' . $openTag . "\n";
            break;
    }

    if ($pfn) {
        $defaultName = ($exportType === 'CSV')
            ? 'reqs.csv'
            : defaultExportFilename($scope, $specTitle);
        $requestedName = getStrParam('export_filename');
        if ($requestedName === '') {
            $requestedName = $defaultName;
        }
        // legacy limited the field to FILENAME_MAXLEN (template maxlength);
        // re-apply the same cap server-side, since a direct HTTP call bypasses
        // the browser attribute that legacy relied upon
        $dims = filenameDimensions();
        $requestedName = clampExportFilename($requestedName, $dims['maxlen']);
        // replace blank on name with _ (safe download header value)
        $requestedName = str_replace(' ', '_', $requestedName);
        $headerFilename = str_replace(["\r", "\n", '"'], '', basename($requestedName));

        header_remove('Content-Type');
        header('Pragma: public');
        header('Content-Type: ' . ($exportType === 'CSV' ? 'text/csv' : 'application/xml')
            . '; charset=' . config_get('charset') . '; name=' . $headerFilename);
        header('Content-Disposition: attachment; filename="' . $headerFilename . '"');
        header('Cache-Control: must-revalidate');
        echo $content;
        exit;
    }
}

http_response_code(404);
out(['status' => 'error', 'message' => 'Not found']);
