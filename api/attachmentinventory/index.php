<?php
/**
 * Test Plan Attachment Inventory - REST BFF API
 * URL: /api/attachmentinventory/index.php
 * Plain PHP, no framework, no compilation
 *
 * NET-NEW 2.0.1 SCREEN (Refs #1976)
 * ----------------------------------
 * TestLink 1.9.20 can only show the attachments of ONE object at a time
 * (test case / test case version / step / execution / test plan) through the
 * per-object attachment dialog. Nothing aggregates every attachment that the
 * artifacts of a test plan carry, so an auditor has to open every test case,
 * every step and every execution to answer "what is attached to this plan,
 * who uploaded it, how big is it and when?".
 *
 * This endpoint answers that question in one read-only payload:
 *   - the TEST PLAN itself             (attachments.fk_table = 'testplans')
 *   - the TEST CASES linked to it      ('testcases')
 *   - the TEST CASE VERSIONS linked    ('tcversions')
 *   - the STEPS of those versions      ('tcsteps')
 *   - the EXECUTIONS recorded in it    ('executions')
 * Only fk_table values the modern attachment API accepts are considered
 * (api/attachments/index.php $KNOWN_TABLES); unknown/legacy sinks are ignored.
 *
 * RIGHTS / ORDER OF OPERATIONS (same as the accepted sibling reports
 * api/keywordbarchart #1906 and api/execduration #1975):
 *   1. resolve the plan -> opaque 404 when it does not exist / has no owner
 *   2. check testplan_metrics on the OWNING test project AND plan with
 *      getAccess = true (a globally held right no longer reaches a private
 *      project -> 403)
 *   3. only then honour the client's tproject_id assertion (404 mismatch)
 * "testplan_metrics" is the read-only metrics right the other plan reports use.
 * The download URL reuses /api/attachments/index.php?action=download, which
 * re-checks the rights on the attachment OWNER, so this endpoint never has to
 * stream file bytes itself.
 *
 * Contract: GET|HEAD ?action=init&tplan_id=N[&tproject_id=P]
 *   200 {status, code,
 *        context:{tplan_id,tplan_name,tproject_id,tproject_name},
 *        items:[{id,object_type,object_id,object_name,title,description,
 *                file_name,file_size,file_size_human,file_type,
 *                date_added,download_url}],
 *        types:[{code,count}],
 *        totals:{total,bytes,by_type:{...}}}
 *   400 invalid_request | 401 not_authenticated / session_expired
 *   403 no_right | 404 tplan_not_found / project_mismatch
 *   405 method_not_allowed
 *
 * Refs #1976
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
require_once('testplan.class.php');
require_once('testproject.class.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

/** Single JSON exit point; $code is applied ONLY when explicitly given. */
function aiOut($payload, $code = null)
{
    if ($code !== null) {
        http_response_code($code);
    }
    echo json_encode($payload);
    exit;
}

function aiFail($httpCode, $code, $message = '')
{
    aiOut(array(
        'status' => 'error',
        'code' => $code,
        'message' => $message !== '' ? $message : $code,
    ), $httpCode);
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    aiFail(405, 'method_not_allowed');
}

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    aiFail(401, 'not_authenticated', 'Not authenticated');
}

bffEnforceSession($db);

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    aiFail(401, 'not_authenticated', 'User not found');
}

$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if ($action !== 'init') {
    aiFail(400, 'invalid_request', 'Unknown action');
}

/** Strict positive integer reader - "1abc", "1.9", "-3" and "" are refused. */
function aiPositiveInt($raw)
{
    if ($raw === null || is_array($raw)) {
        return 0;
    }
    $raw = trim((string)$raw);
    if ($raw === '' || !preg_match('/^[0-9]+$/', $raw)) {
        return 0;
    }
    $n = intval($raw);
    return ($n > 0) ? $n : 0;
}

/** Human readable byte size (binary units, one decimal below 1 KiB multiples). */
function aiHumanSize($bytes)
{
    $bytes = max(0, intval($bytes));
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    $units = array('KiB', 'MiB', 'GiB', 'TiB');
    $value = $bytes / 1024;
    $i = 0;
    while ($value >= 1024 && $i < count($units) - 1) {
        $value /= 1024;
        $i++;
    }
    return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.') . ' ' . $units[$i];
}

/** Comma-separated int list safe for an IN (...) clause (never empty). */
function aiIntList($values)
{
    $out = array();
    foreach ((array)$values as $v) {
        $n = intval($v);
        if ($n > 0) {
            $out[$n] = $n;
        }
    }
    return implode(',', array_values($out));
}

$tplanId = aiPositiveInt($_GET['tplan_id'] ?? null);
$assertedProjectId = aiPositiveInt($_GET['tproject_id'] ?? null);

if ($tplanId <= 0) {
    aiFail(400, 'invalid_request', 'Missing or invalid tplan_id');
}

// 1. Resolve the plan -> opaque 404 when it does not exist or has no owner.
$tbl = tlObjectWithDB::getDBTables(array(
    'testplans', 'attachments', 'testplan_tcversions', 'tcversions',
    'tcsteps', 'nodes_hierarchy', 'executions', 'builds', 'users',
));
$planRow = $db->get_recordset(
    "SELECT id, testproject_id FROM {$tbl['testplans']} WHERE id = " . intval($tplanId));

if (!is_array($planRow) || count($planRow) === 0) {
    aiFail(404, 'tplan_not_found', 'Test plan not found');
}

$plan = $planRow[0];
$owningProjectId = intval($plan['testproject_id']);
if ($owningProjectId <= 0) {
    aiFail(404, 'tplan_not_found', 'Test plan has no owning test project');
}

// 2. Rights on the OWNING project AND plan (getAccess = true).
if (!$user->hasRight($db, 'testplan_metrics', $owningProjectId, $tplanId, true)) {
    tLog('BFF attachmentinventory: user ' . intval($userId) .
         ' has no testplan_metrics right on tproject ' . $owningProjectId .
         ' (tplan ' . $tplanId . ')', 'ERROR');
    aiFail(403, 'no_right', 'testplan_metrics right required');
}

// 3. Client assertion is checked only after the ownership proof.
if ($assertedProjectId > 0 && $assertedProjectId !== $owningProjectId) {
    aiFail(404, 'project_mismatch', 'Test plan belongs to another test project');
}

$planName = testplan::getName($db, $tplanId);
$projectName = testproject::getName($db, $owningProjectId);
if (is_null($planName) || trim((string)$planName) === '') {
    aiFail(404, 'tplan_not_found', 'Test plan not found');
}

// ---------------------------------------------------------------------------
// Object universe of the plan.
// ---------------------------------------------------------------------------
// Test case versions linked to the plan, with their test case + version number.
// A tcversion is itself a nodes_hierarchy node whose parent is the test case
// (tcversions carries no testcase_id column).
$linked = $db->get_recordset(
    "SELECT tptv.tcversion_id, nhv.parent_id AS testcase_id, tcv.version,
            nht.name AS tc_name
       FROM {$tbl['testplan_tcversions']} tptv
       JOIN {$tbl['tcversions']} tcv ON tcv.id = tptv.tcversion_id
       JOIN {$tbl['nodes_hierarchy']} nhv ON nhv.id = tcv.id
       JOIN {$tbl['nodes_hierarchy']} nht ON nht.id = nhv.parent_id
      WHERE tptv.testplan_id = " . intval($tplanId));
if (!is_array($linked)) {
    $linked = array();
}

$tcNames = array();     // testcase node id -> name
$verMeta = array();     // tcversion id -> {testcase_id, version, tc_name}
$tcIds = array();
$verIds = array();
foreach ($linked as $row) {
    $tcid = intval($row['testcase_id']);
    $vid = intval($row['tcversion_id']);
    if ($tcid <= 0 || $vid <= 0) {
        continue;
    }
    $name = html_entity_decode((string)$row['tc_name'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $tcNames[$tcid] = $name;
    $verMeta[$vid] = array(
        'testcase_id' => $tcid,
        'version' => intval($row['version']),
        'tc_name' => $name,
    );
    $tcIds[$tcid] = $tcid;
    $verIds[$vid] = $vid;
}

// Steps that belong to those versions (id -> parent version).
$stepMeta = array();
if (count($verIds) > 0) {
    // tcsteps carry no version column: a step is a nodes_hierarchy child of
    // its tcversion (NH_STEPS.parent_id = tcversion id), see
    // testcase.class.php::getSteps().
    $stepRows = $db->get_recordset(
        "SELECT s.id, nh.parent_id AS tcversion_id, s.step_number
           FROM {$tbl['tcsteps']} s
           JOIN {$tbl['nodes_hierarchy']} nh ON nh.id = s.id
          WHERE nh.parent_id IN (" . aiIntList($verIds) . ")");
    foreach ((array)$stepRows as $row) {
        $stepMeta[intval($row['id'])] = array(
            'tcversion_id' => intval($row['tcversion_id']),
            'step_number' => intval($row['step_number']),
        );
    }
}

// Executions of the plan (id -> display label pieces).
$statusCfg = config_get('results');
// Execution status codes (p/f/b/n) are NOT the keys of status_label (which
// uses the verbose names); mapping one onto the other avoids the raw
// "LOCALIZE: test_status_p" placeholder.
$statusVerbose = array('p' => 'passed', 'f' => 'failed', 'b' => 'blocked', 'n' => 'not_run');
$execMeta = array();
$execRows = $db->get_recordset(
    "SELECT e.id, e.status, b.name AS build_name, u.login AS tester_login
       FROM {$tbl['executions']} e
       LEFT JOIN {$tbl['builds']} b ON b.id = e.build_id
       LEFT JOIN {$tbl['users']} u ON u.id = e.tester_id
      WHERE e.testplan_id = " . intval($tplanId));
foreach ((array)$execRows as $row) {
    $status = strval($row['status']);
    $execMeta[intval($row['id'])] = array(
        'build_name' => (string)($row['build_name'] ?? ''),
        'tester_login' => (string)($row['tester_login'] ?? ''),
        'status' => $status,
        'status_label' => ($status !== '' && isset($statusVerbose[$status])
            && isset($statusCfg['status_label'][$statusVerbose[$status]]))
            ? lang_get($statusCfg['status_label'][$statusVerbose[$status]])
            : '',
    );
}

// ---------------------------------------------------------------------------
// Attachments of the plan's universe.
// ---------------------------------------------------------------------------
$knownTables = array('testplans', 'testcases', 'tcversions', 'tcsteps', 'executions');
$where = array("(a.fk_table = 'testplans' AND a.fk_id = " . intval($tplanId) . ")");
if (count($tcIds) > 0) {
    $where[] = "(a.fk_table = 'testcases' AND a.fk_id IN (" . aiIntList($tcIds) . "))";
}
if (count($verIds) > 0) {
    $where[] = "(a.fk_table = 'tcversions' AND a.fk_id IN (" . aiIntList($verIds) . "))";
}
if (count($stepMeta) > 0) {
    $where[] = "(a.fk_table = 'tcsteps' AND a.fk_id IN (" . aiIntList(array_keys($stepMeta)) . "))";
}
$where[] = "(a.fk_table = 'executions' AND a.fk_id IN (SELECT id FROM {$tbl['executions']} WHERE testplan_id = " . intval($tplanId) . "))";

$attRows = $db->get_recordset(
    "SELECT a.id, a.fk_id, a.fk_table, a.title, a.description,
            a.file_name, a.file_size, a.file_type, a.date_added
       FROM {$tbl['attachments']} a
      WHERE " . implode(' OR ', $where) . "
      ORDER BY a.date_added DESC, a.id DESC");

$items = array();
$totals = array(
    'total' => 0,
    'bytes' => 0,
    'by_type' => array(
        'testplans' => 0,
        'testcases' => 0,
        'tcversions' => 0,
        'tcsteps' => 0,
        'executions' => 0,
    ),
);

foreach ((array)$attRows as $row) {
    // Legacy stores the fk_table prefix-stripped, but be defensive: accept the
    // configured DB prefix form and drop anything the attachment API rejects.
    $type = (string)$row['fk_table'];
    if (defined('DB_TABLE_PREFIX') && DB_TABLE_PREFIX !== '') {
        $type = str_replace(DB_TABLE_PREFIX, '', $type);
    }
    if (!in_array($type, $knownTables, true)) {
        continue;
    }

    $fkId = intval($row['fk_id']);
    $objectName = '';
    $objectId = $fkId;

    if ($type === 'testplans') {
        $objectName = (string)$planName;
    } else if ($type === 'testcases') {
        $objectName = $tcNames[$fkId] ?? ('#' . $fkId);
    } else if ($type === 'tcversions') {
        $meta = $verMeta[$fkId] ?? null;
        $objectName = $meta
            ? $meta['tc_name'] . ' v' . $meta['version']
            : ('#' . $fkId);
    } else if ($type === 'tcsteps') {
        $meta = $stepMeta[$fkId] ?? null;
        $ver = $meta ? ($verMeta[$meta['tcversion_id']] ?? null) : null;
        $objectName = ($meta && $ver)
            ? $ver['tc_name'] . ' v' . $ver['version'] . ' / ' . $meta['step_number']
            : ('#' . $fkId);
    } else if ($type === 'executions') {
        $meta = $execMeta[$fkId] ?? null;
        if ($meta) {
            $parts = array();
            if ($meta['build_name'] !== '') {
                $parts[] = $meta['build_name'];
            }
            if ($meta['status_label'] !== '') {
                $parts[] = $meta['status_label'];
            }
            if ($meta['tester_login'] !== '') {
                $parts[] = $meta['tester_login'];
            }
            $objectName = $parts ? implode(' / ', $parts) : ('#' . $fkId);
        } else {
            $objectName = '#' . $fkId;
        }
    }

    $size = intval($row['file_size']);
    $added = (string)$row['date_added'];
    $addedIso = $added;
    $ts = strtotime($added);
    if ($ts !== false) {
        $addedIso = date('c', $ts);
    }

    $items[] = array(
        'id' => intval($row['id']),
        'object_type' => $type,
        'object_id' => $objectId,
        'object_name' => $objectName,
        'title' => html_entity_decode((string)($row['title'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        'description' => html_entity_decode((string)($row['description'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        'file_name' => (string)$row['file_name'],
        'file_size' => $size,
        'file_size_human' => aiHumanSize($size),
        'file_type' => (string)$row['file_type'],
        'date_added' => $addedIso,
        'date_added_raw' => $added,
        'download_url' => '/api/attachments/index.php?action=download&id=' . intval($row['id']),
    );

    $totals['total']++;
    $totals['bytes'] += $size;
    $totals['by_type'][$type] = intval($totals['by_type'][$type]) + 1;
}

$types = array();
foreach ($knownTables as $code) {
    $types[] = array('code' => $code, 'count' => intval($totals['by_type'][$code]));
}

aiOut(array(
    'status' => 'ok',
    'code' => 200,
    'context' => array(
        'tplan_id' => $tplanId,
        'tplan_name' => (string)$planName,
        'tproject_id' => $owningProjectId,
        'tproject_name' => (string)$projectName,
    ),
    'items' => $items,
    'types' => $types,
    'totals' => $totals,
));
