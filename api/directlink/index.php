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
 * Refs #1542: completes the gateway — the remaining item types
 * item=reqspec|testcase|testsuite (still routed by linkto.php into the legacy
 * inner-frame shell: reqSpecListTree.php / listTestCases.php / reqSpecView.php
 * / archiveData.php) are resolved here too and forwarded to the modern viewers
 * reqSpecView.html / tcView.html / suiteView.html.
 *
 * Route:
 *   GET ?action=resolve&tprojectPrefix=X&item=req|reqspec|testcase|testsuite[&id=][&version=N]
 *     200 {status, tproject_id, tproject_name, tproject_prefix, item, ...item fields, href, grant}
 *     401 anonymous session
 *     403 user without mgt_view_req (req/reqspec) or mgt_view_tc (testcase/testsuite)
 *         on the owning project
 *     400 missing/malformed params or unsupported item
 *     404 unknown test project prefix / item id / version
 *     405 non-GET verb
 *     500 legacy-layer throw
 *
 * Item resolution mirrors legacy linkto.php process_* parity:
 *   - req       : requirement_mgr::getByDocID -> reqView.html?id=<req_id>
 *   - reqspec   : requirement_spec_mgr::getByDocID -> reqSpecView.html?id=<spec_id>
 *   - testcase  : testcase::getInternalID (external "PREFIX-N" id) -> tcView.html?tcase_id=<id>
 *   - testsuite : numeric node id verified as testsuite of the project -> suiteView.html?id=<id>
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

/* node_type id by description, read once from node_types (suiteview BFF parity) */
function dlNodeTypeIds()
{
    global $db, $tables;
    $map = [];
    $rows = $db->get_recordset("SELECT id, description FROM {$tables['node_types']}");
    if (!is_null($rows)) {
        foreach ($rows as $r) {
            $map[trim($r['description'])] = intval($r['id']);
        }
    }
    return $map;
}

/* owning test project of a tree node (walk parent chain to a testproject root) */
function dlOwningProject($nodeId, $types)
{
    global $db, $tables;
    $tprojectTypeId = isset($types['testproject']) ? $types['testproject'] : 6;
    $nodeId = intval($nodeId);
    $seen = 0;
    while ($nodeId > 0 && $seen++ < 1000) {
        $row = $db->get_recordset(
            "SELECT id, parent_id, node_type_id FROM {$tables['nodes_hierarchy']} " .
            "WHERE id = {$nodeId} LIMIT 1");
        if (is_null($row)) {
            return 0;
        }
        $row = current($row);
        if (intval($row['node_type_id']) === $tprojectTypeId) {
            return intval($row['id']);
        }
        $nodeId = intval($row['parent_id']);
    }
    return 0;
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

$supportedItems = ['req', 'reqspec', 'testcase', 'testsuite'];
if (($prefix === '' && $tprojId <= 0) || $item === '' || $docId === '') {
    http_response_code(400);
    dlOut(['status' => 'error', 'message' => 'Missing tprojectPrefix (or tproject_id), item or id']);
}
if (!in_array($item, $supportedItems, true)) {
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

// Right gate mirrors legacy linkto.php checkTestProject(): req/reqspec use
// mgt_view_req, testcase/testsuite use mgt_view_tc — ALWAYS on the owning
// project (NOT the session-context project).
$neededRight = in_array($item, ['testcase', 'testsuite'], true) ? 'mgt_view_tc' : 'mgt_view_req';
if (!$user->hasRight($db, $neededRight, $tprojectId)) {
    http_response_code(403);
    dlOut(['status' => 'error', 'message' => sprintf('No permission to view %s in this project', $item)]);
}

$base = [
    'status' => 'ok',
    'tproject_id' => $tprojectId,
    'tproject_name' => is_array($tprojectData) ? (string)($tprojectData['name'] ?? '') : '',
    'tproject_prefix' => is_array($tprojectData) ? (string)($tprojectData['prefix'] ?? '') : '',
    'item' => $item,
    'title' => '',
    'version' => 0,
    'version_id' => 0,
    'revision' => 0,
];

switch ($item) {
    case 'req':
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
        $pinnedVersionId = null;
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
            $pinnedVersionId = intval($versionId);
        }

        // Card enrichment: use the PINNED version when one was requested (legacy
        // process_req parity — metadata + viewer link must match that version), else
        // the latest version.
        $itemRow = is_null($pinnedVersionId) || is_null($vreq) ? null : $vreq;
        if (is_null($itemRow)) {
            try {
                $latestRows = $reqMgr->get_by_id($reqId);
            } catch (Exception $e) {
                http_response_code(500);
                dlOut(['status' => 'error', 'message' => 'Requirements service error']);
            }
            $itemRow = is_null($latestRows) ? null : current($latestRows);
        }

        $href = '/gui/templates/requirements/reqView.html?id=' . $reqId . '&tproject_id=' . $tprojectId;
        if (!is_null($pinnedVersionId)) {
            $href .= '&req_version_id=' . $pinnedVersionId;
        }

        $base['req_id'] = $reqId;
        $base['req_doc_id'] = $docId;
        $base['title'] = is_array($itemRow) ? (string)($itemRow['title'] ?? '') : '';
        $base['version'] = is_array($itemRow) ? intval($itemRow['version'] ?? 0) : 0;
        $base['version_id'] = is_array($itemRow) ? intval($itemRow['version_id'] ?? 0) : 0;
        $base['revision'] = is_array($itemRow) ? intval($itemRow['revision'] ?? 0) : 0;
        $base['href'] = $href;
        $base['grant'] = [
            'req_mgmt' => $user->hasRight($db, 'mgt_modify_req', $tprojectId),
            'tc_mgmt' => $user->hasRight($db, 'mgt_modify_tc', $tprojectId),
        ];
        dlOut($base);
        break;

    case 'reqspec':
        // Legacy process_reqspec parity: resolve doc-id to the spec node.
        try {
            $reqSpecMgr = new requirement_spec_mgr($db);
            $rows = $reqSpecMgr->getByDocID($docId, $tprojectId);
        } catch (Exception $e) {
            http_response_code(500);
            dlOut(['status' => 'error', 'message' => 'Requirements service error']);
        }
        $spec = is_null($rows) ? null : current($rows);
        if (is_null($spec) || intval($spec['id'] ?? 0) <= 0) {
            http_response_code(404);
            dlOut([
                'status' => 'error',
                'message' => sprintf('Requirement spec %s not found', $docId),
            ]);
        }
        $base['spec_id'] = intval($spec['id']);
        $base['spec_doc_id'] = $docId;
        $base['title'] = (string)($spec['title'] ?? '');
        $base['revision'] = intval($spec['revision'] ?? 0);
        $base['href'] = '/gui/templates/requirements/reqSpecView.html?id=' .
            intval($spec['id']) . '&tproject_id=' . $tprojectId;
        $base['grant'] = [
            'req_mgmt' => $user->hasRight($db, 'mgt_modify_req', $tprojectId),
        ];
        dlOut($base);
        break;

    case 'testcase':
        // Legacy process_testcase parity: the id is the EXTERNAL id ("PREFIX-N")
        // resolved to the internal testcase id by testcase::getInternalID.
        try {
            $tcaseMgr = new testcase($db);
            $glue = config_get('testcase_cfg')->glue_character;
            $tcaseId = $tcaseMgr->getInternalID($docId, [
                'glue' => $glue,
                'tproject_id' => $tprojectId,
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            dlOut(['status' => 'error', 'message' => 'Test case service error']);
        }
        if (intval($tcaseId) <= 0) {
            http_response_code(404);
            dlOut([
                'status' => 'error',
                'message' => sprintf('Test case %s not found', $docId),
            ]);
        }
        // Card enrichment: latest tcversion row (name + external id + version).
        $tcv = $db->get_recordset(
            "SELECT TCV.name, TCV.tc_external_id, TCV.version " .
            "FROM {$tables['tcversions']} TCV " .
            "JOIN {$tables['nodes_hierarchy']} NH ON TCV.id = NH.id " .
            "WHERE NH.parent_id = " . intval($tcaseId) .
            " ORDER BY TCV.version DESC LIMIT 1");
        $tcv = is_null($tcv) ? null : current($tcv);
        $base['tcase_id'] = intval($tcaseId);
        $base['external_id'] = $docId;
        $base['title'] = is_array($tcv) ? (string)($tcv['name'] ?? '') : '';
        $base['version'] = is_array($tcv) ? intval($tcv['version'] ?? 0) : 0;
        $base['href'] = '/gui/templates/testcases/tcView.html?tcase_id=' .
            intval($tcaseId) . '&tproject_id=' . $tprojectId;
        $base['grant'] = [
            'tc_mgmt' => $user->hasRight($db, 'mgt_modify_tc', $tprojectId),
        ];
        dlOut($base);
        break;

    case 'testsuite':
        // Legacy process_testsuite parity: the id is the numeric suite node id.
        $suiteId = intval($docId);
        if ($suiteId <= 0) {
            http_response_code(400);
            dlOut(['status' => 'error', 'message' => 'Invalid test suite id']);
        }
        $types = dlNodeTypeIds();
        $suiteRow = $db->get_recordset(
            "SELECT id, name, parent_id, node_type_id FROM {$tables['nodes_hierarchy']} " .
            "WHERE id = {$suiteId} LIMIT 1");
        $suiteRow = is_null($suiteRow) ? null : current($suiteRow);
        $tsuiteTypeId = isset($types['testsuite']) ? $types['testsuite'] : 2;
        if (is_null($suiteRow) || intval($suiteRow['node_type_id']) !== $tsuiteTypeId) {
            http_response_code(404);
            dlOut([
                'status' => 'error',
                'message' => sprintf('Test suite %d not found', $suiteId),
            ]);
        }
        if (dlOwningProject($suiteId, $types) !== $tprojectId) {
            http_response_code(404);
            dlOut([
                'status' => 'error',
                'message' => sprintf('Test suite %d not found in test project %s', $suiteId, $prefix),
            ]);
        }
        $base['suite_id'] = $suiteId;
        $base['title'] = (string)($suiteRow['name'] ?? '');
        $base['href'] = '/gui/templates/testcases/suiteView.html?id=' .
            $suiteId . '&tproject_id=' . $tprojectId;
        $base['grant'] = [
            'tc_mgmt' => $user->hasRight($db, 'mgt_modify_tc', $tprojectId),
        ];
        dlOut($base);
        break;
}