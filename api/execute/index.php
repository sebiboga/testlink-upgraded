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
 *
 * Since #662 this API ALSO backs the modernized Execute Tests screen
 * (gui/templates/execute/execTest.html), mirroring the legacy
 * lib/execute/execNavigator.php + execSetResults.php flow:
 *   ?action=init      - context for one test plan: builds/platforms/grants
 *   ?action=tcList    - linked test case versions + latest exec status
 *   ?action=tcDetails - version details incl. steps + prior execution
 *   POST ?action=save - write an execution (status/notes/step results),
 *                       reusing legacy write_execution() from exec.inc.php
 *   POST ?action=linkBug   - link an issue to an execution (optionally bound
 *                            to a step), mirroring legacy bugAdd.php?user_action=link
 *   POST ?action=unlinkBug - remove a bug link (legacy bugDelete.php)
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

/**
 * Read the request payload of the Execute Tests save actions. Supports both
 * application/json bodies (legacy save calls) and multipart/form-data POSTs
 * (needed to carry attachment uploads). In multipart mode the step results
 * are transported as a JSON-encoded string field 'steps_json'.
 */
function execTestPayload() {
    $ct = strtolower(strval($_SERVER['CONTENT_TYPE'] ?? ''));
    if (strpos($ct, 'application/json') !== false) {
        $j = json_decode(file_get_contents('php://input'), true);
        return is_array($j) ? $j : [];
    }
    $p = $_POST;
    if (isset($p['steps_json'])) {
        $s = json_decode(strval($p['steps_json']), true);
        $p['steps'] = is_array($s) ? $s : [];
    }
    return $p;
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
                                'bug_url' => (string)$its->buildViewBugURL($bugId),
                                'tcstep_id' => intval($bi['tcstep_id'] ?? 0),
                                'step_number' => intval($bi['step_number'] ?? 0),
                            ];
                        }
                    } catch (Exception $e) {
                        // BTS not reachable - keep empty
                    }
                } else {
                    // graceful fallback: show raw bug strings when no BTS is configured
                    try {
                        $tables = tlObjectWithDB::getDBTables(
                            array('execution_bugs', 'tcsteps'));
                        $brs = $db->get_recordset(
                            "SELECT eb.bug_id, eb.tcstep_id, s.step_number " .
                            "FROM {$tables['execution_bugs']} eb " .
                            "LEFT JOIN {$tables['tcsteps']} s ON s.id = eb.tcstep_id " .
                            "WHERE eb.execution_id = {$execId}");
                        if (!is_null($brs)) {
                            foreach ($brs as $br) {
                                $bugs[] = [
                                    'id' => strval($br['bug_id']),
                                    'link_to_bts' => '',
                                    'tcstep_id' => intval($br['tcstep_id'] ?? 0),
                                    'step_number' => intval($br['step_number'] ?? 0),
                                ];
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
                    'execution_duration' => strval($ex['execution_duration'] ?? ''),
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

// ===========================================================================
// Execute Tests screen (Refs #662)
// ===========================================================================

require_once('testplan.class.php');
require_once('testcase.class.php');
require_once('testproject.class.php');

/**
 * Resolve + validate the (tproject, tplan) context for the Execute Tests
 * screen. The plan MUST be one of the user's accessible test plans, exactly
 * like legacy execSetResults/init_args() does.
 */
function execTestResolveContext($db, $user, $tplanId) {
    if ($tplanId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Missing tplan_id']);
    }
    $tplanMgr = new testplan($db);

    $planInfo = $tplanMgr->get_by_id($tplanId);
    if (is_null($planInfo) || !isset($planInfo['name'])) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Test plan not found']);
    }

    // owning test project = parent of the test plan node
    $tables = tlObjectWithDB::getDBTables(array('nodes_hierarchy'));
    $rs = $db->get_recordset(
        "SELECT parent_id FROM {$tables['nodes_hierarchy']} WHERE id = {$tplanId}");
    $tprojectId = (!is_null($rs) && count($rs) > 0)
        ? intval($rs[0]['parent_id']) : 0;
    if ($tprojectId <= 0) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Test project not found']);
    }

    // access check: plan must be in the user's accessible set for the project
    $accessible = (array)$user->getAccessibleTestPlans($db, $tprojectId, null, null);
    $found = false;
    foreach ($accessible as $ap) {
        if (intval($ap['id']) === $tplanId) { $found = true; break; }
    }
    if (!$found) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'Access to this test plan denied']);
    }
    return [$tplanMgr, $tplanId, $tprojectId, strval($planInfo['name'])];
}

if ($action === 'init') {
    $tplanId = getIntParam('tplan_id');
    list($tplanMgr, , $tprojectId, $tplanName) =
        execTestResolveContext($db, $user, $tplanId);
    $tprojectMgr = new testproject($db);
    $tprojInfo = $tprojectMgr->get_by_id($tprojectId);
    if (is_null($tprojInfo)) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Test project not found']);
    }

    $canExecute = $user->hasRight($db, 'testplan_execute', $tprojectId, $tplanId);
    $roAccess = $user->hasRight($db, 'exec_ro_access', $tprojectId, $tplanId);
    if (!$canExecute && !$roAccess) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'Insufficient rights']);
    }

    // builds of the plan (all of them; UI filters active+open for execution,
    // same as legacy execSetResults which only offers active & open builds)
    $builds = [];
    $defaultBuildId = 0;
    $rawBuilds = $tplanMgr->get_builds($tplanId);
    if (!is_null($rawBuilds)) {
        foreach ($rawBuilds as $bid => $b) {
            $isActive = intval($b['active']) === 1;
            $isOpen = intval($b['is_open']) === 1;
            $builds[] = [
                'id' => intval($b['id']),
                'name' => strval($b['name']),
                'active' => $isActive,
                'open' => $isOpen,
                'executable' => ($isActive && $isOpen),
                'release_date' => isset($b['release_date'])
                    ? strval($b['release_date']) : '',
            ];
            if ($isActive && $isOpen
                && intval($b['id']) > $defaultBuildId) {
                // legacy default = newest active+open build (highest id wins,
                // matching the ORDER BY id DESC used by execSetResults)
                $defaultBuildId = intval($b['id']);
            }
        }
    }
    usort($builds, function ($a, $b) { return $a['id'] < $b['id'] ? 1 : -1; });

    // platforms linked to the plan
    $platforms = [];
    try {
        $rawPlatforms = $tplanMgr->getPlatforms($tplanId);
        if (!is_null($rawPlatforms)) {
            foreach ($rawPlatforms as $p) {
                $platforms[] = [
                    'id' => intval($p['id']),
                    'name' => strval($p['name']),
                ];
            }
        }
    } catch (Exception $e) {
        $platforms = [];
    }

    // execution status vocabulary (same source as legacy localize_tc_status)
    $resultsCfg = config_get('results');
    $statuses = [];
    foreach ($resultsCfg['code_status'] as $code => $suffix) {
        $statuses[] = [
            'code' => strval($code),
            'suffix' => strval($suffix),
            'label' => lang_get('test_status_' . $suffix),
        ];
    }

    // save-and-move navigation mode (legacy execSetResults.php:296)
    // 'unlimited' => move on the whole execution working set,
    // 'limited'   => move only within the current test suite.
    // Default 'unlimited' matches config.inc.php:1119.
    $execCfg = config_get('exec_cfg');
    $saveAndMove = (isset($execCfg->exec_mode)
        && isset($execCfg->exec_mode->save_and_move))
        ? strval($execCfg->exec_mode->save_and_move) : 'unlimited';

    // feature flags live in the serialized options blob (the option_* columns
    // are vestigial and never written - see #525); read them defensively so a
    // missing/unserializable blob can never raise an E_WARNING here (#662)
    $tprojOptions = null;
    if (!empty($tprojInfo['options'])) {
        $tprojOptions = @unserialize($tprojInfo['options']);
    }
    $platformFeature = is_object($tprojOptions)
        && !empty($tprojOptions->platformsEnabled);

    out([
        'status' => 'ok',
        'tproject' => [
            'id' => $tprojectId,
            'name' => strval($tprojInfo['name']),
            'prefix' => strval($tprojInfo['prefix']),
        ],
        'tplan' => ['id' => $tplanId, 'name' => $tplanName],
        'grants' => ['can_execute' => $canExecute ? 1 : 0,
                     'ro_access' => $roAccess ? 1 : 0],
        'builds' => $builds,
        'default_build_id' => $defaultBuildId,
        'platforms' => $platforms,
        'platform_feature_enabled' => $platformFeature,
        'save_and_move' => $saveAndMove,
        // legacy feature flag: render the Execution Time input on the exec
        // form and persist it (exec_cfg->features->exec_duration->enabled)
        'exec_duration_enabled' =>
            (isset($execCfg->features)
             && isset($execCfg->features->exec_duration)
             && !empty($execCfg->features->exec_duration->enabled)) ? 1 : 0,
        // legacy exec_mode->new_exec == 'latest' gates the "Copy attachments
        // from latest execution" checkbox (inc_exec_img_controls.tpl:68-73)
        'new_exec_latest' => (isset($execCfg->exec_mode)
            && isset($execCfg->exec_mode->new_exec)
            && strval($execCfg->exec_mode->new_exec) === 'latest') ? 1 : 0,
        'statuses' => $statuses,
    ]);
}

if ($action === 'tcList') {
    $tplanId = getIntParam('tplan_id');
    list($tplanMgr, , $tprojectId, ) = execTestResolveContext($db, $user, $tplanId);

    $canExecute = $user->hasRight($db, 'testplan_execute', $tprojectId, $tplanId);
    $roAccess = $user->hasRight($db, 'exec_ro_access', $tprojectId, $tplanId);
    if (!$canExecute && !$roAccess) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'Insufficient rights']);
    }

    $buildId = getIntParam('build_id');
    $platformId = getIntParam('platform_id');   // 0 / missing => no-platform mode
    $rawQ = $_GET['q'] ?? '';
    $search = is_string($rawQ) ? trim($rawQ) : '';

    $tables = tlObjectWithDB::getDBTables(array(
        'testplan_tcversions', 'nodes_hierarchy', 'tcversions', 'executions'));

    $sql = "SELECT NHTC.id AS tcase_id, NHTC.name AS tcase_name," .
           " NHTCV.parent_id AS tsuite_id, TPTCV.tcversion_id," .
           " TCV.version, TCV.active, TCV.tc_external_id, TCV.importance," .
           " TCV.execution_type" .
           " FROM {$tables['testplan_tcversions']} TPTCV" .
           " JOIN {$tables['nodes_hierarchy']} NHTCV" .
           " ON NHTCV.id = TPTCV.tcversion_id" .
           " JOIN {$tables['nodes_hierarchy']} NHTC" .
           " ON NHTC.id = NHTCV.parent_id" .
           " JOIN {$tables['tcversions']} TCV ON TCV.id = TPTCV.tcversion_id" .
           " WHERE TPTCV.testplan_id = {$tplanId}" .
           " ORDER BY NHTC.node_order, NHTC.id, TCV.version DESC";

    $rows = $db->get_recordset($sql);
    if (is_null($rows)) { $rows = []; }

    // group the linked rows per test case keeping the LATEST version as the
    // list/display row, but PRESERVING every linked version in 'versions'
    // (newest first, per the ORDER BY TCV.version DESC) so older linked
    // versions stay selectable/executable like in the legacy exec tree (#794)
    $tcGroups = [];
    foreach ($rows as $r) {
        $tid = intval($r['tcase_id']);
        if (!isset($tcGroups[$tid])) {
            $tcGroups[$tid] = ['latest' => $r, 'versions' => []];
        }
        $tcGroups[$tid]['versions'][] = $r;
        if (intval($r['version']) > intval($tcGroups[$tid]['latest']['version'])) {
            $tcGroups[$tid]['latest'] = $r;
        }
    }

    // latest execution status per tcversion on the selected build/platform
    // (computed over ALL linked versions so the per-version status is present)
    $execMap = [];
    if ($buildId > 0 && count($tcGroups) > 0) {
        $tcvIds = [];
        foreach ($tcGroups as $g) {
            foreach ($g['versions'] as $rv) { $tcvIds[] = intval($rv['tcversion_id']); }
        }
        // plans without platforms store platform_id = 0 on executions
        $storedPlatform = $platformId > 0 ? $platformId : 0;
        $tcvSet = implode(',', $tcvIds);
        $sqlE =
            "SELECT E.tcversion_id, E.status, E.id AS execution_id," .
            " E.notes, E.execution_ts, E.tester_id, U.login AS tester_login" .
            " FROM {$tables['executions']} E" .
            " LEFT JOIN users U ON U.id = E.tester_id" .
            " JOIN (SELECT MAX(id) AS max_id FROM {$tables['executions']}" .
            "       WHERE testplan_id = {$tplanId}" .
            "       AND build_id = {$buildId}" .
            "       AND platform_id = {$storedPlatform}" .
            "       AND tcversion_id IN ({$tcvSet})" .
            "       GROUP BY tcversion_id) X ON X.max_id = E.id";
        $ers = $db->get_recordset($sqlE);
        if (!is_null($ers)) {
            foreach ($ers as $er) {
                $execMap[intval($er['tcversion_id'])] = [
                    'execution_id' => intval($er['execution_id']),
                    'status' => strval($er['status']),
                    'notes' => strval($er['notes']),
                    'execution_ts' => strval($er['execution_ts']),
                    'tester_login' => strval($er['tester_login']),
                ];
            }
        }
    }

    // suite paths (for grouping display), resolved through the tree manager
    $pathMap = [];
    try {
        $tsuiteIds = array_values(array_unique(array_map(function ($r) {
            return intval($r['tsuite_id']); }, array_column($tcGroups, 'latest'))));
        if (count($tsuiteIds) > 0) {
            $treePaths = $tplanMgr->tree_manager->get_full_path_verbose($tsuiteIds);
            if (!is_null($treePaths)) {
                foreach ($treePaths as $sid => $parts) {
                    $parts = is_array($parts) ? $parts : [$parts];
                    $pathMap[$sid] = implode(' / ', array_map('strval', $parts));
                }
            }
        }
    } catch (Exception $e) {
        // cosmetic only - keep empty
    }

    $prefixGlue = config_get('testcase_cfg')->glue_character;

    // real prefix fetch (single query, reused for every row)
    $tprojectMgr = new testproject($db);
    $prefix = $tprojectMgr->getTestCasePrefix($tprojectId);

    $items = [];
    foreach ($tcGroups as $tid => $g) {
        $r = $g['latest'];
        $ext = intval($r['tc_external_id']);
        $fullExtId = ($ext > 0) ? ($prefix . $prefixGlue . $ext) : '';
        $tcvId = intval($r['tcversion_id']);
        $prior = isset($execMap[$tcvId]) ? $execMap[$tcvId] : null;
        $haystack = strtolower($fullExtId . ' ' . strval($r['tcase_name']));
        if ($search !== ''
            && strpos($haystack, strtolower($search)) === false) {
            continue;
        }
        // every linked version of this test case (newest first), with the
        // per-version last execution on the current build/platform context
        $versions = [];
        foreach ($g['versions'] as $rv) {
            $rvId = intval($rv['tcversion_id']);
            $versions[] = [
                'tcversion_id' => $rvId,
                'version' => intval($rv['version']),
                'active' => intval($rv['active']),
                'last_execution' => isset($execMap[$rvId])
                    ? $execMap[$rvId] : null,
            ];
        }
        $items[] = [
            'tcase_id' => intval($r['tcase_id']),
            'tcase_name' => strval($r['tcase_name']),
            'tcversion_id' => $tcvId,
            'version' => intval($r['version']),
            'active' => intval($r['active']),
            'full_external_id' => $fullExtId,
            'importance' => intval($r['importance']),
            'execution_type' => intval($r['execution_type']),
            'tsuite_id' => intval($r['tsuite_id']),
            'tsuite_path' => isset($pathMap[$r['tsuite_id']])
                ? $pathMap[$r['tsuite_id']] : '',
            'versions' => $versions,
            'last_execution' => $prior,
        ];
    }

    usort($items, function ($a, $b) {
        return strcasecmp($a['tcase_name'], $b['tcase_name']);
    });

    out(['status' => 'ok', 'count' => count($items), 'items' => $items]);
}

if ($action === 'tcDetails') {
    $tplanId = getIntParam('tplan_id');
    list($tplanMgr, , $tprojectId, ) = execTestResolveContext($db, $user, $tplanId);

    $canExecute = $user->hasRight($db, 'testplan_execute', $tprojectId, $tplanId);
    $roAccess = $user->hasRight($db, 'exec_ro_access', $tprojectId, $tplanId);
    if (!$canExecute && !$roAccess) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'Insufficient rights']);
    }

    // issue tracker integration (bugs per execution), same gate as the
    // /history action - drives the clickable linked-bug badges
    $tprojectMgr = new testproject($db);
    $its = null;
    $info = $tprojectMgr->get_by_id($tprojectId);
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

    $tcaseId = getIntParam('tcase_id');
    $tcversionId = getIntParam('tcversion_id');
    $buildId = getIntParam('build_id');
    $platformId = getIntParam('platform_id');
    if ($tcaseId <= 0 || $tcversionId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Missing tcase_id/tcversion_id']);
    }

    $tcaseMgr = new testcase($db);
    $basic = $tcaseMgr->tree_manager->get_node_hierarchy_info($tcaseId);
    if (is_null($basic)) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Test case not found']);
    }
    // the requested version must belong to this test case AND be linked to
    // the current plan (same guarantee execSetResults relies on)
    $tables = tlObjectWithDB::getDBTables(array(
        'tcversions', 'testplan_tcversions', 'nodes_hierarchy'));
    $vr = $db->get_recordset(
        "SELECT V.id, V.version, V.active, V.summary, V.preconditions," .
        " V.importance, V.execution_type, V.tc_external_id," .
        " NH.parent_id" .
        " FROM {$tables['tcversions']} V" .
        " JOIN {$tables['nodes_hierarchy']} NH ON NH.id = V.id" .
        " WHERE V.id = {$tcversionId}");
    if (is_null($vr) || count($vr) == 0
        || intval($vr[0]['parent_id']) !== $tcaseId) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Version does not belong to test case']);
    }
    $linkRs = $db->get_recordset(
        "SELECT id FROM {$tables['testplan_tcversions']}" .
        " WHERE testplan_id = {$tplanId} AND tcversion_id = {$tcversionId}");
    if (is_null($linkRs) || count($linkRs) == 0) {
        http_response_code(404);
        out(['status' => 'error',
             'message' => 'This version is not linked to the test plan']);
    }
    $vinfo = $vr[0];

    // steps of the version
    $steps = [];
    try {
        $stepRows = $tcaseMgr->get_steps($tcversionId);
        if (!is_null($stepRows)) {
            foreach ($stepRows as $st) {
                $steps[] = [
                    'id' => intval($st['id']),
                    'step_number' => intval($st['step_number']),
                    'actions' => strval($st['actions']),
                    'expected_results' => strval($st['expected_results']),
                ];
            }
            usort($steps, function ($a, $b) {
                return $a['step_number'] - $b['step_number'];
            });
        }
    } catch (Exception $e) {
        $steps = [];
    }

    // latest execution of THIS version on THIS build(+platform), with the
    // recorded step-level results (partial execution feature)
    $prior = null;
    $priorSteps = [];
    if ($buildId > 0) {
        $execTables = tlObjectWithDB::getDBTables(array(
            'executions', 'execution_tcsteps', 'users'));
        $storedPlatform = $platformId > 0 ? $platformId : 0;
        $er = $db->get_recordset(
            "SELECT E.id AS execution_id, E.status, E.notes, E.execution_ts," .
            " E.execution_duration, E.tester_id, U.login AS tester_login" .
            " FROM {$execTables['executions']} E" .
            " LEFT JOIN {$execTables['users']} U ON U.id = E.tester_id" .
            " WHERE E.testplan_id = {$tplanId}" .
            " AND E.build_id = {$buildId}" .
            " AND E.platform_id = {$storedPlatform}" .
            " AND E.tcversion_id = {$tcversionId}" .
            " ORDER BY E.id DESC");
        if (!is_null($er) && count($er) > 0) {
            $prior = [
                'execution_id' => intval($er[0]['execution_id']),
                'status' => strval($er[0]['status']),
                'notes' => strval($er[0]['notes']),
                'execution_ts' => strval($er[0]['execution_ts']),
                'execution_duration' => strval($er[0]['execution_duration'] ?? ''),
                'tester_login' => strval($er[0]['tester_login']),
            ];
            // execution-level attachments of the prior run, so the modern
            // Execute Tests form can list them and offer copy-from-latest
            // (same sink as the execHistory /history action)
            $prior['attachments'] = [];
            try {
                $attachmentMgr = tlAttachmentRepository::create($db);
                $attItems = getAttachmentInfos($attachmentMgr,
                    intval($er[0]['execution_id']), 'executions', true, 1);
                if ($attItems) {
                    foreach ($attItems as $ai) {
                        // root-absolute so the link resolves from the page
                        // served under /gui/templates/execute/ (#792)
                        $prior['attachments'][] = [
                            'id' => intval($ai['id']),
                            'title' => strval($ai['title']),
                            'file_name' => strval($ai['file_name']),
                            'file_size' => intval($ai['file_size']),
                            'download_url' =>
                                '/lib/attachments/attachmentdownload.php?id=' . intval($ai['id']),
                        ];
                    }
                }
            } catch (Exception $e) {
                $prior['attachments'] = [];
            }
            $sr = $db->get_recordset(
                "SELECT tcstep_id, notes, status" .
                " FROM {$execTables['execution_tcsteps']}" .
                " WHERE execution_id = " . intval($er[0]['execution_id']));
            if (!is_null($sr)) {
                foreach ($sr as $srow) {
                    $priorSteps[intval($srow['tcstep_id'])] = [
                        'notes' => strval($srow['notes']),
                        'status' => strval($srow['status']),
                    ];
                }
            }
        }

        // Steps Work-In-Progress residency (legacy "Save steps partial
        // execution" / resume, execSetResults.php:380-404 + 
        // testcase::getStepsPartialExec()): WIP rows OVERRIDE the recorded
        // step results of the last full execution, so a partially-saved run
        // is the state the tester resumes from next time.
        if (count($steps) > 0) {
            $wipCtx = new stdClass();
            $wipCtx->testplan_id = $tplanId;
            $wipCtx->platform_id = $storedPlatform;
            $wipCtx->build_id = $buildId;
            $wipRows = $tcaseMgr->getStepsPartialExec(
                array_map(function ($s) { return intval($s['id']); }, $steps),
                $wipCtx);
            if (!is_null($wipRows)) {
                foreach ($wipRows as $stepId => $wrow) {
                    $priorSteps[intval($stepId)] = [
                        'notes' => strval($wrow['notes']),
                        'status' => strval($wrow['status']),
                    ];
                }
            }
        }
    }

    // Full inline execution history for this version, mirroring the legacy
    // getOtherExecutions() (execSetResults.php) used to render the inline
    // execution_history table. We fetch the whole set for this plan (all
    // builds and platforms, like history_on + show_history_all_*), newest
    // first, and carry per-row grants (edit-notes / delete) + build state
    // so the modern Execute Tests form can show the same row actions.
    $execHistory = [];
    try {
        $execHistoryRows = $tcaseMgr->getExecutionSet(
            $tcaseId, $tcversionId,
            ['testplan_id' => $tplanId, 'build_id' => null, 'platform_id' => null],
            ['exec_id_order' => 'DESC']);
        $canEditNotesGlobal = $user->hasRight($db, 'exec_edit_notes', $tprojectId, $tplanId) ? 1 : 0;
        $canDeleteGlobal = $user->hasRight($db, 'exec_delete', $tprojectId, $tplanId) ? 1 : 0;
        $resultsCfgH = config_get('results');
        if (is_array($execHistoryRows)) {
            foreach ($execHistoryRows as $hVersionRows) {
                if (!is_array($hVersionRows)) { continue; }
                foreach ($hVersionRows as $h) {
                    $hExecId = intval($h['execution_id']);
                    $hStatus = strval($h['status']);
                    $hSuffix = isset($resultsCfgH['code_status'][$hStatus])
                        ? $resultsCfgH['code_status'][$hStatus] : '';
                    $buildOpen = intval($h['build_is_open']) === 1;
                    $hTester = trim(strval($h['tester_first_name'] ?? '') . ' ' .
                                    strval($h['tester_last_name'] ?? ''));
                    if ($hTester === '' && !empty($h['tester_login'])) $hTester = strval($h['tester_login']);
                    // bugs linked to this execution (with the tcstep link),
                    // same source + gate as the /history action
                    $hBugs = [];
                    if ($useITS && !is_null($its)) {
                        try {
                            $dummyB = get_bugs_for_exec($db, $its, $hExecId);
                            foreach ($dummyB as $bugIdB => $biB) {
                                $hBugs[] = [
                                    'id' => strval($bugIdB),
                                    'link_to_bts' => $biB['link_to_bts'],
                                    'bug_url' => (string)$its->buildViewBugURL($bugIdB),
                                    'tcstep_id' => intval($biB['tcstep_id'] ?? 0),
                                    'step_number' => intval($biB['step_number'] ?? 0),
                                ];
                            }
                        } catch (Exception $e) { $hBugs = []; }
                    } else {
                        try {
                            $tblB = tlObjectWithDB::getDBTables(
                                array('execution_bugs', 'tcsteps'));
                            $brsB = $db->get_recordset(
                                "SELECT eb.bug_id, eb.tcstep_id, s.step_number " .
                                "FROM {$tblB['execution_bugs']} eb " .
                                "LEFT JOIN {$tblB['tcsteps']} s ON s.id = eb.tcstep_id " .
                                "WHERE eb.execution_id = {$hExecId}");
                            if (!is_null($brsB)) {
                                foreach ($brsB as $brB) {
                                    $hBugs[] = [
                                        'id' => strval($brB['bug_id']),
                                        'link_to_bts' => '',
                                        'tcstep_id' => intval($brB['tcstep_id'] ?? 0),
                                        'step_number' => intval($brB['step_number'] ?? 0),
                                    ];
                                }
                            }
                        } catch (Exception $e) { $hBugs = []; }
                    }
                    $execHistory[] = [
                    'execution_id' => $hExecId,
                    'tcversion_id' => intval($h['tcversion_id']),
                    'testplan_id' => intval($h['testplan_id']),
                    'tcversion_number' => intval($h['tcversion_number']),
                        'build_id' => intval($h['build_id']),
                        'build_name' => strval($h['build_name']),
                        'build_is_open' => $buildOpen,
                        'platform_name' => strval($h['platform_name']),
                        'tester_id' => intval($h['tester_id']),
                        'tester_login' => strval($h['tester_login']),
                        'tester_name' => $hTester,
                        'status_code' => $hStatus,
                        'status_suffix' => $hSuffix,
                        'status_label' => ($hSuffix != '')
                            ? lang_get('test_status_' . $hSuffix) : $hStatus,
                        'execution_ts' => strval($h['execution_ts']),
                        'execution_duration' => strval($h['execution_duration']),
                        'run_type' => intval($h['execution_run_type'])
                            === TESTCASE_EXECUTION_TYPE_AUTO ? 'automated' : 'manual',
                        'notes' => strval($h['execution_notes']),
                        'can_edit_notes' => ($canEditNotesGlobal && $buildOpen) ? 1 : 0,
                        'can_delete' => ($canDeleteGlobal && $buildOpen) ? 1 : 0,
                        'bugs' => $hBugs,
                    ];
                }
            }
        }
    } catch (Exception $e) {
        $execHistory = [];
    }

    $tprojectMgr = new testproject($db);
    $prefix = $tprojectMgr->getTestCasePrefix($tprojectId);
    $ext = intval($vinfo['tc_external_id']);
    $glue = config_get('testcase_cfg')->glue_character;

    out([
        'status' => 'ok',
        'tcase' => ['id' => $tcaseId, 'name' => strval($basic['name'])],
        'version' => [
            'id' => $tcversionId,
            'number' => intval($vinfo['version']),
            'active' => intval($vinfo['active']),
            'summary' => strval($vinfo['summary']),
            'preconditions' => strval($vinfo['preconditions']),
            'importance' => intval($vinfo['importance']),
            'execution_type' => intval($vinfo['execution_type']),
            'full_external_id' =>
                $prefix . $glue . ($ext > 0 ? $ext : ''),
        ],
        'steps' => $steps,
        'prior_execution' => $prior,
        'prior_step_results' => $priorSteps,
        'exec_history' => $execHistory,
    ]);
}

// POST ?action=save  (JSON body)
// Writes ONE execution row through legacy write_execution(), so every side
// effect of a manual execution (req-link freezing, relation closing,
// step partial-execution rows, custom field values) keeps working.
if ($action === 'save') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        out(['status' => 'error', 'message' => 'POST required']);
    }
    $payload = execTestPayload();
    if (!is_array($payload) || count($payload) == 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid request body']);
    }
    $tplanId = intval($payload['tplan_id'] ?? 0);
    list($tplanMgr, , $tprojectId, ) = execTestResolveContext($db, $user, $tplanId);

    // WRITE right required here (exec_ro_access is NOT enough)
    if (!$user->hasRight($db, 'testplan_execute', $tprojectId, $tplanId)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'Insufficient rights']);
    }

    $tcaseId = intval($payload['tcase_id'] ?? 0);
    $tcversionId = intval($payload['tcversion_id'] ?? 0);
    $versionNumber = intval($payload['version_number'] ?? 0);
    $buildId = intval($payload['build_id'] ?? 0);
    $platformId = intval($payload['platform_id'] ?? -1); // -1 = plan has none
    $statusCode = strtolower(trim(strval($payload['status'] ?? '')));
    $notes = strval($payload['notes'] ?? '');
    // Execution duration (seconds, legacy 'execution_time' input) is optional
    // and only persisted when the feature is enabled (config exec_cfg->
    // features->exec_duration->enabled). Passed through to write_execution(),
    // which maps an empty/invalid value to NULL (exec.inc.php:153-158).
    $executionDuration = '';
    if (config_get('exec_cfg')->features->exec_duration->enabled) {
        $executionDuration = strval($payload['execution_duration'] ?? '');
    }
    // legacy copyAttFromLEXEC checkbox -> '1' when checked (see
    // inc_exec_img_controls.tpl:71); surfaced to the frontend as
    // 'copy_att_from_lexec'
    $copyAttFromLEXEC = (isset($payload['copy_att_from_lexec'])
        && in_array(strtolower(strval($payload['copy_att_from_lexec'])),
                    ['1', 'true', 'on', 'yes']));
    $stepsIn = isset($payload['steps']) && is_array($payload['steps'])
        ? $payload['steps'] : [];

    if ($tcaseId <= 0 || $tcversionId <= 0 || $versionNumber <= 0) {
        http_response_code(400);
        out(['status' => 'error',
             'message' => 'Missing tcase_id/tcversion_id/version_number']);
    }

    $resultsCfg = config_get('results');
    // payload carries STATUS CODES ('p','f','b','n',...) exactly as served
    // by ?action=init -> statuses[].code
    if (!isset($resultsCfg['code_status'][$statusCode])) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid status code']);
    }

    // build must belong to the plan and be executable (active+open), exactly
    // what the legacy UI offered; closed/inactive builds are rejected here
    $validBuild = false;
    $rawBuilds = $tplanMgr->get_builds($tplanId);
    if (!is_null($rawBuilds)) {
        foreach ($rawBuilds as $bid => $b) {
            if (intval($bid) === $buildId
                && intval($b['active']) === 1 && intval($b['is_open']) === 1) {
                $validBuild = true;
                break;
            }
        }
    }
    if (!$validBuild) {
        http_response_code(400);
        out(['status' => 'error',
             'message' => 'Invalid or non-executable build for this plan']);
    }

    // version must still be linked to the plan
    $lt = tlObjectWithDB::getDBTables(array('testplan_tcversions'));
    $lr = $db->get_recordset(
        "SELECT id FROM {$lt['testplan_tcversions']}" .
        " WHERE testplan_id = {$tplanId} AND tcversion_id = {$tcversionId}");
    if (is_null($lr) || count($lr) == 0) {
        http_response_code(400);
        out(['status' => 'error',
             'message' => 'Version is not linked to this test plan']);
    }

    $notRun = $resultsCfg['status_code']['not_run'];
    if ($statusCode === $notRun) {
        // legacy parity: write_execution() ignores not_run results entirely
        out(['status' => 'ok', 'saved' => false, 'reason' => 'not_run']);
    }

    // shape the data exactly the way exec.inc.php:write_execution() expects
    $execSign = new stdClass();
    $execSign->tproject_id = $tprojectId;
    $execSign->tplan_id = $tplanId;
    $execSign->build_id = $buildId;
    $execSign->platform_id = $platformId;
    $execSign->user_id = $userId;

    $execData = array();
    $execData['statusSingle'][$tcversionId] = $statusCode;
    $execData['tc_version'][$tcversionId] = $tcaseId;
    $execData['version_number'][$tcversionId] = $versionNumber;
    // write_execution() applies prepare_string() itself for single saves
    $execData['notes'][$tcversionId] = trim($notes);
    $execData['execution_duration'] = $executionDuration;

    // step-level results: keys are STEP IDS (like legacy step_notes[] inputs);
    // every submitted id must belong to THIS version (tcsteps.parent_id),
    // otherwise forged ids could touch unrelated partial-execution rows
    if (count($stepsIn) > 0) {
        $stepNotes = array();
        $stepStatus = array();
        $wanted = array_map('intval', array_keys($stepsIn));
        $validStepIds = array();
        if (count($wanted) > 0) {
            // NOTE: tcsteps is not part of tlObjectWithDB's table dictionary
            // (exec.inc.php references it as DB_TABLE_PREFIX.'tcsteps' too);
            // the step -> tcversion relation lives in nodes_hierarchy
            $nhTables = tlObjectWithDB::getDBTables(array('nodes_hierarchy'));
            $tcstepsTable = DB_TABLE_PREFIX . 'tcsteps';
            $stRs = $db->get_recordset(
                "SELECT S.id FROM {$tcstepsTable} S" .
                " JOIN {$nhTables['nodes_hierarchy']} NH ON NH.id = S.id" .
                " WHERE NH.parent_id = {$tcversionId}" .
                " AND S.id IN (" . implode(',', $wanted) . ")");
            if (!is_null($stRs)) {
                foreach ($stRs as $sr) { $validStepIds[intval($sr['id'])] = 1; }
            }
        }
        foreach ($stepsIn as $sid => $sv) {
            $sid = intval($sid);
            if ($sid <= 0 || !isset($validStepIds[$sid])) { continue; }
            $sn = isset($sv['notes']) ? strval($sv['notes']) : '';
            $ss = isset($sv['status'])
                ? strtolower(trim(strval($sv['status']))) : $notRun;
            if (!isset($resultsCfg['code_status'][$ss])) { $ss = $notRun; }
            $stepNotes[$sid] = $sn;
            $stepStatus[$sid] = $ss; // code, passed through unchanged
        }
        if (count($stepNotes) > 0) {
            $execData['step_notes'] = $stepNotes;
            $execData['step_status'] = $stepStatus;
            // deleteStepsPartialExec() reads the request superglobal
            $_REQUEST['step_notes'] = $stepNotes;
        }
    }

    // legacy copyAttFromLEXEC: capture the latest execution id on context
    // (tcversion + plan + platform + build) BEFORE writing, so we can copy
    // its attachments onto the brand-new execution after it is inserted.
    // Mirrors execSetResults.php processTestCase() -> testcase::getLatestExecIDInContext.
    $latestExecIDInContext = -1;
    if ($copyAttFromLEXEC) {
        try {
            $ctx = new stdClass();
            $ctx->testplan_id = $tplanId;
            $ctx->platform_id = $platformId > 0 ? $platformId : 0;
            $ctx->build_id = $buildId;
            $ctxTcaseMgr = new testcase($db);
            $latestExecIDInContext =
                $ctxTcaseMgr->getLatestExecIDInContext($tcversionId, $ctx);
        } catch (Exception $e) {
            $latestExecIDInContext = -1;
        }
    }

    $issueTracker = null;
    write_execution($db, $execSign, $execData, $issueTracker);

    // read back the fresh execution row for the response
    $et = tlObjectWithDB::getDBTables(array('executions'));
    $storedPlatform = $platformId > 0 ? $platformId : 0;
    $fr = $db->get_recordset(
        "SELECT id, execution_ts FROM {$et['executions']}" .
        " WHERE testplan_id = {$tplanId} AND build_id = {$buildId}" .
        " AND platform_id = {$storedPlatform}" .
        " AND tcversion_id = {$tcversionId}" .
        " ORDER BY id DESC");
    $newExecId = (!is_null($fr) && count($fr) > 0)
        ? intval($fr[0]['id']) : 0;
    $newTs = (!is_null($fr) && count($fr) > 0)
        ? strval($fr[0]['execution_ts']) : '';

    // Copy attachments from the latest execution of this context onto the
    // fresh run (execution-level + step-level), the exact legacy flow from
    // execSetResults.php:168-205.
    if ($copyAttFromLEXEC && $newExecId > 0 && $latestExecIDInContext > 0) {
        try {
            $fileRepo = tlAttachmentRepository::create($db);
            $fileRepo->copyAttachments($latestExecIDInContext,
                $newExecId, 'executions');

            // step-level attachments: copy per execution_tcsteps row,
            // matched by step_number exactly like the legacy loop
            $stepsTbl = DB_TABLE_PREFIX . 'execution_tcsteps';
            $tcstepsTbl = DB_TABLE_PREFIX . 'tcsteps';
            $sql = "SELECT step_number, tcstep_id, EXTCS.id AS tcsexe_id" .
                   " FROM {$tcstepsTbl} TCS" .
                   " JOIN {$stepsTbl} EXTCS ON EXTCS.tcstep_id = TCS.id" .
                   " WHERE EXTCS.execution_id = ";
            $from = (array)$db->fetchRowsIntoMap(
                $sql . $latestExecIDInContext, 'step_number');
            $to = (array)$db->fetchRowsIntoMap(
                $sql . $newExecId, 'step_number');
            foreach ($from as $stepNum => $sxelem) {
                if (isset($to[$stepNum])) {
                    $fileRepo->copyAttachments($sxelem['tcsexe_id'],
                        $to[$stepNum]['tcsexe_id'], 'execution_tcsteps');
                }
            }
        } catch (Exception $e) {
            // copying is best-effort; never fail the whole save
        }
    }

    // Upload attachments at EXECUTION level, same sink the legacy
    // write_execution() uses (addAttachmentsToExec, exec.inc.php:206-216,
    // 1036+). The frontend posts this dedicated 'exec_attachments' field
    // (NOT 'uploadedFile') so the legacy write_execution() upload branch
    // never inspects/var_dump-pollutes the response - the BFF owns the
    // execution-level attachment upload here.
    $uploadedCount = 0;
    if ($newExecId > 0
        && isset($_FILES['exec_attachments'])
        && is_array($_FILES['exec_attachments']['name'])) {
        try {
            $fileRepo = isset($fileRepo) ? $fileRepo : tlAttachmentRepository::create($db);
            $names = (array)$_FILES['exec_attachments']['name'];
            foreach ($names as $i => $fname) {
                if ($fname === '') { continue; }
                $fInfo = array(
                    'name' => strval($fname),
                    'type' => strval($_FILES['exec_attachments']['type'][$i] ?? ''),
                    'size' => intval($_FILES['exec_attachments']['size'][$i] ?? 0),
                    'tmp_name' => strval($_FILES['exec_attachments']['tmp_name'][$i] ?? ''),
                    'error' => intval($_FILES['exec_attachments']['error'][$i] ?? 0),
                    'full_path' => strval($_FILES['exec_attachments']['full_path'][$i] ?? ''),
                );
                if ($fInfo['error'] !== UPLOAD_ERR_OK
                    || $fInfo['size'] <= 0 || $fInfo['tmp_name'] === '') {
                    continue;
                }
                $repOpt = array('allow_empty_title' => TRUE);
                $upx = $fileRepo->insertAttachment(
                    $newExecId, DB_TABLE_PREFIX . 'executions', '',
                    $fInfo, $repOpt);
                if ($upx->statusOK) { $uploadedCount++; }
            }
        } catch (Exception $e) {
            // best-effort; not fatal for the execution write
        }
    }

    out([
        'status' => 'ok',
        'saved' => true,
        'execution_id' => $newExecId,
        'execution_ts' => $newTs,
        'recorded_status' => $statusCode,
        'attachments_uploaded' => $uploadedCount,
    ]);
}

// POST ?action=savePartialSteps  (JSON body)
// Legacy parity for the "Save steps partial execution" / resume feature
// (lib/execute/execSetResults.php:333-343 handler + 
//  lib/functions/testcase.class.php:9664 saveStepsPartialExec()).
// Persists ONLY the WORK-IN-PROGRESS step results into execution_tcsteps_wip
// WITHOUT writing an executions row; a later full save (?action=save)
// clears these rows via write_execution() (lib/functions/exec.inc.php:111-118).
if ($action === 'savePartialSteps') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        out(['status' => 'error', 'message' => 'POST required']);
    }
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid JSON body']);
    }
    $tplanId = intval($payload['tplan_id'] ?? 0);
    list($tplanMgr, , $tprojectId, ) = execTestResolveContext($db, $user, $tplanId);

    // WRITE right required (exec_ro_access is NOT enough), same as ?action=save
    if (!$user->hasRight($db, 'testplan_execute', $tprojectId, $tplanId)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'Insufficient rights']);
    }

    $tcversionId = intval($payload['tcversion_id'] ?? 0);
    $buildId = intval($payload['build_id'] ?? 0);
    $platformId = intval($payload['platform_id'] ?? -1); // -1 = plan has none
    $stepsIn = isset($payload['steps']) && is_array($payload['steps'])
        ? $payload['steps'] : [];

    if ($tcversionId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Missing tcversion_id']);
    }

    // build must belong to the plan and be executable (active+open), the
    // same gate the full-save action applies
    $validBuild = false;
    $rawBuilds = $tplanMgr->get_builds($tplanId);
    if (!is_null($rawBuilds)) {
        foreach ($rawBuilds as $bid => $b) {
            if (intval($bid) === $buildId
                && intval($b['active']) === 1 && intval($b['is_open']) === 1) {
                $validBuild = true;
                break;
            }
        }
    }
    if (!$validBuild) {
        http_response_code(400);
        out(['status' => 'error',
             'message' => 'Invalid or non-executable build for this plan']);
    }

    // version must still be linked to the plan (same gate as ?action=save),
    // otherwise a privileged user could write WIP rows for a foreign version
    $lt = tlObjectWithDB::getDBTables(array('testplan_tcversions'));
    $lr = $db->get_recordset(
        "SELECT id FROM {$lt['testplan_tcversions']}" .
        " WHERE testplan_id = {$tplanId} AND tcversion_id = {$tcversionId}");
    if (is_null($lr) || count($lr) == 0) {
        http_response_code(400);
        out(['status' => 'error',
             'message' => 'Version is not linked to this test plan']);
    }

    $resultsCfg = config_get('results');
    $notRun = $resultsCfg['status_code']['not_run'];

    // step-level results: keys are STEP IDS; every submitted id must belong
    // to THIS version (tcsteps are nodes whose parent is the version),
    // otherwise forged ids could touch unrelated partial-execution rows
    $stepNotes = array();
    $stepStatus = array();
    if (count($stepsIn) > 0) {
        $wanted = array_map('intval', array_keys($stepsIn));
        $validStepIds = array();
        if (count($wanted) > 0) {
            $nhTables = tlObjectWithDB::getDBTables(array('nodes_hierarchy'));
            $tcstepsTable = DB_TABLE_PREFIX . 'tcsteps';
            $stRs = $db->get_recordset(
                "SELECT S.id FROM {$tcstepsTable} S" .
                " JOIN {$nhTables['nodes_hierarchy']} NH ON NH.id = S.id" .
                " WHERE NH.parent_id = {$tcversionId}" .
                " AND S.id IN (" . implode(',', $wanted) . ")");
            if (!is_null($stRs)) {
                foreach ($stRs as $sr) { $validStepIds[intval($sr['id'])] = 1; }
            }
        }
        foreach ($stepsIn as $sid => $sv) {
            $sid = intval($sid);
            if ($sid <= 0 || !isset($validStepIds[$sid])) { continue; }
            $sn = isset($sv['notes']) ? strval($sv['notes']) : '';
            $ss = isset($sv['status'])
                ? strtolower(trim(strval($sv['status']))) : $notRun;
            if (!isset($resultsCfg['code_status'][$ss])) { $ss = $notRun; }
            $stepNotes[$sid] = $sn;
            $stepStatus[$sid] = $ss;
        }
    }
    if (count($stepNotes) == 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'No step results to save']);
    }

    // ctx mirrors the legacy saveStepsPartialExec handler; platform is
    // normalized the same way the execution write paths store it
    $ctx = new stdClass();
    $ctx->testplan_id = $tplanId;
    $ctx->platform_id = $platformId > 0 ? $platformId : 0;
    $ctx->build_id = $buildId;
    $ctx->tester_id = $userId;

    $wipTcaseMgr = new testcase($db);
    $wipTcaseMgr->saveStepsPartialExec(
        array('notes' => $stepNotes, 'status' => $stepStatus), $ctx);

    out(['status' => 'ok', 'saved' => true,
         'steps' => count($stepNotes), 'build_id' => $buildId]);
}

// POST ?action=deleteExecution  (JSON body: execution_id)
// Deletes ONE execution, mirroring legacy execSetResults.php do_delete
// flow (exec_Delete right + build must be open). Reuses delete_execution()
// so child data (execution_bugs, cfield_execution_values,
// execution_tcsteps, attachments) is cleaned up exactly as the legacy path.
if ($action === 'deleteExecution') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        out(['status' => 'error', 'message' => 'POST required']);
    }
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid JSON body']);
    }
    $tplanId = intval($payload['tplan_id'] ?? 0);
    list($tplanMgr, , $tprojectId, ) = execTestResolveContext($db, $user, $tplanId);

    $execId = intval($payload['execution_id'] ?? 0);
    if ($execId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Missing execution_id']);
    }
    // legacy gate: testplan_execute (execute right) AND the delete right
    // (execSetResults.php checks grants->execute + grants->delete_execution)
    if (!$user->hasRight($db, 'testplan_execute', $tprojectId, $tplanId)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'Insufficient rights']);
    }
    if (!$user->hasRight($db, 'exec_delete', $tprojectId, $tplanId, true)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'Insufficient rights']);
    }

    // the execution must belong to this plan
    $et = tlObjectWithDB::getDBTables(['executions', 'builds']);
    $er = $db->get_recordset(
        "SELECT E.id, E.testplan_id, E.build_id, E.tcversion_id, B.is_open AS build_is_open" .
        " FROM {$et['executions']} E" .
        " LEFT JOIN {$et['builds']} B ON B.id = E.build_id" .
        " WHERE E.id = {$execId}");
    if (is_null($er) || count($er) == 0) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Execution not found']);
    }
    if (intval($er[0]['testplan_id']) !== $tplanId) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'Execution does not belong to this test plan']);
    }
    // legacy only allows deleting executions belonging to an open build
    if (intval($er[0]['build_is_open']) !== 1) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Cannot delete an execution of a closed build']);
    }

    $ok = delete_execution($db, $execId);
    if (!$ok) {
        http_response_code(500);
        out(['status' => 'error', 'message' => 'Delete failed']);
    }

    // audit trail ONLY on success, like legacy execSetResults.php:347-355
    $buildInfo = $tplanMgr->get_build_by_id($tplanId, intval($er[0]['build_id']));
    $auditMsg = 'Execution ' . $execId . ' deleted (build: '
        . strval($buildInfo['name'] ?? '') . ', plan: ' . $tplanId . ')';
    logAuditEvent($auditMsg, 'DELETE', $execId, 'execution');

    out(['status' => 'ok', 'deleted' => true, 'execution_id' => $execId]);
}

// POST ?action=updateNotes  (JSON body: execution_id, notes)
// Updates ONLY the execution notes, mirroring legacy editExecution.php doUpdate
// (exec_edit_notes right + build open). Uses updateExecutionNotes().
if ($action === 'updateNotes') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        out(['status' => 'error', 'message' => 'POST required']);
    }
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid JSON body']);
    }
    $tplanId = intval($payload['tplan_id'] ?? 0);
    list($tplanMgr, , $tprojectId, ) = execTestResolveContext($db, $user, $tplanId);

    $execId = intval($payload['execution_id'] ?? 0);
    if ($execId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Missing execution_id']);
    }
    // legacy gate (editExecution.php checkRights): testplan_execute AND
    // exec_edit_notes; also the inline table only offers edit on open builds
    if (!$user->hasRight($db, 'testplan_execute', $tprojectId, $tplanId)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'Insufficient rights']);
    }
    if (!$user->hasRight($db, 'exec_edit_notes', $tprojectId, $tplanId)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'Insufficient rights']);
    }

    $et = tlObjectWithDB::getDBTables(['executions', 'builds']);
    $er = $db->get_recordset(
        "SELECT E.id, E.testplan_id, E.build_id, B.is_open AS build_is_open" .
        " FROM {$et['executions']} E" .
        " LEFT JOIN {$et['builds']} B ON B.id = E.build_id" .
        " WHERE E.id = {$execId}");
    if (is_null($er) || count($er) == 0) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Execution not found']);
    }
    if (intval($er[0]['testplan_id']) !== $tplanId) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'Execution does not belong to this test plan']);
    }
    if (intval($er[0]['build_is_open']) !== 1) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Cannot edit notes of a closed build']);
    }

    $notes = strval($payload['notes'] ?? '');
    $ok = updateExecutionNotes($db, $execId, $notes) === tl::OK;
    if (!$ok) {
        http_response_code(500);
        out(['status' => 'error', 'message' => 'Update failed']);
    }
    out(['status' => 'ok', 'updated' => true, 'execution_id' => $execId]);
}

// Shared helpers for the "Link Bug to Execution" feature (legacy
// lib/execute/bugAdd.php ?user_action=link + write_execution_bug()).
// Resolve the execution that is the target of a bug link and enforce the
// same gate the legacy screen used (testplan_execute right).
function execBugLinkContext(&$db, &$user, $payload) {
    $tplanId = intval($payload['tplan_id'] ?? 0);
    list(, , $tprojectId, ) = execTestResolveContext($db, $user, $tplanId);
    if (!$user->hasRight($db, 'testplan_execute', $tprojectId, $tplanId)) {
        return null;
    }
    $execId = intval($payload['execution_id'] ?? 0);
    if ($execId <= 0) {
        return null;
    }
    // the execution must belong to the given test plan
    $tables = tlObjectWithDB::getDBTables(array('executions', 'builds'));
    $er = $db->get_recordset(
        "SELECT E.id, E.testplan_id, E.build_id, B.is_open AS build_is_open" .
        " FROM {$tables['executions']} E" .
        " LEFT JOIN {$tables['builds']} B ON B.id = E.build_id" .
        " WHERE E.id = {$execId}");
    if (is_null($er) || count($er) == 0) {
        return null;
    }
    if (intval($er[0]['testplan_id']) !== $tplanId) {
        return null;
    }
    return array('tplanId' => $tplanId, 'tprojectId' => $tprojectId,
                 'execId' => $execId, 'buildIsOpen' => intval($er[0]['build_is_open']) === 1);
}

// POST ?action=linkBug  (JSON body)
// Link an issue (bug) to an execution of the current test plan. Mirrors the
// legacy ?user_action=link flow of lib/execute/bugAdd.php: validate + normalize
// the bug id against the configured issue tracker, then write the linkage into
// execution_bugs via write_execution_bug(). tcstep_id is optional; when given
// the bug is bound to that step (legacy step-level linkage).
if ($action === 'linkBug') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        out(['status' => 'error', 'message' => 'POST required']);
    }
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid JSON body']);
    }
    try {
        $ctx = execBugLinkContext($db, $user, $payload);
        if (is_null($ctx)) {
            http_response_code(403);
            out(['status' => 'error', 'message' => 'Insufficient rights or invalid execution']);
        }
        if (!$ctx['buildIsOpen']) {
            http_response_code(400);
            out(['status' => 'error', 'message' => 'Cannot link a bug to an execution of a closed build']);
        }
        $bugId = trim(strval($payload['bug_id'] ?? ''));
        $tcstepId = intval($payload['tcstep_id'] ?? 0);
        if ($bugId === '') {
            http_response_code(400);
            out(['status' => 'error', 'message' => 'Missing bug id']);
        }

        // resolve through the configured issue tracker, same as legacy bugAdd.php
        $tprojectMgr = new testproject($db);
        $info = $tprojectMgr->get_by_id($ctx['tprojectId']);
        $useITS = !empty($info['issue_tracker_enabled'])
            && config_get('exec_cfg')->features->issue_tracker->enabled;

        if ($useITS) {
            $itMgr = new tlIssueTracker($db);
            $its = $itMgr->getInterfaceObject($ctx['tprojectId']);
            if (is_null($its) || !method_exists($its, 'checkBugIDSyntax')) {
                http_response_code(500);
                out(['status' => 'error', 'message' => 'Issue tracker not available']);
            }
            if (!$its->checkBugIDSyntax($bugId)) {
                http_response_code(400);
                out(['status' => 'error',
                     'message' => 'wrong bug ID format (' . $bugId . ')']);
            }
            $bugID = method_exists($its, 'normalizeBugID')
                ? $its->normalizeBugID($bugId)
                : $bugId;
            if (method_exists($its, 'checkBugIDExistence')
                && !$its->checkBugIDExistence($bugID)) {
                http_response_code(400);
                out(['status' => 'error',
                     'message' => 'bug ' . $bugID . ' does not exist on the bug tracker']);
            }
            $bugId = $bugID;
        }

        $ok = write_execution_bug($db, $ctx['execId'], $bugId, $tcstepId);
        if (!$ok) {
            http_response_code(500);
            out(['status' => 'error', 'message' => 'Link failed']);
        }
        logAuditEvent(
            'Bug ' . $bugId . ' linked to execution ' . $ctx['execId'] .
            ($tcstepId > 0 ? ' (step ' . $tcstepId . ')' : ''),
            'CREATE', $ctx['execId'], 'executions');
        out(['status' => 'ok', 'linked' => true, 'execution_id' => $ctx['execId'],
             'tcstep_id' => $tcstepId, 'bug_id' => $bugId]);
    } catch (\Throwable $e) {
        http_response_code(500);
        out(['status' => 'error', 'message' => $e->getMessage()]);
    }
}

// POST ?action=createBug  (JSON body)
// Create a brand new issue on the configured issue tracker (GitHub) from
// TestLink and link it to the execution. Mirrors the legacy
// ?user_action=doCreate flow of lib/execute/bugAdd.php: build a title
// (summary) + description, call its->addIssue() which returns the new issue
// id, then write_execution_bug() to bind it to the execution (or a tcstep).
// Requires tlCanCreateIssue (the tracker adapter exposing addIssue()).
if ($action === 'createBug') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        out(['status' => 'error', 'message' => 'POST required']);
    }
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid JSON body']);
    }
    try {
        $ctx = execBugLinkContext($db, $user, $payload);
        if (is_null($ctx)) {
            http_response_code(403);
            out(['status' => 'error', 'message' => 'Insufficient rights or invalid execution']);
        }
        if (!$ctx['buildIsOpen']) {
            http_response_code(400);
            out(['status' => 'error', 'message' => 'Cannot create a bug for an execution of a closed build']);
        }
        $summary = trim(strval($payload['bug_summary'] ?? ''));
        $tcstepId = intval($payload['tcstep_id'] ?? 0);
        if ($summary === '') {
            http_response_code(400);
            out(['status' => 'error', 'message' => 'Missing bug summary']);
        }

        $tprojectMgr = new testproject($db);
        $info = $tprojectMgr->get_by_id($ctx['tprojectId']);
        $useITS = !empty($info['issue_tracker_enabled'])
            && config_get('exec_cfg')->features->issue_tracker->enabled;
        if (!$useITS) {
            http_response_code(400);
            out(['status' => 'error', 'message' => 'Issue tracker is disabled']);
        }
        $itMgr = new tlIssueTracker($db);
        $its = $itMgr->getInterfaceObject($ctx['tprojectId']);
        if (is_null($its) || !method_exists($its, 'addIssue')) {
            http_response_code(500);
            out(['status' => 'error', 'message' => 'Issue tracker cannot create issues']);
        }

        $description = trim(strval($payload['bug_description'] ?? ''));
        if ($description === '') {
            $description = 'Created from TestLink execution ' . $ctx['execId'];
        }

        $rs = $its->addIssue($summary, $description);
        if (!is_array($rs) || empty($rs['status_ok']) || intval($rs['id']) <= 0) {
            http_response_code(500);
            out(['status' => 'error',
                 'message' => (is_array($rs) && !empty($rs['msg'])) ? $rs['msg'] : 'Bug creation failed']);
        }
        $newId = strval($rs['id']);

        $ok = write_execution_bug($db, $ctx['execId'], $newId, $tcstepId);
        if (!$ok) {
            http_response_code(500);
            out(['status' => 'error', 'message' => 'Link failed after creating the bug']);
        }
        logAuditEvent(
            'Bug ' . $newId . ' created and linked to execution ' . $ctx['execId'] .
            ($tcstepId > 0 ? ' (step ' . $tcstepId . ')' : ''),
            'CREATE', $ctx['execId'], 'executions');
        out(['status' => 'ok', 'created' => true, 'linked' => true,
             'execution_id' => $ctx['execId'], 'tcstep_id' => $tcstepId,
             'bug_id' => $newId]);
    } catch (\Throwable $e) {
        http_response_code(500);
        out(['status' => 'error', 'message' => $e->getMessage()]);
    }
}

// POST ?action=unlinkBug  (JSON body)
// Remove a bug link from an execution (legacy bugDelete.php).
if ($action === 'unlinkBug') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        out(['status' => 'error', 'message' => 'POST required']);
    }
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid JSON body']);
    }
    $ctx = execBugLinkContext($db, $user, $payload);
    if (is_null($ctx)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'Insufficient rights or invalid execution']);
    }
    $bugId = trim(strval($payload['bug_id'] ?? ''));
    $tcstepId = intval($payload['tcstep_id'] ?? 0);
    if ($bugId === '') {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Missing bug id']);
    }
    $ok = write_execution_bug($db, $ctx['execId'], $bugId, $tcstepId, true);
    out(['status' => 'ok', 'unlinked' => true, 'execution_id' => $ctx['execId'],
         'tcstep_id' => $tcstepId, 'bug_id' => $bugId]);
}

http_response_code(400);
out(['status' => 'error', 'message' => 'Bad request']);