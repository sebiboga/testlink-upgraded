<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * @filesource  tcCreateFromIssueMantisXML.php
 *
 * 2.0.1 shim - Refs #1948 (Mantis Issue XML import modernization): the 1.9.20
 * Smarty renderer was retired. In 1.9.20 this controller owned the whole
 * feature (526 lines: upload form via gui/templates/dashio/testcases/
 * tcCreateFromIssueMantisXML.tpl + an inline copy of the import engine). The
 * dashio UI stopped reaching it years ago (containerView.tpl line 63 re-points
 * $tcMantisXMLAction to gui/templates/testcases/tcCreateFromIssues.html,
 * Refs #1502), so only tl-classic and direct URLs still hit this endpoint.
 *
 * The feature now lives on the full-featured modern screen
 * gui/templates/testcases/tcCreateFromMantis.html + the api/tcmantisimport
 * BFF (Refs #1948), which adds an in-screen suite picker and duplicate
 * criteria/actions through the verbatim 1.9.20 saveImportedTCData() engine,
 * while keeping the legacy mapping (name = issue_issue:<id> - <summary>,
 * summary = description [+ steps + additional information] under the server
 * locale labels, external id = null — see line 476 of the pre-shim controller).
 *
 * Behaviour of this shim:
 *   - GET|HEAD -> 302 to the modern screen with the deep-link context
 *     (tproject_id + containerID) forwarded, like the sibling #1896 shim
 *     lib/testcases/tcCreateFromIssue.php;
 *   - any write verb -> 405 JSON modern_endpoint_only: the legacy POST
 *     performed the import with NO rights check (any authenticated user could
 *     import into any project — sibling class of #1696/#1679) and no CSRF
 *     proof; it must never execute again. The modern BFF enforces
 *     mgt_modify_tc on the addressed project BEFORE container resolution
 *     (the #1697 no-oracle lesson) behind bffSameOriginGuard();
 *   - the legacy GET side effect (session/locale form state) is dropped.
 */
session_start();

$method = strtoupper(strval($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'HEAD'], true)) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    http_response_code(405);
    header('Allow: GET, HEAD');
    echo json_encode([
        'status' => 'error',
        'code' => 'modern_endpoint_only',
        'message' => 'the Mantis Issue Import is served by ' .
            'gui/templates/testcases/tcCreateFromMantis.html (Refs #1948)',
    ]);
    exit;
}

$base = '';
if (isset($_SESSION['basehref'])) {
    $base = rtrim($_SESSION['basehref'], '/') . '/';
}
header('Location: ' . $base . 'gui/templates/testcases/tcCreateFromMantis.html' .
       '?tproject_id=' . intval($_REQUEST['tproject_id'] ?? $_SESSION['testprojectID'] ?? 0) .
       '&containerID=' . intval($_REQUEST['container_id'] ?? $_REQUEST['containerID'] ?? 0));
exit;
