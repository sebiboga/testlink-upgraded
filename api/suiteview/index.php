<?php
/**
 * Test Suite Viewer BFF API
 * URL: /api/suiteview/index.php
 * Plain PHP, no framework.
 *
 * Mirrors the read-only "test suite" viewer of the legacy
 * lib/testcases/archiveData.php?edit=testsuite&id=<id> screen (TestLink
 * 1.9.20 containerView.tpl / tsuiteViewerRO.inc.tpl), opened as a popup from
 * gui/templates/search/searchAdvancedView.html (openTsEdit()).
 *
 * Endpoints (JSON out):
 *   GET ?action=info&id=<suite_id>&tproject_id=<pid>
 *       -> suite header + child suites count + linked test cases
 *          (latest ACTIVE version) + suite keywords + attachments
 *
 * Rights: legacy suite viewer requires read access to the owning test
 * project (mgt_view_tc). The owning project is resolved from the suite node
 * itself (walk up nodes_hierarchy), NOT from the session, because the popup
 * can be opened for a suite of a different project (system-wide search).
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json');

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || $userId <= 0) {
    http_response_code(401);
    echo json_encode(array('status' => 'error', 'message' => 'Not authenticated'));
    exit;
}

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    http_response_code(401);
    echo json_encode(array('status' => 'error', 'message' => 'User not found'));
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_REQUEST['action']) ? trim($_REQUEST['action']) : '';

function out($data) { echo json_encode($data); exit; }

$tables = tlObjectWithDB::getDBTables(
    array('nodes_hierarchy', 'node_types', 'testsuites', 'tcversions',
          'tcsteps', 'keywords', 'object_keywords', 'attachments'));

/**
 * node_type_id => canonical description, read once from node_types.
 */
function typeIds()
{
    global $db, $tables;
    $map = array();
    $rows = $db->get_recordset(
        "SELECT id, description FROM {$tables['node_types']}");
    if (!is_null($rows)) {
        foreach ($rows as $r) {
            $map[trim($r['description'])] = intval($r['id']);
        }
    }
    return $map;
}

/**
 * Walk up nodes_hierarchy from $nodeId to find the tree root node
 * (node_type_id = testproject). Returns the testproject node id or 0.
 */
function owningProjectOf($nodeId, $types)
{
    global $db, $tables;
    $nodeId = intval($nodeId);
    $seen = array();
    while ($nodeId > 0 && !isset($seen[$nodeId])) {
        $seen[$nodeId] = 1;
        $row = frr(
            "SELECT id, parent_id, node_type_id " .
            "FROM {$tables['nodes_hierarchy']} WHERE id = {$nodeId} LIMIT 1");
        if (is_null($row)) return 0;
        if (isset($types['testproject']) && intval($row['node_type_id']) === $types['testproject']) {
            return intval($row['id']);
        }
        $nodeId = intval($row['parent_id']);
    }
    return 0;
}

/**
 * fetchFirstRow returns null OR false (no row); normalize to null so callers
 * never index into a boolean (avoids E_WARNING -> Event Viewer noise).
 */
function frr($sql)
{
    global $db;
    $row = $db->fetchFirstRow($sql);
    return is_array($row) ? $row : null;
}

/**
 * Suite must exist, be a testsuite node, and belong (somewhere up the tree)
 * to a test project. Returns array(id, name) or null.
 */
function resolveSuite($suiteId)
{
    global $db, $tables, $tsuiteTypeId;
    $suiteId = intval($suiteId);
    $row = frr(
        "SELECT NH.id, NH.name, NH.parent_id, NH.node_type_id " .
        "FROM {$tables['nodes_hierarchy']} NH " .
        "WHERE NH.id = {$suiteId} LIMIT 1");
    if (is_null($row)) return null;
    if ($tsuiteTypeId > 0 && intval($row['node_type_id']) !== $tsuiteTypeId) return null;
    return $row;
}

if ($method === 'GET' && $action === 'info') {
    $types = typeIds();
    $tsuiteTypeId = isset($types['testsuite']) ? $types['testsuite'] : 2;
    $tcaseTypeId = isset($types['testcase']) ? $types['testcase'] : 3;

    $suiteId = intval($_REQUEST['id'] ?? 0);
    if ($suiteId <= 0) {
        http_response_code(400);
        out(array('status' => 'error', 'message' => 'Invalid test suite id'));
    }
    $suite = resolveSuite($suiteId);
    if (is_null($suite)) {
        http_response_code(404);
        out(array('status' => 'error', 'message' => 'Test suite not found'));
    }
    $tprojectId = intval($_REQUEST['tproject_id'] ?? 0);
    if ($tprojectId <= 0) {
        $tprojectId = owningProjectOf($suite['id'], $types);
    }
    if ($tprojectId <= 0) {
        http_response_code(404);
        out(array('status' => 'error', 'message' => 'Owning test project not found'));
    }
    if (!$user->hasRight($db, 'mgt_view_tc', $tprojectId)) {
        http_response_code(403);
        out(array('status' => 'error', 'message' => 'You are not authorized to view this test suite'));
    }

    // external-id display form "PREFIX-N" (legacy get_external_id_for_tc)
    $tprojectMgr = new testproject($db);
    $extPrefix = strval($tprojectMgr->getTestCasePrefix($tprojectId));
    $extGlue = config_get('testcase_cfg')->glue_character;

    $det = frr("SELECT details FROM {$tables['testsuites']} WHERE id = {$suiteId} LIMIT 1");
    $details = is_null($det) ? '' : strval($det['details']);

    // parent suite name (if any)
    $parentName = '';
    if (intval($suite['parent_id']) > 0) {
        $parentRow = frr(
            "SELECT name FROM {$tables['nodes_hierarchy']} " .
            "WHERE id = " . intval($suite['parent_id']) . " LIMIT 1");
        if (!is_null($parentRow)) $parentName = strval($parentRow['name']);
    }

    // child suites + linked test cases (direct children only, like the tree)
    $childSuites = 0;
    $tcAgg = array();   // tcase_id => latest active version info
    $children = $db->get_recordset(
        "SELECT NH.id, NH.name, NH.node_type_id, NH.node_order " .
        "FROM {$tables['nodes_hierarchy']} NH " .
        "WHERE NH.parent_id = {$suiteId} ORDER BY NH.node_order, NH.id");
    $tcaseIds = array();
    if (!is_null($children)) {
        foreach ($children as $c) {
            if (intval($c['node_type_id']) === $tcaseTypeId) {
                $tcaseIds[] = intval($c['id']);
            } elseif (intval($c['node_type_id']) === $tsuiteTypeId) {
                $childSuites++;
            }
        }
    }

    if (count($tcaseIds) > 0) {
        $idList = implode(',', array_map('intval', $tcaseIds));
        // latest version per test case = highest tcversion.version whose tcversion
        // node (nodes_hierarchy.id = tcversions.id) is a child of the tcase node
        $rs = $db->get_recordset(
            "SELECT NH.parent_id AS tcase_id, TCV.* " .
            "FROM {$tables['tcversions']} TCV " .
            " JOIN {$tables['nodes_hierarchy']} NH ON NH.id = TCV.id " .
            " WHERE NH.parent_id IN ({$idList}) " .
            " ORDER BY NH.parent_id, TCV.version DESC");
        if (!is_null($rs)) {
            foreach ($rs as $r) {
                $tcId = intval($r['tcase_id']);
                if (isset($tcAgg[$tcId])) continue; // take highest version first
                $tcAgg[$tcId] = $r;
            }
        }
    }

    $testcases = array();
    foreach ($tcaseIds as $tcId) {
        $v = isset($tcAgg[$tcId]) ? $tcAgg[$tcId] : null;
        $name = '';
        $extId = 0;
        if (!is_null($children)) {
            foreach ($children as $c) {
                if (intval($c['id']) === $tcId) { $name = strval($c['name']); break; }
            }
        }
        if (!is_null($v)) $extId = intval($v['tc_external_id']);
        $importance = is_null($v) ? 2 : intval($v['importance']);
        // canonical TestLink importance: HIGH=3, MEDIUM=2, LOW=1 (cfg/const.inc.php)
        $importanceLabel = array(3 => 'high', 2 => 'medium', 1 => 'low');
        $testcases[] = array(
            'id' => $tcId,
            'name' => $name,
            'external_id' => $extId,
            'external_id_display' => $extPrefix . $extGlue . $extId,
            'version' => is_null($v) ? 0 : intval($v['version']),
            'active' => is_null($v) ? 0 : intval($v['active']),
            'status' => is_null($v) ? 0 : intval($v['status']),
            'importance' => $importance,
            'importance_label' => isset($importanceLabel[$importance]) ? $importanceLabel[$importance] : 'medium',
            'summary' => is_null($v) ? '' : strval($v['summary']),
        );
    }

    // suite-level keywords — legacy testsuite::getKeywords() reads object_keywords
    // by fk_id only (fk_table is 'nodes_hierarchy' for suite nodes); mirror it and
    // dedupe so real UI-assigned suite keywords are shown
    $keywords = array();
    $kwRows = $db->get_recordset(
        "SELECT K.keyword FROM {$tables['object_keywords']} OK " .
        " JOIN {$tables['keywords']} K ON K.id = OK.keyword_id " .
        " WHERE OK.fk_id = {$suiteId} " .
        " ORDER BY K.keyword");
    if (!is_null($kwRows)) {
        foreach ($kwRows as $k) {
            $kw = trim(strval($k['keyword']));
            if ($kw !== '' && !in_array($kw, $keywords, true)) $keywords[] = $kw;
        }
    }

    // attachments for the suite — legacy suite manager is bound to the
    // 'nodes_hierarchy' attachment table (testsuite.class.php parent ctor);
    // suite attachments are stored/read with that fk_table
    $attachments = array();
    $attRows = $db->get_recordset(
        "SELECT id, title, file_name, file_type, file_size, date_added " .
        "FROM {$tables['attachments']} " .
        "WHERE fk_id = {$suiteId} AND fk_table = 'nodes_hierarchy' " .
        "ORDER BY date_added DESC LIMIT 50");
    if (!is_null($attRows)) {
        foreach ($attRows as $a) {
            $attachments[] = array(
                'id' => intval($a['id']),
                'title' => strval($a['title']),
                'file_name' => strval($a['file_name']),
                'file_type' => strval($a['file_type']),
                'file_size' => intval($a['file_size']),
                'date_added' => strval($a['date_added']),
            );
        }
    }

    $tprojectRow = frr("SELECT name FROM {$tables['nodes_hierarchy']} WHERE id = {$tprojectId} LIMIT 1");
    $tprojectName = is_null($tprojectRow) ? '' : strval($tprojectRow['name']);

    out(array(
        'status' => 'ok',
        'suite' => array(
            'id' => intval($suite['id']),
            'name' => strval($suite['name']),
            'details' => $details,
            'parent_id' => intval($suite['parent_id']),
            'parent_name' => $parentName,
            'child_suites' => $childSuites,
            'testcases_cnt' => count($testcases),
        ),
        'testcases' => $testcases,
        'keywords' => $keywords,
        'attachments' => $attachments,
        'tproject' => array('id' => $tprojectId, 'name' => $tprojectName),
    ));
}

http_response_code(400);
out(array('status' => 'error', 'message' => 'Unknown action'));