<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * @filesource  reqSpecSearch.php
 * @package     TestLink
 * @link        http://www.teamst.org/index.php
 *
 * Requirement Specification SEARCH RESULTS - MODERNIZED (Dashio standalone
 * page) - Refs #1825
 *
 * The legacy renderer (reqSpecSearch.php + gui/templates/dashio/requirements/
 * reqSpecSearchResults.tpl) has been replaced by:
 *
 *   gui/templates/requirements/searchReqSpec.html     the screen
 *   api/requirements/index.php                        the BFF
 *                                                      (GET ?action=reqspec-context
 *                                                       GET ?action=reqspec-search)
 *
 * and its form, the also-legacy lib/requirements/reqSpecSearchForm.php, by
 * gui/templates/requirements/reqSpecSearchForm.html (api/reqspecsearchform).
 *
 * Like its sibling this controller authorized NOTHING but the session: the test
 * project came from $_SESSION['testprojectID'] and every criterion from the
 * request body, so any authenticated user could run a search across a project
 * they hold no requirement right on.
 *
 * Criteria translation (legacy -> modern), all values preserved verbatim:
 *   requirement_document_id -> doc_id      (the modern name; same LIKE match on
 *                                          req_specs_revisions.doc_id)
 *   name, scope, reqSpecType, log_message, custom_field_value, custom_field_id
 *                                       -> unchanged
 *   coverage                 -> DROPPED, deliberately. The legacy ExtJS grid
 *                                rendered a live "coverage" column and sorted
 *                                on it, but it was not a filter criterion of the
 *                                query at all (init_args collects it and nothing
 *                                ever reads it), so dropping it changes no row.
 *                                The modern results screen shows the coverage of
 *                                the requirement specifications it returns.
 *
 * This file is a redirect ONLY: it holds no state and runs no legacy code path.
 */
require_once('../../config.inc.php');
require_once('../functions/common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

// Legacy testlinkInitPage() contract (see the sibling shim).
if (!checkSessionValid($db)) {
    exit;  // unreachable: the call above already redirected
}

$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';

if ($method !== 'GET' && $method !== 'HEAD') {
    tLog('BFF shim: refused ' . $method . ' on the retired legacy requirement-spec '
        . 'search results - use GET /api/requirements/index.php?action=reqspec-search '
        . '(Refs #1825).', 'WARNING');
    http_response_code(405);
    header('Allow: GET, HEAD');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array(
        'status'  => 'error',
        'code'    => 'wrong_method',
        'message' => 'This legacy endpoint is retired. Open '
            . '/gui/templates/requirements/searchReqSpec.html',
    ));
    exit;
}

$wantsJson = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] !== '')
    || (isset($_SERVER['HTTP_SEC_FETCH_DEST']) && $_SERVER['HTTP_SEC_FETCH_DEST'] !== 'document')
    || stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;

if ($wantsJson) {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array(
        'status'  => 'error',
        'code'    => 'retired_endpoint',
        'message' => 'This legacy endpoint no longer serves the search results. Open '
            . '/gui/templates/requirements/searchReqSpec.html',
    ));
    exit;
}

// strings_stripSlashes() parity: the legacy controller unslashed the request
// before trimming, so a Windows-client bookmark arrives with the same values the
// modern screen would have received.
$_REQUEST = strings_stripSlashes($_REQUEST);

$q = array();

// The legacy controller used the SESSION project only.
$tprojectId = isset($_SESSION['testprojectID']) ? intval($_SESSION['testprojectID']) : 0;
if ($tprojectId > 0) {
    $q['tproject_id'] = $tprojectId;
}
if (isset($_REQUEST['tplan_id']) && intval($_REQUEST['tplan_id']) > 0) {
    $q['tplan_id'] = intval($_REQUEST['tplan_id']);
}

$map = array(
    'requirement_document_id' => 'doc_id',
    'name'                    => 'name',
    'scope'                   => 'scope',
    'reqSpecType'             => 'reqSpecType',
    'log_message'             => 'log_message',
    'custom_field_value'      => 'custom_field_value',
);
foreach ($map as $legacyKey => $modernKey) {
    if (isset($_REQUEST[$legacyKey]) && trim((string)$_REQUEST[$legacyKey]) !== '') {
        $q[$modernKey] = trim((string)$_REQUEST[$legacyKey]);
    }
}
if (isset($_REQUEST['custom_field_id']) &&
    preg_match('/^[0-9]+$/', trim((string)$_REQUEST['custom_field_id'])) === 1 &&
    intval(trim((string)$_REQUEST['custom_field_id'])) > 0) {
    $q['custom_field_id'] = intval(trim((string)$_REQUEST['custom_field_id']));
}

// A deep link that carried criteria means "run this search", not "show me the
// form again" - that is exactly what auto_search=1 tells the modern screen.
if (count($q) > 1) {
    $q['auto_search'] = '1';
}

$url = '/gui/templates/requirements/searchReqSpec.html';
if (!empty($q)) {
    $url .= '?' . http_build_query($q);
}
header('Location: ' . $url, true, 302);
exit;