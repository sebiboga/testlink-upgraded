<?php
/**
 * Create Test Cases from Issue XML BFF API — Refs #1502
 * URL: /api/tccreatefromissues/?action=init   (GET)  — context for the screen
 *      /api/tccreatefromissues/?action=import  (POST) — multipart Mantis XML
 *
 * Modernizes the last standalone legacy import screen in lib/testcases/:
 * tcCreateFromIssueMantisXML.php. Legacy parity:
 *   - permission: mgt_modify_tc on the target test project (403 otherwise)
 *   - upload cap: config import_file_max_size_bytes
 *   - input format: a Mantis bug-tracker XML export rooted at <mantis>,
 *     one <issue> per bug
 *   - one test case per issue: name `Issue:<id> - <summary>`, summary made of
 *     description (+ steps_to_reproduce + additional_information), external id
 *     = the bug id, execution type MANUAL, importance MEDIUM
 *   - duplicate external ids owned by another suite are rejected (legacy
 *     hit_with_same_external_ID + path message); within the same container the
 *     testcase::create() duplicate-name auto-rename path applies
 *   - the issue field labels (issue_issue, issue_description, ...) are served
 *     in the client locale (legacy used the session locale)
 *
 * JSON contract: status ok|error, tproject, container, result rows
 * [{name, message}], field checks, 400/401/403/404/413/422/500.
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
require_once(__DIR__ . '/../_guard.php');

doSessionStart();
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

$user = tlUser::getByID($db, intval($userId));
if (is_null($user)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'User not found']);
    exit;
}

function tcfi_out($data) { echo json_encode($data); exit; }

function tcfi_intParam($key, $default = 0) {
    $v = $_REQUEST[$key] ?? $default;
    return is_numeric($v) ? intval($v) : $default;
}

/** Resolve a supported client locale hint; null keeps the session locale. */
function tcfi_locale() {
    $loc = trim(strval($_REQUEST['locale'] ?? ''));
    if ($loc === '' || !preg_match('/^[a-z]{2}_[A-Z]{2}$/', $loc)) {
        return null;
    }
    return $loc;
}

/**
 * Resolve the container (target suite / project) and verify it belongs to the
 * given test project by walking nodes_hierarchy up to the project root.
 * Mirrors the legacy containerID semantics (container or project).
 */
function tcfi_resolveContainer($db, $tprojectId, $containerId) {
    $tables = tlObjectWithDB::getDBTables(['nodes_hierarchy']);
    $projId = intval($tprojectId);
    $cur = intval($containerId);
    $name = '';
    $isProject = false;
    $visited = [];
    for ($hops = 0; $hops < 64; $hops++) {
        if (isset($visited[$cur])) { break; }
        $visited[$cur] = true;
        $row = $db->get_recordset(
            "SELECT id, parent_id, name FROM {$tables['nodes_hierarchy']} " .
            "WHERE id = " . intval($cur));
        if (is_null($row) || count($row) !== 1) {
            return null;
        }
        $name = strval($row[0]['name']);
        $parent = intval($row[0]['parent_id']);
        if ($parent === 0) {
            $isProject = true;
            return ($cur === $projId)
                ? ['id' => $cur, 'name' => $name, 'isProject' => true]
                : null;
        }
        if ($cur === $projId) {
            $isProject = true;
            return ['id' => $cur, 'name' => $name, 'isProject' => true];
        }
        $cur = $parent;
    }
    return null;
}

/**
 * Turn a parsed <mantis> SimpleXML document into the flat TC set the legacy
 * getTestCaseSetFromIssueSimpleXMLObj() produced: one item per <issue>.
 */
function tcfi_issuesToTcSet($xml, $labels) {
    if (!$xml || !isset($xml->issue)) {
        return null;
    }
    $nl = '<p>';
    $set = [];
    $jdx = 0;
    $l = $labels;
    foreach ($xml->issue as $issue) {
        $summary = trim(strval($issue->summary ?? ''));
        $description = trim(strval($issue->description ?? ''));
        $steps = trim(strval($issue->steps_to_reproduce ?? ''));
        $additional = trim(strval($issue->additional_information ?? ''));
        $id = trim(strval($issue->id ?? ''));

        if ($summary === '' && $description === '' && $id === '') {
            continue;
        }
        $isum = $l['issue_description'] . $nl . $description;
        if ($steps !== '') {
            $isum .= $nl . $l['issue_steps_to_reproduce'] . $nl . $steps;
        }
        if ($additional !== '') {
            $isum .= $nl . $l['issue_additional_information'] . $nl . $additional;
        }

        $set[$jdx++] = [
            'name' => $l['issue_issue'] . ':' . $id . ' - ' . $summary,
            'summary' => $isum,
            'steps' => null,
            'internalid' => null,
            'externalid' => (preg_match('/^\d+$/', $id) && intval($id) > 0)
                ? intval($id) : null,
            'author_login' => null,
            'preconditions' => null,
        ];
    }
    return count($set) > 0 ? $set : null;
}

/**
 * Legacy-parity saveImportedTCData trimmed to the Mantis scope (no keywords /
 * custom fields / requirements in a bug-tracker export). The message strings
 * come from the same lang keys the legacy controller used.
 */
function tcfi_saveTcSet(&$db, &$tcaseMgr, $tcSet, $tprojectId, $containerId,
                        $userId, $prefix, $maxNameLen, $messages) {
    $resultMap = [];
    $safeNameLen = intval($maxNameLen * 0.8);
    for ($idx = 0; $idx < count($tcSet); $idx++) {
        $tc = $tcSet[$idx];
        $name = $tc['name'];
        $summary = strval($tc['summary']);
        $stepData = $tc['steps'];
        $externalid = $tc['externalid'];
        $node_order = isset($tc['node_order']) ? intval($tc['node_order']) : ($idx + 1);
        $preconditions = strval($tc['preconditions']);
        $exec_type = TESTCASE_EXECUTION_TYPE_MANUAL;
        $importance = MEDIUM;

        $nameLen = function_exists('tlStringLen') ? tlStringLen($name) : mb_strlen($name);
        if ($nameLen > $maxNameLen) {
            $xx = $messages['start_feedback'];
            $xx .= sprintf($messages['testcase_name_too_long'], $nameLen, $maxNameLen) . "\n";
            $xx .= $messages['original_name'] . "\n" . $name . "\n" . $messages['end_warning'] . "\n";
            $summary = nl2br($xx) . $summary;
            $name = function_exists('tlSubStr') ? tlSubStr($name, 0, $safeNameLen) : $name;
        }

        $doCreate = true;
        if ($doCreate && $externalid > 0) {
            // block creation of an external id already owned by ANOTHER suite
            $itemId = intval($tcaseMgr->getInternalID(
                $externalid, ['tproject_id' => $tprojectId]));
            if ($itemId > 0) {
                $owner = $tcaseMgr->getTestSuite($itemId);
                if (intval($owner) !== intval($containerId)) {
                    $stain = $tcaseMgr->tree_manager->get_path($itemId, null, 'name');
                    $stain = is_null($stain) ? [] : $stain;
                    $n = count($stain);
                    if ($n > 0 && $externalid) {
                        $stain[$n - 1] = $prefix .
                            config_get('testcase_cfg')->glue_character .
                            $externalid . ':' . $stain[$n - 1];
                    }
                    $resultMap[] = [
                        'name' => $name,
                        'message' => $messages['hit_with_same_external_ID'] . implode('/', $stain),
                    ];
                    $doCreate = false;
                }
            }
        }

        if ($doCreate) {
            $createOptions = [
                'check_duplicate_name' => testcase::CHECK_DUPLICATE_NAME,
                'action_on_duplicate_name' => null,
                'external_id' => $externalid,
            ];
            $ret = $tcaseMgr->create(
                $containerId, $name, $summary, $preconditions, $stepData,
                $userId, null, $node_order, testcase::AUTOMATIC_ID,
                $exec_type, $importance, $createOptions);
            if ($ret && isset($ret['msg'])) {
                $resultMap[] = ['name' => $name, 'message' => strval($ret['msg'])];
            }
        }
    }
    return $resultMap;
}

$action = $_GET['action'] ?? '';
$tcaseMgr = new testcase($db);
$tprojectMgr = new testproject($db);

// ---------------------------------------------------------------------------
// GET ?action=init[&tproject_id=N][&containerID=N][&locale=xx_YY]
// Context for the modernized screen: names, size limit, grant map, issue
// field labels already localized via the client locale hint.
// ---------------------------------------------------------------------------
if ($action === 'init') {
    $tprojectId = tcfi_intParam('tproject_id', intval($_SESSION['testprojectID'] ?? 0));
    if ($tprojectId <= 0) {
        http_response_code(400);
        tcfi_out(['status' => 'error', 'message' => 'Invalid test project id']);
    }
    $info = $tprojectMgr->get_by_id($tprojectId);
    if (!$info) {
        http_response_code(404);
        tcfi_out(['status' => 'error', 'message' => 'Test project not found']);
    }

    $containerId = tcfi_intParam('containerID', $tprojectId);
    if ($containerId <= 0) {
        $containerId = $tprojectId;
    }
    $container = tcfi_resolveContainer($db, $tprojectId, $containerId);
    if (is_null($container)) {
        http_response_code(400);
        tcfi_out(['status' => 'error', 'message' => 'Container does not belong to the test project']);
    }

    $loc = tcfi_locale();
    $prevLocale = $_SESSION['locale'] ?? null;
    if ($loc !== null) {
        $_SESSION['locale'] = $loc;
    }
    $labels = init_labels([
        'issue_issue' => null,
        'issue_steps_to_reproduce' => null,
        'issue_summary' => null,
        'issue_target_version' => null,
        'issue_description' => null,
        'issue_additional_information' => null,
    ]);
    if ($prevLocale !== null) {
        $_SESSION['locale'] = $prevLocale;
    }

    tcfi_out([
        'status' => 'ok',
        'tproject' => ['id' => $tprojectId, 'name' => strval($info['name'])],
        'container' => $container,
        'maxUploadBytes' => intval(config_get('import_file_max_size_bytes')),
        'grants' => [
            'mgt_modify_tc' => $user->hasRight($db, 'mgt_modify_tc', $tprojectId) ? 1 : 0,
        ],
        'labels' => $labels,
    ]);
}

// ---------------------------------------------------------------------------
// POST ?action=import&tproject_id=N[&containerID=N][&locale=xx_YY]
// Body: multipart file field "uploadedFile" (Mantis XML export)
// ---------------------------------------------------------------------------
if ($action === 'import') {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        tcfi_out(['status' => 'error', 'message' => 'Use POST']);
    }

    $tprojectId = tcfi_intParam('tproject_id', intval($_SESSION['testprojectID'] ?? 0));
    if ($tprojectId <= 0) {
        http_response_code(400);
        tcfi_out(['status' => 'error', 'message' => 'Invalid test project id']);
    }
    $info = $tprojectMgr->get_by_id($tprojectId);
    if (!$info) {
        http_response_code(404);
        tcfi_out(['status' => 'error', 'message' => 'Test project not found']);
    }
    if (!$user->hasRight($db, 'mgt_modify_tc', $tprojectId)) {
        http_response_code(403);
        tcfi_out(['status' => 'error', 'message' => 'No permission']);
    }

    $containerId = tcfi_intParam('containerID', $tprojectId);
    if ($containerId <= 0) {
        $containerId = $tprojectId;
    }
    $container = tcfi_resolveContainer($db, $tprojectId, $containerId);
    if (is_null($container)) {
        http_response_code(400);
        tcfi_out(['status' => 'error', 'message' => 'Container does not belong to the test project']);
    }

    if (!isset($_FILES['uploadedFile']) || $_FILES['uploadedFile']['error'] !== UPLOAD_ERR_OK) {
        $errCode = isset($_FILES['uploadedFile']) ? intval($_FILES['uploadedFile']['error']) : -1;
        http_response_code(400);
        tcfi_out(['status' => 'error', 'message' => 'File upload failed (error code: ' . $errCode . ')']);
    }

    $maxBytes = intval(config_get('import_file_max_size_bytes'));
    if ($maxBytes <= 0) {
        $maxBytes = 10 * 1024 * 1024;
    }
    $uploadedBytes = intval($_FILES['uploadedFile']['size']);
    if ($uploadedBytes > $maxBytes) {
        http_response_code(413);
        tcfi_out([
            'status' => 'error',
            'message' => sprintf('File too large (%d bytes); limit is %d bytes',
                                 $uploadedBytes, $maxBytes),
        ]);
    }

    // ---- safe XML parse (LIBXML_NONET) --------------------------------------
    libxml_use_internal_errors(true);
    $rawXml = @file_get_contents($_FILES['uploadedFile']['tmp_name']);
    $xml = @simplexml_load_string($rawXml, 'SimpleXMLElement', LIBXML_NONET);
    libxml_clear_errors();
    if ($xml === false || $rawXml === false || $xml->getName() !== 'mantis') {
        http_response_code(422);
        tcfi_out(['status' => 'error',
                  'message' => 'Invalid XML file: root element must be <mantis>']);
    }

    // localize the issue field labels via the client locale hint (legacy used
    // the session locale) — then restore so we do not mutate the session.
    $loc = tcfi_locale();
    $prevLocale = $_SESSION['locale'] ?? null;
    if ($loc !== null) {
        $_SESSION['locale'] = $loc;
    }
    $messages = [];
    foreach (['already_exists_updated', 'original_name', 'testcase_name_too_long',
              'start_warning', 'end_warning', 'testlink_warning',
              'hit_with_same_external_ID'] as $k) {
        $messages[$k] = lang_get($k);
    }
    $messages['start_feedback'] = $messages['start_warning'] . "\n" . $messages['testlink_warning'] . "\n";
    $labels = init_labels([
        'issue_issue' => null,
        'issue_steps_to_reproduce' => null,
        'issue_summary' => null,
        'issue_target_version' => null,
        'issue_description' => null,
        'issue_additional_information' => null,
    ]);
    if ($prevLocale !== null) {
        $_SESSION['locale'] = $prevLocale;
    }

    $cleanExit = false;
    register_shutdown_function(function () use (&$cleanExit) {
        if ($cleanExit) {
            return;
        }
        while (ob_get_level()) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['status' => 'error', 'message' => 'Import failed']);
    });

    $tcSet = tcfi_issuesToTcSet($xml, $labels);
    $fieldSize = config_get('field_size');
    $maxNameLen = is_object($fieldSize) && isset($fieldSize->testcase_name)
        ? intval($fieldSize->testcase_name) : 100;

    $resultMap = [];
    try {
        if (!is_null($tcSet)) {
            $prefix = $tprojectMgr->getTestCasePrefix($tprojectId);
            $resultMap = tcfi_saveTcSet(
                $db, $tcaseMgr, $tcSet, $tprojectId, $containerId,
                intval($userId), $prefix, $maxNameLen, $messages);
        }
    } catch (\Throwable $e) {
        $cleanExit = true;
        http_response_code(500);
        tcfi_out(['status' => 'error', 'message' => 'Import failed: ' . $e->getMessage()]);
    }
    $cleanExit = true;

    tcfi_out([
        'status' => 'ok',
        'tproject' => ['id' => $tprojectId, 'name' => strval($info['name'])],
        'container' => $container,
        'issues' => is_null($tcSet) ? 0 : count($tcSet),
        'result' => $resultMap,
    ]);
}

http_response_code(400);
tcfi_out(['status' => 'error', 'message' => 'Bad request']);