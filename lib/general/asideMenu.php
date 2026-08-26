<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * @filesource  asideMenu.php
 *
 * Renders the left side menu for its own frame in main.tpl.
 *
 * The menu used to be drawn by each content page, so it disappeared on every
 * page that did not include it - all of the two pane work areas among them.
 * Serving it here keeps it on screen whatever the main frame shows.
 *
**/
require_once('../../config.inc.php');
require_once("common.php");
testlinkInitPage($db,('initProject' == 'initProject'));

// The menu needs showMenu, activeMenu, uri, logo, whoami, access and
// menuGrants. TLSmarty::addMenuContext() fills all of them from initUserEnv()
// for any page that has not built them itself, which is the case here, so an
// empty object is all that has to be handed over.
$gui = new stdClass();

// aside.tpl checks $gui->hasKeywords to show/hide keyword assignment.
// The old single-frame layout computed this in mainPage.php; since the
// sidebar now has its own frame we need it here too.
$tprojectID = isset($_SESSION['testprojectID']) ? intval($_SESSION['testprojectID']) : 0;
$gui->hasKeywords = false;
if($tprojectID > 0) {
  $tproject_mgr = new testproject($db);
  $gui->hasKeywords = $tproject_mgr->hasKeywords($tprojectID);
}

// Reports sub-menu (Reports center modernization, step 1) - Refs #607.
// Mirrors the gate logic of tlReports::get_list_reports() as used by
// lib/results/resultsNavigator.php: enabled=all|req|bts + format_html filter,
// rendered under the ASIDE "Reports" entry instead of a lone metrics link.
// Entries keep their legacy generator URL; screens that get modernized
// counterparts switch their href here (test_plan => testPlanReport, #608).
$gui->reportsMenu = array();
$tplanID = isset($_SESSION['testplanID']) ? intval($_SESSION['testplanID']) : 0;
if($tplanID > 0) {
  // reports_list lives in its own cfg file that legacy report pages
  // require explicitly - it is NOT part of the default config load.
  require_once('../../cfg/reports.cfg.php');
  $reportsCfg = config_get('reports_list');
  if(!is_null($reportsCfg) && (is_array($reportsCfg) || is_object($reportsCfg))) {
    $baseHrefR = isset($_SESSION['basehref']) ? $_SESSION['basehref'] : '';
    $tprojOptsR = $tproject_mgr->getOptions($tprojectID);
    $reqEnabledR = (!empty($tprojOptsR)
      && !empty($tprojOptsR->requirementsEnabled));

    $btsEnabledR = $tproject_mgr->isIssueTrackerEnabled($tprojectID);

    foreach($reportsCfg as $rptItem) {
      $okR = ($rptItem['enabled'] == 'all')
        || (($rptItem['enabled'] == 'req') && $reqEnabledR)
        || (($rptItem['enabled'] == 'bts') && $btsEnabledR);
      if(!$okR) {
        continue;
      }
      if(strpos(',' . $rptItem['format'], 'format_html') === false) {
        continue;
      }
      // Refs #608/#607 - the three plan-document report types share one
      // modernized navigator screen, switched by the type query argument.
      $modernTypes = array(
          'link_report_test_plan'           => 'testplan',
          'link_report_test_report'         => 'testreport',
          'link_report_test_report_on_build'=> 'testreport_onbuild',
      );
      if(isset($modernTypes[$rptItem['title']])) {
        $hrefR = 'gui/templates/results/testPlanReport.html' .
                 "?tproject_id={$tprojectID}&tplan_id={$tplanID}" .
                 '&type=' . $modernTypes[$rptItem['title']];
      // Refs #618 - General Test Plan Metrics (resultsGeneral) modernized;
      // the BFF (api/reports metrics_general action) reuses the very same
      // tlTestPlanMetrics render methods and enforces testplan_metrics.
      } else if($rptItem['title'] == 'link_report_general_tp_metrics') {
        $hrefR = 'gui/templates/results/generalMetrics.html' .
                 "?tproject_id={$tprojectID}&tplan_id={$tplanID}";
      // Refs #671 - Results by Test Suite (resultsByTSuite) modernized;
      // the BFF (api/reports metrics_by_tsuite action) reuses the very same
      // tlTestPlanMetrics::getStatusTotalsTSuiteDepth2ForRender() call and
      // enforces testplan_metrics.
      } else if($rptItem['title'] == 'link_report_by_tsuite') {
        $hrefR = 'gui/templates/results/resultsByTSuite.html' .
                 "?tproject_id={$tprojectID}&tplan_id={$tplanID}";
      // Refs #673 - Baselines L1 & L2 (baselinel1l2) modernized;
      // the BFF (api/reports metrics_baseline_l1l2 action) reads the very
      // same baseline_l1l2_context/details rows as the legacy controller
      // and enforces testplan_metrics.
      } else if($rptItem['title'] == 'baseline_l1l2') {
        $hrefR = 'gui/templates/results/baselineL1L2.html' .
                 "?tproject_id={$tprojectID}&tplan_id={$tplanID}";
      // Refs #677 - Results by Tester per Build (resultsByTesterPerBuild)
      // modernized; the BFF (api/reports metrics_by_tester_per_build action)
      // reuses the very same
      // tlTestPlanMetrics::getStatusTotalsByBuildUAForRender() call and
      // enforces testplan_metrics.
      } else if($rptItem['title'] == 'link_report_by_tester_per_build') {
        $hrefR = 'gui/templates/results/resultsByTesterPerBuild.html' .
                 "?tproject_id={$tprojectID}&tplan_id={$tplanID}";
      // Refs #681 - Test Results Matrix (resultsTC) modernized;
      // the BFF (api/reports metrics_results_matrix action) reuses the very
      // same tlTestPlanMetrics::getExecStatusMatrix() call and enforces
      // testplan_metrics; XLS/email export stays on the legacy controller.
      } else if($rptItem['title'] == 'link_report_test') {
        $hrefR = 'gui/templates/results/resultsMatrix.html' .
                 "?tproject_id={$tprojectID}&tplan_id={$tplanID}";
      // Refs #684 - Assigned Test Case Overview (tcAssignedToUser) modernized;
      // the BFF (api/reports assigned_tc_overview action) reuses
      // testcase::get_assigned_to_user() with last-exec status per row;
      // show_all_users=1+show_inactive_and_closed=1 is the legacy reports
      // variant that shows the "overview for all users" perspective.
      } else if($rptItem['title'] == 'link_assigned_tc_overview') {
        $hrefR = 'gui/templates/results/assignedTcOverview.html' .
                 "?tproject_id={$tprojectID}" .
                 "&show_all_users=1&show_inactive_and_closed=1";
      // Refs #685 - Test Results Flat (resultsTCFlat) modernized;
      // the BFF (api/reports results_flat action) reuses the very same
      // tlTestPlanMetrics::getExecStatusMatrixFlat() call and enforces
      // testplan_metrics; XLS export stays on the legacy controller.
      } else if($rptItem['title'] == 'link_report_test_flat') {
        $hrefR = 'gui/templates/results/resultsTCFlat.html' .
                 "?tproject_id={$tprojectID}&tplan_id={$tplanID}";
      // Refs #686 - Absolute Latest Execution Results (absoluteLatest)
      // modernized; the BFF (api/reports absolute_latest_init/result actions)
      // reuses the very same tlTestPlanMetrics::getLatestExecOnSinglePlatformMatrix()
      // and getNeverRunOnSinglePlatform() calls and enforces testplan_metrics.
      } else if($rptItem['title'] == 'link_report_test_absolute_latest_exec') {
        $hrefR = 'gui/templates/results/absoluteLatest.html' .
                 "?tproject_id={$tprojectID}&tplan_id={$tplanID}";
      // Refs #695 - Results by Status (Failed/Blocked/Not Run) modernized;
      // the BFF (api/reports by_status action) reuses the very same
      // tlTestPlanMetrics::getExecutionsByStatus() / getNotRunWithTesterAssigned()
      // calls and enforces testplan_metrics; XLS/email stays on the legacy
      // controller (lib/results/resultsByStatus.php).
      } else if($rptItem['title'] == 'list_tc_failed') {
        $hrefR = 'gui/templates/results/resultsByStatus.html' .
                 "?tproject_id={$tprojectID}&tplan_id={$tplanID}&status=failed";
      } else if($rptItem['title'] == 'list_tc_blocked') {
        $hrefR = 'gui/templates/results/resultsByStatus.html' .
                 "?tproject_id={$tprojectID}&tplan_id={$tplanID}&status=blocked";
      } else if($rptItem['title'] == 'list_tc_not_run') {
        $hrefR = 'gui/templates/results/resultsByStatus.html' .
                 "?tproject_id={$tprojectID}&tplan_id={$tplanID}&status=not_run";
      // Refs #737 - Test Cases with Custom Fields modernized;
      // the BFF (api/reports tcases_with_cf action) reuses the very same
      // cfield_mgr::get_linked_cfields_at_execution() calls and enforces
      // testplan_metrics.
      } else if($rptItem['title'] == 'link_report_tcases_with_cf') {
        $hrefR = 'gui/templates/results/tcasesWithCF.html' .
                 "?tproject_id={$tprojectID}&tplan_id={$tplanID}";
      // Refs #688 - Test Cases Never Run (neverRunByPP) modernized;
      // the BFF (api/reports never_run_init/never_run_result actions) reuses
      // the very same tlTestPlanMetrics::getNeverRunByPlatform() call and
      // enforces testplan_metrics.
      } else if($rptItem['title'] == 'link_report_never_run') {
        $hrefR = 'gui/templates/results/neverRun.html' .
                 "?tproject_id={$tprojectID}&tplan_id={$tplanID}";
      // Refs #689 - Test Cases Without Tester (casesWithoutTester) modernized;
      // the BFF (api/reports cases_without_tester action) reuses the very same
      // tlTestPlanMetrics::getNotRunWOTesterAssigned() call and enforces
      // testplan_metrics.
      } else if($rptItem['title'] == 'link_report_tcases_without_tester') {
        $hrefR = 'gui/templates/results/casesWithoutTester.html' .
                 "?tproject_id={$tprojectID}&tplan_id={$tplanID}";
      } else {
        $hrefR = $baseHrefR . $rptItem['url'];
      }
      $gui->reportsMenu[] = array(
        'key' => $rptItem['title'],
        'name' => lang_get($rptItem['title']),
        'href' => $hrefR,
      );
    }
  }
}

// Plugins can inject links via these four events (aside.tpl groups them all
// into one "Plugins" section) - mainPage.php used to compute this for the old
// single-frame layout, but that code went dead once the menu got its own
// frame (issue #437) since mainPage.tpl no longer includes aside.tpl.
// Recompute it here instead. tl-classic spreads LEFTMENU_*/RIGHTMENU_* across
// two separate page columns that dashio's single-rail sidebar has no
// equivalent of, so they're merged into one list rather than dropping half
// of them like the previous version of this file did.
$gui->plugins = array();
foreach(array('EVENT_LEFTMENU_TOP','EVENT_LEFTMENU_BOTTOM',
              'EVENT_RIGHTMENU_TOP','EVENT_RIGHTMENU_BOTTOM') as $menu_item) {
  $menu_content = event_signal($menu_item);
  if (!empty($menu_content)) {
    $gui->plugins[$menu_item] = $menu_content;
  }
}

$smarty = new TLSmarty();
$smarty->assign('gui',$gui);

// Render collapsed from the start when it was left that way, instead of
// painting the menu at full width and then snapping it shut.
$smarty->assign('railed',menuRailIsOn());
$smarty->assign('menuRailCookie',menuRailCookieName());

$smarty->display('asideFrame.tpl');
