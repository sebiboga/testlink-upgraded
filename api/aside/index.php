<?php
/**
 * ASIDE navigation menu BFF API
 * URL: /api/aside/
 * Plain PHP, no framework, no compilation
 *
 * Backs gui/templates/aside/aside.html — the modernized left navigation
 * rail frame (legacy lib/general/asideMenu.php + gui/templates/dashio/
 * asideFrame.tpl + aside.tpl). This was the LAST legacy lib/**.php screen
 * still served in the running frameset (Refs #1548): index.php renders the
 * menu through lib/general/asideMenu.php via Smarty. This BFF ports the same
 * server-side menu construction into structured JSON so the Dashio screen
 * can render the tree client-side.
 *
 * Route: GET ?action=init — returns the full menu tree for the effective
 * test project/plan context with exact aside.tpl parity:
 *   - section visibility        ($gui->showMenu: getMenuVisibility)
 *   - per-item rights gating    ($menuGrants / $gui->access)
 *   - every item href           ($gui->uri = $actions, pre-contexted)
 *   - the Reports sub-menu      (cfg/reports.cfg.php + the modern report map,
 *                                ports asideMenu.php)
 *   - plugin menu injections    (EVENT_LEFTMENU_TOP/BOTTOM +
 *                                EVENT_RIGHTMENU_TOP/BOTTOM)
 *   - hasKeywords / countPlans  global gates
 *   - railed (icon-rail) state  (menuRailIsOn cookie)
 * Labels are localized server-side via lang_get() (the same strings.txt
 * sources the legacy Smarty $labels object reads), so every one of the 19
 * server locales is honored out of the box.
 *
 * Session auth (401 anonymous) + same-origin CSRF guard, like every BFF.
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once(__DIR__ . '/../_guard.php');

require_once('common.php');

doSessionStart();
bffSameOriginGuard();

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
// initUserEnv() derives grants/visible sections from the session tlUser
// object; seed it like every grant-consuming BFF (api/reports, ...).
$_SESSION['currentUser'] = $user;

function asideOut($data) { echo json_encode($data); exit; }
function asideParam($key, $default = null) { return $_GET[$key] ?? $default; }

$method = $_SERVER['REQUEST_METHOD'];
$action = asideParam('action', '');

if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}
if ($action !== 'init') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Unknown or missing action']);
    exit;
}

// Commit the requested project to the session before building the menu, so a
// deep link into the menu frame alone still resolves the right context.
initProject($db, array_merge($_GET, $_POST), 'aside');

$tproject_id = intval(asideParam('tproject_id',
    isset($_SESSION['testprojectID']) ? $_SESSION['testprojectID'] : 0));
$tplan_id = intval(asideParam('tplan_id',
    isset($_SESSION['testplanID']) ? $_SESSION['testplanID'] : 0));

// Same call chain as lib/general/asideMenu.php -> TLSmarty::addMenuContext():
// initUserEnv() builds showMenu/activeMenu/uri/access/grants/countPlans.
$ctx = new stdClass();
$ctx->tproject_id = $tproject_id;
$ctx->tplan_id = $tplan_id;
list($uxArgs, $ux) = initUserEnv($db, $ctx);

$showMenu = (property_exists($ux, 'showMenu'))
    ? $ux->showMenu : array();
$uri = (property_exists($ux, 'uri') && is_object($ux->uri))
    ? $ux->uri : new stdClass();
$menuGrants = (property_exists($ux, 'grants') && is_object($ux->grants))
    ? $ux->grants : TLSmarty::emptyMenuGrants();
$access = (property_exists($ux, 'access') && is_object($ux->access))
    ? $ux->access : new stdClass();
$countPlans = (property_exists($ux, 'countPlans')) ? intval($ux->countPlans) : 0;

// hasKeywords (asideMenu.php parity: keyword assignment item shows only when
// the in-context project actually has keywords).
$hasKeywords = false;
if ($tproject_id > 0) {
    $tprojectMgr = new testproject($db);
    $hasKeywords = $tprojectMgr->hasKeywords($tproject_id);
}

// Reports sub-menu (asideMenu.php parity incl. the modern report map).
$reportsMenu = array();
if ($tplan_id > 0) {
    require_once(__DIR__ . '/../../cfg/reports.cfg.php');
    $reportsCfg = config_get('reports_list');
    if (!is_null($reportsCfg) && (is_array($reportsCfg) || is_object($reportsCfg))) {
        $tprojectMgr2 = new testproject($db);
        $baseHrefR = isset($_SESSION['basehref']) ? $_SESSION['basehref'] : '';
        $tprojOptsR = $tprojectMgr2->getOptions($tproject_id);
        $reqEnabledR = (!empty($tprojOptsR)
            && !empty($tprojOptsR->requirementsEnabled));
        $btsEnabledR = $tprojectMgr2->isIssueTrackerEnabled($tproject_id);

        foreach ($reportsCfg as $rptItem) {
            $okR = ($rptItem['enabled'] == 'all')
                || (($rptItem['enabled'] == 'req') && $reqEnabledR)
                || (($rptItem['enabled'] == 'bts') && $btsEnabledR);
            if (!$okR) {
                continue;
            }
            if (strpos(',' . $rptItem['format'], 'format_html') === false) {
                continue;
            }
            $modernTypes = array(
                'link_report_test_plan'           => 'testplan',
                'link_report_test_report'         => 'testreport',
                'link_report_test_report_on_build'=> 'testreport_onbuild',
            );
            if (isset($modernTypes[$rptItem['title']])) {
                $hrefR = '/gui/templates/results/testPlanReport.html' .
                         "?tproject_id={$tproject_id}&tplan_id={$tplan_id}" .
                         '&type=' . $modernTypes[$rptItem['title']];
            } else if ($rptItem['title'] == 'link_report_general_tp_metrics') {
                $hrefR = '/gui/templates/results/generalMetrics.html' .
                         "?tproject_id={$tproject_id}&tplan_id={$tplan_id}";
            } else if ($rptItem['title'] == 'link_report_by_tsuite') {
                $hrefR = '/gui/templates/results/resultsByTSuite.html' .
                         "?tproject_id={$tproject_id}&tplan_id={$tplan_id}";
            } else if ($rptItem['title'] == 'baseline_l1l2') {
                $hrefR = '/gui/templates/results/baselineL1L2.html' .
                         "?tproject_id={$tproject_id}&tplan_id={$tplan_id}";
            } else if ($rptItem['title'] == 'link_report_by_tester_per_build') {
                $hrefR = '/gui/templates/results/resultsByTesterPerBuild.html' .
                         "?tproject_id={$tproject_id}&tplan_id={$tplan_id}";
            } else if ($rptItem['title'] == 'link_report_test') {
                $hrefR = '/gui/templates/results/resultsMatrix.html' .
                         "?tproject_id={$tproject_id}&tplan_id={$tplan_id}";
            } else if ($rptItem['title'] == 'link_assigned_tc_overview') {
                $hrefR = '/gui/templates/results/assignedTcOverview.html' .
                         "?tproject_id={$tproject_id}" .
                         "&show_all_users=1&show_inactive_and_closed=1";
            } else if ($rptItem['title'] == 'link_report_test_flat') {
                $hrefR = '/gui/templates/results/resultsTCFlat.html' .
                         "?tproject_id={$tproject_id}&tplan_id={$tplan_id}";
            } else if ($rptItem['title'] == 'link_report_test_absolute_latest_exec') {
                $hrefR = '/gui/templates/results/absoluteLatest.html' .
                         "?tproject_id={$tproject_id}&tplan_id={$tplan_id}";
            } else if ($rptItem['title'] == 'link_report_failed') {
                $hrefR = '/gui/templates/results/resultsByStatus.html' .
                         "?tproject_id={$tproject_id}&tplan_id={$tplan_id}&status=failed";
            } else if ($rptItem['title'] == 'link_report_blocked_tcs') {
                $hrefR = '/gui/templates/results/resultsByStatus.html' .
                         "?tproject_id={$tproject_id}&tplan_id={$tplan_id}&status=blocked";
            } else if ($rptItem['title'] == 'link_report_not_run') {
                $hrefR = '/gui/templates/results/resultsByStatus.html' .
                         "?tproject_id={$tproject_id}&tplan_id={$tplan_id}&status=not_run";
            } else if ($rptItem['title'] == 'link_report_tcases_with_cf') {
                $hrefR = '/gui/templates/results/tcasesWithCF.html' .
                         "?tproject_id={$tproject_id}&tplan_id={$tplan_id}";
            } else if ($rptItem['title'] == 'link_report_never_run') {
                $hrefR = '/gui/templates/results/neverRun.html' .
                         "?tproject_id={$tproject_id}&tplan_id={$tplan_id}";
            } else if ($rptItem['title'] == 'link_report_tcases_without_tester') {
                $hrefR = '/gui/templates/results/casesWithoutTester.html' .
                         "?tproject_id={$tproject_id}&tplan_id={$tplan_id}";
            } else if ($rptItem['title'] == 'link_charts') {
                $hrefR = '/gui/templates/results/charts.html' .
                         "?tproject_id={$tproject_id}&tplan_id={$tplan_id}";
            } else if ($rptItem['title'] == 'link_report_tplans_with_cf') {
                $hrefR = '/gui/templates/results/tplanWithCF.html' .
                         "?tproject_id={$tproject_id}&tplan_id={$tplan_id}";
            } else if ($rptItem['title'] == 'link_report_free_testcases_on_testproject') {
                $hrefR = '/gui/templates/results/freeTestCases.html' .
                         "?tproject_id={$tproject_id}";
            } else if ($rptItem['title'] == 'link_report_uncovered_testcases') {
                $hrefR = '/gui/templates/results/uncoveredTestCases.html' .
                         "?tproject_id={$tproject_id}";
            } else if ($rptItem['title'] == 'link_report_reqs_coverage') {
                $hrefR = '/gui/templates/results/resultsRequirements.html' .
                         "?tproject_id={$tproject_id}&tplan_id={$tplan_id}";
            } else if ($rptItem['title'] == 'link_report_exec_timeline') {
                $hrefR = '/gui/templates/results/execTimelineStats.html' .
                         "?tproject_id={$tproject_id}&tplan_id={$tplan_id}";
            } else if ($rptItem['title'] == 'link_report_total_bugs') {
                $hrefR = '/gui/templates/results/resultsBugs.html' .
                         "?tproject_id={$tproject_id}&tplan_id={$tplan_id}&type=0";
            } else if ($rptItem['title'] == 'link_report_total_bugs_all_exec') {
                $hrefR = '/gui/templates/results/resultsBugs.html' .
                         "?tproject_id={$tproject_id}&tplan_id={$tplan_id}&type=1";
            } else if ($rptItem['title'] == 'link_report_metrics_more_builds') {
                $hrefR = '/gui/templates/results/resultsMoreBuilds.html' .
                         "?tproject_id={$tproject_id}&tplan_id={$tplan_id}";
            } else if ($rptItem['title'] == 'link_report_quality_gates') {
                // Refs #1067: Release Quality Gates / Go-No-Go dashboard.
                $hrefR = '/gui/templates/results/qualityGate.html' .
                         "?tproject_id={$tproject_id}&tplan_id={$tplan_id}";
            } else if ($rptItem['title'] == 'link_report_performance_reports') {
                // Refs #1063: Performance Test Reports / JMeter JTL Viewer.
                // Project-scoped and stateless (upload-driven): the plan id is
                // forwarded for context but the screen only needs tproject_id.
                $hrefR = '/gui/templates/results/perfReports.html' .
                         "?tproject_id={$tproject_id}&tplan_id={$tplan_id}";
            } else if ($rptItem['title'] == 'link_report_automation_reports') {
                // Refs #1062 / #1921: Automation Test Reports (manual vs
                // automated execution aggregation).
                $hrefR = '/gui/templates/results/automationReports.html' .
                         "?tproject_id={$tproject_id}&tplan_id={$tplan_id}";
            } else if ($rptItem['title'] == 'link_report_defect_intelligence') {
                // Refs #1068: Defect Intelligence dashboard (failure & defect
                // analytics from execution_bugs links).
                $hrefR = '/gui/templates/results/defectIntelligence.html' .
                         "?tproject_id={$tproject_id}&tplan_id={$tplan_id}";
            } else if ($rptItem['title'] == 'link_report_strategy_report') {
                // Refs #1061 / #1925: Test Strategy Report (generated
                // project-specific strategy document). Project-scoped: the
                // plan id is forwarded for context only.
                $hrefR = '/gui/templates/results/strategyReport.html' .
                         "?tproject_id={$tproject_id}&tplan_id={$tplan_id}";
            } else if ($rptItem['title'] == 'link_report_cicd_publish') {
                // Refs #1064: CI/CD Automated Result Publishing (JUnit XML /
                // JSON artifact upload -> executions).
                $hrefR = '/gui/templates/results/cicdPublish.html' .
                         "?tproject_id={$tproject_id}&tplan_id={$tplan_id}";
            } else if ($rptItem['title'] == 'link_report_metrics_trends') {
                // Refs #1935: Metrics & Trends dashboard (current quality
                // snapshot + per-build execution/defect trend).
                $hrefR = '/gui/templates/results/metricsTrends.html' .
                         "?tproject_id={$tproject_id}&tplan_id={$tplan_id}";
            } else if ($rptItem['title'] == 'link_report_flaky_tests') {
                // Refs #1954: Flaky / Unstable Test Cases report (the verdict
                // of every test-case version of the plan, computed over its
                // whole execution history).
                $hrefR = '/gui/templates/results/flakyTests.html' .
                         "?tproject_id={$tproject_id}&tplan_id={$tplan_id}";
            } else if ($rptItem['title'] == 'link_report_exec_history') {
                // Refs #1961: Test Plan Execution History report (every
                // execution row of the plan, newest first).
                $hrefR = '/gui/templates/results/execHistoryReport.html' .
                         "?tproject_id={$tproject_id}&tplan_id={$tplan_id}";
            } else if ($rptItem['title'] == 'link_report_build_comparison') {
                // Refs #1962: Test Plan Build Comparison report (per test-case
                // status diff between two builds of the same plan).
                $hrefR = '/gui/templates/results/buildComparison.html' .
                         "?tproject_id={$tproject_id}&tplan_id={$tplan_id}";
            } else if ($rptItem['title'] == 'link_report_platform_comparison') {
                // Refs #1963: Test Plan Platform Comparison report (per
                // test-case status diff between two platforms of the plan).
                $hrefR = '/gui/templates/results/platformComparison.html' .
                         "?tproject_id={$tproject_id}&tplan_id={$tplan_id}";
            } else if ($rptItem['title'] == 'link_report_plan_comparison') {
                // Refs #1957: Test Plan Comparison report (two plans of the
                // same test project side by side, delta per metric).
                $hrefR = '/gui/templates/results/planComparison.html' .
                         "?tproject_id={$tproject_id}&tplan_id={$tplan_id}";
            } else {
                $sep = (strpos($rptItem['url'], '?') !== false) ? '&' : '?';
                $hrefR = $baseHrefR . $rptItem['url'] .
                         "{$sep}format=0&tproject_id={$tproject_id}&tplan_id={$tplan_id}";
            }
            if ($hrefR !== '' && strpos($hrefR, 'lib/results') === false) {
                $reportsMenu[] = array(
                    'label' => lang_get($rptItem['title']),
                    'href' => $hrefR,
                );
            }
        }
    }
}

// Plugin menu injections (asideMenu.php parity; merged into one list).
$pluginsMenu = array();
foreach (array('EVENT_LEFTMENU_TOP', 'EVENT_LEFTMENU_BOTTOM',
               'EVENT_RIGHTMENU_TOP', 'EVENT_RIGHTMENU_BOTTOM') as $menu_item) {
    $menu_content = event_signal($menu_item);
    if (!empty($menu_content)) {
        foreach ((array)$menu_content as $entry) {
            if (is_array($entry) && isset($entry['href']) && isset($entry['label'])) {
                $pluginsMenu[] = array(
                    'label' => $entry['label'],
                    'href' => $entry['href'],
                );
            }
        }
    }
}

$sections = array();
$get = function ($node, $prop, $def = null) {
    if (is_array($node)) {
        return array_key_exists($prop, $node) ? $node[$prop] : $def;
    }
    return (is_object($node) && property_exists($node, $prop)) ? $node->$prop : $def;
};
$u = function ($prop, $def = null) use ($uri, $get) {
    $v = $get($uri, $prop, $def);
    return ($v === null || $v === '') ? $def : $v;
};
$chk = function ($node, $prop) use ($get) { return (bool)$get($node, $prop); };
$show = function ($key) use ($showMenu, $chk) { return $chk($showMenu, $key); };
$gm = function ($key, $def = null) use ($menuGrants, $get) { return $get($menuGrants, $key, $def); };

// 1. Dashboard -------------------------------------------------------------
if ($show('dashboard')) {
    $sections[] = array(
        'key' => 'dashboard',
        'label' => lang_get('title_dashboard'),
        'icon' => 'fa fa-dashboard',
        'single' => true,
        'items' => array(array(
            'id' => 'dashboard',
            'label' => lang_get('title_dashboard'),
            'href' => (string)$u('dashboard'),
            'icon' => 'fa fa-dashboard',
        )),
    );
}

// 2. Search ----------------------------------------------------------------
if ($show('search')) {
    $sections[] = array(
        'key' => 'search',
        'label' => lang_get('search'),
        'icon' => 'fa fa-search',
        'items' => array(
            array('id' => 'quick_search', 'label' => lang_get('quick_search'),
                  'href' => (string)$u('tcQuickSearch')),
            array('id' => 'href_search_tc', 'label' => lang_get('href_search_tc'),
                  'href' => (string)$u('tcSearch')),
            array('id' => 'advanced_search', 'label' => lang_get('advanced_search'),
                  'href' => (string)$u('fullTextSearch')),
        ),
    );
}

// 3. System ----------------------------------------------------------------
if ($show('system')) {
    $items = array();
    if ($gm('event_viewer') === 'yes') {
        $items[] = array('id' => 'events', 'label' => lang_get('event_viewer'),
                         'href' => (string)$u('events'));
    }
    // Refs #1958 (parent #1072): central API token manager. Personal token is
    // always visible to the caller; plan keys appear only with testplan_planning.
    $items[] = array('id' => 'apiTokens', 'label' => lang_get('title_api_tokens'),
                     'href' => (string)$u('apiTokens'), 'icon' => 'fa fa-key');
    if ($gm('user_mgmt') === 'yes') {
        $items[] = array('id' => 'userInfo',
                         'label' => lang_get('href_user_profile'),
                         'href' => (string)$u('userInfo'),
                         'icon' => 'fa fa-user-circle');
        $items[] = array('id' => 'userMgmt', 'label' => lang_get('title_user_mgmt'),
                         'href' => (string)$u('userMgmt'));
        $items[] = array('label' => lang_get('href_roles_management'),
                         'href' => (string)$u('rolesView'));
        $items[] = array('label' => lang_get('href_assign_tproject_roles'),
                         'href' => (string)$u('usersAssign'));
        $items[] = array('label' => lang_get('href_assign_tplan_roles'),
                         'href' => (string)$u('usersAssignPlan'));
    }
    if ($gm('cfield_management') === 'yes') {
        $items[] = array('id' => 'cfieldsView', 'label' => lang_get('href_cfields_management'),
                         'href' => (string)$u('cfieldsView'));
    }
    if ($get($access, "issuetracker") === "yes") {
        $items[] = array('id' => 'issueTrackerView', 'label' => lang_get('href_issuetracker_management'),
                         'href' => (string)$u('issueTrackerView'));
    }
    if ($get($access, "codetracker") === "yes") {
        $items[] = array('id' => 'codeTrackerView', 'label' => lang_get('href_codetracker_management'),
                         'href' => (string)$u('codeTrackerView'));
    }
    if ($get($access, "reqmgrsystem") === "yes") {
        $items[] = array('id' => 'reqMgrSystemView', 'label' => lang_get('href_reqmgrsystem_management'),
                         'href' => (string)$u('reqMgrSystemView'));
    }
    if ($gm('configuration') === 'yes') {
        $items[] = array('id' => 'installView', 'label' => lang_get('install_header'),
                         'href' => (string)$u('installView'));
    }
    // Refs #1946 (parent #1072): webhook subscriptions manager. Reads need
    // mgt_view_tc (menuGrants key view_tc); the BFF additionally enforces
    // mgt_view_tc (plus mgt_modify_tc for writes) on the ADDRESSED project.
    if ($gm('view_tc') === 'yes') {
        $items[] = array('id' => 'webhooks', 'label' => lang_get('title_webhooks'),
                         'href' => (string)$u('webhooks'));
    }
    // Refs #1950 (parent #1087): Apache SOLR integration. Reads need
    // mgt_view_tc (menuGrants key view_tc); the BFF additionally enforces
    // mgt_view_tc (plus mgt_modify_tc for writes) on the ADDRESSED project.
    if ($gm('view_tc') === 'yes') {
        $items[] = array('id' => 'solrIntegration',
                         'label' => lang_get('title_solr_integration'),
                         'href' => (string)$u('solrIntegration'));
    }
    // Refs #1959 (parent #1058): Koa11y Accessibility Scan Results (net-new).
    if ($gm('view_tc') === 'yes' || $gm('modify_tc') === 'yes') {
        $items[] = array('id' => 'a11yScans',
                         'label' => lang_get('title_a11y_scans'),
                         'href' => (string)$u('a11yScans'));
    }
    if (count($items) > 0) {
        $sections[] = array('key' => 'system', 'label' => lang_get('system'),
                            'icon' => 'fa fa-desktop', 'items' => $items);
    }
}

// 4. Projects --------------------------------------------------------------
if ($show('projects')) {
    $items = array();
    if ($gm('project_edit') === 'yes') {
        $items[] = array('id' => 'projectView',
                         'label' => lang_get('href_tproject_management'),
                         'href' => 'gui/templates/projectsView.html',
                         'icon' => 'fas fa-cog');
    }
    if ($gm('tproject_user_role_assignment') === 'yes') {
        $items[] = array('label' => lang_get('href_assign_user_roles'),
                         'href' => (string)$u('usersAssign'));
    }
    if ($gm('cfield_management') === 'yes') {
        $items[] = array('label' => lang_get('href_cfields_tproject_assign'),
                         'href' => (string)$u('cfAssignment'));
    }
    if ($gm('keywords_view') === 'yes') {
        $items[] = array('label' => lang_get('href_keywords_manage'),
                         'href' => (string)$u('keywordsView'));
    }
    if ($get($access, "platform") === "yes") {
        $items[] = array('label' => lang_get('href_platform_management'),
                         'href' => (string)$u('platformsView'));
    }
    if ($gm('project_inventory_view') || $gm('project_inventory_management')) {
        $items[] = array('label' => lang_get('href_inventory_management'),
                         'href' => (string)$u('inventoryView'));
    }
    if ($countPlans > 0) {
        $items[] = array('label' => lang_get('href_metrics_dashboard'),
                         'href' => (string)$u('metrics_dashboard'));
    }
    if (count($items) > 0) {
        $sections[] = array('key' => 'projects', 'label' => lang_get('projects'),
                            'icon' => 'fa fa-flask', 'items' => $items);
    }
}

// 5. Test Strategy ---------------------------------------------------------
// Legacy renders this section unconditionally (aside.tpl has no showMenu gate).
{
    $items = array();
    $stKeys = array(
        'href_test_strategy_overview' => 'testStrategy',
        'href_test_strategy_doc' => 'strategyDoc',
        // Refs #1945 (parent #1052): project-scoped test type coverage matrix.
        'href_test_strategy_coverage' => 'testTypeCoverage',
        'href_test_strategy_intro' => 'testStrategyIntro',
        'href_test_strategy_objectives' => 'testStrategyObjectives',
        'href_test_strategy_scope' => 'testStrategyScope',
        'href_test_strategy_approach' => 'testStrategyApproach',
        'href_test_strategy_levels' => 'testStrategyLevels',
        'href_test_strategy_types' => 'testStrategyTypes',
        'href_test_strategy_exit_criteria' => 'testStrategyExit',
        // Refs #1953 (parent #1088): exit criteria evaluation dashboard.
        'href_test_strategy_exit_evaluation' => 'exitCriteriaEvaluation',
        'href_test_strategy_environments' => 'testStrategyEnvironments',
        'href_test_strategy_roles' => 'testStrategyRoles',
        'href_test_strategy_tools' => 'testStrategyTools',
        'href_test_strategy_communication' => 'testStrategyCommunication',
        'href_test_strategy_deliverables' => 'testStrategyDeliverables',
        'href_test_strategy_metrics' => 'testStrategyMetrics',
        'href_test_strategy_risks' => 'testStrategyRisks',
        'href_test_strategy_defects' => 'testStrategyDefects',
        'href_test_strategy_cfg' => 'testStrategyCfg',
        'href_test_strategy_training' => 'testStrategyTraining',
        'href_test_strategy_release' => 'testStrategyRelease',
        'href_test_strategy_bug_structure' => 'testStrategyBugStructure',
        'href_test_strategy_bug_lifecycle' => 'testStrategyBugLifecycle',
        'href_test_strategy_performance' => 'testStrategyPerformance',
        'href_test_strategy_security' => 'testStrategySecurity',
        'href_test_strategy_usability' => 'testStrategyUsability',
        'href_test_strategy_accessibility' => 'testStrategyAccessibility',
        'href_test_strategy_compatibility' => 'testStrategyCompatibility',
        'href_test_strategy_reliability' => 'testStrategyReliability',
        'href_test_strategy_maintainability' => 'testStrategyMaintainability',
    );
    $stIcons = array(
        'testStrategy' => 'fas fa-book-open', 'strategyDoc' => 'fas fa-file-signature',
        'testTypeCoverage' => 'fas fa-clipboard-check',
        'testStrategyIntro' => 'fas fa-book',
        'testStrategyObjectives' => 'fas fa-bullseye', 'testStrategyScope' => 'fas fa-expand-arrows-alt',
        'testStrategyApproach' => 'fas fa-compass', 'testStrategyLevels' => 'fas fa-layer-group',
        'testStrategyTypes' => 'fas fa-check-double', 'testStrategyExit' => 'fas fa-flag-checkered',
        'exitCriteriaEvaluation' => 'fas fa-clipboard-check',
        'testStrategyEnvironments' => 'fas fa-server', 'testStrategyRoles' => 'fas fa-users',
        'testStrategyTools' => 'fas fa-wrench', 'testStrategyCommunication' => 'fas fa-comments',
        'testStrategyDeliverables' => 'fas fa-file-alt', 'testStrategyMetrics' => 'fas fa-chart-line',
        'testStrategyRisks' => 'fas fa-exclamation-triangle', 'testStrategyDefects' => 'fas fa-bug',
        'testStrategyCfg' => 'fas fa-code-branch', 'testStrategyTraining' => 'fas fa-graduation-cap',
        'testStrategyRelease' => 'fas fa-rocket', 'testStrategyBugStructure' => 'fas fa-database',
        'testStrategyBugLifecycle' => 'fas fa-arrows-alt-h', 'testStrategyPerformance' => 'fas fa-tachometer-alt',
        'testStrategySecurity' => 'fas fa-shield-alt', 'testStrategyUsability' => 'fas fa-hand-pointer',
        'testStrategyAccessibility' => 'fas fa-universal-access', 'testStrategyCompatibility' => 'fas fa-sync-alt',
        'testStrategyReliability' => 'fas fa-life-ring', 'testStrategyMaintainability' => 'fas fa-cogs',
    );
    foreach ($stKeys as $lblKey => $uriKey) {
        $h = $u($uriKey);
        if ($h === null) { continue; }
        $items[] = array('label' => lang_get($lblKey), 'href' => (string)$h,
                         'icon' => isset($stIcons[$uriKey]) ? $stIcons[$uriKey] : null);
    }
    $items[] = array('label' => lang_get('href_severity_config'),
                     'href' => 'gui/templates/projects/severityConfig.html',
                     'icon' => 'fas fa-sliders-h');
    $sections[] = array('key' => 'testStrategy', 'label' => lang_get('title_test_strategy'),
                        'icon' => 'fas fa-map-signs', 'items' => $items);
}

// 6. Requirements Design ---------------------------------------------------
if ($show('requirements_design')) {
    $items = array();
    $items[] = array('label' => lang_get('href_req_spec'), 'href' => (string)$u('reqSpecMgmt'));
    $items[] = array('label' => lang_get('href_req_spec_tree'), 'href' => (string)$u('reqSpecListTree'));
    $items[] = array('label' => lang_get('href_req_overview'), 'href' => (string)$u('reqOverView'));
    $items[] = array('label' => lang_get('href_print_req'), 'href' => (string)$u('printReqSpec'));
    $items[] = array('label' => lang_get('href_search_req'), 'href' => (string)$u('searchReq'));
    $items[] = array('label' => lang_get('href_search_req_spec'), 'href' => (string)$u('searchReqSpec'));
    if ($gm('req_tcase_link_management') === 'yes') {
        $items[] = array('label' => lang_get('href_req_assign'), 'href' => (string)$u('assignReq'));
    }
    if ($gm('monitor_req') === 'yes') {
        $items[] = array('label' => lang_get('href_req_monitor_overview'),
                         'href' => (string)$u('reqMonOverView'));
    }
    if ($gm('reqs_view') === 'yes') {
        $items[] = array('label' => lang_get('href_quality_objectives'),
                         'href' => (string)$u('qualityObjectives'));
    }
    if ($gm('reqs_view') === 'yes') {
        $items[] = array('label' => lang_get('href_nfr_requirements'),
                         'href' => (string)$u('nfrRequirements'));
        $nfrMap = array(
            'href_nfr_performance' => array('nfrPerformance', 'fa fa-gauge-high'),
            'href_nfr_security' => array('nfrSecurity', 'fa fa-shield-halved'),
            'href_nfr_usability' => array('nfrUsability', 'fa fa-hand-pointer'),
            'href_nfr_accessibility' => array('nfrAccessibility', 'fa fa-universal-access'),
            'href_nfr_compatibility' => array('nfrCompatibility', 'fa fa-cubes'),
            'href_nfr_reliability' => array('nfrReliability', 'fa fa-life-ring'),
            'href_nfr_maintainability' => array('nfrMaintainability', 'fa fa-screwdriver-wrench'),
        );
        foreach ($nfrMap as $lblKey => $cfg2) {
            $h = $u($cfg2[0]);
            if ($h === null) { continue; }
            $items[] = array('label' => lang_get($lblKey), 'href' => (string)$h,
                             'icon' => $cfg2[1]);
        }
    }
    if (count($items) > 0) {
        $sections[] = array('key' => 'requirements_design',
                            'label' => lang_get('requirements_design'),
                            'icon' => 'fas fa-prescription-bottle', 'items' => $items);
    }
}

// 7. Test Case Design ------------------------------------------------------
if ($show('tests_design')) {
    $items = array();
    $tcLabel = ($gm('modify_tc') === 'yes') ? lang_get('href_edit_tc')
                                           : lang_get('href_browse_tc');
    $items[] = array('label' => $tcLabel, 'href' => (string)$u('testSpec'));
    if ($gm('testplan_metrics') === 'yes') {
        $items[] = array('label' => lang_get('href_print_tc'),
                         'href' => (string)$u('printTestSpec'));
    }
    if ($gm('view_tc') === 'yes') {
        $items[] = array('label' => lang_get('href_search_tc'),
                         'href' => (string)$u('tcSearch'));
    }
    if ($hasKeywords && $gm('keyword_assignment') === 'yes') {
        $items[] = array('label' => lang_get('href_keywords_assign'),
                         'href' => (string)$u('keywordsAssign'));
    }
    if ($gm('modify_tc') === 'yes') {
        $items[] = array('label' => lang_get('href_tc_import'),
                         'href' => (string)$u('tcImport'));
        $items[] = array('label' => lang_get('href_tc_create_from_issues'),
                         'href' => (string)$u('tcCreateFromIssues'));
        // Refs #1896: standalone Test Specification Import (tcCreateFromIssue)
        $items[] = array('label' => lang_get('href_tc_spec_import'),
                         'href' => (string)$u('tcCreateFromIssue'));
        // Refs #1948: Mantis Issue Import (tcCreateFromMantis) — the
        // full-featured twin of tcCreateFromIssues: in-screen suite picker +
        // duplicate criteria via the 1.9.20 saveImportedTCData engine.
        $items[] = array('label' => lang_get('href_tc_mantis_import'),
                         'href' => (string)$u('tcCreateFromMantis'));
        $items[] = array('label' => lang_get('link_report_test_cases_created_per_user'),
                         'href' => (string)$u('tcCreatedUser'));
    }
    if ($gm('view_tc') === 'yes') {
        $items[] = array('label' => lang_get('btn_report_test_automation'),
                         'href' => (string)$u('testAutomationSpec'));
        // Refs #1587: run test cases on a remote automation server (XML-RPC)
        $items[] = array('label' => lang_get('href_tc_auto_exec'),
                         'href' => (string)$u('tcAutoExec'));
        // Refs #1724: node name uniqueness diagnostic. Reached only with
        // view_tc, matching the BFF which answers 403 without it.
        $items[] = array('label' => lang_get('href_duplicate_name_check'),
                         'href' => (string)$u('nameCheck'));
        // Refs #1956: Test Specification Health report - the project-wide
        // answer to the question the Duplicate Name Check asks for a single
        // case (duplicates, empty summaries, unlinked requirements, cases
        // never executed and stale cases). Reached only with view_tc, the
        // right the BFF enforces (mgt_view_tc).
        $items[] = array('label' => lang_get('href_tc_spec_health'),
                         'href' => (string)$u('tcSpecHealth'));
    }
    if (count($items) > 0) {
        $sections[] = array('key' => 'tests_design', 'label' => lang_get('tests_design'),
                            'icon' => 'fas fa-drafting-compass', 'items' => $items);
    }
}

// 7b. Test Review Workflow -------------------------------------------------
// ISTQB static testing (Refs #1279): peer review of test cases & requirements.
// Net-new module with no legacy menu twin; shown when the user may view test
// cases or requirements. Label reuses the existing localized "Review" string
// (TLS_req_status_review) so no new server-side string key is required.
if ($gm('view_tc') === 'yes' || $gm('reqs_view') === 'yes' ||
    $gm('modify_tc') === 'yes') {
    $sections[] = array(
        'key' => 'reviews',
        'label' => lang_get('req_status_review'),
        'icon' => 'fas fa-clipboard-check',
        'single' => true,
        'items' => array(array(
            'id' => 'reviews',
            'label' => lang_get('req_status_review'),
            'href' => (string)$u('reviews'),
            'icon' => 'fas fa-clipboard-check',
        )),
    );
}

// 7c. Test Data Management - Test Data Sets --------------------------------
// Net-new module (Refs #1927, parent feature #1069): project-scoped, versioned
// test data sets with a data-type, PII classification + masking rule, a sample
// payload, notes and an optional link to a test case. No legacy menu twin.
// Label uses the new server string key title_test_data_sets, added to all 19
// server locales. Rights (view_tc / modify_tc) match the BFF api/testdata,
// which answers 403 without them.
if ($gm('view_tc') === 'yes' || $gm('modify_tc') === 'yes') {
    $sections[] = array(
        'key' => 'testData',
        'label' => lang_get('title_test_data_sets'),
        'icon' => 'fas fa-database',
        'single' => true,
        'items' => array(array(
            'id' => 'testDataSets',
            'label' => lang_get('title_test_data_sets'),
            'href' => (string)$u('testDataSets'),
            'icon' => 'fas fa-database',
        )),
    );
}

// 7c-bis. Test Environments registry ---------------------------------------
// Net-new module (Refs #1928, parent feature #1070): first-class, project-scoped
// environment definitions (dev / QA / staging / preprod / production-like) with
// availability status, browser-device-OS, base URL, owner, version / build,
// service dependencies and notes. No legacy menu twin. Label uses the new
// server string key title_test_environments, added to all 19 server locales.
// Rights (view_tc / modify_tc) match the BFF api/environments, which answers
// 403 without them.
if ($gm('view_tc') === 'yes' || $gm('modify_tc') === 'yes') {
    $sections[] = array(
        'key' => 'testEnvironments',
        'label' => lang_get('title_test_environments'),
        'icon' => 'fas fa-server',
        'single' => true,
        'items' => array(array(
            'id' => 'testEnvironments',
            'label' => lang_get('title_test_environments'),
            'href' => (string)$u('testEnvironments'),
            'icon' => 'fas fa-server',
        )),
    );
}

// 7c-ter. Risk-Based Testing registry --------------------------------------
// Net-new module (Refs #1930, parent feature #1066): project-scoped risks with
// impact / likelihood / severity / priority, a DERIVED risk level, status,
// owner, mitigation and optional links to a requirement and a test case. No
// legacy menu twin. Label uses the new server string key title_risk_register,
// added to all 19 server locales. Rights (view_tc / modify_tc) match the BFF
// api/risk, which answers 403 without them.
//
// 7c-quater. Risk Coverage Matrix (Refs #1951, parent feature #1066): the
// READ ONLY coverage report over the very same risks - coverage of the linked
// test case / requirement, residual risk after execution, the high risk / low
// coverage worklist and the release verdict. Second entry of the same ASIDE
// section, label uses the new server string key title_risk_coverage_matrix
// (all 19 server locales); rights match the BFF api/riskcoveragematrix.
if ($gm('view_tc') === 'yes' || $gm('modify_tc') === 'yes') {
    $sections[] = array(
        'key' => 'riskRegister',
        'label' => lang_get('title_risk_register'),
        'icon' => 'fas fa-exclamation-triangle',
        'items' => array(
            array(
                'id' => 'riskRegister',
                'label' => lang_get('title_risk_register'),
                'href' => (string)$u('riskRegister'),
                'icon' => 'fas fa-exclamation-triangle',
            ),
            array(
                'id' => 'riskCoverageMatrix',
                'label' => lang_get('title_risk_coverage_matrix'),
                'href' => (string)$u('riskCoverageMatrix'),
                'icon' => 'fas fa-table-cells',
            ),
        ),
    );
}

// 7c-quater. Exploratory Testing Sessions registry ---------------------------
// Net-new module (Refs #1934, parent feature #1071): project-scoped
// exploratory testing sessions with a test charter, status, time box, tester,
// session date, environment, purpose, notes and defect references. No legacy
// menu twin. Label uses the new server string key
// title_exploratory_sessions, added to all 19 server locales. Rights
// (view_tc / modify_tc) match the BFF api/exploratory, which answers 403
// without them.
if ($gm('view_tc') === 'yes' || $gm('modify_tc') === 'yes') {
    $sections[] = array(
        'key' => 'exploratory',
        'label' => lang_get('title_exploratory_sessions'),
        'icon' => 'fas fa-compass',
        'single' => true,
        'items' => array(array(
            'id' => 'exploratorySessions',
            'label' => lang_get('title_exploratory_sessions'),
            'href' => (string)$u('exploratorySessions'),
            'icon' => 'fas fa-compass',
        )),
    );
}

// 7d. Test Closure ---------------------------------------------------------
// ISTQB closure phase (Refs #1278): lessons learned + closure checklist +
// printable closure report + the closure state that freezes plan results.
// Net-new module with no legacy menu twin; plan-scoped, so it needs an active
// test plan. Rights are enforced server-side by api/testclosure.
if ($tplan_id > 0 && $u('testClosure') !== null &&
    ($gm('testplan_planning') === 'yes' || $gm('exec_testcases') === 'yes' ||
     $gm('exec_ro_access') === 'yes')) {
    $sections[] = array(
        'key' => 'testClosure',
        'label' => lang_get('title_test_closure'),
        'icon' => 'fas fa-clipboard-check',
        'single' => true,
        'items' => array(array(
            'id' => 'testClosure',
            'label' => lang_get('title_test_closure'),
            'href' => (string)$u('testClosure'),
            'icon' => 'fas fa-clipboard-check',
        )),
    );
}

// 8. Test Plan -------------------------------------------------------------
if ($show('plans')) {
    $items = array();
    if ($gm('mgt_testplan_create') === 'yes') {
        $items[] = array('label' => lang_get('href_plan_management'),
                         'href' => (string)$u('planView'));
        if ($countPlans > 0) {
            $items[] = array('label' => lang_get('href_assign_tplan_roles'),
                             'href' => (string)$u('usersAssignPlan'));
        }
    }
    if ($u('buildView') !== null && $gm('testplan_create_build') === 'yes'
        && $countPlans > 0) {
        $items[] = array('label' => lang_get('href_build_new'),
                         'href' => (string)$u('buildView'));
    }
    if ($u('planAddTC') !== null) {
        $items[] = array('label' => lang_get('href_add_remove_test_cases'),
                         'href' => (string)$u('planAddTC'));
    }
    if ($u('platformAssign') !== null && $gm('testplan_add_remove_platforms') === 'yes'
        && $countPlans > 0) {
        $items[] = array('label' => lang_get('href_platform_assign'),
                         'href' => (string)$u('platformAssign'));
    }
    if ($u('setTestUrgency') !== null && $gm('testplan_set_urgent_testcases') === 'yes') {
        $items[] = array('label' => lang_get('href_plan_assign_urgency'),
                         'href' => (string)$u('setTestUrgency'));
    }
    if ($u('planUpdateTC') !== null && $gm('testplan_update_linked_testcase_versions') === 'yes') {
        $items[] = array('label' => lang_get('href_update_tplan'),
                         'href' => (string)$u('planUpdateTC'));
    }
    if ($u('showNewestTCV') !== null && $gm('testplan_show_testcases_newest_versions') === 'yes') {
        $items[] = array('label' => lang_get('href_newest_tcversions'),
                         'href' => (string)$u('showNewestTCV'));
    }
    if (count($items) > 0) {
        $sections[] = array('key' => 'plans', 'label' => lang_get('title_test_plan_mgmt'),
                            'icon' => 'fas fa-swatchbook', 'items' => $items);
    }
}

// 9. Test Case Execution ---------------------------------------------------
if ($show('execution')) {
    $items = array();
    if ($u('execDashboard') !== null
        && ($gm('testplan_execute') === 'yes' || $gm('exec_ro_access') === 'yes')) {
        $items[] = array('id' => 'execDashboard',
                         'label' => lang_get('href_exec_dashboard'),
                         'href' => (string)$u('execDashboard'),
                         'icon' => 'fas fa-tachometer-alt');
    }
    // Execution Notes picker (Refs #1809): the ASIDE-reachable entry point of
    // the read-only notes viewer, which without an exec_id could only dead-end.
    // Same read grant the BFF enforces (exec_ro_access OR exec_edit_notes OR
    // testplan_execute), so the menu never offers a link the endpoint refuses.
    if ($u('execNotesPicker') !== null
        && ($gm('testplan_execute') === 'yes' || $gm('exec_ro_access') === 'yes'
            || $gm('exec_edit_notes') === 'yes')) {
        $items[] = array('id' => 'execNotesPicker',
                         'label' => lang_get('title_execution_notes'),
                         'href' => (string)$u('execNotesPicker'),
                         'icon' => 'fas fa-sticky-note');
    }
    if ($u('executeTest') !== null) {
        $lblEx = ($gm('exec_ro_access') === 'yes')
            ? lang_get('href_exec_ro_access') : lang_get('href_execute_test');
        $items[] = array('label' => $lblEx, 'href' => (string)$u('executeTest'));
    }
    if ($u('assignTCVExecution') !== null) {
        $items[] = array('label' => lang_get('href_tc_exec_assignment'),
                         'href' => (string)$u('assignTCVExecution'));
    }
    if ($u('testcase_assignments') !== null && $gm('exec_testcases_assigned_to_me') === 'yes') {
        $items[] = array('label' => lang_get('href_my_testcase_assignments'),
                         'href' => (string)$u('testcase_assignments'));
    }
    if ($u('milestonesView') !== null && $gm('testplan_milestone_overview') === 'yes'
        && $countPlans > 0) {
        $items[] = array('label' => lang_get('href_plan_mstones'),
                         'href' => (string)$u('milestonesView'));
    }
    if (count($items) > 0) {
        $sections[] = array('key' => 'execution', 'label' => lang_get('testcase_execution'),
                            'icon' => 'fas fa-gamepad', 'items' => $items);
    }
}

// 10. Reports --------------------------------------------------------------
if ($show('reports') && count($reportsMenu) > 0) {
    $sections[] = array('key' => 'reports', 'label' => lang_get('reports'),
                        'icon' => 'fas fa-chart-line', 'items' => $reportsMenu);
}

// 11. Plugins --------------------------------------------------------------
if (count($pluginsMenu) > 0 || $gm('plugin_management') === 'yes') {
    $items = array();
    if ($gm('plugin_management') === 'yes') {
        $items[] = array('id' => 'pluginView', 'label' => lang_get('installed_plugins'),
                         'href' => (string)$u('pluginView'));
    }
    foreach ($pluginsMenu as $pEntry) {
        $items[] = $pEntry;
    }
    $sections[] = array('key' => 'plugins', 'label' => lang_get('title_plugins'),
                        'icon' => 'fas fa-puzzle-piece', 'items' => $items);
}

// 12. Documentation --------------------------------------------------------
// Refs #1060/#1059/#1053/#1055/#1937/#1944: the section now carries the modernized hubs
// next to the Documentation hub. The hrefs resolve through $actions->learnTesting / $actions->accessibilityResources / $actions->istqbSyllabus / $actions->accessibilityAudit / $actions->apiReference / $actions->openApiSpec
// (lib/functions/common.php), so they stay in-context like every other item.
$learnHref = $u('learnTesting', '/gui/templates/documentation/learnTesting.html');
$a11yHref = $u('accessibilityResources', '/gui/templates/documentation/accessibilityResources.html');
$istqbHref = $u('istqbSyllabus', '/gui/templates/documentation/istqbSyllabus.html');
$auditHref = $u('accessibilityAudit', '/gui/templates/documentation/accessibilityAudit.html');
$apiRefHref = $u('apiReference', '/gui/templates/documentation/apiReference.html');
$oasHref = $u('openApiSpec', '/gui/templates/documentation/openApiSpec.html');
$cicdHref = $u('cicdHub', '/gui/templates/documentation/cicdHub.html');
$sections[] = array(
    'key' => 'documentation',
    'label' => lang_get('title_documentation'),
    'icon' => 'fas fa-book',
    'single' => false,
    'items' => array(
        array(
            'id' => 'documentationHub',
            'label' => lang_get('title_documentation_hub'),
            'href' => 'gui/templates/documentation/documentation.html',
            'icon' => 'fas fa-book',
        ),
        array(
            'id' => 'learnTesting',
            'label' => lang_get('title_learn_testing'),
            'href' => (string)$learnHref,
            'icon' => 'fas fa-graduation-cap',
        ),
        array(
            'id' => 'accessibilityResources',
            'label' => lang_get('title_accessibility_resources'),
            'href' => (string)$a11yHref,
            'icon' => 'fas fa-universal-access',
        ),
        array(
            'id' => 'accessibilityAudit',
            'label' => lang_get('title_accessibility_audit'),
            'href' => (string)$auditHref,
            'icon' => 'fas fa-check-circle',
        ),
        array(
            'id' => 'istqbSyllabus',
            'label' => lang_get('title_istqb_syllabus'),
            'href' => (string)$istqbHref,
            'icon' => 'fas fa-certificate',
        ),
        array(
            'id' => 'apiReference',
            'label' => lang_get('title_api_reference'),
            'href' => (string)$apiRefHref,
            'icon' => 'fas fa-plug',
        ),
        array(
            'id' => 'openApiSpec',
            'label' => lang_get('title_openapi_spec'),
            'href' => (string)$oasHref,
            'icon' => 'fas fa-file-code',
        ),
        array(
            'id' => 'cicdHub',
            'label' => lang_get('title_cicd_hub'),
            'href' => (string)$cicdHref,
            'icon' => 'fas fa-code-branch',
        ),
    ),
);
asideOut(array(
    'status' => 'ok',
    'tproject_id' => $tproject_id,
    'tplan_id' => $tplan_id,
    'railed' => menuRailIsOn(),
    'rail_cookie' => menuRailCookieName(),
    'sections' => $sections,
));