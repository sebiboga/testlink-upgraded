<?php
/**
 * TestLink Test Project Create/Edit API (BFF)
 *
 * Modernized counterpart of lib/project/projectEdit.php (the last
 * user-reachable legacy screen). Reachable today only via the zero-test-
 * projects admin redirect in getGrantSetWithExit() (lib/functions/common.php)
 * plus direct links, so the whole legacy form (create/edit/delete + tracker
 * integration + feature options + copy-from) is ported as a Dashio page.
 *
 * Auth model (same as legacy projectEdit.php): every route is gated behind
 * mgt_modify_product. Like the other BFFs we bypass testlinkInitPage() (it
 * redirects to login.php on an expired session, breaking JSON clients) and do
 * the standard bootstrap: session start + CSRF guard + explicit 401/403.
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

$action = $_REQUEST['action'] ?? '';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$writeActions = array('create', 'update', 'delete', 'toggle_active', 'toggle_requirements');
if (in_array($action, $writeActions, true) && $method !== 'POST') {
  http_response_code(405);
  echo json_encode(['error' => 'Method not allowed (POST required)']);
  exit;
}

try {
  switch ($action) {
    case 'init':
      initScreen($db, $tprojectMgr, $user);
      break;

    case 'create':
      createProject($db, $tprojectMgr, $user);
      break;

    case 'update':
      updateProject($db, $tprojectMgr, $user);
      break;

    case 'delete':
      deleteProject($db, $tprojectMgr);
      break;

    case 'toggle_active':
      toggleProjectFlag($db, $tprojectMgr, 'setActive', 'setInactive');
      break;

    case 'toggle_requirements':
      toggleProjectFlag($db, $tprojectMgr, 'enableRequirements', 'disableRequirements');
      break;

    default:
      http_response_code(400);
      echo json_encode(['error' => 'Unknown action']);
  }
} catch (BFFServerError $e) {
  http_response_code(500);
  echo json_encode(['error' => $e->getMessage()]);
} catch (Exception $e) {
  http_response_code(400);
  echo json_encode(['error' => $e->getMessage()]);
}

class BFFServerError extends Exception {}

/**
 * Parse the stored options blob; corrupt/empty blobs fall back to legacy
 * create defaults (priority + automation ON, requirements + inventory OFF).
 */
function parseStoredOptions($blob) {
  $opt = empty($blob) ? null : @unserialize($blob);
  if ($opt === false || !is_object($opt)) {
    return buildOptions([]);
  }
  return $opt;
}

function buildOptions($input) {
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

function mergeOptions($storedOpt, $input) {
  $map = array(
    'optReq'        => 'requirementsEnabled',
    'optPriority'   => 'testPriorityEnabled',
    'optAutomation' => 'automationEnabled',
    'optInventory'  => 'inventoryEnabled',
  );
  foreach ($map as $key => $prop) {
    if (array_key_exists($key, $input)) {
      $storedOpt->$prop = (int)(bool)$input[$key];
    }
  }
  return $storedOpt;
}

/**
 * Legacy parity: projectEdit.php::crossChecks() - name syntax plus duplicate
 * name/prefix detection, excluding $excludeId on update.
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

/**
 * Legacy parity: link/unlink through tlIssueTracker / tlCodeTracker /
 * tlReqMgrSystem managers and flip the enabled flags via
 * setIssueTrackerEnabled()/setCodeTrackerEnabled()/setReqMgrIntegrationEnabled().
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

  if (array_key_exists('reqmgrsystem_id', $input)) {
    $rmId = (int)$input['reqmgrsystem_id'];
    $rmMgr = new tlReqMgrSystem($db);
    $linked = $rmMgr->getLinkedTo($projectId);
    if (!is_null($linked)) {
      $rmMgr->unlink($linked['reqmgrsystem_id'], $linked['testproject_id']);
    }
    if ($rmId > 0) {
      $rmMgr->link($rmId, $projectId);
    }
    $tprojectMgr->setReqMgrIntegrationEnabled(
      $projectId, ($rmId > 0 && !empty($input['reqmgr_integration_enabled'])) ? 1 : 0);
  }
}

/**
 * Legacy parity (doCreate/doUpdate): making a project private must never lock
 * its manager out - add the user's global role as project-specific role
 * unless one already exists.
 */
function protectFromLockout(&$db, &$tprojectMgr, &$user, $projectId, $isPublic) {
  if (!$isPublic
      && !tlUser::hasRoleOnTestProject($db, $user->dbID, $projectId)) {
    $tprojectMgr->addUserRole($user->dbID, $projectId,
                              $user->globalRole->dbID);
  }
}

/**
 * List requests of a manager mapped to [{id, verbose}] (same keys projectEdit
 * feeds its <select> options).
 */
function trackerSelectOptions($mgr) {
  $rows = $mgr->getAll();
  $out = array();
  foreach ((array)$rows as $row) {
    $out[] = array(
      'id'      => (int)$row['id'],
      'verbose' => $row['verbose'] ?? $row['name'] ?? '',
    );
  }
  return $out;
}

/**
 * GET ?action=init&tproject_id=N   -> create mode (N=0/missing) or edit mode.
 * Returns the form model + the tracker/copy-from picker data + grants.
 */
function initScreen(&$db, &$tprojectMgr, &$user) {
  $projectId = isset($_REQUEST['tproject_id']) ? (int)$_REQUEST['tproject_id'] : 0;

  $data = array(
    'mode'       => $projectId > 0 ? 'edit' : 'create',
    'project_id' => $projectId,
    'project'    => null,
    'issueTrackers' => trackerSelectOptions(new tlIssueTracker($db)),
    'codeTrackers'  => trackerSelectOptions(new tlCodeTracker($db)),
    'reqMgrSystems' => trackerSelectOptions(new tlReqMgrSystem($db)),
    'copyFromProjects' => array(),
    'grants' => array(
      'canManage'       => ($user->hasRight($db, 'mgt_modify_product') === 'yes'),
      'mgt_view_events' => ($user->hasRight($db, 'mgt_view_events') === 'yes'),
    ),
  );

  if ($projectId > 0) {
    $info = $tprojectMgr->get_by_id($projectId);
    if (is_null($info) || empty($info)) {
      http_response_code(404);
      echo json_encode(['error' => 'Project not found']);
      return;
    }
    $opt = parseStoredOptions($info['options']);
    $flag = function ($name) use ($opt) {
      return (is_object($opt) && !empty($opt->$name)) ? 1 : 0;
    };

    $linked = array('issue' => 0, 'code' => 0, 'reqmgr' => 0);
    $it = (new tlIssueTracker($db))->getLinkedTo($projectId);
    if (!is_null($it))  $linked['issue']  = (int)$it['issuetracker_id'];
    $ct = (new tlCodeTracker($db))->getLinkedTo($projectId);
    if (!is_null($ct))  $linked['code']   = (int)$ct['codetracker_id'];
    $rm = (new tlReqMgrSystem($db))->getLinkedTo($projectId);
    if (!is_null($rm))  $linked['reqmgr'] = (int)$rm['reqmgrsystem_id'];

    $data['project'] = array(
      'id'          => (int)$info['id'],
      'name'        => $info['name'],
      'prefix'      => $info['prefix'],
      'description' => $info['notes'] ?? '',
      'api_key'     => $info['api_key'] ?? '',
      'active'      => (int)$info['active'],
      'isPublic'    => (int)$info['is_public'],
      'optReq'      => $flag('requirementsEnabled'),
      'optPriority' => $flag('testPriorityEnabled'),
      'optAutomation' => $flag('automationEnabled'),
      'optInventory'  => $flag('inventoryEnabled'),
      'issue_tracker_id'     => $linked['issue'],
      'issue_tracker_enabled' => (int)$info['issue_tracker_enabled'],
      'code_tracker_id'      => $linked['code'],
      'code_tracker_enabled' => (int)$info['code_tracker_enabled'],
      'reqmgrsystem_id'      => $linked['reqmgr'],
      'reqmgr_integration_enabled' => (int)$info['reqmgr_integration_enabled'],
    );
  }

  if ($data['mode'] === 'create') {
    // Legacy create() defaults and the copy-from picker (accessible projects).
    $data['project'] = array(
      'id' => 0, 'name' => '', 'prefix' => '', 'description' => '',
      'api_key' => '', 'active' => 1, 'isPublic' => 1,
      'optReq' => 0, 'optPriority' => 1, 'optAutomation' => 1, 'optInventory' => 0,
      'issue_tracker_id' => 0, 'issue_tracker_enabled' => 0,
      'code_tracker_id' => 0, 'code_tracker_enabled' => 0,
      'reqmgrsystem_id' => 0, 'reqmgr_integration_enabled' => 0,
    );
    $accessible = (array)$tprojectMgr->get_accessible_for_user(
      $user->dbID,
      array('output' => 'array_of_map',
            'order_by' => ' ORDER BY nodes_hierarchy.name '));
    foreach ($accessible as $tp) {
      $data['copyFromProjects'][] = array(
        'id'   => (int)$tp['id'],
        'name' => $tp['name'],
      );
    }
  }

  echo json_encode(['success' => true, 'data' => $data]);
}

function readInput() {
  $input = json_decode(file_get_contents('php://input'), true);
  return is_array($input) ? $input : array();
}

function createProject(&$db, &$tprojectMgr, &$user) {
  $input = readInput();

  if (empty($input['name']) || empty($input['prefix'])) {
    throw new Exception('Project name and prefix are required');
  }

  $item = new stdClass();
  $item->name = trim($input['name']);
  $item->prefix = trim($input['prefix']);
  $item->notes = trim($input['description'] ?? '');
  $item->color = trim($input['color'] ?? '');
  if ($item->color === '') { $item->color = '#9BD'; }
  $item->active = isset($input['active']) ? (int)(bool)$input['active'] : 1;
  $item->is_public = isset($input['isPublic']) ? (int)(bool)$input['isPublic'] : 1;
  $item->options = buildOptions($input);

  crossChecksBFF($tprojectMgr, $item->name, $item->prefix);

  // Legacy doCreate -> create(..., array('doChecks' => true,
  // 'setSessionProject' => true)).
  $newId = $tprojectMgr->create($item, array('doChecks' => true,
                                            'setSessionProject' => true));
  if (!$newId || $newId <= 0) {
    throw new BFFServerError(lang_get('refer_to_log'));
  }

  applyTrackers($db, $tprojectMgr, $newId, $input);
  protectFromLockout($db, $tprojectMgr, $user, $newId, $item->is_public);

  // Legacy parity: copy data from an existing project (copy_as).
  $copyFrom = isset($input['copy_from_tproject_id'])
    ? (int)$input['copy_from_tproject_id'] : 0;
  if ($copyFrom > 0) {
    $tprojectMgr->copy_as($copyFrom, $newId, $user->dbID,
                          $item->name, array('copy_requirements' => (int)(bool)$input['optReq']));
  }

  echo json_encode([
    'success' => true,
    'id' => $newId,
    'message' => 'Project created successfully'
  ]);
}

function updateProject(&$db, &$tprojectMgr, &$user) {
  $input = readInput();
  $projectId = isset($_REQUEST['tproject_id'])
    ? (int)$_REQUEST['tproject_id'] : (int)($_REQUEST['id'] ?? 0);
  $projectId = empty($input['project_id']) ? $projectId : (int)$input['project_id'];
  if ($projectId <= 0) {
    $projectId = (int)($_SESSION['testprojectID'] ?? 0);
  }
  if ($projectId <= 0) {
    throw new Exception('Project ID required');
  }

  $current = $tprojectMgr->get_by_id($projectId);
  if (is_null($current) || empty($current)) {
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

  // Partial semantics: only touch fields actually sent (same as api/projects
  // PUT); the full-page form always sends everything, so this is a safety net.
  $notes = array_key_exists('description', $input)
    ? trim($input['description']) : $current['notes'];
  $active = array_key_exists('active', $input)
    ? (int)(bool)$input['active'] : (int)$current['active'];
  $isPublic = array_key_exists('isPublic', $input)
    ? (int)(bool)$input['isPublic'] : (int)$current['is_public'];

  $optKeys = array('optReq','optPriority','optAutomation','optInventory');
  $hasOpt = false;
  foreach ($optKeys as $k) {
    if (array_key_exists($k, $input)) { $hasOpt = true; break; }
  }
  $options = $hasOpt
    ? mergeOptions(parseStoredOptions($current['options']), $input)
    : parseStoredOptions($current['options']);

  $color = !empty($current['color']) ? $current['color'] : '#9BD';
  $ok = $tprojectMgr->update($projectId, $name, $color, $notes,
                             $options, $active, $prefix, $isPublic);
  if (!$ok) {
    throw new BFFServerError('Failed to update project');
  }
  $tprojectMgr->activate($projectId, $active);

  applyTrackers($db, $tprojectMgr, $projectId, $input);
  protectFromLockout($db, $tprojectMgr, $user, $projectId, $isPublic);

  // Legacy parity: doUpdate() explicitly logs audit_testproject_saved.
  $event = new stdClass();
  $event->message = TLS('audit_testproject_saved', $name);
  $event->logLevel = 'AUDIT';
  $event->source = 'GUI';
  $event->objectID = $projectId;
  $event->objectType = 'testprojects';
  $event->code = 'UPDATE';
  logEvent($event);

  echo json_encode([
    'success' => true,
    'id' => $projectId,
    'message' => 'Project updated successfully'
  ]);
}

function deleteProject(&$db, &$tprojectMgr) {
  $projectId = isset($_REQUEST['tproject_id'])
    ? (int)$_REQUEST['tproject_id'] : 0;
  if ($projectId <= 0) {
    throw new Exception('Project ID required');
  }

  $current = $tprojectMgr->get_by_id($projectId);
  if (is_null($current) || empty($current)) {
    http_response_code(404);
    echo json_encode(['error' => 'Project not found']);
    return;
  }

  // Legacy parity: doDelete() runs the full cascade delete.
  $op = $tprojectMgr->delete($projectId);
  if (empty($op['status_ok'])) {
    http_response_code(409);
    echo json_encode([
      'error' => lang_get('info_product_not_deleted_check_log') . ' ' .
                 ($op['msg'] ?? '')
    ]);
    return;
  }

  echo json_encode([
    'success' => true,
    'message' => sprintf(lang_get('test_project_deleted'), $current['name'])
  ]);
}

/**
 * Legacy parity: setActive/setInactive/enableRequirements/disableRequirements
 * are one-liners on the testproject manager; the modern screen uses the same
 * for the availability/requirement toggles.
 */
function toggleProjectFlag(&$db, &$tprojectMgr, $onMethod, $offMethod) {
  $projectId = isset($_REQUEST['tproject_id']) ? (int)$_REQUEST['tproject_id'] : 0;
  $enable = (int)($_REQUEST['enable'] ?? 0);
  if ($projectId <= 0) {
    throw new Exception('Project ID required');
  }

  $current = $tprojectMgr->get_by_id($projectId);
  if (is_null($current) || empty($current)) {
    http_response_code(404);
    echo json_encode(['error' => 'Project not found']);
    return;
  }

  $tprojectMgr->{$enable ? $onMethod : $offMethod}($projectId);
  echo json_encode(['success' => true]);
}