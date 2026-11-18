<?php
/**
 * Test Cases Created per User on a Test Project - BFF (Refs #2000).
 *
 * Read-only project-scoped report replacing the legacy ExtJS renderer
 * `lib/results/tcCreatedPerUserOnTestProject.php` with the Dashio screen
 * `gui/templates/results/tcCreatedPerUser.html`.
 *
 * SEMANTICS
 * ---------
 *   - the unit is a TEST CASE VERSION created inside the selected project
 *     (the legacy `testproject::getTestCasesCreatedByUser()` query), so a test
 *     case with several versions appears once per version;
 *   - the author is `tcversions.author_id`, joined to the users table;
 *   - `user_id = 0` means EVERY user (legacy `TL_USER_ANYBODY` / "Any");
 *   - the time window filters `tcversions.creation_ts` inclusively, with a
 *     configurable start/end hour; dates are ISO `YYYY-MM-DD`, never the
 *     legacy localized `MM/DD/YYYY` input (no ambiguity across locales);
 *   - the suite column is the full hierarchy path of the test case;
 *   - importance is the raw 1/2/3 code plus its localized label.
 *
 * SECURITY
 * --------
 * Session authentication, the shared BFF same-origin guard, and the legacy
 * `testplan_metrics` right checked on the ADDRESSED project BEFORE it is
 * resolved (#1697 no-oracle convention). Denials are logged at AUDIT.
 *
 * Contract: GET|HEAD
 *   ?action=init&tproject_id=P
 *   ?action=report&tproject_id=P[&user_id=U][&start_date=Y-m-d]
 *                       [&end_date=Y-m-d][&start_hour=H][&end_hour=H]
 *   400 invalid_request | 401 not_authenticated/session_expired
 *   403 no_right | 404 tproject_not_found | 405 method_not_allowed
 */
require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function tcpcuOut($payload, $code = null)
{
    if ($code !== null) {
        http_response_code($code);
    }
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function tcpcuFail($httpCode, $code, $message = '')
{
    tcpcuOut(array(
        'status' => 'error',
        'code' => $code,
        'message' => $message !== '' ? $message : $code,
    ), $httpCode);
}

function tcpcuInt($raw)
{
    if ($raw === null || is_array($raw)) {
        return 0;
    }
    $raw = trim((string)$raw);
    if ($raw === '' || !preg_match('/^[0-9]+$/', $raw)) {
        return 0;
    }
    return intval($raw);
}

function tcpcuDecode($value)
{
    return html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function tcpcuDate($raw, $default)
{
    $raw = trim((string)$raw);
    if ($raw === '') {
        return $default;
    }
    $parts = array();
    if (!preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/', $raw, $parts)) {
        return null;
    }
    if (!checkdate(intval($parts[2]), intval($parts[3]), intval($parts[1]))) {
        return null;
    }
    return $raw;
}

function tcpcuHour($raw, $default)
{
    if ($raw === null || trim((string)$raw) === '') {
        return $default;
    }
    if (!preg_match('/^(0?[0-9]|1[0-9]|2[0-3])$/', trim((string)$raw))) {
        return null;
    }
    return intval(trim((string)$raw));
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    tcpcuFail(405, 'method_not_allowed');
}

$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if ($action !== 'init' && $action !== 'report') {
    tcpcuFail(400, 'invalid_request', 'Unknown action');
}

$rawProject = trim((string)($_GET['tproject_id'] ?? ''));
if (!preg_match('/^[1-9][0-9]*$/', $rawProject)) {
    tcpcuFail(400, 'invalid_request', 'Missing tproject_id');
}
$tprojectId = intval($rawProject);

$userId = tcpcuInt($_GET['user_id'] ?? '');
$startHour = tcpcuHour($_GET['start_hour'] ?? '', 0);
$endHour = tcpcuHour($_GET['end_hour'] ?? '', 23);
if ($startHour === null || $endHour === null) {
    tcpcuFail(400, 'invalid_request', 'Invalid hour');
}

$db = new database(DB_TYPE);
doDBConnect($db);

$sessionUserId = $_SESSION['userID'] ?? null;
if (!$sessionUserId || intval($sessionUserId) <= 0) {
    tcpcuFail(401, 'not_authenticated', 'Not authenticated');
}

bffEnforceSession($db);

$user = tlUser::getByID($db, intval($sessionUserId));
if (is_null($user)) {
    tcpcuFail(401, 'not_authenticated', 'User not found');
}

// Rights on the addressed project BEFORE it is resolved (no existence oracle).
if (!$user->hasRight($db, 'testplan_metrics', $tprojectId, null, true)) {
    tLog('BFF tccreatedperuser: user ' . intval($sessionUserId) .
         ' has no testplan_metrics right on tproject ' . $tprojectId, 'AUDIT');
    tcpcuFail(403, 'no_right', 'testplan_metrics right required');
}

$tbl = tlObjectWithDB::getDBTables(array(
    'testprojects', 'nodes_hierarchy', 'tcversions', 'users',
));

$projectRow = $db->get_recordset(
    "SELECT nh.name FROM {$tbl['testprojects']} tp" .
    " JOIN {$tbl['nodes_hierarchy']} nh ON nh.id = tp.id" .
    " WHERE tp.id = " . $tprojectId);
if (!is_array($projectRow) || count($projectRow) === 0) {
    tcpcuFail(404, 'tproject_not_found', 'Test project not found');
}
$projectName = tcpcuDecode($projectRow[0]['name']);

$cfg = config_get('testcase_cfg');
$glue = (string)$cfg->glue_character;
$rptCfg = config_get('reportsCfg');
$offset = intval($rptCfg->start_date_offset ?? (30 * 86400));
$defaultStart = date('Y-m-d', time() - $offset);
$defaultEnd = date('Y-m-d');

$startDate = tcpcuDate($_GET['start_date'] ?? '', $defaultStart);
$endDate = tcpcuDate($_GET['end_date'] ?? '', $defaultEnd);
if ($startDate === null || $endDate === null) {
    tcpcuFail(400, 'invalid_request', 'Invalid date');
}

$users = array();
$uRs = $db->get_recordset(
    "SELECT id, login, first, last FROM {$tbl['users']}" .
    " WHERE active = 1 ORDER BY login");
if (is_array($uRs)) {
    foreach ($uRs as $u) {
        $login = tcpcuDecode($u['login']);
        $label = trim(tcpcuDecode($u['first'] ?? '') . ' ' . tcpcuDecode($u['last'] ?? ''));
        $users[] = array(
            'user_id' => intval($u['id']),
            'login' => $login,
            'display_name' => $label !== '' ? ($login . ' (' . $label . ')') : $login,
        );
    }
}

$importanceCfg = config_get('importance');
$importanceLabels = array();
foreach ((array)($importanceCfg['code_label'] ?? array()) as $code => $labelKey) {
    $importanceLabels[intval($code)] = lang_get($labelKey);
}

$context = array(
    'tproject_id' => $tprojectId,
    'tproject_name' => $projectName,
    'external_id_glue' => $glue,
    'default_start_date' => $defaultStart,
    'default_end_date' => $defaultEnd,
    'start_date' => $startDate,
    'end_date' => $endDate,
    'start_hour' => $startHour,
    'end_hour' => $endHour,
    'user_id' => $userId,
);

if ($action === 'init') {
    tcpcuOut(array(
        'status' => 'ok',
        'context' => $context,
        'users' => $users,
        'importance_labels' => $importanceLabels,
        'limits' => array('max_rows' => 5000),
    ));
}

// ---------------------------------------------------------------------------
// Report: one row per test case VERSION, as in the legacy controller.
// ---------------------------------------------------------------------------
$projectMgr = new testproject($db);
$targets = array();
$projectMgr->get_all_testcases_id($tprojectId, $targets);

$rows = array();
$byUser = array();
$byImportance = array();
$distinctCases = array();
$firstTs = null;
$lastTs = null;

if (count($targets) > 0) {
    $ids = array();
    foreach ($targets as $t) {
        $id = intval($t);
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    if (count($ids) > 0) {
        $externalId = $db->db->concat(
            'TPROJ.prefix', "'" . $glue . "'", 'TCV.tc_external_id');
        $sql = "SELECT TCV.id AS tcversion_id, TCV.version, {$externalId} AS external_id," .
               " NHTC.id AS tcase_id, NHTC.name AS tcase_name," .
               " TCV.creation_ts, TCV.modification_ts, TCV.importance," .
               " U.id AS author_id, U.login, U.first, U.last" .
               " FROM {$tbl['testprojects']} TPROJ" .
               " JOIN {$tbl['nodes_hierarchy']} NHTC ON NHTC.id IN (" . implode(',', $ids) . ")" .
               " JOIN {$tbl['nodes_hierarchy']} NHTCV ON NHTCV.parent_id = NHTC.id" .
               " JOIN {$tbl['tcversions']} TCV ON TCV.id = NHTCV.id" .
               " JOIN {$tbl['users']} U ON U.id = TCV.author_id" .
               " WHERE TPROJ.id = " . $tprojectId .
               " AND TCV.creation_ts >= '" . $db->prepare_string(
                   $startDate . ' ' . sprintf('%02d', $startHour) . ':00:00') . "'" .
               " AND TCV.creation_ts <= '" . $db->prepare_string(
                   $endDate . ' ' . sprintf('%02d', $endHour) . ':59:59') . "'";
        if ($userId > 0) {
            $sql .= " AND U.id = " . $userId;
        }
        $sql .= " ORDER BY TCV.creation_ts DESC, NHTC.id, TCV.version DESC";

        $rs = $db->get_recordset($sql);
        $paths = array();
        if (is_array($rs) && count($rs) > 0) {
            $caseIds = array();
            foreach ($rs as $r) {
                $caseIds[] = intval($r['tcase_id']);
            }
            $pathIds = array_values(array_unique($caseIds));
            $paths = $projectMgr->tree_manager->get_full_path_verbose(
                $pathIds, array('output_format' => 'path_as_string'));
        }
        if (is_array($rs)) {
            foreach ($rs as $r) {
                $tcaseId = intval($r['tcase_id']);
                $importance = intval($r['importance']);
                $login = tcpcuDecode($r['login']);
                $authorId = intval($r['author_id']);
                $created = (string)$r['creation_ts'];
                if ($firstTs === null || $created < $firstTs) {
                    $firstTs = $created;
                }
                if ($lastTs === null || $created > $lastTs) {
                    $lastTs = $created;
                }
                $distinctCases[$tcaseId] = true;
                if (!isset($byUser[$authorId])) {
                    $byUser[$authorId] = array(
                        'user_id' => $authorId, 'login' => $login, 'versions' => 0);
                }
                $byUser[$authorId]['versions']++;
                if (!isset($byImportance[$importance])) {
                    $byImportance[$importance] = 0;
                }
                $byImportance[$importance]++;
                $rows[] = array(
                    'tcase_id' => $tcaseId,
                    'tcversion_id' => intval($r['tcversion_id']),
                    'version' => intval($r['version']),
                    'external_id' => tcpcuDecode($r['external_id']),
                    'name' => tcpcuDecode($r['tcase_name']),
                    'suite_path' => tcpcuDecode($paths[$tcaseId] ?? ''),
                    'author_id' => $authorId,
                    'user' => $login,
                    'importance' => $importance,
                    'importance_label' => $importanceLabels[$importance] ?? (string)$importance,
                    'creation_ts' => $created,
                    'modification_ts' => (string)$r['modification_ts'],
                );
            }
        }
    }
}

$byUser = array_values($byUser);
usort($byUser, function ($a, $b) {
    if ($a['versions'] === $b['versions']) {
        return strcmp($a['login'], $b['login']);
    }
    return $b['versions'] - $a['versions'];
});
ksort($byImportance);

$total = count($rows);
$summary = array(
    'versions' => $total,
    'test_cases' => count($distinctCases),
    'authors' => count($byUser),
    'first_creation' => $firstTs,
    'last_creation' => $lastTs,
    'by_user' => $byUser,
    'by_importance' => $byImportance,
    'verdict' => ($total === 0) ? 'no_data' : 'data',
);

if ($total > 5000) {
    $rows = array_slice($rows, 0, 5000);
    $summary['truncated'] = true;
}

tcpcuOut(array(
    'status' => 'ok',
    'context' => $context,
    'summary' => $summary,
    'rows' => $rows,
    'users' => $users,
    'importance_labels' => $importanceLabels,
));
