<?php
/**
 * Regression matrix for issue #1045 (tcView.html requirements section rights).
 *
 * Three fixture projects hold IDENTICAL coverage rows and differ only in the
 * project role of the logged-in user (fixture: php tmp/fixtures_1045.php):
 *
 *   REQ1045L / rl1045  mgt_view_tc + mgt_modify_tc + req_tcase_link_management
 *                     (NO mgt_view_req)      -> legacy renders the section
 *   REQ1045V / rv1045  mgt_view_tc + mgt_modify_tc + mgt_view_req
 *                                              -> legacy renders the section
 *   REQ1045N / rn1045  mgt_view_tc + mgt_modify_tc only
 *                                              -> legacy hides the section
 *
 * Before the fix the linker role got `requirements: {}` (section vanished) and no
 * payload key could carry the per-row requirement VERSION id the legacy
 * openLinkedReqVersionWindow() needs.
 *
 * Usage: php tmp/fixtures_1045.php && php tmp/verify_1045.php
 */
require_once('config.inc.php');
require_once('common.php');

$BASE = 'http://localhost:8082';
$BFF  = $BASE . '/api/testcases/index.php';

$db = new database(DB_TYPE);
doDBConnect($db);

$PASS = 0;
$FAIL = 0;

function ok($label, $cond, $detail = '')
{
    global $PASS, $FAIL;
    echo ($cond ? 'PASS  ' : 'FAIL  ') . $label . ($cond ? '' : '  ' . $detail) . "\n";
    if ($cond) { $PASS++; } else { $FAIL++; }
}

function scalar($db, $sql)
{
    $r = $db->get_recordset($sql);
    if (is_null($r) || count($r) == 0) {
        die("fixture lookup returned nothing: $sql\n");
    }
    return $r[0];
}

$cases = array(
    // project         user      label                              wantReqs wantLinking
    array('REQ1045L', 'rl1045', 'linker (req_tcase_link_management only)', 2, true),
    array('REQ1045V', 'rv1045', 'req viewer (mgt_view_req only)',            2, false),
    array('REQ1045N', 'rn1045', 'no requirement right (negative control)',   0, false),
);

function tcOfProject($db, $nm)
{
    return intval(scalar($db, "SELECT n.id FROM nodes_hierarchy n " .
        "JOIN nodes_hierarchy c ON c.id=n.parent_id " .
        "JOIN nodes_hierarchy s ON s.id=c.parent_id " .
        "WHERE s.name='$nm' AND n.node_type_id=3 LIMIT 1")['id']);
}
function projectOf($db, $nm)
{
    return intval(scalar($db, "SELECT id FROM nodes_hierarchy WHERE node_type_id=1 AND name='$nm'")['id']);
}
function tcversionOfCase($db, $tcaseId)
{
    return intval(scalar($db, "SELECT id FROM nodes_hierarchy WHERE node_type_id=4 AND parent_id=$tcaseId ORDER BY id LIMIT 1")['id']);
}

function login($BASE, $user)
{
    $jar = "tmp/ck1045_$user.txt";
    @unlink($jar);
    exec('curl -s -c ' . escapeshellarg($jar) . ' -b ' . escapeshellarg($jar) .
         ' -X POST -d ' . escapeshellarg("tl_login=$user&tl_password=admin") .
         ' ' . escapeshellarg("$BASE/login.php?action=doLogin") . ' -o /dev/null');
    return $jar;
}

function fetch($jar, $tprojectId, $tcaseId)
{
    $out = array();
    exec('curl -s -b ' . escapeshellarg($jar) . ' -H ' . escapeshellarg('Origin: http://localhost:8082') .
         ' ' . escapeshellarg(
             'http://localhost:8082/api/testcases/index.php'
             . '?action=view&tproject_id=' . intval($tprojectId) . '&tcase_id=' . intval($tcaseId)) .
         ' -w "\\n%{http_code}"', $out);
    $raw = implode("\n", $out);
    $st = (int) substr($raw, strrpos($raw, "\n") + 1);
    $body = substr($raw, 0, strrpos($raw, "\n"));
    return array($st, json_decode($body, true));
}

$before = intval(scalar($db, "SELECT COUNT(*) AS c FROM events WHERE log_level IN (1,2)")['c']);

foreach ($cases as $c) {
    list($proj, $user, $label, $wantReqs, $wantLinking) = $c;
    $tprojectId = projectOf($db, $proj);
    $tcaseId = tcOfProject($db, $proj);
    $tcv = tcversionOfCase($db, $tcaseId);
    $jar = login($BASE, $user);
    list($st, $j) = fetch($jar, $tprojectId, $tcaseId);

    echo "\n===== $proj as $user - $label [http $st] =====\n";
    ok("$user  HTTP 200 + status ok", $st === 200 && ($j['status'] ?? '') === 'ok',
       "st=$st body=" . json_encode($j));
    if ($st !== 200 || !is_array($j)) {
        continue;
    }
    $g = $j['grants'] ?? array();
    $reqs = $j['requirements'][$tcv] ?? array();
    echo "  grants.mgt_view_req=" . var_export($g['mgt_view_req'] ?? null, true) .
         " grants.req_tcase_link_management=" . var_export($g['req_tcase_link_management'] ?? null, true) .
         " requirementsEnabled=" . var_export($j['requirementsEnabled'] ?? null, true) . "\n";
    foreach ($reqs as $r) {
        echo '    - ' . json_encode($r) . "\n";
    }

    ok("$user  requirements count == $wantReqs", count($reqs) === $wantReqs,
       'got ' . count($reqs));

    // F2: every delivered row carries the LINKED VERSION id (tpl:544-547)
    $allHaveVerId = true;
    foreach ($reqs as $r) {
        if (intval($r['req_version_id'] ?? 0) <= 0) {
            $allHaveVerId = false;
        }
    }
    ok("$user  every row carries req_version_id > 0", $allHaveVerId, json_encode($reqs));

    // F3: per-version reqLinkingEnabled mirrors $reqLinkingEnabled (tpl:516-524)
    $link = !empty($j['versions'][0]['reqLinkingEnabled']);
    ok("$user  versions[0].reqLinkingEnabled == " . var_export($wantLinking, true),
       $link === $wantLinking, 'got ' . var_export($link, true));

    // the label link target (legacy $hrefReqSpecMgmt, tpl:31-32)
    ok("$user  reqSpecMgmtUrl points at reqSpecMgmt.html of the project",
       ($j['reqSpecMgmtUrl'] ?? '') === '/gui/templates/requirements/reqSpecMgmt.html?tproject_id=' . $tprojectId,
       'got ' . var_export($j['reqSpecMgmtUrl'] ?? null, true));

    // client-side gate consistency: the screen must show the section exactly when
    // the payload has rows for this user (canSeeRequirements())
    $canSee = !empty($g['mgt_view_req']) || !empty($g['req_tcase_link_management']);
    ok("$user  client gate canSeeRequirements() matches the legacy OR",
       $canSee === ($wantReqs > 0),
       'canSee=' . var_export($canSee, true) . ' rows=' . count($reqs));
}

// no new Error/Warning in the events table
$after = intval(scalar($db, "SELECT COUNT(*) AS c FROM events WHERE log_level IN (1,2)")['c']);
ok('no new Error/Warning entries in `events`', $after === $before, "before=$before after=$after");

echo "\n===== $PASS passed, $FAIL failed =====\n";
exit($FAIL > 0 ? 1 : 0);