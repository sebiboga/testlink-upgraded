<?php
/**
 * Requirement Import BFF API
 * URL: /api/reqimport/?action=options&tproject_id=N   (GET)
 *      /api/reqimport/?action=import                  (POST, multipart)
 *
 * Mirrors lib/requirements/reqImport.php (TestLink 1.9.20 behavior):
 *  - import ONLY requirements into an existing req. spec (scope=items) or
 *    import a whole spec tree (scope=tree when no req_spec selected).
 *  - supported formats: XML, CSV, CSV (Doors), DocBook.
 *  - duplicate detection: hitCriteria (docid | title), actionOnHit
 *    (update_last_version | create_new_version), skip_frozen_req.
 *
 * Rights (same as legacy reqImport.php checkRights): mgt_view_req AND
 * mgt_modify_req on the target test project.
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
function getParam($key, $default = null) { return $_GET[$key] ?? $_REQUEST[$key] ?? $default; }
function getIntParam($key, $default = 0) { return intval($_REQUEST[$key] ?? $default); }

$action = getParam('action');
$reqSpecMgr = new requirement_spec_mgr($db);
$reqMgr = new requirement_mgr($db);
$tprojectMgr = new testproject($db);

function reqImportRights($db, $user, $tprojectId) {
    if (!$user->hasRight($db, 'mgt_view_req', $tprojectId) ||
        !$user->hasRight($db, 'mgt_modify_req', $tprojectId)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }
}

// ---------------------------------------------------------------------------
// GET ?action=options&tproject_id=N  - import form data
// ---------------------------------------------------------------------------
if ($action === 'options') {
    $tprojectId = getIntParam('tproject_id');
    if ($tprojectId <= 0) {
        $tprojectId = intval($_SESSION['testprojectID'] ?? 0);
    }
    if ($tprojectId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test project id']);
    }
    $info = $tprojectMgr->get_by_id($tprojectId);
    if (!$info) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Test project not found']);
    }
    reqImportRights($db, $user, $tprojectId);

    // Req. specs available as import target (scope=items) — top-level specs.
    $specs = [];
    $rows = $db->get_recordset(
        "SELECT rs.id, rs.doc_id, nh.name AS title
         FROM req_specs rs
         JOIN nodes_hierarchy nh ON nh.id = rs.id
         WHERE rs.testproject_id = " . intval($tprojectId) . "
         ORDER BY nh.node_order ASC, nh.id ASC"
    );
    foreach (($rows ? $rows : []) as $r) {
        $specs[] = [
            'id' => intval($r['id']),
            'doc_id' => (string)$r['doc_id'],
            'title' => (string)$r['title'],
        ];
    }

    // Requirement import types (requirement_mgr) vs spec-tree import (spec mgr).
    $reqImportTypes = [];
    foreach ($reqMgr->get_import_file_types() as $k => $v) {
        $reqImportTypes[] = ['id' => $k, 'label' => $v];
    }
    $specImportTypes = [];
    foreach ($reqSpecMgr->get_import_file_types() as $k => $v) {
        $specImportTypes[] = ['id' => $k, 'label' => $v];
    }

    $file_size_limit = intval(config_get('import_file_max_size_bytes'));

    out([
        'status' => 'ok',
        'tproject' => ['id' => $tprojectId, 'name' => (string)$info['name']],
        'specs' => $specs,
        'req_import_types' => $reqImportTypes,
        'spec_import_types' => $specImportTypes,
        'file_size_limit' => $file_size_limit,
        'file_size_limit_kb' => $file_size_limit > 0 ? max(1, round($file_size_limit / 1024)) : 0,
        'hit_options' => [
            ['id' => 'docid', 'label' => lang_get('same_docid')],
            ['id' => 'title', 'label' => lang_get('same_title')],
        ],
        'duplicate_actions' => [
            ['id' => 'update_last_version', 'label' => lang_get('update_last_requirement_version')],
            ['id' => 'create_new_version', 'label' => lang_get('create_new_requirement_version')],
        ],
    ]);
}

// ---------------------------------------------------------------------------
// POST ?action=import  - execute import
// ---------------------------------------------------------------------------
if ($action === 'import') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        out(['status' => 'error', 'message' => 'Use POST']);
    }

    $tprojectId = getIntParam('tproject_id');
    if ($tprojectId <= 0) {
        $tprojectId = intval($_SESSION['testprojectID'] ?? 0);
    }
    if ($tprojectId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test project id']);
    }
    $info = $tprojectMgr->get_by_id($tprojectId);
    if (!$info) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Test project not found']);
    }
    reqImportRights($db, $user, $tprojectId);

    $reqSpecId = getIntParam('req_spec_id');
    $scope = ($reqSpecId > 0) ? 'items' : 'tree';
    $importType = strval($_REQUEST['importType'] ?? '');
    if ($importType === '') {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Missing import type']);
    }

    $hitCriteria = strval($_REQUEST['hitCriteria'] ?? 'docid');
    if (!in_array($hitCriteria, ['docid', 'title'], true)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid hit criteria']);
    }
    $actionOnHit = strval($_REQUEST['actionOnHit'] ?? 'update_last_version');
    if (!in_array($actionOnHit, ['update_last_version', 'create_new_version'], true)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid action on duplicate']);
    }
    $skipFrozenReq = (getIntParam('skip_frozen_req') === 1);

    // ---- validate upload ---------------------------------------------------
    if (!isset($_FILES['uploadedFile']) || $_FILES['uploadedFile']['error'] !== UPLOAD_ERR_OK) {
        $errCode = isset($_FILES['uploadedFile']) ? $_FILES['uploadedFile']['error'] : -1;
        out(['status' => 'error', 'message' => lang_get('please_choose_req_file') .
             ' (code ' . $errCode . ')']);
    }

    $maxBytes = intval(config_get('import_file_max_size_bytes'));
    $uploadedBytes = intval($_FILES['uploadedFile']['size']);
    if ($maxBytes > 0 && $uploadedBytes > $maxBytes) {
        out(['status' => 'error', 'message' =>
             sprintf(lang_get('max_file_size_is'), round($maxBytes / 1024)) . ' KB']);
    }

    // ---- save to temp -------------------------------------------------------
    $tmpDir = config_get('repositoryPath');
    if (!$tmpDir || !is_dir($tmpDir)) {
        $tmpDir = sys_get_temp_dir();
    }
    $fileName = $tmpDir . '/importReq-' . session_id() . '-' . uniqid() . '.tmp';
    if (!move_uploaded_file($_FILES['uploadedFile']['tmp_name'], $fileName)) {
        http_response_code(500);
        out(['status' => 'error', 'message' => 'Failed to save uploaded file']);
    }

    $opts = [
        'skipFrozenReq' => $skipFrozenReq,
        'hitCriteria' => $hitCriteria,
        'actionOnHit' => $actionOnHit,
    ];
    $context = (object)[
        'tproject_id' => $tprojectId,
        'req_spec_id' => $reqSpecId,
        'user_id' => $userId,
        'importType' => $importType,
    ];

    $items = [];
    $fileStatus = ['status_ok' => true, 'msg' => ''];
    $userFeedback = null;

    try {
        if ($importType === 'XML') {
            libxml_use_internal_errors(true);
            $rawXml = @file_get_contents($fileName);
            $xml = @simplexml_load_string($rawXml, 'SimpleXMLElement', LIBXML_NONET);
            libxml_clear_errors();
            if ($rawXml === false || $xml === false) {
                $fileStatus = ['status_ok' => false, 'msg' => lang_get('import_failed_xml_load_failed')];
            } else {
                if ($scope === 'items') {
                    // Import requirements into the selected req. spec.
                    $isReqSpec = property_exists($xml, 'req_spec');
                    if ($isReqSpec) {
                        // The XML is a spec tree, but a single spec was chosen —
                        // import the spec's top-level spec into the project.
                        foreach ($xml->req_spec as $xkm) {
                            $specItems = $reqSpecMgr->createFromXML(
                                $xkm, $tprojectId, 0, $userId, null, $opts);
                            if (is_array($specItems)) {
                                $items = array_merge($items, $specItems);
                            }
                        }
                    } else {
                        $count = count($xml->requirement);
                        for ($k = 0; $k < $count; $k++) {
                            $reqItems = $reqMgr->createFromXML(
                                $xml->requirement[$k], $tprojectId, $reqSpecId, $userId, null, $opts);
                            if (is_array($reqItems)) {
                                $items = array_merge($items, $reqItems);
                            }
                        }
                    }
                } else {
                    // Import a whole spec tree (no req_spec selected).
                    $isReqSpec = property_exists($xml, 'req_spec');
                    if (!$isReqSpec) {
                        $fileStatus = ['status_ok' => false,
                                       'msg' => lang_get('please_create_req_spec_first')];
                    } else {
                        foreach ($xml->req_spec as $xkm) {
                            $specItems = $reqSpecMgr->createFromXML(
                                $xkm, $tprojectId, 0, $userId, null, $opts);
                            if (is_array($specItems)) {
                                $items = array_merge($items, $specItems);
                            }
                        }
                    }
                }
            }
        } else {
            // CSV / CSV (Doors) / DocBook → requirements import.
            if ($scope !== 'items') {
                $fileStatus = ['status_ok' => false,
                               'msg' => lang_get('please_create_req_spec_first')];
            } else {
                $impSet = loadImportedReq($fileName, $importType);
                if (!is_null($impSet) && isset($impSet['info'])) {
                    $reqSet = $impSet['info'];
                    if (is_array($reqSet)) {
                        foreach ($reqSet as $req) {
                            $reqItems = $reqMgr->createFromMap(
                                $req, $tprojectId, $reqSpecId, $userId, null, $opts);
                            if (is_array($reqItems)) {
                                $items = array_merge($items, $reqItems);
                            }
                        }
                    }
                }
                $userFeedback = isset($impSet['userFeedback']) ? $impSet['userFeedback'] : null;
            }
        }
    } catch (\Throwable $e) {
        @unlink($fileName);
        http_response_code(500);
        out(['status' => 'error', 'message' => 'Import failed: ' . $e->getMessage()]);
    }

    @unlink($fileName);

    $normalized = [];
    foreach ($items as $it) {
        if (!is_array($it)) {
            continue;
        }
        $normalized[] = [
            'doc_id' => (string)($it['doc_id'] ?? ''),
            'title' => (string)($it['title'] ?? ''),
            'import_status' => (string)($it['import_status'] ?? ''),
        ];
    }

    if (!$fileStatus['status_ok']) {
        out(['status' => 'error', 'message' => $fileStatus['msg']]);
    }

    out([
        'status' => 'ok',
        'tproject' => ['id' => $tprojectId, 'name' => (string)$info['name']],
        'req_spec_id' => $reqSpecId,
        'result' => $normalized,
    ]);
}

http_response_code(400);
out(['status' => 'error', 'message' => 'Bad request']);
