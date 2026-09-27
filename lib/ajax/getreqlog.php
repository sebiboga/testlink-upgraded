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
 * Legacy auto-detection: a req_versions row is a requirement VERSION, any other
 * node is a requirement REVISION (1.9.20 used tree_mgr->get_available_node_types()
 * + get_node_hierarchy_info() for the same decision). Both the fragment contract
 * and the deep-link redirect need it, so it is resolved once here.
 *
 * The comparison is done on the NUMERIC node_types.id (8 requirement_version,
 * 10 requirement_revision) exactly like the modern BFF does, so a renamed or
 * localized node_types.description can never silently mis-route a deep link.
 *
 * @param object $db     database handle
 * @param int    $itemId node id from the request
 * @return int node_types.id (0 when the node does not exist)
 */
function getreqlog_node_type($db, $itemId)
{
    if ($itemId <= 0) {
        return 0;
    }
    $nh = $db->get_recordset(
        "SELECT node_type_id FROM nodes_hierarchy WHERE id = " . $itemId);
    if (empty($nh)) {
        return 0;
    }
    return (int)$nh[0]['node_type_id'];
}

/** node_types ids, same constants as api/logviewer/index.php */
define('GETREQLOG_TYPE_VERSION', 8);
define('GETREQLOG_TYPE_REVISION', 10);

/**
 * Escape for the fragment contract. ENT_SUBSTITUTE is mandatory: without it
 * htmlspecialchars() returns '' for a log holding invalid UTF-8, which would
 * turn a non-empty log into a silently blank tooltip.
 *
 * @param string $s already normalized plain text
 * @return string escaped text
 */
function getreqlog_esc($s)
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

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

    // Legacy parity (1.9.20): an empty log still showed the localized
    // "Log Message is empty" hint inside the tooltip instead of a blank box.
    if ($s === '') {
        $hint = trim(strip_tags(lang_get('empty_log_message')));
        return '<span class="tlLogFragment"><em>'
            . getreqlog_esc($hint) . '</em></span>';
    }
    return '<span class="tlLogFragment">' . nl2br(getreqlog_esc($s)) . '</span>';
}

/**
 * Answer the fragment contract with the legacy localized "no right" message and
 * a 403 status, so an unauthorized tooltip never receives the log body.
 *
 * @return void
 */
function getreqlog_denied()
{
    $msg = trim(strip_tags(lang_get('user_has_no_right_for_action')));
    if (!headers_sent()) {
        http_response_code(403);
    }
    echo '<span class="tlLogFragment">' . getreqlog_esc($msg) . '</span>';
    exit;
}

// ---------------------------------------------------------------------- //
// 1. XHR -> escaped fragment for the still-live legacy Ext.ToolTip call sites:
//    gui/templates/dashio/requirements/reqViewVersions.tpl:174,
//    reqViewRevisionRO.tpl:28 and reqCompareVersions.tpl:34 all do
//    `new Ext.ToolTip({ autoLoad:{ url: fRoot+'lib/ajax/getreqlog.php?item_id=' } })`,
//    and Ext injects the response BODY as HTML. Redirecting them would dump the
//    whole modern page (styles, toolbar, scripts) into the tooltip.
//
//    req_versions.id IS the version node id; req_revisions.parent_id is the
//    requirement. Both reach the owning test project through the requirement's
//    spec, which is what the tproject_id filter scopes on and what
//    mgt_view_req is then checked against - the legacy reader did neither.
// ---------------------------------------------------------------------- //
if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    $logRaw = '';
    $nodeType = getreqlog_node_type($db, $itemId);
    if ($nodeType > 0) {
        $scoped = $tprojectId > 0
            ? " AND RSPEC.testproject_id = " . $tprojectId
            : '';
        if ($nodeType === GETREQLOG_TYPE_VERSION) {
            $row = $db->get_recordset(
                "SELECT RV.log_message, RSPEC.testproject_id " .
                " FROM req_versions RV " .
                " JOIN nodes_hierarchy NH ON NH.id = RV.id " .
                " JOIN requirements R ON R.id = NH.parent_id " .
                " JOIN req_specs RSPEC ON RSPEC.id = R.srs_id " .
                " WHERE RV.id = " . $itemId . $scoped);
        } else {
            $row = $db->get_recordset(
                "SELECT RR.log_message, RSPEC.testproject_id " .
                " FROM req_revisions RR " .
                " JOIN nodes_hierarchy RN ON RN.id = RR.id AND RN.node_type_id = " . GETREQLOG_TYPE_REVISION .
                " JOIN requirements R ON R.id = RR.parent_id " .
                " JOIN req_specs RSPEC ON RSPEC.id = R.srs_id " .
                " WHERE RR.id = " . $itemId . $scoped);
        }
        if (!empty($row)) {
            $owningProjectId = intval($row[0]['testproject_id']);
            $canView = isset($_SESSION['currentUser']) && is_object($_SESSION['currentUser'])
                && $owningProjectId > 0
                && $_SESSION['currentUser']->hasRight($db, 'mgt_view_req', $owningProjectId);
            if (!$canView) {
                getreqlog_denied();
            }
            $logRaw = $row[0]['log_message'];
        }
    }
    echo getreqlog_fragment($logRaw);
    exit;
}

// ---------------------------------------------------------------------- //
// 2. Plain browser GET (deep link) -> the modern screen. The type is resolved
//    here exactly like the legacy tooltip did it, so a deep link to a
//    requirement REVISION does not land on the requirement-version screen (and
//    its 404). The modern BFF re-proves the node type and the owning project.
// ---------------------------------------------------------------------- //
$nodeType = getreqlog_node_type($db, $itemId);
if ($nodeType === GETREQLOG_TYPE_VERSION) {
    $type = 'requirement_version';
} else {
    $type = 'requirement';
    if ($nodeType !== GETREQLOG_TYPE_REVISION) {
        tLog('getreqlog.php: item_id=' . $itemId . ' is a node of type '
             . $nodeType . ', not a requirement version/revision.', 'INFO');
    }
}

$url = '/gui/templates/requirements/logViewer.html' .
       '?type=' . $type . '&id=' . $itemId;
if ($tprojectId > 0) {
    $url .= '&tproject_id=' . $tprojectId;
}

header('Location: ' . $url);
exit;
