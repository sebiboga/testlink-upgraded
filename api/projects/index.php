<?php
/**
 * TestLink Projects Management API (BFF)
 *
 * Uses TestLink's DB layer + testproject class directly, bypassing the page
 * bootstrap: testlinkInitPage() redirects to login.php on an expired session,
 * which breaks JSON clients.
 */

require_once('../../config.inc.php');
require_once('../../lib/functions/common.php');

header('Content-Type: application/json');

$db = null;
doDBConnect($db);

$tprojectMgr = new testproject($db);

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$parts = array_values(array_filter(explode('/', $path)));
$last = end($parts);
$projectId = ctype_digit((string)$last) ? (int)$last : null;

try {
  switch ($method) {
    case 'GET':
      $projectId ? getProject($db, $projectId) : listProjects($db);
      break;

    case 'POST':
      createProject($db, $tprojectMgr);
      break;

    case 'PUT':
      if (!$projectId) throw new Exception('Project ID required');
      updateProject($db, $projectId);
      break;

    case 'DELETE':
      if (!$projectId) throw new Exception('Project ID required');
      deleteProject($db, $projectId);
      break;

    default:
      http_response_code(405);
      echo json_encode(['error' => 'Method not allowed']);
  }
} catch (Exception $e) {
  http_response_code(400);
  echo json_encode(['error' => $e->getMessage()]);
}

function projectSelect() {
  return "SELECT tp.id, nh.name, tp.prefix, tp.notes, tp.active, tp.is_public,
                 tp.option_reqs, tp.option_priority, tp.option_automation, tp.options,
                 tp.issue_tracker_enabled, tp.code_tracker_enabled,
                 it.name AS issue_tracker_name, ct.name AS code_tracker_name
          FROM testprojects tp
          JOIN nodes_hierarchy nh ON nh.id = tp.id
          LEFT JOIN testproject_issuetracker tpit ON tpit.testproject_id = tp.id
          LEFT JOIN issuetrackers it ON it.id = tpit.issuetracker_id
          LEFT JOIN testproject_codetracker tpct ON tpct.testproject_id = tp.id
          LEFT JOIN codetrackers ct ON ct.id = tpct.codetracker_id ";
}

function formatProject($row) {
  // testproject::create() only persists the serialized blob, leaving the
  // option_* columns at 0, so the blob is the authoritative source.
  $opt = empty($row['options']) ? null : @unserialize($row['options']);
  $flag = function ($name) use ($opt) {
    return (is_object($opt) && !empty($opt->$name)) ? 1 : 0;
  };

  return [
    'id' => (int)$row['id'],
    'name' => $row['name'],
    'prefix' => $row['prefix'],
    'description' => $row['notes'] ?? '',
    'isActive' => (int)$row['active'],
    'isPublic' => (int)$row['is_public'],
    'optReq' => $flag('requirementsEnabled'),
    'optPriority' => $flag('testPriorityEnabled'),
    'optAutomation' => $flag('automationEnabled'),
    'optInventory' => $flag('inventoryEnabled'),
    'issueTracker' => [
      'name' => $row['issue_tracker_name'] ?? '',
      'enabled' => (bool)$row['issue_tracker_enabled']
    ],
    'codeTracker' => [
      'name' => $row['code_tracker_name'] ?? '',
      'enabled' => (bool)$row['code_tracker_enabled']
    ]
  ];
}

function listProjects(&$db) {
  $rows = $db->get_recordset(projectSelect() . " ORDER BY nh.name");
  $result = array_map('formatProject', (array)$rows);

  echo json_encode([
    'success' => true,
    'data' => $result,
    'count' => count($result)
  ]);
}

function getProject(&$db, $projectId) {
  $rows = $db->get_recordset(projectSelect() . " WHERE tp.id = " . (int)$projectId);

  if (empty($rows)) {
    http_response_code(404);
    echo json_encode(['error' => 'Project not found']);
    return;
  }

  echo json_encode(['success' => true, 'data' => formatProject($rows[0])]);
}

function createProject(&$db, &$tprojectMgr) {
  $input = json_decode(file_get_contents('php://input'), true);

  if (empty($input['name']) || empty($input['prefix'])) {
    throw new Exception('Project name and prefix are required');
  }

  $options = new stdClass();
  $options->requirementsEnabled = (int)($input['optReq'] ?? 0);
  $options->testPriorityEnabled = (int)($input['optPriority'] ?? 0);
  $options->automationEnabled = (int)($input['optAutomation'] ?? 0);
  $options->inventoryEnabled = (int)($input['optInventory'] ?? 0);

  $item = new stdClass();
  $item->name = trim($input['name']);
  $item->prefix = trim($input['prefix']);
  $item->notes = trim($input['description'] ?? '');
  $item->color = '#9BD';
  $item->active = (int)($input['isActive'] ?? 1);
  $item->is_public = (int)($input['isPublic'] ?? 1);
  $item->options = $options;

  $newId = $tprojectMgr->create($item, ['doChecks' => true]);

  if (!$newId) {
    throw new Exception('Failed to create project');
  }

  // execSetResults.php reads option_reqs directly, so keep the legacy
  // columns in sync with the serialized options blob.
  $db->exec_query("UPDATE testprojects SET
                     option_reqs=" . (int)$options->requirementsEnabled . ",
                     option_priority=" . (int)$options->testPriorityEnabled . ",
                     option_automation=" . (int)$options->automationEnabled . "
                   WHERE id=" . (int)$newId);

  applyTrackers($db, $newId, $input);

  echo json_encode([
    'success' => true,
    'id' => $newId,
    'message' => 'Project created successfully'
  ]);
}

function updateProject(&$db, $projectId) {
  $input = json_decode(file_get_contents('php://input'), true);
  if (!$input) throw new Exception('Invalid input');

  if (isset($input['name'])) {
    $db->exec_query("UPDATE nodes_hierarchy SET name='" .
      $db->prepare_string(trim($input['name'])) . "' WHERE id=" . (int)$projectId);
  }

  $updates = [];
  if (isset($input['prefix'])) $updates[] = "prefix='" . $db->prepare_string(trim($input['prefix'])) . "'";
  if (isset($input['description'])) $updates[] = "notes='" . $db->prepare_string($input['description']) . "'";
  if (isset($input['isActive'])) $updates[] = "active=" . (int)$input['isActive'];
  if (isset($input['isPublic'])) $updates[] = "is_public=" . (int)$input['isPublic'];
  if (isset($input['optReq'])) $updates[] = "option_reqs=" . (int)$input['optReq'];
  if (isset($input['optPriority'])) $updates[] = "option_priority=" . (int)$input['optPriority'];
  if (isset($input['optAutomation'])) $updates[] = "option_automation=" . (int)$input['optAutomation'];

  if (isset($input['optInventory']) || isset($input['optReq']) ||
      isset($input['optPriority']) || isset($input['optAutomation'])) {
    $options = new stdClass();
    $options->requirementsEnabled = (int)($input['optReq'] ?? 0);
    $options->testPriorityEnabled = (int)($input['optPriority'] ?? 0);
    $options->automationEnabled = (int)($input['optAutomation'] ?? 0);
    $options->inventoryEnabled = (int)($input['optInventory'] ?? 0);
    $updates[] = "options='" . $db->prepare_string(serialize($options)) . "'";
  }

  if ($updates) {
    $db->exec_query("UPDATE testprojects SET " . implode(',', $updates) .
                    " WHERE id=" . (int)$projectId);
  }

  applyTrackers($db, $projectId, $input);

  echo json_encode([
    'success' => true,
    'id' => (int)$projectId,
    'message' => 'Project updated successfully'
  ]);
}

function applyTrackers(&$db, $projectId, $input) {
  $projectId = (int)$projectId;

  if (array_key_exists('issue_tracker_id', $input)) {
    $itId = (int)$input['issue_tracker_id'];
    $db->exec_query("DELETE FROM testproject_issuetracker WHERE testproject_id=$projectId");
    if ($itId > 0) {
      $db->exec_query("INSERT INTO testproject_issuetracker (testproject_id,issuetracker_id)
                       VALUES ($projectId,$itId)");
    }
    $enabled = ($itId > 0 && !empty($input['issue_tracker_enabled'])) ? 1 : 0;
    $db->exec_query("UPDATE testprojects SET issue_tracker_enabled=$enabled WHERE id=$projectId");
  }

  if (array_key_exists('code_tracker_id', $input)) {
    $ctId = (int)$input['code_tracker_id'];
    $db->exec_query("DELETE FROM testproject_codetracker WHERE testproject_id=$projectId");
    if ($ctId > 0) {
      $db->exec_query("INSERT INTO testproject_codetracker (testproject_id,codetracker_id)
                       VALUES ($projectId,$ctId)");
    }
    $enabled = ($ctId > 0 && !empty($input['code_tracker_enabled'])) ? 1 : 0;
    $db->exec_query("UPDATE testprojects SET code_tracker_enabled=$enabled WHERE id=$projectId");
  }
}

function deleteProject(&$db, $projectId) {
  $db->exec_query("UPDATE testprojects SET active=0 WHERE id=" . (int)$projectId);

  echo json_encode([
    'success' => true,
    'message' => 'Project deactivated successfully'
  ]);
}
