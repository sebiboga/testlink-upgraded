<?php
/**
 * Work Areas hub BFF
 * URL: /api/workarea/
 * Plain PHP, no framework, no compilation.
 *
 * Modern replacement for the legacy work-area launcher
 * lib/general/frmWorkArea.php (Refs #1575, #1971). In 1.9.20 that controller
 * was the entry point of every top-menu work area: it received a `feature`
 * argument and rendered the left tree + right content frameset (frmInner.tpl).
 * #1575 replaced the renderer with a 302 shim that forwards each feature to a
 * standalone modern screen, but there was never a modern landing of its own:
 * stale bookmarks / config still hop through legacy PHP.
 *
 * This BFF backs gui/templates/general/workArea.html. It exposes:
 *   GET|HEAD ?action=init[&tproject_id=N][&tplan_id=M][&feature=<legacy>]
 *     -> {
 *          status, auth, locale, context,
 *          rights:  { requirements, spec, execute, results },
 *          feature: null | { feature, area, target, label_key },
 *          areas:   [ { key, label_key, icon, allowed,
 *                       items:[{ id, label_key, href, feature }] } ]
 *        }
 *
 * Behavioural parity with frmWorkArea.php:
 *   - every legacy `feature=` value of its $feature_map is resolved to the
 *     same modern screen and keeps the session / requested tproject_id and
 *     tplan_id context;
 *   - an unknown / missing feature is a client error (the legacy controller
 *     logged and produced an empty page).
 *
 * Hardening over legacy:
 *   - the legacy controller checked only the session (testlinkInitPage) and
 *     never a right per feature; here every area is gated on the right the
 *     target screen actually enforces, checked on the ADDRESSED project
 *     BEFORE its name is resolved (#1697 no-existence-oracle);
 *   - array-shaped parameters are refused instead of being coerced;
 *   - GET/HEAD only, JSON-only, no-store, nosniff;
 *   - unauthenticated 401, unknown action 400, unknown feature 404,
 *     no right 403, wrong verb 405.
 *
 * Refs #1971.
 */
require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$db = null;
doDBConnect($db);

bffEnforceSession($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || $userId <= 0) {
    http_response_code(401);
    echo json_encode(array('status' => 'error', 'code' => 'not_authenticated',
        'message' => 'Not authenticated'));
    exit;
}

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    http_response_code(401);
    echo json_encode(array('status' => 'error', 'code' => 'user_not_found',
        'message' => 'User not found'));
    exit;
}
$_SESSION['currentUser'] = $user;

function waOut($payload, $code = 200)
{
    http_response_code($code);
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/** Read a scalar request parameter; arrays are refused (client error). */
function waParam($source, $key, $default = null)
{
    if (!array_key_exists($key, $source)) { return $default; }
    $v = $source[$key];
    if (is_array($v) || is_object($v)) {
        waOut(array('status' => 'error', 'code' => 'invalid_parameter',
            'message' => 'Invalid parameter: ' . $key), 400);
    }
    return trim((string)$v);
}

/** Strict positive int from a request source, 0 when absent/invalid. */
function waInt($source, $key)
{
    $v = $source[$key] ?? 0;
    if (!is_scalar($v)) { return 0; }
    return intval($v);
}

/**
 * Legacy frmWorkArea.php $feature_map, verbatim, annotated with the modern
 * area it belongs to. `href` is relative to the web root.
 */
function waFeatureMap()
{
    return array(
        // --- Requirements ---------------------------------------------------
        'reqSpecMgmt'        => array('area' => 'requirements', 'href' => 'gui/templates/requirements/reqSpecMgmt.html'),
        'assignReqs'         => array('area' => 'requirements', 'href' => 'gui/templates/requirements/assignReqs.html'),
        'searchReq'          => array('area' => 'requirements', 'href' => 'gui/templates/requirements/searchReq.html'),
        'searchReqSpec'      => array('area' => 'requirements', 'href' => 'gui/templates/requirements/searchReqSpec.html'),
        'reqSpecSearchForm'  => array('area' => 'requirements', 'href' => 'gui/templates/requirements/reqSpecSearchForm.html'),
        'printReqSpec'       => array('area' => 'requirements', 'href' => 'gui/templates/requirements/printReqSpec.html'),
        // --- Test specification --------------------------------------------
        'editTc'             => array('area' => 'spec', 'href' => 'gui/templates/testcases/testSpec.html'),
        'searchTc'           => array('area' => 'spec', 'href' => 'gui/templates/search/searchView.html'),
        'keywordsAssign'     => array('area' => 'spec', 'href' => 'gui/templates/keywords/keywordsAssign.html'),
        'printTestSpec'      => array('area' => 'spec', 'href' => 'gui/templates/testcases/printTestSpec.html'),
        // --- Execute --------------------------------------------------------
        'executeTest'        => array('area' => 'execute', 'href' => 'gui/templates/execute/execTest.html'),
        'planAddTC'          => array('area' => 'execute', 'href' => 'gui/templates/plans/planAddTCView.html'),
        'planRemoveTC'       => array('area' => 'execute', 'href' => 'gui/templates/plans/planAddTCView.html'),
        'planUpdateTC'       => array('area' => 'execute', 'href' => 'gui/templates/plans/planUpdateTC.html'),
        'show_ve'            => array('area' => 'execute', 'href' => 'gui/templates/plans/planNav.html'),
        'newest_tcversions'  => array('area' => 'execute', 'href' => 'gui/templates/plans/showNewestTcVersions.html'),
        'test_urgency'       => array('area' => 'execute', 'href' => 'gui/templates/plans/testUrgency.html'),
        'tc_exec_assignment' => array('area' => 'execute', 'href' => 'gui/templates/execute/tcExecAssignment.html'),
        // --- Results --------------------------------------------------------
        'showMetrics'        => array('area' => 'results', 'href' => 'gui/templates/results/resultsNavigator.html'),
    );
}

/** Right set enforced by the target screens of each area. */
function waAreaRights()
{
    return array(
        'requirements' => array('mgt_view_req', 'mgt_modify_req'),
        'spec'         => array('mgt_view_tc', 'mgt_modify_tc'),
        'execute'      => array('testplan_execute', 'exec_ro_access'),
        'results'      => array('testplan_metrics'),
    );
}

function waCan($user, $db, $rights, $tpid)
{
    if ($tpid <= 0) { return false; }
    foreach ($rights as $r) {
        if ($user->hasRight($db, $r, $tpid) === 'yes') { return true; }
    }
    return false;
}

/** Project display name + prefix, or null when it does not exist. */
function waProjectInfo($db, $tpid)
{
    $tbl = tlObjectWithDB::getDBTables(array('testprojects', 'nodes_hierarchy'));
    $tp = $tbl['testprojects'];
    $nh = $tbl['nodes_hierarchy'];
    $row = $db->fetchFirstRow(
        "SELECT p.prefix AS prefix, nh.name AS name" .
        " FROM {$tp} p JOIN {$nh} nh ON nh.id = p.id" .
        " WHERE p.id = " . intval($tpid) . " AND nh.node_type_id = 1");
    return (is_array($row) && $row) ? $row : null;
}

/** A plan of the addressed project, or null. */
function waPlanInfo($db, $tpid, $tplanid)
{
    if ($tplanid <= 0) { return null; }
    $tbl = tlObjectWithDB::getDBTables(array('testplans', 'nodes_hierarchy'));
    $tpl = $tbl['testplans'];
    $nh = $tbl['nodes_hierarchy'];
    $row = $db->fetchFirstRow(
        "SELECT t.id AS id, nh.name AS name" .
        " FROM {$tpl} t JOIN {$nh} nh ON nh.id = t.id AND nh.node_type_id = 5" .
        " WHERE t.id = " . intval($tplanid) .
        " AND t.testproject_id = " . intval($tpid));
    return (is_array($row) && $row) ? $row : null;
}

function waQueryContext($tpid, $tplanid)
{
    $q = '?tproject_id=' . intval($tpid);
    if ($tplanid > 0) { $q .= '&tplan_id=' . intval($tplanid); }
    return $q;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    if (!headers_sent()) { header('Allow: GET, HEAD'); }
    waOut(array('status' => 'error', 'code' => 'method_not_allowed',
        'message' => 'Method not allowed'), 405);
}

$action = waParam($_GET, 'action', '');
if ($action === null || $action === '') {
    waOut(array('status' => 'error', 'code' => 'invalid_request',
        'message' => 'Missing action'), 400);
}
if ($action !== 'init') {
    waOut(array('status' => 'error', 'code' => 'invalid_request',
        'message' => 'Unknown action'), 400);
}

$tproject_id = waInt($_GET, 'tproject_id');
if ($tproject_id <= 0) {
    $tproject_id = isset($_SESSION['testprojectID']) ? intval($_SESSION['testprojectID']) : 0;
}
$tplan_id = waInt($_GET, 'tplan_id');
if ($tplan_id <= 0) {
    $tplan_id = isset($_SESSION['testplanID']) ? intval($_SESSION['testplanID']) : 0;
}

$feature = waParam($_GET, 'feature', null);
if ($feature === '') { $feature = null; }

$featureMap = waFeatureMap();
$areaRights = waAreaRights();

// Rights are evaluated on the ADDRESSED project BEFORE it is resolved, so a
// caller without a right cannot tell a real project id from a bogus one.
$rights = array();
foreach ($areaRights as $area => $rr) {
    $rights[$area] = waCan($user, $db, $rr, $tproject_id);
}

$project = ($tproject_id > 0) ? waProjectInfo($db, $tproject_id) : null;
$plan = ($project && $tplan_id > 0) ? waPlanInfo($db, $tproject_id, $tplan_id) : null;

$context = array(
    'tproject_id'   => $tproject_id,
    'tplan_id'      => $plan ? $tplan_id : 0,
    'tproject_name' => $project ? (string)$project['name'] : '',
    'tproject_prefix' => $project ? (string)$project['prefix'] : '',
    'tplan_name'    => $plan ? (string)$plan['name'] : '',
);
$ctx = waQueryContext($tproject_id, $plan ? $tplan_id : 0);

// --- Feature deep-link resolution (legacy frmWorkArea.php parity) ----------
$resolved = null;
if ($feature !== null) {
    if (!isset($featureMap[$feature])) {
        waOut(array('status' => 'error', 'code' => 'unknown_feature',
            'message' => 'Unknown work-area feature'), 404);
    }
    $entry = $featureMap[$feature];
    if (empty($rights[$entry['area']])) {
        waOut(array('status' => 'error', 'code' => 'no_right',
            'message' => 'No right for this work area'), 403);
    }
    $resolved = array(
        'feature'   => $feature,
        'area'      => $entry['area'],
        'target'    => '/' . $entry['href'] . $ctx,
        'label_key' => 'wa.feature.' . $feature,
    );
}

// --- Area cards -----------------------------------------------------------
$groups = array(
    'requirements' => array(
        'label_key' => 'wa.area.requirements',
        'icon'      => 'fas fa-list-check',
        'items'     => array('reqSpecMgmt', 'assignReqs', 'searchReq',
                             'searchReqSpec', 'printReqSpec'),
    ),
    'spec' => array(
        'label_key' => 'wa.area.spec',
        'icon'      => 'fas fa-vial',
        'items'     => array('editTc', 'searchTc', 'keywordsAssign', 'printTestSpec'),
    ),
    'execute' => array(
        'label_key' => 'wa.area.execute',
        'icon'      => 'fas fa-play',
        'items'     => array('executeTest', 'planAddTC', 'planUpdateTC',
                             'tc_exec_assignment', 'test_urgency',
                             'newest_tcversions'),
    ),
    'results' => array(
        'label_key' => 'wa.area.results',
        'icon'      => 'fas fa-chart-line',
        'items'     => array('showMetrics'),
    ),
);

$areas = array();
foreach ($groups as $key => $g) {
    $items = array();
    foreach ($g['items'] as $fid) {
        if (!isset($featureMap[$fid])) { continue; }
        $items[] = array(
            'id'        => $fid,
            'label_key' => 'wa.feature.' . $fid,
            'href'      => '/' . $featureMap[$fid]['href'] . $ctx,
        );
    }
    $areas[] = array(
        'key'       => $key,
        'label_key' => $g['label_key'],
        'icon'      => $g['icon'],
        'allowed'   => !empty($rights[$key]),
        'items'     => $items,
    );
}

waOut(array(
    'status'  => 'ok',
    'auth'    => array(
        'logged_in' => true,
        'user_id'   => $userId,
        'user_login' => isset($user->login) ? (string)$user->login : '',
    ),
    'locale'  => isset($_SESSION['locale']) ? (string)$_SESSION['locale'] : '',
    'context' => $context,
    'rights'  => $rights,
    'feature' => $resolved,
    'areas'   => $areas,
));
