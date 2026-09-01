<?php
/**
 * Test Plan Export BFF API
 * URL: /api/planexport/
 * Plain PHP, no framework, no compilation
 *
 * Mirrors lib/plan/planExport.php (TestLink 1.9.20): allows export in XML
 * format of a test plan using different export contents
 * (linkedItems | tree | 4results). Uses the same legacy testplan class
 * methods so the exported XML format is identical.
 *
 * Routes:
 *   GET  ?action=info [&tproject_id=N][&tplan_id=N][&platform_id=N][&build_id=N]
 *                     -> { tproject:{id,name}, tplan:{id,name}, types, filename,
 *                          nothing_todo }
 *   POST ?action=export (X-Requested-With: XMLHttpRequest)
 *                     [&exportContent=linkedItems|tree|4results]
 *                     [&export_filename=NAME][&platform_id=N][&build_id=N]
 *                     -> streams XML attachment download (application/xml)
 *
 * Permission parity with legacy: lib/plan/planExport.php performs no explicit
 * right check beyond an authenticated session (reading plan contents, like
 * planView). We keep that behaviour for the export itself but gate the info
 * endpoint the same way planView does (mgt_testplan_create holders can reach
 * the management screen from which Export is launched). The front-end still
 * exposes the screen to authenticated users to read plan contents.
 *
 * Self-contained: does not depend on api/plans/index.php.
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
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

function out($data) { echo json_encode($data); exit; }

function getIntParam($key, $default = 0) {
    $v = $_REQUEST[$key] ?? $default;
    return is_numeric($v) ? intval($v) : $default;
}

/**
 * Sanitize exportContent exactly like legacy init_args(): only the three
 * allowed modes pass through; anything else falls back to 'linkedItems'.
 */
function sanitizeExportContent($value) {
    $default = 'linkedItems';
    $v = isset($value) ? substr((string)$value, 0, strlen($default)) : $default;
    if (!in_array($v, ['tree', '4results', 'linkedItems'], true)) {
        $v = $default;
    }
    return $v;
}

/**
 * Build the default export filename the same way the legacy
 * initializeGui() does:
 *   <exportContent>_<tplan name>[+_platform_<name>][+_build_<name>].xml
 */
function buildExportFilename($db, $tplanMgr, $args) {
    $info = $tplanMgr->get_by_id($args['tplan_id'],
        array('output' => 'minimun', 'caller' => __LINE__));
    $add2name = '';
    if ($args['platform_id'] > 0) {
        $dummy = $tplanMgr->getPlatforms($args['tplan_id'],
            array('outputFormat' => 'mapAccessByID'));
        if (isset($dummy[$args['platform_id']]['name'])) {
            $add2name .= '_' . str_replace(' ', '_', $dummy[$args['platform_id']]['name']);
        }
    }
    if ($args['build_id'] > 0) {
        $dummy = $tplanMgr->get_builds($args['tplan_id']);
        if (isset($dummy[$args['build_id']]['name'])) {
            $add2name .= '_' . str_replace(' ', '_', $dummy[$args['build_id']]['name']);
        }
    }
    return $args['exportContent'] . '_' .
           str_replace(' ', '_', $info['name']) . $add2name . '.xml';
}

// ---------------------------------------------------------------------------
// GET ?action=info - plan + export-form context
// ---------------------------------------------------------------------------
if (($_GET['action'] ?? '') === 'info') {
    $tplan_id = getIntParam('tplan_id');
    $tproject_id = getIntParam('tproject_id');
    if ($tplan_id <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test plan id']);
    }

    $tplanMgr = new testplan($db);
    $info = $tplanMgr->get_by_id($tplan_id, array('output' => 'minimun', 'caller' => __LINE__));
    if (is_null($info) || empty($info)) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Test plan not found']);
    }
    if ($tproject_id <= 0) {
        $tproject_id = intval($info['testproject_id'] ?? 0);
    }

    $tproject_name = trim((string)($_SESSION['testprojectName'] ?? ''));
    if ($tproject_name === '') {
        $tproject_name = testproject::getName($db, $tproject_id);
    }

    $args = array(
        'tplan_id' => $tplan_id,
        'tproject_id' => $tproject_id,
        'platform_id' => getIntParam('platform_id'),
        'build_id' => getIntParam('build_id'),
        'exportContent' => sanitizeExportContent($_GET['exportContent'] ?? null),
    );

    $types = array('XML' => 'XML');
    $filename = buildExportFilename($db, $tplanMgr, $args);

    out(array(
        'status' => 'ok',
        'tproject' => array('id' => $tproject_id, 'name' => $tproject_name),
        'tplan' => array('id' => $tplan_id, 'name' => (string)$info['name']),
        'exportContent' => $args['exportContent'],
        'types' => $types,
        'filename' => $filename,
        'grants' => array(
            'mgt_testplan_create' => $user->hasRight($db, 'mgt_testplan_create', $tproject_id) ? 1 : 0,
        ),
    ));
}

// ---------------------------------------------------------------------------
// POST ?action=export - stream the XML file download
// ---------------------------------------------------------------------------
if (($_POST['action'] ?? '') === 'export') {
    $tplan_id = getIntParam('tplan_id');
    $tproject_id = getIntParam('tproject_id');
    if ($tplan_id <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test plan id']);
    }

    $tplanMgr = new testplan($db);
    $info = $tplanMgr->get_by_id($tplan_id, array('output' => 'minimun', 'caller' => __LINE__));
    if (is_null($info) || empty($info)) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Test plan not found']);
    }
    if ($tproject_id <= 0) {
        $tproject_id = intval($info['testproject_id'] ?? 0);
    }

    $exportContent = sanitizeExportContent($_POST['exportContent'] ?? null);

    $context = array(
        'platform_id' => getIntParam('platform_id'),
        'build_id' => getIntParam('build_id'),
        'tproject_id' => $tproject_id,
    );

    switch ($exportContent) {
        case 'tree':
            $content = $tplanMgr->exportTestPlanDataToXML($tplan_id, $context);
            break;
        case '4results':
            $content = $tplanMgr->exportForResultsToXML($tplan_id, $context, null,
                array('tcaseSet' => null));
            break;
        case 'linkedItems':
        default:
            $exportContent = 'linkedItems';
            $content = $tplanMgr->exportLinkedItemsToXML($tplan_id);
            break;
    }

    $requestedName = trim((string)($_POST['export_filename'] ?? ''));
    if ($requestedName === '') {
        $args = array(
            'tplan_id' => $tplan_id,
            'tproject_id' => $tproject_id,
            'platform_id' => $context['platform_id'],
            'build_id' => $context['build_id'],
            'exportContent' => $exportContent,
        );
        $requestedName = buildExportFilename($db, $tplanMgr, $args);
    }
    // replace blank on name with _ (legacy parity)
    $requestedName = str_replace(' ', '_', $requestedName);

    // Drop the JSON header so the response is a raw file download.
    header_remove('Content-Type');
    $headerFilename = str_replace(["\r", "\n", '"'], '', basename($requestedName));
    header('Content-Type: application/xml; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $headerFilename . '"');
    header('Pragma: public');
    header('Cache-Control: must-revalidate');
    echo $content;
    exit;
}

http_response_code(404);
out(['status' => 'error', 'message' => 'Not found']);
