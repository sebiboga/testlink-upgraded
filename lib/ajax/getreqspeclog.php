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
 *      longer inject anything into the caller's DOM.
 *   2. Everyone else (a plain browser GET, i.e. a deep link) gets a
 *      non-mutating 302 to the modern Dashio log viewer, which renders the log
 *      from the BFF's PLAIN-TEXT payload and enforces mgt_view_req on the
 *      owning test project.
 */

require_once('../../config.inc.php');
require_once('common.php');

// Legacy parity: testlinkInitPage() sent anonymous callers to login.php.
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

    if ($s === '') {
        return '';
    }
    return '<span class="tlLogFragment">' . nl2br(htmlspecialchars($s, ENT_QUOTES, 'UTF-8')) . '</span>';
}

// ---------------------------------------------------------------------- //
// 1. XHR -> escaped fragment for the still-live legacy Ext.ToolTip call sites:
//    gui/templates/dashio/requirements/reqSpecCompareRevisions.tpl:34,
//    reqSpecViewRevision.tpl:31 and include/reqSpecViewJS.inc.tpl:30 all do
//    `new Ext.ToolTip({ autoLoad:{ url: fRoot+'lib/ajax/getreqspeclog.php?item_id=' } })`,
//    and Ext injects the response BODY as HTML. Redirecting them would dump the
//    whole modern page (styles, toolbar, scripts) into the tooltip.
// ---------------------------------------------------------------------- //
if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    $row = $db->get_recordset(
        "SELECT RSREV.log_message FROM req_specs_revisions RSREV " .
        " JOIN req_specs RSPEC ON RSPEC.id = RSREV.parent_id " .
        " WHERE RSREV.id = " . $itemId .
        ($tprojectId > 0 ? " AND RSPEC.testproject_id = " . $tprojectId : ''));
    echo getreqspeclog_fragment(empty($row) ? '' : $row[0]['log_message']);
    exit;
}

// ---------------------------------------------------------------------- //
// 2. Plain browser GET (deep link) -> the modern screen.
// ---------------------------------------------------------------------- //
$url = '/gui/templates/requirements/logViewer.html' .
       '?type=requirement_spec_version&id=' . $itemId;
if ($tprojectId > 0) {
    $url .= '&tproject_id=' . $tprojectId;
}

header('Location: ' . $url);
exit;
