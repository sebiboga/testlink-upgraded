<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * LEGACY per-platform metrics pie chart endpoint - SUPERSEDED, Refs #1906.
 *
 * In 1.9.20 this file drew a PNG pie chart with the vendored pChart library
 * for ONE test plan platform: execution-status totals (passed / failed /
 * blocked / not run) from
 * tlTestPlanMetrics::getStatusTotalsByPlatformForRender($tplan_id), labelled
 * via results.status_label. It was an orphan image endpoint (single
 * platform_id per call, no Smarty template).
 *
 * Its only gate was a bare checkRights() calling
 * hasRight('testplan_metrics') against the GLOBAL right, so it proved nothing
 * about the plan the numbers belonged to; tproject_id was blindly read from
 * the session and platform_id from $_REQUEST without ownership checks. On
 * 2.0.1 it could not run at all: the pChart includes it used were gone, so
 * every caller got a PHP fatal / HTTP 500.
 *
 * It is now a session-guarded launcher that redirects to the modern screen
 * (gui/templates/results/platformPieChart.html) served by
 * api/platformpiechart/index.php, which aggregates ALL platforms of the plan
 * with the same getStatusTotalsByPlatformForRender() the legacy pie drew,
 * enforces testplan_metrics on the OWNING test project and test plan and
 * answers a documented JSON contract. The legacy ?tplan_id= (and
 * ?tproject_id=) parameters are preserved.
 *
 * The XHR/crawler branch deliberately refuses instead of redirecting: the old
 * file was consumed by an <img> tag in some 1.9.20 themes, and a 302 would
 * hand that caller a Dashio HTML page where it expects PNG pixels.
 *
 * @package     TestLink
 * @filesource  platformPieChart.php
 */

require_once('../../config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

/**
 * Session gate, parity with the legacy testlinkInitPage() call: an anonymous
 * caller is bounced to the login screen preserving its destination.
 * checkSessionValid()'s own redirect is used (rather than a hand-rolled
 * header()): it walks up from dirname(SCRIPT_FILENAME) until it finds
 * login.php and supplies a root-relative REQUEST_URI destination — a relative
 * 'login.php' would resolve against /lib/results/ and 404 (Refs #1881).
 */
if (!checkSessionValid($db)) {
    exit;  // unreachable: the call above already redirected
}

$tplanId = isset($_GET['tplan_id']) ? intval($_GET['tplan_id']) : 0;
$tprojectId = isset($_GET['tproject_id']) ? intval($_GET['tproject_id']) : 0;
if ($tprojectId <= 0) {
    $tprojectId = intval($_SESSION['testprojectID'] ?? 0);
}

if ($tplanId <= 0) {
    // Nothing to address: answer a machine-readable error instead of guessing
    // a plan. The modern screen renders this state itself (ppc.missingContext).
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    http_response_code(400);
    echo json_encode(array('status' => 'error', 'code' => 'invalid_request',
        'message' => 'missing tplan_id'));
    exit;
}

// Browser navigation -> the modern screen. An XHR / <img> caller keeps the
// hard-fail contract: the legacy answer was a PNG, so handing a 302 to an
// <img> would render broken pixels instead of an error.
//
// A document navigation is identified by Sec-Fetch-Dest: document OR an Accept
// header that asks for text/html. Chrome/Firefox/Edge send BOTH - and their
// document Accept header also carries image/avif,image/webp,image/apng tokens -
// so a bare strpos($accept,'image/') test fired on EVERY real navigation and
// answered 405 instead of redirecting (Refs #1719). 'image/' may therefore only
// decide the caller when the request does not ask for HTML at all.
$accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
$isXhr = stripos((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''), 'XMLHttpRequest') !== false;
$dest = strtolower((string)($_SERVER['HTTP_SEC_FETCH_DEST'] ?? ''));
$isDocument = $dest === 'document' || strpos($accept, 'text/html') !== false;
if ($isXhr || $dest === 'empty' || $dest === 'image'
    || (!$isDocument && strpos($accept, 'image/') !== false)) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    http_response_code(405);
    echo json_encode(array('status' => 'error', 'code' => 'modern_endpoint_only',
        'message' => 'the chart is served by /api/platformpiechart/index.php'));
    exit;
}

$base = str_replace(array("\r", "\n"), '', strval($_SESSION['basehref'] ?? ''));
// Harden against a poisoned session: CRLF would allow header injection and a
// foreign scheme/host an open redirect. Prefer a root-relative base built from
// the session, falling back to '/' (same hardening as the #1947/#1948 shims).
if (preg_match('~^https?://[^/]+(/.+)$~i', $base, $m)) {
    $base = rtrim($m[1], '/') . '/';
}
if ($base === '' || $base[0] !== '/' || strpos($base, '//') === 0) {
    $base = '/';
}
$url = $base . 'gui/templates/results/platformPieChart.html?tplan_id=' . $tplanId;
if ($tprojectId > 0) {
    $url .= '&tproject_id=' . $tprojectId;
}

header('Location: ' . $url);
exit;