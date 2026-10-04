<?php
/**
 * api/cfieldstproject — Custom Fields assignment to a test project BFF (Refs #1816)
 *
 * Modernizes the last standalone lib/cfields/* controller:
 *   lib/cfields/cfieldsTprojectAssign.php (270 lines)
 *   gui/templates/dashio/cfields/cfieldsTprojectAssign.tpl (197 lines)
 * which managed, for ONE test project, the custom fields that are enabled for
 * it: attach / detach a field, re-order the attached ones, pick the display
 * location and bulk-toggle the three boolean attributes (active / required /
 * monitorable).
 *
 * api/cfields/index.php already carries /assignment routes (that part was
 * ported for the inline modal of the Custom Field Manager), but there was NO
 * screen consuming them and NO live entry point: the only caller left in the
 * whole tree was the dead tl-classic mainPageLeft.tpl:77. This file is the
 * screen's own BFF, hardened, and it is what the modern screen talks to.
 *
 * Legacy parity — everything the controller did:
 *   - doAssign    -> cfield_mgr::link_to_testproject()
 *   - doUnassign  -> cfield_mgr::unlink_from_testproject()
 *   - doReorder   -> cfield_mgr::set_display_order() and, when a location was
 *                    posted, cfield_mgr::setDisplayLocation()
 *   - doBooleanMgmt -> getBooleanAttributes() diffed against the hidden mirror
 *                    inputs, then only the rows that really flipped were pushed
 *                    through set_active_for_testproject() / setRequired() /
 *                    setMonitorable() (cfieldsTprojectAssign.php:219-267)
 *   - the location dropdown is only offered for design-time test case fields
 *     ($cf.node_description === 'testcase' && enable_on_execution == 0), the
 *     legacy template printed &nbsp; for everything else (tpl:91-98)
 *   - both tables are DataTables with pagination
 *     (cfg.inc.php:711-714, [cfieldsTprojectAssign] section)
 *   - the name of every row deep-links into the modern Custom Field Editor
 *     (tpl:80+175), which is gui/templates/cfields/cfieldsEdit.html
 *   - audit events on every write
 *
 * Hardening vs legacy (each is a real defect of the old page):
 *   - checkRights() only asked for the GLOBAL cfield_management right while
 *     $args->tproject_id came straight out of $_REQUEST, and no model method
 *     re-checked anything: a manager of project A could attach / detach /
 *     re-order the custom fields of project B. cfield_management is now
 *     enforced on the ADDRESSED project (getAccess = true, so a private
 *     project is not reachable through a global right alone) and the rights
 *     answer is produced BEFORE the project is resolved, so a bogus id and a
 *     foreign id are indistinguishable (no test-project id enumeration).
 *   - the writes never proved the target existed: link_to_testproject() on a
 *     non-existent project id silently inserted an orphan row. Every write
 *     now resolves the project first (404 tproject_not_found).
 *   - checkedCF / display_order / location ids came from the POST unvalidated,
 *     so a crafted form wrote attributes onto custom fields of other projects
 *     and onto ids that do not exist. The submitted ids are now intersected
 *     with the fields really linked to the addressed project (400
 *     unknown_cfield on the write paths).
 *   - $_REQUEST was read by the action switch, so a plain GET with
 *     ?doAction=doUnassign&checkedCF[3]=1 mutated the database (state
 *     changing GET). Writes are POST-only here (405).
 *   - no CSRF proof at all on a state changing POST. bffSameOriginGuard().
 *   - getTproj() threw a bare Exception ("Unable to get Test Project ID") that
 *     the Smarty page rendered as a fatal; the modern contract is 404 + a
 *     machine code.
 *   - the legacy page had no session re-validation, so a tab left open past
 *     sessionInactivityTimeout kept writing. bffEnforceSession() on the writes
 *     (issue #1614).
 *
 * Routes:
 *   GET  ?action=init&tproject_id=N[&locale=xx]  -> context + linked + available
 *   GET  ?action=projects[&locale=xx]             -> projects this user may manage
 *   POST ?action=assign   {tproject_id, ids:[..]}
 *   POST ?action=unassign {tproject_id, ids:[..]}
 *   POST ?action=save     {tproject_id, rows:[{id,display_order,location,
 *                                                 active,required,monitorable}]}
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

/** cfg.inc.php [cfieldsTprojectAssign] -> gui/templates/conf/input_dimensions.conf */
define('CFPA_ORDER_SIZE', 5);
define('CFPA_ORDER_MAXLEN', 5);

function cfpaOut($data, $code = 200) {
    if (!headers_sent()) { http_response_code($code); }
    echo json_encode($data);
    exit;
}

function cfpaFail($code, $machineCode, $message, $extra = array()) {
    cfpaOut(array_merge(array(
        'status' => 'error',
        'code' => $machineCode,
        'message' => $message,
    ), $extra), $code);
}

function cfpaBody() {
    $raw = file_get_contents('php://input');
    $b = json_decode($raw, true);
    if (is_array($b)) { return $b; }
    return $_POST;
}

/**
 * The modern screens pick their language through TLi18n (short code: 'ro'),
 * which is independent of $_SESSION['locale'] driving the Smarty pages. Map
 * the short code onto a shipped TestLink locale so lang_get() resolves against
 * the language actually on screen (api/cfields/index.php:378 assignLocale()).
 */
function cfpaLocale() {
    $short = preg_replace('/[^a-z]/', '', strtolower((string) ($_GET['locale'] ?? '')));
    if ($short === '' || strlen($short) !== 2) { return null; }
    foreach (array_keys((array) config_get('locales')) as $code) {
        if (strpos(strtolower($code), $short) === 0) { return $code; }
    }
    return null;
}

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    cfpaFail(401, 'not_authenticated', 'Not authenticated');
}
$user = tlUser::getByID($db, intval($userId));
if (is_null($user)) {
    cfpaFail(401, 'not_authenticated', 'User not found');
}

$method = $_SERVER['REQUEST_METHOD'];
$action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');
if ($action === '') {
    cfpaFail(400, 'missing_action', 'No action requested');
}
if ($method !== 'GET' && $method !== 'POST') {
    cfpaFail(405, 'method_not_allowed', 'Unsupported HTTP method: ' . $method);
}
if ($method === 'POST') {
    // This screen WRITES: an expired tab must not keep editing assignments.
    bffEnforceSession($db);
}

$cfield_mgr = new cfield_mgr($db);
$lang = cfpaLocale();

/**
 * cfield_management on the ADDRESSED project, answered BEFORE the project is
 * resolved so a foreign id and a non-existent id both come back as the same
 * 403 (no id enumeration). Legacy checkRights() had no project scope at all.
 */
function cfpaRequireManage($db, $user, $tprojectId) {
    // tlUser::hasRight() already carries the admin exception for a private
    // project (globalRoleID != TL_ROLES_ADMIN, tlUser.class.php:936-944).
    return $tprojectId > 0
        && (bool) $user->hasRight($db, 'cfield_management', $tprojectId, null, true);
}

function cfpaTprojectIdFrom($src) {
    $raw = $src['tproject_id'] ?? null;
    if ($raw === null || $raw === '') {
        return intval($_SESSION['testprojectID'] ?? 0);
    }
    if (!is_numeric($raw)) { return -1; }
    return intval($raw);
}

function cfpaResolveTproject($db, $tprojectId) {
    if ($tprojectId <= 0) {
        cfpaFail(400, 'missing_tproject_id', 'No test project selected');
    }
    $tree = new tree($db);
    $info = $tree->get_node_hierarchy_info($tprojectId, null, array('nodeType' => 'testproject'));
    if (is_null($info)) {
        cfpaFail(404, 'tproject_not_found', 'Test project not found');
    }
    return array('id' => intval($tprojectId), 'name' => $info['name']);
}

function cfpaJsonField($cf, $lang, $cfield_mgr) {
    $types = $cfield_mgr->get_available_types();
    $nodes = array();
    foreach ($cfield_mgr->get_allowed_nodes() as $verbose => $typeId) {
        $nodes[$typeId] = lang_get($verbose, $lang);
    }
    return array(
        'id' => intval($cf['id']),
        'name' => $cf['name'],
        'label' => $cf['label'],
        'type' => intval($cf['type']),
        'type_label' => $types[intval($cf['type'])] ?? '',
        'node_type_id' => intval($cf['node_type_id']),
        'node_label' => $nodes[intval($cf['node_type_id'])] ?? '',
        'node_description' => $cf['node_description'] ?? '',
        'enable_on_execution' => intval($cf['enable_on_execution'] ?? 0),
    );
}

/**
 * GET ?action=init — initializeGui() parity (cfieldsTprojectAssign.php:121-141)
 */
if ($action === 'init') {
    if ($method !== 'GET') { cfpaFail(405, 'method_not_allowed', 'Use GET for init'); }

    $tprojectId = cfpaTprojectIdFrom($_GET);
    if ($tprojectId < 0) { cfpaFail(400, 'invalid_tproject_id', 'Invalid test project id'); }
    if (!cfpaRequireManage($db, $user, $tprojectId)) {
        cfpaFail(403, 'no_right', 'The cfield_management right is required on this test project');
    }
    $project = cfpaResolveTproject($db, $tprojectId);

    // createLocationsMenu() (cfieldsTprojectAssign.php:158-166)
    $locations = array();
    $rawLocations = $cfield_mgr->getLocations();
    foreach ((array) ($rawLocations['testcase'] ?? array()) as $code => $labelKey) {
        $locations[] = array('code' => intval($code), 'label' => lang_get($labelKey, $lang));
    }

    $linkedRaw = $cfield_mgr->get_linked_to_testproject($tprojectId);
    $linked = array();
    foreach ((array) $linkedRaw as $cf) {
        $row = cfpaJsonField($cf, $lang, $cfield_mgr);
        $row['display_order'] = intval($cf['display_order'] ?? 0);
        $row['location'] = intval($cf['location'] ?? 0);
        $row['active'] = intval($cf['active'] ?? 0);
        $row['required'] = intval($cf['required'] ?? 0);
        $row['monitorable'] = intval($cf['monitorable'] ?? 0);
        // Location only applies to design-time test case fields (tpl:91-98)
        $row['supports_location'] =
            ($row['node_description'] === 'testcase' && $row['enable_on_execution'] == 0);
        $linked[] = $row;
    }

    // get_all($cf2exclude) — the "Free / not assigned" table (tpl:146-193)
    $exclude = empty($linkedRaw) ? null : array_keys($linkedRaw);
    $available = array();
    foreach ((array) $cfield_mgr->get_all($exclude) as $cf) {
        $available[] = cfpaJsonField($cf, $lang, $cfield_mgr);
    }

    cfpaOut(array(
        'status' => 'ok',
        'tproject' => $project,
        'linked' => $linked,
        'available' => $available,
        'locations' => $locations,
        'counts' => array('linked' => count($linked), 'available' => count($available)),
        'limits' => array(
            'display_order_size' => CFPA_ORDER_SIZE,
            'display_order_maxlen' => CFPA_ORDER_MAXLEN,
        ),
    ));
}

/**
 * GET ?action=projects — switcher. Legacy had none: the project came from the
 * session unless tproject_id was posted (getTproj(), :93-116), so a user could
 * not move between projects without hand-editing the URL.
 */
if ($action === 'projects') {
    if ($method !== 'GET') { cfpaFail(405, 'method_not_allowed', 'Use GET for projects'); }

    $tproject_mgr = new testproject($db);
    $rows = array();
    // get_accessible_for_user() keeps private projects the user has no role on
    // out of the list (tlUser.class.php:1053-1063), so the switcher never
    // advertises a project the caller cannot reach.
    foreach ((array) $tproject_mgr->get_accessible_for_user(
                 intval($userId), array('output' => 'map', 'order_by' => ' ORDER BY name ')
             ) as $pid => $p) {
        $pid = intval($pid);
        if ($pid <= 0) { continue; }
        if (!cfpaRequireManage($db, $user, $pid)) { continue; }
        $rows[] = array('id' => $pid,
                        'name' => is_array($p) ? ($p['name'] ?? '') : '',
                        'prefix' => is_array($p) ? ($p['prefix'] ?? '') : '');
    }
    cfpaOut(array('status' => 'ok', 'projects' => $rows, 'count' => count($rows)));
}

/* ------------------------------------------------------------------ writes */

if ($method !== 'POST') {
    cfpaFail(405, 'method_not_allowed', 'Use POST for ' . $action);
}

$in = cfpaBody();
$tprojectId = cfpaTprojectIdFrom($in);
if ($tprojectId < 0) { cfpaFail(400, 'invalid_tproject_id', 'Invalid test project id'); }
if (!cfpaRequireManage($db, $user, $tprojectId)) {
    cfpaFail(403, 'no_right', 'The cfield_management right is required on this test project');
}
$project = cfpaResolveTproject($db, $tprojectId);

$linkedRaw = (array) $cfield_mgr->get_linked_to_testproject($tprojectId);
$linkedIds = array_map('intval', array_keys($linkedRaw));
$allRaw = (array) $cfield_mgr->get_all();
$allIds = array();
foreach ($allRaw as $k => $cf) { $allIds[] = intval($k); }

// Codes accepted for the display-location column (createLocationsMenu parity).
$locationCodes = array();
foreach ((array) $cfield_mgr->getLocations() as $group => $items) {
    if ($group !== 'testcase') { continue; }
    foreach ((array) $items as $code => $labelKey) { $locationCodes[intval($code)] = true; }
}

/** Submitted ids must be real custom fields; for a write on rows, they must be linked. */
function cfpaSanitizeIds($ids, $allowed) {
    $allowed = array_flip($allowed);
    $out = array();
    foreach ($ids as $id) {
        $id = intval($id);
        if ($id > 0 && isset($allowed[$id])) { $out[$id] = $id; }
    }
    return array_values($out);
}

/* ------------------------------------------------------------ POST assign */
if ($action === 'assign' || $action === 'unassign') {
    $ids = (array) ($in['ids'] ?? array());
    if (!$ids) {
        cfpaFail(400, 'nothing_selected', 'No custom field selected');
    }
    $ids = cfpaSanitizeIds($ids, $allIds);
    if (!$ids) {
        cfpaFail(400, 'unknown_cfield', 'No known custom field in the request');
    }

    if ($action === 'assign') {
        $cfield_mgr->link_to_testproject($tprojectId, $ids);
        logAuditEvent(count($ids) . ' custom field(s) assigned to test project '
                      . $tprojectId, 'ASSIGN', $tprojectId, 'testprojects');
    } else {
        // Unassign may only detach fields that are really attached HERE, or a
        // crafted id would remove the link row of another project.
        $ids = cfpaSanitizeIds($ids, $linkedIds);
        if (!$ids) {
            cfpaFail(400, 'unknown_cfield',
                     'None of the submitted custom fields is assigned to this test project');
        }
        $cfield_mgr->unlink_from_testproject($tprojectId, $ids);
        logAuditEvent(count($ids) . ' custom field(s) unassigned from test project '
                      . $tprojectId, 'UNASSIGN', $tprojectId, 'testprojects');
    }
    cfpaOut(array('status' => 'ok', 'count' => count($ids), 'tproject_id' => $tprojectId));
}

/* --------------------------------------------------------------- POST save */
if ($action === 'save') {
    $rows = (array) ($in['rows'] ?? array());
    if (!$rows) {
        cfpaFail(400, 'nothing_selected', 'No custom field row submitted');
    }
    $rows = cfpaSanitizeIds(array_map(
        function ($r) { return $r['id'] ?? 0; }, $rows), $linkedIds);
    if (!$rows) {
        cfpaFail(400, 'unknown_cfield',
                 'None of the submitted custom fields is assigned to this test project');
    }
    $keep = array_flip($rows);

    $order = array();
    $location = array();
    $desired = array('active' => array(), 'required' => array(), 'monitorable' => array());
    $changed = array('order' => 0, 'location' => 0, 'active' => 0, 'required' => 0,
                     'monitorable' => 0);

    foreach ((array) $in['rows'] as $row) {
        $id = intval($row['id'] ?? 0);
        if ($id <= 0 || !isset($keep[$id])) { continue; }
        if (isset($row['display_order'])) {
            $v = intval($row['display_order']);
            if ($v < 0) { $v = 0; }
            if (intval($linkedRaw[$id]['display_order'] ?? 0) != $v) { $changed['order']++; }
            $order[$id] = $v;
        }
        if (isset($row['location'])) {
            $v = intval($row['location']);
            if (isset($locationCodes[$v])
                && intval($linkedRaw[$id]['location'] ?? 0) != $v) {
                $changed['location']++;
            }
            $location[$id] = $v;
        }
        foreach (array_keys($desired) as $attr) {
            if (isset($row[$attr])) { $desired[$attr][$id] = $row[$attr] ? 1 : 0; }
        }
    }

    if ($order)    { $cfield_mgr->set_display_order($tprojectId, $order); }
    if ($location) { $cfield_mgr->setDisplayLocation($tprojectId, $location); }

    // Only flip what really differs: these setters take a set of ids and ONE
    // value, so a blind write would touch every row on every save
    // (cfieldsTprojectAssign.php:219-267 did the same diff client-side through
    // the hidden_* mirror inputs).
    $before = $cfield_mgr->getBooleanAttributes($tprojectId);
    $setter = array(
        'active' => 'set_active_for_testproject',
        'required' => 'setRequired',
        'monitorable' => 'setMonitorable',
    );
    foreach ($desired as $attr => $wanted) {
        $on = $off = array();
        foreach ($wanted as $id => $val) {
            $now = intval($before[$id][$attr] ?? 0);
            if ($val == 1 && $now == 0) { $on[] = $id; $changed[$attr]++; }
            if ($val == 0 && $now == 1) { $off[] = $id; $changed[$attr]++; }
        }
        if ($on)  { $cfield_mgr->{$setter[$attr]}($tprojectId, $on, 1); }
        if ($off) { $cfield_mgr->{$setter[$attr]}($tprojectId, $off, 0); }
    }

    $touched = array_filter($changed);
    if ($touched) {
        logAuditEvent('Custom field assignment updated for test project ' . $tprojectId,
                      'SAVE', $tprojectId, 'testprojects');
    }
    cfpaOut(array('status' => 'ok', 'changed' => $changed,
                  'tproject_id' => $tprojectId));
}

cfpaFail(400, 'unknown_action', 'Unknown action: ' . $action);
