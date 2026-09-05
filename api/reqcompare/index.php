<?php
/**
 * Requirement Version Compare BFF API
 * URL: /api/reqcompare/index.php
 * Plain PHP, no framework, no compilation
 *
 * Mirrors lib/requirements/reqCompareVersions.php (TestLink 1.9.20
 * "Requirement Version Compare" screen). Rights: mgt_view_req on the owning
 * test project (same targeted check legacy reqView.php does).
 *
 * Endpoints (JSON in/out):
 *   GET ?action=versions&requirement_id=N[&tproject_id=N]
 *       -> requirement header + ordered version/revision list (selectable)
 *   GET ?action=compare&requirement_id=N&left=A&right=B
 *       [&method=html|text][&context=N][&use_html=1]
 *       -> attribute diff + scope diff + linked custom fields diff
 *
 * The client decides against BFF is built from the same legacy building
 * blocks (third_party/diff + third_party/daisydiff) and TLi18n labels.
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
require_once(__DIR__ . '/../../lib/functions/requirements.inc.php');
require_once(__DIR__ . '/../../lib/functions/requirement_mgr.class.php');
require_once(__DIR__ . '/../../lib/functions/requirement_spec_mgr.class.php');

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
$BODY   = json_decode(file_get_contents('php://input'), true) ?? [];

function out($data) { echo json_encode($data); exit; }
function badRequest($msg) { http_response_code(400); out(['status' => 'error', 'message' => $msg]); }

$reqMgr = new requirement_mgr($db);

/**
 * A requirement knows its own test project; enforce mgt_view_req there
 * (no fatals for a nonexistent requirement id). Returns owning project id
 * or out()s with an error.
 */
function resolveReqContext($reqId) {
    global $db, $user;
    $row = $db->get_recordset(
        "SELECT REQ.id, REQ.req_doc_id, RSPEC.testproject_id " .
        " FROM requirements REQ " .
        " JOIN req_specs RSPEC ON RSPEC.id = REQ.srs_id " .
        " WHERE REQ.id = " . intval($reqId));
    if (empty($row)) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Requirement not found']);
    }
    $tid = intval($row[0]['testproject_id']);
    if (!$user->hasRight($db, 'mgt_view_req', $tid)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }
    return [
        'tproject_id' => $tid,
        'req_doc_id'  => (string)$row[0]['req_doc_id'],
    ];
}

if ($method !== 'GET') {
    http_response_code(405);
    out(['status' => 'error', 'message' => 'Method not allowed']);
}

$reqId = intval($_REQUEST['requirement_id'] ?? $_REQUEST['id'] ?? 0);
if ($reqId <= 0) { badRequest('Invalid requirement id'); }
$ctx = resolveReqContext($reqId);

$requestedTid = intval($_REQUEST['tproject_id'] ?? 0);
if ($requestedTid > 0 && $requestedTid !== $ctx['tproject_id']) {
    badRequest('Test project mismatch');
}

$reqCfg   = config_get('req_cfg');
$diffCfg  = config_get('diffEngine');
$useDaisy = ($_REQUEST['method'] ?? 'html') === 'html';

// decode maps for status/type (like api/requirements buildMeta)
$typeLabels   = [];
$statusLabels = [];
foreach ((array)$reqCfg->type_labels as $code => $langKey) {
    $typeLabels[$code] = lang_get($langKey);
}
foreach ((array)$reqCfg->status_labels as $code => $langKey) {
    $statusLabels[$code] = lang_get($langKey);
}

$history = $reqMgr->get_history($reqId, ['output' => 'array', 'decode_user' => true, 'order_by_dir' => 'DESC']);
$items   = [];
foreach ((array)$history as $row) {
    $log = (string)$row['log_message'];
    $log = preg_replace('!\s+!', ' ', trim($log));
    if ($reqCfg->log_message_len > 0 && strlen($log) > $reqCfg->log_message_len) {
        $log = substr($log, 0, $reqCfg->log_message_len) . '...';
    }
    $items[] = [
        'item_id'           => intval($row['item_id']),
        'version_id'        => intval($row['version_id']),
        'revision_id'       => intval($row['revision_id']),
        'version'           => intval($row['version']),
        'revision'          => intval($row['revision']),
        'scope'             => (string)$row['scope'],
        'status'            => (string)$row['status'],
        'status_label'      => isset($statusLabels[$row['status']]) ? $statusLabels[$row['status']] : (string)$row['status'],
        'type'              => (string)$row['type'],
        'type_label'        => isset($typeLabels[$row['type']]) ? $typeLabels[$row['type']] : (string)$row['type'],
        'expected_coverage' => intval($row['expected_coverage']),
        'log_message'       => $log,
        'timestamp'         => (string)$row['timestamp'],
        'last_editor'       => (string)$row['last_editor'],
    ];
}

$webCfg = getWebEditorCfg('requirement');
$reqType = isset($webCfg['type']) ? (string)$webCfg['type'] : 'none';

// ---- version/revision list only ----
if ($action === 'versions') {
    out([
        'status'      => 'ok',
        'tproject_id' => $ctx['tproject_id'],
        'tproject_name' => testproject::getName($db, $ctx['tproject_id']),
        'req_id'      => $reqId,
        'req_doc_id'  => $ctx['req_doc_id'],
        'req_type'    => $reqType,
        'context'     => isset($diffCfg->context) ? intval($diffCfg->context) : 5,
        'items'       => $items,
    ]);
}

// ---- diff two versions/revisions ----
if ($action === 'compare') {
    $leftId  = intval($_REQUEST['left'] ?? 0);
    $rightId = intval($_REQUEST['right'] ?? 0);
    if ($leftId <= 0 || $rightId <= 0) { badRequest('Select two versions to compare'); }
    if ($leftId === $rightId)           { badRequest('Select two different versions to compare'); }

    $byId = [];
    foreach ($items as $it) { $byId[$it['item_id']] = $it; }
    if (!isset($byId[$leftId]) || !isset($byId[$rightId])) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'One or both versions not found']);
    }
    $left  = $byId[$leftId];
    $right = $byId[$rightId];

    // ---- attribute diff (legacy getAttrDiff): status / type / expected_coverage ----
    static $attrLabelKeys = null;
    if ($attrLabelKeys === null) {
        $attrLabelKeys = init_labels(['status' => null, 'type' => null, 'expected_coverage' => null]);
    }
    $attrRows = [];
    $attrDefs = [
        'status'            => ['label' => $attrLabelKeys['status'],            'lvalue' => $left['status_label'],  'rvalue' => $right['status_label']],
        'type'              => ['label' => $attrLabelKeys['type'],              'lvalue' => $left['type_label'],    'rvalue' => $right['type_label']],
        'expected_coverage' => ['label' => $attrLabelKeys['expected_coverage'], 'lvalue' => (string)$left['expected_coverage'],
                                'rvalue' => (string)$right['expected_coverage']],
    ];
    foreach ($attrDefs as $fkey => $def) {
        $attrRows[] = [
            'label'   => $def['label'],
            'lvalue'  => $def['lvalue'],
            'rvalue'  => $def['rvalue'],
            'changed' => ($def['lvalue'] !== $def['rvalue']),
        ];
    }

    // ---- scope diff ----
    $scope = ['type' => $useDaisy ? 'html' : 'text', 'left' => $left['scope'], 'right' => $right['scope'], 'count' => 0];
    if ($left['scope'] !== $right['scope']) {
        if ($useDaisy) {
            require_once(__DIR__ . '/../../third_party/daisydiff/src/HTMLDiff.php');
            $differ = new HTMLDiffer();
            $lScope = $left['scope'];
            $rScope = $right['scope'];
            if ($reqType === 'none') { $lScope = nl2br($lScope); $rScope = nl2br($rScope); }
            list($diffHtml, $count) = $differ->htmlDiff($lScope, $rScope);
            $scope['type']  = 'html';
            $scope['diff']  = $diffHtml;
            $scope['count'] = intval($count);
        } else {
            require_once(__DIR__ . '/../../third_party/diff/diff.php');
            $ln = explode("\n", str_replace('</p>', "</p>\n", $left['scope']));
            $rn = explode("\n", str_replace('</p>', "</p>\n", $right['scope']));
            $context = isset($_REQUEST['context_show_all'])
                ? -1 : (isset($_REQUEST['context']) && is_numeric($_REQUEST['context'])
                    ? intval($_REQUEST['context']) : (isset($diffCfg->context) ? intval($diffCfg->context) : 5));
            $differ = new diff();
            $differ->doDiff($ln, $rn);
            $scope['type'] = 'text';
            $scope['diff'] = $differ->inline($ln, 'revision:' . intval($left['revision']),
                                             $rn, 'revision:' . intval($right['revision']), $context);
            $scope['count'] = count($differ->changes);
        }
    }

    // ---- linked custom fields on each side (legacy getCFToCompare/getCFDiff) ----
    $cfLeft  = (array)$reqMgr->get_linked_cfields(null, [$leftId], $ctx['tproject_id'], ['access_key' => 'node_id']);
    $cfRight = (array)$reqMgr->get_linked_cfields(null, [$rightId], $ctx['tproject_id'], ['access_key' => 'node_id']);
    $leftCfs  = isset($cfLeft[$leftId])  ? $cfLeft[$leftId]  : [];
    $rightCfs = isset($cfRight[$rightId]) ? $cfRight[$rightId] : [];
    $cfCfg    = config_get('custom_fields');
    $showAll  = isset($cfCfg->show_custom_fields_without_value) ? (bool)$cfCfg->show_custom_fields_without_value : false;
    $cfieldMgr = $reqMgr->cfield_mgr;
    $cfKeys = array_unique(array_merge(array_keys($leftCfs), array_keys($rightCfs)));
    $cfRows = [];
    foreach ($cfKeys as $k) {
        $lv = isset($leftCfs[$k])  ? $leftCfs[$k]['value']  : null;
        $rv = isset($rightCfs[$k]) ? $rightCfs[$k]['value'] : null;
        $lRaw = is_array($lv) ? implode(', ', $lv) : (string)$lv;
        $rRaw = is_array($rv) ? implode(', ', $rv) : (string)$rv;
        $lRaw = preg_replace('!\s+!', ' ', trim($lRaw));
        $rRaw = preg_replace('!\s+!', ' ', trim($rRaw));
        if (!$showAll && $lRaw === '' && $rRaw === '') { continue; }
        $vType = (isset($leftCfs[$k]['type']) && isset($cfieldMgr->custom_field_types[$leftCfs[$k]['type']]))
            ? $cfieldMgr->custom_field_types[$leftCfs[$k]['type']] : 'string';
        if (($vType === 'date' || $vType === 'datetime') && is_numeric($lRaw) && intval($lRaw) != 0) {
            $lRaw = tlStrftime(config_get($vType), intval($lRaw));
        }
        if (($vType === 'date' || $vType === 'datetime') && is_numeric($rRaw) && intval($rRaw) != 0) {
            $rRaw = tlStrftime(config_get($vType), intval($rRaw));
        }
        $cfRows[] = [
            'label'   => isset($leftCfs[$k]['label']) ? $leftCfs[$k]['label'] : $k,
            'lvalue'  => $lRaw,
            'rvalue'  => $rRaw,
            'changed' => ($lRaw !== $rRaw),
        ];
    }

    out([
        'status'      => 'ok',
        'req_id'      => $reqId,
        'req_doc_id'  => $ctx['req_doc_id'],
        'req_type'    => $reqType,
        'left'        => [
            'item_id'  => intval($left['item_id']),
            'version'  => intval($left['version']),
            'revision' => intval($left['revision']),
            'timestamp'=> $left['timestamp'],
            'editor'   => $left['last_editor'],
        ],
        'right'       => [
            'item_id'  => intval($right['item_id']),
            'version'  => intval($right['version']),
            'revision' => intval($right['revision']),
            'timestamp'=> $right['timestamp'],
            'editor'   => $right['last_editor'],
        ],
        'method'     => $useDaisy ? 'html' : 'text',
        'attributes' => $attrRows,
        'scope'      => $scope,
        'custom_fields' => $cfRows,
    ]);
}

http_response_code(404);
out(['status' => 'error', 'message' => 'Unknown action']);