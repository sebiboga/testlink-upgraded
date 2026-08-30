<?php
/**
 * Requirement Document Print BFF API
 * URL: /api/reqdoc/index.php
 * Plain PHP, no framework, no compilation.
 *
 * Replaces the requirement-spec branch of the legacy screen
 * lib/results/printDocument.php (TestLink 1.9.20), which was still
 * launched in a new tab by the modernized printReqSpec.html. The document
 * is rendered server-side by reusing the legacy document generator
 * (lib/functions/print.inc.php + printDocOptions.class.php) so output stays
 * 1:1 with the legacy print, while the transport becomes session-authenticated
 * JSON for the modern Dashio screen.
 *
 * Rights (same gate as lib/results/printDocument.php checkRights + the
 * print_init action of api/requirements): user needs 'testplan_metrics' on
 * the test project.
 *
 * Endpoints (JSON out):
 *   GET  ?action=doc&tproject_id=N&id=N&level=reqspec|testproject
 *              &format=N&toc=y|n&cfields=y|n&<opt>=y|n...
 *        -> {status:"ok", html:"...", title:"...", format:N}
 *   Any other verb/action -> 400 JSON.
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once(__DIR__ . '/../../cfg/reports.cfg.php');
require_once('common.php');
require_once(__DIR__ . '/../../lib/functions/requirement_spec_mgr.class.php');
require_once(__DIR__ . '/../../lib/functions/printDocOptions.class.php');
require_once(__DIR__ . '/../../lib/functions/print.inc.php');
require_once(__DIR__ . '/../../lib/functions/testproject.class.php');
require_once(__DIR__ . '/../../lib/functions/testplan.class.php');
require_once(__DIR__ . '/../../lib/functions/tree.class.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function out($data) {
    echo json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE
                           | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    out(['status' => 'error', 'message' => 'Method not allowed']);
}
$action = isset($_GET['action']) ? trim($_GET['action']) : '';
if ($action !== 'doc') {
    http_response_code(400);
    out(['status' => 'error', 'message' => 'Invalid action']);
}

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || $userId <= 0) {
    http_response_code(401);
    out(['status' => 'error', 'message' => 'Not authenticated']);
}
$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    http_response_code(401);
    out(['status' => 'error', 'message' => 'User not found']);
}

$tproject_id = isset($_GET['tproject_id']) ? intval($_GET['tproject_id']) : 0;
$itemID      = isset($_GET['id']) ? intval($_GET['id']) : 0;
$level       = isset($_GET['level']) ? trim($_GET['level']) : 'reqspec';
$format      = isset($_GET['format']) ? intval($_GET['format']) : FORMAT_HTML;
if ($level !== 'reqspec' && $level !== 'testproject') {
    http_response_code(400);
    out(['status' => 'error', 'message' => 'Invalid level']);
}
if ($itemID <= 0) {
    $itemID = $tproject_id;
}

// ---- context: test project must exist -------------------------------------
$tprojectMgr = new testproject($db);
$tprojectInfo = $tprojectMgr->get_by_id($tproject_id);
if (!$tprojectInfo) {
    http_response_code(400);
    out(['status' => 'error', 'message' => 'Invalid test project id']);
}
$tproject_name = $tprojectInfo['name'];
$tproject_scope = isset($tprojectInfo['notes']) ? $tprojectInfo['notes'] : '';
$test_priority_enabled = isset($tprojectInfo['opt']->testPriorityEnabled)
    ? $tprojectInfo['opt']->testPriorityEnabled : false;

// ---- rights: legacy printDocument.php gate is 'testplan_metrics' ----------
if (!$user->hasRightOnProj($db, 'testplan_metrics', $tproject_id)) {
    http_response_code(403);
    out(['status' => 'error',
         'message' => 'You are not authorized to generate documents for this test project']);
}

// ---- requested requirement spec must belong to the project ----------------
$spec = null;
if ($level === 'reqspec') {
    // Covers hand-crafted URLs where id == tproject_id: the probe filters out
    // non-spec nodes, so get_by_id() below can never fatal on a bad id.
    $probeTbl = (new requirement_spec_mgr($db))->object_table;
    $probe = $db->get_recordset(
        'SELECT testproject_id FROM ' . $probeTbl .
        ' WHERE id = ' . intval($itemID) . ' LIMIT 1');
    if (!$probe || intval($probe[0]['testproject_id']) !== intval($tproject_id)) {
        http_response_code(404);
        out(['status' => 'error',
             'message' => 'Requirement specification not found in this test project']);
    }
}

// ---- decode maps ----------------------------------------------------------
$tree_manager = $tprojectMgr->tree_manager;
$decode = array();
$decode['node_descr_id'] = $tree_manager->get_available_node_types();
$decode['node_id_descr'] = array_flip((array)$decode['node_descr_id']);
$pc = config_get('results');
$decode['status_descr_code'] = $pc['status_code'];
$decode['status_code_descr'] = array_flip((array)$decode['status_descr_code']);

// ---- working tree (same filters as legacy initEnv for DOC_REQ_SPEC) -------
$my = array();
$my['options'] = array('recursive' => true, 'prepareNode' => null,
                       'order_cfg' => array('type' => 'spec_order'));
$my['filters'] = array(
    'exclude_node_types' => array('testplan' => 'exclude me',
                                  'testsuite' => 'exclude me',
                                  'testcase' => 'exclude me'),
    'exclude_children_of' => array('testcase' => 'exclude my children',
                                   'testsuite' => 'exclude my children',
                                   'requirement' => 'exclude my children'));
$subtree = $tree_manager->get_subtree($itemID, $my['filters'], $my['options']);

// ---- document meta (mirrors legacy initEnv) --------------------------------
$doc = new stdClass();
$doc->type = DOC_REQ_SPEC;
$doc->type_name = lang_get('req_spec');
$doc->content_range = $level;
$doc->additional_info = null;
$doc->author = '';
$doc->title = '';
$doc->build_name = '';
$doc->build_notes = '';
$doc->testplan_name = '';
$doc->testplan_scope = '';
if ($user) {
    $doc->author = htmlspecialchars($user->getDisplayName());
}
$doc->tproject_name = htmlspecialchars($tproject_name);
$doc->tproject_scope = $tproject_scope;
$doc->test_priority_enabled = $test_priority_enabled;

// ---- print options (mirrors legacy initPrintOpt) ---------------------------
$optObj = new printDocOptions();
$pOpt = $optObj->getAllOptVars();
$lightOn = isset($_GET['allOptionsOn']);
foreach ($pOpt as $opt => $val) {
    $pOpt[$opt] = ($lightOn || (isset($_GET[$opt]) && $_GET[$opt] === 'y')) ? 1 : 0;
}
$pOpt['docType'] = $doc->type;
$pOpt['tocCode'] = '';

// ---- gather content (mirrors legacy main flow, DOC_REQ_SPEC only) -----------
$treeForPlatform = array();
$treeForPlatform[0] = &$subtree;
$doc->title = $doc->tproject_name;

if ($level === 'reqspec') {
    $spec_mgr = new requirement_spec_mgr($db);
    $spec = $spec_mgr->get_by_id($itemID);
    if (is_null($spec)) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Requirement specification not found']);
    }
    $spec['childNodes'] = isset($subtree['childNodes']) ? $subtree['childNodes'] : null;
    $spec['node_type_id'] = $decode['node_descr_id']['requirement_spec'];
    unset($treeForPlatform[0]['childNodes']);
    $treeForPlatform[0]['childNodes'] = array(&$spec);
    $sep = (isset($tlCfg->gui_title_separator_2) && $tlCfg->gui_title_separator_2)
        ? $tlCfg->gui_title_separator_2 : ' :: ';
    $doc->title = htmlspecialchars($tproject_name . $sep . $spec['title']);
}

// ---- rendering ---------------------------------------------------------------
$topText = renderHTMLHeader($doc->type . ' ' . $doc->title, $_SESSION['basehref'], $doc->type);
$topText .= renderFirstPage($doc);
renderTOC($pOpt);

$actionContext = array('tproject_id' => $tproject_id, 'tplan_id' => 0,
                       'build_id' => 0, 'level' => $level, 'format' => $format,
                       'type' => 'reqspec', 'id' => $itemID, 'user_id' => $userId);
$docText = '';
foreach ($treeForPlatform as $platform_id => $tree2work) {
    $actionContext['platform_id'] = $platform_id;
    if (isset($tree2work['childNodes']) && sizeof($tree2work['childNodes']) > 0) {
        $tree2work['name'] = $tproject_name;
        $tree2work['id'] = $tproject_id;
        $tree2work['node_type_id'] = $decode['node_descr_id']['testproject'];
        $docText .= renderReqSpecTreeForPrinting($db, $tree2work, $pOpt,
                                                 null, 0, 1, $userId, 0, $tproject_id);
    }
}
$docText .= renderEOF();
if ($pOpt['toc']) {
    $pOpt['tocCode'] .= '</div>';
    $topText .= $pOpt['tocCode'];
}
$fullHtml = $topText . $docText;

out(array('status' => 'ok',
          'html' => $fullHtml,
          'title' => html_entity_decode($doc->title),
          'tproject_name' => $tproject_name,
          'format' => $format,
          'level' => $level,
          'id' => $itemID));