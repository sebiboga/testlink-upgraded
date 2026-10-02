<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * @filesource attachmentdownload.php
 *
 * 2.0.1 shim - Refs #1794: the legacy attachment download controller was
 * replaced by the modern popup gui/templates/attachments/attachmentDownload.html
 * + the BFF api/attachmentsdownload/index.php.
 *
 * WHY a redirect and not a deletion: this controller is still the src of every
 * inline attachment image that TestLink PRINTS - lib/functions/print.inc.php
 * (5 call sites), lib/functions/testcase.class.php:8396,
 * lib/functions/testsuite.class.php:1721 and
 * lib/functions/requirement_mgr.class.php:4060 all build
 * `lib/attachments/attachmentdownload.php?id=<id>[&skipCheck=<sha256>]`, and
 * gui/javascript/testlink_library.js getImageURL()/toogleImageURL() did too
 * (those two now point straight at the BFF). A 302 keeps every one of those
 * rendered documents working while moving the byte stream behind the BFF.
 *
 * SECURITY: the legacy page authorized NOTHING except
 * config_get('attachments')->enabled, so ANY authenticated user could stream
 * ANY attachment of the installation by guessing its id - filed as bug #1795,
 * fixed in Refs #1794 (the BFF resolves the owner from the STORED fk_table/fk_id row
 * and gates it with api/_attachauth.php, same right sets as the upload/delete
 * legs; cf. bug #1768 for the identical hole on the read side of the upload
 * API). The `skipCheck` token is now compared with hash_equals() and a mismatch
 * is an explicit 403 instead of a bare 404 page.
 *
 * The apikey (API) mode is NOT dropped: `?apikey=` requests are forwarded to
 * api/attachments/index.php?action=download, which implements the key check
 * (test plan / object key) that this file used to do inline.
 */

require_once('../../config.inc.php');
require_once('../functions/common.php');
require_once('../functions/attachments.inc.php');

// Session guard, exactly like the legacy testlinkInitPage($db): an anonymous
// deep link must land on the login screen, never on the bytes.
testlinkInitPage($db);

$id = intval($_REQUEST['id'] ?? $_REQUEST['attachment_id'] ?? 0);
$skipCheck = trim(strval($_REQUEST['skipCheck'] ?? ''));
$apikey = trim(strval($_REQUEST['apikey'] ?? ''));

$base = strval($_SESSION['basehref'] ?? '');
if ($base === '') {
    $base = '/';
}
$base = rtrim($base, '/');

if ($apikey !== '') {
    // API mode: keep the legacy key semantics on the modern endpoint.
    $url = $base . '/api/attachments/index.php?action=download&id=' . $id;
    if ($id > 0) {
        $url .= '&apikey=' . urlencode($apikey);
    }
} else {
    // GUI mode: an inline image / a browser download.
    $url = $base . '/api/attachmentsdownload/index.php?action=download'
        . '&disposition=inline&id=' . $id;
    if ($skipCheck !== '') {
        $url .= '&token=' . urlencode($skipCheck);
    }
}

header('Location: ' . $url);
exit;