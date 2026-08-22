<?php
/**
 * Execution History BFF API (read-only view)
 * URL: /api/execute/
 * Plain PHP, no framework, no compilation
 *
 * Mirrors lib/execute/execHistory.php (TestLink 1.9.20):
 * lists ALL executions of one test case (every version), filtered by the
 * test plans the current user can access, with notes, execution-level
 * custom fields, attachments and linked bugs per execution.
 * Read-only: no state changing operation is exposed here.
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
require_once('exec.inc.php');
require_once('attachments.inc.php');

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

$action = $_GET['action'] ?? '';

// ---------------------------------------------------------------------------
// GET ?action=history&tcase_id=N[&only_active_test_plans=0|1]
// Full execution set of the test case (all versions), same access rules as
// legacy execHistory.php: only executions belonging to test plans the user
// can access; optional filter to ACTIVE test plans only.
// ---------------------------------------------------------------------------
if ($action === 'history') {
    $tcaseId = getIntParam('tcase_id');
    if ($tcaseId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test case id']);
    }

    $tcaseMgr = new testcase($db);
    $tprojectMgr = new testproject($db);

    // owning test project (resolved through the hierarchy chain)
    $tables = tlObjectWithDB::getDBTables(array('nodes_hierarchy'));
    $owningProjectId = 0;
    $cur = $tcaseId;
    for ($i = 0; $i < 50 && $cur > 0; $i++) {
        $rs = $db->get_recordset(
            "SELECT id, parent_id FROM {$tables['nodes_hierarchy']} WHERE id = {$cur}");
        if (is_null($rs) || count($rs) == 0) { break; }
        if (intval($rs[0]['parent_id']) <= 0) {
            $owningProjectId = intval($rs[0]['id']);
            break;
        }
        $cur = intval($rs[0]['parent_id']);
    }
    if ($owningProjectId <= 0) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Test case not found']);
    }
    $tprojectId = $owningProjectId;

    $basic = $tcaseMgr->tree_manager->get_node_hierarchy_info($tcaseId);
    if (is_null($basic)) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Test case not found']);
    }
    $externalId = $tcaseMgr->getExternalID($tcaseId);
    // getExternalID() => [identity, prefix, glue, external_number]
    $extNum = is_array($externalId) ? intval(end($externalId)) : 0;
    $prefix = $tprojectMgr->getTestCasePrefix($tprojectId);
    $glue = config_get('testcase_cfg')->glue_character;

    // accessible test plans drive both the data set and the edit-notes grants
    // (same semantics as legacy init_args()/onlyActiveTestPlans checkbox;
    // 'active' => NULL means "no active filter", like legacy unchecked box)
    $onlyActive = getIntParam('only_active_test_plans', 0) > 0 ? 1 : null;
    $testPlanSet = (array)$user->getAccessibleTestPlans(
        $db, $tprojectId, null, array('active' => $onlyActive));

    $filters['testplan_id'] = null;
    $editNotesGrant = [];
    foreach ($testPlanSet as $rx) {
        $filters['testplan_id'][] = intval($rx['id']);
        $editNotesGrant[intval($rx['id'])] =
            $user->hasRight($db, 'exec_edit_notes', $tprojectId, intval($rx['id'])) ? 1 : 0;
    }

    $execSet = $tcaseMgr->getExecutionSet($tcaseId, null, $filters);

    $executions = [];
    $platformSet = [];
    $neverExecuted = true;

    if (!is_null($execSet) && count($execSet) > 0) {
        $neverExecuted = false;

        // executed platforms -> whether the platform column must be displayed
        // NOTE: getExecutedPlatforms() rows carry 'platform_id' and 'name'
        $rawPlatforms = $tcaseMgr->getExecutedPlatforms($tcaseId);
        if (!is_null($rawPlatforms)) {
            foreach ($rawPlatforms as $pl) {
                $pid = intval($pl['platform_id']);
                if ($pid > 0) {
                    $platformSet[$pid] = strval($pl['name']);
                }
            }
        }

        $resultsCfg = config_get('results');

        // issue tracker integration (bugs per execution), same gate as legacy
        $its = null;
        $info = $tprojectMgr->get_by_id($tprojectId);
        $bugMap = [];
        $useITS = !empty($info['issue_tracker_enabled'])
            && config_get('exec_cfg')->features->issue_tracker->enabled;
        if ($useITS) {
            try {
                $itMgr = new tlIssueTracker($db);
                $its = $itMgr->getInterfaceObject($tprojectId);
                unset($itMgr);
            } catch (Exception $e) {
                $its = null;
            }
        }

        $attachmentMgr = tlAttachmentRepository::create($db);

        foreach ($execSet as $tcversionId => $rows) {
            foreach ($rows as $ex) {
                $execId = intval($ex['execution_id']);
                $tplanIdEx = intval($ex['testplan_id']);

                // localized execution status (same source as legacy
                // localize_tc_status: lang_get('test_status_' . code_status))
                $statusCode = strval($ex['status']);
                $suffix = isset($resultsCfg['code_status'][$statusCode])
                    ? $resultsCfg['code_status'][$statusCode] : '';
                $statusLabel = ($suffix != '') ? lang_get('test_status_' . $suffix)
                                               : $statusCode;

                // execution-level custom fields
                $customFields = [];
                try {
                    $cfMap = $tcaseMgr->get_linked_cfields_at_execution(
                        intval($ex['tcversion_id']), $tcaseId, null,
                        $execId, $tplanIdEx, $tprojectId);
                    if (!is_null($cfMap)) {
                        foreach ($cfMap as $cf) {
                            $val = isset($cf['value']) ? $cf['value'] : null;
                            if (is_array($val)) { $val = implode(', ', $val); }
                            if ($val !== null && $val !== '') {
                                $customFields[] = [
                                    'label' => strval($cf['label']),
                                    'value' => (string)$val,
                                ];
                            }
                        }
                    }
                } catch (Exception $e) {
                    // no execution CF available - keep empty
                }

                // attachments of this execution
                $attachments = [];
                try {
                    $items = getAttachmentInfos($attachmentMgr, $execId, 'executions', true, 1);
                    if ($items) {
                        foreach ($items as $ai) {
                            $attachments[] = [
                                'id' => intval($ai['id']),
                                'title' => strval($ai['title']),
                                'file_name' => strval($ai['file_name']),
                                'file_size' => intval($ai['file_size']),
                                'download_url' =>
                                    'lib/attachments/attachmentdownload.php?id=' . intval($ai['id']),
                            ];
                        }
                    }
                } catch (Exception $e) {
                    // attachments not available - keep empty
                }

                // bugs linked to this execution
                $bugs = [];
                if ($useITS && !is_null($its)) {
                    try {
                        $dummy = get_bugs_for_exec($db, $its, $execId);
                        foreach ($dummy as $bugId => $bi) {
                            $bugs[] = [
                                'id' => strval($bugId),
                                'link_to_bts' => $bi['link_to_bts'],
                            ];
                        }
                    } catch (Exception $e) {
                        // BTS not reachable - keep empty
                    }
                } else {
                    // graceful fallback: show raw bug strings when no BTS is configured
                    try {
                        $ebTables = tlObjectWithDB::getDBTables(array('execution_bugs'));
                        $brs = $db->get_recordset(
                            "SELECT bug_id FROM {$ebTables['execution_bugs']} " .
                            "WHERE execution_id = {$execId}");
                        if (!is_null($brs)) {
                            foreach ($brs as $br) {
                                $bugs[] = ['id' => strval($br['bug_id']), 'link_to_bts' => ''];
                            }
                        }
                    } catch (Exception $e) {
                        // ignore
                    }
                }

                $testerLogin = '';
                $testerName = '';
                if (!empty($ex['tester_login'])) {
                    $testerLogin = strval($ex['tester_login']);
                    $full = trim(strval($ex['tester_first_name']) . ' ' .
                                 strval($ex['tester_last_name']));
                    $testerName = ($full != '') ? $full : $testerLogin;
                }

                $executions[] = [
                    'execution_id' => $execId,
                    'tcversion_id' => intval($ex['tcversion_id']),
                    'tcversion_number' => intval($ex['tcversion_number']),
                    'testplan_id' => $tplanIdEx,
                    'testplan_name' => strval($ex['testplan_name']),
                    'build_id' => intval($ex['build_id']),
                    'build_name' => strval($ex['build_name']),
                    'platform_id' => intval($ex['platform_id']),
                    'platform_name' => strval($ex['platform_name']),
                    'tester_id' => intval($ex['tester_id']),
                    'tester_login' => $testerLogin,
                    'tester_name' => $testerName,
                    'status_code' => $statusCode,
                    'status_suffix' => $suffix,
                    'status_label' => $statusLabel,
                    'execution_ts' => strval($ex['execution_ts']),
                    'notes' => strval($ex['execution_notes']),
                    'run_type' => intval($ex['execution_run_type'])
                        === TESTCASE_EXECUTION_TYPE_AUTO ? 'automated' : 'manual',
                    'can_edit_notes' => isset($editNotesGrant[$tplanIdEx])
                        ? $editNotesGrant[$tplanIdEx] : 0,
                    'customFields' => $customFields,
                    'attachments' => $attachments,
                    'bugs' => $bugs,
                ];

                if (isset($platformSet[intval($ex['platform_id'])]) === false
                    && intval($ex['platform_id']) > 0) {
                    $platformSet[intval($ex['platform_id'])] = strval($ex['platform_name']);
                }
            }
        }
    }

    out([
        'status' => 'ok',
        'tcase' => ['id' => $tcaseId, 'name' => strval($basic['name'])],
        'tproject' => ['id' => $tprojectId],
        'fullExternalId' => $prefix . $glue . $extNum,
        'executions' => $executions,
        'neverExecuted' => $neverExecuted,
        'displayPlatformCol' => count($platformSet) > 0,
        'dateFormat' => config_get('date_format'),
    ]);
}

http_response_code(400);
out(['status' => 'error', 'message' => 'Bad request']);
