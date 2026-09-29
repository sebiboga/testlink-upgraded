<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * LEGACY name-uniqueness backend for a GENERIC node - SUPERSEDED, Refs #1724.
 *
 * In 1.9.20 this endpoint answered the test suite / test case forms and, as its
 * own header comment admitted, performed "no check on user rights" and had no
 * project scope at all: ANY authenticated session could POST a parent_id
 * belonging to ANY test project and learn whether a node name was taken there.
 *
 * It is now a session-guarded, rights-checked shim that keeps the legacy JSON
 * contract ({success, message, code}) so any bookmarked or external caller keeps
 * working, while the real check - and the modern screen - live in
 * gui/templates/testcases/nameCheck.html + api/namecheck/index.php, which
 * proves the parent node belongs to the resolved test project BEFORE answering
 * and requires mgt_view_tc OR mgt_modify_tc on that project.
 *
 * NOTE: after the 2.0.1 modernization this endpoint is no longer called from
 * anywhere inside the application (the test specification form posts to
 * api/testcases/index.php?action=check_name and the new screen posts to
 * api/namecheck/index.php). It is kept for external/bookmark callers only.
 *
 * @package 	TestLink
 * @filesource  checkNodeDuplicateName.php
 */

require_once('../../config.inc.php');
require_once('common.php');
testlinkInitPage($db);

$data = array('success' => true, 'message' => '');

$iParams = array("node_name" => array(tlInputParameter::STRING_N,0,100),
	             "node_id" => array(tlInputParameter::INT),
	             "parent_id" => array(tlInputParameter::INT),
	             "node_type" => array(tlInputParameter::STRING_N,0,20));
$args = G_PARAMS($iParams);

$tree_manager = new tree($db);
$node_types_descr_id = $tree_manager->get_available_node_types();

// To allow name check when creating a NEW NODE => we do not have node id
$args['node_id'] = ($args['node_id'] > 0) ? $args['node_id'] : null;
$args['parent_id'] = ($args['parent_id'] > 0) ? $args['parent_id'] : null;

if (is_null($args['node_id']) && is_null($args['parent_id'])) {
	$data['success'] = false;
	$data['message'] = lang_get('sorry_further');
	echo json_encode($data);
	exit;
}

if (!isset($node_types_descr_id[$args['node_type']])) {
	$data['success'] = false;
	$data['message'] = lang_get('sorry_further');
	echo json_encode($data);
	exit;
}

/**
 * Walk nodes_hierarchy upwards until the test project node. The "is a test
 * project" id is read from the node_types table, never hardcoded.
 * Returns the owning test project id, or null when the chain does not reach one.
 */
function checkNodeOwningProject(&$dbHandler, $nodeId, $testprojectTypeId)
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

$parentId = is_null($args['parent_id']) ? 0 : intval($args['parent_id']);
if ($parentId <= 0 && !is_null($args['node_id'])) {
	$parentId = intval($db->fetchFirstRowSingleColumn(
		"SELECT parent_id FROM nodes_hierarchy WHERE id = " . intval($args['node_id']),
		'parent_id'));
}

$nhTables = tlObjectWithDB::getDBTables('nodes_hierarchy');
$nh = $nhTables['nodes_hierarchy'];
$owningTproject = ($parentId > 0)
	? checkNodeOwningProject($db, $parentId, $node_types_descr_id['testproject'] ?? 0)
	: null;
if (is_null($owningTproject) || $owningTproject <= 0) {
	// A stale/unknown parent_id is NOT a rights problem and NOT an empty
	// container: 1.9.20 answered success:true here. The 'code' key lets a
	// caller tell the three failures apart while the legacy (success,message)
	// pair is preserved for callers that only read those.
	$data['success'] = false;
	$data['code'] = 'parent_not_found';
	$data['message'] = lang_get('no_testcases_available_or_tsuite');
	echo json_encode($data);
	exit;
}

// The right is checked on the OWNING project of the parent, never on a project
// supplied by the caller. This is the hole the legacy endpoint had.
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

// The self-exclusion (edit mode) is only honoured when node_id really is a
// child of the addressed parent, so an id borrowed from another project cannot
// silently suppress a genuine collision.
if (!is_null($args['node_id']) && $args['node_id'] > 0) {
	$nodeParent = $db->fetchFirstRowSingleColumn(
		"SELECT parent_id FROM $nh WHERE id = " . intval($args['node_id']),
		'parent_id');
	if (is_null($nodeParent) || intval($nodeParent) !== $parentId) {
		$args['node_id'] = null;
	}
}

$check = $tree_manager->nodeNameExists($args['node_name'],
	$node_types_descr_id[$args['node_type']],
	$args['node_id'],
	$parentId);

$data['success'] = !$check['status'];
$data['message'] = $check['msg'];

echo json_encode($data);
