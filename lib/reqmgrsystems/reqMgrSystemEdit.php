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

/**
 * Refs #1731: a scalar $_REQUEST value, or null when it is absent OR
 * array-shaped. isset() alone is not enough: PHP fills $_REQUEST['doAction']
 * with an ARRAY for ?doAction[]=x, and the bare (string)/intval casts the
 * legacy file used then raised "E_WARNING Array to string conversion"
 * (persisted as a log_level=2 row by watchPHPErrors, from ANY authenticated
 * session - this shim has no rights check) and coerced the value to the
 * literal "Array", which additionally tripped the default: branch's
 * tLog(..., 'ERROR'). Two Event-Viewer rows per request, on demand.
 *
 * The is_scalar() idiom is the one already used in this repo - see
 * lib/attachments/attachmentdelete.php:33-38 and the bffQueryScalar() /
 * bffQueryInt() helpers in api/reqmgrsystemedit/index.php.
 */
function shimReqScalar($name)
{
    if (!isset($_REQUEST[$name]) || !is_scalar($_REQUEST[$name])) {
        return null;
    }
    return trim((string)$_REQUEST[$name]);
}

function shimReqInt($name)
{
    $v = shimReqScalar($name);
    return ($v === null || !is_numeric($v)) ? 0 : intval($v);
}

// An array-shaped doAction is not a usable verb. It is deliberately NOT turned
// into an HTTP error: this file is a bookmark redirector whose whole contract is
// "never 500, always 302 somewhere sane", and the legacy deep links it exists
// to serve must keep working. The junk value never becomes a string at all, so
// the request is refused below with a FIXED log message - no attacker-controlled
// text reaches the log and no log_level=1/2 row is written (the retired-write
// branch below logs the same way, at INFO - and INFO does not persist below
// WARNING, so the refusal is silent apart from the 302: measured 0 rows of ANY
// level in `events` for a request that used to write 2).
$rawDoAction = shimReqScalar('doAction');
$doActionShaped = isset($_REQUEST['doAction']) && $rawDoAction === null;
$doAction = ($rawDoAction === null) ? '' : $rawDoAction;
$id = shimReqInt('id');

if ($doActionShaped) {
    // Refs #1731: ?doAction[]=x - refuse the request, but at INFO like the
    // retired-write branch, never at ERROR: a crafted query string must not be
    // able to write an Error/Warning row into the Event Viewer. The message is a
    // fixed literal precisely so that no attacker-controlled text is logged.
    tLog('reqMgrSystemEdit.php shim: doAction is not a scalar value - ' .
         'refusing to guess a modern target (Refs #1731).', 'INFO');
    header('Location: ' . $listUrl, true, 302);
    exit;
}

// carry the frame context forward, exactly like the legacy $basehref links did
$qs = array();
foreach (array('tproject_id', 'tplan_id') as $k) {
    // Refs #1731: an array-shaped ?tproject_id[]=1 used to be silently
    // intval()'d to 1 (intval(array) is warning-free in PHP 8), dropping the
    // user into test project 1 with no diagnostic at all. 0 means "no context",
    // which is the case the session fallback below already handles.
    $v = shimReqInt($k);
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
