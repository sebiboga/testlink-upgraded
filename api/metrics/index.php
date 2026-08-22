<?php
/**
 * Metrics Dashboard BFF API
 * URL: /api/metrics/
 * Plain PHP, no framework, no compilation
 *
 * Mirrors lib/results/metricsDashboard.php (TestLink 1.9.20):
 * - project-wide execution progress (progress bars)
 * - per test-plan (+platform) execution status table
 * - accessible test plans only, optional "show only active" filter
 * - rights: testplan_metrics OR testplan_execute
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

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

$path = $_SERVER['PATH_INFO'] ?? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = preg_replace('#^/api/metrics(/index\.php)?#', '', $path);
$path = '/' . trim($path, '/');
$method = $_SERVER['REQUEST_METHOD'];

function out($data) { echo json_encode($data); exit; }

function getPercentage($denominator, $numerator, $round_precision) {
    return ($numerator > 0) ? round(($denominator / $numerator) * 100, $round_precision) : 0;
}

function resolveTprojectId() {
    // explicit ?tproject_id= wins (like legacy R_PARAMS), else session project
    $tid = intval($_GET['tproject_id'] ?? 0);
    if ($tid <= 0) {
        $tid = intval($_SESSION['testprojectID'] ?? 0);
    }
    return $tid;
}

function checkDashboardRights(&$db, &$user) {
    $context = new stdClass();
    $context->tproject_id = resolveTprojectId();
    $context->tplan_id = null;
    $context->getAccessAttr = false;
    $checkOrMode = array('testplan_metrics', 'testplan_execute');
    foreach ($checkOrMode as $right) {
        if ($user->hasRightOnProj($db, $right, $context->tproject_id, $context->tplan_id, $context->getAccessAttr)) {
            return true;
        }
    }
    return false;
}

if ($method === 'GET' && $path === '/meta/rights') {
    out([
        'status' => 'ok',
        'canView' => checkDashboardRights($db, $user),
        'showTestPlanStatus' => (bool)config_get('metrics_dashboard')->show_test_plan_status,
        'precision' => intval(config_get('dashboard_precision')),
    ]);
}

if ($method === 'GET' && ($path === '/dashboard' || $path === '/dashboard/')) {
    if (!checkDashboardRights($db, $user)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'Insufficient rights']);
    }

    $tproject_id = resolveTprojectId();
    if ($tproject_id <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'No test project selected']);
    }

    // tri-state "show only active" exactly like the legacy page (session persisted)
    $paramActive = $_GET['show_only_active'] ?? null;
    if ($paramActive !== null && $paramActive !== '') {
        $selection = ((string)$paramActive === '1');
    } elseif (isset($_SESSION['show_only_active'])) {
        $selection = (bool)$_SESSION['show_only_active'];
    } else {
        $selection = true;
    }
    $_SESSION['show_only_active'] = $args_show_only_active = $selection;

    $result_cfg = config_get('results');
    $statusSetForDisplay = $result_cfg['status_label_for_exec_ui'];   // verbose => label_key
    $round_precision = intval(config_get('dashboard_precision'));

    $treeMgr = new tree($db);
    $nodeInfo = $treeMgr->get_node_hierarchy_info($tproject_id);
    $tproject_name = $nodeInfo ? $nodeInfo['name'] : '';

    $tplan_mgr = new testplan($db);
    $metricsMgr = new tlTestPlanMetrics($db);

    $codeStatusVerbose = array_flip($result_cfg['status_code']);

    // all test plans accessible for this user on this project
    $options = array('output' => 'map');
    $options['active'] = $selection ? ACTIVE : TP_ALL_STATUS;
    $test_plans = $user->getAccessibleTestPlans($db, $tproject_id, null, $options);

    $show_platforms = false;
    $rows = array();
    $total = array('active' => 0, 'executed' => 0);
    foreach ($statusSetForDisplay as $sv => $lbl) {
        $total[$sv] = 0;
    }
    $hasPlans = false;

    foreach ($test_plans as $key => $tpinfo) {
        // only plans with (active) builds can have execution data
        $buildSet = $tplan_mgr->get_builds($key, testplan::ACTIVE_BUILDS);
        if (is_null($buildSet)) {
            continue;
        }
        $hasPlans = true;

        $platformSet = $tplan_mgr->getPlatforms($key);
        $overall = array('active' => 0, 'executed' => 0);
        foreach ($statusSetForDisplay as $sv => $lbl) {
            $overall[$sv] = 0;
        }

        if (!is_null($platformSet)) {
            $show_platforms = true;
            $neurus = $metricsMgr->getExecCountersByPlatformExecStatus($key, null,
                        array('getPlatformSet' => true, 'getOnlyActiveTCVersions' => true));

            foreach ($neurus['with_tester'] as $platform_id => $pinfo) {
                $row = array(
                    'tplan_id' => intval($key),
                    'tplan_name' => $tpinfo['name'],
                    'platform_id' => intval($platform_id),
                    'platform_name' => $neurus['platforms'][$platform_id],
                    'active' => intval($neurus['total'][$platform_id]['qty']),
                    'statuses' => array(),
                );
                $row['executed'] = 0;
                foreach ($pinfo as $code => $elem) {
                    $sv = $codeStatusVerbose[$code];
                    $qty = intval($elem['exec_qty']);
                    $row['statuses'][$sv] = array(
                        'qty' => $qty,
                        'pct' => getPercentage($qty, $row['active'], $round_precision),
                    );
                    if ($sv != 'not_run') {
                        $row['executed'] += $qty;
                    }
                    if (!isset($overall[$sv])) {
                        $overall[$sv] = 0;
                    }
                    $overall[$sv] += $qty;
                    $total[$sv] += $qty;
                }
                $row['progress'] = getPercentage($row['executed'], $row['active'], $round_precision);
                $overall['executed'] += $row['executed'];
                $overall['active'] += $row['active'];
                $rows[] = $row;
            }
            $total['executed'] += $overall['executed'];
            $total['active'] += $overall['active'];
        } else {
            $ov = $metricsMgr->getExecCountersByExecStatus($key, null,
                      array('getOnlyActiveTCVersions' => true));
            if (!is_array($ov)) {
                $ov = array();
            }
            $overall['active'] = isset($ov['total']) ? intval($ov['total']) : 0;
            $overall['executed'] = 0;

            $statuses = array();
            foreach ($ov as $sc => $qty) {
                if ($sc == 'total' || $sc == 'active') {
                    continue;
                }
                $qty = intval($qty);
                if ($sc == 'not_run') {
                    $statuses['not_run'] = $qty;
                    continue;
                }
                $statuses[$sc] = $qty;
                $overall['executed'] += $qty;
            }
            foreach ($statusSetForDisplay as $sv => $lbl) {
                if (!isset($statuses[$sv])) {
                    $statuses[$sv] = 0;
                }
                $overall[$sv] += $statuses[$sv];
                $total[$sv] += $statuses[$sv];
            }
            $total['executed'] += $overall['executed'];
            $total['active'] += $overall['active'];

            $rows[] = array(
                'tplan_id' => intval($key),
                'tplan_name' => $tpinfo['name'],
                'platform_id' => 0,
                'platform_name' => null,     // n/a -> client i18n
                'active' => $overall['active'],
                'executed' => $overall['executed'],
                'progress' => getPercentage($overall['executed'], $overall['active'], $round_precision),
                'statuses' => array(),
                'overall' => array(
                    'active' => $overall['active'],
                    'executed' => $overall['executed'],
                    'progress' => getPercentage($overall['executed'], $overall['active'], $round_precision),
                    'statuses' => $statuses,
                ),
            );
        }

        // keep an overall block for platform rows too
        $last = count($rows) - 1;
        while ($last >= 0 && $rows[$last]['tplan_id'] == intval($key)) {
            if (!isset($rows[$last]['overall'])) {
                $rows[$last]['overall'] = array(
                    'active' => $overall['active'],
                    'executed' => $overall['executed'],
                    'progress' => getPercentage($overall['executed'], $overall['active'], $round_precision),
                    'statuses' => array(),
                );
                foreach ($statusSetForDisplay as $sv => $lbl) {
                    $rows[$last]['overall']['statuses'][$sv] = isset($overall[$sv]) ? $overall[$sv] : 0;
                }
            }
            $last--;
        }
    }

    // project-level progress (progress bars)
    $projectMetrics = array();
    $projectMetrics[] = array(
        'key' => 'executed',
        'value' => getPercentage($total['executed'], $total['active'], $round_precision),
    );
    foreach ($statusSetForDisplay as $sv => $lbl) {
        $projectMetrics[] = array(
            'key' => $sv,
            'value' => getPercentage(isset($total[$sv]) ? $total[$sv] : 0, $total['active'], $round_precision),
        );
    }

    // public direct link (same as legacy lnl.php deep link)
    $apiKey = testproject::getAPIkey($db, $tproject_id);
    $basehref = ($_SESSION['basehref'] ?? '../../');
    $directLink = $basehref . 'lnl.php?type=metricsdashboard&apikey=' . $apiKey;

    out(array(
        'status' => 'ok',
        'tproject_id' => $tproject_id,
        'tproject_name' => $tproject_name,
        'show_only_active' => $selection,
        'show_platforms' => $show_platforms,
        'show_test_plan_status' => (bool)config_get('metrics_dashboard')->show_test_plan_status,
        'status_set' => array_keys($statusSetForDisplay),
        'direct_link' => $directLink,
        'warning' => (!$hasPlans || count($rows) == 0) ? 'no_testplans_available' : '',
        'project_metrics' => $projectMetrics,
        'testplans' => $rows,
        'generated_on' => date('Y-m-d H:i:s'),
    ));
}

http_response_code(404);
out(['status' => 'error', 'message' => 'Not found']);
