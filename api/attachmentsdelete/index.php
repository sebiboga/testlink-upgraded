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
 * enough to remove an attachment of any object. Here the delete is an explicit
 * POST behind bffSameOriginGuard() and the id must be proven to belong to the
 * object the caller is working on.
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

$action = trim(strval($_GET['action'] ?? ($_POST['action'] ?? '')));
$method = strtoupper(strval($_SERVER['REQUEST_METHOD'] ?? 'GET'));

/**
 * @param array $data
 * @param int   $code
 */
function bffAdOut($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

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
    try {
        if ($fkTable === 'nodes_hierarchy') {
            $rows = $db->get_recordset(
                "SELECT tcversions_tc.id AS tcid, tcversions.id AS tcv_id, " .
                "tcversions_tc.name AS tcname, tcversions_tc.testproject_id " .
                "FROM {$t['nodes_hierarchy']} tcversions_tc " .
                "INNER JOIN {$t['nodes_hierarchy']} tcversions " .
                "ON tcversions_tc.parent_id = tcversions.id " .
                "WHERE tcversions_tc.id = " . $fkId . " LIMIT 1");
            if (!is_null($rows) && count($rows) > 0) {
                $r = $rows[0];
                return 'Test case #' . intval($r['tcid']) . ' - ' .
                    strval($r['tcname'] ?? '') . ' (version ' .
                    intval($r['tcv_id']) . ')';
            }
            return $fallback;
        }
        if ($fkTable === 'executions') {
            $rows = $db->get_recordset(
                "SELECT e.id AS exec_id, e.testcase_id, ntc.tc_external_id, " .
                "e.status FROM {$t['executions']} e " .
                "LEFT JOIN {$t['testplans']} tp ON e.testplan_id = tp.id " .
                "LEFT JOIN {$t['nodes_hierarchy']} ntc ON e.tcversion_id = ntc.parent_id " .
                "WHERE e.id = " . $fkId . " LIMIT 1");
            if (!is_null($rows) && count($rows) > 0) {
                $r = $rows[0];
                $ref = strval($r['tc_external_id'] ?? '');
                return 'Execution #' . intval($r['exec_id']) .
                    ($ref !== '' ? ' - ' . $ref : '');
            }
            return $fallback;
        }
        if ($fkTable === 'testplans' || $fkTable === 'testprojects' ||
            $fkTable === 'builds') {
            $rows = $db->get_recordset(
                "SELECT name FROM {$t[$fkTable]} WHERE id = " . $fkId . " LIMIT 1");
            if (!is_null($rows) && count($rows) > 0) {
                return strval($rows[0]['name'] ?? '') . ' #' . $fkId;
            }
        }
    } catch (Throwable $e) {
        logAuditEvent('attachmentdelete owner lookup failed: ' . $e->getMessage(),
            'WARNING', $fkId, 'attachments');
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
    global $method;

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
    $info = $repo->getAttachmentInfo($id);
    if (is_null($info) || count($info) === 0) {
        bffAdOut([
            'status' => 'error',
            'code'   => 'ATTACHMENT_NOT_FOUND',
            'message' => 'Attachment not found',
        ], 404);
    }
    $info = $info[0];

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

    if ($method !== 'GET' && $method !== 'POST') {
        bffAdOut([
            'status' => 'error',
            'code'   => 'METHOD_NOT_ALLOWED',
            'message' => 'Method not allowed',
        ], 405);
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
