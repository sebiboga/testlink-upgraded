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
 * Schema-drift guard for the project-scoped build queries (issue #862).
 *
 * database::exec_query() DIES with an HTML "DB Access Error" trace on any
 * SQL failure (lib/functions/database.class.php), which for a JSON BFF means
 * the client receives HTML while expecting JSON and the whole dashboard
 * collapses into "Failed to load dashboard." The execution-status widget's
 * first query filters on builds.testproject_id, a recent additive column
 * (Ref #826, scope builds to test project). On a not-yet-migrated 1.9.20
 * database that query fails and kills the request; this probe lets the BFF
 * degrade that widget to its empty state instead. The INFORMATION_SCHEMA
 * probe is effectively un-failable for this query (it only reads a system
 * view and references trusted config constants, never the migrated column).
 */
function bffBuildsSchemaOk($dbHandler)
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $sql = " SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS " .
           " WHERE TABLE_SCHEMA = '" . DB_NAME . "' " .
           "   AND TABLE_NAME = '" . DB_TABLE_PREFIX . "builds' " .
           "   AND COLUMN_NAME = 'testproject_id' LIMIT 1";
    $rs = $dbHandler->get_recordset($sql);

    $cache = (is_array($rs) && count($rs) > 0);
    return $cache;
}

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

    // Graceful degradation on schema drift (issue #862): when the deployed
    // DB predates the project-scoped-builds migration (builds.testproject_id
    // missing), getNumberOfBuilds() would otherwise die inside exec_query()
    // with an HTML trace and the whole payload never arrives. Skip the widget
    // (it renders hidden) and keep a valid JSON response.
    $dashboard = bffBuildsSchemaOk($db)
        ? getDashboardData($db, $tprojectID, $tplanID, $lbl)
        : null;
    $tcGrowth = getTestCaseGrowthData($db, $tprojectID);
    // FAST path: bugs with a locally-derived GitHub URL only. The live
    // title/labels/status enrichment calls the ITS (one HTTP getIssue() per
    // bug) and is delivered async through GET /bugsTested below, so the
    // dashboard renders immediately (same pattern as Execute's tcLinkedBugs).
    $bugsInfo = getBugsTestedData($db, $tprojectID, $tplanID, $lbl, false);
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

// Route: GET /bugsTested - async live enrichment of the "Test case bugs"
// widget. The base payload above ships id/tcases/url for a fast first paint;
// this call finishes the rows with tracker title/labels/status. Mirrors the
// async tcLinkedBugs endpoint of the Execute screen.
if ($method === 'GET' && $segments === ['bugsTested']) {
    $bugsInfo = getBugsTestedData($db, $tprojectID, $tplanID, $lbl, true);

    out([
        'status' => 'ok',
        'bugsInfo' => $bugsInfo,
    ]);
}

// Route: GET /assigned - "Test cases assigned to me" widget (Refs #895).
// Ports the legacy tcAssignedToUser.php personal view onto the Dashboard:
// every test case whose execution is assigned to the logged-in user, scoped
// to the selected test project (all its active test plans, or a single plan
// when one is already selected), with the last execution status per row.
if ($method === 'GET' && $segments === ['assigned']) {
    $assigned = getAssignedToMeData($db, $tprojectID, $tplanID, $userId, $user, $lbl);

    out([
        'status' => 'ok',
        'assigned' => $assigned,
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
function getBugsTestedData(&$dbHandler, $tprojectID, $tplanID, $lbl, $enrich = false)
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

    // Live enrichment needs the ITS adapter (one HTTP round-trip per bug via
    // getIssue()). The fast path skips it entirely and only builds a
    // locally-derivable GitHub URL - the async GET /bugsTested call then
    // fills title/labels/status in place (same pattern as Execute screen).
    $its = null;
    if ($enrich) {
        $tprojectMgr = new testproject($dbHandler);
        $tprojectInfo = $tprojectMgr->get_by_id($tprojectID);
        if (!empty($tprojectInfo['issue_tracker_enabled'])) {
            $itMgr = new tlIssueTracker($dbHandler);
            $its = $itMgr->getInterfaceObject($tprojectID);
        }
    }

    $statusColor = array('closed' => '#5cb85c', 'open' => '#e6605e');

    $dbo = array();
    foreach ($bugs as $bugID => $tcaseSet) {
        $item = array('id' => $bugID,
                      'tcases' => implode(', ', array_keys($tcaseSet)),
                      'url' => mainpageIssueViewUrl($dbHandler, $tprojectID, $bugID),
                      'title' => '',
                      'labels' => array(),
                      'label_colors' => array(),
                      'status' => '',
                      'color' => '#8f8f8f',
                      'unavailable' => false);

        if ($enrich && is_object($its)) {
            try {
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
            } catch (Exception $e) {
                // tracker transient failure - keep id/url placeholders
                $item['unavailable'] = true;
            }
        }

        $dbo[] = $item;
    }

    return $dbo;
}

// Local (DB-only) issue view URL for the FAST dashboard path - never
// instantiates the live ITS (GitHub's adapter fires connect()->getRepo() plus
// getIssue() == buildViewBugURL() network calls). Only the GitHub layout is
// derivable without the tracker; anything else falls back to ''.
function mainpageIssueViewUrl($db, $tprojectID, $bugID)
{
    static $cache = [];
    // owner/repo/base are invariant per test project; resolve once per request.
    if (array_key_exists(intval($tprojectID), $cache)) {
        return mainpageIssueUrlFromCfg($cache[intval($tprojectID)], strval($bugID));
    }
    try {
        $itMgr = new tlIssueTracker($db);
        $linkedT = $itMgr->getLinkedTo(intval($tprojectID));
        if (is_null($linkedT)) { return ''; }
        $itd = $itMgr->getByID(intval($linkedT['issuetracker_id']));
        if (is_null($itd)) { return ''; }
        $cfgDoc = simplexml_load_string($itd['cfg']);
        if ($cfgDoc === false) { return ''; }
        $owner = trim((string) $cfgDoc->owner);
        $repo = trim((string) $cfgDoc->repo);
        $base = trim((string) $cfgDoc->url);
        $cache[intval($tprojectID)] = ['owner' => $owner, 'repo' => $repo, 'base' => $base];
        return mainpageIssueUrlFromCfg($cache[intval($tprojectID)], strval($bugID));
    } catch (Exception $e) {
        return '';
    }
}

// GitHub web-UI issue URL from a parsed ITS-GitHub config; '' when the
// tracker is not the GitHub layout (falls back to a non-clickable badge until
// the async /bugsTested enrichment fills real links).
function mainpageIssueUrlFromCfg($cfg, $bugID)
{
    if ($cfg['owner'] === '' || $cfg['repo'] === '' || $bugID === '') { return ''; }
    $bugID = rawurlencode(strval($bugID));
    if (strpos($cfg['base'], 'api.github.com') !== false) {
        return 'https://github.com/' . rawurlencode($cfg['owner'])
            . '/' . rawurlencode($cfg['repo']) . '/issues/' . $bugID;
    }
    return '';
}

/**
 * "Test cases assigned to me" widget payload (Refs #895).
 *
 * Ports the legacy personal view of lib/testcases/tcAssignedToUser.php onto the
 * Dashboard: not the whole-overview variant (that one is the Reports screen
 * Refs #684), but exactly what a tester needs to know what to work on today -
 * the test cases assigned to the logged-in user, scoped to the selected test
 * project, grouped per test plan.
 *
 * Scope decision (legacy init_args parity):
 *  - tplan_id > 0  -> that single test plan only.
 *  - tplan_id == 0 -> testcase::ALL_TESTPLANS of the selected project.
 * Filters default to the "work today" view: active plans + open builds. The
 * full_path mode resolves the test case's suite path so the widget reads like
 * the legacy "Assigned to me" table.
 */
function getAssignedToMeData(&$dbHandler, $tprojectID, $tplanID, $userId, $user, $lbl)
{
    $empty = array(
        'user_id' => intval($userId),
        'tproject_id' => intval($tprojectID),
        'has_data' => false,
        'total' => 0,
        'executed' => 0,
        'pending' => 0,
        'plans' => array(),
    );
    if ($tprojectID <= 0 || intval($userId) <= 0 || is_null($user)) {
        return $empty;
    }

    require_once(__DIR__ . '/../../lib/functions/testcase.class.php');
    require_once(__DIR__ . '/../../lib/functions/testplan.class.php');
    require_once(__DIR__ . '/../../lib/functions/testproject.class.php');

    $tcaseMgr = new testcase($dbHandler);
    $tplanMgr = new testplan($dbHandler);

    // Standard execution status whitelist for rendering.
    $resultsCfg = config_get('results');

    $tplan_param = $tplanID > 0 ? array(intval($tplanID)) : testcase::ALL_TESTPLANS;
    $filters = array('tplan_status' => 'active', 'build_status' => 'open');
    $resultSet = $tcaseMgr->get_assigned_to_user(
        intval($userId), $tprojectID, $tplan_param,
        array('mode' => 'full_path'), $filters);

    if (is_null($resultSet)) {
        return $empty;
    }

    $payload = $empty;

    $tplanNames = array();
    $nhTable = tlObjectWithDB::getDBTables(array('nodes_hierarchy'));
    $sql = 'SELECT id,name FROM ' . $nhTable['nodes_hierarchy'] .
           ' WHERE id IN (' . implode(',', array_map('intval', array_keys($resultSet))) . ')';
    $rows = $dbHandler->get_recordset($sql);
    foreach ($rows as $r) {
        $tplanNames[intval($r['id'])] = $r['name'];
    }

    $tprojectMgr = new testproject($dbHandler);
    $tprojectInfo = $tprojectMgr->get_by_id($tprojectID);

    foreach ($resultSet as $tplan_id => $tcase_set) {
        $tplan_id = intval($tplan_id);
        $hasExecRight = ($user->hasRight(
            $dbHandler, 'testplan_execute', $tprojectID, $tplan_id, true) == 'yes');

        $platforms = $tplanMgr->getPlatforms($tplan_id, array('outputFormat' => 'map'));
        // The legacy check was !is_null($platforms), but the map accessor
        // always returns an array (possibly empty); capacity matters so the
        // widget can hide the platform column when the project defines none.
        $showPlatforms = is_array($platforms) && count($platforms) > 0;

        $projOpts = $tprojectInfo['opt'] ?? null;
        if (is_null($projOpts) && !empty($tprojectInfo['options'])) {
            $projOpts = json_decode($tprojectInfo['options']);
        }
        $priorityEnabled = is_object($projOpts)
            ? !empty($projOpts->testPriorityEnabled)
            : (is_array($projOpts) ? !empty($projOpts['testPriorityEnabled']) : false);

        $rowsOut = array();
        foreach ($tcase_set as $tcase_platform) {
            foreach ($tcase_platform as $tcase) {
                $tcase_id = intval($tcase['testcase_id']);
                $tcversion_id = intval($tcase['tcversion_id']);
                $build_id = intval($tcase['build_id']);

                // Last execution on THIS build/platform (legacy parity).
                $lexec = $tcaseMgr->get_last_execution(
                    $tcase_id, $tcversion_id, $tplan_id,
                    $build_id, intval($tcase['platform_id']),
                    array('getSteps' => 0));
                $status = isset($lexec[$tcversion_id]['status'])
                    ? $lexec[$tcversion_id]['status'] : '';
                if (!in_array($status, array('p', 'f', 'b', 'n'), true)) {
                    $status = $resultsCfg['status_code']['not_run'];
                }

                $creationTs = $tcase['creation_ts'];
                $tsEpoch = is_string($creationTs) ? strtotime($creationTs) : $creationTs;
                $tsEpoch = $tsEpoch ? intval($tsEpoch) : 0;

                $deadlineEpoch = 0;
                if (!empty($tcase['deadline_ts'])) {
                    $dts = strtotime($tcase['deadline_ts']);
                    $deadlineEpoch = $dts ? intval($dts) : 0;
                }

                $row = array(
                    'build_id' => $build_id,
                    'build_name' => $tcase['build_name'],
                    'suite_path' => $tcase['tcase_full_path'] ?? '',
                    'tc_id' => $tcase_id,
                    'tcversion_id' => $tcversion_id,
                    'prefix' => $tcase['prefix'],
                    'tc_external_id' => intval($tcase['tc_external_id']),
                    'name' => $tcase['name'],
                    'version' => intval($tcase['version']),
                    'tplan_id' => $tplan_id,
                    'tplan_name' => isset($tplanNames[$tplan_id])
                        ? $tplanNames[$tplan_id] : '',
                    'status' => $status,
                    'status_key' => in_array($status, array('p','f','b','n'), true)
                        ? array('p'=>'passed','f'=>'failed','b'=>'blocked','n'=>'not_run')[$status]
                        : 'not_run',
                    'creation_ts_epoch' => $tsEpoch,
                    'assigned_on_epoch' => $tsEpoch,
                    'age_days' => $tsEpoch
                        ? intval(floor((time() - $tsEpoch) / 86400)) : 0,
                    'deadline_epoch' => $deadlineEpoch,
                    'deadline_overdue' => ($deadlineEpoch > 0 && time() > $deadlineEpoch),
                    'can_exec' => $hasExecRight,
                );
                // Always ship platform_id/platform_name: the quick-exec and
                // "execute results" links key on platform_id even when the
                // project has no platforms defined (legacy parity,
                // tcAssignedToUser.php builds the exec link unconditionally).
                $row['platform_id'] = intval($tcase['platform_id']);
                $row['platform_name'] = $tcase['platform_name'];
                if ($priorityEnabled) {
                    $prio = intval($tcase['priority']);
                    $level = ($prio >= HIGH) ? 'high'
                        : (($prio >= MEDIUM) ? 'medium' : 'low');
                    $row['priority'] = $prio;
                    $row['priority_level'] = $level;
                }
                $rowsOut[] = $row;
            }
        }

        $payload['plans'][] = array(
            'id' => $tplan_id,
            'name' => isset($tplanNames[$tplan_id]) ? $tplanNames[$tplan_id] : '',
            'show_platforms' => $showPlatforms,
            'priority_enabled' => $priorityEnabled,
            'has_exec_right' => $hasExecRight,
            'rows' => $rowsOut,
        );
    }

    usort($payload['plans'], function ($a, $b) {
        return strcasecmp($a['name'], $b['name']);
    });

    foreach ($payload['plans'] as $plan) {
        $payload['total'] += count($plan['rows']);
        foreach ($plan['rows'] as $r) {
            if ($r['status'] != 'n') {
                $payload['executed'] += 1;
            } else {
                $payload['pending'] += 1;
            }
        }
        if (count($plan['rows']) > 0) {
            $payload['has_data'] = true;
        }
    }

    return $payload;
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
