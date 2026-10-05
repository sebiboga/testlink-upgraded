<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * @filesource getrequirementnodes.php
 * @author    Francisco Mancardi
 *
 * @internal Refs #1696 - legacy REQUIREMENT SPECIFICATION tree lazy loader. It was
 *   the server side of the ExtJS tree built by
 *   lib/functions/tlRequirementFilterControl.class.php and rendered by
 *   gui/templates/dashio/requirements/reqSpecListTree.tpl (the requirement
 *   navigator frame of the 1.9.20 requirement specification work area), and
 *   answered the children of ONE expanded node:
 *     ?mode=reqspec|addtc&root_node=<tproject_id>[&node=<parent id>][&filter_node=<id>]
 *     [&show_children=0|1][&operation=manage|print]
 *   Each row was an ExtJS tree node (text / id / position / leaf / cls /
 *   testlink_node_type / testlink_node_name / forbidden_parent / href) built
 *   from `SELECT ... FROM nodes_hierarchy WHERE parent_id = <parent>`, with
 *   `req_specs.doc_id` / `requirements.req_doc_id` prefixed to the label and a
 *   recursive requirement count appended to every specification label ("Name (n)").
 *   The href was chosen from the $fn[operation][mode] table, so the same loader
 *   also served mode=addtc and operation=print:
 *     mode=reqspec,  operation=manage -> TPROJECT_REQ_SPEC_MGMT | REQ_SPEC_MGMT | REQ_MGMT
 *     mode=reqspec,  operation=print  -> TPROJECT_PTP_RS       | TPROJECT_PRS    | openLinkedReqWindow
 *     mode=addtc,    operation=manage -> EP                    | ERS             | ER
 *     mode=addtc,    operation=print  -> TPROJECT_PTP          | TPROJECT_PRS    | TPROJECT_PRS
 *
 *   It is retired here because it was never authorized:
 *
 *   1. It performed NO rights check at all - only testlinkInitPage(), i.e. a
 *      SESSION check. Any authenticated user, including one whose role is
 *      '<no rights>' and who has no row at all in user_testproject_roles, could
 *      read the requirement doc_ids, requirement titles and the shape of the
 *      requirement branch of ANY test project by sending an arbitrary root_node /
 *      node / filter_node id. Same class of bug as #1765
 *      (getreqcoveragenodes.php) and #1770 (gettprojectnodes.php), whose
 *      siblings were retired first.
 *   2. It had no project scope and no node-type gate: display_children()
 *      filtered on `parent_id` only, so the walk escaped the requirement branch
 *      and any nodes_hierarchy.id could be used as the parent.
 *   3. root_node was optional: with only `node=<id>` the emitted hrefs were
 *      javascript:REQ_SPEC_MGMT(,2) - a node addressed with no project at all.
 *
 *   The equivalent authorized surface is the modern Requirement Specification
 *   Tree navigator (gui/templates/requirements/reqSpecListTree.html) backed by
 *   api/reqspectreelist, which enforces mgt_view_req / mgt_modify_req on the
 *   ADDRESSED project BEFORE resolving it (so it is not even a test-project
 *   existence oracle) and proves every node_id to be a requirement
 *   specification - or a container below one - of that same project. Refs #1695.
 *
 *   Legacy deep links (GET) are answered with a 302 to the modern screen; the
 *   read is deliberately NOT replayed, because it was never authorized. A write
 *   verb is refused with 405 and a pointer to the modern endpoints, logged as a
 *   WARNING so the retirement shows up in the Event Viewer instead of being
 *   silent.
 *
 *   The file is kept (rather than deleted) precisely because
 *   lib/functions/tlRequirementFilterControl.class.php:272 still builds this URL
 *   into its loader - that call path is itself dead (the control is never
 *   instantiated), and a redirect keeps the old URL resolving.
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
    tLog('BFF shim: refused ' . $method . ' on the retired legacy requirement specification tree '
        . 'loader - read it from GET /api/reqspectreelist/index.php?action=init|children|projects '
        . 'instead (Refs #1696).',
        'WARNING');
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode(array(
        'status' => 'error',
        'code' => 'method_not_allowed',
        'message' => 'The legacy requirement specification tree loader was retired; '
            . 'use GET /api/reqspectreelist/index.php?action=init|children|projects',
    ));
    exit;
}

$q = $_GET;

$base = isset($_SESSION['basehref']) ? $_SESSION['basehref'] : '/';
$target = $base . 'gui/templates/requirements/reqSpecListTree.html';

$params = array();
// root_node was the test project id of the tree; node was the expanded node,
// which carries no meaning for the modern screen (it expands client side).
foreach (array('tproject_id', 'root_node') as $k) {
    if (isset($q[$k]) && intval($q[$k]) > 0) {
        $params['tproject_id'] = intval($q[$k]);
        break;
    }
}
// filter_node is forwarded so an old bookmark survives the redirect intact.
// The modern requirement tree does NOT read it (unlike its test-case sibling
// gui/templates/testcases/tcProjectTree.html:119, which does read filter_node):
// grep -ri filter_node over gui/templates/requirements/reqSpecListTree.html and
// api/reqspectreelist/ returns nothing. It is therefore carried as a harmless
// no-op rather than as a gesture.
if (isset($q['filter_node']) && intval($q['filter_node']) > 0) {
    $params['filter_node'] = intval($q['filter_node']);
}

if (!empty($params)) {
    $target .= '?' . http_build_query($params);
}

header('Location: ' . $target);
exit;
