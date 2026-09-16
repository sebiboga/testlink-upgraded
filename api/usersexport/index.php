<?php
/**
 * User Management Export BFF API
 * URL: /api/usersexport/
 *
 * Mirrors lib/usermanagement/usersExport.php (TestLink 1.9.20): exports the
 * full user list to an XML file (ADODB_XML, root <users>, row <user>). The
 * legacy screen streamed the dump straight to the browser via the
 * downloadContentsToFile() helper; this BFF keeps the same data set and
 * filename contract but exposes it as a JSON info route + an XML download.
 *
 * Routes:
 *   GET  ?action=info
 *        -> { status, filename, count, exportTypes, grants:{ mgt_users } }
 *   POST ?action=export [&exportType=XML|MD] [&export_filename=users.xml]  (X-Requested-With: XMLHttpRequest)
 *        -> streams the XML/Markdown attachment download
 *
 * Permission parity with legacy: usersExport.php checkRights() requires the
 * mgt_users right on both the page view and the doExport action, so it is
 * enforced on every route here (401 unauthenticated, 403 no right).
 *
 * Refs #922: MD (Markdown) export added next to the legacy XML dump — same
 * user field set, rendered as a GitHub-style Markdown table.
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

// Legacy parity: lib/usermanagement/usersExport.php checkRights() ->
// $user->hasRight($db,"mgt_users"); checkRights is enforced by
// testlinkInitPage() BEFORE the page renders, so export/view are both gated.
if (!$user->hasRight($db, 'mgt_users')) {
    http_response_code(403);
    out(['status' => 'error', 'message' => 'No rights', 'right' => 'mgt_users']);
}

/**
 * Default export filename, mirroring initializeGui() in usersExport.php
 * ($gui->export_filename = 'users.xml'). The user may override it via the
 * export_filename parameter on the export route; the prefix is shared by
 * both formats, the extension follows the selected export type.
 */
function defaultExportFilename() {
    return 'users';
}

/**
 * Allowed export types, mirroring the legacy tcExport export_file_types map
 * (testcase.class.php) so the User Management export offers the same XML/MD
 * choice the project already uses for test-case exports.
 */
function exportTypes() {
    return array('XML' => 'XML', 'MD' => 'Markdown');
}

/**
 * Build the XML dump of the users table, mirroring doExport() in the legacy
 * controller: same field set, same ADODB_XML root/row tag names.
 */
function buildUsersXml(&$dbHandler) {
    if (!is_file(__DIR__ . '/../../third_party/adodb_xml/class.ADODB_XML.php')) {
        return null;
    }
    require_once(__DIR__ . '/../../third_party/adodb_xml/class.ADODB_XML.php');

    $adodbXML = new ADODB_XML('1.0', 'ISO-8859-1');
    $adodbXML->setRootTagName('users');
    $adodbXML->setRowTagName('user');

    $tables = tlObjectWithDB::getDBTables(array('users'));
    $fieldSet = 'id,login,role_id,email,first,last,locale,' .
                'default_testproject_id,active,expiration_date';
    $sql = " SELECT {$fieldSet} FROM {$tables['users']} ";

    return $adodbXML->ConvertToXMLString($dbHandler->db, $sql);
}

/**
 * Build a Markdown dump of the users table with the same field set as the
 * XML export (id, login, role_id, email, first, last, locale,
 * default_testproject_id, active, expiration_date), rendered as a
 * GitHub-flavoured table. Cell content is escaped so that pipe/backslash/
 * newline characters cannot break the table layout.
 */
function buildUsersMd(&$dbHandler) {
    $tables = tlObjectWithDB::getDBTables(array('users'));
    $fieldSet = 'id,login,role_id,email,first,last,locale,' .
                'default_testproject_id,active,expiration_date';
    $sql = " SELECT {$fieldSet} FROM {$tables['users']} ";
    $rows = $dbHandler->get_recordset($sql);
    if (is_null($rows) || count($rows) === 0) {
        return '';
    }

    $esc = function ($v) {
        $s = is_null($v) ? '' : strval($v);
        $s = str_replace(array('\\', '|', "\r", "\n"), array('\\\\', '\\|', ' ', ' '), $s);
        return $s;
    };

    $headers = array('id', 'login', 'role_id', 'email', 'first', 'last',
                     'locale', 'default_testproject_id', 'active', 'expiration_date');
    $lines = array();
    $lines[] = '# Users';
    $lines[] = '';
    $lines[] = '| ' . implode(' | ', array_map($esc, $headers)) . ' |';
    $lines[] = '|' . implode('|', array_fill(0, count($headers), '----')) . '|';
    foreach ($rows as $row) {
        $cells = array();
        foreach ($headers as $h) {
            $cells[] = $esc(isset($row[$h]) ? $row[$h] : '');
        }
        $lines[] = '| ' . implode(' | ', $cells) . ' |';
    }
    $lines[] = '';

    return implode("\n", $lines);
}

$action = $_REQUEST['action'] ?? '';

// ---------------------------------------------------------------------------
// GET ?action=info  -> context for the modernized export screen
// ---------------------------------------------------------------------------
if ($action === 'info') {
    $tables = tlObjectWithDB::getDBTables(array('users'));
    $rs = $db->get_recordset("SELECT COUNT(1) AS c FROM {$tables['users']}");
    $count = 0;
    if (!is_null($rs) && count($rs) > 0) {
        $count = intval($rs[0]['c'] ?? 0);
    }

    out(array(
        'status'        => 'ok',
        'filename'      => defaultExportFilename() . '.xml',
        'count'         => $count,
        'exportTypes'   => exportTypes(),
        'grants'        => array('mgt_users' => 1),
        'pageTitle'     => 'export_users',
    ));
}

// ---------------------------------------------------------------------------
// POST ?action=export  -> stream the XML or Markdown dump as a file download
// ---------------------------------------------------------------------------
if ($action === 'export') {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method !== 'POST') {
        http_response_code(405);
        out(['status' => 'error', 'message' => 'Method not allowed']);
    }

    $exportType = strtoupper(trim($_REQUEST['exportType'] ?? 'XML'));
    $allowedTypes = exportTypes();
    if (!isset($allowedTypes[$exportType])) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Unsupported export type: ' . $exportType]);
    }
    $ext = ($exportType === 'MD') ? '.md' : '.xml';

    // Content depends on the selected format: XML keeps the legacy
    // ADODB_XML dump, MD is a Markdown table over the same field set.
    $content = ($exportType === 'MD') ? buildUsersMd($db) : buildUsersXml($db);
    if ($content === null || $content === '') {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Nothing to export']);
    }

    $exportFilename = trim((string)($_REQUEST['export_filename'] ?? ''));
    if ($exportFilename === '') {
        $exportFilename = defaultExportFilename() . $ext;
    }
    // Safety: basename only + strip CR/LF/quotes to avoid header injection;
    // cap the length at the legacy field size (STRING_N 0,100).
    $exportFilename = substr(basename($exportFilename), 0, 100);
    $headerFilename = str_replace(["\r", "\n", '"'], '', $exportFilename);
    if ($headerFilename === '') {
        $headerFilename = defaultExportFilename() . $ext;
    }

    // Override the JSON header set above and stream the file as an attachment.
    if (!headers_sent()) {
        $ctype = ($exportType === 'MD')
            ? 'text/markdown; charset=utf-8'
            : 'text/xml; charset=ISO-8859-1';
        header('Content-Type: ' . $ctype);
        header('Content-Disposition: attachment; filename="' . $headerFilename . '"');
        header('Pragma: public');
        header('Cache-Control: must-revalidate');
    }
    echo $content;
    exit;
}

http_response_code(404);
out(['status' => 'error', 'message' => 'Unknown action']);