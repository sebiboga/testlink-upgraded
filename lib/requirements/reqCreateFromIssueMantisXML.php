<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * @filesource  reqCreateFromIssueMantisXML.php
 * @package     TestLink
 * @copyright   2007-2026, TestLink community
 *
 * Create Requirements from a Mantis Issue XML export — MODERNIZED (Refs #1960)
 *
 * The legacy renderer (this file + gui/templates/dashio/requirements/
 * reqCreateFromIssueMantisXML.tpl) was a full Smarty page that re-implemented
 * the Mantis XML import inline and, as a side effect, called `new dBug()`
 * twice on every request and never required lib/functions/requirements.inc.php
 * (a fatal once internal_links is enabled). It is replaced by:
 *
 *   gui/templates/requirements/reqMantisImport.html  the Dashio screen
 *   api/reqmantisimport/index.php                    the BFF (init + import)
 *
 * The import engine and its JSON contract live in api/reqfromissues/index.php
 * (Refs #1503) and are reused in-process, so there is exactly one
 * implementation of the mapping:
 *   title = "Issue:<id> - <summary>"
 *   docid = "Mantis Task ID:<id>"
 *   description = description (+ steps to reproduce + additional information)
 * and of the duplicate handling in requirement_mgr::createFromMap().
 *
 * Rights: mgt_view_req AND mgt_modify_req on the owning test project.
 *
 * This controller is kept only as a non-mutating redirect for stale bookmarks,
 * legacy reqSpecView links and wiki pages. Every write verb is refused so the
 * file can never be used to smuggle an import past the BFF's session, rights
 * and same-origin checks.
 */
require_once('../../config.inc.php');
require_once('common.php');
testlinkInitPage($db, false, false);

if (isset($_SERVER['REQUEST_METHOD'])
    && !in_array(strtoupper($_SERVER['REQUEST_METHOD']), array('GET', 'HEAD'), true)) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('status' => 'error', 'code' => 'modern_endpoint_only',
        'message' => 'Use POST /api/reqmantisimport/index.php?action=import'));
    exit;
}

if (empty($_SESSION['userID'])) {
    $dest = 'lib/requirements/reqCreateFromIssueMantisXML.php'
        . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== ''
            ? '?' . $_SERVER['QUERY_STRING'] : '');
    redirect('login.php?note=expired&destination=' . urlencode($dest));
}

$specId = isset($_REQUEST['req_spec_id']) && is_scalar($_REQUEST['req_spec_id'])
    ? intval($_REQUEST['req_spec_id']) : 0;
if ($specId <= 0 && isset($_REQUEST['spec_id']) && is_scalar($_REQUEST['spec_id'])) {
    $specId = intval($_REQUEST['spec_id']);
}
$tprojectId = isset($_REQUEST['tproject_id']) && is_scalar($_REQUEST['tproject_id'])
    ? intval($_REQUEST['tproject_id']) : 0;
if ($tprojectId <= 0 && isset($_SESSION['testprojectID'])) {
    $tprojectId = intval($_SESSION['testprojectID']);
}

$target = '/gui/templates/requirements/reqMantisImport.html?req_spec_id=' . $specId;
if ($tprojectId > 0) {
    $target .= '&tproject_id=' . $tprojectId;
}
if (isset($_SESSION['locale']) && $_SESSION['locale'] !== '') {
    $target .= '&locale=' . rawurlencode($_SESSION['locale']);
}

redirect($target);
