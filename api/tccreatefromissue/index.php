<?php
/**
 * Test Specification Import BFF API — Refs #1896
 * URL: /api/tccreatefromissue/?action=init     (GET)  — context for the screen
 *      /api/tccreatefromissue/?action=tree     (GET)  — target test-suite list
 *      /api/tccreatefromissue/?action=import   (POST) — multipart TestLink XML
 *
 * Modernizes the orphan legacy renderer lib/testcases/tcCreateFromIssue.php
 * (TestLink 1.9.20). That controller resolved its template through
 * templateConfiguration() to testcases/tcCreateFromIssue.tpl, which does not
 * exist in gui/templates/dashio/, so the screen was dead code. The 1.9.20
 * import engine is preserved verbatim in lib/testcases/tcCreateFromIssue.functions.php
 * and reused here, so the modern BFF has full behavioural parity.
 *
 * Legacy parity:
 *   - permission: mgt_modify_tc on the target test project (403 otherwise)
 *   - upload cap: config import_file_max_size_bytes
 *   - one XML file (testcases root, or testsuite root when useRecursion)
 *   - options: useRecursion, importIntoProject, duplicateLogic.hitCriteria
 *     (name|internalID|externalID) and duplicateLogic.actionOnHit
 *     (update_last_version|generate_new|create_new_version)
 *   - result rows [{name, message}] exactly like the legacy $gui->resultMap
 *
 * JSON contract: status ok|error, tproject, container, result rows, 400/401/403/404/413/422/500.
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
require_once(__DIR__ . '/../_guard.php');

doSessionStart();
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

$user = tlUser::getByID($db, intval($userId));
if (is_null($user)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'User not found']);
    exit;
}

function tccfi_out($data) { echo json_encode($data); exit; }

function tccfi_intParam($key, $default = 0) {
    $v = $_REQUEST[$key] ?? $default;
    return is_numeric($v) ? intval($v) : $default;
}

/**
 * Resolve the client locale hint (2-char short code or full xx_YY) against the
 * configured TestLink locales so the server-side option labels come back in the
 * language the user picked. Mirrors api/tccreatefromissues tcfi_locale().
 */
function tccfi_locale() {
    $hint = trim(strval($_REQUEST['locale'] ?? ''));
    if ($hint === '') {
        return null;
    }
    $codes = (array) config_get('locales');
    if (preg_match('/^[a-z]{2}_[A-Z]{2}$/', $hint)) {
        return (isset($codes[$hint])) ? $hint : null;
    }
    $short = preg_replace('/[^a-z]/', '', strtolower($hint));
    if ($short === '' || strlen($short) !== 2) {
        return null;
    }
    foreach (array_keys($codes) as $code) {
        if (strpos(strtolower($code), $short) === 0) {
            return $code;
        }
    }
    return null;
}

/**
 * Resolve the target container (test project root or a test suite) and verify
 * it belongs to the given test project by walking nodes_hierarchy up to the
 * project root. Mirrors the legacy container_id semantics.
 */
function tccfi_resolveContainer($db, $tprojectId, $containerId) {
    $tables = tlObjectWithDB::getDBTables(['nodes_hierarchy', 'node_types']);
    $projId = intval($tprojectId);
    $orig = intval($containerId);
    $row = $db->get_recordset(
        "SELECT nh.id, nh.parent_id, nh.name, nt.description AS node_type " .
        "FROM {$tables['nodes_hierarchy']} nh " .
        "JOIN {$tables['node_types']} nt ON nt.id = nh.node_type_id " .
        "WHERE nh.id = " . intval($orig));
    if (is_null($row) || count($row) !== 1) {
        return null;
    }
    $origName = strval($row[0]['name']);
    $origType = strval($row[0]['node_type']);
    if (!in_array($origType, ['testproject', 'testsuite'], true)) {
        return null;
    }
    $cur = $orig;
    $visited = [];
    for ($hops = 0; $hops < 64; $hops++) {
        if (isset($visited[$cur])) { break; }
        $visited[$cur] = true;
        $row = $db->get_recordset(
            "SELECT id, parent_id, name FROM {$tables['nodes_hierarchy']} " .
            "WHERE id = " . intval($cur));
        if (is_null($row) || count($row) !== 1) {
            return null;
        }
        $parent = intval($row[0]['parent_id']);
        if ($cur === $projId || $parent === 0) {
            return ($cur === $projId)
                ? ['id' => $orig, 'name' => $origName, 'isProject' => ($orig === $projId)]
                : null;
        }
        $cur = $parent;
    }
    return null;
}

/**
 * Flat, depth-indented list of the test suites of a test project, so the screen
 * can offer a target-suite picker without depending on the (filter-aware)
 * api/testcases?action=tree endpoint.
 */
function tccfi_suiteList($db, $tprojectId) {
    $tables = tlObjectWithDB::getDBTables(['nodes_hierarchy', 'node_types']);
    $rows = $db->get_recordset(
        "SELECT nh.id, nh.parent_id, nh.name, nh.node_order, nt.description AS node_type " .
        "FROM {$tables['nodes_hierarchy']} nh " .
        "JOIN {$tables['node_types']} nt ON nt.id = nh.node_type_id " .
        "WHERE nt.description IN ('testproject','testsuite') ORDER BY nh.node_order, nh.id");
    $byId = [];
    $children = [];
    if (!is_null($rows)) {
        foreach ($rows as $r) {
            $byId[intval($r['id'])] = $r;
            $children[intval($r['parent_id'])][] = intval($r['id']);
        }
    }
    $out = [];
    $walk = function ($pid, $depth) use (&$walk, $byId, $children, &$out) {
        foreach ($children[$pid] ?? [] as $cid) {
            $n = $byId[$cid];
            if (strval($n['node_type']) !== 'testsuite') { continue; }
            $out[] = ['id' => $cid, 'name' => strval($n['name']), 'depth' => $depth];
            $walk($cid, $depth + 1);
        }
    };
    $walk(intval($tprojectId), 0);
    return $out;
}

$action = $_GET['action'] ?? '';
$tprojectMgr = new testproject($db);

// ---------------------------------------------------------------------------
// GET ?action=init[&tproject_id=N][&containerID=N][&locale=xx_YY]
// ---------------------------------------------------------------------------
if ($action === 'init') {
    $tprojectId = tccfi_intParam('tproject_id', intval($_SESSION['testprojectID'] ?? 0));
    if ($tprojectId <= 0) {
        http_response_code(400);
        tccfi_out(['status' => 'error', 'message' => 'Invalid test project id']);
    }
    $info = $tprojectMgr->get_by_id($tprojectId);
    if (!$info) {
        http_response_code(404);
        tccfi_out(['status' => 'error', 'message' => 'Test project not found']);
    }

    $containerId = tccfi_intParam('containerID', $tprojectId);
    if ($containerId <= 0) {
        $containerId = $tprojectId;
    }
    $container = tccfi_resolveContainer($db, $tprojectId, $containerId);
    if (is_null($container)) {
        http_response_code(400);
        tccfi_out(['status' => 'error', 'message' => 'Container does not belong to the test project']);
    }

    $loc = tccfi_locale();
    $prevLocale = $_SESSION['locale'] ?? null;
    if ($loc !== null) {
        $_SESSION['locale'] = $loc;
    }
    $hitOptions = [
        'name' => lang_get('same_name'),
        'internalID' => lang_get('same_internalID'),
        'externalID' => lang_get('same_externalID'),
    ];
    $actionOptions = [
        'update_last_version' => lang_get('update_last_testcase_version'),
        'generate_new' => lang_get('generate_new_testcase'),
        'create_new_version' => lang_get('create_new_testcase_version'),
    ];
    if ($prevLocale !== null) {
        $_SESSION['locale'] = $prevLocale;
    } else {
        unset($_SESSION['locale']);
    }

    tccfi_out([
        'status' => 'ok',
        'tproject' => ['id' => $tprojectId, 'name' => strval($info['name'])],
        'container' => $container,
        'suites' => tccfi_suiteList($db, $tprojectId),
        'maxUploadBytes' => intval(config_get('import_file_max_size_bytes')),
        'grants' => [
            'mgt_modify_tc' => $user->hasRight($db, 'mgt_modify_tc', $tprojectId) ? 1 : 0,
        ],
        'hitOptions' => $hitOptions,
        'actionOptions' => $actionOptions,
    ]);
}

// ---------------------------------------------------------------------------
// GET ?action=tree&tproject_id=N  — flat target-suite picker data
// ---------------------------------------------------------------------------
if ($action === 'tree') {
    $tprojectId = tccfi_intParam('tproject_id', intval($_SESSION['testprojectID'] ?? 0));
    if ($tprojectId <= 0) {
        http_response_code(400);
        tccfi_out(['status' => 'error', 'message' => 'Invalid test project id']);
    }
    $info = $tprojectMgr->get_by_id($tprojectId);
    if (!$info) {
        http_response_code(404);
        tccfi_out(['status' => 'error', 'message' => 'Test project not found']);
    }
    tccfi_out([
        'status' => 'ok',
        'tproject' => ['id' => $tprojectId, 'name' => strval($info['name'])],
        'suites' => tccfi_suiteList($db, $tprojectId),
    ]);
}

// ---------------------------------------------------------------------------
// POST ?action=import&tproject_id=N&container_id=N[&useRecursion=1]
//      [&bIntoProject=1][&hit_criteria=name|internalID|externalID]
//      [&action_on_duplicated_name=update_last_version|generate_new|create_new_version]
// Body: multipart file field "uploadedFile" (TestLink XML export)
// ---------------------------------------------------------------------------
if ($action === 'import') {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        tccfi_out(['status' => 'error', 'message' => 'Use POST']);
    }

    $tprojectId = tccfi_intParam('tproject_id', intval($_SESSION['testprojectID'] ?? 0));
    if ($tprojectId <= 0) {
        http_response_code(400);
        tccfi_out(['status' => 'error', 'message' => 'Invalid test project id']);
    }
    $info = $tprojectMgr->get_by_id($tprojectId);
    if (!$info) {
        http_response_code(404);
        tccfi_out(['status' => 'error', 'message' => 'Test project not found']);
    }
    if (!$user->hasRight($db, 'mgt_modify_tc', $tprojectId)) {
        http_response_code(403);
        tccfi_out(['status' => 'error', 'message' => 'No permission']);
    }

    $containerId = tccfi_intParam('container_id', tccfi_intParam('containerID', $tprojectId));
    if ($containerId <= 0) {
        $containerId = $tprojectId;
    }
    $container = tccfi_resolveContainer($db, $tprojectId, $containerId);
    if (is_null($container)) {
        http_response_code(400);
        tccfi_out(['status' => 'error', 'message' => 'Container does not belong to the test project']);
    }

    if (!isset($_FILES['uploadedFile']) || $_FILES['uploadedFile']['error'] !== UPLOAD_ERR_OK) {
        $errCode = isset($_FILES['uploadedFile']) ? intval($_FILES['uploadedFile']['error']) : -1;
        http_response_code(400);
        tccfi_out(['status' => 'error', 'message' => 'File upload failed (error code: ' . $errCode . ')']);
    }

    $maxBytes = intval(config_get('import_file_max_size_bytes'));
    if ($maxBytes <= 0) {
        $maxBytes = 10 * 1024 * 1024;
    }
    $uploadedBytes = intval($_FILES['uploadedFile']['size']);
    if ($uploadedBytes > $maxBytes) {
        http_response_code(413);
        tccfi_out([
            'status' => 'error',
            'message' => sprintf('File too large (%d bytes); limit is %d bytes', $uploadedBytes, $maxBytes),
        ]);
    }

    // ---- safe XML parse (LIBXML_NONET) --------------------------------------
    libxml_use_internal_errors(true);
    $rawXml = @file_get_contents($_FILES['uploadedFile']['tmp_name']);
    $xml = is_string($rawXml)
        ? @simplexml_load_string($rawXml, 'SimpleXMLElement', LIBXML_NONET) : false;
    libxml_clear_errors();
    if ($xml === false || !is_string($rawXml)) {
        http_response_code(422);
        tccfi_out(['status' => 'error', 'message' => 'Invalid XML file']);
    }
    $rootName = $xml->getName();
    if (!in_array($rootName, ['testcases', 'testsuite'], true)) {
        http_response_code(422);
        tccfi_out(['status' => 'error',
                   'message' => 'Invalid XML root element: expected <testcases> or <testsuite>']);
    }

    $useRecursion = tccfi_intParam('useRecursion', 0) === 1;
    if ($useRecursion && $rootName !== 'testsuite') {
        http_response_code(422);
        tccfi_out(['status' => 'error',
                   'message' => 'Recursive import needs a <testsuite> root element']);
    }
    if (!$useRecursion && $rootName !== 'testcases') {
        http_response_code(422);
        tccfi_out(['status' => 'error',
                   'message' => 'Non-recursive import needs a <testcases> root element']);
    }

    $hitCriteria = strval($_REQUEST['hit_criteria'] ?? 'name');
    if (!in_array($hitCriteria, ['name', 'internalID', 'externalID'], true)) {
        $hitCriteria = 'name';
    }
    $actionOnHit = strval($_REQUEST['action_on_duplicated_name'] ?? '');
    if (!in_array($actionOnHit, ['update_last_version', 'generate_new', 'create_new_version'], true)) {
        $actionOnHit = null;
    }
    $importIntoProject = tccfi_intParam('bIntoProject', 0);

    require_once(__DIR__ . '/../../lib/testcases/tcCreateFromIssue.functions.php');

    $opt = [
        'useRecursion' => $useRecursion,
        'importIntoProject' => $importIntoProject,
        'duplicateLogic' => ['hitCriteria' => $hitCriteria, 'actionOnHit' => $actionOnHit],
    ];

    $cleanExit = false;
    register_shutdown_function(function () use (&$cleanExit) {
        if ($cleanExit) { return; }
        while (ob_get_level()) { ob_end_clean(); }
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['status' => 'error', 'message' => 'Import failed']);
    });

    $resultMap = null;
    try {
        $resultMap = importTestCaseDataFromXML(
            $db, $_FILES['uploadedFile']['tmp_name'],
            intval($containerId), intval($tprojectId), intval($userId), $opt);
    } catch (\Throwable $e) {
        $cleanExit = true;
        http_response_code(500);
        tccfi_out(['status' => 'error', 'message' => 'Import failed: ' . $e->getMessage()]);
    }
    $cleanExit = true;

    // The legacy engine returns each row as a positional array [name, message];
    // normalize to {name, message} objects for the JSON/UI contract.
    $rows = [];
    foreach ((array)$resultMap as $r) {
        if (is_array($r)) {
            $rows[] = ['name' => strval($r[0] ?? ''), 'message' => strval($r[1] ?? '')];
        } elseif (is_object($r)) {
            $rows[] = ['name' => strval($r->name ?? ''), 'message' => strval($r->message ?? '')];
        } else {
            $rows[] = ['name' => '', 'message' => strval($r)];
        }
    }

    tccfi_out([
        'status' => 'ok',
        'tproject' => ['id' => $tprojectId, 'name' => strval($info['name'])],
        'container' => $container,
        'result' => $rows,
    ]);
}

http_response_code(400);
tccfi_out(['status' => 'error', 'message' => 'Unknown action']);
