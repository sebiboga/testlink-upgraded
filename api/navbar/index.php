<?php
/**
 * Top navigation bar (titlebar) BFF API
 * URL: /api/navbar/
 * Plain PHP, no framework, no compilation
 *
 * Backs gui/templates/navbar/navBar.html — the modernized titlebar frame
 * that every frameset page renders above the aside menu (legacy
 * lib/general/navBar.php + gui/templates/dashio/navBar.tpl).
 *
 * Route: GET ?action=init — returns the header state with exact legacy
 * parity: accessible test projects (map_name_with_inactive_mark + combo
 * format/order from gui config), the effective test project id (explicit
 * param > session > cookie memory > first accessible), the test plan list
 * of the owning project with the session-selected id (setSessionTestPlan
 * parity when the stored plan is no longer accessible), the user identity
 * (display name + effective role), ssodisable/logout urls, the
 * EVENT_TITLE_BAR plugin injection and the view_testcase_spec grant.
 *
 * Session auth (401 anonymous) + same-origin CSRF guard, same as every
 * other api/* BFF.
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once(__DIR__ . '/../_guard.php');

require_once('common.php');

doSessionStart();
bffSameOriginGuard();

header('Content-Type: application/json');

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || $userId <= 0) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'User not found']);
    exit;
}

function navbarOut($data) { echo json_encode($data); exit; }
function navbarParam($key, $default = null) { return $_GET[$key] ?? $default; }

$method = $_SERVER['REQUEST_METHOD'];
$action = navbarParam('action', '');

if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}
if ($action !== 'init') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Unknown or missing action']);
    exit;
}

// testlinkInitPage(..., 'initProject') parity: commit the requested test
// project to the session before resolving the header state, so a deep link
// to the titlebar alone still lands on the right project/plan.
initProject($db, array_merge($_GET, $_POST), 'navbar');

$args = new stdClass();
$args->viewer = navbarParam('viewer');
$args->ssodisable = getSSODisable();
$args->user = $user;
$args->testproject = intval(navbarParam('testproject', 0));
$args->tproject_id = intval(navbarParam('tproject_id', 0));
$args->caller = navbarParam('caller');

// New installation detection (init_args parity): no real test project rows.
$args->newInstallation = false;
if ($args->testproject <= 0 || $args->tproject_id <= 0) {
    $sch = tlObject::getDBTables(array('testprojects', 'nodes_hierarchy'));
    $rs = (array)$db->get_recordset(
        " SELECT NH.id FROM {$sch['nodes_hierarchy']} NH " .
        " JOIN {$sch['testprojects']} TPRJ ON TPRJ.id = NH.id LIMIT 1");
    $args->newInstallation = (count($rs) == 0);
}

$guiCfg = config_get("gui");
$tproject_mgr = new testproject($db);

$opx = array('output' => 'map_name_with_inactive_mark',
             'field_set' => $guiCfg->tprojects_combo_format,
             'order_by' => $guiCfg->tprojects_combo_order_by);

$testProjects = array();
try {
    $testProjects = $tproject_mgr->get_accessible_for_user($args->user->dbID, $opx);
} catch (Exception $e) {
    $testProjects = array();
}
if (!is_array($testProjects)) {
    $testProjects = array();
}

$tproject_id = $args->tproject_id;
if ($tproject_id <= 0) {
    $kiki = 'testprojectID';
    $tproject_id = intval(isset($_SESSION[$kiki]) ? $_SESSION[$kiki] : 0);
}
if ($tproject_id <= 0) {
    $ckCfg = config_get('cookie');
    $ckObj = new stdClass();
    $ckObj->name = $ckCfg->testProjectMemory . intval($_SESSION['userID']);
    if (isset($_COOKIE[$ckObj->name])) {
        $tproject_id = intval($_COOKIE[$ckObj->name]);
    }
}
if ($tproject_id <= 0 && !$args->newInstallation) {
    if (count($testProjects) == 0) {
        // Legacy parity: no accessible test project for this user — clear the
        // top menu so it cannot linger from a previous project, and degrade
        // to no-project context (legacy threw here).
        $_SESSION['testprojectTopMenu'] = '';
        $tproject_id = 0;
    } else {
        $tproject_id = intval(current(array_keys($testProjects)));
    }
}

// Cookie project memory (initializeGui parity) — written on the legacy path
// only when a project is in context and the frameset just (re)built.
if ($tproject_id > 0) {
    $ckCfg2 = config_get('cookie');
    $ckObj2 = new stdClass();
    $ckObj2->name = $ckCfg2->testProjectMemory . $args->user->dbID;
    $ckObj2->value = $tproject_id;
    tlSetCookie($ckObj2);
}

// Accessible test plans (parity: getAccessibleTestPlans + session selection).
$testPlans = array();
$tplan_id = 0;
if ($tproject_id > 0) {
    $testPlanSet = (array)$user->getAccessibleTestPlans($db, $tproject_id);
    $TestPlanCount = count($testPlanSet);
    // Legacy initializeGui parity: only adjust/select a plan when the session
    // still holds a plan id; otherwise the combo renders with nothing selected.
    $storedTplanId = isset($_SESSION['testplanID']) ? intval($_SESSION['testplanID']) : null;
    if (!is_null($storedTplanId) && $TestPlanCount > 0) {
        $index = 0;
        $testPlanFound = 0;
        foreach ($testPlanSet as $idx => $row) {
            if (intval($row['id']) == $storedTplanId) {
                $testPlanFound = 1;
                $index = $idx;
                break;
            }
        }
        if ($testPlanFound == 0) {
            $testPlanFound = 1;
            setSessionTestPlan($testPlanSet[0]);
            $index = 0;
        }
        $testPlanSet[$index]['selected'] = 1;
        $tplan_id = intval($testPlanSet[$index]['id']);
    }
    foreach ($testPlanSet as $row) {
        $testPlans[] = array(
            'id' => intval($row['id']),
            'name' => strval($row['name']),
            'selected' => isset($row['selected']) ? intval($row['selected']) : 0,
        );
    }
}

// Effective role for whoami (initializeGui parity).
$testprojectRole = '';
if ($tproject_id > 0 && isset($user->tprojectRoles[$tproject_id])) {
    $role = $user->tprojectRoles[$tproject_id];
    if (is_object($role)) {
        $testprojectRole = $role->getDisplayName();
    }
}
if ($testprojectRole === '' && is_object($user->globalRole)) {
    $testprojectRole = $user->globalRole->getDisplayName();
}

// EVENT_TITLE_BAR plugin injection (navBar.php parity).
$pluginHtml = array();
foreach (array('EVENT_TITLE_BAR') as $menu_item) {
    $menu_content = event_signal($menu_item);
    if (is_array($menu_content)) {
        $menu_content = implode('', $menu_content);
    }
    if (!empty($menu_content)) {
        $pluginHtml[$menu_item] = (string)$menu_content;
    }
}

$sso = ($args->ssodisable ? '&ssodisable' : '');
$tcasePrefix = ($tproject_id > 0)
    ? $tproject_mgr->getTestCasePrefix($tproject_id) . config_get('testcase_cfg')->glue_character
    : '';

navbarOut(array(
    'status' => 'ok',
    'new_installation' => $args->newInstallation,
    'tproject_id' => $tproject_id,
    'tproject_name' => isset($testProjects[$tproject_id])
        ? $testProjects[$tproject_id] : null,
    'projects' => $testProjects,
    'testplans' => $testPlans,
    'tplan_id' => $tplan_id,
    'whoami' => array(
        'name' => $user->getDisplayName(),
        'role' => $testprojectRole,
    ),
    'grants' => array(
        'view_testcase_spec' => $user->hasRightOnProj($db, 'mgt_view_tc'),
    ),
    'ssodisable' => $args->ssodisable,
    'logout_url' => '/logout.php?viewer=' . $sso,
    'user_info_url' => '/gui/templates/usermanagement/userInfo.html' .
        "?tproject_id={$tproject_id}&tplan_id={$tplan_id}",
    'plugins' => $pluginHtml,
    'tcase_prefix' => $tcasePrefix,
    'update_main_page' => ($tproject_id > 0 && is_null($args->caller)) ? 1 : 0,
));