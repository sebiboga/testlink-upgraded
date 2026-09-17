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
 *          + can_manage (mgt_modify_tc) + can_print (testplan_metrics,
 *          drives the Generate-spec HTML/Word toolbar actions)
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
          'tcsteps', 'keywords', 'object_keywords', 'attachments',
          'cfield_design_values', 'executions'));

/**
 * Build localized label maps for the three bulk domains (status/importance/
 * execution_type) exactly like the legacy moveTestCasesViewer did via
 * getConfigAndLabels() and the modern tcBulkOp BFF (api/tcbulkop/index.php).
 */
function buildDomains($db)
{
    $dummy = getConfigAndLabels('testCaseStatus', 'code');
    $statusMap = $dummy['lbl'];
    $importanceMap = array();
    $impCfg = config_get('importance');
    foreach (($impCfg['code_label'] ?? array()) as $code => $label) {
        $importanceMap[$code] = lang_get($label);
    }
    $tcaseMgr = new testcase($db);
    $execMap = array();
    foreach ($tcaseMgr->get_execution_types() as $code => $localized) {
        $execMap[$code] = $localized;
    }
    return array(
        'status' => $statusMap,
        'importance' => $importanceMap,
        'execution_type' => $execMap,
    );
}

/**
 * Resolve the design-time custom fields linked to a test project (the same
 * map legacy containerEdit.php:217 built for the table-view filter inputs).
 * Returns an array of cf rows or an empty array.
 */
function designCfields($db, $tprojectId)
{
    $cfieldMgr = new cfield_mgr($db);
    try {
        $map = $cfieldMgr->get_linked_cfields_at_design(
            $tprojectId, 1, null, 'testcase');
    } catch (Exception $e) {
        return array();
    }
    if (is_null($map)) return array();
    $out = array();
    foreach ($map as $cf) {
        $out[] = array(
            'id' => intval($cf['id']),
            'name' => strval($cf['name'] ?? ''),
            'label' => strval($cf['label'] ?? ''),
            'type' => intval($cf['type'] ?? 0),
            'possible_values' => strval($cf['possible_values'] ?? ''),
        );
    }
    return $out;
}

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
 *
 * $typeId: expected node_type_id of the suite; when null the global
 * $tsuiteTypeId is not consulted (callers that resolved it locally must pass
 * it explicitly — the function cannot see handler-local variables).
 */
function resolveSuite($suiteId, $typeId = null)
{
    global $db, $tables;
    $suiteId = intval($suiteId);
    $row = frr(
        "SELECT NH.id, NH.name, NH.parent_id, NH.node_type_id " .
        "FROM {$tables['nodes_hierarchy']} NH " .
        "WHERE NH.id = {$suiteId} LIMIT 1");
    if (is_null($row)) return null;
    if (!is_null($typeId) && intval($row['node_type_id']) !== intval($typeId)) return null;
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
    $suite = resolveSuite($suiteId, $tsuiteTypeId);
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

    // may the current user manage the test cases payload (bulk table view) —
    // legacy containerView.tpl only rendered the testcases_table_view button
    // when modify_tc_rights == 'yes' (mgt_modify_tc on the owning project)
    $canManage = ($user->hasRight($db, 'mgt_modify_tc', $tprojectId) === 'yes');

    // may the current user generate the testsuite spec document — mirrors
    // legacy lib/results/printDocument.php checkRights() which enforces
    // 'testplan_metrics' on the owning project (via hasRightOnProj). The
    // explicit project id is used because the session testproject may belong
    // to a different project when the suite popup opens from a system-wide
    // search (see header note). This drives the suiteView toolbar
    // Generate-spec (HTML/Word) actions (issue #1369).
    $canPrint = ($user->hasRight($db, 'testplan_metrics', $tprojectId) === 'yes');

    // direct-link URL for the suite (legacy testsuite::buildDirectWebLink,
    // testsuite.class.php:1827) — mirrors the reqView/reqSpec BFF precedent
    // (api/requirements/index.php:691). The prefix is already resolved above.
    $directLink = $_SESSION['basehref'] . 'linkto.php?tprojectPrefix=' .
        urlencode($extPrefix) . '&item=testsuite&id=' . urlencode(intval($suite['id']));

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
            'direct_link' => $directLink,
        ),
        'testcases' => $testcases,
        'keywords' => $keywords,
        'attachments' => $attachments,
        'can_manage' => $canManage,
        'can_print' => $canPrint,
        'domains' => buildDomains($db),
        'tproject' => array('id' => $tprojectId, 'name' => $tprojectName),
    ));
}

// ---------------------------------------------------------------------------
// GET ?action=table&id=<suite_id>[&tproject_id=<pid>]
// Full "test cases table view" payload (legacy moveTestCasesViewer with
// testCasesTableView=1): every test case of the suite (max version per TC)
// with tcversion_id, status, importance, execution_type and design-time custom
// field values, plus the design-CF definitions and the localized domain maps.
// ---------------------------------------------------------------------------
if ($method === 'GET' && $action === 'table') {
    $types = typeIds();
    $tsuiteTypeId = isset($types['testsuite']) ? $types['testsuite'] : 2;
    $tcaseTypeId = isset($types['testcase']) ? $types['testcase'] : 3;

    $suiteId = intval($_REQUEST['id'] ?? 0);
    if ($suiteId <= 0) {
        http_response_code(400);
        out(array('status' => 'error', 'message' => 'Invalid test suite id'));
    }
    $suite = resolveSuite($suiteId, $tsuiteTypeId);
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
    $canManage = ($user->hasRight($db, 'mgt_modify_tc', $tprojectId) === 'yes');

    $tprojectMgr = new testproject($db);
    $extPrefix = strval($tprojectMgr->getTestCasePrefix($tprojectId));
    $extGlue = config_get('testcase_cfg')->glue_character;

    $children = $db->get_recordset(
        "SELECT NH.id, NH.name, NH.node_type_id, NH.node_order " .
        "FROM {$tables['nodes_hierarchy']} NH " .
        "WHERE NH.parent_id = {$suiteId} ORDER BY NH.node_order, NH.id");
    $tcaseIds = array();
    if (!is_null($children)) {
        foreach ($children as $c) {
            if (intval($c['node_type_id']) === $tcaseTypeId) {
                $tcaseIds[] = intval($c['id']);
            }
        }
    }

    // latest version per test case (mirrors the MAX(version) GROUP BY of the
    // legacy moveTestCasesViewer query — versions are SHOWN regardless of the
    // active flag, unlike the per-case viewer override)
    $tcAgg = array();
    if (count($tcaseIds) > 0) {
        $idList = implode(',', array_map('intval', $tcaseIds));
        $rs = $db->get_recordset(
            "SELECT NH.parent_id AS tcase_id, TCV.* " .
            "FROM {$tables['tcversions']} TCV " .
            " JOIN {$tables['nodes_hierarchy']} NH ON NH.id = TCV.id " .
            " WHERE NH.parent_id IN ({$idList}) " .
            " ORDER BY NH.parent_id, TCV.version DESC");
        if (!is_null($rs)) {
            foreach ($rs as $r) {
                $tcId = intval($r['tcase_id']);
                if (isset($tcAgg[$tcId])) continue;
                $tcAgg[$tcId] = $r;
            }
        }
    }

    // design-time CF values per tcversion (cfield_design_values.key node_id =
    // the tcversion id, same relationship the legacy get_linked_cfields_at_design
    // used to render the $gui->cf inputs and column contents)
    $cfById = array();
    foreach (designCfields($db, $tprojectId) as $cf) {
        $cfById[$cf['id']] = $cf;
    }
    $cfValues = array(); // tcversion_id => cfid => value
    if (count($tcaseIds) > 0 && count($cfById) > 0) {
        $cfIdList = implode(',', array_map('intval', array_keys($cfById)));
        $cfRows = $db->get_recordset(
            "SELECT node_id, field_id, value FROM {$tables['cfield_design_values']} " .
            "WHERE field_id IN ({$cfIdList}) AND node_id IN (" .
            implode(',', array_map('intval', array_map(
                function ($tcId) use ($tcAgg) { return intval($tcAgg[$tcId]['id'] ?? 0); },
                $tcaseIds))) . ")");
        if (!is_null($cfRows)) {
            foreach ($cfRows as $cr) {
                $cfValues[intval($cr['node_id'])][intval($cr['field_id'])] = strval($cr['value']);
            }
        }
    }

    $rows = array();
    $nameById = array();
    if (!is_null($children)) {
        foreach ($children as $c) {
            $nameById[intval($c['id'])] = strval($c['name']);
        }
    }
    foreach ($tcaseIds as $tcId) {
        $v = isset($tcAgg[$tcId]) ? $tcAgg[$tcId] : null;
        if (is_null($v)) continue; // no version yet -> nothing to bulk-set
        $tcvId = intval($v['id']);
        $extId = intval($v['tc_external_id'] ?? 0);
        $importance = intval($v['importance']);
        $importanceLabel = array(3 => 'high', 2 => 'medium', 1 => 'low');
        $cfRow = array();
        foreach ($cfById as $cfId => $cf) {
            $cfRow[$cfId] = isset($cfValues[$tcvId][$cfId]) ? $cfValues[$tcvId][$cfId] : '';
        }
        $rows[] = array(
            'tcversion_id' => $tcvId,
            'tcase_id' => $tcId,
            'name' => strval($nameById[$tcId] ?? ''),
            'external_id' => $extId,
            'external_id_display' => $extPrefix . $extGlue . $extId,
            'version' => intval($v['version']),
            'active' => intval($v['active']),
            'status' => intval($v['status'] ?? 0),
            'importance' => $importance,
            'importance_label' => isset($importanceLabel[$importance]) ? $importanceLabel[$importance] : 'medium',
            'execution_type' => intval($v['execution_type'] ?? 1),
            'summary' => strval($v['summary'] ?? ''),
            'cf' => $cfRow,
        );
    }

    $tprojectRow = frr("SELECT name FROM {$tables['nodes_hierarchy']} WHERE id = {$tprojectId} LIMIT 1");
    $tprojectName = is_null($tprojectRow) ? '' : strval($tprojectRow['name']);

    out(array(
        'status' => 'ok',
        'tproject' => array('id' => $tprojectId, 'name' => $tprojectName),
        'suite' => array('id' => intval($suite['id']), 'name' => strval($suite['name'])),
        'rows' => $rows,
        'cfs' => array_values($cfById),
        'domains' => buildDomains($db),
        'can_manage' => $canManage,
    ));
}

// ---------------------------------------------------------------------------
// POST ?action=bulk_set  {id, tproject_id, rows:[{tcversion_id,tcase_id}],
//                         status, importance, execution_type, cfs:{cfId:value}}
// Bulk-set status / importance / execution_type and design-time custom field
// values on the SELECTED test case versions (legacy doBulkSet on
// containerEdit.php:1434). Requires mgt_modify_tc on the owning project.
// ---------------------------------------------------------------------------
if ($method === 'POST' && $action === 'bulk_set') {
    $types = typeIds();
    $tsuiteTypeId = isset($types['testsuite']) ? $types['testsuite'] : 2;
    $suiteId = intval($_POST['id'] ?? ($_REQUEST['id'] ?? 0));
    if ($suiteId <= 0) {
        // try JSON body
        $body = json_decode(file_get_contents('php://input'), true);
        if (is_array($body) && isset($body['id'])) $suiteId = intval($body['id']);
    }
    if ($suiteId <= 0) {
        http_response_code(400);
        out(array('status' => 'error', 'message' => 'Invalid test suite id'));
    }
    $suite = resolveSuite($suiteId, $tsuiteTypeId);
    if (is_null($suite)) {
        http_response_code(404);
        out(array('status' => 'error', 'message' => 'Test suite not found'));
    }
    $tprojectId = intval($_REQUEST['tproject_id'] ?? 0);
    $json = json_decode(file_get_contents('php://input'), true);
    if (!is_array($json)) $json = array();
    if ($tprojectId <= 0) {
        $tprojectId = intval($json['tproject_id'] ?? 0);
    }
    if ($tprojectId <= 0) {
        $tprojectId = owningProjectOf($suite['id'], $types);
    }
    if ($tprojectId <= 0) {
        http_response_code(404);
        out(array('status' => 'error', 'message' => 'Owning test project not found'));
    }
    if ($user->hasRight($db, 'mgt_modify_tc', $tprojectId) !== 'yes') {
        http_response_code(403);
        out(array('status' => 'error', 'message' => 'You are not authorized to modify test cases'));
    }

    $selRows = isset($json['rows']) ? $json['rows'] : array();
    $status = intval($json['status'] ?? 0);
    $importance = intval($json['importance'] ?? 0);
    $executionType = intval($json['execution_type'] ?? 0);
    $cfs = isset($json['cfs']) && is_array($json['cfs']) ? $json['cfs'] : array();

    $tcaseMgr = new testcase($db);
    $updated = 0;
    foreach ((array) $selRows as $row) {
        $tcvId = intval($row['tcversion_id'] ?? 0);
        if ($tcvId <= 0) continue;
        if ($status > 0)          $tcaseMgr->setStatus($tcvId, $status);
        if ($importance > 0)      $tcaseMgr->setImportance($tcvId, $importance);
        if ($executionType > 0)   $tcaseMgr->setExecutionType($tcvId, $executionType);
        $updated++;
    }

    // custom field design values (second round, matching doBulkSet ordering)
    $appliedCfs = 0;
    if (count($cfs) > 0) {
        $cfieldMgr = new cfield_mgr($db);
        $cfMap = $cfieldMgr->get_linked_cfields_at_design(
            $tprojectId, 1, null, 'testcase');
        $cfDefinition = array();
        $valuesFromUX = array();
        foreach ($cfs as $cfId => $val) {
            $cfId = intval($cfId);
            if (!is_null($cfMap) && isset($cfMap[$cfId])) {
                $cfDefinition[$cfId] = $cfMap[$cfId];
                $valuesFromUX[$cfieldMgr->name_prefix . $cfMap[$cfId]['type'] . '_' . $cfId]
                    = is_array($val) ? implode(',', $val) : $val;
            }
        }
        if (count($cfDefinition) > 0) {
            foreach ((array) $selRows as $row) {
                $tcvId = intval($row['tcversion_id'] ?? 0);
                if ($tcvId <= 0) continue;
                $cfieldMgr->design_values_to_db($valuesFromUX, $tcvId, $cfDefinition);
                $appliedCfs++;
            }
        }
    }

    out(array(
        'status' => 'ok',
        'message' => 'Bulk set applied',
        'updated' => $updated,
        'cf_versions_applied' => $appliedCfs,
        'applied' => array_filter(array(
            'status' => $status, 'importance' => $importance,
            'execution_type' => $executionType),
            function ($v) { return $v > 0; }),
    ));
}

// ---------------------------------------------------------------------------
// GET ?action=suites&tproject_id=<pid>
// Flat list of all test suites of the owning project (id + scope path) used to
// populate the Move/Copy target picker. Mirrors the legacy
// gen_combo_test_suites() of containerMoveTC.tpl (containerEdit.php:983).
// Requires mgt_view_tc on the project.
// ---------------------------------------------------------------------------
if ($method === 'GET' && $action === 'suites') {
    $types = typeIds();
    $tsuiteTypeId = isset($types['testsuite']) ? $types['testsuite'] : 2;
    $tprojectId = intval($_REQUEST['tproject_id'] ?? 0);
    if ($tprojectId <= 0) {
        http_response_code(400);
        out(array('status' => 'error', 'message' => 'Invalid test project id'));
    }
    if (!$user->hasRight($db, 'mgt_view_tc', $tprojectId)) {
        http_response_code(403);
        out(array('status' => 'error',
                  'message' => 'You are not authorized for this test project'));
    }
    $suites = array();
    $stack = array(array('id' => $tprojectId, 'path' => ''));
    while (count($stack) > 0) {
        $cur = array_pop($stack);
        $kids = $db->get_recordset(
            "SELECT id, name, node_type_id FROM {$tables['nodes_hierarchy']} " .
            "WHERE parent_id = " . intval($cur['id']) .
            " ORDER BY node_order, id");
        if (is_null($kids)) continue;
        foreach ($kids as $k) {
            if (intval($k['node_type_id']) !== $tsuiteTypeId) continue;
            $name = strval($k['name']);
            $path = $cur['path'] === '' ? $name : $cur['path'] . ' / ' . $name;
            $suites[] = array(
                'id' => intval($k['id']),
                'name' => $name,
                'path' => $path,
            );
            $stack[] = array('id' => intval($k['id']), 'path' => $path);
        }
    }
    usort($suites, function ($a, $b) { return strcmp($a['path'], $b['path']); });
    out(array('status' => 'ok', 'suites' => $suites));
}

// ---------------------------------------------------------------------------
// POST ?action=reorder_testcases  {id, tproject_id, by:'name'|'external_id'}
// Re-orders the direct test-case children of the suite, rewriting
// nodes_hierarchy.node_order (name-natural dictionary sort, legacy
// reorderTestCasesDictionary containerEdit.php:1339-1352, or external-id
// sort reorderTestCasesByExtID :1359-1373; 'by' falls back to config
// testcase_reorder_by). Requires mgt_modify_tc on the owning project.
// ---------------------------------------------------------------------------
if ($method === 'POST' && $action === 'reorder_testcases') {
    $types = typeIds();
    $tsuiteTypeId = isset($types['testsuite']) ? $types['testsuite'] : 2;
    $tcaseTypeId = isset($types['testcase']) ? $types['testcase'] : 3;
    $suiteId = intval($_POST['id'] ?? ($_REQUEST['id'] ?? 0));
    $json = json_decode(file_get_contents('php://input'), true);
    if (!is_array($json)) $json = array();
    if ($suiteId <= 0) {
        $suiteId = intval($json['id'] ?? 0);
    }
    if ($suiteId <= 0) {
        http_response_code(400);
        out(array('status' => 'error', 'message' => 'Invalid test suite id'));
    }
    $suite = resolveSuite($suiteId, $tsuiteTypeId);
    if (is_null($suite)) {
        http_response_code(404);
        out(array('status' => 'error', 'message' => 'Test suite not found'));
    }
    $tprojectId = intval($_REQUEST['tproject_id'] ?? 0);
    if ($tprojectId <= 0) {
        $tprojectId = intval($json['tproject_id'] ?? 0);
    }
    if ($tprojectId <= 0) {
        $tprojectId = owningProjectOf($suite['id'], $types);
    }
    if ($tprojectId <= 0) {
        http_response_code(404);
        out(array('status' => 'error', 'message' => 'Owning test project not found'));
    }
    if ($user->hasRight($db, 'mgt_modify_tc', $tprojectId) !== 'yes') {
        http_response_code(403);
        out(array('status' => 'error', 'message' => 'You are not authorized to modify test cases'));
    }

    $by = strval($json['by'] ?? '');
    if ($by === '') {
        $crit = strtoupper(strval(config_get('testcase_reorder_by')));
        $by = ($crit === 'NAME') ? 'name' : 'external_id';
    }

    $treeMgr = new tree($db);
    if ($by === 'external_id') {
        $rs = $db->get_recordset(
            "SELECT DISTINCT NHTC.id, TCV.tc_external_id " .
            "FROM {$tables['nodes_hierarchy']} NHTC " .
            "JOIN {$tables['nodes_hierarchy']} NHTCV ON NHTCV.parent_id = NHTC.id " .
            "JOIN {$tables['tcversions']} TCV ON TCV.id = NHTCV.id " .
            "WHERE NHTC.parent_id = {$suiteId} " .
            "ORDER BY tc_external_id ASC");
        $ids = array();
        if (!is_null($rs)) {
            foreach ($rs as $r) {
                $ids[] = intval($r['id']);
            }
        }
        if (count($ids) > 0) $treeMgr->change_order_bulk($ids);
    } else {
        $kids = $db->get_recordset(
            "SELECT id, name FROM {$tables['nodes_hierarchy']} " .
            "WHERE parent_id = {$suiteId} AND node_type_id = {$tcaseTypeId}");
        $a2sort = array();
        if (!is_null($kids)) {
            foreach ($kids as $k) {
                $a2sort[intval($k['id'])] = strtolower(strval($k['name']));
            }
        }
        if (count($a2sort) > 0) {
            natsort($a2sort);
            $treeMgr->change_order_bulk(array_keys($a2sort));
        }
    }

    out(array('status' => 'ok', 'message' => 'Test cases reordered', 'by' => $by));
}

// ---------------------------------------------------------------------------
// POST ?action=delete_testcases  {id, tproject_id, tcase_ids:[...]}
// Deletes the selected test cases (ALL versions), mirroring legacy
// doDeleteTestCases (containerEdit.php:1312-1321). Executed test cases are
// blocked unless the user holds testproject_delete_executed_testcases
// (legacy draw_check gate containerEdit.php:1235).
// ---------------------------------------------------------------------------
if ($method === 'POST' && $action === 'delete_testcases') {
    $types = typeIds();
    $tsuiteTypeId = isset($types['testsuite']) ? $types['testsuite'] : 2;
    $suiteId = intval($_POST['id'] ?? ($_REQUEST['id'] ?? 0));
    $json = json_decode(file_get_contents('php://input'), true);
    if (!is_array($json)) $json = array();
    if ($suiteId <= 0) {
        $suiteId = intval($json['id'] ?? 0);
    }
    if ($suiteId <= 0) {
        http_response_code(400);
        out(array('status' => 'error', 'message' => 'Invalid test suite id'));
    }
    $suite = resolveSuite($suiteId, $tsuiteTypeId);
    if (is_null($suite)) {
        http_response_code(404);
        out(array('status' => 'error', 'message' => 'Test suite not found'));
    }
    $tprojectId = intval($_REQUEST['tproject_id'] ?? 0);
    if ($tprojectId <= 0) {
        $tprojectId = intval($json['tproject_id'] ?? 0);
    }
    if ($tprojectId <= 0) {
        $tprojectId = owningProjectOf($suite['id'], $types);
    }
    if ($tprojectId <= 0) {
        http_response_code(404);
        out(array('status' => 'error', 'message' => 'Owning test project not found'));
    }
    if ($user->hasRight($db, 'mgt_modify_tc', $tprojectId) !== 'yes') {
        http_response_code(403);
        out(array('status' => 'error', 'message' => 'You are not authorized to modify test cases'));
    }

    $ids = array_filter(array_map('intval', (array)($json['tcase_ids'] ?? array())));
    if (count($ids) === 0) {
        http_response_code(400);
        out(array('status' => 'error', 'message' => 'No test cases selected'));
    }

    sort($ids);
    $idList = implode(',', $ids);

    // executed gate (legacy: executed test cases have no delete checkbox
    // without testproject_delete_executed_testcases)
    $execRows = $db->get_recordset(
        "SELECT DISTINCT NH.parent_id AS tcase_id " .
        "FROM {$tables['executions']} E " .
        "JOIN {$tables['nodes_hierarchy']} NH ON NH.id = E.tcversion_id " .
        "WHERE NH.parent_id IN ({$idList})");
    $executedIds = array();
    if (!is_null($execRows)) {
        foreach ($execRows as $e) {
            $executedIds[intval($e['tcase_id'])] = 1;
        }
    }
    if (count($executedIds) > 0
        && $user->hasRight($db, 'testproject_delete_executed_testcases',
                           $tprojectId) !== 'yes') {
        http_response_code(422);
        out(array('status' => 'error',
                  'message' => 'Some selected test cases have executions: deleting '
                    . 'requires the delete executed testcases permission',
                  'executed_ids' => array_keys($executedIds)));
    }

    $tcaseMgr = new testcase($db);
    $deleted = 0;
    foreach ($ids as $tid) {
        $tcaseMgr->delete($tid);
        $deleted++;
    }

    out(array('status' => 'ok', 'message' => 'Test cases deleted', 'deleted' => $deleted));
}

// ---------------------------------------------------------------------------
// POST ?action=move_testcases  {id, tproject_id, tcase_ids:[...], target_id}
// POST ?action=copy_testcases  {id, tproject_id, tcase_ids:[...], target_id}
// Move (change_parent, legacy do_move_tcase_set containerEdit.php:295-297) or
// copy (copy_to, legacy do_copy_tcase_set :299-306) the selected test cases
// to the chosen target suite. Requires mgt_modify_tc on the owning project
// and a target suite that belongs to the SAME project (self/descendant moves
// are also rejected, matching the api/testcases move guard).
// ---------------------------------------------------------------------------
if ($method === 'POST'
    && ($action === 'move_testcases' || $action === 'copy_testcases')) {
    $types = typeIds();
    $tsuiteTypeId = isset($types['testsuite']) ? $types['testsuite'] : 2;
    $suiteId = intval($_POST['id'] ?? ($_REQUEST['id'] ?? 0));
    $json = json_decode(file_get_contents('php://input'), true);
    if (!is_array($json)) $json = array();
    if ($suiteId <= 0) {
        $suiteId = intval($json['id'] ?? 0);
    }
    if ($suiteId <= 0) {
        http_response_code(400);
        out(array('status' => 'error', 'message' => 'Invalid test suite id'));
    }
    $suite = resolveSuite($suiteId, $tsuiteTypeId);
    if (is_null($suite)) {
        http_response_code(404);
        out(array('status' => 'error', 'message' => 'Test suite not found'));
    }
    $tprojectId = intval($_REQUEST['tproject_id'] ?? 0);
    if ($tprojectId <= 0) {
        $tprojectId = intval($json['tproject_id'] ?? 0);
    }
    if ($tprojectId <= 0) {
        $tprojectId = owningProjectOf($suite['id'], $types);
    }
    if ($tprojectId <= 0) {
        http_response_code(404);
        out(array('status' => 'error', 'message' => 'Owning test project not found'));
    }
    if ($user->hasRight($db, 'mgt_modify_tc', $tprojectId) !== 'yes') {
        http_response_code(403);
        out(array('status' => 'error', 'message' => 'You are not authorized to modify test cases'));
    }

    $ids = array_filter(array_map('intval', (array)($json['tcase_ids'] ?? array())));
    $targetId = intval($json['target_id'] ?? 0);
    if (count($ids) === 0) {
        http_response_code(400);
        out(array('status' => 'error', 'message' => 'No test cases selected'));
    }
    $target = resolveSuite($targetId, $tsuiteTypeId);
    if (is_null($target)) {
        http_response_code(400);
        out(array('status' => 'error', 'message' => 'Invalid target test suite'));
    }
    $targetProject = owningProjectOf($target['id'], $types);
    if ($targetProject !== $tprojectId) {
        http_response_code(422);
        out(array('status' => 'error',
                  'message' => 'Target test suite belongs to a different test project'));
    }
    if (in_array($targetId, $ids, true)) {
        http_response_code(422);
        out(array('status' => 'error',
                  'message' => 'Cannot move/copy onto the selected test case set'));
    }

    if ($action === 'move_testcases') {
        $treeMgr = new tree($db);
        $ok = $treeMgr->change_parent($ids, $targetId);
        out(array('status' => 'ok', 'message' => 'Test cases moved',
                  'moved' => count($ids), 'result' => intval($ok)));
    } else {
        $tcaseMgr = new testcase($db);
        $copyOpt = array(
            'check_duplicate_name' => config_get('check_names_for_duplicates'),
            'action_on_duplicate_name' => config_get('action_on_duplicate_name'),
            'stepAsGhost' => 0,
        );
        $copyOpt['copy_also'] = array(
            'keyword_assignments' => 1,
            'requirement_assignments' => 0,
        );
        $copied = 0;
        foreach ($ids as $tid) {
            $tcaseMgr->copy_to($tid, $targetId,
                               intval($user->dbID ?? $userId), $copyOpt);
            $copied++;
        }
        out(array('status' => 'ok', 'message' => 'Test cases copied',
                  'copied' => $copied));
    }
}

// ---------------------------------------------------------------------------
// POST ?action=save_suite  create: {parent_id, name, details}
//                          update: {id, name, details}
// Create a new test suite under parent_id (legacy add_testsuite, containerEdit
// addTestSuite:729) or update an existing suite name/details (legacy
// update_testsuite, containerEdit updateTestSuite:839). Requires mgt_modify_tc
// (legacy testcase_mgmt grant gate, containerEdit.php:123). The legacy edit
// form also manages keywords/custom-fields; the tcTestSpec tree covers those
// (api/testcases suite_*), so here we persist name + details for full parity
// with the legacy suite editor.
// ---------------------------------------------------------------------------
if ($method === 'POST' && $action === 'save_suite') {
    $json = json_decode(file_get_contents('php://input'), true);
    if (!is_array($json)) $json = array();

    $name = trim(strval($json['name'] ?? ''));
    $details = strval($json['details'] ?? '');
    $suiteId = intval($json['id'] ?? 0);
    $parentId = intval($json['parent_id'] ?? 0);

    $types = typeIds();
    $tsuiteTypeId = isset($types['testsuite']) ? $types['testsuite'] : 2;

    if ($name === '') {
        http_response_code(400);
        out(array('status' => 'error', 'message' => 'Test suite name is required'));
    }

    if ($suiteId > 0) {
        // ---- UPDATE existing suite -----------------------------
        $suite = resolveSuite($suiteId, $tsuiteTypeId);
        if (is_null($suite)) {
            http_response_code(404);
            out(array('status' => 'error', 'message' => 'Test suite not found'));
        }
        $tprojectId = owningProjectOf($suite['id'], $types);
        if ($tprojectId <= 0) {
            http_response_code(404);
            out(array('status' => 'error', 'message' => 'Owning test project not found'));
        }
        if ($user->hasRight($db, 'mgt_modify_tc', $tprojectId) !== 'yes') {
            http_response_code(403);
            out(array('status' => 'error', 'message' => 'You are not authorized to modify test suites'));
        }
        $tsuiteMgr = new testsuite($db);
        $ret = $tsuiteMgr->update($suiteId, $name, $details, intval($suite['parent_id']));
        if (isset($ret['status_ok']) && !$ret['status_ok']) {
            http_response_code(422);
            out(array('status' => 'error',
                      'message' => strval($ret['msg'] ?? 'Test suite update failed')));
        }
        $id = $suiteId;
        $created = false;
        $message = 'Test suite updated';
    } else {
        // ---- CREATE new suite under parent --------------------
        if ($parentId <= 0) {
            http_response_code(400);
            out(array('status' => 'error', 'message' => 'Missing parent container id'));
        }
        $parentSuite = resolveSuite($parentId, $tsuiteTypeId);
        if (is_null($parentSuite)) {
            // parent may also be the test project node itself (create top-level suite)
            $projRow = frr(
                "SELECT id, node_type_id FROM {$tables['nodes_hierarchy']} " .
                "WHERE id = {$parentId} LIMIT 1");
            if (is_null($projRow)) {
                http_response_code(404);
                out(array('status' => 'error', 'message' => 'Parent container not found'));
            }
            $tprojectTypeId = isset($types['testproject']) ? $types['testproject'] : 1;
            if (intval($projRow['node_type_id']) !== $tprojectTypeId) {
                http_response_code(404);
                out(array('status' => 'error',
                          'message' => 'Parent is neither a test suite nor a test project'));
            }
        }
        $tprojectId = owningProjectOf($parentId, $types);
        if ($tprojectId <= 0) {
            http_response_code(404);
            out(array('status' => 'error', 'message' => 'Owning test project not found'));
        }
        if ($user->hasRight($db, 'mgt_modify_tc', $tprojectId) !== 'yes') {
            http_response_code(403);
            out(array('status' => 'error', 'message' => 'You are not authorized to modify test suites'));
        }
        $tsuiteMgr = new testsuite($db);
        $ret = $tsuiteMgr->create($parentId, $name, $details, null,
                                  config_get('check_names_for_duplicates'), 'block');
        $id = is_array($ret) ? intval($ret['id'] ?? 0) : intval($ret);
        if ($id <= 0) {
            http_response_code(500);
            out(array('status' => 'error',
                      'message' => strval(is_array($ret) ? ($ret['msg'] ?? 'Create failed') : 'Create failed')));
        }
        $created = true;
        $message = 'Test suite created';
    }

    out(array(
        'status' => 'ok',
        'message' => $message,
        'id' => intval($id),
        'parent_id' => $parentId,
        'created' => $created,
    ));
}

// ---------------------------------------------------------------------------
// POST ?action=delete_suite  {id}
// Deep-deletes a test suite (ALL descendants) and its keyword links — legacy
// deleteTestSuite (containerEdit.php:655) bSure path:
//   $tsuiteMgr->delete_deep($objectID); $tsuiteMgr->deleteKeywords($objectID);
// Requires mgt_modify_tc on the owning project (legacy testcase_mgmt gate).
// ---------------------------------------------------------------------------
if ($method === 'POST' && $action === 'delete_suite') {
    $json = json_decode(file_get_contents('php://input'), true);
    if (!is_array($json)) $json = array();
    $suiteId = intval($json['id'] ?? 0);
    if ($suiteId <= 0) {
        http_response_code(400);
        out(array('status' => 'error', 'message' => 'Invalid test suite id'));
    }
    $types = typeIds();
    $tsuiteTypeId = isset($types['testsuite']) ? $types['testsuite'] : 2;
    $suite = resolveSuite($suiteId, $tsuiteTypeId);
    if (is_null($suite)) {
        http_response_code(404);
        out(array('status' => 'error', 'message' => 'Test suite not found'));
    }
    $tprojectId = owningProjectOf($suite['id'], $types);
    if ($tprojectId <= 0) {
        http_response_code(404);
        out(array('status' => 'error', 'message' => 'Owning test project not found'));
    }
    if ($user->hasRight($db, 'mgt_modify_tc', $tprojectId) !== 'yes') {
        http_response_code(403);
        out(array('status' => 'error', 'message' => 'You are not authorized to modify test suites'));
    }

    $tsuiteMgr = new testsuite($db);
    $tsuiteMgr->delete_deep($suiteId);
    $tsuiteMgr->deleteKeywords($suiteId);

    out(array('status' => 'ok', 'message' => 'Test suite deleted', 'id' => $suiteId));
}

// ---------------------------------------------------------------------------
// POST ?action=reorder_child_suites  {id}
// Alphabetically (natural-dictionary sort) reorder the DIRECT child test suites
// of the given suite — legacy reorder_testsuites_alpha doAction
// (containerEdit.php:330-335) -> reorderTestSuitesDictionary (:1381), which
// natsort()s the child suite names and rewrites nodes_hierarchy.node_order via
// tree->change_order_bulk(). Requires mgt_modify_tc on the owning project.
// ---------------------------------------------------------------------------
if ($method === 'POST' && $action === 'reorder_child_suites') {
    $json = json_decode(file_get_contents('php://input'), true);
    if (!is_array($json)) $json = array();
    $suiteId = intval($json['id'] ?? 0);
    if ($suiteId <= 0) {
        http_response_code(400);
        out(array('status' => 'error', 'message' => 'Invalid test suite id'));
    }
    $types = typeIds();
    $tsuiteTypeId = isset($types['testsuite']) ? $types['testsuite'] : 2;
    $suite = resolveSuite($suiteId, $tsuiteTypeId);
    if (is_null($suite)) {
        http_response_code(404);
        out(array('status' => 'error', 'message' => 'Test suite not found'));
    }
    $tprojectId = owningProjectOf($suite['id'], $types);
    if ($tprojectId <= 0) {
        http_response_code(404);
        out(array('status' => 'error', 'message' => 'Owning test project not found'));
    }
    if ($user->hasRight($db, 'mgt_modify_tc', $tprojectId) !== 'yes') {
        http_response_code(403);
        out(array('status' => 'error', 'message' => 'You are not authorized to modify test suites'));
    }

    $kids = $db->get_recordset(
        "SELECT id, name FROM {$tables['nodes_hierarchy']} " .
        "WHERE parent_id = {$suiteId} AND node_type_id = {$tsuiteTypeId} " .
        "ORDER BY node_order, id");
    $a2sort = array();
    if (!is_null($kids)) {
        foreach ($kids as $k) {
            $a2sort[intval($k['id'])] = strtolower(strval($k['name']));
        }
    }
    if (count($a2sort) > 0) {
        natsort($a2sort);
        $treeMgr = new tree($db);
        $treeMgr->change_order_bulk(array_keys($a2sort));
    }

    out(array('status' => 'ok', 'message' => 'Child test suites reordered',
              'count' => count($a2sort)));
}

// ---------------------------------------------------------------------------
// GET ?action=move_targets&id=<suite>&tproject_id=<pid>
// Destination list for suite Move/Copy — mirrors legacy moveTestSuiteViewer
// (containerEdit.php:776): the owning test project (as top-level container,
// FIRST option) + every other test suite of the project, EXCLUDING the suite
// itself and ALL its descendants (moving/copying a suite into its own subtree
// is invalid; the legacy tree operations reject it too). Requires mgt_view_tc.
// ---------------------------------------------------------------------------
if ($method === 'GET' && $action === 'move_targets') {
    $types = typeIds();
    $tsuiteTypeId = isset($types['testsuite']) ? $types['testsuite'] : 2;
    $suiteId = intval($_REQUEST['id'] ?? 0);
    if ($suiteId <= 0) {
        http_response_code(400);
        out(array('status' => 'error', 'message' => 'Invalid test suite id'));
    }
    $suite = resolveSuite($suiteId, $tsuiteTypeId);
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
        out(array('status' => 'error',
                  'message' => 'You are not authorized for this test project'));
    }

    // collect every node of the project (limit walking depth)
    $all = array();
    $stack = array(intval($tprojectId));
    while (count($stack) > 0) {
        $cur = array_pop($stack);
        $kids = $db->get_recordset(
            "SELECT id, name, parent_id, node_type_id FROM {$tables['nodes_hierarchy']} " .
            "WHERE parent_id = {$cur} ORDER BY node_order, id");
        if (is_null($kids)) continue;
        foreach ($kids as $k) {
            $all[] = array(
                'id' => intval($k['id']),
                'name' => strval($k['name']),
                'parent_id' => intval($k['parent_id']),
                'node_type_id' => intval($k['node_type_id']),
            );
            $stack[] = intval($k['id']);
        }
    }

    // excluded = the suite itself + every descendant (walk children edges)
    $excluded = array($suiteId => 1);
    $stack = array($suiteId);
    $depth = 0;
    while (count($stack) > 0 && $depth < 500) {
        $cur = array_pop($stack);
        foreach ($all as $n) {
            if ($n['parent_id'] === $cur) {
                $excluded[$n['id']] = 1;
                $stack[] = $n['id'];
            }
        }
        $depth++;
    }

    $tprojectRow = frr("SELECT name FROM {$tables['nodes_hierarchy']} WHERE id = {$tprojectId} LIMIT 1");
    $tprojectName = is_null($tprojectRow) ? '' : strval($tprojectRow['name']);

    $targets = array();
    foreach ($all as $n) {
        if ($n['node_type_id'] !== $tsuiteTypeId || isset($excluded[$n['id']])) continue;
        $targets[] = array('id' => $n['id'], 'name' => $n['name']);
    }
    usort($targets, function ($a, $b) { return strtolower($a['name']) <=> strtolower($b['name']); });

    out(array(
        'status' => 'ok',
        'project_id' => $tprojectId,
        'project_name' => $tprojectName,
        'excluded' => array_keys($excluded),
        'targets' => $targets,
    ));
}

http_response_code(400);
out(array('status' => 'error', 'message' => 'Unknown action'));