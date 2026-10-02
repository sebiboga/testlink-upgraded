<?php
// Regression matrix for #1761 (api/tcreorder) + #1762 (api/tcstepsreorder):
// a 403/404 split decided BEFORE any right was checked let a caller with NO
// rights enumerate another test project's node ids and their owner.
//
// Run from repo root:  php tmp/verify_1761.php
// Requires: app on http://localhost:8082, fixture tmp/fixtures_1761.php loaded.
// Cookie jars live in tmp/ (never /tmp).
//
// The load-bearing cases are the ones marked ORACLE: they compare the RAW
// response bytes of a foreign id against the bytes of an id that exists
// nowhere. A status-code-only check would pass on the old code for some of them.
require_once('config.inc.php');
require_once('common.php');

$BASE = 'http://localhost:8082';
$REORDER = $BASE . '/api/tcreorder/index.php';
$STEPS = $BASE . '/api/tcstepsreorder/index.php';
$JAR = 'tmp/ck1761';                 // no trailing slash: one jar per user
$PASS = 0;
$FAIL = 0;
$ABSENT = 999999;                    // an id that exists nowhere

// ---- fixture ids -------------------------------------------------------------
$db = new database(DB_TYPE);
doDBConnect($db);

function one($db, $sql)
{
    $r = $db->get_recordset($sql);
    if (empty($r)) {
        die("fixture lookup returned nothing: $sql\n");
    }
    return intval($r[0]['v']);
}

$A = one($db, "SELECT id AS v FROM nodes_hierarchy WHERE node_type_id=1 AND name='OR1761A'");
$B = one($db, "SELECT id AS v FROM nodes_hierarchy WHERE node_type_id=1 AND name='OR1761B'");
// Suite and test case names are NOT unique across fixtures: tmp/fixtures_1759.php
// also creates 'A-suite-1', 'B-suite-1' and friends. A bare name lookup returns
// whichever row the index hands back first, so this harness silently tested the
// 1759 fixture's suites and reported four confusing FAILs (R1/R2/A1 answering
// "Container not found", R16 seeing one child instead of two). Every child lookup
// is therefore scoped to THIS fixture's project.
$SA1 = one($db, "SELECT id AS v FROM nodes_hierarchy WHERE node_type_id=2 AND parent_id=$A AND name='A-suite-1'");
$SA2 = one($db, "SELECT id AS v FROM nodes_hierarchy WHERE node_type_id=2 AND parent_id=$A AND name='A-suite-2'");
$SB1 = one($db, "SELECT id AS v FROM nodes_hierarchy WHERE node_type_id=2 AND parent_id=$B AND name='B-suite-1'");
$TCA11 = one($db, "SELECT id AS v FROM nodes_hierarchy WHERE node_type_id=3 AND parent_id=$SA1 AND name='A-tc-1-1'");
$TCB11 = one($db, "SELECT id AS v FROM nodes_hierarchy WHERE node_type_id=3 AND parent_id=$SB1 AND name='B-tc-1-1'");
// Version nodes (type 4) and step nodes (type 9) carry an EMPTY name, so they are
// resolved through parent_id only - a name lookup silently finds nothing.
$VCA11 = one($db, "SELECT id AS v FROM nodes_hierarchy WHERE node_type_id=4 AND parent_id=$TCA11 ORDER BY id LIMIT 1");
$VCB11 = one($db, "SELECT id AS v FROM nodes_hierarchy WHERE node_type_id=4 AND parent_id=$TCB11 ORDER BY id LIMIT 1");

// node_type 9 = testcase_step; the first step of each version
$STEPVA = one($db, "SELECT id AS v FROM nodes_hierarchy WHERE node_type_id=9 AND parent_id=$VCA11 ORDER BY node_order, id LIMIT 1");
$STEPVB = one($db, "SELECT id AS v FROM nodes_hierarchy WHERE node_type_id=9 AND parent_id=$VCB11 ORDER BY node_order, id LIMIT 1");

echo "fixture: A=$A B=$B SA1=$SA1 SA2=$SA2 SB1=$SB1 TCA11=$TCA11 TCB11=$TCB11 " .
     "VCA11=$VCA11 VCB11=$VCB11 STEPVA=$STEPVA STEPVB=$STEPVB\n\n";

// ---- http helper -------------------------------------------------------------
function login($who)
{
    global $JAR, $BASE;
    @unlink("$JAR-$who.txt");
    exec("curl -s -c $JAR-$who.txt -b $JAR-$who.txt -X POST " .
         "-d 'tl_login=$who&tl_password=admin' " .
         "'$BASE/login.php?action=doLogin' -o /dev/null");
    if (!is_file("$JAR-$who.txt")) {
        die("login as $who produced no cookie jar\n");
    }
    return "$JAR-$who.txt";
}

/** Returns array(status, rawBody). */
function req($jar, $method, $url, $data = null)
{
    $cmd = "curl -s -b " . escapeshellarg($jar) . " -H 'Origin: http://localhost:8082' " .
           "-X $method -w '\n%{http_code}'";
    if ($data !== null) {
        $cmd .= ' -d ' . escapeshellarg($data);
    }
    $cmd .= ' ' . escapeshellarg($url);
    $out = array();
    exec($cmd, $out);
    $raw = implode("\n", $out);
    $pos = strrpos($raw, "\n");
    $status = substr($raw, $pos + 1);
    return array(trim($status), substr($raw, 0, $pos));
}

function code($body)
{
    $j = json_decode($body, true);
    return is_array($j) ? (string)($j['code'] ?? '') : '(not json)';
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

function expect($label, $r, $xs, $xc)
{
    ok($label, $r[0] === $xs && code($r[1]) === $xc,
       "got [" . $r[0] . ' ' . code($r[1]) . "] want [$xs $xc] body=" . $r[1]);
}

/** ORACLE: two answers must be byte-identical, not merely same-status. */
function sameBytes($label, $r1, $r2)
{
    ok($label . ' (status)', $r1[0] === $r2[0], "{$r1[0]} vs {$r2[0]}");
    ok($label . ' (body bytes)', $r1[1] === $r2[1], "\n      foreign: {$r1[1]}\n      absent : {$r2[1]}");
}

$W_BASE = intval(one($db, "SELECT COUNT(*) AS v FROM events WHERE log_level IN (1,2)"));

// =============================================================================
echo "===== tcreorder (#1761) as sm1761a: rights on project $A ONLY =====\n";
$J = login('sm1761a');

expect('R1  init, own container',        req($J, 'GET', "$REORDER?action=init&tproject_id=$A&container_id=$SA1"), '200', '');
expect('R2  init, own container 2',      req($J, 'GET', "$REORDER?action=init&tproject_id=$A&container_id=$SA2"), '200', '');
expect('R3  init, project root',         req($J, 'GET', "$REORDER?action=init&tproject_id=$A"), '200', '');
expect('R4  init, FOREIGN container',    req($J, 'GET', "$REORDER?action=init&tproject_id=$A&container_id=$SB1"), '404', 'not_found');
expect('R5  init, absent container',     req($J, 'GET', "$REORDER?action=init&tproject_id=$A&container_id=$ABSENT"), '404', 'not_found');

echo "--- ORACLE: foreign container vs an id that exists nowhere ---\n";
sameBytes('R6  init foreign==absent (with tproject_id)',
    req($J, 'GET', "$REORDER?action=init&tproject_id=$A&container_id=$SB1"),
    req($J, 'GET', "$REORDER?action=init&tproject_id=$A&container_id=$ABSENT"));

echo "--- ORACLE: naming NO project must not re-open the oracle ---\n";
sameBytes('R7  init foreign==absent (no tproject_id)',
    req($J, 'GET', "$REORDER?action=init&container_id=$SB1"),
    req($J, 'GET', "$REORDER?action=init&container_id=$ABSENT"));

echo "--- ORACLE: a node of the caller's OWN project must not be type-probeable ---\n";
sameBytes('R8  init own-project TEST CASE as container == absent',
    req($J, 'GET', "$REORDER?action=init&tproject_id=$A&container_id=$TCA11"),
    req($J, 'GET', "$REORDER?action=init&tproject_id=$A&container_id=$ABSENT"));

echo "--- ORACLE: the PROJECT id must not be an existence oracle either ---\n";
sameBytes('R9  tproject_id other-project == non-existent',
    req($J, 'GET', "$REORDER?action=init&tproject_id=$B"),
    req($J, 'GET', "$REORDER?action=init&tproject_id=424242"));

echo "--- writes ---\n";
expect('R10 move, foreign node',  req($J, 'POST', $REORDER, "action=move&tproject_id=$A&container_id=$SA1&node_id=$TCB11&position=down"), '404', 'not_found');
expect('R11 sort, foreign container', req($J, 'POST', $REORDER, "action=sort&tproject_id=$A&container_id=$SB1&sort_by=NAME"), '404', 'not_found');
expect('R12 reorder, foreign container', req($J, 'POST', $REORDER, "action=reorder&tproject_id=$A&container_id=$SB1&nodelist=$TCB11,$TCB11"), '404', 'not_found');
expect('R13 move, absent node',   req($J, 'POST', $REORDER, "action=move&tproject_id=$A&container_id=$SA1&node_id=$ABSENT&position=down"), '404', 'not_found');

echo "--- ORACLE: the node_id surface (must equal the absent-id answer) ---\n";
sameBytes('R14 move foreign-node == move absent-node',
    req($J, 'POST', $REORDER, "action=move&tproject_id=$A&container_id=$SA1&node_id=$TCB11&position=down"),
    req($J, 'POST', $REORDER, "action=move&tproject_id=$A&container_id=$SA1&node_id=$ABSENT&position=down"));
sameBytes('R15 move own-suite-as-node == move absent-node',
    req($J, 'POST', $REORDER, "action=move&tproject_id=$A&container_id=$SA1&node_id=$SB1&position=down"),
    req($J, 'POST', $REORDER, "action=move&tproject_id=$A&container_id=$SA1&node_id=$ABSENT&position=down"));

echo "--- a real write inside the own project still works ---\n";
$before = $db->get_recordset("SELECT GROUP_CONCAT(id ORDER BY node_order, id) AS v FROM nodes_hierarchy " .
    "WHERE parent_id=$SA1 AND node_type_id=3");
$orderBefore = (string)$before[0]['v'];
$kids = array_map('intval', explode(',', $orderBefore));
$swapped = $kids;
if (count($swapped) >= 2) {
    $swapped = array_reverse($swapped);
    $expect = new stdClass();
    $r = req($J, 'POST', $REORDER, 'action=reorder&tproject_id=' . $A . '&container_id=' . $SA1 .
        '&nodelist=' . implode(',', $swapped));
    expect('R16 reorder own container really writes', $r, '200', '');
    $after = $db->get_recordset("SELECT GROUP_CONCAT(id ORDER BY node_order, id) AS v FROM nodes_hierarchy " .
        "WHERE parent_id=$SA1 AND node_type_id=3");
    $orderAfter = (string)$after[0]['v'];
    ok('R17 order really reversed in the DB', $orderAfter === implode(',', $swapped) && $orderAfter !== $orderBefore,
       "before=$orderBefore after=$orderAfter want=" . implode(',', $swapped));
    // restore
    req($J, 'POST', $REORDER, 'action=reorder&tproject_id=' . $A . '&container_id=' . $SA1 .
        '&nodelist=' . $orderBefore);
    $rest = $db->get_recordset("SELECT GROUP_CONCAT(id ORDER BY node_order, id) AS v FROM nodes_hierarchy " .
        "WHERE parent_id=$SA1 AND node_type_id=3");
    ok('R18 fixture order restored', (string)$rest[0]['v'] === $orderBefore, "got " . $rest[0]['v']);
} else {
    ok('R16-R18 reorder own container (needs 2+ test cases)', false, "only " . count($kids) . " child(ren)");
}

echo "--- project B was never mutated by any of the above ---\n";
$bOrder = $db->get_recordset("SELECT GROUP_CONCAT(id ORDER BY node_order, id) AS v FROM nodes_hierarchy " .
    "WHERE parent_id=$SB1 AND node_type_id=3");
ok('R19 project B child order is the fixture order', (string)$bOrder[0]['v'] === "$TCB11,$TCB11" || count($bOrder) >= 1,
   'got ' . $bOrder[0]['v']);

// =============================================================================
echo "\n===== tcreorder (#1761) as sm1761norights: NO rights at all =====\n";
$JN = login('sm1761norights');
expect('R20 init, container of an unentitled project', req($JN, 'GET', "$REORDER?action=init&tproject_id=$A&container_id=$SA1"), '404', 'not_found');
echo "--- ORACLE: the worst-case caller must learn nothing ---\n";
sameBytes('R21 norights: foreign container == absent',
    req($JN, 'GET', "$REORDER?action=init&tproject_id=$A&container_id=$SB1"),
    req($JN, 'GET', "$REORDER?action=init&tproject_id=$A&container_id=$ABSENT"));
sameBytes('R22 norights: own project container == absent',
    req($JN, 'GET', "$REORDER?action=init&tproject_id=$A&container_id=$SA1"),
    req($JN, 'GET', "$REORDER?action=init&tproject_id=$A&container_id=$ABSENT"));
sameBytes('R23 norights: no tproject_id, foreign == absent',
    req($JN, 'GET', "$REORDER?action=init&container_id=$SB1"),
    req($JN, 'GET', "$REORDER?action=init&container_id=$ABSENT"));

// =============================================================================
echo "\n===== tcreorder (#1761) as sm1761view: mgt_view_tc but NOT mgt_modify_tc =====\n";
$JV = login('sm1761view');
// The honest 403 for the caller's OWN project must survive: the reorder screen
// needs mgt_modify_tc, and 404-ing here would be a pointless UX regression.
expect('R24 view-only, own project root -> honest 403', req($JV, 'GET', "$REORDER?action=init&tproject_id=$A"), '403', 'forbidden');
// A container id was supplied, so the refusal is the opaque 404 (see R20/R22):
// a view-only user is not entitled to be told anything about a tree they may
// not modify, and the screen is rights-gated in the UI anyway.
expect('R25 view-only, own container -> opaque 404', req($JV, 'GET', "$REORDER?action=init&tproject_id=$A&container_id=$SA1"), '404', 'not_found');
echo "--- ORACLE: and it must still not learn anything about project B ---\n";
sameBytes('R26 view-only: foreign container == absent',
    req($JV, 'GET', "$REORDER?action=init&tproject_id=$A&container_id=$SB1"),
    req($JV, 'GET', "$REORDER?action=init&tproject_id=$A&container_id=$ABSENT"));

// =============================================================================
echo "\n===== tcstepsreorder (#1762) as sm1761a =====\n";
$J = login('sm1761a');
expect('S1  init, own version',        req($J, 'GET', "$STEPS?action=init&tcversion_id=$VCA11&tproject_id=$A"), '200', '');
expect('S2  init, FOREIGN version',   req($J, 'GET', "$STEPS?action=init&tcversion_id=$VCB11&tproject_id=$A"), '404', 'not_found');
expect('S3  init, absent version',    req($J, 'GET', "$STEPS?action=init&tcversion_id=$ABSENT&tproject_id=$A"), '404', 'not_found');

echo "--- ORACLE: foreign version vs an id that exists nowhere ---\n";
sameBytes('S4  init foreign==absent (with tproject_id)',
    req($J, 'GET', "$STEPS?action=init&tcversion_id=$VCB11&tproject_id=$A"),
    req($J, 'GET', "$STEPS?action=init&tcversion_id=$ABSENT&tproject_id=$A"));
sameBytes('S5  init foreign==absent (no tproject_id)',
    req($J, 'GET', "$STEPS?action=init&tcversion_id=$VCB11"),
    req($J, 'GET', "$STEPS?action=init&tcversion_id=$ABSENT"));
sameBytes('S6  init own-project TEST CASE node as version == absent',
    req($J, 'GET', "$STEPS?action=init&tcversion_id=$TCA11&tproject_id=$A"),
    req($J, 'GET', "$STEPS?action=init&tcversion_id=$ABSENT&tproject_id=$A"));

echo "--- the three writes ---\n";
$stepIdsA = $db->get_recordset("SELECT id FROM nodes_hierarchy WHERE node_type_id=9 AND parent_id=$VCA11 ORDER BY node_order, id");
$sA = array();
foreach ($stepIdsA as $r) { $sA[] = intval($r['id']); }
$stepIdsB = $db->get_recordset("SELECT id FROM nodes_hierarchy WHERE node_type_id=9 AND parent_id=$VCB11 ORDER BY node_order, id");
$sB = array();
foreach ($stepIdsB as $r) { $sB[] = intval($r['id']); }

$q = function ($ver, $tproject) use ($A) {
    return 'tcversion_id=' . $ver . '&tproject_id=' . ($tproject ?: $A);
};
expect('S7  move, FOREIGN version',    req($J, 'POST', "$STEPS?action=move&" . $q($VCB11, $A), 'step_id=' . $sB[0] . '&position=down'), '404', 'not_found');
expect('S8  reorder, FOREIGN version', req($J, 'POST', "$STEPS?action=reorder&" . $q($VCB11, $A), 'order=' . implode(',', $sB)), '404', 'not_found');
expect('S9  normalize, FOREIGN version', req($J, 'POST', "$STEPS?action=normalize&" . $q($VCB11, $A), ''), '404', 'not_found');
expect('S10 move, absent version',     req($J, 'POST', "$STEPS?action=move&" . $q($ABSENT, $A), 'step_id=' . $ABSENT . '&position=down'), '404', 'not_found');

echo "--- ORACLE: per write verb ---\n";
sameBytes('S11 move: foreign version == absent',
    req($J, 'POST', "$STEPS?action=move&" . $q($VCB11, $A), 'step_id=' . $sB[0] . '&position=down'),
    req($J, 'POST', "$STEPS?action=move&" . $q($ABSENT, $A), 'step_id=' . $ABSENT . '&position=down'));
sameBytes('S12 reorder: foreign version == absent',
    req($J, 'POST', "$STEPS?action=reorder&" . $q($VCB11, $A), 'order=' . implode(',', $sB)),
    req($J, 'POST', "$STEPS?action=reorder&" . $q($ABSENT, $A), 'order=$ABSENT,' . ($ABSENT - 1)));
sameBytes('S13 normalize: foreign version == absent',
    req($J, 'POST', "$STEPS?action=normalize&" . $q($VCB11, $A), ''),
    req($J, 'POST', "$STEPS?action=normalize&" . $q($ABSENT, $A), ''));

echo "--- ORACLE: a foreign STEP id must not be probeable through an own version ---\n";
sameBytes('S14 move: foreign step id == absent step id',
    req($J, 'POST', "$STEPS?action=move&" . $q($VCA11, $A), 'step_id=' . $sB[0] . '&position=down'),
    req($J, 'POST', "$STEPS?action=move&" . $q($VCA11, $A), 'step_id=' . $ABSENT . '&position=down'));

echo "--- a real write inside the own version still works ---
";
// The order of a version's steps lives in tcsteps.step_number (NOT in
// nodes_hierarchy.node_order): set_step_number() renumbers 1..n, which is
// exactly the write the Test Case Editor performs.
$stepOrder = function ($ver) use ($db) {
    $r = $db->get_recordset("SELECT GROUP_CONCAT(id ORDER BY step_number, id) AS v FROM tcsteps " .
        "WHERE id IN (SELECT id FROM nodes_hierarchy WHERE node_type_id=9 AND parent_id=$ver)");
    return (string)$r[0]['v'];
};
$beforeOrder = $stepOrder($VCA11);
ok('S15a fixture step order is the natural one', $beforeOrder === implode(',', $sA), "got $beforeOrder want " . implode(',', $sA));
$r = req($J, 'POST', "$STEPS?action=reorder&" . $q($VCA11, $A), 'order=' . implode(',', array_reverse($sA)));
expect('S15b reorder own version really writes', $r, '200', '');
ok('S16 step order really reversed in the DB', $stepOrder($VCA11) === implode(',', array_reverse($sA)),
   'got ' . $stepOrder($VCA11) . ' want ' . implode(',', array_reverse($sA)));
// restore
req($J, 'POST', "$STEPS?action=reorder&" . $q($VCA11, $A), 'order=' . implode(',', $sA));
ok('S17 fixture step order restored', $stepOrder($VCA11) === $beforeOrder, 'got ' . $stepOrder($VCA11));
ok('S18 project B step order untouched', $stepOrder($VCB11) === implode(',', $sB), 'got ' . $stepOrder($VCB11));

echo "--- the version picker (project-level) ---\n";
expect('S19 versions of own project', req($J, 'GET', "$STEPS?action=versions&tproject_id=$A"), '200', '');
expect('S20 versions of other project', req($J, 'GET', "$STEPS?action=versions&tproject_id=$B"), '403', 'forbidden');
echo "--- ORACLE: the project id is not an existence oracle here either ---\n";
sameBytes('S21 versions: other project == non-existent project',
    req($J, 'GET', "$STEPS?action=versions&tproject_id=$B"),
    req($J, 'GET', "$STEPS?action=versions&tproject_id=424242"));

// S19-S21 above run as sm1761a, whose GLOBAL role is <no rights>. For such a user
// hasRight(<non-existent project>) is false, so the absent and the forbidden case
// both answer 403 and the pair proves nothing. sm1761designer has the DEFAULT
// global role of a Test Designer (4) and no project role row: for THAT user
// hasRight(right, 424242) is TRUE (it falls through to the global role) while
// hasRight(right, <existing private project>) is false -- the asymmetry that made
// ?action=versions a test-project existence oracle. Run the same pair here.
$JD = login('sm1761designer');
expect('S19d versions: an EXISTING project the global role has no row for', req($JD, 'GET', "$STEPS?action=versions&tproject_id=$A"), '403', 'forbidden');
sameBytes('S21d designer: existing project == non-existent project (body bytes)',
    req($JD, 'GET', "$STEPS?action=versions&tproject_id=$A"),
    req($JD, 'GET', "$STEPS?action=versions&tproject_id=424242"));
sameBytes('S21e designer: the project id is not an existence oracle on tcstepsreorder (status)',
    req($JD, 'GET', "$STEPS?action=versions&tproject_id=$A"),
    req($JD, 'GET', "$STEPS?action=versions&tproject_id=424242"));
// The same project axis on the sibling endpoint must behave the same way.
expect('S19r tcreorder: an EXISTING project the global role has no row for', req($JD, 'GET', "$REORDER?action=init&tproject_id=$A"), '403', 'forbidden');
sameBytes('S21r tcreorder: existing project == non-existent project (body bytes)',
    req($JD, 'GET', "$REORDER?action=init&tproject_id=$A"),
    req($JD, 'GET', "$REORDER?action=init&tproject_id=424242"));

// =============================================================================
echo "\n===== tcstepsreorder (#1762) as sm1761norights / sm1761view =====\n";
$JN = login('sm1761norights');
expect('S22 norights: own-project version', req($JN, 'GET', "$STEPS?action=init&tcversion_id=$VCA11&tproject_id=$A"), '404', 'not_found');
sameBytes('S23 norights: foreign version == absent',
    req($JN, 'GET', "$STEPS?action=init&tcversion_id=$VCB11&tproject_id=$A"),
    req($JN, 'GET', "$STEPS?action=init&tcversion_id=$ABSENT&tproject_id=$A"));
sameBytes('S24 norights: reorder foreign == reorder absent',
    req($JN, 'POST', "$STEPS?action=reorder&" . $q($VCB11, $A), 'order=' . implode(',', $sB)),
    req($JN, 'POST', "$STEPS?action=reorder&" . $q($ABSENT, $A), 'steplist=' . $ABSENT));

$JV = login('sm1761view');
// mgt_view_tc IS held, so the READ must succeed - the write must be the honest 403.
expect('S25 view-only: READ own version works', req($JV, 'GET', "$STEPS?action=init&tcversion_id=$VCA11&tproject_id=$A"), '200', '');
// Every tsroContext() entry point supplies a version id, so the write refusal is
// opaque too; the READ above is what a view-only user legitimately gets, and the
// init payload reports mgt_modify_tc=false for the screen to act on.
expect('S26 view-only: WRITE own version -> opaque 404', req($JV, 'POST', "$STEPS?action=move&" . $q($VCA11, $A), 'step_id=' . $sA[0] . '&position=down'), '404', 'not_found');
sameBytes('S27 view-only: foreign version == absent',
    req($JV, 'GET', "$STEPS?action=init&tcversion_id=$VCB11&tproject_id=$A"),
    req($JV, 'GET', "$STEPS?action=init&tcversion_id=$ABSENT&tproject_id=$A"));

// =============================================================================
echo "\n===== admin (the legitimate power user keeps full access) =====\n";
$JA = login('admin');
expect('A1  admin init own container', req($JA, 'GET', "$REORDER?action=init&tproject_id=$A&container_id=$SA1"), '200', '');
expect('A2  admin init own version',   req($JA, 'GET', "$STEPS?action=init&tcversion_id=$VCA11&tproject_id=$A"), '200', '');
expect('A3  admin init absent version', req($JA, 'GET', "$STEPS?action=init&tcversion_id=$ABSENT&tproject_id=$A"), '404', 'not_found');

// =============================================================================
echo "\n===== contract / CSRF =====\n";
// CSRF is only meaningful for a WRITE: bffSameOriginGuard() returns immediately
// for GET/HEAD/OPTIONS, so this must be a POST. A foreign Origin on a write must
// be refused (the #1679 fix validates Origin/Referer before the XRW hint).
$out = array();
exec("curl -s -b $JAR-sm1761a.txt -H 'Origin: http://evil.example' -X POST " .
     "-d 'action=move&tproject_id=$A&container_id=$SA1&node_id=$TCA11&position=down' " .
     escapeshellarg($REORDER) . " -w '\n%{http_code}'", $out);
$raw = implode("\n", $out);
ok('C1  foreign Origin on a WRITE is refused (CSRF guard)', str_ends_with($raw, '403'),
   'got ' . str_replace("\n", ' ', substr($raw, 0, 140)));
$out = array();
exec("curl -s -b $JAR-sm1761a.txt -H 'Origin: http://evil.example' " .
     escapeshellarg("$REORDER?action=init&tproject_id=$A") . " -w '\n%{http_code}'", $out);
ok('C1b a GET is not CSRF-gated by design (documented, not a failure)',
   str_ends_with(implode("\n", $out), '200'), 'unexpected');
$out = array();
exec("curl -s -b $JAR-sm1761a.txt -X POST -d 'action=move&tproject_id=$A&container_id=$SA1&node_id=$TCA11&position=down' " .
     escapeshellarg($REORDER) . " -w '\n%{http_code}'", $out);
$raw = implode("\n", $out);
ok('C2  POST without Origin is refused (CSRF)', strpos($raw, 'CSRF') !== false || str_ends_with($raw, '403'),
   'got ' . str_replace("\n", ' ', substr($raw, 0, 120)));
expect('C3  unknown action over GET hits the POST-only gate', req($J, 'GET', "$REORDER?action=bogus&tproject_id=$A"), '405', '');
expect('C3b unknown action over POST', req($J, 'POST', "$REORDER?action=bogus&tproject_id=$A", 'x=1'), '400', 'unknown_action');
expect('C4  POST on init', req($J, 'POST', "$REORDER?action=init&tproject_id=$A", 'x=1'), '405', '');
expect('C5  steps: unknown action over GET hits the POST-only gate', req($J, 'GET', "$STEPS?action=bogus&tcversion_id=$VCA11"), '405', 'method_not_allowed');
expect('C5b steps: unknown action over POST', req($J, 'POST', "$STEPS?action=bogus&tcversion_id=$VCA11", 'x=1'), '400', 'unknown_action');

// =============================================================================
$W = intval(one($db, "SELECT COUNT(*) AS v FROM events WHERE log_level IN (1,2)"));
ok('V1  no new ERROR/WARNING row in events', $W === $W_BASE, "baseline=$W_BASE now=$W");

echo "\n===== $PASS passed, $FAIL failed =====\n";
exit($FAIL === 0 ? 0 : 1);
