<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * Plan View Utilities
 *
 * @package   TestLink
 * @copyright 2007-2023, TestLink community
 * @filesource planViewUtils.php
 *
 */

/**
 * Initialize GUI object for test plan view
 *
 * @param resource $dbHandler database connection
 * @param stdClass $argsObj arguments object
 * @param stdClass $guiObj GUI object to populate
 * @param testplan $tplanMgr test plan manager
 */
function planViewGUIInit(&$dbHandler, &$argsObj, &$guiObj, &$tplanMgr)
{
  if (!isset($guiObj->drawPlatformQtyColumn)) {
    $guiObj->drawPlatformQtyColumn = false;
  }

  if ($guiObj->getTestPlans) {
    $guiObj->tplans = $argsObj->user->getAccessibleTestPlans(
      $dbHandler, $argsObj->tproject_id, null,
      array('output' => 'mapfull', 'active' => null)
    );
  }

  // planView.tpl reads tcase_qty/build_qty/platform_qty/rights per row.
  // planEdit.php discards its enriched $gui (re-initialized at planEdit.php:282),
  // so the qty aggregates and the per-plan rights must be computed here,
  // otherwise every rendered row emits E_WARNINGs (see issue #622).
  if (!is_null($guiObj->tplans) && count($guiObj->tplans) > 0) {
    $tplanSet = array_keys($guiObj->tplans);

    $tplanMgr->platform_mgr->setTestProjectID($argsObj->tproject_id);
    $dummy = $tplanMgr->platform_mgr->testProjectCount();
    $guiObj->drawPlatformQtyColumn =
      isset($dummy[$argsObj->tproject_id]['platform_qty']) &&
      $dummy[$argsObj->tproject_id]['platform_qty'] > 0;

    $dummy = $tplanMgr->count_testcases($tplanSet, null, array('output' => 'groupByTestPlan'));
    $buildQty = $tplanMgr->get_builds($tplanSet, null, null, array('getCount' => true));

    foreach ($tplanSet as $idk) {
      $guiObj->tplans[$idk]['tcase_qty'] = isset($dummy[$idk]['qty']) ? intval($dummy[$idk]['qty']) : 0;
      $guiObj->tplans[$idk]['build_qty'] = isset($buildQty[$idk]['build_qty']) ? intval($buildQty[$idk]['build_qty']) : 0;

      if ($guiObj->drawPlatformQtyColumn) {
        $plat = $tplanMgr->getPlatforms($idk);
        $guiObj->tplans[$idk]['platform_qty'] = is_null($plat) ? 0 : count($plat);
      }

      // Get rights for each test plan (mirrors lib/plan/planView.php)
      $rightSet = array('testplan_user_role_assignment');
      foreach ($rightSet as $target) {
        $roleObj = null;
        if (isset($guiObj->tplans[$idk]['has_role']) && $guiObj->tplans[$idk]['has_role'] > 0) {
          $theRole = $guiObj->tplans[$idk]['has_role'];
          if (isset($argsObj->user->tplanRoles[$theRole])) {
            $roleObj = $argsObj->user->tplanRoles[$theRole];
          } else if (!is_null($argsObj->user->tprojectRoles) &&
                     isset($argsObj->user->tprojectRoles[$argsObj->tproject_id])) {
            $roleObj = $argsObj->user->tprojectRoles[$argsObj->tproject_id];
          }
        } else if (!is_null($argsObj->user->tprojectRoles) &&
                   isset($argsObj->user->tprojectRoles[$argsObj->tproject_id])) {
          $roleObj = $argsObj->user->tprojectRoles[$argsObj->tproject_id];
        }

        if (is_null($roleObj)) {
          $roleObj = $argsObj->user->globalRole;
        }
        $guiObj->tplans[$idk]['rights'][$target] = $roleObj->hasRight($target);
      }
    }
  }
}
