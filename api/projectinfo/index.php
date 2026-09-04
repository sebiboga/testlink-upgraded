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
 *   GET ?action=info&id=<project_id>
 *   GET ?action=info&tproject_id=<project_id>   (alias, legacy param name)
 *   GET ?action=info                            (falls back to the session's
 *                                                testprojectID, like legacy)
 *       -> project attributes + option flags + attachment list + grants
 *
 * Auth: same as legacy archiveData.php - any authenticated user can view the
 * project info (no extra hard right gate; the reachable callers are inside an
 * authenticated work area). Grants are returned so the UI surfaces what the
 * user may actually do (e.g. "Manage project" only with mgt_modify_product).
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
    array('nodes_hierarchy', 'attachments', 'testprojects'));

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

if ($action === 'info') {
    $projectId = resolveProjectId();
    if ($projectId <= 0) {
        http_response_code(400);
        out(array('status' => 'error', 'message' => 'Missing project id'));
    }

    $tprojectMgr = new testproject($db);
    $project = $tprojectMgr->get_by_id($projectId);
    if (is_null($project)) {
        http_response_code(404);
        out(array('status' => 'error', 'message' => 'Test project not found'));
    }

    $opt = isset($project['opt']) && is_object($project['opt'])
        ? $project['opt']
        : (object) array('requirementsEnabled' => 0, 'testPriorityEnabled' => 0,
                         'automationEnabled' => 0, 'inventoryEnabled' => 0);

    // attachments bound to the project node ("nodes_hierarchy" fk_table,
    // same convention as the suite viewer BFF)
    $attachments = array();
    $attRows = $db->get_recordset(
        "SELECT id, title, file_name, file_type, file_size, date_added " .
        "FROM {$tables['attachments']} " .
        "WHERE fk_id = {$projectId} AND fk_table = 'nodes_hierarchy' " .
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

    $projectIdS = intval($projectId);
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
        'grants' => array(
            'mgt_modify_product' => $user->hasRight($db, 'mgt_modify_product', $projectIdS),
            'mgt_view_tc'        => $user->hasRight($db, 'mgt_view_tc', $projectIdS),
            'mgt_view_req'       => $user->hasRight($db, 'mgt_view_req', $projectIdS),
        ),
    ));
}

http_response_code(400);
out(array('status' => 'error', 'message' => 'Unknown action'));