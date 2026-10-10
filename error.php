<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * General purpose error page - MODERNIZED (Dashio standalone page) - Refs #1895
 *
 * The legacy renderer (this file + gui/templates/dashio/feedback/error.tpl)
 * has been replaced by:
 *
 *   gui/templates/feedback/error.html   the screen (localized, locale switcher)
 *   api/error/index.php                 the BFF (stable error_code + message_key)
 *
 * The screen covers everything the legacy page did:
 *   - code 1 -> "No CSRFName found, probable invalid request."
 *   - code 2 -> "Invalid CSRF token"
 *   - any other code (or none) -> the legacy default
 *     "Rocket Raccoon is watching You"
 *   - NO rights gate: the legacy page rendered for anonymous visitors too
 *     (it never called testlinkInitPage), so the shim stays anonymous.
 *
 * Hardening over legacy:
 *   - the numeric code is scalar/integer-guarded (an array-shaped ?code[]=x,
 *     the same defect class as #1886 / #1893, can no longer reach a sink);
 *   - the message is localized client-side (the legacy page was hardcoded
 *     English regardless of the user's locale).
 *
 * This controller is kept only as a redirect for stale bookmarks, the CSRF
 * guard and wiki links.
 */
require_once('config.inc.php');
require_once('common.php');

// Nothing here is state-changing: refuse anything but a safe read.
if (isset($_SERVER['REQUEST_METHOD']) &&
    !in_array(strtoupper($_SERVER['REQUEST_METHOD']), array('GET', 'HEAD'), true)) {
  http_response_code(405);
  header('Allow: GET, HEAD');
  exit('Method Not Allowed');
}

// Scalar/integer guard: a non-scalar or non-numeric code falls back to the
// legacy generic default instead of being coerced (#1886 / #1893 class).
$code = 0;
if (isset($_REQUEST['code']) && is_scalar($_REQUEST['code']) &&
    preg_match('/^-?\d+$/', trim((string)$_REQUEST['code']))) {
  $code = (int)$_REQUEST['code'];
}

$base = (isset($_SESSION['basehref']) && $_SESSION['basehref'] !== '')
      ? $_SESSION['basehref']
      : rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/';
$target = $base . 'gui/templates/feedback/error.html?code=' . $code;

if (!headers_sent()) {
  header('Location: ' . $target, true, 302);
} else {
  echo "<script type='text/javascript'>window.location.replace("
     . json_encode($target) . ");</script>";
}
exit;
