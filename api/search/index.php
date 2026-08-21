<?php
/**
 * Search BFF API - quick test case search
 * URL: /api/search/
 * Plain PHP, no framework, no compilation
 *
 * Mirrors lib/search/searchForm.php + lib/testcases/tcSearch.php
 * (TestLink 1.9.20 quick "Search Test Cases" behavior):
 * search is done ONLY ON CURRENT test project, all text criteria are
 * AND-ed (LIKE '%value%'), result cap = testcase_cfg.search.max_qty_for_display.
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
    return is_string($v) ? trim($v) : $v;
}

// same sanitize the legacy applies to targetTestCase (remove blanks/html/parens)
function sanitizeTargetTestCase($v) {
    return str_replace([' ', '<', '>', '(', ')'], '', (string)$v);
}

function validateSearchDate($dateToValidate, $format = 'Y-m-d') {
    $ddd = DateTime::createFromFormat($format, $dateToValidate);
    return $ddd && $ddd->format($format) === $dateToValidate;
}

$action = $_GET['action'] ?? '';
$tprojectId = intval($_GET['tproject_id'] ?? ($_SESSION['testprojectID'] ?? 0));

if ($tprojectId <= 0) {
    http_response_code(400);
    out(['status' => 'error', 'message' => 'Invalid test project id']);
}

// menu visibility rule (common.php getMenuVisibility): search needs view_tc,
// which maps to the 'mgt_view_tc' right string
if (!$user->hasRight($db, 'mgt_view_tc', $tprojectId)) {
    http_response_code(403);
    out(['status' => 'error', 'message' => 'No permission']);
}

$tproject_mgr = new testproject($db);
$tcase_mgr = new testcase($db);
$tcaseCfg = config_get('testcase_cfg');
$glue = $tcaseCfg->glue_character;

// ---------------------------------------------------------------------------
// GET ?action=context&tproject_id=N - everything the form needs to render
// ---------------------------------------------------------------------------
if ($action === 'context') {
    $info = $tproject_mgr->get_by_id($tprojectId);
    if (!$info) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Test project not found']);
    }

    $prefix = $tproject_mgr->getTestCasePrefix($tprojectId) . $glue;

    $keywords = [];
    $kwSet = $tproject_mgr->getKeywords($tprojectId);
    if (!is_null($kwSet)) {
        foreach ($kwSet as $kwo) {
            $keywords[] = ['id' => intval($kwo->dbID), 'name' => $kwo->name];
        }
    }

    // custom fields linked to testcase at design time (enabled only)
    $customFields = [];
    $designCf = $tproject_mgr->cfield_mgr->get_linked_cfields_at_design(
        $tprojectId, cfield_mgr::ENABLED, null, 'testcase');
    if (!is_null($designCf)) {
        foreach ($designCf as $cf_id => $cf) {
            $customFields[] = [
                'id' => intval($cf_id),
                'label' => $cf['label'],
                'type' => intval($cf['type']),
            ];
        }
    }

    // status domain: {code: localized label}, same source as legacy
    $dummy = getConfigAndLabels('testCaseStatus', 'code');
    $statusDomain = [];
    foreach ($dummy['lbl'] as $code => $lbl) {
        $statusDomain[] = ['code' => intval($code), 'label' => $lbl];
    }

    $opt = $tproject_mgr->getOptions($tprojectId);
    $opt = is_null($opt) ? new stdClass() : $opt;

    out([
        'status' => 'ok',
        'tproject' => ['id' => $tprojectId, 'name' => $info['name']],
        'tcasePrefix' => $prefix,
        'keywords' => $keywords,
        'customFields' => $customFields,
        'statusDomain' => $statusDomain,
        'importanceEnabled' => !empty($opt->testPriorityEnabled),
        'requirementsEnabled' => !empty($opt->requirementsEnabled),
        'maxQtyForDisplay' => intval($tcaseCfg->search->max_qty_for_display),
        'dateFormat' => config_get('date_format'),
    ]);
}

// ---------------------------------------------------------------------------
// GET ?action=search&tproject_id=N&<criteria> - run the search
// ---------------------------------------------------------------------------
if ($action === 'search') {
    $tables = tlObjectWithDB::getDBTables(array('cfield_design_values', 'nodes_hierarchy',
        'requirements', 'req_coverage', 'tcsteps', 'testcase_keywords',
        'tcversions', 'users'));

    $args = new stdClass();
    $args->targetTestCase = sanitizeTargetTestCase(getParam('targetTestCase'));
    $args->version = intval(getParam('version'));
    $args->name = getParam('name');
    $args->summary = getParam('summary');
    $args->preconditions = getParam('preconditions');
    $args->steps = getParam('steps');
    $args->expected_results = getParam('expected_results');
    $args->created_by = getParam('created_by');
    $args->edited_by = getParam('edited_by');
    $args->creation_date_from = getParam('creation_date_from');
    $args->creation_date_to = getParam('creation_date_to');
    $args->modification_date_from = getParam('modification_date_from');
    $args->modification_date_to = getParam('modification_date_to');
    $args->importance = intval(getParam('importance'));
    $args->status = intval(getParam('status'));
    $args->keyword_id = intval(getParam('keyword_id'));
    $args->custom_field_id = intval(getParam('custom_field_id'));
    $args->custom_field_value = getParam('custom_field_value');
    $args->requirement_doc_id = getParam('requirement_doc_id');

    $warning = '';
    $emptyTestProject = false;
    $applyFilters = true;

    $prefix = $tproject_mgr->getTestCasePrefix($tprojectId) . $glue;

    $from = array('by_keyword_id' => ' ', 'by_custom_field' => ' ',
                  'by_requirement_doc_id' => '', 'users' => '');
    $filter = array();
    $tcaseID = null;

    if ($args->targetTestCase != "" && strcmp($args->targetTestCase, $prefix) != 0) {
        if (strpos($args->targetTestCase, $glue) === false) {
            $args->targetTestCase = $prefix . $args->targetTestCase;
        }
        $tcaseID = $tcase_mgr->getInternalID($args->targetTestCase);
        $filter['by_tc_id'] = " AND NH_TCV.parent_id = " . intval($tcaseID);
    } else {
        $a_tcid = null;
        $tproject_mgr->get_all_testcases_id($tprojectId, $a_tcid);
        if (!is_null($a_tcid)) {
            $filter['by_tc_id'] = " AND NH_TCV.parent_id IN (" . implode(",", $a_tcid) . ") ";
        } else {
            $emptyTestProject = true;
            $filter['by_tc_id'] = " AND 1 = 0 ";
        }
    }

    if ($args->version > 0) {
        $filter['by_version'] = " AND TCV.version = {$args->version} ";
    }

    if ($args->keyword_id > 0) {
        $from['by_keyword_id'] = " JOIN {$tables['testcase_keywords']} KW ON KW.testcase_id = NH_TC.id ";
        $filter['by_keyword_id'] = " AND KW.keyword_id  = " . $args->keyword_id;
    }

    // plain text fields -> LIKE '%value%', all AND-ed (quick search has no jolly)
    $likeFields = array('name' => 'NH_TC', 'summary' => 'TCV', 'preconditions' => 'TCV');
    foreach ($likeFields as $kf => $alias) {
        if ($args->$kf != "") {
            $safe = $db->prepare_string($args->$kf);
            $filter[$kf] = " AND {$alias}.{$kf} like '%{$safe}%' ";
        }
    }

    // steps / expected results live in tcsteps (LEFT JOINed, may be multi-row)
    if ($args->steps != "") {
        $safe = $db->prepare_string($args->steps);
        $filter['by_steps'] = " AND TCSTEPS.actions like '%{$safe}%' ";
    }
    if ($args->expected_results != "") {
        $safe = $db->prepare_string($args->expected_results);
        $filter['by_expected_results'] = " AND TCSTEPS.expected_results like '%{$safe}%' ";
    }

    if ($args->custom_field_id > 0) {
        $designCf = $tproject_mgr->cfield_mgr->get_linked_cfields_at_design(
            $tprojectId, cfield_mgr::ENABLED, null, 'testcase');
        $cf_def = isset($designCf[$args->custom_field_id]) ? $designCf[$args->custom_field_id] : null;
        if (is_null($cf_def)) {
            http_response_code(400);
            out(['status' => 'error', 'message' => 'Unknown custom field']);
        }
        $from['by_custom_field'] = " JOIN {$tables['cfield_design_values']} CFD " .
                                   " ON CFD.node_id=NH_TCV.id ";
        $filter['by_custom_field'] = " AND CFD.field_id={$args->custom_field_id} ";

        $cfTypes = $tproject_mgr->cfield_mgr->custom_field_types;
        switch ($cfTypes[$cf_def['type']]) {
            case 'date':
                $cfValue = $tproject_mgr->cfield_mgr->cfdate2mktime($args->custom_field_value);
                $filter['by_custom_field'] .= " AND CFD.value = {$cfValue}";
                break;

            case 'datetime':
                $cfValue = $tproject_mgr->cfield_mgr->cfdatetime2mktime($args->custom_field_value);
                $filter['by_custom_field'] .= " AND CFD.value = {$cfValue}";
                break;

            default:
                $safe = $db->prepare_string($args->custom_field_value);
                $filter['by_custom_field'] .= " AND CFD.value like '%{$safe}%' ";
                break;
        }
    }

    if ($args->requirement_doc_id != "") {
        $safe = $db->prepare_string($args->requirement_doc_id);
        $from['by_requirement_doc_id'] = " JOIN {$tables['req_coverage']} RC" .
                                         " ON RC.testcase_id = NH_TC.id " .
                                         " JOIN {$tables['requirements']} REQ " .
                                         " ON REQ.id=RC.req_id ";
        $filter['by_requirement_doc_id'] = " AND REQ.req_doc_id like '%{$safe}%' ";
    }

    if ($args->importance > 0) {
        $filter['importance'] = " AND TCV.importance = {$args->importance} ";
    }

    if ($args->status > 0) {
        $filter['status'] = " AND TCV.status = {$args->status} ";
    }

    if ($args->created_by != '') {
        $safe = $db->prepare_string($args->created_by);
        $from['users'] .= " JOIN {$tables['users']} AUTHOR ON AUTHOR.id = TCV.author_id ";
        $filter['author'] = " AND ( AUTHOR.login LIKE '%{$safe}%' OR " .
                            "       AUTHOR.first LIKE '%{$safe}%' OR " .
                            "       AUTHOR.last LIKE '%{$safe}%') ";
    }

    if ($args->edited_by != '') {
        $safe = $db->prepare_string($args->edited_by);
        $from['users'] .= " JOIN {$tables['users']} UPDATER ON UPDATER.id = TCV.updater_id ";
        $filter['modifier'] = " AND ( UPDATER.login LIKE '%{$safe}%' OR " .
                              "         UPDATER.first LIKE '%{$safe}%' OR " .
                              "         UPDATER.last LIKE '%{$safe}%') ";
    }

    // date range filters, honoring the configured date format
    $k2w = array('creation_date_from' => '', 'creation_date_to' => " 23:59:59",
                 'modification_date_from' => '', 'modification_date_to' => " 23:59:59");
    $k2f = array('creation_date_from' => ' creation_ts >= ',
                 'creation_date_to' => 'creation_ts <= ',
                 'modification_date_from' => ' modification_ts >= ',
                 'modification_date_to' => ' modification_ts <= ');
    $dateFormat = config_get('date_format');
    $PHPdateFormat = str_replace('%', '', $dateFormat);
    foreach ($k2w as $key => $value) {
        if ($args->$key != '' && validateSearchDate($args->$key, $PHPdateFormat)) {
            $da = split_localized_date($args->$key, $dateFormat);
            if ($da != null) {
                $iso = $da['year'] . "-" . $da['month'] . "-" . $da['day'] . $value;
                $filter[$key] = " AND TCV.{$k2f[$key]} '{$iso}' ";
            }
        } else {
            $args->$key = '';
        }
    }

    if (!is_null($tcaseID) && $tcaseID <= 0) {
        $applyFilters = false;
        $warning = 'testcase_does_not_exists';
    }

    $rows = [];
    $count = 0;
    if ($applyFilters) {
        $sqlPart2 = " FROM {$tables['nodes_hierarchy']} NH_TC " .
                    " JOIN {$tables['nodes_hierarchy']} NH_TCV ON NH_TCV.parent_id = NH_TC.id " .
                    " JOIN {$tables['tcversions']} TCV ON NH_TCV.id = TCV.id " .
                    " LEFT OUTER JOIN {$tables['nodes_hierarchy']} NH_TCSTEPS ON NH_TCSTEPS.parent_id = NH_TCV.id " .
                    " LEFT OUTER JOIN {$tables['tcsteps']} TCSTEPS ON NH_TCSTEPS.id = TCSTEPS.id " .
                    " {$from['by_keyword_id']} {$from['by_custom_field']} {$from['by_requirement_doc_id']} " .
                    " {$from['users']} " .
                    " WHERE 1=1 " . implode("", $filter);

        $sqlCount = "SELECT COUNT(DISTINCT(NH_TC.id)) " . $sqlPart2;
        $count = intval($db->fetchOneValue($sqlCount));

        if ($count > 0) {
            if ($count <= intval($tcaseCfg->search->max_qty_for_display)) {
                $sqlFields = " SELECT DISTINCT NH_TC.id AS testcase_id,NH_TC.name,TCV.id AS tcversion_id," .
                             " TCV.summary, TCV.version, TCV.tc_external_id ";
                $map = $db->fetchRowsIntoMap($sqlFields . $sqlPart2, 'testcase_id');
                if (!is_null($map)) {
                    $options = array('output_format' => 'path_as_string');
                    $pathInfo = $tproject_mgr->tree_manager->get_full_path_verbose(
                        array_keys($map), $options);
                    foreach ($map as $rec) {
                        $tcid = $rec['testcase_id'];
                        $rows[] = [
                            'testcase_id' => intval($tcid),
                            'tcversion_id' => intval($rec['tcversion_id']),
                            'name' => $rec['name'],
                            'summary' => (string)$rec['summary'],
                            'version' => intval($rec['version']),
                            'tc_external_id' => intval($rec['tc_external_id']),
                            'full_external_id' => $prefix . $rec['tc_external_id'],
                            'path' => isset($pathInfo[$tcid]) ? $pathInfo[$tcid] : '',
                        ];
                    }
                }
            } else {
                $warning = 'too_wide_search_criteria';
                $count = 0;
                $rows = [];
            }
        }
    }

    if ($warning === '' && $emptyTestProject) {
        $warning = 'empty_testproject';
    } elseif ($warning === '' && $count == 0 && !$emptyTestProject && $applyFilters) {
        $warning = 'no_records_found';
    }

    out([
        'status' => 'ok',
        'count' => $count,
        'rows' => $rows,
        'warning' => $warning,
        'tcasePrefix' => $prefix,
    ]);
}

http_response_code(400);
out(['status' => 'error', 'message' => 'Bad request']);
