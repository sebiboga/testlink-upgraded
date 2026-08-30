<?php
/**
 * BFF API — Install / Upgrade check
 *
 * Reports the installation status of a running TestLink instance to the
 * modernized Dashio screen (gui/templates/install/installView.html).
 *
 * Mirrors the legacy check logic that used to live on install/index.php and
 * lib/functions/configCheck.php:
 *   - checkSchemaVersion()  -> schemaStatus + dbSchemaVersion + messages
 *   - checkForInstallDir()  -> is the installer still reachable (security note)
 *   - checkForAdminDefaultPwd() -> default admin password warning
 * The full install wizard (pre-DB, pre-session) intentionally stays legacy.
 *
 * Refs #797.
 */
require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
doSessionStart();

$userId = isset($_SESSION['userID']) ? $_SESSION['userID'] : 0;
if (!$userId || $userId <= 0) {
    http_response_code(401);
    echo json_encode(array('status' => 'error', 'message' => 'Not authenticated'));
    exit;
}

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function install_abs_path()
{
    return dirname(__FILE__) . '/../../';
}

function install_is_config_present()
{
    return is_file(install_abs_path() . 'config_db.inc.php');
}

function install_install_dir_present()
{
    clearstatcache();
    return is_dir(install_abs_path() . 'install');
}

function install_default_admin_pwd($db)
{
    if (!$db || !method_exists($db, 'exec_query')) {
        return null;
    }
    try {
        $user = new tlUser();
        $user->login = 'admin';
        if ($user->readFromDB($db, tlUser::USER_O_SEARCH_BYLOGIN) >= tl::OK) {
            return ($user->comparePassword($db, 'admin') >= tl::OK);
        }
    } catch (Exception $e) {
        return null;
    }
    return false;
}

function install_schema_status($db, &$dbSchemaVersion, &$schemaMsg)
{
    $latest = defined('TL_LATEST_DB_VERSION') ? TL_LATEST_DB_VERSION : 'DB 2.0.0';
    $dbSchemaVersion = null;
    $schemaMsg = null;
    $empty = array('status' => 'undetermined');
    if (!$db || !method_exists($db, 'exec_query')) {
        return $empty;
    }
    $table = (defined('DB_TABLE_PREFIX') ? DB_TABLE_PREFIX : '') . 'db_version';
    $res = @$db->exec_query("SELECT * FROM {$table} ORDER BY upgrade_ts DESC", 1);
    if (!$res) {
        return $empty;
    }
    $row = $db->fetch_array($res);
    if (!$row) {
        return $empty;
    }
    $version = trim($row['version']);
    $dbSchemaVersion = $version;

    $manualVersions = array(
        'DB 1.3', 'DB 1.4', 'DB 1.5', 'DB 1.6', 'DB 1.9.8',
        'DB 1.9.10', 'DB 1.9.11', 'DB 1.9.12', 'DB 1.9.13',
        'DB 1.9.14', 'DB 1.9.15', 'DB 1.9.16', 'DB 1.9.17',
        'DB 1.9.18', 'DB 1.9.19',
    );
    if (in_array($version, $manualVersions)) {
        return array('status' => 'manual', 'msg' => 'manual');
    }
    if ($version == 'DB 1.9.20') {
        $m = $db->db->metaColumns(DB_TABLE_PREFIX . 'users');
        if (isset($m['PASSWORD']) && $m['PASSWORD']->max_length == 32) {
            return array('status' => 'upgrade', 'msg' => 'partial_migration');
        }
        return array('status' => 'ok');
    }
    if ($version == $latest) {
        return array('status' => 'ok');
    }
    if (in_array($version,
            array('1.7.0 Alpha', '1.7.0 Beta 1', '1.7.0 Beta 2', '1.7.0 Beta 3',
                  '1.7.0 Beta 4', '1.7.0 Beta 5', '1.7.0 RC 2', '1.7.0 RC 3',
                  'DB 1.1', 'DB 1.2'))) {
        return array('status' => 'upgrade', 'msg' => 'upgrade');
    }
    return array('status' => 'unknown', 'msg' => 'unknown', 'version' => $version);
}

$db = null;
$dbReachable = false;
try {
    doDBConnect($db);
    $dbReachable = true;
} catch (Exception $e) {
    $db = null;
    $dbReachable = false;
}

$schema = install_schema_status($db, $dbSchemaVersion, $schemaMsg);

$securityNotes = array();
$securityCodes = array();
if (install_install_dir_present()) {
    $securityNotes[] = lang_get('sec_note_remove_install_dir');
    $securityCodes[] = 'install_dir';
}
$authCfg = config_get('authentication');
if (isset($authCfg['method']) && $authCfg['method'] == 'LDAP') {
    if (!extension_loaded('ldap')) {
        $securityNotes[] = lang_get('ldap_extension_not_loaded');
        $securityCodes[] = 'ldap';
    }
} else {
    $dflt = install_default_admin_pwd($db);
    if ($dflt === true) {
        $securityNotes[] = lang_get('sec_note_admin_default_pwd');
        $securityCodes[] = 'admin_pwd';
    }
}

$appVersion = defined('TL_VERSION') ? TL_VERSION : '2.0.1';
$latestDb = defined('TL_LATEST_DB_VERSION') ? TL_LATEST_DB_VERSION : 'DB 2.0.0';

$configPresent = install_is_config_present();

// GD / extensions used by reports (mirrors installCheck checks)
$gdOk = (extension_loaded('gd') && function_exists('imagepng'));

echo json_encode(array(
    'status' => 'ok',
    'installed' => ($configPresent && $dbReachable && $schema['status'] == 'ok'),
    'configPresent' => $configPresent,
    'dbReachable' => $dbReachable,
    'appVersion' => $appVersion,
    'latestDbVersion' => $latestDb,
    'dbSchemaVersion' => $dbSchemaVersion,
    'schemaStatus' => $schema['status'],
    'schemaMsg' => $schema['msg'],
    'schemaVersion' => isset($schema['version']) ? $schema['version'] : null,
    'installDirPresent' => install_install_dir_present(),
    'securityNotes' => $securityNotes,
    'securityCodes' => $securityCodes,
    'gdOk' => $gdOk,
    'whoami' => isset($_SESSION['userID']) ? intval($_SESSION['userID']) : 0,
    'links' => array(
        'installer' => '/install/index.php',
        'manual'    => '/docs/testlink_installation_manual.pdf',
        'readme'    => '/README',
        'changelog' => '/CHANGELOG',
    ),
));