<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 *
 * @filesource  getExecNotes.php
 * @package     TestLink
 *
 * LEGACY READ-ONLY EXECUTION NOTES VIEWER - REDIRECT SHIM (Refs #1807)
 *
 * The screen itself is modernized: gui/templates/execute/execNotesReadonly.html
 * backed by api/execnotesreadonly/index.php.
 *
 * Why this file still exists: four legacy templates load its output as an AJAX
 * fragment and assign it into innerHTML
 *   gui/templates/dashio/execute/include/execSetResultsUtils.inc.tpl
 *   gui/templates/dashio/execute/include/execSetResultsJS.inc.tpl
 *   gui/templates/dashio/execute/execHistory.tpl
 *   gui/templates/tl-classic/execute/include/execSetResultsUtils.inc.tpl
 *   gui/templates/tl-classic/execute/execSetResults.tpl
 *   gui/templates/tl-classic/execute/execHistory.tpl
 * (six files carry the line; `tl-classic/execute/include/execSetResultsJS.inc.tpl`
 * does not exist, so the count of DISTINCT call sites is 5)
 * all as `url2load = fRoot + 'lib/execute/getExecNotes.php?readonly=1&exec_id=' + exec_id`,
 * so the FRAGMENT contract has to keep answering. The fragment is now produced by
 * the BFF, with the same authorization the modern screen uses, instead of by the
 * old controller.
 *
 * SECURITY (Refs #1807) - this controller used to be an UNAUTHORIZED-BY-RIGHT
 * READ: it called only testlinkInitPage($db) (a session, NO right, NO ownership),
 * took a bare $args->exec_id and handed get_execution() straight to the view, so
 * ANY authenticated user - `<no rights>` role 3 included - could read the notes of
 * ANY execution on ANY test project by enumerating ?exec_id=. It also
 * dereferenced $map[0]['notes'] with no guard (E_WARNING + fatal 500 on an unknown
 * id) and rendered the stored RichEdit blob through the web editor, i.e. a stored
 * payload executing with the app origin's privileges. All of that now lives in the
 * BFF, which authorizes the OWNING test project.
 *
 * Contract preserved for callers:
 *   - no session         -> 401 (legacy testlinkInitPage redirected to login)
 *   - write verb         -> 405 (this view has never written anything)
 *   - bad / missing id   -> 400 with an empty escaped fragment
 *   - XHR / fragment     -> 200 text/html fragment produced by the BFF, and ONLY
 *                           for a same-origin caller, because the legacy call
 *                           sites assign the answer into innerHTML
 *   - cross-origin XHR   -> 403 (the BFF answers 404 for BOTH "no such execution"
 *                           and "no right", so this file must not turn the two
 *                           into distinguishable answers)
 *   - browser navigation -> 302 to the modern screen
 *
 * The shim NEVER reads or writes a note itself: every byte of content comes from
 * api/execnotesreadonly/index.php?action=fragment.
 */
require_once('../../config.inc.php');
require_once('common.php');

doSessionStart();

require_once(__DIR__ . '/../../api/_guard.php');

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// Legacy testlinkInitPage() contract: no session, no data.
if (empty($_SESSION['userID']) || intval($_SESSION['userID']) <= 0) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array(
        'status' => 'error',
        'code' => 'not_authenticated',
        'message' => 'Not authenticated',
    ));
    exit;
}

// This view has never written anything; refuse the write verbs explicitly so a
// POST here can never be mistaken for a supported action.
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array(
        'status' => 'error',
        'code' => 'method_not_allowed',
        'message' => 'This view is read-only; use GET',
    ));
    exit;
}

$execId = isset($_GET['exec_id']) ? trim((string)$_GET['exec_id']) : '';
if ($execId === '' || !preg_match('/^[0-9]+$/', $execId) || intval($execId) <= 0) {
    http_response_code(400);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo '<pre class="execnotes-readonly-fragment"></pre>';
    exit;
}

// Browser navigation: hand over to the modern screen, exactly like every other
// converted legacy controller does.
if (strcasecmp(trim((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')), 'XMLHttpRequest') !== 0) {
    $basehref = isset($_SESSION['basehref']) ? $_SESSION['basehref'] : '/';
    header('Location: ' . $basehref .
           'gui/templates/execute/execNotesReadonly.html?exec_id=' . intval($execId), true, 302);
    exit;
}

// ---------------------------------------------------------------------------
// Fragment branch. The answer lands in the innerHTML of a panel on the caller's
// page, so only a genuinely same-origin caller may fetch it.
//
// The XRW hint is the browser's own same-origin marker for this very call (the
// legacy callers use $.ajax) and is the baseline proof, because a same-origin
// GET sends neither Origin nor Referer. A PRESENT Origin/Referer is still
// validated authoritatively: unparseable or foreign -> 403 outright, and the
// XRW hint never overrides it (issue #1679).
// ---------------------------------------------------------------------------
$requestHost = bffAuthority($_SERVER['HTTP_HOST'] ?? '');
$https = strtolower(trim((string)($_SERVER['HTTPS'] ?? '')));
$defaultPort = ':' . (($https !== '' && $https !== 'off') ? '443' : '80');
$hostKey = bffStripDefaultPort($requestHost, $defaultPort);

foreach (array('HTTP_ORIGIN', 'HTTP_REFERER') as $hdr) {
    $val = trim((string)($_SERVER[$hdr] ?? ''));
    if ($val === '') {
        continue;
    }
    $parts = parse_url($val);
    if (empty($parts['host'])) {
        enro_forbid('unparseable_origin');
    }
    $authority = strtolower($parts['host']);
    if (!empty($parts['port'])) {
        $authority .= ':' . $parts['port'];
    }
    if (bffStripDefaultPort($authority, $defaultPort) !== $hostKey) {
        enro_forbid('cross_origin');
    }
}

/**
 * A same-origin refusal. Emits a JSON body with a machine code so a caller can
 * tell "foreign Origin" from "unparseable Origin" instead of parsing an empty
 * 200-shaped page, and so the refusal can never be confused with the BFF's own
 * answers.
 */
function enro_forbid($code) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode(array(
        'status' => 'error',
        'code' => $code,
        'message' => 'Forbidden: same-origin required',
    ));
    exit;
}

// Run the BFF IN-PROCESS rather than proxying it over HTTP: the fragment has to
// keep the same session cookie, status codes and body as the modern screen, and
// a nested self-request is not available (the dev server is single threaded, so
// it would deadlock into a 502). config.inc.php has already put lib/functions on
// the include path, and every require below is require_once, so the BFF's own
// bootstrap collapses onto the one already done here.
$_GET['action'] = 'fragment';
$_GET['exec_id'] = intval($execId);
require __DIR__ . '/../../api/execnotesreadonly/index.php';
exit;