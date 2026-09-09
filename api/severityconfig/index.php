<?php
/**
 * TestLink Severity Configuration API (BFF)
 *
 * Per-project severity levels (part of the Test Strategy). Severity levels
 * are stored in the project's serialized options blob under the property
 * "severityLevels", mirroring the legacy approach where project-scoped
 * strategy settings live in testprojects.options.
 *
 * When a project has no severityLevels stored, the endpoint returns the
 * default scale documented in the Bug Severity guide (Low/Medium/High/
 * Critical), and saving persists the customized scale.
 *
 * Auth model (same as projectEdit.php / api/projects): gated behind
 * mgt_modify_product for writes; reads require a valid session only so any
 * logged-in user can consult the project's Test Strategy.
 *
 * Refs #1291.
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

$tprojectMgr = new testproject($db);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_REQUEST['action'] ?? '';
$projectId = isset($_REQUEST['tproject_id'])
    ? (int)$_REQUEST['tproject_id'] : 0;

/**
 * Default severity scale (documented in the Bug Severity guide). level is
 * the internal numeric id (1=Low .. 4=Critical) that maps onto the legacy
 * importance/urgency intensities.
 */
function defaultSeverityLevels() {
    return array(
        array('level' => 1, 'code' => 'low',      'label' => null, 'description' => null),
        array('level' => 2, 'code' => 'medium',   'label' => null, 'description' => null),
        array('level' => 3, 'code' => 'high',     'label' => null, 'description' => null),
        array('level' => 4, 'code' => 'critical', 'label' => null, 'description' => null),
    );
}

/**
 * Parse the stored options blob, tolerate corrupt/empty blobs.
 */
function parseStoredOptions($blob) {
    $opt = empty($blob) ? null : @unserialize($blob);
    if ($opt === false || !is_object($opt)) {
        return new stdClass();
    }
    return $opt;
}

/**
 * Read the severity levels currently stored for a project.
 * Returns the default scale when none stored yet.
 */
function getStoredLevels($tprojectMgr, $projectId) {
    $project = $tprojectMgr->get_by_id($projectId);
    if (is_null($project)) {
        return null;
    }
    $opt = parseStoredOptions($project['options'] ?? '');
    $stored = isset($opt->severityLevels) && is_array($opt->severityLevels)
        ? $opt->severityLevels : null;
    if ($stored === null) {
        return defaultSeverityLevels();
    }
    // Merge stored entries onto the default scaffold so a project that
    // skipped customizing a level still returns a complete scale.
    $byLevel = array();
    foreach ($stored as $entry) {
        if (isset($entry['level'])) {
            $byLevel[(int)$entry['level']] = $entry;
        } else if (is_object($entry) && isset($entry->level)) {
            $byLevel[(int)$entry->level] = (array)$entry;
        }
    }
    $out = array();
    foreach (defaultSeverityLevels() as $def) {
        $lv = $def['level'];
        if (isset($byLevel[$lv])) {
            $out[] = array(
                'level'       => $lv,
                'code'        => isset($byLevel[$lv]['code']) ? $byLevel[$lv]['code'] : $def['code'],
                'label'       => !empty($byLevel[$lv]['label']) ? $byLevel[$lv]['label'] : null,
                'description' => !empty($byLevel[$lv]['description']) ? $byLevel[$lv]['description'] : null,
            );
        } else {
            $out[] = $def;
        }
    }
    return $out;
}

function buildProjectRow($row) {
    return array(
        'id'         => (int)$row['id'],
        'name'       => $row['name'],
        'prefix'     => $row['prefix'],
    );
}

switch ($method) {
    case 'GET':
        if ($action === 'projects') {
            // List accessible projects for the screen selector.
            $sql = "SELECT tp.id, nh.name, tp.prefix FROM testprojects tp " .
                   "JOIN nodes_hierarchy nh ON nh.id = tp.id ORDER BY nh.name";
            $rows = (array)$db->get_recordset($sql);
            $items = array();
            foreach ($rows as $row) {
                $items[] = buildProjectRow($row);
            }
            echo json_encode(array('status' => 'ok', 'projects' => $items));
            exit;
        }

        if ($projectId <= 0) {
            echo json_encode(array('status' => 'ok',
                'levels' => defaultSeverityLevels(),
                'project' => null,
                'canEdit' => false));
            exit;
        }

        $levels = getStoredLevels($tprojectMgr, $projectId);
        if ($levels === null) {
            http_response_code(404);
            echo json_encode(array('status' => 'error', 'message' => 'Project not found'));
            exit;
        }
        $project = $tprojectMgr->get_by_id($projectId);
        $opt = parseStoredOptions($project['options'] ?? '');
        echo json_encode(array(
            'status'    => 'ok',
            'project'   => buildProjectRow($project),
            'priorityEnabled' => !empty($opt->testPriorityEnabled),
            'levels'    => $levels,
            'canEdit'   => ($user->hasRight($db, 'mgt_modify_product') === 'yes'),
        ));
        break;

    case 'POST':
    case 'PUT':
        if (!$user->hasRight($db, 'mgt_modify_product')) {
            http_response_code(403);
            echo json_encode(array('status' => 'error', 'message' => 'No permission'));
            exit;
        }
        if ($projectId <= 0) {
            http_response_code(400);
            echo json_encode(array('status' => 'error', 'message' => 'Project ID required'));
            exit;
        }
        $project = $tprojectMgr->get_by_id($projectId);
        if (is_null($project)) {
            http_response_code(404);
            echo json_encode(array('status' => 'error', 'message' => 'Project not found'));
            exit;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!isset($input['levels']) || !is_array($input['levels'])) {
            http_response_code(400);
            echo json_encode(array('status' => 'error', 'message' => 'levels array required'));
            exit;
        }

        // Validate + normalize: level must be int 1..4 (unique), label/description
        // strings trimmed and length-capped.
        $levels = array();
        $seen = array();
        foreach ($input['levels'] as $entry) {
            $lv = isset($entry['level']) ? (int)$entry['level'] : 0;
            if ($lv < 1 || $lv > 4) {
                http_response_code(400);
                echo json_encode(array('status' => 'error', 'message' => 'Invalid level'));
                exit;
            }
            if (isset($seen[$lv])) {
                http_response_code(400);
                echo json_encode(array('status' => 'error', 'message' => 'Duplicate level'));
                exit;
            }
            $seen[$lv] = true;
            $levels[] = array(
                'level'       => $lv,
                'code'        => isset($entry['code']) ? trim((string)$entry['code']) : '',
                'label'       => isset($entry['label']) ? mb_substr(trim((string)$entry['label']), 0, 40) : '',
                'description' => isset($entry['description']) ? mb_substr(trim((string)$entry['description']), 0, 300) : '',
            );
        }

        // Merge severityLevels onto the stored options blob, keep other flags.
        $opt = parseStoredOptions($project['options'] ?? '');
        if (empty($levels)) {
            // Reset-to-defaults: drop the property entirely so the default
            // scale is served again.
            unset($opt->severityLevels);
        } else {
            $opt->severityLevels = $levels;
        }

        $name = $project['name'];
        $color = !empty($project['color']) ? $project['color'] : '#9BD';
        $notes = $project['notes'] ?? '';
        $ok = $tprojectMgr->update($projectId, $name, $color, $notes,
                                   $opt, (int)$project['active'],
                                   $project['prefix'], (int)$project['is_public']);
        if (!$ok) {
            http_response_code(500);
            echo json_encode(array('status' => 'error', 'message' => 'Failed to save severity levels'));
            exit;
        }

        // Legacy parity: project config changes log an AUDIT event.
        $event = new stdClass();
        $event->message = TLS('severityConfig_saved', $name);
        $event->logLevel = 'AUDIT';
        $event->source = 'GUI';
        $event->objectID = $projectId;
        $event->objectType = 'testprojects';
        $event->code = 'UPDATE';
        logEvent($event);

        echo json_encode(array(
            'status'  => 'ok',
            'project' => buildProjectRow($project),
            'levels'  => $levels,
            'message' => 'Severity levels saved',
        ));
        break;

    default:
        http_response_code(405);
        echo json_encode(array('status' => 'error', 'message' => 'Method not allowed'));
}