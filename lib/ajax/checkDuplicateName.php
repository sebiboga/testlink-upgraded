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
 * ({success, message, code}) for bookmarked/external callers, but resolves the
 * OWNING test project of the test case and checks the right THERE. The real
 * check - and the modern screen - live in
 * gui/templates/testcases/nameCheck.html + api/namecheck/index.php.
 *
 * NOTE: after the 2.0.1 modernization this endpoint is no longer called from
 * anywhere inside the application (the test specification form posts to
 * api/testcases/index.php?action=check_name). It is kept for external/bookmark
 * callers only. G_PARAMS() reads $_GET, so this is a GET-only JSON read; the
 * same-origin policy of the browser already blocks a cross-origin read, and no
 * same-origin guard is needed for a read with no side effect.
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
 * Walk nodes_hierarchy upwards until the test project node. The "is a test
 * project" id is read from the node_types table, never hardcoded.
 * Returns the owning test project id, or null when the chain does not reach one.
 */
function checkTcaseOwningProject(&$dbHandler, $nodeId, $testprojectTypeId)
{
	$nhTables = tlObjectWithDB::getDBTables('nodes_hierarchy');
	$nh = $nhTables['nodes_hierarchy'];
	$walk = intval($nodeId);
	$projectTypeId = intval($testprojectTypeId);
	$guard = 64;
	while ($walk > 0 && $guard-- > 0) {
		// Test the node's OWN type first: a test SUITE is checked against the
		// test project root as its parent, and that root IS the chain end -
		// walking past it reads parent_id = 0 and wrongly reports the project
		// as "not found", which broke every test SUITE name check (bug #1725).
		// fetchFirstRow (NOT fetchFirstRowSingleColumn): two columns are needed.
		$row = $dbHandler->fetchFirstRow(
			"SELECT parent_id, node_type_id FROM $nh WHERE id = " . intval($walk));
		if (empty($row) || !array_key_exists('parent_id', $row)) { return null; }
		if (intval($row['node_type_id']) === $projectTypeId) { return $walk; }
		$parent = intval($row['parent_id']);
		if ($parent == 0) { return null; }
		$walk = $parent;
	}
	return null;
}

$owningTproject = ($tc_id > 0)
	? checkTcaseOwningProject($db, $tc_id, $node_types_descr_id['testproject'] ?? 0)
	: null;
if (is_null($owningTproject) || $owningTproject <= 0) {
	// testcase_id 0 means "a NEW, unsaved test case": there is no node and
	// therefore no owning project to check a right against, so this endpoint
	// cannot answer. The create form is served by api/testcases/index.php
	// (?action=check_name), which knows the suite being written into.
	$data['success'] = false;
	$data['code'] = 'parent_not_found';
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
	$data['code'] = 'no_permission';
	$data['message'] = lang_get('no_permissions_for_action');
	echo json_encode($data);
	exit;
}

$check = $tree_manager->nodeNameExists($name, $my_node_type, $tc_id > 0 ? $tc_id : null);

$data['success'] = !$check['status'];
$data['message'] = $check['msg'];

echo json_encode($data);
