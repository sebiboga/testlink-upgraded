<?php
/**
 * BFF API — Accessibility Audit / a11y gaps review
 *
 * Backs gui/templates/documentation/accessibilityAudit.html. Returns WCAG 2.2
 * conformance review data with per-criterion status (pass/partial/fail/manual).
 * Every human label returned as i18n KEY (a11yaudit.*).
 *
 * Route: GET|HEAD ?action=init (default)
 * Refs #1055.
 */
require_once(__DIR__ . '/../../config.inc.php');
require_once(__DIR__ . '/../_guard.php');
require_once('common.php');

doSessionStart();
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

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

$meta = array(
    'wcagVersion' => 'WCAG 2.2',
    'reviewDate'  => date('Y-m-d'),
    'scope'       => 'a11yaudit.meta.scope',
);

$principles = array(
    array('id'=>'perceivable','icon'=>'fas fa-eye','criteria'=>array(
        array('id'=>'wcag1_1_1','level'=>'A','status'=>'pass'),
        array('id'=>'wcag1_3_1','level'=>'A','status'=>'partial'),
        array('id'=>'wcag1_4_3','level'=>'AA','status'=>'partial'),
        array('id'=>'wcag1_4_4','level'=>'AA','status'=>'pass'),
        array('id'=>'wcag1_4_10','level'=>'AA','status'=>'partial'),
    )),
    array('id'=>'operable','icon'=>'fas fa-keyboard','criteria'=>array(
        array('id'=>'wcag2_1_1','level'=>'A','status'=>'partial'),
        array('id'=>'wcag2_4_1','level'=>'A','status'=>'fail'),
        array('id'=>'wcag2_4_3','level'=>'A','status'=>'partial'),
        array('id'=>'wcag2_4_7','level'=>'AA','status'=>'partial'),
        array('id'=>'wcag2_5_3','level'=>'AA','status'=>'partial'),
    )),
    array('id'=>'understandable','icon'=>'fas fa-lightbulb','criteria'=>array(
        array('id'=>'wcag3_1_1','level'=>'A','status'=>'pass'),
        array('id'=>'wcag3_2_2','level'=>'A','status'=>'pass'),
        array('id'=>'wcag3_3_1','level'=>'A','status'=>'partial'),
        array('id'=>'wcag3_3_2','level'=>'A','status'=>'partial'),
    )),
    array('id'=>'robust','icon'=>'fas fa-code','criteria'=>array(
        array('id'=>'wcag4_1_2','level'=>'A','status'=>'partial'),
        array('id'=>'wcag4_1_3','level'=>'AA','status'=>'manual'),
    )),
);

$related = array(
    array('labelKey'=>'a11yaudit.related.a11yres','href'=>"/gui/templates/documentation/accessibilityResources.html?{$ctx}",'external'=>false),
    array('labelKey'=>'a11yaudit.related.nfrAccessibility','href'=>"/gui/templates/strategy/nfrAccessibility.html?{$ctx}",'external'=>false),
    array('labelKey'=>'a11yaudit.related.learnTesting','href'=>"/gui/templates/documentation/learnTesting.html?{$ctx}",'external'=>false),
    array('labelKey'=>'a11yaudit.related.documentation','href'=>"/gui/templates/documentation/documentation.html?{$ctx}",'external'=>false),
);

echo json_encode(array('status'=>'ok','meta'=>$meta,'principles'=>$principles,'related'=>$related));
