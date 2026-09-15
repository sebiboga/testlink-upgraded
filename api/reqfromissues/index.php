<?php
/**
 * Create Requirements from Issues XML (Mantis) BFF API — Refs #1503
 * URL: /api/reqfromissues/?action=init   (GET)  — context for the screen
 *      /api/reqfromissues/?action=import  (POST) — multipart Mantis XML
 *
 * Modernizes the standalone legacy import screen lib/requirements/
 * reqCreateFromIssueMantisXML.php (+ reqCreateFromIssueMantisXML.tpl),
 * reachable from the legacy reqSpecView 'Requirement Operations' fieldset
 * ("Create From Issues (XML)", btn_create_from_issue_xml) as
 * reqCreateFromIssueMantisXML.php?scope=branch&req_spec_id=<id> — the
 * REQUIREMENTS sibling of #1502 (tcCreateFromIssueMantisXML). Legacy parity:
 *   - permission: mgt_view_req AND mgt_modify_req on the owning test project
 *     (403 otherwise), mirroring checkRights()
 *   - upload cap: config import_file_max_size_bytes
 *   - input format: a Mantis bug-tracker XML export rooted at <mantis>,
 *     one <issue> per bug
 *   - one requirement per issue created directly under the target req. spec:
 *       docid  = 'Mantis Task ID:<id>'
 *       title  = 'Issue:<id> - <summary>'   (issue_issue label)
 *       description = issue_description: <description> (+ steps_to_reproduce,
 *                     + additional_information), all joined with <p>
 *       node_order = document order, status='' type='' expected_coverage=1
 *   - duplicate handling stays inside requirement_mgr::createFromMap()
 *     (hit criteria docid; same-spec hit / other-branch hit messages;
 *     field_size req_title/req_docid over-length skip) — the exact legacy
 *     result rows, i18n'd via the client locale hint (legacy: session locale)
 *
 * JSON contract: status ok|error, req_spec context, result rows
 * [{doc_id, name, message}], 200/400/401/403/404/405/413/422/500.
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
require_once(__DIR__ . '/../_guard.php');
// requirement_mgr::create() calls req_link_replace() when internal_links is
// enabled (the shipped default) — every other requirements controller requires
// this file; the legacy reqCreateFromIssueMantisXML.php forgot it and would
// fatal with "Call to undefined function req_link_replace()" (pre-existing).
require_once(__DIR__ . '/../../lib/functions/requirements.inc.php');

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

function rfi_out($data) { echo json_encode($data); exit; }

function rfi_intParam($key, $default = 0) {
    $v = $_REQUEST[$key] ?? $default;
    return is_numeric($v) ? intval($v) : $default;
}

/**
 * Resolve the client locale hint (2-char short code like the TLi18n switcher
 * produces, or a full xx_YY flag) against the configured TestLink locales so
 * server-side labels come back in the language the user picked. Mirrors
 * api/cfields assignLocale() / api/tccreatefromissues tcfi_locale().
 */
function rfi_locale() {
    $hint = trim(strval($_REQUEST['locale'] ?? ''));
    if ($hint === '') {
        return null;
    }
    $codes = (array) config_get('locales');
    if (preg_match('/^[a-z]{2}_[A-Z]{2}$/', $hint)) {
        return (isset($codes[$hint])) ? $hint : null;
    }
    $short = preg_replace('/[^a-z]/', '', strtolower($hint));
    if ($short === '' || strlen($short) !== 2) {
        return null;
    }
    foreach (array_keys($codes) as $code) {
        if (strpos(strtolower($code), $short) === 0) {
            return $code;
        }
    }
    return null;
}

/**
 * Resolve a requirement-spec node: name, node path (legacy tree
 * get_node_hierarchy_info) and the owning test project (root walk).
 * Returns null when the node is not a requirement_spec / does not exist.
 */
function rfi_resolveSpec($db, $specId) {
    $tables = tlObjectWithDB::getDBTables(['nodes_hierarchy', 'node_types']);
    $specId = intval($specId);
    $row = $db->get_recordset(
        "SELECT nh.id, nh.parent_id, nh.name, nh.node_type_id " .
        "FROM {$tables['nodes_hierarchy']} nh " .
        "WHERE nh.id = " . intval($specId));
    if (is_null($row) || count($row) !== 1) {
        return null;
    }
    $node = $row[0];
    if (intval($node['node_type_id']) !== 6) { // 6 = requirement_spec
        return null;
    }
    $pathNames = [strval($node['name'])];
    $cur = intval($node['parent_id']);
    $tprojectId = 0;
    $visited = [];
    for ($hops = 0; $hops < 64; $hops++) {
        if (isset($visited[$cur])) { break; }
        $visited[$cur] = true;
        $prow = $db->get_recordset(
            "SELECT id, parent_id, name, node_type_id " .
            "FROM {$tables['nodes_hierarchy']} " .
            "WHERE id = " . intval($cur));
        if (is_null($prow) || count($prow) !== 1) {
            break;
        }
        if (intval($prow[0]['node_type_id']) === 1) { // 1 = testproject
            $tprojectId = intval($prow[0]['id']);
            array_unshift($pathNames, strval($prow[0]['name']));
            break;
        }
        array_unshift($pathNames, strval($prow[0]['name']));
        $cur = intval($prow[0]['parent_id']);
    }
    return [
        'id' => $specId,
        'name' => strval($node['name']),
        'path' => implode(' / ', $pathNames),
        'tproject_id' => $tprojectId,
    ];
}

/**
 * Turn a parsed <mantis> SimpleXML document into the flat requirement-item set
 * the legacy getFromMantisIssueSimpleXMLObj() produced: one item per <issue>.
 * Returns null when nothing was extracted (or no issues present).
 */
function rfi_issuesToReqSet($xml, $labels) {
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

        $set[$jdx] = [
            'docid' => 'Mantis Task ID:' . $id,
            'title' => $l['issue_issue'] . ':' . $id . ' - ' . $summary,
            'description' => $isum,
            'node_order' => $jdx,
            'status' => '',
            'type' => '',
            'expected_coverage' => 1,
        ];
        $jdx++;
    }
    return count($set) > 0 ? $set : null;
}

$action = $_GET['action'] ?? '';
$reqMgr = new requirement_mgr($db);
$tprojectMgr = new testproject($db);

// ---------------------------------------------------------------------------
// GET ?action=init&req_spec_id=N[&tproject_id=N][&locale=xx_YY]
// Context for the modernized screen: spec name + path, owning project, size
// limit, grant map, issue field labels localized via the client locale hint.
// ---------------------------------------------------------------------------
if ($action === 'init') {
    $specId = rfi_intParam('req_spec_id', 0);
    if ($specId <= 0) {
        $specId = rfi_intParam('spec_id', 0);
    }
    if ($specId <= 0) {
        http_response_code(400);
        rfi_out(['status' => 'error', 'message' => 'Invalid requirement spec id']);
    }
    $spec = rfi_resolveSpec($db, $specId);
    if (is_null($spec)) {
        http_response_code(404);
        rfi_out(['status' => 'error', 'message' => 'Requirement spec not found']);
    }
    $tprojectId = intval($spec['tproject_id']);
    $info = $tprojectMgr->get_by_id($tprojectId);
    if (!$info) {
        http_response_code(404);
        rfi_out(['status' => 'error', 'message' => 'Test project not found']);
    }

    $viewReq = $user->hasRight($db, 'mgt_view_req', $tprojectId) ? 1 : 0;
    $modifyReq = $user->hasRight($db, 'mgt_modify_req', $tprojectId) ? 1 : 0;
    if (!($viewReq && $modifyReq)) {
        http_response_code(403);
        rfi_out(['status' => 'error', 'message' => 'No permission']);
    }

    $loc = rfi_locale();
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
    } else {
        unset($_SESSION['locale']);
    }

    $fieldSize = config_get('field_size');
    rfi_out([
        'status' => 'ok',
        'tproject' => [
            'id' => $tprojectId,
            'name' => strval($info['name']),
        ],
        'req_spec' => [
            'id' => $spec['id'],
            'name' => $spec['name'],
            'path' => $spec['path'],
        ],
        'maxUploadBytes' => intval(config_get('import_file_max_size_bytes')),
        'fieldSize' => [
            'req_title' => (is_object($fieldSize) && isset($fieldSize->req_title))
                ? intval($fieldSize->req_title) : 100,
            'req_docid' => (is_object($fieldSize) && isset($fieldSize->req_docid))
                ? intval($fieldSize->req_docid) : 100,
        ],
        'grants' => [
            'mgt_view_req' => $viewReq,
            'mgt_modify_req' => $modifyReq,
        ],
        'labels' => $labels,
    ]);
}

// ---------------------------------------------------------------------------
// POST ?action=import&req_spec_id=N[&locale=xx_YY]
// Body: multipart file field "uploadedFile" (Mantis XML export)
// ---------------------------------------------------------------------------
if ($action === 'import') {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        rfi_out(['status' => 'error', 'message' => 'Use POST']);
    }

    $specId = rfi_intParam('req_spec_id', 0);
    if ($specId <= 0) {
        $specId = rfi_intParam('spec_id', 0);
    }
    if ($specId <= 0) {
        http_response_code(400);
        rfi_out(['status' => 'error', 'message' => 'Invalid requirement spec id']);
    }
    $spec = rfi_resolveSpec($db, $specId);
    if (is_null($spec)) {
        http_response_code(404);
        rfi_out(['status' => 'error', 'message' => 'Requirement spec not found']);
    }
    $tprojectId = intval($spec['tproject_id']);
    $info = $tprojectMgr->get_by_id($tprojectId);
    if (!$info) {
        http_response_code(404);
        rfi_out(['status' => 'error', 'message' => 'Test project not found']);
    }
    $viewReq = $user->hasRight($db, 'mgt_view_req', $tprojectId) ? 1 : 0;
    $modifyReq = $user->hasRight($db, 'mgt_modify_req', $tprojectId) ? 1 : 0;
    if (!($viewReq && $modifyReq)) {
        http_response_code(403);
        rfi_out(['status' => 'error', 'message' => 'No permission']);
    }

    if (!isset($_FILES['uploadedFile']) || $_FILES['uploadedFile']['error'] !== UPLOAD_ERR_OK) {
        $errCode = isset($_FILES['uploadedFile']) ? intval($_FILES['uploadedFile']['error']) : -1;
        http_response_code(400);
        rfi_out(['status' => 'error', 'message' => 'File upload failed (error code: ' . $errCode . ')']);
    }

    $maxBytes = intval(config_get('import_file_max_size_bytes'));
    if ($maxBytes <= 0) {
        $maxBytes = 10 * 1024 * 1024;
    }
    $uploadedBytes = intval($_FILES['uploadedFile']['size']);
    if ($uploadedBytes > $maxBytes) {
        http_response_code(413);
        rfi_out([
            'status' => 'error',
            'message' => sprintf('File too large (%d bytes); limit is %d bytes',
                                 $uploadedBytes, $maxBytes),
        ]);
    }

    // ---- safe XML parse (LIBXML_NONET, no die()) ---------------------------
    libxml_use_internal_errors(true);
    $rawXml = @file_get_contents($_FILES['uploadedFile']['tmp_name']);
    $xml = @simplexml_load_string($rawXml, 'SimpleXMLElement', LIBXML_NONET);
    libxml_clear_errors();
    if ($xml === false || $rawXml === false || $xml->getName() !== 'mantis') {
        http_response_code(422);
        rfi_out(['status' => 'error',
                  'message' => 'Invalid XML file: root element must be <mantis>']);
    }

    // localize the issue field labels + createFromMap messages via the client
    // locale hint (legacy used the session locale) — then restore so we do not
    // mutate the session.
    $loc = rfi_locale();
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
    } else {
        unset($_SESSION['locale']);
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

    $reqSet = rfi_issuesToReqSet($xml, $labels);

    $resultMap = [];
    try {
        if (!is_null($reqSet)) {
            if ($loc !== null) {
                $_SESSION['locale'] = $loc;
            }
            foreach ($reqSet as $item) {
                $feedbackRows = $reqMgr->createFromMap(
                    $item, $tprojectId, $specId, intval($userId));
                foreach ((array) $feedbackRows as $fb) {
                    if (is_array($fb)) {
                        $resultMap[] = [
                            'doc_id' => strval($fb['doc_id'] ?? ''),
                            'name' => strval($fb['title'] ?? ''),
                            'message' => strval($fb['import_status'] ?? ''),
                        ];
                    }
                }
            }
            if ($prevLocale !== null) {
                $_SESSION['locale'] = $prevLocale;
            } else {
                unset($_SESSION['locale']);
            }
        }
    } catch (\Throwable $e) {
        $cleanExit = true;
        http_response_code(500);
        rfi_out(['status' => 'error', 'message' => 'Import failed: ' . $e->getMessage()]);
    }
    $cleanExit = true;

    rfi_out([
        'status' => 'ok',
        'tproject' => ['id' => $tprojectId, 'name' => strval($info['name'])],
        'req_spec' => [
            'id' => $spec['id'],
            'name' => $spec['name'],
        ],
        'issues' => is_null($reqSet) ? 0 : count($reqSet),
        'result' => $resultMap,
    ]);
}

http_response_code(400);
rfi_out(['status' => 'error', 'message' => 'Bad request']);