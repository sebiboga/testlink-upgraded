<?php
// Fixture for #1652 browser testing: Requirement log-message viewer
// (gui/templates/requirements/logViewer.html + api/logviewer/index.php).
//
// Creates tproject `LOGV` (prefix LG1, requirements enabled) with TWO
// requirement specifications so the BFF's cross-project guard can be exercised:
//
//   tproject LOGV  (id $idP)
//     RS-LOGV  "Log Viewer Spec"        -> revision 1 (log: spec creation log)
//                                        -> revision 2 (log: multiline, <p> wrapped,
//                                                     contains markup + entity)
//     RS-LOGV-EMPTY "Empty Log Spec"    -> revision 1 with an EMPTY log_message
//     REQ-LOGV-1 "First requirement"    -> version 1 (rich log) + version 2 (empty log)
//     REQ-LOGV-2 "Second requirement"   -> revision 1 only (no version)
//
//   tproject LOGV-ALT (id $idPAlt)
//     RS-LOGV-ALT -> revision 1 (log from a project the caller may not be in)
//
// Run from repo root: php tmp/fixtures_1652.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$reqSpecMgr = new requirement_spec_mgr($db);
$reqMgr = new requirement_mgr($db);
$userId = 1; // admin

// Re-runnable: drop the previous fixture projects first.
function dropProject($tprojMgr, $name) {
    foreach ((array)$tprojMgr->get_by_name($name) as $row) {
        $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
        if ($oid > 0) {
            echo "deleting old project $name ($oid)\n";
            $tprojMgr->delete($oid, 1);
        }
    }
}
dropProject($tprojMgr, 'LOGV');
dropProject($tprojMgr, 'LOGV-ALT');

function makeProject($tprojMgr, $name, $prefix, $notes) {
    $item = new stdClass();
    $item->name = $name;
    $item->prefix = $prefix;
    $item->notes = $notes;
    $item->color = '';
    $item->active = 1;
    $item->is_public = 1;
    $opts = new stdClass();
    $opts->requirementsEnabled = 1;
    $opts->testPriorityEnabled = 0;
    $opts->automationEnabled = 0;
    $opts->inventoryEnabled = 0;
    $opts->platformsEnabled = 0;
    $opts->testcasecfEnabled = 0;
    $opts->requirementcfEnabled = 0;
    $item->options = $opts;
    $r = $tprojMgr->create($item);
    $id = intval($r);
    if ($id <= 0) { die("tproject create failed for $name\n"); }
    $tprojMgr->setActive($id);
    return $id;
}

$idP = makeProject($tprojMgr, 'LOGV', 'LG1', 'fixture for #1652 (log message viewer)');
echo "tproject LOGV=$idP\n";

$idPAlt = makeProject($tprojMgr, 'LOGV-ALT', 'LGA', 'fixture for #1652 (cross-project guard)');
echo "tproject LOGV-ALT=$idPAlt\n";

// ---------------------------------------------------------------- specs ----
function makeSpec($reqSpecMgr, $tprojectId, $docId, $title, $userId) {
    $op = $reqSpecMgr->create($tprojectId, $tprojectId, $docId, $title,
        'scope of ' . $docId, 2, $userId, TL_REQ_SPEC_TYPE_USER_REQ_SPEC);
    if (!$op['status_ok'] || $op['id'] <= 0) {
        die("spec create failed for $docId: " . $op['msg'] . "\n");
    }
    return array('id' => intval($op['id']), 'rev1' => intval($op['revision_id']));
}

$specA = makeSpec($reqSpecMgr, $idP, 'RS-LOGV', 'Log Viewer Spec', $userId);
$specEmpty = makeSpec($reqSpecMgr, $idP, 'RS-LOGV-EMPTY', 'Empty Log Spec', $userId);
$specAlt = makeSpec($reqSpecMgr, $idPAlt, 'RS-LOGV-ALT', 'Alt Project Spec', $userId);
echo "spec RS-LOGV={$specA['id']} (rev1={$specA['rev1']})\n";
echo "spec RS-LOGV-EMPTY={$specEmpty['id']} (rev1={$specEmpty['rev1']})\n";
echo "spec RS-LOGV-ALT={$specAlt['id']} (rev1={$specAlt['rev1']})\n";

// Second revision of RS-LOGV: creates req_specs_revisions row #2.
$item = array();
$item['title'] = 'Log Viewer Spec (rev 2)';
$item['doc_id'] = 'RS-LOGV';
$item['scope'] = 'scope of RS-LOGV after the second revision';
$item['log_message'] = "<p>Second revision of the spec: <b>bold</b> and a\n" .
                     "new line with an entity: &amp; &lt;script&gt;alert(1)&lt;/script&gt;</p>\n" .
                     "<p>Trailing paragraph.</p>";
$item['type'] = TL_REQ_SPEC_TYPE_USER_REQ_SPEC;
$item['active'] = 1;
$item['total_req'] = 2;
$item['status_id'] = 1;
$item['parent_id'] = $specA['id'];
$item['tproject_id'] = $idP;
$item['user_id'] = $userId;
$rev2 = $reqSpecMgr->create_new_revision($specA['id'], $item);
$specA['rev2'] = intval($rev2['id']);
echo "spec RS-LOGV rev2={$specA['rev2']}\n";

// An EMPTY log on the RS-LOGV-EMPTY first revision, to exercise the
// legacy empty_log_message placeholder.
$db->exec_query("UPDATE req_specs_revisions SET log_message = '' " .
                 "WHERE id = " . intval($specEmpty['rev1']));
echo "emptied spec RS-LOGV-EMPTY rev1 log\n";

// --------------------------------------------------------- requirements ----
function makeReq($reqMgr, $srsId, $docId, $title, $userId, $tprojectId, $order) {
    $r = $reqMgr->create($srsId, $docId, $title, 'scope of ' . $docId, $userId,
        TL_REQ_STATUS_VALID, TL_REQ_TYPE_INFO, 1, $order, $tprojectId);
    if (!$r['status_ok'] || $r['id'] <= 0) {
        die("req create failed for $docId: " . $r['msg'] . "\n");
    }
    return intval($r['id']);
}

$req1 = makeReq($reqMgr, $specA['id'], 'REQ-LOGV-1', 'First requirement', $userId, $idP, 1);
$req2 = makeReq($reqMgr, $specA['id'], 'REQ-LOGV-2', 'Second requirement', $userId, $idP, 2);
echo "req REQ-LOGV-1=$req1 REQ-LOGV-2=$req2\n";

$req1v1 = 0;
$lrvi = $db->get_recordset("SELECT req_version_id FROM latest_req_version_id WHERE req_id = " .
    intval($req1));
if (!empty($lrvi)) { $req1v1 = intval($lrvi[0]['req_version_id']); }
if ($req1v1 <= 0) { die("could not resolve the first version of REQ-LOGV-1 ($req1)\n"); }
echo "req REQ-LOGV-1 version 1 = $req1v1\n";

// Requirement-version log message (req_versions.log_message).
$db->exec_query("UPDATE req_versions SET log_message = '" .
    $db->prepare_string("<p>Version 1 of REQ-LOGV-1: initial &amp; final.\n" .
                       "Second line with markup <i>kept as text</i>.</p>") .
    "' WHERE id = " . $req1v1);
echo "set req_versions log for REQ-LOGV-1 version 1\n";

// A second version of req1 so we can show version 2 with an EMPTY log.
$db->exec_query("UPDATE req_versions SET active = 0 WHERE id = " . intval($req1v1));
$ret = $reqMgr->create_version($req1, 2, 'scope of REQ-LOGV-1 v2', $userId,
    TL_REQ_STATUS_VALID, TL_REQ_TYPE_INFO, 1);
if (!$ret['status_ok']) { die('create_version failed: ' . $ret['msg'] . "\n"); }
$req1v2 = intval($ret['id']);
$db->exec_query("UPDATE req_versions SET log_message = '' WHERE id = " . $req1v2);
echo "req REQ-LOGV-1 version 2 = $req1v2 (empty log)\n";

// Requirement-revision log message (req_revisions.log_message). A brand-new
// requirement only gets a req_versions row, so the req_revisions row is created
// here exactly the way the tree manager does it: a nodes_hierarchy node of type
// requirement_revision (10) whose id is the req_revisions primary key.
if (empty($db->get_recordset("SELECT id FROM req_revisions WHERE parent_id = " . intval($req2)))) {
    $treeMgr = new tree($db, 'nodes_hierarchy');
    $revNodeId = $treeMgr->new_node($req2, 10);
    $req2rev1 = intval($revNodeId);
    $db->exec_query(
        "INSERT INTO req_revisions (parent_id, id, revision, req_doc_id, name, scope, " .
        "status, type, active, is_open, expected_coverage, log_message, author_id, " .
        "creation_ts, modifier_id, modification_ts) VALUES (" .
        intval($req2) . ", " . $req2rev1 . ", 1, 'REQ-LOGV-2', 'Second requirement', " .
        "'scope of REQ-LOGV-2', '" . $db->prepare_string(TL_REQ_STATUS_VALID) . "', '" .
        $db->prepare_string(TL_REQ_TYPE_INFO) . "', 1, 1, 1, '" .
        $db->prepare_string('Revision 1 of REQ-LOGV-2: <script>alert(1)</script> markup kept as text.') .
        "', " . intval($userId) . ", " . $db->db_now() . ", " . intval($userId) . ", " .
        $db->db_now() . ")");
} else {
    $rr = $db->get_recordset("SELECT id FROM req_revisions WHERE parent_id = " . intval($req2) .
        " ORDER BY id LIMIT 1");
    $req2rev1 = intval($rr[0]['id']);
}
if ($req2rev1 <= 0) { die("no req_revisions row created for REQ-LOGV-2 ($req2)\n"); }
echo "req REQ-LOGV-2 revision 1 = $req2rev1\n";

// The ALT project spec revision log (used by the cross-project 404 test).
$db->exec_query("UPDATE req_specs_revisions SET log_message = '" .
    $db->prepare_string('ALT project secret log - must never leak.') .
    "' WHERE id = " . intval($specAlt['rev1']));

// ------------------------------------------------------------- summary ----
// Machine-readable line first, so bash/verify_1652.sh can parse it instead of
// scraping the human-readable block below (ids change on every run - never
// hardcode them). Same convention as fixtures_1638.php.
echo "FIXTURE_OK issue=1652 tproject=$idP alt=$idPAlt"
   . " spec={$specA['id']} spec_rev1={$specA['rev1']} spec_rev2={$specA['rev2']}"
   . " spec_empty={$specEmpty['id']} spec_empty_rev1={$specEmpty['rev1']}"
   . " spec_alt={$specAlt['id']} spec_alt_rev1={$specAlt['rev1']}"
   . " req1=$req1 req1_v1=$req1v1 req1_v2=$req1v2 req2=$req2 req2_rev1=$req2rev1\n";

echo "\n=== #1652 fixture summary ===\n";
echo "tproject LOGV            = $idP\n";
echo "tproject LOGV-ALT        = $idPAlt\n";
echo "spec RS-LOGV             = {$specA['id']}  rev1={$specA['rev1']}  rev2={$specA['rev2']}\n";
echo "spec RS-LOGV-EMPTY       = {$specEmpty['id']}  rev1={$specEmpty['rev1']} (empty log)\n";
echo "spec RS-LOGV-ALT         = {$specAlt['id']}  rev1={$specAlt['rev1']} (other project)\n";
echo "req  REQ-LOGV-1          = $req1  v1=$req1v1 (rich log)  v2=$req1v2 (empty log)\n";
echo "req  REQ-LOGV-2          = $req2  rev1=$req2rev1\n";
echo "\nURLs:\n";
echo "  /gui/templates/requirements/logViewer.html?type=requirement_spec_version&id={$specA['rev1']}&tproject_id=$idP\n";
echo "  /gui/templates/requirements/logViewer.html?type=requirement_spec_version&id={$specA['rev2']}&tproject_id=$idP\n";
echo "  /gui/templates/requirements/logViewer.html?type=requirement_spec_version&id={$specEmpty['rev1']}&tproject_id=$idP\n";
echo "  /gui/templates/requirements/logViewer.html?type=requirement_version&id=$req1v2&tproject_id=$idP\n";
echo "  /gui/templates/requirements/logViewer.html?type=requirement_version&id=$req1v1&tproject_id=$idP\n";
echo "  /gui/templates/requirements/logViewer.html?type=requirement&id=$req2rev1&tproject_id=$idP\n";
echo "  /gui/templates/requirements/logViewer.html?type=requirement_spec_version&id={$specAlt['rev1']}&tproject_id=$idP  (expect 404 cross-project)\n";
