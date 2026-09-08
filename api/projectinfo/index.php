<?php
/**
 * Test Project Information Viewer BFF API
 * URL: /api/projectinfo/index.php
 * Plain PHP, no framework.
 *
 * Mirrors the read-only "test project" viewer of the legacy
 * lib/testcases/archiveData.php?edit=testproject&id=<id> screen (TestLink
 * 1.9.20 containerView.tpl, rendered via testproject::show()): project
 * attributes, options and attachments. This viewer was the last standalone
 * legacy screen still reachable from the modern UI - it is the "home" /
 * cancel target of lib/general/frmWorkArea.php and of the plan/execution
 * navigators (planAddTCNavigator.php, execNavigator.php), and it is listed
 * as a pending legacy redirect (#756).
 *
 * Endpoints (JSON out):
 *   GET  ?action=info&id=<project_id>
 *   GET  ?action=info&tproject_id=<project_id>   (alias, legacy param name)
 *   GET  ?action=info                            (falls back to the session's
 *                                                 testprojectID, like legacy)
 *        -> project attributes + option flags + attachment list + grants +
 *           canDoExport (whether the project has at least one direct test
 *           suite child, mirroring legacy canDoExport)
 *   POST ?action=upload&id=<project_id>          (multipart: uploadedFile +
 *                                                 fileTitle) -> uploads a new
 *        attachment bound to the project node ('nodes_hierarchy'), mirroring
 *        lib/testcases/containerEdit.php doAction=fileUpload via
 *        fileUploadManagement(). Returns the new attachment row.
 *   POST ?action=delete&id=<project_id>&file_id=<id>
 *        -> deletes the attachment, mirroring containerEdit.php
 *        doAction=deleteFile via deleteAttachment(). The attachment must be
 *        bound to THIS project node (fk_id + fk_table guard) before the
 *        delete is issued. Returns the refreshed attachment list.
 *   POST ?action=new_suite&id=<project_id>
 *        (JSON body: name, details?) -> creates a test suite directly under
 *        the project, mirroring containerEdit.php doAction=add_testsuite
 *        (addTestSuite() at containerEdit.php:729) i.e.
 *        testsuite::create(parent=$projectId, name, details, null,
 *        config_get('check_names_for_duplicates'), 'block'). Requires
 *        mgt_modify_tc (legacy testcase_mgmt). Fires
 *        EVENT_TEST_SUITE_CREATE like the legacy path. Returns the new suite
 *        id + refreshed suite list.
 *   POST ?action=reorder_suites_alpha&id=<project_id>
 *        -> reorders the project's direct test-suite children alphabetically
 *        (natural sort, case-insensitive), mirroring containerEdit.php
 *        doAction=reorder_testproject_testsuites_alpha (containerEdit.php:337
 *        -> reorderTestSuitesDictionary at containerEdit.php:1381: get_children
 *        excluding testplan/requirement/testcase/requirement_spec, natsort on
 *        lowercased name, then tree::change_order_bulk). Requires
 *        mgt_modify_tc. Returns the refreshed (ordered) suite list.
 *
 * Auth: same as legacy archiveData.php - any authenticated user can view the
 * project info (no extra hard right gate; the reachable callers are inside an
 * authenticated work area). Grants are returned so the UI surfaces what the
 * user may actually do (e.g. "Manage project" only with mgt_modify_product).
 * The write actions (upload/delete) require POST and mgt_modify_tc on
 * the owning test project (403 otherwise). This matches legacy
 * containerEdit.php which gated project-level fileUpload/deleteFile with
 * mgt_modify_tc (via "testcase_mgmt"). An explicit fk_id/fk_table ownership
 * guard on delete is added as BFF hardening.
 * Unknown / forged project id -> 404.
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json');

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || $userId <= 0) {
    http_response_code(401);
    echo json_encode(array('status' => 'error', 'message' => 'Not authenticated'));
    exit;
}

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    http_response_code(401);
    echo json_encode(array('status' => 'error', 'message' => 'User not found'));
    exit;
}

function out($data) { echo json_encode($data); exit; }

$action = isset($_REQUEST['action']) ? trim($_REQUEST['action']) : '';

$tables = tlObjectWithDB::getDBTables(
    array('nodes_hierarchy', 'attachments', 'testprojects', 'testsuites',
          'node_types'));

/**
 * Resolve the target project id in the same order as legacy archiveData.php:
 * explicit id > tproject_id > session testprojectID.
 */
function resolveProjectId() {
    if (isset($_REQUEST['id']) && $_REQUEST['id'] !== '') {
        return intval($_REQUEST['id']);
    }
    if (isset($_REQUEST['tproject_id']) && $_REQUEST['tproject_id'] !== '') {
        return intval($_REQUEST['tproject_id']);
    }
    return isset($_SESSION['testprojectID']) ? intval($_SESSION['testprojectID']) : 0;
}

/**
 * Load the project node and return the testproject record (or null).
 */
function loadProject($db, $projectId) {
    $tprojectMgr = new testproject($db);
    $project = $tprojectMgr->get_by_id(intval($projectId));
    return $project;
}

/**
 * Count direct children of a project that are "exportable" (test suites).
 * Mirrors the legacy canDoExport computation in
 * lib/testcases/containerEdit.php / lib/functions/testproject.class.php:777-778:
 *   $exclusion = array('testcase', 'me', 'testplan' => 'me', 'requirement_spec' => 'me');
 *   $gui->canDoExport = count((array)$this->tree_manager->get_children($safeID,$exclusion)) > 0;
 * i.e. only direct test-suite children make the project exportable.
 */
function countExportChildren($dbHandler, $tables, $projectId) {
    $rows = $dbHandler->get_recordset(
        "SELECT nt.description AS t FROM {$tables['nodes_hierarchy']} nh " .
        "JOIN {$tables['node_types']} nt ON nh.node_type_id = nt.id " .
        "WHERE nh.parent_id = " . intval($projectId));
    if (is_null($rows)) {
        return 0;
    }
    $excl = array('testproject', 'testplan', 'requirement_spec', 'requirement',
                  'requirement_version', 'testcase', 'testcase_version',
                  'requirement_revision', 'requirement_spec_revision',
                  'testcase_step', 'build', 'platform', 'user');
    $c = 0;
    foreach ($rows as $r) {
        $t = isset($r['t']) ? $r['t'] : (isset($r['description']) ? $r['description'] : '');
        if ($t !== '' && !in_array($t, $excl, true)) {
            $c++;
        }
    }
    return $c;
}

/**
 * List the DIRECT test-suite children of a project, in current display order
 * (legacy containerView.tpl renders the project's suite list via
 * tree_manager->get_children with the same exclusion set used by
 * reorderTestSuitesDictionary). Details come from the testsuites table.
 */
function getProjectSuites($db, $tables, $projectId) {
    $suites = array();
    $tSuiteType = 2; // node_types.description='testsuite'; guard against drift
    $typeRows = $db->get_recordset(
        "SELECT id, description FROM {$tables['node_types']}");
    if (!is_null($typeRows)) {
        foreach ($typeRows as $tr) {
            if (strval($tr['description']) === 'testsuite') {
                $tSuiteType = intval($tr['id']);
                break;
            }
        }
    }
    $rows = $db->get_recordset(
        "SELECT nh.id, nh.name, nh.node_order, ts.details " .
        "FROM {$tables['nodes_hierarchy']} nh " .
        "LEFT JOIN {$tables['testsuites']} ts ON ts.id = nh.id " .
        "WHERE nh.parent_id = " . intval($projectId) . " " .
        "AND nh.node_type_id = {$tSuiteType} " .
        "ORDER BY nh.node_order, nh.id");
    if (!is_null($rows) && count($rows) > 0) {
        foreach ($rows as $r) {
            $suites[] = array(
                'id'         => intval($r['id']),
                'name'       => strval($r['name']),
                'node_order' => intval($r['node_order']),
                'details'    => strval($r['details'] ?? ''),
            );
        }
    }
    return $suites;
}

/**
 * Fetch attachments bound to the project node ("nodes_hierarchy" fk_table,
 * same convention as the suite viewer BFF).
 */
function getProjectAttachments($db, $tables, $projectId) {
    $attachments = array();
    $attRows = $db->get_recordset(
        "SELECT id, title, file_name, file_type, file_size, date_added " .
        "FROM {$tables['attachments']} " .
        "WHERE fk_id = " . intval($projectId) . " AND fk_table = 'nodes_hierarchy' " .
        "ORDER BY date_added DESC LIMIT 50");
    if (!is_null($attRows) && count($attRows) > 0) {
        foreach ($attRows as $a) {
            $attachments[] = array(
                'id'          => intval($a['id']),
                'title'       => strval($a['title']),
                'file_name'   => strval($a['file_name']),
                'file_type'   => strval($a['file_type']),
                'file_size'   => intval($a['file_size']),
                'date_added'  => strval($a['date_added']),
                'download_url' => '/lib/attachments/attachmentdownload.php?id=' . intval($a['id']),
            );
        }
    }
    return $attachments;
}

if ($action === 'info') {
    $projectId = resolveProjectId();
    if ($projectId <= 0) {
        http_response_code(400);
        out(array('status' => 'error', 'message' => 'Missing project id'));
    }

    $project = loadProject($db, $projectId);
    if (is_null($project)) {
        http_response_code(404);
        out(array('status' => 'error', 'message' => 'Test project not found'));
    }

    $opt = isset($project['opt']) && is_object($project['opt'])
        ? $project['opt']
        : (object) array('requirementsEnabled' => 0, 'testPriorityEnabled' => 0,
                         'automationEnabled' => 0, 'inventoryEnabled' => 0);

    $projectIdS = intval($projectId);
    $attachments = getProjectAttachments($db, $tables, $projectIdS);
    out(array(
        'status' => 'ok',
        'project' => array(
            'id'        => $projectIdS,
            'name'      => strval($project['name']),
            'prefix'    => strval($project['prefix']),
            'notes'     => strval($project['notes']),
            'color'     => strval($project['color']),
            'active'    => intval($project['active']) === 1,
            'is_public' => intval($project['is_public']) === 1,
            'tc_counter' => intval($project['tc_counter']),
            'options' => array(
                'requirementsEnabled'  => intval($opt->requirementsEnabled ?? 0) === 1,
                'testPriorityEnabled'  => intval($opt->testPriorityEnabled ?? 0) === 1,
                'automationEnabled'    => intval($opt->automationEnabled ?? 0) === 1,
                'inventoryEnabled'     => intval($opt->inventoryEnabled ?? 0) === 1,
            ),
            'flags' => array(
                'issueTrackerEnabled'     => intval($project['issue_tracker_enabled']) === 1,
                'codeTrackerEnabled'      => intval($project['code_tracker_enabled']) === 1,
                'reqmgrIntegrationEnabled' => intval($project['reqmgr_integration_enabled']) === 1,
            ),
        ),
        'attachments' => $attachments,
        'canDoExport' => countExportChildren($db, $tables, $projectIdS) > 0,
        'suites' => getProjectSuites($db, $tables, $projectIdS),
        'grants' => array(
            'mgt_modify_product' => $user->hasRight($db, 'mgt_modify_product', $projectIdS),
            'mgt_modify_tc'      => $user->hasRight($db, 'mgt_modify_tc', $projectIdS),
            'mgt_view_tc'        => $user->hasRight($db, 'mgt_view_tc', $projectIdS),
            'mgt_view_req'       => $user->hasRight($db, 'mgt_view_req', $projectIdS),
        ),
    ));
}

if ($action === 'upload') {
    // Mirrors lib/testcases/containerEdit.php doAction=fileUpload for the
    // testproject container level: fileUploadManagement($db, tprojectID,
    // fileTitle, attachment table name = 'nodes_hierarchy').
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        out(array('status' => 'error', 'message' => 'Method not allowed'));
    }
    $projectId = resolveProjectId();
    if ($projectId <= 0) {
        http_response_code(400);
        out(array('status' => 'error', 'message' => 'Missing project id'));
    }

    $project = loadProject($db, $projectId);
    if (is_null($project)) {
        http_response_code(404);
        out(array('status' => 'error', 'message' => 'Test project not found'));
    }

    if (!$user->hasRight($db, 'mgt_modify_tc', $projectId)) {
        http_response_code(403);
        out(array('status' => 'error', 'message' => 'No permission to upload attachments'));
    }

    $title = isset($_REQUEST['fileTitle']) ? trim(strval($_REQUEST['fileTitle'])) : '';
    $uploadOp = fileUploadManagement($db, $projectId, $title, 'nodes_hierarchy');
    if ($uploadOp->statusOK) {
        $attachments = getProjectAttachments($db, $tables, $projectId);
        out(array(
            'status'     => 'ok',
            'message'    => strval($uploadOp->msg ?? ($title !== '' ? $title : '')),
            'attachments' => $attachments,
        ));
    }

    $statusCode = isset($uploadOp->statusCode) ? strval($uploadOp->statusCode) : 'upload_failed';
    $msg = isset($uploadOp->msg) && $uploadOp->msg !== '' && !is_null($uploadOp->msg)
        ? strval($uploadOp->msg)
        : (($statusCode !== '' && $statusCode !== '0')
            ? $statusCode
            : (trim(strval($_FILES['uploadedFile']['name'] ?? '')) === ''
                ? 'No file uploaded'
                : 'upload failed'));
    http_response_code(422);
    out(array(
        'status' => 'error',
        'message' => $msg,
        'code' => $statusCode,
    ));
}

if ($action === 'delete') {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        out(array('status' => 'error', 'message' => 'Method not allowed'));
    }
    $projectId = resolveProjectId();
    if ($projectId <= 0) {
        http_response_code(400);
        out(array('status' => 'error', 'message' => 'Missing project id'));
    }

    $project = loadProject($db, $projectId);
    if (is_null($project)) {
        http_response_code(404);
        out(array('status' => 'error', 'message' => 'Test project not found'));
    }

    if (!$user->hasRight($db, 'mgt_modify_tc', $projectId)) {
        http_response_code(403);
        out(array('status' => 'error', 'message' => 'No permission to delete attachments'));
    }

    $fileId = isset($_REQUEST['file_id']) ? intval($_REQUEST['file_id']) : 0;
    if ($fileId <= 0) {
        http_response_code(400);
        out(array('status' => 'error', 'message' => 'Missing file id'));
    }

    // Security guard (BFF hardening): the attachment must exist AND be bound to
    // THIS project node before the delete is issued, so a forged file_id cannot
    // remove attachments of other containers even with mgt_modify_product.
    $attRows = $db->get_recordset(
        "SELECT id FROM {$tables['attachments']} " .
        "WHERE id = {$fileId} AND fk_id = " . intval($projectId) . " " .
        "AND fk_table = 'nodes_hierarchy'");
    if (is_null($attRows) || count($attRows) === 0) {
        http_response_code(404);
        out(array('status' => 'error', 'message' => 'Attachment not found on this project'));
    }

    // Legacy containerEdit.php deleteFile path (deleteAttachment with the
    // session check disabled - the BFF did not store the list in session).
    $info = deleteAttachment($db, $fileId, false);
    $attachments = getProjectAttachments($db, $tables, $projectId);
    out(array(
        'status'      => 'ok',
        'deleted_id'  => $fileId,
        'deleted'     => !is_null($info),
        'attachments' => $attachments,
    ));
}

if ($action === 'new_suite') {
    // Mirrors lib/testcases/containerEdit.php doAction=add_testsuite
    // (addTestSuite(), containerEdit.php:729) for level=testproject: creates a
    // test suite directly under the project node.
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        out(array('status' => 'error', 'message' => 'Method not allowed'));
    }
    $projectId = resolveProjectId();
    if ($projectId <= 0) {
        http_response_code(400);
        out(array('status' => 'error', 'message' => 'Missing project id'));
    }

    $project = loadProject($db, $projectId);
    if (is_null($project)) {
        http_response_code(404);
        out(array('status' => 'error', 'message' => 'Test project not found'));
    }

    // Legacy gate: containerEdit.php:$doIt = (grants->testcase_mgmt == 'yes'),
    // i.e. mgt_modify_tc.
    if (!$user->hasRight($db, 'mgt_modify_tc', $projectId)) {
        http_response_code(403);
        out(array('status' => 'error', 'message' => 'No permission: modify test cases required'));
    }

    $rawBody = file_get_contents('php://input');
    $body = array();
    if ($rawBody !== false && trim($rawBody) !== '') {
        $decoded = json_decode($rawBody, true);
        if (is_array($decoded)) {
            $body = $decoded;
        } else {
            $body = $_POST;
        }
    } else {
        $body = $_POST;
    }

    $name = trim(strval($body['name'] ?? ''));
    $details = strval($body['details'] ?? '');
    if ($name === '') {
        http_response_code(400);
        out(array('status' => 'error', 'code' => 'empty_name',
                  'message' => 'Please give a name to Test Suite'));
    }
    // Legacy gate + name checks: containerEdit.php uses the global
    // $g_ereg_forbidden (config.inc.php) via check_string().
    if (!check_string($name, $g_ereg_forbidden)) {
        http_response_code(400);
        out(array('status' => 'error', 'code' => 'bad_chars',
                  'message' => 'Test Suite name contains forbidden characters'));
    }

    $tsuiteMgr = new testsuite($db);
    $ret = $tsuiteMgr->create(intval($projectId), $name, $details, null,
                              config_get('check_names_for_duplicates'), 'block');

    if (!is_array($ret) || !(isset($ret['status_ok']) ? $ret['status_ok'] : false)) {
        $msg = (is_array($ret) && isset($ret['msg'])) ? strval($ret['msg']) : 'Create failed';
        $code = (is_array($ret) && isset($ret['msg']) && $ret['msg'] !== 'ok') ? 'duplicate' : 'create_failed';
        http_response_code(400);
        out(array('status' => 'error', 'code' => $code, 'message' => $msg));
    }

    $newId = intval($ret['id'] ?? 0);
    if ($newId <= 0) {
        http_response_code(500);
        out(array('status' => 'error', 'code' => 'create_failed', 'message' => 'Create failed'));
    }

    // Legacy addTestSuite() fires EVENT_TEST_SUITE_CREATE after a successful
    // create (containerEdit.php:761) with the same context.
    if (function_exists('event_signal')) {
        event_signal('EVENT_TEST_SUITE_CREATE', array(
            'id' => $newId,
            'name' => $name,
            'details' => $details,
        ));
    }

    out(array(
        'status'  => 'ok',
        'id'      => $newId,
        'name'    => $name,
        'message' => 'testsuite_created',
        'suites'  => getProjectSuites($db, $tables, intval($projectId)),
    ));
}

if ($action === 'reorder_suites_alpha') {
    // Mirrors lib/testcases/containerEdit.php doAction=
    // reorder_testproject_testsuites_alpha (containerEdit.php:337) ->
    // reorderTestSuitesDictionary() (containerEdit.php:1381): natural-sort the
    // project's direct non-testcase/plan/req children by lowercase name and
    // rewrite node_order sequentially via tree::change_order_bulk().
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        out(array('status' => 'error', 'message' => 'Method not allowed'));
    }
    $projectId = resolveProjectId();
    if ($projectId <= 0) {
        http_response_code(400);
        out(array('status' => 'error', 'message' => 'Missing project id'));
    }

    $project = loadProject($db, $projectId);
    if (is_null($project)) {
        http_response_code(404);
        out(array('status' => 'error', 'message' => 'Test project not found'));
    }

    if (!$user->hasRight($db, 'mgt_modify_tc', $projectId)) {
        http_response_code(403);
        out(array('status' => 'error', 'message' => 'No permission: modify test cases required'));
    }

    $treeMgr = new tree($db);
    $excludeNodeTypes = array('testplan' => 1, 'requirement' => 1,
                              'testcase' => 1, 'requirement_spec' => 1);
    $itemSet = $treeMgr->get_children(intval($projectId), $excludeNodeTypes);
    if (is_array($itemSet) && count($itemSet) > 0) {
        $a2sort = array();
        foreach ($itemSet as $node) {
            $a2sort[intval($node['id'])] = strtolower(strval($node['name']));
        }
        natsort($a2sort);
        $a2sort = array_keys($a2sort);
        $treeMgr->change_order_bulk($a2sort);
    }

    out(array(
        'status'  => 'ok',
        'message' => 'suites_reordered',
        'suites'  => getProjectSuites($db, $tables, intval($projectId)),
    ));
}

http_response_code(400);
out(array('status' => 'error', 'message' => 'Unknown action'));