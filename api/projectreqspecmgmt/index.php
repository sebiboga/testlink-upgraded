<?php
/**
 * api/projectreqspecmgmt — Test Project Requirement Specification launcher BFF
 * (Refs #1833)
 *
 * Modern twin of the last project-scoped legacy controller left in the
 * Requirements area:
 *   lib/project/project_req_spec_mgmt.php          (46 lines)
 *   gui/templates/dashio/requirements/project_req_spec_mgmt.tpl
 *
 * The legacy screen is a pure GATED LAUNCHER: a context line
 * ("Test project > <name> > Requirement Specification") plus four buttons that
 * only navigate somewhere else:
 *   New requirement specification -> reqSpecEdit.php?doAction=create&tproject_id=
 *   Reorder requirement specs     -> reqSpecEdit.php?doAction=reorder&tproject_id=
 *   Import requirement specs      -> reqImport.php?scope=tree&tproject_id=
 *   Export all requirement specs  -> reqExport.php?scope=tree&tproject_id=
 *
 * Gates in the legacy template (dashio/requirements/project_req_spec_mgmt.tpl):
 *   New / Reorder / Import -> `$gui->grants->modify` = mgt_modify_req
 *   Export                 -> always visible, but the whole page is behind
 *                             pageAccessCheck rightsOr=[mgt_view_req,mgt_modify_req]
 * so a mgt_view_req-only user keeps exactly one button (Export) and a
 * mgt_modify_req-only user keeps all four. Ported 1:1.
 *
 * Hardening vs legacy:
 *  - the legacy page took the project from $_SESSION['testprojectID'] and never
 *    verified it; a modern `tproject_id` is accepted but the project must
 *    really exist, otherwise 404 instead of an empty launcher;
 *  - the four action URLs are built SERVER-side from validated ids, so the
 *    screen cannot be talked into linking to a foreign project;
 *  - the counters (specifications / requirements) are additive: they give the
 *    launcher something to show, which the legacy screen never did.
 *
 *  GET ?action=init[&tproject_id=N]
 *
 * 401 anon / session expired · 403 no right (neither mgt_view_req nor
 * mgt_modify_req) or same-origin · 404 unknown project · 405 wrong verb
 *
 * Session-based auth, JSON I/O. No Smarty.
 */
require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

// Cheap session gate BEFORE the DB connect (issue #1677): common.php echoes a
// raw dbms_msg on a failed connect, which would leak host/database name in the
// body of an HTTP 200 for an unauthenticated request.
if (intval($_SESSION['userID'] ?? 0) <= 0) {
    fail(401, 'session_expired', 'Session expired or not authenticated.');
}

require_once(__DIR__ . '/../_guard.php');

$action = trim((string)($_GET['action'] ?? ''));
if ((string)($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    fail(405, 'method_not_allowed', 'Only GET is accepted.');
}
if ($action !== 'init') {
    fail(400, 'unknown_action', 'Unknown action.');
}

$db = new database(DB_TYPE);
$conn = doDBConnect($db);
if (!empty($conn) && isset($conn['status']) && !$conn['status']) {
    fail(500, 'server_error', 'Database connection failed.');
}
bffEnforceSession($db);

$T = tlObjectWithDB::getDBTables([
    'testprojects', 'nodes_hierarchy', 'node_types', 'tcversions',
]);

$userId = intval($_SESSION['userID']);
$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    fail(401, 'session_expired', 'User not found.');
}

/**
 * helpers
 */
function fail(int $code, string $machine, string $message): void
{
    if (!headers_sent()) {
        http_response_code($code);
    }
    echo json_encode([
        'status'  => 'error',
        'code'    => $machine,
        'message' => $message,
    ]);
    exit;
}

function firstRow($db, string $sql): ?array
{
    $rs = $db->get_recordset($sql);
    if (is_null($rs) || !$rs) {
        return null;
    }
    return (array)reset($rs);
}

function nodeTypes($db, $T): array
{
    static $types = null;
    if (!is_null($types)) {
        return $types;
    }
    $types = [];
    foreach ((array)$db->get_recordset("SELECT id, description FROM {$T['node_types']}") as $r) {
        $types[strtolower((string)$r['description'])] = (int)$r['id'];
    }
    return $types;
}

try {
    /* ------------------------------------------------------------------ */
    /* GET ?action=init                                                    */
    /* ------------------------------------------------------------------ */
    $rawTproject = trim((string)($_GET['tproject_id'] ?? ''));
    if ($rawTproject === '') {
        $tprojectId = intval($_SESSION['testprojectID'] ?? 0);
    } else {
        if (!preg_match('/^\d+$/', $rawTproject)) {
            fail(400, 'bad_param', "Parameter 'tproject_id' must be an integer.");
        }
        $tprojectId = (int)$rawTproject;
    }

    if ($tprojectId <= 0) {
        fail(400, 'bad_param', 'No test project selected (tproject_id missing and none in the session).');
    }

    // Legacy rightsOr = [mgt_view_req, mgt_modify_req] -> an OR, so a
    // view-only OR a modify-only manager reaches the page.
    $canModify = (bool)$user->hasRight($db, 'mgt_modify_req', $tprojectId);
    $canView = (bool)$user->hasRight($db, 'mgt_view_req', $tprojectId);
    if (!$canModify && !$canView) {
        // logger.class.php only knows DEBUG/INFO/WARNING/ERROR/AUDIT/L18N -
        // there is no SECURITY level, so passing one raises "Undefined array
        // key" (logging.inc.php:105) on every denial. Every other BFF audits a
        // denied read as AUDIT.
        $event = new stdClass();
        $event->message = 'Access denied to projectReqSpecMgmt (tproject=' . $tprojectId . ')';
        $event->logLevel = 'AUDIT';
        $event->source = 'GUI';
        $event->objectID = $tprojectId;
        $event->objectType = 'testprojects';
        $event->code = 'REQ_SPEC_MGMT_ACCESS_DENIED';
        logEvent($event);
        fail(403, 'no_right', 'You need mgt_view_req or mgt_modify_req on this test project.');
    }

    // NOTE: option_reqs is a legacy leftover column that 2.0.1 no longer
    // maintains - the live flags live in the serialized testprojects.options
    // blob (testproject::getOptions), which is what projectEdit writes.
    $tproject = firstRow($db, "SELECT id, prefix FROM {$T['testprojects']} WHERE id=" . $tprojectId);
    if (!$tproject) {
        fail(404, 'tproject_not_found', 'Test project not found.');
    }

    $tprojMgr = new testproject($db);

    // The project name lives in nodes_hierarchy (testprojects has no name
    // column in 2.0.1).
    $nameRow = firstRow($db, "SELECT name FROM {$T['nodes_hierarchy']} WHERE id=" . $tprojectId);
    $tprojectName = $nameRow ? (string)$nameRow['name'] : '';

    $nt = nodeTypes($db, $T);
    $ntSpec = (int)($nt['requirement_spec'] ?? 0);
    $ntReq = (int)($nt['requirement'] ?? 0);

    // Counters. nodes_hierarchy has NO project column, so ownership has to be
    // derived from the parent chain. Doing that with one query per node (an
    // N+1 over every requirement in the installation) is the obvious way to get
    // it wrong, so the whole hierarchy is read ONCE and the tree is walked in
    // memory: nodes_hierarchy is a few thousand rows at most.
    $counts = countProjectNodes($db, $T, $tprojectId, [$ntSpec, $ntReq]);
    $qtySpecs = (int)($counts[$ntSpec] ?? 0);
    $qtyReqs = (int)($counts[$ntReq] ?? 0);

    $ctx = 'tproject_id=' . $tprojectId;
    $base = '/gui/templates/requirements/';

    // The four legacy destinations, all of them ALREADY modern screens. Built
    // here (not in the browser) so the ids in them are the proven ones.
    $actions = [
        'create' => $canModify
            ? ['label' => 'prsm.btnNewReqSpec', 'url' => $base . 'reqSpecMgmt.html?' . $ctx, 'icon' => 'fa-plus']
            : null,
        'reorder' => $canModify
            ? ['label' => 'prsm.btnReorderReqSpec', 'url' => $base . 'reqSpecMgmt.html?' . $ctx, 'icon' => 'fa-sort-amount-down']
            : null,
        'import' => $canModify
            ? ['label' => 'prsm.btnImport', 'url' => $base . 'reqImport.html?' . $ctx . '&scope=tree', 'icon' => 'fa-upload']
            : null,
        'export' => ['label' => 'prsm.btnExportAllReqSpec', 'url' => $base . 'reqExport.html?' . $ctx . '&scope=tree', 'icon' => 'fa-download'],
    ];

    echo json_encode([
        'status' => 'ok',
        'action' => 'init',
        'context' => [
            'tproject_id'   => $tprojectId,
            'tproject_name' => $tprojectName,
            'tproject_prefix' => (string)$tproject['prefix'],
        ],
        'grants' => [
            'can_view'   => $canView,
            'can_modify' => $canModify,
        ],
        'requirements_enabled' => (bool)$tprojMgr->getOptions($tprojectId)->requirementsEnabled,
        'qty' => [
            'specifications' => $qtySpecs,
            'requirements'   => $qtyReqs,
        ],
        'actions' => $actions,
    ]);
} catch (Throwable $e) {
    // Keep the JSON contract intact even when the legacy stack fatals (#1423).
    error_log('projectreqspecmgmt BFF init: ' . $e->getMessage());
    fail(500, 'server_error', 'The request could not be completed.');
}

/**
 * Walk the hierarchy DOWN from the test project and count the nodes of each
 * wanted type. One SELECT for the whole nodes_hierarchy table, then pure PHP.
 *
 * @return array node_type_id => qty, for the types in $wanted (0 entries omitted)
 */
function countProjectNodes($db, $T, int $tprojectId, array $wanted): array
{
    $want = [];
    foreach ($wanted as $t) {
        $t = (int)$t;
        if ($t > 0) {
            $want[$t] = true;
        }
    }
    $out = [];
    if (!$want) {
        return $out;
    }

    $children = [];
    foreach ((array)$db->get_recordset(
        "SELECT id, parent_id, node_type_id FROM {$T['nodes_hierarchy']}"
    ) as $r) {
        $children[(int)$r['parent_id']][] = [(int)$r['id'], (int)$r['node_type_id']];
    }

    $stack = [$tprojectId];
    $seen = [];
    while ($stack) {
        $current = array_pop($stack);
        if (!isset($children[$current])) {
            continue;
        }
        foreach ($children[$current] as $child) {
            [$childId, $childType] = $child;
            // A malformed/looped hierarchy must not spin forever.
            if (isset($seen[$childId])) {
                continue;
            }
            $seen[$childId] = true;
            if (isset($want[$childType])) {
                $out[$childType] = ($out[$childType] ?? 0) + 1;
            }
            $stack[] = $childId;
        }
    }
    return $out;
}
