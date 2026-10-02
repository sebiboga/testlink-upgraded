<?php
/**
 * Regression matrix for #1790 (api/suitemove, `move` action): the refusals about a
 * caller-supplied node_id / new_parent_id answered TWO (in one case three)
 * different bodies, so the existence, ownership and NODE TYPE of any
 * nodes_hierarchy id stayed observable even though the status was already 404.
 *
 * Collapsed pairs (all confirmed by curl before the fix):
 *   node_id of an UNENTITLED project  -> 404 "Suite has no owning test project"
 *   node_id that exists nowhere      -> 404 "Suite not found"
 *   new_parent_id of the wrong TYPE  -> 400 "Destination is not a test suite"
 *   new_parent_id with no owner      -> 404 "Destination has no owning test project"
 *   new_parent_id that exists nowhere-> 404 "Destination not found"
 *
 * The wrong-type branch was the worst of them: it named the node type of an
 * arbitrary id (even one in a project the caller has no rights on) AND split the
 * status, so the status alone told a test case apart from an id that is not there.
 *
 * Written in PHP + curl because the shell harnesses of this lane (tmp/verify_1759.sh,
 * tmp/verify_1779.sh) shell out to the `mysql` CLIENT, which is not installed in this
 * environment. Fixture: php tmp/fixtures_1759.php
 *
 * Usage: php tmp/verify_1790.php
 */
require_once('config.inc.php');
require_once('common.php');

$BASE = 'http://localhost:8082';
$BFF  = $BASE . '/api/suitemove/index.php';
$ABSENT = 999999;
$ABSENT2 = 999998;

$db = new database(DB_TYPE);
doDBConnect($db);
$PASS = 0;
$FAIL = 0;

function scalar($db, $sql)
{
    $r = $db->get_recordset($sql);
    if (is_null($r) || count($r) == 0) {
        die("fixture lookup returned nothing: $sql\n");
    }
    return $r[0];
}

function ok($label, $cond, $detail = '')
{
    global $PASS, $FAIL;
    if ($cond) {
        echo "PASS  $label\n";
        $PASS++;
    } else {
        echo "FAIL  $label  $detail\n";
        $FAIL++;
    }
}

/** jar, method, body, expected status, expected code (code '' = don't care) */
function expect($jar, $method, $data, $xs, $xc, $label)
{
    global $BFF;
    $args = 'curl -s -b ' . escapeshellarg($jar);
    if ($method === 'GET') {
        $cmd = $args . ' -H ' . escapeshellarg('Origin: http://localhost:8082') .
               ' -X GET ' . escapeshellarg($BFF . '?' . $data) . ' -w "\\n%{http_code}"';
    } else {
        $cmd = $args . ' -H ' . escapeshellarg('Origin: http://localhost:8082') .
               ' -X ' . $method . ' -d ' . escapeshellarg($data) .
               ' ' . escapeshellarg($BFF) . ' -w "\\n%{http_code}"';
    }
    $out = array();
    exec($cmd, $out);
    $raw = implode("\n", $out);
    $st = (int) substr($raw, strrpos($raw, "\n") + 1);
    $body = substr($raw, 0, strrpos($raw, "\n"));
    $j = json_decode($body, true);
    $code = is_array($j) ? (string)($j['code'] ?? '') : '';
    $good = ($st === $xs) && ($xc === '' || $code === $xc);
    ok($label . " [$st $code]", $good, $good ? '' : "got [$st $code] want [$xs $xc] body=$body");
    return array($st, $body);
}

/**
 * The actual property under test: two answers to the same question that must not
 * be distinguishable. Compares the WHOLE body byte for byte, not just the status.
 */
function sameBytes($label, $resA, $resB)
{
    list($stA, $bodyA) = $resA;
    list($stB, $bodyB) = $resB;
    // An empty body on either side means the request itself failed (no session,
    // server down); comparing two empties would PASS for entirely the wrong
    // reason, which is exactly what this function used to do.
    $bothEmpty = ($bodyA === '' && $bodyB === '');
    ok($label . ' (status)', $stA === $stB && $stA !== 0,
       $bothEmpty ? 'both bodies empty - the requests never reached the endpoint'
                  : "A=[$stA] B=[$stB]");
    ok($label . ' (body bytes)', !$bothEmpty && $bodyA === $bodyB,
       'A=' . $bodyA . ' B=' . $bodyB);
}

// ---- fixture ids ------------------------------------------------------------
// Suite / test case names are NOT unique across fixtures - tmp/fixtures_1761.php
// creates 'A-suite-1', 'B-suite-1', 'A-tc-1-1' and friends too. A bare name lookup
// returns whichever row comes back first, so every child lookup is scoped to THIS
// fixture's project by parent_id.
$A  = intval(scalar($db, "SELECT id FROM nodes_hierarchy WHERE node_type_id=1 AND name='SM1759A'")['id']);
$B  = intval(scalar($db, "SELECT id FROM nodes_hierarchy WHERE node_type_id=1 AND name='SM1759B'")['id']);
$SA1 = intval(scalar($db, "SELECT id FROM nodes_hierarchy WHERE node_type_id=2 AND parent_id=$A AND name='A-suite-1'")['id']);
$SA2 = intval(scalar($db, "SELECT id FROM nodes_hierarchy WHERE node_type_id=2 AND parent_id=$A AND name='A-suite-2'")['id']);
$SB1 = intval(scalar($db, "SELECT id FROM nodes_hierarchy WHERE node_type_id=2 AND parent_id=$B AND name='B-suite-1'")['id']);
$TCA = intval(scalar($db, "SELECT id FROM nodes_hierarchy WHERE node_type_id=3 AND parent_id=$SA1 AND name='A-case-1'")['id']);
$TCB = intval(scalar($db, "SELECT id FROM nodes_hierarchy WHERE node_type_id=3 AND parent_id=$SB1 AND name='B-case-1'")['id']);
echo "fixture: A=$A B=$B SA1=$SA1 SA2=$SA2 SB1=$SB1 TCA=$TCA TCB=$TCB absent=$ABSENT\n";

// ---- sessions ---------------------------------------------------------------
$JARS = array('sm1759a', 'sm1759norights', 'sm1759view', 'admin');
foreach ($JARS as $u) {
    $jar = "tmp/ck1790_$u.txt";
    @unlink($jar);
    exec('curl -s -c ' . escapeshellarg($jar) . ' -b ' . escapeshellarg($jar) .
         ' -X POST -d ' . escapeshellarg("tl_login=$u&tl_password=admin") .
         ' ' . escapeshellarg("$BASE/login.php?action=doLogin") . ' -o /dev/null');
    $GLOBALS['J_' . $u] = $jar;
}
$JA = $GLOBALS['J_sm1759a'];
$JNR = $GLOBALS['J_sm1759norights'];
$JV = $GLOBALS['J_sm1759view'];

$WBASE = intval(scalar($db, "SELECT COUNT(*) AS c FROM events WHERE log_level IN (1,2)")['c']);

echo "\n===== the node_id axis, as sm1759a (mgt_modify_tc on project A only) =====\n";
$absentNode  = expect($JA, 'POST', "action=move&tproject_id=$A&node_id=$ABSENT&position=down", 404, 'not_found', 'N1 absent node_id');
$foreignNode = expect($JA, 'POST', "action=move&tproject_id=$A&node_id=$SB1&position=down", 404, 'not_found', 'N2 foreign node_id');
sameBytes('N3 foreign node_id == absent node_id', $foreignNode, $absentNode);

echo "\n--- the same axis with NO tproject_id named at all ---\n";
$absentNoP  = expect($JA, 'POST', "action=move&node_id=$ABSENT&position=down", 404, 'not_found', 'N4 absent node_id, no tproject_id');
$foreignNoP = expect($JA, 'POST', "action=move&node_id=$SB1&position=down", 404, 'not_found', 'N5 foreign node_id, no tproject_id');
sameBytes('N6 foreign == absent (no tproject_id)', $foreignNoP, $absentNoP);

echo "\n--- the node axis with the WRONG node type (live branch: move never\n";
echo "--- proves the node type, it only proves existence and ownership) ---\n";
$nodeOwnTc  = expect($JA, 'POST', "action=move&tproject_id=$A&node_id=$TCA&position=down", 404, 'not_found', 'N10 own TEST CASE as node_id');
$nodeOwnRoot = expect($JA, 'POST', "action=move&tproject_id=$A&node_id=$A&position=down", 404, 'not_found', 'N11 own project root as node_id');
$nodeForeignTc = expect($JA, 'POST', "action=move&tproject_id=$A&node_id=$TCB&position=down", 404, 'not_found', 'N12 FOREIGN test case as node_id');
sameBytes('N13 own test case == absent node', $nodeOwnTc, $absentNode);
sameBytes('N14 project root == absent node', $nodeOwnRoot, $absentNode);
ok('N15 every node-axis refusal is byte-identical',
   count(array_unique(array($absentNode[1], $foreignNode[1], $nodeOwnTc[1],
       $nodeOwnRoot[1], $nodeForeignTc[1]))) === 1,
   implode(' | ', array($absentNode[1], $foreignNode[1], $nodeOwnTc[1], $nodeOwnRoot[1], $nodeForeignTc[1])));

echo "\n===== the new_parent_id axis (the node TYPE oracle) =====\n";
$destAbsent = expect($JA, 'POST', "action=move&tproject_id=$A&node_id=$SA1&position=bottom&new_parent_id=$ABSENT", 404, 'not_found', 'D1 absent destination');
$destOwnTc  = expect($JA, 'POST', "action=move&tproject_id=$A&node_id=$SA1&position=bottom&new_parent_id=$TCA", 404, 'not_found', 'D2 destination is a test case of the OWN project');
$destForeignTc = expect($JA, 'POST', "action=move&tproject_id=$A&node_id=$SA1&position=bottom&new_parent_id=$TCB", 404, 'not_found', 'D3 destination is a test case of a FOREIGN project');
$destForeignRoot = expect($JA, 'POST', "action=move&tproject_id=$A&node_id=$SA1&position=bottom&new_parent_id=$B", 404, 'not_found', 'D4 destination is the root of a FOREIGN project');
sameBytes('D5 own test case == absent destination', $destOwnTc, $destAbsent);
sameBytes('D6 foreign test case == absent destination', $destForeignTc, $destAbsent);
sameBytes('D7 foreign project root == absent destination', $destForeignRoot, $destAbsent);

echo "\n--- every refusal for a caller-supplied destination is ONE answer ---\n";
$bodies = array($destAbsent[1], $destOwnTc[1], $destForeignTc[1], $destForeignRoot[1]);
ok('D8 all four destination refusals are byte-identical',
   count(array_unique($bodies)) === 1, implode(' | ', $bodies));

echo "\n===== as sm1759norights (no project role at all) =====\n";
$nrAbsent  = expect($JNR, 'POST', "action=move&tproject_id=$A&node_id=$ABSENT&position=down", 404, 'not_found', 'N7 norights: absent node_id');
$nrForeign = expect($JNR, 'POST', "action=move&tproject_id=$A&node_id=$SB1&position=down", 404, 'not_found', 'N8 norights: foreign node_id');
sameBytes('N9 norights: foreign == absent', $nrForeign, $nrAbsent);
// The NODE is refused before the destination is even resolved, so the answer is
// the same opaque node refusal this user gets for an absent node_id - never a
// message that names the destination.
$nrDest = expect($JNR, 'POST', "action=move&tproject_id=$A&node_id=$SA1&position=bottom&new_parent_id=$TCA", 404, 'not_found', 'D9 norights: test case as destination');
sameBytes('D10 norights: the refusal is the absent-node answer, and names no type', $nrDest, $nrAbsent);

echo "\n===== as sm1759view (mgt_view_tc only, cannot write) =====\n";
// A view-only user (mgt_view_tc, no mgt_modify_tc) is refused the same opaque
// way, because $leakMessage makes the node refusal this action's own wording
// rather than the informative 403.
$vwAbsent = expect($JV, 'POST', "action=move&tproject_id=$A&node_id=$ABSENT&position=down", 404, 'not_found', 'D11 view-only: absent node_id');
$vw = expect($JV, 'POST', "action=move&tproject_id=$A&node_id=$SA1&position=bottom&new_parent_id=$TCA", 404, 'not_found', 'D12 view-only write refused');
sameBytes('D13 view-only: own-suite write == absent node (status)', $vw, $vwAbsent);
ok('D14 view-only refusal never names a node type or an owner',
   strpos($vw[1], 'not a test suite') === false &&
   strpos($vw[1], 'no owning test project') === false, $vw[1]);

echo "\n===== the legitimate writes still work (no false 404) =====\n";
// move A-suite-2 into A-suite-1, then move it back
$mv = expect($JA, 'POST', "action=move&tproject_id=$A&node_id=$SA2&position=bottom&new_parent_id=$SA1", 200, '', 'L1 real move into the other suite');
$parent = intval(scalar($db, "SELECT parent_id AS p FROM nodes_hierarchy WHERE id=$SA2")['p']);
ok('L2 the DB really re-parented the suite', $parent === intval($SA1), "parent_id=$parent want=$SA1");
$mvBack = expect($JA, 'POST', "action=move&tproject_id=$A&node_id=$SA2&position=bottom&new_parent_id=$A", 200, '', 'L3 real move back to the project root');
$parent2 = intval(scalar($db, "SELECT parent_id AS p FROM nodes_hierarchy WHERE id=$SA2")['p']);
ok('L4 the DB really re-parented it back', $parent2 === intval($A), "parent_id=$parent2 want=$A");
// a real re-parent onto a SUITE must still answer 200
$mv2 = expect($JA, 'POST', "action=move&tproject_id=$A&node_id=$SA2&position=top&new_parent_id=$SA1", 200, '', 'L5 real move onto a suite (top)');
$parent3 = intval(scalar($db, "SELECT parent_id AS p FROM nodes_hierarchy WHERE id=$SA2")['p']);
ok('L6 the DB really re-parented it', $parent3 === intval($SA1), "parent_id=$parent3 want=$SA1");
// and back to the root, so the fixture is left as it was found
expect($JA, 'POST', "action=move&tproject_id=$A&node_id=$SA2&position=bottom&new_parent_id=$A", 200, '', 'L7 restore: back to the project root');
ok('L8 fixture restored',
   intval(scalar($db, "SELECT parent_id AS p FROM nodes_hierarchy WHERE id=$SA2")['p']) === intval($A), 'not restored');

echo "\n===== reorder / init / suites must be untouched =====\n";
expect($JA, 'GET', "action=init&tproject_id=$A&container_id=$SA1", 200, '', 'C1 init own suite');
expect($JA, 'GET', "action=init&tproject_id=$A&container_id=$ABSENT", 404, 'not_found', 'C2 init absent container');
$no = expect($JA, 'POST', "action=move&tproject_id=$A&node_id=$SA2&position=down", 200, '', 'C3 in-container reorder write still works');
expect($JA, 'POST', "action=reorder&tproject_id=$A&container_id=$A&nodelist=$SA2,$SA1", 200, '', 'C4 reorder the project root children');
expect($JA, 'POST', "action=reorder&tproject_id=$A&container_id=$A&nodelist=$SA1,$SA2", 200, '', 'C5 and back');

echo "\n===== contract / CSRF =====\n";
expect($JA, 'POST', "action=move&tproject_id=$A&node_id=$ABSENT&position=sideways", 400, 'bad_request', 'K1 an INVALID position is still a 400');
// an omitted position defaults to 'bottom', so it is NOT a 400 - it falls through
// to the normal opaque node refusal. Pinned because a harness that assumed a 400
// here would have reported a regression that does not exist.
expect($JA, 'POST', "action=move&tproject_id=$A&node_id=$ABSENT", 404, 'not_found', 'K1b an OMITTED position defaults to bottom');
expect($JA, 'POST', "action=move&tproject_id=$A&position=down", 400, 'bad_request', 'K2 missing node_id is still a 400');
expect($JA, 'GET', "action=move&tproject_id=$A&node_id=$ABSENT", 405, 'method_not_allowed', 'K3 move over GET is still 405');

echo "\n===== Event Viewer =====\n";
$WAFTER = intval(scalar($db, "SELECT COUNT(*) AS c FROM events WHERE log_level IN (1,2)")['c']);
ok('V1 no new ERROR/WARNING row in events', $WAFTER === $WBASE, "$WBASE -> $WAFTER");

echo "\n===== $PASS passed, $FAIL failed =====\n";
exit($FAIL === 0 ? 0 : 1);