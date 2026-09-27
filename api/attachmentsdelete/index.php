<?php
/**
 * Attachment Delete BFF API
 * URL: /api/attachmentsdelete/
 * Plain PHP, no framework, no compilation
 *
 * Modern twin of lib/attachments/attachmentdelete.php +
 * gui/templates/dashio/attachments/attachmentdelete.tpl (TestLink 1.9.20).
 *
 * Legacy behaviour ported:
 *   - testlinkInitPage($db,false,false,'checkRights') -> checkRights() only
 *     asserts config_get('attachments')->enabled, so the 403 gate is kept
 *     verbatim.
 *   - the delete only ran when checkAttachmentID() accepted the id, i.e. when
 *     the id was present in the session allow-list
 *     $_SESSION['s_lastAttachmentInfos'] that attachments.inc.php fills while
 *     listing the attachments of an object. A deep link / a fresh session
 *     therefore never deleted anything and the tpl rendered
 *     'error_attachment_delete'. That ownership proof is preserved here
 *     (code ATTACHMENT_NOT_ALLOWED) and can additionally be satisfied by
 *     passing the owning table + id explicitly.
 *   - deletion itself is $repo->deleteAttachment() plus the
 *     audit_attachment_deleted audit event (deleteAttachment() does both).
 *
 * Hardening vs legacy: the legacy page deleted on GET with a bare ?id=, so a
 * single click anywhere in the app was destructive and a crafted link was
 * enough to remove an attachment of any object. Here the delete requires an
 * authenticated session, an explicit POST behind bffSameOriginGuard() and an
 * id that matches the object in the URL (or the session allow-list).
 * NOT a rights check: like the legacy page it does not verify that the user may
 * touch the owning object - that gap is shared with api/attachments and tracked
 * in the follow-up filed with this commit.
 *
 * JSON contract:
 *   GET  ?action=init&id=N[&table=T][&fk_id=M] -> attachment + owner context
 *   POST ?action=delete  id=N[&table=T][&fk_id=M]
 *   401 anonymous, 403 attachments disabled / not allowed, 400 bad id or
 *   unknown action, 404 unknown attachment, 405 wrong verb, 500 guarded.
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

$db = new database(DB_TYPE);
doDBConnect($db);

/**
 * @param array $data
 * @param int   $code
 */
function bffAdOut($data, $code = 200) {
    // JSON_INVALID_UTF8_SUBSTITUTE: a legacy DB can hold a latin1 attachment
    // title; without it json_encode() returns false and the client parses an
    // empty body as a generic error. Same header contract as api/_guard.php.
    $json = json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE);
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
    }
    echo ($json === false) ? '{"status":"error","code":"ENCODING_FAILED"}' : $json;
    exit;
}

$action = trim(strval($_GET['action'] ?? ($_POST['action'] ?? '')));
$method = strtoupper(strval($_SERVER['REQUEST_METHOD'] ?? 'GET'));

// ---- session authentication (the legacy page got it from testlinkInitPage) ----
// Mandatory: without it an anonymous visitor who guesses a valid (table, fk_id)
// pair could delete any attachment. Fail closed.
$userId = intval($_SESSION['userID'] ?? 0);
if ($userId <= 0) {
    bffAdOut([
        'status' => 'error',
        'code'   => 'NOT_AUTHENTICATED',
        'message' => 'Not authenticated',
    ], 401);
}
$currentUser = tlUser::getByID($db, $userId);
if (is_null($currentUser)) {
    bffAdOut([
        'status' => 'error',
        'code'   => 'NOT_AUTHENTICATED',
        'message' => 'User not found',
    ], 401);
}
// legacy checkSessionValid() parity (see api/_guard.php, issue #1614)
bffEnforceSession($db);

/** Legacy checkRights() of lib/attachments/attachmentdelete.php. */
function bffAdEnabled() {
    return (bool)config_get('attachments')->enabled;
}

/**
 * The session allow-list that attachments.inc.php maintains while listing the
 * attachments of an object (legacy checkAttachmentID()).
 */
function bffAdInSessionAllowList($id) {
    $list = $_SESSION['s_lastAttachmentInfos'] ?? null;
    if (!is_array($list)) {
        return false;
    }
    foreach ($list as $info) {
        if (is_array($info) && intval($info['id'] ?? 0) === intval($id)) {
            return true;
        }
    }
    return false;
}

/**
 * Cached column probe. This fork ships a slimmed-down nodes_hierarchy /
 * executions schema (no tc_external_id, no testproject_id and no tcversion_id
 * on executions), so the owner-label queries must be built defensively: an
 * unknown column raises a DB error page (get_recordset() dies) instead of
 * degrading to the plain '<table> #<id>' label.
 */
function bffAdHasColumn($db, $table, $col) {
    static $cache = array();
    $key = $table . '.' . $col;
    if (!isset($cache[$key])) {
        $cache[$key] = false;
        // NB: no LIKE - '_' is a single-char wildcard there, so 'tc_id' would
        // also match 'tcc_id' and the caller would then query a column that does
        // not exist. Compare the returned Field name instead.
        $rows = $db->get_recordset("SHOW COLUMNS FROM " . $table);
        if (is_array($rows)) {
            foreach ($rows as $r) {
                if (strcasecmp(strval($r['Field'] ?? ''), $col) === 0) {
                    $cache[$key] = true;
                    break;
                }
            }
        }
    }
    return $cache[$key];
}

/**
 * Best-effort human label of the entity owning the attachment, so the popup can
 * state what is being deleted. Unknown tables fall back to '<table> #<id>'.
 */
function bffAdOwnerLabel($db, $attachInfo) {
    $fkTable = strval($attachInfo['fk_table'] ?? '');
    $fkId = intval($attachInfo['fk_id'] ?? 0);
    $fallback = $fkTable . ' #' . $fkId;
    if ($fkId <= 0) {
        return $fallback;
    }
    $t = tlObjectWithDB::getDBTables(
        ['executions', 'testplans', 'testprojects', 'builds', 'nodes_hierarchy']);
    $nh = $t['nodes_hierarchy'];
    $hasExtId = bffAdHasColumn($db, 'nodes_hierarchy', 'tc_external_id');

    if ($fkTable === 'nodes_hierarchy') {
        // nodes_hierarchy holds EVERY kind of container (test project 1, test
        // suite 2, test case 3, build 4, test plan 5), so the node type decides
        // the wording: calling a test suite "Test case" is wrong. The test case
        // name is not reachable from the node in this fork (no
        // testcase_tsuite, and tcversions has no name column), so the node name
        // is the best human label available.
        $rows = $db->get_recordset("SELECT name, node_type_id FROM {$nh} " .
            "WHERE id = " . $fkId . " LIMIT 1");
        if (is_array($rows) && count($rows) > 0) {
            $nm = trim(strval($rows[0]['name'] ?? ''));
            return $fkId . ($nm !== '' ? ' - ' . $nm : '');
        }
        return $fallback;
    }

    if ($fkTable === 'executions') {
        $cols = 'e.id AS exec_id';
        $extra = '';
        if (bffAdHasColumn($db, 'executions', 'tcversion_id') && $hasExtId) {
            $cols .= ', ntc.tc_external_id AS tcext';
            $extra = "LEFT JOIN {$nh} ntc ON e.tcversion_id = ntc.parent_id ";
        }
        $rows = $db->get_recordset("SELECT $cols FROM {$t['executions']} e " .
            $extra . "WHERE e.id = " . $fkId . " LIMIT 1");
        if (is_array($rows) && count($rows) > 0) {
            $ref = strval($rows[0]['tcext'] ?? '');
            return intval($rows[0]['exec_id']) .
                ($ref !== '' ? ' - ' . $ref : '');
        }
        return $fallback;
    }

    if ($fkTable === 'testplans' || $fkTable === 'testprojects' ||
        $fkTable === 'builds') {
        // Upstream keeps the human name in the table, this fork keeps the plan /
        // project name in nodes_hierarchy - probe instead of assuming.
        if (bffAdHasColumn($db, $fkTable, 'name')) {
            $rows = $db->get_recordset("SELECT name FROM {$t[$fkTable]} " .
                "WHERE id = " . $fkId . " LIMIT 1");
            if (is_array($rows) && count($rows) > 0) {
                $nm = trim(strval($rows[0]['name'] ?? ''));
                return ($nm !== '' ? $nm . ' #' : '#') . $fkId;
            }
        }
        $rows = $db->get_recordset("SELECT name FROM {$nh} " .
            "WHERE id = " . $fkId . " LIMIT 1");
        if (is_array($rows) && count($rows) > 0) {
            $nm = trim(strval($rows[0]['name'] ?? ''));
            if ($nm !== '') {
                return $nm . ' #' . $fkId;
            }
        }
    }
    return $fallback;
}

/**
 * Read + validate the target. Returns the attachment row on success, exits
 * with the proper JSON status otherwise.
 *
 * @return array
 */
function bffAdLoad($db) {
    if (!bffAdEnabled()) {
        bffAdOut([
            'status' => 'error',
            'code'   => 'ATTACHMENTS_DISABLED',
            'message' => 'Attachments are disabled on this installation',
        ], 403);
    }

    $id = intval($_GET['id'] ?? ($_POST['id'] ?? 0));
    if ($id <= 0) {
        bffAdOut([
            'status' => 'error',
            'code'   => 'INVALID_ID',
            'message' => 'Invalid attachment id',
        ], 400);
    }

    $repo = tlAttachmentRepository::create($db);
    // getAttachmentInfo() returns the FLAT info hash of the single attachment
    // (id / fk_id / fk_table / title / file_name / ...), not a row list.
    $info = $repo->getAttachmentInfo($id);
    if (!is_array($info) || count($info) === 0) {
        bffAdOut([
            'status' => 'error',
            'code'   => 'ATTACHMENT_NOT_FOUND',
            'message' => 'Attachment not found',
        ], 404);
    }

    // Ownership proof: either the caller states the owning object and it
    // matches, or the id is in the session allow-list filled by the
    // attachments list (legacy checkAttachmentID()).
    $givenTable = trim(strval($_GET['table'] ?? ($_POST['table'] ?? '')));
    $givenFkId = intval($_GET['fk_id'] ?? ($_POST['fk_id'] ?? 0));
    $realTable = str_replace(DB_TABLE_PREFIX, '', strval($info['fk_table'] ?? ''));
    $allowed = false;
    if ($givenTable !== '' && $givenFkId > 0) {
        $allowed = ($givenTable === $realTable &&
                    $givenFkId === intval($info['fk_id'] ?? 0));
    }
    if (!$allowed) {
        $allowed = bffAdInSessionAllowList($id);
    }
    if (!$allowed) {
        bffAdOut([
            'status' => 'error',
            'code'   => 'ATTACHMENT_NOT_ALLOWED',
            'message' => 'Attachment does not belong to the object in context',
        ], 403);
    }

    return $info;
}

if ($action === 'init') {
    if ($method !== 'GET') {
        bffAdOut([
            'status' => 'error',
            'code'   => 'METHOD_NOT_ALLOWED',
            'message' => 'init is a GET action',
        ], 405);
    }
    $info = bffAdLoad($db);
    $realTable = str_replace(DB_TABLE_PREFIX, '', strval($info['fk_table'] ?? ''));
    bffAdOut([
        'status' => 'ok',
        'action' => 'init',
        'attachment' => [
            'id'          => intval($info['id']),
            'title'       => strval($info['title'] ?? ''),
            'file_name'   => strval($info['file_name'] ?? ''),
            'file_type'   => strval($info['file_type'] ?? ''),
            'file_size'   => intval($info['file_size'] ?? 0),
            'date_added'  => strval($info['date_added'] ?? ''),
            'description' => strval($info['description'] ?? ''),
            'owner_table' => $realTable,
            'owner_id'    => intval($info['fk_id'] ?? 0),
            'owner_label' => bffAdOwnerLabel($db, $info),
        ],
        'download_url' => '/api/attachments/index.php?action=download&id=' .
            intval($info['id']),
        'legacy_code'  => 'ADEL-01',
    ]);
}

if ($action === 'delete') {
    if ($method !== 'POST') {
        bffAdOut([
            'status' => 'error',
            'code'   => 'METHOD_NOT_ALLOWED',
            'message' => 'delete requires POST',
        ], 405);
    }
    $info = bffAdLoad($db);
    $id = intval($info['id']);
    $title = strval($info['title'] ?? '');
    try {
        $repo = tlAttachmentRepository::create($db);
        $done = $repo->deleteAttachment($id, $info);
        // tlAttachmentRepository::deleteAttachment() also unlinks the file from
        // the filesystem repository and returns tl::ERROR when that unlink
        // fails, even though the DB row IS gone (file already removed by hand,
        // repository folder missing, ...). What the user cares about is whether
        // the attachment still exists, so confirm that before reporting failure.
        if (!$done) {
            $still = $repo->getAttachmentInfo($id);
            $done = (is_null($still) || count($still) === 0);
        }
    } catch (Throwable $e) {
        logAuditEvent('attachmentdelete failed: ' . $e->getMessage(),
            'ERROR', $id, 'attachments');
        bffAdOut([
            'status' => 'error',
            'code'   => 'DELETE_FAILED',
            'message' => 'Attachment delete failed',
        ], 500);
    }
    if (!$done) {
        bffAdOut([
            'status' => 'error',
            'code'   => 'DELETE_FAILED',
            'message' => 'Attachment delete failed',
            'legacy_code' => 'ADEL-02',
        ], 500);
    }
    logAuditEvent(TLS('audit_attachment_deleted', $title), 'DELETE', $id, 'attachments');
    bffAdOut([
        'status' => 'ok',
        'action' => 'delete',
        'deleted_id' => $id,
        'title'  => $title,
        'legacy_code' => 'ADEL-03',
    ]);
}

bffAdOut([
    'status' => 'error',
    'code'   => 'UNKNOWN_ACTION',
    'message' => 'Unknown action',
], 400);
