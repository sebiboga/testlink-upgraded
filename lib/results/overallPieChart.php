<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * LEGACY overall metrics pie chart endpoint - SUPERSEDED, Refs #1907.
 *
 * In 1.9.20 this file drew a PNG pie chart with the vendored pChart library
 * (third_party/pchart) for ONE test plan: execution-status totals (passed /
 * failed / blocked / not run) returned by
 * tlTestPlanMetrics::getExecCountersByExecStatus($tplan_id), labelled via
 * results.status_label and coloured via results.charts.status_colour. It was an
 * orphan image endpoint (no Smarty template): the Charts screen re-drew the same
 * numbers client-side with Chart.js, but exposed no numeric breakdown, no totals
 * row and no CSV export of this pie.
 *
 * Its only gate was a bare checkRights() calling
 * hasRight('testplan_metrics') against the GLOBAL right, so it proved nothing
 * about the plan the numbers belonged to; tproject_id was blindly read from the
 * session and tplan_id from $_REQUEST without ownership checks. It also returned
 * a blank PNG (a bare die()) whenever the plan had no builds (issue #634).
 *
 * It is now a session-guarded launcher that redirects to the modern screen
 * (gui/templates/results/overallPieChart.html) served by
 * api/overallpiechart/index.php, which reports the same getExecCountersByExecStatus()
 * counters as a documented JSON contract - per-status quantities, percentages and
 * totals plus CSV export - and enforces testplan_metrics on the OWNING test
 * project and test plan instead of the global right. The legacy ?tplan_id= (and
 * ?tproject_id=) parameters are preserved.
 *
 * The XHR/crawler branch deliberately refuses instead of redirecting: the old
 * file was consumed by an <img> tag in some 1.9.20 themes, and a 302 would
 * hand that caller a Dashio HTML page where it expects PNG pixels.
 *
 * @package     TestLink
 * @filesource  overallPieChart.php
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
    // a plan. The modern screen renders this state itself (opc.missingContext).
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
        'message' => 'the chart is served by /api/overallpiechart/index.php'));
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
$url = $base . 'gui/templates/results/overallPieChart.html?tplan_id=' . $tplanId;
if ($tprojectId > 0) {
    $url .= '&tproject_id=' . $tprojectId;
}

header('Location: ' . $url);
exit;
