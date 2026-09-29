<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * @filesource  reqMgrSystemEdit.php
 * @author      francisco.mancardi@gmail.com
 * @since 1.9.6
 *
 * @internal revisions
 *
 * @internal Refs #1727 - retired legacy page, now a session-guarded redirect shim.
 *   In 1.9.20 this was a full legacy page: it ran testlinkInitPage() +
 *   checkRights() (reqmgrsystem_management), built an item from
 *   reqMgrSystemCommands and rendered gui/templates/dashio/reqmgrsystems/
 *   reqMgrSystemEdit.tpl (a plain cross-site-postable <form action="...php">,
 *   no origin proof, an Ext.Ajax call to lib/ajax/getreqmgrsystemcfgtemplate.php
 *   for the configuration example, a "used on test project" table and a
 *   showEventHistoryFor() icon).
 *
 *   The capability is modernized as gui/templates/reqmgrsystems/reqMgrSystemEdit.html
 *   backed by api/reqmgrsystemedit/index.php: create / edit / delete /
 *   check connection / configuration example, the linked-test-projects table and
 *   the event-history deep link, all behind the SAME single legacy right plus
 *   bffSameOriginGuard() + bffEnforceSession(), which the legacy form never had.
 *   The list screen (gui/templates/reqmgrsystems/reqMgrSystemView.html) links to
 *   it per row, and $actions->reqMgrSystemEdit (lib/functions/common.php) carries
 *   the current test project / test plan context.
 *
 *   So every legacy deep link resolves:
 *     ?doAction=create                      -> editor, create mode
 *     ?doAction=edit&id=N                   -> editor, edit mode
 *     ?doAction=checkConnection&id=N        -> editor, edit mode (Check connection)
 *     ?doAction=doCreate|doUpdate|doDelete  -> list screen (see below)
 *     anything else                         -> 302 to the list screen + tLog ERROR
 *
 *   The three legacy WRITE doActions are deliberately NOT executed here. They
 *   were only ever reachable from the two dead reqMgrSystemEdit.tpl files, they
 *   are plain unauthenticated-by-origin POSTs (the exact weakness the BFF closes
 *   with bffSameOriginGuard), and the modern editor performs the same writes
 *   through api/reqmgrsystemedit with the right + session + origin proof. Keeping
 *   a silent second write path would reintroduce the hole this shim exists to
 *   close, so the request is logged and the user is sent to the list screen.
 */

require_once("../../config.inc.php");
require_once("common.php");

$db = new database(DB_TYPE);
doDBConnect($db);

// Legacy testlinkInitPage() contract: an anonymous visitor is bounced to the
// login screen with the note=expired bounce and the original destination.
// checkSessionValid()'s own redirect is used (rather than a hand-rolled
// header()) because it walks up from dirname(SCRIPT_FILENAME) until it finds
// login.php - a relative 'login.php' would resolve against /lib/reqmgrsystems/
// and 404.
if (!checkSessionValid($db)) {
    exit;  // unreachable: the call above already redirected
}

$base = isset($_SESSION['basehref']) ? $_SESSION['basehref'] : '/';
$listUrl = $base . 'gui/templates/reqmgrsystems/reqMgrSystemView.html';
$editorUrl = $base . 'gui/templates/reqmgrsystems/reqMgrSystemEdit.html';

$doAction = isset($_REQUEST['doAction']) ? trim((string)$_REQUEST['doAction']) : 'create';
$id = isset($_REQUEST['id']) ? intval($_REQUEST['id']) : 0;

// carry the frame context forward, exactly like the legacy $basehref links did
$qs = array();
foreach (array('tproject_id', 'tplan_id') as $k) {
    $v = isset($_REQUEST[$k]) ? intval($_REQUEST[$k]) : 0;
    if ($v > 0) {
        $qs[$k] = $v;
    } else {
        // Refs #1727: the session keys are testprojectID / testplanID (see
        // lib/functions/common.php and lib/attachments/attachmentdelete.php);
        // $_SESSION['tproject_id'] is never written anywhere, so a bookmarked
        // legacy deep link without the query parameters used to lose the
        // project/plan context.
        $sessionKey = ($k === 'tproject_id') ? 'testprojectID' : 'testplanID';
        if (isset($_SESSION[$sessionKey]) && intval($_SESSION[$sessionKey]) > 0) {
            $qs[$k] = intval($_SESSION[$sessionKey]);
        }
    }
}
$suffix = empty($qs) ? '' : '?' . http_build_query($qs);

switch ($doAction) {
    case '':
    case 'create':
    case 'edit':
    case 'checkConnection':
        $url = $editorUrl . ($id > 0 ? '?id=' . $id . ($suffix ? '&' . substr($suffix, 1) : '')
                                      : $suffix);
        break;

    case 'doCreate':
    case 'doUpdate':
    case 'doDelete':
    case 'delete':
        tLog('reqMgrSystemEdit.php shim: legacy write doAction "' . $doAction .
             '" is no longer executed server side (Refs #1727) - the modern editor ' .
             'writes through api/reqmgrsystemedit, which enforces the right, the ' .
             'session window and same-origin proof.', 'INFO');
        $url = $listUrl . $suffix;
        break;

    default:
        tLog('reqMgrSystemEdit.php shim: unknown doAction "' . $doAction .
             '" - refusing to guess a modern target (Refs #1727).', 'ERROR');
        $url = $listUrl . $suffix;
        break;
}

header('Location: ' . $url, true, 302);
exit;
