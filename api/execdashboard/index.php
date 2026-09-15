<?php
/**
 * Execution Dashboard BFF API
 * URL: /api/execdashboard/index.php
 * Plain PHP, no framework — modernizes the legacy
 * lib/execute/execDashboard.php landing pane of the Execute Tests area
 * (gui/templates/dashio/execute/execDashboard.tpl, Refs #1496).
 *
 * The legacy controller resolved the current execution context
 * (tplan/build/platform) from the SESSION 'execution_mode' cache (keyed by
 * form_token) or REQUEST, fell back to the plan's max (active+open) build and
 * first linked platform, then rendered the "Execution context" header page:
 * test plan name/notes + design-scope custom fields with show_on_execution=1,
 * build name/notes + custom fields, an amber closed-build warning box, the
 * linked platform name/notes and the REST API parameter triplet
 * (testPlanID/buildID/platformID) used by the execTest toolbar.
 *
 * Read access mirrors api/execute: testplan_execute OR exec_ro_access on the
 * owning test project. Write context switching mirrors api/execute too
 * (testplan_execute required).
 *
 * Endpoints (JSON in/out):
 *   GET  ?action=init&tplan_id=N[&build_id=N][&platform_id=N][&tproject_id=N]
 *        -> resolved context + tplan/build/platform notes, cfields HTML,
 *           closed-build flag, selector lists, grants, rest_args.
 *        Context params follow the legacy precedence: explicit build_id /
 *        platform_id win, otherwise session 'execution_mode' cache, otherwise
 *        legacy defaults (max active+open build / first linked platform).
 *   POST ?action=context  {tplan_id, build_id, platform_id, form_token}
 *        -> persists the execution context into the SESSION 'execution_mode'
 *           cache under the form token (legacy getContextFromGlobalScope
 *           contract), so the execTest toolbar and dashboard agree.
 *        Requires testplan_execute (a write to session scope).
 */
require_once(__DIR__ . '/../../config.inc.php');
require_once(__DIR__ . '/../../config_db.inc.php');
require_once('common.php');

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

function out($data) { echo json_encode($data); exit; }

$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_REQUEST['action']) ? trim($_REQUEST['action']) : '';
$BODY = json_decode(file_get_contents('php://input'), true) ?? [];

$tplanMgr = new testplan($db);
$buildMgr = new build($db);
$platformMgr = new tlPlatform($db, 0);
$treeMgr = new tree($db);

/** Iffy int from REQUEST or JSON body, honoring the legacy setting_* names. */
function ctxInt($name) {
    global $BODY;
    if (isset($_REQUEST[$name]) && $_REQUEST[$name] !== '') {
        return intval($_REQUEST[$name]);
    }
    if (array_key_exists($name, $BODY) && $BODY[$name] !== null) {
        return intval($BODY[$name]);
    }
    return 0;
}
function ctxIntAlt($a, $b) {
    $v = ctxInt($a);
    return $v > 0 ? $v : ctxInt($b);
}

/**
 * Resolve tproject_id for a plan: explicit param wins, else walk the plan
 * node up nodes_hierarchy (legacy get_node_hierarchy_info parent_id).
 */
function resolveTprojectId($tplanId) {
    global $db, $treeMgr, $tplanMgr;
    $tid = ctxIntAlt('tproject_id', 'setting_tproject');
    if ($tid > 0) {
        return $tid;
    }
    $info = $treeMgr->get_node_hierarchy_info($tplanId);
    if (is_array($info) && isset($info['parent_id'])) {
        return intval($info['parent_id']);
    }
    $trows = $db->get_recordset(
        'SELECT testproject_id FROM ' . $tplanMgr->object_table .
        ' WHERE id = ' . intval($tplanId));
    return ($trows && count($trows) > 0)
        ? intval($trows[0]['testproject_id']) : 0;
}

/** Plan must exist and the user needs read-level exec access on its project. */
function requireExecReadAssess($tplanId) {
    global $db, $user, $tplanMgr;
    $info = $tplanMgr->get_by_id($tplanId);
    if (!$info) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Test plan does not exist']);
    }
    $tid = resolveTprojectId($tplanId);
    if ($tid <= 0) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Test project not resolvable']);
    }
    $ok = $user->hasRight($db, 'testplan_execute', $tid, $tplanId) ||
          $user->hasRight($db, 'exec_ro_access', $tid, $tplanId);
    if (!$ok) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'You are not authorized to execute tests on this plan']);
    }
    return ['tproject_id' => $tid, 'plan' => $info];
}

/** Legacy default: max build id among ACTIVE (1) OPEN (1) builds of the plan. */
function defaultBuildId($tplanId) {
    global $tplanMgr;
    $id = $tplanMgr->get_max_build_id($tplanId, 1, 1);
    if (is_null($id) || $id === false) {
        $id = $tplanMgr->get_max_build_id($tplanId);
    }
    return intval($id);
}

/** Resolve the platform id from the plan's linked platform list. */
function linkedPlatforms($tplanId, $tprojectId) {
    global $platformMgr;
    $platformMgr->setTestProjectID(intval($tprojectId));
    $dummy = $platformMgr->getLinkedToTestplan($tplanId);
    if (is_null($dummy)) {
        return [];
    }
    $list = [];
    foreach ($dummy as $p) {
        $list[] = [
            'id'   => intval($p['id']),
            'name' => (string)$p['name'],
        ];
    }
    return $list;
}

/** Small HTML table of design-scope custom fields shown on execution. */
function cfieldsHtml($target) {
    return is_string($target) ? $target : '';
}

/** Web editor type of a notes field: 'none' means plain text. */
function notesEditorType($cfgKey) {
    $cfg = getWebEditorCfg($cfgKey);
    return isset($cfg['type']) ? $cfg['type'] : 'none';
}

// -------------------------------------------------------------- init --------
if ($method === 'GET' && $action === 'init') {
    $tplanId = ctxIntAlt('tplan_id', 'setting_testplan');
    if ($tplanId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Bad Test Plan ID']);
    }
    $ctx = requireExecReadAssess($tplanId);
    $tid = $ctx['tproject_id'];
    $plan = $ctx['plan'];

    // build: explicit param > token-scoped session execution_mode cache
    // (only the entry tied to THIS plan, legacy getContextFromGlobalScope) >
    // legacy live-scope stored_setting_build > legacy default (newest
    // active+open build).
    $buildId = ctxIntAlt('build_id', 'setting_build');
    $sessionCache = isset($_SESSION['execution_mode'])
        && is_array($_SESSION['execution_mode']) ? $_SESSION['execution_mode'] : [];
    if ($buildId <= 0) {
        foreach ($sessionCache as $tokenEntry) {
            if (is_array($tokenEntry)
                && isset($tokenEntry['setting_testplan'])
                && intval($tokenEntry['setting_testplan']) === intval($tplanId)
                && isset($tokenEntry['setting_build'])
                && intval($tokenEntry['setting_build']) > 0) {
                $buildId = intval($tokenEntry['setting_build']);
                break;
            }
        }
    }
    if ($buildId <= 0 && isset($_SESSION[$tplanId . '_stored_setting_build'])
        && intval($_SESSION[$tplanId . '_stored_setting_build']) > 0) {
        $buildId = intval($_SESSION[$tplanId . '_stored_setting_build']);
    }
    $buildId = $buildId > 0 ? $buildId : defaultBuildId($tplanId);

    $buildInfo = $buildMgr->get_by_id($buildId);
    if (!$buildInfo) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Build does not exist']);
    }
    $buildOptions = $tplanMgr->get_builds_for_html_options($tplanId);
    $buildName = '';
    if (is_array($buildOptions) && isset($buildOptions[$buildId])) {
        $buildName = (string)$buildOptions[$buildId];
    }

    $platforms = linkedPlatforms($tplanId, $tid);
    // platform: explicit param > token-scoped session cache > legacy
    // live-scope stored_setting_platform > first linked platform
    $platformId = ctxIntAlt('platform_id', 'setting_platform');
    if ($platformId <= 0) {
        foreach ($sessionCache as $tokenEntry) {
            if (is_array($tokenEntry)
                && isset($tokenEntry['setting_testplan'])
                && intval($tokenEntry['setting_testplan']) === intval($tplanId)
                && isset($tokenEntry['setting_platform'])
                && intval($tokenEntry['setting_platform']) > 0) {
                $platformId = intval($tokenEntry['setting_platform']);
                break;
            }
        }
    }
    if ($platformId <= 0 && isset($_SESSION[$tplanId . '_stored_setting_platform'])
        && intval($_SESSION[$tplanId . '_stored_setting_platform']) > 0) {
        $platformId = intval($_SESSION[$tplanId . '_stored_setting_platform']);
    }
    $platformInfo = null;
    if ($platformId > 0) {
        $platformMgr->setTestProjectID($tid);
        $pi = $platformMgr->getByID($platformId);
        if (is_array($pi)) {
            $platformInfo = [
                'id'         => intval($pi['id']),
                'name'       => (string)($pi['name'] ?? ''),
                'notes'      => (string)($pi['notes'] ?? ''),
                'notes_type' => notesEditorType('platform'),
            ];
        }
    }
    if (is_null($platformInfo) && count($platforms) > 0) {
        $platformId = intval($platforms[0]['id']);
        $platformMgr->setTestProjectID($tid);
        $pi = $platformMgr->getByID($platformId);
        if (is_array($pi)) {
            $platformInfo = [
                'id'         => intval($pi['id']),
                'name'       => (string)($pi['name'] ?? ''),
                'notes'      => (string)($pi['notes'] ?? ''),
                'notes_type' => notesEditorType('platform'),
            ];
        }
    }

    // build cfields need the owning tproject id (build cfields are keyed by
    // tproject as the design scope container id)
    $tprojectMgr = new testproject($db);
    $buildCfields = '';
    try {
        $buildCfields = (string)$buildMgr->html_table_of_custom_field_values(
            $buildId, $tid, 'design', ['show_on_execution' => 1]);
    } catch (\Throwable $e) {
        $buildCfields = '';
    }
    $tplanCfields = '';
    try {
        $tplanCfields = (string)$tplanMgr->html_table_of_custom_field_values(
            $tplanId, 'design', ['show_on_execution' => 1]);
    } catch (\Throwable $e) {
        $tplanCfields = '';
    }

    $buildsSelect = [];
    if (is_array($buildOptions)) {
        $bt = tlObjectWithDB::getDBTables(['builds']);
        $openByBuild = $db->get_recordset(
            'SELECT id, is_open FROM ' . $bt['builds'] .
            ' WHERE testproject_id = ' . intval($tid));
        $openMap = [];
        if ($openByBuild) {
            foreach ($openByBuild as $ob) {
                $openMap[intval($ob['id'])] = intval($ob['is_open']) === 1;
            }
        }
        foreach ($buildOptions as $bid => $bname) {
            $buildsSelect[] = [
                'id'     => intval($bid),
                'name'   => (string)$bname,
                'is_open'=> isset($openMap[intval($bid)])
                           && $openMap[intval($bid)] ? 1 : 0,
            ];
        }
    }

    $grants = [
        'execute'         => $user->hasRight($db, 'testplan_execute', $tid, $tplanId) ? 1 : 0,
        'edit_exec_notes' => ($user->hasRight($db, 'testplan_execute', $tid, $tplanId) &&
                              $user->hasRight($db, 'exec_edit_notes', $tid, $tplanId)) ? 1 : 0,
        'delete_execution'=> $user->hasRight($db, 'exec_delete', $tid, $tplanId) ? 1 : 0,
        'edit_testcase'   => $user->hasRight($db, 'mgt_modify_tc', $tid, $tplanId) ? 1 : 0,
    ];

    out([
        'status'  => 'ok',
        'context' => [
            'tproject_id' => intval($tid),
            'tplan_id'    => intval($tplanId),
            'build_id'    => intval($buildId),
            'platform_id' => intval($platformId ? $platformId : 0),
            'tcase_prefix'=> (string)$tprojectMgr->getTestCasePrefix($tid),
        ],
        'tplan' => [
            'id'         => intval($tplanId),
            'name'       => (string)$plan['name'],
            'notes'      => (string)($plan['notes'] ?? ''),
            'notes_type' => notesEditorType('testplan'),
            'cfields'    => cfieldsHtml($tplanCfields),
        ],
        'build' => [
            'id'         => intval($buildId),
            'name'       => $buildName,
            'notes'      => (string)($buildInfo['notes'] ?? ''),
            'notes_type' => notesEditorType('build'),
            'is_open'    => intval($buildInfo['is_open'] ?? 1) === 1 ? 1 : 0,
            'cfields'    => cfieldsHtml($buildCfields),
        ],
        'platform' => $platformInfo,
        'platforms' => $platforms,
        'builds'   => $buildsSelect,
        'grants'   => $grants,
        'rest_args' => [
            'testPlanID' => intval($tplanId),
            'buildID'    => intval($buildId),
            'platformID' => intval($platformId),
        ],
    ]);
}

// ----------------------------------------------------------- context --------
if ($method === 'POST' && $action === 'context') {
    $tplanId = ctxIntAlt('tplan_id', 'setting_testplan');
    if ($tplanId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Bad Test Plan ID']);
    }
    $ctx = requireExecReadAssess($tplanId);
    $tid = $ctx['tproject_id'];
    if (!$user->hasRight($db, 'testplan_execute', $tid, $tplanId)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'You are not authorized to set the execution context']);
    }

    $buildId = ctxIntAlt('build_id', 'setting_build');
    $platformId = ctxIntAlt('platform_id', 'setting_platform');

    $formToken = isset($BODY['form_token']) ? $BODY['form_token']
        : (isset($_REQUEST['form_token']) ? $_REQUEST['form_token'] : '');

    $store = new stdClass();
    $store->setting_testplan = intval($tplanId);
    $store->setting_build = intval($buildId);
    $store->setting_platform = intval($platformId);

    if ($formToken !== '') {
        if (!isset($_SESSION['execution_mode']) || !is_array($_SESSION['execution_mode'])) {
            $_SESSION['execution_mode'] = [];
        }
        $_SESSION['execution_mode'][$formToken] = (array)$store;
    } else {
        // no token -> legacy REQUEST-scoped behaviour is a no-op for session;
        // keep the plan/build/platform session live-scope keys in sync so the
        // execTest toolbar default selectors pick them up.
        $_SESSION[$tplanId . '_stored_setting_build'] = intval($buildId);
        $_SESSION[$tplanId . '_stored_setting_platform'] = intval($platformId);
    }

    out([
        'status' => 'ok',
        'context' => [
            'tplan_id'    => intval($tplanId),
            'build_id'    => intval($buildId),
            'platform_id' => intval($platformId),
        ],
    ]);
}

http_response_code(404);
out(['status' => 'error', 'message' => 'Unknown action']);