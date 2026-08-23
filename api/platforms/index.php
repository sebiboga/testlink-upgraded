<?php
/**
 * Platforms BFF API
 * URL: /api/platforms/
 * Plain PHP, no framework, no compilation
 *
 * Mirrors lib/platforms/platformsView.php + platformsEdit.php +
 * platformsExport.php + platformsImport.php (TestLink 1.9.20 behavior).
 *
 * Rights split (same as the legacy screens):
 *   view   -> platform_view OR platform_management
 *   manage -> platform_management
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
require_once('xml.inc.php');
require_once(__DIR__ . '/../../third_party/adodb_xml/class.ADODB_XML.php');

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

$path = $_SERVER['PATH_INFO'] ?? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = preg_replace('#^/api/platforms(/index\.php)?#', '', $path);
$path = '/' . trim($path, '/');
$method = $_SERVER['REQUEST_METHOD'];
$segments = array_values(array_filter(explode('/', $path)));

function out($data) { echo json_encode($data); exit; }
function getParam($key, $default = null) { return $_GET[$key] ?? $default; }
function getBody() { return json_decode(file_get_contents('php://input'), true) ?? []; }

$tproject_mgr = new testproject($db);

// Legacy platformsView.php accepts users with platform_management OR platform_view,
// while every write action goes through platformsEdit.php which requires
// platform_management (checkGUISecurityClearance).
function canView($user, $db, $tproject_id) {
    return $user->hasRight($db, 'platform_management', $tproject_id) ||
           $user->hasRight($db, 'platform_view', $tproject_id);
}
function canManage($user, $db, $tproject_id) {
    return $user->hasRight($db, 'platform_management', $tproject_id);
}

function needTprojectId() {
    $id = intval($_GET['tproject_id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test project id']);
    }
    return $id;
}

/**
 * Platform must belong to the test project in context (legacy platformsEdit.php
 * re-derives tproject_id from the platform row itself).
 */
function needOwnedPlatform($platMgr, $id, $tproject_id) {
    if (!$platMgr->belongsToTestProject($id, $tproject_id)) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Platform not found']);
    }
}

function platToJSON($p) {
    return [
        'id' => intval($p['id']),
        'name' => (string)$p['name'],
        'notes' => (string)$p['notes'],
        'enable_on_design' => intval($p['enable_on_design']),
        'enable_on_execution' => intval($p['enable_on_execution']),
        'is_open' => intval($p['is_open']),
    ];
}

$platMgr = null;
function platMgrFor($tproject_id) {
    global $db, $platMgr;
    if (is_null($platMgr)) {
        $platMgr = new tlPlatform($db, $tproject_id);
    } else {
        $platMgr->setTestProjectID($tproject_id);
    }
    return $platMgr;
}

// ---------------------------------------------------------------------------
// GET /?tproject_id=N - list with linked_count + delete-block status + rights
// Same data set as tlPlatform::initViewGui() (include_linked_count=true).
// ---------------------------------------------------------------------------
if ($method === 'GET' && count($segments) === 0) {
    $tproject_id = needTprojectId();
    if (!canView($user, $db, $tproject_id)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }

    $mgr = platMgrFor($tproject_id);
    $rows = $mgr->getAll(['include_linked_count' => true,
                          'enable_on_design' => null,
                          'enable_on_execution' => null,
                          'is_open' => null]);
    $items = [];
    if (!is_null($rows)) {
        foreach ($rows as $r) {
            $it = platToJSON($r);
            // legacy UI only offers delete when platform is not linked to any testplan
            $it['linked_count'] = isset($r['linked_count']) ? intval($r['linked_count']) : 0;
            $it['deletable'] = ($it['linked_count'] == 0);
            $items[] = $it;
        }
    }

    out([
        'status' => 'ok',
        'items' => $items,
        'tproject' => ['id' => $tproject_id, 'name' => testproject::getName($db, $tproject_id)],
        'rights' => [
            'canManage' => (bool)canManage($user, $db, $tproject_id),
            'canViewEvents' => (bool)$user->hasRight($db, 'mgt_view_events', $tproject_id),
        ],
    ]);
}

// ---------------------------------------------------------------------------
// GET /{id} - single platform (view right)
// ---------------------------------------------------------------------------
if ($method === 'GET' && isset($segments[0]) && ctype_digit($segments[0]) && !isset($segments[1])) {
    $id = intval($segments[0]);
    $mgr0 = new tlPlatform($db, null);
    $p = $mgr0->getPlatform($id);
    if (!$p) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Platform not found']);
    }
    $tproject_id = intval($p['testproject_id']);
    if (!canView($user, $db, $tproject_id)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }
    $item = platToJSON($p);
    $item['testproject_id'] = $tproject_id;
    out(['status' => 'ok', 'item' => $item]);
}

// ---------------------------------------------------------------------------
// POST / - create platform (platform_management)
// body: tproject_id, name, notes, enable_on_design, enable_on_execution, is_open
// ---------------------------------------------------------------------------
if ($method === 'POST' && count($segments) === 0) {
    $body = getBody();
    $tproject_id = intval($body['tproject_id'] ?? 0);
    if ($tproject_id <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test project id']);
    }
    if (!canManage($user, $db, $tproject_id)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }

    $name = trim((string)($body['name'] ?? ''));
    if ($name === '') {
        http_response_code(422);
        out(['status' => 'error', 'message' => 'Empty platform name is not allowed',
             'error_code' => tlPlatform::E_NAMELENGTH]);
    }

    $mgr = platMgrFor($tproject_id);
    $plat = new stdClass();
    $plat->name = $name;
    $plat->notes = (string)($body['notes'] ?? '');
    $plat->enable_on_design = !empty($body['enable_on_design']) ? 1 : 0;
    $plat->enable_on_execution = !empty($body['enable_on_execution']) ? 1 : 0;
    // legacy create() defaults is_open to 1 when not provided
    $plat->is_open = array_key_exists('is_open', $body) ? (!empty($body['is_open']) ? 1 : 0) : 1;

    try {
        $op = $mgr->create($plat);
    } catch (Exception $e) {
        http_response_code(422);
        out(['status' => 'error', 'message' => 'Empty platform name is not allowed',
             'error_code' => tlPlatform::E_NAMELENGTH]);
    }

    if ($op['status'] == tl::OK) {
        out(['status' => 'ok', 'id' => intval($op['id'])]);
    }
    http_response_code(422);
    out(['status' => 'error', 'message' => 'Platform could not be created',
         'error_code' => intval($op['status'])]);
}

// ---------------------------------------------------------------------------
// PUT /{id} - update platform (platform_management)
// body: tproject_id, name, notes, enable_on_design, enable_on_execution, is_open
// ---------------------------------------------------------------------------
if ($method === 'PUT' && isset($segments[0]) && ctype_digit($segments[0]) &&
    (!isset($segments[1]) || $segments[1] !== 'flags')) {
    $body = getBody();
    $tproject_id = intval($body['tproject_id'] ?? 0);
    if ($tproject_id <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test project id']);
    }
    if (!canManage($user, $db, $tproject_id)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }

    $id = intval($segments[0]);
    $mgr = platMgrFor($tproject_id);
    needOwnedPlatform($mgr, $id, $tproject_id);

    $name = trim((string)($body['name'] ?? ''));
    if ($name === '') {
        http_response_code(422);
        out(['status' => 'error', 'message' => 'Empty platform name is not allowed',
             'error_code' => tlPlatform::E_NAMELENGTH]);
    }

    // guard against renames that would duplicate an existing platform name
    // (legacy update() missed this check; create() has it)
    $dupId = $mgr->getID($name);
    if ($dupId && intval($dupId) != $id) {
        http_response_code(422);
        out(['status' => 'error', 'message' => 'Platform name already exists',
             'error_code' => tlPlatform::E_NAMEALREADYEXISTS]);
    }

    $result = $mgr->update(
        $id,
        $name,
        (string)($body['notes'] ?? ''),
        !empty($body['enable_on_design']) ? 1 : 0,
        !empty($body['enable_on_execution']) ? 1 : 0,
        !empty($body['is_open']) ? 1 : 0
    );
    if ($result == tl::OK) {
        out(['status' => 'ok']);
    }
    http_response_code(422);
    out(['status' => 'error', 'message' => 'Platform update failed',
         'error_code' => intval($result)]);
}

// ---------------------------------------------------------------------------
// PUT /{id}/flags - quick toggle from list view (platform_management)
// body: field=enable_on_design|enable_on_execution|is_open, value=0|1
// mirrors enableDesign/disableDesign/enableExec/disableExec/openForExec/closeForExec
// ---------------------------------------------------------------------------
if ($method === 'PUT' && isset($segments[1]) && $segments[1] === 'flags') {
    $body = getBody();
    $tproject_id = intval($body['tproject_id'] ?? 0);
    if ($tproject_id <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test project id']);
    }
    if (!canManage($user, $db, $tproject_id)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }

    $fieldMap = [
        'enable_on_design' => ['on' => 'enableDesign', 'off' => 'disableDesign'],
        'enable_on_execution' => ['on' => 'enableExec', 'off' => 'disableExec'],
        'is_open' => ['on' => 'openForExec', 'off' => 'closeForExec'],
    ];
    $field = (string)($body['field'] ?? '');
    if (!isset($fieldMap[$field])) {
        http_response_code(422);
        out(['status' => 'error', 'message' => 'Unknown flag field']);
    }

    $id = intval($segments[0]);
    $mgr = platMgrFor($tproject_id);
    needOwnedPlatform($mgr, $id, $tproject_id);

    $value = !empty($body['value']) ? 1 : 0;
    $pfn = $fieldMap[$field][$value ? 'on' : 'off'];
    $mgr->$pfn($id);
    out(['status' => 'ok']);
}

// ---------------------------------------------------------------------------
// DELETE /{id}?tproject_id=N - delete platform (platform_management)
// The legacy UI blocks deletion of platforms linked to testplans
// (warning_cannot_delete_platform); enforce the same rule server-side.
// ---------------------------------------------------------------------------
if ($method === 'DELETE' && isset($segments[0]) && ctype_digit($segments[0])) {
    $tproject_id = needTprojectId();
    if (!canManage($user, $db, $tproject_id)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }

    $id = intval($segments[0]);
    $mgr = platMgrFor($tproject_id);
    needOwnedPlatform($mgr, $id, $tproject_id);

    $p = $mgr->getPlatform($id);
    $all = $mgr->getAll(['include_linked_count' => true]);
    $linked = 0;
    foreach ((array)$all as $row) {
        if (intval($row['id']) == $id) {
            $linked = intval($row['linked_count']);
            break;
        }
    }
    if ($linked > 0) {
        http_response_code(422);
        out([
            'status' => 'error',
            'message' => sprintf('%s is being used! You cannot remove it now. You must first remove it from the testplans using it', $p['name']),
            'error_code' => 'DELETE_BLOCKED',
        ]);
    }

    if ($mgr->delete($id) == tl::OK) {
        out(['status' => 'ok']);
    }
    http_response_code(422);
    out(['status' => 'error', 'message' => 'Platform update failed',
         'error_code' => tlPlatform::E_DBERROR]);
}

// ---------------------------------------------------------------------------
// GET /export?tproject_id=N&filename=... (platform_view)
// Identical output to legacy platformsExport.php doExport():
// ADODB_XML document <platforms><platform>... fields incl. is_open.
// ---------------------------------------------------------------------------
if ($method === 'GET' && isset($segments[0]) && $segments[0] === 'export') {
    $tproject_id = needTprojectId();
    if (!canView($user, $db, $tproject_id)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }

    $tables = tlObjectWithDB::getDBTables(array('platforms'));
    $adodbXML = new ADODB_XML("1.0", "UTF-8");
    $sql = " SELECT name,notes,enable_on_design,enable_on_execution,is_open
             FROM {$tables['platforms']} PLAT
             WHERE PLAT.testproject_id=" . intval($tproject_id);
    $adodbXML->setRootTagName('platforms');
    $adodbXML->setRowTagName('platform');
    $content = $adodbXML->ConvertToXMLString($db->db, $sql);

    $filename = basename(getParam('filename', ''));
    if ($filename === '') {
        $tname = str_ireplace(" ", "", testproject::getName($db, $tproject_id));
        $filename = $tname . '-platforms.xml';
    }

    header('Content-Type: application/xml');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($content));
    echo $content;
    exit;
}

// ---------------------------------------------------------------------------
// POST /import - multipart upload XML (platform_management)
// field: tproject_id, uploadedFile
// Same create-or-update-by-name logic as legacy platformsImport.php doImport().
// ---------------------------------------------------------------------------
if ($method === 'POST' && isset($segments[0]) && $segments[0] === 'import') {
    $tproject_id = intval($_POST['tproject_id'] ?? 0);
    if ($tproject_id <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test project id']);
    }
    if (!canManage($user, $db, $tproject_id)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }

    $fInfo = $_FILES['targetFilename'] ?? $_FILES['uploadedFile'] ?? null;
    if (is_null($fInfo) || $fInfo['error'] == UPLOAD_ERR_NO_FILE) {
        http_response_code(422);
        out(['status' => 'error', 'message' => 'Please choose a platforms file',
             'error_code' => 'NO_FILE']);
    }
    if ($fInfo['error'] != UPLOAD_ERR_OK) {
        http_response_code(422);
        out(['status' => 'error', 'message' => 'File upload failed',
             'error_code' => 'UPLOAD_ERROR']);
    }
    $maxSize = config_get('import_file_max_size_bytes');
    if ($fInfo['size'] > $maxSize) {
        http_response_code(422);
        out(['status' => 'error', 'message' => 'File too large',
             'error_code' => 'TOO_LARGE']);
    }

    $dest = TL_TEMP_PATH . session_id() . "-import_platforms.tmp";
    if (!move_uploaded_file($fInfo['tmp_name'], $dest)) {
        http_response_code(500);
        out(['status' => 'error', 'message' => 'Could not store uploaded file']);
    }

    // http://websec.io/2012/08/27/Preventing-XXE-in-PHP.html
    $xml = @simplexml_load_file_wrapper($dest);
    @unlink($dest);

    if ($xml === FALSE || $xml === null) {
        http_response_code(422);
        out(['status' => 'error', 'message' => 'Problems loading XML content',
             'error_code' => 'WRONG_FORMAT']);
    }

    $mgr = platMgrFor($tproject_id);
    $platformsOnSystem = $mgr->getAllAsMap(['accessKey' => 'name',
                                            'output' => 'rows',
                                            'enable_on_design' => null,
                                            'enable_on_execution' => null,
                                            'is_open' => null]);

    $imported = 0;
    $updated = 0;
    $skipped = 0;
    foreach ($xml as $platform) {
        if (property_exists($platform, 'name')) {
            $name = trim((string)$platform->name);
            if (isset($platformsOnSystem[$name])) {
                $mgr->update($platformsOnSystem[$name]['id'],
                             $name,
                             (string)$platform->notes,
                             intval($platform->enable_on_design),
                             intval($platform->enable_on_execution),
                             intval($platform->is_open));
                $updated++;
            } else {
                $item = new stdClass();
                $item->name = $name;
                $item->notes = (string)$platform->notes;
                $item->enable_on_design = intval($platform->enable_on_design);
                $item->enable_on_execution = intval($platform->enable_on_execution);
                $item->is_open = intval($platform->is_open);
                $mgr->create($item);
                $imported++;
            }
        } else {
            $skipped++;
        }
    }

    out(['status' => 'ok', 'imported' => $imported, 'updated' => $updated,
         'skipped' => $skipped]);
}

// ---------------------------------------------------------------------------
// GET /assign?tproject_id=N[&tplan_id=M] - platform assignment screen data
// Mirrors lib/platforms/platformsAssign.php initializeGui() + init_option_panels().
// Right required: testplan_add_remove_platforms (checkPageAccess).
//
// Left pane  = available platforms: getAllAsMap(config_get('platforms')->allowedOnAssign)
//              minus the linked ones.
// Right pane = linked platforms (getLinkedToTestplanAsMap) + per-platform count of
//              linked test cases (testplan::count_testcases) - used by the UI to
//              block unlinking platforms that still have linked TC versions.
// fixNeeded / unknownQty mirror countLinkedTCVersionsByPlatform()[platform_id=0]:
// linked TC versions still on platform_id=0 ("unknown platform").
// ---------------------------------------------------------------------------
if ($method === 'GET' && isset($segments[0]) && $segments[0] === 'assign' && !isset($segments[1])) {
    $tproject_id = intval(getParam('tproject_id', 0));
    $tplan_id = intval(getParam('tplan_id', 0));

    // allow arriving with only the tplan context (deep link) - derive the project
    if ($tproject_id <= 0 && $tplan_id > 0) {
        $tplan0 = (new testplan($db))->get_by_id($tplan_id);
        if (isset($tplan0['id'])) {
            $tproject_id = intval($tplan0['testproject_id']);
        }
    }
    if ($tproject_id <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test project id']);
    }
    if (!$user->hasRight($db, 'testplan_add_remove_platforms', $tproject_id)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }

    out(assignPayload($db, $user, $tproject_id, $tplan_id));
}

// ---------------------------------------------------------------------------
// POST /assign - link/unlink platforms on a test plan
// body: tproject_id, tplan_id, add:[ids], remove:[ids], mode:'save'|'save_tcv'
// Mirrors legacy doAssignPlatforms / doAssignAndLinkTCV:
//   - linkToTestplan(add) + unlinkFromTestplan(remove)
//   - when TC versions are still linked to platform 0 (fixNeeded) and exactly ONE
//     platform was added -> changeLinkedTCVersionsPlatform(tplan, 0, added)
//   - mode save_tcv historically intended copyLinkFromPlatformToPlatform(), but that
//     method does not exist and its inputs (onRightSide/to_select_box) were never
//     populated in 1.9.20, so both actions only ever link/unlink. Kept as alias for
//     API compatibility with the button.
// Server-side guard mirrors the legacy client-side rule: platforms with linked
// test cases can never be unlinked.
// ---------------------------------------------------------------------------
if ($method === 'POST' && isset($segments[0]) && $segments[0] === 'assign' && !isset($segments[1])) {
    $body = getBody();
    $tproject_id = intval($body['tproject_id'] ?? 0);
    $tplan_id = intval($body['tplan_id'] ?? 0);
    if ($tproject_id <= 0 || $tplan_id <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test project or test plan id']);
    }
    if (!$user->hasRight($db, 'testplan_add_remove_platforms', $tproject_id)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }

    $mgr = platMgrFor($tproject_id);
    $tplan_mgr = new testplan($db);

    $tplan = $tplan_mgr->get_by_id($tplan_id);
    if (!isset($tplan['id']) || intval($tplan['testproject_id']) != $tproject_id) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Test plan not found']);
    }

    $add = array_values(array_filter(array_map('intval', (array)($body['add'] ?? []))));
    $remove = array_values(array_filter(array_map('intval', (array)($body['remove'] ?? []))));

    // qty BEFORE the operations, like legacy line order
    $qtyByPlatform = $tplan_mgr->countLinkedTCVersionsByPlatform($tplan_id);
    $fixNeeded = isset($qtyByPlatform[0]['qty']) && intval($qtyByPlatform[0]['qty']) > 0;

    // every platform must belong to this project
    foreach (array_merge($add, $remove) as $pid) {
        needOwnedPlatform($mgr, $pid, $tproject_id);
    }

    // legacy transferLeft() refuses to move platforms with linked TCs out of the
    // right pane -> enforce the same server-side so remove lists stay clean.
    foreach ($remove as $pid) {
        if ($tplan_mgr->count_testcases($tplan_id, $pid) > 0) {
            $p = $mgr->getPlatform($pid);
            http_response_code(422);
            out([
                'status' => 'error',
                'message' => sprintf('%s has linked test cases and cannot be removed', $p['name']),
                'error_code' => 'PLATFORM_HAS_LINKED_TCVS',
            ]);
        }
    }

    if ($add !== []) {
        $op = $mgr->linkToTestplan($add, $tplan_id);
        if ($op != tl::OK) {
            http_response_code(500);
            out(['status' => 'error', 'message' => 'Linking platform failed']);
        }
    }
    if ($remove !== []) {
        $op = $mgr->unlinkFromTestplan($remove, $tplan_id);
        if ($op != tl::OK) {
            http_response_code(500);
            out(['status' => 'error', 'message' => 'Unlinking platform failed']);
        }
    }

    // legacy doAssignPlatforms: single add fixes the "unknown platform" links
    $reassigned = false;
    if ($fixNeeded && count($add) == 1) {
        $tplan_mgr->changeLinkedTCVersionsPlatform($tplan_id, 0, intval($add[0]));
        $reassigned = true;
    }

    $payload = assignPayload($db, $user, $tproject_id, $tplan_id);
    $payload['reassignedUnknownPlatform'] = $reassigned;
    out($payload);
}

/**
 * Shared payload builder for GET/POST /assign.
 */
function assignPayload($db, $user, $tproject_id, $tplan_id) {
    global $db;

    $mgr = platMgrFor($tproject_id);
    $tplan_mgr = new testplan($db);

    $tplans = [];
    $rows = $GLOBALS['tproject_mgr']->get_all_testplans($tproject_id);
    if (!is_null($rows)) {
        foreach ($rows as $r) {
            $tplans[] = ['id' => intval($r['id']), 'name' => (string)$r['name'],
                         'active' => intval($r['active'] ?? 0),
                         'is_open' => intval($r['is_open'] ?? 0)];
        }
    }

    $available = [];
    $assigned = [];
    $fixNeeded = false;
    $unknownQty = 0;
    $tplanName = null;

    if ($tplan_id > 0) {
        $tplan = $tplan_mgr->get_by_id($tplan_id);
        if (isset($tplan['id']) && intval($tplan['testproject_id']) == $tproject_id) {
            $tplanName = (string)$tplan['name'];

            $qtyByPlatform = $tplan_mgr->countLinkedTCVersionsByPlatform($tplan_id);
            $unknownQty = isset($qtyByPlatform[0]['qty']) ? intval($qtyByPlatform[0]['qty']) : 0;
            $fixNeeded = ($unknownQty > 0);

            // left pane source set - exact legacy config_get('platforms')->allowedOnAssign
            $fromMap = $mgr->getAllAsMap(config_get('platforms')->allowedOnAssign);
            $linkedMap = $mgr->getLinkedToTestplanAsMap($tplan_id);

            foreach ((array)$linkedMap as $pid => $pname) {
                $count = intval($tplan_mgr->count_testcases($tplan_id, $pid));
                $row = $mgr->getPlatform($pid);
                $assigned[] = [
                    'id' => intval($pid),
                    'name' => (string)$row['name'],
                    'is_open' => intval($row['is_open']),
                    'displayName' => (string)$pname,
                    'linkedCount' => $count,
                ];
                unset($fromMap[$pid]);
            }
            foreach ((array)$fromMap as $pid => $pname) {
                $available[] = ['id' => intval($pid), 'name' => (string)$pname];
            }
        } else {
            $tplan_id = 0;
        }
    }

    return [
        'status' => 'ok',
        'tproject' => ['id' => $tproject_id, 'name' => testproject::getName($db, $tproject_id)],
        'tplan' => $tplan_id > 0 ? ['id' => $tplan_id, 'name' => $tplanName] : null,
        'tplans' => $tplans,
        'available' => $available,
        'assigned' => $assigned,
        'fixNeeded' => $fixNeeded,
        'unknownQty' => $unknownQty,
        'rights' => [
            'canAssign' => (bool)$user->hasRight($db, 'testplan_add_remove_platforms', $tproject_id),
        ],
    ];
}

http_response_code(404);
out(['status' => 'error', 'message' => 'Not found']);
