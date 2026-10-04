<?php
/**
 * api/planurgency — Test Plan Urgency Management BFF (Refs #1832)
 *
 * Modern twin of the last Test Plan screen still only reachable through a
 * legacy Smarty controller:
 *   lib/plan/planUrgency.php
 *   gui/templates/dashio/plan/planUrgency.tpl
 *
 * Legacy behavior ported 1:1:
 *  - page rights  : checkRights() -> pageAccessCheck rightsAnd=['testplan_planning']
 *                   on the SESSION test project (hasRightOnProj semantics).
 *  - context      : a TEST SUITE (id = tree node id) inside a TEST PLAN.
 *  - grid         : testPlanUrgency::getSuiteUrgency() — fetchRowsIntoMap()
 *                   keyed by tcversion_id with CUMULATIVE, i.e. one test case
 *                   with N linked platforms produces N rows grouped under the
 *                   same key. Columns: tcprefix + tc_external_id + name,
 *                   assigned_to (info icon + first/last tooltip), importance,
 *                   urgency radio triple (HIGH=3 / MEDIUM=2 / LOW=1), and
 *                   priority = importance * urgency mapped back to a level by
 *                   priority_to_level().
 *                   Rows are the DIRECT children of the suite: the legacy SQL
 *                   joins NHB.parent_id = <suite> and requires a
 *                   testplan_tcversions row for NHA, so a test case nested in
 *                   a sub-suite of this suite is NOT listed and NOT touched by
 *                   the suite-wide button. Parity kept on purpose.
 *  - suite write  : three submit buttons (high/medium/low) -> doProcess()
 *                   -> setSuiteUrgency(tplan, node, urgency), feedback
 *                   `feedback_urgency_ok` / `feedback_urgency_fail`.
 *  - per-TC write : radio groups `urgency[<tcversion_id>]` -> doIt ->
 *                   setTestUrgency(tplan, tcversion_id, urgency) for each.
 *  - empty suite  : `testsuite_is_empty`.
 *  - help         : `level=testproject` -> show_instructions('test_urgency'),
 *                   served by the modern Help popup (#1552) from the screen.
 *
 * Modern supersets (no legacy capability removed):
 *  - the platform / build selectors are explicit URL parameters instead of
 *    opaque values read out of $_SESSION['plan_mode'][form_token] (the legacy
 *    screen could only ever show what the last tree form had left in session).
 *  - the platform column is shown, so a multi-platform test case is readable.
 *  - one row per (test case, platform): the assigned users of the selected
 *    build are aggregated into a list instead of repeating the test case name
 *    on a continuation row.
 *
 * Hardening vs legacy (the legacy page trusted the form for both the suite id
 * and the list of tcversion_ids; neither was proven to belong to the plan):
 *  - the test plan is proven to be a `testplan` node whose project root equals
 *    tproject_id, and the suite is proven to be a `testsuite` node under that
 *    very project, before anything is read or written;
 *  - `testplan_planning` is checked on the ADDRESSED project (not the session
 *    one) with getAccess semantics, and a refusal is audited;
 *  - submitted tcversion_ids are intersected with the versions the suite really
 *    exposes for that plan+platform, so a caller cannot retag an arbitrary
 *    version of another plan/project through this route;
 *  - suite_urgency refuses an urgency outside {1,2,3} and a suite with no
 *    rows (nothing_selected) instead of silently UPDATE-ing 0 rows and
 *    reporting success (the legacy OK came from exec_query() truthiness).
 *
 *  GET  ?action=init&tproject_id=N&tplan_id=N&tsuite_id=N[&build_id=N][&platform_id=N]
 *       -> context + builds + platforms + grouped grid
 *  POST ?action=suite_urgency {tproject_id,tplan_id,tsuite_id,urgency}
 *  POST ?action=tc_urgency    {tproject_id,tplan_id,tsuite_id,platform_id,
 *                              urgency:{tcversion_id:urgency}}
 *
 * 401 anon / session expired · 403 no right or same-origin · 404 unknown
 * project / plan / suite · 400 bad params · 405 unknown action
 *
 * Session-based auth, JSON I/O. No Smarty.
 */
require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');

if ($action === '' && !in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    bffFail(400, 'bad_request', 'Missing action.');
}

if (!in_array($action, ['init', 'suite_urgency', 'tc_urgency'], true)) {
    bffFail(400, 'unknown_action', 'Unknown action.');
}

$isWrite = in_array($action, ['suite_urgency', 'tc_urgency'], true);
if ($isWrite && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    bffFail(405, 'method_not_allowed', 'This action requires POST.');
}
if (!$isWrite && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    bffFail(405, 'method_not_allowed', 'This action requires GET.');
}

/**
 * Session gate. The cheap $_SESSION check runs BEFORE the DB connect on purpose
 * (issue #1677): common.php echoes a raw dbms_msg on a failed connect, so
 * connecting first leaked host/database name in the body of an HTTP 200 for an
 * unauthenticated request. bffEnforceSession() then re-checks the inactivity
 * timeout once the handler exists (it needs $db).
 */
if (intval($_SESSION['userID'] ?? 0) <= 0) {
    bffFail(401, 'session_expired', 'Session expired or not authenticated.');
}

require_once(__DIR__ . '/../_guard.php');
if ($isWrite) {
    bffSameOriginGuard();
}

$db = new database(DB_TYPE);
$conn = doDBConnect($db);
if (!empty($conn) && isset($conn['status']) && !$conn['status']) {
    bffFail(500, 'server_error', 'Database connection failed.');
}
bffEnforceSession($db);

/**
 * `database::$tables` is NOT populated by doDBConnect() - the supported way to
 * reach the (optionally prefixed) table names is tlObjectWithDB::getDBTables().
 */
$T = tlObjectWithDB::getDBTables([
    'testprojects', 'testplans', 'nodes_hierarchy', 'node_types',
    'testplan_tcversions', 'tcversions', 'platforms', 'builds',
    'user_assignments', 'users',
]);

$user = tlUser::getByID($db, intval($_SESSION['userID']));
if (is_null($user)) {
    bffFail(401, 'session_expired', 'User not found.');
}

try {
    bffDispatch($db, $T, $user, $action);
} catch (Throwable $e) {
    // The JSON contract must hold even when the legacy stack fatals: a raw
    // PHP error page on a BFF route breaks fetch() parsing and (before #1423)
    // leaked absolute paths (CWE-200).
    error_log('planurgency BFF ' . $action . ': ' . $e->getMessage());
    bffFail(500, 'server_error', 'The request could not be completed.');
}

if (is_null($user)) {
    bffFail(401, 'session_expired', 'User not found.');
}

/**
 * helpers
 */
function bffFail(int $code, string $machine, string $message): void
{
    if (!headers_sent()) {
        http_response_code($code);
    }
    echo json_encode([
        'status'  => 'error',
        'code'    => $machine,
        'message' => $message,
    ]);
    exit;
}

function bffParam(string $name, $default = null)
{
    return $_GET[$name] ?? $_POST[$name] ?? $default;
}

function bffIntParam(string $name, int $default = 0): int
{
    $v = bffParam($name, null);
    if ($v === null || $v === '') {
        return $default;
    }
    if (!is_scalar($v) || !preg_match('/^-?\d+$/', trim((string)$v))) {
        bffFail(400, 'bad_param', "Parameter '$name' must be an integer.");
    }
    return (int)trim((string)$v);
}

function bffPositiveIntParam(string $name): int
{
    $v = bffIntParam($name, 0);
    if ($v <= 0) {
        bffFail(400, 'bad_param', "Parameter '$name' is required and must be > 0.");
    }
    return $v;
}

/**
 * First row of a query, or null. `database` has no fetch_by_id()/fetch_row()
 * (only exec_query/get_recordset), so this is the repo-wide idiom.
 */
function bffFirstRow($db, string $sql): ?array
{
    $rs = $db->get_recordset($sql);
    if (is_null($rs) || !$rs) {
        return null;
    }
    return (array)reset($rs);
}

/**
 * Node type ids resolved from the node_types table by DESCRIPTION, never
 * hardcoded (same defensive approach as api/suitemove and api/tcreorder).
 */
function bffNodeTypes($db, $T): array
{
    static $types = null;
    if (!is_null($types)) {
        return $types;
    }
    $nt = $T['node_types'];
    $types = [];
    foreach ((array)$db->get_recordset("SELECT id, description FROM {$nt}") as $r) {
        $types[strtolower((string)$r['description'])] = (int)$r['id'];
    }
    return $types;
}

function bffNodeType($db, $T, string $description): int
{
    $types = bffNodeTypes($db, $T);
    return (int)($types[strtolower($description)] ?? 0);
}

function bffUrgency($raw, string $where): int
{
    if (!is_scalar($raw) || !preg_match('/^-?\d+$/', trim((string)$raw))) {
        bffFail(400, 'bad_param', "Urgency ($where) must be an integer.");
    }
    $u = (int)trim((string)$raw);
    if (!in_array($u, [HIGH, MEDIUM, LOW], true)) {
        bffFail(400, 'bad_urgency', "Urgency ($where) must be one of 1 (low), 2 (medium), 3 (high).");
    }
    return $u;
}

/**
 * Resolve and PROVE the (project, plan, suite) triple.
 *
 *  - tproject_id must exist;
 *  - tplan_id must be a `testplan` node whose project root is tproject_id;
 *  - tsuite_id must be a `testsuite` node whose project root is tproject_id.
 *
 * The plan-vs-project and suite-vs-project proofs close the cross-project hole
 * of the legacy page, which read all three ids straight out of the query
 * string / form and only checked `testplan_planning` on the session project.
 */
function bffResolveContext($db, $T, $user, int $tprojectId, int $tplanId, int $tsuiteId): array
{
    $ctx = new stdClass();
    $ctx->tproject_id = $tprojectId;
    $ctx->tplan_id = $tplanId;
    $ctx->tsuite_id = $tsuiteId;
    $ctx->rightsOr = [];
    $ctx->rightsAnd = ['testplan_planning'];

    if (!hasRight($db, $user, $ctx)) {
        bffAuditDenied($db, $user, $ctx, 'planUrgency');
        bffFail(403, 'no_right', 'You do not have permission to manage test plans on this project.');
    }

    $tproject = bffFirstRow($db, "SELECT id, prefix FROM {$T['testprojects']} WHERE id=" . $tprojectId);
    if (!$tproject) {
        bffFail(404, 'tproject_not_found', 'Test project not found.');
    }

    $planRow = bffNodeInProject($db, $T, $tplanId, $tprojectId, bffNodeType($db, $T, 'testplan'));
    if (!$planRow) {
        bffFail(404, 'tplan_not_found', 'Test plan not found in this test project.');
    }

    $suiteRow = bffNodeInProject($db, $T, $tsuiteId, $tprojectId, bffNodeType($db, $T, 'testsuite'));
    if (!$suiteRow) {
        bffFail(404, 'tsuite_not_found', 'Test suite not found in this test project.');
    }

    return [$tproject, $planRow, $suiteRow];
}

/**
 * Fetch a nodes_hierarchy row of the given node_type that belongs to the given
 * test project (walking the parent chain up to a `testproject` node).
 *
 * node_type_id (2.0.1): 1 testproject · 2 testsuite · 3 testcase ·
 * 4 testcase_version · 5 testplan · 6 requirement_specification
 */
function bffNodeInProject($db, $T, int $nodeId, int $tprojectId, int $nodeType): ?array
{
    $nh = $T['nodes_hierarchy'];
    $current = (int)$nodeId;
    $guard = 0;
    while ($current > 0 && $guard < 64) {
        $row = bffFirstRow($db, "SELECT id, name, node_type_id, parent_id FROM {$nh} WHERE id=" . (int)$current);
        if (!$row) {
            return null;
        }
        if ((int)$row['node_type_id'] === $nodeType) {
            return bffProjectRootOf($db, $T, (int)$row['id']) === $tprojectId ? $row : null;
        }
        $current = (int)$row['parent_id'];
        $guard++;
    }
    return null;
}

/**
 * Walk up to the `testproject` node (node_type_id = 1) owning this node.
 */
function bffProjectRootOf($db, $T, int $nodeId): int
{
    $nh = $T['nodes_hierarchy'];
    $ntProject = bffNodeType($db, $T, 'testproject');
    $current = (int)$nodeId;
    $guard = 0;
    while ($current > 0 && $guard < 64) {
        $row = bffFirstRow($db, "SELECT id, node_type_id, parent_id FROM {$nh} WHERE id=" . (int)$current);
        if (!$row) {
            return 0;
        }
        if ($ntProject > 0 && (int)$row['node_type_id'] === $ntProject) {
            return (int)$row['id'];
        }
        $current = (int)$row['parent_id'];
        $guard++;
    }
    return 0;
}

function bffAuditDenied($db, $user, $ctx, string $op): void
{
    $event = new stdClass();
    $event->message    = "Access denied to $op (tplan=" . (int)($ctx->tplan_id ?? 0)
                        . ", tsuite=" . (int)($ctx->tsuite_id ?? 0) . ")";
    $event->logLevel   = 'SECURITY';
    $event->source     = 'GUI';
    $event->objectID   = (int)($ctx->tproject_id ?? 0);
    $event->objectType = 'testprojects';
    $event->code       = 'PLAN_URGENCY_ACCESS_DENIED';
    logEvent($event);
}

/**
 * testplan_planning on the addressed project, legacy pageAccessCheck semantics.
 */
function hasRight($db, $user, $ctx)
{
    foreach ((array)($ctx->rightsAnd ?? []) as $right) {
        if (!$user->hasRight($db, $right, (int)$ctx->tproject_id, false, true)) {
            return false;
        }
    }
    return true;
}

function bffUrgencyOptions(): array
{
    return [
        ['value' => HIGH, 'level' => 'high'],
        ['value' => MEDIUM, 'level' => 'medium'],
        ['value' => LOW, 'level' => 'low'],
    ];
}

/**
 * Port of testPlanUrgency::getSuiteUrgency() with the platform / build columns
 * added and the assigned users aggregated.
 *
 * @return array rows[] { tcversion_id, testcase_id, name, tc_external_id,
 *                        tcprefix, urgency, importance, priority,
 *                        platforms[] { platform_id, platform_name, urgency,
 *                                      priority, assigned[] {login,first,last} } }
 */
function bffCollectSuiteUrgency($db, $T, array $planRow, int $tsuiteId, int $platformId, int $buildId): array
{
    $tprojectId = bffProjectRootOf($db, $T, (int)$planRow['id']);
    $tplanId = (int)$planRow['id'];

    $prefix = '';
    $tp = bffFirstRow($db, "SELECT id, prefix FROM {$T['testprojects']} WHERE id=" . $tprojectId);
    if ($tp) {
        $testcase_cfg = config_get('testcase_cfg');
        $prefix = $tp['prefix'] . $testcase_cfg->glue_character;
    }
    $prefixSql = $db->prepare_string($prefix);

    $nh = $T['nodes_hierarchy'];
    $tptcv = $T['testplan_tcversions'];
    $tcv = $T['tcversions'];
    $pl = $T['platforms'];

    $moreFields = '';
    $moreJoins = '';
    if ($buildId > 0) {
        $ua = $T['user_assignments'];
        $users = $T['users'];
        $tcase = new testcase($db);
        $execTaskId = (int)$tcase->assignment_types['testcase_execution']['id'];

        $moreFields = ', USERS.login AS assigned_to, USERS.first, USERS.last';
        $moreJoins = " LEFT JOIN {$ua} UA ON UA.feature_id = TPTCV.id"
            . " AND UA.type = " . $execTaskId
            . " AND UA.build_id = " . (int)$buildId
            . " LEFT JOIN {$users} USERS ON USERS.id = UA.user_id";
    }

    $sql = " SELECT DISTINCT '{$prefixSql}' AS tcprefix, NHB.name, NHB.node_order,"
        . " NHA.parent_id AS testcase_id, TCV.tc_external_id, TPTCV.tcversion_id,"
        . " TPTCV.urgency, TCV.importance, (TCV.importance * TPTCV.urgency) AS priority,"
        . " TPTCV.platform_id, PL.name AS platform_name"
        . $moreFields
        . " FROM {$nh} NHA"
        . " JOIN {$nh} NHB ON NHA.parent_id = NHB.id"
        . " JOIN {$tptcv} TPTCV ON TPTCV.tcversion_id = NHA.id"
        . " JOIN {$tcv} TCV ON TCV.id = TPTCV.tcversion_id"
        . " LEFT JOIN {$pl} PL ON PL.id = TPTCV.platform_id"
        . $moreJoins
        . " WHERE TPTCV.testplan_id = " . (int)$tplanId
        . " AND NHB.parent_id = " . (int)$tsuiteId;

    if ($platformId > 0) {
        $sql .= " AND TPTCV.platform_id = " . (int)$platformId;
    }
    $sql .= " ORDER BY NHB.node_order, NHB.id, TPTCV.platform_id";

    $rs = (array)$db->get_recordset($sql);

    $out = [];
    $seen = [];
    foreach ($rs as $r) {
        $tcvId = (int)$r['tcversion_id'];
        $platId = (int)($r['platform_id'] ?? 0);
        $key = $tcvId . '|' . $platId;

        if (!isset($out[$tcvId])) {
            $out[$tcvId] = [
                'tcversion_id' => $tcvId,
                'testcase_id'  => (int)$r['testcase_id'],
                'tc_external_id' => (int)$r['tc_external_id'],
                'tcprefix'     => (string)$r['tcprefix'],
                'name'         => (string)$r['name'],
                'platforms'    => [],
            ];
        }

        if (isset($seen[$key])) {
            if (($r['assigned_to'] ?? null) !== null && $r['assigned_to'] !== '') {
                $out[$tcvId]['platforms'][$platId]['assigned'][] = [
                    'login' => (string)$r['assigned_to'],
                    'first' => (string)($r['first'] ?? ''),
                    'last'  => (string)($r['last'] ?? ''),
                ];
            }
            continue;
        }
        $seen[$key] = true;

        $assigned = [];
        if (($r['assigned_to'] ?? null) !== null && $r['assigned_to'] !== '') {
            $assigned[] = [
                'login' => (string)$r['assigned_to'],
                'first' => (string)($r['first'] ?? ''),
                'last'  => (string)($r['last'] ?? ''),
            ];
        }

        $out[$tcvId]['platforms'][$platId] = [
            'platform_id'   => $platId,
            'platform_name' => (string)($r['platform_name'] ?? ''),
            'urgency'       => (int)$r['urgency'],
            'importance'    => (int)$r['importance'],
            'priority'      => (int)$r['priority'],
            'priority_level' => priority_to_level((int)$r['priority']),
            'assigned'      => $assigned,
        ];
    }

    $rows = [];
    foreach ($out as $row) {
        ksort($row['platforms']);
        $row['platforms'] = array_values($row['platforms']);
        // The radio group is per TEST CASE VERSION, exactly like the legacy
        // `urgency[<tcversion_id>]` control: the last platform read wins when a
        // version is linked to more than one platform, but the per-platform
        // current value stays visible in the grid.
        $first = $row['platforms'][0] ?? null;
        $row['urgency'] = $first ? (int)$first['urgency'] : OFF;
        $row['importance'] = $first ? (int)$first['importance'] : 0;
        $row['priority'] = $first ? (int)$first['priority'] : 0;
        $row['priority_level'] = $first ? $first['priority_level'] : LOW;
        $rows[] = $row;
    }

    return $rows;
}

/**
 * Builds + platforms the plan can offer as the filter for the assigned-to
 * column and the urgency read.
 */
function bffPlanOptions($db, $T, int $tplanId, int $tprojectId): array
{
    // 2.0.1 schema: `builds` hangs off the TEST PROJECT (builds.testproject_id)
    // and has NO link to the test plan (testplan_tcversions lost its build_id
    // column), so the build selector offers the project's builds. Platforms are
    // narrowed to the ones this plan actually links rows for, because
    // testplan_tcversions.platform_id is still there.
    $builds = [];
    foreach ((array)$db->get_recordset(
        "SELECT b.id, b.name, b.active, b.is_open FROM {$T['builds']} b"
        . " WHERE b.testproject_id = " . (int)$tprojectId
        . " ORDER BY b.name"
    ) as $b) {
        $builds[] = ['id' => (int)$b['id'], 'name' => (string)$b['name'], 'active' => (int)$b['active'], 'is_open' => (int)$b['is_open']];
    }
    $tplanId = (int)$tplanId;

    $platforms = [];
    foreach ((array)$db->get_recordset(
        "SELECT PL.id, PL.name FROM {$T['platforms']} PL"
        . " WHERE PL.testproject_id = " . (int)$tprojectId
        . " AND PL.id IN (SELECT platform_id FROM {$T['testplan_tcversions']}"
        . " WHERE testplan_id = " . (int)$tplanId . " AND platform_id > 0)"
        . " ORDER BY PL.name"
    ) as $p) {
        $platforms[] = ['id' => (int)$p['id'], 'name' => (string)$p['name']];
    }

    return ['builds' => $builds, 'platforms' => $platforms];
}


/**
 * Action dispatch. Isolated in a function so the caller can wrap it in a
 * try/catch and keep the JSON contract intact.
 */
function bffDispatch($db, $T, $user, string $action)
{
    /* ------------------------------------------------------------------ */
    /* GET ?action=init                                                     */
    /* ------------------------------------------------------------------ */
    if ($action === 'init') {
        $tprojectId = bffPositiveIntParam('tproject_id');
        $tplanId = bffPositiveIntParam('tplan_id');
        $tsuiteId = bffPositiveIntParam('tsuite_id');
        $buildId = bffIntParam('build_id', 0);
        $platformId = bffIntParam('platform_id', 0);

        [$tproject, $planRow, $suiteRow] = bffResolveContext($db, $T, $user, $tprojectId, $tplanId, $tsuiteId);

        $opts = bffPlanOptions($db, $T, $tplanId, $tprojectId);
        if ($platformId > 0) {
            $known = false;
            foreach ($opts['platforms'] as $p) {
                if ($p['id'] === $platformId) {
                    $known = true;
                    break;
                }
            }
            if (!$known) {
                bffFail(404, 'platform_not_found', 'Platform is not used by this test plan.');
            }
        }
        if ($buildId > 0) {
            $known = false;
            foreach ($opts['builds'] as $b) {
                if ($b['id'] === $buildId) {
                    $known = true;
                    break;
                }
            }
            if (!$known) {
                bffFail(404, 'build_not_found', 'Build is not used by this test plan.');
            }
        }

        $rows = bffCollectSuiteUrgency($db, $T, $planRow, $tsuiteId, $platformId, $buildId);

        echo json_encode([
            'status' => 'ok',
            'action' => 'init',
            'context' => [
                'tproject_id'  => $tprojectId,
                'tproject_prefix' => (string)$tproject['prefix'],
                'tplan_id'     => (int)$planRow['id'],
                'tplan_name'   => (string)$planRow['name'],
                'tsuite_id'    => (int)$suiteRow['id'],
                'tsuite_name'  => (string)$suiteRow['name'],
                'build_id'     => $buildId,
                'platform_id'  => $platformId,
            ],
            'builds' => $opts['builds'],
            'platforms' => $opts['platforms'],
            'urgencyOptions' => bffUrgencyOptions(),
            'testcases' => $rows,
            'qty' => count($rows),
        ]);
        return;
    }

    /* ------------------------------------------------------------------ */
    /* POST ?action=suite_urgency                                           */
    /* ------------------------------------------------------------------ */
    if ($action === 'suite_urgency') {
        $tprojectId = bffPositiveIntParam('tproject_id');
        $tplanId = bffPositiveIntParam('tplan_id');
        $tsuiteId = bffPositiveIntParam('tsuite_id');
        $urgency = bffUrgency(bffParam('urgency'), 'suite');

        [$tproject, $planRow, $suiteRow] = bffResolveContext($db, $T, $user, $tprojectId, $tplanId, $tsuiteId);

        $rows = bffCollectSuiteUrgency($db, $T, $planRow, $tsuiteId, 0, 0);
        if (!$rows) {
            bffFail(400, 'nothing_selected', 'The selected test suite contains no test cases linked to this test plan.');
        }

        $tplanUrgency = new testPlanUrgency($db);
        $rc = $tplanUrgency->setSuiteUrgency($tplanId, $tsuiteId, $urgency);
        if ($rc != OK) {
            bffFail(500, 'urgency_not_saved', 'The urgency could not be saved.');
        }

        $fresh = bffCollectSuiteUrgency($db, $T, $planRow, $tsuiteId, 0, 0);
        $updated = 0;
        foreach ($fresh as $r) {
            if ((int)$r['urgency'] === $urgency) {
                $updated++;
            }
        }

        echo json_encode([
            'status' => 'ok',
            'action' => 'suite_urgency',
            'urgency' => $urgency,
            'updated' => $updated,
            'testcases' => $fresh,
        ]);
        return;
    }

    /* ------------------------------------------------------------------ */
    /* POST ?action=tc_urgency                                              */
    /* ------------------------------------------------------------------ */
    if ($action === 'tc_urgency') {
        $tprojectId = bffPositiveIntParam('tproject_id');
        $tplanId = bffPositiveIntParam('tplan_id');
        $tsuiteId = bffPositiveIntParam('tsuite_id');
        $platformId = bffIntParam('platform_id', 0);

        $payload = bffParam('urgency', null);
        if (!is_array($payload) || !$payload) {
            bffFail(400, 'nothing_selected', 'No test case urgency was submitted.');
        }
        if (count($payload) > 5000) {
            bffFail(400, 'too_many_rows', 'Too many test cases submitted at once.');
        }

        [$tproject, $planRow, $suiteRow] = bffResolveContext($db, $T, $user, $tprojectId, $tplanId, $tsuiteId);

        // Only the versions this suite really exposes for this plan (+platform)
        // may be written — the legacy loop trusted the form's tcversion_id keys.
        $rows = bffCollectSuiteUrgency($db, $T, $planRow, $tsuiteId, $platformId, 0);
        $allowed = [];
        foreach ($rows as $r) {
            $allowed[(int)$r['tcversion_id']] = true;
        }
        if (!$allowed) {
            bffFail(400, 'nothing_selected', 'The selected test suite contains no test cases linked to this test plan.');
        }

        $wanted = [];
        foreach ($payload as $tcversionId => $urgency) {
            $id = (int)$tcversionId;
            if ($id <= 0) {
                continue;
            }
            if (!isset($allowed[$id])) {
                continue;
            }
            $wanted[$id] = bffUrgency($urgency, "tcversion $id");
        }

        if (!$wanted) {
            bffFail(400, 'nothing_selected', 'None of the submitted test cases belong to the selected test suite and test plan.');
        }

        $tplanUrgency = new testPlanUrgency($db);
        $failed = [];
        foreach ($wanted as $tcversionId => $urgency) {
            $rc = $tplanUrgency->setTestUrgency($tplanId, $tcversionId, $urgency);
            if ($rc != OK) {
                $failed[] = $tcversionId;
            }
        }

        $fresh = bffCollectSuiteUrgency($db, $T, $planRow, $tsuiteId, $platformId, 0);

        $payloadOut = [
            'status' => 'ok',
            'action' => 'tc_urgency',
            'requested' => count($wanted),
            'updated' => count($wanted) - count($failed),
            'failed' => $failed,
            'testcases' => $fresh,
        ];
        if ($failed) {
            $payloadOut['status'] = 'error';
            $payloadOut['code'] = 'urgency_not_saved';
            $payloadOut['message'] = 'The urgency could not be saved for some test cases.';
            http_response_code(500);
        }
        echo json_encode($payloadOut);
        return;
    }
}
