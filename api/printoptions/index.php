<?php
/**
 * api/printoptions — Print Document Options BFF
 *
 * Modernizes lib/results/printDocOptions.php + lib/functions/printDocOptions.class.php
 * (Refs #1570): the legacy popup launched before every document print (Test Spec /
 * Req Spec / Test Plan design / Test Report / Test Report on build) to pick the
 * output format and the per-document print preferences that tree_getPrintPreferences()
 * used to forward to printDocument.php.
 *
 * Routes:
 *   GET ?action=init&type=<doc_type>[&tproject_id=N][&tplan_id=M]
 *     -> doc type list, main title, format list, the grouped checkbox option set
 *        (legacy init_checkboxes(): base doc group + testspec OR reqspec group,
 *        plus the exec group for testreport/testreport_onbuild), build list for
 *        testreport_onbuild, and the print context.
 *        401 anon / 405 non-GET / 400 bad type or missing context / 403 no right.
 * Session-based auth, JSON I/O, no Smarty.
 */
require_once(__DIR__ . '/../../config.inc.php');
require_once(__DIR__ . '/../../cfg/reports.cfg.php');
require_once('common.php');
require_once('users.inc.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

$userId = $_SESSION['userID'] ?? null;
if (!$userId || $userId <= 0) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

$db = new database(DB_TYPE);
doDBConnect($db);

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'User not found']);
    exit;
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

if ($action !== 'init') {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
    exit;
}

$allowedTypes = [
    DOC_TEST_SPEC,
    DOC_REQ_SPEC,
    DOC_TEST_PLAN_DESIGN,
    DOC_TEST_PLAN_EXECUTION,
    DOC_TEST_PLAN_EXECUTION_ON_BUILD,
];

$type = isset($_GET['type']) ? trim($_GET['type']) : '';
if ($type === '') {
    $type = DOC_TEST_PLAN_DESIGN; // legacy init_args() default
}
if (!in_array($type, $allowedTypes, true)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid document type']);
    exit;
}

$tproject_id = isset($_GET['tproject_id']) ? intval($_GET['tproject_id']) : 0;
if ($tproject_id <= 0) {
    $tproject_id = isset($_SESSION['testprojectID']) ? intval($_SESSION['testprojectID']) : 0;
}
$tplan_id = isset($_GET['tplan_id']) ? intval($_GET['tplan_id']) : 0;
if ($tplan_id <= 0) {
    $tplan_id = isset($_SESSION['testplanID']) ? intval($_SESSION['testplanID']) : 0;
}

$needsPlan = in_array($type, [DOC_TEST_PLAN_DESIGN,
    DOC_TEST_PLAN_EXECUTION, DOC_TEST_PLAN_EXECUTION_ON_BUILD], true);

// legacy rights: testcasesprint/reports -> 'testplan_metrics',
// requirements print init -> 'mgt_view_req' (parity of the target screens).
$right = ($type === DOC_REQ_SPEC) ? 'mgt_view_req' : 'testplan_metrics';
if (!$user->hasRight($db, $right, $tproject_id > 0 ? $tproject_id : null)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Missing rights: ' . $right]);
    exit;
}

$tprojMgr = new testproject($db);
$tprojOpts = $tprojMgr->getOptions($tproject_id);
$requirementsEnabled = (!empty($tprojOpts) && isset($tprojOpts->requirementsEnabled))
    ? (bool) $tprojOpts->requirementsEnabled : false;

if ($type === DOC_REQ_SPEC && !$requirementsEnabled) {
    http_response_code(400);
    echo json_encode(['status' => 'error',
        'message' => 'Requirements are not enabled for this test project']);
    exit;
}

$projName = '';
if ($tproject_id > 0) {
    $projRow = $db->fetchOneRow(
        "SELECT name FROM {$tprojMgr->getTableName()} WHERE id = " . intval($tproject_id));
    if (is_array($projRow)) {
        $projName = $projRow['name'];
    }
}
if ($tproject_id <= 0 || $projName === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'No test project in context']);
    exit;
}

$tplanMgr = new testplan($db);
$tplanName = '';
if ($tplan_id > 0) {
    $tplanInfo = $tplanMgr->get_by_id($tplan_id);
    if (is_array($tplanInfo) && !empty($tplanInfo['name'])) {
        $tplanName = $tplanInfo['name'];
    } else {
        $tplan_id = 0;
    }
}
if ($needsPlan && $tplan_id <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'No active test plan in context']);
    exit;
}

// ---- option set: legacy init_checkboxes() in printDocOptions.php ----------
function pdo_group_doc()
{
    return [
        ['value' => 'toc',            'checked' => false, 'group' => 'doc'],
        ['value' => 'headerNumbering', 'checked' => false, 'group' => 'doc'],
    ];
}
function pdo_group_reqspec()
{
    $g = [
        ['value' => 'req_spec_scope', 'checked' => true],
        ['value' => 'req_spec_author', 'checked' => false],
        ['value' => 'req_spec_overwritten_count_reqs', 'checked' => false],
        ['value' => 'req_spec_type', 'checked' => false],
        ['value' => 'req_spec_cf', 'checked' => false],
        ['value' => 'req_scope', 'checked' => true],
        ['value' => 'req_author', 'checked' => false],
        ['value' => 'req_status', 'checked' => false],
        ['value' => 'req_type', 'checked' => false],
        ['value' => 'req_cf', 'checked' => false],
        ['value' => 'req_relations', 'checked' => false],
        ['value' => 'req_linked_tcs', 'checked' => false],
        ['value' => 'req_coverage', 'checked' => false],
        ['value' => 'displayVersion', 'checked' => false],
    ];
    foreach ($g as $k => $v) {
        $g[$k]['group'] = 'reqSpec';
    }
    return $g;
}
function pdo_group_testspec()
{
    $g = [
        ['value' => 'header', 'checked' => false],
        ['value' => 'summary', 'checked' => true],
        ['value' => 'body', 'checked' => false],
        ['value' => 'author', 'checked' => false],
        ['value' => 'keyword', 'checked' => false],
        ['value' => 'cfields', 'checked' => false],
        ['value' => 'requirement', 'checked' => false],
    ];
    foreach ($g as $k => $v) {
        $g[$k]['group'] = 'testSpec';
    }
    return $g;
}
function pdo_group_exec()
{
    $g = [
        ['value' => 'execResultsByCFOnExecCombination', 'checked' => false],
        ['value' => 'notes', 'checked' => false],
        ['value' => 'step_exec_notes', 'checked' => false],
        ['value' => 'passfail', 'checked' => true],
        ['value' => 'step_exec_status', 'checked' => true],
        ['value' => 'build_cfields', 'checked' => false],
        ['value' => 'metrics', 'checked' => false],
    ];
    foreach ($g as $k => $v) {
        $g[$k]['group'] = 'exec';
    }
    return $g;
}

$options = pdo_group_doc();
if ($type === DOC_REQ_SPEC) {
    $options = array_merge($options, pdo_group_reqspec());
} else {
    $options = array_merge($options, pdo_group_testspec());
}
if ($type === DOC_TEST_PLAN_EXECUTION ||
    $type === DOC_TEST_PLAN_EXECUTION_ON_BUILD) {
    $options = array_merge($options, pdo_group_exec());
}

// ---- formats (legacy $gui->outputFormat + showFormat for testspec/reqspec)
$format = isset($_GET['format']) ? intval($_GET['format']) : FORMAT_HTML;
$formats = [
    ['id' => FORMAT_HTML, 'key' => 'format_html'],
    ['id' => FORMAT_MSWORD, 'key' => 'format_pseudo_msword'],
];
$showFormat = ($type === DOC_TEST_SPEC || $type === DOC_REQ_SPEC);
if ($showFormat && !array_key_exists($format, [FORMAT_HTML => 1, FORMAT_MSWORD => 1])) {
    $format = FORMAT_HTML;
}

// ---- main title (legacy initializeGui + init_args) ------------------------
$titleKeys = [
    DOC_TEST_SPEC => 'testspecification_report',
    DOC_REQ_SPEC => 'requirement_specification_report',
    DOC_TEST_PLAN_DESIGN => 'report_test_plan_design',
    DOC_TEST_PLAN_EXECUTION => 'test_report',
    DOC_TEST_PLAN_EXECUTION_ON_BUILD => 'test_report_on_build',
];
$docTitles = [
    DOC_TEST_SPEC => lang_get('testspecification_report'),
    DOC_REQ_SPEC => lang_get('requirement_specification_report'),
    DOC_TEST_PLAN_DESIGN => lang_get('report_test_plan_design'),
    DOC_TEST_PLAN_EXECUTION => lang_get('test_report'),
    DOC_TEST_PLAN_EXECUTION_ON_BUILD => lang_get('test_report_on_build'),
];
if ($needsPlan && $tplan_id > 0) {
    $mainTitle = lang_get('test_plan') . ': ' . $tplanName;
} else {
    $mainTitle = $docTitles[$type] . ' - ' . lang_get('doc_opt_title');
}

// ---- builds for DOC_TEST_PLAN_EXECUTION_ON_BUILD (legacy buildInfoSet) -----
$builds = [];
if ($type === DOC_TEST_PLAN_EXECUTION_ON_BUILD) {
    $buildInfoSet = $tplanMgr->get_builds($tplan_id);
    if (!is_null($buildInfoSet) && is_array($buildInfoSet)) {
        foreach ($buildInfoSet as $bid => $binfo) {
            $builds[] = [
                'id' => intval($bid),
                'name' => $binfo['name'],
            ];
        }
    }
}

// ---- doc type list for the selector ---------------------------------------
$docTypes = [];
foreach ($allowedTypes as $t) {
    $docTypes[] = ['key' => $t, 'label' => $docTitles[$t]];
}

echo json_encode([
    'status' => 'ok',
    'doc_type' => $type,
    'main_title' => $mainTitle,
    'doc_types' => $docTypes,
    'format' => $format,
    'formats' => $formats,
    'show_format' => $showFormat,
    'options' => $options,
    'builds' => $builds,
    'context' => [
        'tproject_id' => $tproject_id,
        'tproject_name' => $projName,
        'tplan_id' => $tplan_id,
        'tplan_name' => $tplanName,
        'requirements_enabled' => $requirementsEnabled,
        'needs_plan' => $needsPlan,
    ],
]);
