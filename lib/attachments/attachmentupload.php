<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * @filesource attachmentupload.php
 *
 * 2.0.1 shim - Refs #1947 (screen #1623-series attachments modernisation): the
 * legacy Smarty Attachment Upload popup
 * (gui/templates/dashio/attachments/attachmentupload.tpl) was replaced by the
 * modern Dashio popup gui/templates/attachments/attachmentUpload.html + the
 * api/attachments BFF (`?action=upload|list`). This controller is kept as a
 * session-guarded, non-mutating redirect shim so old deep links and the legacy
 * openFileUploadWindow() helper keep resolving: anonymous users are sent to the
 * login screen (legacy testlinkInitPage behaviour) and authenticated users land
 * on the modern popup with the owning object (fk_table + fk_id) forwarded.
 *
 * Siblings already retired the same way: attachmentdelete.php (#1638) and
 * attachmentdownload.php (#1794); this was the last attachment controller still
 * rendering Smarty.
 *
 * WHY the legacy page was unsafe: it authorized NOTHING but the global
 * `config_get('attachments')->enabled` flag and - after a plain GET - stashed
 * `$_SESSION['s_upload_id']` / `$_SESSION['s_upload_tableName']` straight from
 * the query string, then let a POST upload insert into ANY table. The BFF now
 * proves the caller holds the object-owner right (api/_attachauth.php, Refs
 * #1768) before an upload, and writes require a POST behind
 * bffSameOriginGuard().
 *
 * The shim NEVER mutates anything (the legacy GET side effect of writing the
 * session upload target is dropped), so simply opening this URL can no longer
 * prime a subsequent upload.
**/
require_once('../../config.inc.php');
require_once('../functions/common.php');

// Anonymous -> login bounce comes from checkSessionValid() inside
// testlinkInitPage(); no rights callback is passed because the legacy
// checkRights() only gated on the global attachments flag while the modern
// BFF re-checks both the flag and the object ownership.
testlinkInitPage($db, FALSE, false, null);

$id = isset($_REQUEST['id']) && is_scalar($_REQUEST['id'])
    ? intval($_REQUEST['id']) : 0;
$table = isset($_REQUEST['table']) && is_scalar($_REQUEST['table'])
    ? trim((string) $_REQUEST['table']) : '';
// legacy helper used `tableName` on some call sites
if ($table === '' && isset($_REQUEST['tableName']) && is_scalar($_REQUEST['tableName'])) {
    $table = trim((string) $_REQUEST['tableName']);
}

$base = strval($_SESSION['basehref'] ?? '');
// Harden against a poisoned session: CRLF would allow header injection and a
// foreign scheme/host an open redirect. Only a same-app root-relative base is
// accepted; anything else falls back to '/'.
$base = str_replace(array("\r", "\n"), '', $base);
if ($base === '' || $base[0] !== '/') {
    $base = '/';
}
$base = rtrim($base, '/');

$url = $base . '/gui/templates/attachments/attachmentUpload.html';
$url .= '?id=' . $id;
if ($table !== '') {
    $url .= '&table=' . rawurlencode($table);
}

header('Location: ' . $url);
exit;
