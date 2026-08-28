<?php
/**
 * Test Case Markdown Import BFF API
 * URL: /api/testcasesimport/?action=import_md  (POST)
 *
 * Imports test cases from the structured Markdown produced by the CI agents
 * (reference format: tmp/TLU_Test_Cases.md) using the markdownTcImport parser.
 *
 * Legacy parity (lib/testcases/tcImport.php):
 *  - permission: mgt_modify_tc on the target project
 *  - size limit: config import_file_max_size_bytes
 *  - duplicate hitCriteria: 'name' (title within target suite)
 *  - actionOnHit: 'skip' (default) | 'create_new_version'
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
        "WHERE description IN ('testproject','testsuite','testcase')");
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
        "WHERE id = " . intval($containerId));
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
    } elseif (isset($_REQUEST['markdown'])) {
        $markdown = strval($_REQUEST['markdown']);
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
    $hitCriteria = strtolower(trim(strval($_REQUEST['hit_criteria'] ?? 'name')));
    if ($hitCriteria !== 'name') {
        http_response_code(400);
        out(['status' => 'error',
             'message' => 'Unsupported hit_criteria (only "name" is implemented for markdown imports)']);
    }
    $actionOnHit = strtolower(trim(strval($_REQUEST['action_on_hit'] ?? 'skip')));
    if (!in_array($actionOnHit, ['skip', 'create_new_version'], true)) {
        http_response_code(400);
        out(['status' => 'error',
             'message' => 'Unsupported action_on_hit (skip | create_new_version)']);
    }

    $dryRun = intval($_REQUEST['dry_run'] ?? 0) === 1;

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
    $tables = tlObjectWithDB::getDBTables(array('nodes_hierarchy'));
    $existingCasesBySuite = [];
    $rows = $db->get_recordset(
        "SELECT NH.parent_id AS suite_id, NH.id AS tcase_id, NH.name " .
        "FROM {$tables['nodes_hierarchy']} NH WHERE NH.node_type_id = {$tcTypeId}");
    if (!is_null($rows)) {
        foreach ($rows as $r) {
            $key = strtolower(trim(strval($r['name'])));
            $existingCasesBySuite[intval($r['suite_id'])][$key] = intval($r['tcase_id']);
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

            if (isset($existingCasesBySuite[$suiteId][$nameKey])) {
                if ($actionOnHit === 'skip') {
                    $skipped[] = ['tcId' => strval($c['tcId']), 'title' => $title,
                                  'reason' => 'duplicate title in target suite'];
                    continue;
                }
                // create_new_version
                if ($dryRun) {
                    $newVersions[] = ['tcId' => strval($c['tcId']), 'title' => $title,
                                      'suite' => $suiteName, 'steps' => count($steps)];
                    continue;
                }
                try {
                    $existingTcId = intval($existingCasesBySuite[$suiteId][$nameKey]);
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
                                 'error' => $e->getMessage()];
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
                             'error' => $e->getMessage()];
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

http_response_code(400);
out(['status' => 'error', 'message' => 'Bad request']);
