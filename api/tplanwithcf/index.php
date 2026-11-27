<?php
require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function tpwcOut($payload, $code = null)
{
    if ($code !== null) {
        http_response_code($code);
    }
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function tpwcFail($httpCode, $code, $message = '')
{
    tpwcOut(array(
        'status' => 'error',
        'code' => $code,
        'message' => $message !== '' ? $message : $code,
    ), $httpCode);
}

function tpwcPositiveInt($raw)
{
    if ($raw === null || is_array($raw)) {
        return 0;
    }
    $raw = trim((string)$raw);
    if ($raw === '' || !preg_match('/^[0-9]+$/', $raw)) {
        return 0;
    }
    $n = intval($raw);
    return $n > 0 ? $n : 0;
}

function tpwcDecode($value)
{
    return html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function tpwcClean($value)
{
    return trim(preg_replace('!\s+!', ' ', tpwcDecode($value)));
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    tpwcFail(405, 'method_not_allowed');
}

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    tpwcFail(401, 'not_authenticated');
}
bffEnforceSession($db);

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    tpwcFail(401, 'not_authenticated');
}

$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if (!in_array($action, array('init', 'report'), true)) {
    tpwcFail(400, 'invalid_request');
}

$tplanId = tpwcPositiveInt($_GET['tplan_id'] ?? null);
$assertedProjectId = tpwcPositiveInt($_GET['tproject_id'] ?? null);
$cfId = tpwcPositiveInt($_GET['cf_id'] ?? null);
$scope = strtolower(trim((string)($_GET['scope'] ?? 'all')));
$q = trim((string)($_GET['q'] ?? ''));
if (!in_array($scope, array('all', 'filled', 'missing'), true) || strlen($q) > 200) {
    tpwcFail(400, 'invalid_request');
}
if ($tplanId <= 0) {
    tpwcFail(400, 'invalid_request');
}

$tbl = tlObjectWithDB::getDBTables(array(
    'testplans', 'testprojects', 'nodes_hierarchy', 'testplan_tcversions',
    'tcversions', 'cfield_testplan_design_values', 'cfield_testprojects',
    'cfield_node_types', 'node_types', 'custom_fields',
));

$planRs = $db->get_recordset(
    "SELECT id, testproject_id FROM {$tbl['testplans']} WHERE id = " . intval($tplanId));
if (!is_array($planRs) || count($planRs) === 0) {
    tpwcFail(404, 'tplan_not_found');
}
$owningProjectId = intval($planRs[0]['testproject_id']);
if ($owningProjectId <= 0) {
    tpwcFail(404, 'tplan_not_found');
}
if (!$user->hasRight($db, 'testplan_metrics', $owningProjectId, $tplanId, true)) {
    tLog('BFF tplanwithcf: user ' . intval($userId) .
         ' has no testplan_metrics right on tproject ' . intval($owningProjectId) .
         ' (tplan ' . intval($tplanId) . ')', 'AUDIT');
    tpwcFail(403, 'no_right');
}
if ($assertedProjectId > 0 && $assertedProjectId !== $owningProjectId) {
    tpwcFail(404, 'project_mismatch');
}

$planName = testplan::getName($db, $tplanId);
$projectRs = $db->get_recordset(
    "SELECT TP.prefix, NH.name FROM {$tbl['testprojects']} TP" .
    " JOIN {$tbl['nodes_hierarchy']} NH ON NH.id = TP.id" .
    " WHERE TP.id = " . intval($owningProjectId));
if (is_null($planName) || trim((string)$planName) === '' ||
    !is_array($projectRs) || count($projectRs) === 0) {
    tpwcFail(404, 'tplan_not_found');
}
$projectName = tpwcDecode($projectRs[0]['name'] ?? '');
$prefix = tpwcDecode($projectRs[0]['prefix'] ?? '');

$context = array(
    'tplan_id' => $tplanId,
    'tplan_name' => tpwcDecode((string)$planName),
    'tproject_id' => $owningProjectId,
    'tproject_name' => $projectName,
    'prefix' => $prefix,
);

$nodeTypeRs = $db->get_recordset(
    "SELECT id FROM {$tbl['node_types']} WHERE description = 'testcase'");
$testcaseNodeTypeId = (is_array($nodeTypeRs) && count($nodeTypeRs) > 0)
    ? intval($nodeTypeRs[0]['id']) : 0;
if ($testcaseNodeTypeId <= 0) {
    tpwcFail(404, 'custom_fields_not_found');
}

$fieldRs = $db->get_recordset(
    "SELECT CF.id, CF.name, CF.label, CF.type, CF.display_order " .
    "FROM {$tbl['custom_fields']} CF " .
    "JOIN {$tbl['cfield_testprojects']} CFTP ON CFTP.field_id = CF.id " .
    "JOIN {$tbl['cfield_node_types']} CFNT ON CFNT.field_id = CF.id " .
    "WHERE CFTP.testproject_id = " . intval($owningProjectId) .
    " AND CFTP.active = 1 AND CF.enable_on_testplan_design = 1" .
    " AND CFNT.node_type_id = " . intval($testcaseNodeTypeId) .
    " ORDER BY CFTP.display_order, CF.id");

$customFields = array();
if (is_array($fieldRs)) {
    foreach ($fieldRs as $f) {
        $customFields[] = array(
            'id' => intval($f['id']),
            'name' => (string)$f['name'],
            'label' => tpwcDecode($f['label'] ?? ''),
            'type' => (string)($f['type'] ?? ''),
        );
    }
}

if ($cfId > 0) {
    $known = false;
    foreach ($customFields as $f) {
        if ($f['id'] === $cfId) {
            $known = true;
            break;
        }
    }
    if (!$known) {
        tpwcFail(400, 'invalid_request');
    }
}

$filter = array('cf_id' => $cfId, 'scope' => $scope, 'q' => $q);
if ($action === 'init') {
    tpwcOut(array(
        'status' => 'ok',
        'code' => 200,
        'context' => $context,
        'filter' => $filter,
        'custom_fields' => $customFields,
    ));
}

$assignedRs = $db->get_recordset(
    "SELECT TPTC.id AS link_id, TPTC.tcversion_id, TCV.tc_external_id, TCV.version, " .
    "       NHB.id AS tcase_id, NHB.name AS tcase_name " .
    "FROM {$tbl['testplan_tcversions']} TPTC " .
    "JOIN {$tbl['tcversions']} TCV ON TCV.id = TPTC.tcversion_id " .
    "JOIN {$tbl['nodes_hierarchy']} NHA ON NHA.id = TPTC.tcversion_id " .
    "JOIN {$tbl['nodes_hierarchy']} NHB ON NHB.id = NHA.parent_id " .
    "WHERE TPTC.testplan_id = " . intval($tplanId) .
    " ORDER BY NHB.name, TCV.tc_external_id, TCV.version");

$valuesRs = $db->get_recordset(
    "SELECT CFTDV.link_id, CFTDV.field_id, CFTDV.value " .
    "FROM {$tbl['cfield_testplan_design_values']} CFTDV " .
    "JOIN {$tbl['testplan_tcversions']} TPTC ON TPTC.id = CFTDV.link_id " .
    "WHERE TPTC.testplan_id = " . intval($tplanId));

$valueMap = array();
if (is_array($valuesRs)) {
    foreach ($valuesRs as $v) {
        $linkId = intval($v['link_id']);
        if (!isset($valueMap[$linkId])) {
            $valueMap[$linkId] = array();
        }
        $valueMap[$linkId][intval($v['field_id'])] = tpwcClean($v['value'] ?? '');
    }
}

$pathCache = array();
function tpwcSuitePath($db, $tbl, $tcaseId, &$cache)
{
    if (isset($cache[$tcaseId])) {
        return $cache[$tcaseId];
    }
    $parts = array();
    $current = intval($tcaseId);
    $guard = 0;
    while ($current > 0 && $guard < 40) {
        $guard++;
        $rs = $db->get_recordset(
            "SELECT id, name, parent_id, node_type_id FROM {$tbl['nodes_hierarchy']}" .
            " WHERE id = " . intval($current));
        if (!is_array($rs) || count($rs) === 0) {
            break;
        }
        $node = $rs[0];
        $parts[] = tpwcDecode($node['name'] ?? '');
        $current = intval($node['parent_id'] ?? 0);
    }
    $cache[$tcaseId] = implode(' / ', array_reverse($parts));
    return $cache[$tcaseId];
}

$rows = array();
$assignedCount = 0;
$filledCount = 0;
$filledCells = 0;
if (is_array($assignedRs)) {
    foreach ($assignedRs as $a) {
        $assignedCount++;
        $linkId = intval($a['link_id']);
        $values = isset($valueMap[$linkId]) ? $valueMap[$linkId] : array();
        $filled = 0;
        foreach ($customFields as $f) {
            if (isset($values[$f['id']]) && $values[$f['id']] !== '') {
                $filled++;
            }
        }
        if ($filled > 0) {
            $filledCount++;
            $filledCells += $filled;
        }
        if ($scope === 'filled' && $filled === 0) {
            continue;
        }
        if ($scope === 'missing' && $filled > 0) {
            continue;
        }
        $tcaseId = intval($a['tcase_id']);
        $name = $prefix . '-' . intval($a['tc_external_id']) .
                ' — ' . tpwcDecode($a['tcase_name'] ?? '');
        if ($q !== '' && stripos(tpwcSuitePath($db, $tbl, $tcaseId, $pathCache) . ' ' . $name, $q) === false) {
            continue;
        }
        if ($cfId > 0 && (!isset($values[$cfId]) || $values[$cfId] === '')) {
            continue;
        }
        $cells = array();
        foreach ($customFields as $f) {
            $cells[] = isset($values[$f['id']]) ? $values[$f['id']] : '';
        }
        $rows[] = array(
            'link_id' => $linkId,
            'tcase_id' => $tcaseId,
            'tcversion_id' => intval($a['tcversion_id']),
            'version' => intval($a['version'] ?? 1),
            'external_id' => intval($a['tc_external_id']),
            'name' => $name,
            'suite_path' => tpwcSuitePath($db, $tbl, $tcaseId, $pathCache),
            'filled' => $filled,
            'cells' => $cells,
        );
    }
}

$possibleCells = $assignedCount * count($customFields);
tpwcOut(array(
    'status' => 'ok',
    'code' => 200,
    'context' => $context,
    'filter' => $filter,
    'custom_fields' => $customFields,
    'rows' => $rows,
    'kpis' => array(
        'assigned' => $assignedCount,
        'with_values' => $filledCount,
        'without_values' => $assignedCount - $filledCount,
        'custom_fields' => count($customFields),
        'filled_cells' => $filledCells,
        'coverage_pct' => $possibleCells > 0
            ? round(100.0 * $filledCells / $possibleCells, 1) : 0.0,
        'shown' => count($rows),
    ),
));
