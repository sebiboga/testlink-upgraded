<?php
/**
 * Test Strategy Report - REST BFF API
 * URL: /api/strategyreport/index.php
 * Plain PHP, no framework, no compilation
 *
 * Refs #1061 / #1925 (enhancement). TestLink 1.9.20 shipped a static Test
 * Strategy reference (the modern `gui/templates/strategy/testStrategy.html`
 * hub, Refs #1052) but no way to GENERATE a consolidated, project-specific
 * Test Strategy document from the artifacts a project already holds. This BFF
 * assembles exactly that document for one test project - scope, test types and
 * levels, environments and tooling, team and responsibilities, related
 * requirements/plans/suites/cases and a computed readiness signal - from data
 * that already lives in the schema. No external service, no new tables.
 *
 * The ledger's TODO section is empty (every ASIDE entry already maps to a
 * modern .html + BFF), so - exactly like #1065 (RTM), #1067 (quality gates)
 * and #1062/#1068 - this run builds the next coherent, genuinely-MISSING
 * report instead of a legacy port.
 *
 * WHAT IT RETURNS (for one test project)
 *   context   project id / name / prefix / active / public / notes +
 *             requirements / priority / automation flag state
 *   scope     requirements specs, requirements, test suites, test cases,
 *             test plans, builds, platforms, keywords, assigned custom fields
 *   types     manual vs automated test-case versions + importance split
 *   levels    top-level suites with their test-case count (test levels)
 *   environments  platforms linked to the project's plans
 *   tooling   issue tracker + code tracker enabled state
 *   team      users + roles assigned on the project
 *   related   test plans (id/name/active)
 *   signals   coverage of requirements, execution readiness ratio
 *
 * SECURITY
 * Session auth + bffSameOriginGuard + bffEnforceSession; right testplan_metrics
 * held on the OWNING test project. The owning project is resolved/validated as
 * a positive int; a supplied-but-invalid tproject_id is refused before any
 * right probe. All ids are strictly validated positive integers and never
 * interpolated as strings into SQL.
 *
 * Contract: GET|HEAD ?action=init&tproject_id=P
 *   200 {status, code, context, scope, types, levels, environments, tooling,
 *        team, related, signals}
 *   400 invalid_request | 401 not_authenticated / session_expired
 *   403 no_right | 404 tproject_not_found
 *   405 method_not_allowed
 *
 * Refs #1061, #1925
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

/**
 * Single JSON exit point. $code is applied ONLY when explicitly given, so a
 * guard/validation branch that already set a status is not reset to 200.
 */
function srOut($payload, $code = null)
{
    if ($code !== null) {
        http_response_code($code);
    }
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

function srFail($httpCode, $code, $message = '')
{
    srOut(array(
        'status' => 'error',
        'code' => $code,
        'message' => $message !== '' ? $message : $code,
    ), $httpCode);
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    srFail(405, 'method_not_allowed');
}

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || intval($userId) <= 0) {
    srFail(401, 'not_authenticated', 'Not authenticated');
}

bffEnforceSession($db);

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    srFail(401, 'not_authenticated', 'User not found');
}

$action = strtolower(trim((string)($_GET['action'] ?? 'init')));
if ($action !== 'init') {
    srFail(400, 'invalid_request', 'Unknown action');
}

/** Strict positive integer reader - "1abc", "1.9", "-3" and "" are all refused. */
function srPositiveInt($raw)
{
    if ($raw === null || is_array($raw)) {
        return 0;
    }
    $raw = trim((string)$raw);
    if ($raw === '' || !preg_match('/^[0-9]+$/', $raw)) {
        return 0;
    }
    $n = intval($raw);
    return ($n > 0) ? $n : 0;
}

$tprojectId = srPositiveInt($_GET['tproject_id'] ?? null);
if ($tprojectId <= 0) {
    srFail(400, 'invalid_request', 'Missing or invalid tproject_id');
}

$tbl = tlObjectWithDB::getDBTables(array(
    'testprojects', 'nodes_hierarchy', 'tcversions', 'testplans', 'builds',
    'platforms', 'keywords', 'req_specs', 'requirements', 'req_coverage',
    'cfield_testprojects', 'user_testproject_roles', 'roles', 'users',
    'issuetrackers', 'codetrackers',
));

// 1. resolve the project (opaque 404 when missing) BEFORE any right probe.
$projRow = $db->get_recordset(
    'SELECT * FROM ' . $tbl['testprojects'] . ' WHERE id = ' . intval($tprojectId));
if (!is_array($projRow) || count($projRow) === 0) {
    srFail(404, 'tproject_not_found', 'Test project not found');
}
$proj = $projRow[0];

$projectName = testproject::getName($db, $tprojectId);
$projectName = is_null($projectName) ? '' : (string)$projectName;

// Feature flags live in the serialized testproject options blob
// (option_reqs / option_priority / option_automation are DEAD columns on 2.0.1
// - see #1833).
$tprojMgr = new testproject($db);
$tprojOpts = $tprojMgr->getOptions($tprojectId);
$optReqs = (int)(!empty($tprojOpts) && isset($tprojOpts->requirementsEnabled)
    && $tprojOpts->requirementsEnabled);
$optPriority = (int)(!empty($tprojOpts) && isset($tprojOpts->testPriorityEnabled)
    && $tprojOpts->testPriorityEnabled);
$optAutomation = (int)(!empty($tprojOpts) && isset($tprojOpts->automationEnabled)
    && $tprojOpts->automationEnabled);

// 2. right on the OWNING project (project-scoped report -> no plan)
if (!$user->hasRight($db, 'testplan_metrics', $tprojectId, null, true)) {
    tLog('BFF strategyreport: user ' . intval($userId) .
         ' has no testplan_metrics right on tproject ' . $tprojectId, 'AUDIT');
    srFail(403, 'no_right', 'testplan_metrics right required');
}

// ---------------------------------------------------------------------------
// Node map (id => [name,parent,type]) - read once, the tree is small relative
// to executions and lets us scope suites/cases/specs to this project without a
// recursive SQL query.
// ---------------------------------------------------------------------------
$nodes = array();
$rsn = $db->get_recordset(
    'SELECT id, name, parent_id, node_type_id FROM ' . $tbl['nodes_hierarchy']);
foreach ((array)$rsn as $row) {
    $nodes[intval($row['id'])] = array(
        'name' => (string)$row['name'],
        'parent' => intval($row['parent_id']),
        'type' => intval($row['node_type_id']),
    );
}

/** True when node id's ancestor chain reaches $projectNodeId. */
function srBelongsTo($nodes, $nodeId, $projectNodeId)
{
    $guard = 0;
    $cur = $nodeId;
    while ($cur > 0 && $guard++ < 10000) {
        if ($cur === $projectNodeId) {
            return true;
        }
        if (!isset($nodes[$cur])) {
            return false;
        }
        $cur = $nodes[$cur]['parent'];
    }
    return false;
}

// TL_NODE_TYPE_*: 1 project, 2 suite, 3 testcase, 4 tcversion,
// 5 testplan, 6 req spec, 7 requirement, 8 req version.
$suiteIds = array();
$testcaseIds = array();
$versionIds = array();
$reqSpecIds = array();
$requirementIds = array();
foreach ($nodes as $id => $n) {
    if ($n['type'] === 2 && srBelongsTo($nodes, $id, $tprojectId)) {
        $suiteIds[$id] = true;
    } elseif ($n['type'] === 3 && srBelongsTo($nodes, $id, $tprojectId)) {
        $testcaseIds[$id] = true;
    } elseif ($n['type'] === 6 && $n['parent'] === $tprojectId) {
        $reqSpecIds[$id] = true;
    } elseif ($n['type'] === 7 && srBelongsTo($nodes, $id, $tprojectId)) {
        $requirementIds[$id] = true;
    }
}
// version nodes (type 4) whose parent is one of this project's test cases.
foreach ($nodes as $id => $n) {
    if ($n['type'] === 4 && isset($testcaseIds[$n['parent']])) {
        $versionIds[$id] = true;
    }
}

$countSuites = count($suiteIds);
$countTestcases = count($testcaseIds);
$countReqSpecs = count($reqSpecIds);
$countRequirements = count($requirementIds);

// ---------------------------------------------------------------------------
// Test types: latest active version of each test case -> execution_type 1/2 and
// importance. We take MAX(version) per test case (the "current" version).
// ---------------------------------------------------------------------------
$manual = 0;
$automated = 0;
$importance = array('1' => 0, '2' => 0, '3' => 0);
if (count($versionIds) > 0) {
    $idList = implode(',', array_map('intval', array_keys($versionIds)));
    $rsv = $db->get_recordset(
        'SELECT V.id, V.version, V.execution_type, V.importance, V.active,
                NH.parent_id AS tcid
           FROM ' . $tbl['tcversions'] . ' V
           JOIN ' . $tbl['nodes_hierarchy'] . ' NH ON NH.id = V.id
          WHERE V.id IN (' . $idList . ')
          ORDER BY NH.parent_id, V.version DESC');
    $seen = array();
    foreach ((array)$rsv as $row) {
        $tcid = intval($row['tcid']);
        if (isset($seen[$tcid])) {
            continue; // keep only the latest version per test case
        }
        if (intval($row['active']) !== 1) {
            continue;
        }
        $seen[$tcid] = true;
        if (intval($row['execution_type']) === TESTCASE_EXECUTION_TYPE_AUTO) {
            $automated++;
        } else {
            $manual++;
        }
        $imp = (string)intval($row['importance']);
        if (isset($importance[$imp])) {
            $importance[$imp]++;
        }
    }
}
$countLatestVersions = $manual + $automated;

// ---------------------------------------------------------------------------
// Test levels: top-level suites (suite whose parent is the project) with their
// recursive test-case count.
// ---------------------------------------------------------------------------
$levels = array();
foreach (array_keys($suiteIds) as $sid) {
    if ($nodes[$sid]['parent'] !== $tprojectId) {
        continue;
    }
    $cnt = 0;
    foreach (array_keys($testcaseIds) as $tcid) {
        if (srBelongsTo($nodes, $tcid, $sid)) {
            $cnt++;
        }
    }
    $levels[] = array('id' => $sid, 'name' => $nodes[$sid]['name'], 'test_cases' => $cnt);
}
usort($levels, function ($a, $b) {
    if ($a['test_cases'] === $b['test_cases']) {
        return strcmp($a['name'], $b['name']);
    }
    return ($b['test_cases'] < $a['test_cases']) ? -1 : 1;
});

// ---------------------------------------------------------------------------
// Scope counters: plans, builds, platforms, keywords, assigned custom fields.
// ---------------------------------------------------------------------------
$countPlans = 0;
$relatedPlans = array();
$rspl = $db->get_recordset(
    'SELECT TP.id, TP.active, NH.name AS name FROM ' . $tbl['testplans'] . ' TP
     JOIN ' . $tbl['nodes_hierarchy'] . ' NH ON NH.id = TP.id
     WHERE TP.testproject_id = ' . intval($tprojectId) . ' ORDER BY TP.id');
foreach ((array)$rspl as $row) {
    $relatedPlans[] = array(
        'id' => intval($row['id']),
        'name' => (string)$row['name'],
        'active' => intval($row['active']),
    );
    $countPlans++;
}

$countBuilds = 0;
$rsb = $db->get_recordset(
    'SELECT COUNT(*) AS c FROM ' . $tbl['builds'] .
    ' WHERE testproject_id = ' . intval($tprojectId));
if (is_array($rsb) && isset($rsb[0]['c'])) {
    $countBuilds = intval($rsb[0]['c']);
}

$environments = array();
$rsplat = $db->get_recordset(
    'SELECT id, name, notes, is_open FROM ' . $tbl['platforms'] .
    ' WHERE testproject_id = ' . intval($tprojectId) . ' ORDER BY name');
foreach ((array)$rsplat as $row) {
    $environments[] = array(
        'id' => intval($row['id']),
        'name' => (string)$row['name'],
        'active' => intval($row['is_open']),
    );
}

$countKeywords = 0;
$rsk = $db->get_recordset(
    'SELECT COUNT(*) AS c FROM ' . $tbl['keywords'] .
    ' WHERE testproject_id = ' . intval($tprojectId));
if (is_array($rsk) && isset($rsk[0]['c'])) {
    $countKeywords = intval($rsk[0]['c']);
}

$countCfields = 0;
$rscf = $db->get_recordset(
    'SELECT COUNT(*) AS c FROM ' . $tbl['cfield_testprojects'] .
    ' WHERE testproject_id = ' . intval($tprojectId));
if (is_array($rscf) && isset($rscf[0]['c'])) {
    $countCfields = intval($rscf[0]['c']);
}

// ---------------------------------------------------------------------------
// Tooling: enabled issue / code trackers.
// ---------------------------------------------------------------------------
$issueTrackers = array();
$rsit = $db->get_recordset(
    'SELECT id, name FROM ' . $tbl['issuetrackers'] . ' ORDER BY id');
foreach ((array)$rsit as $row) {
    $issueTrackers[] = array('id' => intval($row['id']), 'name' => (string)$row['name']);
}
$codeTrackers = array();
$rsct = $db->get_recordset(
    'SELECT id, name FROM ' . $tbl['codetrackers'] . ' ORDER BY id');
foreach ((array)$rsct as $row) {
    $codeTrackers[] = array('id' => intval($row['id']), 'name' => (string)$row['name']);
}

// ---------------------------------------------------------------------------
// Team: users with a role on this project.
// ---------------------------------------------------------------------------
$team = array();
$rst = $db->get_recordset(
    'SELECT UTR.user_id AS uid, U.login AS login, U.first AS first, U.last AS last,
            U.active AS uactive, R.description AS rolename, R.id AS roleid
       FROM ' . $tbl['user_testproject_roles'] . ' UTR
       JOIN ' . $tbl['users'] . ' U ON U.id = UTR.user_id
       JOIN ' . $tbl['roles'] . ' R ON R.id = UTR.role_id
      WHERE UTR.testproject_id = ' . intval($tprojectId) . '
      ORDER BY U.login');
foreach ((array)$rst as $row) {
    $team[] = array(
        'user_id' => intval($row['uid']),
        'login' => (string)$row['login'],
        'first' => (string)$row['first'],
        'last' => (string)$row['last'],
        'active' => intval($row['uactive']),
        'role_id' => intval($row['roleid']),
        // the legacy `roles` table stores the display name in `description`.
        'role' => (string)$row['rolename'],
    );
}

// ---------------------------------------------------------------------------
// Signals: how many requirements are covered by at least one requirement
// coverage link, and the automation readiness ratio.
// ---------------------------------------------------------------------------
$coveredRequirements = 0;
if ($countRequirements > 0) {
    $reqIdList = implode(',', array_map('intval', array_keys($requirementIds)));
    $rscov = $db->get_recordset(
        'SELECT COUNT(DISTINCT req_id) AS c FROM ' . $tbl['req_coverage'] .
        ' WHERE req_id IN (' . $reqIdList . ') AND is_active = 1');
    if (is_array($rscov) && isset($rscov[0]['c'])) {
        $coveredRequirements = intval($rscov[0]['c']);
    }
}
$automationPct = ($countLatestVersions > 0)
    ? round(100 * $automated / $countLatestVersions, 1)
    : 0.0;

srOut(array(
    'status' => 'ok',
    'code' => 'ok',
    'context' => array(
        'project_id' => $tprojectId,
        'name' => $projectName,
        'prefix' => (string)($proj['prefix'] ?? ''),
        'active' => intval($proj['active'] ?? 0),
        'is_public' => intval($proj['is_public'] ?? 0),
        'notes' => (string)($proj['notes'] ?? ''),
        'req_enabled' => $optReqs,
        'priority_enabled' => $optPriority,
        'automation_enabled' => $optAutomation,
        'issue_tracker_enabled' => intval($proj['issue_tracker_enabled'] ?? 0),
        'code_tracker_enabled' => intval($proj['code_tracker_enabled'] ?? 0),
    ),
    'scope' => array(
        'req_specs' => $countReqSpecs,
        'requirements' => $countRequirements,
        'test_suites' => $countSuites,
        'test_cases' => $countTestcases,
        'test_plans' => $countPlans,
        'builds' => $countBuilds,
        'platforms' => count($environments),
        'keywords' => $countKeywords,
        'custom_fields' => $countCfields,
        'top_level_suites' => count($levels),
    ),
    'types' => array(
        'manual' => $manual,
        'automated' => $automated,
        'high' => $importance['3'],
        'medium' => $importance['2'],
        'low' => $importance['1'],
    ),
    'levels' => $levels,
    'environments' => $environments,
    'tooling' => array(
        'issue_trackers' => $issueTrackers,
        'code_trackers' => $codeTrackers,
    ),
    'team' => $team,
    'related' => array(
        'test_plans' => $relatedPlans,
    ),
    'signals' => array(
        'covered_requirements' => $coveredRequirements,
        'automation_pct' => $automationPct,
    ),
));
