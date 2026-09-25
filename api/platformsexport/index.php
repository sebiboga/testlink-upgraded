<?php
declare(strict_types=1);

$GLOBALS['platformExportResponseStarted'] = false;
ob_start();
register_shutdown_function(static function (): void {
    if ($GLOBALS['platformExportResponseStarted']) {
        return;
    }
    $responseStatus = http_response_code();
    if (is_int($responseStatus) && $responseStatus >= 400) {
        return;
    }

    $error = error_get_last();
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    $captured = (string)ob_get_contents();
    if (!in_array($error['type'] ?? null, $fatalTypes, true) && trim($captured) === '') {
        return;
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode([
        'status' => 'error',
        'error_code' => 'SERVER_ERROR',
        'message' => 'Unable to export platforms',
    ]);
});

require_once __DIR__ . '/../../config.inc.php';
require_once 'common.php';
require_once 'xml.inc.php';

doSessionStart();
require_once __DIR__ . '/../_guard.php';
bffSameOriginGuard();

$dbResult = doDBConnect($db);
if (empty($dbResult['status'])) {
    platformExportJson(500, [
        'status' => 'error',
        'error_code' => 'DATABASE_UNAVAILABLE',
        'message' => 'Database unavailable',
    ]);
}

function platformExportBeginResponse(): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $GLOBALS['platformExportResponseStarted'] = true;
}

function platformExportJson(int $status, array $payload): void
{
    platformExportBeginResponse();
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function platformExportProjectId(): int
{
    $value = $_GET['tproject_id'] ?? null;
    if (!is_scalar($value) || !ctype_digit(trim((string)$value))) {
        platformExportJson(400, [
            'status' => 'error',
            'error_code' => 'INVALID_TPROJECT_ID',
            'message' => 'Invalid test project id',
        ]);
    }

    $id = (int)trim((string)$value);
    if ($id <= 0) {
        platformExportJson(400, [
            'status' => 'error',
            'error_code' => 'INVALID_TPROJECT_ID',
            'message' => 'Invalid test project id',
        ]);
    }
    return $id;
}

function platformExportProject($db, int $tprojectId): array
{
    $project = (new testproject($db))->get_by_id($tprojectId);
    if (!is_array($project) || !isset($project['id'])) {
        platformExportJson(404, [
            'status' => 'error',
            'error_code' => 'TEST_PROJECT_NOT_FOUND',
            'message' => 'Test project not found',
        ]);
    }
    return $project;
}

function platformExportAllowed($db, $user, int $tprojectId): bool
{
    return $user->hasRight($db, 'platform_view', $tprojectId)
        || $user->hasRight($db, 'platform_management', $tprojectId);
}

function platformExportSanitizeFilename(string $filename): string
{
    $filename = str_replace(' ', '', trim($filename));
    $filename = str_replace(['\\', '/', "\0"], '_', $filename);
    $filename = preg_replace('/[\x00-\x1F\x7F"<>]/u', '_', $filename) ?? '';
    if (function_exists('mb_substr')) {
        $filename = mb_substr($filename, 0, 200, 'UTF-8');
    } else {
        $filename = substr($filename, 0, 200);
    }
    return trim($filename);
}

function platformExportFilename(string $projectName, $requested): string
{
    $default = platformExportSanitizeFilename($projectName . '-platforms.xml');
    if ($requested === null) {
        return $default !== '' ? $default : 'platforms.xml';
    }
    if (!is_scalar($requested)) {
        platformExportJson(422, [
            'status' => 'error',
            'error_code' => 'INVALID_FILENAME',
            'message' => 'Please give a file name',
        ]);
    }

    $filename = platformExportSanitizeFilename((string)$requested);
    if ($filename === '' || $filename === '.' || $filename === '..') {
        platformExportJson(422, [
            'status' => 'error',
            'error_code' => 'INVALID_FILENAME',
            'message' => 'Please give a file name',
        ]);
    }
    return $filename;
}

function platformExportRows($db, int $tprojectId): array
{
    $tables = tlObjectWithDB::getDBTables(['platforms']);
    $sql = 'SELECT name, notes, enable_on_design, enable_on_execution, is_open'
        . ' FROM ' . $tables['platforms']
        . ' WHERE testproject_id=' . $tprojectId;
    $result = $db->db->Execute($sql);
    if ($result === false) {
        platformExportJson(500, [
            'status' => 'error',
            'error_code' => 'EXPORT_QUERY_FAILED',
            'message' => 'Unable to export platforms',
        ]);
    }

    $rows = [];
    while (!$result->EOF) {
        $rows[] = $result->fields;
        $result->MoveNext();
    }
    return $rows;
}

function platformExportXmlValue($value): string
{
    $value = (string)$value;
    $value = preg_replace(
        '/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u',
        '',
        $value
    ) ?? '';
    return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function platformExportXml(array $rows): string
{
    $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<platforms>\n";
    foreach ($rows as $row) {
        $xml .= "  <platform>\n";
        $xml .= '    <name>' . platformExportXmlValue($row['name'] ?? '') . "</name>\n";
        $xml .= '    <notes>' . platformExportXmlValue($row['notes'] ?? '') . "</notes>\n";
        $xml .= '    <enable_on_design>' . (int)($row['enable_on_design'] ?? 0) . "</enable_on_design>\n";
        $xml .= '    <enable_on_execution>' . (int)($row['enable_on_execution'] ?? 0) . "</enable_on_execution>\n";
        $xml .= '    <is_open>' . (int)($row['is_open'] ?? 0) . "</is_open>\n";
        $xml .= "  </platform>\n";
    }
    return $xml . "</platforms>\n";
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'GET') {
    header('Allow: GET');
    platformExportJson(405, [
        'status' => 'error',
        'error_code' => 'METHOD_NOT_ALLOWED',
        'message' => 'Method not allowed',
    ]);
}

$userId = (int)($_SESSION['userID'] ?? 0);
if ($userId <= 0) {
    platformExportJson(401, [
        'status' => 'error',
        'error_code' => 'AUTH_REQUIRED',
        'message' => 'Authentication required',
    ]);
}

$user = tlUser::getByID($db, $userId);
if (!is_object($user)) {
    platformExportJson(401, [
        'status' => 'error',
        'error_code' => 'AUTH_REQUIRED',
        'message' => 'Authentication required',
    ]);
}

$action = (string)($_GET['action'] ?? 'init');
if ($action !== 'init' && $action !== 'download') {
    platformExportJson(400, [
        'status' => 'error',
        'error_code' => 'UNKNOWN_ACTION',
        'message' => 'Unknown action',
    ]);
}

$tprojectId = platformExportProjectId();
$project = platformExportProject($db, $tprojectId);
if (!platformExportAllowed($db, $user, $tprojectId)) {
    platformExportJson(403, [
        'status' => 'error',
        'error_code' => 'NO_PERMISSION',
        'message' => 'No permission',
    ]);
}

$projectName = (string)testproject::getName($db, $tprojectId);
$defaultFilename = platformExportFilename($projectName, null);

if ($action === 'init') {
    $rows = platformExportRows($db, $tprojectId);
    platformExportJson(200, [
        'status' => 'ok',
        'project' => [
            'id' => $tprojectId,
            'name' => $projectName,
        ],
        'format' => 'XML',
        'default_filename' => $defaultFilename,
        'platform_count' => count($rows),
        'documentation_url' => '/docs/tl-file-formats.pdf',
    ]);
}

$filename = platformExportFilename($projectName, $_GET['filename'] ?? null);
$content = platformExportXml(platformExportRows($db, $tprojectId));
$asciiFilename = preg_replace('/[^\x20-\x7E]/', '_', $filename) ?? 'platforms.xml';
$asciiFilename = str_replace(['\\', '"'], '_', $asciiFilename);
platformExportBeginResponse();
header('Content-Type: application/xml; charset=UTF-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . strlen($content));
header("Content-Disposition: attachment; filename=\"{$asciiFilename}\"; filename*=UTF-8''" . rawurlencode($filename));
echo $content;
