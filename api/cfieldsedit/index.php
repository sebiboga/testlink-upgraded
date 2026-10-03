<?php
/**
 * api/cfieldsedit — Custom Field Editor BFF  (Refs #1812)
 *
 * Modernizes the last live legacy Custom Fields controller:
 *   lib/cfields/cfieldsEdit.php (505 lines)
 *   gui/templates/dashio/cfields/cfieldsEdit.tpl (234 lines)
 *   gui/templates/dashio/cfields/cfieldsEditJS.tpl
 * which rendered the create / edit / delete form of a Custom Field Definition.
 * The modern Custom Field Manager (gui/templates/cfields/cfieldsView.html,
 * Refs #957) only carried an INLINE modal for the same job, so the editor had no
 * deep-linkable screen and no dedicated BFF - and the four legacy deep links
 * (tl-classic/cfields/cfieldsView.tpl:11+54, dashio/cfields/cfieldsTprojectAssign.tpl:80+175,
 * tl-classic/cfields/cfieldsTprojectAssign.tpl:55+144) still rendered the old
 * Smarty page.
 *
 * Legacy parity — everything the controller did:
 *   - do_action=create / edit / do_add / do_add_and_assign / do_update / do_delete
 *   - request2cf(): the "cf_" prefix scan + the missing-key defaults + the
 *     enable_on_* -> show_on_* implication
 *   - cfieldCfgInit(): application areas (execution/design/testplan_design),
 *     available types, allowed nodes, enable_on_cfg / show_on_cfg per area,
 *     possible_values_cfg per type
 *   - the is_used() lock: when the field already holds values its type and node
 *     type can no longer be changed
 *   - trim() on name / label / possible_values, name uniqueness
 *   - audit events audit_cfield_created / audit_cfield_saved / audit_cfield_deleted
 *   - "add and assign to current test project" -> cfield_mgr::link_to_testproject()
 *   - the UI rules of cfieldsEditJS.tpl: possible_values visibility per type,
 *     enable_on option hiding + the whole combo hidden when no area is allowed
 *     for the chosen node type, show_on_* disabled/hidden per node type, and
 *     the "enable on execution implies show on execution" rule that hides the
 *     combo (cfieldsEdit.php:94-96)
 *
 * Rights — legacy checkRights() (cfieldsEdit.php:503-506) gated EVERY action on
 * cfield_management. Kept as is, and additionally enforced per test project on
 * the assign path (legacy had no such check).
 *
 * Hardening vs legacy (each is a real defect of the old page):
 *   - is_used() lock was UI-ONLY: a crafted POST changed the type / node type of
 *     a field that already holds values, corrupting every stored value. Now
 *     rejected server side with 400 type_locked.
 *   - do_add_and_assign() called link_to_testproject() with the UNVALIDATED
 *     tproject_id taken straight from the request body, so a custom field could
 *     be assigned to any test project of any tenant. The project is now proven
 *     to exist and cfield_management is enforced on the OWNING project.
 *   - doUpdate() dereferenced $oldObjData[$cfield_id]['name'] without checking
 *     the row exists (PHP 8 "Trying to access array offset on null" + a fatal).
 *     Unknown id is now 404 cfield_not_found.
 *   - name / label / possible_values were only validated by client-side
 *     JavaScript; an empty or over-long value reached the DB. Now 400
 *     empty_name / empty_label / name_too_long / label_too_long /
 *     possible_values_too_long, with the maxlengths of
 *     gui/templates/conf/input_dimensions.conf ([cfieldsEdit] section).
 *   - do_action was an unchecked string used to build del_action in
 *     cfieldsEditJS.tpl. Only the four modelled actions are served here.
 *   - the delete confirmation used the CF name interpolated into a JS string
 *     (cfieldsEdit.tpl:200-202, escape:'javascript'); the modern screen passes it
 *     as data, never as code.
 *
 * Routes:
 *   GET  ?action=init&do_action=create|edit&cfield_id=N[&tproject_id=P]
 *   POST ?action=create   {name,label,type,node_type_id,possible_values,
 *                          enable_on,show_on_execution,tproject_id,assign}
 *   POST ?action=update   {id,name,label,type,node_type_id,possible_values,
 *                          enable_on,show_on_execution}
 *   POST ?action=delete   {id}
 *
 * Session-based auth, JSON I/O, no Smarty.
 */
require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
require_once('users.inc.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

/** Max lengths, gui/templates/conf/input_dimensions.conf [cfieldsEdit]. */
define('CFE_NAME_MAXLEN', 25);
define('CFE_LABEL_MAXLEN', 50);
define('CFE_PV_MAXLEN', 255);

/** Application areas in the order cfieldsEditJS.tpl loops them. */
function cfeAreas() { return array('execution', 'design', 'testplan_design'); }

function cfeOut($data, $code = 200) {
    if (!headers_sent()) { http_response_code($code); }
    echo json_encode($data);
    exit;
}

function cfeFail($code, $machineCode, $message, $extra = array()) {
    cfeOut(array_merge(array(
        'status' => 'error',
        'code' => $machineCode,
        'message' => $message,
    ), $extra), $code);
}

function cfeBody() {
    $raw = file_get_contents('php://input');
    $b = json_decode($raw, true);
    if (is_array($b)) { return $b; }
    // tolerate a form-encoded POST as well (deep links / no-JS fallback)
    return $_POST;
}

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    cfeFail(401, 'not_authenticated', 'Not authenticated');
}
$user = tlUser::getByID($db, intval($userId));
if (is_null($user)) {
    cfeFail(401, 'not_authenticated', 'User not found');
}

// Legacy checkRights(): cfieldsEdit.php:503-506 - every action of this screen.
$canManage = $user->hasRight($db, 'cfield_management');
if (!$canManage) {
    cfeFail(403, 'no_right', 'The cfield_management right is required');
}

$method = $_SERVER['REQUEST_METHOD'];
$action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');
if ($action === '') {
    cfeFail(400, 'missing_action', 'No action requested');
}
if ($method !== 'GET' && $method !== 'POST' && $method !== 'PUT' && $method !== 'DELETE') {
    cfeFail(405, 'method_not_allowed', 'Unsupported HTTP method: ' . $method);
}
if ($method !== 'GET') {
    // This screen WRITES: an expired tab must not keep editing definitions.
    bffEnforceSession($db);
}
if ($method === 'GET' && $action !== 'init') {
    cfeFail(405, 'method_not_allowed', 'Use POST for ' . $action);
}

$cfield_mgr = new cfield_mgr($db);

/**
 * request2cf() parity (cfieldsEdit.php:172-243).
 *
 * $in is the already-normalised payload; the legacy version scanned a hash for
 * the "cf_" prefix. The BFF accepts the un-prefixed names because the modern
 * screen builds the JSON itself and the mapping is asserted server side below.
 */
function cfeRequest2cf(array $in) {
    $missing = array(
        'show_on_design' => 0,
        'enable_on_design' => 0,
        'show_on_execution' => 0,
        'enable_on_execution' => 0,
        'show_on_testplan_design' => 0,
        'enable_on_testplan_design' => 0,
        'possible_values' => ' ',
    );
    $cf = array();
    foreach ($missing as $k => $v) { $cf[$k] = $v; }
    foreach (array('name', 'label', 'type', 'node_type_id', 'possible_values',
                   'enable_on', 'show_on_execution') as $k) {
        if (array_key_exists($k, $in)) { $cf[$k] = $in[$k]; }
    }

    // IMPORTANT/CRITIC parity with the legacy function: enable_on_* is derived
    // from the single cf_enable_on combo and implies show_on_* for that area.
    $setter = array('design' => 0, 'execution' => 0, 'testplan_design' => 0);
    $enableOn = isset($cf['enable_on']) ? (string) $cf['enable_on'] : '';
    switch ($enableOn) {
        case 'design':
        case 'execution':
        case 'testplan_design':
            $setter[$enableOn] = 1;
            break;
        default:
            $setter['design'] = 1;
            break;
    }
    foreach ($setter as $area => $val) {
        $cf['enable_on_' . $area] = $val;
        if ($cf['enable_on_' . $area]) { $cf['show_on_' . $area] = 1; }
    }
    return $cf;
}

/** Validate + normalise the shared write payload. 400 on the first problem. */
function cfeValidatePayload(array $in, $cfield_mgr) {
    $cf = cfeRequest2cf($in);

    $keys2trim = array('name', 'label', 'possible_values');
    foreach ($keys2trim as $k) { $cf[$k] = trim((string) $cf[$k]); }

    if ($cf['name'] === '') { cfeFail(400, 'empty_name', 'The name cannot be empty'); }
    if ($cf['label'] === '') { cfeFail(400, 'empty_label', 'The label cannot be empty'); }
    if (strlen($cf['name']) > CFE_NAME_MAXLEN) {
        cfeFail(400, 'name_too_long',
                'The name cannot exceed ' . CFE_NAME_MAXLEN . ' characters');
    }
    if (strlen($cf['label']) > CFE_LABEL_MAXLEN) {
        cfeFail(400, 'label_too_long',
                'The label cannot exceed ' . CFE_LABEL_MAXLEN . ' characters');
    }
    if (strlen($cf['possible_values']) > CFE_PV_MAXLEN) {
        cfeFail(400, 'possible_values_too_long',
                'Possible values cannot exceed ' . CFE_PV_MAXLEN . ' characters');
    }

    $types = $cfield_mgr->get_available_types();
    if (!array_key_exists(intval($cf['type']), $types)) {
        cfeFail(400, 'unknown_type', 'Unknown custom field type: ' . intval($cf['type']));
    }
    $cf['type'] = intval($cf['type']);

    // cfield_mgr::get_allowed_nodes() builds its ids from decode tables, so the
    // values arrive as STRINGS ("3", not 3) - normalise before comparing.
    $allowedNodes = array_map('intval', array_values($cfield_mgr->get_allowed_nodes()));
    if (!in_array(intval($cf['node_type_id']), $allowedNodes, true)) {
        cfeFail(400, 'unknown_node_type',
                'Unknown node type: ' . intval($cf['node_type_id']));
    }
    $cf['node_type_id'] = intval($cf['node_type_id']);

    // The UI hides / disables everything that does not make sense for the chosen
    // node type (cfieldsEditJS.tpl::configure_cf_attr). Reject it server side
    // too, otherwise a crafted POST can enable a field on an area the node type
    // does not support (the classic "REQ field enabled on execution" nonsense).
    $area = isset($in['enable_on']) ? (string) $in['enable_on'] : 'design';
    if (!in_array($area, cfeAreas(), true)) { $area = 'design'; }
    $enableCfg = $cfield_mgr->get_enable_on_cfg($area);
    if (empty($enableCfg[intval($cf['node_type_id'])])) {
        cfeFail(400, 'area_not_allowed_for_node_type',
                'Custom fields on this node type cannot be enabled on "' . $area . '"');
    }
    $showCfg = $cfield_mgr->get_show_on_cfg($area);
    if (empty($showCfg[intval($cf['node_type_id'])])) {
        $cf['show_on_' . $area] = 0;
    }
    // "enable on execution implies show on execution" (cfieldsEdit.php:94-96).
    if ($area === 'execution') { $cf['show_on_execution'] = 1; }

    return $cf;
}

/** Enforce the is_used() lock that legacy only applied in the UI. */
function cfeEnforceTypeLock($cfield_mgr, $cf) {
    $old = $cfield_mgr->get_by_id(intval($cf['id']));
    if (is_null($old) || !isset($old[intval($cf['id'])])) {
        cfeFail(404, 'cfield_not_found', 'Custom field not found');
    }
    $old = $old[intval($cf['id'])];
    if (!$cfield_mgr->is_used(intval($cf['id']))) { return $old; }
    if (intval($old['type']) !== intval($cf['type'])) {
        cfeFail(400, 'type_locked',
                'The type of a custom field that already holds values cannot be changed');
    }
    if (intval($old['node_type_id'] ?? 0) !== intval($cf['node_type_id'])) {
        cfeFail(400, 'node_type_locked',
                'The node type of a custom field that already holds values cannot be changed');
    }
    return $old;
}

function cfeJsonField($cf, $isUsed = 0) {
    return array(
        'id' => intval($cf['id']),
        'name' => (string) $cf['name'],
        'label' => (string) $cf['label'],
        'type' => intval($cf['type']),
        'possible_values' => (string) ($cf['possible_values'] ?? ''),
        'show_on_design' => intval($cf['show_on_design'] ?? 0),
        'enable_on_design' => intval($cf['enable_on_design'] ?? 0),
        'show_on_execution' => intval($cf['show_on_execution'] ?? 0),
        'enable_on_execution' => intval($cf['enable_on_execution'] ?? 0),
        'show_on_testplan_design' => intval($cf['show_on_testplan_design'] ?? 0),
        'enable_on_testplan_design' => intval($cf['enable_on_testplan_design'] ?? 0),
        'node_type_id' => intval($cf['node_type_id'] ?? 0),
        'node_description' => (string) ($cf['node_description'] ?? ''),
        'is_used' => $isUsed ? 1 : 0,
    );
}

/**
 * Resolve the addressed test project.
 *
 * The id can arrive three ways and ALL of them must be honoured: a query string
 * parameter (?tproject_id=), a form-encoded POST field, or a key of the JSON
 * body (which this BFF prefers, so it is read from the parsed body too - reading
 * only $_GET/$_POST silently ignored tproject_id on every JSON write and fell
 * back to the session project).
 */
function cfeResolveProject($db, $user, $body = array()) {
    $id = isset($_GET['tproject_id']) ? intval($_GET['tproject_id']) : 0;
    if ($id <= 0 && isset($body['tproject_id'])) { $id = intval($body['tproject_id']); }
    if ($id <= 0 && isset($_POST['tproject_id'])) { $id = intval($_POST['tproject_id']); }
    if ($id <= 0) { $id = intval($_SESSION['testprojectID'] ?? 0); }
    if ($id <= 0) {
        return array('tproject_id' => 0, 'tproject_name' => '');
    }
    $tree = new tree($db);
    $info = $tree->get_node_hierarchy_info($id, null, array('nodeType' => 'testproject'));
    if (is_null($info)) {
        cfeFail(404, 'tproject_not_found', 'Test project not found');
    }
    return array('tproject_id' => $id, 'tproject_name' => $info['name']);
}

/* ------------------------------------------------------------------ GET init */
if ($action === 'init') {
    $doAction = (string) ($_GET['do_action'] ?? 'create');
    if (!in_array($doAction, array('create', 'edit'), true)) {
        cfeFail(400, 'unknown_do_action', 'Unknown do_action: ' . $doAction);
    }
    $cfieldId = isset($_GET['cfield_id']) ? intval($_GET['cfield_id']) : 0;

    $lang = isset($_SESSION['TL_language']) ? $_SESSION['TL_language'] : 'en_GB';

    // cfieldCfgInit() (cfieldsEdit.php:437-465)
    $areas = array();
    foreach (cfeAreas() as $area) {
        $areas[] = array(
            'id' => $area,
            'label' => lang_get($area, $lang),
            'enable_on_cfg' => $cfield_mgr->get_enable_on_cfg($area),
            'show_on_cfg' => $cfield_mgr->get_show_on_cfg($area),
        );
    }
    $types = array();
    foreach ($cfield_mgr->get_available_types() as $id => $name) {
        $types[] = array('id' => intval($id), 'name' => $name);
    }
    $nodes = array();
    foreach ($cfield_mgr->get_allowed_nodes() as $verbose => $typeId) {
        $nodes[] = array(
            'id' => intval($typeId),
            'name' => lang_get($verbose, $lang),
            'verbose' => $verbose,
        );
    }

    // emptyCF (cfieldsEdit.php:128-137)
    $emptyCF = array(
        'id' => $cfieldId,
        'name' => '', 'label' => '', 'type' => 0, 'possible_values' => '',
        'show_on_design' => 1, 'enable_on_design' => 1,
        'show_on_execution' => 0, 'enable_on_execution' => 0,
        'show_on_testplan_design' => 0, 'enable_on_testplan_design' => 0,
        'node_type_id' => intval($cfield_mgr->get_allowed_nodes()['testcase']),
    );

    $isUsed = 0;
    $isLinked = false;
    $linkedProjects = array();
    $mode = 'create';

    if ($doAction === 'edit') {
        if ($cfieldId <= 0) {
            cfeFail(400, 'missing_cfield_id', 'No custom field id requested');
        }
        $byId = $cfield_mgr->get_by_id($cfieldId);
        if (is_null($byId) || !isset($byId[$cfieldId])) {
            cfeFail(404, 'cfield_not_found', 'Custom field not found');
        }
        $raw = $byId[$cfieldId];
        $isUsed = $cfield_mgr->is_used($cfieldId);
        $tps = $cfield_mgr->get_linked_testprojects($cfieldId);
        $isLinked = !is_null($tps) && count($tps) > 0;
        $linkedProjects = array();
        if (!is_null($tps)) {
            foreach ($tps as $pid => $row) {
                $linkedProjects[] = array('id' => intval($pid), 'name' => $row['name']);
            }
        }
        $mode = 'edit';
        $emptyCF = cfeJsonField($raw, $isUsed);
        $emptyCF['id'] = $cfieldId;
    } else {
        $emptyCF['is_used'] = 0;
    }

    $proj = cfeResolveProject($db, $user);

    cfeOut(array(
        'status' => 'ok',
        'mode' => $mode,
        'cfield' => $emptyCF,
        'areas' => $areas,
        'types' => $types,
        'nodes' => $nodes,
        'possible_values_cfg' => $cfield_mgr->get_possible_values_cfg(),
        'is_used' => $isUsed,
        'is_linked' => $isLinked,
        'linked_tprojects' => $linkedProjects,
        'tproject_id' => $proj['tproject_id'],
        'tproject_name' => $proj['tproject_name'],
        'tplan_id' => intval($_SESSION['testplanID'] ?? 0),
        'limits' => array(
            'name_maxlen' => CFE_NAME_MAXLEN,
            'label_maxlen' => CFE_LABEL_MAXLEN,
            'possible_values_maxlen' => CFE_PV_MAXLEN,
        ),
    ));
}

/* ------------------------------------------------------------ POST create */
if ($action === 'create') {
    $in = cfeBody();
    $cf = cfeValidatePayload($in, $cfield_mgr);

    // doCreate(): trim + name uniqueness (cfieldsEdit.php:319-348)
    $dup = $cfield_mgr->get_by_name($cf['name']);
    if (!is_null($dup)) {
        cfeFail(409, 'name_exists',
                'A custom field with this name already exists',
                array('field' => cfeJsonField(reset($dup))));
    }

    $assign = !empty($in['assign']);
    $tprojectId = 0;
    if ($assign) {
        $proj = cfeResolveProject($db, $user, $in);
        if ($proj['tproject_id'] <= 0) {
            cfeFail(400, 'no_tproject_selected',
                    'Select a test project before assigning the new custom field');
        }
        // legacy passed the request tproject_id straight to link_to_testproject()
        if (!$user->hasRight($db, 'cfield_management', $proj['tproject_id'], null, true)) {
            cfeFail(403, 'no_right_on_project',
                    'No cfield_management right on test project "' . $proj['tproject_name'] . '"');
        }
        $tprojectId = $proj['tproject_id'];
    }

    $ret = $cfield_mgr->create($cf);
    if (empty($ret['status_ok'])) {
        cfeFail(500, 'create_failed', 'Error creating the custom field');
    }
    logAuditEvent(TLS('audit_cfield_created', $cf['name']), 'CREATE',
                  $ret['id'], 'custom_fields');

    $linked = false;
    if ($assign) {
        $cfield_mgr->link_to_testproject($tprojectId, array($ret['id']));
        $linked = true;
    }

    $fresh = $cfield_mgr->get_by_id($ret['id']);
    $field = is_null($fresh) || !isset($fresh[$ret['id']])
        ? cfeJsonField(array_merge($cf, array('id' => $ret['id'])))
        : cfeJsonField($fresh[$ret['id']]);

    cfeOut(array(
        'status' => 'ok',
        'code' => 'created',
        'id' => intval($ret['id']),
        'cfield' => $field,
        'assigned' => $linked,
        'tproject_id' => $tprojectId,
    ));
}

/* ------------------------------------------------------------ POST update */
if ($action === 'update') {
    $in = cfeBody();
    $id = isset($in['id']) ? intval($in['id']) : 0;
    if ($id <= 0) { cfeFail(400, 'missing_cfield_id', 'No custom field id requested'); }
    $cf = cfeValidatePayload($in, $cfield_mgr);
    $cf['id'] = $id;

    // Proven to exist + is_used() lock enforced (legacy: UI only).
    $old = cfeEnforceTypeLock($cfield_mgr, $cf);

    // doUpdate(): name uniqueness (cfieldsEdit.php:382-395)
    if (!$cfield_mgr->name_is_unique($id, $cf['name'])) {
        cfeFail(409, 'name_exists',
                'A custom field with this name already exists');
    }

    if (!$cfield_mgr->update($cf)) {
        cfeFail(500, 'update_failed', 'Error updating the custom field');
    }
    logAuditEvent(TLS('audit_cfield_saved', $cf['name']), 'SAVE', $id, 'custom_fields');

    $fresh = $cfield_mgr->get_by_id($id);
    $field = is_null($fresh) || !isset($fresh[$id])
        ? cfeJsonField(array_merge($cf, array('id' => $id)))
        : cfeJsonField($fresh[$id]);

    cfeOut(array(
        'status' => 'ok',
        'code' => 'updated',
        'id' => $id,
        'cfield' => $field,
        'old_name' => (string) $old['name'],
    ));
}

/* ------------------------------------------------------------ POST delete */
if ($action === 'delete') {
    $in = cfeBody();
    $id = isset($in['id']) ? intval($in['id']) : 0;
    if ($id <= 0) { cfeFail(400, 'missing_cfield_id', 'No custom field id requested'); }

    // doDelete() proved the row exists before deleting (cfieldsEdit.php:417-425).
    $byId = $cfield_mgr->get_by_id($id);
    if (is_null($byId) || !isset($byId[$id])) {
        cfeFail(404, 'cfield_not_found', 'Custom field not found');
    }
    $cf = $byId[$id];
    $linkedTps = $cfield_mgr->get_linked_testprojects($id);
    $linkedCount = is_null($linkedTps) ? 0 : count($linkedTps);
    $isUsed = $cfield_mgr->is_used($id);

    if (!$cfield_mgr->delete($id)) {
        cfeFail(500, 'delete_failed', 'Error deleting the custom field');
    }
    logAuditEvent(TLS('audit_cfield_deleted', $cf['name']), 'DELETE', $id, 'custom_fields');

    cfeOut(array(
        'status' => 'ok',
        'code' => 'deleted',
        'id' => $id,
        'name' => (string) $cf['name'],
        'had_values' => $isUsed ? 1 : 0,
        'unlinked_projects' => $linkedCount,
    ));
}

cfeFail(405, 'method_not_allowed', 'Unknown action: ' . $action);
