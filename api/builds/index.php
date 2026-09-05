<?php
/**
 * Builds & Releases BFF API
 * URL: /api/builds/
 * Plain PHP, no framework, no compilation
 *
 * Mirrors lib/plan/buildView.php + lib/plan/buildEdit.php (TestLink 1.9.20
 * behavior): list builds of a test plan, create/update/delete, active/open
 * toggles, closed_on_date handling, copy build to all test plans of the
 * project and copy tester assignments from a source build.
 *
 * Rights (same as legacy screens):
 *   everything -> testplan_create_build (buildView.php checkRights rightsAnd)
 *   delete with existing executions additionally requires exec_delete
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();


header('Content-Type: application/json; charset=utf-8');

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

$path = $_SERVER['PATH_INFO'] ?? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = preg_replace('#^/api/builds(/index\.php)?#', '', $path);
$path = '/' . trim($path, '/');
$method = $_SERVER['REQUEST_METHOD'];
$segments = array_values(array_filter(explode('/', $path)));

function out($data) { echo json_encode($data); exit; }
function getBody() { return json_decode(file_get_contents('php://input'), true) ?? []; }

function needTplanId() {
    $id = intval($_GET['tplan_id'] ?? ($_POST['tplan_id'] ?? 0));
    if ($id <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test plan id']);
    }
    return $id;
}

/**
 * Resolve the test plan context exactly like legacy initEnv(): plan must be
 * a testplan node; tproject_id comes from its parent.
 */
function resolveTplan(&$db, $tplanId) {
    $tplanMgr = new testplan($db);
    $info = $tplanMgr->tree_manager->get_node_hierarchy_info(
        $tplanId, null, array('nodeType' => 'testplan'));
    if (is_null($info)) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Invalid Test Plan ID']);
    }
    return [
        'tplan_mgr' => $tplanMgr,
        'tplan_id' => $tplanId,
        'tplan_name' => $info['name'],
        'tproject_id' => intval($info['parent_id']),
    ];
}

/**
 * Resolve context for a build row. Builds are scoped to the Test Project
 * (issue #503), so authorization derives from the build's testproject_id
 * rather than a (now ambiguous) owning test plan.
 */
function resolveBuild(&$db, $b) {
    $tprojectId = intval($b['testproject_id'] ?? 0);
    $tp = new testproject($db);
    $info = $tp->tree_manager->get_node_hierarchy_info($tprojectId);
    if (is_null($info)) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Invalid Test Project ID']);
    }
    return [
        'tproject_id' => $tprojectId,
        'tproject_name' => $info['name'],
    ];
}

/** Project display name for audit entries - resolved from ctx, not session. */
function tprojectName(&$tp, $tprojectId) {
    $info = $tp->tree_manager->get_node_hierarchy_info(intval($tprojectId));
    return is_null($info) ? '' : $info['name'];
}

function canManage(&$user, &$db, $tprojectId) {
    return (bool)$user->hasRight($db, 'testplan_create_build', $tprojectId);
}

function canDeleteExec(&$user, &$db, $tprojectId) {
    return (bool)$user->hasRight($db, 'exec_delete', $tprojectId);
}

/** Trim a string body field. */
function strField($body, $key) {
    $v = isset($body[$key]) ? trim((string)$body[$key]) : '';
    return ($v === '') ? null : $v;
}

/** Validate ISO release date (YYYY-MM-DD), empty is allowed. */
function isoDateOrNull($body, $key = 'release_date') {
    $v = isset($body[$key]) ? trim((string)$body[$key]) : '';
    if ($v === '') {
        return [null, null];
    }
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) ||
        !checkdate(intval($m[2]), intval($m[3]), intval($m[1]))) {
        return [false, 'invalid_release_date'];
    }
    return [$v, null];
}

$tplanMgr = new testplan($db);
$buildMgr = new build($db);

/* ------------------------------------------------------------------ */
/* GET routes                                                          */
/* ------------------------------------------------------------------ */

// GET /?tplan_id=N  -> builds list + context + per-screen rights
if ($method === 'GET' && count($segments) === 0) {
    $tplanId = needTplanId();
    $ctx = resolveTplan($db, $tplanId);
    if (!canManage($user, $db, $ctx['tproject_id'])) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'Insufficient rights']);
    }

    // Source-build selector data (legacy init_source_build_selector):
    // newest first + assignment count per build.
    $srcItems = [];
    $opts = $tplanMgr->get_builds_for_html_options(
        $tplanId, null, null, array('orderByDir' => 'id:DESC'));
    if (!is_null($opts)) {
        foreach ($opts as $bid => $bname) {
            $count = $tplanMgr->assignment_mgr->get_count_of_assignments_for_build_id($bid);
            $srcItems[] = ['id' => intval($bid), 'name' => $bname,
                           'assignments' => intval($count)];
        }
    }

    $buildSet = $tplanMgr->get_builds($tplanId);
    $items = [];
    if (!is_null($buildSet)) {
        foreach ($buildSet as $b) {
            $items[] = [
                'id' => intval($b['id']),
                'name' => $b['name'],
                'notes' => (string)$b['notes'],
                'release_date' => (isset($b['release_date']) && $b['release_date'])
                    ? substr($b['release_date'], 0, 10) : '',
                'closed_on_date' => (isset($b['closed_on_date']) && $b['closed_on_date'])
                    ? substr($b['closed_on_date'], 0, 10) : '',
                'active' => intval($b['active']),
                'is_open' => intval($b['is_open']),
                'commit_id' => (string)($b['commit_id'] ?? ''),
                'tag' => (string)($b['tag'] ?? ''),
                'branch' => (string)($b['branch'] ?? ''),
                'release_candidate' => (string)($b['release_candidate'] ?? ''),
            ];
        }
    }

    // Sibling plans of this project for "copy to all test plans" info.
    $siblingPlans = 0;
    $tplanset = $tplanMgr->tproject_mgr->get_all_testplans($ctx['tproject_id']);
    if (!is_null($tplanset)) {
        foreach ($tplanset as $pid => $pinfo) {
            if (intval($pid) !== intval($tplanId)
                && isset($pinfo['active']) && intval($pinfo['active'])) {
                $siblingPlans++;
            }
        }
    }

    // Localized execution statuses for the "copy with exec status" filter
    // (legacy initializeGui(): results config + lang_get()).
    $resultsCfg = config_get('results');
    $execStatusOptions = [];
    foreach ($resultsCfg['status_label_for_exec_ui'] as $kv => $vl) {
        $execStatusOptions[] = [
            'code' => $resultsCfg['status_code'][$kv],
            'label' => lang_get($vl),
        ];
    }

    out([
        'status' => 'ok',
        'tplan' => ['id' => $tplanId, 'name' => $ctx['tplan_name'],
                    'tproject_id' => $ctx['tproject_id']],
        'builds' => $items,
        'source_builds' => $srcItems,
        'exec_status_options' => $execStatusOptions,
        'other_plans_count' => $siblingPlans,
        'rights' => [
            'canManage' => true,
            'canDeleteExec' => canDeleteExec($user, $db, $ctx['tproject_id']),
        ],
    ]);
}

// GET /{id} -> single build (edit modal prefill)
if ($method === 'GET' && count($segments) === 1 && ctype_digit($segments[0])) {
    $b = $buildMgr->get_by_id(intval($segments[0]));
    if (is_null($b)) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Build not found']);
    }
    $ctx = resolveBuild($db, $b);
    if (!canManage($user, $db, $ctx['tproject_id'])) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'Insufficient rights']);
    }
    out([
        'status' => 'ok',
        'build' => [
            'id' => intval($b['id']),
            'tproject_id' => intval($b['testproject_id']),
            'name' => $b['name'],
            'notes' => (string)$b['notes'],
            'release_date' => (isset($b['release_date']) && $b['release_date'])
                ? substr($b['release_date'], 0, 10) : '',
            'closed_on_date' => (isset($b['closed_on_date']) && $b['closed_on_date'])
                ? substr($b['closed_on_date'], 0, 10) : '',
            'active' => intval($b['active']),
            'is_open' => intval($b['is_open']),
            'commit_id' => (string)($b['commit_id'] ?? ''),
            'tag' => (string)($b['tag'] ?? ''),
            'branch' => (string)($b['branch'] ?? ''),
            'release_candidate' => (string)($b['release_candidate'] ?? ''),
        ],
    ]);
}

/* ------------------------------------------------------------------ */
/* POST routes                                                         */
/* ------------------------------------------------------------------ */

// POST /  -> create (legacy do_create incl. copy options)
if ($method === 'POST' && count($segments) === 0) {
    $body = getBody();
    $tplanId = intval($body['tplan_id'] ?? 0);
    if ($tplanId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test plan id']);
    }
    $ctx = resolveTplan($db, $tplanId);
    if (!canManage($user, $db, $ctx['tproject_id'])) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'Insufficient rights']);
    }
    $tp = $ctx['tplan_mgr'];

    $name = strField($body, 'name');
    if (is_null($name)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'empty_field_no', 'field' => 'name']);
    }
    [$rdate, $err] = isoDateOrNull($body);
    if ($err) {
        http_response_code(400);
        out(['status' => 'error', 'message' => $err]);
    }
    // Legacy crossChecks: duplicate name inside THIS test plan.
    if ($tp->check_build_name_existence($tplanId, $name, null)) {
        http_response_code(409);
        out(['status' => 'error', 'message' => 'warning_duplicate_build', 'detail' => $name]);
    }

    $isActive = empty($body['active']) ? 0 : 1;
    $isOpen = empty($body['open']) ? 0 : 1;

    $oBuild = new stdClass();
    $oBuild->name = $name;
    $oBuild->tplan_id = $tplanId;
    $oBuild->release_date = $rdate;
    $oBuild->notes = isset($body['notes']) ? (string)$body['notes'] : '';
    $oBuild->commit_id = strField($body, 'commit_id');
    $oBuild->tag = strField($body, 'tag');
    $oBuild->branch = strField($body, 'branch');
    $oBuild->release_candidate = strField($body, 'release_candidate');
    $oBuild->is_active = $isActive;
    $oBuild->is_open = $isOpen;
    try {
        $buildID = $buildMgr->createFromObject($oBuild);
    } catch (Exception $ex) {
        http_response_code(500);
        out(['status' => 'error', 'message' => 'cannot_add_build']);
    }

    if (!$buildID) {
        http_response_code(500);
        out(['status' => 'error', 'message' => 'cannot_add_build']);
    }

    // Legacy do_create: closing a build stamps closed_on_date.
    if (!$isOpen) {
        $buildMgr->setClosedOnDate($buildID, date('Y-m-d'));
    }

    // Copy tester assignments from source build (legacy behavior).
    // Security: the source build MUST belong to this test plan, otherwise a
    // user could clone assignments (user ids) from a plan of another project.
    $copyAssign = !empty($body['copy_tester_assignments']);
    $sourceBuildId = intval($body['source_build_id'] ?? 0);
    if ($copyAssign && $sourceBuildId > 0) {
        $src = $buildMgr->get_by_id($sourceBuildId);
        if (is_null($src) || intval($src['testproject_id']) !== $ctx['tproject_id']) {
            http_response_code(400);
            out(['status' => 'error', 'message' => 'Invalid source build']);
        }
        $statusFilter = isset($body['exec_status_filter']) &&
                        is_array($body['exec_status_filter'])
            ? array_map('strval', $body['exec_status_filter']) : null;
        copyTesterAssignments($tp, $tplanId, $sourceBuildId, $buildID,
                              intval($userId), $statusFilter);
    }

    // Copy to all other active plans of this project (legacy doCopyToTestPlans).
    if (!empty($body['copy_to_all_tplans'])) {
        doCopyToTestPlans($tp, $db, $ctx, $name, (string)$oBuild->notes,
                          $isActive, $isOpen);
    }

    logAuditEvent(TLS('audit_build_created',
        tprojectName($tp, $ctx['tproject_id']), $ctx['tplan_name'], $name),
        'CREATE', $buildID, 'builds');

    out(['status' => 'ok', 'id' => intval($buildID)]);
}

// POST /{id}/flags -> active/open toggles (legacy setActive/setInactive/open/close)
if ($method === 'POST' && count($segments) === 2 && ctype_digit($segments[0])
    && $segments[1] === 'flags') {
    $buildId = intval($segments[0]);
    $body = getBody();
    $b = $buildMgr->get_by_id($buildId);
    if (is_null($b)) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Build not found']);
    }
    $ctx = resolveBuild($db, $b);
    if (!canManage($user, $db, $ctx['tproject_id'])) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'Insufficient rights']);
    }
    if (!array_key_exists('active', $body) && !array_key_exists('open', $body)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Nothing to change']);
    }
    if (array_key_exists('active', $body)) {
        if ((bool)$body['active']) { $buildMgr->setActive($buildId); }
        else { $buildMgr->setInactive($buildId); }
    }
    if (array_key_exists('open', $body)) {
        // stamp/clear closure date only on real transitions (legacy parity)
        $wasOpen = intval($b['is_open']) === 1;
        $nowOpen = (bool)$body['open'];
        if ($nowOpen) {
            $buildMgr->setOpen($buildId);
            if (!$wasOpen) { $buildMgr->setClosedOnDate($buildId, null); }
        } else {
            $buildMgr->setClosed($buildId);
            if ($wasOpen) { $buildMgr->setClosedOnDate($buildId, date('Y-m-d')); }
        }
    }
    out(['status' => 'ok']);
}

/**
 * Copy tester assignments source->new build.
 * Mirrors legacy doCreate(): full copy when no status filter, otherwise
 * per-platform hits filtered by exec status.
 */
function copyTesterAssignments(&$tp, $tplanId, $srcId, $dstId, $userId, $statusFilter) {
    if (is_null($statusFilter) || count($statusFilter) === 0) {
        $tp->assignment_mgr->copy_assignments($srcId, $dstId, $userId);
        return;
    }
    $resultsCfg = config_get('results');
    $execVerboseDomain = array_flip($resultsCfg['status_code']);

    $getOpt = array('outputFormat' => 'mapAccessByID', 'addIfNull' => true,
                    'outputDetails' => 'name');
    $platformSet = $tp->getPlatforms($tplanId, $getOpt);
    $caOpt = array();
    $caOpt['keep_old_assignments'] = true;
    foreach ($platformSet as $platform_id => $pname) {
        $glf = array('filters' => array('platform_id' => $platform_id));
        foreach ($statusFilter as $ec) {
            // ignore unknown status codes silently (avoid E_WARNING noise)
            if (!isset($execVerboseDomain[$ec])) { continue; }
            if ($execVerboseDomain[$ec] === 'not_run') {
                $tcaseSet = $tp->getHitsNotRunForBuildAndPlatform(
                    $tplanId, $platform_id, $srcId);
            } else {
                $tcaseSet = $tp->getHitsSingleStatusFull(
                    $tplanId, $platform_id, $ec, array($srcId));
            }
            if (!is_null($tcaseSet)) {
                $targetSet = array_keys($tcaseSet);
                $features = $tp->getLinkedFeatures($tplanId, $glf['filters']);
                $caOpt['feature_set'] = null;
                foreach ($targetSet as $tcase_id) {
                    if (isset($features[$tcase_id][$platform_id]['feature_id'])) {
                        $caOpt['feature_set'][] =
                            $features[$tcase_id][$platform_id]['feature_id'];
                    }
                }
                if (!empty($caOpt['feature_set'])) {
                    $tp->assignment_mgr->copy_assignments($srcId, $dstId, $userId, $caOpt);
                }
            }
        }
    }
}

/**
 * Create same-named build in every other active plan of the project when the
 * name is free there (legacy doCopyToTestPlans()).
 */
function doCopyToTestPlans(&$tp, &$db, $ctx, $name, $notes, $active, $open) {
    $filters = array('tplan2exclude' => $ctx['tplan_id']);
    $tplanset = $tp->tproject_mgr->get_all_testplans($ctx['tproject_id'], $filters);
    if (!is_null($tplanset)) {
        $bm = new build($db);
        foreach ($tplanset as $pid => $info) {
            if (isset($info['active']) && !intval($info['active'])) {
                continue;
            }
            if (!$tp->check_build_name_existence(intval($pid), $name)) {
                $bm->create(intval($pid), $name, $notes, $active, $open);
            }
        }
    }
}

/* ------------------------------------------------------------------ */
/* PUT route - update (legacy do_update)                               */
/* ------------------------------------------------------------------ */
if ($method === 'PUT' && count($segments) === 1 && ctype_digit($segments[0])) {
    $buildId = intval($segments[0]);
    $body = getBody();
    $b = $buildMgr->get_by_id($buildId);
    if (is_null($b)) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Build not found']);
    }
    $ctx = resolveBuild($db, $b);
    if (!canManage($user, $db, $ctx['tproject_id'])) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'Insufficient rights']);
    }
    $tp = new testplan($db);
    $ctx['tplan_mgr'] = $tp;

    $name = strField($body, 'name');
    if (is_null($name)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'empty_field_no', 'field' => 'name']);
    }
    [$rdate, $err] = isoDateOrNull($body);
    if ($err) {
        http_response_code(400);
        out(['status' => 'error', 'message' => $err]);
    }
    // Legacy crossChecks: duplicate name inside THIS project, excluding self.
    if ($buildMgr->checkNameExistence($ctx['tproject_id'], $name, $buildId)) {
        http_response_code(409);
        out(['status' => 'error', 'message' => 'warning_duplicate_build', 'detail' => $name]);
    }

    $attr = array(
        'release_date' => $rdate,
        'release_candidate' => strField($body, 'release_candidate'),
        'is_active' => empty($body['active']) ? 0 : 1,
        'is_open' => empty($body['open']) ? 0 : 1,
        'commit_id' => strField($body, 'commit_id'),
        'tag' => strField($body, 'tag'),
        'branch' => strField($body, 'branch'),
    );
    $notes = isset($body['notes']) ? (string)$body['notes'] : '';
    if (!$buildMgr->update($buildId, $name, $notes, $attr)) {
        http_response_code(500);
        out(['status' => 'error', 'message' => 'cannot_update_build']);
    }
    // Legacy do_update semantics: build::update() unconditionally resets
    // closed_on_date to NULL (latent behavior of the class), so we must
    // restore/adjust afterwards:
    //   open->closed : stamp today
    //   closed->open : clear
    //   still closed : preserve the historical closure date
    $wasOpen = intval($b['is_open']) === 1;
    $nowOpen = !empty($attr['is_open']);
    if ($wasOpen && !$nowOpen) {
        $buildMgr->setClosedOnDate($buildId, date('Y-m-d'));
    } elseif (!$wasOpen && $nowOpen) {
        $buildMgr->setClosedOnDate($buildId, null);
    } elseif (!$nowOpen) {
        $hist = isset($b['closed_on_date']) ? substr((string)$b['closed_on_date'], 0, 10) : '';
        $buildMgr->setClosedOnDate($buildId, ($hist !== '') ? $hist : null);
    }

    logAuditEvent(TLS('audit_build_saved',
        tprojectName($tp, $ctx['tproject_id']), $ctx['tproject_name'], $name),
        'SAVE', $buildId, 'builds');

    out(['status' => 'ok']);
}

/* ------------------------------------------------------------------ */
/* DELETE route - delete (legacy do_delete)                            */
/* ------------------------------------------------------------------ */
if ($method === 'DELETE' && count($segments) === 1 && ctype_digit($segments[0])) {
    $buildId = intval($segments[0]);
    $b = $buildMgr->get_by_id($buildId);
    if (is_null($b)) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Build not found']);
    }
    $ctx = resolveBuild($db, $b);
    if (!canManage($user, $db, $ctx['tproject_id'])) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'Insufficient rights']);
    }

    // Legacy doDelete(): executions on this build require exec_delete right.
    // Builds are project-scoped (issue #503), so count executions across all
    // plans of the project.
    $buildTables = tlObjectWithDB::getDBTables(array('executions', 'testplans'));
    $qry = "SELECT COUNT(0) AS qty FROM {$buildTables['executions']} E " .
           "JOIN {$buildTables['testplans']} TP ON TP.id = E.testplan_id " .
           "WHERE TP.testproject_id = {$ctx['tproject_id']} " .
           "AND E.build_id = {$buildId}";
    $rsq = $db->get_recordset($qry);
    $qty = $rsq[0]['qty'] ?? 0;
    if ($qty > 0 && !canDeleteExec($user, $db, $ctx['tproject_id'])) {
        http_response_code(409);
        out(['status' => 'error', 'message' => 'cannot_delete_build_no_exec_delete',
             'detail' => $b['name']]);
    }
    if (!$buildMgr->delete($buildId)) {
        http_response_code(500);
        out(['status' => 'error', 'message' => 'cannot_delete_build']);
    }
    logAuditEvent(TLS('audit_build_deleted',
        tprojectName($tplanMgr, $ctx['tproject_id']), $ctx['tproject_name'], $b['name']),
        'DELETE', $buildId, 'builds');

    out(['status' => 'ok']);
}

http_response_code(404);
out(['status' => 'error', 'message' => 'Unknown route']);
