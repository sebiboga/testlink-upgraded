<?php
/**
 * Requirement Management System EDITOR BFF API
 * URL: /api/reqmgrsystemedit/
 * Plain PHP, no framework, no compilation.
 *
 * Backs the modern standalone editor gui/templates/reqmgrsystems/reqMgrSystemEdit.html,
 * the modern twin of the legacy controller lib/reqmgrsystems/reqMgrSystemEdit.php
 * (+ gui/templates/dashio/reqmgrsystems/reqMgrSystemEdit.tpl and its tl-classic twin)
 * and of the legacy ajax helper lib/ajax/getreqmgrsystemcfgtemplate.php that the
 * template called for the "show configuration example" link.
 *
 * Legacy parity (see the docs mirror docs/Modernized-ReqMgrSystemEdit.md):
 *   doAction=create / edit   -> GET  ?action=init[&id=N]
 *   doAction=doCreate        -> POST ?action=create
 *   doAction=doUpdate        -> POST ?action=update
 *   doAction=doDelete        -> POST ?action=delete
 *   doAction=checkConnection -> POST ?action=check_connection
 *   displayCfgExample()      -> GET  ?action=cfg_template&type=N
 *
 * The legacy screen was gated by exactly ONE right - reqmgrsystem_management
 * (checkRights() at the bottom of reqMgrSystemEdit.php). It is enforced here on
 * EVERY route, reads included, so the standalone screen can not become a
 * configuration-disclosure oracle for a view-only role.
 *
 * Hardening over the legacy controller (all of it was missing there):
 *   - bffEnforceSession(): the legacy controller ran testlinkInitPage(), which
 *     called checkSessionValid(); without it an idle tab kept reading AND
 *     writing configuration (issue #1614 family).
 *   - bffSameOriginGuard(): the legacy form was a plain cross-site-postable
 *     POST to a .php URL with no origin proof.
 *   - no dead switch branch that can answer a blank 200 (issue #1722): an
 *     unknown action is a 400 with a machine code.
 *   - a deleted / unknown id is a 404 with a machine code instead of a broken
 *     form plus E_WARNING rows (issue #1721 family).
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

// Legacy parity: testlinkInitPage() ran checkSessionValid() on every page load.
bffEnforceSession($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || $userId <= 0) {
    bffOut(401, array('code' => 'not_authenticated', 'message' => 'Not authenticated'));
}

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    bffOut(401, array('code' => 'not_authenticated', 'message' => 'User not found'));
}

$action = isset($_GET['action']) ? (string)$_GET['action'] : '';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

function bffOut($code, $payload) {
    if (!headers_sent()) {
        http_response_code($code);
    }
    echo json_encode($payload);
    exit;
}

function bffBody() {
    $raw = file_get_contents('php://input');
    $data = json_decode((string)$raw, true);
    return is_array($data) ? $data : array();
}

/**
 * Legacy checkRights(): the editor is ONE right, no read/manage split.
 * api/reqmgrsystems (the LIST screen) has the split; this screen does not.
 */
function canManage(&$db, &$user) {
    return (bool)$user->hasRight($db, 'reqmgrsystem_management');
}

$mgr = new tlReqMgrSystem($db);

$WRITES = array('create', 'update', 'delete', 'check_connection');
$READS  = array('init', 'cfg_template');

// ---------------------------------------------------------------------------
// Right gate first, for EVERY action: no configuration row is revealed to a
// caller without the management right, and an unknown id must not be usable
// as an existence oracle either.
// ---------------------------------------------------------------------------
if (!canManage($db, $user)) {
    bffOut(403, array('code' => 'forbidden', 'message' => 'Forbidden'));
}

if ($action === '' || !in_array($action, array_merge($READS, $WRITES), true)) {
    bffOut(400, array('code' => 'unknown_action', 'message' => 'Unknown action'));
}

$isWrite = in_array($action, $WRITES, true);
if ($isWrite && $method !== 'POST') {
    bffOut(405, array('code' => 'method_not_allowed', 'message' => 'POST required'));
}
if (!$isWrite && $method !== 'GET') {
    bffOut(405, array('code' => 'method_not_allowed', 'message' => 'GET required'));
}

/**
 * Type domain, shaped for a <select>. Legacy: $gui->typeDomain =
 * $mgr->getTypes() = "<type name> (Interface: <api>)" keyed by type code.
 */
function typeDomain($mgr) {
    $types = $mgr->getTypes();
    $out = array();
    if (is_array($types)) {
        foreach ($types as $code => $descr) {
            $out[] = array(
                'code'   => intval($code),
                'label'  => (string)$descr,
                'api'    => isset($mgr->systems[$code]) ? $mgr->systems[$code]['api'] : '',
                'type'   => isset($mgr->systems[$code]) ? $mgr->systems[$code]['type'] : '',
            );
        }
    }
    usort($out, function ($a, $b) { return $a['code'] - $b['code']; });
    return $out;
}

function itemToJson($item, $mgr) {
    return array(
        'id'   => intval($item['id']),
        'name' => (string)$item['name'],
        'type' => intval($item['type']),
        'cfg'  => isset($item['cfg']) ? (string)$item['cfg'] : '',
    );
}

// ---------------------------------------------------------------------------
// GET ?action=init[&id=N] - the create form (id absent / 0) or the edit form.
// Legacy parity: initializeGui() removed DEAD links to deleted test projects
// before listing the live ones ("just to fix erroneous test project delete").
// ---------------------------------------------------------------------------
if ($action === 'init') {
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    $domain = typeDomain($mgr);

    if ($id <= 0) {
        bffOut(200, array(
            'status'  => 'ok',
            'mode'    => 'create',
            'item'    => array('id' => 0, 'name' => '', 'type' => 0, 'cfg' => ''),
            'types'   => $domain,
            'testprojects' => array(),
            'mgt_view_events' => (bool)$user->hasRight($db, 'mgt_view_events'),
        ));
    }

    $item = $mgr->getByID($id);
    if (is_null($item) || !isset($item['id'])) {
        bffOut(404, array('code' => 'not_found',
                          'message' => 'Requirement management system not found'));
    }

    $dummy = $mgr->getLinks($id, array('getDeadLinks' => true));
    if (is_null($dummy)) {
        $dummy = array();
    }
    foreach ($dummy as $tprojectId => $elem) {
        $mgr->unlink($id, $tprojectId);
    }

    $projects = array();
    $links = $mgr->getLinks($id);
    if (is_array($links)) {
        foreach ($links as $tprojectId => $elem) {
            $projects[] = array(
                'testproject_id'   => intval($tprojectId),
                'testproject_name' => isset($elem['testproject_name'])
                                      ? (string)$elem['testproject_name'] : '',
            );
        }
    }

    bffOut(200, array(
        'status'  => 'ok',
        'mode'    => 'edit',
        'item'    => itemToJson($item, $mgr),
        'types'   => $domain,
        'testprojects' => $projects,
        'mgt_view_events' => (bool)$user->hasRight($db, 'mgt_view_events'),
    ));
}

// ---------------------------------------------------------------------------
// GET ?action=cfg_template&type=N
// Mirrors lib/ajax/getreqmgrsystemcfgtemplate.php, which the legacy template
// called from displayCfgExample(). Issues #1625/#1626: a type with no loadable
// interface implementation must degrade to a message, never a fatal.
// ---------------------------------------------------------------------------
if ($action === 'cfg_template') {
    $type = isset($_GET['type']) ? intval($_GET['type']) : 0;
    $types = $mgr->getTypes();
    if (!isset($types[$type])) {
        bffOut(400, array('code' => 'invalid_type', 'message' => 'Invalid type'));
    }
    $iname = $mgr->getImplementationForType($type);
    if (is_null($iname) || @stream_resolve_include_path($iname . '.class.php') === false) {
        bffOut(200, array(
            'status'    => 'ok',
            'available' => false,
            'cfg'       => '',
            'message'   => is_null($iname)
                            ? 'Interface for type ' . $type . ' not implemented'
                            : 'Interface ' . $iname . ' not implemented',
        ));
    }
    try {
        $cfg = call_user_func(array($iname, 'getCfgTemplate'));
        bffOut(200, array('status' => 'ok', 'available' => true,
                          'cfg' => (string)$cfg, 'message' => ''));
    } catch (Throwable $e) {
        tLog('reqmgrsystemedit cfg_template type=' . $type . ': ' . $e->getMessage(), 'ERROR');
        bffOut(200, array('status' => 'ok', 'available' => false, 'cfg' => '',
                          'message' => 'Interface ' . $iname . ' not implemented'));
    }
}

// ---------------------------------------------------------------------------
// POST ?action=create  { name, type, cfg }
// ---------------------------------------------------------------------------
if ($action === 'create') {
    $body = bffBody();
    $name = isset($body['name']) ? trim((string)$body['name']) : '';
    $type = isset($body['type']) ? intval($body['type']) : 0;
    $cfg  = isset($body['cfg']) ? (string)$body['cfg'] : '';

    if ($name === '') {
        bffOut(400, array('code' => 'validation_failed',
                          'message' => 'empty name is not allowed'));
    }
    $types = $mgr->getTypes();
    if (!isset($types[$type])) {
        bffOut(400, array('code' => 'invalid_type', 'message' => 'Invalid type'));
    }

    $it = new stdClass();
    $it->name = $name;
    $it->type = $type;
    $it->cfg  = $cfg;

    $op = $mgr->create($it);
    if (!empty($op['status_ok'])) {
        $created = $mgr->getByID($op['id']);
        bffOut(200, array('status' => 'ok', 'id' => intval($op['id']),
                          'item' => is_null($created) ? null : itemToJson($created, $mgr),
                          'message' => ''));
    }
    bffOut(409, array('code' => 'create_failed', 'message' => (string)$op['msg']));
}

// ---------------------------------------------------------------------------
// POST ?action=update  { id, name, type, cfg }
// ---------------------------------------------------------------------------
if ($action === 'update') {
    $body = bffBody();
    $id = isset($body['id']) ? intval($body['id']) : 0;
    if ($id <= 0) {
        bffOut(400, array('code' => 'invalid_id', 'message' => 'Invalid id'));
    }
    $existing = $mgr->getByID($id);
    if (is_null($existing) || !isset($existing['id'])) {
        bffOut(404, array('code' => 'not_found',
                          'message' => 'Requirement management system not found'));
    }

    $name = isset($body['name']) ? trim((string)$body['name']) : '';
    $type = isset($body['type']) ? intval($body['type']) : intval($existing['type']);
    $cfg  = isset($body['cfg']) ? (string)$body['cfg'] : (string)($existing['cfg'] ?? '');

    if ($name === '') {
        bffOut(400, array('code' => 'validation_failed',
                          'message' => 'empty name is not allowed'));
    }
    $types = $mgr->getTypes();
    if (!isset($types[$type])) {
        bffOut(400, array('code' => 'invalid_type', 'message' => 'Invalid type'));
    }

    $it = new stdClass();
    $it->id   = $id;
    $it->name = $name;
    $it->type = $type;
    $it->cfg  = $cfg;

    $op = $mgr->update($it);
    if (!empty($op['status_ok'])) {
        $updated = $mgr->getByID($id);
        bffOut(200, array('status' => 'ok', 'id' => $id,
                          'item' => is_null($updated) ? null : itemToJson($updated, $mgr),
                          'message' => ''));
    }
    bffOut(409, array('code' => 'update_failed', 'message' => (string)$op['msg']));
}

// ---------------------------------------------------------------------------
// POST ?action=delete  { id }
// Legacy doDelete(): tlReqMgrSystem::delete() refuses while the system is
// still linked to a test project, and that refusal is surfaced (409).
// ---------------------------------------------------------------------------
if ($action === 'delete') {
    $body = bffBody();
    $id = isset($body['id']) ? intval($body['id']) : 0;
    if ($id <= 0) {
        bffOut(400, array('code' => 'invalid_id', 'message' => 'Invalid id'));
    }
    $existing = $mgr->getByID($id);
    if (is_null($existing) || !isset($existing['id'])) {
        bffOut(404, array('code' => 'not_found',
                          'message' => 'Requirement management system not found'));
    }
    $op = $mgr->delete($id);
    if (!empty($op['status_ok'])) {
        bffOut(200, array('status' => 'ok', 'id' => $id, 'message' => ''));
    }
    bffOut(409, array('code' => 'delete_failed', 'message' => (string)$op['msg']));
}

// ---------------------------------------------------------------------------
// POST ?action=check_connection  { id }
// Legacy checkConnection() delegates to tlReqMgrSystem::checkConnection(),
// which degrades to false when the interface is not shipped (#1625).
// ---------------------------------------------------------------------------
if ($action === 'check_connection') {
    $body = bffBody();
    $id = isset($body['id']) ? intval($body['id']) : 0;
    if ($id <= 0) {
        bffOut(400, array('code' => 'invalid_id', 'message' => 'Invalid id'));
    }
    $item = $mgr->getByID($id);
    if (is_null($item) || !isset($item['id'])) {
        bffOut(404, array('code' => 'not_found',
                          'message' => 'Requirement management system not found'));
    }
    $impl = isset($item['implementation']) ? $item['implementation'] : null;
    if (is_null($impl)) {
        bffOut(200, array('status' => 'error', 'connected' => false,
                          'code' => 'not_implemented',
                          'message' => 'Interface for type ' . intval($item['type'])
                                       . ' not implemented'));
    }
    if (!@class_exists($impl)) {
        bffOut(200, array('status' => 'error', 'connected' => false,
                          'code' => 'not_implemented',
                          'message' => 'Interface ' . $impl . ' not implemented'));
    }
    try {
        $connected = (bool)$mgr->checkConnection($id);
        bffOut(200, array('status' => $connected ? 'ok' : 'error',
                          'connected' => $connected,
                          'code' => $connected ? 'connected' : 'connection_failed',
                          'message' => $connected ? 'Connection OK' : 'Connection failed'));
    } catch (Throwable $e) {
        tLog('reqmgrsystemedit check_connection id=' . $id . ': ' . $e->getMessage(), 'ERROR');
        bffOut(200, array('status' => 'error', 'connected' => false,
                          'code' => 'connection_failed', 'message' => $e->getMessage()));
    }
}

bffOut(400, array('code' => 'unknown_action', 'message' => 'Unknown action'));
