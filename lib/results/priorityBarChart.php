<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * LEGACY priority bar chart endpoint - SUPERSEDED, Refs #1845.
 *
 * In 1.9.20 this file drew a STACKED COLUMN chart image with the vendored
 * phpchart library: one column per keyword, four series (pass / fail /
 * blocked / not run) over ALL_TEST_SUITES, ALL_BUILDS and ALL_PLATFORMS, data
 * from results::getAggregateKeywordResults(). Its own first line said
 * "@TODO this file seems not to be in use" and it was never reached from any
 * menu, template or JavaScript.
 *
 * On 2.0.1 it could not run at all: BOTH includes are gone
 * (third_party/charts/charts.php and lib/functions/results.class.php), so the
 * include was a hard PHP fatal and every caller got HTTP 500 with no body. It
 * also had no authorization at all - $tplan_id came straight out of
 * $_REQUEST and was never checked for ownership or for a right, so any
 * authenticated user could read the per-keyword execution breakdown of ANY
 * test plan.
 *
 * It is now a session-guarded launcher that redirects to the modern screen
 * (gui/templates/results/priorityBarChart.html) served by
 * api/prioritybarchart/index.php, which enforces testplan_metrics on the
 * OWNING test project and test plan and answers a documented JSON contract.
 * The legacy ?tplan_id= (and ?tproject_id=) parameters are preserved.
 *
 * The XHR/crawler branch deliberately refuses instead of redirecting: the old
 * file was consumed by an <img> tag in some 1.9.20 themes, and a 302 would
 * hand that caller a Dashio HTML page where it expects PNG pixels.
 *
 * @package    TestLink
 * @filesource priorityBarChart.php
 */

require_once('../../config.inc.php');
require_once('common.php');

/**
 * Session gate, parity with the legacy testlinkInitPage() call: an anonymous
 * caller is bounced to the login screen preserving its destination.
 */
if (($_SESSION['userID'] ?? 0) <= 0) {
    $dest = basename(__FILE__) . '?' . (isset($_SERVER['QUERY_STRING'])
        ? $_SERVER['QUERY_STRING'] : '');
    header('Location: ../login.php?note=expired&destination=' . urlencode($dest));
    exit;
}

$tplanId = isset($_GET['tplan_id']) ? intval($_GET['tplan_id']) : 0;
$tprojectId = isset($_GET['tproject_id']) ? intval($_GET['tproject_id']) : 0;
if ($tprojectId <= 0) {
    $tprojectId = intval($_SESSION['testprojectID'] ?? 0);
}

if ($tplanId <= 0) {
    // Nothing to address: answer a machine-readable error instead of guessing
    // a plan. The modern screen renders this state itself (pbc.missingContext).
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
        'message' => 'the chart is served by /api/prioritybarchart/index.php'));
    exit;
}

$base = isset($_SESSION['basehref']) ? $_SESSION['basehref'] : '../';
$url = $base . 'gui/templates/results/priorityBarChart.html?tplan_id=' . $tplanId;
if ($tprojectId > 0) {
    $url .= '&tproject_id=' . $tprojectId;
}

header('Location: ' . $url);
exit;