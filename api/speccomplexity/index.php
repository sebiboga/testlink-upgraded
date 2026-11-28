<?php
/**
 * Test Specification Complexity Profile BFF (Refs #2092).
 *
 * Actions: GET|HEAD ?action=init|report|case
 */
require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
require_once('testproject.class.php');

doSessionStart();
require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function scxOut($payload, $code = null)
{
    if ($code !== null) {
        http_response_code($code);
    }
    echo json_encode($payload);
    exit;
}

function scxFail($httpCode, $code, $message = '')
{
    scxOut(array(
        'status' => 'error',
        'code' => $code,
        'message' => $message !== '' ? $message : $code,
    ), $httpCode);
}

function scxPositiveInt($raw)
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

function scxDecode($value)
{
    return html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function scxPct($num, $den)
{
    return $den > 0 ? round(100.0 * $num / $den, 1) : 0.0;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    scxFail(405, 'method_not_allowed');
}

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    scxFail(401, 'not_authenticated');
}
bffEnforceSession($db);
$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    scxFail(401, 'not_authenticated');
}

$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if (!in_array($action, array('init', 'report', 'case'), true)) {
    scxFail(400, 'invalid_request');
}

$tprojectId = scxPositiveInt($_GET['tproject_id'] ?? null);
if ($tprojectId <= 0) {
    $tprojectId = scxPositiveInt($_SESSION['testprojectID'] ?? null);
}
if ($tprojectId <= 0) {
    scxFail(400, 'invalid_request');
}

if (!$user->hasRight($db, 'mgt_view_tc', $tprojectId, 0, true)) {
    tLog('BFF speccomplexity: user ' . intval($userId) .
         ' has no mgt_view_tc right on tproject ' . $tprojectId, 'AUDIT');
    scxFail(403, 'no_right');
}

$tbl = tlObjectWithDB::getDBTables(array(
    'testprojects', 'nodes_hierarchy', 'tcversions', 'tcsteps', 'req_coverage',
    'object_keywords', 'keywords', 'custom_fields', 'cfield_testprojects',
    'cfield_design_values', 'req_versions',
));

$projRs = $db->get_recordset(
    "SELECT p.id, p.prefix, n.name AS project_name " .
    "FROM {$tbl['testprojects']} p " .
    "JOIN {$tbl['nodes_hierarchy']} n ON n.id = p.id " .
    "WHERE p.id = " . intval($tprojectId));
if (!is_array($projRs) || count($projRs) === 0) {
    scxFail(404, 'tproject_not_found');
}
$project = $projRs[0];
$projectPrefix = (string)$project['prefix'];
$context = array(
    'tproject_id' => intval($tprojectId),
    'tproject_name' => scxDecode($project['project_name']),
    'prefix' => (string)$project['prefix'],
);

$treeSql =
    "WITH RECURSIVE tree AS (" .
    "  SELECT n.id, n.name, n.parent_id, n.node_type_id, 0 AS depth " .
    "  FROM {$tbl['nodes_hierarchy']} n " .
    "  WHERE n.parent_id = " . intval($tprojectId) . " AND n.node_type_id IN (2,3) " .
    "  UNION ALL " .
    "  SELECT c.id, c.name, c.parent_id, c.node_type_id, t.depth + 1 " .
    "  FROM {$tbl['nodes_hierarchy']} c " .
    "  JOIN tree t ON c.parent_id = t.id AND c.node_type_id IN (2,3) " .
    "  WHERE t.depth < 32" .
    ") SELECT id, name, parent_id, node_type_id, depth FROM tree";

$suites = array();
$cases = array();
$treeRs = $db->get_recordset($treeSql);
if (is_array($treeRs)) {
    foreach ($treeRs as $r) {
        $id = intval($r['id']);
        if (intval($r['node_type_id']) === 2) {
            $suites[$id] = array('name' => scxDecode($r['name']), 'parent' => intval($r['parent_id']));
        } elseif (intval($r['node_type_id']) === 3) {
            $cases[$id] = array('name' => scxDecode($r['name']), 'suite' => intval($r['parent_id']));
        }
    }
}

$suitePath = array();
$scxWalk = function ($id) use (&$suitePath, &$suites) {
    if (isset($suitePath[$id])) {
        return $suitePath[$id];
    }
    $parts = array();
    $cur = $id;
    $guard = 0;
    while ($cur > 0 && isset($suites[$cur]) && $guard < 32) {
        $parts[] = $suites[$cur]['name'];
        $cur = $suites[$cur]['parent'];
        $guard++;
    }
    $suitePath[$id] = implode(' / ', array_reverse($parts));
    return $suitePath[$id];
};

$suiteList = array();
foreach ($suites as $id => $s) {
    $suiteList[] = array('id' => $id, 'name' => $s['name'], 'path' => $scxWalk($id));
}
usort($suiteList, function ($a, $b) {
    return strcasecmp($a['path'], $b['path']);
});

$bandCatalogue = array('high' => 3, 'medium' => 2, 'low' => 1);
$filters = array(
    'suites' => $suiteList,
    'bands' => array_keys($bandCatalogue),
);

if ($action === 'init') {
    scxOut(array(
        'status' => 'ok',
        'code' => 200,
        'context' => $context,
        'filters' => $filters,
        'limits' => array('min_steps' => 1),
    ));
}

$suiteId = scxPositiveInt($_GET['suite_id'] ?? null);
$bandFilter = isset($_GET['band']) && !is_array($_GET['band'])
    ? strtolower(trim((string)$_GET['band'])) : '';
if ($bandFilter === '' || $bandFilter === 'all' || $bandFilter === '0') {
    $bandFilter = '';
}
if ($bandFilter !== '' && !isset($bandCatalogue[$bandFilter])) {
    scxFail(400, 'invalid_request');
}
if ($suiteId > 0 && !isset($suites[$suiteId])) {
    scxFail(400, 'invalid_request');
}

$minSteps = 1;
if (isset($_GET['min_steps']) && !is_array($_GET['min_steps'])) {
    $raw = trim((string)$_GET['min_steps']);
    if ($raw === '' || !preg_match('/^[0-9]+$/', $raw)) {
        scxFail(400, 'invalid_request');
    }
    $minSteps = intval($raw);
    if ($minSteps < 1 || $minSteps > 100) {
        scxFail(400, 'invalid_request');
    }
}

$tcaseId = 0;
if ($action === 'case') {
    $tcaseId = scxPositiveInt($_GET['tcase_id'] ?? null);
    if ($tcaseId <= 0 || !isset($cases[$tcaseId])) {
        scxFail(404, 'tcase_not_found');
    }
}

$scope = array();
foreach ($cases as $id => $c) {
    if ($action === 'case') {
        if ($id === $tcaseId) {
            $scope[$id] = $c;
        }
        continue;
    }
    if ($suiteId > 0) {
        $inScope = false;
        $cur = intval($c['suite']);
        $guard = 0;
        while ($cur > 0 && $guard < 32) {
            if ($cur === $suiteId) {
                $inScope = true;
                break;
            }
            $cur = isset($suites[$cur]) ? intval($suites[$cur]['parent']) : 0;
            $guard++;
        }
        if (!$inScope) {
            continue;
        }
    }
    $scope[$id] = $c;
}
$scopeIds = array_keys($scope);

$versions = array();
$caseVersions = array();
$latestVersion = array();
$externalId = array();
if (count($scopeIds) > 0) {
    foreach (array_chunk($scopeIds, 500) as $chunk) {
        $in = implode(',', array_map('intval', $chunk));
        $verRs = $db->get_recordset(
            "SELECT vn.id AS tcversion_id, vn.parent_id AS tcase_id, tv.tc_external_id, " .
            "       tv.version, tv.summary, tv.importance, tv.execution_type, tv.active " .
            "FROM {$tbl['nodes_hierarchy']} vn " .
            "JOIN {$tbl['tcversions']} tv ON tv.id = vn.id " .
            "WHERE vn.node_type_id = 4 AND vn.parent_id IN (" . $in . ") " .
            "ORDER BY tv.version, vn.id");
        if (is_array($verRs)) {
            foreach ($verRs as $r) {
                $tvid = intval($r['tcversion_id']);
                $cid = intval($r['tcase_id']);
                $versions[$tvid] = array(
                    'version' => intval($r['version']),
                    'summary' => scxDecode($r['summary']),
                    'external_id' => intval($r['tc_external_id']),
                    'importance' => intval($r['importance']),
                    'execution_type' => intval($r['execution_type']),
                    'active' => intval($r['active']),
                );
                $caseVersions[$cid][] = $tvid;
                $latestVersion[$cid] = $tvid;
                $externalId[$cid] = intval($r['tc_external_id']);
            }
        }
    }
}

$versionIds = array_keys($versions);
$stepStats = array();
$reqCounts = array();
$kwCounts = array();
$cfCounts = array();
foreach (array_chunk($versionIds, 400) as $chunk) {
    $in = implode(',', array_map('intval', $chunk));

    $stepRs = $db->get_recordset(
        "SELECT n.parent_id AS tcversion_id, COUNT(*) AS steps, " .
        "       SUM(CASE WHEN TRIM(COALESCE(ts.expected_results, '')) <> '' THEN 1 ELSE 0 END) AS with_expected " .
        "FROM {$tbl['nodes_hierarchy']} n " .
        "JOIN {$tbl['tcsteps']} ts ON ts.id = n.id " .
        "WHERE n.node_type_id = 9 AND n.parent_id IN (" . $in . ") AND ts.active = 1 " .
        "GROUP BY n.parent_id");
    if (is_array($stepRs)) {
        foreach ($stepRs as $r) {
            $stepStats[intval($r['tcversion_id'])] = array(
                'steps' => intval($r['steps']),
                'with_expected' => intval($r['with_expected']),
            );
        }
    }

    $reqRs = $db->get_recordset(
        "SELECT tcversion_id, COUNT(DISTINCT req_id) AS reqs " .
        "FROM {$tbl['req_coverage']} WHERE tcversion_id IN (" . $in . ") AND is_active = 1 " .
        "GROUP BY tcversion_id");
    if (is_array($reqRs)) {
        foreach ($reqRs as $r) {
            $reqCounts[intval($r['tcversion_id'])] = intval($r['reqs']);
        }
    }

    $kwRs = $db->get_recordset(
        "SELECT fk_id, COUNT(DISTINCT keyword_id) AS keywords " .
        "FROM {$tbl['object_keywords']} WHERE fk_table = 'tcversions' " .
        "AND fk_id IN (" . $in . ") GROUP BY fk_id");
    if (is_array($kwRs)) {
        foreach ($kwRs as $r) {
            $kwCounts[intval($r['fk_id'])] = intval($r['keywords']);
        }
    }

    $cfRs = $db->get_recordset(
        "SELECT cdv.node_id AS tcversion_id, COUNT(DISTINCT cdv.field_id) AS filled " .
        "FROM {$tbl['cfield_design_values']} cdv " .
        "JOIN {$tbl['cfield_testprojects']} ctp ON ctp.field_id = cdv.field_id " .
        "JOIN {$tbl['custom_fields']} cf ON cf.id = cdv.field_id " .
        "WHERE ctp.testproject_id = " . intval($tprojectId) . " AND ctp.active = 1 " .
        "AND cf.enable_on_design = 1 AND cdv.node_id IN (" . $in . ") " .
        "AND TRIM(COALESCE(cdv.value, '')) <> '' GROUP BY cdv.node_id");
    if (is_array($cfRs)) {
        foreach ($cfRs as $r) {
            $cfCounts[intval($r['tcversion_id'])] = intval($r['filled']);
        }
    }
}

$cfTotal = 0;
$cfRs = $db->get_recordset(
    "SELECT COUNT(DISTINCT ctp.field_id) AS total " .
    "FROM {$tbl['cfield_testprojects']} ctp " .
    "JOIN {$tbl['custom_fields']} cf ON cf.id = ctp.field_id " .
    "WHERE ctp.testproject_id = " . intval($tprojectId) . " AND ctp.active = 1 " .
    "AND cf.enable_on_design = 1");
if (is_array($cfRs) && count($cfRs) > 0) {
    $cfTotal = intval($cfRs[0]['total']);
}

$scxEval = function ($id) use ($cases, $latestVersion, $versions, $stepStats, $reqCounts,
    $kwCounts, $cfCounts, $projectPrefix, $externalId, $scxWalk) {
    $tvid = $latestVersion[$id] ?? 0;
    $v = $tvid > 0 ? $versions[$tvid] : null;
    $steps = $stepStats[$tvid]['steps'] ?? 0;
    $withExpected = $stepStats[$tvid]['with_expected'] ?? 0;
    $reqs = $reqCounts[$tvid] ?? 0;
    $keywords = $kwCounts[$tvid] ?? 0;
    $filled = $cfCounts[$tvid] ?? 0;
    $summary = $v ? $v['summary'] : '';
    $summaryChars = mb_strlen(trim($summary), 'UTF-8');
    $score = $steps * 2 + (int)round(scxPct($withExpected, $steps) / 10)
        + $reqs * 3 + $keywords * 2 + $filled * 2 + min(5, intval($summaryChars / 200));
    if ($score >= 25) {
        $band = 'high';
    } elseif ($score >= 12) {
        $band = 'medium';
    } else {
        $band = 'low';
    }
    $ext = intval($externalId[$id] ?? 0);
    return array(
        'tcase_id' => $id,
        'tcase_name' => $cases[$id]['name'],
        'external_id' => ($projectPrefix !== '' && $ext > 0) ? $projectPrefix . '-' . $ext : (string)$ext,
        'suite_path' => $scxWalk($cases[$id]['suite']),
        'version' => $v ? intval($v['version']) : 0,
        'version_id' => $tvid,
        'steps' => $steps,
        'with_expected' => $withExpected,
        'expected_pct' => scxPct($withExpected, $steps),
        'requirements' => $reqs,
        'keywords' => $keywords,
        'cfields_filled' => $filled,
        'summary_chars' => $summaryChars,
        'importance' => $v ? intval($v['importance']) : 0,
        'execution_type' => $v ? intval($v['execution_type']) : 0,
        'score' => $score,
        'band' => $band,
        'summary' => $summary,
    );
};

$summary = array(
    'total_cases' => count($scope),
    'high' => 0,
    'medium' => 0,
    'low' => 0,
    'avg_score' => 0.0,
    'total_steps' => 0,
    'missing_expected' => 0,
    'no_requirements' => 0,
    'no_keywords' => 0,
    'cfields_total' => $cfTotal,
    'cfields_filled' => 0,
);
$evaluated = array();
foreach ($scope as $id => $c) {
    $e = $scxEval($id);
    $evaluated[$id] = $e;
    $summary[$e['band']]++;
    $summary['total_steps'] += $e['steps'];
    $summary['missing_expected'] += ($e['steps'] - $e['with_expected']);
    if ($e['requirements'] <= 0) {
        $summary['no_requirements']++;
    }
    if ($e['keywords'] <= 0) {
        $summary['no_keywords']++;
    }
    if ($e['cfields_filled'] > 0) {
        $summary['cfields_filled']++;
    }
}
$scoreSum = 0;
foreach ($evaluated as $e) {
    $scoreSum += $e['score'];
}
$summary['avg_score'] = $summary['total_cases'] > 0
    ? round($scoreSum / $summary['total_cases'], 1) : 0.0;

if ($action === 'case') {
    $e = $evaluated[$tcaseId];
    $steps = array();
    if ($e['version_id'] > 0) {
        $stepRs = $db->get_recordset(
            "SELECT ts.step_number, ts.actions, ts.expected_results " .
            "FROM {$tbl['nodes_hierarchy']} n " .
            "JOIN {$tbl['tcsteps']} ts ON ts.id = n.id " .
            "WHERE n.parent_id = " . intval($e['version_id']) . " AND n.node_type_id = 9 " .
            "AND ts.active = 1 ORDER BY ts.step_number, n.id");
        if (is_array($stepRs)) {
            foreach ($stepRs as $r) {
                $steps[] = array(
                    'number' => intval($r['step_number']),
                    'actions' => scxDecode($r['actions']),
                    'expected' => scxDecode($r['expected_results']),
                );
            }
        }
    }
    $keywordsList = array();
    if ($e['version_id'] > 0) {
        $kwRs = $db->get_recordset(
            "SELECT k.keyword FROM {$tbl['object_keywords']} okw " .
            "JOIN {$tbl['keywords']} k ON k.id = okw.keyword_id " .
            "WHERE okw.fk_table = 'tcversions' AND okw.fk_id = " . intval($e['version_id']) .
            " ORDER BY k.keyword");
        if (is_array($kwRs)) {
            foreach ($kwRs as $r) {
                $keywordsList[] = scxDecode($r['keyword']);
            }
        }
    }
    $reqList = array();
    if ($e['version_id'] > 0) {
        $reqRs = $db->get_recordset(
            "SELECT rc.req_id, n.name AS title, rv.version " .
            "FROM {$tbl['req_coverage']} rc " .
            "LEFT JOIN {$tbl['nodes_hierarchy']} n ON n.id = rc.req_id " .
            "LEFT JOIN {$tbl['req_versions']} rv ON rv.id = rc.req_version_id " .
            "WHERE rc.tcversion_id = " . intval($e['version_id']) . " AND rc.is_active = 1 " .
            "ORDER BY n.name");
        if (is_array($reqRs)) {
            foreach ($reqRs as $r) {
                $reqList[] = array('req_id' => intval($r['req_id']),
                    'title' => scxDecode($r['title']), 'version' => intval($r['version']));
            }
        }
    }
    $cfieldList = array();
    if ($e['version_id'] > 0) {
        $cfListRs = $db->get_recordset(
            "SELECT cf.label, cdv.value FROM {$tbl['cfield_design_values']} cdv " .
            "JOIN {$tbl['custom_fields']} cf ON cf.id = cdv.field_id " .
            "WHERE cdv.node_id = " . intval($e['version_id']) . " " .
            "AND TRIM(COALESCE(cdv.value, '')) <> '' ORDER BY cf.label");
        if (is_array($cfListRs)) {
            foreach ($cfListRs as $r) {
                $cfieldList[] = array('label' => scxDecode($r['label']), 'value' => scxDecode($r['value']));
            }
        }
    }
    $e['steps_list'] = $steps;
    $e['keywords_list'] = $keywordsList;
    $e['requirements_list'] = $reqList;
    $e['cfields_list'] = $cfieldList;
    scxOut(array('status' => 'ok', 'code' => 200, 'context' => $context, 'case' => $e));
}

$rows = array();
foreach ($evaluated as $e) {
    if ($e['steps'] < $minSteps) {
        continue;
    }
    if ($bandFilter !== '' && $e['band'] !== $bandFilter) {
        continue;
    }
    $rows[] = $e;
}
usort($rows, function ($a, $b) {
    if ($a['score'] !== $b['score']) {
        return $b['score'] - $a['score'];
    }
    return strcasecmp($a['tcase_name'], $b['tcase_name']);
});

scxOut(array(
    'status' => 'ok',
    'code' => 200,
    'context' => $context,
    'filters' => $filters,
    'filter' => array('suite_id' => $suiteId, 'band' => $bandFilter, 'min_steps' => $minSteps),
    'summary' => $summary,
    'rows' => $rows,
));
