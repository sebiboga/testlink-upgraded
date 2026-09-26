<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * @filesource	keywordsEdit.php
 *
 * 2.0.1.shim - Refs #1599: the legacy Smarty keyword dialog (create / edit /
 * delete / create-and-link) was replaced by the modern Dashio popup
 * gui/templates/keywords/keywordsEdit.html + the api/keywordsedit BFF.
 *
 * This controller is kept as a session-guarded redirect shim so old deep links
 * (and the still-shipped dead tl-classic / dashio templates) resolve:
 *   - anonymous users are sent to the login screen (legacy testlinkInitPage
 *     behaviour);
 *   - GET requests (form display: doAction=create|edit|cfl) are mapped onto the
 *     modern popup with the mode/id/tcversion_id/tproject_id/tplan_id context
 *     forwarded;
 *   - POST requests (the legacy do_create / do_update / do_delete / do_cfl
 *     writes) are performed here with the very same model calls and the very
 *     same legacy AND-mode rights gate (mgt_modify_key AND mgt_view_key at
 *     test-project level), then redirected (303) back to the modern Keyword
 *     Management screen. Keeping the writes server-side avoids a silent data
 *     loss for any form that still posts here.
**/
require_once("../../config.inc.php");
require_once("common.php");

// Anonymous -> login (same contract as the legacy testlinkInitPage call).
testlinkInitPage($db, TRUE);

function kwShimOut($msg, $tproject_id) {
	$base = isset($_SESSION['basehref']) ? $_SESSION['basehref'] : '/';
	$url = $base . 'gui/templates/keywords/keywordsView.html?tproject_id=' . intval($tproject_id) .
		'&tplan_id=' . (isset($_SESSION['testplanID']) ? intval($_SESSION['testplanID']) : 0);
	header('Location: ' . $url);
	exit;
}

// Legacy input contract (initEnv() in the pre-2.0.1 controller).
$doAction = isset($_REQUEST['doAction']) ? trim($_REQUEST['doAction']) : '';
$keywordId = isset($_REQUEST['id']) ? intval($_REQUEST['id']) : 0;
$keyword = isset($_REQUEST['keyword']) ? $_REQUEST['keyword'] : '';
$notes = isset($_REQUEST['notes']) ? $_REQUEST['notes'] : '';
$tprojectID = isset($_REQUEST['tproject_id']) ? intval($_REQUEST['tproject_id']) : 0;
$tcversionId = isset($_REQUEST['tcversion_id']) ? intval($_REQUEST['tcversion_id']) : 0;

if ($tprojectID <= 0) {
	$tprojectID = isset($_SESSION['testprojectID']) ? intval($_SESSION['testprojectID']) : 0;
}
$tplanID = isset($_SESSION['testplanID']) ? intval($_SESSION['testplanID']) : 0;

$isWrite = in_array($doAction, array('do_create', 'do_update', 'do_delete', 'do_cfl'), TRUE);

if ($isWrite) {
	// Same rights gate as the legacy initEnv(): AND mode on the test project.
	$currentUser = $_SESSION['currentUser'];
	$canModify = $currentUser->hasRight($db, 'mgt_modify_key', $tprojectID);
	$canView = $currentUser->hasRight($db, 'mgt_view_key', $tprojectID);
	if (!$canModify || !$canView || $tprojectID <= 0) {
		header('Location: ' . (isset($_SESSION['basehref']) ? $_SESSION['basehref'] : '/') .
			'gui/templates/keywords/keywordsView.html?tproject_id=' . $tprojectID);
		exit;
	}

	$tprojectMgr = new testproject($db);
	switch ($doAction) {
		case 'do_create':
			$tprojectMgr->addKeyword($tprojectID, $keyword, $notes);
			break;

		case 'do_update':
			if ($keywordId > 0) {
				$tprojectMgr->updateKeyword($tprojectID, $keywordId, $keyword, $notes);
			}
			break;

		case 'do_delete':
			if ($keywordId > 0) {
				$dko = array('context' => 'getTestProjectName', 'tproject_id' => $tprojectID);
				$tprojectMgr->deleteKeyword($keywordId, $dko);
			}
			break;

		case 'do_cfl':
			$op = $tprojectMgr->addKeyword($tprojectID, $keyword, $notes);
			if ($op['status'] >= tl::OK && $tcversionId > 0) {
				$tbl = tlObject::getDBTables('nodes_hierarchy');
				$sql = "SELECT parent_id FROM {$tbl['nodes_hierarchy']} WHERE id=" . $tcversionId;
				$rs = $db->get_recordset($sql);
				$tcaseId = !is_null($rs) && count($rs) ? intval($rs[0]['parent_id']) : 0;
				if ($tcaseId > 0) {
					$tcaseMgr = new testcase($db);
					$tcaseMgr->addKeywords($tcaseId, $tcversionId, array(intval($op['id'])));
				}
			}
			break;
	}
	kwShimOut('', $tprojectID);
}

// GET (form display) -> modern popup
$mode = 'create';
switch ($doAction) {
	case 'edit':
		$mode = 'edit';
		break;

	case 'cfl':
		$mode = 'cfl';
		break;
}

if ($mode === 'edit' && $keywordId <= 0) {
	$mode = 'create';
}
if ($mode === 'cfl' && $tcversionId <= 0) {
	$mode = 'create';
}

$url = $_SESSION['basehref'] . 'gui/templates/keywords/keywordsEdit.html';
$url .= '?mode=' . $mode .
	'&tproject_id=' . $tprojectID .
	'&tplan_id=' . $tplanID .
	'&id=' . $keywordId .
	'&tcversion_id=' . $tcversionId;
header('Location: ' . $url);
exit;
