<?php
/**
 * Attachments BFF API
 * URL: /api/attachments/
 * Plain PHP, no framework, no compilation
 *
 * Mirrors lib/attachments/attachmentupload.php + attachmentdelete.php +
 * attachmentdownload.php (TestLink 1.9.20 behavior):
 *   - list attachments of a bject (fk_table + fk_id)
 *   - upload one or more files (title optional for executions)
 *   - delete an attachment (hardened: must belong to the given fk_table/fk_id)
 *   - download a single attachment streamed with the legacy XSS-safe
 *     SVG handling
 *
 * Rights (same as legacy screens): attachments feature enabled
 * (config_get("attachments")->enabled on every attachmentupload.php /
 * attachmentdelete.php / attachmentdownload.php checkRights).
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

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

require_once(__DIR__ . '/../../lib/functions/attachments.inc.php');

$action = trim(strval($_GET['action'] ?? ($_POST['action'] ?? '')));

function bffOut($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function bffAttachmentsEnabled() {
    return (bool)config_get('attachments')->enabled;
}

/** Format one attachment row like the other modern BFF areas. */
function bffAttachRow($ai) {
    return [
        'id'          => intval($ai['id']),
        'title'       => strval($ai['title'] ?? ''),
        'file_name'   => strval($ai['file_name'] ?? ''),
        'file_type'   => strval($ai['file_type'] ?? ''),
        'file_size'   => intval($ai['file_size'] ?? 0),
        'date_added'  => strval($ai['date_added'] ?? ''),
        'download_url' => '/api/attachments/index.php?action=download&id=' .
                          intval($ai['id']),
    ];
}

/**
 * Download: streamed content, NOT JSON. Same security posture as the legacy
 * attachmentdownload.php: attachments must be enabled, the attachment must
 * exist, and SVG content that is not script-safe is forced to an attachment
 * download instead of inline (CVE-2022-35195 area XSS guard).
 */
if ($action === 'download') {
    if (!bffAttachmentsEnabled()) {
        bffOut(['status' => 'error', 'message' => 'Attachments disabled'], 403);
    }
    $id = intval($_GET['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        exit;
    }
    global $g_repositoryType;
    $fileRepo = tlAttachmentRepository::create($db);
    $attachInfo = $fileRepo->getAttachmentInfo($id);
    if (!$attachInfo) {
        http_response_code(404);
        exit;
    }
    $content = $fileRepo->getAttachmentContent($id, $attachInfo);
    if ($content === '') {
        http_response_code(404);
        exit;
    }

    @ob_end_clean();
    $doEncode = ($g_repositoryType == TL_REPOSITORY_TYPE_DB);
    if ($doEncode) {
        $content = base64_decode($content);
    }

    $what2do = "Content-Disposition: inline;";
    if (strripos($content, "<!DOCTYPE svg") !== false ||
        strripos($content, "<svg") !== false) {
        if (!XSS_StringScriptSafe($content)) {
            $what2do = "Content-Disposition: attachment;";
        }
    }

    header('Pragma: public');
    header("Cache-Control: ");
    if (!(isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] == "on" &&
          preg_match("/MSIE/", $_SERVER["HTTP_USER_AGENT"]))) {
        header('Pragma: no-cache');
    }
    header('Content-Type: ' . $attachInfo['file_type']);
    header('Content-Length: ' . $attachInfo['file_size']);
    header($what2do . " filename=\"" . $attachInfo['file_name'] . "\"");
    header("Content-Description: Download Data");
    echo $content;
    exit;
}

// All remaining actions return JSON.
header('Content-Type: application/json; charset=utf-8');

if (!bffAttachmentsEnabled()) {
    bffOut(['status' => 'error', 'message' => 'Attachments disabled'], 403);
}

// fk_table must be a real table name. Legacy opens the dialog with an
// arbitrary string; we restrict upload/list/delete to known tables that
// TestLink 1.9.20 actually stores attachments for.
$KNOWN_TABLES = [
    'executions', 'tcsteps', 'tcversions', 'testcases',
    'requirement_specs', 'req_specs', 'requirements', 'testprojects',
    'testsuites', 'nodes_hierarchy', 'testplans',
];

/** @return int the attachment id on success, or exits with an error. */
function checkFk($needTable, $needId) {
    global $KNOWN_TABLES;
    if (!is_string($needTable) || !in_array($needTable, $KNOWN_TABLES, true)) {
        bffOut(['status' => 'error',
                'message' => 'Invalid attachment table'], 400);
    }
    $fkId = intval($needId);
    if ($fkId <= 0) {
        bffOut(['status' => 'error', 'message' => 'Invalid id'], 400);
    }
    return [$needTable, $fkId];
}

if ($action === 'list') {
    list($fkTable, $fkId) = checkFk(
        strval($_GET['table'] ?? ($_POST['table'] ?? '')),
        intval($_GET['id'] ?? ($_POST['id'] ?? 0))
    );
    $stdTableUsedAsFolder = str_replace(DB_TABLE_PREFIX, '', $fkTable);
    $attTables = tlObjectWithDB::getDBTables(array('attachments'));
    $rows = $db->get_recordset(
        "SELECT id, title, file_name, file_type, file_size, date_added " .
        "FROM {$attTables['attachments']} " .
        "WHERE fk_id = {$fkId} AND fk_table = '" .
        $db->prepare_string($stdTableUsedAsFolder) . "' ORDER BY id");
    $atts = [];
    if (!is_null($rows)) {
        foreach ($rows as $r) {
            $atts[] = bffAttachRow($r);
        }
    }
    bffOut(['status' => 'ok', 'attachments' => $atts,
            'fk_table' => $fkTable, 'fk_id' => $fkId,
            'max_size' => TL_REPOSITORY_MAXFILESIZE]);
}

if ($action === 'upload') {
    list($fkTable, $fkId) = checkFk(
        strval($_POST['table'] ?? ''),
        intval($_POST['id'] ?? 0)
    );
    $title = trim(strval($_POST['title'] ?? ''));
    $opt = null;
    if ($fkTable === 'executions') {
        $opt['allow_empty_title'] = true;
    }
    $repo = tlAttachmentRepository::create($db);
    $l2d = isset($_FILES['uploadedFile']['name'])
        ? count($_FILES['uploadedFile']['name']) - 1 : -1;
    if ($l2d < 0) {
        bffOut(['status' => 'error', 'message' => 'No file selected'], 400);
    }
    $uploaded = 0;
    $errors = [];
    for ($fdx = 0; $fdx <= $l2d; $fdx++) {
        $fSize = isset($_FILES['uploadedFile']['size'][$fdx])
            ? intval($_FILES['uploadedFile']['size'][$fdx]) : 0;
        $fTmpName = isset($_FILES['uploadedFile']['tmp_name'][$fdx])
            ? $_FILES['uploadedFile']['tmp_name'][$fdx] : '';
        $fType = isset($_FILES['uploadedFile']['type'][$fdx])
            ? $_FILES['uploadedFile']['type'][$fdx] : '';
        $fName = isset($_FILES['uploadedFile']['name'][$fdx])
            ? $_FILES['uploadedFile']['name'][$fdx] : '';
        $fErr = isset($_FILES['uploadedFile']['error'][$fdx])
            ? $_FILES['uploadedFile']['error'][$fdx] : 0;

        if ($fSize > 0 && $fTmpName !== '' && $fErr === 0) {
            $fin = [
                'name' => $fName, 'type' => $fType,
                'tmp_name' => $fTmpName, 'size' => $fSize, 'error' => $fErr,
            ];
            $uploadOp = $repo->insertAttachment($fkId, $fkTable, $title,
                                                $fin, $opt);
            if ($uploadOp->statusOK) {
                $uploaded++;
                logAuditEvent(TLS("audit_attachment_created",
                                  $title, $fName),
                              "CREATE", $fkId, "attachments");
            } else {
                $errors[] = strval($uploadOp->msg ?: $fName);
            }
        } else {
            $errors[] = strval(getFileUploadErrorMessage(
                ['name' => $fName, 'type' => $fType,
                 'tmp_name' => $fTmpName, 'size' => $fSize, 'error' => $fErr]));
        }
    }
    bffOut([
        'status' => ($uploaded > 0 || count($errors) === 0) ? 'ok' : 'error',
        'message' => $uploaded . ' file(s) uploaded',
        'uploaded' => $uploaded,
        'errors' => $errors,
    ]);
}

if ($action === 'delete') {
    list($fkTable, $fkId) = checkFk(
        strval($_POST['table'] ?? ''),
        intval($_POST['id'] ?? 0)
    );
    $fileId = intval($_POST['file_id'] ?? 0);
    if ($fileId <= 0) {
        bffOut(['status' => 'error', 'message' => 'Invalid file id'], 400);
    }
    // Hardening: attachment must exist AND belong to this fk_table/fk_id
    // (a forged file_id cannot remove attachments of other nodes).
    $stdTableUsedAsFolder = str_replace(DB_TABLE_PREFIX, '', $fkTable);
    $attTables = tlObjectWithDB::getDBTables(array('attachments'));
    $rows = $db->get_recordset(
        "SELECT id FROM {$attTables['attachments']} " .
        "WHERE id = {$fileId} AND fk_id = {$fkId} AND fk_table = '" .
        $db->prepare_string($stdTableUsedAsFolder) . "'");
    if (is_null($rows) || count($rows) === 0) {
        bffOut(['status' => 'error',
                'message' => 'Attachment not found for this object'], 404);
    }
    deleteAttachment($db, $fileId, false);
    bffOut(['status' => 'ok', 'message' => 'Attachment deleted',
            'deleted_id' => $fileId]);
}

bffOut(['status' => 'error', 'message' => 'Unknown action'], 400);