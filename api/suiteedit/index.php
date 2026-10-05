<?php
/**
 * Test Suite Create / Edit / Delete - BFF API
 * URL: /api/suiteedit/index.php
 * Plain PHP, no framework, no compilation.
 *
 * Refs #1852. Modern replacement for the container-definition slice of the
 * legacy generic container controller lib/testcases/containerEdit.php:
 *
 *   doAction=new_testsuite    -> gui/templates/dashio/testcases/containerNew.tpl
 *   doAction=add_testsuite    -> same template, POST
 *   doAction=edit_testsuite   -> gui/templates/dashio/testcases/containerEdit.tpl
 *   doAction=update_testsuite -> same template, POST
 *   doAction=delete_testsuite -> gui/templates/dashio/testcases/containerDelete.tpl
 *
 * Two real gaps are closed here, both found by porting the legacy branches:
 *
 * 1. THE TEST-SUITE DESIGN CUSTOM FIELDS WERE A SILENT DROP ON BOTH HALVES.
 *    Legacy containerEdit.tpl rendered {$cf} (testsuite::html_table_of_custom_
 *    field_inputs -> cfield_mgr::html_table_inputs) and writeCustomFieldsToDB()
 *    (containerEdit.php:688) persisted them with
 *    cfield_mgr::design_values_to_db() on BOTH updateTestSuite() (:893) and
 *    addTestSuite() (:783). The modern api/suiteview 'save_suite' reads only
 *    name + details from the POST body, so the CF definitions were neither
 *    offered nor written - editing a suite name silently discarded every
 *    custom-field value stored for it. Same class as the build design CF drop
 *    closed in #1787 (api/builds /cfields).
 *
 * 2. THE MODERN 'delete_suite' DELETES MORE THAN 1.9.20 ALLOWED.
 *    Legacy deleteTestSuite() (containerEdit.php:709) walked the subtree with
 *    get_testcases_deep() + build_del_testsuite_warning_msg() (:465): when any
 *    test case in the subtree was LINKED AND EXECUTED it required the
 *    'delete_executed_testcases' right (can_delete = grants->delete_executed_
 *    testcases), rendered the per-test-case path + exec-status table and
 *    rendered NO delete button at all when the right was missing (system_
 *    blocks_tsuite_delete_due_to_exec_tc). api/suiteview 'delete_suite' calls
 *    delete_deep() + deleteKeywords() unconditionally on a plain mgt_modify_tc,
 *    so a Test Designer could wipe the whole subtree of a suite whose cases are
 *    the evidence of an executed run. Here the same gate is enforced, and the
 *    delete route re-checks it at write time instead of trusting the GET.
 *
 * Everything else is 1:1 legacy parity:
 *   - name validation: empty / whitespace -> warning_empty_testsuite_name
 *   - forbidden characters ($g_ereg_forbidden) -> string_contains_bad_chars
 *   - create: testsuite::create(parent, name, details, NULL,
 *              config_get('check_names_for_duplicates'), 'block')
 *     - BUGID 3890: on duplicate the action is hardcoded to 'block'; the
 *       action_on_duplicate_name config is only for copy/move.
 *     - new suites are created as the LAST element of the branch ($new_order
 *       is deliberately NULL - the legacy ordering code was commented out).
 *   - update: testsuite::update(id, name, details, parent_id)
 *   - keywords: deleteKeywords() then addKeywords(explode(',')) exactly like
 *     updateTestSuite(); addKeywords() only on create, like addTestSuite()
 *   - plugin events EVENT_TEST_SUITE_CREATE / EVENT_TEST_SUITE_UPDATE
 *
 * Contract (JSON out):
 *   GET  ?action=init&mode=create|edit|delete[&tproject_id=][&container_id=][&suite_id=]
 *   POST ?action=create  {tproject_id, parent_id, name, details, keywords[], cfields{}}
 *   POST ?action=update  {tproject_id, suite_id,  name, details, keywords[], cfields{}}
 *   POST ?action=delete  {tproject_id, suite_id}
 *
 *   401 not_authenticated | session_expired
 *   403 no_right | no_right_delete_exec | csrf
 *   400 invalid_parameter | invalid_mode | empty_name | bad_chars | unknown_action
 *   404 tproject_not_found | parent_not_found | suite_not_found | project_mismatch
 *   405 wrong_method (Allow: GET, HEAD, POST)
 *   409 suite_has_executed (delete refused, no right)
 *   422 duplicate_name (legacy 'block' on duplicate)
 *   500 server_error
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

/* ------------------------------------------------------------------ *
 * Session gate BEFORE the DB connect (the #1677 lesson: common.php   *
 * echoes a raw dbms_msg, so a down database must never answer an     *
 * anonymous caller with the host/database name).                     *
 * ------------------------------------------------------------------ */
doSessionStart();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$db = new database(DB_TYPE);
doDBConnect($db);

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();
// Legacy parity: testlinkInitPage() ran checkSessionValid() on EVERY page.
bffEnforceSession($db);

$userId = isset($_SESSION['userID']) ? intval($_SESSION['userID']) : 0;
if ($userId <= 0) {
    http_response_code(401);
    out(array('status' => 'error', 'code' => 'not_authenticated',
              'message' => 'Not authenticated'));
}

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    http_response_code(401);
    out(array('status' => 'error', 'code' => 'not_authenticated',
              'message' => 'Not authenticated'));
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD' && $method !== 'POST') {
    header('Allow: GET, HEAD, POST');
    http_response_code(405);
    out(array('status' => 'error', 'code' => 'wrong_method',
              'message' => 'Method not allowed'));
}

$action = isset($_GET['action']) ? trim((string)$_GET['action']) : '';

/* ================================================================== *
 * helpers                                                            *
 * ================================================================== */

function out($data, $code = null)
{
    if (!is_null($code)) {
        http_response_code($code);
    }
    echo json_encode($data);
    exit;
}

function bffBody()
{
    static $body = null;
    if ($body === null) {
        $j = json_decode(file_get_contents('php://input'), true);
        $body = is_array($j) ? $j : array();
    }
    return $body;
}

function getParam($key, $default = null)
{
    $b = bffBody();
    if (isset($b[$key])) {
        return $b[$key];
    }
    if (isset($_POST[$key])) {
        return $_POST[$key];
    }
    if (isset($_GET[$key])) {
        return $_GET[$key];
    }
    return $default;
}

/** Strict positive integer or 0. A non-numeric value never becomes 0 silently. */
function getInt($key, $default = 0)
{
    $v = getParam($key, $default);
    if (is_array($v) || is_object($v) || is_bool($v)) {
        return $default;
    }
    $v = trim((string)$v);
    if ($v === '') {
        return $default;
    }
    if (!preg_match('/^-?\d+$/', $v)) {
        return $default;
    }
    return intval($v);
}

function getStr($key, $default = '')
{
    $v = getParam($key, $default);
    if (is_array($v) || is_object($v)) {
        return $default;
    }
    return trim((string)$v);
}

/** Integer or null, with a distinguishing "present but malformed" signal. */
function getIdOrFail($key)
{
    $raw = getParam($key, null);
    if ($raw === null || (is_string($raw) && trim($raw) === '')) {
        out(array('status' => 'error', 'code' => 'invalid_parameter',
                  'message' => 'Missing parameter: ' . $key), 400);
    }
    if (is_array($raw) || is_object($raw) || is_bool($raw)) {
        out(array('status' => 'error', 'code' => 'invalid_parameter',
                  'message' => 'Invalid parameter: ' . $key), 400);
    }
    $raw = trim((string)$raw);
    if (!preg_match('/^\d+$/', $raw) || intval($raw) <= 0) {
        out(array('status' => 'error', 'code' => 'invalid_parameter',
                  'message' => 'Invalid parameter: ' . $key), 400);
    }
    return intval($raw);
}

/**
 * Node type ids, resolved from the node_types table by DESCRIPTION instead of
 * hardcoded, so a renamed/localized description can never silently re-route
 * the screen (same defensive approach as api/suitemove, api/tcreorder).
 */
function suiteEditNodeTypes($db)
{
    static $n = null;
    if ($n === null) {
        $T = tlObjectWithDB::getDBTables(array('node_types'));
        $rows = $db->get_recordset("SELECT id, description FROM {$T['node_types']}");
        $n = array();
        if (!is_null($rows)) {
            foreach ($rows as $r) {
                $n[strtolower((string)$r['description'])] = intval($r['id']);
            }
        }
        // 1.9.20 numeric fallbacks, only when the table cannot be read at all.
        $n += array('testproject' => 1, 'testsuite' => 2, 'testcase' => 3);
    }
    return $n;
}

function suiteEditTables()
{
    static $t = null;
    if ($t === null) {
        $t = tlObjectWithDB::getDBTables(
            array('nodes_hierarchy', 'testprojects'));
    }
    return $t;
}

/** Raw node row (any type), or null. */
function suiteEditNode($db, $nodeId)
{
    $T = suiteEditTables();
    $nodeId = intval($nodeId);
    if ($nodeId <= 0) {
        return null;
    }
    static $cache = array();
    if (array_key_exists($nodeId, $cache)) {
        return $cache[$nodeId];
    }
    $row = $db->get_recordset(
        "SELECT id, name, parent_id, node_type_id, node_order" .
        " FROM {$T['nodes_hierarchy']} WHERE id = {$nodeId}");
    $info = null;
    if (!is_null($row) && count($row) > 0) {
        $info = $row[0];
    }
    $cache[$nodeId] = $info;
    return $info;
}

/** Walk up nodes_hierarchy to the test project root (type 1). 0 when orphaned. */
function suiteEditOwningProject($db, $node)
{
    $types = suiteEditNodeTypes($db);
    $nodeType = isset($node['node_type_id']) ? intval($node['node_type_id']) : 0;
    if ($nodeType == $types['testproject']) {
        return intval($node['id']);
    }
    $parentId = intval($node['parent_id']);
    $guard = 0;
    while ($parentId > 0 && $guard < 64) {
        $guard++;
        $row = suiteEditNode($db, $parentId);
        if (is_null($row)) {
            return 0;
        }
        if (intval($row['node_type_id']) == $types['testproject']) {
            return intval($row['id']);
        }
        $next = intval($row['parent_id']);
        if ($next == $parentId) {
            return 0;
        }
        $parentId = $next;
    }
    return 0;
}

/**
 * The test project NAME lives in nodes_hierarchy on the project node, not in
 * testprojects (2.0.1 keeps only the prefix there).
 */
function suiteEditProjectName(&$db, $tprojectId)
{
    $node = suiteEditNode($db, $tprojectId);
    return is_null($node) ? '' : (string)$node['name'];
}

function suiteEditProjectPrefix(&$db, $tprojectId)
{
    $T = suiteEditTables();
    $row = $db->get_recordset(
        "SELECT prefix FROM {$T['testprojects']} WHERE id = " . intval($tprojectId));
    return (!is_null($row) && count($row) > 0) ? (string)$row[0]['prefix'] : '';
}

/** Root-first ancestor chain of a node, itself included. */
function suiteEditChain($db, $nodeId)
{
    $chain = array();
    $types = suiteEditNodeTypes($db);
    $current = intval($nodeId);
    $guard = 0;
    while ($current > 0 && $guard < 64) {
        $guard++;
        $info = suiteEditNode($db, $current);
        if (is_null($info)) {
            break;
        }
        array_unshift($chain, $info);
        if (intval($info['node_type_id']) == $types['testproject']) {
            break;
        }
        $next = intval($info['parent_id']);
        if ($next == $current) {
            break;
        }
        $current = $next;
    }
    return $chain;
}

/**
 * Prove that $nodeId is a TEST SUITE (node_type_id = 2) of $tprojectId.
 *
 * A node of another project, of another type (a test case, a test plan, a
 * requirement) or one that does not exist all leave through the SAME opaque
 * 404, so the endpoint is not a node-id existence / type oracle (the #1759 /
 * #1779 lesson of api/suitemove).
 */
function requireSuiteOfProject(&$db, $nodeId, $tprojectId)
{
    $types = suiteEditNodeTypes($db);
    $node = suiteEditNode($db, $nodeId);
    if (is_null($node) || intval($node['node_type_id']) != $types['testsuite']) {
        out(array('status' => 'error', 'code' => 'suite_not_found',
                  'message' => 'Test suite not found'), 404);
    }
    $owner = suiteEditOwningProject($db, $node);
    if ($owner <= 0 || intval($tprojectId) != $owner) {
        out(array('status' => 'error', 'code' => 'suite_not_found',
                  'message' => 'Test suite not found'), 404);
    }
    return $node;
}

/**
 * Prove that $nodeId is a valid CREATE PARENT of $tprojectId: either the test
 * project root itself or one of its test suites.
 */
function requireParentOfProject(&$db, $nodeId, $tprojectId)
{
    $types = suiteEditNodeTypes($db);
    $node = suiteEditNode($db, $nodeId);
    if (is_null($node)) {
        out(array('status' => 'error', 'code' => 'parent_not_found',
                  'message' => 'Parent container not found'), 404);
    }
    $nodeType = intval($node['node_type_id']);
    if ($nodeType != $types['testproject'] && $nodeType != $types['testsuite']) {
        out(array('status' => 'error', 'code' => 'parent_not_found',
                  'message' => 'Parent container not found'), 404);
    }
    $owner = suiteEditOwningProject($db, $node);
    if ($owner <= 0 || intval($tprojectId) != $owner) {
        out(array('status' => 'error', 'code' => 'parent_not_found',
                  'message' => 'Parent container not found'), 404);
    }
    return $node;
}

/** The addressed test project, proven to exist. */
function requireTproject(&$db, $tprojectId)
{
    $types = suiteEditNodeTypes($db);
    $node = suiteEditNode($db, $tprojectId);
    if (is_null($node) || intval($node['node_type_id']) != $types['testproject']) {
        out(array('status' => 'error', 'code' => 'tproject_not_found',
                  'message' => 'Test project not found'), 404);
    }
    return $node;
}

/* ================================================================== *
 * custom fields - parity with containerEdit.php writeCustomFieldsToDB  *
 * ================================================================== */

/** The session locale's date format with the '%' markers stripped - exactly
 *  what cfield_mgr::_build_cfield() builds for itself. */
function suiteEditCfDateFormat()
{
    $cfg = config_get('locales_date_format');
    $locale = isset($_SESSION['locale']) ? $_SESSION['locale'] : 'en_GB';
    if (!isset($cfg[$locale])) {
        $locale = 'en_GB';
    }
    return str_replace('%', '', $cfg[$locale]);
}

/**
 * Timestamp -> ISO 'Y-m-d' (or 'Y-m-d H:i:s'). date() and NOT gmdate(): the
 * stamp was produced by cfield_mgr's mktime(), i.e. local midnight in the
 * SERVER timezone, so the local format is the only one that reads it back as
 * the same calendar day.
 */
function suiteEditEpochToIso($epoch, $withTime = false)
{
    if (intval($epoch) <= 0) {
        return '';
    }
    $iso = date($withTime ? 'Y-m-d H:i:s' : 'Y-m-d', intval($epoch));
    return ($iso === '1970-01-01' || $iso === '1970-01-01 00:00:00') ? '' : $iso;
}

/** ISO -> the locale format cfield_mgr::split_localized_date() parses. */
function suiteEditIsoToLocale($iso, $withTime = false)
{
    if ($iso === '' || is_null($iso)) {
        return '';
    }
    $iso = trim((string)$iso);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?$/', $iso, $m)) {
        return $iso; // not ISO -> hand it to split_localized_date() as-is
    }
    $fmt = suiteEditCfDateFormat();
    if ($withTime && !isset($m[4])) {
        $m[4] = '00'; $m[5] = '00'; $m[6] = '00';
    }
    return strtr($fmt, array(
        'd' => $m[3], 'm' => $m[2], 'Y' => $m[1], 'y' => substr($m[1], 2, 2),
        'H' => $m[4], 'i' => $m[5], 's' => $m[6],
    ));
}

/** Possible values of a list CF: '|'-separated in the legacy storage. */
function suiteEditPossibleValues($raw)
{
    $raw = (string)$raw;
    if ($raw === '') {
        return array();
    }
    $parts = explode('|', $raw);
    $out = array();
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p !== '') {
            $out[] = $p;
        }
    }
    return $out;
}

/**
 * Test-suite DESIGN custom fields of a test project as DATA, never as HTML, so
 * no free-text CF label or possible value can inject markup (same rule as
 * api/builds /cfields, #1787).
 *
 * Legacy source: containerEdit.tpl {$cf} <- testsuite::html_table_of_custom_
 * field_inputs($id, $parent_id, 'design', '', $userInput) ->
 * cfield_mgr::get_linked_cfields_at_design($tproject_id, 1, null, 'testsuite')
 */
function suiteEditCfDefs($tsuiteMgr, $tprojectId, $suiteId = 0)
{
    $cfMap = $tsuiteMgr->cfield_mgr->get_linked_cfields_at_design(
        intval($tprojectId), 1, null, 'testsuite');
    if (is_null($cfMap)) {
        return array();
    }
    $types = array(0 => 'string', 1 => 'numeric', 2 => 'float', 4 => 'email',
                   5 => 'checkbox', 6 => 'list', 7 => 'multiselection list',
                   8 => 'date', 9 => 'radio', 10 => 'datetime',
                   20 => 'text area', 500 => 'script', 501 => 'server');
    $out = array();
    foreach ((array)$cfMap as $fieldId => $cf) {
        $fieldId = intval($fieldId);
        $typeId = intval($cf['type'] ?? 0);
        $hasValue = array_key_exists('value', $cf) && $cf['value'] !== null;
        $value = $hasValue ? (string)$cf['value'] : null;
        $default = isset($cf['default_value']) ? (string)$cf['default_value'] : '';
        if (($typeId === 8 || $typeId === 10) && $hasValue &&
            $value !== '' && ctype_digit($value)) {
            $value = suiteEditEpochToIso(intval($value), $typeId === 10);
        }
        $entry = array(
            'field_id'   => $fieldId,
            'name'       => (string)($cf['name'] ?? ''),
            'label'      => (string)($cf['label'] ?? ($cf['name'] ?? '')),
            'type'       => $typeId,
            'type_label' => isset($types[$typeId]) ? $types[$typeId] : 'string',
            'required'   => !empty($cf['required']) ? 1 : 0,
            'possible_values' => suiteEditPossibleValues($cf['possible_values'] ?? ''),
            'default_value'   => $default,
            'value'      => ($value === null || $value === '') ? $default : $value,
            'has_value'  => $hasValue ? 1 : 0,
        );
        if ($typeId === 5 || $typeId === 7) {
            // checkbox / multiselection: '|'-joined storage
            $entry['values'] = ($entry['value'] === '')
                ? array()
                : array_values(array_filter(array_map('trim', explode('|', $entry['value'])),
                    function ($v) { return $v !== ''; }));
        }
        $out[] = $entry;
    }
    return $out;
}

/**
 * Persist submitted test-suite design custom fields.
 *
 * The key's PRESENCE decides, not its content: a caller that sends no
 * 'cfields' at all is not editing custom fields. design_values_to_db() writes
 * EVERY field of $cfMap, so treating an absent block as "clear all" would wipe
 * the values of a suite renamed from a table that does not show them.
 *
 * @return int number of FIELDS actually written
 */
function suiteEditSaveCf(&$tsuiteMgr, $body, $cfMap, $suiteId)
{
    if (is_null($cfMap) || count($cfMap) == 0) {
        return 0;
    }
    if (!array_key_exists('cfields', $body) || !is_array($body['cfields'])) {
        return 0;
    }
    $in = $body['cfields'];
    $hash = array();
    $written = 0;
    foreach ($cfMap as $fieldId => $cf) {
        $fieldId = intval($fieldId);
        $typeId = intval($cf['type'] ?? 0);
        $val = array_key_exists($fieldId, $in) ? $in[$fieldId] : '';
        $prefix = 'custom_field_' . $typeId . '_' . $fieldId;
        if ($typeId === 5 || $typeId === 7) {
            $vals = is_array($val) ? array_map('strval', $val)
                                   : (($val === '') ? array() : array((string)$val));
            $vals = array_values(array_filter($vals, function ($v) { return $v !== ''; }));
            if (empty($vals)) {
                // An unticked checkbox submits nothing; passing [] instead makes
                // _build_cfield() read $value[0] on an empty array (E_WARNING)
                // and store NULL, tripping tlStringLen(null) downstream.
                continue;
            }
            $hash[$prefix] = $vals;
            $written++;
        } elseif ($typeId === 8 || $typeId === 10) {
            $isDateTime = ($typeId === 10);
            $val = is_array($val) ? (string)($val['input'] ?? '') : (string)$val;
            $hash[$prefix . '_input'] = suiteEditIsoToLocale($val, $isDateTime);
            $written++;
            if ($isDateTime) {
                $hour = '0'; $minute = '0'; $second = '0';
                if (preg_match('/^\d{4}-\d{2}-\d{2}[ T](\d{2}):(\d{2})(?::(\d{2}))?$/',
                               trim((string)$val), $tm)) {
                    $hour = $tm[1]; $minute = $tm[2];
                    $second = isset($tm[3]) ? $tm[3] : '0';
                }
                $hash[$prefix . '_hour'] = $hour;
                $hash[$prefix . '_minute'] = $minute;
                $hash[$prefix . '_second'] = $second;
            }
        } else {
            $hash[$prefix] = is_array($val) ? '' : (string)$val;
            $written++;
        }
    }
    if (count($hash) == 0) {
        return 0;
    }
    $tsuiteMgr->cfield_mgr->design_values_to_db($hash, intval($suiteId), $cfMap, null, 'testsuite');
    return $written;
}

/* ================================================================== *
 * name validation - containerEdit.php:117-128                          *
 * ================================================================== */

/**
 * @return array [name, details, code, httpStatus] - code null when OK.
 */
function suiteEditValidateName(&$db)
{
    global $g_ereg_forbidden;
    $name = getStr('name');
    $details = getStr('details', null);
    if (is_null($details)) {
        $details = '';
    }
    if ($name === '') {
        return array('', $details, 'empty_name', 400);
    }
    /* strings_stripSlashes() ran on the whole $_REQUEST in legacy
       init_args(); we strip here so a name typed as \" is judged the same. */
    $name = stripslashes($name);
    if (check_string($name, $g_ereg_forbidden)) {
        return array('', $details, 'bad_chars', 400);
    }
    if (mb_strlen($name, 'UTF-8') > 100) {
        /* testsuite name column is varchar(100); a longer value would be
           silently truncated by MySQL in strict mode -> 500 instead. */
        return array('', $details, 'name_too_long', 400);
    }
    return array($name, $details, null, 200);
}

/* ================================================================== *
 * keywords - containerEdit.php addTestSuite / updateTestSuite         *
 * ================================================================== */

function suiteEditKeywordNames($body)
{
    $raw = null;
    foreach (array('keywords', 'assigned_keyword_list') as $k) {
        if (array_key_exists($k, $body)) {
            $raw = $body[$k];
            break;
        }
    }
    if ($raw === null) {
        $raw = getParam('keywords', null);
    }
    if ($raw === null) {
        return null; // absent -> caller is not editing keywords
    }
    $list = array();
    if (is_array($raw)) {
        foreach ($raw as $k) {
            if (is_array($k) || is_object($k)) {
                continue;
            }
            $k = trim(stripslashes((string)$k));
            if ($k !== '') {
                $list[] = $k;
            }
        }
    } else {
        $raw = trim(stripslashes((string)$raw));
        if ($raw !== '') {
            foreach (explode(',', $raw) as $k) {
                $k = trim($k);
                if ($k !== '') {
                    $list[] = $k;
                }
            }
        }
    }
    return array_values(array_unique($list));
}

/** Keywords of a test project (for the picker) and of one suite (assigned). */
function suiteEditKeywordSets($db, $tprojectMgr, $tprojectId, $suiteId = 0)
{
    $available = array();
    $map = $tprojectMgr->get_keywords_map(intval($tprojectId));
    if (!is_null($map)) {
        foreach ($map as $id => $name) {
            $available[] = array('id' => intval($id), 'name' => (string)$name);
        }
    }
    $assigned = array();
    if (intval($suiteId) > 0) {
        $T = tlObjectWithDB::getDBTables(array('object_keywords'));
        $rows = $db->get_recordset(
            "SELECT keyword_id FROM {$T['object_keywords']}" .
            " WHERE fk_id = " . intval($suiteId) .
            " AND fk_table = 'testsuite' ORDER BY keyword_id");
        if (!is_null($rows)) {
            foreach ($rows as $r) {
                $assigned[] = intval($r['kw_id']);
            }
        }
    }
    return array('available' => $available, 'assigned' => $assigned);
}

/* ================================================================== *
 * delete preview - containerEdit.php deleteTestSuite + warning builder *
 * ================================================================== */

/**
 * Legacy build_del_testsuite_warning_msg() (containerEdit.php:465):
 * walks the DEEP test cases of the suite, resolves each one's execution
 * status and classifies it as
 *   no_links | linked_but_not_executed | linked_and_executed
 * A single linked_and_executed case flips $show_warning and therefore requires
 * the 'delete_executed_testcases' right.
 *
 * @return array {can_delete, blocked, rows:[], counters:{}}
 */
function suiteEditDeletePreview($db, &$treeMgr, &$tcaseMgr, $suiteId)
{
    $tsuiteMgr = new testsuite($db);
    $rows = array();
    $counters = array('total' => 0, 'no_links' => 0,
                      'linked_but_not_executed' => 0, 'linked_and_executed' => 0);
    $hasExecuted = false;

    $testcases = $tsuiteMgr->get_testcases_deep($suiteId);
    if (!is_null($testcases)) {
        $getOptions = array('addExecIndicator' => true);
        foreach ($testcases as $elem) {
            $counters['total']++;
            $status = 'no_links';
            $xx = null;
            /* get_exec_status() is the legacy call; it can raise an
               E_WARNING on a slim schema, so it is called defensively and a
               failure degrades to "no_links" instead of a 500. */
            if (is_callable(array($tcaseMgr, 'get_exec_status'))) {
                $xx = @$tcaseMgr->get_exec_status(intval($elem['id']), null, $getOptions);
            }
            if (!is_null($xx)) {
                $status = !empty($xx['executed']) ? 'linked_and_executed'
                                                 : 'linked_but_not_executed';
            }
            $counters[$status]++;
            if ($status === 'linked_and_executed') {
                $hasExecuted = true;
            }
            $path = $treeMgr->get_path(intval($elem['id']), intval($suiteId));
            $pathNames = array();
            if (!is_null($path)) {
                foreach ($path as $p) {
                    $pathNames[] = (string)$p['name'];
                }
            }
            $rows[] = array(
                'name'   => (string)($elem['name'] ?? ''),
                'path'   => implode(' \\ ', $pathNames),
                'status' => $status,
            );
        }
    }

    return array(
        'can_delete' => !$hasExecuted,
        'has_executed' => $hasExecuted,
        'rows' => $rows,
        'counters' => $counters,
    );
}

/* ================================================================== *
 * context shared by every mode                                        *
 * ================================================================== */

function suiteEditContext(&$db, $tprojectId, $mode)
{
    $types = suiteEditNodeTypes($db);
    $tprojectMgr = new testproject($db);
    $ctx = array(
        'mode' => $mode,
        'tproject_id' => intval($tprojectId),
        'tproject_name' => suiteEditProjectName($db, $tprojectId),
        'tproject_prefix' => suiteEditProjectPrefix($db, $tprojectId),
        'can_modify' => false,
        'can_delete_executed' => false,
    );
    return array($ctx, $tprojectMgr);
}

/* ================================================================== *
 * GET ?action=init                                                   *
 * ================================================================== */

if ($action === 'init') {
    if ($method === 'POST') {
        header('Allow: GET, HEAD');
        http_response_code(405);
        out(array('status' => 'error', 'code' => 'wrong_method',
                  'message' => 'Use GET for action=init'));
    }

    $mode = strtolower(trim((string)getParam('mode', '')));
    if (!in_array($mode, array('create', 'edit', 'delete'), true)) {
        out(array('status' => 'error', 'code' => 'invalid_mode',
                  'message' => 'mode must be create, edit or delete'), 400);
    }

    /* The container is the ADDRESS: the owning project is always re-derived
       from it, so a caller can never present project A's rights while editing
       project B's tree (the IDOR class of #1601 / #1595). */
    $containerId = getInt('container_id', 0);
    $suiteId = getInt('suite_id', 0);
    $requestedProject = getInt('tproject_id', 0);

    if ($mode === 'create') {
        $parentId = $containerId > 0 ? $containerId : $suiteId;
        if ($parentId <= 0) {
            $parentId = $requestedProject;
        }
        if ($parentId <= 0) {
            out(array('status' => 'error', 'code' => 'invalid_parameter',
                      'message' => 'Missing container_id'), 400);
        }
        $parent = suiteEditNode($db, $parentId);
        if (is_null($parent)) {
            out(array('status' => 'error', 'code' => 'parent_not_found',
                      'message' => 'Parent container not found'), 404);
        }
        $tprojectId = suiteEditOwningProject($db, $parent);
        if ($tprojectId <= 0) {
            out(array('status' => 'error', 'code' => 'parent_not_found',
                      'message' => 'Parent container not found'), 404);
        }
        requireParentOfProject($db, $parentId, $tprojectId);
        if ($requestedProject > 0 && $requestedProject != $tprojectId) {
            out(array('status' => 'error', 'code' => 'project_mismatch',
                      'message' => 'Test project mismatch'), 404);
        }
    } else {
        if ($suiteId <= 0) {
            out(array('status' => 'error', 'code' => 'invalid_parameter',
                      'message' => 'Missing suite_id'), 400);
        }
        $node = suiteEditNode($db, $suiteId);
        if (is_null($node)) {
            out(array('status' => 'error', 'code' => 'suite_not_found',
                      'message' => 'Test suite not found'), 404);
        }
        $tprojectId = suiteEditOwningProject($db, $node);
        if ($tprojectId <= 0) {
            out(array('status' => 'error', 'code' => 'suite_not_found',
                      'message' => 'Test suite not found'), 404);
        }
        requireSuiteOfProject($db, $suiteId, $tprojectId);
        if ($requestedProject > 0 && $requestedProject != $tprojectId) {
            out(array('status' => 'error', 'code' => 'project_mismatch',
                      'message' => 'Test project mismatch'), 404);
        }
        $parentId = intval($node['parent_id']);
    }

    requireTproject($db, $tprojectId);

    $canModify = ($user->hasRight($db, 'mgt_modify_tc', $tprojectId) === 'yes');
    $canDeleteExec = ($user->hasRight($db, 'delete_executed_testcases', $tprojectId) === 'yes');

    list($ctx, $tprojectMgr) = suiteEditContext($db, $tprojectId, $mode);
    $ctx['can_modify'] = $canModify;
    $ctx['can_delete_executed'] = $canDeleteExec;
    $ctx['parent_id'] = intval($parentId);
    $ctx['parent_name'] = is_null(suiteEditNode($db, $parentId))
        ? '' : (string)suiteEditNode($db, $parentId)['name'];
    $ctx['parent_path'] = array();
    foreach (suiteEditChain($db, $parentId) as $n) {
        $ctx['parent_path'][] = array('id' => intval($n['id']),
                                      'name' => (string)$n['name']);
    }

    $tsuiteMgr = new testsuite($db);
    $payload = array(
        'status' => 'ok',
        'context' => $ctx,
        'suite' => null,
        'cfields' => array(),
        'keywords' => array('available' => array(), 'assigned' => array()),
        'delete_preview' => null,
    );

    if ($mode === 'create') {
        $payload['cfields'] = suiteEditCfDefs($tsuiteMgr, $tprojectId, 0);
        $payload['keywords'] = suiteEditKeywordSets($db, $tprojectMgr, $tprojectId, 0);
    } else {
        $row = $tsuiteMgr->get_by_id($suiteId);
        if (is_null($row)) {
            out(array('status' => 'error', 'code' => 'suite_not_found',
                      'message' => 'Test suite not found'), 404);
        }
        $kw = suiteEditKeywordSets($db, $tprojectMgr, $tprojectId, $suiteId);
        $payload['suite'] = array(
            'id'         => intval($suiteId),
            'name'       => (string)($row['name'] ?? ''),
            'details'    => (string)($row['details'] ?? ''),
            'parent_id'  => intval($row['parent_id'] ?? $parentId),
            'node_order' => isset($row['node_order']) ? intval($row['node_order']) : 0,
        );
        $payload['cfields'] = suiteEditCfDefs($tsuiteMgr, $tprojectId, $suiteId);
        $payload['keywords'] = $kw;
        if ($mode === 'delete') {
            $treeMgr = new tree($db);
            $tcaseMgr = new testcase($db);
            $preview = suiteEditDeletePreview($db, $treeMgr, $tcaseMgr, $suiteId);
            $preview['can_delete'] = $preview['can_delete'] || $canDeleteExec;
            $preview['blocked'] = !$preview['can_delete'];
            $preview['blocked_message_key'] = 'sued.blockedDeleteExec';
            $preview['delete_notice'] = $preview['has_executed']
                ? 'delete_notice' : '';
            $payload['delete_preview'] = $preview;
            /* Sub-suites and test cases are the blast radius; the count comes
               from the same deep walk the gate uses. */
            $payload['delete_preview']['sub_suites'] = suiteEditChildSuiteCount($db, $suiteId);
        }
    }

    out($payload);
}

/** Direct child test suites of a node (used for the delete blast radius). */
function suiteEditChildSuiteCount(&$db, $nodeId)
{
    $types = suiteEditNodeTypes($db);
    $T = suiteEditTables();
    $row = $db->get_recordset(
        "SELECT COUNT(*) AS c FROM {$T['nodes_hierarchy']}" .
        " WHERE parent_id = " . intval($nodeId) .
        " AND node_type_id = " . intval($types['testsuite']));
    return (!is_null($row) && count($row) > 0) ? intval($row[0]['c']) : 0;
}

/* ================================================================== *
 * POST writes                                                         *
 * ================================================================== */

if ($method !== 'POST') {
    header('Allow: GET, HEAD');
    http_response_code(405);
    out(array('status' => 'error', 'code' => 'wrong_method',
              'message' => 'This action requires POST'));
}

$tsuiteMgr = new testsuite($db);
$tprojectMgr = new testproject($db);
$body = bffBody();

if ($action === 'create' || $action === 'update') {
    $isCreate = ($action === 'create');

    $tprojectId = getIdOrFail('tproject_id');
    requireTproject($db, $tprojectId);

    if ($isCreate) {
        $parentId = getIdOrFail('parent_id');
        requireParentOfProject($db, $parentId, $tprojectId);
    } else {
        $suiteId = getIdOrFail('suite_id');
        requireSuiteOfProject($db, $suiteId, $tprojectId);
        $parentId = intval(suiteEditNode($db, $suiteId)['parent_id']);
    }

    if ($user->hasRight($db, 'mgt_modify_tc', $tprojectId) !== 'yes') {
        out(array('status' => 'error', 'code' => 'no_right',
                  'message' => 'You are not authorized to modify test suites'), 403);
    }

    list($name, $details, $code, $http) = suiteEditValidateName($db);
    if (!is_null($code)) {
        out(array('status' => 'error', 'code' => $code,
                  'message' => ($code === 'empty_name')
                      ? 'Test suite name is required'
                      : (($code === 'bad_chars')
                          ? 'The name contains forbidden characters'
                          : 'The name is too long (max 100 characters)')), $http);
    }

    if ($isCreate) {
        /* BUGID 3890: on duplicate the action is hardcoded to 'block';
           config_get('action_on_duplicate_name') is only for copy/move. */
        $ret = $tsuiteMgr->create($parentId, $name, $details, null,
                                  config_get('check_names_for_duplicates'), 'block');
        $ok = is_array($ret) ? !empty($ret['status_ok']) : ($ret > 0);
        $newId = is_array($ret) ? intval($ret['id'] ?? 0) : intval($ret);
        if (!$ok || $newId <= 0) {
            $msg = is_array($ret) ? (string)($ret['msg'] ?? '') : '';
            $code = ($msg === 'ok' || $msg === '') ? 'create_failed' : $msg;
            out(array('status' => 'error', 'code' => $code,
                      'message' => ($msg === 'ok' || $msg === '')
                          ? 'Test suite could not be created' : $msg,
                      'id' => $newId), 422);
        }
        $suiteId = $newId;
        $eventName = 'EVENT_TEST_SUITE_CREATE';
    } else {
        $ret = $tsuiteMgr->update($suiteId, $name, $details, $parentId);
        if (!is_array($ret) || empty($ret['status_ok'])) {
            $msg = is_array($ret) ? (string)($ret['msg'] ?? '') : '';
            out(array('status' => 'error',
                      'code' => ($msg === 'ok' || $msg === '') ? 'update_failed' : $msg,
                      'message' => ($msg === 'ok' || $msg === '')
                          ? 'Test suite could not be updated' : $msg), 422);
        }
        $eventName = 'EVENT_TEST_SUITE_UPDATE';
    }

    /* Keywords: delete-then-add on update, add-only on create - legacy
       updateTestSuite() / addTestSuite() exactly. */
    $kwNames = suiteEditKeywordNames($body);
    $kwWritten = 0;
    if (!is_null($kwNames)) {
        $tsuiteMgr->deleteKeywords($suiteId);
        if (count($kwNames) > 0) {
            $tsuiteMgr->addKeywords($suiteId, $kwNames);
            $kwWritten = count($kwNames);
        }
    }

    /* Design custom fields (containerEdit.php writeCustomFieldsToDB). */
    $cfMap = $tsuiteMgr->cfield_mgr->get_linked_cfields_at_design($tprojectId, 1, null, 'testsuite');
    $cfWritten = suiteEditSaveCf($tsuiteMgr, $body, $cfMap, $suiteId);

    /* Plugin events - legacy ctx shape. */
    if (function_exists('event_signal')) {
        event_signal($eventName, array('id' => intval($suiteId), 'name' => $name,
                                       'details' => $details));
    }

    $parentName = is_null(suiteEditNode($db, $parentId))
        ? '' : (string)suiteEditNode($db, $parentId)['name'];
    $newNode = suiteEditNode($db, $suiteId);

    out(array(
        'status' => 'ok',
        'message' => $isCreate ? 'Test suite created' : 'Test suite updated',
        'id' => intval($suiteId),
        'created' => $isCreate,
        'name' => $name,
        'parent_id' => intval($parentId),
        'parent_name' => $parentName,
        'node_order' => is_null($newNode) ? 0 : intval($newNode['node_order']),
        'keywords_written' => $kwWritten,
        'cfields_written' => $cfWritten,
        'tproject_id' => intval($tprojectId),
    ), 200);
}

if ($action === 'delete') {
    $tprojectId = getIdOrFail('tproject_id');
    requireTproject($db, $tprojectId);
    $suiteId = getIdOrFail('suite_id');
    requireSuiteOfProject($db, $suiteId, $tprojectId);

    if ($user->hasRight($db, 'mgt_modify_tc', $tprojectId) !== 'yes') {
        out(array('status' => 'error', 'code' => 'no_right',
                  'message' => 'You are not authorized to modify test suites'), 403);
    }

    /* The legacy gate is RE-EVALUATED here instead of trusting the GET that
       showed the button: between the preview and the confirm the subtree can
       have gained an execution. */
    $treeMgr = new tree($db);
    $tcaseMgr = new testcase($db);
    $preview = suiteEditDeletePreview($db, $treeMgr, $tcaseMgr, $suiteId);
    if ($preview['has_executed'] &&
        $user->hasRight($db, 'delete_executed_testcases', $tprojectId) !== 'yes') {
        out(array('status' => 'error', 'code' => 'suite_has_executed',
                  'message' => 'The test plan blocks this deletion because the test suite ' .
                               'contains executed test cases'), 409);
    }

    $row = $tsuiteMgr->get_by_id($suiteId);
    $deletedName = is_null($row) ? '' : (string)($row['name'] ?? '');
    $parentId = intval(suiteEditNode($db, $suiteId)['parent_id']);

    $tsuiteMgr->delete_deep($suiteId);
    $tsuiteMgr->deleteKeywords($suiteId);

    if (function_exists('event_signal')) {
        event_signal('EVENT_TEST_SUITE_DELETE', array('id' => intval($suiteId),
                                                     'name' => $deletedName));
    }

    out(array(
        'status' => 'ok',
        'message' => 'Test suite deleted',
        'id' => intval($suiteId),
        'deleted_name' => $deletedName,
        'parent_id' => $parentId,
        'deleted_testcases' => intval($preview['counters']['total']),
        'tproject_id' => intval($tprojectId),
    ), 200);
}

out(array('status' => 'error', 'code' => 'unknown_action',
          'message' => 'Unknown action'), 400);
