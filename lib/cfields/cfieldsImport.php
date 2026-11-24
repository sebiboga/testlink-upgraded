<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * @filesource  cfieldsImport.php
 *
 * 2.0.1 - Refs #2048: the legacy Smarty Custom Fields import screen is replaced
 * by gui/templates/cfields/cfieldsTransfer.html (tab Import XML) and by the
 * api/cfieldstransfer BFF. This controller is kept only as a session-guarded,
 * non-mutating redirect shim for old deep links. The legacy doImport handler
 * used simplexml_load_file_wrapper() which die()d on invalid XML, had no upload
 * size cap and wrote every definition straight from the uploaded document; all
 * of that now lives in the BFF's JSON POST ?action=import route.
 */
require_once(dirname(__FILE__) . '/../../config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET'
    && strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
    header('Content-Type: text/plain; charset=utf-8');
    http_response_code(405);
    header('Allow: GET, HEAD');
    echo "CFI-405: legacy import POSTs are no longer accepted here.\n"
       . "Use gui/templates/cfields/cfieldsTransfer.html (api/cfieldstransfer?action=import).\n";
    exit;
}

if (!checkSessionValid($db)) {
    exit;
}

$base = str_replace(array("\r", "\n"), '', strval($_SESSION['basehref'] ?? ''));
if (preg_match('~^https?://[^/]+(/.+)$~i', $base, $m)) {
    $base = rtrim($m[1], '/') . '/';
}
if ($base === '' || $base[0] !== '/' || strpos($base, '//') === 0) {
    $base = '/';
}
header('Location: ' . $base . 'gui/templates/cfields/cfieldsTransfer.html#import');
exit;
