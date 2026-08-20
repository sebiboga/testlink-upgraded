<?php
/**
 * TestLink Projects Management API (BFF)
 * Modern REST API for test project management
 */

require_once('../../config.inc.php');
require_once('../../lib/functions/common.php');

// Initialize
testlinkInitPage($GLOBALS['db'], false, false, "checkRights");
$user = $_SESSION['currentUser'];
$db = $GLOBALS['db'];

// Parse request
$method = $_SERVER['REQUEST_METHOD'];
$pathInfo = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$parts = array_filter(explode('/', $pathInfo));
$projectId = end($parts) !== 'projects' ? (int)end($parts) : null;

header('Content-Type: application/json');

try {
  switch ($method) {
    case 'GET':
      if ($projectId) {
        getProjectDetail($db, $user, $projectId);
      } else {
        listProjects($db, $user);
      }
      break;

    case 'POST':
      createProject($db, $user);
      break;

    case 'PUT':
      if (!$projectId) throw new Exception('Project ID required');
      updateProject($db, $user, $projectId);
      break;

    case 'DELETE':
      if (!$projectId) throw new Exception('Project ID required');
      deleteProject($db, $user, $projectId);
      break;

    default:
      http_response_code(405);
      echo json_encode(['error' => 'Method not allowed']);
  }
} catch (Exception $e) {
  http_response_code(400);
  echo json_encode(['error' => $e->getMessage()]);
}

function listProjects(&$db, &$user) {
  $tproject_mgr = new testproject($db);
  $opt = array(
    'output' => 'array_of_map',
    'order_by' => ' ORDER BY name ',
    'add_issuetracker' => true,
    'add_codetracker' => true,
    'add_reqmgrsystem' => true
  );

  $userID = $user->dbID;
  $projects = $tproject_mgr->get_accessible_for_user($userID, $opt);

  if (is_null($projects)) {
    $projects = array();
  }

  // Format response
  $result = array();
  foreach ($projects as $p) {
    $result[] = array(
      'id' => (int)$p['id'],
      'name' => $p['name'],
      'description' => $p['notes'] ?? '',
      'isActive' => (int)$p['active'],
      'issueTracker' => array(
        'name' => $p['it_name'] ?? '',
        'enabled' => (bool)($p['issue_tracker_enabled'] ?? false)
      ),
      'codeTracker' => array(
        'name' => $p['ct_name'] ?? '',
        'enabled' => (bool)($p['code_tracker_enabled'] ?? false)
      ),
      'requirementsManager' => $p['reqmgrsysname'] ?? ''
    );
  }

  echo json_encode([
    'success' => true,
    'data' => $result,
    'count' => count($result),
    'canManage' => $user->hasRight($db, 'mgt_modify_product')
  ]);
}

function getProjectDetail(&$db, &$user, $projectId) {
  $tproject_mgr = new testproject($db);
  $project = $tproject_mgr->get_by_id($projectId);

  if (!$project) {
    http_response_code(404);
    echo json_encode(['error' => 'Project not found']);
    return;
  }

  echo json_encode([
    'success' => true,
    'data' => array(
      'id' => (int)$project['id'],
      'name' => $project['name'],
      'description' => $project['notes'] ?? '',
      'isActive' => (int)$project['is_active']
    )
  ]);
}

function createProject(&$db, &$user) {
  if (!$user->hasRight($db, 'mgt_modify_product')) {
    http_response_code(403);
    echo json_encode(['error' => 'Permission denied']);
    return;
  }

  $input = json_decode(file_get_contents('php://input'), true);
  if (!$input || empty($input['name'])) {
    throw new Exception('Project name is required');
  }

  $tproject_mgr = new testproject($db);
  $newId = $tproject_mgr->create(
    trim($input['name']),
    isset($input['description']) ? trim($input['description']) : ''
  );

  if (!$newId) {
    throw new Exception('Failed to create project');
  }

  echo json_encode([
    'success' => true,
    'id' => $newId,
    'message' => 'Project created successfully'
  ]);
}

function updateProject(&$db, &$user, $projectId) {
  if (!$user->hasRight($db, 'mgt_modify_product')) {
    http_response_code(403);
    echo json_encode(['error' => 'Permission denied']);
    return;
  }

  $input = json_decode(file_get_contents('php://input'), true);

  $tproject_mgr = new testproject($db);
  $project = $tproject_mgr->get_by_id($projectId);

  if (!$project) {
    http_response_code(404);
    echo json_encode(['error' => 'Project not found']);
    return;
  }

  // Update project
  $sql = "UPDATE testproject SET ";
  $updates = array();
  if (isset($input['name'])) $updates[] = "name='" . $db->prepare_string($input['name']) . "'";
  if (isset($input['description'])) $updates[] = "notes='" . $db->prepare_string($input['description']) . "'";
  if (isset($input['isActive'])) $updates[] = "is_active=" . (int)$input['isActive'];

  if (empty($updates)) {
    echo json_encode(['success' => true, 'message' => 'No changes']);
    return;
  }

  $sql .= implode(',', $updates) . " WHERE id=" . (int)$projectId;
  $db->exec_query($sql);

  echo json_encode([
    'success' => true,
    'id' => $projectId,
    'message' => 'Project updated successfully'
  ]);
}

function deleteProject(&$db, &$user, $projectId) {
  if (!$user->hasRight($db, 'mgt_modify_product')) {
    http_response_code(403);
    echo json_encode(['error' => 'Permission denied']);
    return;
  }

  $tproject_mgr = new testproject($db);
  $project = $tproject_mgr->get_by_id($projectId);

  if (!$project) {
    http_response_code(404);
    echo json_encode(['error' => 'Project not found']);
    return;
  }

  // Delete project (soft delete by deactivating)
  $sql = "UPDATE testproject SET is_active=0 WHERE id=" . (int)$projectId;
  $db->exec_query($sql);

  echo json_encode([
    'success' => true,
    'message' => 'Project deleted successfully'
  ]);
}

function checkRights(&$db, &$user) {
  return $user->hasRight($db, 'mgt_modify_product');
}
