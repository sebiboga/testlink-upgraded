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
 *   returned to ANY authenticated user). It is now a non-mutating,
 *   session-guarded 302 redirect to the modern Dashio log viewer, which renders
 *   the log from the BFF's PLAIN-TEXT payload and enforces mgt_view_req on the
 *   owning test project.
 */

require_once('../../config.inc.php');
require_once('common.php');

// Legacy parity: testlinkInitPage() sent anonymous callers to login.php.
testlinkInitPage($db);

$itemId = isset($_REQUEST['item_id']) ? $_REQUEST['item_id']
         : (isset($_REQUEST['id']) ? $_REQUEST['id'] : 0);

$tprojectId = isset($_REQUEST['tproject_id']) ? intval($_REQUEST['tproject_id']) : 0;

// Legacy getreqlog.php auto-detected the node type: requirement_version rows came
// from req_versions, anything else fell back to req_revisions. The modern BFF
// proves the type, so resolve it here the same way and forward the right type.
$type = 'requirement_version';
$nodeTypes = $db->get_recordset("SELECT id, description FROM node_types");
$descr = array();
foreach ((array)$nodeTypes as $nt) {
    $descr[(int)$nt['id']] = $nt['description'];
}
$nh = $db->get_recordset(
    "SELECT node_type_id FROM nodes_hierarchy WHERE id = " . intval($itemId));
if (!empty($nh)) {
    $d = isset($descr[(int)$nh[0]['node_type_id']])
       ? $descr[(int)$nh[0]['node_type_id']] : '';
    if ($d === 'requirement_version') {
        $type = 'requirement_version';
    } elseif ($d === 'requirement_revision') {
        $type = 'requirement';
    } else {
        // not a requirement version/revision node - let the modern screen answer
        // with its not-found state instead of guessing.
        tLog('getreqlog.php: item_id ' . intval($itemId) . ' is a "' . $d .
             '" node, not a requirement version/revision - redirecting to the ' .
             'modern log viewer which answers 404.', 'INFO');
        $type = 'requirement';
    }
}

$url = '/gui/templates/requirements/logViewer.html' .
       '?type=' . $type . '&id=' . intval($itemId);
if ($tprojectId > 0) {
    $url .= '&tproject_id=' . $tprojectId;
}

header('Location: ' . $url);
exit;
