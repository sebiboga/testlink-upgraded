<?php
/**
 * Launch TestCase Print gateway BFF  (legacy `ltcp.php`)
 * URL: /api/tcprintlaunch/
 * Plain PHP, no framework, no compilation.
 *
 * Modern replacement for the resolver half of the legacy public entry point
 * `ltcp.php` (Refs #1623). A share link
 *
 *     ltcp.php?apikey=<32-char user API key>&testcase=<PREFIX>-<NUM>-<VERSION>
 *
 * used to: authenticate by user API key (setUpEnvForRemoteAccess), resolve the
 * test case external id + version through the testproject prefix, check
 * `mgt_view_tc` on the owning project, then 302-redirect into the STILL-LEGACY
 * `lib/testcases/tcPrint.php` renderer.
 *
 * This BFF performs the identical resolution and answers JSON so the modern
 * screen `gui/templates/testcases/tcLaunchPrint.html` can show a localized
 * result card and hand over to the already-modern print screen
 * `gui/templates/testcases/tcPrint.html` (?action=tc_print) - no legacy
 * `lib/**.php` page is rendered any more.
 *
 * Legacy error-code parity (`$commonText` in ltcp.php is rendered by the
 * screen as the localized hint, `code` is the machine-readable equivalent):
 *
 *   legacy  | condition                                   | here
 *   --------+---------------------------------------------+--------------------------
 *   LTCP-01 | apikey length != 32 / key not resolvable     | 400 bad_apikey / 403 bad_apikey
 *   LTCP-02 | `testcase` is not PREFIX-NUM-VERSION        | 400 bad_external_id
 *   LTCP-03 | prefix is not a known test project          | 404 unknown_prefix
 *   LTCP-04 | no `mgt_view_tc` on the owning project      | 403 no_rights
 *   LTCP-05 | external id does not resolve to a test case | 404 testcase_not_found
 *   (die)   | version missing in the test case id set     | 404 version_not_found
 *   OK      | all checks pass                             | 200 + print_url
 *
 * Authorization:
 *   - `apikey` (32 chars) -> remote access for the owning user, parity with
 *     ltcp.php setUpEnvForRemoteAccess(). The session is established for that
 *     user so the follow-up tcPrint.html navigation (which is session-based by
 *     design, see api/testcasesprint) is authenticated. The legacy
 *     `clearSession => true` is intentionally NOT reproduced: instead the
 *     identity of the existing session is switched to the apikey owner, which
 *     authorizes the same documents without logging an unrelated user out.
 *   - no `apikey` + an authenticated session -> the session user is the
 *     principal (convenience superset of the legacy apikey-only contract, used
 *     when a logged-in user opens the screen from the app).
 *
 * Contract:
 *   GET ?action=resolve&apikey=K&testcase=P-N-V
 *     -> 200 { status:'ok', testcase:{...}, print_url, legacy_code:null }
 *     -> 400/403/404 { status:'error', code, legacy_code, message }
 *     -> 405 non-GET.
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
require_once(__DIR__ . '/../_guard.php');

doDBConnect($db);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

const TCPRINTLAUNCH_USER_APIKEY_LEN = 32;

function out($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function fail($code, $httpCode, $legacyCode, $message) {
    out(array('status' => 'error', 'code' => $code,
              'legacy_code' => $legacyCode, 'message' => $message), $httpCode);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    fail('method_not_allowed', 405, null, 'Only GET is accepted');
}

$action = trim(strval($_GET['action'] ?? ''));
if ($action !== 'resolve') {
    fail('unknown_action', 400, null, 'Unknown or missing action');
}

// ---- parameter scan (ltcp.php R_PARAMS parity) -----------------------------
$apikey = trim(strval($_GET['apikey'] ?? ''));
$testcase = trim(strval($_GET['testcase'] ?? ''));

// ---- authorization ---------------------------------------------------------
// config.inc.php / common.php never start the session on their own, so without
// this the $_SESSION read below is always empty and the documented session
// branch would 401 a logged-in user. Same call as api/executionprint (line 47)
// and api/testcasesprint (line 60); safe here because nothing has been echoed.
doSessionStart();

$user = null;
$authMode = 'session';
$sessionUserId = isset($_SESSION['userID']) ? intval($_SESSION['userID']) : 0;

if ($apikey !== '') {
    if (strlen($apikey) !== TCPRINTLAUNCH_USER_APIKEY_LEN) {
        fail('bad_apikey', 400, 'LTCP-01', 'Aborting - Bad API Key length');
    }
    // Probe only: setUpEnvForRemoteAccess() internally does count($user) on
    // tlUser::getByAPIKey() which returns null on a no-match -> PHP 8
    // count(null) TypeError (a fake 32-char key would 500). Same guard as
    // api/publiclink and api/executionprint.
    $apiUsers = tlUser::getByAPIKey($db, $apikey);
    if (!is_array($apiUsers) || count($apiUsers) !== 1) {
        fail('bad_apikey', 403, 'LTCP-01', 'Aborting - Bad API Key');
    }
    $auRow = reset($apiUsers);
    $user = tlUser::getByID($db, intval($auRow['id'] ?? 0));
    if (is_null($user)) {
        fail('bad_apikey', 403, 'LTCP-01', 'Aborting - Bad API Key');
    }
    $authMode = 'apikey';
    // Establish the remote-access session for the apikey owner (legacy
    // ltcp.php did this before its redirect), without wiping an existing
    // session. The browser keeps the cookie, so the tcPrint.html hand-over is
    // authenticated as the same user the link was issued for.
    $opt = array('setPaths' => true, 'clearSession' => false);
    setUpEnvForRemoteAccess($db, $apikey, null, $opt);
    // The session identity just changed to the link owner. Re-issue the session
    // id so a pre-existing (or fixated) cookie cannot be reused against the new
    // identity; without this every other key of the caller's session would
    // survive and their next action would be attributed to the link owner.
    if (PHP_SESSION_ACTIVE !== session_status()) {
        session_regenerate_id(true);
    }
} else {
    if ($sessionUserId <= 0) {
        fail('unauthenticated', 401, null, 'Not authenticated');
    }
    $user = tlUser::getByID($db, $sessionUserId);
    if (is_null($user)) {
        fail('unauthenticated', 401, null, 'Not authenticated');
    }
}

// Inactivity window (#1614) - only meaningful for the session branch, but
// harmless for apikey, which just established its own fresh session.
bffEnforceSession($db);

// ltcp.php validated the apikey BEFORE the testcase parameter, so a request
// with both a bad key and a missing test case reported LTCP-01. Keep that
// order here.
if ($testcase === '') {
    fail('missing_testcase', 400, 'LTCP-02', 'Missing testcase parameter');
}

// ---- prefix -> test project (ltcp.php parity, mitigated SQL injection) -----
$tbl = DB_TABLE_PREFIX . 'testprojects';
$rs = $db->fetchRowsIntoMap("SELECT prefix,id FROM $tbl ", 'prefix');
if (!is_array($rs)) {
    $rs = array();
}

$testCasePieces = explode('-', $testcase);
if (count($testCasePieces) !== 3) {
    fail('bad_external_id', 400, 'LTCP-02',
         'Test case must be PREFIX-NUMBER-VERSION');
}
$prjPrefix = trim($testCasePieces[0]);
if (!isset($rs[$prjPrefix])) {
    fail('unknown_prefix', 404, 'LTCP-03', 'Unknown test project prefix');
}
$tproject_id = intval($rs[$prjPrefix]['id']);

$canRead = $user->hasRight($db, 'mgt_view_tc', $tproject_id, null, true);
if ($canRead == false) {
    fail('no_rights', 403, 'LTCP-04',
         'System checks do not allow the operation requested');
}

// ---- test case + version resolution ---------------------------------------
$externalID = $testCasePieces[0] . '-' . $testCasePieces[1];
$tcaseMgr = new testcase($db);
$testcase_id = intval($tcaseMgr->getInternalID($externalID));
$allTCVID = ($testcase_id > 0) ? $tcaseMgr->getAllVersionsID($testcase_id) : null;
if (!is_array($allTCVID) || count($allTCVID) == 0) {
    fail('testcase_not_found', 404, 'LTCP-05', 'Test case not found');
}
$idSet = implode(',', array_map('intval', $allTCVID));
$tcaseVersionNumber = intval($testCasePieces[2]);
$tbl = DB_TABLE_PREFIX . 'tcversions';
$sql = " SELECT version,id FROM $tbl
         WHERE id IN ($idSet)
         AND version = $tcaseVersionNumber";
$vs = (array)$db->fetchRowsIntoMap($sql, 'version');
if (count($vs) !== 1) {
    fail('version_not_found', 404, null, 'Test case version not found');
}
$tcversion_id = intval($vs[$tcaseVersionNumber]['id']);

// ---- payload ---------------------------------------------------------------
$tproject = new testproject($db);
$tprojRow = $tproject->get_by_prefix($prjPrefix);
$tprojectName = (!is_null($tprojRow) && isset($tprojRow['name']))
    ? strval($tprojRow['name']) : '';

// Test case / test project names live in nodes_hierarchy, not in testprojects.
$tcName = '';
$treeMgr = new tree($db);
$tcNode = $treeMgr->get_node_hierarchy_info($testcase_id);
if (!is_null($tcNode) && isset($tcNode['name'])) {
    $tcName = strval($tcNode['name']);
}
$suiteName = '';
if (!is_null($tcNode) && isset($tcNode['parent_id']) && intval($tcNode['parent_id']) > 0) {
    $suiteNode = $treeMgr->get_node_hierarchy_info(intval($tcNode['parent_id']));
    if (!is_null($suiteNode) && isset($suiteNode['name'])) {
        $suiteName = strval($suiteNode['name']);
    }
}

$availableVersions = array();
// One query for the whole id set - the version numbers are already known from
// the $vs lookup above, so no per-version round-trip is needed.
$verRows = $db->get_recordset("SELECT id,version FROM " . DB_TABLE_PREFIX .
                              "tcversions WHERE id IN ($idSet) ORDER BY version");
if (!is_null($verRows)) {
    foreach ($verRows as $vr) {
        $availableVersions[] = array('tcversion_id' => intval($vr['id']),
                                     'version' => intval($vr['version']));
    }
}

$baseHref = defined('TL_BASE_HREF') ? strval(TL_BASE_HREF) : '';
if ($baseHref === '' && isset($_SESSION['basehref'])) {
    $baseHref = strval($_SESSION['basehref']);
}
$baseHref = rtrim($baseHref, '/');
// print_url is a window.location.href sink in the screen, so it must stay
// same-origin. TL_BASE_HREF comes from get_home_url(), which prefers
// HTTP_X_FORWARDED_HOST over HTTP_HOST - behind a proxy that forwards a
// client-supplied header this would turn "Open test case print" into an
// off-site redirect. Fall back to a relative URL on any host mismatch.
if ($baseHref !== '') {
    $bHost = strval(parse_url($baseHref, PHP_URL_HOST));
    $bPort = parse_url($baseHref, PHP_URL_PORT);
    $reqHost = isset($_SERVER['HTTP_HOST']) ? strval($_SERVER['HTTP_HOST']) : '';
    // HTTP_HOST carries the port when it is not the scheme default, so compare
    // against the same shape - otherwise a legitimate :8082 install looks like
    // a mismatch and loses its sub-directory path prefix.
    $bAuthority = strtolower($bHost) . (is_null($bPort) ? '' : ':' . intval($bPort));
    $reqAuthority = strtolower($reqHost);
    $reqPort = parse_url($baseHref, PHP_URL_SCHEME) === 'https' ? 443 : 80;
    if ($reqAuthority === '' || ($bAuthority !== $reqAuthority &&
                                $reqAuthority !== $bAuthority . ':' . $reqPort)) {
        $baseHref = '';
    }
}
$printUrl = $baseHref . '/gui/templates/testcases/tcPrint.html'
          . '?testcase_id=' . $testcase_id
          . '&tcversion_id=' . $tcversion_id
          . '&tproject_id=' . $tproject_id;

out(array(
    'status' => 'ok',
    'legacy_code' => null,
    'auth_mode' => $authMode,
    'testcase' => array(
        'testcase_id' => $testcase_id,
        'tcversion_id' => $tcversion_id,
        'external_id' => $externalID,
        'version' => $tcaseVersionNumber,
        'name' => $tcName,
        'suite' => $suiteName,
        'tproject_id' => $tproject_id,
        'tproject_name' => $tprojectName,
        'prefix' => $prjPrefix,
        'available_versions' => $availableVersions,
    ),
    'print_url' => $printUrl,
));
