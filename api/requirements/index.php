<?php
/**
 * Requirements BFF API
 * URL: /api/requirements/
 * Plain PHP, no framework, no compilation
 *
 * Serves the Requirements Overview screen (reqOverview).
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
function getParam($key, $default = null) { return $_GET[$key] ?? $default; }

$tproject_mgr = new testproject($db);
$req_mgr = new requirement_mgr($db);
$cfield_mgr = new cfield_mgr($db);

/**
 * Resolve project context: query param wins, session is fallback.
 */
function resolveTprojectId() {
    global $tproject_mgr;
    $tid = intval(getParam('tproject_id', 0));
    if ($tid <= 0) {
        $tid = intval($_SESSION['testprojectID'] ?? 0);
    }
    if ($tid <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'No test project selected']);
    }
    return $tid;
}

/**
 * Meta block: cfg flags, custom field columns and localized label maps.
 */
function buildMeta($tproject_id) {
    global $cfield_mgr, $req_mgr;

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
    $cfMap = (array)$cfield_mgr->get_linked_cfields_at_design($tproject_id, 1, null, 'requirement', null, 'name');
    $cfields = [];
    foreach ($cfMap as $name => $cf) {
        $cfields[] = [
            'name' => $name,
            'label' => htmlentities($cf['label'], ENT_QUOTES, $charsetCfg),
            'type' => intval($cf['type']),
            'verbose_type' => isset($cfield_mgr->custom_field_types[$cf['type']])
                ? $cfield_mgr->custom_field_types[$cf['type']] : 'string',
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

    $allVersionsParam = getParam('all_versions', null);
    if (!is_null($allVersionsParam)) {
        $allVersions = ($allVersionsParam === '1' || $allVersionsParam === 'true');
        $_SESSION['all_versions'] = $allVersions;
    } else {
        $allVersions = isset($_SESSION['all_versions']) ? $_SESSION['all_versions'] : false;
    }

    $reqIDs = $tproject_mgr->get_all_requirement_ids($tid);
    $rows = [];
    $elapsed = 0;

    if (!empty($reqIDs)) {
        if ($allVersions) {
            $reqSet = $req_mgr->get_by_id($reqIDs, requirement_mgr::ALL_VERSIONS, null,
                ['output_format' => 'mapOfArray']);
        } else {
            $reqSet = $req_mgr->getByIDBulkLatestVersionRevision($reqIDs, ['outputFormat' => 'mapOfArray']);
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
            $coverageSet = $req_mgr->getLatestReqVersionCoverageCounterSet($reqVersionSet);
        }

        $relationCounters = null;
        if ($meta['relations_enabled']) {
            $relationCounters = $req_mgr->getRelationsCounters($reqIDs);
        }

        $cfByVer = [];
        if (count($meta['cfields']) > 0) {
            $cfByVer = (array)$req_mgr->get_linked_cfields(null, $reqVersionSet, $tid,
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
                $p = $req_mgr->tree_mgr->get_path($srsId);
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
                        $vType = isset($cfield_mgr->custom_field_types[$cf['type']])
                            ? $cfield_mgr->custom_field_types[$cf['type']] : 'string';
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
        'items' => $rows,
        'total' => count($rows),
        'all_versions' => (bool)$allVersions,
        'elapsed_seconds' => $elapsed,
    ]);
}

http_response_code(404);
echo json_encode(['status' => 'error', 'message' => 'Unknown endpoint']);
