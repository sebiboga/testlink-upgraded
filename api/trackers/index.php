<?php
/**
 * TestLink Trackers API (BFF)
 * Lists the issue trackers and code trackers available for project assignment.
 */

require_once('../../config.inc.php');
require_once('../../lib/functions/common.php');
require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json');

$db = null;
doDBConnect($db);

$issueTrackers = (array)$db->get_recordset("SELECT id, name, type FROM issuetrackers ORDER BY name");
$codeTrackers = (array)$db->get_recordset("SELECT id, name, type FROM codetrackers ORDER BY name");

$asList = function ($rows) {
  return array_map(function ($r) {
    return ['id' => (int)$r['id'], 'name' => $r['name']];
  }, $rows);
};

echo json_encode([
  'success' => true,
  'issueTrackers' => $asList($issueTrackers),
  'codeTrackers' => $asList($codeTrackers)
]);
