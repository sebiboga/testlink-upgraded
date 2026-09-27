<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * @filesource getreqspeclog.php
 *
 * @internal Refs #1652 - legacy deep link closed by the log-message viewer
 *   modernization. This endpoint used to `echo` an UNESCAPED HTML fragment
 *   built with nl2br() + str_replace('<p>','',...) straight out of
 *   req_specs_revisions.log_message, with no rights check and no proof that the
 *   id belonged to the caller's test project (the SQL was
 *   `SELECT log_message ... WHERE id = <intval>`, so ANY project's log was
 *   returned to ANY authenticated user).
 *
 *   It now answers TWO contracts, because the legacy log tooltips are still
 *   live (see the note on the fragment branch below):
 *
 *   1. XHR callers (Ext.ToolTip autoLoad, which sets X-Requested-With) get an
 *      HTML-escaped fragment with the same normalization the modern BFF
 *      applies - visually the same tooltip, but a log containing markup can no
 *      longer inject anything into the caller's DOM - and ONLY after the
 *      request has been authorized against the OWNING test project, which the
 *      legacy reader never did.
 *   2. Everyone else (a plain browser GET, i.e. a deep link) gets a
 *      non-mutating 302 to the modern Dashio log viewer, which renders the log
 *      from the BFF's PLAIN-TEXT payload and enforces mgt_view_req on the
 *      owning test project.
 */

require_once('../../config.inc.php');
require_once('common.php');

// Legacy parity: testlinkInitPage() sent anonymous callers to login.php and
// populated $_SESSION['currentUser'] (checkSessionValid()).
testlinkInitPage($db);

$itemId = isset($_REQUEST['item_id']) ? $_REQUEST['item_id']
         : (isset($_REQUEST['id']) ? $_REQUEST['id'] : 0);
$itemId = intval($itemId);

$tprojectId = isset($_REQUEST['tproject_id']) ? intval($_REQUEST['tproject_id']) : 0;

/**
 * Legacy tooltip contract: an HTML fragment, not a document. The log is
 * normalized exactly like the BFF does (api/logviewer/index.php lvNormalizeLog)
 * and then ESCAPED, so a log containing <script> renders as visible text
 * instead of executing inside the caller's page.
 *
 * @param string $log raw log_message column value
 * @return string escaped fragment
 */
function getreqspeclog_fragment($log)
{
    $s = (string)$log;
    $s = preg_replace('#</?p\s*>#i', "\n", $s);
    $s = preg_replace('#<br\s*/?>#i', "\n", $s);
    $s = strip_tags($s);
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = str_replace(array("\r\n", "\r"), "\n", $s);
    $s = preg_replace('/[ \t]+\n/', "\n", $s);
    $s = preg_replace('/\n{3,}/', "\n\n", $s);
    $s = trim($s);

    // Legacy parity (1.9.20): an empty log still showed the localized
    // "Log Message is empty" hint inside the tooltip instead of a blank box.
    if ($s === '') {
        $hint = trim(strip_tags(lang_get('empty_log_message')));
        return '<span class="tlLogFragment"><em>'
            . getreqspeclog_esc($hint) . '</em></span>';
    }
    return '<span class="tlLogFragment">' . nl2br(getreqspeclog_esc($s)) . '</span>';
}

/**
 * Escape for the fragment contract. ENT_SUBSTITUTE is mandatory: without it
 * htmlspecialchars() returns '' for a log holding invalid UTF-8, which would
 * turn a non-empty log into a silently blank tooltip.
 *
 * @param string $s already normalized plain text
 * @return string escaped text
 */
function getreqspeclog_esc($s)
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Answer the fragment contract with the legacy localized "no right" message and
 * a 403 status, so an unauthorized tooltip never receives the log body.
 *
 * @return void
 */
function getreqspeclog_denied()
{
    $msg = trim(strip_tags(lang_get('user_has_no_right_for_action')));
    if (!headers_sent()) {
        http_response_code(403);
    }
    echo '<span class="tlLogFragment">' . getreqspeclog_esc($msg) . '</span>';
    exit;
}

// ---------------------------------------------------------------------- //
// 1. XHR -> escaped fragment for the still-live legacy Ext.ToolTip call sites:
//    gui/templates/dashio/requirements/reqSpecCompareRevisions.tpl:34,
//    reqSpecViewRevision.tpl:31 and include/reqSpecViewJS.inc.tpl:30 all do
//    `new Ext.ToolTip({ autoLoad:{ url: fRoot+'lib/ajax/getreqspeclog.php?item_id=' } })`,
//    and Ext injects the response BODY as HTML. Redirecting them would dump the
//    whole modern page (styles, toolbar, scripts) into the tooltip.
//
//    The owning test project is derived from the revision itself (the legacy
//    SQL did not even do that) and mgt_view_req is enforced on it, closing the
//    cross-project read any authenticated user could perform before #1652.
// ---------------------------------------------------------------------- //
if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    if ($itemId > 0) {
        $row = $db->get_recordset(
            "SELECT RSREV.log_message, RSPEC.testproject_id " .
            " FROM req_specs_revisions RSREV " .
            " JOIN nodes_hierarchy SNH ON SNH.id = RSREV.id AND SNH.node_type_id = 11 " .
            " JOIN req_specs RSPEC ON RSPEC.id = RSREV.parent_id " .
            " WHERE RSREV.id = " . $itemId .
            ($tprojectId > 0 ? " AND RSPEC.testproject_id = " . $tprojectId : ''));

        if (!empty($row)) {
            $owningProjectId = intval($row[0]['testproject_id']);
            $canView = isset($_SESSION['currentUser']) && is_object($_SESSION['currentUser'])
                && $owningProjectId > 0
                && $_SESSION['currentUser']->hasRight($db, 'mgt_view_req', $owningProjectId);
            if (!$canView) {
                getreqspeclog_denied();
            }
            echo getreqspeclog_fragment($row[0]['log_message']);
            exit;
        }
    }
    // Unknown id, or an id that does not belong to the requested project:
    // answer exactly like the legacy reader did for an empty log.
    echo getreqspeclog_fragment('');
    exit;
}

// ---------------------------------------------------------------------- //
// 2. Plain browser GET (deep link) -> the modern screen. The modern BFF proves
//    the node type and the owning project, so nothing is resolved here.
// ---------------------------------------------------------------------- //
$url = '/gui/templates/requirements/logViewer.html' .
       '?type=requirement_spec_version&id=' . $itemId;
if ($tprojectId > 0) {
    $url .= '&tproject_id=' . $tprojectId;
}

header('Location: ' . $url);
exit;
