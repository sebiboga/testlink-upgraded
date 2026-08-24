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
    $tprojOptsR = isset($_SESSION['testprojectOptions'])
      ? $_SESSION['testprojectOptions'] : null;
    $reqEnabledR = (!is_null($tprojOptsR)
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
