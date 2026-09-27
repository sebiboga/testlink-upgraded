<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * @filesource stepReorder.php
 *
 * @internal Refs #1671 - legacy re-order endpoint for the STEPS of a test case
 *   version. In 1.9.20 there was no screen for this at all: the steps of the
 *   version being edited were re-ordered with a TableDnD drag-and-drop
 *   (gui/templates/tl-classic/testcases/steps_horizontal.inc.tpl, the only
 *   caller - a dead template) that POSTed
 *   stepSeq=<step_node_id>&<step_node_id>&... to this file.
 *
 *   It is retired here for three reasons:
 *
 *   1. it performed NO authorization at all ("// No authorization checks").
 *      Any authenticated user - including one with no test-case management
 *      right whatsoever - could renumber the steps of any test case version of
 *      any test project.
 *   2. it read $_REQUEST, so a plain GET mutated state: the endpoint was
 *      CSRF-able.
 *   3. the submitted step ids were never proven to belong to anything. Only
 *      the first id was read (to fetch NH_STEPS.parent_id) and that result was
 *      then never even used, so ids of unrelated versions, of other test
 *      projects, or of any other node type (suite, test case, requirement, ...)
 *      could be renumbered at will.
 *
 *   The equivalent authorized surface is the modern Reorder Test Steps screen
 *   (gui/templates/testcases/tcStepReorder.html) backed by
 *   api/tcstepsreorder, which checks mgt_modify_tc on the OWNING project,
 *   requires POST behind bffSameOriginGuard(), and proves every submitted id
 *   to be a testcase_step child of the addressed version.
 *
 *   Legacy deep links (GET) are answered with a 302 to the modern screen. The
 *   mutation is deliberately NOT replayed: it was never authorized, and a 302
 *   that silently performed the reorder would be worse than refusing it. A
 *   write verb is refused with 405 and a pointer to the modern endpoint.
 */

require_once('../../config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

// Legacy testlinkInitPage() contract: an anonymous visitor is bounced to the
// login screen. checkSessionValid()'s own redirect is used (rather than a
// hand-rolled header()) because it walks up from dirname(SCRIPT_FILENAME)
// until it finds login.php - a relative 'login.php' would resolve against
// /lib/ajax/ and 404.
if (!checkSessionValid($db)) {
    exit;  // unreachable: the call above already redirected
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method !== 'GET' && $method !== 'HEAD') {
    tLog('BFF shim: refused ' . $method . ' on the retired legacy step-reorder ' .
         'endpoint - use POST /api/tcstepsreorder/index.php?action=move|reorder|normalize ' .
         'instead (Refs #1671).', 'WARNING');
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode(array(
        'status' => 'error',
        'code' => 'method_not_allowed',
        'message' => 'The legacy step-reorder endpoint was retired; ' .
                     'use POST /api/tcstepsreorder/index.php?action=move|reorder|normalize',
    ));
    exit;
}

$q = $_GET;

// The legacy URL had no version id (it only carried stepSeq), so there is
// nothing to forward out of an old bookmark except the project; the modern
// screen then offers its own version picker. An explicit tcversion_id (used by
// the modern entry buttons, and by any hand-made deep link) is forwarded as is.
$target = '/gui/templates/testcases/tcStepReorder.html';

$params = array();
if (isset($q['tproject_id']) && intval($q['tproject_id']) > 0) {
    $params['tproject_id'] = intval($q['tproject_id']);
}
if (isset($q['tcversion_id']) && intval($q['tcversion_id']) > 0) {
    $params['tcversion_id'] = intval($q['tcversion_id']);
}

if (!empty($params)) {
    $target .= '?' . http_build_query($params);
}

header('Location: ' . $target);
exit;
