<?php
/**
 * api/configcheck — Configuration Check / Security Notes BFF (Refs #1814)
 *
 * Surfaces the config-check security notes that legacy computed on EVERY page
 * load (lib/functions/configCheck.php getSecurityNotes(), consumed by
 * initUserEnv() at lib/functions/common.php:1858 and by login.php:230) but that
 * NOTHING rendered in 2.0.1: the only template that ever showed
 * $gui->securityNotes was gui/templates/tl-classic/mainPage.tpl:83, whose
 * controller no longer exists (the dashboard is now gui/templates/mainpage/
 * mainPage.html, which never fetched them).
 *
 * Routes (read-only endpoint):
 *   GET|HEAD ?action=init -> JSON payload for
 *       gui/templates/conf/configCheck.html and for the Home banner of
 *       gui/templates/mainpage/mainPage.html
 *
 * Deliberate difference from getSecurityNotes(): the legacy helper DROPS the
 * notes when config_check_warning_mode is FILE (it writes them to
 * log_path/config_check.txt and returns null) or SILENT - which is exactly why
 * issue #1814 could say "nothing displays the result". This endpoint always
 * returns the full note list AND reports the configured mode + destination file
 * so the screen can render both. The check COLLECTION is byte-for-byte the
 * legacy order (install dir -> auth -> BTS -> repository -> schema -> email ->
 * extensions), calling the SAME legacy helpers, so the notes can never drift
 * from what legacy would have logged.
 *
 * Authorization: a valid session only - legacy parity. The notes were rendered
 * on the dashboard of EVERY authenticated user (role 3 included) in 1.9.20, and
 * they carry no test-project data at all (server config only), so introducing a
 * right here would hide them from exactly the users who need to see them.
 *
 * Session-based auth, JSON I/O. No Smarty.
 */
require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');

// This endpoint never writes: the verb check runs BEFORE bffSameOriginGuard()
// so a plain POST gets the documented 405 + Allow instead of the guard's 403
// (same ordering rationale as api/execnotesreadonly).
cc_require_safe_verb();

bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');

/**
 * Refuse every write verb explicitly (the endpoint has no write path).
 */
function cc_require_safe_verb() {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method !== 'GET' && $method !== 'HEAD') {
        header('Allow: GET, HEAD');
        http_response_code(405);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        echo json_encode(array(
            'status' => 'error',
            'code' => 'method_not_allowed',
            'message' => 'This endpoint is read-only; use GET',
        ));
        exit;
    }
}

/**
 * Stable machine-coded JSON failure. The modern screen keys its state cards off
 * `code`, never off the English message.
 */
function cc_fail($status, $code, $message) {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        http_response_code($status);
    }
    echo json_encode(array(
        'status' => 'error',
        'code' => $code,
        'message' => $message,
    ));
    exit;
}

// is_scalar() first: `?action[]=init` would otherwise reach a (string) cast,
// which emits an "Array to string conversion" E_WARNING that watchPHPErrors()
// writes into the EVENTS TABLE - an anonymous caller could poison the Event
// Viewer. A non-scalar action is refused outright (400), never normalised.
$actionArg = $_GET['action'] ?? 'init';
if (!is_scalar($actionArg)) {
    cc_fail(400, 'unknown_action', 'Unknown action');
}
$action = trim((string)$actionArg);
if ($action === '') {
    $action = 'init';
}
if ($action !== 'init') {
    cc_fail(400, 'unknown_action', 'Unknown action');
}

// Session gate BEFORE the database connect (the #1677 lesson): a down database
// must never answer an anonymous caller with a raw dbms_msg.
$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    cc_fail(401, 'not_authenticated', 'Not authenticated');
}

$db = new database(DB_TYPE);
// doDBConnect() never throws and echoes its raw dbms_msg on failure - capture
// and discard that echo so an authenticated caller never receives DB host/name
// bytes inside the JSON body (doDBConnect() already tLog()s the failure, so the
// event trail is preserved). Same inspect-the-result rationale as api/install.
ob_start();
$dbConn = doDBConnect($db);
ob_end_clean();
if (!is_array($dbConn) || empty($dbConn['status'])) {
    cc_fail(500, 'db_unavailable', 'Database unavailable');
}

$user = tlUser::getByID($db, intval($userId));
if (is_null($user)) {
    cc_fail(401, 'not_authenticated', 'Not authenticated');
}

// Enforces the legacy session inactivity window (same note as every other BFF).
if (function_exists('bffEnforceSession')) {
    bffEnforceSession($db);
}

// The modern screen picks a language through TLi18n ('ro'), independent of
// $_SESSION['locale'] (which drives the Smarty pages) - without this map the
// note texts would come back in the session language while the chrome renders
// in the client one. Same pattern as api/cfields::assignLocale().
$clientLang = null;
$localeArg = preg_replace('/[^a-z]/', '', strtolower((string)($_GET['locale'] ?? '')));
if ($localeArg !== '' && strlen($localeArg) === 2) {
    foreach (array_keys((array) config_get('locales')) as $code) {
        if (strpos(strtolower($code), $localeArg) === 0) {
            $clientLang = $code;
            break;
        }
    }
}

// ---------------------------------------------------------------------------
// Collect the notes in the exact order of getSecurityNotes()
// (lib/functions/configCheck.php:251-305), reusing its helpers so a note can
// never disagree with what legacy logs. The ONLY skipped step is the mode gate
// that nulls the notes for FILE/SILENT - that gate is the bug this screen fixes.
// ---------------------------------------------------------------------------
$notes = array();

try {
    if (checkForInstallDir()) {
        $notes[] = array('code' => 'install_dir', 'text' => lang_get('sec_note_remove_install_dir', $clientLang));
    }

    $authCfg = config_get('authentication');
    $method = (is_array($authCfg) && isset($authCfg['method'])) ? $authCfg['method'] : '';
    if ($method === 'LDAP') {
        if (!checkForLDAPExtension()) {
            $notes[] = array('code' => 'ldap', 'text' => lang_get('ldap_extension_not_loaded', $clientLang));
        }
    } else {
        if (checkForAdminDefaultPwd($db)) {
            $notes[] = array('code' => 'admin_pwd', 'text' => lang_get('sec_note_admin_default_pwd', $clientLang));
        }
    }

    if (!checkForBTSConnection($db)) {
        $notes[] = array('code' => 'bts_connection', 'text' => lang_get('bts_connection_problems', $clientLang));
    }

    // `==` on purpose: TL_REPOSITORY_TYPE_FS is an int constant and a
    // hand-edited custom_config can carry the numeric string; the strict
    // compare would silently drop the repository_dir note from the one screen
    // whose job is to render it (same loose compare as the legacy helper).
    if (defined('TL_REPOSITORY_TYPE_FS') && config_get('repositoryType') == TL_REPOSITORY_TYPE_FS) {
        $repo = checkForRepositoryDir(config_get('repositoryPath'));
        if (!is_array($repo) || !isset($repo['status_ok']) || !$repo['status_ok']) {
            // The helper's own message is already localized (it sprintf()s the
            // path into lang_get() text) - exactly what legacy showed. No
            // invented fallback key: an unknown lang key would log an L18N
            // warning row into the Event Viewer on every hit.
            if (is_array($repo) && isset($repo['msg']) && $repo['msg'] !== '') {
                $notes[] = array('code' => 'repository_dir', 'text' => $repo['msg']);
            }
        }
    }

    $schema = checkSchemaVersion($db);
    if (is_array($schema) && isset($schema['msg']) && $schema['msg'] !== '') {
        $notes[] = array('code' => 'schema', 'text' => $schema['msg']);
    }

    $emailMsgs = checkEmailConfig();
    if (!is_null($emailMsgs)) {
        foreach ((array)$emailMsgs as $detail) {
            $notes[] = array('code' => 'email_config', 'text' => (string)$detail);
        }
    }

    // Appends its own localized messages (strings or arrays without a code); if
    // it (or an earlier helper) throws, the catch below keeps the notes already
    // collected - the normalization loop runs on the final payload either way.
    checkForExtensions($notes);
} catch (\Throwable $e) {
    // A fatal inside one check must not corrupt the JSON contract (same
    // fail-safe as api/install): report what was collected so far.
    tLog('api/configcheck: check aborted: ' . $e->getMessage(), 'ERROR');
}

// Normalize whatever checkForExtensions() appended - on the happy path AND on
// the exception path, so a raw string element can never reach the JSON (the
// screen reads n.code/n.text). Without this a partial append after a throw
// would render an empty row while still counting it.
foreach ($notes as $k => $n) {
    if (!is_array($n)) {
        $notes[$k] = array('code' => 'extensions', 'text' => (string)$n);
    } elseif (!isset($n['code'])) {
        $notes[$k]['code'] = 'extensions';
    }
}

$mode = (string)config_get('config_check_warning_mode');
// Legacy builds the name with no separator (config_check_warning_mode FILE and
// SILENT share the write inside configCheck.php:320-331); keep the byte-identical
// expression so the screen shows the same path the file actually lands on.
$filename = config_get('log_path') . 'config_check.txt';
// config_check.txt is maintained for BOTH FILE and SILENT (for SILENT it is the
// only artifact left by the mode gate that nulls the notes), so the screen
// promises the destination file in exactly those two modes and in no other.
$fileTarget = ($mode === 'FILE' || $mode === 'SILENT') ? $filename : null;

echo json_encode(array(
    'status' => 'ok',
    'notes' => $notes,
    'count' => count($notes),
    'mode' => $mode,
    'file' => $fileTarget,
    'appVersion' => defined('TL_VERSION') ? TL_VERSION : '2.0.1',
    'user_id' => intval($userId),
    // Traceability: which legacy function this endpoint mirrors.
    'legacy_function' => 'getSecurityNotes',
), JSON_INVALID_UTF8_SUBSTITUTE);
