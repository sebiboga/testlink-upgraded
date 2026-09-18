<?php
/**
 * Public Share-Link Gateway BFF API
 * URL: /api/publiclink/
 * Plain PHP, no framework, no compilation
 *
 * Resolves the legacy public share links emitted by the modern UI
 * (execution print share, attachment share, metrics dashboard share) into a
 * MODERN target URL. Refs #1541.
 *
 * The gateway screen gui/templates/links/publicLink.html calls this resolver
 * (GET ?action=resolve) then redirects to the returned target. The legacy
 * entry `lnl.php` now just forwards every share link here, exactly like the
 * deep-link gateway linkto.php forwards to api/directlink (Refs #1532).
 *
 * Authorization mirrors the legacy lnl.php init_args() flow:
 *   - 32-char apikey -> remote access for the owning user
 *     (setUpEnvForRemoteAccess + tlUser::getByAPIKey)
 *   - 64-char apikey -> anonymous/public access for the connected entity
 *     (setUpEnvForAnonymousAccess, addOpAccess=false). For type=exec the key
 *     is swapped for the owning test plan's api_key (legacy parity).
 *
 * Type mapping to MODERN targets:
 *   exec              -> /gui/templates/execute/execPrint.html?id=&apikey=
 *                        (api/executionprint serves anonymous public links)
 *   file              -> /api/attachments/index.php?action=download&id=&apikey=
 *   metricsdashboard  -> /gui/templates/results/metricsDashboard.html?apikey=
 *   test_plan / test_report / testreport_onbuild
 *                    -> /gui/templates/results/reportPrint.html (reportPublicUrl
 *                       parity with the legacy lnl.php option flags)
 *   testspec, metrics_tp_general, list_tc_*, results_matrix,
 *   results_by_tester_per_build, charts_basic, abslatest_results_matrix,
 *   report_exec_timeline and the accessWithoutLogin types
 *                    -> the legacy reports.cfg.php url with apikey + params
 *                       (unchanged behavior; the report BFF apikey allowlist
 *                       covers results_flat/metrics_general/charts_data/
 *                       exec_timeline - filed as a follow-up for the rest)
 *
 * Contract:
 *   GET ?action=resolve&type=exec&id=N&apikey=K[&entities=][&format=]
 *     -> 200 { status:'ok', type, id, apikey_length, target, href }
 *     -> 200 { status:'error', code, message }   (invalid link / unknown type)
 *     -> 400 malformed params, 405 non-GET.
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
require_once(__DIR__ . '/../../cfg/reports.cfg.php');

doDBConnect($db);

header('Content-Type: application/json; charset=utf-8');

function publicLinkOut($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET') {
    publicLinkOut(['status' => 'error', 'code' => 'method_not_allowed'], 405);
}

$action = trim(strval($_GET['action'] ?? ''));
if ($action !== 'resolve') {
    publicLinkOut(['status' => 'error', 'code' => 'unknown_action',
                   'message' => 'Unknown or missing action'], 400);
}

// ---- parameter scan (legacy lnl.php init_args parity) ----------------------
$type = trim(strval($_GET['type'] ?? ''));
$id = intval($_GET['id'] ?? 0);
$apikey = trim(strval($_GET['apikey'] ?? ''));
$format = intval($_GET['format'] ?? 0);
$format = ($format <= 0) ? FORMAT_HTML : $format;
$entities = intval($_GET['entities'] ?? 0);

$userAPIkeyLen = 32;
$objectAPIkeyLen = 64;
$akl = strlen($apikey);
if ($akl !== $userAPIkeyLen && $akl !== $objectAPIkeyLen) {
    publicLinkOut(['status' => 'error', 'code' => 'bad_apikey',
                   'message' => 'Aborting - Bad API Key lenght'], 400);
}
if ($type === '') {
    publicLinkOut(['status' => 'error', 'code' => 'bad_type',
                   'message' => 'Aborting - Bad type'], 400);
}

// Inherited accessWithoutLogin custom types need the entities mask.
$masks = array('tproject_id' => 1, 'tplan_id' => 2, 'build_id' => 4);
$use = array();
foreach ($masks as $kx => $mm) {
    $use[$kx] = (($entities & $mm) > 0);
}

// ---- authorization (legacy init_args light/green flow) ---------------------
$opt = array('setPaths' => true, 'clearSession' => true);
$light = 'red';

if ($akl === $userAPIkeyLen) {
    // remote access for the owning user. Probe only (mirrors the
    // api/reportsprint inline pattern): setUpEnvForRemoteAccess() internally
    // does count($user) on tlUser::getByAPIKey() which returns null on a
    // no-match -> PHP 8 count(null) TypeError (a fake 32-char key would 500).
    // The probe authorizes the link; each target BFF re-validates the key in
    // its own context, so no session mutation is needed here.
    $apiUsers = tlUser::getByAPIKey($db, $apikey);
    $light = (is_array($apiUsers) && count($apiUsers) === 1) ? 'green' : 'red';
} else {
    // object key; for exec, swap to the owning plan's api_key (legacy parity)
    if ($type === 'exec') {
        if ($id <= 0) {
            publicLinkOut(['status' => 'error', 'code' => 'bad_id',
                           'message' => 'Missing id'], 400);
        }
        $et = DB_TABLE_PREFIX . 'executions';
        $rs = $db->get_recordset("SELECT testplan_id FROM $et WHERE id=" . intval($id));
        if (is_null($rs) || count($rs) == 0) {
            publicLinkOut(['status' => 'error', 'code' => 'not_found',
                           'message' => 'Execution not found'], 404);
        }
        $tpl = DB_TABLE_PREFIX . 'testplans';
        $prs = $db->get_recordset("SELECT api_key FROM $tpl WHERE id=" . intval($rs[0]['testplan_id']));
        if (Is_null($prs) || count($prs) == 0 || trim(strval($prs[0]['api_key'])) === '') {
            publicLinkOut(['status' => 'error', 'code' => 'not_found',
                           'message' => 'Test plan API key not found'], 404);
        }
        $planKey = strval($prs[0]['api_key']);
        // Fail-closed: a genuine share link always carries the owning plan
        // key here (all emitters produce it), so require the presented
        // anonymous key to match before swapping (a foreign key with a known
        // execution id must not open the print).
        if ($apikey !== $planKey) {
            publicLinkOut(['status' => 'error', 'code' => 'forbidden_key',
                           'message' => 'API key does not match execution test plan'], 403);
        }
        $apikey = $planKey;
    }

    $kerberos = new stdClass();
    $kerberos->args = new stdClass();
    $kerberos->args->tproject_id = intval($_GET['tproject_id'] ?? 0);
    $kerberos->args->tplan_id = intval($_GET['tplan_id'] ?? 0);
    $kerberos->method = null;

    if (setUpEnvForAnonymousAccess($db, $apikey, $kerberos, $opt)) {
        $light = 'green';
    }
}

if ($light !== 'green') {
    publicLinkOut(['status' => 'error', 'code' => 'invalid_link',
                   'message' => 'Link is not valid for anonymous access']);
}

// ---- resolve the MODERN target ---------------------------------------------
$target = null;
$cfg = isset($GLOBALS['tlCfg']->reports_list[$type])
    ? $GLOBALS['tlCfg']->reports_list[$type] : null;

switch ($type) {
    case 'exec':
        $target = "/gui/templates/execute/execPrint.html?id={$id}&apikey=" .
                  rawurlencode($apikey);
        break;

    case 'file':
        $target = "/api/attachments/index.php?action=download&id={$id}&apikey=" .
                  rawurlencode($apikey);
        break;

    case 'metricsdashboard':
        $target = "/gui/templates/results/metricsDashboard.html?apikey=" .
                  rawurlencode($apikey);
        break;

    case 'test_plan':
    case 'test_report':
    case 'testreport_onbuild':
        $target = publicLinkReportUrl($type, $apikey);
        break;

    case 'testspec':
        if ($cfg === null || !isset($cfg['url']) || $cfg['url'] === '') {
            publicLinkOut(['status' => 'error', 'code' => 'no_target',
                           'message' => 'No target for this link type'], 400);
        }
        $target = $cfg['url'] . "?apikey=" . rawurlencode($apikey) .
            "&type={$type}&level=testproject&id={$id}" .
            "&tproject_id={$id}" .
            "&header=y&summary=y&toc=y&body=y&cfields=y&author=y" .
            "&requirement=y&keyword=y&headerNumbering=y&format=" . FORMAT_HTML;
        break;

    case 'metrics_tp_general':
        $target = ($cfg !== null && isset($cfg['url']) ? $cfg['url'] : '')
            . "?apikey=" . rawurlencode($apikey) .
            "&tproject_id=" . intval($_GET['tproject_id'] ?? 0) .
            "&tplan_id=" . intval($_GET['tplan_id'] ?? 0) .
            "&format=" . FORMAT_HTML;
        break;

    case 'list_tc_failed':
    case 'list_tc_blocked':
    case 'list_tc_not_run':
        $target = ($cfg !== null && isset($cfg['url']) ? $cfg['url'] : '')
            . "&apikey=" . rawurlencode($apikey) .
            "&tproject_id=" . intval($_GET['tproject_id'] ?? 0) .
            "&tplan_id=" . intval($_GET['tplan_id'] ?? 0) .
            "&format={$format}";
        break;

    case 'results_matrix':
    case 'abslatest_results_matrix':
    case 'report_exec_timeline':
    case 'results_by_tester_per_build':
    case 'charts_basic':
        $target = ($cfg !== null && isset($cfg['url']) ? $cfg['url'] : '')
            . "?apikey=" . rawurlencode($apikey) .
            "&tproject_id=" . intval($_GET['tproject_id'] ?? 0) .
            "&tplan_id=" . intval($_GET['tplan_id'] ?? 0) .
            "&format={$format}";
        break;

    default:
        // accessWithoutLogin custom types (legacy lnl.php default branch)
        $needle = 'list_tc_';
        if (strpos($type, $needle) !== false) {
            $target = ($cfg !== null && isset($cfg['url']) ? $cfg['url'] : '')
                . "&apikey=" . rawurlencode($apikey) .
                "&tproject_id=" . intval($_GET['tproject_id'] ?? 0) .
                "&tplan_id=" . intval($_GET['tplan_id'] ?? 0) .
                "&format={$format}";
        } else {
            $awl = config_get('accessWithoutLogin');
            if (!isset($awl[$type])) {
                publicLinkOut(['status' => 'error', 'code' => 'unknown_type',
                               'message' => 'ABORTING - UNKNOWN TYPE'], 400);
            }
            $conf = $awl[$type];
            $param = '';
            foreach ($use as $prop => $useIt) {
                if ($useIt) {
                    $param .= "&$prop=" . intval($GLOBALS['_GET'][$prop] ?? 0);
                }
            }
            $target = $conf['url'] . "&apikey=" . rawurlencode($apikey) . $param;
        }
        break;
}

if (is_null($target) || $target === '') {
    publicLinkOut(['status' => 'error', 'code' => 'no_target',
                   'message' => 'No target for this link type'], 400);
}

$target = ltrim($target, '/');
$href = '/' . $target;

publicLinkOut(array(
    'status' => 'ok',
    'type' => $type,
    'id' => $id,
    'apikey_length' => $akl,
    'format' => $format,
    'target' => $target,
    'href' => $href,
));

/**
 * Refs #1408 parity: the test_plan / test_report / testreport_onbuild public
 * links land on the modern reportPrint.html popup with the same option flags
 * the legacy lnl.php reportPublicUrl() produced.
 */
function publicLinkReportUrl($type, $apikey) {
    $passfail = ($type === 'test_report' || $type === 'testreport_onbuild') ? 'y' : 'n';
    $withBuild = ($type === 'testreport_onbuild');
    $optStr = "header=y&summary=y&toc=y&body=y&passfail={$passfail}&cfields=y&metrics=y&author=y" .
              "&requirement=y&keyword=y&notes=y&headerNumbering=y";
    $url = "/gui/templates/results/reportPrint.html" .
           "?type={$type}&level=testproject" .
           "&id=" . intval($_GET['tproject_id'] ?? 0) .
           "&tproject_id=" . intval($_GET['tproject_id'] ?? 0) .
           "&tplan_id=" . intval($_GET['tplan_id'] ?? 0);
    if ($withBuild && intval($_GET['build_id'] ?? 0) > 0) {
        $url .= "&build_id=" . intval($_GET['build_id'] ?? 0);
    }
    $url .= "&format=" . FORMAT_HTML .
            "&apikey=" . rawurlencode($apikey) .
            "&opts=" . rawurlencode($optStr);
    return $url;
}