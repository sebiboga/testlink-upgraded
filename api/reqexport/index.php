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

function checkReqExportRight($db, $user, $tprojectId, $granted) {
    if ($tprojectId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test project id']);
    }
    $has = $user->hasRight($db, 'mgt_view_req', $tprojectId);
    if ($granted === null) {
        // discovery/options path: report the right instead of failing
        return $has;
    }
    if (!$has) {
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

function reqSpecContext(&$reqSpecMgr, $scope, $req_spec_id) {
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

    $req_spec_id = 0;
    $specTitle = lang_get('all_reqspecs_in_tproject');
    if ($scope !== 'tree') {
        $req_spec_id = getIntParam('req_spec_id');
        $spec = reqSpecContext($reqSpecMgr, $scope, $req_spec_id);
        // legacy remember - makes subsequent requests easier
        $_SESSION['req_spec_id'] = $req_spec_id;
        $specTitle = (string)$spec['title'];
    }

    $tproject_name = trim((string)($_SESSION['testprojectName'] ?? ''));
    if ($tproject_name === '') {
        $tproject_name = (string)testproject::getName($db, $tproject_id);
    }

    checkReqExportRight($db, $user, $tproject_id, false);

    out(array(
        'status' => 'ok',
        'tproject' => array('id' => $tproject_id, 'name' => $tproject_name),
        'scope' => $scope,
        'req_spec_id' => $req_spec_id,
        'req_spec_title' => $specTitle,
        'types' => array('XML' => 'XML'),
        'filename' => defaultExportFilename($scope, $specTitle),
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
    checkReqExportRight($db, $user, $tproject_id, true);

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
        $spec = reqSpecContext($reqSpecMgr, $scope, $req_spec_id);
        $specTitle = (string)$spec['title'];
    }

    $pfn = null;
    switch ($exportType) {
        case 'CSV':
            $requirements_map = $reqSpecMgr->get_requirements($req_spec_id);
            $pfn = 'exportReqDataToCSV';
            $fileName = 'reqs.csv';
            $content = $pfn($requirements_map);
            break;

        case 'XML':
        default:
            $pfn = 'exportReqSpecToXML';
            $fileName = 'reqs.xml';
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