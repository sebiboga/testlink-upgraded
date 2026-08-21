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
 * Read-only: no state changing operation is exposed here.
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

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

$action = $_GET['action'] ?? '';

$tcaseMgr = new testcase($db);
$tprojectMgr = new testproject($db);

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

    out([
        'status' => 'ok',
        'tproject' => ['id' => $tprojectId, 'name' => $info['name']],
        'options' => [
            'requirementsEnabled' => !empty($opt->requirementsEnabled),
            'automationEnabled' => !empty($opt->automationEnabled),
            'testPriorityEnabled' => !empty($opt->testPriorityEnabled),
        ],
        'hasTestPlans' => $hasTestPlans,
        'grants' => $grants,
        'canEditExecuted' => intval(config_get('testcase_cfg')->canEditExecuted),
        'dateFormat' => config_get('date_format'),
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

    $latestVersionNumber = 0;
    foreach ($versionsRaw as $vr) {
        $latestVersionNumber = max($latestVersionNumber, intval($vr['version']));
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
            'customFields' => $customFields,
            'attachments' => $attachments,
        ];
    }

    // requirements coverage (only when enabled + right to view)
    $requirements = [];
    $opt = $tprojectMgr->getOptions($tprojectId);
    $opt = is_null($opt) ? new stdClass() : $opt;
    $canViewReq = (!empty($opt->requirementsEnabled))
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
        'requirements' => $requirements,
        'requirementsEnabled' => !empty($opt->requirementsEnabled),
        'testPriorityEnabled' => !empty($opt->testPriorityEnabled),
        'automationEnabled' => !empty($opt->automationEnabled),
        'relations' => $relations,
        'grants' => $grants,
        'requestedTcversionId' => $tcversionId,
    ]);
}

http_response_code(400);
out(['status' => 'error', 'message' => 'Bad request']);
