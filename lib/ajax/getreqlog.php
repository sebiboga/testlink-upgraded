<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * @filesource getreqlog.php
 *
 * @internal Refs #1652 - legacy deep link closed by the log-message viewer
 *   modernization. This endpoint used to `echo` an UNESCAPED HTML fragment
 *   built with nl2br() + str_replace('<p>','',...) straight out of
 *   req_versions.log_message / req_revisions.log_message, with no rights check
 *   and no proof that the id belonged to the caller's test project (the SQL was
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
function getreqlog_fragment($log)
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
//    gui/templates/dashio/requirements/reqViewVersions.tpl:174,
//    reqViewRevisionRO.tpl:28 and reqCompareVersions.tpl:34 all do
//    `new Ext.ToolTip({ autoLoad:{ url: fRoot+'lib/ajax/getreqlog.php?item_id=' } })`,
//    and Ext injects the response BODY as HTML. Redirecting them would dump the
//    whole modern page (styles, toolbar, scripts) into the tooltip.
// ---------------------------------------------------------------------- //
if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    // Legacy auto-detection: a req_versions row is a requirement VERSION, any
    // other node is a requirement REVISION. The fragment contract is what the
    // tooltip needs, so the node type only has to pick the right table.
    $nh = $db->get_recordset(
        "SELECT node_type_id FROM nodes_hierarchy WHERE id = " . $itemId);
    $logRaw = '';
    if (!empty($nh)) {
        $nodeTypes = $db->get_recordset("SELECT id, description FROM node_types");
        $descr = array();
        foreach ((array)$nodeTypes as $nt) {
            $descr[(int)$nt['id']] = $nt['description'];
        }
        $d = isset($descr[(int)$nh[0]['node_type_id']])
           ? $descr[(int)$nh[0]['node_type_id']] : '';
        // req_versions.id IS the version node id; req_revisions.parent_id is the
        // requirement. Both reach the owning test project through the
        // requirement's spec, which is what the tproject_id filter scopes on.
        $scoped = $tprojectId > 0
            ? " AND R.srs_id IN (SELECT id FROM req_specs WHERE testproject_id = " . $tprojectId . ")"
            : '';
        if ($d === 'requirement_version') {
            $row = $db->get_recordset(
                "SELECT RV.log_message FROM req_versions RV " .
                " JOIN nodes_hierarchy NH ON NH.id = RV.id " .
                " JOIN requirements R ON R.id = NH.parent_id " .
                " WHERE RV.id = " . $itemId . $scoped);
        } else {
            $row = $db->get_recordset(
                "SELECT RR.log_message FROM req_revisions RR " .
                " JOIN requirements R ON R.id = RR.parent_id " .
                " WHERE RR.id = " . $itemId . $scoped);
        }
        if (!empty($row)) {
            $logRaw = $row[0]['log_message'];
        }
    }
    echo getreqlog_fragment($logRaw);
    exit;
}

// ---------------------------------------------------------------------- //
// 2. Plain browser GET (deep link) -> the modern screen. The modern BFF proves
//    the node type and the owning project, so guessing a type here would only
//    risk answering the wrong screen state.
// ---------------------------------------------------------------------- //
$url = '/gui/templates/requirements/logViewer.html' .
       '?type=requirement_version&id=' . $itemId;
if ($tprojectId > 0) {
    $url .= '&tproject_id=' . $tprojectId;
}

header('Location: ' . $url);
exit;
