<?php
/**
 * Attachment Download BFF API
 * URL: /api/attachmentsdownload/
 * Plain PHP, no framework, no compilation
 *
 * Modern twin of lib/attachments/attachmentdownload.php +
 * gui/templates/dashio/attachments/attachment404.tpl (TestLink 1.9.20).
 *
 * Legacy behaviour ported:
 *   - testlinkInitPage($db) -> the download required a session (the anonymous
 *     variant is commented out in the legacy file because of CVE-2022-35195),
 *     and checkRights() only asserts config_get('attachments')->enabled.
 *   - $fileRepo->getAttachmentInfo($id) then getAttachmentContent($id, $info);
 *     when either is empty the page rendered attachment404.tpl.
 *   - DB repository content is base64 decoded ($g_repositoryType ==
 *     TL_REPOSITORY_TYPE_DB).
 *   - SVG hardening: the disposition is downgraded from `inline` to
 *     `attachment` when the payload is not XSS_StringScriptSafe() - a stored
 *     SVG must never render script in the app origin.
 *   - skipCheck parity: when the caller supplies a check token it must equal
 *     hash('sha256', $attachInfo['file_name']), otherwise no content is served.
 *     The modern screen always receives that token from ?action=init.
 *   - headers: Pragma/Cache-Control/Content-Type/Content-Length/
 *     Content-Disposition/Content-Description, echoed body.
 *
 * Hardening vs legacy (all with a bug filed / documented in Refs #1794):
 *   - the legacy page authorised NOTHING beyond "attachments enabled": any
 *     authenticated user, including the global <no rights> role, could stream
 *     every attachment of the installation by guessing its id. Here the owner
 *     is resolved from the STORED row (fk_table/fk_id) and gated with the
 *     shared api/_attachauth.php right sets (Refs #1768 parity) - never from
 *     caller-supplied parameters.
 *   - the skipCheck token is compared with hash_equals() (timing safe) and a
 *     mismatch is an explicit 403 instead of the legacy bare 404 page.
 *   - Content-Type / Content-Disposition values are CRLF-stripped and the
 *     filename's double quotes are removed (header injection guard).
 *   - X-Content-Type-Options: nosniff on every response.
 *
 * Routes:
 *   GET ?action=init&id=N            -> JSON metadata + owner context
 *   GET ?action=download&id=N[&token=T][&disposition=inline|attachment]
 *                                    -> the authorized byte stream
 *   401 anonymous, 403 attachments disabled / not allowed / bad token,
 *   400 bad id or unknown action, 404 unknown attachment or empty content,
 *   405 wrong verb, 500 guarded.
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
require_once(__DIR__ . '/../_attachauth.php');
bffSameOriginGuard();

$db = new database(DB_TYPE);
doDBConnect($db);

/** JSON response + exit. */
function bffDlOut($data, $code = 200)
{
    $json = json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE);
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
    }
    echo ($json === false) ? '{"status":"error","code":"ENCODING_FAILED"}' : $json;
    exit;
}

/** Cached column probe (this fork ships a slimmed nodes_hierarchy schema). */
function bffDlHasColumn($db, $table, $col)
{
    static $cache = array();
    $key = $table . '.' . $col;
    if (!isset($cache[$key])) {
        $cache[$key] = false;
        $rows = $db->get_recordset("SHOW COLUMNS FROM " . $table);
        if (is_array($rows)) {
            foreach ($rows as $r) {
                if (strcasecmp(strval($r['Field'] ?? ''), $col) === 0) {
                    $cache[$key] = true;
                    break;
                }
            }
        }
    }
    return $cache[$key];
}

/** @return array|null first row or null. */
function bffDlFirstRow($db, $sql)
{
    $rows = $db->get_recordset($sql);
    if (is_null($rows) || count($rows) === 0) {
        return null;
    }
    return $rows[0];
}

/** Human readable file size (legacy attachment lists showed the raw bytes). */
function bffDlHumanSize($bytes)
{
    $bytes = intval($bytes);
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    $i = 0;
    $val = floatval($bytes);
    while ($val >= 1024 && $i < count($units) - 1) {
        $val /= 1024;
        $i++;
    }
    return ($i === 0 ? strval($bytes) : number_format($val, 1)) . ' ' . $units[$i];
}

/**
 * Human label of the object owning the attachment set. Unknown / unresolvable
 * owners fall back to '<table> #<id>' - the popup always states the owner.
 */
function bffDlOwnerLabel($db, $attachInfo)
{
    $fkTable = strval($attachInfo['fk_table'] ?? '');
    $fkId = intval($attachInfo['fk_id'] ?? 0);
    $fallback = ($fkTable !== '' ? $fkTable : '?') . ' #' . $fkId;
    if ($fkId <= 0) {
        return $fallback;
    }
    $t = tlObjectWithDB::getDBTables(
        array('testprojects', 'testplans', 'builds', 'executions',
              'nodes_hierarchy', 'req_specs', 'requirements'));

    switch ($fkTable) {
        case 'testprojects':
            $r = bffDlFirstRow($db, "SELECT name FROM {$t['testprojects']} " .
                "WHERE id = {$fkId} LIMIT 1");
            return is_null($r) ? $fallback
                : ($fkId . ' - ' . strval($r['name'] ?? ''));
        case 'testplans':
            $r = bffDlFirstRow($db, "SELECT name FROM {$t['testplans']} " .
                "WHERE id = {$fkId} LIMIT 1");
            return is_null($r) ? $fallback
                : ($fkId . ' - ' . strval($r['name'] ?? ''));
        case 'builds':
            $r = bffDlFirstRow($db, "SELECT name FROM {$t['builds']} " .
                "WHERE id = {$fkId} LIMIT 1");
            return is_null($r) ? $fallback
                : ($fkId . ' - ' . strval($r['name'] ?? ''));
        case 'executions':
            $r = bffDlFirstRow($db, "SELECT id FROM {$t['executions']} " .
                "WHERE id = {$fkId} LIMIT 1");
            return is_null($r) ? $fallback : ('Execution #' . $fkId);
        case 'req_specs':
        case 'requirement_specs':
            $r = bffDlFirstRow($db, "SELECT doc_id FROM {$t['req_specs']} " .
                "WHERE id = {$fkId} LIMIT 1");
            return is_null($r) ? $fallback
                : ($fkId . ' - ' . strval($r['doc_id'] ?? ''));
        case 'requirements':
            $r = bffDlFirstRow($db, "SELECT req_doc_id FROM {$t['requirements']} " .
                "WHERE id = {$fkId} LIMIT 1");
            return is_null($r) ? $fallback
                : ($fkId . ' - ' . strval($r['req_doc_id'] ?? ''));
        default:
            // nodes_hierarchy and everything else holds its label in `name`
            if (bffDlHasColumn($db, 'nodes_hierarchy', 'name')) {
                $r = bffDlFirstRow($db, "SELECT name FROM {$t['nodes_hierarchy']} " .
                    "WHERE id = {$fkId} LIMIT 1");
                if (!is_null($r)) {
                    $nm = trim(strval($r['name'] ?? ''));
                    return $fkId . ($nm !== '' ? ' - ' . $nm : '');
                }
            }
            return $fallback;
    }
}

/** Names of the test project / test plan the owner lives in. */
function bffDlContextNames($db, $ctx)
{
    $tprojectId = intval($ctx['tproject_id'] ?? 0);
    $tplanId = intval($ctx['tplan_id'] ?? 0);
    $t = tlObjectWithDB::getDBTables(array('testprojects', 'testplans'));
    $names = array('testproject' => '', 'testplan' => '');
    if ($tprojectId > 0) {
        $r = bffDlFirstRow($db, "SELECT name FROM {$t['testprojects']} " .
            "WHERE id = {$tprojectId} LIMIT 1");
        if (!is_null($r)) {
            $names['testproject'] = strval($r['name'] ?? '');
        }
    }
    if ($tplanId > 0) {
        $r = bffDlFirstRow($db, "SELECT name FROM {$t['testplans']} " .
            "WHERE id = {$tplanId} LIMIT 1");
        if (!is_null($r)) {
            $names['testplan'] = strval($r['name'] ?? '');
        }
    }
    return $names;
}

/** The check token handed to the client (legacy skipCheck parity). */
function bffDlToken($attachInfo)
{
    return hash('sha256', strval($attachInfo['file_name'] ?? ''));
}

$action = trim(strval($_GET['action'] ?? ''));
$method = strtoupper(strval($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method !== 'GET') {
    header('Allow: GET');
    bffDlOut(array(
        'status' => 'error',
        'code'   => 'METHOD_NOT_ALLOWED',
        'message' => 'This endpoint only answers GET',
    ), 405);
}

// ---- session authentication (legacy: testlinkInitPage($db)) ----------------
$userId = intval($_SESSION['userID'] ?? 0);
if ($userId <= 0) {
    bffDlOut(array(
        'status' => 'error',
        'code'   => 'NOT_AUTHENTICATED',
        'message' => 'Not authenticated',
    ), 401);
}
$currentUser = tlUser::getByID($db, $userId);
if (is_null($currentUser)) {
    bffDlOut(array(
        'status' => 'error',
        'code'   => 'NOT_AUTHENTICATED',
        'message' => 'User not found',
    ), 401);
}
bffEnforceSession($db);

// legacy checkRights() parity: config_get('attachments')->enabled
if (!(bool)config_get('attachments')->enabled) {
    bffDlOut(array(
        'status' => 'error',
        'code'   => 'ATTACHMENTS_DISABLED',
        'message' => 'Attachments are disabled',
    ), 403);
}

if ($action === '') {
    $action = 'init';
}

$id = intval($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    bffDlOut(array(
        'status' => 'error',
        'code'   => 'INVALID_ATTACHMENT_ID',
        'message' => 'A positive attachment id is required',
    ), 400);
}

$fileRepo = tlAttachmentRepository::create($db);
$attachInfo = $fileRepo->getAttachmentInfo($id);
if (!$attachInfo) {
    bffDlOut(array(
        'status' => 'error',
        'code'   => 'ATTACHMENT_NOT_FOUND',
        'message' => 'Attachment not found',
    ), 404);
}

// ---- object level authorization (Refs #1768 parity, fail closed) -----------
$fkTable = strval($attachInfo['fk_table'] ?? '');
$fkId = intval($attachInfo['fk_id'] ?? 0);
$ctx = attAuthResolveContext($db, $fkTable, $fkId);
if (!attAuthOwnerAllowed($db, $currentUser, $ctx)) {
    bffDlOut(array(
        'status' => 'error',
        'code'   => 'FORBIDDEN',
        'message' => 'No rights on the object owning this attachment',
    ), 403);
}

$fileName = strval($attachInfo['file_name'] ?? '');
$fileType = strval($attachInfo['file_type'] ?? '');
$token = bffDlToken($attachInfo);

if ($action === 'init') {
    $isSvg = (stripos($fileType, 'svg') !== false ||
              strtolower(substr($fileName, -4)) === '.svg');
    $isImage = (stripos($fileType, 'image/') === 0) && !$isSvg;
    $ctxNames = bffDlContextNames($db, $ctx);

    bffDlOut(array(
        'status'     => 'ok',
        'attachment' => array(
            'id'            => $id,
            'file_name'     => $fileName,
            'title'         => strval($attachInfo['title'] ?? ''),
            'description'   => strval($attachInfo['description'] ?? ''),
            'file_type'     => $fileType,
            'file_size'     => intval($attachInfo['file_size'] ?? 0),
            'file_size_human' => bffDlHumanSize(intval($attachInfo['file_size'] ?? 0)),
            'date_added'    => strval($attachInfo['date_added'] ?? ''),
            'token'         => $token,
            'is_image'      => $isImage,
            'is_svg'        => $isSvg,
            'can_preview'   => $isImage,
            'owner'         => array(
                'table'   => $fkTable,
                'id'      => $fkId,
                'label'   => bffDlOwnerLabel($db, $attachInfo),
                'domain'  => strval($ctx['domain'] ?? ''),
            ),
            'context'       => array(
                'tproject_id' => intval($ctx['tproject_id'] ?? 0),
                'tplan_id'    => intval($ctx['tplan_id'] ?? 0),
                'testproject' => $ctxNames['testproject'],
                'testplan'    => $ctxNames['testplan'],
            ),
            'download_url'  => '/api/attachmentsdownload/index.php?action=download&id='
                               . $id . '&token=' . urlencode($token),
            'inline_url'    => '/api/attachmentsdownload/index.php?action=download&id='
                               . $id . '&token=' . urlencode($token) .
                               '&disposition=inline',
        ),
    ));
}

if ($action !== 'download') {
    bffDlOut(array(
        'status' => 'error',
        'code'   => 'UNKNOWN_ACTION',
        'message' => 'Unknown action',
    ), 400);
}

// ---- the stream -----------------------------------------------------------
// Legacy skipCheck parity: a supplied token must match the file name hash.
$reqToken = trim(strval($_GET['token'] ?? ''));
if ($reqToken !== '' && !hash_equals($token, $reqToken)) {
    bffDlOut(array(
        'status' => 'error',
        'code'   => 'INVALID_TOKEN',
        'message' => 'Attachment check token mismatch',
    ), 403);
}

$content = $fileRepo->getAttachmentContent($id, $attachInfo);
if (is_null($content) || $content === '') {
    bffDlOut(array(
        'status' => 'error',
        'code'   => 'ATTACHMENT_EMPTY',
        'message' => 'Attachment has no content',
    ), 404);
}

@ob_end_clean();
global $g_repositoryType;
if ($g_repositoryType == TL_REPOSITORY_TYPE_DB) {
    $content = base64_decode($content);
}

$wantAttachment = (strtolower(trim(strval($_GET['disposition'] ?? ''))) === 'attachment');

// SVG hardening (legacy parity): never render an unsafe SVG in our origin.
if (strripos($content, "<!DOCTYPE svg") !== false ||
    strripos($content, "<svg") !== false) {
    if (!XSS_StringScriptSafe($content)) {
        $wantAttachment = true;
    }
}

$disposition = $wantAttachment ? 'attachment' : 'inline';

$safeType = str_replace(array("\r", "\n"), '', $fileType);
$safeName = str_replace(array("\r", "\n", '"'), '', $fileName);

header('Pragma: public');
header('Cache-Control: ');
if (!(isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] == "on" &&
      preg_match("/MSIE/", $_SERVER["HTTP_USER_AGENT"]))) {
    header('Pragma: no-cache');
}
header('Content-Type: ' . ($safeType !== '' ? $safeType : 'application/octet-stream'));
header('Content-Length: ' . strlen($content));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: ' . $disposition . '; filename="' . $safeName . '"');
header('Content-Description: Download Data');
echo $content;
exit;