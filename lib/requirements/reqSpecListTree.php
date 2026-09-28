<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 *
 * @filesource reqSpecListTree.php
 *
 * LEGACY REDIRECT SHIM - the requirement specification tree navigator was
 * modernized in Refs #1695.
 *
 * The 84-line controller this replaces built an ExtJS tree
 * (test project -> requirement specification -> requirement) through
 * tlRequirementFilterControl::build_tree_menu() and rendered
 * gui/templates/dashio/requirements/reqSpecListTree.tpl (inc_head.tpl +
 * inc_ext_js.tpl + treebyloader.js). Its lazy loader,
 * lib/ajax/getrequirementnodes.php, did no rights check at all, so any
 * authenticated user could read the requirement doc_ids and titles of ANY test
 * project.
 *
 * The modern screen is gui/templates/requirements/reqSpecListTree.html and it
 * is backed by api/reqspectreelist/index.php, which enforces
 * mgt_view_req / mgt_modify_req on the addressed project and proves every node
 * id to be a requirement specification of that same project.
 *
 * The non-public testlinkInitPage($db, false, false) contract is preserved:
 * an anonymous call is bounced to login.php?note=expired&destination=... exactly
 * as the legacy frame did.
 */
require_once('../../config.inc.php');
require_once("common.php");

testlinkInitPage($db, false, false);

$tid = (isset($_REQUEST['tproject_id']) && is_scalar($_REQUEST['tproject_id']))
    ? intval($_REQUEST['tproject_id']) : 0;
if ($tid <= 0 && isset($_SESSION['testprojectID'])) {
    $tid = intval($_SESSION['testprojectID']);
}

// basehref, like every sibling shim (reqTcAssign.php, execNavigator.php,
// mainPage.php): a sub-directory installation must not be redirected off the
// document root.
$base = isset($_SESSION['basehref']) ? $_SESSION['basehref'] : '/';
$target = $base . 'gui/templates/requirements/reqSpecListTree.html';
if ($tid > 0) {
    $target .= '?tproject_id=' . $tid;
}

header('Location: ' . $target);
exit;
