<?php
/**
 * TestLink Projects Management API (BFF)
 *
 * Auth model (same as legacy projectEdit.php): every route of this endpoint
 * operates on test projects, which legacy gates behind the
 * "Test Project Management" screen -> requires mgt_modify_product.
 *
 * We still bypass testlinkInitPage() (it redirects to login.php on an expired
 * session, which breaks JSON clients) and do the standard BFF bootstrap
 * instead: session start + CSRF guard + explicit 401/403 JSON responses.
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');

$db = null;
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

if (!$user->hasRight($db, 'mgt_modify_product')) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'No permission']);
    exit;
}

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
      updateProject($db, $tprojectMgr, $projectId);
      break;

    case 'DELETE':
      if (!$projectId) throw new Exception('Project ID required');
      deleteProject($db, $tprojectMgr, $projectId);
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
                 tp.options, tp.issue_tracker_enabled, tp.code_tracker_enabled,
                 it.name AS issue_tracker_name, ct.name AS code_tracker_name
          FROM testprojects tp
          JOIN nodes_hierarchy nh ON nh.id = tp.id
          LEFT JOIN testproject_issuetracker tpit ON tpit.testproject_id = tp.id
          LEFT JOIN issuetrackers it ON it.id = tpit.issuetracker_id
          LEFT JOIN testproject_codetracker tpct ON tpct.testproject_id = tp.id
          LEFT JOIN codetrackers ct ON ct.id = tpct.codetracker_id ";
}

function formatProject($row) {
  // The serialized blob is the single source of truth for feature flags;
  // the option_* columns are vestigial and never written (see #525).
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

/**
 * Legacy parity: projectEdit.php::crossChecks() — name syntax check plus
 * duplicate name/prefix detection. $excludeId is the project being updated
 * (null on create), mirroring the " testprojects.id <> X" SQL filter legacy
 * passes to get_by_name()/get_by_prefix().
 */
function crossChecksBFF(&$tprojectMgr, $name, $prefix, $excludeId = null) {
  $op = $tprojectMgr->checkName($name);
  if (!$op['status_ok']) {
    throw new Exception($op['msg']);
  }

  $filter = null;
  if (!is_null($excludeId)) {
    $filter = ' testprojects.id <> ' . (int)$excludeId;
  }

  if ($tprojectMgr->get_by_name($name, $filter)) {
    throw new Exception(sprintf(lang_get('error_product_name_duplicate'), $name));
  }

  $rs = $tprojectMgr->get_by_prefix($prefix, $filter);
  if (!is_null($rs)) {
    throw new Exception(sprintf(lang_get('error_tcase_prefix_exists'), $prefix));
  }
}

function buildOptions($input) {
  // Same defaults legacy create() applies when checkboxes are absent:
  // priority + automation ON, requirements + inventory OFF.
  $options = new stdClass();
  $options->requirementsEnabled = isset($input['optReq'])
    ? (int)(bool)$input['optReq'] : 0;
  $options->testPriorityEnabled = isset($input['optPriority'])
    ? (int)(bool)$input['optPriority'] : 1;
  $options->automationEnabled = isset($input['optAutomation'])
    ? (int)(bool)$input['optAutomation'] : 1;
  $options->inventoryEnabled = isset($input['optInventory'])
    ? (int)(bool)$input['optInventory'] : 0;
  return $options;
}

/**
 * Legacy parity: link/unlink through tlIssueTracker / tlCodeTracker managers
 * (like doCreate/doUpdate in projectEdit.php) instead of raw SQL against the
 * link tables, and flip the enabled flag via setIssueTrackerEnabled() /
 * setCodeTrackerEnabled().
 */
function applyTrackers(&$db, &$tprojectMgr, $projectId, $input) {
  $projectId = (int)$projectId;

  if (array_key_exists('issue_tracker_id', $input)) {
    $itId = (int)$input['issue_tracker_id'];
    $itMgr = new tlIssueTracker($db);
    $linked = $itMgr->getLinkedTo($projectId);
    if (!is_null($linked)) {
      $itMgr->unlink($linked['issuetracker_id'], $linked['testproject_id']);
    }
    if ($itId > 0) {
      $itMgr->link($itId, $projectId);
    }
    $tprojectMgr->setIssueTrackerEnabled(
      $projectId, ($itId > 0 && !empty($input['issue_tracker_enabled'])) ? 1 : 0);
  }

  if (array_key_exists('code_tracker_id', $input)) {
    $ctId = (int)$input['code_tracker_id'];
    $ctMgr = new tlCodeTracker($db);
    $linked = $ctMgr->getLinkedTo($projectId);
    if (!is_null($linked)) {
      $ctMgr->unlink($linked['codetracker_id'], $linked['testproject_id']);
    }
    if ($ctId > 0) {
      $ctMgr->link($ctId, $projectId);
    }
    $tprojectMgr->setCodeTrackerEnabled(
      $projectId, ($ctId > 0 && !empty($input['code_tracker_enabled'])) ? 1 : 0);
  }
}

function createProject(&$db, &$tprojectMgr) {
  $input = json_decode(file_get_contents('php://input'), true);

  if (empty($input['name']) || empty($input['prefix'])) {
    throw new Exception('Project name and prefix are required');
  }

  $item = new stdClass();
  $item->name = trim($input['name']);
  $item->prefix = trim($input['prefix']);
  $item->notes = trim($input['description'] ?? '');
  $item->color = '#9BD';
  $item->active = isset($input['isActive']) ? (int)(bool)$input['isActive'] : 1;
  $item->is_public = isset($input['isPublic']) ? (int)(bool)$input['isPublic'] : 1;
  $item->options = buildOptions($input);

  crossChecksBFF($tprojectMgr, $item->name, $item->prefix);

  $newId = $tprojectMgr->create($item, ['doChecks' => false]);

  if (!$newId) {
    throw new Exception('Failed to create project');
  }

  applyTrackers($db, $tprojectMgr, $newId, $input);

  logAuditProject($item->name, $newId, 'CREATE', 'audit_testproject_saved');

  echo json_encode([
    'success' => true,
    'id' => $newId,
    'message' => 'Project created successfully'
  ]);
}

function updateProject(&$db, &$tprojectMgr, $projectId) {
  $input = json_decode(file_get_contents('php://input'), true);
  if (!$input) throw new Exception('Invalid input');

  $projectId = (int)$projectId;
  $current = $tprojectMgr->get_by_id($projectId);
  if (is_null($current) || !$current) {
    http_response_code(404);
    echo json_encode(['error' => 'Project not found']);
    return;
  }

  $name = array_key_exists('name', $input)
    ? trim($input['name']) : trim($current['name']);
  $prefix = array_key_exists('prefix', $input)
    ? trim($input['prefix']) : trim($current['prefix']);

  if ($name === '' || $prefix === '') {
    throw new Exception('Project name and prefix are required');
  }

  crossChecksBFF($tprojectMgr, $name, $prefix, $projectId);

  // Partial updates (e.g. active toggle) only touch what was sent; fields not
  // present keep their stored values read from get_by_id() above.
  $notes = array_key_exists('description', $input)
    ? $input['description'] : $current['notes'];
  $active = array_key_exists('isActive', $input)
    ? (int)(bool)$input['isActive'] : (int)$current['active'];
  $isPublic = array_key_exists('isPublic', $input)
    ? (int)(bool)$input['isPublic'] : (int)$current['is_public'];

  $optKeys = ['optReq','optPriority','optAutomation','optInventory'];
  $hasOpt = false;
  foreach ($optKeys as $k) {
    if (array_key_exists($k, $input)) { $hasOpt = true; break; }
  }
  $options = $hasOpt ? buildOptions($input)
    : @unserialize($current['options']);

  // Legacy parity: doUpdate() -> tprojectMgr::update() + activate()
  $ok = $tprojectMgr->update($projectId, $name, '#9BD', $notes,
                             $options, $active, $prefix, $isPublic);
  if (!$ok) {
    throw new Exception('Failed to update project');
  }
  $tprojectMgr->activate($projectId, $active);

  applyTrackers($db, $tprojectMgr, $projectId, $input);

  logAuditProject($name, $projectId, 'UPDATE', 'audit_testproject_saved');

  echo json_encode([
    'success' => true,
    'id' => $projectId,
    'message' => 'Project updated successfully'
  ]);
}

function logAuditProject($name, $id, $code, $msgKey) {
  $event = new stdClass();
  $event->message = TLS($msgKey, $name);
  $event->logLevel = 'AUDIT';
  $event->source = 'GUI';
  $event->objectID = (int)$id;
  $event->objectType = 'testprojects';
  $event->code = $code;
  logEvent($event);
}

function deleteProject(&$db, &$tprojectMgr, $projectId) {
  $projectId = (int)$projectId;
  $current = $tprojectMgr->get_by_id($projectId);
  if (is_null($current) || !$current) {
    http_response_code(404);
    echo json_encode(['error' => 'Project not found']);
    return;
  }

  // Legacy parity: doDelete() runs the full cascade delete
  // (test plans, cases, keywords, attachments...), NOT a soft deactivate.
  $op = $tprojectMgr->delete($projectId);

  if (empty($op['status_ok'])) {
    http_response_code(409);
    echo json_encode([
      'error' => lang_get('info_product_not_deleted_check_log') . ' ' .
                 ($op['msg'] ?? '')
    ]);
    return;
  }

  logAuditProject($current['name'], $projectId, 'DELETE', 'audit_testproject_saved');

  echo json_encode([
    'success' => true,
    'message' => sprintf(lang_get('test_project_deleted'), $current['name'])
  ]);
}
