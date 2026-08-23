<?php
/**
 * TestLink Plugins API (BFF)
 * Modernized Installed/Available Plugins management screen (pluginView).
 *
 * Mirrors lib/plugins/pluginView.php behavior:
 *  - lists installed plugins (from plugins table) and available plugins
 *    (scanned from TL_PLUGIN_PATH, not yet registered)
 *  - install: plugin_register + plugin_init + plugin_install
 *  - uninstall: plugin_uninstall(id)
 *
 * Legacy right enforced on every route: mgt_plugins.
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
require_once(__DIR__ . '/../../lib/functions/plugin_api.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');

function bffFail($code, $message) {
  http_response_code($code);
  echo json_encode(['status' => 'error', 'message' => $message]);
  exit;
}

$db = null;
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || $userId <= 0) {
  bffFail(401, 'Not authenticated');
}

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
  bffFail(401, 'User not found');
}

if (!$user->hasRight($db, 'mgt_plugins')) {
  bffFail(403, 'No permission');
}

$method = $_SERVER['REQUEST_METHOD'];
$path = isset($_GET['action']) ? $_GET['action'] : '';

// POST operations may arrive either as ?action=… or with an "operation"
// field in the JSON body (mirrors legacy pluginView.php form parameter).
$body = [];
if ($method === 'POST') {
  $body = json_decode(file_get_contents('php://input'), true) ?? [];
  if ($path === '') {
    $path = trim((string)($body['operation'] ?? ''));
  }
}

switch ("$method $path") {
  case 'GET list':
    $installed = get_all_installed_plugins();
    $available = get_all_available_plugins($installed);
    echo json_encode([
      'success' => true,
      'installed' => array_map('bffPluginRow', (array)$installed),
      'available' => array_map('bffPluginRow', (array)$available),
      'canManage' => true,
    ]);
    exit;

  case 'POST install':
    $name = trim((string)($body['pluginName'] ?? ''));
    if ($name === '' || !preg_match('/^[A-Za-z0-9_]+$/', $name)) {
      bffFail(400, 'Invalid plugin name');
    }
    if (!is_dir(TL_PLUGIN_PATH . $name)) {
      bffFail(404, 'Plugin directory not found');
    }
    try {
      $p_plugin = plugin_register($name, true);
      plugin_init($name);
      plugin_install($p_plugin);
    } catch (Throwable $e) {
      bffFail(500, 'Install failed: ' . $e->getMessage());
    }
    echo json_encode(['status' => 'ok', 'success' => true, 'message' => 'plugin_installed', 'name' => $name]);
    exit;

  case 'POST uninstall':
    $id = intval($body['pluginId'] ?? 0);
    if ($id <= 0) {
      bffFail(400, 'Invalid plugin id');
    }
    $t_basename = plugin_uninstall($id);
    echo json_encode(['status' => 'ok', 'success' => true, 'message' => 'plugin_uninstalled', 'name' => (string)$t_basename]);
    exit;

  default:
    bffFail(405, 'Method not allowed');
}

function bffPluginRow($p) {
  return [
    'id' => isset($p['id']) ? (int)$p['id'] : 0,
    'name' => (string)($p['name'] ?? ''),
    'enabled' => !empty($p['enabled']),
    'description' => (string)($p['description'] ?? ''),
    'version' => (string)($p['version'] ?? ''),
  ];
}
