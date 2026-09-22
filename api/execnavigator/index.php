<?php
/**
 * api/execnavigator — Execution Navigator BFF (Refs #1562)
 *
 * Modernizes the last standalone legacy lib/execute screen with no modern
 * twin: the execution tree navigator `lib/execute/execNavigator.php` (+ legacy
 * dashio `execNavigator.tpl`, ExtJS left-pane tree + filter panel).
 *
 * Legacy parity — the navigator runs the EXACT same tree pipeline as 1.9.20:
 *   - tlTestCaseFilterControl(db, 'execution_mode') (the same class used by
 *     execNavigator.php) reads the active filters/settings from the request +
 *     session (form-token session data, stored build/platform settings) and
 *     builds the execution test-plan tree via execTree() (execTreeMenu.inc.php)
 *     with exec-status counters / colouring / status-code filters.
 *   - checkAccessToExec() rights probe ported 1:1: testplan_execute OR
 *     exec_ro_access on the OWNING project (admin shortcut), matching
 *     execNavigator.php initializeGui()/checkAccessToExec().
 *   - loadExecDashboard flag semantics preserved (session form-token cache +
 *     request override), so the modern navigator can deep-link to the
 *     Execution Dashboard just like the legacy left frame did (EXDS).
 *
 * The tree children JSON is produced by the legacy renderExecTreeNode(), i.e.
 * node fields id / name / text (HTML with exec-status colouring) / leaf /
 * tcversion_id / external_id / version / testlink_node_type / counters. The
 * modern screen renders it recursively and maps legacy "javascript:" hrefs to
 * the modern execTest.html screen (ST -> execute test case, EXDS/SP -> open
 * execTest/dashboard). Nothing of the legacy pipeline is dropped.
 *
 * Routes:
 *   GET ?action=init[&tplan_id=N][&tproject_id=M][&setting_build=N]
 *       [&setting_platform=N][&setting_exec_tree_counters_logic=N]
 *       [&loadExecDashboard=0|1][&form_token=...][&filter_*...]
 *       -> context + select-options (testplans/builds/platforms) + rights +
 *          tree JSON.
 *       401 anon / 400 no plan context / 403 no rights (nor CSRF guard) /
 *       404 unknown plan / 405 non-GET / error 500 guarded.
 *
 * Session-based auth, JSON I/O, no Smarty.
 */
require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
require_once('users.inc.php');
require_once('exec.inc.php');

require_once(__DIR__ . '/../../lib/functions/tlTestCaseFilterControl.class.php');
require_once(__DIR__ . '/../../lib/functions/treeMenu.inc.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

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

function out($data) {
    echo json_encode($data);
    exit;
}

function execNavigatorBadParam($msg) {
    http_response_code(400);
    out(['status' => 'error', 'message' => $msg]);
}

// Port of the legacy execNavigator.php checkAccessToExec() — the executive
// rights probe on the owning project (admin shortcut parity).
function execNavigatorGrants(&$db, &$user, $tprojectId, $tplanId) {
    $k2a = array('testplan_execute', 'exec_ro_access');
    $grants = array();
    foreach ($k2a as $r2c) {
        $grants[$r2c] = false;
        if ($user->hasRight($db, $r2c, $tprojectId, $tplanId, true)
            || $user->globalRoleID == TL_ROLES_ADMIN) {
            $grants[$r2c] = true;
        }
    }
    return $grants;
}

$action = isset($_GET['action']) && is_scalar($_GET['action'])
        ? strtolower(trim((string) $_GET['action'])) : '';
$method = $_SERVER['REQUEST_METHOD'];

if ($action === 'init') {
    if ($method !== 'GET') {
        http_response_code(405);
        out(['status' => 'error', 'message' => 'GET required']);
    }

    // ---- resolve the execution context (tplan + owning project) -----------
    $tplanId = intval($_REQUEST['tplan_id'] ?? $_SESSION['testplanID'] ?? 0);
    if ($tplanId <= 0) {
        execNavigatorBadParam('No test plan in context');
    }

    $tables = tlObjectWithDB::getDBTables(array('testplans', 'testprojects'));
    $planRs = $db->get_recordset(
        "SELECT tp.testproject_id FROM {$tables['testplans']} tp WHERE tp.id=" . $tplanId);
    if (!$planRs || count($planRs) === 0) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Test plan not found']);
    }
    $tprojectId = intval($planRs[0]['testproject_id']);
    $reqTproject = intval($_REQUEST['tproject_id'] ?? 0);
    if ($reqTproject > 0 && $reqTproject !== $tprojectId) {
        http_response_code(400);
        out(['status' => 'error',
             'message' => 'tproject_id does not match the requested test plan']);
    }

    // Let the legacy filter control resolve the session-bound context the same
    // way the frmWorkArea -> execNavigator.php flow established it. Keep the
    // previous session values so a foreign/privilege probe cannot hijack the
    // user's context.
    // The legacy filter/result definitions read the filter_* params from POST
    // only (tlInputParameter "POST" sources); the modern navigator talks GET,
    // so mirror the relevant names into $_POST when they are absent there.
    $postMirrorKeys = array(
        'filter_tc_id', 'filter_testcase_name', 'filter_toplevel_testsuite',
        'filter_keywords', 'filter_workflow_status', 'filter_importance',
        'filter_priority', 'filter_execution_type', 'filter_assigned_user',
        'filter_custom_fields', 'filter_bugs', 'filter_platforms',
        'filter_result', 'filter_result_result', 'filter_result_method',
        'filter_result_build', 'filter_keywords_filter_type',
        'caller', 'reset_filters', 'feature',
    );
    foreach ($postMirrorKeys as $pk) {
        if (isset($_GET[$pk]) && !isset($_POST[$pk])) {
            $_POST[$pk] = $_GET[$pk];
        }
    }
    $sessTP = isset($_SESSION['testplanID']) ? intval($_SESSION['testplanID']) : 0;
    $sessTPr = isset($_SESSION['testprojectID']) ? intval($_SESSION['testprojectID']) : 0;

    // Seed the session with the REQUESTED (validated) plan before constructing
    // the filter control: init_setting_testplan() reads $_SESSION['testplanID']
    // to resolve the effective plan and the settings/testplans for the panel.
    // This mirrors the legacy initProject()/session flow that ran before
    // execNavigator.php. The previous session values are restored if the
    // rights probe below fails, so a probe cannot hijack the user's context.
    $_SESSION['testplanID'] = $tplanId;
    $_SESSION['testprojectID'] = ($tprojectId > 0) ? $tprojectId : $sessTPr;

    // ---- build the execution tree with the legacy pipeline ----------------
    $gui = new stdClass();
    try {
        $control = new tlTestCaseFilterControl($db, 'execution_mode');
        $control->formAction = '';

        // Legacy initializeGui() parity.
        $gui->loadExecDashboard = true;
        if (isset($_SESSION['loadExecDashboard'][$control->form_token])
            || $control->args->loadExecDashboard == 0) {
            $gui->loadExecDashboard = false;
            unset($_SESSION['loadExecDashboard'][$control->form_token]);
        }

        $dummy = config_get('results');
        $gui->not_run = $dummy['status_code']['not_run'];
        $dummy = config_get('execution_filter_methods');
        $gui->lastest_exec_method = $dummy['status_code']['latest_execution'];
        $gui->pageTitle = lang_get('href_execute_test');
    } catch (Throwable $e) {
        http_response_code(500);
        out(['status' => 'error', 'message' => 'Tree build failed: ' . $e->getMessage()]);
    }

    // ---- effective execution context (legacy init_setting_testplan parity) --
    // The modern navigator keeps tplan_id of the previously selected plan in the
    // URL and carries a plan switch via setting_testplan (GET). The legacy
    // execNavigator.php let tlTestCaseFilterControl::init_setting_testplan()
    // resolve the effective plan from the session/setting_testplan before
    // checkAccessToExec(); otherwise the grants check would run against the old
    // plan while the tree gets built for the new one.
    $effTplanId = intval($control->args->testplan_id ?? 0);
    $effTprojectId = intval($control->args->testproject_id ?? 0);
    if ($effTprojectId <= 0) {
        $effTprojectId = $tprojectId;
    }

    $mismatched = false;
    if ($reqTproject > 0 && $effTprojectId > 0 && $reqTproject !== $effTprojectId) {
        $mismatched = true;
    }
    if ($mismatched) {
        http_response_code(400);
        out(['status' => 'error',
             'message' => 'tproject_id does not match the effective test plan context']);
    }

    // ---- rights (legacy checkAccessToExec) on the EFFECTIVE plan -----------
    $grants = execNavigatorGrants($db, $user, $effTprojectId, $effTplanId);
    if (!$grants['testplan_execute'] && !$grants['exec_ro_access']) {
        // Restore the user's previous execution context (probe safety).
        $_SESSION['testplanID'] = $sessTP;
        if ($sessTPr > 0) {
            $_SESSION['testprojectID'] = $sessTPr;
        }
        http_response_code(403);
        out(['status' => 'error',
             'message' => 'You do not have rights to execute tests on this plan']);
    }

    // Persist ONLY after the rights probe passed (legacy initProject/
    // setSessionTestPlan wrote the session at this point too, never earlier).
    $_SESSION['testplanID'] = $effTplanId;
    $_SESSION['testprojectID'] = $effTprojectId;

    $gui->tproject_id = $effTprojectId;
    $gui->menuUrl = 'gui/templates/execute/execTest.html';
    $gui->args = $control->get_argument_string();

    $gui->features = array('export' => false, 'import' => false);
    $gui->execAccess = false;
    if ($grants['testplan_execute']) {
        $gui->features['export'] = true;
        $gui->features['import'] = true;
        $gui->execAccess = true;
    }
    if ($grants['exec_ro_access']) {
        $gui->execAccess = true;
    }
    $control->draw_export_testplan_button = $gui->features['export'];
    $control->draw_import_xml_results_button = $gui->features['import'];

    try {
        $control->build_tree_menu($gui);
    } catch (Throwable $e) {
        http_response_code(500);
        out(['status' => 'error', 'message' => 'Tree build failed: ' . $e->getMessage()]);
    }

    // ---- compose the tree payload (legacy ajaxTree contract) --------------
    $root = empty($gui->ajaxTree->root_node) ? new stdClass() : $gui->ajaxTree->root_node;
    $children = [];
    if (!empty($gui->ajaxTree->children)
        && is_string($gui->ajaxTree->children)
        && $gui->ajaxTree->children !== '[]') {
        $dec = json_decode($gui->ajaxTree->children, true);
        if (is_array($dec)) {
            $children = $dec;
        }
    }

    // graph-style settings select options mirror the filter panel (execution_mode).
    if (isset($_GET['debug']) && intval($_GET['debug']) === 1) {
        $GLOBALS['__dbg'] = array(
            'do_filtering' => $control->do_filtering ?? null,
            'args_result' => (array) ($control->args->filter_result_result ?? null),
            'args_method' => $control->args->filter_result_method ?? null,
            'post_result' => (array) ($_POST['filter_result_result'] ?? null),
        );
    }
    $s = function ($key) use ($control) {
        $cfg = $control->settings[$key] ?? null;
        return is_null($cfg) ? $cfg : array(
            'items' => isset($cfg['items']) && is_array($cfg['items'])
                       ? array_map('strval', $cfg['items']) : null,
            'selected' => intval($cfg['selected'] ?? -1),
            'label' => strval($cfg['label'] ?? ''),
        );
    };

    // testplan selector: map id -> name (settings item map).
    $tpOptions = $s('setting_testplan');
    $buildOptions = $s('setting_build');
    $platformOptions = $s('setting_platform');

    // ---- legacy filter panel options (execution_mode) ----------------------
    $fo = function ($c) {
        if (is_null($c)) {
            return null;
        }
        $str = function ($v) {
            if (is_array($v)) {
                return array_map('strval', $v);
            }
            return is_null($v) ? null : strval($v);
        };
        return array(
            'items' => isset($c['items']) && is_array($c['items'])
                       ? array_map('strval', $c['items']) : null,
            'selected' => $str($c['selected'] ?? null),
            'size' => intval($c['size'] ?? 1),
            'label' => strval($c['label'] ?? ''),
        );
    };
    $filtersOut = array();
    foreach (array('filter_tc_id', 'filter_testcase_name',
                   'filter_keywords', 'filter_priority',
                   'filter_execution_type') as $fk) {
        $filtersOut[$fk] = $fo($control->filters[$fk] ?? null);
    }
    $fkw = $control->filters['filter_keywords'] ?? null;
    if (is_array($fkw) && isset($fkw['filter_keywords_filter_type'])) {
        $filtersOut['filter_keywords']['filter_keywords_filter_type'] =
            $fo($fkw['filter_keywords_filter_type']);
    }
    $fr = $control->filters['filter_result'] ?? null;
    if (is_array($fr)) {
        $filtersOut['filter_result'] = array(
            'result' => $fo($fr['filter_result_result'] ?? null),
            'method' => $fo($fr['filter_result_method'] ?? null),
            'build' => $fo($fr['filter_result_build'] ?? null),
            'js_selection' => intval($fr['filter_result_method']['js_selection'] ?? 0),
        );
    }
    $countersLogic = $s('setting_exec_tree_counters_logic');
    $refreshTree = boolVal(!empty($control->args->setting_refresh_tree_on_action)
                    || !empty($control->args->setting_build)
                    || !empty($control->args->setting_platform));

    out(array(
        'status' => 'ok',
        'action' => 'init',
        'debug' => $GLOBALS['__dbg'] ?? null,
        'context' => array(
            'testproject_id' => $effTprojectId,
            'testproject_name' => strval($control->args->testproject_name ?? ''),
            'testplan_id' => intval($control->args->testplan_id ?? $effTplanId),
            'testplan_name' => strval($control->args->testplan_name ?? ''),
            'setting_build' => intval($control->args->setting_build ?? 0),
            'setting_platform' => isset($control->args->setting_platform)
                                  ? intval($control->args->setting_platform) : null,
            'not_run' => strval($gui->not_run ?? ''),
            'latest_exec_method' => intval($gui->lastest_exec_method ?? 0),
            'load_exec_dashboard' => boolVal($gui->loadExecDashboard),
        ),
        'controls' => array(
            'testplans' => $tpOptions,
            'builds' => $buildOptions,
            'platforms' => $platformOptions,
        ),
        'settings' => array(
            'exec_tree_counters_logic' => $countersLogic,
            'refresh_tree_on_action' => $refreshTree,
        ),
        'filters' => $filtersOut,
        'rights' => array(
            'exec_access' => boolVal($gui->execAccess),
            'export' => boolVal($gui->features['export']),
            'import' => boolVal($gui->features['import']),
            'testplan_execute' => boolVal($grants['testplan_execute']),
            'exec_ro_access' => boolVal($grants['exec_ro_access']),
        ),
        'tree' => array(
            'root' => array(
                'id' => intval($root->id ?? 0),
                'name' => strval($root->name ?? ''),
                'text' => strval($root->text ?? $root->name ?? ''),
                'href' => strval($root->href ?? ''),
                'leaf' => boolVal($root->leaf ?? false),
                'position' => isset($root->position) ? intval($root->position) : 0,
            ),
            'children' => $children,
            'cookie_prefix' => strval($gui->ajaxTree->cookiePrefix ?? ''),
            'loader' => strval($gui->ajaxTree->loader ?? ''),
        ),
        'menu_url' => strval($gui->menuUrl),
        'args' => strval($control->get_argument_string()),
    ));
}

http_response_code(400);
out(['status' => 'error', 'message' => 'Unknown or missing action']);