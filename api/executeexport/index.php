<?php
/**
 * Execution Export BFF API
 * URL: /api/executeexport/
 * Plain PHP, no framework, JSON I/O (POST export streams the XML download).
 *
 * Mirrors lib/execute/execExport.php (TestLink 1.9.20): exports the set of
 * test-case versions displayed on the bulk-execution window ("Export All Test
 * Cases in Test Suite", BUGID 3421) as a TestLink XML document.
 *
 * XML shape (byte-identical with the legacy download):
 *   <?xml ...?> (TL_XMLEXPORT_HEADER)
 *   <executionSet>
 *     <context>  (testproject/testplan/build + optional platform names/ids,
 *                 project prefix) built with the legacy exportDataToXML()
 *     <testcases> one <testcase ...> per tcversion via the legacy
 *                 testcase::exportTestCaseDataToXML(0,id,tproject,true)
 *   </executionSet>
 *
 * Routes:
 *   GET  ?action=info&tplan_id=N&build_id=N&platform_id=N&tsuite_id=N
 *                     &tcversionSet=1,2,3  -> JSON context + grants
 *   POST ?action=export (X-Requested-With: XMLHttpRequest)
 *                     [&export_filename=NAME]
 *                     -> streams the file download (application/xml)
 *
 * Rights parity + hardening: the legacy controller had no explicit check
 * beyond authentication. Following the other execution-area BFF screens
 * (execSetResults / executionedit / executionprint), the routes are gated on
 * testplan_execute OR exec_ro_access on the OWNING test project (resolved
 * from the test plan), so an authenticated user with no execution rights gets
 * 403 instead of being able to dump the whole set.
 *
 * Self-contained: does not depend on api/execute or api/execsetresults.
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
    out(['status' => 'error', 'message' => 'Not authenticated']);
}

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    http_response_code(401);
    out(['status' => 'error', 'message' => 'User not found']);
}

function out($data) { echo json_encode($data); exit; }

function getIntParam($key, $default = 0) {
    $v = $_REQUEST[$key] ?? $default;
    return is_numeric($v) ? intval($v) : $default;
}

function getStrParam($key, $default = '') {
    $v = $_REQUEST[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

/**
 * Comma-separated tcversion ids for the export set.
 * Never more than 4096 ids / 24 KB to keep the request sane.
 */
function sanitizeTcversionSet($value) {
    if (!is_string($value) || trim($value) === '') {
        return array();
    }
    $ids = array();
    foreach (explode(',', $value) as $part) {
        $part = trim($part);
        if ($part !== '' && is_numeric($part)) {
            $ids[] = intval($part);
        }
    }
    return array_slice(array_unique($ids), 0, 4096);
}

/**
 * Resolve the owning test project of a test plan and gate on the execution
 * rights used by the rest of the Execution area.
 * Returns the testproject id on success, exits with JSON error otherwise.
 */
function resolveAndCheck($db, $user, $tplanMgr, $tplanId) {
    if ($tplanId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test plan id']);
    }
    $tplan = $tplanMgr->get_by_id($tplanId);
    if (is_null($tplan) || empty($tplan)) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Test plan not found']);
    }
    $tprojectId = intval($tplan['testproject_id']);
    if ($tprojectId <= 0) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Test plan has no owning test project']);
    }
    $canWrite = $user->hasRight($db, 'testplan_execute', $tprojectId);
    $canRo    = $user->hasRight($db, 'exec_ro_access', $tprojectId);
    if (!$canWrite && !$canRo) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }
    return $tprojectId;
}

/**
 * Legacy contextAsXML() port: build <context> from testproject/testplan/build
 * (+ optional platform) names/ids + prefix via exportDataToXML().
 * Returns the inner XML snippet (no XML header; the outer header is added by
 * contentAsXML()).
 */
function contextAsXMLBFF($db, $tprojectId, $tplanId, $platformId, $buildId) {
    $contextInfo = array();

    $tprojectMgr = new testproject($db);
    $tproject = $tprojectMgr->get_by_id($tprojectId);
    if (is_null($tproject) || empty($tproject)) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Test project not found']);
    }
    $contextInfo['tproject_id'] = intval($tproject['id']);
    $contextInfo['tproject_name'] = (string)$tproject['name'];
    $contextInfo['prefix'] = (string)($tproject['prefix'] ?? '');

    $tplanMgr = new testplan($db);
    $tplan = $tplanMgr->get_by_id($tplanId);
    if (is_null($tplan) || empty($tplan)) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Test plan not found']);
    }
    $contextInfo['tplan_id'] = intval($tplan['id']);
    $contextInfo['tplan_name'] = (string)$tplan['name'];

    $contextInfo['build_id'] = 0;
    $contextInfo['build_name'] = '';
    if ($buildId > 0) {
        $buildMgr = new build($db);
        $build = $buildMgr->get_by_id($buildId);
        if (!is_null($build) && !empty($build) && isset($build['name'])) {
            $contextInfo['build_id'] = intval($build['id']);
            $contextInfo['build_name'] = (string)$build['name'];
        }
    }

    $contextInfo['platform_id'] = 0;
    $contextInfo['platform_name'] = '';
    if ($platformId > 0) {
        $platformMgr = new tlPlatform($db, $tprojectId);
        $platform = $platformMgr->getByID($platformId);
        if (!is_null($platform) && !empty($platform) && isset($platform['name'])) {
            $contextInfo['platform_id'] = intval($platform['id']);
            $contextInfo['platform_name'] = (string)$platform['name'];
        }
    }

    $xml_root = "<context>{{XMLCODE}}\n</context>";
    $platform_template = '';
    if ($contextInfo['platform_id'] > 0) {
        $platform_template = "\n\t" .
            "<platform>" .
            "\t\t" . "<name><![CDATA[||PLATFORMNAME||]]></name>" .
            "\t\t" . "<internal_id><![CDATA[||PLATFORMID||]]></internal_id>" .
            "\n\t" . "</platform>";
    }
    $xml_template = "\n\t" .
        "<testproject>" .
        "\t\t" . "<name><![CDATA[||TPROJECTNAME||]]></name>" .
        "\t\t" . "<internal_id><![CDATA[||TPROJECTID||]]></internal_id>" .
        "\t\t" . "<prefix><![CDATA[||TPROJECTPREFIX||]]></prefix>" .
        "\n\t" . "</testproject>" .
        "\n\t" .
        "<testplan>" .
        "\t\t" . "<name><![CDATA[||TPLANNAME||]]></name>" .
        "\t\t" . "<internal_id><![CDATA[||TPLANID||]]></internal_id>" .
        "\n\t" . "</testplan>" . $platform_template .
        "\n\t" .
        "<build>" .
        "\t\t" . "<name><![CDATA[||BUILDNAME||]]></name>" .
        "\t\t" . "<internal_id><![CDATA[||BUILDID||]]></internal_id>" .
        "\n\t" . "</build>";

    $xml_mapping = array(
        "||TPROJECTNAME||" => "tproject_name", "||TPROJECTID||" => 'tproject_id',
        "||TPROJECTPREFIX||" => "prefix",
        "||TPLANNAME||" => "tplan_name", "||TPLANID||" => 'tplan_id',
        "||BUILDNAME||" => "build_name", "||BUILDID||" => 'build_id',
        "||PLATFORMNAME||" => "platform_name", "||PLATFORMID||" => 'platform_id',
    );

    // Legacy call: exportDataToXML([$contextInfo], root, template, mapping, true)
    return exportDataToXML(array($contextInfo), $xml_root, $xml_template,
        $xml_mapping, true);
}

/**
 * Legacy tcaseSetAsXML() port: one <testcase ...> per tcversion in the set
 * via the legacy testcase::exportTestCaseDataToXML(0,id,tproject,true)
 * (execution-order / users / requirements are not part of the legacy fields).
 */
function tcaseSetAsXMLBFF($db, $tprojectId, array $tcversionIds) {
    $tcaseMgr = new testcase($db);
    $xmlTC = "<testcases>\n\t";
    foreach ($tcversionIds as $tcversion_id) {
        $xmlTC .= $tcaseMgr->exportTestCaseDataToXML(0, $tcversion_id,
            $tprojectId, true);
    }
    $xmlTC .= "</testcases>\n\t";
    return $xmlTC;
}

/**
 * Legacy contentAsXML() port: assemble the whole document with the header.
 */
function contentAsXMLBFF($db, $tprojectId, $tplanId, $platformId, $buildId,
    array $tcversionIds) {
    $context = contextAsXMLBFF($db, $tprojectId, $tplanId, $platformId, $buildId);
    $tcaseSet = tcaseSetAsXMLBFF($db, $tprojectId, $tcversionIds);
    return TL_XMLEXPORT_HEADER .
        "\n<executionSet>\n\t{$context}\n\t{$tcaseSet}\n\t</executionSet>";
}

// ---------------------------------------------------------------------------
// GET ?action=info - export form context
// ---------------------------------------------------------------------------
if (($_GET['action'] ?? '') === 'info') {
    $tplanId = getIntParam('tplan_id');
    $tprojectMgr = new testproject($db);
    $tplanMgr = new testplan($db);

    $tprojectId = resolveAndCheck($db, $user, $tplanMgr, $tplanId);

    $tplan = $tplanMgr->get_by_id($tplanId);
    $tprojectName = (string)$tprojectMgr->getName($db, $tprojectId);

    $buildId = getIntParam('build_id');
    $buildName = '';
    if ($buildId > 0) {
        $buildMgr = new build($db);
        $build = $buildMgr->get_by_id($buildId);
        if (!is_null($build) && !empty($build) && isset($build['name'])) {
            $buildName = (string)$build['name'];
        } else {
            $buildId = 0;
        }
    }

    $platformId = getIntParam('platform_id');
    $platformName = '';
    if ($platformId > 0) {
        $platformMgr = new tlPlatform($db, $tprojectId);
        $platform = $platformMgr->getByID($platformId);
        if (!is_null($platform) && !empty($platform) && isset($platform['name'])) {
            $platformName = (string)$platform['name'];
        } else {
            $platformId = 0;
        }
    }

    $tcversionIds = sanitizeTcversionSet($_GET['tcversionSet'] ?? null);

    out(array(
        'status' => 'ok',
        'tplan' => array(
            'id' => (int)$tplanId,
            'name' => (string)$tplan['name'],
        ),
        'tproject' => array(
            'id' => $tprojectId,
            'name' => $tprojectName,
        ),
        'platform' => array('id' => $platformId, 'name' => $platformName),
        'build' => array('id' => $buildId, 'name' => $buildName),
        'tsuite_id' => getIntParam('tsuite_id'),
        'tc_count' => count($tcversionIds),
        'default_filename' => 'export_execution_set.xml',
        'types' => array('XML' => 'XML'),
        'rights' => array(
            'testplan_execute' => $user->hasRight($db, 'testplan_execute', $tprojectId) ? 1 : 0,
            'exec_ro_access' => $user->hasRight($db, 'exec_ro_access', $tprojectId) ? 1 : 0,
        ),
    ));
}

// ---------------------------------------------------------------------------
// POST ?action=export - stream the file download
// ---------------------------------------------------------------------------
if (($_POST['action'] ?? '') === 'export') {
    $tplanId = getIntParam('tplan_id');
    $tplanMgr = new testplan($db);

    $tprojectId = resolveAndCheck($db, $user, $tplanMgr, $tplanId);

    $tcversionIds = sanitizeTcversionSet($_POST['tcversionSet'] ?? null);
    if (count($tcversionIds) === 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'No test case versions to export']);
    }

    $buildId = getIntParam('build_id');
    $platformId = getIntParam('platform_id');

    $content = contentAsXMLBFF($db, $tprojectId, $tplanId, $platformId,
        $buildId, $tcversionIds);

    $requestedName = getStrParam('export_filename');
    if ($requestedName === '') {
        $requestedName = 'export_execution_set.xml';
    }
    $requestedName = str_replace(' ', '_', $requestedName);
    $headerFilename = str_replace(["\r", "\n", '"', '/', '\\'], '', basename($requestedName));
    if ($headerFilename === '') {
        $headerFilename = 'export_execution_set.xml';
    }

    header_remove('Content-Type');
    header('Pragma: public');
    header('Content-Type: application/xml; charset=' . config_get('charset')
        . '; name=' . $headerFilename);
    header('Content-Disposition: attachment; filename="' . $headerFilename . '"');
    header('Cache-Control: must-revalidate');
    echo $content;
    exit;
}

http_response_code(404);
out(['status' => 'error', 'message' => 'Not found']);