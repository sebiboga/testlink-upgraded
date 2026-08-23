<?php
/**
 * TestLink Trackers API (BFF)
 * Lists the issue trackers and code trackers available for project assignment.
 *
 * Feeds only the Test Project Management screen (legacy projectEdit.php),
 * so it requires the same right: mgt_modify_product.
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

$issueTrackers = (array)$db->get_recordset("SELECT id, name, type FROM issuetrackers ORDER BY name");
$codeTrackers = (array)$db->get_recordset("SELECT id, name, type FROM codetrackers ORDER BY name");
$reqMgrSystems = (array)$db->get_recordset("SELECT id, name, type FROM reqmgrsystems ORDER BY name");

$asList = function ($rows) {
  return array_map(function ($r) {
    return ['id' => (int)$r['id'], 'name' => $r['name']];
  }, $rows);
};

echo json_encode([
  'success' => true,
  'issueTrackers' => $asList($issueTrackers),
  'codeTrackers' => $asList($codeTrackers),
  'reqMgrSystems' => $asList($reqMgrSystems)
]);
