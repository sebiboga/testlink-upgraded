<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * @filesource  cfieldsExport.php
 *
 * 2.0.1 - Refs #2048: the legacy Smarty Custom Fields export screen is replaced
 * by gui/templates/cfields/cfieldsTransfer.html (tab Export XML), backed by
 * api/cfieldstransfer?action=export. This controller is kept only as a
 * session-guarded, non-mutating redirect shim for old deep links. The legacy
 * doExport handler is not replayed here: it wrote the XML with PHP's exit()
 * straight into the old form POST flow and had no rights gate on the download
 * itself beyond the page load.
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
    echo "CFE-405: legacy export POSTs are no longer accepted here.\n"
       . "Use gui/templates/cfields/cfieldsTransfer.html (api/cfieldstransfer?action=export).\n";
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
header('Location: ' . $base . 'gui/templates/cfields/cfieldsTransfer.html#export');
exit;
