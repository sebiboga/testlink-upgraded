<?php
/**
 * Test Case / Test Suite Export BFF API
 * URL: /api/testcasesexport/
 *
 * Mirrors lib/testcases/tcExport.php (TestLink 1.9.20): generates the XML
 * export of one test case, a test suite (recursive optional), or a whole test
 * project, and streams it as a file download. Uses the very same legacy
 * testcase/testsuite class methods so the exported XML format is identical.
 *
 * Routes:
 *   GET  ?action=info  [&tproject_id=N][&testcase_id=N][&tcversion_id=N]
 *                     [&containerID=N][&useRecursion=0|1]
 *                     -> { tproject:{id,name}, mode, filename, types, grants }
 *   POST ?action=export (X-Requested-With: XMLHttpRequest)
 *                     -> streams XML attachment download (application/xml)
 *
 * Permission parity with legacy: tcExport.php performs no explicit right check
 * beyond an authenticated session (the export reads design data, like the rest
 * of the test specification). We keep that behaviour but expose the grants map
 * so the front-end can hide Export when the user lacks design access.
 *
 * Self-contained: does not depend on api/testcases/index.php.
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
require_once(__DIR__ . '/../../lib/functions/xml.inc.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json');

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

function out($data) { echo json_encode($data); exit; }

function getIntParam($key, $default = 0) {
    $v = $_REQUEST[$key] ?? $default;
    return is_numeric($v) ? intval($v) : $default;
}

/**
 * Count direct children of a node that are "exportable" containers/cases.
 * Mirrors the legacy $check_children logic in lib/testcases/tcExport.php,
 * which uses $tree_mgr->get_children() excluding testplan/requirement_spec/
 * requirement nodes.
 */
function countExportChildren($dbHandler, $nodeId) {
    $tables = tlObjectWithDB::getDBTables(array('nodes_hierarchy', 'node_types'));
    $rows = $dbHandler->get_recordset(
        "SELECT nt.description AS t FROM {$tables['nodes_hierarchy']} nh " .
        "JOIN {$tables['node_types']} nt ON nh.node_type_id = nt.id " .
        "WHERE nh.parent_id = " . intval($nodeId));
    if (is_null($rows)) {
        return 0;
    }
    $excl = array('testplan', 'requirement_spec', 'requirement',
                  'requirement_version', 'testcase_version',
                  'requirement_revision', 'requirement_spec_revision',
                  'build', 'platform');
    $c = 0;
    foreach ($rows as $r) {
        $t = isset($r['t']) ? $r['t'] : (isset($r['description']) ? $r['description'] : '');
        if ($t !== '' && !in_array($t, $excl, true)) {
            $c++;
        }
    }
    return $c;
}

/**
 * Resolve the test project id from an explicit param, else from the session.
 */
function resolveTprojectId() {
    $id = getIntParam('tproject_id');
    if ($id <= 0) {
        $id = intval($_SESSION['testprojectID'] ?? 0);
    }
    return $id;
}

$action = $_REQUEST['action'] ?? '';

$treeMgr = new tree($db);
$tcaseMgr = new testcase($db);
$tsuiteMgr = new testsuite($db);
$tprojectMgr = new testproject($db);

// ---------------------------------------------------------------------------
// GET ?action=info
// Context for the modernized export screen: names, mode, default filename,
// supported types and the user's grants.
// ---------------------------------------------------------------------------
if ($action === 'info') {
    $tprojectId = resolveTprojectId();
    if ($tprojectId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test project id']);
    }

    $tproj = $tprojectMgr->get_by_id($tprojectId);
    if (!$tproj) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Test project not found']);
    }
    if (is_object($tproj)) {
        $tprojectName = strval($tproj->name ?? '');
    } else {
        $tprojectName = strval($tproj['name'] ?? '');
    }

    $tcaseId = getIntParam('testcase_id');
    $tcversionId = getIntParam('tcversion_id');
    $containerId = getIntParam('containerID');
    $useRecursion = getIntParam('useRecursion');

    // Determine export mode mirroring initializeGui() in lib/testcases/tcExport.php
    $oneTestCase = ($tcaseId > 0 && $tcversionId > 0);
    $mode = 'testcase';
    $filename = '';
    $pageTitle = 'title_tc_export';
    $nothingTodo = '';

    if ($useRecursion) {
        // testsuite(deep) or full testproject export
        $nodeInfo = $treeMgr->get_node_hierarchy_info($containerId > 0 ? $containerId : $tprojectId);
        $name = is_null($nodeInfo) ? '' : $nodeInfo['name'];
        $mode = ($containerId <= 0 || $containerId === $tprojectId) ? 'project' : 'testsuite';
        $filename = $name . '.' . ($mode === 'project' ? 'testproject-deep' : 'testsuite-deep') . '.xml';
        $pageTitle = $mode === 'project' ? 'title_tsuite_export_all' : 'title_tsuite_export';
        if (countExportChildren($db, $containerId > 0 ? $containerId : $tprojectId) === 0) {
            $nothingTodo = $mode === 'project' ? 'tcx.noTestsuitesToExport' : 'tcx.noTestsuitesToExport';
        }
    } elseif ($oneTestCase) {
        $tcinfo = $tcaseMgr->get_by_id($tcaseId, $tcversionId, null, array('output' => 'essential'));
        if (!is_null($tcinfo) && count($tcinfo) > 0) {
            $filename = $tcinfo[0]['name'] . '.version' . $tcinfo[0]['version'] . '.testcase.xml';
        }
        $pageTitle = 'title_tc_export';
    } else {
        $mode = 'suite_tc';
        $nodeInfo = $treeMgr->get_node_hierarchy_info($containerId);
        $name = is_null($nodeInfo) ? '' : $nodeInfo['name'];
        $filename = $name . '.testsuite-children-testcases.xml';
        $pageTitle = 'title_tc_export_all';
        if (countExportChildren($db, $containerId) === 0) {
            $nothingTodo = 'tcx.noTestcasesToExport';
        }
    }

    // Expose legacy export types (currently only XML).
    $types = $tcaseMgr->get_export_file_types();

    // Resolve the test case display name (for the default filename).
    // get_by_id() returns an array of rows; each row has 'name'/'version'.
    $tcaseName = '';
    $tcaseVersion = '';
    if ($oneTestCase) {
        $tcinfo = $tcaseMgr->get_by_id($tcaseId, $tcversionId, null, array('output' => 'essential'));
        if (!is_null($tcinfo) && count($tcinfo) > 0) {
            $tcaseName = strval($tcinfo[0]['name'] ?? '');
            $tcaseVersion = strval($tcinfo[0]['version'] ?? '');
        }
    }

    // Permissions: legacy tcExport.php only requires an authenticated session.
    // Surface design-access grant so the UI can hide Export without it.
    $grants = array(
        'mgt_modify_tc' => $user->hasRight($db, 'mgt_modify_tc', $tprojectId) ? 1 : 0,
    );

    out(array(
        'status' => 'ok',
        'tproject' => array(
            'id' => intval($tprojectId),
            'name' => $tprojectName,
        ),
        'mode' => $mode,
        'oneTestCase' => $oneTestCase ? 1 : 0,
        'useRecursion' => $useRecursion ? 1 : 0,
        'testcase_id' => $tcaseId,
        'tcversion_id' => $tcversionId,
        'containerID' => $containerId,
        'tcase' => array('name' => $tcaseName, 'version' => $tcaseVersion),
        'filename' => $filename,
        'pageTitle' => $pageTitle,
        'nothingTodo' => $nothingTodo,
        'types' => $types,
        'grants' => $grants,
    ));
}

// ---------------------------------------------------------------------------
// POST ?action=export
// Generate the XML export and stream it as a file download.
// ---------------------------------------------------------------------------
if ($action === 'export') {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method !== 'POST') {
        http_response_code(405);
        out(['status' => 'error', 'message' => 'Method not allowed']);
    }

    $tprojectId = resolveTprojectId();
    if ($tprojectId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test project id']);
    }

    $tcaseId = getIntParam('testcase_id');
    $tcversionId = getIntParam('tcversion_id');
    $containerId = getIntParam('containerID');
    $useRecursion = getIntParam('useRecursion');
    $oneTestCase = ($tcaseId > 0 && $tcversionId > 0);

    $exportType = strtoupper(trim($_REQUEST['exportType'] ?? 'XML'));

    // Option flags, mirroring init_args() in lib/testcases/tcExport.php
    $flag = function ($key) {
        return isset($_REQUEST[$key]) ? intval($_REQUEST[$key]) : 0;
    };
    $optExport = array(
        'REQS' => $flag('exportReqs'),
        'CFIELDS' => $flag('exportCFields'),
        'KEYWORDS' => $flag('exportKeywords'),
        'EXTERNALID' => $flag('exportTestCaseExternalID'),
        'ADDPREFIX' => $flag('exportTestCaseExternalID') ? $flag('addPrefix') : 0,
        'RECURSIVE' => $useRecursion,
        'TCSUMMARY' => $flag('exportTCSummary'),
        'TCPRECONDITIONS' => $flag('exportTCPreconditions'),
        'ATTACHMENTS' => $flag('exportAttachments'),
        'TCSTEPS' => $flag('exportTCSteps'),
    );

    // Filename: explicit override or default from the export context.
    $exportFilename = trim((string)($_REQUEST['export_filename'] ?? ''));
    if ($exportFilename === '') {
        if ($useRecursion) {
            $nodeInfo = $treeMgr->get_node_hierarchy_info($containerId > 0 ? $containerId : $tprojectId);
            $name = is_null($nodeInfo) ? 'export' : $nodeInfo['name'];
            $suffix = ($containerId <= 0 || $containerId === $tprojectId)
                ? '.testproject-deep.xml' : '.testsuite-deep.xml';
            $exportFilename = $name . $suffix;
        } elseif ($oneTestCase) {
            $tcinfo = $tcaseMgr->get_by_id($tcaseId, $tcversionId, null, array('output' => 'essential'));
            if (!is_null($tcinfo) && count($tcinfo) > 0) {
                $exportFilename = $tcinfo[0]['name'] . '.version' . $tcinfo[0]['version'] . '.testcase.xml';
            } else {
                $exportFilename = 'testcase.xml';
            }
        } else {
            $nodeInfo = $treeMgr->get_node_hierarchy_info($containerId);
            $name = is_null($nodeInfo) ? 'export' : $nodeInfo['name'];
            $exportFilename = $name . '.testsuite-children-testcases.xml';
        }
    }

    // Safety: keep the filename a plain basename to avoid path injection.
    $exportFilename = basename($exportFilename);

    $content = '';
    if ($exportType === 'XML') {
        if ($oneTestCase) {
            $optExport['RELATIONS'] = true;
            $optExport['ROOTELEM'] = "<testcases>{{XMLCODE}}</testcases>";
            $content = $tcaseMgr->exportTestCaseDataToXML(
                $tcaseId, $tcversionId, $tprojectId, null, $optExport);
        } else {
            $optExp = $optExport;
            if (getIntParam('exportSkel')) {
                $optExp['skeleton'] = 1;
            }
            $content = TL_XMLEXPORT_HEADER;
            $content .= $tsuiteMgr->exportTestSuiteDataToXML($containerId, $tprojectId, $optExp);
        }
    }

    if ($content === '') {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Nothing to export for the given selection']);
    }

    // Override the JSON header set above and stream the XML as an attachment.
    if (!headers_sent()) {
        header('Content-Type: application/xml; charset=utf-8; name=' . $exportFilename);
        header('Content-Transfer-Encoding: BASE64;');
        header('Content-Disposition: attachment; filename="' . $exportFilename . '"');
        header('Pragma: public');
        header('Cache-Control: must-revalidate');
    }
    echo $content;
    exit;
}

http_response_code(404);
out(['status' => 'error', 'message' => 'Unknown action']);
