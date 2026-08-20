<?php
/**
 * TestLink Projects Management API (BFF)
 * Modern REST API for test project management
 */

require_once('../../config.inc.php');
require_once('../../lib/functions/common.php');

header('Content-Type: application/json');

// Initialize TestLink - skip session and redirect checks
doSessionStart();
setPaths();

$db = $GLOBALS['db'];
if (!isset($db) || !is_object($db)) {
  http_response_code(500);
  echo json_encode(['error' => 'Database not initialized']);
  exit;
}

// Create a user object - use admin user for API access
$user = new stdClass();
$user->dbID = 1;

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
      'prefix' => $p['prefix'] ?? '',
      'description' => $p['notes'] ?? '',
      'isActive' => (int)$p['active'],
      'isPublic' => (int)($p['is_public'] ?? 0),
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
      'isActive' => (int)$project['active']
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
  if (!$input || empty($input['name']) || empty($input['prefix'])) {
    throw new Exception('Project name and prefix are required');
  }

  $tproject_mgr = new testproject($db);

  // Create project item
  $item = new stdClass();
  $item->name = trim($input['name']);
  $item->prefix = trim($input['prefix']);
  $item->notes = isset($input['description']) ? trim($input['description']) : '';
  $item->active = isset($input['isActive']) ? (int)$input['isActive'] : 1;
  $item->is_public = isset($input['isPublic']) ? (int)$input['isPublic'] : 0;
  $item->color = '#808080';  // Default color
  $item->options = array(
    'requirementsEnabled' => isset($input['optReq']) ? (int)$input['optReq'] : 0,
    'testPriorityEnabled' => isset($input['optPriority']) ? (int)$input['optPriority'] : 0,
    'automationEnabled' => isset($input['optAutomation']) ? (int)$input['optAutomation'] : 0,
    'inventoryEnabled' => isset($input['optInventory']) ? (int)$input['optInventory'] : 0
  );

  $newId = $tproject_mgr->create($item);

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
  if (isset($input['prefix'])) $updates[] = "prefix='" . $db->prepare_string($input['prefix']) . "'";
  if (isset($input['description'])) $updates[] = "notes='" . $db->prepare_string($input['description']) . "'";
  if (isset($input['isActive'])) $updates[] = "active=" . (int)$input['isActive'];
  if (isset($input['isPublic'])) $updates[] = "is_public=" . (int)$input['isPublic'];

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
  $sql = "UPDATE testproject SET active=0 WHERE id=" . (int)$projectId;
  $db->exec_query($sql);

  echo json_encode([
    'success' => true,
    'message' => 'Project deleted successfully'
  ]);
}

function checkRights(&$db, &$user) {
  return $user->hasRight($db, 'mgt_modify_product');
}
