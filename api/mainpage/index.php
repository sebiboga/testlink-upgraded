<?php
/**
 * Dashboard (Home / Main Page) BFF API
 * URL: /api/mainpage/
 * Plain PHP, no framework, no compilation.
 *
 * Mirrors lib/general/mainPage.php (TestLink 1.9.20) dashboard widgets:
 *  - execution status totals (passed/failed/blocked/not_run) for the selected
 *    test plan, with the overall completion percentage
 *  - monthly test case growth (project-scoped, last 12 months)
 *  - bugs that testers attached to executions of the current test plan
 *  - all open issues on the project's linked issue tracker (project-wide)
 * plus the list of test plans accessible to the user for the project selector.
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
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

function out($data) { echo json_encode($data); exit; }

function resolveContext() {
    $tid = intval($_GET['tproject_id'] ?? 0);
    if ($tid <= 0) {
        $tid = intval($_SESSION['testprojectID'] ?? 0);
    }
    $tpid = intval($_GET['tplan_id'] ?? 0);
    if ($tpid <= 0) {
        $tpid = intval($_SESSION['testplanID'] ?? 0);
    }
    return array('tproject_id' => $tid, 'tplan_id' => $tpid);
}

/**
 * Execution status totals for the selected test plan, grouped by top-level
 * test suite and summed across platforms (mirrors getDashboardData()).
 * Returns null when there is nothing to draw (no project/plan, no builds, a
 * plan with no executed cases).
 */
function dashboardData(&$db, $tprojectID, $tplanID) {
    if ($tprojectID <= 0 || $tplanID <= 0) {
        return null;
    }

    $tplanMgr = new testplan($db);
    if ($tplanMgr->getNumberOfBuilds($tplanID) == 0) {
        return null;
    }

    $metricsMgr = new tlTestPlanMetrics($db);
    $rx = $metricsMgr->getStatusTotalsByTopLevelTestSuiteForRender($tplanID, null,
            array('groupByPlatform' => 1));
    if (is_null($rx) || !property_exists($rx, 'info') || is_null($rx->info)) {
        return null;
    }

    $resultsCfg = config_get('results');
    $dbo = array();
    $dbo['total'] = 0;
    $dbo['slices'] = array();

    $palette = array('passed' => '#4ECDC4', 'failed' => '#e6605e',
                     'blocked' => '#f0ad4e', 'not_run' => '#8f8f8f');

    foreach ($palette as $statusVerbose => $color) {
        $qty = 0;
        foreach ($rx->info as $suiteSet) {
            foreach ($suiteSet as $suiteInfo) {
                if (isset($suiteInfo['details'][$statusVerbose]['qty'])) {
                    $qty += intval($suiteInfo['details'][$statusVerbose]['qty']);
                }
            }
        }

        $lblKey = isset($resultsCfg['status_label'][$statusVerbose])
                  ? $resultsCfg['status_label'][$statusVerbose] : $statusVerbose;

        $dbo['slices'][$statusVerbose] = array(
            'qty' => $qty, 'percentage' => 0, 'color' => $color,
            'label' => lang_get($lblKey),
        );
        $dbo['total'] += $qty;
    }

    if ($dbo['total'] == 0) {
        return null;
    }

    $executed = $dbo['total'] - $dbo['slices']['not_run']['qty'];
    $dbo['executed'] = $executed;
    $dbo['percentage_completed'] = number_format(100 * ($executed / $dbo['total']), 1);

    foreach ($dbo['slices'] as $statusVerbose => $slice) {
        $dbo['slices'][$statusVerbose]['percentage'] =
            number_format(100 * ($slice['qty'] / $dbo['total']), 1);
    }

    $dbo['tplan_name'] = isset($_SESSION['testplanName']) ? $_SESSION['testplanName'] : '';
    return $dbo;
}

/**
 * Monthly test case growth, project-scoped (mirrors getTestCaseGrowthData()).
 * Returns null when the project has no test cases at all.
 */
function tcGrowthData(&$db, $tprojectID) {
    if ($tprojectID <= 0) {
        return null;
    }

    $tprojectMgr = new testproject($db);
    $monthly = $tprojectMgr->getTestCaseCreationMonthly($tprojectID, 12);

    if (is_array($monthly) && array_sum($monthly) == 0) {
        return null;
    }
    if (!is_array($monthly) || count($monthly) == 0) {
        return null;
    }

    $gx = array('labels' => array(), 'values' => array(), 'total' => 0, 'peak' => 0);
    foreach ($monthly as $yearMonth => $qty) {
        $gx['labels'][] = date('M y', strtotime($yearMonth . '-01'));
        $gx['values'][] = (int)$qty;
        $gx['total'] += (int)$qty;
        $gx['peak'] = max($gx['peak'], (int)$qty);
    }

    return $gx;
}

/**
 * Bugs linked to executions of the current test plan (mirrors
 * getBugsTestedData()). Returns null when no bugs are linked.
 */
function bugsData(&$db, $tprojectID, $tplanID) {
    if ($tprojectID <= 0 || $tplanID <= 0) {
        return null;
    }

    $tplanMgr = new testplan($db);
    $rows = $tplanMgr->getAllExecutionsWithBugs($tplanID);

    if (empty($rows)) {
        return null;
    }

    $bugs = array();
    foreach ($rows as $row) {
        $bugID = $row['bug_id'];
        if (!isset($bugs[$bugID])) {
            $bugs[$bugID] = array();
        }
        $bugs[$bugID][$row['full_external_id'] . ':' . $row['name']] = true;
    }

    $its = null;
    $tprojectMgr = new testproject($db);
    $tprojectInfo = $tprojectMgr->get_by_id($tprojectID);
    if (!empty($tprojectInfo['issue_tracker_enabled'])) {
        $itMgr = new tlIssueTracker($db);
        $its = $itMgr->getInterfaceObject($tprojectID);
    }

    $statusColor = array('closed' => '#5cb85c', 'open' => '#e6605e');

    $dbo = array();
    foreach ($bugs as $bugID => $tcaseSet) {
        $item = array('id' => (string)$bugID,
                      'tcases' => implode(', ', array_keys($tcaseSet)),
                      'url' => '',
                      'title' => '',
                      'status' => '',
                      'color' => '#8f8f8f');

        if (is_object($its)) {
            $issue = $its->getIssue($bugID);
            if (is_object($issue)) {
                $item['url'] = $issue->url;
                $item['title'] = rtrim(strtok((string)$issue->summary, "\n"), ':');
                $item['status'] = (string)$issue->statusVerbose;

                $key = strtolower($item['status']);
                if (isset($statusColor[$key])) {
                    $item['color'] = $statusColor[$key];
                }
            }
        }

        $dbo[] = $item;
    }

    return $dbo;
}

/**
 * All open issues on the project's linked issue tracker (project-wide,
 * mirrors getProjectIssuesData()). Only trackers exposing listIssues() are
 * supported; for all others the widget is absent (null).
 */
function projectIssuesData(&$db, $tprojectID) {
    if ($tprojectID <= 0) {
        return null;
    }

    $tprojectMgr = new testproject($db);
    $tprojectInfo = $tprojectMgr->get_by_id($tprojectID);
    if (empty($tprojectInfo['issue_tracker_enabled'])) {
        return null;
    }

    $itMgr = new tlIssueTracker($db);
    $its = $itMgr->getInterfaceObject($tprojectID);
    if (!is_object($its) || !method_exists($its, 'listIssues')) {
        return null;
    }

    $issues = $its->listIssues('open', 100, 1, 'created');
    if (!is_array($issues) || count($issues) == 0) {
        return null;
    }

    $statusColor = array('closed' => '#5cb85c', 'open' => '#e6605e');

    $dbo = array();
    foreach ($issues as $issue) {
        $item = array('id' => (string)$issue->id,
                      'number' => (string)$issue->number,
                      'url' => $issue->url,
                      'title' => $issue->title,
                      'status' => $issue->state,
                      'color' => '#8f8f8f',
                      'labels' => array());

        if (isset($issue->labels) && is_array($issue->labels)) {
            $item['labels'] = $issue->labels;
        }

        if (isset($statusColor[$issue->state])) {
            $item['color'] = $statusColor[$issue->state];
        }

        $dbo[] = $item;
    }

    return $dbo;
}

$path = $_SERVER['PATH_INFO'] ?? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = preg_replace('#^/api/mainpage(/index\.php)?#', '', $path);
$path = '/' . trim($path, '/');
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET' && ($path === '/data' || $path === '' || $path === '/')) {
    $ctx = resolveContext();
    $tprojectID = $ctx['tproject_id'];
    $tplanID = $ctx['tplan_id'];

    if ($tprojectID <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'No test project selected']);
    }

    // Test plans accessible to the user on this project (for the selector +
    // to let the page fall back to the user's default plan). A project with no
    // accessible plans is treated as NOT accessible — the user must never
    // read another project's data by crafting ?tproject_id=/tplan_id=.
    $arrPlans = (array)$user->getAccessibleTestPlans($db, $tprojectID);
    if (count($arrPlans) == 0) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No access to this test project']);
    }

    // If the requested plan is not among the user's accessible plans for this
    // project, fall back to the first accessible plan (mirrors the legacy
    // mainPage.php fallback) instead of trusting the raw query value.
    $accessibleIds = array();
    foreach ($arrPlans as $p) {
        $accessibleIds[] = (int)$p['id'];
    }
    if ($tplanID > 0 && !in_array((int)$tplanID, $accessibleIds, true)) {
        $tplanID = (int)$arrPlans[0]['id'];
    }

    $plans = array();
    $currentPlanName = '';
    foreach ($arrPlans as $p) {
        $plans[] = array('id' => (int)$p['id'],
                         'name' => $p['name'],
                         'selected' => ((int)$p['id'] === (int)$tplanID) ? 1 : 0);
        if ((int)$p['id'] === (int)$tplanID) {
            $currentPlanName = $p['name'];
        }
    }

    $nodeMgr = new tree($db);
    $nodeInfo = $nodeMgr->get_node_hierarchy_info($tprojectID);
    $tprojectName = $nodeInfo ? $nodeInfo['name'] : '';

    // Preserve/commit the plan to the session so the selected plan for
    // subsequent screens matches what the dashboard shows (mirrors the
    // session commit legacy mainPage.php did on landing).
    if ($tplanID > 0) {
        foreach ($arrPlans as $p) {
            if ((int)$p['id'] === (int)$tplanID) {
                setSessionTestPlan($p);
                break;
            }
        }
    }

    $dashboard = dashboardData($db, $tprojectID, $tplanID);
    if ($dashboard !== null) {
        $dashboard['tplan_name'] = $currentPlanName ?: $dashboard['tplan_name'];
    }

    out(array(
        'status' => 'ok',
        'tproject_id' => $tprojectID,
        'tproject_name' => $tprojectName,
        'tplan_id' => $tplanID,
        'tplans' => $plans,
        'dashboard' => $dashboard,
        'tc_growth' => tcGrowthData($db, $tprojectID),
        'bugs' => bugsData($db, $tprojectID, $tplanID),
        'open_issues' => projectIssuesData($db, $tprojectID),
        'generated_on' => date('Y-m-d H:i:s'),
    ));
}

http_response_code(404);
out(['status' => 'error', 'message' => 'Not found']);
