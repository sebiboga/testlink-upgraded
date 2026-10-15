<?php
/**
 * BFF API — ISTQB Syllabus hub
 *
 * Backs gui/templates/documentation/istqbSyllabus.html — the net-new
 * "ISTQB Syllabus / certification reference" screen requested in #1053
 * (1.9.20 shipped no such screen; the Documentation menu only offered the
 * local product documentation and support links). It returns a curated
 * reference catalogue: what ISTQB/CTFL is, the certification levels, the
 * CTFL v4.0 syllabus chapters and the official external resources, plus
 * deep links to the related modern TestLink screens.
 *
 * Plain PHP, session-based auth, JSON I/O, same-origin guard — the same
 * contract as every other api/* BFF. Every human label is returned as an
 * i18n KEY (istqb.*) so the client renders it through TLi18n and no string
 * is hardcoded server-side; the same keys live in every client locale bundle.
 *
 * Route: GET|HEAD ?action=init (default)
 *
 * Refs #1053.
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

// Refs #1614: enforce the session-inactivity timeout once the user resolves, so
// a stale tab cannot keep serving past sessionInactivityTimeout.
bffEnforceSession($db);

$method = $_SERVER['REQUEST_METHOD'];
$action = (string)($_GET['action'] ?? 'init');

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

// Certification levels offered by ISTQB (official istqb.org pages).
$levels = array(
    array('id' => 'ctfl', 'icon' => 'fas fa-seedling',
          'href' => 'https://istqb.org/certifications/certified-tester-foundation-level-ctfl-v4-0/'),
    array('id' => 'ctal', 'icon' => 'fas fa-layer-group',
          'href' => 'https://istqb.org/certifications/certified-tester-advanced-level-ctal/'),
    array('id' => 'ctel', 'icon' => 'fas fa-award',
          'href' => 'https://istqb.org/certifications/certified-tester-expert-level-ctel/'),
    array('id' => 'specialist', 'icon' => 'fas fa-certificate',
          'href' => 'https://istqb.org/certifications/'),
);

// CTFL v4.0 syllabus chapters — the six chapters of the Foundation Level
// syllabus, with the key topics each one covers. Text is KEY only.
$chapters = array(
    array('id' => 'ch1', 'number' => '1'),
    array('id' => 'ch2', 'number' => '2'),
    array('id' => 'ch3', 'number' => '3'),
    array('id' => 'ch4', 'number' => '4'),
    array('id' => 'ch5', 'number' => '5'),
    array('id' => 'ch6', 'number' => '6'),
);

// Official ISTQB resources (external, documentation only — never injected markup).
$resources = array(
    array('id' => 'home', 'labelKey' => 'istqb.item.home', 'descKey' => 'istqb.item.home.desc',
          'href' => 'https://istqb.org/'),
    array('id' => 'ctfl', 'labelKey' => 'istqb.item.ctfl', 'descKey' => 'istqb.item.ctfl.desc',
          'href' => 'https://istqb.org/certifications/certified-tester-foundation-level-ctfl-v4-0/'),
    array('id' => 'certs', 'labelKey' => 'istqb.item.certs', 'descKey' => 'istqb.item.certs.desc',
          'href' => 'https://istqb.org/certifications/'),
    array('id' => 'glossary', 'labelKey' => 'istqb.item.glossary', 'descKey' => 'istqb.item.glossary.desc',
          'href' => 'https://glossary.istqb.org/'),
);

// Related modern screens inside TestLink (deep-link the current context).
$related = array(
    array('labelKey' => 'istqb.link.learnTesting',
          'href' => "/gui/templates/documentation/learnTesting.html?{$ctx}", 'external' => false),
    array('labelKey' => 'istqb.link.accessibility',
          'href' => "/gui/templates/documentation/accessibilityResources.html?{$ctx}", 'external' => false),
    array('labelKey' => 'istqb.link.testStrategy',
          'href' => "/gui/templates/strategy/testStrategy.html?{$ctx}", 'external' => false),
    array('labelKey' => 'istqb.link.documentation',
          'href' => "/gui/templates/documentation/documentation.html?{$ctx}", 'external' => false),
);

echo json_encode(array(
    'status'    => 'ok',
    'levels'    => $levels,
    'chapters'  => $chapters,
    'resources' => $resources,
    'related'   => $related,
));
