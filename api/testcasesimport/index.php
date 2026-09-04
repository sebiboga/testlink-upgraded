<?php
/**
 * Test Case Import BFF API
 * URL: /api/testcasesimport/?action=import_md   (POST) — Markdown import
 *      /api/testcasesimport/?action=import_xml  (POST) — legacy XML import
 *
 * Markdown import uses the structured Markdown produced by the CI agents
 * (reference format: tmp/TLU_Test_Cases.md) via the markdownTcImport parser.
 *
 * XML import (action=import_xml) mirrors lib/testcases/tcImport.php — it
 * uploads an XML file and imports test cases / suites into the target project.
 *
 * Legacy parity (lib/testcases/tcImport.php):
 *  - permission: mgt_modify_tc on the target project
 *  - size limit: config import_file_max_size_bytes
 *  - duplicate hitCriteria: 'name' | 'internalID' | 'externalID'
 *  - actionOnHit: 'skip' | 'update_last_version' | 'generate_new' | 'create_new_version'
 *  - useRecursion / intoProject flags for suite import
 *
 * Self-contained: does not depend on api/testcases/index.php.
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
require_once(__DIR__ . '/../../lib/functions/markdown_tc_import.class.php');

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
 * Collect every testsuite node of a project (any depth) via BFS over
 * nodes_hierarchy (tree_manager helpers proved unreliable in this code base).
 */
function getProjectSuites($dbHandler, $tprojectId) {
    $tables = tlObjectWithDB::getDBTables(array('nodes_hierarchy', 'node_types'));
    $typeRows = $dbHandler->get_recordset(
        "SELECT id, description FROM {$tables['node_types']} " .
        "WHERE description IN ('testproject','testsuite','testcase','testcase_version')");
    if (is_null($typeRows)) {
        return ['byName' => [], 'types' => []];
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
                $key = strtolower(trim(strval($child['name'])));
                if ($key !== '' && !isset($byName[$key])) {
                    $byName[$key] = $cid;
                }
                $queue[] = $cid;
            }
        }
    }
    return ['byName' => $byName, 'types' => $types];
}

$action = $_GET['action'] ?? '';

$tcaseMgr = new testcase($db);
$tprojectMgr = new testproject($db);

// ---------------------------------------------------------------------------
// GET ?action=info[&tproject_id=N][&containerID=N]
// Context for the modernized import screen: names, size limit, grant.
// ---------------------------------------------------------------------------
if ($action === 'info') {
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

    $containerId = getIntParam('containerID');
    if ($containerId <= 0) {
        $containerId = $tprojectId;
    }
    $tables = tlObjectWithDB::getDBTables(array('nodes_hierarchy'));
    $cRow = $db->get_recordset(
        "SELECT id, parent_id, name FROM {$tables['nodes_hierarchy']} " .
        "WHERE id = " . intval($containerId) .
        " AND parent_id IN (0, " . intval($tprojectId) . ")");
    $container = ['id' => $tprojectId, 'name' => strval($info['name']),
                  'isProject' => true];
    if (!is_null($cRow) && count($cRow) === 1) {
        $isProj = (intval($cRow[0]['parent_id']) === 0)
            || ($containerId === $tprojectId);
        $container = ['id' => intval($cRow[0]['id']),
                      'name' => strval($cRow[0]['name']),
                      'isProject' => $isProj];
    }

    out([
        'status' => 'ok',
        'tproject' => ['id' => $tprojectId, 'name' => strval($info['name'])],
        'container' => $container,
        'maxUploadBytes' => intval(config_get('import_file_max_size_bytes')),
        'grants' => ['mgt_modify_tc'
            => $user->hasRight($db, 'mgt_modify_tc', $tprojectId) ? 1 : 0],
    ]);
}

// ---------------------------------------------------------------------------
// POST ?action=import_md&tproject_id=N[&dry_run=1][&hit_criteria=name]
//      [&action_on_hit=skip|create_new_version]
// Body: markdown=<text> form field OR multipart file field "uploadedFile"
// ---------------------------------------------------------------------------
if ($action === 'import_md') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        out(['status' => 'error', 'message' => 'Use POST']);
    }

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
    if (!$user->hasRight($db, 'mgt_modify_tc', $tprojectId)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }

    // ---- input ------------------------------------------------------------
    // legacy parity: import_file_max_size_bytes cap
    $maxBytes = intval(config_get('import_file_max_size_bytes'));
    if ($maxBytes <= 0) {
        $maxBytes = 10 * 1024 * 1024; // 10 MB fallback when config unset
    }

    $markdown = '';
    $uploadedBytes = 0;
    if (isset($_FILES['uploadedFile']) && $_FILES['uploadedFile']['error'] === UPLOAD_ERR_OK) {
        $uploadedBytes = intval($_FILES['uploadedFile']['size']);
        if ($maxBytes > 0 && $uploadedBytes > $maxBytes) {
            http_response_code(413);
            out(['status' => 'error',
                 'message' => sprintf('File too large (%d bytes); limit is %d bytes',
                                      $uploadedBytes, $maxBytes)]);
        }
        $markdown = file_get_contents($_FILES['uploadedFile']['tmp_name']);
    } elseif (isset($_POST['markdown'])) {
        $markdown = strval($_POST['markdown']);
        $uploadedBytes = strlen($markdown);
        if ($maxBytes > 0 && $uploadedBytes > $maxBytes) {
            http_response_code(413);
            out(['status' => 'error',
                 'message' => sprintf('File too large (%d bytes); limit is %d bytes',
                                      $uploadedBytes, $maxBytes)]);
        }
    }
    if (trim($markdown) === '') {
        http_response_code(400);
        out(['status' => 'error',
             'message' => 'No markdown content posted (use "markdown" field or "uploadedFile" upload)']);
    }

    // legacy parity options
    $hitCriteria = strtolower(trim(strval($_POST['hit_criteria'] ?? 'name')));
    if (!in_array($hitCriteria, ['name', 'internalid', 'externalid'], true)) {
        http_response_code(400);
        out(['status' => 'error',
             'message' => 'Invalid hit_criteria']);
    }
    // canonically re-case so downstream (and the legacy XML switch) match
    $hitCriteria = ($hitCriteria === 'internalid') ? 'internalID'
                  : (($hitCriteria === 'externalid') ? 'externalID' : 'name');
    $actionOnHit = strtolower(trim(strval($_POST['action_on_hit'] ?? 'skip')));
    if (!in_array($actionOnHit, ['skip', 'update_last_version', 'generate_new', 'create_new_version'], true)) {
        http_response_code(400);
        out(['status' => 'error',
             'message' => 'Invalid action_on_hit']);
    }

    $dryRun = intval($_POST['dry_run'] ?? 0) === 1;

    // ---- parse --------------------------------------------------------------
    $parser = new markdownTcImport();
    $parsed = $parser->parse($markdown);
    if ($parsed['caseCount'] === 0 && count($parsed['suites']) === 0) {
        http_response_code(422);
        out(['status' => 'error',
             'message' => 'No suites or test cases recognized in markdown',
             'parserErrors' => $parser->errors]);
    }

    // report a project-name clash; caller decides precedence
    $mdProject = trim(strval($parsed['meta']['project'] ?? ''));
    $projectMismatch = ($mdProject !== '' && $mdProject !== strval($info['name']))
        ? ['file' => $mdProject, 'target' => strval($info['name'])] : null;

    // ---- index existing content ---------------------------------------------
    $suitesIndex = getProjectSuites($db, $tprojectId);
    $tsuiteMgr = new testsuite($db);

    $tcTypeId = intval($suitesIndex['types']['testcase'] ?? -1);
    $tcVersionTypeId = intval($suitesIndex['types']['testcase_version'] ?? 4);
    $tables = tlObjectWithDB::getDBTables(array('nodes_hierarchy', 'tcversions'));
    $existingCasesBySuite = [];
    $existingCasesByNode = [];
    $existingCasesByExternal = [];
    $rows = $db->get_recordset(
        "SELECT NH.parent_id AS suite_id, NH.id AS tcase_id, NH.name, TCV.tc_external_id " .
        "FROM {$tables['nodes_hierarchy']} NH " .
        "LEFT JOIN {$tables['nodes_hierarchy']} NHV " .
        "        ON NHV.parent_id = NH.id AND NHV.node_type_id = {$tcVersionTypeId} " .
        "LEFT JOIN {$tables['tcversions']} TCV ON TCV.id = NHV.id " .
        "WHERE NH.node_type_id = {$tcTypeId}");
    if (!is_null($rows)) {
        foreach ($rows as $r) {
            $suiteId = intval($r['suite_id']);
            $tcId = intval($r['tcase_id']);
            $existingCasesBySuite[$suiteId][strtolower(trim(strval($r['name'])))] = $tcId;
            $existingCasesByNode[$suiteId][$tcId] = $tcId;
            $ext = intval($r['tc_external_id'] ?? 0);
            if ($ext > 0 && !isset($existingCasesByExternal[$suiteId][$ext])) {
                $existingCasesByExternal[$suiteId][$ext] = $tcId;
            }
        }
    }

    $created = []; $skipped = []; $newVersions = []; $failed = [];
    $suitesCreated = []; $suitesMatched = [];

    foreach ($parsed['suites'] as $suite) {
        $suiteName = trim(strval($suite['name']));
        if ($suiteName === '' || count($suite['cases']) === 0) {
            continue;
        }
        $key = strtolower($suiteName);
        if (isset($suitesIndex['byName'][$key])) {
            $suiteId = intval($suitesIndex['byName'][$key]);
            $suitesMatched[] = $suiteName;
        } else {
            if ($dryRun) {
                $suiteId = 0;
                $suitesCreated[] = $suiteName;
            } else {
                $ret = $tsuiteMgr->create(intval($tprojectId), $suiteName,
                    'Imported from markdown', null,
                    testsuite::CHECK_DUPLICATE_NAME, 'block');
                if (!$ret || !isset($ret['id']) || intval($ret['id']) <= 0) {
                    foreach ($suite['cases'] as $c) {
                        $failed[] = ['tcId' => strval($c['tcId']), 'title' => $c['title'],
                                     'error' => 'Cannot create test suite: ' . $suiteName];
                    }
                    continue;
                }
                $suiteId = intval($ret['id']);
                $suitesIndex['byName'][$key] = $suiteId;
                $suitesCreated[] = $suiteName;
            }
        }

        foreach ($suite['cases'] as $c) {
            $title = trim(strval($c['title']));
            $nameKey = strtolower($title);
            $steps = array_map(function($s) {
                return ['step_number' => $s['step_number'],
                        'actions' => $s['actions'],
                        'expected_results' => $s['expected_results'],
                        'execution_type' => TESTCASE_EXECUTION_TYPE_MANUAL];
            }, is_array($c['steps']) ? $c['steps'] : []);
            $importance = intval($c['importance']) ?: 2;

            // Select the duplicate index + match key per hit_criteria (legacy
            // parity with lib/testcases/tcImport.php:280-296).
            // tcId from markdown is "TC-<N>" — extract numeric part for ID matching.
            $tcNumericId = 0;
            if (preg_match('/(\d+)\s*$/', strval($c['tcId'] ?? ''), $idMatch)) {
                $tcNumericId = intval($idMatch[1]);
            }
            $existingTcId = 0;
            if ($hitCriteria === 'externalID') {
                if ($tcNumericId > 0 && isset($existingCasesByExternal[$suiteId][$tcNumericId])) {
                    $existingTcId = $existingCasesByExternal[$suiteId][$tcNumericId];
                }
            } elseif ($hitCriteria === 'internalID') {
                if ($tcNumericId > 0 && isset($existingCasesByNode[$suiteId][$tcNumericId])) {
                    $existingTcId = $existingCasesByNode[$suiteId][$tcNumericId];
                }
            } elseif (isset($existingCasesBySuite[$suiteId][$nameKey])) {
                $existingTcId = $existingCasesBySuite[$suiteId][$nameKey];
            }

            if ($actionOnHit !== 'generate_new' && $existingTcId > 0) {
                if ($actionOnHit === 'skip') {
                    $skipped[] = ['tcId' => strval($c['tcId']), 'title' => $title,
                                  'reason' => 'duplicate in target suite'];
                    continue;
                }
                if ($actionOnHit === 'update_last_version') {
                    // Legacy parity (lib/testcases/tcImport.php:384-427):
                    // update the LATEST version in place — no new version.
                    if ($dryRun) {
                        $newVersions[] = ['tcId' => strval($c['tcId']), 'title' => $title,
                                          'suite' => $suiteName, 'steps' => count($steps)];
                        continue;
                    }
                    try {
                        $existingTcId = intval($existingTcId);
                        $last = $tcaseMgr->get_last_version_info($existingTcId,
                                                                 ['output' => 'minimun']);
                        $lastVersionId = is_array($last) ? intval($last['id'] ?? 0) : 0;
                        if ($lastVersionId <= 0) {
                            $failed[] = ['tcId' => strval($c['tcId']), 'title' => $title,
                                         'error' => 'Cannot resolve latest version of matched test case'];
                            continue;
                        }
                        $ur = $tcaseMgr->update($existingTcId, $lastVersionId, $title, '',
                            strval($c['preconditions']), $steps, intval($userId), '',
                            testcase::DEFAULT_ORDER, TESTCASE_EXECUTION_TYPE_MANUAL,
                            $importance);
                        $okU = is_array($ur)
                            ? (isset($ur['status_ok']) ? intval($ur['status_ok']) : 1)
                            : (isset($ur->status_ok) ? intval($ur->status_ok) : 0);
                        if ($okU) {
                            $newVersions[] = ['tcId' => strval($c['tcId']),
                                              'title' => $title, 'suite' => $suiteName,
                                              'tcase_id' => $existingTcId,
                                              'tcversion_id' => $lastVersionId];
                        } else {
                            $msg = is_array($ur) ? strval($ur['msg'] ?? 'update failed')
                                                 : strval($ur->msg ?? 'update failed');
                            $failed[] = ['tcId' => strval($c['tcId']), 'title' => $title,
                                         'error' => $msg];
                        }
                    } catch (Exception $e) {
                        $failed[] = ['tcId' => strval($c['tcId']), 'title' => $title,
                                     'error' => 'Update failed (internal error)'];
                    }
                    continue;
                }
                // create_new_version: bump to a fresh version, then update it
                if ($dryRun) {
                    $newVersions[] = ['tcId' => strval($c['tcId']), 'title' => $title,
                                      'suite' => $suiteName, 'steps' => count($steps)];
                    continue;
                }
                try {
                    $existingTcId = intval($existingTcId);
                    $vr = $tcaseMgr->create_new_version($existingTcId, intval($userId));
                    $okV = is_array($vr)
                        ? (isset($vr['status_ok']) ? intval($vr['status_ok']) : 1)
                        : (isset($vr->status_ok) ? intval($vr->status_ok) : 0);
                    $newVersionId = is_array($vr)
                        ? intval($vr['id'] ?? 0)
                        : intval($vr->id ?? 0);
                    if (!$okV || $newVersionId <= 0) {
                        $msg = is_array($vr) ? strval($vr['msg'] ?? 'version create failed')
                                             : strval($vr->msg ?? 'version create failed');
                        $failed[] = ['tcId' => strval($c['tcId']), 'title' => $title,
                                     'error' => $msg];
                        continue;
                    }
                    $ur = $tcaseMgr->update($existingTcId, $newVersionId, $title, '',
                        strval($c['preconditions']), $steps, intval($userId), '',
                        testcase::DEFAULT_ORDER, TESTCASE_EXECUTION_TYPE_MANUAL,
                        $importance);
                    $okU = is_array($ur)
                        ? (isset($ur['status_ok']) ? intval($ur['status_ok']) : 1)
                        : (isset($ur->status_ok) ? intval($ur->status_ok) : 0);
                    if ($okU) {
                        $newVersions[] = ['tcId' => strval($c['tcId']), 'title' => $title,
                                          'suite' => $suiteName, 'tcase_id' => $existingTcId,
                                          'tcversion_id' => $newVersionId];
                    } else {
                        $msg = is_array($ur) ? strval($ur['msg'] ?? 'update failed')
                                             : strval($ur->msg ?? 'update failed');
                        $failed[] = ['tcId' => strval($c['tcId']), 'title' => $title,
                                     'error' => $msg];
                    }
                } catch (Exception $e) {
                    $failed[] = ['tcId' => strval($c['tcId']), 'title' => $title,
                                 'error' => 'Update failed (internal error)'];
                }
                continue;
            }

            if ($dryRun) {
                $created[] = ['tcId' => strval($c['tcId']), 'title' => $title,
                              'suite' => $suiteName, 'importance' => $importance,
                              'steps' => count($steps)];
                $existingCasesBySuite[$suiteId][$nameKey] = -1; // pretend inserted
                continue;
            }
            try {
                $ret = $tcaseMgr->create($suiteId, $title, '',
                    strval($c['preconditions']), $steps, intval($userId), '',
                    testcase::DEFAULT_ORDER, testcase::AUTOMATIC_ID,
                    TESTCASE_EXECUTION_TYPE_MANUAL, $importance);
                $ok = is_array($ret)
                    ? (isset($ret['status_ok']) ? intval($ret['status_ok']) : 1)
                    : (isset($ret->status_ok) ? intval($ret->status_ok) : 0);
                if ($ok) {
                    $newId = is_array($ret) ? intval($ret['id']) : intval($ret->id);
                    $created[] = ['tcId' => strval($c['tcId']), 'title' => $title,
                                  'suite' => $suiteName, 'id' => $newId];
                    $existingCasesBySuite[$suiteId][$nameKey] = $newId;
                } else {
                    $msg = is_array($ret) ? strval($ret['msg'] ?? 'create failed')
                                          : strval($ret->msg ?? 'create failed');
                    $failed[] = ['tcId' => strval($c['tcId']), 'title' => $title,
                                 'error' => $msg];
                }
            } catch (Exception $e) {
                $failed[] = ['tcId' => strval($c['tcId']), 'title' => $title,
                             'error' => 'Create failed (internal error)'];
            }
        }
    }

    out([
        'status' => 'ok',
        'dry_run' => $dryRun,
        'tproject' => ['id' => $tprojectId, 'name' => strval($info['name'])],
        'projectMismatch' => $projectMismatch,
        'meta' => $parsed['meta'],
        'options' => ['hit_criteria' => $hitCriteria, 'action_on_hit' => $actionOnHit],
        'suitesCreated' => $suitesCreated,
        'suitesMatched' => $suitesMatched,
        'createdCount' => count($created),
        'skippedCount' => count($skipped),
        'newVersionCount' => count($newVersions),
        'failedCount' => count($failed),
        'created' => $created,
        'skipped' => $skipped,
        'newVersions' => $newVersions,
        'failed' => $failed,
        'parserErrors' => $parser->errors,
    ]);
}

// ---------------------------------------------------------------------------
// POST ?action=import_xml&tproject_id=N&containerID=N
//      [&hit_criteria=name|internalID|externalID]
//      [&action_on_hit=skip|update_last_version|generate_new|create_new_version]
//      [&useRecursion=1][&intoProject=1]
// Body: multipart file field "uploadedFile"
// ---------------------------------------------------------------------------
if ($action === 'import_xml') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        out(['status' => 'error', 'message' => 'Use POST']);
    }

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
    if (!$user->hasRight($db, 'mgt_modify_tc', $tprojectId)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }

    $containerId = getIntParam('containerID');
    if ($containerId <= 0) {
        $containerId = $tprojectId;
    }

    // ---- input validation --------------------------------------------------
    if (!isset($_FILES['uploadedFile']) || $_FILES['uploadedFile']['error'] !== UPLOAD_ERR_OK) {
        $errCode = isset($_FILES['uploadedFile']) ? $_FILES['uploadedFile']['error'] : -1;
        http_response_code(400);
        out(['status' => 'error', 'message' => 'File upload failed (error code: ' . $errCode . ')']);
    }

    $maxBytes = intval(config_get('import_file_max_size_bytes'));
    $uploadedBytes = intval($_FILES['uploadedFile']['size']);
    if ($maxBytes > 0 && $uploadedBytes > $maxBytes) {
        http_response_code(413);
        out(['status' => 'error',
             'message' => sprintf('File too large (%d bytes); limit is %d bytes',
                                  $uploadedBytes, $maxBytes)]);
    }

    $useRecursion = getIntParam('useRecursion') === 1;
    $intoProject = getIntParam('intoProject') === 1;

    $hitCriteria = strtolower(trim(strval($_REQUEST['hit_criteria'] ?? 'name')));
    if (!in_array($hitCriteria, ['name', 'internalid', 'externalid'], true)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid hit_criteria']);
    }
    // canonically re-case so the legacy XML switch matches
    $hitCriteria = ($hitCriteria === 'internalid') ? 'internalID'
                  : (($hitCriteria === 'externalid') ? 'externalID' : 'name');
    $actionOnHit = strtolower(trim(strval($_REQUEST['action_on_hit'] ?? 'skip')));
    if (!in_array($actionOnHit, ['skip', 'update_last_version', 'generate_new', 'create_new_version'], true)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid action_on_hit']);
    }

    // ---- save uploaded file to temp -----------------------------------------
    $tmpDir = config_get('repositoryPath');
    if (!$tmpDir || !is_dir($tmpDir)) {
        $tmpDir = sys_get_temp_dir();
    }
    $dest = tempnam($tmpDir, 'xmlimport_');
    if (!move_uploaded_file($_FILES['uploadedFile']['tmp_name'], $dest)) {
        http_response_code(500);
        out(['status' => 'error', 'message' => 'Failed to save uploaded file']);
    }

    // ---- validate XML ------------------------------------------------------
    require_once(__DIR__ . '/../../lib/functions/xml.inc.php');
    require_once(__DIR__ . '/../../lib/functions/csv.inc.php');

    // simplexml_load_file_wrapper() dies with raw HTML for malformed XML, which
    // would break the JSON contract. Validate with a safe parser first so we can
    // return a proper JSON error.
    libxml_use_internal_errors(true);
    $rawXml = @file_get_contents($dest);
    $xml = @simplexml_load_string($rawXml, 'SimpleXMLElement', LIBXML_NONET);
    libxml_clear_errors();
    if ($xml === FALSE || $rawXml === false) {
        @unlink($dest);
        http_response_code(422);
        out(['status' => 'error', 'message' => 'Invalid XML file']);
    }

    $elementName = $xml->getName();
    if ($useRecursion && $elementName !== 'testsuite') {
        @unlink($dest);
        http_response_code(422);
        out(['status' => 'error',
             'message' => 'XML root element must be <testsuite> for recursive import']);
    }
    if (!$useRecursion && $elementName !== 'testcases' && $elementName !== 'testcase') {
        @unlink($dest);
        http_response_code(422);
        out(['status' => 'error',
             'message' => 'XML root element must be <testcases> or <testcase> for flat import']);
    }

    // ---- load import functions from legacy tcImport.php ---------------------
    // The legacy script mixes page logic (config require, Smarty display, initArgs)
    // with the import helpers. Executing the whole script from the BFF is unsafe,
    // so we extract only the function definitions (which start at the first
    // top-level `function` keyword) and eval them. Helper globals/classes the
    // functions rely on (common.php, xml.inc.php, csv.inc.php) are already loaded
    // by this BFF and by the include below.
    if (!function_exists('importTestCaseDataFromXML')) {
        $tcImportPath = __DIR__ . '/../../lib/testcases/tcImport.php';
        $src = @file_get_contents($tcImportPath);
        if ($src === false) {
            http_response_code(500);
            out(['status' => 'error', 'message' => 'Legacy import library not found']);
        }
        // find first top-level `function `importTestCaseDataFromXML`` and eval
        // everything that follows (it is pure function/class definitions).
        $pos = strpos($src, 'function importTestCaseDataFromXML(');
        if ($pos === false) {
            http_response_code(500);
            out(['status' => 'error', 'message' => 'Legacy import functions not found']);
        }
        $code = substr($src, $pos);
        try {
            eval($code);
        } catch (\Throwable $e) {
            http_response_code(500);
            out(['status' => 'error', 'message' => 'Failed to load import helpers']);
        }
        if (!function_exists('importTestCaseDataFromXML')) {
            http_response_code(500);
            out(['status' => 'error', 'message' => 'Legacy import helpers unavailable']);
        }
    }

    // ---- run import ---------------------------------------------------------
    // Defense-in-depth: the legacy helper built into simplexml_load_file_wrapper
    // (lib/functions/xml.inc.php) calls die() + echoes raw HTML on a parse failure.
    // The BFF pre-validates the file as well-formed above, so this path should not
    // be reached, but guarantee the JSON contract even if it ever fires.
    $cleanExit = false;
    register_shutdown_function(function () use (&$cleanExit) {
        if ($cleanExit) {
            return;
        }
        while (ob_get_level()) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode(['status' => 'error', 'message' => 'Import failed']);
    });

    $opt = [
        'useRecursion' => $useRecursion,
        'importIntoProject' => $intoProject,
        'duplicateLogic' => [
            'hitCriteria' => $hitCriteria,
            'actionOnHit' => $actionOnHit,
        ],
    ];

    $resultMap = null;
    try {
        $resultMap = importTestCaseDataFromXML(
            $db, $dest, $containerId, $tprojectId, $userId, $opt
        );
    } catch (\Throwable $e) {
        $cleanExit = true;
        @unlink($dest);
        http_response_code(500);
        out(['status' => 'error',
             'message' => 'Import failed: ' . $e->getMessage()]);
    }

    @unlink($dest);
    $cleanExit = true;

    // ---- normalise resultMap to JSON-friendly structure ---------------------
    // The legacy saveImportedTCData returns a flat list of [title, message]
    // pairs; normalise into structured rows for the frontend.
    $normalized = [
        'status' => 'ok',
        'tproject' => ['id' => $tprojectId, 'name' => strval($info['name'])],
        'useRecursion' => $useRecursion,
        'intoProject' => $intoProject,
        'options' => ['hit_criteria' => $hitCriteria, 'action_on_hit' => $actionOnHit],
        'result' => [],
    ];

    if (is_array($resultMap)) {
        foreach ($resultMap as $row) {
            if (!is_array($row) || count($row) < 2) {
                continue;
            }
            list($name, $message) = $row;
            $normalized['result'][] = [
                'name' => strval($name),
                'message' => strval($message),
            ];
        }
    } elseif ($resultMap === null && !$useRecursion && $containerId > $tprojectId) {
        // No test cases found / empty result — keep result empty.
    }

    out($normalized);
}

http_response_code(400);
out(['status' => 'error', 'message' => 'Bad request']);
