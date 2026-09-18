<?php
/**
 * Fix Test Plans BFF API (repair utility)
 * URL: /api/fixplans/
 * Plain PHP, no framework, no compilation.
 *
 * Refs #1540: modern replacement for the legacy lib/project/fix_tplans.php
 * screen (BUG 1021 repair utility — reassign test plans that lost / never got
 * a valid testproject_id). The legacy controller is BROKEN on the upgraded
 * schema: it calls getTestPlansWithoutProject() which does not exist anywhere
 * in the codebase (PHP Fatal on every load), and writes testproject_id updates
 * straight from POST keys with no validation. This BFF reimplements the
 * intended flow with ownership-safe validation:
 *
 *   GET ?action=init
 *      200 {status, grant, orphan_plans, orphan_builds, projects}
 *   POST ?action=reassign {"assignments":[{"plan_id":N,"project_id":N},..]}
 *      200 {status, updated, orphan_plans, orphan_builds}
 *   POST ?action=fix_build {"build_id":N,"project_id":N}
 *      200 {status, updated, orphan_plans, orphan_builds}
 *
 *  401 anonymous session
 *  403 user without mgt_modify_product (legacy has_rights parity)
 *  400 missing/malformed params or unknown plan/build/project id
 *  405 non-GET/POST verb
 *  500 database/legacy-layer throw
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');

$db = null;
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

// Legacy gate (fix_tplans.php has_rights($db, 'mgt_modify_product')) — the
// repair tool is admin-only: even listing orphan plans requires the right.
if (!$user->hasRight($db, 'mgt_modify_product')) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'No permission to manage test projects']);
    exit;
}

$tables = tlObjectWithDB::getDBTables(
    array('testprojects', 'testplans', 'builds'));
$tprojT = $tables['testprojects'];
$tplanT = $tables['testplans'];
$buildT = $tables['builds'];

function fpErr($code, $msg)
{
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $msg]);
    exit;
}

function fpRows($db, $sql)
{
    $rs = $db->exec_query($sql);
    $out = array();
    if ($rs) {
        while (!$rs->EOF) {
            $out[] = (array)$rs->fields;
            $rs->MoveNext();
        }
    }
    return $out;
}

function orphanPlans($db, $tplanT, $tprojT)
{
    return fpRows($db,
        "SELECT tp.id, tp.name, tp.active, tp.is_open, tp.testproject_id " .
        "FROM {$tplanT} tp " .
        "WHERE tp.testproject_id = 0 OR NOT EXISTS " .
        "(SELECT 1 FROM {$tprojT} p WHERE p.id = tp.testproject_id) " .
        "ORDER BY tp.id");
}

function orphanBuilds($db, $buildT, $tprojT)
{
    return fpRows($db,
        "SELECT b.id, b.name, b.active, b.is_open, b.testproject_id " .
        "FROM {$buildT} b " .
        "WHERE b.testproject_id = 0 OR NOT EXISTS " .
        "(SELECT 1 FROM {$tprojT} p WHERE p.id = b.testproject_id) " .
        "ORDER BY b.id");
}

function allProjects($db, $tprojT)
{
    return fpRows($db,
        "SELECT id, name, prefix FROM {$tprojT} ORDER BY name");
}

function existsRow($db, $sql)
{
    $rs = $db->exec_query($sql);
    return ($rs && !$rs->EOF && intval($rs->fields['c'] ?? 0) > 0);
}

function fpState($db, $tplanT, $buildT, $tprojT)
{
    return array(
        'orphan_plans' => orphanPlans($db, $tplanT, $tprojT),
        'orphan_builds' => orphanBuilds($db, $buildT, $tprojT),
        'projects' => allProjects($db, $tprojT),
    );
}

$method = $_SERVER['REQUEST_METHOD'];
$action = trim((string)($_REQUEST['action'] ?? ''));

try {
    switch ($method) {
        case 'GET':
            if ($action !== 'init') {
                fpErr(400, 'Unknown or missing action');
            }
            echo json_encode(array(
                'status' => 'ok',
                'grant' => array('modify_product' => true),
            ) + fpState($db, $tplanT, $buildT, $tprojT));
            exit;

        case 'POST':
            $body = json_decode(file_get_contents('php://input'), true);
            if (!is_array($body)) {
                $body = $_POST;
            }

            if ($action === 'reassign') {
                $assignments = isset($body['assignments']) ? $body['assignments'] : null;
                if (!is_array($assignments)) {
                    if (isset($body['plan_id']) && isset($body['project_id'])) {
                        $assignments = array(array(
                            'plan_id' => $body['plan_id'],
                            'project_id' => $body['project_id'],
                        ));
                    } else {
                        fpErr(400, 'assignments array (or plan_id/project_id) required');
                    }
                }
                if (count($assignments) === 0) {
                    fpErr(400, 'No assignments provided');
                }
                $clean = array();
                foreach ($assignments as $a) {
                    $planId = intval($a['plan_id'] ?? 0);
                    $projId = intval($a['project_id'] ?? 0);
                    if ($planId <= 0 || $projId <= 0) {
                        continue; // legacy "none" rows are skipped client-side
                    }
                    if (!existsRow($db,
                        "SELECT COUNT(1) AS c FROM {$tplanT} WHERE id = " . intval($planId))) {
                        fpErr(404, 'Test plan ' . $planId . ' not found');
                    }
                    if (!existsRow($db,
                        "SELECT COUNT(1) AS c FROM {$tprojT} WHERE id = " . intval($projId))) {
                        fpErr(404, 'Test project ' . $projId . ' not found');
                    }
                    $clean[] = array('plan_id' => $planId, 'project_id' => $projId);
                }
                if (count($clean) === 0) {
                    fpErr(400, 'No valid assignments provided');
                }
                $updated = 0;
                foreach ($clean as $row) {
                    $db->exec_query("UPDATE {$tplanT} SET testproject_id = " .
                        intval($row['project_id']) . " WHERE id = " . intval($row['plan_id']));
                    $updated += $db->affected_rows();
                }
                echo json_encode(array('status' => 'ok', 'updated' => $updated)
                    + fpState($db, $tplanT, $buildT, $tprojT));
                exit;
            }

            if ($action === 'fix_build') {
                $buildId = intval($body['build_id'] ?? 0);
                $projId = intval($body['project_id'] ?? 0);
                if ($buildId <= 0 || $projId <= 0) {
                    fpErr(400, 'build_id and project_id required');
                }
                if (!existsRow($db,
                    "SELECT COUNT(1) AS c FROM {$buildT} WHERE id = " . intval($buildId))) {
                    fpErr(404, 'Build ' . $buildId . ' not found');
                }
                if (!existsRow($db,
                    "SELECT COUNT(1) AS c FROM {$tprojT} WHERE id = " . intval($projId))) {
                    fpErr(404, 'Test project ' . $projId . ' not found');
                }
                $db->exec_query("UPDATE {$buildT} SET testproject_id = " .
                    intval($projId) . " WHERE id = " . intval($buildId));
                $updated = $db->affected_rows();
                echo json_encode(array('status' => 'ok', 'updated' => $updated)
                    + fpState($db, $tplanT, $buildT, $tprojT));
                exit;
            }

            fpErr(400, 'Unknown or missing action');
            exit;

        default:
            fpErr(405, 'Method not allowed');
    }
} catch (Exception $e) {
    fpErr(500, 'Repair service error');
}