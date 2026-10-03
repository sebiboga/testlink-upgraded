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
 *   - checkForRepositoryDir() -> FS attachments repository dir exists + writable
 *     warning (configCheck.php:368-390, called from getSecurityNotes() at
 *     configCheck.php:275-282) — ported in #1283
 *   - install_community_videos() -> the curated YouTube walkthroughs that the
 *     legacy landing page hardcoded in install/index.php:52-58 (#1286)
 *   - links.forum -> the community forum legacy offered twice on the same
 *     landing page (install/index.php:45 and :49-50) (#1285)
 * The full install wizard (pre-DB, pre-session) intentionally stays legacy.
 *
 * Refs #797, #1286, #1285, #1283.
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

/**
 * Curated walkthrough videos contributed by TestLink users.
 *
 * Legacy source: install/index.php:52-58 ("Some user contributed videos (You Tube)").
 * They are served from the BFF (not hardcoded in the HTML) so the list has a single
 * source of truth and can be localized on the client like every other label.
 * `key` is the i18n key of the caption; `url` is always an absolute https link.
 */
function install_community_videos()
{
    return array(
        array('id' => 'NOvTWZvc2x8', 'key' => 'install.videoInstallProject',
              'url' => 'https://www.youtube.com/watch?v=NOvTWZvc2x8'),
        array('id' => 'P2zWScVjuag', 'key' => 'install.videoTestManagementTool',
              'url' => 'https://www.youtube.com/watch?v=P2zWScVjuag'),
        array('id' => '7xH1LKQU1TA', 'key' => 'install.videoIntroduction',
              'url' => 'https://www.youtube.com/watch?v=7xH1LKQU1TA'),
        array('id' => '6s48WGuX2WE', 'key' => 'install.videoWalkthrough',
              'url' => 'https://www.youtube.com/watch?v=6s48WGuX2WE'),
    );
}

/**
 * Filesystem (FS) attachments repository check.
 *
 * Legacy source: lib/functions/configCheck.php:368-390 (checkForRepositoryDir),
 * driven by getSecurityNotes() at :275-282 — only when repositoryType ==
 * TL_REPOSITORY_TYPE_FS. Message wording and the is_dir()/is_writable() logic
 * are kept identical to legacy so server-side consumers see the same text.
 *
 * @return array {msg, status_ok, exists, writable}
 */
function install_check_repository_dir($the_dir)
{
    clearstatcache();

    $ret['msg'] = lang_get('attachments_dir') . " " . $the_dir . " ";
    $ret['status_ok'] = false;
    $ret['exists'] = false;
    $ret['writable'] = false;

    if (is_dir($the_dir)) {
        $ret['exists'] = true;
        $ret['msg'] .= lang_get('exists') . ' ';
        $ret['status_ok'] = (is_writable($the_dir)) ? true : false;
        $ret['writable'] = $ret['status_ok'];

        if ($ret['status_ok']) {
            $ret['msg'] .= lang_get('directory_is_writable');
        } else {
            $ret['msg'] .= lang_get('but_directory_is_not_writable');
        }
    } else {
        $ret['msg'] .= lang_get('does_not_exist');
    }

    return $ret;
}

function install_check_email_config()
{
    $common[] = lang_get('check_email_config');
    $msg = null;
    $idx = 1;
    $key2get = array('tl_admin_email', 'from_email', 'return_path_email', 'smtp_host');

    foreach ($key2get as $cfg_key) {
        $cfg_param = config_get($cfg_key);
        if (trim($cfg_param) == '' || strpos($cfg_param, 'not_configured') > 0) {
            $msg[$idx++] = $cfg_key;
        }
    }
    return is_null($msg) ? null : array_merge($common, array_slice($msg, 0));
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
if (defined('DB_TYPE')) {
    // doDBConnect() never throws: it returns a result array with 'status',
    // so inspect it instead of relying on an exception.
    $dbConn = doDBConnect($db);
    $dbReachable = (is_array($dbConn) && !empty($dbConn['status']));
}

$schema = array('status' => 'undetermined');
$dbSchemaVersion = null;
$schemaMsg = null;
if ($dbReachable) {
    $schema = install_schema_status($db, $dbSchemaVersion, $schemaMsg);
}

$securityNotes = array();
$securityCodes = array();
// Parallel to $securityNotes: per-note localization hint ({code, key, params}).
// A null entry means "no client-side key for this note" -> the front-end falls
// back to the server-rendered string, so existing consumers are unaffected.
$securityNoteItems = array();
if (install_install_dir_present()) {
    $securityNotes[] = lang_get('sec_note_remove_install_dir');
    $securityCodes[] = 'install_dir';
    $securityNoteItems[] = null;
}
$authCfg = config_get('authentication');
if (isset($authCfg['method']) && $authCfg['method'] == 'LDAP') {
    if (!extension_loaded('ldap')) {
        $securityNotes[] = lang_get('ldap_extension_not_loaded');
        $securityCodes[] = 'ldap';
        $securityNoteItems[] = null;
    }
} elseif ($dbReachable) {
    $dflt = install_default_admin_pwd($db);
    if ($dflt === true) {
        $securityNotes[] = lang_get('sec_note_admin_default_pwd');
        $securityCodes[] = 'admin_pwd';
        $securityNoteItems[] = null;
    }
}

// Email configuration security check
$emailMsgs = install_check_email_config();
if (!is_null($emailMsgs)) {
    foreach ($emailMsgs as $detail) {
        $securityNotes[] = $detail;
        $securityNoteItems[] = null;
    }
}

/**
 * Attachments repository check (legacy getSecurityNotes(), configCheck.php:275-282).
 * Only performed for the filesystem repository type, exactly like legacy.
 */
$repositoryType = config_get('repositoryType');
$repositoryPath = config_get('repositoryPath');
$repositoryDir = array(
    'type'      => $repositoryType,
    'typeCode'  => (defined('TL_REPOSITORY_TYPE_DB') && $repositoryType == TL_REPOSITORY_TYPE_DB) ? 'db' : 'fs',
    'path'      => $repositoryPath,
    'checked'   => false,
    'status_ok' => null,
    'exists'    => null,
    'writable'  => null,
    'msg'       => null,
);
if (defined('TL_REPOSITORY_TYPE_FS') && $repositoryType == TL_REPOSITORY_TYPE_FS) {
    $repositoryDir['checked'] = true;
    $repoCheck = install_check_repository_dir($repositoryPath);
    $repositoryDir['status_ok'] = (bool) $repoCheck['status_ok'];
    $repositoryDir['exists'] = (bool) $repoCheck['exists'];
    $repositoryDir['writable'] = (bool) $repoCheck['writable'];
    $repositoryDir['msg'] = $repoCheck['msg'];

    if (!$repositoryDir['status_ok']) {
        $securityNotes[] = $repoCheck['msg'];
        $securityCodes[] = 'repository_dir';
        $securityNoteItems[] = array(
            'code' => 'repository_dir',
            'key'  => $repositoryDir['exists'] ? 'install.repoDirNotWritable' : 'install.repoDirMissing',
            'params' => array('path' => $repositoryPath),
        );
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
    'schemaMsg' => isset($schema['msg']) ? $schema['msg'] : null,
    'schemaVersion' => isset($schema['version']) ? $schema['version'] : null,
    'installDirPresent' => install_install_dir_present(),
    'securityNotes' => $securityNotes,
    'securityCodes' => $securityCodes,
    'securityNoteItems' => $securityNoteItems,
    'repository' => $repositoryDir,
    'gdOk' => $gdOk,
    'whoami' => isset($_SESSION['userID']) ? intval($_SESSION['userID']) : 0,
    'links' => array(
        'installer' => '/install/index.php',
        'manual'    => '/docs/testlink_installation_manual.pdf',
        'readme'    => '/README.md',
        'changelog' => '/CHANGELOG',
        // Legacy install/index.php:25 ($forum_url) offered the community forum
        // twice: inline in the migration notice (:45) and next to the manual /
        // README / CHANGELOG links (:49-50). Served from the BFF so the URL is
        // not hardcoded in the front-end.
        'forum'     => 'http://forum.testlink.org',
    ),
    'videos' => install_community_videos(),
));