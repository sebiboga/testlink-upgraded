<?php
/**
 * Direct-Link Resolver BFF API
 * URL: /api/directlink/
 * Plain PHP, no framework, no compilation.
 *
 * Refs #1532: modern replacement for the legacy linkto.php deep-link gateway
 * (item=req). The modern Requirement Viewer (reqView.html) and Revision
 * Viewer (reqRevisionView.html) expose a "Direct link" button whose value was
 * linkto.php?tprojectPrefix=<prefix>&item=req&id=<doc_id>; anyone opening that
 * URL landed in the FULL legacy shell (legacy navBar + asideMenu +
 * lib/requirements/reqView.php). This BFF + gui/templates/links/directLink.html
 * resolve exactly the same deep link and forward the user to the modern
 * requirement viewer.
 *
 * Route:
 *   GET ?action=resolve&tprojectPrefix=X&item=req[&id=DOC_ID][&version=N]
 *     200 {status, tproject_id, tproject_name, req_id, req_doc_id, title,
 *          version, version_id, revision, href, grant}
 *     401 anonymous session
 *     403 user without mgt_view_req on the owning project
 *     400 missing/malformed params or unsupported item
 *     404 unknown test project prefix / requirement doc-id / version
 *     405 non-GET verb
 *     500 legacy-layer throw
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
require_once('requirements.inc.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || $userId <= 0) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'User not found']);
    exit;
}

function dlOut($data)
{
    echo json_encode($data);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET') {
    http_response_code(405);
    dlOut(['status' => 'error', 'message' => 'Method not allowed']);
}

$action = trim((string)($_GET['action'] ?? ''));
if ($action !== 'resolve') {
    http_response_code(400);
    dlOut(['status' => 'error', 'message' => 'Unknown or missing action']);
}

$prefix  = trim((string)($_GET['tprojectPrefix'] ?? ''));
$item    = trim((string)($_GET['item'] ?? ''));
$docId   = trim((string)($_GET['id'] ?? ''));
$version = trim((string)($_GET['version'] ?? ''));
$tprojId = intval($_GET['tproject_id'] ?? 0);

if (($prefix === '' && $tprojId <= 0) || $item === '' || $docId === '') {
    http_response_code(400);
    dlOut(['status' => 'error', 'message' => 'Missing tprojectPrefix (or tproject_id), item or id']);
}
if ($item !== 'req') {
    http_response_code(400);
    dlOut(['status' => 'error', 'message' => 'Unsupported item type: ' . $item]);
}

// Resolve the test project via its prefix (legacy linkto.php checkTestProject
// parity) OR trust an explicit tproject_id alias (used by screens that know
// the numeric id but not the prefix, e.g. reqRevisionView.html).
$tproject = new testproject($db);
if ($prefix !== '') {
    $tprojectData = $tproject->get_by_prefix($prefix);
    if (is_null($tprojectData)) {
        http_response_code(404);
        dlOut([
            'status' => 'error',
            'message' => sprintf('Test project %s not found', $prefix),
        ]);
    }
    $tprojectId = intval($tprojectData['id']);
} else {
    $tprojectData = $tproject->get_by_id($tprojId);
    if (is_null($tprojectData)) {
        http_response_code(404);
        dlOut([
            'status' => 'error',
            'message' => sprintf('Test project %d not found', $tprojId),
        ]);
    }
    $tprojectId = $tprojId;
}

// Right gate mirrors legacy linkto.php checkTestProject(): item=req uses
// mgt_view_req on the owning project (NOT the session-context project).
if (!$user->hasRight($db, 'mgt_view_req', $tprojectId)) {
    http_response_code(403);
    dlOut(['status' => 'error', 'message' => 'No permission to view requirements in this project']);
}

// Resolve the requirement doc-id (legacy process_req parity).
try {
    $reqMgr = new requirement_mgr($db);
    $rows = $reqMgr->getByDocID($docId, $tprojectId);
} catch (Exception $e) {
    http_response_code(500);
    dlOut(['status' => 'error', 'message' => 'Requirements service error']);
}
$req = is_null($rows) ? null : current($rows);
$reqId = is_null($req) ? null : $req['id'];

if (is_null($reqId)) {
    http_response_code(404);
    dlOut([
        'status' => 'error',
        'message' => sprintf('Requirement %s not found', $docId),
    ]);
}

// Optional explicit version validation (legacy process_req second step).
if ($version !== '' && is_numeric($version)) {
    try {
        $vreq = $reqMgr->get_by_id($reqId, null, intval($version));
    } catch (Exception $e) {
        http_response_code(500);
        dlOut(['status' => 'error', 'message' => 'Requirements service error']);
    }
    $vreq = is_null($vreq) ? null : current($vreq);
    $versionId = (!is_null($vreq) && intval($vreq['version'] ?? 0) === intval($version))
        ? $vreq['version_id'] : null;
    if (is_null($versionId)) {
        http_response_code(404);
        dlOut([
            'status' => 'error',
            'message' => sprintf('Requirement %s version %s not found', $docId, $version),
        ]);
    }
}

// Latest version used for the card enrichment.
try {
    $latestRows = $reqMgr->get_by_id($reqId);
} catch (Exception $e) {
    http_response_code(500);
    dlOut(['status' => 'error', 'message' => 'Requirements service error']);
}
$latest = is_null($latestRows) ? null : current($latestRows);

$href = '/gui/templates/requirements/reqView.html?id=' . $reqId . '&tproject_id=' . $tprojectId;

dlOut([
    'status' => 'ok',
    'tproject_id' => $tprojectId,
    'tproject_name' => is_array($tprojectData) ? (string)($tprojectData['name'] ?? '') : '',
    'tproject_prefix' => $prefix,
    'req_id' => $reqId,
    'req_doc_id' => $docId,
    'title' => is_array($latest) ? (string)($latest['title'] ?? '') : '',
    'version' => is_array($latest) ? intval($latest['version'] ?? 0) : 0,
    'version_id' => is_array($latest) ? intval($latest['version_id'] ?? 0) : 0,
    'revision' => is_array($latest) ? intval($latest['revision'] ?? 0) : 0,
    'href' => $href,
    'grant' => [
        'req_mgmt' => $user->hasRight($db, 'mgt_modify_req', $tprojectId),
    ],
]);