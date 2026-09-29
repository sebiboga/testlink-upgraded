<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * LEGACY test-case name-uniqueness backend - SUPERSEDED, Refs #1724.
 *
 * In 1.9.20 this endpoint gated the check on the GLOBAL mgt_view_tc right
 * (has_rights($db, 'mgt_view_tc') with no project context), so a user holding
 * that right on one test project could enumerate test case names inside EVERY
 * test project. It also had no same-origin proof, so it was rideable from any
 * site using a logged-in session, and its error branch called
 * lang_get('Invalid right') - a key that does not exist in any locale bundle,
 * so the "permission denied" answer rendered as the literal English text
 * "Invalid right".
 *
 * It is now a session-guarded shim that keeps the legacy JSON contract
 * ({success, message}) for the surviving 1.9.20 callers, but resolves the
 * OWNING test project of the test case and checks the right THERE. The real
 * check - and the modern screen - live in
 * gui/templates/testcases/nameCheck.html + api/namecheck/index.php.
 *
 * @package 	TestLink
 * @filesource  checkDuplicateName.php
 */

require_once('../../config.inc.php');
require_once('common.php');
testlinkInitPage($db);
$data = array('success' => true, 'message' => '');

$iParams = array(
	"name" => array(tlInputParameter::STRING_N, 0, 100),
	"testcase_id" => array(tlInputParameter::INT),
);
$args = G_PARAMS($iParams);

$tree_manager = new tree($db);
$node_types_descr_id = $tree_manager->get_available_node_types();
$my_node_type = $node_types_descr_id['testcase'];
$name = $args['name'];
$tc_id = $args['testcase_id'];

/**
 * Walk nodes_hierarchy upwards until the test project node (node_type_id 1).
 * Returns the owning test project id, or null when the chain does not reach one.
 */
function checkTcaseOwningProject(&$dbHandler, $nodeId)
{
	$walk = intval($nodeId);
	$guard = 64;
	while ($walk > 0 && $guard-- > 0) {
		$parent = intval($dbHandler->fetchFirstRowSingleColumn(
			"SELECT parent_id FROM nodes_hierarchy WHERE id = " . intval($walk),
			'parent_id'));
		if ($parent == 0) { return null; }
		$nt = intval($dbHandler->fetchFirstRowSingleColumn(
			"SELECT node_type_id FROM nodes_hierarchy WHERE id = " . $parent,
			'node_type_id'));
		if ($nt === 1) { return $parent; }
		$walk = $parent;
	}
	return null;
}

$owningTproject = checkTcaseOwningProject($db, $tc_id);
if (is_null($owningTproject) || $owningTproject <= 0) {
	$data['success'] = false;
	$data['message'] = lang_get('no_testcases_available_or_tsuite');
	echo json_encode($data);
	exit;
}

// The right is checked on the OWNING project of the test case, not globally.
// This is the hole the legacy endpoint had.
$tlUser = tlUser::getByID($db, $_SESSION['userID']);
$canView = !is_null($tlUser) && $tlUser->hasRight($db, 'mgt_view_tc', $owningTproject) === 'yes';
$canModify = !is_null($tlUser) && $tlUser->hasRight($db, 'mgt_modify_tc', $owningTproject) === 'yes';
if (!$canView && !$canModify) {
	tLog('Invalid right for the user for project ' . intval($owningTproject), 'ERROR');
	$data['success'] = false;
	$data['message'] = lang_get('no_permissions_for_action');
	echo json_encode($data);
	exit;
}

$check = $tree_manager->nodeNameExists($name, $my_node_type, $tc_id > 0 ? $tc_id : null);

$data['success'] = !$check['status'];
$data['message'] = $check['msg'];

echo json_encode($data);
