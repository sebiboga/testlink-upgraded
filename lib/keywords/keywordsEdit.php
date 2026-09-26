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
// NOTE: the second argument stays FALSE on purpose. Passing TRUE runs
// initProject(), which would let a crafted GET overwrite the session's
// testprojectID/testplanID from the request string - the legacy controller
// called testlinkInitPage($db) and never had that side effect (Refs #1604).
testlinkInitPage($db);

/**
 * Refs #1601: rights are checked for the tproject_id sent by the caller while
 * the keyword is addressed by a bare id, and neither tlKeyword::writeToDB()
 * (UPDATE ... WHERE id = X) nor testproject::deleteKeyword() (id only) re-check
 * the owner - a keyword manager of project A could rename/re-own or delete a
 * keyword of project B through this still-live URL.
 */
function kwShimOwnedBy($db, $keywordId, $tprojectID) {
	$kw = tlKeyword::getByID($db, $keywordId);
	return (!is_null($kw) && $kw->dbID > 0 && intval($kw->testprojectID) === intval($tprojectID));
}

/**
 * $status < 0 is a tlKeyword::E_* error code: it is forwarded to the modern
 * screen as kwerr= so a failed legacy write is not silently reported as a
 * success (Refs #1604). 303 for the POST branch, 302 for the GET branch.
 */
function kwShimOut($status, $tproject_id, $httpCode = 302) {
	$base = isset($_SESSION['basehref']) ? $_SESSION['basehref'] : '/';
	$url = $base . 'gui/templates/keywords/keywordsView.html?tproject_id=' . intval($tproject_id) .
		'&tplan_id=' . (isset($_SESSION['testplanID']) ? intval($_SESSION['testplanID']) : 0);
	if (intval($status) < 0) {
		$url .= '&kwerr=' . intval($status);
	}
	http_response_code($httpCode);
	header('Location: ' . $url);
	exit;
}

// Legacy input contract (initEnv() in the pre-2.0.1 controller).
$doAction = isset($_REQUEST['doAction']) ? trim($_REQUEST['doAction']) : '';
$keywordId = isset($_REQUEST['id']) ? intval($_REQUEST['id']) : 0;
// Cast to string and drop arrays: keyword[]=x would reach
// tlKeyword::checkKeyword() -> trim(array) and fatal with a TypeError.
$keyword = isset($_REQUEST['keyword']) && !is_array($_REQUEST['keyword'])
	? (string)$_REQUEST['keyword'] : '';
$notes = isset($_REQUEST['notes']) && !is_array($_REQUEST['notes'])
	? (string)$_REQUEST['notes'] : '';
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

		// CSRF: the modern BFFs use bffSameOriginGuard(), the shim performed the
	// same writes with session validation only, so a cross-site form POST with
	// the victim's cookie could delete a keyword (Refs #1604).
	$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
	$secFetchSite = isset($_SERVER['HTTP_SEC_FETCH_SITE']) ? $_SERVER['HTTP_SEC_FETCH_SITE'] : '';
	$isXhr = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
		&& $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest';
	$base = isset($_SESSION['basehref']) ? $_SESSION['basehref'] : '/';
	$baseHost = parse_url((isset($_SERVER['HTTP_HOST']) ? 'http://' . $_SERVER['HTTP_HOST'] : $base), PHP_URL_HOST);
	if (!$isXhr && $secFetchSite === 'cross-site') {
		header('Location: ' . $base . 'gui/templates/keywords/keywordsView.html?tproject_id=' . $tprojectID);
		exit;
	}
	if ($origin !== '' && $baseHost !== null && parse_url($origin, PHP_URL_HOST) !== $baseHost) {
		header('Location: ' . $base . 'gui/templates/keywords/keywordsView.html?tproject_id=' . $tprojectID);
		exit;
	}

	$tprojectMgr = new testproject($db);
	$status = tl::OK;
	switch ($doAction) {
		case 'do_create':
			$op = $tprojectMgr->addKeyword($tprojectID, $keyword, $notes);
			$status = intval($op['status']);
			break;

		case 'do_update':
			if ($keywordId > 0 && kwShimOwnedBy($db, $keywordId, $tprojectID)) {
				$status = intval($tprojectMgr->updateKeyword($tprojectID, $keywordId, $keyword, $notes));
			} else {
				$status = -1;
			}
			break;

		case 'do_delete':
			if ($keywordId > 0 && kwShimOwnedBy($db, $keywordId, $tprojectID)) {
				$dko = array('context' => 'getTestProjectName', 'tproject_id' => $tprojectID);
				$status = intval($tprojectMgr->deleteKeyword($keywordId, $dko));
			} else {
				$status = -1;
			}
			break;

		case 'do_cfl':
			$op = $tprojectMgr->addKeyword($tprojectID, $keyword, $notes);
			$status = intval($op['status']);
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
	kwShimOut($status, $tprojectID, 303);
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
