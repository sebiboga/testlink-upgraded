<?php
/**
 * Test Case Viewer BFF API (read-only view)
 * URL: /api/testcases/
 * Plain PHP, no framework, no compilation
 *
 * Mirrors lib/testcases/archiveData.php (feature=testcase) +
 * gui/templates/dashio/testcases/tcView_viewer.tpl (TestLink 1.9.20):
 * shows one test case with ALL its versions, steps, keywords,
 * custom fields, attachments, requirements and relations.
 * Also backs the Test Specification editor (create/update/delete/upload
 * attachment operations guarded by bffSameOriginGuard + mgt_modify_tc).
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
    $v = $_GET[$key] ?? $default;
    return is_numeric($v) ? intval($v) : $default;
}

/** Read a testproject option flag tolerating both object and array results
 *  from testproject::getOptions() (stored options blob may be either shape). */
function tprojectOpt($opt, $key) {
    if (is_object($opt)) {
        return !empty($opt->$key);
    }
    if (is_array($opt)) {
        return !empty($opt[$key]);
    }
    return false;
}

/**
 * Config-driven "required" flag for the estimated execution duration field
 * (config.inc.php: $tlCfg->testcase_cfg->estimated_execution_duration->required).
 * Legacy emits the raw string as an HTML5 required attribute; the BFF exposes
 * a boolean so the modern editor can enforce the same rule client-side.
 */
function isDurationRequired($tcaseCfg) {
    if (!isset($tcaseCfg) || !isset($tcaseCfg->estimated_execution_duration)) {
        return false;
    }
    $req = $tcaseCfg->estimated_execution_duration->required ?? '';
    return trim(strval($req)) !== '';
}

/**
 * Walk up the nodes_hierarchy parent chain.
 * NOTE: tree_manager::get_path() / testproject::getByChildID() proved
 * unreliable in this code base, so we resolve ancestors directly.
 */
function getParentChain($dbHandler, $nodesTable, $nodeId) {
    $chain = [];
    $cur = intval($nodeId);
    for ($i = 0; $i < 50 && $cur > 0; $i++) {
        $rs = $dbHandler->get_recordset(
            "SELECT id, name, parent_id, node_type_id FROM {$nodesTable} " .
            "WHERE id = {$cur}");
        if (is_null($rs) || count($rs) == 0) {
            break;
        }
        $chain[] = $rs[0];
        $next = intval($rs[0]['parent_id']);
        if ($next <= 0 || $next === $cur) {
            break;
        }
        $cur = $next;
    }
    return array_reverse($chain);
}

/**
 * Collect every testsuite node of a project (any depth) via BFS over
 * nodes_hierarchy (tree_manager helpers proved unreliable in this code base).
 */
function getProjectSuites($dbHandler, $tprojectId) {
    $tables = tlObjectWithDB::getDBTables(array('nodes_hierarchy', 'node_types'));
    $typeRows = $dbHandler->get_recordset(
        "SELECT id, description FROM {$tables['node_types']} " .
        "WHERE description IN ('testproject','testsuite','testcase')");
    if (is_null($typeRows)) {
        return ['byId' => [], 'byName' => [], 'childMap' => [], 'types' => []];
    }
    $types = [];
    foreach ($typeRows as $tr) {
        $types[$tr['description']] = intval($tr['id']);
    }

    $allRows = $dbHandler->get_recordset(
        "SELECT id, parent_id, name, node_type_id FROM {$tables['nodes_hierarchy']}");
    $childMap = [];
    if (!is_null($allRows)) {
        foreach ($allRows as $r) {
            $childMap[intval($r['parent_id'])][] = $r;
        }
    }

    $byId = [];
    $byName = [];
    $queue = [intval($tprojectId)];
    $seen = [intval($tprojectId) => true];
    while (count($queue) > 0) {
        $cur = array_shift($queue);
        foreach ($childMap[$cur] ?? [] as $child) {
            $cid = intval($child['id']);
            if (isset($seen[$cid])) {
                continue;
            }
            $seen[$cid] = true;
            if (intval($child['node_type_id']) === ($types['testsuite'] ?? -1)) {
                $byId[$cid] = $child;
                $key = strtolower(trim(strval($child['name'])));
                if ($key !== '' && !isset($byName[$key])) {
                    $byName[$key] = $cid;
                }
                $queue[] = $cid;
            }
        }
    }
    return ['byId' => $byId, 'byName' => $byName,
            'childMap' => $childMap, 'types' => $types];
}

/* ------------------------------------------------------------------
 * Edit-mode tree filter support (port of the legacy
 * tlTestCaseFilterControl edit_mode + treeMenu.inc.php getTestSpecTree
 * behaviour). The modern testSpec.html filter panel posts these params
 * to the BFF `tree` action; the last applied set is kept in session so
 * a full page reload keeps the same view (legacy persisted per filter
 * mode + form token).
 * ------------------------------------------------------------------ */

/** Parse a list of positive ints from CSV/array input. */
function treeFilterCsvInts($v) {
    $out = [];
    if (is_array($v)) {
        foreach ($v as $x) {
            if (is_numeric($x) && intval($x) > 0) { $out[] = intval($x); }
        }
    } else {
        foreach (preg_split('/[,;\s]+/', trim(strval($v))) as $tok) {
            if ($tok !== '' && is_numeric($tok) && intval($tok) > 0) { $out[] = intval($tok); }
        }
    }
    return array_values(array_unique($out));
}

/** Is the edit-mode tree filter panel enabled by config (legacy show_filters)? */
function treeFiltersEnabled() {
    try {
        $cfg = config_get('tree_filter_cfg');
        $editCfg = $cfg->testcases->edit_mode ?? null;
        return intval($editCfg->show_filters ?? 0) === 1;
    } catch (Exception $e) {
        return false;
    }
}

/** Sanitize the raw __GET filter params into a consistent filter map. */
function treeFiltersFromInput() {
    $f = [];
    $name = trim(strval($_GET['filter_testcase_name'] ?? ''));
    if ($name !== '') { $f['testcase_name'] = $name; }
    $tcId = trim(strval($_GET['filter_tc_id'] ?? ''));
    if ($tcId !== '') { $f['tc_id'] = $tcId; }
    $topSuites = treeFilterCsvInts($_GET['filter_toplevel_testsuite'] ?? null);
    if (count($topSuites) > 0) { $f['toplevel_suite'] = $topSuites[0]; }
    $kw = treeFilterCsvInts($_GET['filter_keywords'] ?? null);
    if (count($kw) > 0) {
        $f['keywords'] = $kw;
        $type = strtoupper(trim(strval($_GET['filter_keywords_filter_type'] ?? 'Or')));
        if ($type === 'NOT') { $type = 'NOTLINKED'; }
        if (!in_array($type, array('OR', 'AND', 'NOTLINKED'), true)) { $type = 'OR'; }
        $f['keywords_type'] = $type;
    }
    $plats = treeFilterCsvInts($_GET['filter_platforms'] ?? null);
    if (count($plats) > 0) { $f['platforms'] = $plats; }
    if (isset($_GET['filter_active_inactive'])) {
        $ai = intval($_GET['filter_active_inactive']);
        if ($ai === 1 || $ai === 2) { $f['active_inactive'] = $ai; }
    }
    foreach (array('importance', 'execution_type', 'workflow_status') as $k) {
        if (isset($_GET['filter_' . $k])) {
            $v = intval($_GET['filter_' . $k]);
            if ($v > 0) { $f[$k] = $v; }
        }
    }
    if (isset($_GET['filter_custom_fields']) && strval($_GET['filter_custom_fields']) !== ''
        && strval($_GET['filter_custom_fields']) !== '{}') {
        $cfRaw = strval($_GET['filter_custom_fields']);
        $cf = json_decode($cfRaw, true);
        if (!is_array($cf)) {
            parse_str(preg_replace('/[\[\]]/u', '', $cfRaw), $cf);
        }
        foreach ((array)$cf as $fk => $fv) {
            if (is_numeric($fk) && intval($fk) > 0 && is_scalar($fv)
                && trim(strval($fv)) !== '') {
                $f['custom_fields'][intval($fk)] = trim(strval($fv));
            }
        }
    }
    return $f;
}

/** Compute the effective filter set: request params override, else session. */
function treeEffectiveFilters($tprojectId, $fromRequest, $reset) {
    $key = 'testSpecFilters_' . intval($tprojectId);
    if ($reset) {
        unset($_SESSION[$key]);
        return [];
    }
    if (is_array($fromRequest) && count($fromRequest) > 0) {
        $_SESSION[$key] = $fromRequest;
        return $fromRequest;
    }
    return (isset($_SESSION[$key]) && is_array($_SESSION[$key]))
        ? $_SESSION[$key] : [];
}

/**
 * All project testcase node-ids reachable from the project root
 * (BFS over nodes_hierarchy, same walk as the tree builder).
 */
function treeProjectTestcaseIds($tprojectId, $childMap, $nodeTypes) {
    $tcaseType = intval($nodeTypes['testcase'] ?? 3);
    $out = [];
    $queue = [intval($tprojectId)];
    $seen = [intval($tprojectId) => true];
    while (count($queue) > 0) {
        $cur = array_shift($queue);
        foreach ($childMap[$cur] ?? [] as $cid) {
            $cid = intval($cid);
            if (isset($seen[$cid])) { continue; }
            $seen[$cid] = true;
            $queue[] = $cid;
            $ntype = intval($nodeTypes['byType'][$cid] ?? -1);
            if ($ntype === $tcaseType) { $out[] = $cid; }
        }
    }
    return $out;
}

/**
 * Compute the set of testcase node-ids that pass the active filters.
 * Acts on the LATEST tcversion (latest_tcase_version_id view).
 * Returns null when no tc-level filter is active (whole tree is shown),
 * otherwise an associative array id => true (ids of the full project set
 * that were NOT filtered out).
 */
function treeMatchTestcases($dbHandler, $tprojectId, $tcIds, $filters, $nodeTypes, $tables) {
    $tcaseType = intval($nodeTypes['testcase'] ?? 3);
    $universe = array_fill_keys($tcIds, true);
    if (count($universe) === 0) { return $universe; }

    $byName = $filters['testcase_name'] ?? null;
    $byTcId = $filters['tc_id'] ?? null;
    $byKw = $filters['keywords'] ?? null;
    $byKwType = $filters['keywords_type'] ?? 'OR';
    $byPlat = $filters['platforms'] ?? null;
    $byActive = $filters['active_inactive'] ?? null;
    $byImp = $filters['importance'] ?? null;
    $byExec = $filters['execution_type'] ?? null;
    $byStatus = $filters['workflow_status'] ?? null;
    $byCf = $filters['custom_fields'] ?? null;

    if (!$byName && !$byTcId && !$byKw && !$byPlat && !$byActive
        && !$byImp && !$byExec && !$byStatus && !$byCf) {
        return null;
    }

    $match = $universe;
    $nh = $tables['nodes_hierarchy'];
    $tcv = $tables['tcversions'];
    $vLatest = 'latest_tcase_version_id';

    // filter by testcase name (node name substring, like legacy)
    if ($byName !== null) {
        $like = $dbHandler->prepare_string($byName);
        $rs = $dbHandler->get_recordset(
            " SELECT NH.id AS tc_id FROM {$nh} NH " .
            " WHERE NH.node_type_id = {$tcaseType} " .
            " AND NH.name LIKE '%{$like}%'");
        $set = [];
        foreach ((array)$rs as $r) { $set[intval($r['tc_id'])] = true; }
        $match = array_intersect_key($match, $set);
    }

    // filter by test case id: internal node id and/or external id (prefix-aware)
    if ($byTcId !== null) {
        $tok = strval($byTcId);
        if (is_numeric($tok)) {
            $idx = intval($tok);
            $rs = $dbHandler->get_recordset(
                " SELECT NH.parent_id AS tc_id FROM {$nh} NH " .
                " JOIN {$vLatest} LTVC ON LTVC.tcversion_id = NH.id " .
                " JOIN {$tcv} TCV ON TCV.id = NH.id " .
                " WHERE NH.parent_id = {$idx} OR TCV.tc_external_id = {$idx} ");
        } else {
            $like = $dbHandler->prepare_string($tok);
            $rs = $dbHandler->get_recordset(
                " SELECT NH.parent_id AS tc_id FROM {$nh} NH " .
                " JOIN {$vLatest} LTVC ON LTVC.tcversion_id = NH.id " .
                " JOIN {$tcv} TCV ON TCV.id = NH.id " .
                " WHERE CAST(TCV.tc_external_id AS CHAR) LIKE '{$like}%' ");
        }
        $set = [];
        foreach ((array)$rs as $r) { $set[intval($r['tc_id'])] = true; }
        $match = array_intersect_key($match, $set);
    }

    // filter by keywords (latest version; Or / And / NotLinked semantics)
    if ($byKw !== null) {
        $kwIn = implode(',', $byKw);
        $set = [];
        if ($byKwType === 'AND') {
            $rs = $dbHandler->get_recordset(
                " SELECT FOX.testcase_id FROM ( " .
                "   SELECT MAX(TK.testcase_id) AS testcase_id, COUNT(*) AS HITS " .
                "   FROM testcase_keywords TK " .
                "   JOIN {$vLatest} LTVC ON LTVC.tcversion_id = TK.tcversion_id " .
                "   WHERE TK.keyword_id IN ({$kwIn}) " .
                "   GROUP BY TK.tcversion_id ) AS FOX " .
                " WHERE FOX.HITS = " . intval(count($byKw)));
        } elseif ($byKwType === 'NOTLINKED') {
            $rs = $dbHandler->get_recordset(
                " SELECT NHTCV.parent_id AS testcase_id FROM {$nh} NHTCV " .
                " JOIN {$vLatest} LTCV ON NHTCV.id = LTCV.tcversion_id " .
                " WHERE NOT EXISTS (SELECT 1 FROM testcase_keywords TCK " .
                "                  WHERE TCK.tcversion_id = LTCV.tcversion_id " .
                "                  AND TCK.keyword_id IN ({$kwIn}))");
        } else { // OR
            $rs = $dbHandler->get_recordset(
                " SELECT TK.testcase_id FROM testcase_keywords TK " .
                " JOIN {$vLatest} LTVC ON LTVC.tcversion_id = TK.tcversion_id " .
                " WHERE TK.keyword_id IN ({$kwIn}) ");
        }
        foreach ((array)$rs as $r) {
            $set[intval($r['testcase_id'])] = true;
        }
        $match = array_intersect_key($match, $set);
    }

    // filter by platforms (latest version; any-of)
    if ($byPlat !== null) {
        $platIn = implode(',', $byPlat);
        $rs = $dbHandler->get_recordset(
            " SELECT TP.testcase_id FROM testcase_platforms TP " .
            " JOIN {$vLatest} LTVC ON LTVC.tcversion_id = TP.tcversion_id " .
            " WHERE TP.platform_id IN ({$platIn}) ");
        $set = [];
        foreach ((array)$rs as $r) { $set[intval($r['testcase_id'])] = true; }
        $match = array_intersect_key($match, $set);
    }

    // active / inactive (by presence of any active version)
    if ($byActive !== null) {
        $rs = $dbHandler->get_recordset(
            " SELECT DISTINCT NH.parent_id AS tc_id FROM {$nh} NH " .
            " JOIN {$tcv} TCV ON TCV.id = NH.id " .
            " WHERE TCV.active = 1 ");
        $activeSet = [];
        foreach ((array)$rs as $r) { $activeSet[intval($r['tc_id'])] = true; }
        if ($byActive === 1) { // active only
            $match = array_intersect_key($match, $activeSet);
        } else { // inactive only
            $match = array_diff_key($match, $activeSet);
        }
    }

    // importance / execution_type / workflow_status on the latest version
    foreach (array('importance' => 'importance', 'execution_type' => 'execution_type',
                   'workflow_status' => 'status') as $fk => $col) {
        $val = $filters[$fk] ?? null;
        if ($val === null) { continue; }
        $rs = $dbHandler->get_recordset(
            " SELECT NH.parent_id AS tc_id FROM {$nh} NH " .
            " JOIN {$vLatest} LTVC ON LTVC.tcversion_id = NH.id " .
            " JOIN {$tcv} TCV ON TCV.id = NH.id " .
            " WHERE TCV.{$col} = " . intval($val));
        $set = [];
        foreach ((array)$rs as $r) { $set[intval($r['tc_id'])] = true; }
        $match = array_intersect_key($match, $set);
    }

    // custom fields: design values stored on the (latest) tcversion
    if ($byCf !== null && count($byCf) > 0) {
        foreach ($byCf as $fieldId => $needle) {
            $like = $dbHandler->prepare_string(substr($needle, 0, 60));
            $rs = $dbHandler->get_recordset(
                " SELECT NH.parent_id AS tc_id FROM cfield_design_values CDV " .
                " JOIN {$nh} NH ON NH.id = CDV.node_id " .
                " JOIN {$vLatest} LTVC ON LTVC.tcversion_id = CDV.node_id " .
                " WHERE CDV.field_id = " . intval($fieldId) .
                " AND CDV.value != '' AND CDV.value LIKE '%{$like}%' ");
            $set = [];
            foreach ((array)$rs as $r) { $set[intval($r['tc_id'])] = true; }
            $match = array_intersect_key($match, $set);
        }
    }

    return $match;
}

/**
 * Top-level suite restriction: returns the id of the single top-level
 * suite to KEEP (legacy select "all" = 0/no filter), or null when the
 * whole tree should be shown.
 */
function treeToplevelSuiteKeep($filters, $childMap, $tprojectId, $nodeTypes) {
    if (empty($filters['toplevel_suite'])) { return null; }
    $suiteType = intval($nodeTypes['testsuite'] ?? 2);
    foreach ($childMap[intval($tprojectId)] ?? [] as $cid) {
        $cid = intval($cid);
        if (intval($nodeTypes['byType'][$cid] ?? -1) === $suiteType
            && $cid === intval($filters['toplevel_suite'])) {
            return $cid;
        }
    }
    return null;
}

$action = $_GET['action'] ?? '';

$tcaseMgr = new testcase($db);
$tprojectMgr = new testproject($db);
$tcaseCfg = config_get('testcase_cfg');

// ---------------------------------------------------------------------------
// GET ?action=context&tproject_id=N
// Everything the viewer needs about the environment (grants, options).
// ---------------------------------------------------------------------------
if ($action === 'context') {
    $tprojectId = getIntParam('tproject_id');
    if ($tprojectId <= 0) {
        $tprojectId = intval($_SESSION['testprojectID'] ?? 0);
    }
    if ($tprojectId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test project id']);
    }
    $info = $tprojectMgr->get_by_id($tprojectId);
    if (!$info) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Test project not found']);
    }
    if (!$user->hasRight($db, 'mgt_view_tc', $tprojectId)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }

    $opt = $tprojectMgr->getOptions($tprojectId);
    $opt = is_null($opt) ? new stdClass() : $opt;

    // test plans available on this project (for "Add to test plan" button)
    $hasTestPlans = false;
    $tables = tlObjectWithDB::getDBTables(array('nodes_hierarchy', 'testplans'));
    $sql = " SELECT COUNT(0) AS qty FROM {$tables['testplans']} TP " .
           " JOIN {$tables['nodes_hierarchy']} NH ON NH.id = TP.id " .
           " WHERE NH.parent_id = " . intval($tprojectId);
    try {
        $hasTestPlans = intval($db->fetchOneValue($sql)) > 0;
    } catch (Exception $e) {
        $hasTestPlans = false;
    }

    $grantKeys = array('mgt_modify_tc', 'mgt_view_req', 'mgt_modify_req',
        'testplan_planning', 'mgt_modify_product', 'testcase_freeze',
        'keyword_assignment', 'req_tcase_link_management',
        'testproject_edit_executed_testcases',
        'testproject_delete_executed_testcases',
        'testproject_add_remove_keywords_executed_tcversions',
        'delete_frozen_tcversion');
    $grants = [];
    foreach ($grantKeys as $gk) {
        $grants[$gk] = $user->hasRight($db, $gk, $tprojectId) ? 1 : 0;
    }

    // --- edit-mode tree filter panel data (port of legacy tree_filter_cfg) ---
    $filterOptions = ['enabled' => false, 'keywords' => [], 'platforms' => [],
                      'toplevelSuites' => [], 'customFields' => [], 'statusDomain' => []];
    try {
        $tFilterCfg = config_get('tree_filter_cfg');
        $editCfg = $tFilterCfg->testcases->edit_mode ?? null;
        $filterOptions['enabled'] = intval($editCfg->show_filters ?? 0) === 1;
        $filterOptions['statusDomain'] = tcStatusDomain();
        if ($filterOptions['enabled']) {
            $cfgKeyMap = array('filter_tc_id' => 'filter_tc_id',
                'filter_testcase_name' => 'filter_testcase_name',
                'filter_toplevel_testsuite' => 'filter_toplevel_testsuite',
                'filter_keywords' => 'filter_keywords',
                'filter_platforms' => 'filter_platforms',
                'filter_active_inactive' => 'filter_active_inactive',
                'filter_importance' => 'filter_importance',
                'filter_execution_type' => 'filter_execution_type',
                'filter_workflow_status' => 'filter_workflow_status',
                'filter_custom_fields' => 'filter_custom_fields');
            foreach ($cfgKeyMap as $hint => $cfgKey) {
                $filterOptions[$hint] = intval($editCfg->{$cfgKey} ?? 0) === 1;
            }
            $nhT = tlObjectWithDB::getDBTables(
                array('nodes_hierarchy', 'node_types', 'keywords', 'platforms', 'custom_fields', 'cfield_testprojects'));
            $kwRows = $db->get_recordset(
                " SELECT KW.id, KW.keyword FROM {$nhT['keywords']} KW " .
                " WHERE KW.testproject_id = " . intval($tprojectId) . " ORDER BY KW.keyword");
            foreach ((array)$kwRows as $kr) {
                $filterOptions['keywords'][intval($kr['id'])] = strval($kr['keyword']);
            }
            $platRows = $db->get_recordset(
                " SELECT PL.id, PL.name FROM {$nhT['platforms']} PL " .
                " WHERE PL.testproject_id = " . intval($tprojectId) .
                " AND PL.enable_on_design = 1 ORDER BY PL.name");
            foreach ((array)$platRows as $pr) {
                $filterOptions['platforms'][intval($pr['id'])] = strval($pr['name']);
            }
            $typeRows2 = $db->get_recordset(
                " SELECT id, description FROM {$nhT['node_types']} " .
                " WHERE description IN ('testsuite','testcase')");
            $tsuiteType = 2; $tcaseType = 3;
            foreach ((array)$typeRows2 as $tr) {
                if ($tr['description'] === 'testsuite') { $tsuiteType = intval($tr['id']); }
                if ($tr['description'] === 'testcase') { $tcaseType = intval($tr['id']); }
            }
            $suiteRows = $db->get_recordset(
                " SELECT id, name, node_type_id FROM {$nhT['nodes_hierarchy']} " .
                " WHERE parent_id = " . intval($tprojectId) .
                " AND node_type_id = {$tsuiteType} ORDER BY name");
            $filterOptions['toplevelSuites'][] = ['id' => 0, 'name' => ''];
            foreach ((array)$suiteRows as $sr) {
                $filterOptions['toplevelSuites'][] = ['id' => intval($sr['id']), 'name' => strval($sr['name'])];
            }
            $cfRows = $db->get_recordset(
                " SELECT CF.id, CF.name, CF.label, CF.type, CF.possible_values " .
                " FROM {$nhT['custom_fields']} CF " .
                " JOIN {$nhT['cfield_testprojects']} CTP ON CTP.field_id = CF.id " .
                " WHERE CTP.testproject_id = " . intval($tprojectId) .
                " AND CF.enable_on_design = 1 " .
                " ORDER BY CTP.display_order, CF.id");
            foreach ((array)$cfRows as $cr) {
                $filterOptions['customFields'][] = [
                    'id' => intval($cr['id']),
                    'name' => strval($cr['name']),
                    'label' => strval($cr['label'] ?: $cr['name']),
                    'type' => intval($cr['type']),
                    'possible_values' => strval($cr['possible_values']),
                ];
            }
        }
    } catch (Exception $e) {
        $filterOptions = ['enabled' => false];
    }
    $sessionKey = 'testSpecFilters_' . intval($tprojectId);
    $persistedFilters = (isset($_SESSION[$sessionKey]) && is_array($_SESSION[$sessionKey]))
        ? $_SESSION[$sessionKey] : null;

    out([
        'status' => 'ok',
        'tproject' => ['id' => $tprojectId, 'name' => $info['name']],
        'options' => [
            'requirementsEnabled' => tprojectOpt($opt, 'requirementsEnabled'),
            'automationEnabled' => tprojectOpt($opt, 'automationEnabled'),
            'testPriorityEnabled' => tprojectOpt($opt, 'testPriorityEnabled'),
        ],
        'hasTestPlans' => $hasTestPlans,
        'grants' => $grants,
        'canEditExecuted' => intval($tcaseCfg->canEditExecuted ?? 0),
        'estimateDurationRequired' => isDurationRequired($tcaseCfg),
        'dateFormat' => config_get('date_format'),
        'filterOptions' => $filterOptions,
        'persistedFilters' => $persistedFilters,
    ]);
}

// ---------------------------------------------------------------------------
// GET ?action=view&tcase_id=N[&tcversion_id=M]
// Full read-only payload for the test case viewer.
// ---------------------------------------------------------------------------
if ($action === 'view') {
    $tcaseId = getIntParam('tcase_id');
    $tcversionId = getIntParam('tcversion_id');
    if ($tcaseId <= 0 && $tcversionId > 0) {
        // allow arriving by version id (e.g. deep links)
        $tid = $db->fetchOneValue(
            "SELECT parent_id FROM {$tcaseMgr->tables['nodes_hierarchy']} " .
            "WHERE id = " . intval($tcversionId));
        $tcaseId = intval($tid);
    }
    if ($tcaseId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test case id']);
    }

    // owning test project (may differ from session project - same as legacy).
    // NOTE: testproject::getByChildID() is unreliable here (it reads
    // $path[0]['parent_id'] which is 0 for a root node), so resolve directly.
    $owningProject = null;
    $nhTable = tlObjectWithDB::getDBTables(array('nodes_hierarchy'));
    $tcChain = getParentChain($db, $nhTable['nodes_hierarchy'], $tcaseId);
    if (!empty($tcChain)) {
        $rootId = intval($tcChain[0]['id']);
        if (intval($tcChain[0]['node_type_id']) == 1) {
            $owningProject = $tprojectMgr->get_by_id($rootId);
        }
    }
    if (is_null($owningProject) || !isset($owningProject['id'])) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Test case not found']);
    }
    $tprojectId = intval($owningProject['id']);

    if (!$user->hasRight($db, 'mgt_view_tc', $tprojectId)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }

    $basic = $tcaseMgr->get_basic_info($tcaseId, null);
    if (is_null($basic)) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Test case not found']);
    }
    $first = reset($basic);

    $glue = config_get('testcase_cfg')->glue_character;
    $prefix = $tprojectMgr->getTestCasePrefix($tprojectId);

    // full path (as string) + parent testsuite.
    // NOTE: built from our own parent chain - tree_manager::get_path() /
    // get_full_path_verbose() proved unreliable here.
    $pathString = '';
    if (count($tcChain) > 1) {
        $names = [];
        for ($i = 1; $i < count($tcChain); $i++) { // skip root project node
            $names[] = $tcChain[$i]['name'];
        }
        $pathString = implode(' / ', $names);
    }

    // parent testsuite = second-to-last element of the chain
    $tsuiteName = '';
    $tsuiteId = 0;
    if (count($tcChain) >= 2) {
        $tsuiteNode = $tcChain[count($tcChain) - 2];
        $tsuiteId = intval($tsuiteNode['id']);
        $tsuiteName = strval($tsuiteNode['name']);
    }

    // all versions (or just requested one)
    $opt = array('output' => 'full', 'access_key' => 'tcversion_id');
    if ($tcversionId > 0) {
        $versionsRaw = $tcaseMgr->get_by_id($tcaseId, $tcversionId, null, $opt);
    } else {
        $versionsRaw = $tcaseMgr->get_by_id($tcaseId, testcase::ALL_VERSIONS, null, $opt);
    }
    if (is_null($versionsRaw)) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Test case not found']);
    }

    // users map for author/updater display
    $usersTable = tlObjectWithDB::getDBTables(array('users'));
    $usersMap = [];
    $uRows = $db->fetchRowsIntoMap(
        "SELECT id, login, first, last FROM {$usersTable['users']}", 'id');
    if (!is_null($uRows)) {
        foreach ($uRows as $uid => $ur) {
            $display = trim($ur['first'] . ' ' . $ur['last']);
            $usersMap[intval($uid)] = ($display != '') ? ($display . ' (' . $ur['login'] . ')') : $ur['login'];
        }
    }

    // which versions have been executed (drives warnings, like legacy)
    $executedSet = [];
    $tcvIds = array_map('intval', array_keys($versionsRaw));
    if (count($tcvIds) > 0) {
        $execTable = tlObjectWithDB::getDBTables(array('executions'));
        $rs = $db->get_recordset(
            "SELECT DISTINCT tcversion_id FROM {$execTable['executions']} " .
            "WHERE tcversion_id IN (" . implode(',', $tcvIds) . ")");
        if (!is_null($rs)) {
            foreach ($rs as $r) {
                $executedSet[intval($r['tcversion_id'])] = 1;
            }
        }
    }

    // latest version number must be computed over ALL versions,
    // even when the payload was filtered to a single requested version
    $latestVersionNumber = 0;
    $tvTables = tlObjectWithDB::getDBTables(array('nodes_hierarchy', 'tcversions'));
    $lvRow = $db->fetchFirstRow(
        " SELECT MAX(TCV.version) AS vmax FROM {$tvTables['tcversions']} TCV " .
        " JOIN {$tvTables['nodes_hierarchy']} NH ON NH.id = TCV.id " .
        " WHERE NH.parent_id = {$tcaseId}");
    if (!is_null($lvRow) && isset($lvRow['vmax'])) {
        $latestVersionNumber = intval($lvRow['vmax']);
    }

    $versions = [];
    foreach ($versionsRaw as $tcvx => $vr) {
        // access_key is not always honored -> prefer the row's own id
        $tcvx = intval($vr['id'] ?? $tcvx);

        // steps (output=full normally embeds them; fallback to direct SQL)
        $steps = [];
        if (isset($vr['steps']) && !is_null($vr['steps']) && is_array($vr['steps'])) {
            foreach ($vr['steps'] as $st) {
                $steps[] = [
                    'step_number' => intval($st['step_number']),
                    'actions' => (string)$st['actions'],
                    'expected_results' => (string)$st['expected_results'],
                    'execution_type' => intval($st['execution_type']),
                    'id' => intval($st['id'] ?? 0),
                ];
            }
        } else {
            $stTables = tlObjectWithDB::getDBTables(array('nodes_hierarchy', 'tcsteps'));
            $srs = $db->get_recordset(
                " SELECT TCSTEPS.id, TCSTEPS.step_number, TCSTEPS.actions, " .
                "        TCSTEPS.expected_results, TCSTEPS.execution_type " .
                " FROM {$stTables['nodes_hierarchy']} NH_ST " .
                " JOIN {$stTables['tcsteps']} TCSTEPS ON NH_ST.id = TCSTEPS.id " .
                " WHERE NH_ST.parent_id = {$tcvx} ORDER BY TCSTEPS.step_number");
            if (!is_null($srs)) {
                foreach ($srs as $st) {
                    $steps[] = [
                        'step_number' => intval($st['step_number']),
                        'actions' => (string)$st['actions'],
                        'expected_results' => (string)$st['expected_results'],
                        'execution_type' => intval($st['execution_type']),
                        'id' => intval($st['id']),
                    ];
                }
            }
        }

        // keywords assigned to THIS version
        $keywords = [];
        $kwMap = $tcaseMgr->getKeywords($tcaseId, $tcvx);
        if (!is_null($kwMap)) {
            foreach ($kwMap as $kwo) {
                $kwo = (array)$kwo;
                $keywords[] = [
                    'id' => intval($kwo['keyword_id'] ?? ($kwo['id'] ?? 0)),
                    'name' => strval($kwo['keyword'] ?? ($kwo['name'] ?? '')),
                ];
            }
        }

        // platforms assigned to THIS version (gap #915) + edit capability
        // (legacy platforms.inc.tpl remove-enabled flag).
        $platforms = versionPlatformAssignments($db, $tcaseId, $tcvx);
        $versionCanAssign = canAssignPlatforms(
            $user, $db, $tprojectId,
            intval($vr['is_open'] ?? 1), isset($executedSet[$tcvx]));

        // custom fields with design-time values for this version
        $customFields = [];
        try {
            $cfMap = $tcaseMgr->get_linked_cfields_at_design(
                $tcaseId, $tcvx, null, null, $tprojectId);
            if (!is_null($cfMap)) {
                foreach ($cfMap as $cf) {
                    $val = isset($cf['value']) ? $cf['value'] : null;
                    if (is_array($val)) {
                        $val = implode(', ', $val);
                    }
                    $customFields[] = [
                        'id' => intval($cf['id']),
                        'label' => $cf['label'],
                        'type' => intval($cf['type']),
                        'value' => ($val === null || $val === '') ? '' : (string)$val,
                    ];
                }
            }
        } catch (Exception $e) {
            // no custom fields available - keep empty
        }

        // attachments of this version
        $attachments = [];
        if (function_exists('getAttachmentInfosFrom')) {
            $attMap = getAttachmentInfosFrom($tcaseMgr, $tcvx);
            if (!is_null($attMap)) {
                foreach ($attMap as $ai) {
                    $attachments[] = [
                        'id' => intval($ai['id']),
                        'title' => $ai['title'],
                        'file_name' => $ai['file_name'],
                        'file_size' => intval($ai['file_size']),
                        'file_type' => isset($ai['file_type']) ? $ai['file_type'] : '',
                        'date_added' => isset($ai['date_added']) ? (string)$ai['date_added'] : '',
                    ];
                }
            }
        }

        $authorId = intval($vr['author_id'] ?? 0);
        $updaterId = intval($vr['updater_id'] ?? 0);

        $versions[] = [
            'tcversion_id' => $tcvx,
            'version' => intval($vr['version']),
            'summary' => (string)($vr['summary'] ?? ''),
            'preconditions' => (string)($vr['preconditions'] ?? ''),
            'importance' => intval($vr['importance'] ?? 0),
            'status' => intval($vr['status'] ?? 0),
            'execution_type' => intval($vr['execution_type'] ?? 1),
            'active' => intval($vr['active'] ?? 0),
            'is_open' => intval($vr['is_open'] ?? 0),
            'estimated_exec_duration' => isset($vr['estimated_exec_duration'])
                && $vr['estimated_exec_duration'] !== null
                ? floatval($vr['estimated_exec_duration']) : null,
            'author' => isset($usersMap[$authorId]) ? $usersMap[$authorId] : '',
            'updater' => $updaterId > 0 && isset($usersMap[$updaterId]) ? $usersMap[$updaterId] : '',
            'creation_ts' => (string)($vr['creation_ts'] ?? ''),
            'modification_ts' => (string)($vr['modification_ts'] ?? ''),
            'is_latest' => intval($vr['version']) === $latestVersionNumber,
            'has_been_executed' => isset($executedSet[$tcvx]),
            'steps' => $steps,
            'keywords' => $keywords,
            'platforms' => $platforms,
            'canAssignPlatforms' => $versionCanAssign,
            'customFields' => $customFields,
            'attachments' => $attachments,
        ];
    }

    // requirements coverage (only when enabled + right to view)
    $requirements = [];
    $opt = $tprojectMgr->getOptions($tprojectId);
    $opt = is_null($opt) ? new stdClass() : $opt;
    $canViewReq = tprojectOpt($opt, 'requirementsEnabled')
        && $user->hasRight($db, 'mgt_view_req', $tprojectId);
    if ($canViewReq) {
        try {
            // NOTE: requirement_mgr::get_all_for_tcase() does not expose the
            // linked version, so resolve coverage directly.
            $rqTables = tlObjectWithDB::getDBTables(
                array('requirements', 'req_versions', 'req_coverage', 'req_specs', 'nodes_hierarchy'));
            $rqSql = " SELECT RC.tcversion_id, REQ.id, REQ.req_doc_id, " .
                     "        NHA.name AS title, NHB.name AS req_spec_title, " .
                     "        RV.version " .
                     " FROM {$rqTables['req_coverage']} RC " .
                     " JOIN {$rqTables['requirements']} REQ ON REQ.id = RC.req_id " .
                     " JOIN {$rqTables['nodes_hierarchy']} NHA ON NHA.id = REQ.id " .
                     " LEFT JOIN {$rqTables['nodes_hierarchy']} NHB ON NHB.id = REQ.srs_id " .
                     " LEFT JOIN {$rqTables['req_versions']} RV ON RV.id = RC.req_version_id " .
                     " WHERE RC.testcase_id = {$tcaseId}";
            $rqRows = $db->get_recordset($rqSql);
            if (!is_null($rqRows)) {
                foreach ($rqRows as $rq) {
                    $requirements[intval($rq['tcversion_id'])][] = [
                        'id' => intval($rq['id']),
                        'req_doc_id' => strval($rq['req_doc_id']),
                        'title' => strval($rq['title']),
                        'version' => intval($rq['version'] ?? 1),
                        'req_spec_title' => strval($rq['req_spec_title'] ?? ''),
                    ];
                }
            }
        } catch (Exception $e) {
            // coverage not available
        }
    }

    // relations between test cases.
    // NOTE: testcase::getRelations() dies on a DB error in this environment
    // (it re-resolves the prefix with a NULL project), so query directly.
    $relations = [];
    try {
        $relTables = tlObjectWithDB::getDBTables(
            array('testcase_relations', 'relation_type', 'nodes_hierarchy'));
        $relSql = " SELECT TR.id, TR.source_id, TR.destination_id, " .
                  "        TR.relation_type, TR.link_status, RT.description " .
                  " FROM {$relTables['testcase_relations']} TR " .
                  " JOIN {$relTables['relation_type']} RT ON RT.id = TR.relation_type " .
                  " WHERE TR.source_id = {$tcaseId} OR TR.destination_id = {$tcaseId}";
        $relRows = $db->get_recordset($relSql);
        if (!is_null($relRows)) {
            foreach ($relRows as $rr) {
                $otherId = intval($rr['source_id']) === $tcaseId
                    ? intval($rr['destination_id']) : intval($rr['source_id']);
                $otherName = '';
                $nr = $db->fetchFirstRow(
                    "SELECT name FROM {$relTables['nodes_hierarchy']} WHERE id = {$otherId}");
                if (!is_null($nr) && isset($nr['name'])) {
                    $otherName = $nr['name'];
                }
                $relations[] = [
                    'id' => intval($rr['id']),
                    'type' => strval($rr['description']),
                    'is_source' => intval($rr['source_id']) === $tcaseId,
                    'related_tcase_id' => $otherId,
                    'related_tcase_name' => $otherName,
                ];
            }
        }
    } catch (Exception $e) {
        // relations not available
    }

    // test plans available on this project (for "Add to test plan" button)
    $hasTestPlans = false;
    $tpTables = tlObjectWithDB::getDBTables(array('nodes_hierarchy', 'testplans'));
    $sql = " SELECT COUNT(0) AS qty FROM {$tpTables['testplans']} TP " .
           " JOIN {$tpTables['nodes_hierarchy']} NH ON NH.id = TP.id " .
           " WHERE NH.parent_id = " . intval($tprojectId);
    try {
        $hasTestPlans = intval($db->fetchOneValue($sql)) > 0;
    } catch (Exception $e) {
        $hasTestPlans = false;
    }

    $grants = [];
    foreach (array('mgt_modify_tc', 'mgt_view_req', 'testcase_freeze',
                   'keyword_assignment', 'req_tcase_link_management',
                   'testplan_planning') as $gk) {
        $grants[$gk] = $user->hasRight($db, $gk, $tprojectId) ? 1 : 0;
    }

    out([
        'status' => 'ok',
        'tcase' => [
            'id' => $tcaseId,
            'name' => $first['name'],
            'parent_id' => intval($first['parent_id'] ?? 0),
            'node_order' => intval($first['node_order'] ?? 0),
        ],
        'tproject' => ['id' => $tprojectId, 'name' => $owningProject['name']],
        'testsuite' => ['id' => $tsuiteId, 'name' => $tsuiteName],
        'prefix' => $prefix,
        'glue' => $glue,
        'fullExternalId' => $prefix . $glue . intval($first['tc_external_id'] ?? 0),
        'path' => $pathString,
        'versions' => $versions,
        'platformsProject' => projectPlatforms($db, $tprojectId),
        'requirements' => $requirements,
        'requirementsEnabled' => tprojectOpt($opt, 'requirementsEnabled'),
        'testPriorityEnabled' => tprojectOpt($opt, 'testPriorityEnabled'),
        'automationEnabled' => tprojectOpt($opt, 'automationEnabled'),
        'relations' => $relations,
        'grants' => $grants,
        'hasTestPlans' => $hasTestPlans,
        'requestedTcversionId' => $tcversionId,
    ]);
}

// ---------------------------------------------------------------------------
// Test Specification screen (editTc/testSpec): tree + editor.
// Mirrors lib/testcases/tcEdit.php + lib/ajax/gettprojectnodes.php (1.9.20)
// ---------------------------------------------------------------------------

function getJsonBody() {
    $raw = file_get_contents('php://input');
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

/**
 * TC status workflow domain (draft..final) from global config.
 * Returns map code => config key (e.g. 1 => 'draft'), mirroring legacy
 * $tlCfg->testCaseStatus (cfg/const.inc.php). Client renders labels via
 * i18n keys tcview.status<code> (same domain used by tcView.html).
 */
function tcStatusDomain() {
    $cfg = config_get('testCaseStatus');
    if (!is_array($cfg) || !count($cfg)) {
        // fallback to the standard 1.9.20 domain
        $cfg = array('draft' => 1, 'readyForReview' => 2, 'reviewInProgress' => 3,
                     'rework' => 4, 'obsolete' => 5, 'future' => 6, 'final' => 7);
    }
    $domain = [];
    foreach ($cfg as $key => $code) {
        $code = intval($code);
        if ($code > 0) { $domain[$code] = strval($key); }
    }
    ksort($domain);
    return $domain;
}

/** Validate a submitted TC status code against the configured domain. */
function normalizeTcStatus($value) {
    $code = intval($value);
    $domain = tcStatusDomain();
    return isset($domain[$code]) ? $code : null;
}

function jout($data, $code = 200) {
    http_response_code($code);
    out($data);
}

/**
 * Resolve owning test project of any node via parent chain.
 */
function owningProjectOf($dbHandler, $tprojectMgr, $nodeId) {
    $nh = tlObjectWithDB::getDBTables(array('nodes_hierarchy'));
    $chain = getParentChain($dbHandler, $nh['nodes_hierarchy'], $nodeId);
    if (empty($chain)) {
        return null;
    }
    $root = $chain[0];
    if (intval($root['node_type_id']) != 1) {
        return null;
    }
    return intval($root['id']);
}

/**
 * Project platforms as {id: {name, enable_on_design}}. Mirrors the legacy
 * assign-UI source: tlPlatform list of the owning project filtered to
 * enable_on_design=1 (design-visible platforms). The full flag set is
 * returned so callers can reproduce the "free platforms" computation.
 */
function projectPlatforms($dbHandler, $tprojectId) {
    $tables = tlObjectWithDB::getDBTables(array('platforms'));
    $rows = $dbHandler->get_recordset(
        " SELECT id, name, enable_on_design FROM {$tables['platforms']} " .
        " WHERE testproject_id = " . intval($tprojectId) .
        " ORDER BY name ASC");
    $map = [];
    if (!is_null($rows)) {
        foreach ($rows as $r) {
            $map[intval($r['id'])] = [
                'name' => strval($r['name']),
                'enable_on_design' => intval($r['enable_on_design']),
            ];
        }
    }
    return $map;
}

/**
 * Per-version platform assignments incl. the testcase_platforms link id
 * (tcplat_link), mirroring testcase::getPlatforms() (tcEdit platforms.inc).
 */
function versionPlatformAssignments($dbHandler, $tcaseId, $tcversionId) {
    $tables = tlObjectWithDB::getDBTables(array('testcase_platforms', 'platforms'));
    $rows = $dbHandler->get_recordset(
        " SELECT TCPL.id AS tcplat_link, TCPL.platform_id, PL.name " .
        " FROM {$tables['testcase_platforms']} TCPL " .
        " JOIN {$tables['platforms']} PL ON PL.id = TCPL.platform_id " .
        " WHERE TCPL.testcase_id = " . intval($tcaseId) .
        " AND TCPL.tcversion_id = " . intval($tcversionId) .
        " ORDER BY TCPL.id ASC");
    $out = [];
    if (!is_null($rows)) {
        foreach ($rows as $r) {
            $out[] = [
                'id' => intval($r['platform_id']),
                'name' => strval($r['name']),
                'tcplat_link' => intval($r['tcplat_link']),
                'enable_on_design' => 1,
            ];
        }
    }
    return $out;
}

/**
 * Whether platform assignments of a version may be changed. Mirrors the
 * legacy platRW flag (tcView_viewer.tpl): edit right required, version must
 * not be frozen, and executed versions need the exec-edit right/config.
 */
function canAssignPlatforms($user, $dbHandler, $tprojId, $isOpen, $executed) {
    if (!$user->hasRight($dbHandler, 'mgt_modify_tc', $tprojId)) {
        return false;
    }
    if (intval($isOpen) === 0) { // frozen
        return false;
    }
    if ($executed) {
        if ($user->hasRight($dbHandler, 'testproject_edit_executed_testcases', $tprojId)) {
            return true;
        }
        if (intval(config_get('testcase_cfg')->canEditExecuted ?? 0) > 0) {
            return true;
        }
        return false;
    }
    return true;
}

/**
 * Design-visible platforms NOT yet assigned to (tcase_id, tcversion_id).
 * Mirrors legacy testcase::getFreePlatforms(): enable_on_design=1 minus the
 * already-linked set. Returns {id: name}.
 */
function platformFreeList($platformsProject, $platformsAssigned) {
    $free = [];
    foreach ($platformsProject as $pid => $info) {
        if (intval($info['enable_on_design'] ?? 0) === 0) {
            continue;
        }
        if (!isset($platformsAssigned[$pid])) {
            $free[$pid] = $info['name'];
        }
    }
    return $free;
}

/** Whether a tcversion already has executions (drives platform edit gating). */
function versionHasExecutions($dbHandler, $tcversionId) {
    $tables = tlObjectWithDB::getDBTables(array('executions'));
    $row = $dbHandler->fetchFirstRow(
        "SELECT id FROM {$tables['executions']} " .
        "WHERE tcversion_id = " . intval($tcversionId) . " LIMIT 1");
    return !is_null($row) && isset($row['id']);
}

/**
 * Resolve a tcversion id for a given test case id (defaults to the latest
 * version). Used by the add/remove platform write actions.
 */
function resolveTcversionForPlatform($dbHandler, $tcaseId, $tcversionId) {
    if (intval($tcversionId) > 0) {
        $row = $dbHandler->fetchFirstRow(
            " SELECT TCV.id, TCV.is_open, TCV.active FROM " .
            tlObjectWithDB::getDBTables(array('tcversions'))['tcversions'] . " TCV " .
            " JOIN " . tlObjectWithDB::getDBTables(array('nodes_hierarchy'))['nodes_hierarchy'] . " NH " .
            "   ON NH.id = TCV.id " .
            " WHERE TCV.id = " . intval($tcversionId) .
            " AND NH.parent_id = " . intval($tcaseId));
        return $row;
    }
    return $dbHandler->fetchFirstRow(
        " SELECT TCV.id, TCV.is_open, TCV.active FROM " .
        tlObjectWithDB::getDBTables(array('tcversions'))['tcversions'] . " TCV " .
        " JOIN " . tlObjectWithDB::getDBTables(array('nodes_hierarchy'))['nodes_hierarchy'] . " NH " .
        "   ON NH.id = TCV.id " .
        " WHERE NH.parent_id = " . intval($tcaseId) .
        " ORDER BY TCV.version DESC LIMIT 1");
}

if ($action === 'tree') {
    $tprojectId = getIntParam('tproject_id');
    if ($tprojectId <= 0) {
        $tprojectId = intval($_SESSION['testprojectID'] ?? 0);
    }
    if ($tprojectId <= 0) {
        jout(['status' => 'error', 'message' => 'Invalid test project id'], 400);
    }
    $info = $tprojectMgr->get_by_id($tprojectId);
    if (!$info) {
        jout(['status' => 'error', 'message' => 'Test project not found'], 404);
    }
    if (!$user->hasRight($db, 'mgt_view_tc', $tprojectId)) {
        jout(['status' => 'error', 'message' => 'No permission'], 403);
    }

    $tables = tlObjectWithDB::getDBTables(
        array('nodes_hierarchy', 'node_types', 'tcversions'));
    $typeRows = $db->get_recordset(
        "SELECT id, description FROM {$tables['node_types']} " .
        "WHERE description IN ('testproject','testsuite','testcase')");
    $types = [];
    foreach ((array)$typeRows as $tr) {
        $types[$tr['description']] = intval($tr['id']);
    }
    $tsuiteType = $types['testsuite'] ?? 2;
    $tcaseType = $types['testcase'] ?? 3;

    $allRows = $db->get_recordset(
        "SELECT id, parent_id, name, node_type_id, node_order " .
        "FROM {$tables['nodes_hierarchy']} ORDER BY node_order, id");
    $childMap = [];
    $nodesById = [];
    if (!is_null($allRows)) {
        foreach ($allRows as $r) {
            $nid = intval($r['id']);
            $nodesById[$nid] = $r;
            $childMap[intval($r['parent_id'])][] = $nid;
        }
    }

    // test case aggregates: version count, external id, active flag
    $tcAgg = [];
    $rsAgg = $db->get_recordset(
        " SELECT NH.parent_id AS tcase_id, COUNT(TCV.id) AS nver, " .
        " MAX(TCV.tc_external_id) AS extid, MAX(TCV.version) AS vmax, " .
        " MIN(TCV.active) AS ever_active " .
        "FROM {$tables['tcversions']} TCV " .
        " JOIN {$tables['nodes_hierarchy']} NH ON NH.id = TCV.id " .
        " GROUP BY NH.parent_id");
    if (!is_null($rsAgg)) {
        foreach ($rsAgg as $ra) {
            $tcAgg[intval($ra['tcase_id'])] = $ra;
        }
    }

    // --- edit-mode filters (session-persisted, mirror legacy) ---
    $nodeTypesByType = ['testsuite' => $tsuiteType, 'testcase' => $tcaseType];
    $byType = [];
    foreach ($nodesById as $nid => $nrow) {
        $byType[$nid] = intval($nrow['node_type_id']);
    }
    $nodeTypesByType['byType'] = $byType;
    $resetFilters = intval($_GET['reset_filters'] ?? 0) === 1;
    if (treeFiltersEnabled()) {
        $filters = treeEffectiveFilters(
            $tprojectId, treeFiltersFromInput(), $resetFilters);
    } else {
        unset($_SESSION['testSpecFilters_' . intval($tprojectId)]);
        $filters = [];
    }
    $filtersActive = count($filters) > 0;

    $tcIds = treeProjectTestcaseIds($tprojectId, $childMap, $nodeTypesByType);
    $match = $filtersActive
        ? treeMatchTestcases($db, $tprojectId, $tcIds, $filters, $nodeTypesByType, $tables)
        : null;
    $keepSuiteId = treeToplevelSuiteKeep($filters, $childMap, $tprojectId, $nodeTypesByType);

    // iterative subtree walk (BFS) building nested structure
    $build = function($pid) use (&$build, $childMap, $nodesById,
                                $tsuiteType, $tcaseType, $tcAgg, $match,
                                $filtersActive) {
        $suites = [];
        $testcases = [];
        foreach ($childMap[$pid] ?? [] as $cid) {
            $n = $nodesById[$cid];
            $ntype = intval($n['node_type_id']);
            if ($ntype === $tsuiteType) {
                $children = $build($cid);
                $hasChildren = (count($children['suites']) + count($children['testcases'])) > 0;
                if ($filtersActive && !$hasChildren) {
                    continue; // prune empty suite when filtering
                }
                $suites[] = [
                    'type' => 'testsuite',
                    'id' => $cid,
                    'name' => strval($n['name']),
                    'children' => $children,
                ];
            } elseif ($ntype === $tcaseType) {
                if (!is_null($match) && !isset($match[$cid])) {
                    continue; // filtered out
                }
                $agg = $tcAgg[$cid] ?? null;
                $testcases[] = [
                    'type' => 'testcase',
                    'id' => $cid,
                    'name' => strval($n['name']),
                    'external_id' => intval($agg['extid'] ?? 0),
                    'versions' => intval($agg['nver'] ?? 0),
                    'active' => intval($agg['ever_active'] ?? 1) > 0,
                ];
            }
        }
        usort($suites, function($a, $b) { return strnatcasecmp($a['name'], $b['name']); });
        return ['suites' => $suites, 'testcases' => $testcases];
    };

    $branchId = is_null($keepSuiteId) ? $tprojectId : $keepSuiteId;
    jout([
        'status' => 'ok',
        'tproject' => ['id' => $tprojectId, 'name' => strval($info['name'])],
        'tree' => $build($branchId),
        'filtered' => $filtersActive,
        'filters' => $filters,
        'matchCount' => is_null($match) ? null : count($match),
    ]);
}

if ($action === 'get') {
    $tcaseId = getIntParam('tcase_id');
    if ($tcaseId <= 0) {
        jout(['status' => 'error', 'message' => 'Invalid test case id'], 400);
    }
    $tprojectId = owningProjectOf($db, $tprojectMgr, $tcaseId);
    if (is_null($tprojectId)) {
        jout(['status' => 'error', 'message' => 'Test case not found'], 404);
    }
    if (!$user->hasRight($db, 'mgt_view_tc', $tprojectId)) {
        jout(['status' => 'error', 'message' => 'No permission'], 403);
    }

    $tvTables = tlObjectWithDB::getDBTables(
        array('nodes_hierarchy', 'tcversions', 'tcsteps', 'executions'));
    // optional tcversion_id: edit a SPECIFIC version, not always the latest
    // (version selector port from legacy tcEdit viewer; default = latest).
    $requestedTcversion = getIntParam('tcversion_id');
    $versionFilter = $requestedTcversion > 0
        ? " AND TCV.id = {$requestedTcversion} " : '';
    $lvRow = $db->fetchFirstRow(
        " SELECT TCV.*, NHT.name AS tc_name, NHT.parent_id AS suite_id " .
        " FROM {$tvTables['tcversions']} TCV " .
        " JOIN {$tvTables['nodes_hierarchy']} NH ON NH.id = TCV.id " .
        " JOIN {$tvTables['nodes_hierarchy']} NHT ON NHT.id = NH.parent_id " .
        " WHERE NH.parent_id = {$tcaseId} " . $versionFilter .
        " ORDER BY TCV.version DESC LIMIT 1");
    if (empty($lvRow)) {
        if ($requestedTcversion > 0) {
            // A concrete version was requested but does not belong to this
            // test case (anymore) -> fail loudly instead of silently showing
            // the latest version (which caused an E_WARNING on the fallback
            // and hid the real condition at the #914 version selector).
            jout(['status' => 'error', 'message' => 'Test case version not found'],
                 404);
        }
        // fall back to manager API (handles schema variants)
        $basic = $tcaseMgr->get_basic_info($tcaseId, null);
        if (is_null($basic)) {
            jout(['status' => 'error', 'message' => 'Test case not found'], 404);
        }
        $firstV = reset($basic);
        $lvRow = $db->fetchFirstRow(
            " SELECT * FROM {$tvTables['tcversions']} WHERE id = " .
            intval($firstV['tcversion_id']));
        if (empty($lvRow)) {
            jout(['status' => 'error', 'message' => 'Test case not found'], 404);
        }
    }
    $tcversionId = intval($lvRow['id']);

    $execRs = $db->get_recordset(
        "SELECT DISTINCT tcversion_id FROM {$tvTables['executions']} " .
        "WHERE tcversion_id = {$tcversionId}");
    $executed = !is_null($execRs) && count($execRs) > 0;

    $steps = [];
    $stepRs = $db->get_recordset(
        " SELECT TCS.id, TCS.step_number, TCS.actions, TCS.expected_results, " .
        " TCS.execution_type " .
        "FROM {$tvTables['tcsteps']} TCS " .
        " JOIN {$tvTables['nodes_hierarchy']} NH ON NH.id = TCS.id " .
        " WHERE NH.parent_id = {$tcversionId} " .
        " ORDER BY TCS.step_number");
    if (!is_null($stepRs)) {
        foreach ($stepRs as $sr) {
            $steps[] = [
                'step_number' => intval($sr['step_number']),
                'actions' => strval($sr['actions']),
                'expected_results' => strval($sr['expected_results']),
                'execution_type' => intval($sr['execution_type']),
            ];
        }
    }

    $assignedKw = [];
    try {
        $kwMap = $tcaseMgr->get_keywords_map($tcaseId, $tcversionId);
        if (!is_null($kwMap)) {
            foreach ($kwMap as $kid => $kr) {
                $assignedKw[intval($kid)] = strval(
                    is_array($kr) ? ($kr['keyword'] ?? '') : $kr);
            }
        }
    } catch (Exception $e) {
        $assignedKw = [];
    }

    $projKw = [];
    try {
        $pk = $tprojectMgr->getKeywords($tprojectId);
        if (!is_null($pk)) {
            foreach ($pk as $kwo) {
                $projKw[intval($kwo->dbID)] = strval($kwo->name);
            }
        }
    } catch (Exception $e) {
        $projKw = [];
    }

    // per-version platform assignment (gap #915): assigned platforms + the
    // project's design-visible platforms (legacy getPlatforms / getPlatformsMap
    // + getFreePlatforms + platforms.inc.tpl).
    $platformsPrj = projectPlatforms($db, $tprojectId);
    $platformsAssigned = [];
    foreach (versionPlatformAssignments($db, $tcaseId, $tcversionId) as $pa) {
        $platformsAssigned[$pa['id']] = $pa['name'];
    }
    $platformsEditable = canAssignPlatforms(
        $user, $db, $tprojectId, intval($lvRow['is_open'] ?? 1), $executed);

    // design-time custom fields linked to the project (per-step/tc level)
    $customFields = [];
    try {
        $cfMap = $tcaseMgr->get_linked_cfields_at_design(
            $tcaseId, $tcversionId, null, null, $tprojectId);
        if (!is_null($cfMap) && is_array($cfMap)) {
            foreach ($cfMap as $cfi) {
                $val = $cfi['value'] ?? null;
                if (is_array($val)) { $val = implode('|', $val); }
                $verbose = strval(
                    $tcaseMgr->cfield_mgr->custom_field_types[intval($cfi['type'] ?? 0)] ?? 'string');
                if (in_array($verbose, ['date', 'datetime'], true)
                    && $val !== '' && is_numeric($val)) {
                    $val = gmdate('Y-m-d\TH:i', intval($val));
                    if ($verbose === 'date') { $val = substr($val, 0, 10); }
                }
                $customFields[] = [
                    'id' => intval($cfi['id']),
                    'name' => strval($cfi['name'] ?? ''),
                    'label' => strval($cfi['label'] ?? ''),
                    'type' => intval($cfi['type'] ?? 0),
                    'typeName' => $verbose,
                    'possible_values' => strval($cfi['possible_values'] ?? ''),
                    'default_value' => strval($cfi['default_value'] ?? ''),
                    'required' => intval($cfi['required'] ?? 0),
                    'show_on_design' => intval($cfi['show_on_design'] ?? 0) === 1,
                    'enable_on_design' => intval($cfi['enable_on_design'] ?? 0) === 1,
                    'length_max' => intval($cfi['length_max'] ?? 0),
                    'value' => ($val === null || $val === '') ? '' : (string)$val,
                ];
            }
        }
    } catch (Exception $e) {
        $customFields = [];
    }

    // attachments of the latest version (metadata only; content streamed via
    // lib/attachments/attachmentdownload.php?id=N)
    $attachments = [];
    try {
        $attMap = getAttachmentInfosFrom($tcaseMgr, $tcversionId, false);
        if (!is_null($attMap)) {
            foreach ($attMap as $ai) {
                $attachments[] = [
                    'id' => intval($ai['id']),
                    'title' => strval($ai['title'] ?? ''),
                    'file_name' => strval($ai['file_name'] ?? ''),
                    'file_size' => intval($ai['file_size'] ?? 0),
                    'file_type' => strval($ai['file_type'] ?? ''),
                    'date_added' => strval($ai['date_added'] ?? ''),
                ];
            }
        }
    } catch (Exception $e) {
        $attachments = [];
    }

    jout([
        'status' => 'ok',
        'tcase' => [
            'id' => $tcaseId,
            'tcversion_id' => $tcversionId,
            'version' => intval($lvRow['version'] ?? 0),
            'name' => strval($lvRow['tc_name'] ?? ''),
            'summary' => strval($lvRow['summary'] ?? ''),
            'preconditions' => strval($lvRow['preconditions'] ?? ''),
            'importance' => intval($lvRow['importance'] ?? 2),
            'execution_type' => intval($lvRow['execution_type'] ?? 1),
            'status' => intval($lvRow['status'] ?? 1),
            'active' => intval($lvRow['active'] ?? 1),
            'is_open' => intval($lvRow['is_open'] ?? 1),
            'external_id' => intval($lvRow['tc_external_id'] ?? 0),
            'estimatedExecDuration' => strval($lvRow['estimated_exec_duration'] ?? ''),
            'parent_id' => intval($lvRow['suite_id'] ?? 0),
        ],
        'steps' => $steps,
        'customFields' => $customFields,
        'attachments' => $attachments,
        'keywordsAssigned' => $assignedKw,
        'keywordsProject' => $projKw,
        'platformsAssigned' => $platformsAssigned,
        'platformsFree' => platformFreeList($platformsPrj, $platformsAssigned),
        'platformsProject' => $platformsPrj,
        'platformsEditable' => $platformsEditable,
        'executed' => $executed,
        'statusDomain' => tcStatusDomain(),
        'estimateDurationRequired' => isDurationRequired($tcaseCfg),
    ]);
}

// ---------------------------------------------------------------------------
// GET ?action=version_list&tcase_id=N
// All versions of a test case with lifecycle flags (id, version, active,
// is_open, has_been_executed, is_latest). Mirrors the legacy tcView_viewer
// version-section data (get_by_id ALL_VERSIONS) and drives the version
// selector + Freeze/Unfreeze + Activate/Deactivate + Delete-version buttons.
// ---------------------------------------------------------------------------
if ($action === 'version_list') {
    $tcaseId = getIntParam('tcase_id');
    if ($tcaseId <= 0) {
        jout(['status' => 'error', 'message' => 'Invalid test case id'], 400);
    }
    $tprojectId = owningProjectOf($db, $tprojectMgr, $tcaseId);
    if (is_null($tprojectId)) {
        jout(['status' => 'error', 'message' => 'Test case not found'], 404);
    }
    if (!$user->hasRight($db, 'mgt_view_tc', $tprojectId)) {
        jout(['status' => 'error', 'message' => 'No permission'], 403);
    }

    $vTables = tlObjectWithDB::getDBTables(
        array('nodes_hierarchy', 'tcversions', 'executions'));
    $lvRow = $db->fetchFirstRow(
        " SELECT MAX(TCV.version) AS vmax FROM {$vTables['tcversions']} TCV " .
        " JOIN {$vTables['nodes_hierarchy']} NH ON NH.id = TCV.id " .
        " WHERE NH.parent_id = {$tcaseId}");
    $latestVersion = intval($lvRow['vmax'] ?? 0);

    $rows = $db->get_recordset(
        " SELECT TCV.id AS tcversion_id, TCV.version, TCV.active, TCV.is_open, " .
        "        TCV.summary, TCV.creation_ts, TCV.modification_ts " .
        " FROM {$vTables['tcversions']} TCV " .
        " JOIN {$vTables['nodes_hierarchy']} NH ON NH.id = TCV.id " .
        " WHERE NH.parent_id = {$tcaseId} " .
        " ORDER BY TCV.version ASC");
    $versions = [];
    if (!is_null($rows)) {
        $executedSet = [];
        if (count($rows) > 0) {
            $execRs = $db->get_recordset(
                " SELECT DISTINCT tcversion_id FROM {$vTables['executions']} " .
                " WHERE tcversion_id IN (" .
                implode(',', array_map('intval', array_column($rows, 'tcversion_id'))) . ")");
            if (!is_null($execRs)) {
                foreach ($execRs as $ex) {
                    $executedSet[intval($ex['tcversion_id'])] = 1;
                }
            }
        }
        foreach ($rows as $r) {
            $vid = intval($r['tcversion_id']);
            $versions[] = [
                'tcversion_id' => $vid,
                'version' => intval($r['version']),
                'active' => intval($r['active']),
                'is_open' => intval($r['is_open']),
                'has_been_executed' => isset($executedSet[$vid]) ? 1 : 0,
                'is_latest' => intval($r['version']) === $latestVersion,
            ];
        }
    }

    $grants = [];
    foreach (array('mgt_modify_tc', 'testcase_freeze',
                   'delete_frozen_tcversion',
                   'testproject_delete_executed_testcases') as $gk) {
        $grants[$gk] = $user->hasRight($db, $gk, $tprojectId) ? 1 : 0;
    }

    jout([
        'status' => 'ok',
        'tcase_id' => $tcaseId,
        'tproject_id' => $tprojectId,
        'versions' => $versions,
        'grants' => $grants,
    ]);
}

// ---------------------------------------------------------------------------
// GET ?action=keywords&tproject_id=N
// Project keyword map {id: name} for the test case editor. The create
// (new test case) form needs this without an existing tcase_id; mirrors
// the keywordsProject payload already exposed by the 'get' action
// (lib/testcases/containerEdit.php legacy flow loads project keywords
// unconditionally for the create form).
// ---------------------------------------------------------------------------
if ($action === 'keywords') {
    $tprojectId = getIntParam('tproject_id');
    if ($tprojectId <= 0) {
        $tprojectId = intval($_SESSION['testprojectID'] ?? 0);
    }
    if ($tprojectId <= 0) {
        jout(['status' => 'error', 'message' => 'Invalid test project id'], 400);
    }
    $info = $tprojectMgr->get_by_id($tprojectId);
    if (!$info) {
        jout(['status' => 'error', 'message' => 'Test project not found'], 404);
    }
    if (!$user->hasRight($db, 'mgt_view_tc', $tprojectId)) {
        jout(['status' => 'error', 'message' => 'No permission'], 403);
    }

    $projKw = [];
    try {
        $pk = $tprojectMgr->getKeywords($tprojectId);
        if (!is_null($pk)) {
            foreach ($pk as $kwo) {
                $projKw[intval($kwo->dbID)] = strval($kwo->name);
            }
        }
    } catch (Exception $e) {
        $projKw = [];
    }

    // project-level design custom fields (definitions only, no value yet)
    $customFields = [];
    try {
        $cfMap = $tcaseMgr->cfield_mgr->get_linked_cfields_at_design(
            $tprojectId, ENABLED, null, 'testcase', null);
        if (!is_null($cfMap) && is_array($cfMap)) {
            foreach ($cfMap as $cfi) {
                $val = $cfi['value'] ?? null;
                if (is_array($val)) { $val = implode('|', $val); }
                $verbose = strval(
                    $tcaseMgr->cfield_mgr->custom_field_types[intval($cfi['type'] ?? 0)] ?? 'string');
                if (in_array($verbose, ['date', 'datetime'], true)
                    && $val !== '' && is_numeric($val)) {
                    $val = gmdate('Y-m-d\TH:i', intval($val));
                    if ($verbose === 'date') { $val = substr($val, 0, 10); }
                }
                $customFields[] = [
                    'id' => intval($cfi['id']),
                    'name' => strval($cfi['name'] ?? ''),
                    'label' => strval($cfi['label'] ?? ''),
                    'type' => intval($cfi['type'] ?? 0),
                    'typeName' => $verbose,
                    'possible_values' => strval($cfi['possible_values'] ?? ''),
                    'default_value' => strval($cfi['default_value'] ?? ''),
                    'required' => intval($cfi['required'] ?? 0),
                    'show_on_design' => intval($cfi['show_on_design'] ?? 0) === 1,
                    'enable_on_design' => intval($cfi['enable_on_design'] ?? 0) === 1,
                    'length_max' => intval($cfi['length_max'] ?? 0),
                    'value' => is_array($cfi['value'] ?? null)
                        ? implode('|', array_map('strval', $cfi['value']))
                        : strval($cfi['value'] ?? ''),
                ];
            }
        }
    } catch (Exception $e) {
        $customFields = [];
    }

    jout([
        'status' => 'ok',
        'tproject' => ['id' => $tprojectId, 'name' => strval($info['name'])],
        'keywordsProject' => $projKw,
        'customFields' => $customFields,
        'statusDomain' => tcStatusDomain(),
        'estimateDurationRequired' => isDurationRequired($tcaseCfg),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== '') {
    $writeGrants = ['mgt_modify_tc'];
    $checkWrite = function($nodeId) use ($db, $user, $tprojectMgr) {
        $tprojectId = owningProjectOf($db, $tprojectMgr, intval($nodeId));
        if (is_null($tprojectId)) {
            jout(['status' => 'error', 'message' => 'Node not found'], 404);
        }
        if (!$user->hasRight($db, 'mgt_modify_tc', $tprojectId)) {
            jout(['status' => 'error',
                  'message' => 'Requires permission: modify test cases'], 403);
        }
        return $tprojectId;
    };

    // Attachment upload/delete use multipart/form-data (not JSON), so they are
    // handled before $body = getJsonBody() reads php://input.
    // Mirrors lib/testcases/tcEdit.php doAction=fileUpload / doAction=deleteFile
    // (fileUploadManagement + deleteAttachment, fk_table='tcversions').
    $handleAttachmentUpload = function($tcaseId, $tcversionId, $uploadTitle) use ($db, $tcaseMgr) {
        $uploadOp = fileUploadManagement($db, $tcversionId, $uploadTitle, 'tcversions');
        if (!$uploadOp->statusOK) {
            $code = isset($uploadOp->statusCode) ? strval($uploadOp->statusCode) : '';
            jout(['status' => 'error', 'message' => strval($uploadOp->msg ?: 'Upload failed'),
                  'code' => $code], 422);
        }
        $attMap = getAttachmentInfosFrom($tcaseMgr, $tcversionId, false);
        $attachments = [];
        if (!is_null($attMap)) {
            foreach ($attMap as $ai) {
                $attachments[] = [
                    'id' => intval($ai['id']),
                    'title' => strval($ai['title'] ?? ''),
                    'file_name' => strval($ai['file_name'] ?? ''),
                    'file_size' => intval($ai['file_size'] ?? 0),
                    'file_type' => strval($ai['file_type'] ?? ''),
                    'date_added' => strval($ai['date_added'] ?? ''),
                ];
            }
        }
        jout([
            'status' => 'ok',
            'message' => strval($uploadOp->msg ?: 'Attachment uploaded'),
            'attachments' => $attachments,
        ]);
    };

    if ($action === 'upload_attachment') {
        $tcaseId = intval($_POST['tcase_id'] ?? 0);
        $tcversionId = intval($_POST['tcversion_id'] ?? 0);
        if ($tcaseId <= 0 || $tcversionId <= 0) {
            jout(['status' => 'error', 'message' => 'Missing test case or version id'], 400);
        }
        $tprojectId = $checkWrite($tcaseId);
        $handleAttachmentUpload($tcaseId, $tcversionId, trim(strval($_POST['fileTitle'] ?? '')));
    }

    if ($action === 'delete_attachment') {
        $tcaseId = intval($_POST['tcase_id'] ?? 0);
        $tcversionId = intval($_POST['tcversion_id'] ?? 0);
        $fileId = intval($_POST['file_id'] ?? 0);
        if ($tcaseId <= 0 || $tcversionId <= 0 || $fileId <= 0) {
            jout(['status' => 'error', 'message' => 'Missing test case, version or file id'], 400);
        }
        $tprojectId = $checkWrite($tcaseId);
        // BFF hardening: the attachment must exist AND be bound to this
        // tcversion before the delete is issued (forged file_id cannot remove
        // attachments of other nodes).
        $attTables = tlObjectWithDB::getDBTables(array('attachments'));
        $attRows = $db->get_recordset(
            "SELECT id FROM {$attTables['attachments']} " .
            "WHERE id = {$fileId} AND fk_id = {$tcversionId} AND fk_table = 'tcversions'");
        if (is_null($attRows) || count($attRows) === 0) {
            jout(['status' => 'error', 'message' => 'Attachment not found on this test case'], 404);
        }
        deleteAttachment($db, $fileId, false);
        $attMap = getAttachmentInfosFrom($tcaseMgr, $tcversionId, false);
        $attachments = [];
        if (!is_null($attMap)) {
            foreach ($attMap as $ai) {
                $attachments[] = [
                    'id' => intval($ai['id']),
                    'title' => strval($ai['title'] ?? ''),
                    'file_name' => strval($ai['file_name'] ?? ''),
                    'file_size' => intval($ai['file_size'] ?? 0),
                    'file_type' => strval($ai['file_type'] ?? ''),
                    'date_added' => strval($ai['date_added'] ?? ''),
                ];
            }
        }
        jout(['status' => 'ok', 'message' => 'Attachment deleted',
              'deleted_id' => $fileId, 'attachments' => $attachments]);
    }

    $body = getJsonBody();

    $normSteps = function($rawSteps, $execType) {
        $out = [];
        $num = 0;
        foreach ((array)$rawSteps as $st) {
            if (!is_array($st)) { continue; }
            $actions = trim(strval($st['actions'] ?? ''));
            $expected = trim(strval($st['expected_results'] ?? ''));
            if ($actions === '' && $expected === '') { continue; }
            $num++;
            // Per-step execution type (legacy step editor supports one per
            // step); fall back to the TC-level execution type when absent or
            // out of the manual/automated domain.
            $stepExec = intval($st['execution_type'] ?? $execType);
            if ($stepExec !== TESTCASE_EXECUTION_TYPE_MANUAL
                && $stepExec !== TESTCASE_EXECUTION_TYPE_AUTO) {
                $stepExec = intval($execType);
            }
            $out[] = [
                'step_number' => $num,
                'actions' => $actions,
                'expected_results' => $expected,
                'execution_type' => $stepExec,
            ];
        }
        return $out;
    };

    $kwString = function($rawKw) {
        $ids = [];
        foreach ((array)$rawKw as $kid) {
            if (intval($kid) > 0) { $ids[] = intval($kid); }
        }
        return count($ids) ? implode(',', $ids) : '';
    };

    // Persist design-time custom field values for a test case version.
    // Mirrors legacy testcaseCommands: get_linked_cfields_at_design() +
    // cfield_mgr::design_values_to_db().
    $saveDesignCF = function($body, $tcaseId, $tcversionId, $tprojectId) use ($db, $tcaseMgr) {
        $rawCF = $body['customFields'] ?? [];
        if (!is_array($rawCF) || !count($rawCF)) {
            // no custom fields posted - leave values untouched (like legacy,
            // which only writes keys present on the form)
            return;
        }
        $cf_map = $tcaseMgr->get_linked_cfields_at_design(
            $tcaseId, $tcversionId, null, null, $tprojectId);
        if (is_null($cf_map)) { return; }

        // key: cf id value: field definition
        $byId = [];
        foreach ((array)$cf_map as $cfi) {
            $byId[intval($cfi['id'])] = $cfi;
        }

        // legacy input name format: custom_field_<type>_<id>
        $cfield = [];
        foreach ($rawCF as $cfv) {
            if (!is_array($cfv)) { continue; }
            $cfId = intval($cfv['id'] ?? 0);
            if ($cfId <= 0 || !isset($byId[$cfId])) { continue; }
            $verbose = trim(strval(
                $tcaseMgr->cfield_mgr->custom_field_types[intval($byId[$cfId]['type'] ?? 0)] ?? ''));
            $value = $cfv['value'] ?? '';
            if (is_array($value)) {
                $value = implode('|', array_map('strval', $value));
            } else {
                $value = strval($value);
            }
            // date/datetime posted as ISO (Y-m-d / Y-m-d\TH:i) -> unix ts
            if (($verbose === 'date' || $verbose === 'datetime') && $value !== '') {
                $ts = strtotime($value);
                if ($ts !== false) {
                    if ($verbose === 'date') {
                        $value = strval(mktime(
                            0, 0, 0, intval(date('n', $ts)),
                            intval(date('j', $ts)), intval(date('Y', $ts))));
                    } else {
                        $value = strval($ts);
                    }
                } else {
                    $value = '';
                }
            }
            $typeId = intval($cfv['type'] ?? $byId[$cfId]['type']);
            $cfield[$cfId] = ['type_id' => $typeId, 'cf_value' => $value];
        }
        if (count($cfield)) {
            $tcaseMgr->cfield_mgr->design_values_to_db(
                $cfield, intval($tcversionId), null, 'bff_design_cf');
        }
    };

    // Persist the per-version platform SET posted by the modern editors
    // (gap #915). testcase_platforms is synced to exactly match the submitted
    // ids - functionally identical to the legacy discrete addPlatform /
    // removePlatform operations (addPlatforms / deletePlatforms), but in one
    // round-trip for the inline editor's Save button.
    $syncPlatforms = function($rawPlatforms, $tcaseId, $tcversionId, $tprojectId)
        use ($db, $tcaseMgr) {
        if (!is_array($rawPlatforms)) { return; }
        $wanted = [];
        foreach ($rawPlatforms as $pid) {
            $pid = intval($pid);
            if ($pid > 0) { $wanted[$pid] = true; }
        }
        $prj = projectPlatforms($db, $tprojectId);
        $cur = [];
        foreach (versionPlatformAssignments($db, $tcaseId, $tcversionId) as $pa) {
            $cur[$pa['id']] = true;
        }
        $toAdd = [];
        $toRemove = [];
        foreach ($wanted as $pid => $ign) {
            if (!isset($cur[$pid]) && isset($prj[$pid])
                && intval($prj[$pid]['enable_on_design'] ?? 0) === 1) {
                $toAdd[] = $pid;
            }
        }
        foreach ($cur as $pid => $ign) {
            if (!isset($wanted[$pid])) { $toRemove[] = $pid; }
        }
        if (count($toAdd)) {
            $tcaseMgr->addPlatforms($tcaseId, $tcversionId, $toAdd);
        }
        if (count($toRemove)) {
            $tcaseMgr->deletePlatforms($tcaseId, $tcversionId, $toRemove);
        }
    };

    // -----------------------------------------------------------------------
    // POST ?action=add_platform {tcase_id, tcversion_id, platforms:[ids]}
    // Assign one or more project platforms to a test case VERSION (legacy
    // tcEdit doAction=addPlatform -> testcaseCommands::addPlatform ->
    // testcase::addPlatforms). Mirrors the platforms.inc.tpl "free_platforms"
    // multi-select submit.
    // -----------------------------------------------------------------------
    if ($action === 'add_platform') {
        $tcaseId = intval($body['tcase_id'] ?? 0);
        if ($tcaseId <= 0) {
            jout(['status' => 'error', 'message' => 'Missing test case id'], 400);
        }
        $tprojectId = $checkWrite($tcaseId);
        $pRow = resolveTcversionForPlatform($db, $tcaseId, intval($body['tcversion_id'] ?? 0));
        if (empty($pRow) || !isset($pRow['id'])) {
            jout(['status' => 'error', 'message' => 'Test case version not found'], 404);
        }
        $tcversionId = intval($pRow['id']);

        if (!canAssignPlatforms($user, $db, $tprojectId,
                                intval($pRow['is_open'] ?? 1), versionHasExecutions($db, $tcversionId))) {
            jout(['status' => 'error',
                  'message' => 'Platform assignment is not allowed on this version '
                    . '(frozen, executed without exec-edit right, or no edit right)'],
                 403);
        }

        $platIds = [];
        foreach ((array)($body['platforms'] ?? []) as $pid) {
            if (intval($pid) > 0) { $platIds[] = intval($pid); }
        }
        if (!count($platIds)) {
            jout(['status' => 'error', 'message' => 'No platforms to assign'], 400);
        }

        // only design-visible platforms of the owning project may be assigned
        $prj = projectPlatforms($db, $tprojectId);
        foreach ($platIds as $pid) {
            if (!isset($prj[$pid]) || intval($prj[$pid]['enable_on_design'] ?? 0) === 0) {
                jout(['status' => 'error',
                      'message' => 'Platform #' . $pid . ' is not assignable in this project'], 422);
            }
        }

        try {
            $tcaseMgr->addPlatforms($tcaseId, $tcversionId, array_unique($platIds));
        } catch (Throwable $e) {
            jout(['status' => 'error', 'message' => $e->getMessage()], 500);
        }

        $assignedMap = [];
        foreach (versionPlatformAssignments($db, $tcaseId, $tcversionId) as $pa) {
            $assignedMap[$pa['id']] = $pa['name'];
        }
        jout([
            'status' => 'ok',
            'message' => 'Platform(s) assigned',
            'tcversion_id' => $tcversionId,
            'platformsAssigned' => $assignedMap,
            'platformsFree' => platformFreeList($prj, $assignedMap),
            'platformsProject' => $prj,
            'platformsEditable' => canAssignPlatforms($user, $db, $tprojectId,
                intval($pRow['is_open'] ?? 1), versionHasExecutions($db, $tcversionId)),
        ]);
    }

    // -----------------------------------------------------------------------
    // POST ?action=remove_platform {tcase_id, tcversion_id, platform_id|tcplat_link_id}
    // Unassign ONE platform from a test case VERSION (legacy tcEdit
    // doAction=removePlatform -> testcaseCommands::removePlatform ->
    // testcase::deletePlatformsByLink(testcase_platforms.id)).
    // -----------------------------------------------------------------------
    if ($action === 'remove_platform') {
        $tcaseId = intval($body['tcase_id'] ?? 0);
        if ($tcaseId <= 0) {
            jout(['status' => 'error', 'message' => 'Missing test case id'], 400);
        }
        $tprojectId = $checkWrite($tcaseId);
        $pRow = resolveTcversionForPlatform($db, $tcaseId, intval($body['tcversion_id'] ?? 0));
        if (empty($pRow) || !isset($pRow['id'])) {
            jout(['status' => 'error', 'message' => 'Test case version not found'], 404);
        }
        $tcversionId = intval($pRow['id']);

        if (!canAssignPlatforms($user, $db, $tprojectId,
                                intval($pRow['is_open'] ?? 1), versionHasExecutions($db, $tcversionId))) {
            jout(['status' => 'error',
                  'message' => 'Platform assignment is not allowed on this version '
                    . '(frozen, executed without exec-edit right, or no edit right)'],
                 403);
        }

        $platId = intval($body['platform_id'] ?? 0);
        $linkId = intval($body['tcplat_link_id'] ?? 0);
        if ($platId > 0) {
            $tcaseMgr->deletePlatforms($tcaseId, $tcversionId, $platId);
        } elseif ($linkId > 0) {
            $tcaseMgr->deletePlatformsByLink($tcaseId, $linkId);
        } else {
            jout(['status' => 'error', 'message' => 'Missing platform_id or tcplat_link_id'], 400);
        }

        $prj = projectPlatforms($db, $tprojectId);
        $assignedMap = [];
        foreach (versionPlatformAssignments($db, $tcaseId, $tcversionId) as $pa) {
            $assignedMap[$pa['id']] = $pa['name'];
        }
        jout([
            'status' => 'ok',
            'message' => 'Platform removed',
            'tcversion_id' => $tcversionId,
            'platformsAssigned' => $assignedMap,
            'platformsFree' => platformFreeList($prj, $assignedMap),
            'platformsProject' => $prj,
            'platformsEditable' => canAssignPlatforms($user, $db, $tprojectId,
                intval($pRow['is_open'] ?? 1), versionHasExecutions($db, $tcversionId)),
        ]);
    }

    // POST ?action=suite_create {parent_id,name}
    if ($action === 'suite_create') {
        $parentId = intval($body['parent_id'] ?? 0);
        $name = trim(strval($body['name'] ?? ''));
        if ($parentId <= 0 || $name === '') {
            jout(['status' => 'error', 'message' => 'Missing suite parent or name'], 400);
        }
        $tprojectId = $checkWrite($parentId);
        $tsuiteMgr = new testsuite($db);
        $ret = $tsuiteMgr->create($parentId, $name, '');
        $newId = is_array($ret) ? intval($ret['id'] ?? 0) : intval($ret);
        if ($newId <= 0) {
            jout(['status' => 'error', 'message' => 'Create failed'], 500);
        }
        jout(['status' => 'ok', 'id' => $newId, 'message' => 'Suite created']);
    }

    // POST ?action=suite_update {id,name}
    if ($action === 'suite_update') {
        $sid = intval($body['id'] ?? 0);
        $name = trim(strval($body['name'] ?? ''));
        if ($sid <= 0 || $name === '') {
            jout(['status' => 'error', 'message' => 'Missing suite id or name'], 400);
        }
        $tprojectId = $checkWrite($sid);
        $tsuiteMgr = new testsuite($db);
        $info = $tsuiteMgr->get_by_id($sid);
        $details = is_array($info) ? strval($info['details'] ?? '') : '';
        $tsuiteMgr->update($sid, $name, $details, null);
        jout(['status' => 'ok', 'message' => 'Suite updated']);
    }

    // POST ?action=suite_delete {id}
    if ($action === 'suite_delete') {
        $sid = intval($body['id'] ?? 0);
        if ($sid <= 0) {
            jout(['status' => 'error', 'message' => 'Missing suite id'], 400);
        }
        $tprojectId = $checkWrite($sid);
        $tsuiteMgr = new testsuite($db);
        $ret = $tsuiteMgr->delete($sid);
        jout(['status' => 'ok', 'message' => 'Suite deleted', 'result' => $ret]);
    }

    // POST ?action=create {parent_id,name,summary,preconditions,steps[],
    //                      importance,execution_type,keywords[]}
    if ($action === 'create') {
        $parentId = intval($body['parent_id'] ?? 0);
        $name = trim(strval($body['name'] ?? ''));
        if ($parentId <= 0 || $name === '') {
            jout(['status' => 'error', 'message' => 'Missing parent suite or name'], 400);
        }
        $tprojectId = $checkWrite($parentId);
        $execType = intval($body['execution_type'] ?? TESTCASE_EXECUTION_TYPE_MANUAL);
        $importance = intval($body['importance'] ?? 2);
        $summary = strval($body['summary'] ?? '');
        $preconds = strval($body['preconditions'] ?? '');
        $steps = $normSteps($body['steps'] ?? [], $execType);
        $kwIds = $kwString($body['keywords'] ?? []);

        // TC status workflow domain (legacy setStatus); default = draft.
        $status = normalizeTcStatus($body['status'] ?? '');
        if ($status === null) {
            $status = 1; // draft
        }

        // Estimated execution duration (minutes): numeric validated, optionally
        // required per config (legacy attributesLinear.inc.tpl + setEstimatedExecDuration).
        $estDur = trim(strval($body['estimated_execution_duration'] ?? ''));
        if ($estDur !== '' && !is_numeric($estDur)) {
            jout(['status' => 'error', 'message' => 'Invalid estimated duration'], 400);
        }
        if (isDurationRequired($tcaseCfg) && $estDur === '') {
            jout(['status' => 'error',
                  'message' => 'Estimated execution duration is required'], 400);
        }

        $ret = $tcaseMgr->create($parentId, $name, $summary, $preconds, $steps,
                                 intval($user->dbID ?? $userId), $kwIds,
                                 testcase::DEFAULT_ORDER, testcase::AUTOMATIC_ID,
                                 $execType, $importance,
                                 array('status' => $status,
                                       'estimatedExecDuration' => $estDur));
        $newId = 0;
        if (is_array($ret)) {
            if (isset($ret['status_ok']) && !$ret['status_ok']) {
                jout(['status' => 'error',
                      'message' => strval($ret['msg'] ?? 'Create failed')], 400);
            }
            $newId = intval($ret['id'] ?? 0);
        } else {
            $newId = intval($ret);
        }
        if ($newId <= 0) {
            jout(['status' => 'error', 'message' => 'Create failed'], 500);
        }

        // persist design-time custom fields on the first version
        $tvTables = tlObjectWithDB::getDBTables(array('nodes_hierarchy', 'tcversions'));
        $nrow = $db->fetchFirstRow(
            " SELECT TCV.id AS tcversion_id FROM {$tvTables['tcversions']} TCV " .
            " JOIN {$tvTables['nodes_hierarchy']} NH ON NH.id = TCV.id " .
            " WHERE NH.parent_id = {$newId} ORDER BY TCV.version DESC LIMIT 1");
        $newTcversionId = intval($nrow['tcversion_id'] ?? 0);
        if ($newTcversionId > 0) {
            $saveDesignCF($body, $newId, $newTcversionId, $tprojectId);
            // per-version platforms on the newly created first version
            // (gap #915)
            if ($newTcversionId > 0) {
                $syncPlatforms($body['platforms'] ?? null, $newId,
                               $newTcversionId, $tprojectId);
            }
        }

        jout(['status' => 'ok', 'id' => $newId, 'message' => 'Test case created']);
    }

    // POST ?action=update {tcase_id,tcversion_id(optional),name,summary,
    //                      preconditions,steps[],importance,execution_type,
    //                      keywords[]}
    // Updates a specific version when tcversion_id is provided (version
    // selector), otherwise the LATEST version (legacy doUpdate behavior).
    if ($action === 'update') {
        $tcaseId = intval($body['tcase_id'] ?? 0);
        if ($tcaseId <= 0) {
            jout(['status' => 'error', 'message' => 'Missing test case id'], 400);
        }
        $tprojectId = $checkWrite($tcaseId);

        $tvTables2 = tlObjectWithDB::getDBTables(array('nodes_hierarchy', 'tcversions', 'executions'));
        $updateTcversion = intval($body['tcversion_id'] ?? 0);
        $updateFilter = $updateTcversion > 0
            ? " AND TCV.id = {$updateTcversion} " : '';
        $lvRow = $db->fetchFirstRow(
            " SELECT TCV.* FROM {$tvTables2['tcversions']} TCV " .
            " JOIN {$tvTables2['nodes_hierarchy']} NH ON NH.id = TCV.id " .
            " WHERE NH.parent_id = {$tcaseId} " . $updateFilter .
            " ORDER BY TCV.version DESC LIMIT 1");
        if (empty($lvRow)) {
            jout(['status' => 'error', 'message' => 'Test case version not found'], 404);
        }
        $tcversionId = intval($lvRow['id']);

        $execRs = $db->get_recordset(
            "SELECT id FROM {$tvTables2['executions']} WHERE tcversion_id = {$tcversionId}");
        if (!is_null($execRs) && count($execRs) > 0
            && !$user->hasRight($db, 'testproject_edit_executed_testcases', $tprojectId)
            && !(($tcaseCfg->canEditExecuted ?? 0) > 0)) {
            jout(['status' => 'error',
                  'message' => 'This version has executions: editing requires special permission'],
                 403);
        }

        $name = trim(strval($body['name'] ?? ''));
        if ($name === '') {
            jout(['status' => 'error', 'message' => 'Name must not be empty'], 400);
        }
        $execType = intval($body['execution_type'] ?? $lvRow['execution_type']);
        $importance = intval($body['importance'] ?? $lvRow['importance']);
        $summary = strval($body['summary'] ?? '');
        $preconds = strval($body['preconditions'] ?? '');
        $steps = $normSteps($body['steps'] ?? [], $execType);
        $kwIds = $kwString($body['keywords'] ?? []);

        // TC status workflow domain (legacy setStatus); keep current when absent.
        $status = normalizeTcStatus($body['status'] ?? '');
        if ($status === null) {
            $curStatus = intval($lvRow['status'] ?? 0);
            $status = $curStatus >= 1 ? $curStatus : 1;
        }

        // Estimated execution duration (minutes): numeric validated, optionally
        // required per config (legacy setEstimatedExecDuration + update() $attr).
        $estDur = trim(strval($body['estimated_execution_duration'] ?? ''));
        if ($estDur !== '' && !is_numeric($estDur)) {
            jout(['status' => 'error', 'message' => 'Invalid estimated duration'], 400);
        }
        if (isDurationRequired($tcaseCfg) && $estDur === '') {
            jout(['status' => 'error',
                  'message' => 'Estimated execution duration is required'], 400);
        }
        $attr = array('status' => $status, 'estimatedExecDuration' => $estDur);

        $ret = $tcaseMgr->update($tcaseId, $tcversionId, $name, $summary,
                                 $preconds, $steps,
                                 intval($user->dbID ?? $userId), $kwIds,
                                 intval($lvRow['node_order'] ?? testcase::DEFAULT_ORDER),
                                 $execType, $importance, $attr);
        if (is_array($ret) && isset($ret['status_ok']) && !$ret['status_ok']) {
            jout(['status' => 'error',
                  'message' => strval($ret['msg'] ?? 'Update failed')], 400);
        }
        $saveDesignCF($body, $tcaseId, $tcversionId, $tprojectId);
        $syncPlatforms($body['platforms'] ?? null, $tcaseId, $tcversionId, $tprojectId);
        jout(['status' => 'ok', 'message' => 'Test case saved',
              'tcversion_id' => $tcversionId]);
    }

    // POST ?action=delete {tcase_id}  -> removes ALL versions (legacy doDelete)
    if ($action === 'delete') {
        $tcaseId = intval($body['tcase_id'] ?? 0);
        if ($tcaseId <= 0) {
            jout(['status' => 'error', 'message' => 'Missing test case id'], 400);
        }
        $checkWrite($tcaseId);
        $ret = $tcaseMgr->delete($tcaseId);
        jout(['status' => 'ok', 'message' => 'Test case deleted', 'result' => $ret]);
    }

    // -----------------------------------------------------------------------
    // POST ?action=delete_version {tcase_id, tcversion_id}
    // Removes ONLY the requested version (legacy doDelete with a concrete
    // tcversion_id). Mirrors "delete_tc_version" button in tcView_viewer.
    // -----------------------------------------------------------------------
    if ($action === 'delete_version') {
        $tcaseId = intval($body['tcase_id'] ?? 0);
        $tcversionId = intval($body['tcversion_id'] ?? 0);
        if ($tcaseId <= 0 || $tcversionId <= 0) {
            jout(['status' => 'error',
                  'message' => 'Missing test case or version id'], 400);
        }
        $tprojectId = $checkWrite($tcaseId);

        $dvTables = tlObjectWithDB::getDBTables(
            array('nodes_hierarchy', 'tcversions', 'executions'));
        $dvRow = $db->fetchFirstRow(
            " SELECT TCV.* FROM {$dvTables['tcversions']} TCV " .
            " JOIN {$dvTables['nodes_hierarchy']} NH ON NH.id = TCV.id " .
            " WHERE NH.parent_id = {$tcaseId} AND TCV.id = {$tcversionId}");
        if (empty($dvRow)) {
            jout(['status' => 'error',
                  'message' => 'Version not found on this test case'], 404);
        }

        $isFrozen = intval($dvRow['is_open'] ?? 0) === 0;
        if ($isFrozen
            && !$user->hasRight($db, 'delete_frozen_tcversion', $tprojectId)) {
            jout(['status' => 'error',
                  'message' => 'This version is frozen: deleting requires the '
                    . 'delete frozen tcversion permission'], 403);
        }

        $execRs = $db->get_recordset(
            "SELECT id FROM {$dvTables['executions']} " .
            "WHERE tcversion_id = {$tcversionId} LIMIT 1");
        if (!is_null($execRs) && count($execRs) > 0
            && !$user->hasRight($db,
                'testproject_delete_executed_testcases', $tprojectId)
            && !((config_get('testcase_cfg')->canDeleteExecuted ?? 0) > 0)) {
            jout(['status' => 'error',
                  'message' => 'This version has executions: deleting requires '
                    . 'special permission'], 403);
        }

        $ret = $tcaseMgr->delete($tcaseId, $tcversionId);
        $versionsLeft = intval($db->fetchOneValue(
            " SELECT COUNT(0) FROM {$dvTables['tcversions']} TCV " .
            " JOIN {$dvTables['nodes_hierarchy']} NH ON NH.id = TCV.id " .
            " WHERE NH.parent_id = {$tcaseId}"));
        if ($versionsLeft <= 0) {
            // Last version removed: destroy the (now empty) test case node as
            // well, so no ghost test case stays in the tree. Legacy achieves
            // the same through the (only) version delete + full drain path.
            $db->exec_query(
                "DELETE FROM {$dvTables['nodes_hierarchy']} WHERE id = {$tcaseId}");
        }
        jout(['status' => 'ok', 'message' => 'Test case version deleted',
              'result' => $ret, 'versions_left' => $versionsLeft]);
    }

    // -----------------------------------------------------------------------
    // POST ?action=freeze|unfreeze {tcase_id, tcversion_id}
    // Toggle is_open on a single version (legacy testcaseCommands::freeze/
    // unfreeze -> testcase::setIsOpen). Requires the testcase_freeze right.
    // -----------------------------------------------------------------------
    if ($action === 'freeze' || $action === 'unfreeze') {
        $tcaseId = intval($body['tcase_id'] ?? 0);
        $tcversionId = intval($body['tcversion_id'] ?? 0);
        if ($tcaseId <= 0 || $tcversionId <= 0) {
            jout(['status' => 'error',
                  'message' => 'Missing test case or version id'], 400);
        }
        $tprojectId = $checkWrite($tcaseId);
        if (!$user->hasRight($db, 'testcase_freeze', $tprojectId)) {
            jout(['status' => 'error',
                  'message' => 'Requires permission: freeze test cases'], 403);
        }
        $fuTables = tlObjectWithDB::getDBTables(
            array('nodes_hierarchy', 'tcversions'));
        $fuRow = $db->fetchFirstRow(
            " SELECT TCV.id FROM {$fuTables['tcversions']} TCV " .
            " JOIN {$fuTables['nodes_hierarchy']} NH ON NH.id = TCV.id " .
            " WHERE NH.parent_id = {$tcaseId} AND TCV.id = {$tcversionId}");
        if (empty($fuRow)) {
            jout(['status' => 'error',
                  'message' => 'Version not found on this test case'], 404);
        }
        $isOpen = $action === 'unfreeze' ? 1 : 0;
        $tcaseMgr->setIsOpen(null, $tcversionId, $isOpen);
        $tcaseMgr->update_last_modified(
            $tcversionId, intval($user->dbID ?? $userId));
        jout(['status' => 'ok',
              'message' => $isOpen
                  ? 'Test case version unfrozen'
                  : 'Test case version frozen',
              'tcversion_id' => $tcversionId, 'is_open' => $isOpen]);
    }

    // -----------------------------------------------------------------------
    // POST ?action=activate|deactivate {tcase_id, tcversion_id}
    // Per-version active flag (legacy setActiveAttr -> update_active_status).
    // -----------------------------------------------------------------------
    if ($action === 'activate' || $action === 'deactivate') {
        $tcaseId = intval($body['tcase_id'] ?? 0);
        $tcversionId = intval($body['tcversion_id'] ?? 0);
        if ($tcaseId <= 0 || $tcversionId <= 0) {
            jout(['status' => 'error',
                  'message' => 'Missing test case or version id'], 400);
        }
        $tprojectId = $checkWrite($tcaseId);
        $adTables = tlObjectWithDB::getDBTables(
            array('nodes_hierarchy', 'tcversions'));
        $adRow = $db->fetchFirstRow(
            " SELECT TCV.id FROM {$adTables['tcversions']} TCV " .
            " JOIN {$adTables['nodes_hierarchy']} NH ON NH.id = TCV.id " .
            " WHERE NH.parent_id = {$tcaseId} AND TCV.id = {$tcversionId}");
        if (empty($adRow)) {
            jout(['status' => 'error',
                  'message' => 'Version not found on this test case'], 404);
        }
        $activeAttr = $action === 'activate' ? 1 : 0;
        $tcaseMgr->update_active_status($tcaseId, $tcversionId, $activeAttr);
        $tcaseMgr->update_last_modified(
            $tcversionId, intval($user->dbID ?? $userId));
        jout(['status' => 'ok',
              'message' => $activeAttr
                  ? 'Test case version activated'
                  : 'Test case version deactivated',
              'tcversion_id' => $tcversionId, 'active' => $activeAttr]);
    }

    // POST ?action=create_version {tcase_id}  -> new version cloned from latest
    if ($action === 'create_version') {
        $tcaseId = intval($body['tcase_id'] ?? 0);
        if ($tcaseId <= 0) {
            jout(['status' => 'error', 'message' => 'Missing test case id'], 400);
        }
        $checkWrite($tcaseId);
        $ret = $tcaseMgr->create_new_version($tcaseId, intval($user->dbID ?? $userId));
        $newTcv = is_array($ret) ? intval($ret['id'] ?? 0) : intval($ret);
        if ($newTcv <= 0) {
            jout(['status' => 'error', 'message' => 'Version creation failed'], 500);
        }
        jout(['status' => 'ok', 'tcversion_id' => $newTcv,
              'message' => 'New version created']);
    }

    // -------------------------------------------------------------------------
    // POST ?action=move  {node_id, type, new_parent_id, position}
    // Move a testsuite or testcase to a new parent (mirrors legacy do_move
    // in tcEdit.php / containerEdit.php: change_parent + change_child_order).
    // -------------------------------------------------------------------------
    if ($action === 'move') {
        $nodeId    = intval($body['node_id'] ?? 0);
        $nodeType  = trim(strval($body['type'] ?? ''));       // 'testsuite' | 'testcase'
        $newParent = intval($body['new_parent_id'] ?? 0);
        $position  = trim(strval($body['position'] ?? 'bottom')); // 'top' | 'bottom'

        if ($nodeId <= 0 || $newParent <= 0
            || !in_array($nodeType, ['testsuite', 'testcase'])) {
            jout(['status' => 'error',
                  'message' => 'Missing node_id, new_parent_id, or invalid type'], 400);
        }

        // prevent moving a node into itself
        if ($nodeId === $newParent) {
            jout(['status' => 'error',
                  'message' => 'Cannot move a node into itself'], 400);
        }

        $tprojectId = $checkWrite($nodeId);

        // also verify the destination is in the same project
        $destProject = owningProjectOf($db, $tprojectMgr, $newParent);
        if (is_null($destProject) || $destProject !== $tprojectId) {
            jout(['status' => 'error',
                  'message' => 'Destination is not in the same project'], 400);
        }

        // prevent moving a testsuite into its own descendant
        if ($nodeType === 'testsuite') {
            $nhTables = tlObjectWithDB::getDBTables(array('nodes_hierarchy'));
            $descendantCheck = $db->fetchFirstRow(
                "SELECT id FROM {$nhTables['nodes_hierarchy']} " .
                "WHERE id = {$newParent} AND parent_id = {$nodeId}");
            if (!empty($descendantCheck)) {
                // quick one-level check; for deep recursion, walk the chain
                jout(['status' => 'error',
                      'message' => 'Cannot move a suite into its own child'], 400);
            }
            // deeper descendant check: walk up from newParent to see if nodeId appears
            $nh = $nhTables['nodes_hierarchy'];
            $cur = $newParent;
            for ($i = 0; $i < 50; $i++) {
                $row = $db->fetchFirstRow(
                    "SELECT parent_id FROM {$nh} WHERE id = {$cur}");
                if (empty($row) || intval($row['parent_id']) <= 0) { break; }
                if (intval($row['parent_id']) === $nodeId) {
                    jout(['status' => 'error',
                          'message' => 'Cannot move a suite into its own descendant'], 400);
                }
                $cur = intval($row['parent_id']);
            }
        }

        $excludeNodeTypes = ['testplan' => 1, 'requirement' => 1, 'requirement_spec' => 1];
        $treeMgr = new tree($db);

        $ok = $treeMgr->change_parent($nodeId, $newParent);
        if (!$ok) {
            jout(['status' => 'error', 'message' => 'Move failed'], 500);
        }

        if ($position === 'top' || $position === 'bottom') {
            $treeMgr->change_child_order($newParent, $nodeId, $position,
                                         $excludeNodeTypes);
        }

        jout(['status' => 'ok', 'message' => 'Node moved']);
    }

    // -------------------------------------------------------------------------
    // POST ?action=copy  {node_id, type, new_parent_id, position, options}
    // Deep-copy a testsuite or testcase to a new parent (mirrors legacy
    // do_copy in tcEdit.php / containerEdit.php: copy_to + change_child_order).
    // -------------------------------------------------------------------------
    if ($action === 'copy') {
        $nodeId    = intval($body['node_id'] ?? 0);
        $nodeType  = trim(strval($body['type'] ?? ''));
        $newParent = intval($body['new_parent_id'] ?? 0);
        $position  = trim(strval($body['position'] ?? 'bottom'));
        $copyOpts  = $body['options'] ?? [];

        if ($nodeId <= 0 || $newParent <= 0
            || !in_array($nodeType, ['testsuite', 'testcase'])) {
            jout(['status' => 'error',
                  'message' => 'Missing node_id, new_parent_id, or invalid type'], 400);
        }

        $tprojectId = $checkWrite($nodeId);
        $destProject = owningProjectOf($db, $tprojectMgr, $newParent);
        if (is_null($destProject) || $destProject !== $tprojectId) {
            jout(['status' => 'error',
                  'message' => 'Destination is not in the same project'], 400);
        }

        $userIdInt = intval($user->dbID ?? $userId);
        $newId = 0;

        if ($nodeType === 'testcase') {
            $copyAlso = [
                'keyword_assignments'      => !empty($copyOpts['copyKeywords']),
                'requirement_assignments'  => !empty($copyOpts['copyRequirements']),
            ];
            $tcCopyOpts = [
                'check_duplicate_name'   => 0,
                'action_on_duplicate_name' => 'generate_new',
                'copy_also'              => $copyAlso,
                'stepAsGhost'            => !empty($copyOpts['copyAsGhost']),
                'copyOnlyLatest'         => !empty($copyOpts['copyOnlyLatestVersion']),
                'preserve_external_id'   => false,
            ];

            $ret = $tcaseMgr->copy_to($nodeId, $newParent, $userIdInt, $tcCopyOpts);
            if (is_array($ret) && isset($ret['status_ok']) && !$ret['status_ok']) {
                jout(['status' => 'error',
                      'message' => strval($ret['msg'] ?? 'Copy failed')], 500);
            }
            $newId = is_array($ret) ? intval($ret['id'] ?? 0) : intval($ret);
        } else {
            // testsuite
            $tsOpts = [
                'check_duplicate_name'    => 0,
                'action_on_duplicate_name' => 'allow_repeat',
                'copyKeywords'            => !empty($copyOpts['copyKeywords']) ? 1 : 0,
                'copyRequirements'        => !empty($copyOpts['copyRequirements']) ? 1 : 0,
            ];
            $tsuiteMgr = new testsuite($db);
            $ret = $tsuiteMgr->copy_to($nodeId, $newParent, $userIdInt, $tsOpts);
            if (is_array($ret) && isset($ret['status_ok']) && !$ret['status_ok']) {
                jout(['status' => 'error',
                      'message' => strval($ret['msg'] ?? 'Copy failed')], 500);
            }
            $newId = is_array($ret) ? intval($ret['id'] ?? 0) : intval($ret);
        }

        if ($newId <= 0) {
            jout(['status' => 'error', 'message' => 'Copy failed (no id)'], 500);
        }

        // position the copy
        $excludeNodeTypes = ['testplan' => 1, 'requirement' => 1, 'requirement_spec' => 1];
        $treeMgr = new tree($db);
        if ($position === 'top' || $position === 'bottom') {
            $treeMgr->change_child_order($newParent, $newId, $position,
                                         $excludeNodeTypes);
        }

        jout(['status' => 'ok', 'id' => $newId, 'message' => 'Node copied']);
    }

    jout(['status' => 'error', 'message' => 'Bad request'], 400);
}

http_response_code(400);
out(['status' => 'error', 'message' => 'Bad request']);
