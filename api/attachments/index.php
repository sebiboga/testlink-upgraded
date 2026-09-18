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

$action = trim(strval($_GET['action'] ?? ($_POST['action'] ?? '')));

$userId = $_SESSION['userID'] ?? null;

// ---- legacy public share-link apikey path (Refs #1541) ----
// lnl.php ?type=file share links land on the download route with an apikey
// (32-char user key, or 64-char object key already swapped by the resolver).
// Anonymous object-key downloads are bound to the attachment's owning entity
// below (fail-closed). All other actions keep requiring a session user.
$publicApikey = isset($_GET['apikey']) ? trim((string)$_GET['apikey']) : '';
$isAnonFromKey = false;
if ($publicApikey !== '' && $action === 'download') {
    $userId = 0;
    $isAnonFromKey = true;
    if (strlen($publicApikey) === 32) {
        $apiUsers = tlUser::getByAPIKey($db, $publicApikey);
        if (!is_array($apiUsers) || count($apiUsers) !== 1) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Invalid API key']);
            exit;
        }
        $auRow = reset($apiUsers);
        $userId = intval($auRow['id'] ?? 0);
        $isAnonFromKey = false;
    }
}

if (!$isAnonFromKey) {
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
} else {
    $user = null;
}

/**
 * Bind a 64-char object key to the scope of an attachment download: the key
 * must belong to the entity that owns the attachment (execution -> test plan,
 * test plan / build / test project -> their own api_key), otherwise the
 * download is refused. Legacy only checked "some entity" (hippie env mode);
 * this is the fail-closed hardening for the modern public links.
 */
function bffAttachBindObjectKey(&$db, $attachInfo, $apikey) {
    $fkTable = strval($attachInfo['fk_table'] ?? '');
    $fkId = intval($attachInfo['fk_id'] ?? 0);
    $keyOf = '';
    if ($fkTable === 'executions') {
        $t = tlObjectWithDB::getDBTables(['executions', 'testplans']);
        $er = $db->get_recordset(
            "SELECT testplan_id FROM {$t['executions']} WHERE id=" . $fkId);
        if (is_null($er) || count($er) == 0) {
            return false;
        }
        $prs = $db->get_recordset(
            "SELECT api_key FROM {$t['testplans']} WHERE id=" . intval($er[0]['testplan_id']));
        if (is_null($prs) || count($prs) == 0) {
            return false;
        }
        $keyOf = strval($prs[0]['api_key']);
    } elseif ($fkTable === 'testplans') {
        $t = tlObjectWithDB::getDBTables(['testplans']);
        $prs = $db->get_recordset(
            "SELECT api_key FROM {$t['testplans']} WHERE id=" . $fkId);
        if (is_null($prs) || count($prs) == 0) {
            return false;
        }
        $keyOf = strval($prs[0]['api_key']);
    } elseif ($fkTable === 'builds') {
        $t = tlObjectWithDB::getDBTables(['builds']);
        $brs = $db->get_recordset(
            "SELECT testproject_id FROM {$t['builds']} WHERE id=" . $fkId);
        if (is_null($brs) || count($brs) == 0) {
            return false;
        }
        // builds link to a test project, not a single plan
        $ent = getEntityByAPIKey($db, $apikey, 'testproject');
        return is_array($ent) && intval($ent['id'] ?? 0) === intval($brs[0]['testproject_id']);
    } elseif ($fkTable === 'testprojects') {
        $ent = getEntityByAPIKey($db, $apikey, 'testproject');
        return is_array($ent) && intval($ent['id'] ?? 0) === $fkId;
    } elseif ($fkTable === 'nodes_hierarchy') {
        $ent = getEntityByAPIKey($db, $apikey, 'testproject');
        if (is_array($ent)) {
            return true;
        }
        $ent2 = getEntityByAPIKey($db, $apikey, 'testplan');
        return is_array($ent2);
    } else {
        // generic: any entity carrying this key (legacy hippie parity)
        $ent = getEntityByAPIKey($db, $apikey, 'testproject');
        if (is_array($ent)) {
            return true;
        }
        $ent2 = getEntityByAPIKey($db, $apikey, 'testplan');
        return is_array($ent2);
    }
    return ($keyOf !== '' && $keyOf === $apikey);
}

require_once(__DIR__ . '/../../lib/functions/attachments.inc.php');

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
    // Anonymous object-key downloads: the key must belong to the entity the
    // attachment belongs to (Refs #1541, fail-closed hardening vs the legacy
    // hippie "any entity with this key" check).
    if ($isAnonFromKey && !bffAttachBindObjectKey($db, $attachInfo, $publicApikey)) {
        http_response_code(403);
        echo json_encode(['status' => 'error',
                          'message' => 'API key not bound to attachment owner']);
        exit;
    }
    $content = $fileRepo->getAttachmentContent($id, $attachInfo);
    if (is_null($content) || $content === '') {
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
    $safeType = str_replace(["\r", "\n"], '', strval($attachInfo['file_type']));
    $safeName = str_replace(["\r", "\n", '"'], '', strval($attachInfo['file_name']));
    header('Content-Type: ' . $safeType);
    header('Content-Length: ' . $attachInfo['file_size']);
    header($what2do . " filename=\"" . $safeName . "\"");
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
    // Legacy stores fk_table prefix-stripped; accept the unprefixed form and
    // strip any configured DB prefix before the whitelist check.
    $stripped = $needTable;
    if (defined('DB_TABLE_PREFIX') && DB_TABLE_PREFIX !== '') {
        $stripped = str_replace(DB_TABLE_PREFIX, '', $needTable);
    }
    if (!is_string($needTable) ||
        !in_array($stripped, $KNOWN_TABLES, true)) {
        bffOut(['status' => 'error',
                'message' => 'Invalid attachment table'], 400);
    }
    $fkId = intval($needId);
    if ($fkId <= 0) {
        bffOut(['status' => 'error', 'message' => 'Invalid id'], 400);
    }
    return [$stripped, $fkId];
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
            if ($fSize > TL_REPOSITORY_MAXFILESIZE) {
                $errors[] = strval(getFileUploadErrorMessage(
                    ['name' => $fName, 'type' => $fType,
                     'tmp_name' => $fTmpName, 'size' => $fSize,
                     'error' => UPLOAD_ERR_FORM_SIZE]));
                continue;
            }
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
                $errMsg = strval($uploadOp->msg ?: $fName);
                if (is_null($uploadOp->msg) &&
                    isset($uploadOp->statusCode) && $uploadOp->statusCode) {
                    $tmpMsg = lang_get('FILE_UPLOAD_' . $uploadOp->statusCode);
                    if ($tmpMsg !== false) {
                        $errMsg = $tmpMsg;
                    }
                }
                $errors[] = $errMsg;
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
    $delInfo = deleteAttachment($db, $fileId, false);
    if (!$delInfo) {
        bffOut(['status' => 'error', 'message' => 'Attachment delete failed'],
               500);
    }
    bffOut(['status' => 'ok', 'message' => 'Attachment deleted',
            'deleted_id' => $fileId]);
}

bffOut(['status' => 'error', 'message' => 'Unknown action'], 400);