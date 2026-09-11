<?php
/**
 * Requirement Revision Viewer BFF API
 * URL: /api/reqrevision/index.php
 * Plain PHP, no framework, no compilation
 *
 * Mirrors lib/requirements/reqViewRevision.php (TestLink 1.9.20 "Requirement
 * Revision" read-only viewer popup, reached via the legacy JS helper
 * openReqRevisionWindow()). The legacy screen resolves a nodes_hierarchy
 * item_id into an immutable requirement VERSION or REVISION snapshot and shows
 * the same attributes as the requirement viewer but with no editing affordance.
 *
 * Also neutralizes the dashio-theme breakage reported in #1427 (the legacy
 * screen include()s the missing displayReqCoverageRO.inc.tpl and HTTP 500s).
 *
 * Endpoints (JSON out, session auth):
 *   GET ?action=revision&item_id=N[&show_req_spec_title=1][&tproject_id=N]
 *       -> requirement version/revision snapshot: id, doc id, title, version,
 *          revision, status/type labels, scope, expected coverage, coverage
 *          (active linked test cases), custom field values, author/modifier,
 *          creation/modification timestamps, spec title, owning test project.
 *
 * Rights: mgt_view_req on the OWNING test project (modern parity - the legacy
 * controller gated the SESSION project only). 401 unauthenticated, 405 non-GET,
 * 400 invalid id, 403 no right, 404 unknown node.
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
require_once(__DIR__ . '/../../lib/functions/requirements.inc.php');
require_once(__DIR__ . '/../../lib/functions/requirement_mgr.class.php');

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

$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_REQUEST['action']) ? trim($_REQUEST['action']) : '';

function out($data) { echo json_encode($data); exit; }
function badRequest($msg) { http_response_code(400); out(['status' => 'error', 'message' => $msg]); }

if ($method !== 'GET') {
    http_response_code(405);
    out(['status' => 'error', 'message' => 'Method not allowed']);
}

if ($action !== 'revision') {
    badRequest('Unknown action');
}

$itemId = intval($_REQUEST['item_id'] ?? 0);
if ($itemId <= 0) {
    badRequest('Invalid item id');
}

$reqMgr = new requirement_mgr($db);

// Resolve the node: item_id is either a requirement_version node or a
// requirement_revision node (legacy get_available_node_types / get_node_hierarchy_info).
$nodeTypeMap = $reqMgr->tree_mgr->get_available_node_types();
$nodeTypeById = array_flip($nodeTypeMap);
$item = $reqMgr->tree_mgr->get_node_hierarchy_info($itemId);
if (empty($item) || !isset($item['node_type_id'])) {
    http_response_code(404);
    out(['status' => 'error', 'message' => 'Requirement item not found']);
}
$nodeKind = isset($nodeTypeById[$item['node_type_id']])
    ? $nodeTypeById[$item['node_type_id']] : '';

$getOpt = ['renderImageInline' => true];
switch ($nodeKind) {
    case 'requirement_version':
        $info = $reqMgr->get_version($itemId, $getOpt);
        if (empty($info)) {
            http_response_code(404);
            out(['status' => 'error', 'message' => 'Requirement version not found']);
        }
        $info['revision_id'] = -1;
        $info['target_id'] = $info['version_id'];
        $reqVersionId = intval($info['version_id']);
        break;

    case 'requirement_revision':
        $info = $reqMgr->get_revision($itemId, $getOpt);
        if (empty($info)) {
            http_response_code(404);
            out(['status' => 'error', 'message' => 'Requirement revision not found']);
        }
        $info['target_id'] = intval($info['revision_id']);
        $reqVersionId = intval($info['req_version_id']);
        break;

    default:
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Not a requirement version/revision node']);
}

// The requirement knows its own test project; gate there (modern parity, the
// legacy controller checked the SESSION project only).
$resolvedTid = intval($info['testproject_id']);
if ($resolvedTid <= 0) {
    // Defensive fallback: resolve the project by walking up the tree
    // (node_types.testproject = 1).
    $anc = $reqMgr->tree_mgr->get_path($info['srs_id'] ?? 0);
    foreach ((array)$anc as $an) {
        if (intval($an['node_type_id'] ?? 0) === 1) { $resolvedTid = intval($an['id']); }
    }
}
if ($resolvedTid <= 0) {
    http_response_code(400);
    out(['status' => 'error', 'message' => 'No test project resolved']);
}
if (!$user->hasRight($db, 'mgt_view_req', $resolvedTid)) {
    http_response_code(403);
    out(['status' => 'error', 'message' => 'No permission']);
}

$showReqSpecTitle = intval($_REQUEST['show_req_spec_title'] ?? 1) === 1;

$reqCfg = config_get('req_cfg');
$typeLabels = [];
$statusLabels = [];
foreach ((array)$reqCfg->type_labels as $code => $langKey) {
    $typeLabels[$code] = lang_get($langKey);
}
foreach ((array)$reqCfg->status_labels as $code => $langKey) {
    $statusLabels[$code] = lang_get($langKey);
}

// Linked test cases (active coverage for the underlying requirement version).
$coverage = [];
$covRs = $reqMgr->getActiveForReqVersion($reqVersionId);
if (!empty($covRs)) {
    foreach ($covRs as $tc) {
        $coverage[] = [
            'tcase_id'      => intval($tc['tcase_id']),
            'tcversion_id'  => intval($tc['tcversion_id']),
            'tcase_name'    => (string)$tc['tcase_name'],
            'tc_external_id' => (string)$tc['tc_external_id'],
            'tc_version'    => intval($tc['version']),
            'is_obsolete'   => intval($tc['is_obsolete']) === 1,
        ];
    }
}

// Linked design custom fields for this version/revision node (legacy
// html_table_of_custom_field_values(null, item_id, tproject_id)).
$cfValues = [];
$cfieldMgr = new cfield_mgr($db);
$cfMap = $reqMgr->get_linked_cfields(null, $itemId, $resolvedTid,
    ['access_key' => 'node_id']);
if (!empty($cfMap)) {
    foreach ($cfMap as $cf) {
        $vType = isset($cfieldMgr->custom_field_types[$cf['type']])
            ? $cfieldMgr->custom_field_types[$cf['type']] : 'string';
        $value = trim((string)($cf['value'] ?? ''));
        if ($vType == 'date' || $vType == 'datetime') {
            if ($value !== '' && is_numeric($value) && intval($value) != 0) {
                $value = tlStrftime(config_get($vType), intval($value));
            }
        }
        $cfValues[(string)$cf['name']] = $value;
    }
}

$expected = intval($info['expected_coverage']);
$coverageQty = count($coverage);
$coveragePct = null;
if (!empty($reqCfg->expected_coverage_management) && $expected > 0) {
    $coveragePct = round(100 / $expected * $coverageQty, 2);
}

out([
    'status'                 => 'ok',
    'node_kind'              => $nodeKind,
    'tproject_id'            => $resolvedTid,
    'tproject_name'          => testproject::getName($db, $resolvedTid),
    'item_id'                => $itemId,
    'show_req_spec_title'    => $showReqSpecTitle,
    'req_id'                 => intval($info['id']),
    'req_doc_id'             => (string)$info['req_doc_id'],
    'req_spec_title'         => (string)$info['req_spec_title'],
    'req_spec_doc_id'        => (string)$info['req_spec_doc_id'],
    'title'                  => (string)$info['title'],
    'version'                => intval($info['version']),
    'revision'               => intval($info['revision']),
    'version_id'             => intval($info['version_id']),
    'revision_id'            => intval($info['revision_id']),
    'status_code'            => (string)$info['status'],
    'status'                 => 'ok',
    'status_label'           => isset($statusLabels[$info['status']])
        ? $statusLabels[$info['status']] : (string)$info['status'],
    'type'                   => (string)$info['type'],
    'type_label'             => isset($typeLabels[$info['type']])
        ? $typeLabels[$info['type']] : (string)$info['type'],
    'scope'                  => (string)$info['scope'],
    'is_open'                => intval($info['is_open']),
    'expected_coverage'      => $expected,
    'author'                 => (string)$info['author'],
    'modifier'               => (string)($info['modifier'] ?? ''),
    'creation_ts'            => (string)$info['creation_ts'],
    'modification_ts'        => (string)$info['modification_ts'],
    'expected_coverage_mgmt' => !empty($reqCfg->expected_coverage_management),
    'cf_values'              => (object)$cfValues,
    'coverage'               => $coverage,
    'coverage_qty'           => $coverageQty,
    'coverage_pct'           => $coveragePct,
    'grants'                 => [
        'req_mgmt'           => $user->hasRight($db, 'mgt_modify_req', $resolvedTid),
    ],
]);