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
require_once(__DIR__ . '/../../cfg/reports.cfg.php');
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

// ---------------------------------------------------------------------------
// Route: GET /reqspec-context - everything the Search Requirement
// Specifications form needs to render.
// Mirrors lib/requirements/reqSpecSearchForm.php
// ---------------------------------------------------------------------------
if ($method === 'GET' && count($segments) === 1 && $segments[0] === 'reqspec-context') {
    $tpid = resolveTprojectId();
    $info = $tprojectMgr->get_by_id($tpid);
    if (!$info) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Test project not found']);
    }

    // req spec type domain, localized the same way as the legacy form
    $reqSpecCfg = config_get('req_spec_cfg');
    $types = [];
    foreach (init_labels($reqSpecCfg->type_labels) as $code => $lbl) {
        $types[] = ['code' => (string)$code, 'label' => $lbl];
    }

    // custom fields linked to requirement_spec at design time (enabled only)
    $customFields = [];
    $designCf = $cfieldMgr->get_linked_cfields_at_design(
        $tpid, cfield_mgr::ENABLED, null, 'requirement_spec');
    if (!is_null($designCf)) {
        foreach ($designCf as $cf_id => $cf) {
            $customFields[] = [
                'id' => intval($cf_id),
                'label' => $cf['label'],
                'type' => intval($cf['type']),
            ];
        }
    }

    // legacy form shows Doc ID filter only when the project HAS req specs
    // (testproject::GET_NOT_EMPTY_REQSPEC)
    $reqSpecSet = $tprojectMgr->getOptionReqSpec(
        $tpid, testproject::GET_NOT_EMPTY_REQSPEC);

    $opt = $tprojectMgr->getOptions($tpid);

    out([
        'status' => 'ok',
        'tproject' => ['id' => $tpid, 'name' => $info['name']],
        'types' => $types,
        'customFields' => $customFields,
        'hasReqSpecs' => !is_null($reqSpecSet) && count($reqSpecSet) > 0,
        'requirementsEnabled' => !empty($opt->requirementsEnabled),
        'maxQtyForDisplay' => intval(config_get('req_cfg')->search->max_qty_for_display),
    ]);
}

// ---------------------------------------------------------------------------
// Route: GET /reqspec-search - run the Search Requirement Specifications.
// Mirrors lib/requirements/reqSpecSearch.php: LIKE '%value%' criteria AND-ed
// against req_specs_revisions (+ nodes_hierarchy for name), type exact match,
// custom field values joined on the REVISION node id; result cap =
// req_cfg.search.max_qty_for_display -> 'too_wide_search_criteria' warning.
// ---------------------------------------------------------------------------
if ($method === 'GET' && count($segments) === 1 && $segments[0] === 'reqspec-search') {
    $tpid = resolveTprojectId();

    $tables = tlObjectWithDB::getDBTables(array(
        'cfield_design_values', 'nodes_hierarchy', 'req_specs', 'req_specs_revisions'));

    $docId = getParam('doc_id');
    $name = getParam('name');
    $type = getParam('reqSpecType', 'notype');
    $scope = getParam('scope');
    $logMessage = getParam('log_message');
    $cfId = intval(getParam('custom_field_id', 0));
    $cfValue = getParam('custom_field_value');

    $filter = null;
    $join = null;

    if ($docId !== '') {
        $safe = $db->prepare_string($docId);
        $filter['by_id'] = " AND RSPECREV.doc_id like '%{$safe}%' ";
    }

    if ($name !== '') {
        $safe = $db->prepare_string($name);
        $filter['by_name'] = " AND NHRSPEC.name like '%{$safe}%' ";
    }

    if ($type !== '' && $type !== 'notype') {
        $safe = $db->prepare_string($type);
        $filter['by_type'] = " AND RSPECREV.type='{$safe}' ";
    }

    if ($scope !== '') {
        $safe = $db->prepare_string($scope);
        $filter['by_scope'] = " AND RSPECREV.scope like '%{$safe}%' ";
    }

    if ($logMessage !== '') {
        $safe = $db->prepare_string($logMessage);
        $filter['by_log_message'] = " AND RSPECREV.log_message like '%{$safe}%' ";
    }

    if ($cfId > 0) {
        $designCf = $cfieldMgr->get_linked_cfields_at_design(
            $tpid, cfield_mgr::ENABLED, null, 'requirement_spec');
        if (is_null($designCf) || !isset($designCf[$cfId])) {
            http_response_code(400);
            out(['status' => 'error', 'message' => 'Unknown custom field']);
        }
        $join['by_custom_field'] = " JOIN {$tables['cfield_design_values']} CFD " .
                       " ON CFD.node_id=RSPECREV.id ";
        $safeVal = $db->prepare_string($cfValue);
        $filter['by_custom_field'] = " AND CFD.field_id=" . intval($cfId) .
                                     " AND CFD.value like '%{$safeVal}%' ";
    }

    $sql = " SELECT NHRSPEC.name, NHRSPEC.id, RSPEC.doc_id, RSPECREV.id AS revision_id, RSPECREV.revision " .
           " FROM {$tables['req_specs']} RSPEC JOIN {$tables['req_specs_revisions']} RSPECREV " .
           " ON RSPEC.id=RSPECREV.parent_id " .
           " JOIN {$tables['nodes_hierarchy']} NHRSPEC " .
           " ON NHRSPEC.id = RSPEC.id ";

    if (!is_null($join)) {
        $sql .= implode("", $join);
    }

    $sql .= " AND RSPEC.testproject_id = {$tpid} ";

    if (!is_null($filter)) {
        $sql .= implode("", $filter);
    }

    $sql .= ' ORDER BY NHRSPEC.id ASC, RSPECREV.revision DESC ';

    $rs = $db->exec_query($sql);
    $grouped = [];
    $count = 0;
    while ($row = $db->fetch_array($rs)) {
        $rspecId = intval($row['id']);
        if (!isset($grouped[$rspecId])) {
            $grouped[$rspecId] = [
                'req_spec_id' => $rspecId,
                'doc_id' => $row['doc_id'],
                'name' => $row['name'],
                'revisions' => [],
            ];
            $count++;
        }
        $grouped[$rspecId]['revisions'][] = [
            'revision_id' => intval($row['revision_id']),
            'revision' => intval($row['revision']),
        ];
    }

    $rows = [];
    $warning = '';
    if ($count > 0) {
        $maxQty = intval(config_get('req_cfg')->search->max_qty_for_display);
        if ($count <= $maxQty) {
            $options = array('output_format' => 'path_as_string');
            $pathInfo = $tprojectMgr->tree_manager->get_full_path_verbose(
                array_keys($grouped), $options);
            foreach ($grouped as $rspecId => $rec) {
                $rec['path'] = isset($pathInfo[$rspecId]) ? $pathInfo[$rspecId] : '';
                $rows[] = $rec;
            }
        } else {
            $warning = 'too_wide_search_criteria';
        }
    } else {
        $warning = 'no_records_found';
    }

    out([
        'status' => 'ok',
        'count' => $count,
        'rows' => $rows,
        'warning' => $warning,
    ]);
}


// ===========================================================================
// C) Search Requirements (path routed) - modernized searchReq screen.
// Mirrors lib/requirements/reqSearchForm.php + reqSearch.php (1.9.20):
// - search ONLY on current test project; text criteria AND-ed (LIKE '%v%')
// - versions AND revisions searched (UNION), results grouped by requirement
// - result cap = req_cfg.search.max_qty_for_display
// ===========================================================================

function getStrParam($key, $default = '') {
    foreach ([$_GET[$key] ?? null, $_POST[$key] ?? null] as $v) {
        if (is_string($v)) {
            $v = trim($v);
            return strlen($v) > 0 ? $v : $default;
        }
    }
    return $default;
}
function getIntParam($key, $default = 0) {
    foreach ([$_GET[$key] ?? null, $_POST[$key] ?? null] as $v) {
        if (!is_null($v) && !is_array($v) && trim((string)$v) !== '') {
            return intval(trim((string)$v));
        }
    }
    return $default;
}

// Route: GET /search-context - everything the Search Requirements form needs
if ($method === 'GET' && isset($segments[0]) && $segments[0] === 'search-context') {
    list($tpid, $info) = resolveMonitorTprojectId($tprojectMgr);

    if (!$user->hasRight($db, 'mgt_view_req', $tpid)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }

    $tcaseCfg = config_get('testcase_cfg');
    $prefix = $tprojectMgr->getTestCasePrefix($tpid) . $tcaseCfg->glue_character;

    // requirement doc ids exist? (legacy filter_by.requirement_doc_id)
    $reqSpecSet = $tprojectMgr->getOptionReqSpec($tpid, testproject::GET_NOT_EMPTY_REQSPEC);
    $filterByDocId = !is_null($reqSpecSet);

    $meta = buildMeta($tpid);
    $reqCfg = config_get('req_cfg');

    // type domain: same source as legacy form
    $types = [];
    foreach ($meta['type_labels'] as $code => $lbl) {
        $types[] = ['code' => (string)$code, 'label' => $lbl];
    }

    // status domain
    $statusDomain = [];
    foreach ($meta['status_labels'] as $code => $lbl) {
        $statusDomain[] = ['code' => (string)$code, 'label' => $lbl];
    }

    // relation type select (only if relations enabled)
    $relationItems = [];
    if (!empty($meta['relations_enabled'])) {
        $relSel = $reqMgr->init_relation_type_select();
        $items = $relSel['items'];
        // equal relations appear twice (xxx_source / xxx_destination):
        // keep single entry keyed by numeric type like legacy form does
        foreach ($relSel['equal_relations'] as $key => $oldkey) {
            $new_key = (int)str_replace("_source", "", $oldkey);
            $items[$new_key] = $relSel['items'][$oldkey];
            unset($items[$oldkey]);
        }
        foreach ($items as $k => $lbl) {
            $relationItems[] = ['id' => (string)$k, 'label' => $lbl];
        }
    }

    out([
        'status' => 'ok',
        'context' => [
            'tproject_id' => $tpid,
            'tproject_name' => $info['name'],
            'tcase_prefix' => $prefix,
            'max_results' => intval(config_get('req_cfg')->search->max_qty_for_display),
        ],
        'filters' => [
            'requirement_doc_id' => $filterByDocId,
            'expected_coverage' => $meta['expected_coverage_management'],
            'relation_type' => $meta['relations_enabled'],
            'design_scope_custom_fields' => count($meta['cfields']) > 0,
        ],
        'custom_fields' => $meta['cfields'],
        'types' => $types,
        'statuses' => $statusDomain,
        'relation_types' => $relationItems,
    ]);
}

// Route: GET /search - run the Search Requirements
if ($method === 'GET' && isset($segments[0]) && $segments[0] === 'search') {
    list($tpid, $dummyInfo) = resolveMonitorTprojectId($tprojectMgr);

    if (!$user->hasRight($db, 'mgt_view_req', $tpid)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }

    $args = new stdClass();

    $strnull = ['requirement_document_id', 'name', 'scope', 'reqStatus',
                'version', 'tcid', 'reqType', 'relation_type',
                'creation_date_from', 'creation_date_to', 'log_message',
                'modification_date_from', 'modification_date_to'];
    foreach ($strnull as $keyvar) {
        $args->$keyvar = getStrParam($keyvar, '');
        if ($args->$keyvar === '') {
            $args->$keyvar = null;
        }
    }

    $int0 = ['custom_field_id', 'coverage'];
    foreach ($int0 as $keyvar) {
        $args->$keyvar = getIntParam($keyvar, 0);
    }

    $args->userID = intval($userId);
    $args->tprojectID = $tpid;

    $sql = reqBuildSearchSql($db, $args);
    $map = (array)$db->fetchRowsIntoMap($sql, 'id', database::CUMULATIVE);

    // dont show requirements from different testprojects than the selected one
    if (count($map)) {
        foreach (array_keys($map) as $item) {
            $pid = $tprojectMgr->tree_manager->getTreeRoot($item);
            if ($pid != $tpid) {
                unset($map[$item]);
            }
        }
    }

    $rowQty = count($map);
    $maxDisplay = intval(config_get('req_cfg')->search->max_qty_for_display);

    $resultSet = [];
    $tooWide = false;
    if ($rowQty > 0) {
        if ($rowQty <= $maxDisplay) {
            $req_set = array_keys($map);
            $options = ['output_format' => 'path_as_string'];
            $pathInfo = $tprojectMgr->tree_manager->get_full_path_verbose($req_set, $options);

            $charset = config_get('charset');
            foreach ($map as $req_id => $itemSet) {
                $rfx = $itemSet[0];

                $matches = [];
                $seen = [];
                foreach ($itemSet as $rx) {
                    // de-duplicate identical version|revision pairs
                    $tag = $rx['version'] . '|' . $rx['revision'];
                    if (isset($seen[$tag])) { continue; }
                    $seen[$tag] = true;
                    $matches[] = [
                        'version_id' => intval($rx['version_id']),
                        'version' => intval($rx['version']),
                        'revision_id' => intval($rx['revision_id']),
                        'revision' => intval($rx['revision']),
                    ];
                }

                $path = '';
                if (isset($pathInfo[$rfx['id']])) {
                    $p = $pathInfo[$rfx['id']];
                    $path = is_array($p) ? implode(" / ", $p) : (string)$p;
                }

                $resultSet[] = [
                    'req_id' => intval($rfx['id']),
                    'req_doc_id' => htmlentities($rfx['req_doc_id'], ENT_QUOTES, $charset),
                    'name' => htmlentities($rfx['name'], ENT_QUOTES, $charset),
                    'path' => htmlentities($path, ENT_QUOTES, $charset),
                    'matches' => $matches,
                ];
            }
        } else {
            $tooWide = true;
        }
    }

    out([
        'status' => 'ok',
        'row_qty' => $rowQty,
        'too_wide' => $tooWide,
        'max_results' => $maxDisplay,
        'results' => $resultSet,
    ]);
}

// ---------------------------------------------------------------------------
// Reimplementation of legacy build_search_sql() from lib/requirements/reqSearch.php
// (kept verbatim in behavior: UNION over req_versions + req_revisions)
// ---------------------------------------------------------------------------
function reqBuildSearchSql(&$dbHandler, &$argsObj) {
    $tables = tlObjectWithDB::getDBTables([
        'cfield_design_values', 'nodes_hierarchy', 'req_specs', 'req_relations',
        'req_versions', 'req_revisions', 'requirements', 'req_coverage', 'tcversions'
    ]);

    $filter = ['ver' => null, 'rev' => null];
    $from   = ['ver' => null, 'rev' => null];

    // date filters (localized input -> ISO, with hh:mm:ss boundaries)
    $date_fields = ['creation_ts' => 'ts', 'modification_ts' => 'ts'];
    $date_keys = ['date_from' => '>=', 'date_to' => '<='];
    foreach ($date_fields as $fx => $needle) {
        foreach ($date_keys as $fk => $op) {
            $fkey = str_replace($needle, $fk, $fx);
            if ($argsObj->$fkey) {
                $iso = reqLocalizeDateToIso($argsObj->$fkey);
                if (!is_null($iso)) {
                    $hhmmss = ('from' == substr($fkey, -4)) ? ' 00:00:00' : ' 23:59:59';
                    $iso .= $hhmmss;
                    $filter['ver'][$fkey] = " AND REQV.$fx $op '{$iso}' ";
                    $filter['rev'][$fkey] = " AND REQR.$fx $op '{$iso}' ";
                }
            }
        }
    }

    // LIKE filters
    $likeKeys = [
        'name'                   => ['name'       => ['ver' => "NH_REQ", 'rev' => "REQR"]],
        'requirement_document_id'=> ['req_doc_id' => ['ver' => 'REQ',    'rev' => 'REQR']],
        'scope'                  => ['scope'      => ['ver' => 'REQV',   'rev' => 'REQR']],
        'log_message'            => ['log_message'=> ['ver' => 'REQV',   'rev' => 'REQR']],
    ];

    foreach ($likeKeys as $key => $fcfg) {
        if ($argsObj->$key) {
            $value = $dbHandler->prepare_string($argsObj->$key);
            $field = key($fcfg);
            foreach ($fcfg[$field] as $table => $alias) {
                $filter[$table][$field] = " AND {$alias}.{$field} like '%{$value}%' ";
            }
        }
    }

    // exact char filters
    $char_keys = [
        'reqType'   => ['type'   => ['ver' => "REQV", 'rev' => "REQR"]],
        'reqStatus' => ['status' => ['ver' => 'REQV', 'rev' => 'REQR']],
    ];

    foreach ($char_keys as $key => $fcfg) {
        if ($argsObj->$key) {
            $value = $dbHandler->prepare_string($argsObj->$key);
            $field = key($fcfg);
            foreach ($fcfg[$field] as $table => $alias) {
                $filter[$table][$field] = " AND {$alias}.{$field} = '{$value}' ";
            }
        }
    }

    if ($argsObj->version !== null && $argsObj->version != '') {
        $version = $dbHandler->prepare_int($argsObj->version);
        $filter['ver']['version'] = " AND REQV.version = {$version} ";
    }

    if ($argsObj->coverage > 0) {
        // search by expected coverage of testcases
        $coverage = $dbHandler->prepare_int($argsObj->coverage);
        $filter['ver']['coverage'] = " AND REQV.expected_coverage = {$coverage} ";
        $filter['rev']['coverage'] = " AND REQR.expected_coverage = {$coverage} ";
    }

    // Complex processing: relation type
    // value format: e.g. "3_destination" / "2_source" / "4"
    if (!is_null($argsObj->relation_type)) {
        $dummy = explode('_', $argsObj->relation_type);
        $rel_type = $dummy[0];
        $side = isset($dummy[1])
            ? " RR.{$dummy[1]}_id = NH_REQ.id "
            : " RR.source_id = NH_REQ.id OR RR.destination_id = NH_REQ.id ";
        $from['ver']['relation_type'] =
            " JOIN {$tables['req_relations']} RR ON ($side) AND RR.relation_type = {$rel_type} ";
        $from['rev']['relation_type'] = $from['ver']['relation_type'];
    }

    if ($argsObj->custom_field_id > 0) {
        $cfield_id = $dbHandler->prepare_int($argsObj->custom_field_id);
        $cfValue = isset($argsObj->custom_field_value) ? (string)$argsObj->custom_field_value : '';
        $cfield_value = $dbHandler->prepare_string($cfValue);
        $from['ver']['custom_field'] =
            " JOIN {$tables['cfield_design_values']} CFD ON CFD.node_id = REQV.id ";
        $from['rev']['custom_field'] =
            " JOIN {$tables['cfield_design_values']} CFD ON CFD.node_id = REQR.id ";
        $filter['ver']['custom_field'] =
            " AND CFD.field_id = {$cfield_id} AND CFD.value like '%{$cfield_value}%' ";
        $filter['rev']['custom_field'] = $filter['ver']['custom_field'];
    }

    if ($argsObj->tcid != "" && !is_null($argsObj->tcid)) {
        // search for reqs linked to this testcase (by external id)
        $tcid = trim(str_replace(config_get('testcase_cfg')->glue_character, "", $argsObj->tcid));
        $tcid = $dbHandler->prepare_string($tcid);
        if ($tcid != '') {
            $filter['ver']['tcid'] = " AND TCV.tc_external_id = '{$tcid}' ";

            $from['ver']['tcid'] =
                " JOIN {$tables['req_coverage']} RC ON RC.req_version_id = NH_REQV.id " .
                " JOIN {$tables['nodes_hierarchy']} NH_TCV ON NH_TCV.id = RC.tcversion_id " .
                " JOIN {$tables['tcversions']} TCV ON TCV.id = NH_TCV.id ";
            $from['rev']['tcid'] = $from['ver']['tcid'];
        }
    }

    // STEP 1 - search on REQ Versions
    $common = " SELECT NH_REQ.name, REQ.id, REQ.req_doc_id,";
    $sql = $common .
        " REQV.id as version_id, REQV.version, REQV.revision, -1 AS revision_id " .
        " FROM {$tables['requirements']} REQ " .
        " JOIN {$tables['nodes_hierarchy']} NH_REQ ON NH_REQ.id=REQ.id " .
        " JOIN {$tables['nodes_hierarchy']} NH_REQV ON NH_REQV.parent_id = NH_REQ.id " .
        " JOIN {$tables['req_versions']} REQV ON REQV.id=NH_REQV.id ";

    foreach (['from', 'filter'] as $vv) {
        $ref = &$$vv;
        if (!is_null($ref['ver'])) {
            $sql .= ($vv == 'filter') ? ' WHERE 1=1 ' : '';
            $sql .= implode("", $ref['ver']);
        }
    }
    $stm['ver'] = $sql;

    // STEP 2 - search on REQ Revisions (UNION)
    $sql4Union = $common .
        " REQR.parent_id AS version_id, REQV.version, REQR.revision, REQR.id AS revision_id " .
        " FROM {$tables['requirements']} REQ " .
        " JOIN {$tables['nodes_hierarchy']} NH_REQ ON NH_REQ.id=REQ.id " .
        " JOIN {$tables['nodes_hierarchy']} NH_REQV ON NH_REQV.parent_id = NH_REQ.id " .
        " JOIN {$tables['req_versions']} REQV ON REQV.id=NH_REQV.id " .
        " JOIN {$tables['req_revisions']} REQR ON REQR.parent_id=REQV.id ";

    foreach (['from', 'filter'] as $vv) {
        $ref = &$$vv;
        if (!is_null($ref['rev'])) {
            $sql4Union .= ($vv == 'filter') ? ' WHERE 1=1 ' : '';
            $sql4Union .= implode("", $ref['rev']);
        }
    }

    $sql = $stm['ver'] . " UNION ({$sql4Union}) ORDER BY id ASC, version DESC, revision DESC ";
    return $sql;
}

/**
 * Convert a localized date (per configured date_format) to ISO Y-m-d.
 */
function reqLocalizeDateToIso($localizedDate) {
    $dateFormat = config_get('date_format');
    $l10ndate = split_localized_date(trim($localizedDate), $dateFormat);
    if (is_array($l10ndate)) {
        return $l10ndate['year'] . "-" . $l10ndate['month'] . "-" . $l10ndate['day'];
    }
    return null;
}


