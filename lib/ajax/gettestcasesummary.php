<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 *
 * @filesource  gettestcasesummary.php
 * @package     TestLink
 * @copyright   2005-2018 TestLink community
 * @author      Francisco Mancardi
 *
 * @internal revisions
 */
//
// Test Case Summary - legacy ExtJS tooltip backend. MODERNIZED as Refs #1767:
//
//   modern screen  gui/templates/testcases/tcSummary.html
//   modern BFF     api/tcsummary/index.php
//
// This file is kept ONLY as a compatibility launcher.
//
// WHY it could not stay as it was - all three defects are real, not cosmetic:
//
//   1. It performed NO authorization check whatsoever. testlinkInitPage($db)
//      only validates the SESSION, and tcase_id came straight out of
//      $_REQUEST, so ANY authenticated user - including one with no test case
//      management right at all - could read the summary of a test case
//      belonging to ANY test project. Same class as the sibling open bugs
//      #1696 (getrequirementnodes.php) and #1679.
//
//   2. It echoed the stored RichEdit summary RAW into the caller DOM, where
//      the ExtJS tooltip injected it as HTML. Any markup an author had ever
//      saved in a summary therefore executed in every viewer's browser
//      (stored XSS). The BFF returns the summary as a data field and the
//      modern screen renders it as escaped plain text.
//
//   3. testcase::get_last_version_info() and get_by_id() can both return NULL,
//      and the result was dereferenced unconditionally ($tcase['summary']),
//      which is a PHP 8 warning plus an empty tooltip.
//
// WHAT CHANGED FOR THE THREE LEGACY CALLERS, stated plainly:
// gui/templates/dashio/plan/planAddTC_m1.tpl, planAddTCJS.inc.tpl and
// gui/templates/tl-classic/plan/planAddTC_m1.tpl still build an Ext.ToolTip
// whose autoLoad points at this file. Ext 3.4 sends X-Requested-With by
// default (useDefaultXhrHeader), so they now receive the 405 JSON branch and
// their tooltip is EMPTY. That is intentional: it is the only way to close the
// no-rights-check read and the raw-HTML echo for those frames as well, and the
// Dashio workframe - the one actually shipped in 2.0.1 - gets the summary back
// through its own per-row button (gui/templates/plans/planAddTCView.html).
//
// Contract kept for everything else: a browser navigation is answered with a
// 302 to the modern popup carrying the same tcase_id / tcversion_id; anything
// else (an XHR / a crawler / a REST client) is refused with 405, because the
// modern BFF is the only sanctioned reader now. Anonymous callers keep the
// legacy testlinkInitPage() behaviour and are sent to the login screen with
// the ?destination= bookmark preserved.

require_once('../../config.inc.php');
require_once('common.php');

$base = isset($_SESSION['basehref']) ? $_SESSION['basehref'] : '/';

// Anonymous: legacy testlinkInitPage() contract (destination preserved).
$tcsumInactive = isset($_SESSION['expires_on'])
              && intval($_SESSION['expires_on']) < time();
if (empty($_SESSION['userID']) || intval($_SESSION['userID']) <= 0 || $tcsumInactive) {
    $dest = 'lib/ajax/gettestcasesummary.php';
    if (isset($_SERVER['QUERY_STRING'])) {
        $dest .= '?' . $_SERVER['QUERY_STRING'];
    }
    $login = $base . 'login.php?note=expired&destination='
           . urlencode($dest);
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])
        || strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode(array('status' => 'error', 'code' => 'not_authenticated',
                               'message' => 'Not authenticated'));
        exit;
    }
    header('Location: ' . $login, true, 302);
    exit;
}

/**
 * True when this is NOT a top-level browser navigation: an XHR/fetch
 * (X-Requested-With on older clients, Sec-Fetch-Dest on modern ones) or a
 * client that does not accept HTML. A plain address-bar / window.open()
 * navigation has Sec-Fetch-Dest: document and an Accept list containing
 * text/html.
 */
function tcsummary_isNavigation()
{
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'compatibility') {
        return false;
    }
    $dest = strtolower($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '');
    if ($dest !== '' && $dest !== 'document' && $dest !== 'iframe') {
        return false;
    }
    $accept = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');
    if ($accept !== '' && strpos($accept, 'text/html') === false
        && strpos($accept, 'application/xhtml') === false) {
        return false;
    }
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET';
}

// Non-navigational request: the modern BFF replaces this endpoint.
if (!tcsummary_isNavigation()) {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode(array(
        'status' => 'error',
        'code' => 'method_not_allowed',
        'message' => 'This legacy endpoint no longer serves data; use '
                   . '/api/tcsummary/index.php?action=summary&tcase_id=<id>',
        'bff_url' => '/api/tcsummary/index.php?action=summary&tcase_id='
                   . (isset($_GET['tcase_id']) && is_scalar($_GET['tcase_id'])
                      ? rawurlencode((string)$_GET['tcase_id']) : ''),
    ));
    exit;
}

// Browser navigation: hand over to the modern popup, ids preserved.
$query = array();
if (isset($_GET['tcase_id']) && is_scalar($_GET['tcase_id'])
    && preg_match('/^[0-9]+$/', trim((string)$_GET['tcase_id']))) {
    $query['tcase_id'] = intval($_GET['tcase_id']);
}
if (isset($_GET['tcversion_id']) && is_scalar($_GET['tcversion_id'])
    && preg_match('/^[0-9]+$/', trim((string)$_GET['tcversion_id']))) {
    $query['tcversion_id'] = intval($_GET['tcversion_id']);
}
// Forward the current context so the deep link survives; the modern BFF treats
// tproject_id as a client-side ASSERTION only and refuses a mismatch.
$ctx = array();
if (isset($_SESSION['testprojectID']) && intval($_SESSION['testprojectID']) > 0) {
    $ctx['tproject_id'] = intval($_SESSION['testprojectID']);
} elseif (isset($_GET['tproject_id']) && is_scalar($_GET['tproject_id'])
    && preg_match('/^[0-9]+$/', trim((string)$_GET['tproject_id']))) {
    $ctx['tproject_id'] = intval($_GET['tproject_id']);
}
if (isset($_SESSION['testplanID']) && intval($_SESSION['testplanID']) > 0) {
    $ctx['tplan_id'] = intval($_SESSION['testplanID']);
}
$query = array_merge($ctx, $query);

header('Location: ' . $base . 'gui/templates/testcases/tcSummary.html'
       . (count($query) ? '?' . http_build_query($query) : ''), true, 302);
exit;
