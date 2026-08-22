<?php
/**
 * Requirements BFF API - Print Requirements Spec document navigator
 * URL: /api/requirements/
 * Plain PHP, no framework, no compilation
 *
 * Mirrors lib/results/printDocOptions.php?type=reqspec (TestLink 1.9.20
 * "Print Requirement Specification" screen):
 *  - returns current test project context + rights (same right that
 *    lib/results/printDocument.php enforces: 'testplan_metrics')
 *  - returns the print option checkbox set (printDocOptions class,
 *    doc + reqSpec sets) with their default checked state
 *  - returns output formats (FORMAT_HTML / FORMAT_MSWORD)
 *  - returns the requirement specification tree of the project
 *    (equivalent of lib/ajax/getrequirementnodes.php with
 *    show_children=0&operation=print)
 *
 * Document GENERATION itself stays in the legacy controller
 * lib/results/printDocument.php - the modern screen just navigates.
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
function getParam($key, $default = '') {
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : '';
}

$action = $_GET['action'] ?? '';
$tprojectId = intval($_GET['tproject_id'] ?? 0);
if ($tprojectId <= 0) {
    // same fallback the legacy navigator does: session test project
    $tprojectId = intval($_SESSION['testprojectID'] ?? 0);
}

$tprojectMgr = new testproject($db);

if ($action === 'init') {
    if ($tprojectId <= 0) {
        out(['status' => 'ok', 'hasProject' => false]);
    }

    $proj = $tprojectMgr->get_by_id($tprojectId);
    if (is_null($proj) || !isset($proj['name'])) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test project id']);
    }

    // same right check printDocument.php applies before generating a doc
    $canGenerate = $user->hasRightOnProj($db, 'testplan_metrics', $tprojectId);

    // print options: exact copy of printDocOptions class (doc + reqSpec sets),
    // labels are i18n keys resolved client side (opt_<value> naming as legacy)
    $docOptions = [
        ['value' => 'toc',            'checked' => false],
        ['value' => 'headerNumbering','checked' => false],
    ];
    $reqSpecOptions = [
        ['value' => 'req_spec_scope',                 'checked' => true],
        ['value' => 'req_spec_author',                'checked' => false],
        ['value' => 'req_spec_overwritten_count_reqs','checked' => false],
        ['value' => 'req_spec_type',                  'checked' => false],
        ['value' => 'req_spec_cf',                    'checked' => false],
        ['value' => 'req_scope',                      'checked' => true],
        ['value' => 'req_author',                     'checked' => false],
        ['value' => 'req_status',                     'checked' => false],
        ['value' => 'req_type',                       'checked' => false],
        ['value' => 'req_cf',                         'checked' => false],
        ['value' => 'req_relations',                  'checked' => false],
        ['value' => 'req_linked_tcs',                 'checked' => false],
        ['value' => 'req_coverage',                   'checked' => false],
        ['value' => 'displayVersion',                 'checked' => false],
    ];

    $formats = [
        ['id' => FORMAT_HTML,   'key' => 'printReq.formatHtml'],
        ['id' => FORMAT_MSWORD, 'key' => 'printReq.formatWord'],
    ];

    $reqQty = intval($tprojectMgr->count_all_requirements($tprojectId));

    out([
        'status' => 'ok',
        'hasProject' => true,
        'tproject_id' => $tprojectId,
        'tproject_name' => $proj['name'],
        'requirements_enabled' => isset($proj['opt']) && isset($proj['opt']['requirementsEnabled'])
            ? intval($proj['opt']['requirementsEnabled']) > 0 : true,
        'canGenerate' => (bool)$canGenerate,
        'reqQty' => $reqQty,
        'formats' => $formats,
        'docOptions' => $docOptions,
        'reqSpecOptions' => $reqSpecOptions,
    ]);
}

if ($action === 'tree') {
    if ($tprojectId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'No test project selected']);
    }

    $proj = $tprojectMgr->get_by_id($tprojectId);
    if (is_null($proj)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test project id']);
    }

    $tables = tlObjectWithDB::getDBTables(array('req_specs', 'requirements', 'nodes_hierarchy'));

    // all requirement specs of the project (nested specs supported,
    // same behaviour cfg->child_requirements_mgmt allows in legacy)
    $sql = "SELECT RS.id, RS.testproject_id, RS.doc_id, NH.name, NH.parent_id " .
           " FROM {$tables['req_specs']} RS " .
           " JOIN {$tables['nodes_hierarchy']} NH ON NH.id = RS.id " .
           " WHERE RS.testproject_id = " . intval($tprojectId) .
           " ORDER BY NH.node_order, NH.name";

    $specs = $db->fetchRowsIntoMap($sql, 'id');
    $specIds = is_array($specs) ? array_keys($specs) : [];

    // requirement count per spec
    $reqCount = [];
    if (count($specIds) > 0) {
        $idList = implode(',', array_map('intval', $specIds));
        $sql = " SELECT req_spec_id, COUNT(0) AS qty " .
               " FROM {$tables['requirements']} " .
               " WHERE req_spec_id IN (" . $idList . ")" .
               " GROUP BY req_spec_id";
        $map = $db->fetchRowsIntoMap($sql, 'req_spec_id');
        if (!is_null($map)) {
            foreach ($map as $rsid => $ele) {
                $reqCount[$rsid] = intval($ele['qty']);
            }
        }
    }

    // build nested structure from flat map
    $childrenOf = [];
    foreach ($specs as $sid => $row) {
        $pid = intval($row['parent_id']);
        $childrenOf[$pid][] = $sid;
    }

    $buildNode = function($sid) use (&$buildNode, &$childrenOf, &$specs, &$reqCount) {
        $row = $specs[$sid];
        $kids = [];
        if (isset($childrenOf[$sid])) {
            foreach ($childrenOf[$sid] as $kidId) {
                $kids[] = $buildNode($kidId);
            }
        }
        return [
            'id' => intval($sid),
            'parentId' => intval($row['parent_id']),
            'name' => $row['name'],
            'docId' => $row['doc_id'],
            'reqCount' => isset($reqCount[$sid]) ? $reqCount[$sid] : 0,
            'children' => $kids,
        ];
    };

    $rootNodes = [];
    if (isset($childrenOf[intval($tprojectId)])) {
        foreach ($childrenOf[intval($tprojectId)] as $kidId) {
            $rootNodes[] = $buildNode($kidId);
        }
    }

    // sort siblings by name like the legacy tree menu does
    $sortByName = function(&$arr) use (&$sortByName) {
        usort($arr, function($a, $b) { return strcasecmp($a['name'], $b['name']); });
        foreach ($arr as $k => $v) {
            if (!empty($v['children'])) {
                $sortByName($arr[$k]['children']);
            }
        }
    };
    $sortByName($rootNodes);

    $reqQty = intval($tprojectMgr->count_all_requirements($tprojectId));

    out([
        'status' => 'ok',
        'tproject_id' => $tprojectId,
        'tproject_name' => $proj['name'],
        'reqQty' => $reqQty,
        'specQty' => count($specIds),
        'roots' => $rootNodes,
    ]);
}

http_response_code(400);
out(['status' => 'error', 'message' => 'Unknown action']);
