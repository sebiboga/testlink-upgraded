<?php
/**
 * Test Case Version Compare BFF API
 * URL: /api/testcasescompare/
 *
 * Mirrors lib/testcases/tcCompareVersions.php (TestLink 1.9.20): lists the
 * versions of a test case and produces an inline side-by-side diff between
 * two selected versions (summary / preconditions / steps / expected results).
 * Uses the very same third_party diff engines so the rendered diff matches
 * the legacy output.
 *
 * Routes:
 *   GET ?action=info [&tproject_id=N][&testcase_id=N]
 *     -> { status, tcase:{id,name}, versions:[{version,id,modification_ts,
 *          creation_ts,author_first_name,author_last_name,is_active,is_open}],
 *          tcType, context, grants }
 *   GET ?action=compare [&testcase_id=N][&version_left=N][&version_right=N]
 *          [&use_html_comp=1|0][&context=N][&context_show_all=1]
 *     -> { status, subtitle, sections:[{key,heading,count,message,diff}] }
 *
 * Permission parity with legacy: tcCompareVersions.php performs no explicit
 * right check beyond an authenticated session. We keep that behaviour.
 *
 * Self-contained: does not depend on api/testcases/index.php.
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

function getIntParam($key, $default = 0) {
    $v = $_REQUEST[$key] ?? $default;
    return is_numeric($v) ? intval($v) : $default;
}

function getParam($key, $default = '') {
    return isset($_REQUEST[$key]) ? trim((string)$_REQUEST[$key]) : $default;
}

// Load the diff engines exactly like the legacy controller does.
require_once(__DIR__ . '/../../third_party/diff/diff.php');
require_once(__DIR__ . '/../../third_party/daisydiff/src/HTMLDiff.php');

$action = $_REQUEST['action'] ?? '';
$tcaseMgr = new testcase($db);

function resolveTprojectId() {
    $id = getIntParam('tproject_id');
    if ($id <= 0) {
        $id = intval($_SESSION['testprojectID'] ?? 0);
    }
    return $id;
}

/**
 * Build the flat version list + the compare panel exactly like the legacy
 * buildDiff()/initializeGUI() do (see lib/testcases/tcCompareVersions.php):
 * summary + preconditions are compared verbatim; steps are concatenated with
 * line breaks so the textual diff is meaningful.
 */
function buildDiff($items, $leftVersion, $rightVersion, $useDaisydiff, $tcType, $context) {
    $attrKeys = array();
    $attrKeys['simple'] = array('summary', 'preconditions');
    $attrKeys['complex'] = array('steps' => 'actions', 'expected_results' => 'expected_results');

    $diff = array();
    foreach (array_merge($attrKeys['simple'], array_keys($attrKeys['complex'])) as $gx) {
        $diff[$gx]['left'] = null;
        $diff[$gx]['right'] = null;
    }

    foreach ($items as $tcase) {
        foreach (array('left', 'right') as $side) {
            $version = $side === 'left' ? $leftVersion : $rightVersion;
            if (intval($tcase['version']) != intval($version)) {
                continue;
            }
            foreach ($attrKeys['simple'] as $attr) {
                $diff[$attr][$side] = $tcase[$attr];
            }
            if (is_array($tcase['steps'])) {
                foreach ($tcase['steps'] as $step) {
                    foreach ($attrKeys['complex'] as $attr => $key2read) {
                        $diff[$attr][$side] .= str_replace(
                            "</p>", "</p>\n", $step[$key2read]
                        ) . "<br />" . "<br />";
                    }
                }
            }
        }
    }

    $sections = array();
    foreach ($diff as $key => $val) {
        $section = array(
            'key' => $key,
            'count' => 0,
            'diff' => '',
        );
        $val['left'] = isset($val['left']) ? $val['left'] : '';
        $val['right'] = isset($val['right']) ? $val['right'] : '';

        if ($useDaisydiff) {
            $engine = new HTMLDiffer();
            if ($tcType === 'none') {
                list($d, $count) = $engine->htmlDiff(nl2br($val['left']), nl2br($val['right']));
            } else {
                list($d, $count) = $engine->htmlDiff($val['left'], $val['right']);
            }
            $section['diff'] = $d;
            $section['count'] = $count;
        } else {
            $engine = new diff();
            $left = explode("\n", str_replace("</p>", "</p>\n", $val['left']));
            $right = explode("\n", str_replace("</p>", "</p>\n", $val['right']));
            $section['diff'] = $engine->inline($left, 'v' . $leftVersion, $right, 'v' . $rightVersion, $context);
            $section['count'] = count($engine->changes);
        }
        $sections[] = $section;
    }
    return $sections;
}

if ($action === 'info') {
    $tcaseId = getIntParam('testcase_id');
    if ($tcaseId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test case id']);
    }

    $tcversions = $tcaseMgr->get_by_id($tcaseId);
    if (is_null($tcversions) || count($tcversions) === 0) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Test case not found']);
    }

    $tcaseName = strval($tcversions[0]['name'] ?? '');
    $versions = array();
    foreach ($tcversions as $row) {
        $ts = isset($row['modification_ts']) && $row['modification_ts'] !== '0000-00-00 00:00:00'
            ? $row['modification_ts'] : (isset($row['creation_ts']) ? $row['creation_ts'] : '');
        $versions[] = array(
            'version' => intval($row['version'] ?? 0),
            'modification_ts' => $ts,
            'author_first_name' => strval($row['author_first_name'] ?? ''),
            'author_last_name' => strval($row['author_last_name'] ?? ''),
        );
    }
    // newest first (the legacy template lists them in get_by_id order, which
    // is ascending by version); keep ascending to mirror legacy selection.
    $versions = array_reverse($versions);

    $tcCfg = getWebEditorCfg('design');
    $tcType = isset($tcCfg['type']) ? $tcCfg['type'] : 'none';
    $diffEngineCfg = config_get("diffEngine");
    $context = isset($diffEngineCfg->context) ? intval($diffEngineCfg->context) : 5;

    out(array(
        'status' => 'ok',
        'tcase' => array('id' => $tcaseId, 'name' => $tcaseName),
        'versions' => $versions,
        'tcType' => $tcType,
        'context' => $context,
        'use_html_comp' => 1,
        'grants' => array('mgt_modify_tc' => $user->hasRight($db, 'mgt_modify_tc') ? 1 : 0),
    ));
}

if ($action === 'compare') {
    $tcaseId = getIntParam('testcase_id');
    $left = getIntParam('version_left');
    $right = getIntParam('version_right');
    if ($tcaseId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test case id']);
    }
    if ($left <= 0 || $right <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Select two versions to compare']);
    }
    if ($left === $right) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Select two different versions']);
    }

    $tcversions = $tcaseMgr->get_by_id($tcaseId);
    if (is_null($tcversions) || count($tcversions) === 0) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Test case not found']);
    }
    $tcaseName = strval($tcversions[0]['name'] ?? '');

    $useDaisydiff = getIntParam('use_html_comp', 1) ? true : false;

    if (getIntParam('context_show_all')) {
        $context = null;
    } else {
        $diffEngineCfg = config_get("diffEngine");
        $context = (getIntParam('context') > 0)
            ? getIntParam('context')
            : (isset($diffEngineCfg->context) ? intval($diffEngineCfg->context) : 5);
    }

    $tcCfg = getWebEditorCfg('design');
    $tcType = isset($tcCfg['type']) ? $tcCfg['type'] : 'none';

    $sections = buildDiff($tcversions, $left, $right, $useDaisydiff, $tcType, $context);

    // Messages ("Number of changes in %s: %s.", "No changes in %s.", subtitle)
    // are resolved CLIENT-side via TLi18n keys (tcc.numChanges / tcc.noChanges /
    // tcc.subtitle). The legacy screen resolved them server-side with lang_get(),
    // but most locale files lack num_changes/diff_subtitle_tc, which fired
    // E_WARNING "Undefined array key" into the Event Viewer for every compare.
    // Returning raw counts + version ids lets the browser format localized text.
    out(array(
        'status' => 'ok',
        'tcaseName' => $tcaseName,
        'version_left' => $left,
        'version_right' => $right,
        'sections' => $sections,
    ));
}

http_response_code(404);
out(['status' => 'error', 'message' => 'Unknown action']);
