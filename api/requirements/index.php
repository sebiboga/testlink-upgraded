<?php
/**
 * Requirements BFF API - shared router
 * URL: /api/requirements/
 * Plain PHP, no framework, no compilation
 *
 * Serves TWO modernized screens:
 *
 * A) Print Requirements Spec document navigator (?action=init | ?action=tree)
 *    Mirrors lib/results/printDocOptions.php?type=reqspec (TestLink 1.9.20
 *    "Print Requirement Specification" screen):
 *     - returns current test project context + rights (same right that
 *       lib/results/printDocument.php enforces: 'testplan_metrics')
 *     - returns the print option checkbox set (printDocOptions class,
 *       doc + reqSpec sets) with their default checked state
 *     - returns output formats (FORMAT_HTML / FORMAT_MSWORD)
 *     - returns the requirement specification tree of the project
 *       (equivalent of lib/ajax/getrequirementnodes.php with
 *       show_children=0&operation=print)
 *    Document GENERATION itself stays in the legacy controller
 *    lib/results/printDocument.php - the modern screen just navigates.
 *
 * B) Requirements Monitor Overview (path routed):
 *      GET  /api/requirements/index.php/monitor-overview?tprojectId=N
 *           -> all project requirements + monitored flag of current user
 *      POST /api/requirements/index.php/monitor
 *           -> {reqId, action:'on'|'off'} subscribe/unsubscribe notifications
 *    Mirrors lib/requirements/reqMonitorOverview.php (right: 'mgt_view_req').
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
require_once('requirements.inc.php');

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

// Legacy reqOverview.php rights check
if (!$user->hasRight($db, 'mgt_view_req')) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'No permission']);
    exit;
}

$path = $_SERVER['PATH_INFO'] ?? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = preg_replace('#^/api/requirements(/index\.php)?#', '', $path);
$path = '/' . trim($path, '/');
$method = $_SERVER['REQUEST_METHOD'];
$segments = array_values(array_filter(explode('/', $path)));

function out($data) { echo json_encode($data); exit; }
function getParam($key, $default = '') {
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : '';
}
function getBody() { return json_decode(file_get_contents('php://input'), true) ?? []; }

$tprojectId = intval($_GET['tproject_id'] ?? 0);
if ($tprojectId <= 0) {
    // same fallback the legacy navigator does: session test project
    $tprojectId = intval($_SESSION['testprojectID'] ?? 0);
}

$tprojectMgr = new testproject($db);
$reqMgr = new requirement_mgr($db);
$cfieldMgr = new cfield_mgr($db);

/**
 * Resolve test project id for the Overview endpoints:
 * explicit query param wins, session project is the fallback.
 */
function resolveTprojectId() {
    $tpid = intval($_GET['tproject_id'] ?? 0);
    if ($tpid <= 0) {
        $tpid = intval($_SESSION['testprojectID'] ?? 0);
    }
    if ($tpid <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'No test project selected']);
    }
    return $tpid;
}

$method = $_SERVER['REQUEST_METHOD'];
$path = $_SERVER['PATH_INFO'] ?? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = preg_replace('#^/api/requirements(/index\.php)?#', '', $path);
$path = '/' . trim($path, '/');
$segments = array_values(array_filter(explode('/', $path)));

/**
 * Resolve target test project for monitor-overview endpoints:
 * explicit query/body param wins, falls back to session project.
 */
function resolveMonitorTprojectId($tprojectMgr) {
    $tpid = intval($_GET['tprojectId'] ?? 0);
    if ($tpid <= 0) {
        $body = getBody();
        $tpid = intval($body['tprojectId'] ?? 0);
    }
    if ($tpid <= 0) {
        $tpid = intval($_GET['tproject_id'] ?? 0);
    }
    if ($tpid <= 0) {
        $tpid = intval($_SESSION['testprojectID'] ?? 0);
    }
    if ($tpid <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Test project is mandatory']);
    }
    $item = $tprojectMgr->get_by_id($tpid);
    if (!$item) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Test project not found']);
    }
    return [$tpid, $item];
}

// ---------------------------------------------------------------------------
// Route: GET /monitor-overview - list all requirements with monitor state
// ---------------------------------------------------------------------------
if ($method === 'GET' && isset($segments[0]) && $segments[0] === 'monitor-overview') {

    list($mTpid, $mItem) = resolveMonitorTprojectId($tprojectMgr);

    if (!$user->hasRightOnProj($db, 'mgt_view_req', $mTpid)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }

    $reqIDSet = $tprojectMgr->get_all_requirement_ids($mTpid);
    $items = [];
    if (!is_null($reqIDSet) && count($reqIDSet) > 0) {
        $reqSet = $reqMgr->getByIDBulkLatestVersionRevision(
            $reqIDSet, ['outputFormat' => 'mapOfArray']);
        $monitoredSet = $reqMgr->getMonitoredByUser($userId, $mTpid);

        $pathCache = [];
        foreach ($reqIDSet as $id) {
            if (!isset($reqSet[$id]) || !isset($reqSet[$id][0])) {
                continue;
            }
            $req = $reqSet[$id][0];

            // req spec path (cached per spec)
            $srsId = intval($req['srs_id']);
            if (!isset($pathCache[$srsId])) {
                $pathNames = [];
                $pset = $reqMgr->tree_mgr->get_path($srsId);
                if (is_array($pset)) {
                    foreach ($pset as $p) {
                        $pathNames[] = $p['name'];
                    }
                }
                $pathCache[$srsId] = implode(' / ', $pathNames);
            }

            $items[] = [
                'id' => intval($req['id']),
                'req_doc_id' => $req['req_doc_id'],
                'title' => $req['title'],
                'srs_id' => $srsId,
                'spec_path' => $pathCache[$srsId],
                'version_id' => intval($req['version_id']),
                'version' => intval($req['version']),
                'creation_ts' => $req['creation_ts'],
                'author' => $req['author'],
                'monitored' => isset($monitoredSet[$req['id']]),
            ];
        }
    }

    usort($items, function ($a, $b) {
        return strcmp($a['spec_path'], $b['spec_path']) ?: strcasecmp($a['title'], $b['title']);
    });

    out([
        'status' => 'ok',
        'items' => $items,
        'total' => count($items),
        'tproject_id' => $mTpid,
        'tproject_name' => $mItem['name'],
    ]);
}

// ---------------------------------------------------------------------------
// Route: POST /monitor - subscribe/unsubscribe current user on a requirement
// ---------------------------------------------------------------------------
if ($method === 'POST' && isset($segments[0]) && $segments[0] === 'monitor') {

    list($mTpid, $mDummy) = resolveMonitorTprojectId($tprojectMgr);

    if (!$user->hasRightOnProj($db, 'mgt_view_req', $mTpid)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }

    $body = getBody();
    $reqId = intval($body['reqId'] ?? 0);
    $action = trim($body['action'] ?? '');

    if ($reqId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'reqId invalid']);
    }
    if (!in_array($action, ['on', 'off'])) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'action must be on|off']);
    }

    // requirement must exist inside this project
    $rt = tlObjectWithDB::getDBTables(array('requirements', 'req_specs'));
    $sql = " SELECT REQ.id AS req_id, RS.testproject_id " .
           " FROM {$rt['requirements']} REQ " .
           " JOIN {$rt['req_specs']} RS ON RS.id = REQ.srs_id " .
           " WHERE REQ.id = " . intval($reqId);
    $reqRs = $db->get_recordset($sql);
    if (!$reqRs || intval($reqRs[0]['testproject_id']) !== $mTpid) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Requirement not found in this project']);
    }

    if ($action === 'on') {
        $reqMgr->monitorOn($reqId, $userId, $mTpid);
    } else {
        $reqMgr->monitorOff($reqId, $userId, $mTpid);
    }

    $monitoredSet = $reqMgr->getMonitoredByUser($userId, $mTpid);
    out([
        'status' => 'ok',
        'reqId' => $reqId,
        'action' => $action,
        'monitored' => isset($monitoredSet[$reqId]),
    ]);
}

$action = $_GET['action'] ?? '';

/**
 * Meta block: cfg flags, custom field columns and localized label maps.
 */
function buildMeta($tproject_id) {
    global $cfieldMgr;

    $reqCfg = config_get('req_cfg');
    $charsetCfg = config_get('charset');

    $expectedCoverage = isset($reqCfg->expected_coverage_management) ? (bool)$reqCfg->expected_coverage_management : false;
    $relationsEnabled = isset($reqCfg->relations->enable) ? (bool)$reqCfg->relations->enable : false;

    // localized type/status labels (same as legacy init_labels)
    $typeLabels = [];
    foreach ((array)$reqCfg->type_labels as $code => $langKey) {
        $typeLabels[$code] = lang_get($langKey);
    }
    $statusLabels = [];
    foreach ((array)$reqCfg->status_labels as $code => $langKey) {
        $statusLabels[$code] = lang_get($langKey);
    }

    // custom fields linked at design time for requirement nodes, ordered by name
    $cfMap = (array)$cfieldMgr->get_linked_cfields_at_design($tproject_id, 1, null, 'requirement', null, 'name');
    $cfields = [];
    foreach ($cfMap as $name => $cf) {
        $cfields[] = [
            'name' => $name,
            'label' => htmlentities($cf['label'], ENT_QUOTES, $charsetCfg),
            'type' => intval($cf['type']),
            'verbose_type' => isset($cfieldMgr->custom_field_types[$cf['type']])
                ? $cfieldMgr->custom_field_types[$cf['type']] : 'string',
        ];
    }

    return [
        'tproject_id' => intval($tproject_id),
        'expected_coverage_management' => $expectedCoverage,
        'relations_enabled' => $relationsEnabled,
        'type_labels' => $typeLabels,
        'status_labels' => $statusLabels,
        'cfields' => $cfields,
    ];
}

// Route: GET /meta - cfg flags and label maps only
if ($method === 'GET' && isset($segments[0]) && $segments[0] === 'meta' && !isset($segments[1])) {
    $tid = resolveTprojectId();
    out(['status' => 'ok', 'meta' => buildMeta($tid)]);
}

// Route: GET /overview - all requirements of the project with latest or all versions
if ($method === 'GET' && isset($segments[0]) && $segments[0] === 'overview') {
    $chronoStart = microtime(true);
    $tid = resolveTprojectId();
    $meta = buildMeta($tid);

    $allVersionsParam = getParam('all_versions', '');
    if ($allVersionsParam !== '') {
        $allVersions = ($allVersionsParam === '1' || $allVersionsParam === 'true');
        $_SESSION['all_versions'] = $allVersions;
    } else {
        $allVersions = isset($_SESSION['all_versions']) ? $_SESSION['all_versions'] : false;
    }

    $reqIDs = $tprojectMgr->get_all_requirement_ids($tid);
    $rows = [];
    $elapsed = 0;

    if (!empty($reqIDs)) {
        if ($allVersions) {
            $reqSet = $reqMgr->get_by_id($reqIDs, requirement_mgr::ALL_VERSIONS, null,
                ['output_format' => 'mapOfArray']);
        } else {
            $reqSet = $reqMgr->getByIDBulkLatestVersionRevision($reqIDs, ['outputFormat' => 'mapOfArray']);
        }

        // version set for bulk custom field fetch / coverage counters (same as legacy)
        $reqVersionSet = [];
        if (($meta['expected_coverage_management'] || count($meta['cfields']) > 0) && !empty($reqSet)) {
            foreach (array_keys($reqSet) as $rqID) {
                if (isset($reqSet[$rqID][0]['version_id'])) {
                    $reqVersionSet[] = $reqSet[$rqID][0]['version_id'];
                }
            }
        }

        $coverageSet = null;
        if ($meta['expected_coverage_management']) {
            $coverageSet = $reqMgr->getLatestReqVersionCoverageCounterSet($reqVersionSet);
        }

        $relationCounters = null;
        if ($meta['relations_enabled']) {
            $relationCounters = $reqMgr->getRelationsCounters($reqIDs);
        }

        $cfByVer = [];
        if (count($meta['cfields']) > 0) {
            $cfByVer = (array)$reqMgr->get_linked_cfields(null, $reqVersionSet, $tid,
                ['access_key' => 'node_id']);
        }

        $pathCache = [];

        foreach ($reqIDs as $id) {
            if (!isset($reqSet[$id]) || empty($reqSet[$id])) {
                continue;
            }
            $req = $reqSet[$id];

            // reqspec path cached per srs_id (same as legacy)
            $srsId = $req[0]['srs_id'];
            if (!isset($pathCache[$srsId])) {
                $p = $reqMgr->tree_mgr->get_path($srsId);
                $names = [];
                foreach ($p as $nodeInfo) {
                    $names[] = $nodeInfo['name'];
                }
                $pathCache[$srsId] = implode('/', $names);
            }
            $specPath = $pathCache[$srsId];

            foreach ($req as $version) {
                $isOpen = intval($version['is_open']);

                // coverage
                $coverageQty = null;
                $coveragePct = null;
                if ($meta['expected_coverage_management']) {
                    $coverageQty = isset($coverageSet[$id]) ? intval($coverageSet[$id]['qty']) : 0;
                    $expected = intval($version['expected_coverage']);
                    if ($expected > 0) {
                        $coveragePct = round(100 / $expected * $coverageQty, 2);
                    }
                }

                // relations counter
                $relQty = 0;
                if ($meta['relations_enabled']) {
                    $relQty = isset($relationCounters[$id]) ? intval($relationCounters[$id]) : 0;
                }

                // custom field values for this version
                $cfValues = [];
                if (count($meta['cfields']) > 0 && isset($cfByVer[$version['version_id']])) {
                    foreach ($cfByVer[$version['version_id']] as $cf) {
                        $vType = isset($cfieldMgr->custom_field_types[$cf['type']])
                            ? $cfieldMgr->custom_field_types[$cf['type']] : 'string';
                        $value = preg_replace('!\s+!', ' ', htmlspecialchars($cf['value'], ENT_QUOTES));
                        if (($vType == 'date' || $vType == 'datetime') && is_numeric($value) && $value != 0) {
                            $format = config_get($vType);
                            $value = tlStrftime($format, intval($value));
                        }
                        $cfValues[$cf['name']] = $value;
                    }
                }

                $modifiedNever = is_null($version['modification_ts'])
                    || $version['modification_ts'] == '0000-00-00 00:00:00';

                $rows[] = [
                    'req_id' => intval($id),
                    'version_id' => intval($version['version_id']),
                    'srs_id' => intval($srsId),
                    'doc_id' => $req[0]['req_doc_id'],
                    'title' => $req[0]['title'],
                    'spec_path' => $specPath,
                    'display_title' => $req[0]['req_doc_id'] .
                        config_get('gui_title_separator_1') . $req[0]['title'],
                    'version' => intval($version['version']),
                    'revision' => intval($version['revision']),
                    'creation_ts_raw' => $version['creation_ts'],
                    'author' => $version['author'],
                    'modified_never' => $modifiedNever,
                    'modification_ts_raw' => $modifiedNever ? null : $version['modification_ts'],
                    'modifier' => $modifiedNever ? null : $version['modifier'],
                    'frozen' => !$isOpen,
                    'expected_coverage' => intval($version['expected_coverage']),
                    'coverage_qty' => $coverageQty,
                    'coverage_pct' => $coveragePct,
                    'type' => $version['type'],
                    'status' => $version['status'],
                    'relations_qty' => $relQty,
                    'cfields' => $cfValues,
                ];
            }
        }
    }

    $elapsed = round(microtime(true) - $chronoStart, 2);

    out([
        'status' => 'ok',
        'meta' => $meta,
        'tproject_name' => testproject::getName($db, $tid),
        'items' => $rows,
        'total' => count($rows),
        'all_versions' => (bool)$allVersions,
        'elapsed_seconds' => $elapsed,
    ]);
}

// ---------------------------------------------------------------------------
// Print Requirement Specification screen (lib/results/printDocOptions.php
// ?type=reqspec). Document generation stays in lib/results/printDocument.php.
// ---------------------------------------------------------------------------

if ($action === 'print_init') {
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

if ($action === 'print_tree') {
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

    // requirement count per spec (requirements.srs_id -> req_specs.id)
    $reqCount = [];
    if (count($specIds) > 0) {
        $idList = implode(',', array_map('intval', $specIds));
        $sql = " SELECT srs_id, COUNT(0) AS qty " .
               " FROM {$tables['requirements']} " .
               " WHERE srs_id IN (" . $idList . ")" .
               " GROUP BY srs_id";
        $map = $db->fetchRowsIntoMap($sql, 'srs_id');
        if (!is_null($map)) {
            foreach ($map as $srsId => $ele) {
                $reqCount[$srsId] = intval($ele['qty']);
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

http_response_code(404);
echo json_encode(['status' => 'error', 'message' => 'Unknown endpoint']);
