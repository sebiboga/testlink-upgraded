<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * Report requirement based results - MODERNIZED (Dashio) - Refs #1966
 *
 * The legacy renderer has been replaced by:
 *   gui/templates/results/resultsRequirements.html
 *   api/reports/index.php action=metrics_results_reqs
 *
 * This controller is kept only as a redirect for stale bookmarks / wiki links.
 */
require_once('../../config.inc.php');
require_once('common.php');

// Non-GET verbs are refused before any session work so the contract is stable
// (405 + Allow) instead of being masked by the anonymous login redirect.
if (isset($_SERVER['REQUEST_METHOD']) && !in_array(strtoupper($_SERVER['REQUEST_METHOD']), array('GET','HEAD'), true)) {
  http_response_code(405);
  header('Allow: GET, HEAD');
  exit('Method Not Allowed');
}

// Anonymous -> login (same contract as the legacy testlinkInitPage call).
testlinkInitPage($db, TRUE);

$tproject_id = isset($_REQUEST['tproject_id']) ? intval($_REQUEST['tproject_id']) : 0;
if ($tproject_id <= 0) {
  $tproject_id = isset($_SESSION['testprojectID']) ? intval($_SESSION['testprojectID']) : 0;
}

$tplan_id = isset($_REQUEST['tplan_id']) ? intval($_REQUEST['tplan_id']) : 0;
if ($tplan_id <= 0) {
  $tplan_id = isset($_SESSION['testplanID']) ? intval($_SESSION['testplanID']) : 0;
}

// Harden the base against a poisoned session: CRLF would allow header
// injection and a foreign scheme/host an open redirect. Prefer a root-relative
// base built from the session, falling back to '/' (same hardening as the
// #1947/#1948 shims).
$base = str_replace(array("\r", "\n"), '', strval($_SESSION['basehref'] ?? ''));
if (preg_match('~^https?://[^/]+(/.+)$~i', $base, $m)) {
    $base = rtrim($m[1], '/') . '/';
}
if ($base === '' || $base[0] !== '/' || strpos($base, '//') === 0) {
    $base = '/';
}

$target = $base . 'gui/templates/results/resultsRequirements.html'
        . '?tproject_id=' . $tproject_id . '&tplan_id=' . $tplan_id;

http_response_code(302);
header('Location: ' . $target);
exit;
