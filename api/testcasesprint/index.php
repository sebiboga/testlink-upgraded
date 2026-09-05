<?php
/**
 * Test Cases Print BFF API
 * URL: /api/testcasesprint/
 * Plain PHP, no framework, no compilation
 *
 * Serves the modernized "Print Test Specification" screen
 * (gui/templates/testcases/printTestSpec.html + printTestDoc.html) - the
 * last remaining doc type of lib/results/printDocOptions.php that was still
 * legacy. The other print types were modernized already: DOC_REQ_SPEC
 * (printReqSpec.html + api/reqdoc) and the DOC_TEST_PLAN_* family
 * (testPlanReport.html + api/reportsprint).
 *
 * Mirrors lib/results/printDocOptions.php?type=testspec (TestLink 1.9.20):
 *  - init: current test project context + rights ('testplan_metrics', the
 *          exact right lib/results/printDocument.php checkRights() enforces)
 *          + the print option checkbox sets (printDocOptions class, doc +
 *          testSpec sets) with their default checked state + output formats.
 *  - tree: the test project suite tree (equivalent of
 *          lib/ajax/gettprojectnodes.php with show_tcases=0&operation=print).
 *  - print/download: generate the document by reusing the battle-tested
 *          legacy render pipeline lib/results/printDocument.php over the
 *          network (same strategy as api/reportsprint Refs #844/#854).
 *
 * IMPORTANT (scoping): the included legacy controller resolves its nested
 * require() of config files against the current working directory and, when
 * included from a FUNCTION scope, runs them in that local scope where the
 * global $tlCfg is invisible ("on null" fatal). So this file includes
 * lib/results/printDocument.php at the TOP-LEVEL script scope after chdir()
 * into lib/results. Never move that include inside a helper function.
 *
 * Security differences vs legacy (intentional hardening):
 *  - Legacy printDocument.php reached the generator with testlinkInitPage()
 *    (session) OR an anonymous apikey (lnl.php share links). The modern print
 *    screen runs inside the authenticated app, so the BFF REQUIRES a session
 *    user and never accepts an apikey anonymous path.
 *
 * Routes:
 *   GET ?action=init&tproject_id=N
 *        -> context + rights + print options + formats.
 *   GET ?action=tree&tproject_id=N
 *        -> nested suite tree of the test project with recursive tc counts.
 *   GET ?action=print&type=testspec&level=testproject|testsuite&id=N
 *        &tproject_id=N&format=N&[opt=y|n...]
 *        -> { status, level, id, doc_type, title, body_html }
 *        Access: authenticated + testplan_metrics on the context project.
 *   GET ?action=download&... (same params)
 *        -> streams the generated document (HTML / pseudo-MSWORD) as an
 *           attachment download instead of JSON.
 *        Access: same as print.
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

$db = new database(DB_TYPE);
doDBConnect($db);

function out($data) { echo json_encode($data); exit; }

$userId = $_SESSION['userID'] ?? null;
if (!$userId || $userId <= 0) {
    http_response_code(401);
    out(['status' => 'error', 'message' => 'Not authenticated']);
}
// keep the legacy checkSessionValid() (which redirects on staleness) happy so
// it never emits a redirect script into our captured body.
$_SESSION['lastActivity'] = time();

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    http_response_code(401);
    out(['status' => 'error', 'message' => 'User not found']);
}
$_SESSION['currentUser'] = $user;

$action = isset($_GET['action']) ? trim($_GET['action']) : '';

if (!in_array($action, ['init', 'tree', 'print', 'download'], true)) {
    http_response_code(404);
    out(['status' => 'error', 'message' => 'Unknown action']);
}

$tprojectId = isset($_GET['tproject_id']) ? intval($_GET['tproject_id']) : 0;
if ($tprojectId <= 0) {
    // legacy falls back to the session's active test project
    $tprojectId = isset($_SESSION['testprojectID']) ? intval($_SESSION['testprojectID']) : 0;
}

// ---- shared context resolution ----
$tprojectMgr = new testproject($db);
$proj = ($tprojectId > 0) ? $tprojectMgr->get_by_id($tprojectId) : null;
$hasProject = !is_null($proj) && isset($proj['name']);

// ---- authorization: testplan_metrics on the context project (legacy
//      printDocument.php checkRights() -> hasRightOnProj('testplan_metrics')).
$canGenerate = false;
if ($hasProject) {
    try {
        $canGenerate = (bool)$user->hasRightOnProj($db, 'testplan_metrics', $tprojectId);
    } catch (\Throwable $e) {
        $canGenerate = false;
    }
}

// ---------------------------------------------------------------------------
// GET ?action=init
// ---------------------------------------------------------------------------
if ($action === 'init') {
    if (!$hasProject) {
        out(['status' => 'ok', 'hasProject' => false]);
    }

    // print options: exact copy of printDocOptions class (doc + testSpec
    // sets); labels are i18n keys resolved client side as pts.opt_<value>.
    $docOptions = [
        ['value' => 'toc',             'checked' => false],
        ['value' => 'headerNumbering', 'checked' => false],
    ];
    $testSpecOptions = [
        ['value' => 'header',      'checked' => false],
        ['value' => 'summary',     'checked' => true],
        ['value' => 'body',        'checked' => false],
        ['value' => 'author',      'checked' => false],
        ['value' => 'keyword',     'checked' => false],
        ['value' => 'cfields',     'checked' => false],
        ['value' => 'requirement', 'checked' => false],
    ];

    $formats = [
        ['id' => 0, 'key' => 'pts.formatHtml'],   // FORMAT_HTML
        ['id' => 4, 'key' => 'pts.formatWord'],   // FORMAT_MSWORD (pseudo MS Word)
    ];

    $tcQty = intval($tprojectMgr->count_testcases($tprojectId));

    out([
        'status' => 'ok',
        'hasProject' => true,
        'tproject_id' => $tprojectId,
        'tproject_name' => $proj['name'],
        'canGenerate' => $canGenerate,
        'tcQty' => $tcQty,
        'formats' => $formats,
        'docOptions' => $docOptions,
        'testSpecOptions' => $testSpecOptions,
    ]);
}

// ---------------------------------------------------------------------------
// GET ?action=tree
// ---------------------------------------------------------------------------
if ($action === 'tree') {
    if (!$hasProject) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'No test project selected']);
    }

    $treeMgr = $tprojectMgr->tree_manager;
    $nodeDescr = $treeMgr->get_available_node_types(); // code -> id
    $typeIdSuite = isset($nodeDescr['testsuite']) ? intval($nodeDescr['testsuite']) : 2;
    $typeIdTc = isset($nodeDescr['testcase']) ? intval($nodeDescr['testcase']) : 3;

    $tables = tlObjectWithDB::getDBTables(array('nodes_hierarchy'));

    // all descendant node ids of the project root (suites + testcases)
    $descIds = $treeMgr->get_subtree_list($tprojectId, null, 'array');
    $descIds = is_array($descIds) ? array_map('intval', $descIds) : [];
    $childrenOf = [];
    $tcsOf = [];
    $suites = [];
    if (count($descIds) > 0) {
        $idList = implode(',', $descIds);
        $rs = $db->get_recordset(
            "SELECT id, parent_id, node_type_id, name " .
            "FROM {$tables['nodes_hierarchy']} WHERE id IN ($idList)")
            ;
        if (is_array($rs)) {
            foreach ($rs as $r) {
                $nid = intval($r['id']);
                $pid = intval($r['parent_id']);
                $ntid = intval($r['node_type_id']);
                $childrenOf[$pid][] = $nid;
                if ($ntid === $typeIdSuite) {
                    $suites[$nid] = ['id' => $nid, 'name' => $r['name'], 'parent_id' => $pid];
                } elseif ($ntid === $typeIdTc) {
                    $tcsOf[$pid][] = $nid;
                }
            }
        }
    }

    // recursive suite structure with direct tc count + recursive total
    $buildNode = function ($sid) use (&$buildNode, &$childrenOf, &$suites, &$tcsOf) {
        $kids = [];
        if (isset($childrenOf[$sid])) {
            foreach ($childrenOf[$sid] as $kidId) {
                if (isset($suites[$kidId])) {
                    $kids[] = $buildNode($kidId);
                }
            }
        }
        $direct = isset($tcsOf[$sid]) ? count($tcsOf[$sid]) : 0;
        $total = $direct;
        foreach ($kids as $k) { $total += $k['tcTotal']; }
        return [
            'id' => $sid,
            'parentId' => intval($suites[$sid]['parent_id']),
            'name' => $suites[$sid]['name'],
            'tcCount' => $direct,
            'tcTotal' => $total,
            'children' => $kids,
        ];
    };

    $rootNodes = [];
    if ($tprojectId > 0 && isset($childrenOf[$tprojectId])) {
        foreach ($childrenOf[$tprojectId] as $kidId) {
            if (isset($suites[$kidId])) {
                $rootNodes[] = $buildNode($kidId);
            }
        }
    }

    // sort siblings by name like the legacy tree menu does
    $sortByName = function (&$arr) use (&$sortByName) {
        usort($arr, function ($a, $b) { return strnatcasecmp($a['name'], $b['name']); });
        foreach ($arr as $k => $v) {
            if (!empty($v['children'])) {
                $sortByName($arr[$k]['children']);
            }
        }
    };
    $sortByName($rootNodes);

    $tcQty = intval($tprojectMgr->count_testcases($tprojectId));

    out([
        'status' => 'ok',
        'tproject_id' => $tprojectId,
        'tproject_name' => $proj['name'],
        'tcQty' => $tcQty,
        'suiteQty' => count($suites),
        'roots' => $rootNodes,
    ]);
}

// ---------------------------------------------------------------------------
// GET ?action=print | ?action=download   (legacy printDocument.php generator)
// ---------------------------------------------------------------------------
$level = isset($_GET['level']) ? trim($_GET['level']) : 'testproject';
$validLevels = ['testproject', 'testsuite'];
if (!in_array($level, $validLevels, true)) {
    http_response_code(400);
    out(['status' => 'error', 'message' => 'Invalid level']);
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$tplanId = 0; // testspec documents are plan-agnostic
$format = isset($_GET['format']) ? intval($_GET['format']) : 0;
if ($id <= 0 && $level === 'testproject') { $id = $tprojectId; }
if ($id <= 0) {
    http_response_code(400);
    out(['status' => 'error', 'message' => 'Missing id']);
}

if (!$hasProject) {
    http_response_code(404);
    out(['status' => 'error', 'message' => 'Test project not found']);
}

// level=testsuite: the suite must exist and belong to the project hierarchy
if ($level === 'testsuite') {
    $nodeInfo = $tprojectMgr->tree_manager->get_node_hierarchy_info($id);
    if (is_null($nodeInfo) || !isset($nodeInfo['name'])) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Test suite not found']);
    }
    // confirm the suite hangs under the project (subtree membership test)
    $descIds = $tprojectMgr->tree_manager->get_subtree_list($tprojectId, null, 'array');
    $descIds = is_array($descIds) ? array_map('intval', $descIds) : [];
    if (!in_array(intval($id), $descIds, true)) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Test suite not found in this test project']);
    }
}

if (!$canGenerate) {
    http_response_code(403);
    out(['status' => 'error', 'message' => 'No permission']);
}

// ---- set the session + request state the legacy controller expects ----
$_SESSION['testprojectID'] = $tprojectId;
if (empty($_SESSION['testprojectPrefix'])) {
    $_SESSION['testprojectPrefix'] = isset($proj['prefix']) ? $proj['prefix'] : '';
}

// Forward all generation params + optional print option flags into
// $_GET/$_REQUEST so the included controller's init_args()/initPrintOpt()
// read them unchanged (toc=y, summary=y, ... become booleans server-side).
$optValues = ['toc', 'headerNumbering', 'header', 'summary', 'body', 'author',
              'keyword', 'cfields', 'requirement'];
foreach ($optValues as $ov) {
    if (isset($_GET[$ov]) && trim($_GET[$ov]) === 'y') {
        $_GET[$ov] = 'y';
        $_REQUEST[$ov] = 'y';
    }
}

$_GET['type'] = 'testspec';
$_GET['level'] = $level;
$_GET['id'] = $id;
$_GET['docTestPlanId'] = 0;
$_GET['tplan_id'] = 0;
$_GET['tproject_id'] = $tprojectId;
$_GET['format'] = $format;
$_REQUEST['type'] = 'testspec';
$_REQUEST['level'] = $level;
$_REQUEST['id'] = $id;
$_REQUEST['docTestPlanId'] = 0;
$_REQUEST['tproject_id'] = $tprojectId;
$_REQUEST['format'] = $format;

setPaths();

// ---- generate the document at TOP-LEVEL scope (see scoping note) ----
$cwd = getcwd();
chdir(__DIR__ . '/../../lib/results');
ob_start();
try {
    include __DIR__ . '/../../lib/results/printDocument.php';
    $bodyHtml = (string)ob_get_clean();
} catch (\Throwable $e) {
    ob_end_clean();
    chdir((string)$cwd);
    http_response_code(500);
    out(['status' => 'error', 'message' => 'Document generation failed']);
}
chdir((string)$cwd);

if ($bodyHtml === '') {
    http_response_code(500);
    out(['status' => 'error', 'message' => 'Document generation returned no content']);
}

// extract the document title produced by the generator
$docTitle = $proj['name'];
if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $bodyHtml, $m)) {
    $docTitle = trim($m[1]);
} elseif (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $bodyHtml, $m)) {
    $docTitle = trim(strip_tags($m[1]));
}

if ($action === 'download') {
    // legacy flushHttpHeader() already set attachment headers for non-HTML;
    // for HTML we set them here after the buffer is cleaned.
    if ($format === 0) { // FORMAT_HTML
        $prefix = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $proj['prefix'] ?? '');
        $prefix = $prefix === '' ? 'testspec' : $prefix;
        $filename = $prefix . '-test-spec-' . date('Y-m-d') . '.html';
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
    }
    echo $bodyHtml;
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
echo json_encode([
    'status' => 'ok',
    'level' => $level,
    'id' => $id,
    'doc_type' => 'testspec',
    'title' => $docTitle,
    'tproject_name' => $proj['name'],
    'body_html' => $bodyHtml,
]);
exit;