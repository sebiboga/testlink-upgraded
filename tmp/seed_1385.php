<?php
// Session seed helper for Task #1385 reproduction.
// URL: /tmp/seed_1385.php?token=N&tcase_id={<testcase node id>}
// Stores $_SESSION['execution_mode'][N]['testcases_to_show'] = array(<tcase id>)
// in the BROWSER's session so the same session can be used against both the
// legacy planExport.php and the modern /api/planexport/ BFF.
require_once(__DIR__ . '/../config.inc.php');
require_once(__DIR__ . '/../lib/functions/common.php');

doSessionStart();

header('Content-Type: application/json; charset=utf-8');

$token = isset($_GET['token']) ? intval($_GET['token']) : 0;
// comma-separated testcase ids (space tolerated)
$rawIds = isset($_GET['tcase_id']) ? preg_split('/[,\s]+/', trim((string)$_GET['tcase_id']), -1, PREG_SPLIT_NO_EMPTY) : [];
$tcaseIds = [];
foreach ($rawIds as $v) {
    $n = intval($v);
    if ($n > 0) { $tcaseIds[] = $n; }
}
if ($token <= 0 || empty($tcaseIds)) {
    echo json_encode(['status' => 'error', 'message' => 'missing token/tcase_id']);
    exit;
}

$_SESSION['execution_mode'] = is_array($_SESSION['execution_mode'] ?? null)
    ? $_SESSION['execution_mode'] : [];
$_SESSION['execution_mode'][$token] = ['testcases_to_show' => $tcaseIds];
echo json_encode(['status' => 'ok', 'token' => $token, 'tcase_ids' => $tcaseIds,
                  'session' => $_SESSION['execution_mode'][$token]]);