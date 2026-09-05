<?php
/**
 * Test Results Import BFF API
 * URL: /api/resultsimport/?action=info   (GET)   — form context data
 *      /api/resultsimport/?action=import (POST, multipart) — import results XML
 *
 * Mirrors lib/results/resultsImport.php (TestLink 1.9.20 behavior): import
 * execution results from an XML file ("Import XML Results") using the same
 * helpers (simplexml_load_file_wrapper / importResults / check_exec_values /
 * copyIssues) and identical localized result messages.
 *
 * Rights (write-execution gate consistent with the execution screens): the
 * user must hold testplan_execute on the owning test project of the target
 * test plan. Reads (info) use the same gate since the form targets a write.
 *
 * Resilience: the legacy DB layer die()s with a raw HTML page on hard DB
 * errors; an output buffer + shutdown handler traps that so this BFF always
 * answers with application/json. The XML parser is hardened against XXE and
 * malformed files (no die()).
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
require_once(__DIR__ . '/../../lib/functions/xml.inc.php');
require_once(__DIR__ . '/../../lib/functions/exec.inc.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json');

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || $userId <= 0) {
    http_response_code(401);
    out(['status' => 'error', 'message' => 'Not authenticated']);
}

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    http_response_code(401);
    out(['status' => 'error', 'message' => 'User not found']);
}

function out($data) { echo json_encode($data); exit; }

function getParam($key, $default = null) { return $_REQUEST[$key] ?? $default; }
function getIntParam($key, $default = 0) {
    $v = $_REQUEST[$key] ?? $default;
    return is_numeric($v) ? intval($v) : intval($default);
}

$action = getParam('action');
$tplanMgr = new testplan($db);
$tprojectMgr = new testproject($db);

/**
 * Resolve the target test plan + owning project, verifying the current user
 * holds testplan_execute (write gate). Returns the context, or errors out.
 */
function resultsImportResolveContext($db, $user, $tplanMgr, $tprojectMgr) {
    $tplanId = getIntParam('tplan_id');
    if ($tplanId > 0) {
        $planInfo = $tplanMgr->get_by_id($tplanId);
        if (is_null($planInfo) || !isset($planInfo['name'])) {
            http_response_code(404);
            out(['status' => 'error', 'message' => 'Test plan not found']);
        }
    } else {
        $tplanId = intval($_SESSION['testplanID'] ?? 0);
        if ($tplanId <= 0) {
            http_response_code(400);
            out(['status' => 'error', 'message' => 'No test plan selected']);
        }
        $planInfo = $tplanMgr->get_by_id($tplanId);
        if (is_null($planInfo)) {
            http_response_code(404);
            out(['status' => 'error', 'message' => 'Test plan not found']);
        }
    }

    // Owning test project.
    $tprojectId = getIntParam('tproject_id');
    if ($tprojectId <= 0) {
        $nodeInfo = $tplanMgr->tree_manager->get_node_hierarchy_info($tplanId);
        $tprojectId = intval(is_null($nodeInfo) ? 0 : $nodeInfo['parent_id']);
    }
    if ($tprojectId <= 0) {
        $tprojectId = intval($_SESSION['testprojectID'] ?? 0);
    }
    if ($tprojectId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test project id']);
    }

    if (!$user->hasRight($db, 'testplan_execute', $tprojectId)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }

    $tprojInfo = $tprojectMgr->get_by_id($tprojectId);
    if (!$tprojInfo) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Test project not found']);
    }

    return [
        'tplan_id' => $tplanId,
        'tproject_id' => $tprojectId,
        'plan_name' => (string)$planInfo['name'],
        'project_name' => (string)$tprojInfo['name'],
    ];
}

// ---------------------------------------------------------------------------
// GET ?action=info[&tplan_id=N][&tproject_id=N]  — form context data
// ---------------------------------------------------------------------------
if ($action === 'info') {
    $ctx = resultsImportResolveContext($db, $user, $tplanMgr, $tprojectMgr);
    $tplanId = $ctx['tplan_id'];
    $tprojectId = $ctx['tproject_id'];

    ob_start();
    register_shutdown_function(function () {
        if (defined('BFF_JSON_SENT') || ob_get_level() <= 0 || headers_sent()) {
            return;
        }
        $buf = trim(ob_get_contents());
        if ($buf === '' || ($buf[0] !== '{' && $buf[0] !== '[')) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            http_response_code(500);
            echo json_encode([
                'status' => 'error',
                'message' => 'An unexpected error occurred while loading the import screen.',
            ]);
        }
    });

    try {
        // Builds for the selected plan.
    $buildRows = $tplanMgr->get_builds($tplanId);
    $builds = [];
    foreach (($buildRows ? $buildRows : []) as $b) {
        $builds[] = [
            'id' => intval($b['id']),
            'name' => (string)$b['name'],
            'open' => (intval($b['is_open'] ?? 1) === 1),
            'active' => (intval($b['active'] ?? 1) === 1),
        ];
    }

    // Platforms linked to the plan.
    $platforms = [];
    $platRows = $tplanMgr->getPlatforms($tplanId);
    foreach (($platRows ? $platRows : []) as $p) {
        $platforms[] = [
            'id' => intval($p['id']),
            'name' => (string)$p['name'],
        ];
    }

    $copyIssuesCfg = config_get('exec_cfg');
    $copyIssuesEnabled = isset($copyIssuesCfg->copyLatestExecIssues)
        && (intval($copyIssuesCfg->copyLatestExecIssues->enabled) === 1);

    $maxBytes = intval(config_get('import_file_max_size_bytes'));

    out([
        'status' => 'ok',
        'tproject' => ['id' => $tprojectId, 'name' => $ctx['project_name']],
        'tplan' => ['id' => $tplanId, 'name' => $ctx['plan_name']],
        'builds' => $builds,
        'platforms' => $platforms,
        'copy_issues_enabled' => $copyIssuesEnabled,
        'file_size_limit' => $maxBytes,
        'file_size_limit_kb' => $maxBytes > 0 ? max(1, round($maxBytes / 1024)) : 0,
        'import_types' => [
            ['id' => 'XML', 'label' => 'XML'],
        ],
    ]);
    } catch (\Throwable $e) {
        http_response_code(500);
        out(['status' => 'error', 'message' => 'An unexpected error occurred while loading the import screen: ' . $e->getMessage()]);
    }
}

// ---------------------------------------------------------------------------
// POST ?action=import  — perform the import
// ---------------------------------------------------------------------------
if ($action === 'import') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        out(['status' => 'error', 'message' => 'Use POST']);
    }

    $ctx = resultsImportResolveContext($db, $user, $tplanMgr, $tprojectMgr);
    $tplanId = $ctx['tplan_id'];
    $tprojectId = $ctx['tproject_id'];

    $buildId = getIntParam('build_id');
    $platformId = getIntParam('platform_id');
    $copyIssues = (getIntParam('copy_issues') === 1);
    $importType = strtoupper(strval(getParam('importType', 'XML')));
    if ($importType !== 'XML') {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Unsupported import type']);
    }

    // ---- validate upload ---------------------------------------------------
    if (!isset($_FILES['uploadedFile']) || $_FILES['uploadedFile']['error'] !== UPLOAD_ERR_OK) {
        $errCode = isset($_FILES['uploadedFile']) ? $_FILES['uploadedFile']['error'] : -1;
        if ($errCode === UPLOAD_ERR_INI_SIZE || $errCode === UPLOAD_ERR_FORM_SIZE) {
            $maxBytes = intval(config_get('import_file_max_size_bytes'));
            out(['status' => 'error', 'message' =>
                 sprintf(lang_get('max_file_size_is'), round($maxBytes / 1024)) . ' KB']);
        }
        out(['status' => 'error', 'message' => lang_get('please_choose_file_to_import') .
             ' (code ' . $errCode . ')']);
    }

    $maxBytes = intval(config_get('import_file_max_size_bytes'));
    $uploadedBytes = intval($_FILES['uploadedFile']['size']);
    if ($maxBytes > 0 && $uploadedBytes > $maxBytes) {
        out(['status' => 'error', 'message' =>
             sprintf(lang_get('max_file_size_is'), round($maxBytes / 1024)) . ' KB']);
    }

    // ---- save to temp -------------------------------------------------------
    $tmpDir = TL_TEMP_PATH;
    if (!$tmpDir || !is_dir($tmpDir)) {
        $tmpDir = sys_get_temp_dir();
    }
    $dest = $tmpDir . '/' . session_id() . '-results.import';
    if (!move_uploaded_file($_FILES['uploadedFile']['tmp_name'], $dest)) {
        http_response_code(500);
        out(['status' => 'error', 'message' => 'Failed to save uploaded file']);
    }

    ob_start();
    register_shutdown_function(function () {
        if (defined('BFF_JSON_SENT') || ob_get_level() <= 0 || headers_sent()) {
            return;
        }
        $buf = trim(ob_get_contents());
        if ($buf === '' || ($buf[0] !== '{' && $buf[0] !== '[')) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            http_response_code(500);
            echo json_encode([
                'status' => 'error',
                'message' => 'Import failed: a database error occurred while processing the file.',
            ]);
        }
    });

    $resultMap = null;

    try {
        // Hardened XML check (legacy check_xml_execution_results without die()).
        $fileCheck = resultsImportCheckXml($dest);
        if (!$fileCheck['status_ok']) {
            @unlink($dest);
            http_response_code(422);
            out(['status' => 'error', 'message' => $fileCheck['msg']]);
        }

        libxml_use_internal_errors(true);
        $rawXml = @file_get_contents($dest);
        $xml = @simplexml_load_string($rawXml, 'SimpleXMLElement', LIBXML_NONET);
        libxml_clear_errors();
        if ($rawXml === false || $xml === false) {
            @unlink($dest);
            http_response_code(422);
            out(['status' => 'error', 'message' => lang_get('wrong_results_import_format')]);
        }
        if ($xml->getName() !== 'results') {
            @unlink($dest);
            http_response_code(422);
            out(['status' => 'error', 'message' => lang_get('wrong_results_import_format')]);
        }

        // Build the execution context (mirrors legacy importResults context keys).
        $executionContext = (object)[
            'tprojectID' => $tprojectId,
            'tprojectName' => null,
            'tplanID' => $tplanId,
            'tplanName' => null,
            'buildID' => $buildId,
            'buildName' => null,
            'platformID' => $platformId,
            'platformName' => null,
            'userID' => $userId,
            'copyIssues' => $copyIssues,
        ];

        // XML context overrides GUI selection; name has precedence over id.
        $contextKeys = [
            'testproject' => ['id' => 'tprojectID', 'name' => 'tprojectName'],
            'testplan'    => ['id' => 'tplanID', 'name' => 'tplanName'],
            'build'       => ['id' => 'buildID', 'name' => 'buildName'],
            'platform'    => ['id' => 'platformID', 'name' => 'platformName'],
        ];
        foreach ($contextKeys as $xmlkey => $execElem) {
            if (isset($xml->{$xmlkey})) {
                $joker = $xml->{$xmlkey};
                if (isset($joker['name'])) {
                    $executionContext->{$execElem['name']} = (string)$joker['name'];
                    $executionContext->{$execElem['id']} = null;
                    continue;
                }
                if (isset($joker['id'])) {
                    $executionContext->{$execElem['id']} = (int)$joker['id'];
                    $executionContext->{$execElem['name']} = null;
                }
            }
        }

        $xmlTCExec = $xml->xpath('//testcase');
        $resultData = resultsImportExecutionsFromXML($xmlTCExec);
        if ($resultData) {
            $resultMap = resultsImportSaveResultData($db, $resultData, $executionContext);
        } else {
            $resultMap = [];
        }
    } catch (\Throwable $e) {
        @unlink($dest);
        http_response_code(500);
        out(['status' => 'error', 'message' => 'Import failed: ' . $e->getMessage()]);
    }

    @unlink($dest);

    // Normalize the legacy flat [message] rows into a report list.
    $normalized = [];
    foreach ((array)$resultMap as $row) {
        $msg = is_array($row) ? (string)($row[0] ?? '') : (string)$row;
        $normalized[] = ['message' => $msg];
    }

    out([
        'status' => 'ok',
        'tproject' => ['id' => $tprojectId, 'name' => $ctx['project_name']],
        'tplan_id' => $tplanId,
        'build_id' => $buildId,
        'platform_id' => $platformId,
        'result' => $normalized,
    ]);
}

http_response_code(400);
out(['status' => 'error', 'message' => 'Bad request']);

// ===========================================================================
//  Ported legacy helpers (lib/results/resultsImport.php), made JSON-safe
// ===========================================================================

/**
 * Check the XML file starts OK (legacy check_xml_execution_results, no die()).
 */
function resultsImportCheckXml($fileName) {
    $fileCheck = ['status_ok' => 0, 'msg' => lang_get('wrong_results_import_format')];
    if (!file_exists($fileName) || filesize($fileName) === 0) {
        return ['status_ok' => 0, 'msg' => lang_get('wrong_results_import_format')];
    }
    libxml_use_internal_errors(true);
    $raw = @file_get_contents($fileName);
    $xml = @simplexml_load_string($raw, 'SimpleXMLElement', LIBXML_NONET);
    libxml_clear_errors();
    if ($xml !== false) {
        $fileCheck = ['status_ok' => 1, 'msg' => 'ok'];
        if ($xml->getName() !== 'results') {
            $fileCheck = ['status_ok' => 0, 'msg' => lang_get('wrong_results_import_format')];
        }
    }
    return $fileCheck;
}

/**
 * Parse the execution rows out of the <results> document
 * (legacy importExecutionsFromXML + importExecutionFromXML).
 */
function resultsImportExecutionsFromXML($xmlTCExecSet) {
    $execInfoSet = null;
    if ($xmlTCExecSet) {
        $jdx = 0;
        $execQty = count($xmlTCExecSet);
        for ($idx = 0; $idx < $execQty; $idx++) {
            $execInfo = resultsImportExecutionFromXML($xmlTCExecSet[$idx]);
            if ($execInfo) {
                $execInfoSet[$jdx++] = $execInfo;
            }
        }
    }
    return $execInfoSet;
}

function resultsImportExecutionFromXML($xmlTCExec) {
    if (!$xmlTCExec) {
        return null;
    }
    $execInfo = [];
    $execInfo['tcase_id'] = isset($xmlTCExec['id']) ? (int)$xmlTCExec['id'] : 0;
    $execInfo['tcase_external_id'] = (string)$xmlTCExec['external_id'];
    $execInfo['tcase_name'] = (string)$xmlTCExec->name;
    $execInfo['result'] = (string)trim($xmlTCExec->result);
    $execInfo['notes'] = (string)trim($xmlTCExec->notes);
    $execInfo['timestamp'] = (string)trim($xmlTCExec->timestamp);
    $execInfo['tester'] = (string)trim($xmlTCExec->tester);
    $execInfo['execution_type'] = intval((int)trim($xmlTCExec->execution_type));
    $execInfo['execution_duration'] = trim($xmlTCExec->execution_duration);

    if (($bugQty = count($xmlTCExec->bug_id)) > 0) {
        foreach ($xmlTCExec->bug_id as $bug) {
            $execInfo['bug_id'][] = (string)$bug;
        }
    }

    $execInfo['steps'] = null;
    if (isset($xmlTCExec->steps) && isset($xmlTCExec->steps->step)) {
        $itemStructure['elements'] = [
            'integer' => ['step_number' => 'intval'],
            'string'  => ['result' => 'trim', 'notes' => 'trim'],
        ];
        $execInfo['steps'] = getItemsFromSimpleXMLObj($xmlTCExec->steps->step, $itemStructure);
    }

    $execInfo['custom_fields'] = null;
    if (isset($xmlTCExec->custom_fields) && isset($xmlTCExec->custom_fields->custom_field)) {
        $itemStructure['elements'] = [
            'string' => ['name' => 'trim', 'value' => 'trim'],
        ];
        $execInfo['custom_fields'] = getItemsFromSimpleXMLObj(
            $xmlTCExec->custom_fields->custom_field, $itemStructure);
    }

    return $execInfo;
}

/**
 * Mirror legacy check_exec_values() — validate a single parsed execution row.
 */
function resultsImportCheckExecValues($db, $tcaseMgr, $tcaseCfg, &$execValues, &$columnDef, $tprojectId = 0) {
    $tables = tlObjectWithDB::getDBTables(array('users', 'execution_bugs'));
    $checks = ['status_ok' => false, 'tcase_id' => 0, 'tester_id' => 0, 'msg' => []];
    $tcaseId = $execValues['tcase_id'];
    $tcaseExternalId = trim($execValues['tcase_external_id']);
    $usingExternalId = ($tcaseExternalId !== '');

    if ($usingExternalId) {
        // A plain numeric external ID (no "prefix-id" glue) requires the test
        // project ID to be scoped (testcase::getInternalID throws otherwise).
        $optGi = $tprojectId > 0 ? ['tproject_id' => $tprojectId] : null;
        $checks['tcase_id'] = $tcaseMgr->getInternalID($tcaseExternalId, $optGi);
        $checks['status_ok'] = intval($checks['tcase_id']) > 0;
        if (!$checks['status_ok']) {
            $checks['msg'][] = sprintf(lang_get('tcase_external_id_do_not_exists'), $tcaseExternalId);
        }
    } else {
        $checks['tcase_id'] = $tcaseId;
        $checks['status_ok'] = intval($checks['tcase_id']) > 0;
        if (!$checks['status_ok']) {
            $checks['msg'][] = sprintf(lang_get('tcase_id_is_not_number'), $tcaseId);
        }
    }

    $identity = '';
    if ($checks['status_ok']) {
        $identity = $usingExternalId ? $tcaseExternalId : $checks['tcase_id'];
    }

    if ($checks['status_ok'] && $execValues['timestamp'] !== '') {
        $checks['status_ok'] = isValidISODateTime($execValues['timestamp']);
        if (!$checks['status_ok']) {
            $checks['msg'][] = sprintf(lang_get('invalid_execution_timestamp'),
                                       $identity, $execValues['timestamp']);
        }
    }

    if ($checks['status_ok'] && $execValues['tester'] !== '') {
        $sql = "SELECT id,login FROM {$tables['users']} WHERE login ='" .
               $db->prepare_string($execValues['tester']) . "'";
        $userInfo = $db->get_recordset($sql);
        if (!is_null($userInfo) && isset($userInfo[0]['id'])) {
            $checks['tester_id'] = $userInfo[0]['id'];
        } else {
            $checks['status_ok'] = false;
            $checks['msg'][] = sprintf(lang_get('invalid_tester'), $identity, $execValues['tester']);
        }
    }

    $execValues['bug_id'] = isset($execValues['bug_id']) ? $execValues['bug_id'] : null;
    if ($checks['status_ok'] && !is_null($execValues['bug_id']) && is_array($execValues['bug_id'])) {
        foreach ($execValues['bug_id'] as $bugId) {
            if (($fieldLen = strlen(trim($bugId))) > $columnDef['bug_id']->max_length) {
                $checks['msg'][] = sprintf(lang_get('bug_id_invalid_len'),
                                           $fieldLen, $columnDef['bug_id']->max_length);
                $checks['status_ok'] = false;
                break;
            }
        }
    }

    if ($checks['status_ok'] && isset($execValues['execution_type'])) {
        $execValues['execution_type'] = intval($execValues['execution_type']);
        $execDomain = $tcaseMgr->get_execution_types();
        if ($execValues['execution_type'] == 0) {
            $execValues['execution_type'] = TESTCASE_EXECUTION_TYPE_MANUAL;
            $checks['msg'][] = sprintf(lang_get('missing_exec_type'),
                                       $execValues['execution_type'],
                                       isset($execDomain[$execValues['execution_type']])
                                           ? $execDomain[$execValues['execution_type']]
                                           : '');
        } else {
            $checks['status_ok'] = isset($execDomain[$execValues['execution_type']]);
            if (!$checks['status_ok']) {
                $checks['msg'][] = sprintf(lang_get('invalid_exec_type'),
                                           $execValues['execution_type']);
            }
        }
    }

    return $checks;
}

/**
 * Mirror legacy saveImportedResultData() — persist the execution rows and
 * build the localized result-message list. $ctx is the resolved context object
 * (legacy: $executionContext), $origCtx keeps the GUI-selected context object
 * for the copyIssues option (legacy passed $context as $options).
 */
function resultsImportSaveResultData(&$db, $resultData, $context) {
    if (!$resultData) {
        return null;
    }

    $debugMsg = 'FUNCTION: ' . __FUNCTION__;
    $tables = tlObjectWithDB::getDBTables(array('executions', 'execution_bugs'));
    $tcaseCfg = config_get('testcase_cfg');

    $l10n = [
        'import_results_tc_not_found' => '',
        'import_results_invalid_result' => '',
        'tproject_id_not_found' => '',
        'import_results_ok' => '',
        'invalid_cf' => '',
        'import_results_skipped' => '',
    ];
    foreach ($l10n as $key => $value) {
        $l10n[$key] = lang_get($key);
    }

    $resultsCfg = config_get('results');
    // Acceptable result codes come from the DB status_code domain (the legacy
    // code_status key is undefined in the base config — the literal correct
    // domain is status_code).
    $acceptableCodes = [];
    foreach (($resultsCfg['status_code'] ?? []) as $ks => $code) {
        $acceptableCodes[strtolower($code)] = true;
    }
    foreach (($resultsCfg['status_label'] ?? []) as $ks => $lbl) {
        $code = $resultsCfg['status_code'][$ks] ?? $ks;
        $l10n[$code] = lang_get($lbl);
    }

    // Column defs for execution_bugs.bug_id length check.
    $adodbObj = $db->get_dbmgr_object();
    $columnDef = [];
    $columnDef['execution_bugs'] = $adodbObj->MetaColumns($tables['execution_bugs']);
    $keySet = array_keys($columnDef['execution_bugs']);
    foreach ($keySet as $keyName) {
        if (($keylow = strtolower($keyName)) != $keyName) {
            $columnDef['execution_bugs'][$keylow] = $columnDef['execution_bugs'][$keyName];
            unset($columnDef['execution_bugs'][$keyName]);
        }
    }

    $user = new tlUser($context->userID);
    $user->readFromDB($db);

    $tcaseMgr = new testcase($db);
    $resultMap = [];

    $tcQty = count($resultData);
    $tplanMgr = null;
    $tprojectMgr = null;
    $buildMgr = null;
    if ($tcQty) {
        $tplanMgr = new testplan($db);
        $tprojectMgr = new testproject($db);
        $buildMgr = new build($db);
    }

    // ---- common-settings checks (project / plan / build / platform) --------
    $checks = ['status_ok' => true, 'msg' => null];

    $dummy = null;
    if (!is_null($context->tprojectID) && intval($context->tprojectID) > 0) {
        $dummy = $tprojectMgr->get_by_id($context->tprojectID, ['output' => 'existsByID']);
    } else if (!is_null($context->tprojectName)) {
        $row = $tprojectMgr->get_by_name($context->tprojectName, null, ['output' => 'existsByName']);
        if (!is_null($row)) {
            $dummy = $row[0];
        }
    }
    $checks['status_ok'] = !is_null($dummy);
    if (!$checks['status_ok']) {
        $checks['msg'][] = sprintf($l10n['tproject_id_not_found'], $context->tprojectID);
    }
    if (!$checks['status_ok']) {
        foreach ($checks['msg'] as $warning) {
            $resultMap[] = [$warning];
        }
    }
    if ($checks['status_ok']) {
        $context->tprojectID = $dummy['id'];
    }

    // plan
    $dummy = null;
    if (!is_null($context->tplanID) && intval($context->tplanID) > 0) {
        $dummy = $tplanMgr->get_by_id($context->tplanID, ['output' => 'minimun']);
        if (!is_null($dummy)) {
            $dummy['id'] = $context->tplanID;
        }
    } else if (!is_null($context->tplanName)) {
        $dummy = $tplanMgr->get_by_name($context->tplanName, $context->tprojectID, ['output' => 'minimun']);
        if (!is_null($dummy)) {
            $dummy = $dummy[0];
        }
    }
    if (!is_null($dummy)) {
        $context->tplanID = $dummy['id'];
    }
    if ((intval($context->tprojectID) <= 0) && intval($context->tplanID) > 0) {
        $dummy = $tplanMgr->tree_manager->get_node_hierarchy_info($context->tplanID);
        $context->tprojectID = is_null($dummy) ? null : $dummy['parent_id'];
    }

    // platform
    $dummy = null;
    $tplanMgr->platform_mgr->setTestProjectID($context->tprojectID);
    if (!is_null($context->platformID) && intval($context->platformID) > 0) {
        $dummy = [$tplanMgr->platform_mgr->getByID($context->platformID)];
    } else if (property_exists($context, 'platformName') && !is_null($context->platformName)) {
        $xx = $tplanMgr->platform_mgr->getID($context->platformName);
        if (!is_null($xx)) {
            $dummy = [0 => ['id' => $xx]];
        }
    }
    if (!is_null($dummy)) {
        $context->platformID = $dummy[0]['id'];
    }

    // build
    $dummy = null;
    $optGB = ['tproject_id' => $context->tprojectID, 'output' => 'minimun'];
    if (!is_null($context->buildID) && intval($context->buildID) > 0) {
        $dummy = $buildMgr->get_by_id($context->buildID, $optGB);
        if (!is_null($dummy)) {
            $dummy = [$dummy];
        }
    } else if (!is_null($context->buildName)) {
        $dummy = $buildMgr->get_by_name($context->buildName, $optGB);
    }
    if (!is_null($dummy)) {
        $context->buildID = $dummy[0]['id'];
    }

    $doIt = $checks['status_ok'];

    // Authorization re-check on the FINAL XML-resolved context: the initial
    // gate ran against the GUI-selected project, but the XML <testproject>/
    // <testplan> elements can override the target. Refuse if the resolved
    // project no longer grants testplan_execute.
    if ($doIt && intval($context->tprojectID) > 0) {
        $authUser = new tlUser($context->userID);
        $authUser->readFromDB($db);
        if (!$authUser->hasRight($db, 'testplan_execute', $context->tprojectID)) {
            $checks['status_ok'] = false;
            $resultMap[] = ['No permission'];
            $doIt = false;
        }
    }

    for ($idx = 0; $doIt && $idx < $tcQty; $idx++) {
        $testerId = 0;
        $testerName = '';
        $usingExternalId = false;
        $message = null;
        $statusOk = true;
        $tcaseExec = $resultData[$idx];

        $checks = resultsImportCheckExecValues($db, $tcaseMgr, $tcaseCfg, $tcaseExec, $columnDef['execution_bugs'], intval($context->tprojectID));
        $statusOk = $checks['status_ok'];
        if ($statusOk) {
            $tcaseId = $checks['tcase_id'];
            $tcaseExternalId = trim($tcaseExec['tcase_external_id']);
            $testerId = $checks['tester_id'];
            $usingExternalId = ($tcaseExternalId !== '');
        } else {
            foreach ($checks['msg'] as $warning) {
                $resultMap[] = [$warning];
            }
        }

        if ($statusOk) {
            $tcaseIdentity = $usingExternalId ? $tcaseExternalId : $tcaseId;
            $resultCode = strtolower($tcaseExec['result']);
            $resultIsAcceptable = isset($acceptableCodes[$resultCode]);
            $notes = $tcaseExec['notes'];
            $message = null;

            $infoOnCase = $tplanMgr->getLinkInfo($context->tplanID, $tcaseId, $context->platformID);
            if (is_null($infoOnCase)) {
                $message = sprintf($l10n['import_results_tc_not_found'], $tcaseIdentity);
            } else if (!$resultIsAcceptable) {
                $message = sprintf($l10n['import_results_invalid_result'],
                                   $tcaseIdentity, $tcaseExec['result']);
            } else {
                $infoOnCase = current($infoOnCase);
                $tcversionId = $infoOnCase['tcversion_id'];
                $version = $infoOnCase['version'];
                $notes = $db->prepare_string(trim($notes));

                $executionTs = ($tcaseExec['timestamp'] !== '')
                    ? "'" . $tcaseExec['timestamp'] . "'"
                    : $db->db_now();

                if ($testerId != 0) {
                    $testerName = $tcaseExec['tester'];
                } else {
                    $testerName = $user->login;
                    $testerId = $context->userID;
                }

                $addExecDuration = (strlen($tcaseExec['execution_duration']) > 0
                                    && is_numeric($tcaseExec['execution_duration']));

                $lexid = 0;
                if ($context->copyIssues) {
                    $lexid = $tcaseMgr->getSystemWideLastestExecutionID($tcversionId);
                }

                $idCard = ['id' => $tcaseId, 'version_id' => $tcversionId];
                $exco = [
                    'tplan_id' => $context->tplanID,
                    'platform_id' => $context->platformID,
                    'build_id' => $context->buildID,
                ];
                $lexInfo = $tcaseMgr->getLatestExecSingleContext($idCard, $exco, ['output' => 'timestamp']);
                $doInsert = true;
                if (!is_null($lexInfo)) {
                    $tts = $lexInfo[$tcaseId][0]['execution_ts'];
                    $doInsert = ($lexInfo[$tcaseId][0]['execution_ts'] != trim($executionTs, "'"));
                    $msgTxt = $l10n['import_results_skipped'];
                }

                if ($doInsert) {
                    $sql = " /* $debugMsg */ " .
                        " INSERT INTO {$tables['executions']} (build_id,tester_id,status,testplan_id," .
                        " tcversion_id,execution_ts,notes,tcversion_number,platform_id,execution_type" .
                        ($addExecDuration ? ',execution_duration' : '') . ")" .
                        " VALUES ({$context->buildID}, {$testerId},'{$resultCode}',{$context->tplanID}, " .
                        " {$tcversionId},{$executionTs},'{$notes}', {$version}, " .
                        " {$context->platformID}, {$tcaseExec['execution_type']}" .
                        ($addExecDuration ? ",{$tcaseExec['execution_duration']}" : '') . ")";
                    $db->exec_query($sql);
                    $executionId = $db->insert_id($tables['executions']);

                    if ($lexid > 0 && $context->copyIssues) {
                        copyIssues($db, $lexid, $executionId);
                    }

                    if (isset($tcaseExec['steps']) && !is_null($tcaseExec['steps'])
                        && $executionId > 0) {
                        $stepSet = $tcaseMgr->getStepsSimple(
                            $tcversionId, 0,
                            ['fields2get' => 'TCSTEPS.step_number,TCSTEPS.id',
                             'accessKey' => 'step_number']);
                        $sc = count($tcaseExec['steps']);
                        for ($sx = 0; $sx < $sc; $sx++) {
                            $snum = $tcaseExec['steps'][$sx]['step_number'];
                            if (isset($stepSet[$snum])) {
                                $tcstepId = $stepSet[$snum]['id'];
                                $target = DB_TABLE_PREFIX . 'execution_tcsteps';
                                $stepResult = isset($tcaseExec['steps'][$sx]['result'])
                                    ? $tcaseExec['steps'][$sx]['result'] : null;
                                $doItStep = (!is_null($stepResult) && trim($stepResult) !== '')
                                    || $stepResult != ($resultsCfg['status_code']['not_run'] ?? 'n');
                                if ($doItStep) {
                                    $sql = " INSERT INTO {$target} (execution_id,tcstep_id,notes";
                                    $values = " VALUES ( {$executionId},  {$tcstepId} , " .
                                        "'" . $db->prepare_string($tcaseExec['steps'][$sx]['notes'] ?? '') . "'";
                                    $status = strtolower(trim($stepResult));
                                    $status = $status === '' ? '' : $status[0];
                                    $sql .= ",status";
                                    $values .= ",'" . $db->prepare_string($stepResult) . "'";
                                    $sql .= ") " . $values . ")";
                                    $db->exec_query($sql);
                                    $db->insert_id($target);
                                }
                            }
                        }
                    }

                    if (isset($tcaseExec['bug_id']) && !is_null($tcaseExec['bug_id'])
                        && is_array($tcaseExec['bug_id'])) {
                        foreach ($tcaseExec['bug_id'] as $bugId) {
                            $bugId = trim($bugId);
                            $sql = " /* $debugMsg */ " .
                                " SELECT execution_id AS check_qty FROM  {$tables['execution_bugs']} " .
                                " WHERE bug_id = '" . $db->prepare_string($bugId) .
                                "' AND execution_id={$executionId} ";
                            $rs = $db->get_recordset($sql);
                            if (is_null($rs)) {
                                $sql = " /* $debugMsg */ " .
                                    " INSERT INTO {$tables['execution_bugs']} (bug_id,execution_id)" .
                                    " VALUES ('" . $db->prepare_string($bugId) . "', {$executionId} )";
                                $db->exec_query($sql);
                            }
                        }
                    }

                    if (isset($tcaseExec['custom_fields']) && !is_null($tcaseExec['custom_fields'])
                        && is_array($tcaseExec['custom_fields'])) {
                        $cfieldMgr = new cfield_mgr($db);
                        $cfSetByName = $cfieldMgr->get_linked_cfields_at_execution(
                            $context->tprojectID, 1, 'testcase', null, null, null, 'name');
                        foreach ($tcaseExec['custom_fields'] as $cf) {
                            $ak = null;
                            if (isset($cfSetByName[$cf['name']])) {
                                $ak[$cfSetByName[$cf['name']]['id']]['cf_value'] = $cf['value'];
                            } else {
                                $message = sprintf($l10n['invalid_cf'], $tcaseIdentity, $cf['name']);
                            }
                            if (!is_null($ak)) {
                                $cfieldMgr->execution_values_to_db(
                                    $ak, $tcversionId, $executionId, $context->tplanID, null, 'plain');
                            }
                        }
                    }

                    if (!is_null($message)) {
                        $resultMap[] = [$message];
                    }
                    $msgTxt = $l10n['import_results_ok'];
                }
                $message = sprintf($msgTxt, $tcaseIdentity, $version, $testerName,
                                   isset($l10n[$resultCode]) ? $l10n[$resultCode] : $resultCode,
                                   $executionTs);
            }
        }

        if (!is_null($message)) {
            $resultMap[] = [$message];
        }
    }
    return $resultMap;
}
