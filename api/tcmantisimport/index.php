<?php
/**
 * Mantis Issue XML Test Case Import BFF API — Refs #1948
 * URL: /api/tcmantisimport/?action=init     (GET|HEAD) — context for the screen
 *      /api/tcmantisimport/?action=tree     (GET|HEAD) — target test-suite list
 *      /api/tcmantisimport/?action=import   (POST)     — multipart Mantis XML
 *
 * Backs the modern screen gui/templates/testcases/tcCreateFromMantis.html and
 * retires the 1.9.20 Smarty renderer lib/testcases/tcCreateFromIssueMantisXML.php
 * to a redirect shim (that controller still rendered its tpl in 2.0.1, but the
 * dashio UI stopped reaching it: containerView.tpl re-points the import action
 * to the #1502 screen; only tl-classic and direct URLs still hit it).
 *
 * Relationship to Refs #1502 (api/tccreatefromissues + tcCreateFromIssues.html):
 * that screen covers the SAME <mantis> format with a container fixed by the URL.
 * This BFF is the full-featured variant (documented overlap in #1948): it adds
 * an in-screen suite picker (action=tree) and duplicate handling through the
 * verbatim 1.9.20 engine saveImportedTCData() with its classic hit criteria /
 * action options. Refs #1896 (api/tccreatefromissue) is a different format
 * (TestLink XML, <testcases|testsuite> roots only).
 *
 * The save path reuses the exact 1.9.20 engine extracted verbatim in
 * lib/testcases/tcCreateFromIssue.functions.php (saveImportedTCData), so the
 * created test cases behave identically to the legacy sibling importer.
 *
 * Legacy parity per <issue>:
 *   - test case name  = "<issue_issue label>:<id> - <summary>"
 *   - test case summary = description + optional steps_to_reproduce +
 *     optional additional_information sections, each prefixed with its
 *     server-locale label (same concatenation as the legacy controller)
 *
 * Defects in the legacy controller that are closed here (not ported):
 *   - NO rights check at all (session-only testlinkInitPage): any authenticated
 *     user could import into ANY project/suite (sibling class of #1696/#1679).
 *     The BFF checks mgt_modify_tc on the ADDRESSED project BEFORE the
 *     container is resolved (the #1697 no-oracle lesson).
 *   - no same-origin/CSRF proof on the write (plain form POST).
 *   - container_id never validated against the addressed project.
 *
 * Contract: JSON status ok|error + stable machine codes
 *   400 invalid_request|unknown_action|container_not_in_project
 *   401 not_authenticated|session_expired
 *   403 no_right|foreign_origin
 *   404 tproject_not_found
 *   405 method_not_allowed
 *   413 file_too_large
 *   422 invalid_xml|invalid_format
 *   500 server_error
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
require_once(__DIR__ . '/../_guard.php');

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

set_exception_handler(function ($e) {
    http_response_code(500);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['status' => 'error', 'code' => 'server_error', 'message' => 'Server error']);
    exit;
});

doSessionStart();
bffSameOriginGuard();

$db = new database(DB_TYPE);
doDBConnect($db);
bffEnforceSession($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'code' => 'not_authenticated', 'message' => 'Not authenticated']);
    exit;
}

$user = tlUser::getByID($db, intval($userId));
if (is_null($user)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'code' => 'not_authenticated', 'message' => 'User not found']);
    exit;
}

function tcmi_out($data) { echo json_encode($data); exit; }

function tcmi_intParam($key, $default = 0) {
    $v = $_REQUEST[$key] ?? $default;
    return is_scalar($v) && is_numeric($v) ? intval($v) : $default;
}

/**
 * Resolve the client locale hint (2-char short code or full xx_YY) against the
 * configured TestLink locales so the server-side issue-section labels come back
 * in the language the user picked. Mirrors api/tccreatefromissue tccfi_locale().
 */
function tcmi_locale() {
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
 * The five issue-section labels the import concatenates, resolved through the
 * server locale bundles (same keys the legacy controller used).
 */
function tcmi_sectionLabels() {
    return [
        'issue'       => lang_get('issue_issue'),
        'description' => lang_get('issue_description'),
        'steps'       => lang_get('issue_steps_to_reproduce'),
        'additional'  => lang_get('issue_additional_information'),
    ];
}

/**
 * Resolve the target container (test project root or a test suite) and verify
 * it belongs to the given test project by walking nodes_hierarchy up to the
 * project root. Mirrors api/tccreatefromissue tccfi_resolveContainer().
 */
function tcmi_resolveContainer($db, $tprojectId, $containerId) {
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
 * Flat, depth-indented list of the test suites of a test project.
 */
function tcmi_suiteList($db, $tprojectId) {
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

/**
 * mgt_view_tc OR mgt_modify_tc on the addressed project — read gate for
 * init/tree (the suite list is project data; the #1696 lesson).
 */
function tcmi_canRead($db, $user, $tprojectId) {
    return $user->hasRight($db, 'mgt_view_tc', $tprojectId)
        || $user->hasRight($db, 'mgt_modify_tc', $tprojectId);
}

// Refuse array parameters (?action[]=init) before any dispatch — #1810/#1945.
foreach (['action', 'tproject_id', 'containerID', 'container_id', 'locale',
          'hit_criteria', 'action_on_duplicated_name'] as $p) {
    if (isset($_REQUEST[$p]) && !is_scalar($_REQUEST[$p])) {
        http_response_code(400);
        tcmi_out(['status' => 'error', 'code' => 'invalid_request',
                  'message' => 'Invalid parameter: ' . $p]);
    }
}

$action = strval($_GET['action'] ?? '');
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$tprojectMgr = new testproject($db);

// ---------------------------------------------------------------------------
// GET|HEAD ?action=init[&tproject_id=N][&containerID=N][&locale=xx_YY]
// ---------------------------------------------------------------------------
if ($action === 'init') {
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        http_response_code(405);
        header('Allow: GET, HEAD');
        tcmi_out(['status' => 'error', 'code' => 'method_not_allowed', 'message' => 'Use GET']);
    }
    $tprojectId = tcmi_intParam('tproject_id', intval($_SESSION['testprojectID'] ?? 0));
    if ($tprojectId <= 0) {
        http_response_code(400);
        tcmi_out(['status' => 'error', 'code' => 'invalid_request', 'message' => 'Invalid test project id']);
    }
    // Rights BEFORE the project is proven to exist — no #1697 existence oracle.
    if (!tcmi_canRead($db, $user, $tprojectId)) {
        http_response_code(403);
        tcmi_out(['status' => 'error', 'code' => 'no_right', 'message' => 'No permission']);
    }
    $info = $tprojectMgr->get_by_id($tprojectId);
    if (!$info) {
        http_response_code(404);
        tcmi_out(['status' => 'error', 'code' => 'tproject_not_found', 'message' => 'Test project not found']);
    }

    $containerId = tcmi_intParam('containerID', tcmi_intParam('container_id', $tprojectId));
    if ($containerId <= 0) {
        $containerId = $tprojectId;
    }
    $container = tcmi_resolveContainer($db, $tprojectId, $containerId);
    if (is_null($container)) {
        http_response_code(400);
        tcmi_out(['status' => 'error', 'code' => 'container_not_in_project',
                  'message' => 'Container does not belong to the test project']);
    }

    $loc = tcmi_locale();
    $prevLocale = $_SESSION['locale'] ?? null;
    if ($loc !== null) {
        $_SESSION['locale'] = $loc;
    }
    $labels = tcmi_sectionLabels();
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

    tcmi_out([
        'status' => 'ok',
        'tproject' => ['id' => $tprojectId, 'name' => strval($info['name'])],
        'container' => $container,
        'suites' => tcmi_suiteList($db, $tprojectId),
        'maxUploadBytes' => intval(config_get('import_file_max_size_bytes')),
        'labels' => $labels,
        'hitOptions' => $hitOptions,
        'actionOptions' => $actionOptions,
        'grants' => [
            'mgt_modify_tc' => $user->hasRight($db, 'mgt_modify_tc', $tprojectId) ? 1 : 0,
        ],
    ]);
}

// ---------------------------------------------------------------------------
// GET|HEAD ?action=tree&tproject_id=N  — flat target-suite picker data
// ---------------------------------------------------------------------------
if ($action === 'tree') {
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        http_response_code(405);
        header('Allow: GET, HEAD');
        tcmi_out(['status' => 'error', 'code' => 'method_not_allowed', 'message' => 'Use GET']);
    }
    $tprojectId = tcmi_intParam('tproject_id', intval($_SESSION['testprojectID'] ?? 0));
    if ($tprojectId <= 0) {
        http_response_code(400);
        tcmi_out(['status' => 'error', 'code' => 'invalid_request', 'message' => 'Invalid test project id']);
    }
    if (!tcmi_canRead($db, $user, $tprojectId)) {
        http_response_code(403);
        tcmi_out(['status' => 'error', 'code' => 'no_right', 'message' => 'No permission']);
    }
    $info = $tprojectMgr->get_by_id($tprojectId);
    if (!$info) {
        http_response_code(404);
        tcmi_out(['status' => 'error', 'code' => 'tproject_not_found', 'message' => 'Test project not found']);
    }
    tcmi_out([
        'status' => 'ok',
        'tproject' => ['id' => $tprojectId, 'name' => strval($info['name'])],
        'suites' => tcmi_suiteList($db, $tprojectId),
    ]);
}

// ---------------------------------------------------------------------------
// POST ?action=import&tproject_id=N&container_id=N[&hit_criteria=...]
//      [&action_on_duplicated_name=...][&locale=xx_YY]
// Body: multipart file field "uploadedFile" (Mantis BT XML export)
// ---------------------------------------------------------------------------
if ($action === 'import') {
    if ($method !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        tcmi_out(['status' => 'error', 'code' => 'method_not_allowed', 'message' => 'Use POST']);
    }

    $tprojectId = tcmi_intParam('tproject_id', intval($_SESSION['testprojectID'] ?? 0));
    if ($tprojectId <= 0) {
        http_response_code(400);
        tcmi_out(['status' => 'error', 'code' => 'invalid_request', 'message' => 'Invalid test project id']);
    }
    // Right BEFORE the project is proven to exist — no #1697 oracle, and a
    // write into a foreign project is a flat 403/404, never a silent insert.
    if (!$user->hasRight($db, 'mgt_modify_tc', $tprojectId)) {
        http_response_code(403);
        tcmi_out(['status' => 'error', 'code' => 'no_right', 'message' => 'No permission']);
    }
    $info = $tprojectMgr->get_by_id($tprojectId);
    if (!$info) {
        http_response_code(404);
        tcmi_out(['status' => 'error', 'code' => 'tproject_not_found', 'message' => 'Test project not found']);
    }

    $containerId = tcmi_intParam('container_id', tcmi_intParam('containerID', $tprojectId));
    if ($containerId <= 0) {
        $containerId = $tprojectId;
    }
    $container = tcmi_resolveContainer($db, $tprojectId, $containerId);
    if (is_null($container)) {
        http_response_code(400);
        tcmi_out(['status' => 'error', 'code' => 'container_not_in_project',
                  'message' => 'Container does not belong to the test project']);
    }

    if (!isset($_FILES['uploadedFile']) || !is_array($_FILES['uploadedFile'])
        || ($_FILES['uploadedFile']['error'] ?? -1) !== UPLOAD_ERR_OK) {
        $errCode = isset($_FILES['uploadedFile']) && is_array($_FILES['uploadedFile'])
            ? intval($_FILES['uploadedFile']['error'] ?? -1) : -1;
        http_response_code(400);
        tcmi_out(['status' => 'error', 'code' => 'invalid_request',
                  'message' => 'File upload failed (error code: ' . $errCode . ')']);
    }

    $maxBytes = intval(config_get('import_file_max_size_bytes'));
    if ($maxBytes <= 0) {
        $maxBytes = 10 * 1024 * 1024;
    }
    $uploadedBytes = intval($_FILES['uploadedFile']['size']);
    if ($uploadedBytes > $maxBytes) {
        http_response_code(413);
        tcmi_out(['status' => 'error', 'code' => 'file_too_large',
                  'message' => sprintf('File too large (%d bytes); limit is %d bytes', $uploadedBytes, $maxBytes)]);
    }

    // ---- safe XML parse (LIBXML_NONET, no entity expansion) -----------------
    libxml_use_internal_errors(true);
    $rawXml = @file_get_contents($_FILES['uploadedFile']['tmp_name']);
    $xml = is_string($rawXml)
        ? @simplexml_load_string($rawXml, 'SimpleXMLElement', LIBXML_NONET) : false;
    libxml_clear_errors();
    if ($xml === false || !is_string($rawXml)) {
        http_response_code(422);
        tcmi_out(['status' => 'error', 'code' => 'invalid_xml', 'message' => 'Invalid XML file']);
    }
    if ($xml->getName() !== 'mantis') {
        http_response_code(422);
        tcmi_out(['status' => 'error', 'code' => 'invalid_format',
                  'message' => 'Invalid XML root element: expected <mantis> (use Test Specification Import for TestLink XML)']);
    }

    $hitCriteria = strval($_REQUEST['hit_criteria'] ?? 'name');
    if (!in_array($hitCriteria, ['name', 'internalID', 'externalID'], true)) {
        $hitCriteria = 'name';
    }
    $actionOnHit = strval($_REQUEST['action_on_duplicated_name'] ?? '');
    if (!in_array($actionOnHit, ['update_last_version', 'generate_new', 'create_new_version'], true)) {
        $actionOnHit = null;
    }

    require_once(__DIR__ . '/../../lib/testcases/tcCreateFromIssue.functions.php');

    // ---- Mantis <issue> set -> tcData rows (legacy parity, Refs #1948) ------
    $loc = tcmi_locale();
    $prevLocale = $_SESSION['locale'] ?? null;
    if ($loc !== null) {
        $_SESSION['locale'] = $loc;
    }
    $labels = tcmi_sectionLabels();
    $nl = '<p>';
    $tcData = [];
    foreach ($xml->issue as $issue) {
        // is_scalar-style hardening: SimpleXML cast of an element with element
        // children would produce garbage; force text content only.
        $id = isset($issue->id) ? trim((string) $issue->id) : '';
        $summary = isset($issue->summary) ? trim((string) $issue->summary) : '';
        $desc = isset($issue->description) ? (string) $issue->description : '';
        $steps = isset($issue->steps_to_reproduce) ? (string) $issue->steps_to_reproduce : '';
        $addl = isset($issue->additional_information) ? (string) $issue->additional_information : '';

        $isum = $labels['description'] . $nl . $desc;
        if ($steps !== '') {
            $isum .= $nl . $labels['steps'] . $nl . $steps;
        }
        if ($addl !== '') {
            $isum .= $nl . $labels['additional'] . $nl . $addl;
        }

        $tcData[] = [
            'name' => $labels['issue'] . ':' . $id . ' - ' . $summary,
            'summary' => $isum,
            'steps' => null,
            'internalid' => null,
            'externalid' => null,
            'author_login' => null,
            'preconditions' => null,
        ];
    }
    if ($prevLocale !== null) {
        $_SESSION['locale'] = $prevLocale;
    } else {
        unset($_SESSION['locale']);
    }

    $duplicatedLogic = ['hitCriteria' => $hitCriteria, 'actionOnHit' => $actionOnHit];

    $cleanExit = false;
    register_shutdown_function(function () use (&$cleanExit) {
        if ($cleanExit) { return; }
        while (ob_get_level()) { ob_end_clean(); }
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['status' => 'error', 'code' => 'server_error', 'message' => 'Import failed']);
    });

    $resultMap = null;
    try {
        // Exact 1.9.20 save path (extracted in #1896): kwMap null = no keywords.
        $resultMap = saveImportedTCData(
            $db, $tcData, intval($tprojectId), intval($containerId), intval($userId),
            null, $duplicatedLogic);
    } catch (\Throwable $e) {
        $cleanExit = true;
        http_response_code(500);
        tcmi_out(['status' => 'error', 'code' => 'server_error', 'message' => 'Import failed']);
    }
    $cleanExit = true;

    // The engine returns each row as a positional array [name, message];
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

    tcmi_out([
        'status' => 'ok',
        'tproject' => ['id' => $tprojectId, 'name' => strval($info['name'])],
        'container' => $container,
        'result' => $rows,
    ]);
}

http_response_code(400);
tcmi_out(['status' => 'error', 'code' => 'unknown_action', 'message' => 'Unknown action']);
