<?php
/**
 * api/reqtcassign — "Assign Requirements to a Test Case" BFF (Refs #1702)
 *
 * Modern twin of the single-test-case mode of the legacy Smarty popup
 *   lib/requirements/reqTcAssign.php            (?edit=testcase&showCloseButton=1)
 *   gui/templates/dashio/requirements/reqTcAssign.tpl
 *
 * The legacy controller was reduced to a session-guarded 302 shim in #1595,
 * but ONLY for its testsuite/bulk mode: the shim reads ?id= and redirects to
 * gui/templates/requirements/reqTcBulkAssign.html?tsuite_id=<id>, so the live
 * entry point gui/javascript/testlink_library.js:openReqWindow(tcase_id, cb)
 * — which builds ?edit=testcase&showCloseButton=1&callback=<cb>&id=<tcase_id>
 * — resolved to the BULK popup with a TEST CASE id in the tsuite_id slot:
 * a silent mis-dispatch to the wrong screen, against a suite that does not
 * exist. This BFF is the real testcase mode.
 *
 * Legacy behaviour ported 1:1 (see reqTcAssign.tpl):
 *  - page right   : checkRights() -> pageAccessCheck rightsAnd=
 *                   ['req_tcase_link_management'] on the session test project.
 *  - context      : a TEST CASE (tcase_id), plus the project requirements
 *                   must be enabled, else the legacy
 *                   `warning_req_tc_assignment_impossible` notice.
 *  - req specs    : testproject::genComboReqSpec($tproject_id,'dotted','&nbsp;')
 *                   and the selection remembered in $_SESSION['currentSrsId'].
 *  - title        : "Test Case {SEP} <tcTitle> [Ver<version>]".
 *  - assigned grid: requirement_spec_mgr::getReqsOnSpecForLatestTCV($spec,$tc)
 *                   -> req_doc_id / req title / scope / assigned_by
 *                      (coverage_author) / timestamp (localized), plus
 *                      can_be_removed (LINK_TC_REQ_OPEN) and a row checkbox
 *                      DISABLED when reqTCLinks.freezeLinkOnNewREQVersion is on
 *                      and can_be_removed = 0 (legacy cbDisabled logic).
 *  - free grid    : requirement_spec_mgr::getReqsOnSpecNotLinkedToLatestTCV(...)
 *                   -> req_doc_id / title [Ver<n>] / scope.
 *  - unassign     : submits the checked link_id[] (coverage row ids) and
 *                   deletes exactly those rows (legacy req_list unassign).
 *                   Guarded by check_action_precondition() -> we answer 400
 *                   'Nothing selected' for an empty selection.
 *  - assign       : submits the checked req_id[] and links the LATEST
 *                   requirement version to the LATEST test case version
 *                   (requirement_mgr::assignToTCaseUsingLatestVersions()).
 *  - scope render : reqEditorType 'none' renders the scope as-is, otherwise
 *                   it is stripped and truncated to SCOPE_SHORT_TRUNCATE.
 *  - linking gate : when testcase_cfg.reqLinkingDisabledAfterExec = 1 and the
 *                   test case has ALREADY been executed, the legacy template
 *                   set $reqLinkingEnabled = 0 and showed the reason instead
 *                   of the Assign/Unassign buttons.
 *
 * Modern supersets (nothing removed):
 *  - per-row Unlink button next to the legacy multi-select Unassign, so a
 *    single link can be dropped without selecting the rest of the grid.
 *  - an explicit "linking disabled" reason is returned by the BFF
 *    (linking_enabled + linking_disabled_reason) so the client never has to
 *    re-derive the legacy two-condition gate.
 *  - counters (requirement count per grid) come from the BFF.
 *
 * Routes:
 *   GET  ?action=init&tproject_id=N&tcase_id=N[&idSRS=M]
 *        -> test case context + req spec combo + assigned/free grids + grants
 *   POST ?action=assign   {tproject_id, tcase_id, idSRS, req_id:[..]}
 *   POST ?action=unassign {tproject_id, tcase_id, link_id:[..]}
 *   POST ?action=unlink   {tproject_id, tcase_id, link_id}      (single row)
 *
 *   401 anon · 403 no right / same-origin · 404 unknown test case or spec
 *   400 bad params / nothing selected · 405 wrong verb · 409 linking disabled
 *
 * Session-based auth, JSON I/O. No Smarty.
 */
require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

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

// The legacy screen went through testlinkInitPage() on every page load, which
// ran checkSessionValid(); doSessionStart() alone does not honour
// sessionInactivityTimeout, so a tab left open past the idle window could keep
// writing req_coverage rows. Placed after the userID gate and before any route
// dispatch, so it covers GET init and all three POST routes.
bffEnforceSession($db);

function out($data) {
    echo json_encode($data);
    exit;
}

function getBody() {
    return json_decode(file_get_contents('php://input'), true) ?? [];
}

function denyForbidden() {
    http_response_code(403);
    out(['status' => 'error',
         'message' => 'You do not have rights to manage requirement / test case links']);
}

function badRequest($msg) {
    http_response_code(400);
    out(['status' => 'error', 'message' => $msg]);
}

function notFound($msg) {
    http_response_code(404);
    out(['status' => 'error', 'message' => $msg]);
}

/** Normalize an incoming list of ids (array or csv string) to unique positive ints */
function toIdSet($raw) {
    if (is_string($raw)) {
        $raw = explode(',', $raw);
    }
    if (!is_array($raw)) {
        $raw = ($raw === null || $raw === '') ? [] : array($raw);
    }
    $out = [];
    foreach ($raw as $v) {
        if (!is_scalar($v)) {
            continue;
        }
        $n = intval(trim((string) $v));
        if ($n > 0) {
            $out[$n] = $n;
        }
    }
    return array_values($out);
}

/** Req spec combo (dotted paths) for the project — legacy genComboReqSpec() */
function reqSpecCombo($tprojectId) {
    global $db;
    $tprojectMgr = new testproject($db);
    $combo = $tprojectMgr->genComboReqSpec($tprojectId, 'dotted', '&nbsp;');
    $items = [];
    if (!is_null($combo)) {
        foreach ((array) $combo as $id => $name) {
            $items[] = [
                'id' => intval($id),
                'name' => trim(strval($name), "&nbsp; \t"),
            ];
        }
    }
    return $items;
}

/**
 * Resolve a requirement specification id that really belongs to $tprojectId.
 * Without this a caller could pass the idSRS of another project and read or
 * assign its requirements through this screen.
 */
function resolveSpecId($tprojectId, $specId) {
    foreach (reqSpecCombo($tprojectId) as $s) {
        if ($s['id'] === intval($specId)) {
            return $s['id'];
        }
    }
    return 0;
}

/**
 * True when the test case already has at least one execution (any version).
 *
 * NOTE (2.0.1 schema): `tcversions` has NO `testcase_id` column - a test case
 * node id IS the test case id and version 1 shares it. To honour "any version
 * has been executed" the version ids are listed first through
 * testcase::get_by_id(ALL_VERSIONS) and the executions are counted per id.
 */
function tcaseHasBeenExecuted($tcaseId) {
    global $db;
    $tcMgr = new testcase($db);
    return $tcMgr->latestVersionHasBeenExecuted($tcaseId);
}

/**
 * Resolve + validate the (tproject_id, tcase_id) pair, check the legacy
 * right and return the resolved context.
 */
function resolveCtx($tprojectId, $tcaseId) {
    global $db, $user;

    if ($tprojectId <= 0) {
        badRequest('Invalid test project id');
    }
    if ($tcaseId <= 0) {
        badRequest('Invalid test case id');
    }
    if (!$user->hasRight($db, 'req_tcase_link_management', $tprojectId, null, true)) {
        // legacy pageAccessCheck() logged the refused access before blocking
        logAuditEvent(TLS('audit_security_user_right_missing', $user->login,
                          $tprojectId, 'req_tcase_link_management'),
                      'SECURITY', $tprojectId, 'testprojects');
        denyForbidden();
    }

    $tprojectMgr = new testproject($db);
    $tp = $tprojectMgr->get_by_id($tprojectId);
    if (is_null($tp) || count($tp) === 0) {
        notFound('Test project not found');
    }
    $opt = $tprojectMgr->getOptions($tprojectId);
    $opt = is_object($opt) ? $opt : new stdClass();
    if (empty($opt->requirementsEnabled)) {
        // the sibling BFF api/reqtcbassign refuses on a project with
        // requirements turned off; genComboReqSpec() does not care, so without
        // this the routes would still list and write requirement links there.
        badRequest('Requirements are not enabled on this test project');
    }

    // The test case id must really be a test case node ...
    $nodeType = $tprojectMgr->tree_manager->getNodeType($tcaseId);
    if (is_null($nodeType) || $nodeType['node_type'] !== 'testcase') {
        // guards the legacy mis-dispatch: a test SUITE id here used to land on
        // the bulk popup, a bogus id used to produce an empty grid
        notFound('Test case not found');
    }
    $node = $tprojectMgr->tree_manager->get_node_hierarchy_info($tcaseId);

    // ... and it must belong to the requested test project (walk parent_id up
    // to the testproject root), which also proves cross-project isolation.
    $guard = 0;
    $cursor = intval($node['parent_id'] ?? 0);
    $owner = 0;
    while ($cursor > 0 && $guard++ < 200) {
        if ($cursor == $tprojectId) {
            $owner = $tprojectId;
            break;
        }
        $row = $db->get_recordset(
            "SELECT parent_id FROM nodes_hierarchy WHERE id=" . intval($cursor));
        if (is_null($row) || count($row) === 0) {
            break;
        }
        $cursor = intval($row[0]['parent_id']);
    }
    if ($owner === 0) {
        notFound('Test case not found in this test project');
    }

    $tcMgr = new testcase($db);
    $version = $tcMgr->get_last_active_version($tcaseId);
    $version = (is_null($version) || count($version) === 0) ? null : current($version);
    if (is_null($version)) {
        notFound('Test case has no active version');
    }

    return [
        'tproject_id' => $tprojectId,
        'tproject_name' => strval($tp['name'] ?? ''),
        'tcase_id' => $tcaseId,
        'tcase_name' => strval($node['name'] ?? ''),
        'tcversion_id' => intval($version['tcversion_id'] ?? 0),
        'tcversion' => intval($version['version'] ?? 0),
        'requirements_enabled' => !empty($opt->requirementsEnabled),
    ];
}

/**
 * Legacy linking gate. Two independent conditions, exactly as the template
 * evaluated them:
 *   1. the req_tcase_link_management right (already checked in resolveCtx)
 *   2. testcase_cfg.reqLinkingDisabledAfterExec == 1 AND the LATEST test case
 *      version has already been executed - legacy computed this from
 *      testcase::get_versions_status_quo($tcase_id, $latest)['executed'], so a
 *      test case whose old v1 was executed but whose current v2 is not must
 *      still be linkable
 * Returns [enabled, reason_code] where reason_code is '' when linking is on.
 */
function linkingGate($tcaseId) {
    global $db;
    $tcCfg = config_get('testcase_cfg');
    if (intval($tcCfg->reqLinkingDisabledAfterExec ?? 0) === 1
        && tcaseHasBeenExecuted($tcaseId)) {
        return [false, 'reqLinkingDisabledAfterExec'];
    }
    return [true, ''];
}

/** Assigned grid rows — legacy getReqsOnSpecForLatestTCV() */
function assignedRows($specId, $tcaseId, $versionString) {
    global $db;
    if ($specId <= 0) {
        return [];
    }
    $mgr = new requirement_spec_mgr($db);
    // The legacy controller passed
    //   array('link_status' => array(LINK_TC_REQ_OPEN, LINK_TC_REQ_CLOSED_BY_EXEC))
    // (legacy lib/requirements/reqTcAssign.php). Without it the class default
    // array('link_status' => 1) applies and every link closed by an execution
    // disappears from the grid and can no longer be removed - and
    // exec.inc.php::closeOpenReqLinks() closes ALL open links of a version when
    // it is executed, so this is the normal state of a project that executes
    // its test cases.
    $rs = $mgr->getReqsOnSpecForLatestTCV(
        $specId, $tcaseId,
        ['output' => 'array', 'version_string' => $versionString],
        ['link_status' => [LINK_TC_REQ_OPEN, LINK_TC_REQ_CLOSED_BY_EXEC]]);
    $rows = [];
    if (!is_null($rs)) {
        foreach ((array) $rs as $r) {
            $rows[] = [
                'link_id' => intval($r['link_id'] ?? 0),
                'req_id' => intval($r['id'] ?? 0),
                'req_version_id' => intval($r['req_version_id'] ?? 0),
                'version' => intval($r['version'] ?? 0),
                'doc_id' => strval($r['req_doc_id'] ?? ''),
                // the legacy SELECT concatenates the version into the title
                // (CONCAT(NH_REQ.name,' [v',version,'] ')). The modern screen
                // renders the version from the client-side TLi18n locale, so
                // that artifact is stripped here.
                'title' => stripVersionSuffix(strval($r['title'] ?? '')),
                'scope' => strval($r['scope'] ?? ''),
                'author' => strval($r['coverage_author'] ?? ''),
                'ts' => localizeTs(strval($r['coverage_ts'] ?? '')),
                'can_be_removed' => intval($r['can_be_removed'] ?? 0) === 1,
            ];
        }
    }
    return $rows;
}

/**
 * Remove the " [v<n>] " tail the legacy requirement_spec_mgr SELECT appends to
 * the requirement title, so the client can render the version itself with the
 * user's own locale instead of the server's `version_short`.
 */
function stripVersionSuffix($title) {
    return preg_replace('/\s*\[[A-Za-z]*\d+\]\s*$/', '',
                        trim(strval($title)));
}

/**
 * Localize a coverage timestamp server-side, the way the legacy template's
 * {localize_timestamp} Smarty modifier did. The client renders in its own
 * locale, so the raw value is not reusable there.
 *
 * NOTE: config_get('timestamp_format') is a strftime() mask (e.g.
 * "%d/%m/%Y %H:%M:%S"), NOT a date() mask, so it must go through
 * tlStrftime() - which is what localizeTimeStamp() does.
 */
function localizeTs($ts) {
    $ts = trim(strval($ts));
    if ($ts === '' || $ts === '0000-00-00 00:00:00') {
        return '';
    }
    $format = config_get('timestamp_format');
    if (empty($format)) {
        return $ts;
    }
    if (function_exists('localizeTimeStamp')) {
        return strval(localizeTimeStamp($ts, $format));
    }
    return $ts;
}

/**
 * Free grid rows - the requirements of $specId that are NOT linked to the
 * latest active test case version of $tcaseId.
 *
 * (For the record: 1.9.20 built this list with get_requirements($specId) minus
 * array_diff_byId() the assigned rows, which is correct - the DEFECTIVE method
 * in this class, getReqsOnSpecNotLinkedToLatestTCV(), is a different one, filed
 * separately as issue #1705. It is called from elsewhere, which is why it still
 * matters, but it was NOT the source of this grid.) That method is DEFECTIVE
 * (reported as a bug in this run): it LEFT JOINs req_coverage and then
 * req_versions ON RCOV.req_version_id, so
 *   (a) a requirement that IS linked comes back as well (its join produced a
 *       row, there is no NOT EXISTS / IS NULL filter), and
 *   (b) a requirement that is NOT linked has a NULL REQVER row, and
 *       CONCAT(name,' [v',NULL,'] ') evaluates to NULL, i.e. an empty title.
 * So the legacy "available" grid listed linked requirements with a title and
 * unlinked ones without one - exactly inverted.
 *
 * The list is rebuilt here from the two proven primitives the bulk-assignment
 * BFF already uses: getAllLatestRQVOnReqSpec() for the latest requirement
 * versions of the spec, minus the open req_coverage rows of the latest active
 * test case version.
 */
function freeRows($specId, $tcaseId, $versionString) {
    global $db;
    if ($specId <= 0) {
        return [];
    }
    $mgr = new requirement_spec_mgr($db);
    $rs = $mgr->getAllLatestRQVOnReqSpec($specId, ['output' => 'array']);
    if (is_null($rs) || count($rs) === 0) {
        return [];
    }
    $reqs = [];
    foreach ((array) $rs as $r) {
        $reqs[intval($r['id'])] = [
            'req_id' => intval($r['id']),
            'req_version_id' => intval($r['req_version_id']),
            'version' => intval($r['version']),
            // getAllLatestRQVOnReqSpec() concatenates the version into
            // req_doc_id (CONCAT(REQ.req_doc_id,' [',REQV.version,'] ')), the
            // assigned grid returns the raw doc id - strip it so both columns
            // read the same and the version shows up only in the V{n} chip.
            'doc_id' => preg_replace('/\s*\[\d+\]\s*$/', '',
                                     strval($r['req_doc_id'] ?? '')),
            'title' => strval($r['title'] ?? ''),
            'scope' => strval($r['scope'] ?? ''),
        ];
    }
    if (count($reqs) === 0) {
        return [];
    }

    $tcMgr = new testcase($db);
    $active = $tcMgr->get_last_active_version($tcaseId);
    $activeId = intval((is_array($active) && count($active) > 0)
                       ? current($active)['tcversion_id'] : 0);
    if ($activeId <= 0) {
        return array_values($reqs);
    }
    $in = implode(',', array_map('intval', array_keys($reqs)));
    $t = tlObjectWithDB::getDBTables('req_coverage');
    $linked = $db->get_recordset(
        "SELECT DISTINCT RCOV.req_id FROM {$t['req_coverage']} RCOV " .
        " WHERE RCOV.req_id IN ({$in}) " .
        " AND RCOV.tcversion_id = " . $activeId .
        " AND RCOV.link_status IN (" . intval(LINK_TC_REQ_OPEN) . ","
                                 . intval(LINK_TC_REQ_CLOSED_BY_EXEC) . ")");
    if (!is_null($linked)) {
        foreach ($linked as $row) {
            unset($reqs[intval($row['req_id'])]);
        }
    }
    return array_values($reqs);
}

/**
 * SCOPE_SHORT_TRUNCATE, the char budget the legacy template applied to the
 * scope when a rich text requirement editor is configured. The legacy value
 * was a Smarty config_load substitution
 * (gui/templates/conf/input_dimensions.conf); it is read here with a safe
 * default so the screen can never end up with a 0-char truncation.
 */
function scopeShortTruncate() {
    static $cached = null;
    if (!is_null($cached)) {
        return $cached;
    }
    $cached = 30;
    $conf = __DIR__ . '/../../gui/templates/conf/input_dimensions.conf';
    if (is_readable($conf)) {
        $lines = @file($conf, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (is_array($lines)) {
            foreach ($lines as $line) {
                if (preg_match('/^\s*SCOPE_SHORT_TRUNCATE\s*=\s*(\d+)/', $line, $m)) {
                    $n = intval($m[1]);
                    if ($n > 0) {
                        $cached = $n;
                    }
                    break;
                }
            }
        }
    }
    return $cached;
}

/** Shared init payload builder so the POST routes can echo fresh state back. */function buildPayload($ctx, $specId) {
    global $db;

    $versionString = lang_get('version_short');
    $specs = reqSpecCombo($ctx['tproject_id']);
    $selectedName = '';
    foreach ($specs as $s) {
        if ($s['id'] === $specId) {
            $selectedName = $s['name'];
            break;
        }
    }

    list($linkingEnabled, $reason) = linkingGate($ctx['tcase_id']);

    // reqEditorType 'none' keeps the raw scope (nl2br), otherwise the legacy
    // template stripped tags and truncated it to SCOPE_SHORT_TRUNCATE, which
    // the legacy Smarty template read from gui/templates/conf/
    // input_dimensions.conf (global value, 30 by default).
    $reqEdCfg = getWebEditorCfg('requirement');
    $reqEditorType = is_array($reqEdCfg) && isset($reqEdCfg['type'])
                     ? $reqEdCfg['type'] : 'none';
    $scopeTruncate = scopeShortTruncate();

    $assigned = assignedRows($specId, $ctx['tcase_id'], $versionString);
    // Legacy parity: the "available" grid was ALWAYS filled - the template only
    // disabled cbDisabled on its check boxes / the assign button, it did not
    // hide the rows. Keeping the rows visible also tells the user what could
    // be linked once the gate is lifted. An empty grid is only correct when
    // there is no requirement specification selected.
    $free = ($specId > 0) ? freeRows($specId, $ctx['tcase_id'], $versionString) : [];

    // legacy cbDisabled: freezeLinkOnNewREQVersion + can_be_removed = 0
    $freeze = !empty(config_get('reqTCLinks')->freezeLinkOnNewREQVersion);
    foreach ($assigned as $k => $row) {
        $assigned[$k]['removable'] = $linkingEnabled
                                    && (!$freeze || $row['can_be_removed']);
        $assigned[$k]['req_view_url'] =
            '/gui/templates/requirements/reqView.html?id=' . $row['req_id']
            . '&tproject_id=' . $ctx['tproject_id'];
    }
    foreach ($free as $k => $row) {
        $free[$k]['req_view_url'] =
            '/gui/templates/requirements/reqView.html?id=' . $row['req_id']
            . '&tproject_id=' . $ctx['tproject_id'];
    }

    return [
        'status' => 'ok',
        'tproject_id' => $ctx['tproject_id'],
        'tproject_name' => $ctx['tproject_name'],
        'tcase' => [
            'id' => $ctx['tcase_id'],
            'name' => $ctx['tcase_name'],
            'tcversion_id' => $ctx['tcversion_id'],
            'version' => $ctx['tcversion'],
        ],
        'requirements_enabled' => $ctx['requirements_enabled'],
        'req_specs' => $specs,
        'has_req_spec' => count($specs) > 0,
        'selected_req_spec' => ['id' => $specId, 'name' => $selectedName],
        'assigned' => $assigned,
        'free' => $free,
        'counts' => ['assigned' => count($assigned), 'free' => count($free)],
        'grants' => [
            'req_tcase_link_management' => true,
            'link' => $linkingEnabled,
            'reason' => $reason,
        ],
        'freeze_link_on_new_version' => $freeze,
        'req_editor_type' => $reqEditorType,
        'scope_truncate' => $scopeTruncate,
        'version_label' => $versionString,
    ];
}

$method = $_SERVER['REQUEST_METHOD'];

// The action travels in the query string (?action=...), like every other
// modernized BFF. It is also accepted from the JSON body so a caller that
// posts the action in the payload is not mistaken for a missing action.
$action = '';
if (isset($_REQUEST['action']) && is_scalar($_REQUEST['action'])) {
    $action = strtolower(trim((string) $_REQUEST['action']));
} elseif ($method === 'POST' && ($body = getBody()) && isset($body['action'])
        && is_scalar($body['action'])) {
    $action = strtolower(trim((string) $body['action']));
}

// ---------------------------------------------------------------------------
// GET ?action=init
// ---------------------------------------------------------------------------
if ($action === 'init') {
    if ($method !== 'GET') {
        http_response_code(405);
        out(['status' => 'error', 'message' => 'GET required']);
    }
    $tprojectId = intval($_REQUEST['tproject_id'] ?? 0);
    $tcaseId = intval($_REQUEST['tcase_id'] ?? 0);
    $ctx = resolveCtx($tprojectId, $tcaseId);

    $specs = reqSpecCombo($tprojectId);

    // Legacy selection order: ?idSRS wins, then the session memory, then the
    // first combo entry.
    $requested = 0;
    if (isset($_REQUEST['idSRS']) && is_scalar($_REQUEST['idSRS'])) {
        $requested = intval($_REQUEST['idSRS']);
    }
    $sessionSrs = intval($_SESSION['currentSrsId'] ?? 0);
    $selected = 0;
    if ($requested > 0) {
        // a spec id of ANOTHER test project must not be read or remembered
        $selected = resolveSpecId($tprojectId, $requested);
        if ($selected === 0) {
            notFound('Requirement specification not found in this test project');
        }
    } elseif ($sessionSrs > 0) {
        $selected = resolveSpecId($tprojectId, $sessionSrs);
    }
    if ($selected <= 0 && count($specs) > 0) {
        $selected = $specs[0]['id'];
    }
    if ($selected > 0) {
        $_SESSION['currentSrsId'] = $selected;
    }

    $payload = buildPayload($ctx, $selected);
    $payload['action'] = 'init';
    out($payload);
}

// ---------------------------------------------------------------------------
// POST ?action=assign | unassign | unlink
// ---------------------------------------------------------------------------
if ($action === 'assign' || $action === 'unassign' || $action === 'unlink') {
    if ($method !== 'POST') {
        http_response_code(405);
        out(['status' => 'error', 'message' => 'POST required']);
    }
    $payload = getBody();
    if (!is_array($payload)) {
        badRequest('Invalid JSON body');
    }
    $ctx = resolveCtx(intval($payload['tproject_id'] ?? 0),
                      intval($payload['tcase_id'] ?? 0));

    // The legacy template hid the Assign/Unassign buttons entirely when the
    // linking gate was off; the server must refuse the write too, otherwise
    // the gate is only cosmetic.
    list($linkingEnabled, $reason) = linkingGate($ctx['tcase_id']);
    if (!$linkingEnabled) {
        http_response_code(409);
        out(['status' => 'error', 'reason' => $reason,
             'message' => 'Requirement linking is disabled for an executed test case']);
    }

    $specId = 0;
    if (isset($payload['idSRS']) && is_scalar($payload['idSRS'])) {
        $specId = intval($payload['idSRS']);
    }
    if ($specId <= 0) {
        $specId = intval($_SESSION['currentSrsId'] ?? 0);
    }
    $specId = resolveSpecId($ctx['tproject_id'], $specId);
    if ($specId <= 0) {
        badRequest('Requirement specification not found in this test project');
    }

    $reqMgr = new requirement_mgr($db);

    if ($action === 'assign') {
        $reqIds = toIdSet($payload['req_id'] ?? null);
        if (count($reqIds) === 0) {
            // legacy: check_action_precondition() blocked with
            // "please_select_a_req"
            badRequest('Nothing selected');
        }
        // Only requirements that really live on the selected spec of this
        // project may be linked (cross-spec / cross-project hardening).
        $valid = [];
        foreach (freeRows($specId, $ctx['tcase_id'], lang_get('version_short')) as $r) {
            $valid[$r['req_id']] = true;
        }
        $accepted = [];
        $rejected = [];
        foreach ($reqIds as $id) {
            if (isset($valid[$id])) {
                $accepted[] = $id;
            } else {
                $rejected[] = $id;
            }
        }
        if (count($accepted) === 0) {
            badRequest('None of the selected requirements are available on this specification');
        }
        $done = 0;
        foreach ($accepted as $rid) {
            $reqMgr->assignToTCaseUsingLatestVersions($rid, $ctx['tcase_id'], $userId);
            $done++;
        }
        if ($done > 0) {
            logAuditEvent(
                TLS('audit_req_assigned_tc',
                    $done . ' requirement(s) of Req Spec #' . $specId,
                    $ctx['tcase_name']),
                'ASSIGN', $ctx['tcase_id'], 'testcases');
        }
        $resp = buildPayload($ctx, $specId);
        $resp['action'] = 'assign';
        $resp['assigned_count'] = $done;
        $resp['rejected'] = $rejected;
        out($resp);
    }

    // ---- unassign / unlink -------------------------------------------------
    // The legacy grid submitted the checked req_coverage link ids. They are
    // re-proven here against this test case + this spec + the latest active
    // test case version, so a crafted id cannot unlink somebody else's link.
    $linkIds = toIdSet($payload['link_id'] ?? null);
    if (count($linkIds) === 0) {
        badRequest('Nothing selected');
    }
    $allowed = [];
    foreach (assignedRows($specId, $ctx['tcase_id'], lang_get('version_short')) as $r) {
        if ($r['link_id'] > 0) {
            $allowed[$r['link_id']] = $r;
        }
    }
    $accepted = [];
    $rejected = [];
    foreach ($linkIds as $lid) {
        if (isset($allowed[$lid])) {
            $accepted[] = $lid;
        } else {
            $rejected[] = $lid;
        }
    }
    if (count($accepted) === 0) {
        badRequest('None of the selected links belong to this test case on this specification');
    }
    // freezeLinkOnNewREQVersion: the legacy template disabled the checkbox of a
    // link that can no longer be removed
    $freeze = !empty(config_get('reqTCLinks')->freezeLinkOnNewREQVersion);
    $t = tlObjectWithDB::getDBTables('req_coverage');
    $removed = 0;
    $blocked = 0;
    foreach ($accepted as $lid) {
        if ($freeze && !$allowed[$lid]['can_be_removed']) {
            $blocked++;
            continue;
        }
        $db->exec_query(
            "DELETE FROM {$t['req_coverage']} WHERE id = " . intval($lid));
        $removed++;
    }
    if ($removed > 0) {
        logAuditEvent(
            TLS('audit_req_assignment_removed_tc',
                $removed . ' requirement link(s) of Req Spec #' . $specId,
                $ctx['tcase_name']),
            'UNASSIGN', $ctx['tcase_id'], 'testcases');
    }
    $resp = buildPayload($ctx, $specId);
    $resp['action'] = ($action === 'unlink') ? 'unlink' : 'unassign';
    $resp['unassigned_count'] = $removed;
    $resp['rejected'] = $rejected;
    $resp['blocked'] = $blocked;
    out($resp);
}

badRequest('Unknown or missing action');
