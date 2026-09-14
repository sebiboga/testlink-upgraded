<?php
/**
 * Requirement Editor BFF API
 * URL: /api/reqedit/index.php
 * Plain PHP, no framework, no compilation
 *
 * Standalone modern screen for lib/requirements/reqEdit.php (TestLink 1.9.20
 * "Requirement editor"). Editing also lives inside the modern reqSpecMgmt
 * modal; this dedicated endpoint powers the standalone reqEdit.html screen
 * that opens from the modernized Advanced Search / search requirement screens
 * (searchAdvancedView.html, searchReq.html, searchReqSpec.html), which used to
 * jump straight to the legacy reqEdit.php controller.
 *
 * Rights (same as legacy reqEdit.php / api/reqspec):
 *   view   -> mgt_view_req OR mgt_modify_req
 *   manage -> mgt_modify_req
 *
 * Endpoints (JSON in/out):
 *   GET  ?action=form&id=N                -> requirement (selected version) + spec + options (edit)
 *                                           optional version_id|req_version_id loads THAT exact
 *                                           version (legacy reqEdit.php parity, gap #1382); no
 *                                           version arg -> latest version. Returns the full
 *                                           'versions' list for the editor version selector.
 *   GET  ?action=form&spec_id=N           -> options + spec info (create)
 *   POST ?action=save                     -> create (no id) or update (id) a requirement
 *                                           body version_id targets that exact version (gap #1382),
 *                                           absent -> latest. stay_here (1) keeps create form open
 *   POST ?action=version&id=N             -> create a new version of the requirement
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
require_once(__DIR__ . '/../../lib/functions/requirements.inc.php');
require_once(__DIR__ . '/../../lib/functions/requirement_spec_mgr.class.php');
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
$BODY = json_decode(file_get_contents('php://input'), true) ?? [];

function out($data) { echo json_encode($data); exit; }
function badRequest($msg) {
    http_response_code(400);
    out(['status' => 'error', 'message' => $msg]);
}

$tprojectMgr = new testproject($db);
$reqSpecMgr  = new requirement_spec_mgr($db);
$reqMgr      = new requirement_mgr($db);

function canView($user, $db, $tproject_id) {
    return $user->hasRight($db, 'mgt_view_req', $tproject_id) ||
           $user->hasRight($db, 'mgt_modify_req', $tproject_id);
}
function canManage($user, $db, $tproject_id) {
    return $user->hasRight($db, 'mgt_modify_req', $tproject_id);
}

/** Resolve + authorize the test project in context (query string or body). */
function needTprojectId() {
    global $tprojectMgr, $user, $db, $BODY;
    $id = intval($_REQUEST['tproject_id'] ?? ($BODY['tproject_id'] ?? 0));
    if ($id <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test project id']);
    }
    $info = $tprojectMgr->get_by_id($id);
    if (!$info) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Test project does not exist']);
    }
    if (!canView($user, $db, $id)) {
        http_response_code(403);
        out(['status' => 'error',
             'message' => 'You are not authorized to view requirement specifications']);
    }
    return $id;
}

function needManageRight($tproject_id) {
    global $user, $db;
    if (!canManage($user, $db, $tproject_id)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'You have no right to modify requirements']);
    }
}

/** Spec must exist and belong to the test project in context (mirror api/reqspec). */
function needOwnedSpec($specId, $tproject_id, &$reqSpecMgr, &$db) {
    $rows = $db->get_recordset(
        'SELECT RS.testproject_id FROM ' . $reqSpecMgr->object_table . ' RS' .
        ' JOIN req_specs_revisions RSV ON RSV.parent_id = RS.id' .
        ' WHERE RS.id = ' . intval($specId) . ' LIMIT 1');
    if (!$rows || intval($rows[0]['testproject_id']) !== intval($tproject_id)) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Requirement specification not found']);
    }
    return @$reqSpecMgr->get_by_id(intval($specId)) ?: null;
}

/**
 * Legacy parity with lib/functions/common.php::getItemTemplateContents():
 * resolve the configured requirement_template for the 'scope' field so that
 * create mode opens the Scope editor pre-filled with the template scaffold.
 * Returns '' when no template is configured (type 'none' or unknown) — which
 * matches lib/requirements/reqEdit.php renderGui() default branch behaviour.
 */
function requirementTemplateBody() {
    if (!function_exists('getItemTemplateContents')) {
        return '';
    }
    return (string)getItemTemplateContents('requirement_template', 'scope', '');
}

/** Localized type/status option maps + defaults, same as api/reqspec options. */
function reqOptions() {
    $cfg = config_get('req_cfg');
    $reqTypes = [];
    foreach ($cfg->type_labels as $code => $labelKey) {
        $reqTypes[(string)$code] = lang_get($labelKey);
    }
    $reqStatuses = [];
    foreach ($cfg->status_labels as $code => $labelKey) {
        $reqStatuses[(string)$code] = lang_get($labelKey);
    }
    return [
        'reqTypes'    => $reqTypes,
        'reqStatuses' => $reqStatuses,
        'defaultReqType'    => TL_REQ_TYPE_FEATURE,
        'defaultReqStatus'  => TL_REQ_STATUS_VALID,
    ];
}

if ($action === '') {
    http_response_code(400);
    out(['status' => 'error', 'message' => 'Missing action']);
}

// ------------------------------------------------------------------ form ---
// GET ?action=form&id=N (edit) | ?action=form&spec_id=N&tproject_id=N (create)
if ($method === 'GET' && $action === 'form') {
    $tproject_id = needTprojectId();
    $options = reqOptions();
    $tpInfo = $tprojectMgr->get_by_id($tproject_id);
    $tpName = (is_array($tpInfo) && isset($tpInfo['name'])) ? (string)$tpInfo['name'] : '';

    $reqId = intval($_REQUEST['id'] ?? 0);
    if ($reqId > 0) {
        $req = null;
        // resolve owning project from the requirement spec
        $info = $db->get_recordset(
            "SELECT srs.testproject_id FROM requirements r" .
            " JOIN req_specs srs ON srs.id = r.srs_id" .
            " WHERE r.id = " . intval($reqId) . " LIMIT 1");
        if (!$info || intval($info[0]['testproject_id']) !== intval($tproject_id)) {
            http_response_code(404);
            out(['status' => 'error', 'message' => 'Requirement not found or not in project']);
        }
        // all version rows of the requirement: selector list + fallback source.
        // gap #1382: legacy reqEdit.php?doAction=edit&req_version_id=VID edits
        // THAT exact version; without it, the latest version is edited.
        $versionRows = $db->get_recordset(
            "SELECT v.id AS version_id, v.version, v.revision, v.status," .
            " v.is_open, v.scope, v.creation_ts" .
            " FROM nodes_hierarchy vh" .
            " JOIN req_versions v ON v.id = vh.id" .
            " WHERE vh.parent_id = " . intval($reqId) .
            " ORDER BY v.version DESC");
        if (!$versionRows) {
            http_response_code(404);
            out(['status' => 'error', 'message' => 'Requirement not found']);
        }
        $reqVersionId = intval($_REQUEST['req_version_id'] ?? ($_REQUEST['version_id'] ?? 0));
        if ($reqVersionId > 0) {
            $found = false;
            foreach ($versionRows as $vrow) {
                if (intval($vrow['version_id']) === $reqVersionId) { $found = true; break; }
            }
            if (!$found) {
                http_response_code(404);
                out(['status' => 'error', 'message' => 'Requirement version not found']);
            }
        } else {
            // no explicit version -> latest (first row of the DESC list)
            $reqVersionId = intval($versionRows[0]['version_id']);
        }
        // render the selected version (same field set as before + version_id)
        $rows = $db->get_recordset(
            "SELECT r.id, r.srs_id, r.req_doc_id, nh.name AS title, nh.node_order," .
            " v.scope, v.status, v.type, v.version, v.active, v.is_open," .
            " v.expected_coverage, v.creation_ts, v.modification_ts, v.id AS version_id," .
            " u.login AS author_login, srsnh.name AS spec_title" .
            " FROM requirements r" .
            " JOIN nodes_hierarchy nh ON nh.id = r.id" .
            " JOIN nodes_hierarchy vh ON vh.parent_id = r.id" .
            " JOIN req_versions v ON v.id = vh.id" .
            " JOIN nodes_hierarchy srsnh ON srsnh.id = r.srs_id" .
            " LEFT JOIN users u ON u.id = v.author_id" .
            " WHERE r.id = " . intval($reqId) .
            "   AND v.id = " . intval($reqVersionId) . " LIMIT 1");
        if (!$rows) {
            http_response_code(404);
            out(['status' => 'error', 'message' => 'Requirement not found']);
        }
        $r = $rows[0];
        $req = [
            'id'                => intval($r['id']),
            'version_id'        => intval($r['version_id']),
            'is_latest'         => intval($r['version_id']) === intval($versionRows[0]['version_id']),
            'srs_id'            => intval($r['srs_id']),
            'req_doc_id'        => (string)$r['req_doc_id'],
            'title'             => (string)$r['title'],
            'scope'             => (string)$r['scope'],
            'status'            => (string)$r['status'],
            'type'              => (string)$r['type'],
            'version'           => intval($r['version']),
            'active'            => intval($r['active']),
            'is_open'           => intval($r['is_open']),
            'expected_coverage' => intval($r['expected_coverage']),
            'author'            => (string)$r['author_login'],
            'spec_title'        => (string)$r['spec_title'],
        ];
        // version list for the editor selector (same shape as reqView /view)
        $versions = [];
        foreach ($versionRows as $vrow) {
            $versions[] = [
                'version_id' => intval($vrow['version_id']),
                'version'    => intval($vrow['version']),
                'revision'   => intval($vrow['revision']),
                'status'     => (string)$vrow['status'],
                'is_open'    => intval($vrow['is_open']),
            ];
        }
        $specId = intval($req['srs_id']);
        out(['status' => 'ok', 'mode' => 'edit', 'requirement' => $req,
             'versions' => $versions, 'show_version_selector' => count($versions) > 1,
             'options' => $options, 'tproject_id' => $tproject_id,
             'tproject_name' => $tpName,
             'rights' => ['view' => canView($user, $db, $tproject_id),
                          'manage' => canManage($user, $db, $tproject_id)]]);
    }

    // create mode: require a spec in project
    $specId = intval($_REQUEST['spec_id'] ?? 0);
    if ($specId <= 0) {
        badRequest('Missing requirement id or spec id');
    }
    $spec = needOwnedSpec($specId, $tproject_id, $reqSpecMgr, $db);
    $specTitle = '#' . $specId;
    if ($spec && isset($spec['title']) && trim((string)$spec['title']) !== '') {
        $specTitle = (string)$spec['title'];
    } else {
        $st = $db->get_recordset(
            "SELECT RSV.name FROM req_specs_revisions RSV" .
            " WHERE RSV.parent_id = " . intval($specId) .
            " ORDER BY RSV.revision DESC LIMIT 1");
        if ($st && trim((string)$st[0]['name']) !== '') {
            $specTitle = (string)$st[0]['name'];
        }
    }
    // gap #1381: legacy create mode pre-fills the Scope editor with the
    // requirement_template content (getItemTemplateContents) — mirror it here.
    $templateBody = requirementTemplateBody();
    out(['status' => 'ok', 'mode' => 'create',
         'requirement' => ['srs_id' => $specId, 'spec_title' => $specTitle,
                           'version' => 0, 'req_doc_id' => '', 'title' => '',
                           'scope' => $templateBody,
                           'status' => $options['defaultReqStatus'],
                           'type' => $options['defaultReqType'], 'expected_coverage' => 1],
         'template_body' => $templateBody,
         'versions' => [], 'show_version_selector' => false,
         'options' => $options, 'tproject_id' => $tproject_id,
         'tproject_name' => $tpName,
         'rights' => ['view' => canView($user, $db, $tproject_id),
                      'manage' => canManage($user, $db, $tproject_id)]]);
}

// ------------------------------------------------------------------ save ---
// POST ?action=save  body: {tproject_id, id?, spec_id, req_doc_id, title,
//                           scope, status, type, expected_coverage}
if ($method === 'POST' && $action === 'save') {
    $tproject_id = needTprojectId();
    needManageRight($tproject_id);

    $reqId = intval($BODY['id'] ?? 0);
    $docId = trim((string)($BODY['req_doc_id'] ?? ''));
    $title = trim((string)($BODY['title'] ?? ''));
    if ($docId === '') { badRequest('Document ID cannot be empty'); }
    if ($title === '') { badRequest('Title cannot be empty'); }

    $scope = (string)($BODY['scope'] ?? '');
    $status = strtoupper(trim((string)($BODY['status'] ?? TL_REQ_STATUS_VALID)));
    $type = (string)($BODY['type'] ?? TL_REQ_TYPE_FEATURE);
    $expectedCoverage = max(1, intval($BODY['expected_coverage'] ?? 1));
    // legacy reqEdit.php stay_here: keep the caller on the create form after save
    $stayHere = !empty($BODY['stay_here']) ? 1 : 0;

    if ($reqId > 0) {
        // resolve owning project + exact version to edit (gap #1382)
        $info = $db->get_recordset(
            "SELECT srs.testproject_id FROM requirements r" .
            " JOIN req_specs srs ON srs.id = r.srs_id" .
            " WHERE r.id = " . intval($reqId) . " LIMIT 1");
        if (!$info || intval($info[0]['testproject_id']) !== intval($tproject_id)) {
            http_response_code(404);
            out(['status' => 'error', 'message' => 'Requirement not found or not in project']);
        }
        $versionRows = $db->get_recordset(
            "SELECT v.id AS version_id, v.version FROM nodes_hierarchy vh" .
            " JOIN req_versions v ON v.id = vh.id" .
            " WHERE vh.parent_id = " . intval($reqId) .
            " ORDER BY v.version DESC");
        if (!$versionRows) {
            http_response_code(404);
            out(['status' => 'error', 'message' => 'Requirement not found']);
        }
        $versionId = intval($BODY['version_id'] ?? ($BODY['req_version_id'] ?? 0));
        if ($versionId > 0) {
            // deterministically target the requested version
            $found = false;
            foreach ($versionRows as $vrow) {
                if (intval($vrow['version_id']) === $versionId) { $found = true; break; }
            }
            if (!$found) {
                http_response_code(404);
                out(['status' => 'error', 'message' => 'Requirement version not found']);
            }
        } else {
            // no explicit version -> edit the latest (legacy default)
            $versionId = intval($versionRows[0]['version_id']);
        }
        $op = $reqMgr->update(intval($reqId), intval($versionId), $docId, $title,
                              $scope, $userId, $status, $type, $expectedCoverage);
        if (!$op['status_ok']) {
            badRequest($op['msg']);
        }
        out(['status' => 'ok', 'mode' => 'update', 'id' => intval($reqId),
             'version_id' => intval($versionId), 'stay_here' => $stayHere]);
    } else {
        $specId = intval($BODY['spec_id'] ?? 0);
        if ($specId <= 0) { badRequest('Invalid specification id'); }
        needOwnedSpec($specId, $tproject_id, $reqSpecMgr, $db);
        $op = $reqMgr->create($specId, $docId, $title, $scope, $userId,
                              $status, $type, $expectedCoverage);
        if (!$op['status_ok']) {
            badRequest($op['msg']);
        }
        out(['status' => 'ok', 'mode' => 'create', 'id' => intval($op['id']),
             'stay_here' => $stayHere]);
    }
}

// --------------------------------------------------------------- version ---
// POST ?action=version&id=N  -> create a new version of the requirement
if ($method === 'POST' && $action === 'version') {
    $tproject_id = needTprojectId();
    needManageRight($tproject_id);

    $reqId = intval($_REQUEST['id'] ?? ($BODY['id'] ?? 0));
    if ($reqId <= 0) { badRequest('Invalid requirement id'); }

    $info = $db->get_recordset(
        "SELECT srs.testproject_id FROM requirements r" .
        " JOIN req_specs srs ON srs.id = r.srs_id" .
        " WHERE r.id = " . intval($reqId) . " LIMIT 1");
    if (!$info || intval($info[0]['testproject_id']) !== intval($tproject_id)) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Requirement not found or not in project']);
    }

    $reqData = $reqMgr->get_by_id(intval($reqId));
    if (!$reqData) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Requirement not found']);
    }
    $latest = null;
    foreach ($reqData as $v) {
        if (is_null($latest) || intval($v['version']) > intval($latest['version'])) {
            $latest = $v;
        }
    }
    $newVersion = intval($latest['version']) + 1;
    $ok = $reqMgr->create_version(intval($reqId), $newVersion, (string)$latest['scope'],
                                  $userId, $latest['status']);
    if (!$ok) {
        http_response_code(500);
        out(['status' => 'error', 'message' => 'Failed to create new version']);
    }
    out(['status' => 'ok', 'version' => $newVersion]);
}

http_response_code(400);
out(['status' => 'error', 'message' => 'Unknown action']);
