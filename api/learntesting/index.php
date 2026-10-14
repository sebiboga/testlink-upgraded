<?php
/**
 * BFF API — Learn Testing hub
 *
 * Backs gui/templates/documentation/learnTesting.html — the modernized
 * "Learn Testing" learning hub requested in #1060. It returns a curated,
 * self-contained catalogue of learning tracks (TestLink concepts mapped to
 * the modern screens) plus a small set of authoritative external resources.
 *
 * Plain PHP, session-based auth, JSON I/O, same-origin CSRF guard — same
 * contract as every other api/* BFF. Labels are returned as i18n KEYS
 * (learn.*) so the client renders them with TLi18n and no string is hardcoded
 * in the BFF; the same keys live in every client locale bundle.
 *
 * Route: GET ?action=init (default)
 *
 * Refs #1060.
 */
require_once(__DIR__ . '/../../config.inc.php');
require_once(__DIR__ . '/../_guard.php');

require_once('common.php');

doSessionStart();
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Session gate BEFORE the DB connect (the #1677 lesson): an anonymous caller
// must get a clean 401 JSON, never a leaked raw dbms_msg on an outage.
$userId = $_SESSION['userID'] ?? null;
if (!$userId || $userId <= 0) {
    http_response_code(401);
    echo json_encode(array('status' => 'error', 'code' => 'not_authenticated',
                           'message' => 'Not authenticated'));
    exit;
}

$db = new database(DB_TYPE);
doDBConnect($db);

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    http_response_code(401);
    echo json_encode(array('status' => 'error', 'code' => 'not_authenticated',
                           'message' => 'User not found'));
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? $_GET['action'] : 'init';
if ($method !== 'GET' && $method !== 'HEAD') {
    http_response_code(405);
    header('Allow: GET, HEAD');
    echo json_encode(array('status' => 'error', 'code' => 'method_not_allowed',
                           'message' => 'Method not allowed'));
    exit;
}
if ($action !== 'init') {
    http_response_code(400);
    echo json_encode(array('status' => 'error', 'code' => 'unknown_action',
                           'message' => 'Unknown or missing action'));
    exit;
}

$ctx = 'tproject_id=' . intval($_SESSION['testprojectID'] ?? 0) .
       '&tplan_id=' . intval($_SESSION['testplanID'] ?? 0);

// Curated tracks. Each item links either to a modern Dashio screen (internal,
// deep-linking the current project/plan context) or to the official learning
// page of a recognised body (external, opened in a new tab).
$tracks = array(
    array(
        'id'    => 'gettingStarted',
        'icon'  => 'fas fa-graduation-cap',
        'items' => array(
            array('labelKey' => 'learn.item.concepts',
                  'href' => "/gui/templates/documentation/documentation.html", 'external' => false),
            array('labelKey' => 'learn.item.userManual',
                  'href' => "/docs/testlink_user_manual.pdf", 'external' => true),
            array('labelKey' => 'learn.item.fileFormats',
                  'href' => "/docs/tl-file-formats.pdf", 'external' => true),
        ),
    ),
    array(
        'id'    => 'requirements',
        'icon'  => 'fas fa-list-check',
        'items' => array(
            array('labelKey' => 'learn.item.reqMgmt',
                  'href' => "/gui/templates/requirements/reqSpecMgmt.html?{$ctx}", 'external' => false),
            array('labelKey' => 'learn.item.reqTrace',
                  'href' => "/gui/templates/requirements/reqTcAssign.html?{$ctx}", 'external' => false),
        ),
    ),
    array(
        'id'    => 'testDesign',
        'icon'  => 'fas fa-pen-ruler',
        'items' => array(
            array('labelKey' => 'learn.item.tcSpec',
                  'href' => "/gui/templates/testcases/testSpec.html?{$ctx}", 'external' => false),
            array('labelKey' => 'learn.item.goodTestCase',
                  'href' => "/docs/bibliographical_references/GoodTest.pdf", 'external' => true),
            array('labelKey' => 'learn.item.testTypes',
                  'href' => "/gui/templates/strategy/testTypes.html?{$ctx}", 'external' => false),
        ),
    ),
    array(
        'id'    => 'plans',
        'icon'  => 'fas fa-clipboard-list',
        'items' => array(
            array('labelKey' => 'learn.item.testPlan',
                  'href' => "/gui/templates/plans/planView.html?{$ctx}", 'external' => false),
            array('labelKey' => 'learn.item.execute',
                  'href' => "/gui/templates/execute/execTest.html?{$ctx}", 'external' => false),
            array('labelKey' => 'learn.item.execNotes',
                  'href' => "/gui/templates/execute/execNotes.html?{$ctx}", 'external' => false),
        ),
    ),
    array(
        'id'    => 'reports',
        'icon'  => 'fas fa-chart-line',
        'items' => array(
            array('labelKey' => 'learn.item.metrics',
                  'href' => "/gui/templates/results/resultsNavigator.html?{$ctx}", 'external' => false),
            array('labelKey' => 'learn.item.strategyMetrics',
                  'href' => "/gui/templates/strategy/metrics.html?{$ctx}", 'external' => false),
        ),
    ),
    array(
        'id'    => 'quality',
        'icon'  => 'fas fa-shield-halved',
        'items' => array(
            array('labelKey' => 'learn.item.accessibility',
                  'href' => "/gui/templates/strategy/nfrAccessibility.html?{$ctx}", 'external' => false),
            array('labelKey' => 'learn.item.security',
                  'href' => "/gui/templates/strategy/nfrSecurity.html?{$ctx}", 'external' => false),
            array('labelKey' => 'learn.item.performance',
                  'href' => "/gui/templates/strategy/nfrPerformance.html?{$ctx}", 'external' => false),
        ),
    ),
);

$resources = array(
    array('labelKey' => 'learn.res.istqb', 'descKey' => 'learn.res.istqb.desc',
          'icon' => 'fas fa-certificate', 'href' => 'https://www.istqb.org/'),
    array('labelKey' => 'learn.res.wcag', 'descKey' => 'learn.res.wcag.desc',
          'icon' => 'fas fa-universal-access',
          'href' => 'https://www.w3.org/WAI/standards-guidelines/wcag/'),
    array('labelKey' => 'learn.res.owasp', 'descKey' => 'learn.res.owasp.desc',
          'icon' => 'fas fa-lock', 'href' => 'https://owasp.org/www-project-top-ten/'),
    array('labelKey' => 'learn.res.ministry', 'descKey' => 'learn.res.ministry.desc',
          'icon' => 'fas fa-flask',
          'href' => 'https://www.ministryoftesting.com/'),
);

echo json_encode(array(
    'status'    => 'ok',
    'tracks'    => $tracks,
    'resources' => $resources,
));
