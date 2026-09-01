<?php
/**
 * Dashboard (main page) BFF API
 * URL: /api/mainpage/
 * Plain PHP, no framework, no compilation
 *
 * Supplies all the widgets of the modernized Dashboard:
 *   - execution status pie (passed/failed/blocked/not run totals)
 *   - monthly test case growth bar chart
 *   - bugs linked to executions of the selected plan
 *   - project-wide open issues from the linked tracker (with labels)
 *
 * Backs gui/templates/mainpage/mainPage.html.
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
require_once('users.inc.php');

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

$path = $_SERVER['PATH_INFO'] ?? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = preg_replace('#^/api/mainpage(/index\.php)?#', '', $path);
$path = '/' . trim($path, '/');
$method = $_SERVER['REQUEST_METHOD'];
$segments = array_values(array_filter(explode('/', $path)));

function out($data) { echo json_encode($data); exit; }
function getParam($key, $default = null) { return $_GET[$key] ?? $default; }

/**
 * The modern screens pick a language through TLi18n (short code: 'ro'), which
 * is independent of $_SESSION['locale'] driving the Smarty pages. Map the
 * short code onto a TestLink locale so every label we translate (status
 * names, widget titles) matches the language the page renders in.
 */
function assignLocale() {
    $short = preg_replace('/[^a-z]/', '', strtolower((string) getParam('locale', '')));
    if ($short === '' || strlen($short) !== 2) {
        return null;
    }
    foreach (array_keys((array) config_get('locales')) as $code) {
        if (strpos(strtolower($code), $short) === 0) {
            return $code;
        }
    }
    return null;
}

$tprojectID = intval(getParam('tproject_id', 0));
if ($tprojectID <= 0) {
    $tprojectID = intval($_SESSION['testprojectID'] ?? 0);
}
$tplanID = intval(getParam('tplan_id', 0));
if ($tplanID <= 0) {
    $tplanID = intval($_SESSION['testplanID'] ?? 0);
}

$lang = assignLocale();
$lbl = function($key) use ($lang) {
    return lang_get($key, $lang);
};

// Route: GET / - full dashboard widget payload
if ($method === 'GET' && empty($segments)) {
    $tprojectMgr = new testproject($db);
    $tprojectInfo = null;
    if ($tprojectID > 0) {
        $tprojectInfo = $tprojectMgr->get_by_id($tprojectID);
    }

    $dashboard = getDashboardData($db, $tprojectID, $tplanID, $lbl);
    $tcGrowth = getTestCaseGrowthData($db, $tprojectID);
    $bugsInfo = getBugsTestedData($db, $tprojectID, $tplanID, $lbl);
    $projectIssues = getProjectIssuesData($db, $tprojectID);

    out([
        'status' => 'ok',
        'tproject' => [
            'id' => $tprojectID,
            'name' => is_array($tprojectInfo) ? ($tprojectInfo['name'] ?? '') : '',
        ],
        'tplan_name' => (string) ($_SESSION['testplanName'] ?? ''),
        'hasTestCases' => ($tprojectID > 0) ? ($tprojectMgr->count_testcases($tprojectID) > 0) : false,
        'dashboard' => $dashboard,
        'tcGrowth' => $tcGrowth,
        'bugsInfo' => $bugsInfo,
        'projectIssues' => $projectIssues,
    ]);
}

http_response_code(404);
out(['status' => 'error', 'message' => 'Not found']);

/**
 * Execution status counters for the dashboard pie + status table.
 *
 * Mirrors lib/general/mainPage.php::getDashboardData(). Returns null when
 * there is nothing worth drawing (no project, no plan, plan with no builds,
 * or no linked test cases).
 */
function getDashboardData(&$dbHandler, $tprojectID, $tplanID, $lbl)
{
    if ($tprojectID <= 0 || $tplanID <= 0) {
        return null;
    }

    $tplanMgr = new testplan($dbHandler);
    if ($tplanMgr->getNumberOfBuilds($tplanID) == 0) {
        return null;
    }

    $metricsMgr = new tlTestPlanMetrics($dbHandler);
    $rx = $metricsMgr->getStatusTotalsByTopLevelTestSuiteForRender($tplanID, null,
            array('groupByPlatform' => 1));
    if (is_null($rx) || !property_exists($rx, 'info') || is_null($rx->info)) {
        return null;
    }

    $resultsCfg = config_get('results');
    $dbo = new stdClass();
    $dbo->total = 0;
    $dbo->slices = array();

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

        $dbo->slices[$statusVerbose] = array('qty' => $qty, 'percentage' => 0,
                                             'color' => $color,
                                             'label' => $lbl($lblKey));
        $dbo->total += $qty;
    }

    if ($dbo->total == 0) {
        return null;
    }

    $executed = $dbo->total - $dbo->slices['not_run']['qty'];
    $dbo->executed = $executed;
    $dbo->percentage_completed = number_format(100 * ($executed / $dbo->total), 1);

    foreach ($dbo->slices as $statusVerbose => $slice) {
        $dbo->slices[$statusVerbose]['percentage'] =
            number_format(100 * ($slice['qty'] / $dbo->total), 1);
    }

    $dbo->tplan_name = (string) getParam('tplan_name', '');
    if ($dbo->tplan_name === '' && isset($_SESSION['testplanName'])) {
        $dbo->tplan_name = (string) $_SESSION['testplanName'];
    }

    // Rebuild as an assoc array for JSON (labels already translated).
    $slicesOut = array();
    foreach ($dbo->slices as $statusVerbose => $slice) {
        $slicesOut[] = array(
            'key' => $statusVerbose,
            'qty' => intval($slice['qty']),
            'percentage' => $slice['percentage'],
            'color' => $slice['color'],
            'label' => $slice['label'],
        );
    }

    return array(
        'total' => intval($dbo->total),
        'executed' => intval($dbo->executed),
        'percentage_completed' => $dbo->percentage_completed,
        'tplan_name' => $dbo->tplan_name,
        'slices' => $slicesOut,
    );
}

/**
 * Monthly test case growth for the bar chart widget.
 *
 * Mirrors lib/general/mainPage.php::getTestCaseGrowthData(). Returns null
 * when there is no project or the project has no test cases at all.
 */
function getTestCaseGrowthData(&$dbHandler, $tprojectID)
{
    if ($tprojectID <= 0) {
        return null;
    }

    $tprojectMgr = new testproject($dbHandler);
    $monthly = $tprojectMgr->getTestCaseCreationMonthly($tprojectID, 12);

    if (array_sum($monthly) == 0) {
        return null;
    }

    $labels = array();
    $values = array();
    $total = 0;
    $peak = 0;
    foreach ($monthly as $yearMonth => $qty) {
        $labels[] = date('M y', strtotime($yearMonth . '-01'));
        $values[] = $qty;
        $total += $qty;
        $peak = max($peak, $qty);
    }

    return array('labels' => $labels, 'values' => $values,
                 'total' => $total, 'peak' => $peak);
}

/**
 * Bugs linked to executions of the current test plan, one row per bug.
 *
 * Mirrors lib/general/mainPage.php::getBugsTestedData().
 */
function getBugsTestedData(&$dbHandler, $tprojectID, $tplanID, $lbl)
{
    if ($tprojectID <= 0 || $tplanID <= 0) {
        return null;
    }

    $tplanMgr = new testplan($dbHandler);
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
    $tprojectMgr = new testproject($dbHandler);
    $tprojectInfo = $tprojectMgr->get_by_id($tprojectID);
    if (!empty($tprojectInfo['issue_tracker_enabled'])) {
        $itMgr = new tlIssueTracker($dbHandler);
        $its = $itMgr->getInterfaceObject($tprojectID);
    }

    $statusColor = array('closed' => '#5cb85c', 'open' => '#e6605e');

    $dbo = array();
    foreach ($bugs as $bugID => $tcaseSet) {
        $item = array('id' => $bugID,
                      'tcases' => implode(', ', array_keys($tcaseSet)),
                      'url' => '',
                      'title' => '',
                      'labels' => array(),
                      'label_colors' => array(),
                      'status' => '',
                      'color' => '#8f8f8f',
                      'unavailable' => false);

        if (is_object($its)) {
            $issue = $its->getIssue($bugID);
            if (is_object($issue)) {
                $item['url'] = $issue->url;
                $item['title'] = (!empty($issue->title))
                    ? (string) $issue->title
                    : rtrim(strtok((string) $issue->summary, "\n"), ':');
                $item['status'] = (string) ($issue->state ?? $issue->statusVerbose ?? '');
                if (isset($issue->labels) && is_array($issue->labels)) {
                    $item['labels'] = array_values($issue->labels);
                }
                if (isset($issue->label_colors) && is_array($issue->label_colors)) {
                    $item['label_colors'] = (object) $issue->label_colors;
                }

                $key = strtolower($item['status']);
                if (isset($statusColor[$key])) {
                    $item['color'] = $statusColor[$key];
                }
            } else {
                $item['unavailable'] = true;
            }
        }

        $dbo[] = $item;
    }

    return $dbo;
}

/**
 * ALL open issues on the project's linked issue tracker, independent of the
 * selected test plan. Mirrors lib/general/mainPage.php::getProjectIssuesData().
 */
function getProjectIssuesData(&$dbHandler, $tprojectID)
{
    if ($tprojectID <= 0) {
        return null;
    }

    $tprojectMgr = new testproject($dbHandler);
    $tprojectInfo = $tprojectMgr->get_by_id($tprojectID);
    if (empty($tprojectInfo['issue_tracker_enabled'])) {
        return null;
    }

    $itMgr = new tlIssueTracker($dbHandler);
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
        $item = array('id' => (string) $issue->id,
                      'number' => (string) $issue->number,
                      'url' => $issue->url,
                      'title' => $issue->title,
                      'status' => $issue->state,
                      'color' => '#8f8f8f',
                      'labels' => array(),
                      'label_colors' => array());

        if (isset($issue->labels) && is_array($issue->labels)) {
            $item['labels'] = $issue->labels;
        }
        if (isset($issue->label_colors) && is_array($issue->label_colors)) {
            $item['label_colors'] = (object) $issue->label_colors;
        }

        if (isset($statusColor[$issue->state])) {
            $item['color'] = $statusColor[$issue->state];
        }

        $dbo[] = $item;
    }

    return $dbo;
}
