<?php
/**
 * Requirements BFF API
 * URL: /api/requirements/
 * Plain PHP, no framework, no compilation
 *
 * Endpoints:
 *   GET  /api/requirements/search-context?tproject_id=N -> Search Requirements form context
 *   GET  /api/requirements/search?tproject_id=N&...     -> Search Requirements execution
 *   GET  /api/requirements/monitor-overview?tprojectId=N  -> all project requirements + monitored flag
 *   POST /api/requirements/monitor                        -> {reqId, action: 'on'|'off'}
 *
 * The search endpoints mirror lib/requirements/reqSearchForm.php +
 * lib/requirements/reqSearch.php (TestLink 1.9.20 "Search Requirements"):
 * - search ONLY ON CURRENT test project
 * - text criteria are AND-ed (LIKE '%value%')
 * - versions AND revisions are searched (UNION), results grouped by requirement
 * - result cap = req_cfg.search.max_qty_for_display
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
function getParam($key, $default = null) { return $_GET[$key] ?? $default; }
function getBody() { return json_decode(file_get_contents('php://input'), true) ?? []; }

// string/int param helpers used by the search endpoints (reject arrays)
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

$tproject_mgr = new testproject($db);
$req_mgr = new requirement_mgr($db);

/**
 * Resolve target test project: query param wins, falls back to session project.
 */
function resolveTprojectId($tproject_mgr) {
    // accept both naming conventions used by the modernized screens
    $tpid = getIntParam('tprojectId', 0);
    if ($tpid <= 0) {
        $tpid = getIntParam('tproject_id', 0);
    }
    if ($tpid <= 0) {
        $tpid = intval($_SESSION['testprojectID'] ?? 0);
    }
    if ($tpid <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Test project is mandatory']);
    }
    $item = $tproject_mgr->get_by_id($tpid);
    if (!$item) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Test project not found']);
    }
    return [$tpid, $item];
}

// Route: GET /search-context - everything the Search Requirements form needs to render
if ($method === 'GET' && isset($segments[0]) && $segments[0] === 'search-context') {
    list($tpid, $info) = resolveTprojectId($tproject_mgr);

    if (!$user->hasRight($db, 'mgt_view_req', $tpid)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }

    $tcaseCfg = config_get('testcase_cfg');
    $prefix = $tproject_mgr->getTestCasePrefix($tpid) . $tcaseCfg->glue_character;

    $keywords = [];
    $kwSet = $tproject_mgr->getKeywords($tpid);
    $hasKeywords = !is_null($kwSet) && count($kwSet) > 0;
    if ($hasKeywords) {
        foreach ($kwSet as $kwo) {
            $keywords[] = ['id' => intval($kwo->dbID), 'name' => $kwo->name];
        }
    }

    // custom fields linked to requirement at design time (enabled only)
    $customFields = [];
    $designCf = $tproject_mgr->cfield_mgr->get_linked_cfields_at_design(
        $tpid, cfield_mgr::ENABLED, null, 'requirement');
    $hasCustomFields = !is_null($designCf) && count($designCf) > 0;
    if ($hasCustomFields) {
        foreach ($designCf as $cf_id => $cf) {
            $customFields[] = [
                'id' => intval($cf_id),
                'label' => $cf['label'],
                'type' => intval($cf['type']),
                'possible_values' => isset($cf['possible_values']) ? (string)$cf['possible_values'] : '',
            ];
        }
    }

    // requirement doc ids exist?
    $reqSpecSet = $tproject_mgr->getOptionReqSpec($tpid, testproject::GET_NOT_EMPTY_REQSPEC);
    $filterByDocId = !is_null($reqSpecSet);

    $reqCfg = config_get('req_cfg');

    // type domain: same source as legacy form
    $typeLabels = init_labels($reqCfg->type_labels);
    $types = [];
    foreach ($typeLabels as $code => $lbl) {
        $types[] = ['code' => (string)$code, 'label' => $lbl];
    }

    // status domain
    $statusLabels = init_labels($reqCfg->status_labels);
    $statusDomain = [];
    foreach ($statusLabels as $code => $lbl) {
        $statusDomain[] = ['code' => (string)$code, 'label' => $lbl];
    }

    // expected coverage management enabled?
    $coverageEnabled = !empty($reqCfg->expected_coverage_management);

    // relation type select (only if relations enabled)
    $relationItems = [];
    $relationsEnabled = false;
    if (!is_null($reqCfg->relations) && !empty($reqCfg->relations->enable)) {
        $relationsEnabled = true;
        $relSel = $req_mgr->init_relation_type_select();
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
            'max_results' => intval($reqCfg->search->max_qty_for_display),
        ],
        'filters' => [
            'keyword' => $hasKeywords,
            'design_scope_custom_fields' => $hasCustomFields,
            'requirement_doc_id' => $filterByDocId,
            'expected_coverage' => $coverageEnabled,
            'relation_type' => $relationsEnabled,
        ],
        'keywords' => $keywords,
        'custom_fields' => $customFields,
        'types' => $types,
        'statuses' => $statusDomain,
        'relation_types' => $relationItems,
    ]);
}

// Route: GET /search - run the Search Requirements
if ($method === 'GET' && isset($segments[0]) && $segments[0] === 'search') {
    list($tpid, $dummyInfo) = resolveTprojectId($tproject_mgr);

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
            $pid = $tproject_mgr->tree_manager->getTreeRoot($item);
            if ($pid != $tpid) {
                unset($map[$item]);
            }
        }
    }

    $rowQty = count($map);
    $reqCfg = config_get('req_cfg');
    $maxDisplay = intval($reqCfg->search->max_qty_for_display);

    $resultSet = [];
    $tooWide = false;
    if ($rowQty > 0) {
        if ($rowQty <= $maxDisplay) {
            $req_set = array_keys($map);
            $options = ['output_format' => 'path_as_string'];
            $pathInfo = $tproject_mgr->tree_manager->get_full_path_verbose($req_set, $options);

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

// Route: GET /monitor-overview - list all requirements with monitor state
if ($method === 'GET' && isset($segments[0]) && $segments[0] === 'monitor-overview') {
    if (!$user->hasRight($db, 'mgt_view_req')) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
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

// Route: POST /monitor - subscribe/unsubscribe current user on a requirement
if ($method === 'POST' && isset($segments[0]) && $segments[0] === 'monitor') {
    if (!$user->hasRight($db, 'mgt_view_req')) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'No permission']);
    }

    list($tpid, $dummy) = resolveTprojectId($tproject_mgr);

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
    $reqInfo = $req_mgr->get_by_id($reqId);
    if (!$reqInfo || intval($reqInfo['tproject_id']) !== $tpid) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Requirement not found in this project']);
    }

    if ($action === 'on') {
        $req_mgr->monitorOn($reqId, $userId, $tpid);
    } else {
        $req_mgr->monitorOff($reqId, $userId, $tpid);
    }

    $monitoredSet = $req_mgr->getMonitoredByUser($userId, $tpid);
    out([
        'status' => 'ok',
        'reqId' => $reqId,
        'action' => $action,
        'monitored' => isset($monitoredSet[$reqId]),
    ]);
}

http_response_code(404);
out(['status' => 'error', 'message' => 'Unknown endpoint']);

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
