<?php
/**
 * Test Plan Import BFF API
 * URL: /api/planimport/
 * Plain PHP, no framework, no compilation
 *
 * Mirrors lib/plan/planImport.php (TestLink 1.9.20): allows import in XML
 * format of test plan links to test cases and platforms. Works only if the
 * linked items ALREADY exist on the system (same as the legacy screen).
 *
 * Routes:
 *   GET  ?action=info [&tproject_id=N][&tplan_id=N]
 *                     -> { tproject:{id,name}, tplan:{id,name}, import_limit_bytes,
 *                          import_type }
 *   POST ?action=import (multipart, field "uploadedFile"; X-Requested-With: XMLHttpRequest)
 *                     [&tproject_id=N][&tplan_id=N] + uploaded XML file
 *                     -> { result_map: [[message, status], ...], tplan_name }
 *
 * Permission parity with legacy: lib/plan/planImport.php init_args() requires
 * 'mgt_testplan_create' at test project level (checkGUISecurityClearance).
 *
 * Self-contained: does not depend on api/plans/index.php.
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
require_once(__DIR__ . '/../../lib/functions/xml.inc.php');

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
 * Resolve tplan_id like legacy init_args(): request keys tplan_id / itemID
 * (itemID wins when both set and different). Throws on missing id.
 */
function resolvePlanContext($db, $user) {
    $args = new stdClass();
    $tplan_id = getIntParam('tplan_id');
    $itemID   = getIntParam('itemID');
    if ($tplan_id == 0) { $tplan_id = $itemID; }
    if ($itemID != 0 && $tplan_id != $itemID) { $tplan_id = $itemID; }
    if ($tplan_id == 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Error Test Plan ID']);
    }
    $tplanMgr = new testplan($db);
    $info = $tplanMgr->get_by_id($tplan_id);
    if (is_null($info)) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Test plan not found']);
    }
    $args->tplan_id = (int)$tplan_id;
    $args->tproject_id = (int)$info['testproject_id'];
    $args->tplan_name = $info['name'];
    $args->userID = (int)$user->dbID;

    // Feature access check (same as legacy init_args()):
    // mgt_testplan_create at test project level.
    if (!$user->hasRight($db, 'mgt_testplan_create', $args->tproject_id)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }
    return array($args, $tplanMgr);
}

/**
 * Import test plan links from the uploaded XML file.
 * Byte-for-byte port of legacy importTestPlanLinksFromXML() so the reported
 * messages and the linking behaviour match TestLink 1.9.20 exactly.
 */
function importTestPlanLinksFromXML(&$dbHandler, &$tplanMgr, $targetFile, $contextObj)
{
    $msg = array();
    $labels = $contextObj->labels;
    $tprojectMgr = new testproject($dbHandler);
    $tprojectInfo = $tprojectMgr->get_by_id($contextObj->tproject_id);
    $tcasePrefix = $tprojectInfo['prefix'] . config_get('testcase_cfg')->glue_character;

    $tprojectHasTC = $tprojectMgr->count_testcases($contextObj->tproject_id) > 0;
    if (!$tprojectHasTC) {
        $msg[] = array(sprintf($labels['tproject_has_zero_testcases'], $tprojectInfo['name']), $labels['not_imported']);
        return $msg;
    }

    $xml = @simplexml_load_file($targetFile);
    if ($xml !== FALSE) {
        $tcaseMgr = new testcase($dbHandler);
        $tcaseSet = array();
        $tprojectMgr->get_all_testcases_id($contextObj->tproject_id, $tcaseSet, array('output' => 'external_id'));
        $tcaseSet = array_flip($tcaseSet);

        $status_ok = true;
        if (property_exists($xml, 'platforms')) {
            $platformMgr = new tlPlatform($dbHandler, $contextObj->tproject_id);
            $platformUniverse = $platformMgr->getAllAsMap();
            if (is_null($platformUniverse)) {
                $status_ok = false;
                $msg[] = array($labels['no_platforms_on_tproject'], $labels['not_imported']);
            } else {
                $platformUniverse = array_flip($platformUniverse);
                $op = processPlatformsBff($platformMgr, $tplanMgr, $platformUniverse, $xml->platforms,
                             $labels, $contextObj->tplan_id);
                $status_ok = $op['status_ok'];
                $msg = $op['msg'];
            }
        }

        if ($status_ok && $xml->xpath('//executables')) {
            $tables = tlObjectWithDB::getDBTables(array('testplan_tcversions'));
            $platformSet = (array)$tplanMgr->getPlatforms($contextObj->tplan_id, array('outputFormat' => 'mapAccessByName'));
            $targetHasPlatforms = (count($platformSet) > 0);

            $xmlLinks = $xml->executables->children();
            $loops2do = count($xmlLinks);

            $tplanDesignCfg = config_get('tplanDesign');

            for ($idx = 0; $idx < $loops2do; $idx++) {
                $targetName = null;
                $platformID = -1;
                $linkWithPlatform = false;
                $status_ok = false;
                $dummy_msg = null;
                $import_status = $labels['ok'];

                if (($platformElementExists = property_exists($xmlLinks[$idx], 'platform'))) {
                    $targetName = trim((string)$xmlLinks[$idx]->platform->name);
                    $linkWithPlatform = ($targetName != '');
                }

                if ($targetHasPlatforms) {
                    if ($linkWithPlatform && isset($platformSet[$targetName])) {
                        $platformID = $platformSet[$targetName]['id'];
                        $status_ok = true;
                        $dummy_msg = null;
                    } else {
                        $import_status = $labels['not_imported'];
                        if (!$platformElementExists) {
                            $dummy_msg = sprintf($labels['link_without_platform_element'], $idx + 1);
                        } else if (!$linkWithPlatform) {
                            $dummy_msg = sprintf($labels['link_without_required_platform'], $idx + 1);
                        } else {
                            $dummy_msg = sprintf($labels['platform_not_linked'], $idx + 1, $targetName, $contextObj->tplan_name);
                        }
                    }
                } else {
                    if ($linkWithPlatform) {
                        $import_status = $labels['not_imported'];
                        $dummy_msg = sprintf($labels['link_with_platform_not_needed'], $idx + 1);
                    } else {
                        $platformID = 0;
                        $status_ok = true;
                    }
                }
                if (!is_null($dummy_msg)) {
                    $msg[] = array($dummy_msg, $import_status);
                }

                if ($status_ok) {
                    $createLink = false;
                    $updateLink = false;

                    $externalID = (int)$xmlLinks[$idx]->testcase->externalid;
                    $tcaseName = (string)$xmlLinks[$idx]->testcase->name;
                    $execOrder = (int)$xmlLinks[$idx]->testcase->execution_order;
                    $version = (int)$xmlLinks[$idx]->testcase->version;

                    if (isset($tcaseSet[$externalID])) {
                        $dummy = $tcaseMgr->get_basic_info($tcaseSet[$externalID],
                                                           array('number' => $version));
                        if (count($dummy) > 0) {
                            $lvFilters = array('tplan_id' => $contextObj->tplan_id);
                            $linkedVersions = $tcaseMgr->get_linked_versions($dummy[0]['id'], $lvFilters);
                            $updateLink = false;
                            $doUpdateFeedBack = true;

                            if (!($createLink = is_null($linkedVersions))) {
                                if (!isset($linkedVersions[$dummy[0]['tcversion_id']])) {
                                    $createLink = !isset($tplanDesignCfg->hideTestCaseWithStatusIn[$dummy[0]['status']]);
                                    if ($createLink == FALSE) {
                                        $rogue = 'testCaseStatus_' .
                                                 $tplanDesignCfg->hideTestCaseWithStatusIn[$dummy[0]['status']];
                                        $dummy_msg = sprintf($labels['cant_link_to_tplan_feedback'], $externalID, $version);
                                        $msg[] = array($dummy_msg,
                                                       sprintf($labels['tcversion_status_forbidden'], lang_get($rogue)));
                                    }
                                } else {
                                    $createLink = false;
                                    $updateLink = false;
                                    $plat_keys = array_keys($linkedVersions[$dummy[0]['tcversion_id']][$contextObj->tplan_id]);
                                    $plat_keys = array_flip($plat_keys);

                                    if (isset($plat_keys[$platformID])) {
                                        $updateLink = true;
                                    } else if ($platformID == 0) {
                                        $msg[] = array('platform 0 missing messages', $labels['not_imported']);
                                    } else {
                                        $createLink = true;
                                    }
                                }
                            }

                            if ($createLink) {
                                $createLink = !isset($tplanDesignCfg->hideTestCaseWithStatusIn[$dummy[0]['status']]);
                                if ($createLink == FALSE) {
                                    $rogue = 'testCaseStatus_' .
                                             $tplanDesignCfg->hideTestCaseWithStatusIn[$dummy[0]['status']];
                                    $dummy_msg = sprintf($labels['cant_link_to_tplan_feedback'], $externalID, $version);
                                    $msg[] = array($dummy_msg,
                                                   sprintf($labels['tcversion_status_forbidden'], lang_get($rogue)));
                                }
                            }

                            if ($createLink) {
                                $item2link['items'] = array($dummy[0]['id'] => array($platformID => $dummy[0]['tcversion_id']));
                                $item2link['tcversion'] = array($dummy[0]['id'] => $dummy[0]['tcversion_id']);
                                $tplanMgr->link_tcversions($contextObj->tplan_id, $item2link, $contextObj->userID);
                                $dummy_msg = sprintf($labels['link_to_tplan_feedback'], $externalID, $version);
                                if ($platformID > 0) {
                                    $dummy_msg .= sprintf($labels['link_to_platform'], $targetName);
                                }
                                $msg[] = array($dummy_msg, $labels['ok']);

                                $updateLink = true;
                                $doUpdateFeedBack = false;
                            }

                            if ($updateLink) {
                                $newOrder = array($dummy[0]['tcversion_id'] => $execOrder);
                                $tplanMgr->setExecutionOrder($contextObj->tplan_id, $newOrder);
                                if ($doUpdateFeedBack) {
                                    $dummy_msg = sprintf($labels['tcase_link_updated'], $tcasePrefix . $externalID . ' ' .
                                            $tcaseName, $version);
                                    $msg[] = array($dummy_msg, $labels['ok']);
                                }
                            }
                        } else {
                            $msg[] = array(sprintf($labels['tcversion_doesnot_exist'], $externalID, $version, $tprojectInfo['name']));
                        }
                    } else {
                        $msg[] = array(sprintf($labels['tcase_doesnot_exist'], $externalID, $tprojectInfo['name']));
                    }
                }
            }
        } else {
            if ($status_ok) {
                // Legacy parity: when the XML has no <executables> section
                // nothing is reported (planImport.php silently skips).
            }
        }
    } else {
        $msg[] = array(lang_get('simplexml_load_file_wrapper_error'), $labels['not_imported']);
    }
    return $msg;
}

/**
 * Port of legacy processPlatforms(): link each declared platform to the test
 * plan if it exists on the test project and is not already linked.
 */
function processPlatformsBff(&$platMgr, &$tplanMgr, $universe, $xmlSubset, $lbl, $tplanID)
{
    $ret = array('status_ok' => true, 'msg' => null);
    $children = $xmlSubset->children();
    $msg_ok = array();
    $loops2do = count($children);
    $status_ok = true;
    $idSet = null;
    for ($idx = 0; $idx < $loops2do; $idx++) {
        $targetName = trim((string)$children[$idx]->name);
        if (isset($universe[$targetName])) {
            $status_ok = $status_ok && true;
            $idSet[$universe[$targetName]] = $targetName;
        } else {
            $status_ok = false;
            $ret['msg'][] = array(sprintf($lbl['platform_not_on_tproject'], $targetName), $lbl['not_imported']);
        }
    }
    if ($status_ok && !is_null($idSet)) {
        $currentPlatformSet = $tplanMgr->getPlatforms($tplanID, array('outputFormat' => 'mapAccessByID'));
        foreach ($idSet as $platformID => $platformName) {
            if (!isset($currentPlatformSet[$platformID])) {
                $platMgr->linkToTestplan($platformID, $tplanID);
                $msg_ok[] = array(sprintf($lbl['platform_linked'], $platformName), $lbl['ok']);
            }
        }
        $ret['msg'] = $msg_ok;
    }
    return $ret;
}

// ---------------------------------------------------------------------------
// Route: actions
// ---------------------------------------------------------------------------
$action = isset($_GET['action']) ? $_GET['action'] : 'info';
$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : $action;

$labelKeys = array(
    'link_without_required_platform', 'ok', 'link_without_platform_element',
    'no_platforms_on_tproject', 'tcase_link_updated', 'link_with_platform_not_needed',
    'tproject_has_zero_testcases', 'platform_not_on_tproject', 'platform_linked',
    'platform_not_linked', 'tcase_doesnot_exist', 'tcversion_doesnot_exist',
    'not_imported', 'link_to_tplan_feedback', 'link_to_platform',
    'tcversion_status_forbidden', 'cant_link_to_tplan_feedback'
);

if ($method_check = true) { } // no-op, kept for clarity

if (($method = $_SERVER['REQUEST_METHOD']) === 'GET' && $action === 'info') {
    list($args, $tplanMgr) = resolvePlanContext($db, $user);
    $importLimitBytes = (int)config_get('import_file_max_size_bytes');

    $tprojectMgr = new testproject($db);
    $tprojectInfo = $tprojectMgr->get_by_id($args->tproject_id);
    $tprojectHasTC = $tprojectMgr->count_testcases($args->tproject_id) > 0;

    $resultMap = null;
    if (!$tprojectHasTC) {
        $resultMap = array(array('', sprintf(lang_get('tproject_has_zero_testcases'), $tprojectInfo['name'])));
    }

    out(array(
        'status' => 'ok',
        'tproject' => array('id' => $args->tproject_id, 'name' => $tprojectInfo['name']),
        'tplan' => array('id' => $args->tplan_id, 'name' => $args->tplan_name),
        'import_limit_bytes' => $importLimitBytes,
        'import_limit_kb' => (int)($importLimitBytes / 1024),
        'import_type' => 'XML',
        'result_map' => $resultMap,
    ));
}

if ($method === 'POST' && $action === 'import') {
    list($args, $tplanMgr) = resolvePlanContext($db, $user);

    $source = isset($_FILES['uploadedFile']['tmp_name']) ? $_FILES['uploadedFile']['tmp_name'] : null;
    $uploadErr = isset($_FILES['uploadedFile']['error']) ? $_FILES['uploadedFile']['error'] : UPLOAD_ERR_NO_FILE;
    $importLimitBytes = (int)config_get('import_file_max_size_bytes');
    $resultMap = null;
    $fileCheck = array('status_ok' => 1, 'msg' => 'ok');

    if ($uploadErr !== UPLOAD_ERR_OK || is_null($source) || $source === 'none' || $source === '') {
        out(array('status' => 'error', 'message' => lang_get('please_choose_file_to_import')));
    }

    if (isset($_FILES['uploadedFile']['size']) && $_FILES['uploadedFile']['size'] > $importLimitBytes) {
        $size = $_FILES['uploadedFile']['size'];
        http_response_code(413);
        out(array('status' => 'error',
                  'message' => sprintf(lang_get('file_size_exceeded'), $size, $importLimitBytes)));
    }

    $dest_common = TL_TEMP_PATH . session_id() . '-planImport';
    $input_file = $dest_common . '.xml';
    if (!move_uploaded_file($source, $input_file)) {
        http_response_code(500);
        out(array('status' => 'error', 'message' => 'Upload failed'));
    }

    $context = new stdClass();
    $context->tproject_id = $args->tproject_id;
    $context->tplan_id = $args->tplan_id;
    $context->tplan_name = $args->tplan_name;
    $context->userID = $args->userID;
    $context->labels = array();
    foreach ($labelKeys as $k) {
        $context->labels[$k] = lang_get($k);
    }

    $resultMap = importTestPlanLinksFromXML($db, $tplanMgr, $input_file, $context);
    @unlink($input_file);

    out(array(
        'status' => 'ok',
        'result_map' => is_null($resultMap) ? array() : array_values($resultMap),
        'tplan_name' => $args->tplan_name,
    ));
}

http_response_code(404);
out(array('status' => 'error', 'message' => 'Unknown action'));