<?php
/**
 * Requirement Specification Search FORM - BFF API
 * URL: /api/reqspecsearchform/index.php
 *
 * Modern, rights-checked replacement for the legacy search-form page
 * lib/requirements/reqSpecSearchForm.php (Refs #1825).
 *
 * The legacy controller is a FULL legacy renderer
 * (testlinkInitPage() -> init_args() -> TLSmarty->display('reqSpecSearchForm.tpl'))
 * and it authorized NOTHING but the session: `$gui->tproject_id` came straight
 * from $_SESSION['testprojectID'] and the design-time custom fields of that
 * project were listed to any authenticated user, including one holding no
 * requirement right at all (same class as #1696 / #1765 / #1770).
 *
 * Contract:
 *   GET|HEAD ?action=init
 *          &tproject_id=N
 *          [&doc_id=..][&name=..][&reqSpecType=..][&scope=..][&log_message=..]
 *          [&custom_field_id=N][&custom_field_value=..]
 *
 * Read-only. Every value needed to RENDER the criteria form is returned as
 * DATA (never as markup), so a custom field label or a req-spec type label can
 * never inject anything into the page.
 *
 *   200 {status:ok, context:{...}, types:[...], customFields:[...], criteria:{...}}
 *   400 invalid_tproject          - tproject_id is not a positive integer
 *   401 not_authenticated         - no session
 *   403 no_right                  - no mgt_view_req on the ADDRESSED project
 *   404 tproject_not_found        - unknown test project
 *   405 wrong_method              - non GET/HEAD
 *   500 server_error              - guarded
 */
require_once(__DIR__ . '/../../config.inc.php');
require_once(__DIR__ . '/../../config_db.inc.php');
require_once('common.php');

doSessionStart();
require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$db = new database(DB_TYPE);
doDBConnect($db);

function out($data) { echo json_encode($data); exit; }

function failOut($http, $message, $machine)
{
    http_response_code($http);
    out(array('status' => 'error', 'code' => $machine, 'message' => $message));
}

set_exception_handler(function ($e) {
    error_log('api/reqspecsearchform: ' . $e->getMessage());
    if (!headers_sent()) { http_response_code(500); }
    echo json_encode(array('status' => 'error', 'code' => 'server_error',
        'message' => 'Internal error'));
    exit;
});

$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
$action = isset($_REQUEST['action']) ? trim((string)$_REQUEST['action']) : 'init';

if ($action !== 'init') {
    failOut(400, 'Unknown action', 'unknown_action');
}

// GET and HEAD read the same payload and write nothing; a link checker sends a
// HEAD, so it must not be answered "wrong method" (api/reqmonitors parity).
if ($method !== 'GET' && $method !== 'HEAD') {
    http_response_code(405);
    header('Allow: GET, HEAD');
    failOut(405, 'This action only accepts GET', 'wrong_method');
}

$userId = isset($_SESSION['userID']) ? intval($_SESSION['userID']) : 0;
if ($userId <= 0) {
    failOut(401, 'Not authenticated', 'not_authenticated');
}
$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    failOut(401, 'Not authenticated', 'not_authenticated');
}

// bffEnforceSession() also honours the inactivity window configured by the
// administrator; the legacy testlinkInitPage() did the same, but it answered
// with a redirect instead of a status code.
bffEnforceSession($db);

// --- test project resolution -------------------------------------------------
// The ADDRESSED project is used, not the session one: a deep link must render
// the criteria of the project it names. Explicit param wins, session is the
// fallback (the legacy page always used the session project and had no way to
// move without hand-editing the URL).
$rawTp = isset($_REQUEST['tproject_id']) ? trim((string)$_REQUEST['tproject_id']) : '';
if ($rawTp === '') {
    $tprojectId = isset($_SESSION['testprojectID']) ? intval($_SESSION['testprojectID']) : 0;
} elseif (preg_match('/^[0-9]+$/', $rawTp) !== 1) {
    failOut(400, 'Invalid test project id', 'invalid_tproject');
} else {
    $tprojectId = intval($rawTp);
}
if ($tprojectId <= 0) {
    failOut(400, 'Invalid test project id', 'invalid_tproject');
}

// --- rights ------------------------------------------------------------------
// Checked on the ADDRESSED project BEFORE it is resolved, so a caller without
// the right cannot turn this into a test-project existence oracle (#1697 lesson).
// mgt_modify_req implies mgt_view_req (roles.inc.php), so an author passes too.
if (!($user->hasRight($db, 'mgt_view_req', $tprojectId) ||
      $user->hasRight($db, 'mgt_modify_req', $tprojectId))) {
    tLog('mgt_view_req missing on test project ' . $tprojectId
        . ' - Requirement Specification Search Form refused', 'WARNING');
    failOut(403, 'You are not authorized to view requirements of this test project', 'no_right');
}

$tprojectMgr = new testproject($db);
$info = $tprojectMgr->get_by_id($tprojectId);
if (is_null($info)) {
    failOut(404, 'Test project not found', 'tproject_not_found');
}

// --- req-spec type domain ----------------------------------------------------
// Same source as the legacy form (reqSpecSearchForm.php:44-45) so the codes
// handed to /reqspec-search are exactly the ones the DB stores.
$reqSpecCfg = config_get('req_spec_cfg');
$types = array();
foreach (init_labels($reqSpecCfg->type_labels) as $code => $label) {
    $types[] = array('code' => (string)$code, 'label' => (string)$label);
}

// --- design-time custom fields linked at requirement_spec scope ---------------
$cfieldMgr = new cfield_mgr($db);
$customFields = array();
$designCf = $cfieldMgr->get_linked_cfields_at_design(
    $tprojectId, cfield_mgr::ENABLED, null, 'requirement_spec');
if (!is_null($designCf)) {
    foreach ($designCf as $cfId => $cf) {
        $customFields[] = array(
            'id'    => intval($cfId),
            'label' => (string)$cf['label'],
            'type'  => isset($cf['type']) ? intval($cf['type']) : 0,
        );
    }
}

// --- project options ---------------------------------------------------------
$reqSpecSet = $tprojectMgr->getOptionReqSpec(
    $tprojectId, testproject::GET_NOT_EMPTY_REQSPEC);
$hasReqSpecs = (is_array($reqSpecSet) || is_object($reqSpecSet))
    && count((array)$reqSpecSet) > 0;

$opt = $tprojectMgr->getOptions($tprojectId);
$requirementsEnabled = !empty($opt->requirementsEnabled);

$reqCfg = config_get('req_cfg');
$maxQty = isset($reqCfg->search->max_qty_for_display)
    ? intval($reqCfg->search->max_qty_for_display) : 0;

// --- criteria echoed back for pre-fill ---------------------------------------
// Only the seven criteria the modern results screen understands
// (gui/templates/requirements/searchReqSpec.html:209) are echoed, each trimmed
// and length-capped: the form is a deep-linkable page, so the values travel in
// the query string and must never be unbounded.
function crit($key, $max)
{
    if (!isset($_REQUEST[$key])) { return ''; }
    $v = trim((string)$_REQUEST[$key]);
    if ($v === '') { return ''; }
    if (strlen($v) > $max) { $v = substr($v, 0, $max); }
    return $v;
}

$rawType = crit('reqSpecType', 32);
$knownType = false;
foreach ($types as $t) {
    if ($t['code'] === $rawType) { $knownType = true; break; }
}
if (!$knownType) { $rawType = ''; }

$rawCfId = 0;
if (isset($_REQUEST['custom_field_id']) &&
    preg_match('/^[0-9]+$/', trim((string)$_REQUEST['custom_field_id'])) === 1) {
    $rawCfId = intval(trim((string)$_REQUEST['custom_field_id']));
}
$knownCf = false;
foreach ($customFields as $cf) {
    if ($cf['id'] === $rawCfId) { $knownCf = true; break; }
}
if (!$knownCf) {
    // An unknown custom field id is dropped rather than forwarded: the results
    // BFF answers 400 'Unknown custom field' for it, and a form that hands out
    // a filter the server will refuse is worse than no filter.
    $rawCfId = 0;
}

out(array(
    'status'  => 'ok',
    'context' => array(
        'tproject_id'           => $tprojectId,
        'tproject_name'         => (string)$info['name'],
        'prefix'                => (string)(isset($info['prefix']) ? $info['prefix'] : ''),
        'user_id'               => $userId,
        'user_login'            => (string)$user->login,
        'requirements_enabled'  => $requirementsEnabled ? 1 : 0,
        'has_req_specs'         => $hasReqSpecs ? 1 : 0,
        'max_qty_for_display'   => $maxQty,
    ),
    'types'        => $types,
    'customFields' => $customFields,
    'criteria'     => array(
        'doc_id'             => crit('doc_id', 255),
        'name'               => crit('name', 255),
        'reqSpecType'        => $rawType,
        'scope'              => crit('scope', 2000),
        'log_message'        => crit('log_message', 2000),
        'custom_field_id'    => $rawCfId,
        'custom_field_value' => crit('custom_field_value', 2000),
    ),
    'grant' => array(
        'view'   => (bool)$user->hasRight($db, 'mgt_view_req', $tprojectId),
        'modify' => (bool)$user->hasRight($db, 'mgt_modify_req', $tprojectId),
    ),
));