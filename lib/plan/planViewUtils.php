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
  if ($guiObj->getTestPlans) {
    $guiObj->tplans = $argsObj->user->getAccessibleTestPlans(
      $dbHandler, $argsObj->tproject_id, null,
      array('output' => 'mapfull', 'active' => null)
    );
  }
}
