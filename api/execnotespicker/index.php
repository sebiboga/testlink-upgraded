<?php
/**
 * api/execnotespicker — Execution Notes PICKER BFF (Refs #1809)
 *
 * The follow-up the #1807 run explicitly deferred: gui/templates/execute/
 * execNotesReadonly.html is deep-linkable only (?exec_id=N). Opened WITHOUT an
 * exec_id (the shape an ASIDE menu link would produce) it can only render a
 * dead-end "missing id" card, which is why $actions->execNotesReadonly stayed
 * an UNWIRED action (lib/functions/common.php). This endpoint gives the ASIDE
 * a real target: list the executions of the current test plan - which of them
 * carry notes - so the reader can open the read-only viewer on one of them.
 *
 * Routes (safe verbs only - this endpoint NEVER writes):
 *   GET|HEAD ?action=init[&tplan_id=N]        -> context: project/plan names,
 *                                                counts (all vs with-notes),
 *                                                grants
 *   GET|HEAD ?action=executions[&tplan_id=N]
 *          [&with_notes=1|0]                  -> execution rows (capped),
 *                                                newest first
 *
 * tplan_id falls back to $_SESSION['testplanID'] exactly like every other
 * plan-scoped BFF, so the ASIDE link needs no query string at all.
 *
 * Security (the #1697/#1792 ruling):
 *   - the same READ grant as api/execnotesreadonly / api/execnotes
 *     (exec_ro_access OR exec_edit_notes OR testplan_execute) is enforced on
 *     the plan's OWN test project - a picker is a notes oracle without it;
 *   - "no such plan" and "a plan you may not read" answer BYTE-IDENTICALLY
 *     (404 plan_not_found), so the endpoint is not a test-plan existence
 *     oracle; the refusal is audited (AUDIT, never WARNING - a denied user
 *     must not add Warning rows to the Event Viewer);
 *   - every parameter is is_scalar()-guarded BEFORE the (string) cast, so
 *     ?action[]=x / ?tplan_id[]=1 can never emit an "Array to string
 *     conversion" E_WARNING into the events table (Refs #1810);
 *   - the DB connect runs AFTER the session gate, so a down database answers
 *     401 to an anonymous caller instead of leaking dbms_msg (#1677).
 *
 * Session-based auth, JSON I/O. No Smarty.
 */
require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');

// Verb check BEFORE bffSameOriginGuard(), same reasoning as
// api/execnotesreadonly: this endpoint has no write path, so the guard would
// protect nothing while swallowing the documented 405 for a plain POST.
enp_require_safe_verb();

bffSameOriginGuard();

// is_scalar() first (Refs #1810): `?action[]=executions` would otherwise reach
// the (string) cast below and write an E_WARNING into the EVENTS TABLE - and
// this line runs BEFORE the session check, i.e. unauthenticated.
$actionArg = $_GET['action'] ?? 'init';
if (!is_scalar($actionArg)) {
    enp_fail(400, 'unknown_action', 'Unknown action');
}
$action = trim((string)$actionArg);
if ($action === '') {
    $action = 'init';
}
if ($action !== 'init' && $action !== 'executions') {
    enp_fail(400, 'unknown_action', 'Unknown action');
}

// ---------------------------------------------------------------------------
// Session gate BEFORE the database connect (#1677): an anonymous caller must
// get the JSON 401, never a raw dbms_msg from a failing doDBConnect().
// ---------------------------------------------------------------------------
$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    enp_fail(401, 'not_authenticated', 'Not authenticated');
}

// The connect runs only now: an anonymous caller has already been answered the
// JSON 401 above, so a down database can never leak dbms_msg to it (#1677).
$db = new database(DB_TYPE);
doDBConnect($db);

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    enp_fail(401, 'not_authenticated', 'Not authenticated');
}

if (function_exists('bffEnforceSession')) {
    bffEnforceSession($db);
}

header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');

// ---------------------------------------------------------------------------
// Refuse every write verb explicitly (405 + Allow contract).
// ---------------------------------------------------------------------------
function enp_require_safe_verb() {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method !== 'GET' && $method !== 'HEAD') {
        header('Allow: GET, HEAD');
        http_response_code(405);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array(
            'status' => 'error',
            'code' => 'method_not_allowed',
            'message' => 'This endpoint is read-only; use GET',
        ));
        exit;
    }
}

/**
 * Stable machine-coded JSON failure - the screen keys its state cards off
 * `code`, never off the English message.
 */
function enp_fail($status, $code, $message) {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        http_response_code($status);
    }
    echo json_encode(array(
        'status' => 'error',
        'code' => $code,
        'message' => $message,
    ));
    exit;
}

// ---------------------------------------------------------------------------
// Resolve the test plan: request first, session fallback.
// ---------------------------------------------------------------------------
$tplanArg = $_GET['tplan_id'] ?? null;
if ($tplanArg !== null && !is_scalar($tplanArg)) {
    enp_fail(400, 'invalid_tplan', 'A positive tplan_id is required');
}
if ($tplanArg !== null && trim((string)$tplanArg) !== '') {
    $tplanRaw = trim((string)$tplanArg);
    if (!preg_match('/^[0-9]+$/', $tplanRaw)) {
        enp_fail(400, 'invalid_tplan', 'A positive tplan_id is required');
    }
    $tplanId = intval($tplanRaw);
} else {
    $tplanId = isset($_SESSION['testplanID']) ? intval($_SESSION['testplanID']) : 0;
}
if ($tplanId <= 0) {
    // No plan selected anywhere: the screen renders its "select a test plan"
    // card from this code - NOT an access-denied card, because nothing was
    // denied yet.
    enp_fail(400, 'no_testplan', 'No test plan selected');
}

$tplanMgr = new testplan($db);
$planInfo = $tplanMgr->get_by_id($tplanId, array('output' => 'minimun'));
if (is_null($planInfo)) {
    // Unknown plan AND a plan the caller may not read are the SAME answer
    // (the #1697/#1792 oracle ruling).
    enp_fail(404, 'plan_not_found', 'Test plan not found');
}

$tprojectId  = intval($planInfo['tproject_id']);
$tplanName   = strval($planInfo['name']);
$tprojName   = strval($planInfo['tproject_name']);
$tprojPrefix = strval($planInfo['prefix']);

// Same READ grant as api/execnotesreadonly (Refs #1807) / api/execnotes
// (Refs #1551) so the picker, the read-only viewer and the editable popup can
// never disagree about who may read the notes.
$canRead  = $user->hasRight($db, 'exec_ro_access', $tprojectId, $tplanId)
    || $user->hasRight($db, 'exec_edit_notes', $tprojectId, $tplanId)
    || $user->hasRight($db, 'testplan_execute', $tprojectId, $tplanId);
$canEdit = ($user->hasRight($db, 'exec_edit_notes', $tprojectId, $tplanId) === 'yes');

if (!$canRead) {
    tLog('BFF: user ' . intval($userId) . ' refused the execution-notes picker for ' .
         'testplan ' . $tplanId . ' (testproject ' . $tprojectId . ') - no right',
         'AUDIT');
    // BYTE-IDENTICAL to the unknown-plan answer above: a role-3 caller must not
    // be able to enumerate which plan ids exist.
    enp_fail(404, 'plan_not_found', 'Test plan not found');
}

$execTables = tlObjectWithDB::getDBTables(array('executions', 'nodes_hierarchy',
                                                'tcversions', 'builds', 'platforms'));

// ---------------------------------------------------------------------------
// GET ?action=init — context for the picker page.
// ---------------------------------------------------------------------------
if ($action === 'init') {
    $allCount = enp_count($db, $execTables['executions'], $tplanId, false);
    $noteCount = enp_count($db, $execTables['executions'], $tplanId, true);

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array(
        'status' => 'ok',
        'context' => array(
            'tplan_id' => $tplanId,
            'tplan_name' => $tplanName,
            'tproject_id' => $tprojectId,
            'tproject_name' => $tprojName,
            'tproject_prefix' => $tprojPrefix,
            'total_executions' => $allCount,
            'with_notes' => $noteCount,
        ),
        'rights' => array(
            'can_read' => true,
            'can_edit' => $canEdit,
        ),
        'viewer_url' => '/gui/templates/execute/execNotesReadonly.html',
    ), JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// ---------------------------------------------------------------------------
// GET ?action=executions — the rows.
// ---------------------------------------------------------------------------
$withNotes = true;
if (isset($_GET['with_notes'])) {
    if (!is_scalar($_GET['with_notes'])) {
        enp_fail(400, 'invalid_parameter', 'with_notes must be 0 or 1');
    }
    $wn = trim((string)$_GET['with_notes']);
    if ($wn !== '0' && $wn !== '1') {
        enp_fail(400, 'invalid_parameter', 'with_notes must be 0 or 1');
    }
    $withNotes = ($wn === '1');
}

// One more row than the cap, so the screen can tell "exactly 500" from "there
// is more" without a second COUNT query.
$LIMIT = 500;

$sql = "/* api/execnotespicker */ " .
       " SELECT E.id, E.status, E.execution_ts, E.notes, E.tcversion_id, " .
       "        E.build_id, E.platform_id, " .
       "        NH_TC.id AS tcase_node, NH_TC.name AS tc_name, " .
       "        TV.tc_external_id AS tc_num, " .
       "        B.name AS build_name, P.name AS platform_name " .
       " FROM {$execTables['executions']} E " .
       " LEFT JOIN {$execTables['nodes_hierarchy']} NH_VER " .
       "        ON NH_VER.id = E.tcversion_id AND NH_VER.node_type_id = 4 " .
       " LEFT JOIN {$execTables['nodes_hierarchy']} NH_TC " .
       "        ON NH_TC.id = NH_VER.parent_id AND NH_TC.node_type_id = 3 " .
       " LEFT JOIN {$execTables['tcversions']} TV ON TV.id = E.tcversion_id " .
       " LEFT JOIN {$execTables['builds']} B ON B.id = E.build_id " .
       " LEFT JOIN {$execTables['platforms']} P ON P.id = E.platform_id " .
       " WHERE E.testplan_id = " . intval($tplanId);
if ($withNotes) {
    $sql .= " AND E.notes IS NOT NULL AND TRIM(E.notes) <> ''";
}
$sql .= " ORDER BY E.execution_ts IS NULL, E.execution_ts DESC, E.id DESC" .
        " LIMIT " . ($LIMIT + 1);

$rs = $db->get_recordset($sql);
$rows = ($rs && count($rs) > 0) ? $rs : array();
$truncated = (count($rows) > $LIMIT);
if ($truncated) {
    $rows = array_slice($rows, 0, $LIMIT);
}

// Suite path: batched ancestor walk (one IN-query per hierarchy LEVEL, never
// one query per row), then one path composition per distinct test case.
$parents = array();
foreach ($rows as $r) {
    if (intval($r['tcase_node']) > 0) {
        $parents[] = intval($r['tcase_node']);
    }
}
$ancMap = enp_ancestors($db, $execTables['nodes_hierarchy'], $parents);

$items = array();
foreach ($rows as $r) {
    $notesText = enp_notes_to_text(isset($r['notes']) ? (string)$r['notes'] : '');
    $preview = $notesText;
    if (function_exists('mb_substr')) {
        if (mb_strlen($preview, 'UTF-8') > 160) {
            $preview = mb_substr($preview, 0, 160, 'UTF-8') . '…';
        }
    } elseif (strlen($preview) > 160) {
        $preview = substr($preview, 0, 160) . '…';
    }

    $tcNode = intval($r['tcase_node']);
    $num = intval($r['tc_num']);
    $externalId = ($num > 0 && $tprojPrefix !== '') ? ($tprojPrefix . '-' . $num) : '';

    $items[] = array(
        'id' => intval($r['id']),
        'status_char' => isset($r['status']) ? (string)$r['status'] : '',
        'execution_ts' => isset($r['execution_ts']) ? (string)$r['execution_ts'] : '',
        'testcase_name' => isset($r['tc_name']) ? (string)$r['tc_name'] : '',
        'testcase_external_id' => $externalId,
        'suite_path' => ($tcNode > 0) ? enp_suite_path($tcNode, $ancMap) : '',
        'build_name' => isset($r['build_name']) ? (string)$r['build_name'] : '',
        'platform_name' => isset($r['platform_name']) ? (string)$r['platform_name'] : '',
        'has_notes' => ($notesText !== ''),
        'notes_preview' => $preview,
        'viewer_url' => '/gui/templates/execute/execNotesReadonly.html?exec_id=' .
                        intval($r['id']),
    );
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(array(
    'status' => 'ok',
    'tplan_id' => $tplanId,
    'with_notes' => $withNotes,
    'truncated' => $truncated,
    'count' => count($items),
    'executions' => $items,
), JSON_INVALID_UTF8_SUBSTITUTE);
exit;

/**
 * COUNT(*) of the plan's executions, optionally only the ones carrying notes.
 */
function enp_count(&$db, $execTable, $tplanId, $withNotesOnly) {
    $sql = "SELECT COUNT(*) AS cnt FROM {$execTable} WHERE testplan_id = " .
           intval($tplanId);
    if ($withNotesOnly) {
        $sql .= " AND notes IS NOT NULL AND TRIM(notes) <> ''";
    }
    $rs = $db->get_recordset($sql);
    return ($rs && count($rs) > 0) ? intval($rs[0]['cnt']) : 0;
}

/**
 * Load every ancestor (and the nodes themselves) of the given nodes with ONE
 * query per hierarchy level - the batched alternative to a per-row parent walk.
 *
 * Returns map id => array('name', 'parent_id', 'node_type_id').
 */
function enp_ancestors(&$db, $nhTable, $nodeIds) {
    $map = array();
    $seen = array();
    $frontier = array_values(array_unique(array_filter(array_map('intval', $nodeIds))));
    for ($level = 0; $level < 64 && count($frontier) > 0; $level++) {
        $ids = array();
        foreach ($frontier as $id) {
            if (!isset($seen[$id])) {
                $ids[] = $id;
                $seen[$id] = true;
            }
        }
        if (count($ids) === 0) {
            break;
        }
        $rs = $db->get_recordset(
            "SELECT id, name, parent_id, node_type_id FROM {$nhTable} " .
            "WHERE id IN (" . implode(',', $ids) . ")");
        $next = array();
        if ($rs) {
            foreach ($rs as $row) {
                $id = intval($row['id']);
                $map[$id] = array(
                    'name' => strval($row['name']),
                    'parent_id' => intval($row['parent_id']),
                    'node_type_id' => intval($row['node_type_id']),
                );
                $p = intval($row['parent_id']);
                if ($p > 0 && $p !== $id) {
                    $next[] = $p;
                }
            }
        }
        $frontier = $next;
    }
    return $map;
}

/**
 * "Outer suite / Inner suite" path of a test case node, composed from the
 * ancestor map. Stops at the project root (node_type_id = 1) exactly like the
 * walk in api/execnotesreadonly.
 */
function enp_suite_path($nodeId, $map) {
    $parts = array();
    $cur = $nodeId;
    for ($guard = 0; $guard < 64; $guard++) {
        if (!isset($map[$cur])) {
            break;
        }
        $n = $map[$cur];
        if ($n['node_type_id'] === 2) {
            array_unshift($parts, $n['name']);
        } elseif ($n['node_type_id'] === 1) {
            break;
        }
        $p = $n['parent_id'];
        if ($p <= 0 || $p === $cur) {
            break;
        }
        $cur = $p;
    }
    return implode(' / ', $parts);
}

/**
 * Flatten the stored RichEdit/HTML blob to plain text for the row preview.
 * Byte-for-byte the same normalisation order as
 * api/execnotesreadonly/enro_notes_to_text(), so the preview can never
 * disagree with what the viewer will show.
 */
function enp_notes_to_text($html) {
    $s = (string)$html;
    if (trim($s) === '') {
        return '';
    }
    $s = str_replace(array("\r\n", "\r"), "\n", $s);
    $s = preg_replace('#<br\s*/?>#i', "\n", $s);
    $s = preg_replace('#</(p|div|li|tr|h[1-6])\s*>#i', "\n", $s);
    $s = strip_tags($s);
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $bom = preg_replace('/^(\xEF\xBB\xBF|\xE2\x80\x8B)+/', '', $s);
    if ($bom !== null) {
        $s = $bom;
    }
    $s = preg_replace("/[ \t]+\n/", "\n", $s);
    $s = preg_replace("/\n{3,}/", "\n\n", $s);
    return trim($s);
}
