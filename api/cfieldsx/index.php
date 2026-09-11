<?php
/**
 * Custom Fields XML Exchange BFF API
 * URL: /api/cfieldsx/
 * Modernizes legacy lib/cfields/cfieldsExport.php + lib/cfields/cfieldsImport.php
 * Plain PHP, no framework, no compilation
 *
 * Routes:
 *   GET  /                 -> info { count, has_export_right, has_import_right,
 *                                     default_filename, import_limit_kb }
 *   POST /export           -> streams XML download (custom_field rows)
 *                             right: cfield_view ; JSON body { export_filename }
 *   POST /import           -> multipart upload (field: targetFilename)
 *                             right: cfield_management
 *                             returns { status, imported[], not_imported[], msg }
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
require_once('users.inc.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

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
$path = preg_replace('#^/api/cfieldsx(/index\.php)?#', '', $path);
$path = '/' . trim($path, '/');
$method = $_SERVER['REQUEST_METHOD'];
$segments = array_values(array_filter(explode('/', $path)));

function out($data) { echo json_encode($data); exit; }

function canVolatileChars($v, $prepend = 0) {
    return preg_match("/^[a-zA-Z0-9_\.]+$/", $v);
}

$cfield_mgr = new cfield_mgr($db);

/**
 * Export XML exactly like legacy ADODB_XML converter:
 * root <custom_fields>, one <custom_field> row per joined (CF, cfield_node_types) row.
 */
function buildExportXml(&$db) {
    $tables = tlObjectWithDB::getDBTables(array('custom_fields', 'cfield_node_types'));
    $sql = " SELECT name,label,type,possible_values,default_value,valid_regexp," .
           " length_min,length_max,show_on_design,enable_on_design,show_on_execution," .
           " enable_on_execution,show_on_testplan_design,enable_on_testplan_design, node_type_id" .
           " FROM {$tables['custom_fields']} CF,{$tables['cfield_node_types']}" .
           " WHERE CF.id=field_id";
    $rows = $db->fetchRowsIntoMap($sql, 'id');
    if (empty($rows)) {
        $rows = array();
    }

    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->formatOutput = true;
    $root = $dom->createElement('custom_fields');
    $dom->appendChild($root);

    $cols = array('name', 'label', 'type', 'possible_values', 'default_value',
                  'valid_regexp', 'length_min', 'length_max', 'show_on_design',
                  'enable_on_design', 'show_on_execution', 'enable_on_execution',
                  'show_on_testplan_design', 'enable_on_testplan_design', 'node_type_id');

    foreach ($rows as $idx => $row) {
        if (!is_array($row)) {
            $row = (array)$row;
        }
        $el = $dom->createElement('custom_field');
        foreach ($cols as $c) {
            $val = isset($row[$c]) ? $row[$c] : '';
            $el->appendChild($dom->createElement($c, (string)$val));
        }
        $root->appendChild($el);
    }
    return $dom->saveXML();
}

// GET / -> info
if ($method === 'GET' && empty($segments)) {
    $count = 0;
    $tables = tlObjectWithDB::getDBTables(array('custom_fields'));
    $rs = $db->exec_query("SELECT COUNT(1) AS c FROM {$tables['custom_fields']}");
    if ($rs) {
        $count = intval($db->fetch_array($rs)['c']);
    }
    out(array(
        'status' => 'ok',
        'count' => $count,
        'has_export_right' => $user->hasRight($db, 'cfield_view') ? 1 : 0,
        'has_import_right' => $user->hasRight($db, 'cfield_management') ? 1 : 0,
        'default_filename' => 'customFields.xml',
        'import_limit_kb' => intval(config_get('import_file_max_size_bytes') / 1024),
    ));
}

// POST /export
if ($method === 'POST' && isset($segments[0]) && $segments[0] === 'export') {
    if (!$user->hasRight($db, 'cfield_view')) {
        http_response_code(403);
        out(array('status' => 'error', 'message' => 'No permission'));
    }

    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $filename = isset($body['export_filename']) ? trim((string)$body['export_filename']) : 'customFields.xml';
    if ($filename === '') {
        $filename = 'customFields.xml';
    }
    $filename = str_replace(array("\r", "\n", '"'), '', basename($filename));
    if (!canVolatileChars($filename)) {
        $filename = 'customFields.xml';
    }
    if (stripos($filename, '.xml') === false) {
        $filename .= '.xml';
    }

    $content = buildExportXml($db);

    header_remove('Content-Type');
    header('Pragma: public');
    header('Content-Type: application/xml; charset=' . config_get('charset') . '; name=' . $filename);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($content));
    header('Cache-Control: must-revalidate');
    echo $content;
    exit;
}

// POST /import
if ($method === 'POST' && isset($segments[0]) && $segments[0] === 'import') {
    if (!$user->hasRight($db, 'cfield_management')) {
        http_response_code(403);
        out(array('status' => 'error', 'message' => 'No permission'));
    }

    $key = 'targetFilename';
    $source = isset($_FILES[$key]['tmp_name']) ? $_FILES[$key]['tmp_name'] : null;

    if (empty($source) || !is_uploaded_file($source)) {
        http_response_code(422);
        out(array('status' => 'error', 'message' => 'need_file',
                  'imported' => array(), 'not_imported' => array()));
    }

    $fileName = isset($_FILES[$key]['name']) ? basename($_FILES[$key]['name']) : 'customFields.xml';
    $uploadSize = isset($_FILES[$key]['size']) ? intval($_FILES[$key]['size']) : 0;
    $maxBytes = intval(config_get('import_file_max_size_bytes'));
    if ($maxBytes > 0 && $uploadSize > $maxBytes) {
        http_response_code(422);
        out(array('status' => 'error', 'message' => 'file_too_big',
                  'limit_kb' => intval($maxBytes / 1024),
                  'imported' => array(), 'not_imported' => array()));
    }

    $dest = TL_TEMP_PATH . session_id() . '-import_cfields.tmp';
    if (!move_uploaded_file($source, $dest)) {
        http_response_code(500);
        out(array('status' => 'error', 'message' => 'upload_failed',
                  'imported' => array(), 'not_imported' => array()));
    }

    // XXE-safe parse (mirror of legacy simplexml_load_file_wrapper)
    $xmlRaw = @file_get_contents($dest);
    @unlink($dest);
    $prev = libxml_disable_entity_loader(true);
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xmlRaw, 'SimpleXMLElement', LIBXML_NONET);
    $errors = libxml_get_errors();
    libxml_clear_errors();
    if (function_exists('libxml_disable_entity_loader')) {
        libxml_disable_entity_loader($prev);
    }

    if ($xml === false) {
        http_response_code(422);
        out(array('status' => 'error', 'message' => 'parse_failed',
                  'xml_errors' => array_map(function ($e) { return trim($e->message); }, $errors),
                  'imported' => array(), 'not_imported' => array()));
    }

    $imported = array();
    $notImported = array();
    foreach ($xml as $cf) {
        $name = trim((string)$cf->name);
        if ($name === '') {
            $notImported[] = '';
            continue;
        }
        if (is_null($cfield_mgr->get_by_name($name))) {
            $cfield_mgr->create((array)$cf);
            $imported[] = $name;
        } else {
            $notImported[] = $name;
        }
    }

    out(array('status' => 'ok', 'message' => 'done',
              'filename' => $fileName,
              'imported' => $imported,
              'not_imported' => $notImported));
}

http_response_code(404);
out(array('status' => 'error', 'message' => 'Not found'));