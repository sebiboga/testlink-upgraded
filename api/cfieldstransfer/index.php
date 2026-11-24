<?php
/**
 * api/cfieldstransfer — Custom Fields definition import/export BFF (Refs #2048)
 *
 * Modern twin of the two remaining Smarty-rendered Custom Fields definition
 * controllers:
 *   lib/cfields/cfieldsImport.php (145 lines) — upload customFields.xml and
 *     create every field whose name does not exist yet.
 *   lib/cfields/cfieldsExport.php (116 lines) — download all definitions as
 *     the exact ADODB_XML document (root <custom_fields>, row <custom_field>).
 *
 * Neither screen had a modern entry point: cfieldsView.html (Refs #957) offered
 * no import/export button at all, so both legacy renderers were only reachable
 * by typing their URLs. This BFF and gui/templates/cfields/cfieldsTransfer.html
 * give them one deep-linkable Dashio screen with the full legacy behaviour.
 *
 * Routes:
 *   GET|HEAD ?action=info   — context, rights, limits, import/export field spec
 *   GET|HEAD ?action=usage  — net-new per-definition usage inventory
 *   GET|HEAD ?action=export[&filename=customFields.xml] — XML download
 *   POST    ?action=import  (multipart targetFilename=<XML file>)
 *
 * Rights: cfield_view is required for info/export and cfield_management for
 * import, matching the two legacy checkRights() implementations. An anonymous
 * request gets 401, a request without the right 403 (machine code no_right).
 */
require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
require_once('users.inc.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

define('CTCF_MAX_UPLOAD_BYTES', 1048576);
define('CTCF_MAX_FIELDS', 500);

function ctcfOut($data, $code = 200) {
    if (!headers_sent()) { http_response_code($code); }
    echo json_encode($data);
    exit;
}

function ctcfFail($code, $machineCode, $message) {
    ctcfOut(array('status' => 'error', 'code' => $machineCode,
                  'message' => $message), $code);
}

function ctcfScalar($v, $default = '') {
    return is_scalar($v) ? $v : $default;
}

/** Fields of one <custom_field> row, in the legacy cfieldsExport::doExport() order. */
function ctcfFieldNames() {
    return array('name', 'label', 'type', 'possible_values', 'default_value',
                 'valid_regexp', 'length_min', 'length_max', 'show_on_design',
                 'enable_on_design', 'show_on_execution', 'enable_on_execution',
                 'show_on_testplan_design', 'enable_on_testplan_design',
                 'node_type_id');
}

/** Only definition columns written by cfield_mgr::create() are imported. */
function ctcfWritableNames() {
    return array('name', 'label', 'type', 'possible_values', 'show_on_design',
                 'enable_on_design', 'show_on_execution', 'enable_on_execution',
                 'show_on_testplan_design', 'enable_on_testplan_design',
                 'node_type_id');
}

$db = new database(DB_TYPE);
doDBConnect($db);
$cfieldMgr = new cfield_mgr($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    ctcfFail(401, 'not_authenticated', 'Not authenticated');
}
$user = tlUser::getByID($db, intval($userId));
if (is_null($user)) {
    ctcfFail(401, 'not_authenticated', 'User not found');
}

$method = strtoupper($_SERVER['REQUEST_METHOD']);
if ($method !== 'GET' && $method !== 'HEAD' && $method !== 'POST') {
    ctcfFail(405, 'method_not_allowed', 'Unsupported HTTP method: ' . $method);
}
$action = (string)ctcfScalar($_GET['action'] ?? $_POST['action'] ?? '', '');
if ($action === '') {
    ctcfFail(400, 'missing_action', 'No action requested');
}
if (!in_array($action, array('info', 'usage', 'export', 'import'), true)) {
    ctcfFail(400, 'unknown_action', 'Unknown action: ' . $action);
}
if ($action === 'import' && $method !== 'POST') {
    ctcfFail(405, 'method_not_allowed', 'Use POST for import');
}
if ($action !== 'import' && $method === 'POST') {
    ctcfFail(405, 'method_not_allowed', 'Use GET for ' . $action);
}

$canView = (bool)$user->hasRight($db, 'cfield_view');
$canManage = (bool)$user->hasRight($db, 'cfield_management');
$needed = ($action === 'import') ? 'cfield_management' : 'cfield_view';
$allowed = ($action === 'import') ? $canManage : $canView;
if (!$allowed) {
    ctcfFail(403, 'no_right', 'The ' . $needed . ' right is required');
}
// Every route is a live session operation: an expired tab must not keep
// reading/exporting/importing definitions (legacy testlinkInitPage parity).
bffEnforceSession($db);

if ($action === 'info') {
    ctcfOut(array(
        'status' => 'ok',
        'context' => array(
            'user_id' => intval($userId),
            'basehref' => (string)($_SESSION['basehref'] ?? ''),
        ),
        'grants' => array('view' => $canView, 'manage' => $canManage),
        'limits' => array(
            'max_upload_bytes' => CTCF_MAX_UPLOAD_BYTES,
            'max_fields' => CTCF_MAX_FIELDS,
            'import_file_max_size_bytes' => (int)config_get('import_file_max_size_bytes'),
        ),
        'fields' => ctcfFieldNames(),
        'types' => $cfieldMgr->custom_field_types,
        'filename' => 'customFields.xml',
    ));
}

if ($action === 'usage') {
    $tables = tlObjectWithDB::getDBTables(array('custom_fields', 'cfield_node_types',
        'cfield_testprojects', 'cfield_design_values', 'cfield_execution_values',
        'cfield_testplan_design_values', 'cfield_build_design_values', 'node_types'));
    $sql = " SELECT CF.id, CF.name, CF.label, CF.type, " .
           "        GROUP_CONCAT(DISTINCT NT.description ORDER BY NT.description SEPARATOR ', ') AS node_types, " .
           "        (SELECT COUNT(*) FROM {$tables['cfield_testprojects']} TP " .
           "          WHERE TP.field_id = CF.id) AS projects, " .
           "        (SELECT COUNT(*) FROM {$tables['cfield_design_values']} DV " .
           "          WHERE DV.field_id = CF.id) AS design_values, " .
           "        (SELECT COUNT(*) FROM {$tables['cfield_execution_values']} EV " .
           "          WHERE EV.field_id = CF.id) AS execution_values, " .
           "        (SELECT COUNT(*) FROM {$tables['cfield_testplan_design_values']} TV " .
           "          WHERE TV.field_id = CF.id) AS plan_values, " .
           "        (SELECT COUNT(*) FROM {$tables['cfield_build_design_values']} BV " .
           "          WHERE BV.field_id = CF.id) AS build_values " .
           " FROM {$tables['custom_fields']} CF " .
           " LEFT JOIN {$tables['cfield_node_types']} CNT ON CNT.field_id = CF.id " .
           " LEFT JOIN {$tables['node_types']} NT ON NT.id = CNT.node_type_id " .
           " GROUP BY CF.id, CF.name, CF.label, CF.type " .
           " ORDER BY CF.name ";
    $rs = $db->get_recordset($sql);
    $rows = array();
    $totals = array('fields' => 0, 'linked_projects' => 0, 'design_values' => 0,
                    'execution_values' => 0, 'plan_values' => 0, 'build_values' => 0,
                    'unused' => 0);
    if (is_array($rs)) {
        foreach ($rs as $row) {
            $values = (int)$row['design_values'] + (int)$row['execution_values'] +
                      (int)$row['plan_values'] + (int)$row['build_values'];
            $item = array(
                'id' => (int)$row['id'],
                'name' => (string)$row['name'],
                'label' => (string)$row['label'],
                'type' => (int)$row['type'],
                'node_types' => (string)($row['node_types'] ?? ''),
                'projects' => (int)$row['projects'],
                'design_values' => (int)$row['design_values'],
                'execution_values' => (int)$row['execution_values'],
                'plan_values' => (int)$row['plan_values'],
                'build_values' => (int)$row['build_values'],
                'values_total' => $values,
                'verdict' => ($values > 0) ? 'used' : 'unused',
            );
            $rows[] = $item;
            $totals['fields']++;
            $totals['linked_projects'] += $item['projects'];
            $totals['design_values'] += $item['design_values'];
            $totals['execution_values'] += $item['execution_values'];
            $totals['plan_values'] += $item['plan_values'];
            $totals['build_values'] += $item['build_values'];
            if ($values === 0) { $totals['unused']++; }
        }
    }
    ctcfOut(array('status' => 'ok', 'rows' => $rows, 'totals' => $totals));
}

if ($action === 'export') {
    require_once(__DIR__ . '/../../third_party/adodb_xml/class.ADODB_XML.php');
    $filename = (string)ctcfScalar($_GET['filename'] ?? '', 'customFields.xml');
    $filename = basename(str_replace(array("\r", "\n", "\0", '"'), '', $filename));
    if (!preg_match('/^[A-Za-z0-9 _.-]+$/', $filename) || $filename === '') {
        $filename = 'customFields.xml';
    }
    $tables = tlObjectWithDB::getDBTables(array('custom_fields', 'cfield_node_types'));
    // To solve issues with MAC OS (legacy cfieldsExport.php parity).
    $tmp = (PHP_OS === 'Darwin') ? config_get('temp_dir') : null;
    $adodbXML = new ADODB_XML('1.0', 'ISO-8859-1', $tmp);
    $sql = " SELECT name,label,type,possible_values,default_value,valid_regexp, " .
           " length_min,length_max,show_on_design,enable_on_design,show_on_execution," .
           " enable_on_execution,show_on_testplan_design,enable_on_testplan_design, " .
           " node_type_id " .
           " FROM {$tables['custom_fields']} CF,{$tables['cfield_node_types']} " .
           " WHERE CF.id=field_id ";
    $adodbXML->setRootTagName('custom_fields');
    $adodbXML->setRowTagName('custom_field');
    $content = $adodbXML->ConvertToXMLString($db->db, $sql);
    if (!headers_sent()) {
        header('Content-Type: application/xml; charset=ISO-8859-1');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store');
    }
    echo $content;
    exit;
}

// POST ?action=import — legacy doImport(), hardened.
if (!isset($_FILES['targetFilename']) || !is_array($_FILES['targetFilename'])) {
    ctcfFail(422, 'no_file', 'Please choose a file to import');
}
$file = $_FILES['targetFilename'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    ctcfFail(422, 'upload_error', 'The file could not be uploaded');
}
$size = (int)($file['size'] ?? 0);
$limit = min(CTCF_MAX_UPLOAD_BYTES, (int)config_get('import_file_max_size_bytes'));
if ($size <= 0 || $size > $limit) {
    ctcfFail(413, 'file_too_large', 'The file is too large');
}
$tmpName = $file['tmp_name'] ?? '';
if (!is_string($tmpName) || $tmpName === '' || !is_uploaded_file($tmpName)) {
    ctcfFail(422, 'no_file', 'The file could not be uploaded');
}
$raw = file_get_contents($tmpName);
if ($raw === false || $raw === '') {
    ctcfFail(422, 'empty_file', 'The XML file is empty');
}

// LIBXML_NONET + no entity loader: the legacy wrapper called die() on parse
// errors and documented XXE as a risk; this route always answers JSON.
$previous = libxml_use_internal_errors(true);
$xml = simplexml_load_string($raw, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
libxml_clear_errors();
libxml_use_internal_errors($previous);
if ($xml === false || !isset($xml->custom_field)) {
    ctcfFail(422, 'invalid_xml', 'Problems loading XML content');
}

$allowedNodeTypes = array_map('intval', array_values($cfieldMgr->get_allowed_nodes()));
$writable = ctcfWritableNames();
$created = array();
$skipped = array();
$failed = array();
$count = 0;
foreach ($xml->custom_field as $cf) {
    $count++;
    if ($count > CTCF_MAX_FIELDS) {
        ctcfFail(413, 'too_many_fields', 'Too many custom fields in the file');
    }
    $name = trim((string)$cf->name);
    if ($name === '' || is_null($cfieldMgr->get_by_name($name))) {
        // An empty name is rejected BEFORE the lookup: get_by_name('') would
        // match nothing and create() would then insert an unusable definition.
        if ($name === '') {
            $failed[] = array('name' => '', 'message' => 'Missing custom field name');
            continue;
        }
    } else {
        $skipped[] = $name;
        continue;
    }
    $values = array();
    foreach ($writable as $key) {
        $values[$key] = isset($cf->$key) ? (string)$cf->$key : '';
    }
    $values['name'] = $name;
    $values['node_type_id'] = is_numeric($values['node_type_id'])
        ? (int)$values['node_type_id'] : 0;
    if (!in_array($values['node_type_id'], $allowedNodeTypes, true)) {
        $failed[] = array('name' => $name, 'message' => 'Invalid node type');
        continue;
    }
    if ($values['label'] === '') { $values['label'] = $name; }
    foreach (array('type', 'node_type_id', 'show_on_design', 'enable_on_design',
                   'show_on_execution', 'enable_on_execution',
                   'show_on_testplan_design', 'enable_on_testplan_design') as $numKey) {
        $values[$numKey] = is_numeric($values[$numKey]) ? intval($values[$numKey]) : 0;
    }
    $result = $cfieldMgr->create($values);
    if (!empty($result['status_ok'])) {
        $created[] = $name;
    } else {
        $failed[] = array('name' => $name, 'message' => (string)($result['msg'] ?? 'ko'));
    }
}

ctcfOut(array(
    'status' => 'ok',
    'result' => array(
        'created' => $created,
        'skipped' => $skipped,
        'failed' => $failed,
        'total' => $count,
    ),
));
