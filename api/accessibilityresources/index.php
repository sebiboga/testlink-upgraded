<?php
/**
 * BFF API — Accessibility Resources hub
 *
 * Backs gui/templates/documentation/accessibilityResources.html — the net-new
 * "Accessibility learning resources" screen requested in #1059 (1.9.20 shipped
 * no such screen). It returns a curated catalogue of authoritative accessibility
 * learning/reference resources plus a WCAG "POUR" primer.
 *
 * Plain PHP, session-based auth, JSON I/O, same-origin guard — the same contract
 * as every other api/* BFF. Every human label is returned as an i18n KEY
 * (a11yres.*) so the client renders it through TLi18n and no string is hardcoded
 * server-side; the same keys live in every client locale bundle.
 *
 * Route: GET|HEAD ?action=init (default)
 *
 * Refs #1059.
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
$rawAction = $_GET['action'] ?? 'init';
if (!is_scalar($rawAction)) {
    http_response_code(400);
    echo json_encode(array('status' => 'error', 'code' => 'unknown_action',
                           'message' => 'Unknown or missing action'));
    exit;
}
$action = (string)$rawAction;

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

// WCAG "at a glance" — the four POUR principles. Text comes from keys only.
$principles = array(
    array('id' => 'perceivable', 'icon' => 'fas fa-eye',
          'href' => 'https://www.w3.org/WAI/WCAG21/Understanding/intro#understanding-the-four-principles-of-accessibility'),
    array('id' => 'operable', 'icon' => 'fas fa-keyboard',
          'href' => 'https://www.w3.org/WAI/WCAG21/Understanding/intro#understanding-the-four-principles-of-accessibility'),
    array('id' => 'understandable', 'icon' => 'fas fa-lightbulb',
          'href' => 'https://www.w3.org/WAI/WCAG21/Understanding/intro#understanding-the-four-principles-of-accessibility'),
    array('id' => 'robust', 'icon' => 'fas fa-code',
          'href' => 'https://www.w3.org/WAI/WCAG21/Understanding/intro#understanding-the-four-principles-of-accessibility'),
);

// Curated resource groups. Every item points at a recognised accessibility
// authority; the links are documentation, never injected markup.
$groups = array(
    array(
        'id'    => 'standards',
        'icon'  => 'fas fa-scale-balanced',
        'items' => array(
            array('labelKey' => 'a11yres.item.wcag21', 'descKey' => 'a11yres.item.wcag21.desc',
                  'href' => 'https://www.w3.org/TR/WCAG21/'),
            array('labelKey' => 'a11yres.item.waiStandards', 'descKey' => 'a11yres.item.waiStandards.desc',
                  'href' => 'https://www.w3.org/WAI/standards-guidelines/'),
            array('labelKey' => 'a11yres.item.ariaApg', 'descKey' => 'a11yres.item.ariaApg.desc',
                  'href' => 'https://www.w3.org/WAI/ARIA/apg/'),
        ),
    ),
    array(
        'id'    => 'tutorials',
        'icon'  => 'fas fa-graduation-cap',
        'items' => array(
            array('labelKey' => 'a11yres.item.waiTutorials', 'descKey' => 'a11yres.item.waiTutorials.desc',
                  'href' => 'https://www.w3.org/WAI/tutorials/'),
            array('labelKey' => 'a11yres.item.waiCourses', 'descKey' => 'a11yres.item.waiCourses.desc',
                  'href' => 'https://www.w3.org/WAI/courses/'),
            array('labelKey' => 'a11yres.item.w3schools', 'descKey' => 'a11yres.item.w3schools.desc',
                  'href' => 'https://www.w3schools.com/accessibility/'),
        ),
    ),
    array(
        'id'    => 'testing',
        'icon'  => 'fas fa-vial',
        'items' => array(
            array('labelKey' => 'a11yres.item.easyChecks', 'descKey' => 'a11yres.item.easyChecks.desc',
                  'href' => 'https://www.w3.org/WAI/test-evaluate/preliminary/'),
            array('labelKey' => 'a11yres.item.evalTools', 'descKey' => 'a11yres.item.evalTools.desc',
                  'href' => 'https://www.w3.org/WAI/test-evaluate/tools/list/'),
            array('labelKey' => 'a11yres.item.webaimChecklist', 'descKey' => 'a11yres.item.webaimChecklist.desc',
                  'href' => 'https://webaim.org/standards/wcag/checklist'),
        ),
    ),
    array(
        'id'    => 'tooling',
        'icon'  => 'fas fa-screwdriver-wrench',
        'items' => array(
            array('labelKey' => 'a11yres.item.axe', 'descKey' => 'a11yres.item.axe.desc',
                  'href' => 'https://www.deque.com/axe/devtools/'),
            array('labelKey' => 'a11yres.item.wave', 'descKey' => 'a11yres.item.wave.desc',
                  'href' => 'https://wave.webaim.org/'),
            array('labelKey' => 'a11yres.item.lighthouse', 'descKey' => 'a11yres.item.lighthouse.desc',
                  'href' => 'https://developer.chrome.com/docs/lighthouse/accessibility/'),
        ),
    ),
);

// Related modern screens inside TestLink (deep-link the current context).
$related = array(
    array('labelKey' => 'a11yres.link.nfrAccessibility',
          'href' => "/gui/templates/strategy/nfrAccessibility.html?{$ctx}", 'external' => false),
    array('labelKey' => 'a11yres.link.learnTesting',
          'href' => "/gui/templates/documentation/learnTesting.html?{$ctx}", 'external' => false),
    array('labelKey' => 'a11yres.link.documentation',
          'href' => "/gui/templates/documentation/documentation.html?{$ctx}", 'external' => false),
);

echo json_encode(array(
    'status'     => 'ok',
    'principles' => $principles,
    'groups'     => $groups,
    'related'    => $related,
));
