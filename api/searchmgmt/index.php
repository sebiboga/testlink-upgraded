<?php
/**
 * Full-Text Search BFF API (modern twin of lib/search/searchMgmt.php)
 * URL: /api/searchmgmt/
 * Plain PHP, no framework, no compilation.
 *
 * WHAT THE LEGACY SCREEN WAS
 * -------------------------
 * lib/search/searchMgmt.php (137 lines, `@since 1.9.16`) was the "Full-Text
 * Search" entry point. The navBar posted a one-line form
 * (gui/templates/tl-classic/navBar.tpl:68-79):
 *
 *   <form name="fullTextSearch" action="lib/search/searchMgmt.php" method="post">
 *     <input type="hidden" name="caller" value="navBar">
 *     <input type="hidden" name="tproject_id" value="{$gui->tproject_id}">
 *     <input type="text" name="target" value="" />   <-- one free-text box
 *   </form>
 *
 * The controller then:
 *   1. ran `strings_stripSlashes($_REQUEST)` and R_PARAMS()-ed only
 *      `target` (STRING_N, 0..200) and `caller` (STRING_N, 0..20);
 *   2. built a `searchCommands` manager, called initEnv() and force-enabled
 *      every search dimension:
 *          tc_summary, tc_title, tc_steps, tc_expected_results, tc_id,
 *          tc_preconditions, ts_summary, ts_title
 *      plus, when requirements are enabled on the project,
 *          rs_scope, rs_title, rq_scope, rq_title, rq_doc_id;
 *   3. set `forceSearch = strlen(trim($args->target)) > 0` (a non-empty
 *      target means "run it now", an empty one means "just show the form");
 *   4. set `warning_msg = no_records_found` and rendered
 *      `searchResults.tpl`, which includes `searchGUI.inc.tpl` (the whole
 *      advanced criteria form) and then - only when `$gui->doSearch` and no
 *      warning - the ExtJS result tables.
 *
 * The legacy "screen" was therefore: the full 260-line advanced criteria
 * form + the result tables, in the tl-classic/ExtJS look, with failure modes
 * reported as untranslated output. Its *distinguishing* capability versus
 * the modern `searchView.html` (quick search, no free-text box) and
 * `searchAdvancedView.html` (the whole criteria form, driven by
 * `api/search?action=fulltext`) is the ONE-BOX full-text search.
 *
 * WHAT THIS BFF DOES
 * ------------------
 * Two read-only GET actions, both reusing `searchCommands` for exact
 * legacy parity (the very same engine `api/search?action=fulltext` uses):
 *
 *   GET ?action=init&tproject_id=N
 *       -> context: project name/prefix, requirements-enabled flag, the
 *          forced criteria dimensions (as named booleans, so the screen
 *          renders exactly the boxes legacy always forced on), the
 *          available refinements (keywords, custom fields, requirement
 *          status domain) and the caller's grants.
 *   GET ?action=results&tproject_id=N&target=...&and_or=or|and[&<crit>=1...]
 *       -> { warning, count, testcases[], testsuites[], reqspecs[],
 *            requirements[], tcasePrefix }.
 *
 * CONTRACT (JSON always, charset=utf-8 + nosniff)
 *   401 not_authenticated | session_expired
 *   403 forbidden (rights missing on the OWNING project) - audited
 *   400 invalid_tproject | need_criteria | need_checkbox
 *   404 tproject_not_found
 *   405 method_not_allowed
 *   500 internal_error
 * The screen maps every `code` to a localized message, so no untranslated
 * server string can ever reach the user.
 *
 * The right is `mgt_view_tc` on the project the search runs against - the
 * same check `api/search/index.php` and `gui/templates/search/searchView.html`
 * use, and the one the legacy grid of every result type is built on. A denied
 * attempt is written to the Event Viewer as
 * `audit_security_user_right_missing ... view` (a JSON BFF cannot redirect
 * home, so without the event a denied attempt would leave no trace; same
 * convention as api/issuetracker, api/codetracker, api/ltx).
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$db = new database(DB_TYPE);
doDBConnect($db);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    out(array(
        'status' => 'error',
        'code' => 'method_not_allowed',
        'message' => 'This endpoint is read-only',
    ));
}

/** Single JSON answer + terminate. */
function out($data) {
    echo json_encode($data);
    exit;
}

/** Answer an error with a stable machine code the screen can localize. */
function fail($http, $code, $extra = array()) {
    http_response_code($http);
    out(array_merge(array(
        'status' => 'error',
        'code' => $code,
        'message' => $code,
    ), $extra));
}

/** Read a scalar GET param, rejecting arrays (?x[]=1) to avoid TypeErrors. */
function gp($key, $default = '') {
    $v = $_GET[$key] ?? $default;
    if (is_array($v) || is_object($v)) {
        return $default;
    }
    return trim((string)$v);
}

/** Audit a refused access (see header comment). */
function auditDenied($user, $activity) {
    logAuditEvent(TLS('audit_security_user_right_missing', $user->login,
                      'searchMgmt', $activity));
}

// ---------------------------------------------------------------------------
// session
// ---------------------------------------------------------------------------
$userId = intval($_SESSION['userID'] ?? 0);
if ($userId <= 0) {
    http_response_code(401);
    out(array(
        'status' => 'error',
        'code' => 'not_authenticated',
        'message' => 'Not authenticated',
    ));
}

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    http_response_code(401);
    out(array(
        'status' => 'error',
        'code' => 'not_authenticated',
        'message' => 'User not found',
    ));
}

bffEnforceSession($db);

$action = gp('action', '');

// ---------------------------------------------------------------------------
// project resolution - the rights check happens BEFORE the project is
// resolved so a bogus id and a foreign project are indistinguishable (the
// id-enumeration hole fixed in #1697 for the requirement tree).
// ---------------------------------------------------------------------------
$tprojectId = intval(gp('tproject_id', '0'));
if ($tprojectId <= 0) {
    // legacy parity: searchForm/searchMgmt always ran on the SESSION project
    $tprojectId = intval($_SESSION['testprojectID'] ?? 0);
}
if ($tprojectId <= 0) {
    fail(400, 'invalid_tproject');
}

if (!$user->hasRight($db, 'mgt_view_tc', $tprojectId)) {
    auditDenied($user, 'view');
    fail(403, 'forbidden');
}

$tproject_mgr = new testproject($db);
$project = $tproject_mgr->get_by_id($tprojectId);
if (is_null($project)) {
    fail(404, 'tproject_not_found');
}

$glue = $tproject_mgr->getCSVGlue();
$prefix = $tproject_mgr->getTestCasePrefix($tprojectId) . $glue;

// The criteria dimensions legacy searchMgmt.php force-enabled. They are NOT
// user-switchable in that controller, so the modern screen shows them as
// read-only, checked, per-area boxes - the only way a user narrows the
// full-text search is the extra refinement columns.
$tcCriteria = array(
    'tc_id' => 'tcID',
    'tc_title' => 'tcTitle',
    'tc_summary' => 'tcSummary',
    'tc_preconditions' => 'tcPreconditions',
    'tc_steps' => 'tcSteps',
    'tc_expected_results' => 'tcExpected',
);
$tsCriteria = array(
    'ts_title' => 'tsTitle',
    'ts_summary' => 'tsSummary',
);
$reqEnabled = (int)$tproject_mgr->getOption('reqs') > 0;

// ---------------------------------------------------------------------------
// action=init
// ---------------------------------------------------------------------------
if ($action === 'init') {
    $keywords = array();
    $cfTc = array();
    $cfReq = array();
    if ($tproject_mgr->cfield_mgr !== null) {
        $keywords = $tproject_mgr->getKeywords();
        $cfTc = (array)$tproject_mgr->cfield_mgr->get_cfields(
            array('enabled' => 1, 'scope' => 'testcase'));
        $cfReq = (array)$tproject_mgr->cfield_mgr->get_cfields(
            array('enabled' => 1, 'scope' => 'requirement'));
    }

    $out = array(
        'status' => 'ok',
        'context' => array(
            'tproject_id' => $tprojectId,
            'tproject_name' => $project->name,
            'tcase_prefix' => $tproject_mgr->getTestCasePrefix($tprojectId),
            'tcasePrefix' => $prefix,
            'reqEnabled' => (bool)$reqEnabled,
            'max_target_length' => 200,
        ),
        // forced-by-legacy dimensions, grouped per result area
        'criteria' => array(
            'testcases' => $tcCriteria,
            'testsuites' => $tsCriteria,
            'reqspecs' => $reqEnabled ? array('rs_title' => 'rsTitle', 'rs_scope' => 'rsScope') : array(),
            'requirements' => $reqEnabled ? array('rq_doc_id' => 'rqDocId', 'rq_title' => 'rqTitle', 'rq_scope' => 'rqScope') : array(),
        ),
        // optional refinements
        'keywords' => array(),
        'custom_fields' => array(
            'testcase' => array(),
            'requirement' => array(),
        ),
        'grants' => array(
            'mgt_view_tc' => (bool)$user->hasRight($db, 'mgt_view_tc', $tprojectId),
            'mgt_view_req' => (bool)$user->hasRight($db, 'mgt_view_req', $tprojectId),
        ),
    );

    foreach ((array)$keywords as $kw) {
        $kw = (array)$kw;
        $out['keywords'][] = array(
            'id' => intval($kw['keyword_id'] ?? $kw['id'] ?? 0),
            'name' => (string)($kw['name'] ?? ''),
        );
    }
    foreach ($cfTc as $cf) {
        $cf = (array)$cf;
        $out['custom_fields']['testcase'][] = array(
            'id' => intval($cf['cfield_id'] ?? 0),
            'name' => (string)($cf['cfname'] ?? ''),
            'type' => (string)($cf['cf_type'] ?? ''),
        );
    }
    foreach ($cfReq as $cf) {
        $cf = (array)$cf;
        $out['custom_fields']['requirement'][] = array(
            'id' => intval($cf['cfield_id'] ?? 0),
            'name' => (string)($cf['cfname'] ?? ''),
            'type' => (string)($cf['cf_type'] ?? ''),
        );
    }

    out($out);
}

// ---------------------------------------------------------------------------
// action=results  (mirrors lib/search/searchMgmt.php -> searchCommands)
// ---------------------------------------------------------------------------
if ($action !== 'results') {
    fail(400, 'unknown_action');
}

require_once(__DIR__ . '/../../lib/search/searchCommands.class.php');

// Feed legacy initArgs() through $_REQUEST exactly like the old form POST.
$criteriaKeys = array_keys($tcCriteria) + array_keys($tsCriteria);
if ($reqEnabled) {
    $criteriaKeys = array_merge($criteriaKeys,
        array('rs_title', 'rs_scope', 'rq_doc_id', 'rq_title', 'rq_scope'));
}
foreach ($criteriaKeys as $k) {
    unset($_REQUEST[$k]);
    if (gp($k) === '1') {
        $_REQUEST[$k] = '1';
    }
}

// searchMgmt.php strips slashes off the whole request before R_PARAMS.
$_REQUEST['target'] = str_replace('\\', '', gp('target'));
if (strlen($_REQUEST['target']) > 200) {
    $_REQUEST['target'] = substr($_REQUEST['target'], 0, 200);
}
$_REQUEST['and_or'] = (gp('and_or') === 'and') ? 'and' : 'or';
$_REQUEST['created_by'] = gp('created_by');
$_REQUEST['edited_by'] = gp('edited_by');
foreach (array('creation_date_from', 'creation_date_to',
               'modification_date_from', 'modification_date_to') as $k) {
    $_REQUEST[$k] = gp($k);
}
$_REQUEST['keyword_id'] = intval(gp('keyword_id', '0'));
$_REQUEST['custom_field_id'] = intval(gp('custom_field_id', '0'));
$_REQUEST['custom_field_value'] = gp('custom_field_value');
$_REQUEST['tcWKFStatus'] = intval(gp('tcWKFStatus', '0'));
// requirement status is letter-coded (D/R/W/...) and legacy R_PARAMS caps it
$_REQUEST['reqStatus'] = substr(gp('reqStatus'), 0, 1);
$_REQUEST['doAction'] = 'doSearch';
$_REQUEST['tproject_id'] = $tprojectId;
$_REQUEST['caller'] = 'searchMgmt';

$cmdMgr = new searchCommands($db);
$cmdMgr->initEnv();
$args = $cmdMgr->getArgs();
$gui = $cmdMgr->getGui();

// The legacy engine expects these gui properties; the old controllers got
// them from template-time initialization, feed them explicitly here (avoids
// PHP 8 "undefined property" warnings and a broken CF type switch).
$gui->cf_types = $tproject_mgr->cfield_mgr->custom_field_types;
$gui->design_cf_rq = isset($gui->design_cf_req) ? $gui->design_cf_req : null;

// searchMgmt.php: no dimension left unchecked, otherwise the legacy
// search.php aborted with the untranslated `need_checkbox` message.
if (!$args->oneCheck) {
    fail(400, 'need_checkbox');
}

// cleanUpTarget() equivalent, from lib/search/search.php
$targetSet = array();
$ts = preg_replace('/ {2,}/', ' ', trim((string)$args->target));
foreach (explode(' ', (string)$ts) as $val) {
    $val = trim($val);
    if ($val !== '') {
        $targetSet[] = $db->prepare_string($val);
    }
}
$canUseTarget = count($targetSet) > 0;
if (!$canUseTarget && !$args->oneValueOK) {
    fail(400, 'need_criteria');
}

// which custom field space does the selected custom field belong to?
$tc_cf_id = null;
$req_cf_id = 0;
if ($args->custom_field_id > 0) {
    if (isset($gui->design_cf_tc[$args->custom_field_id])) {
        $tc_cf_id = intval($args->custom_field_id);
    }
    if (isset($gui->design_cf_req[$args->custom_field_id])) {
        $req_cf_id = intval($args->custom_field_id);
    }
}

$emptyTestProject = true;

// Test Suites
$mapTS = array();
if ($canUseTarget && ($args->ts_summary || $args->ts_title)) {
    $mapTS = (array)$cmdMgr->searchTestSuites($targetSet, $canUseTarget);
}

// Requirement SPECifications
$mapRS = array();
if ($canUseTarget && ($args->rs_scope || $args->rs_title)) {
    $mapRS = (array)$cmdMgr->searchReqSpec($targetSet, $canUseTarget);
}

// REQuirements
$mapRQ = array();
if ($args->rq_scope || $args->rq_title || $args->rq_doc_id || $req_cf_id > 0) {
    $mapRQ = (array)$cmdMgr->searchReq($targetSet, $canUseTarget, $req_cf_id);
}

// Test Cases
$mapTC = array();
$tcaseSet = $cmdMgr->getTestCaseIDSet($tprojectId);
if (!is_null($tcaseSet) && count($tcaseSet) > 0) {
    $emptyTestProject = false;
    $mapTC = (array)$cmdMgr->searchTestCases($tcaseSet, $targetSet, $canUseTarget, $tc_cf_id);
}

$cmdMgr->initSchema();
$treeMgr = $tproject_mgr->tree_manager;
$pathOptions = array('output_format' => 'path_as_string');

$tcRows = array();
if (count($mapTC) > 0) {
    $pathInfo = $treeMgr->get_full_path_verbose(array_keys($mapTC), $pathOptions);
    foreach ($mapTC as $rec) {
        $rec = (array)$rec;
        $tcid = intval($rec['testcase_id'] ?? 0);
        $extId = intval($rec['tc_external_id'] ?? 0);
        $tcRows[] = array(
            'testcase_id' => $tcid,
            'name' => (string)($rec['name'] ?? ''),
            'summary' => (string)($rec['summary'] ?? ''),
            'version' => intval($rec['version'] ?? 0),
            'full_external_id' => $prefix . $extId,
            'path' => (string)($pathInfo[$tcid] ?? ''),
        );
    }
}

$tsRows = array();
foreach ($mapTS as $rec) {
    $rec = (array)$rec;
    $tsRows[] = array(
        'id' => intval($rec['id'] ?? 0),
        'name' => (string)($rec['name'] ?? ''),
        'details' => (string)($rec['details'] ?? ''),
    );
}

$rsRows = array();
foreach ($mapRS as $rec) {
    $rec = (array)$rec;
    $rsRows[] = array(
        'req_spec_id' => intval($rec['req_spec_id'] ?? 0),
        'name' => (string)($rec['name'] ?? ''),
        'revision' => intval($rec['revision'] ?? 0),
        'scope' => (string)($rec['scope'] ?? ''),
    );
}

$rqRows = array();
if (count($mapRQ) > 0) {
    $reqPathInfo = $treeMgr->get_full_path_verbose(array_keys($mapRQ), $pathOptions);
    foreach ($mapRQ as $rid => $rec) {
        $rec = (array)$rec;
        // searchReq()'s SELECT returns no version/revision - do not fake them
        $rqRows[] = array(
            'req_id' => intval($rec['req_id'] ?? $rid),
            'req_doc_id' => (string)($rec['req_doc_id'] ?? ''),
            'name' => (string)($rec['name'] ?? ''),
            'scope' => (string)($rec['scope'] ?? ''),
            'path' => (string)($reqPathInfo[$rid] ?? ''),
        );
    }
}

$total = count($tcRows) + count($tsRows) + count($rsRows) + count($rqRows);

$warning = '';
if ($emptyTestProject) {
    $warning = 'empty_testproject';
} elseif ($total == 0) {
    $warning = 'no_records_found';
}

out(array(
    'status' => 'ok',
    'warning' => $warning,
    'count' => $total,
    'target' => (string)$args->target,
    'and_or' => (string)$args->and_or,
    'testcases' => $tcRows,
    'testsuites' => $tsRows,
    'reqspecs' => $rsRows,
    'requirements' => $rqRows,
    'tcasePrefix' => $prefix,
    'project' => array('id' => $tprojectId, 'name' => $project->name),
));
