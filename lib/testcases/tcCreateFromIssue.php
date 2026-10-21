<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * @filesource  tcCreateFromIssue.php
 *
 * @internal legacy renderer retired by the #1896 modernization pass: this
 *   controller resolved templateConfiguration()->default_template to
 *   testcases/tcCreateFromIssue.tpl, which never existed in gui/templates/dashio
 *   (dead screen). The Test Specification Import feature is now hosted by the
 *   standalone modern screen gui/templates/testcases/tcCreateFromIssue.html
 *   backed by the api/tccreatefromissue BFF, which reuses the exact same import
 *   engine (lib/testcases/tcCreateFromIssue.functions.php).
 *
 * Route to the modern twin, preserving the deep-link context identity.
 */
session_start();
$base = str_replace(array("\r", "\n"), '', strval($_SESSION['basehref'] ?? ''));
// Harden against a poisoned session: CRLF would allow header injection and a
// foreign scheme/host an open redirect. Prefer a root-relative base built from
// the session, falling back to '/' (same hardening as the #1947/#1948 shims).
if (preg_match('~^https?://[^/]+(/.+)$~i', $base, $m)) {
    $base = rtrim($m[1], '/') . '/';
}
if ($base === '' || $base[0] !== '/' || strpos($base, '//') === 0) {
    $base = '/';
}
header('Location: ' . $base . 'gui/templates/testcases/tcCreateFromIssue.html' .
       '?tproject_id=' . intval($_REQUEST['tproject_id'] ?? $_SESSION['testprojectID'] ?? 0) .
       '&containerID=' . intval($_REQUEST['container_id'] ?? $_REQUEST['containerID'] ?? 0));
exit;
