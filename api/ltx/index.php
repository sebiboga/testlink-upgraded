<?php
/**
 * Direct Links Frameset Gateway BFF (legacy `ltx.php`)
 * URL: /api/ltx/
 * Plain PHP, no framework, no compilation.
 *
 * Modern replacement for the whole of `ltx.php` (Refs #1677).
 *
 * LEGACY BEHAVIOUR (ltx.php, 481 lines, repo root)
 * ------------------------------------------------
 * "Direct links for external access to testlink items with frames for
 * navigation and tree" - the LAST root-level legacy frameset gateway still
 * rendering Smarty (`main.tpl` / `frmInner.tpl` / `workframe.tpl`). Its
 * siblings are all modernized already: `linkto.php` -> gui/templates/links/
 * directLink.html (Refs #1532/#1542), `lnl.php` -> gui/templates/links/
 * publicLink.html (Refs #1541), `ltcp.php` -> gui/templates/testcases/
 * tcLaunchPrint.html (Refs #1623).
 *
 * It was a TWO-STEP frameset:
 *   Step 1 (outer) `ltx.php?item=<item>&...`
 *       init_args() validates the item, then checkTestPlan() loads the plan,
 *       derives the owning project and requires `testplan_execute` on
 *       (project, plan); launch_outer_exec() / launch_outer_xta2m() then
 *       display `main.tpl` - the legacy navBar + asideMenu + inner-iframe
 *       frameset whose content frame is `ltx.php?...&load=1`.
 *   Step 2 (inner) `ltx.php?item=<item>&load=1&...`
 *       launch_inner_exec() / launch_inner_xta2m() re-run init_args(), resolve
 *       the work-frame URL and display `frmInner.tpl` (left exec navigator
 *       frame + right work frame) or `workframe.tpl` (single frame).
 *
 *       exec  -> `lib/execute/execSetResults.php?level=testcase&version_id=..
 *                 &id=<tcversion node parent_id>&setting_testplan=..` as work
 *                 frame + `lib/execute/execNavigator.php?loadExecDashboard=0&..`
 *                 as tree frame
 *       xta2m -> `gui/templates/results/assignedTcOverview.html?tproject_id=..
 *                 &user_id=..[&tplan_id=..][&build_id=..]`
 *                 (xtra tasks assigned to ME - eXecution Tasks Assigned TO Me)
 *
 * WHAT THIS BFF DOES
 * ------------------
 * Same resolution, JSON answer, so `gui/templates/links/ltxDirectLink.html`
 * can render a localized "deep link resolved" card with the full context
 * (project / plan / build / platform / test case / target user) and hand over
 * to the ALREADY-MODERN screens:
 *   exec  -> gui/templates/execute/execSetResults.html
 *            (legacy lib/execute/execSetResults.php, now a shim)
 *          + gui/templates/execute/execNavigator.html   (left frame twin)
 *   xta2m -> gui/templates/results/assignedTcOverview.html
 * The 2.0.1 shell (navBar + asideMenu + content iframe) is provided by
 * index.php, so the legacy `main.tpl` outer frameset is simply dropped.
 *
 * LEGACY FAILURE PARITY (`$commonText` markers, as in the ltcp.php gateway)
 *
 *   legacy branch                                  | here
 *   ----------------------------------------------+------------------------------
 *   echo lang_get('security_check_ko')            | 400 LTX-01  security_check_ko
 *   die('ltx - tplan info does not exist')        | 404 LTX-02  plan_not_found
 *   $hasRight === false (default: "need to fail!")| 403 LTX-03  no_rights
 *   echo lang_get('build_id_not_set')             | 400 LTX-04  build_id_not_set
 *   echo lang_get('testplan_not_set')             | 400 LTX-06  testplan_not_set
 *   echo lang_get('tcversion_id')                 | 400 LTX-07  tcversion_not_set
 *   (nothing echoed, plain $jump_to['msg']='ko')  | 404 LTX-09  not_resolvable
 *   null recordset from an unknown feature_id     | 404 LTX-10  unknown_feature
 *
 * NOTE: the legacy table also listed LTX-05 (item_not_set) and LTX-08
 * (platform_id_not_set). No legacy branch ever reached them - `item` was
 * validated by the init_args switch that answers LTX-01, and a missing
 * platform_id is OPTIONAL in the legacy script (it defaulted to the plan
 * link's platform). They are not emulated, and the screen has no mapping for
 * them either. The code review (MINOR-14) flagged this comment as claiming
 * two branches that do not exist.
 *
 * Every distinct machine code actually emitted (19, verified by grepping
 * `fail('`): unauthenticated, unknown_action, method_not_allowed,
 * security_check_ko, build_id_not_set, testplan_not_set, tcversion_not_set,
 * missing_user_id, not_your_tasks, no_rights, plan_not_found,
 * project_not_found, tcversion_not_found, tcase_not_found, unknown_build,
 * unknown_platform, version_not_in_plan, unknown_feature, server_error.
 *
 * SECURITY FIXES vs the legacy script (each one a real hole, see #1677 body)
 * ------------------------------------------------------------------------
 * 1. The inner frame (`&load=1`) was DIRECTLY reachable and ran
 *    init_args() only - which never calls checkTestPlan() and never calls
 *    check_xta2m(). So `ltx.php?item=exec&load=1&build_id=..&tplan_id=..`
 *    skipped the `testplan_execute` right check entirely, and
 *    `ltx.php?item=xta2m&load=1&user_id=<other>&tplan_id=..` skipped the
 *    "assigned to ME" self-check, i.e. any authenticated user could open
 *    another user's assigned-test-case overview. Here the right check and
 *    the self-check are applied on EVERY action, inner or not.
 * 2. check_exec() read `$rs[0]['testplan_id']` from
 *    `testplan_tcversions WHERE id = <feature_id>` with no null guard: an
 *    unknown feature_id dereferenced null (PHP 8 warning, broken deep link).
 *    Unknown feature_id is now a clean 404 LTX-10.
 * 3. Nothing proved the submitted build_id / platform_id / tcversion_id
 *    belonged to the resolved test plan, so a deep link could be issued for
 *    plan A (which the user may execute) while pointing at plan B's build /
 *    platform / version. Membership in the plan is now proven for all three.
 * 4. build_link_exec() interpolated `$_GET['anchor']` unescaped into the
 *    frameset URL that became the iframe src. The anchor is now passed
 *    through a strict whitelist (alnum, dash, underscore, dot) and dropped
 *    otherwise.
 * 5. `$argsObj->feature_id > 0` compared a raw GET STRING with 0, so
 *    feature_id="1abc" was truthy. All numeric params are intval'ed first.
 *
 * Contract:
 *   GET ?action=init&item=exec|xta2m
 *       [&tplan_id=N][&build_id=N][&platform_id=N][&tcversion_id=N]
 *       [&feature_id=N][&user_id=N][&anchor=S]
 *     -> 200 { status:'ok', item, context:{...}, targets:{...}, options:{...},
 *               legacy_code:null }
 *     -> 400/403/404 { status:'error', code, legacy_code, message }
 *     -> 401 unauthenticated / session_expired, 405 non-GET.
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
require_once(__DIR__ . '/../_guard.php');

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function out($data, $code = null) {
    if (!is_null($code)) {
        http_response_code($code);
    }
    echo json_encode($data);
    exit;
}

function fail($code, $httpCode, $legacyCode, $message) {
    out(array('status' => 'error', 'code' => $code,
              'legacy_code' => $legacyCode, 'message' => $message), $httpCode);
}

/** intval() a GET param, tolerating absent / non-numeric input (fix 5). */
function gInt($key) {
    $v = $_GET[$key] ?? 0;
    return is_numeric($v) ? intval($v) : 0;
}

try {
    doSessionStart();
    bffSameOriginGuard();

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        fail('method_not_allowed', 405, null, 'Only GET is accepted');
    }

    // Connect INSIDE the try and INSIDE the auth gate (code review MINOR-8 /
    // MINOR-13). common.php:190-191 echoes the raw dbms_msg on a failed
    // connect, so doing this before the guard leaked an untranslated DBMS
    // message - host and database name - in the body of an HTTP 200.
    $db = new database(DB_TYPE);
    $conn = doDBConnect($db);
    if (!empty($conn) && isset($conn['status']) && !$conn['status']) {
        fail('server_error', 500, null, 'Database connection failed');
    }

    $action = trim(strval($_GET['action'] ?? ''));
    if ($action !== 'init') {
        fail('unknown_action', 400, null, 'Unknown or missing action');
    }

    // ---- authentication (ltx.php ran testlinkInitPage($db,true)) -----------
    $userId = isset($_SESSION['userID']) ? intval($_SESSION['userID']) : 0;
    if ($userId <= 0) {
        fail('unauthenticated', 401, null, 'Not authenticated');
    }
    $user = tlUser::getByID($db, $userId);
    if (is_null($user)) {
        fail('unauthenticated', 401, null, 'Not authenticated');
    }
    bffEnforceSession($db);

    // ---- item (ltx.php init_args() switch) --------------------------------
    $item = trim(strval($_GET['item'] ?? ''));
    if ($item !== 'exec' && $item !== 'xta2m') {
        // legacy: `echo lang_get('security_check_ko')` for every other item
        // (init_args default branch sets status_ok = false)
        fail('security_check_ko', 400, 'LTX-01',
             'System checks do not allow the operation requested');
    }

    $tplanId    = gInt('tplan_id');
    $buildId    = gInt('build_id');
    // Distinguish "no platform_id key" from an explicit `platform_id=0`
    // ("no platform"): only the former may fall back to the plan link's
    // platform (code review MINOR-3).
    $platformIdGiven = isset($_GET['platform_id']);
    $platformId = gInt('platform_id');
    $tcversionId = gInt('tcversion_id');
    $featureId  = gInt('feature_id');
    $targetUserId = gInt('user_id');

    // ---- xta2m: eXecution Tasks Assigned TO Me ---------------------------
    // legacy check_xta2m(): user_id > 0 && tplan_id > 0 && user_id === self
    if ($item === 'xta2m') {
        if ($targetUserId <= 0) {
            fail('missing_user_id', 400, 'LTX-01', 'Missing user_id');
        }
        if ($tplanId <= 0) {
            fail('testplan_not_set', 400, 'LTX-06', 'Test plan not set');
        }
        if ($targetUserId !== $userId) {
            // legacy parity: status_ok = false when user_id != session user
            fail('not_your_tasks', 403, 'LTX-03',
                 'Execution tasks assigned to another user are not accessible');
        }
    }

    // ---- exec: resolve the plan context (legacy check_exec + checkTestPlan)
    if ($item === 'exec') {
        if ($buildId <= 0) {
            // legacy init_args: status_ok = (build_id > 0)
            fail('build_id_not_set', 400, 'LTX-04', 'Build not set');
        }
        if ($featureId > 0) {
            // legacy check_exec(): the feature_id is a testplan_tcversions row
            // and carries the whole plan/platform/version triple. Null guard
            // added here (fix 2) - legacy read $rs[0]['testplan_id'] blind.
            $tbl = DB_TABLE_PREFIX . 'testplan_tcversions';
            $rs = $db->get_recordset(
                "SELECT id, testplan_id, tcversion_id, platform_id" .
                " FROM $tbl WHERE id = $featureId");
            if (is_null($rs) || count($rs) === 0) {
                fail('unknown_feature', 404, 'LTX-10',
                     'The requested execution feature does not exist');
            }
            $tplanId = intval($rs[0]['testplan_id']);
            $tcversionId = intval($rs[0]['tcversion_id']);
            // Only DEFAULT the platform from the feature row: an explicitly
            // submitted platform_id must win, otherwise the platform selector
            // on the screen silently snaps back to the feature row's platform
            // on every Apply (code review MAJOR-1). An explicit empty value
            // means "no platform" and is honoured (code review MINOR-3).
            if ($platformId <= 0 && !$platformIdGiven) {
                $platformId = intval($rs[0]['platform_id']);
            }
        }
        if ($tplanId <= 0 || $tcversionId <= 0) {
            if ($tcversionId <= 0) {
                fail('tcversion_not_set', 400, 'LTX-07', 'Test case version not set');
            }
            fail('testplan_not_set', 400, 'LTX-06', 'Test plan not set');
        }
    }

    // ---- test plan + owning project (legacy checkTestPlan) ----------------
    // NOTE 2.0.1 schema: `testprojects` and `testplans` have NO `name`
    // column - the human name of a project/plan lives in its
    // `nodes_hierarchy` row. testplan::get_by_id('full') is the helper that
    // performs that join (TPLAN.* + NH_TPLAN.name + tproject_id +
    // tproject_name + prefix), so `output => 'full'` is mandatory here;
    // 'minimun' (what ltx.php used) does NOT carry active/is_open.
    $tplanMgr = new testplan($db);
    $planRow = $tplanMgr->get_by_id($tplanId, array('output' => 'full'));
    if (is_null($planRow) || !isset($planRow['id'])) {
        // legacy: die('ltx - tplan info does not exist')
        fail('plan_not_found', 404, 'LTX-02',
             'ltx - tplan info does not exist');
    }
    $tprojectId = intval($planRow['tproject_id'] ?? 0);
    if ($tprojectId <= 0) {
        fail('project_not_found', 404, 'LTX-02', 'Test project of the plan not found');
    }
    $tprojectMgr = new testproject($db);
    $projectRow = $tprojectMgr->get_by_id($tprojectId);
    if (is_null($projectRow)) {
        fail('project_not_found', 404, 'LTX-02', 'Test project not found');
    }

    // legacy checkTestPlan(): testplan_execute on (project, plan) for
    // exec + xta2m. Applied on every action - the legacy inner frame never
    // did this (fix 1).
    $hasRight = $user->hasRight($db, 'testplan_execute', $tprojectId, $tplanId);
    if (!$hasRight) {
        fail('no_rights', 403, 'LTX-03',
             'System checks do not allow the operation requested');
    }

    // ---- shared context ---------------------------------------------------
    $tprojectName = strval($projectRow['name'] ?? ($planRow['tproject_name'] ?? ''));
    $tprojectPrefix = strval($projectRow['prefix'] ?? ($planRow['prefix'] ?? ''));
    $tplanName = strval($planRow['name'] ?? '');
    $tplanActive = intval($planRow['active'] ?? 0);
    $tplanIsOpen = intval($planRow['is_open'] ?? 0);

    $baseHref = defined('TL_BASE_HREF') ? strval(TL_BASE_HREF) : '';
    if ($baseHref === '' && isset($_SESSION['basehref'])) {
        $baseHref = strval($_SESSION['basehref']);
    }
    $baseHref = rtrim($baseHref, '/');

    // anchor: strict whitelist (fix 4) - it is concatenated into an iframe
    // src, so anything that could break out of the URL is dropped.
    $anchor = trim(strval($_GET['anchor'] ?? ''));
    if ($anchor !== '' && preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $anchor) !== 1) {
        $anchor = '';
    }
    $anchorPart = ($anchor === '') ? '' : '&anchor=' . rawurlencode($anchor);

    $context = array(
        'user' => array('user_id' => $userId,
                        'display_name' => strval($user->getDisplayName())),
        'tproject' => array('id' => $tprojectId, 'name' => $tprojectName,
                            'prefix' => $tprojectPrefix),
        'tplan'    => array('id' => $tplanId, 'name' => $tplanName,
                            'active' => $tplanActive, 'is_open' => $tplanIsOpen),
    );

    // Table names for both branches (the xta2m branch proves the forwarded
    // build belongs to the owning project, so it needs `builds` too).
    $tables = tlObjectWithDB::getDBTables(array(
        'nodes_hierarchy', 'tcversions', 'testplan_tcversions', 'builds',
        'platforms'));

    // =====================================================================
    // xta2m -> assignedTcOverview.html (legacy launch_inner_xta2m)
    // =====================================================================
    if ($item === 'xta2m') {
        $context['user']['target_user_id'] = $targetUserId;
        $context['user']['is_self'] = true;

        $url = $baseHref . '/gui/templates/results/assignedTcOverview.html'
             . '?tproject_id=' . $tprojectId
             . '&user_id=' . $targetUserId;
        // legacy: only forwarded the ids that were > 0
        if ($tplanId > 0)  { $url .= '&tplan_id=' . $tplanId; }
        if ($buildId > 0) {
            // The build is forwarded to assignedTcOverview.html, so prove it
            // belongs to the OWNING project first - the exec branch already
            // did, this branch did not (code review MINOR-2).
            $bRs = $db->get_recordset(
                "SELECT id FROM {$tables['builds']}" .
                " WHERE id = $buildId AND testproject_id = $tprojectId");
            if (is_null($bRs) || count($bRs) === 0) {
                fail('unknown_build', 404, 'LTX-09',
                     'Build not found in this test project');
            }
            $url .= '&build_id=' . $buildId;
        }

        out(array(
            'status' => 'ok',
            'item' => 'xta2m',
            'legacy_code' => null,
            'context' => $context,
            'targets' => array(
                'primary_label_key' => 'ltx.openOverview',
                'primary_url' => $url,
                'tree_url' => null,
            ),
            'options' => array('builds' => array(), 'platforms' => array(),
                               'linked_versions' => array()),
        ));
    }

    // =====================================================================
    // exec -> execSetResults.html + execNavigator.html
    // =====================================================================

    // the test case node: in 2.0.1 a version is its own nodes_hierarchy row
    // whose parent is the test case node (see api/execsetresults
    // esrResolveTcVersion).
    $verRs = $db->get_recordset(
        "SELECT V.id, V.version, V.tc_external_id, NH.name AS tc_name," .
        " NH.parent_id AS tcase_id, NH.name AS node_name" .
        " FROM {$tables['tcversions']} V" .
        " JOIN {$tables['nodes_hierarchy']} NH ON NH.id = V.id" .
        " WHERE V.id = $tcversionId");
    if (is_null($verRs) || count($verRs) === 0) {
        // legacy process_exec() read $info['parent_id'] off a null $info
        fail('tcversion_not_found', 404, 'LTX-09',
             'Test case version not found');
    }
    $verRow = $verRs[0];
    $tcaseId = intval($verRow['tcase_id'] ?? 0);
    if ($tcaseId <= 0) {
        fail('tcase_not_found', 404, 'LTX-09', 'Test case not found');
    }

    // the version must be LINKED to the plan (fix 3). A version linked under
    // several platforms has one testplan_tcversions row PER platform, so the
    // row used for `feature` / the platform fallback is chosen deterministically
    // (ORDER BY id) and PREFERRED for the requested platform - but a version
    // linked under platform A is still perfectly valid on platform B, so the
    // platform filter is a preference and never a gate (code review MINOR-7).
    $linkSql = "SELECT id, platform_id FROM {$tables['testplan_tcversions']}" .
               " WHERE testplan_id = $tplanId AND tcversion_id = $tcversionId" .
               " ORDER BY id";
    $linkRs = $db->get_recordset($linkSql);
    if (is_null($linkRs) || count($linkRs) === 0) {
        fail('version_not_in_plan', 404, 'LTX-09',
             'The test case version is not linked to this test plan');
    }
    $linkRow = $linkRs[0];
    if ($platformId > 0) {
        foreach ($linkRs as $candidate) {
            if (intval($candidate['platform_id'] ?? 0) === $platformId) {
                $linkRow = $candidate;
                break;
            }
        }
    }

    // the build must belong to the OWNING project (fix 3)
    $buildRow = $db->get_recordset(
        "SELECT id, name, is_open, active FROM {$tables['builds']}" .
        " WHERE id = $buildId AND testproject_id = $tprojectId");
    if (is_null($buildRow) || count($buildRow) === 0) {
        fail('unknown_build', 404, 'LTX-09', 'Build not found in this test project');
    }
    $buildRow = $buildRow[0];
    $buildName = strval($buildRow['name'] ?? '');

    // the platform must belong to the OWNING project when one was submitted
    // (fix 3). platform_id = 0 means "no platform", which legacy allowed.
    $platformName = '';
    $platformIsOpen = 1;
    if ($platformId > 0) {
        $platRs = $db->get_recordset(
            "SELECT id, name, is_open" .
            " FROM {$tables['platforms']}" .
            " WHERE id = $platformId AND testproject_id = $tprojectId");
        if (is_null($platRs) || count($platRs) === 0) {
            fail('unknown_platform', 404, 'LTX-09',
                 'Platform not found in this test project');
        }
        $platformName = strval($platRs[0]['name'] ?? '');
        $platformIsOpen = intval($platRs[0]['is_open'] ?? 1);
    }
    // When the caller named NO platform at all, keep the plan link's platform
    // so the resolved deep link is complete. An EXPLICIT `platform_id=0` (the
    // screen's "No platform" choice) is honoured and must not be overridden -
    // otherwise that option can never take effect (code review MINOR-3).
    if ($platformId <= 0 && !$platformIdGiven) {
        $platformId = intval($linkRow['platform_id'] ?? 0);
        if ($platformId > 0) {
            $platRs = $db->get_recordset(
                "SELECT name FROM {$tables['platforms']}" .
                " WHERE id = $platformId AND testproject_id = $tprojectId");
            if (!is_null($platRs) && count($platRs) > 0) {
                $platformName = strval($platRs[0]['name'] ?? '');
            }
        }
    }

    $tcName = strval($verRow['node_name'] ?? '');
    $tcExternalId = $tprojectPrefix . '-' . intval($verRow['tc_external_id'] ?? 0);
    $versionNo = intval($verRow['version'] ?? 1);

    $suiteName = '';
    if ($tcaseId > 0) {
        $tcaseNode = $db->get_recordset(
            "SELECT name, parent_id FROM {$tables['nodes_hierarchy']}" .
            " WHERE id = $tcaseId");
        if (!is_null($tcaseNode) && count($tcaseNode) > 0) {
            $tcName = strval($tcaseNode[0]['name'] ?? $tcName);
            $suiteId = intval($tcaseNode[0]['parent_id'] ?? 0);
            if ($suiteId > 0) {
                $suiteNode = $db->get_recordset(
                    "SELECT name FROM {$tables['nodes_hierarchy']}" .
                    " WHERE id = $suiteId");
                if (!is_null($suiteNode) && count($suiteNode) > 0) {
                    $suiteName = strval($suiteNode[0]['name'] ?? '');
                }
            }
        }
    }

    $context['build']    = array('id' => $buildId, 'name' => $buildName,
                                 'is_open' => intval($buildRow['is_open'] ?? 1),
                                 'active' => intval($buildRow['active'] ?? 1));
    $context['platform'] = array('id' => $platformId, 'name' => $platformName,
                                 'is_open' => $platformIsOpen,
                                 'selected' => $platformId > 0);
    $context['tcase']    = array('tcase_id' => $tcaseId,
                                 'tcversion_id' => $tcversionId,
                                 'version' => $versionNo,
                                 'external_id' => $tcExternalId,
                                 'name' => $tcName,
                                 'suite' => $suiteName);
    $context['feature']  = array('id' => $featureId,
                                 'testplan_tcversions_id' => intval($linkRow['id'] ?? 0));

    $workUrl = $baseHref . '/gui/templates/execute/execSetResults.html'
             . '?tproject_id=' . $tprojectId
             . '&tplan_id=' . $tplanId
             . '&tcase_id=' . $tcaseId
             . '&tcversion_id=' . $tcversionId
             . '&build_id=' . $buildId
             . '&platform_id=' . $platformId
             . $anchorPart;
    // legacy tree frame: lib/execute/execNavigator.php?loadExecDashboard=0
    $treeUrl = $baseHref . '/gui/templates/execute/execNavigator.html'
             . '?tproject_id=' . $tprojectId
             . '&tplan_id=' . $tplanId
             . '&build_id=' . $buildId
             . '&platform_id=' . $platformId
             . '&loadExecDashboard=0';

    // ---- option lists so the screen can switch build / platform / version -
    $builds = array();
    $bRs = $db->get_recordset(
        "SELECT id, name FROM {$tables['builds']}" .
        " WHERE testproject_id = $tprojectId AND active = 1" .
        " ORDER BY name");
    if (!is_null($bRs)) {
        foreach ($bRs as $b) {
            $builds[] = array('id' => intval($b['id']), 'name' => strval($b['name']));
        }
    }

    $platforms = array();
    $pRs = $db->get_recordset(
        "SELECT id, name FROM {$tables['platforms']}" .
        " WHERE testproject_id = $tprojectId AND enable_on_execution = 1" .
        " ORDER BY name");
    if (!is_null($pRs)) {
        foreach ($pRs as $p) {
            $platforms[] = array('id' => intval($p['id']),
                                 'name' => strval($p['name']));
        }
    }

    // One row per LINKED test case version. A version linked under several
    // platforms has several testplan_tcversions rows, so GROUP BY the version
    // and take the lowest feature id - otherwise the selector rendered
    // duplicate <option> values (code review MINOR-11) - and join the test case
    // node for its name instead of issuing one extra query per version
    // (code review MINOR-6: a 2000-TC plan meant 2001 queries per page load).
    $linkedVersions = array();
    $lvRs = $db->get_recordset(
        "SELECT MIN(TPTCV.id) AS feature_id, TPTCV.tcversion_id," .
        " MIN(TPTCV.platform_id) AS platform_id, V.version," .
        " NH.parent_id AS tcase_id, NH_TC.name AS name, NH.name AS version_name" .
        " FROM {$tables['testplan_tcversions']} TPTCV" .
        " JOIN {$tables['tcversions']} V ON V.id = TPTCV.tcversion_id" .
        " JOIN {$tables['nodes_hierarchy']} NH ON NH.id = V.id" .
        " LEFT JOIN {$tables['nodes_hierarchy']} NH_TC ON NH_TC.id = NH.parent_id" .
        " WHERE TPTCV.testplan_id = $tplanId" .
        " GROUP BY TPTCV.tcversion_id, V.version, NH.parent_id, NH_TC.name" .
        " ORDER BY NH_TC.name, V.version");
    if (!is_null($lvRs)) {
        foreach ($lvRs as $lv) {
            $lvName = strval($lv['name'] ?? '');
            if ($lvName === '') {
                // no test case node (orphan version): fall back to the version
                // node's own name
                $lvName = strval($lv['version_name'] ?? '');
            }
            $linkedVersions[] = array(
                'feature_id' => intval($lv['feature_id']),
                'tcversion_id' => intval($lv['tcversion_id']),
                'tcase_id' => intval($lv['tcase_id'] ?? 0),
                'platform_id' => intval($lv['platform_id'] ?? 0),
                'version' => intval($lv['version'] ?? 1),
                'name' => $lvName,
            );
        }
    }

    out(array(
        'status' => 'ok',
        'item' => 'exec',
        'legacy_code' => null,
        'context' => $context,
        'targets' => array(
            'primary_label_key' => 'ltx.openExecution',
            'primary_url' => $workUrl,
            'tree_label_key' => 'ltx.openNavigator',
            'tree_url' => $treeUrl,
        ),
        'options' => array('builds' => $builds, 'platforms' => $platforms,
                           'linked_versions' => $linkedVersions),
    ));
} catch (Throwable $e) {
    tLog('BFF api/ltx: ' . $e->getMessage(), 'ERROR');
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo json_encode(array(
        'status' => 'error',
        'code' => 'server_error',
        'legacy_code' => null,
        'message' => 'Unexpected error while resolving the direct link',
    ));
    exit;
}
