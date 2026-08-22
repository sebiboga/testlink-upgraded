<?php
/**
 * Test Cases Created Per User (per Test Project) - REST BFF API
 * URL: /api/results/
 * Plain PHP, no framework, no compilation
 *
 * Mirrors lib/results/tcCreatedPerUserOnTestProject.php (TestLink 1.9.20):
 * - GET /meta   -> rights, project info, user drop-down, default date window
 * - GET /report -> test cases created per user within a date/hour window
 * - GET /csv    -> same result set as CSV download (same columns as legacy)
 *
 * Legacy notes kept 1:1:
 * - user_id = 0 means "any user" (TL_USER_ANYBODY), no author filter
 * - time window is [startDate startHour:00:00 , endDate endHour:59:59]
 * - invalid/missing dates simply remove the corresponding filter (sanitizeDates())
 * - rights gate: testplan_metrics (legacy checkRights())
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

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
$path = preg_replace('#^/api/results(/index\.php)?#', '', $path);
$path = '/' . trim($path, '/');
$method = $_SERVER['REQUEST_METHOD'];

function out($data) { echo json_encode($data); exit; }

/**
 * Same rights gate as the legacy screen checkRights():
 * only 'testplan_metrics' grants access to this report.
 */
function checkReportRights(&$db, &$user) {
    $context = new stdClass();
    $context->tproject_id = intval($_SESSION['testprojectID'] ?? 0);
    $context->tplan_id = null;
    $context->getAccessAttr = false;
    return (bool)$user->hasRightOnProj($db, 'testplan_metrics',
        $context->tproject_id, $context->tplan_id, $context->getAccessAttr);
}

/**
 * Current test project from session.
 */
function getTprojectId() {
    return intval($_SESSION['testprojectID'] ?? 0);
}

/**
 * Validate YYYY-MM-DD or return '' (no filter), like legacy sanitizeDates().
 */
function safeDate($value) {
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    if (preg_match('#^(\d{4})-(\d{2})-(\d{2})$#', $value, $m) !== 1) {
        return '';
    }
    if (!checkdate(intval($m[2]), intval($m[3]), intval($m[1]))) {
        return '';
    }
    return $value;
}

/**
 * Clamp hour to 0..23.
 */
function safeHour($value, $default) {
    $h = intval($value);
    if ($h < 0 || $h > 23) {
        $h = intval($default);
    }
    return $h;
}

/**
 * Format a DB timestamp string to 'YYYY-MM-DD HH:MM'.
 */
function fmtTs($ts) {
    if (is_null($ts) || trim((string)$ts) === '' || strpos((string)$ts, '0000-00-00') === 0) {
        return null;
    }
    $t = strtotime($ts);
    return ($t === false) ? null : date('Y-m-d H:i', $t);
}

/**
 * Build the query options array exactly like the legacy controller does.
 */
function buildQueryOptions() {
    $options = array();
    $startDate = safeDate($_GET['start_date'] ?? '');
    $endDate = safeDate($_GET['end_date'] ?? '');
    $startHour = safeHour($_GET['start_hour'] ?? 0, 0);
    $endHour = safeHour($_GET['end_hour'] ?? 23, 23);

    if ($startDate !== '') {
        $options['startTime'] = sprintf('%s %02d:00:00', $startDate, $startHour);
    }
    if ($endDate !== '') {
        $options['endTime'] = sprintf('%s %02d:59:59', $endDate, $endHour);
    }
    return $options;
}

/**
 * Run the report and flatten the legacy cumulative result set into rows.
 * Each test case version is one row (same as legacy ext table).
 */
function runReport(&$db, $tproject_id, $filter_user_id) {
    $mgr = new testproject($db);
    $rs = $mgr->getTestCasesCreatedByUser($tproject_id, intval($filter_user_id), buildQueryOptions());

    $rows = array();
    if (!is_null($rs)) {
        foreach ($rs as $itemInfo) {
            foreach ($itemInfo as $tcase) {
                $rows[] = array(
                    'login' => $tcase['login'],
                    'firstName' => $tcase['first_name'] ?? '',
                    'lastName' => $tcase['last_name'] ?? '',
                    'path' => $tcase['path'],
                    'tcaseId' => intval($tcase['tcase_id']),
                    'tcversionId' => intval($tcase['tcversion_id']),
                    'externalId' => $tcase['external_id'],
                    'name' => $tcase['tcase_name'],
                    'version' => intval($tcase['version']),
                    'importance' => intval($tcase['importance']),
                    'creationTs' => fmtTs($tcase['creation_ts']),
                    'modificationTs' => fmtTs($tcase['modification_ts']),
                );
            }
        }
    }

    // stable sort: user, then path, then external id (ext table grouped by user)
    usort($rows, function ($a, $b) {
        $k = strcasecmp($a['login'], $b['login']);
        if ($k === 0) { $k = strcasecmp($a['path'], $b['path']); }
        if ($k === 0) { $k = strcasecmp($a['externalId'], $b['externalId']); }
        if ($k === 0) { $k = $a['version'] - $b['version']; }
        return $k;
    });

    return $rows;
}

$tproject_id = getTprojectId();
$canView = checkReportRights($db, $user);

if ($method === 'GET' && $path === '/meta') {
    if ($tproject_id <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'No test project selected']);
    }

    $nodeInfo = null;
    if ($canView) {
        $treeMgr = new tree($db);
        $nodeInfo = $treeMgr->get_node_hierarchy_info($tproject_id);

        // user drop-down content: all users, exactly like legacy
        // getUsersForHtmlOptions($dbHandler, ALL_USERS_FILTER, array(TL_USER_ANYBODY => ...))
        $usersMap = getUsersForHtmlOptions($db, ALL_USERS_FILTER, null);
        $users = array();
        if (!is_null($usersMap)) {
            foreach ($usersMap as $uid => $displayName) {
                if (intval($uid) <= 0) {
                    continue; // skip legacy "any"/"nobody" pseudo entries, front-end adds its own localized ones
                }
                $users[] = array('id' => intval($uid), 'name' => $displayName);
            }
            usort($users, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
        }

        // defaults mirror initializeGuiForInput(): reportsCfg offsets/hours
        $cfg = config_get('reportsCfg');
        $now = time();
        $offset = property_exists($cfg, 'start_date_offset') ? intval($cfg->start_date_offset) : 0;
        $defaults = array(
            'startDate' => date('Y-m-d', $now - $offset),
            'endDate' => date('Y-m-d', $now),
            'startHour' => property_exists($cfg, 'start_time') ? intval($cfg->start_time) : 0,
            'endHour' => intval(date('G', $now)),
        );
    } else {
        $users = array();
        $defaults = null;
    }

    out([
        'status' => 'ok',
        'canView' => $canView,
        'tprojectId' => $tproject_id,
        'tprojectName' => $nodeInfo ? $nodeInfo['name'] : '',
        'users' => $users,
        'defaults' => $defaults,
    ]);
}

if ($method === 'GET' && $path === '/report') {
    if (!$canView) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'Insufficient rights']);
    }
    if ($tproject_id <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'No test project selected']);
    }

    $filterUserId = intval($_GET['user_id'] ?? 0); // 0 = any user (TL_USER_ANYBODY)
    $rows = runReport($db, $tproject_id, $filterUserId);

    // summary stats for the results header
    $usersSet = array();
    $casesSet = array();
    foreach ($rows as $r) {
        $usersSet[$r['login']] = true;
        $casesSet[$r['tcaseId']] = true;
    }

    out([
        'status' => 'ok',
        'tprojectId' => $tproject_id,
        'rows' => $rows,
        'totalRows' => count($rows),
        'distinctUsers' => count($usersSet),
        'distinctCases' => count($casesSet),
        'generatedOn' => date('Y-m-d H:i:s'),
    ]);
}

if ($method === 'GET' && $path === '/csv') {
    if (!$canView) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'Insufficient rights']);
    }
    if ($tproject_id <= 0) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        out(['status' => 'error', 'message' => 'No test project selected']);
    }

    $fromDate = safeDate($_GET['start_date'] ?? '');
    $toDate = safeDate($_GET['end_date'] ?? '');

    // importance labels server side, same keys as legacy initGuiForCSVDownload()
    $impCfg = config_get('importance');
    $impL10N = isset($impCfg['code_label']) ? $impCfg['code_label'] : array();
    foreach ($impL10N as $ci => $lc) {
        $impL10N[$ci] = lang_get($lc);
    }

    // column headers, same layout as legacy getCSVColumnsDefinition()
    $lbl = init_labels(array(
        'user' => null, 'testsuite' => null, 'testcase' => null,
        'importance' => null, 'title_created' => null, 'title_last_mod' => null,
        'th_start_time' => null, 'th_end_time' => null,
    ));
    $colHeaders = array(
        $lbl['user'], $lbl['testsuite'], $lbl['testcase'], $lbl['importance'],
        $lbl['title_created'], $lbl['title_last_mod'],
        $lbl['th_start_time'], $lbl['th_end_time'],
    );

    $rows = runReport($db, $tproject_id, intval($_GET['user_id'] ?? 0));

    $filename = 'tc_created_per_user_' . $tproject_id . '_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');

    $fp = fopen('php://temp', 'r+');
    fputs($fp, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel opens it cleanly
    fputcsv($fp, $colHeaders);
    foreach ($rows as $r) {
        $impLabel = isset($impL10N[$r['importance']])
            ? '(' . $r['importance'] . ') ' . $impL10N[$r['importance']]
            : $r['importance'];
        fputcsv($fp, array(
            $r['login'],
            $r['path'],
            $r['externalId'] . ' : ' . $r['name'] . ' [v' . $r['version'] . ']',
            $impLabel,
            $r['creationTs'],
            $r['modificationTs'],
            $fromDate,
            $toDate,
        ));
    }
    rewind($fp);
    fpassthru($fp);
    fclose($fp);
    exit;
}

http_response_code(404);
out(['status' => 'error', 'message' => 'Unknown endpoint']);
