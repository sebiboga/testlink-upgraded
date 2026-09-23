<?php
/**
 * Custom Fields BFF API
 * URL: /api/cfields/
 * Plain PHP, no framework, no compilation
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
require_once('users.inc.php');

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

// Legacy lib/cfields/cfieldsView.php:30 lets any user holding EITHER
// cfield_view OR cfield_management browse the custom-field list (Refs #950).
// Read routes are therefore gated on $canView. Write routes (POST/PUT/DELETE)
// and the assignment endpoints stay on $canManage, mirroring
// lib/cfields/cfieldsEdit.php:494 and lib/cfields/cfieldsTProjectAssign.php:150.
$canView = $user->hasRight($db, 'cfield_view') || $user->hasRight($db, 'cfield_management');
$canManage = $user->hasRight($db, 'cfield_management');

if (!$canView) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'No permission']);
    exit;
}

$path = $_SERVER['PATH_INFO'] ?? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = preg_replace('#^/api/cfields(/index\.php)?#', '', $path);
$path = '/' . trim($path, '/');
$method = $_SERVER['REQUEST_METHOD'];
$segments = array_values(array_filter(explode('/', $path)));

function out($data) { echo json_encode($data); exit; }
function getParam($key, $default = null) { return $_GET[$key] ?? $default; }
function getBody() { return json_decode(file_get_contents('php://input'), true) ?? []; }
function deny() {
    http_response_code(403);
    out(['status' => 'error', 'message' => 'No permission']);
}

$cfield_mgr = new cfield_mgr($db);

// Legacy cfield_mgr::is_used (lib/functions/cfield_mgr.class.php:1486) reports a
// custom field as "used" the moment any value row exists in one of the four value
// tables. Editing such a field must NOT allow changing its type or node type
// (warning_no_type_change semantics). The set is resolved once per request so
// list endpoints pay one UNION query instead of one is_used() per row.
function usedFieldIds() {
    static $set = null;
    global $cfield_mgr;
    if ($set !== null) { return $set; }
    $t = tlObject::getDBTables(['cfield_design_values', 'cfield_build_design_values',
                                'cfield_testplan_design_values', 'cfield_execution_values']);
    $sql = "SELECT DISTINCT field_id FROM {$t['cfield_design_values']} " .
           "UNION SELECT DISTINCT field_id FROM {$t['cfield_build_design_values']} " .
           "UNION SELECT DISTINCT field_id FROM {$t['cfield_testplan_design_values']} " .
           "UNION SELECT DISTINCT field_id FROM {$t['cfield_execution_values']}";
    $ids = array_map('intval', (array) $cfield_mgr->db->fetchColumnsIntoArray($sql, 'field_id'));
    $set = array_flip($ids);
    return $set;
}

function cfToJSON($cf, $isUsed = 0) {
    return [
        'id' => intval($cf['id']),
        'name' => $cf['name'],
        'label' => $cf['label'],
        'type' => intval($cf['type']),
        'possible_values' => $cf['possible_values'] ?? '',
        'show_on_design' => intval($cf['show_on_design']),
        'enable_on_design' => intval($cf['enable_on_design']),
        'show_on_execution' => intval($cf['show_on_execution']),
        'enable_on_execution' => intval($cf['enable_on_execution']),
        'show_on_testplan_design' => intval($cf['show_on_testplan_design']),
        'enable_on_testplan_design' => intval($cf['enable_on_testplan_design']),
        'node_type_id' => isset($cf['node_type_id']) ? intval($cf['node_type_id']) : 0,
        'node_description' => $cf['node_description'] ?? '',
        'active' => isset($cf['active']) ? intval($cf['active']) : 1,
        'default_value' => $cf['default_value'] ?? '',
        // 1 when the field already holds values -> type/node_type are locked.
        'is_used' => intval($isUsed) ? 1 : 0,
    ];
}

// Route: GET /meta/types - available custom field types
if ($method === 'GET' && isset($segments[0]) && $segments[0] === 'meta' && isset($segments[1]) && $segments[1] === 'types') {
    $types = $cfield_mgr->get_available_types();
    $items = [];
    foreach ($types as $id => $name) {
        $items[] = ['id' => intval($id), 'name' => $name];
    }
    out(['status' => 'ok', 'items' => $items]);
}

// Route: GET /meta/nodes - allowed node types
if ($method === 'GET' && isset($segments[0]) && $segments[0] === 'meta' && isset($segments[1]) && $segments[1] === 'nodes') {
    $nodes = $cfield_mgr->get_allowed_nodes();
    $items = [];
    foreach ($nodes as $verbose => $id) {
        $items[] = ['id' => intval($id), 'name' => $verbose];
    }
    out(['status' => 'ok', 'items' => $items]);
}

// Route: GET / - list all custom fields
if ($method === 'GET' && empty($segments)) {
    $map = $cfield_mgr->get_all();
    $items = [];
    if ($map) {
        $used = usedFieldIds();
        foreach ($map as $id => $cf) {
            $items[] = cfToJSON($cf, isset($used[$id]) ? 1 : 0);
        }
    }
    out(['status' => 'ok', 'items' => $items, 'total' => count($items), 'can_manage' => $canManage ? 1 : 0]);
}

// Route: GET /{id} - get single custom field
if ($method === 'GET' && isset($segments[0]) && is_numeric($segments[0])) {
    $id = intval($segments[0]);
    $map = $cfield_mgr->get_by_id($id);
    if (!$map || !isset($map[$id])) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Custom field not found']);
    }
    out(['status' => 'ok', 'item' => cfToJSON($map[$id], isset(usedFieldIds()[$id]) ? 1 : 0)]);
}

// Route: POST / - create custom field
if ($method === 'POST' && empty($segments)) {
    if (!$canManage) { deny(); }
    $body = getBody();
    $name = trim($body['name'] ?? '');
    $label = trim($body['label'] ?? '');

    if ($name === '' || $label === '') {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Name and label are required']);
    }

    // Legacy create form has a second submit button "Add and assign (to current
    // test project)" (gui/templates/dashio/cfields/cfieldsEdit.tpl:206-209,
    // lib/cfields/cfieldsEdit.php:325-328) which links the freshly created
    // custom field to the active test project right away. Mirror that here:
    // when assign_to_project is set, resolve the project (body -> URL -> active
    // session project) and link the new field to it after the create succeeds.
    $assignToProject = !empty($body['assign_to_project']);
    $tprojectId = 0;
    if ($assignToProject) {
        $tprojectId = intval($body['tproject_id'] ?? 0);
        if ($tprojectId <= 0) {
            $tprojectId = assignTprojectId();
        }
        if ($tprojectId <= 0) {
            http_response_code(400);
            out(['status' => 'error', 'message' => 'No test project selected']);
        }
        // Validates the resolved project before creating anything (parity with
        // the GET /assignment existence check below).
        $tprojectInfo = $cfield_mgr->tree_manager->get_node_hierarchy_info(
            $tprojectId,
            null,
            ['nodeType' => 'testproject']
        );
        if (is_null($tprojectInfo)) {
            http_response_code(400);
            out(['status' => 'error', 'message' => 'Test project not found']);
        }
    }

    $existing = $cfield_mgr->get_by_name($name);
    if ($existing) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Custom field name already exists']);
    }

    $nodeTypeMap = $cfield_mgr->get_allowed_nodes();
    $nodeTypeName = $body['node_type'] ?? 'testcase';
    $nodeTypeId = $nodeTypeMap[$nodeTypeName] ?? $nodeTypeMap['testcase'];

    // Legacy enable_on_execution == 1 implies show_on_execution == 1
    // (lib/cfields/cfieldsEdit.php:198-227 request2cf), so the flag is forced
    // on whenever the field is enabled on execution, exactly like the legacy
    // initShowOnExec() combo did (cfieldsEditJS.tpl:251-269).
    $enableOnExecution = $body['enable_on_execution'] ?? 0 ? 1 : 0;
    $showOnExecution = intval($body['show_on_execution'] ?? 0);

    // Requirement node types never carry execution display (legacy
    // show_on_cfg / enable_on_cfg, cfield_mgr.class.php:157-186), so scrub
    // both flags the way request2cf's missing-keys default did.
    if (in_array($nodeTypeName, ['requirement_spec', 'requirement'])) {
        $enableOnExecution = 0;
        $showOnExecution = 0;
    }

    $cf = [
        'name' => $name,
        'label' => $label,
        'type' => intval($body['type'] ?? 0),
        'possible_values' => $body['possible_values'] ?? '',
        'show_on_design' => 1,
        'enable_on_design' => $body['enable_on_design'] ?? 1,
        'show_on_execution' => $enableOnExecution ? 1 : $showOnExecution,
        'enable_on_execution' => $enableOnExecution,
        'show_on_testplan_design' => 0,
        'enable_on_testplan_design' => $body['enable_on_testplan_design'] ?? 0,
        'node_type_id' => $nodeTypeId,
    ];

    $result = $cfield_mgr->create($cf);
    if ($result['status_ok']) {
        logAuditEvent("Custom field '$name' created", "CREATE", $result['id'], "custom_fields");
        $newMap = $cfield_mgr->get_by_id($result['id']);
        $created = isset($newMap[$result['id']]) ? $newMap[$result['id']] : $cf;
        $row = ['status' => 'ok', 'item' => cfToJSON($created, 0)];
        if ($assignToProject) {
            $cfield_mgr->link_to_testproject($tprojectId, [$result['id']]);
            $row['assigned'] = 1;
            $row['tproject_id'] = $tprojectId;
        }
        out($row);
    } else {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Error creating custom field']);
    }
}

// Route: PUT /{id} - update custom field
if ($method === 'PUT' && isset($segments[0]) && is_numeric($segments[0])) {
    if (!$canManage) { deny(); }
    $id = intval($segments[0]);
    $map = $cfield_mgr->get_by_id($id);
    if (!$map || !isset($map[$id])) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Custom field not found']);
    }

    $existing = $map[$id];
    $body = getBody();

    $name = trim($body['name'] ?? $existing['name']);
    $label = trim($body['label'] ?? $existing['label']);

    if ($name !== $existing['name']) {
        $dup = $cfield_mgr->get_by_name($name);
        if ($dup) {
            // Legacy doUpdate (lib/cfields/cfieldsEdit.php:367-381) uses
            // name_is_unique() and surfaces lang_get('cf_name_exists') when a
            // rename would collide; mirror that with an explicit code so the
            // modern screens can render the localized message (Refs #956).
            http_response_code(400);
            out(['status' => 'error', 'code' => 'cf_name_exists',
                 'message' => lang_get('cf_name_exists', assignLocale())]);
        }
    }

    $nodeTypeMap = $cfield_mgr->get_allowed_nodes();
    $nodeTypeName = $body['node_type'] ?? 'testcase';

    // A custom field that already holds values keeps its type and node type
    // locked (legacy warning_no_type_change semantics, lib/functions/
    // cfield_mgr.class.php:1486). The UI renders them read-only for used fields;
    // this guard is the authoritative backstop that rejects any genuine attempt
    // to re-type a used field while still allowing the unchanged values through.
    $isUsed = isset(usedFieldIds()[$id]) ? 1 : 0;
    if ($isUsed) {
        $reqType = intval($body['type'] ?? $existing['type']);
        $reqNodeId = null;
        $reqNodeType = $body['node_type'] ?? null;
        // get_allowed_nodes() returns DB strings ('3'), so normalise to int
        // before the strict comparison against the stored int node_type_id.
        if ($reqNodeType !== null && isset($nodeTypeMap[$reqNodeType])) {
            $reqNodeId = intval($nodeTypeMap[$reqNodeType]);
        }
        if ($reqType !== intval($existing['type']) ||
            ($reqNodeId !== null && $reqNodeId !== intval($existing['node_type_id']))) {
            http_response_code(400);
            out(['status' => 'error', 'code' => 'warning_no_type_change',
                 'message' => lang_get('warning_no_type_change', assignLocale())]);
        }
        $type = intval($existing['type']);
        $nodeTypeId = intval($existing['node_type_id']);
    } else {
        $type = intval($body['type'] ?? $existing['type']);
        $nodeTypeId = $nodeTypeMap[$nodeTypeName] ?? $existing['node_type_id'];
    }

    // Legacy rule (request2cf + initShowOnExec): enable_on_execution forces
    // show_on_execution=1. Enforce it server-side on update as well.
    $enableOnExecution = intval($body['enable_on_execution'] ?? $existing['enable_on_execution']) ? 1 : 0;
    $showOnExecution = intval($body['show_on_execution'] ?? $existing['show_on_execution']);

    // Requirement node types never carry execution display; scrub both flags
    // (parity with legacy missing-keys defaults in request2cf).
    if (in_array($nodeTypeId, [$nodeTypeMap['requirement_spec'], $nodeTypeMap['requirement']])) {
        $enableOnExecution = 0;
        $showOnExecution = 0;
    }

    $cf = [
        'id' => $id,
        'name' => $name,
        'label' => $label,
        'type' => $type,
        'possible_values' => $body['possible_values'] ?? $existing['possible_values'],
        'show_on_design' => intval($body['show_on_design'] ?? $existing['show_on_design']),
        'enable_on_design' => intval($body['enable_on_design'] ?? $existing['enable_on_design']),
        'show_on_execution' => $enableOnExecution ? 1 : $showOnExecution,
        'enable_on_execution' => $enableOnExecution,
        'show_on_testplan_design' => intval($body['show_on_testplan_design'] ?? $existing['show_on_testplan_design']),
        'enable_on_testplan_design' => intval($body['enable_on_testplan_design'] ?? $existing['enable_on_testplan_design']),
        'node_type_id' => $nodeTypeId,
    ];

    $result = $cfield_mgr->update($cf);
    if ($result) {
        logAuditEvent("Custom field '$name' updated", "SAVE", $id, "custom_fields");
        $map = $cfield_mgr->get_by_id($id);
        out(['status' => 'ok', 'item' => cfToJSON($map[$id], isset(usedFieldIds()[$id]) ? 1 : 0)]);
    } else {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Error updating custom field']);
    }
}

// Route: DELETE /{id} - delete custom field
if ($method === 'DELETE' && isset($segments[0]) && is_numeric($segments[0])) {
    if (!$canManage) { deny(); }
    $id = intval($segments[0]);
    $map = $cfield_mgr->get_by_id($id);
    if (!$map || !isset($map[$id])) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Custom field not found']);
    }

    $cf = $map[$id];
    $result = $cfield_mgr->delete($id);
    if ($result) {
        logAuditEvent("Custom field '{$cf['name']}' deleted", "DELETE", $id, "custom_fields");
        out(['status' => 'ok']);
    } else {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Error deleting custom field']);
    }
}

// ---------------------------------------------------------------------------
// Assignment of custom fields to a test project.
//
// Mirrors lib/cfields/cfieldsTprojectAssign.php. The legacy screen posted the
// whole form back and diffed the checkboxes against hidden mirror inputs to
// work out which rows changed; here the client sends the desired state and the
// diffing happens below, against what is actually stored.
// ---------------------------------------------------------------------------

// The modern screens pick a language through TLi18n (short code: 'ro'), which
// is independent of $_SESSION['locale'] driving the Smarty pages. Without this
// the JSON labels below would come back in the session language while the rest
// of the page renders in the one the user picked - German column headers on a
// Romanian screen. Map the short code onto a TestLink locale and let lang_get
// resolve against it; fall back to the session when the client says nothing.
function assignLocale() {
    $short = preg_replace('/[^a-z]/', '', strtolower((string) getParam('locale', '')));
    if ($short === '' || strlen($short) !== 2) {
        return null;
    }
    foreach (array_keys((array) config_get('locales')) as $code) {
        if (strpos(strtolower($code), $short) === 0) {
            return $code;
        }
    }
    return null;
}

function assignTprojectId() {
    $id = intval(getParam('tproject_id', 0));
    if ($id <= 0) {
        $id = intval($_SESSION['testprojectID'] ?? 0);
    }
    return $id;
}

// GET /assignment?tproject_id=N - linked + available fields for one project
if ($method === 'GET' && isset($segments[0]) && $segments[0] === 'assignment') {
    if (!$canManage) { deny(); }
    $tprojectId = assignTprojectId();
    if ($tprojectId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'No test project selected']);
    }

    $tprojectName = '';
    $tree = new tree($db);
    $info = $tree->get_node_hierarchy_info($tprojectId, null, ['nodeType' => 'testproject']);
    if (is_null($info)) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Test project not found']);
    }
    $tprojectName = $info['name'];

    $lang = assignLocale();
    $types = $cfield_mgr->get_available_types();
    $nodes = [];
    foreach ($cfield_mgr->get_allowed_nodes() as $verbose => $typeId) {
        $nodes[$typeId] = lang_get($verbose, $lang);
    }

    // Display location only applies to design-time test case fields, which is
    // why the legacy template hid the dropdown for execution-only fields.
    $locations = [];
    $rawLocations = $cfield_mgr->getLocations();
    foreach (($rawLocations['testcase'] ?? []) as $code => $labelKey) {
        $locations[] = ['code' => intval($code), 'label' => lang_get($labelKey, $lang)];
    }

    $linkedRaw = $cfield_mgr->get_linked_to_testproject($tprojectId);
    $linked = [];
    $used = usedFieldIds();
    foreach ((array) $linkedRaw as $cf) {
        $row = cfToJSON($cf, isset($used[$cf['id']]) ? 1 : 0);
        $row['display_order'] = intval($cf['display_order'] ?? 0);
        $row['location'] = intval($cf['location'] ?? 0);
        $row['required'] = intval($cf['required'] ?? 0);
        $row['monitorable'] = intval($cf['monitorable'] ?? 0);
        $row['typeLabel'] = $types[$row['type']] ?? '';
        $row['nodeLabel'] = $nodes[$row['node_type_id']] ?? '';
        $row['supportsLocation'] =
            ($row['node_description'] === 'testcase' && $row['enable_on_execution'] == 0);
        $linked[] = $row;
    }

    $exclude = empty($linkedRaw) ? null : array_keys($linkedRaw);
    $available = [];
    foreach ((array) $cfield_mgr->get_all($exclude) as $cf) {
        $row = cfToJSON($cf, isset($used[$cf['id']]) ? 1 : 0);
        $row['typeLabel'] = $types[$row['type']] ?? '';
        $row['nodeLabel'] = $nodes[$row['node_type_id']] ?? '';
        $available[] = $row;
    }

    out([
        'status' => 'ok',
        'tproject' => ['id' => $tprojectId, 'name' => $tprojectName],
        'linked' => $linked,
        'available' => $available,
        'locations' => $locations,
    ]);
}

// POST /assignment/link - attach fields to the project
if ($method === 'POST' && isset($segments[0]) && $segments[0] === 'assignment'
    && isset($segments[1]) && $segments[1] === 'link') {
    if (!$canManage) { deny(); }
    $tprojectId = assignTprojectId();
    $body = getBody();
    $ids = array_values(array_filter(array_map('intval', (array) ($body['ids'] ?? []))));
    if ($tprojectId <= 0 || !$ids) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Nothing to assign']);
    }

    $cfield_mgr->link_to_testproject($tprojectId, $ids);
    logAuditEvent(count($ids) . " custom field(s) assigned to test project {$tprojectId}",
                  "ASSIGN", $tprojectId, "testprojects");
    out(['status' => 'ok', 'count' => count($ids)]);
}

// POST /assignment/unlink - detach fields from the project
if ($method === 'POST' && isset($segments[0]) && $segments[0] === 'assignment'
    && isset($segments[1]) && $segments[1] === 'unlink') {
    if (!$canManage) { deny(); }
    $tprojectId = assignTprojectId();
    $body = getBody();
    $ids = array_values(array_filter(array_map('intval', (array) ($body['ids'] ?? []))));
    if ($tprojectId <= 0 || !$ids) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Nothing to unassign']);
    }

    $cfield_mgr->unlink_from_testproject($tprojectId, $ids);
    logAuditEvent(count($ids) . " custom field(s) unassigned from test project {$tprojectId}",
                  "UNASSIGN", $tprojectId, "testprojects");
    out(['status' => 'ok', 'count' => count($ids)]);
}

// PUT /assignment - save order, location and the three boolean attributes
if ($method === 'PUT' && isset($segments[0]) && $segments[0] === 'assignment') {
    if (!$canManage) { deny(); }
    $tprojectId = assignTprojectId();
    if ($tprojectId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'No test project selected']);
    }

    $rows = (array) (getBody()['rows'] ?? []);
    if (!$rows) {
        out(['status' => 'ok', 'message' => 'No changes']);
    }

    $order = [];
    $location = [];
    $desired = ['active' => [], 'required' => [], 'monitorable' => []];

    foreach ($rows as $row) {
        $id = intval($row['id'] ?? 0);
        if ($id <= 0) { continue; }
        if (isset($row['display_order'])) { $order[$id] = intval($row['display_order']); }
        if (isset($row['location']))      { $location[$id] = intval($row['location']); }
        foreach (array_keys($desired) as $attr) {
            if (isset($row[$attr])) { $desired[$attr][$id] = $row[$attr] ? 1 : 0; }
        }
    }

    if ($order)    { $cfield_mgr->set_display_order($tprojectId, $order); }
    if ($location) { $cfield_mgr->setDisplayLocation($tprojectId, $location); }

    // Only flip what actually differs: these setters take a set of ids and one
    // value, so a blind write would touch every row on every save.
    $before = $cfield_mgr->getBooleanAttributes($tprojectId);
    $setter = [
        'active' => 'set_active_for_testproject',
        'required' => 'setRequired',
        'monitorable' => 'setMonitorable',
    ];
    foreach ($desired as $attr => $wanted) {
        $on = $off = [];
        foreach ($wanted as $id => $val) {
            $now = intval($before[$id][$attr] ?? 0);
            if ($val == 1 && $now == 0) { $on[] = $id; }
            if ($val == 0 && $now == 1) { $off[] = $id; }
        }
        if ($on)  { $cfield_mgr->{$setter[$attr]}($tprojectId, $on, 1); }
        if ($off) { $cfield_mgr->{$setter[$attr]}($tprojectId, $off, 0); }
    }

    logAuditEvent("Custom field assignment updated for test project {$tprojectId}",
                  "SAVE", $tprojectId, "testprojects");
    out(['status' => 'ok']);
}

http_response_code(404);
out(['status' => 'error', 'message' => 'Not found']);
