<?php
/**
 * Assign Test Case to Test Plan BFF API
 * URL: /api/tcassign2tplan/
 * Plain PHP, no framework.
 *
 * Mirrors lib/testcases/tcAssign2Tplan.php (TestLink 1.9.20 behavior):
 * while in the test specification feature, show which ACTIVE test plans the
 * given TEST CASE VERSION is linked to (and which it can be added to), then
 * link the target version to the selected test plan/platform pairs.
 *
 * The legacy controller only required a valid session (testlinkInitPage);
 * there is no extra menu right on the standalone screen, so the BFF preserves
 * that for the read and write routes (any authenticated session in the test
 * project context). The tcase must belong to the given project.
 *
 * Routes:
 *   GET  /init?tcase_id=&tcversion_id=&tproject_id=
 *   POST /add  {tcase_id, tcversion_id, tproject_id, add2tplanid:{tplan_id:{platform_id:1}}}
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');

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

$path = $_SERVER['PATH_INFO'] ?? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = preg_replace('#^/api/tcassign2tplan(/index\.php)?#', '', $path);
$path = '/' . trim($path, '/');
$method = $_SERVER['REQUEST_METHOD'];
$segments = array_values(array_filter(explode('/', $path)));

function out($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}
function bffBody() {
    static $body = null;
    if ($body === null) {
        $j = json_decode(file_get_contents('php://input'), true);
        $body = is_array($j) ? $j : [];
    }
    return $body;
}
function getParam($key, $default = null) {
    if (isset($_GET[$key])) { return $_GET[$key]; }
    if (isset($_POST[$key])) { return $_POST[$key]; }
    $body = bffBody();
    if (isset($body[$key])) { return $body[$key]; }
    return $default;
}
function failsafeShutdown() { exit; }
register_shutdown_function('failsafeShutdown');

$action = isset($_GET['action']) ? $_GET['action']
    : ($segments[0] ?? (bffBody()['action'] ?? null));

function resolveContext(&$db, &$user, $tcase_id, $tcversion_id, $tproject_id) {
    $tcaseMgr = new testcase($db);
    $tprojectMgr = new testproject($db);
    $tplanMgr = new testplan($db);

    $tcaseId = intval($tcase_id);
    $tcverId = intval($tcversion_id);
    $tprojId = intval($tproject_id);
    if ($tcaseId <= 0 || $tprojId <= 0) {
        out(['status' => 'error', 'message' => 'Missing or invalid context'], 400);
    }

    // tcase must belong to the project context (nodes_hierarchy parenting).
    $parentQ = "SELECT parent_id FROM nodes_hierarchy WHERE id = {$tcaseId}";
    $pid = intval($db->fetchFirstRowSingleColumn($parentQ, 'parent_id'));
    $ok = false;
    $walk = $pid;
    $guard = 40;
    while ($walk > 0 && $guard-- > 0) {
        if ($walk == $tprojId) { $ok = true; break; }
        $walk = intval($db->fetchFirstRowSingleColumn(
            "SELECT parent_id FROM nodes_hierarchy WHERE id = {$walk}",
            'parent_id'));
    }
    if (!$ok) {
        out(['status' => 'error', 'message' => 'Test case not found in project context'], 404);
    }

    // version (number) + external identity for display, mirroring legacy.
    $glue = config_get('testcase_cfg')->glue_character;
    $version = null;
    $tcaseIdentity = '';
    $tcName = '';
    $options = ['output' => 'essential'];
    $all = $tcaseMgr->get_by_id($tcaseId, testcase::ALL_VERSIONS, null, $options);
    if (!is_null($all)) {
        foreach ($all as $tcvInfo) {
            if (intval($tcvInfo['id']) == $tcverId) {
                $version = $tcvInfo['version'];
                $tcName = $tcvInfo['name'];
                $prefix = $tprojectMgr->getTestCasePrefix($tprojId);
                $tcaseIdentity = $prefix . $glue . $tcvInfo['tc_external_id'] . ':' . $tcvInfo['name'];
                break;
            }
        }
    }
    if (is_null($version)) {
        // fall back: accept the requested version id if tcase exists but the
        // version record was filtered; legacy would simply leave $version null.
        $version = '';
    }

    return [$tcaseMgr, $tplanMgr, $tprojectMgr, $tcaseId, $tcverId, $tprojId,
            $version, $tcaseIdentity, $tcName];
}

// Build the plan/platform grid exactly as the legacy controller does.
function buildGrid(&$db, &$tcaseMgr, &$tplanMgr, &$tprojectMgr, $tcaseId, $tcverId, $tprojId, $version) {
    $linkInfo = $tcaseMgr->get_linked_versions($tcaseId);
    $tplanSet = $tprojectMgr->get_all_testplans($tprojId, ['plan_status' => 1]);
    $plans = [];

    if (is_null($tplanSet)) {
        return ['plans' => [], 'can_do' => false];
    }

    $hasLinks = array_fill_keys(array_keys($tplanSet), false);
    $linkedTplans = null;
    if (!is_null($linkInfo)) {
        foreach ($linkInfo as $tcvId => $info) {
            foreach ($info as $tplanId => $platformInfo) {
                $tplanId = intval($tplanId);
                $hasLinks[$tplanId] = true;
                foreach ($platformInfo as $platformId => $value) {
                    $linkedTplans[$tplanId][$platformId]['tcversion_id'] = $value['tcversion_id'];
                    $linkedTplans[$tplanId][$platformId]['version'] = $value['version'];
                    $linkedTplans[$tplanId][$platformId]['draw_checkbox'] = false;
                }
            }
        }
    }

    $getOpt = ['outputFormat' => 'map', 'addIfNull' => true];
    $grid = [];
    $canDo = false;
    foreach ($tplanSet as $tplanId => $value) {
        $tplanId = intval($tplanId);
        $platformSet = $tplanMgr->getPlatforms($tplanId, $getOpt);
        $row = [
            'id' => $tplanId,
            'name' => $value['name'],
            'platforms' => [],
            'linked' => false,
            'target_version' => $version,
            'target_version_id' => $tcverId,
            'can_add' => false,
        ];

        $linkedPlatforms = null;
        $targetVersionNumber = $version;
        $targetVersionId = $tcverId;
        if ($hasLinks[$tplanId]) {
            $linkedPlatforms = array_flip(array_keys($linkedTplans[$tplanId]));
            $dummy = current($linkedTplans[$tplanId]);
            $targetVersionNumber = $dummy['version'];
            $targetVersionId = $dummy['tcversion_id'];
            $row['linked'] = true;
        }

        foreach ($platformSet as $platformId => $platformInfo) {
            $platformId = intval($platformId);
            $doAdd = true;
            $drawCheckbox = true;
            if ($hasLinks[$tplanId]) {
                if (isset($linkedPlatforms[$platformId])) {
                    $drawCheckbox = false;
                } elseif ($targetVersionNumber == $version) {
                    $drawCheckbox = true;
                } else {
                    $doAdd = false;
                }
            }
            if ($doAdd) {
                $row['platforms'][] = [
                    'platform_id' => $platformId,
                    'platform' => $platformInfo,
                    'tcversion_id' => $targetVersionId,
                    'version' => $targetVersionNumber,
                    'draw_checkbox' => $drawCheckbox,
                    'already_linked' => !$drawCheckbox,
                ];
                if ($drawCheckbox) {
                    $row['can_add'] = true;
                    $canDo = true;
                }
            }
        }
        $grid[] = $row;
    }

    return ['plans' => $grid, 'can_do' => $canDo];
}

switch ($action) {
    case 'init':
        if ($method !== 'GET') { out(['status' => 'error', 'message' => 'Method not allowed'], 405); }
        $tcaseId = intval(getParam('tcase_id', 0));
        $tcverId = intval(getParam('tcversion_id', 0));
        $tprojId = intval(getParam('tproject_id', 0));

        list($tcaseMgr, $tplanMgr, $tprojectMgr, $tcaseId2, $tcverId2, $tprojId2,
             $version, $tcaseIdentity, $tcName) =
            resolveContext($db, $user, $tcaseId, $tcverId, $tprojId);

        $gridData = buildGrid($db, $tcaseMgr, $tplanMgr, $tprojectMgr,
                              $tcaseId2, $tcverId2, $tprojId2, $version);

        out([
            'status' => 'ok',
            'tcase' => [
                'id' => $tcaseId2,
                'tcversion_id' => $tcverId2,
                'name' => $tcName,
                'version' => $version,
                'identity' => $tcaseIdentity,
            ],
            'tproject_id' => $tprojId2,
            'plans' => $gridData['plans'],
            'can_do' => $gridData['can_do'],
            'title' => lang_get('add_tcversion_to_plans'),
            'no_test_plans' => lang_get('no_test_plans'),
            'testplan_usage' => lang_get('testplan_usage'),
            'btn_add' => lang_get('btn_add'),
            'btn_cancel' => lang_get('btn_cancel'),
            'version_label' => lang_get('version'),
            'test_plan' => lang_get('test_plan'),
            'platform' => lang_get('platform'),
        ]);
        break;

    case 'add':
        if ($method !== 'POST') { out(['status' => 'error', 'message' => 'Method not allowed'], 405); }
        $tcaseId = intval(getParam('tcase_id', 0));
        $tcverId = intval(getParam('tcversion_id', 0));
        $tprojId = intval(getParam('tproject_id', 0));

        list($tcaseMgr, $tplanMgr, $tprojectMgr, $tcaseId2, $tcverId2, $tprojId2,
             $version, $tcaseIdentity, $tcName) =
            resolveContext($db, $user, $tcaseId, $tcverId, $tprojId);

        $body = bffBody();
        $add2tplanid = $body['add2tplanid'] ?? null;
        if (!is_array($add2tplanid) || count($add2tplanid) === 0) {
            out(['status' => 'error', 'message' => 'No test plan selected',
                 'error_code' => 'NO_PLAN_SELECTED'], 400);
        }

        $added = 0;
        $addedByPlan = [];
        try {
            foreach ($add2tplanid as $tplanId => $platformSet) {
                $tplanId = intval($tplanId);
                if (!is_array($platformSet)) { continue; }
                $platformSet = array_filter($platformSet, function ($v) {
                    return $v === true || $v === 1 || $v === '1' || $v === 'on';
                });
                if (count($platformSet) === 0) { continue; }
                $item2link = null;
                $item2link['tcversion'][$tcaseId2] = $tcverId2;
                $item2link['platform'] = [];
                $item2link['items'] = [];
                foreach (array_keys($platformSet) as $platformId) {
                    $platformId = intval($platformId);
                    $item2link['platform'][$platformId] = $platformId;
                    $item2link['items'][$tcaseId2][$platformId] = $tcverId2;
                }
                $tplanMgr->link_tcversions($tplanId, $item2link, intval($user->dbID));
                $addedByPlan[$tplanId] = array_keys($platformSet);
                $added++;
            }
        } catch (Throwable $e) {
            http_response_code(500);
            out(['status' => 'error', 'message' => $e->getMessage()]);
        }

        out([
            'status' => 'ok',
            'added' => $added,
            'added_by_plan' => $addedByPlan,
            'tcase_id' => $tcaseId2,
            'tcversion_id' => $tcverId2,
        ]);
        break;

    default:
        out(['status' => 'error', 'message' => 'Unknown action'], 404);
}
