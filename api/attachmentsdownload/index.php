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
 *     variant is commented out in the legacy file because of CVE-2022-35195).
 *     The legacy file DEFINED checkRights() but never passed it to
 *     testlinkInitPage() (lib/functions/common.php:538-542), so the gate below is
 *     NOT legacy parity: keeping it is a deliberate, stricter rule (an
 *     installation that disabled attachments after storing files used to keep
 *     serving them), and it is the only 403 that does not concern a right.
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
 *   - headers: Cache-Control: private, no-store (attachment bytes are rights
 *     gated, so no shared cache may replay them), Content-Type,
 *     Content-Length, X-Content-Type-Options, Content-Disposition (filename +
 *     RFC 5987 filename*), Content-Description, plus CSP sandbox +
 *     X-Frame-Options on the inline branch; echoed body.
 *
 * Hardening vs legacy (bug #1795 tracks the read-side hole; Refs #1794 the fix):
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
 *   400 bad id, unknown action or malformed disposition,
 *   404 unknown attachment or empty content, 405 wrong verb.
 *   bffSameOriginGuard() is still required at the top (house style for every
 *   api/<area>/index.php, and it turns a non-GET into 403 instead of 405 before
 *   this endpoint answers), but on a GET-only endpoint it has nothing to check:
 *   downloading bytes is not a state change.
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

/**
 * May this attachment be RENDERED in the app origin?
 * Attachments are user supplied, so anything that can execute or carry markup
 * (text/html, application/xhtml+xml, image/svg+xml) is refused: inline it would
 * be stored XSS with the victim's session. Shared by init() and the stream so
 * the "Open in new tab" button can never promise what the stream refuses.
 */
function bffDlCanInline($fileType, $isImage, $isSvg)
{
    if ($isSvg) {
        return false;
    }
    $lc = strtolower(trim(strval($fileType)));
    if ($isImage) {
        return ($lc !== '');
    }
    if ($lc === '') {
        return false;
    }
    if ($lc === 'application/pdf' || $lc === 'text/plain') {
        return true;
    }
    return strpos($lc, 'audio/') === 0 || strpos($lc, 'video/') === 0;
}

/** Human readable file size (legacy attachment lists showed the raw bytes). */
function bffDlHumanSize($bytes)
{
    $bytes = max(0, intval($bytes));
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
 * Name of a test project / test plan.
 *
 * SCHEMA NOTE (this fork): testprojects and testplans have NO `name` column -
 * every container label lives in nodes_hierarchy (testproject = node_type_id 1,
 * testplan = node_type_id 5), exactly like api/_attachauth.php resolves the
 * owning project. Querying testprojects.name kills the request with a DB access
 * error page, so the label MUST come from the node tree.
 *
 * @return string '' when the node does not exist or the type does not match
 */
function bffDlNodeName($db, $nodeId, $expectedTypeId = 0)
{
    // $expectedTypeId 0 = any node type
    $node = attAuthNode($db, $nodeId);
    if (is_null($node)) {
        return '';
    }
    if ($expectedTypeId > 0 && intval($node['node_type_id']) !== $expectedTypeId) {
        return '';
    }
    $t = tlObjectWithDB::getDBTables(array('nodes_hierarchy'));
    $row = attAuthFirstRow($db, "SELECT name FROM {$t['nodes_hierarchy']} " .
        "WHERE id = " . intval($nodeId) . " LIMIT 1");
    return is_null($row) ? '' : strval($row['name'] ?? '');
}

/**
 * Human label of the object owning the attachment set. Unknown / unresolvable
 * owners fall back to '<table> #<id>' - the popup always states the owner.
 */
function bffDlOwnerLabel($db, $attachInfo)
{
    $fkTable = strval($attachInfo['fk_table'] ?? '');
// Legacy stores fk_table prefix-stripped (tlAttachmentRepository:567), but
// normalise anyway so this leg cannot drift from api/attachmentsdelete.
if (defined('DB_TABLE_PREFIX') && DB_TABLE_PREFIX !== '') {
    $fkTable = str_replace(DB_TABLE_PREFIX, '', $fkTable);
}
    $fkId = intval($attachInfo['fk_id'] ?? 0);
    $fallback = ($fkTable !== '' ? $fkTable : '?') . ' #' . $fkId;
    if ($fkId <= 0) {
        return $fallback;
    }
    $t = tlObjectWithDB::getDBTables(
        array('builds', 'executions', 'req_specs', 'requirements'));
    $types = attAuthNodeTypes($db);

    switch ($fkTable) {
        case 'testprojects':
            // no testprojects.name in 2.0.1: the label is the tree root node
            $nm = bffDlNodeName($db, $fkId, intval($types['testproject']));
            return ($nm !== '') ? ($fkId . ' - ' . $nm) : $fallback;
        case 'testplans':
            // no testplans.name either: the plan label is node_type_id 5
            $nm = bffDlNodeName($db, $fkId, intval($types['testplan']));
            return ($nm !== '') ? ($fkId . ' - ' . $nm) : $fallback;
        case 'builds':
            $r = attAuthFirstRow($db, "SELECT name FROM {$t['builds']} " .
                "WHERE id = {$fkId} LIMIT 1");
            return is_null($r) ? $fallback
                : ($fkId . ' - ' . strval($r['name'] ?? ''));
        case 'executions':
            $r = attAuthFirstRow($db, "SELECT id FROM {$t['executions']} " .
                "WHERE id = {$fkId} LIMIT 1");
            return is_null($r) ? $fallback : ('Execution #' . $fkId);
        case 'req_specs':
        case 'requirement_specs':
            $r = attAuthFirstRow($db, "SELECT doc_id FROM {$t['req_specs']} " .
                "WHERE id = {$fkId} LIMIT 1");
            return is_null($r) ? $fallback
                : ($fkId . ' - ' . strval($r['doc_id'] ?? ''));
        case 'requirements':
            $r = attAuthFirstRow($db, "SELECT req_doc_id FROM {$t['requirements']} " .
                "WHERE id = {$fkId} LIMIT 1");
            return is_null($r) ? $fallback
                : ($fkId . ' - ' . strval($r['req_doc_id'] ?? ''));
        default:
            // nodes_hierarchy (suite / test case / version / step / ...) and any
            // other container holds its label in the node tree.
            $nm = bffDlNodeName($db, $fkId, 0);
            return ($nm !== '') ? ($fkId . ' - ' . $nm) : $fallback;
    }
}

/** Names of the test project / test plan the owner lives in. */
function bffDlContextNames($db, $ctx)
{
    $tprojectId = intval($ctx['tproject_id'] ?? 0);
    $tplanId = intval($ctx['tplan_id'] ?? 0);
    $types = attAuthNodeTypes($db);
    $names = array('testproject' => '', 'testplan' => '');
    if ($tprojectId > 0) {
        $names['testproject'] = bffDlNodeName($db, $tprojectId,
            intval($types['testproject']));
    }
    if ($tplanId > 0) {
        $names['testplan'] = bffDlNodeName($db, $tplanId,
            intval($types['testplan']));
    }
    return $names;
}

/** The check token handed to the client (legacy skipCheck parity). */
function bffDlToken($attachInfo)
{
    return hash('sha256', strval($attachInfo['file_name'] ?? ''));
}

// is_string guard: `?action[]=x` would emit an 'Array to string conversion'
// warning into the Event Viewer before the 400.
$rawAction = $_GET['action'] ?? '';
$action = is_string($rawAction) ? trim($rawAction) : '';
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

$id = intval($_GET['id'] ?? 0);
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
// Legacy stores fk_table prefix-stripped (tlAttachmentRepository:567), but
// normalise anyway so this leg cannot drift from api/attachmentsdelete.
if (defined('DB_TABLE_PREFIX') && DB_TABLE_PREFIX !== '') {
    $fkTable = str_replace(DB_TABLE_PREFIX, '', $fkTable);
}
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
    // same allowlist the stream enforces, so inline_url is never a false promise
    $canInline = bffDlCanInline($fileType, $isImage, $isSvg);
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
            'can_inline'    => $canInline,
            // legacy parity: getAttachmentInfo() flags an inline-capable image
            // with the marker [tlInlineImage]<attachment id>[/tlInlineImage]
            // (NOT base64 - the attachment lists turned it into an <img> whose
            // src pointed back at the download URL with an inline
            // disposition). preview_url is that URL, restricted to raster
            // images: an inline_url for text/html would be stored XSS in our own
            // origin (attachments are user uploaded), so it is not emitted.
            'preview_url'   => ($isImage && !$isSvg &&
                               strval($attachInfo['inlineString'] ?? '') !== '')
                ? '/api/attachmentsdownload/index.php?action=download&id=' . $id
                  . '&token=' . urlencode($token) . '&disposition=inline'
                : '',
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
            // the CTA of the screen: MUST be disposition=attachment. A missing
            // disposition means `attachment` here (fail closed) - a link built
            // without it would render a PDF/text/html in a tab instead of saving
            // the file, and text/html is stored XSS in our own origin.
            'download_url'  => '/api/attachmentsdownload/index.php?action=download&id='
                               . $id . '&token=' . urlencode($token) .
                               '&disposition=attachment',
            // what "Open in new tab" uses - empty when the stream would refuse
            // to render it, so the button is never a lie.
            'inline_url'    => $canInline
                ? '/api/attachmentsdownload/index.php?action=download&id=' . $id
                  . '&token=' . urlencode($token) . '&disposition=inline'
                : '',
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
    // base64_decode() returns false on a corrupt payload; a false here would
    // make strlen() a TypeError on PHP 8, so an undecodable row is an error.
    $decoded = base64_decode($content);
    if (is_string($decoded)) {
        $content = $decoded;
    }
}
if (!is_string($content)) {
    bffDlOut(array(
        'status' => 'error',
        'code'   => 'ATTACHMENT_EMPTY',
        'message' => 'Attachment content could not be decoded',
    ), 404);
}
$safeType = str_replace(array("\r", "\n"), '', $fileType);

// Disposition is a whitelist, and the DEFAULT is `attachment` (fail closed):
// only an explicit disposition=inline renders in the app origin.
$reqDisposition = strtolower(trim(strval($_GET['disposition'] ?? '')));
if ($reqDisposition !== '' &&
    $reqDisposition !== 'inline' && $reqDisposition !== 'attachment') {
    bffDlOut(array(
        'status' => 'error',
        'code'   => 'INVALID_DISPOSITION',
        'message' => 'disposition must be inline or attachment',
    ), 400);
}
$wantAttachment = ($reqDisposition !== 'inline');

// Content types that may be rendered inline. Everything else - notably
// text/html, application/xhtml+xml and image/svg+xml - is served as a download:
// attachments are user supplied, so an inline text/html would run script in the
// TestLink origin with the victim's session (nosniff does not help, the type
// itself is the problem).
$inlineType = false;
if (!$wantAttachment) {
    $lcType = strtolower(trim($safeType));
    $inlineType = ($lcType !== '' &&
        (strpos($lcType, 'image/') === 0 ||
         strpos($lcType, 'audio/') === 0 ||
         strpos($lcType, 'video/') === 0 ||
         $lcType === 'application/pdf' ||
         $lcType === 'text/plain'));
}


// SVG hardening (legacy parity): never render an unsafe SVG in our origin.
if (strripos($content, "<!DOCTYPE svg") !== false ||
    strripos($content, "<svg") !== false) {
    if (!XSS_StringScriptSafe($content)) {
        $wantAttachment = true;
        $inlineType = false;
    }
}

$disposition = ($wantAttachment || !$inlineType) ? 'attachment' : 'inline';

// Filename: drop every control byte (header injection + display), keep the
// printable ones, and add the RFC 5987 form for non-ASCII names.
$safeName = preg_replace('/[\x00-\x1F\x7F]/', '', $fileName);
$safeName = str_replace('"', '', $safeName);
$asciiName = preg_replace('/[^\x20-\x7E]/', '_', $safeName);

header('Cache-Control: private, no-store, max-age=0');
header('Content-Type: ' . ($safeType !== '' ? $safeType : 'application/octet-stream'));
header('Content-Length: ' . strlen($content));
header('X-Content-Type-Options: nosniff');
// RFC 6266: one header carrying both forms (a second header() with the same
// name would be dropped by some clients).
$cd = 'Content-Disposition: ' . $disposition . '; filename="' . $asciiName . '"';
if ($safeName !== $asciiName) {
    $cd .= "; filename*=UTF-8''" . rawurlencode($safeName);
}
header($cd);
if ($disposition === 'inline') {
    // belt and braces for the inline branch: no script, no plugins, no framing
    header("Content-Security-Policy: sandbox; default-src 'none'; img-src data:; style-src 'unsafe-inline'; script-src 'none'");
    header('X-Frame-Options: DENY');
}
header('Content-Description: Download Data');
echo $content;
exit;