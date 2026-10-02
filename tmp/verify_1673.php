<?php
/**
 * Regression matrix for Issue #1673 — lib/ajax/stepReorder.php.
 *
 * The 1.9.20 file renumbered tcsteps.step_number for ANY authenticated caller
 * (its own comment said "// No authorization checks"), read $_REQUEST so a plain
 * GET mutated state (CSRF-able from an <img src>), and interpolated the caller
 * supplied step ids straight into the UPDATE built by
 * testcase::set_step_number() — never intval()'d, never escaped.
 *
 * The endpoint is now a retired shim (commit 1ee7c403c). This suite pins the
 * retirement: nothing may write through it again, from any verb, with or without
 * rights, and the replacement BFF must keep refusing the same callers.
 *
 * Run from repo root:  php tmp/verify_1673.php
 * Requires: app on http://localhost:8082, fixture tmp/fixtures_1761.php loaded
 * (projects OR1761A / OR1761B, users sm1761a / sm1761view / sm1761norights).
 * Cookie jars live in tmp/ (never /tmp).
 *
 * Load-bearing cases:
 *   L*  the legacy verb matrix — GET redirects, every write verb is 405;
 *   M*  the mutation really is impossible (tcsteps.step_number byte-unchanged
 *       after a hostile request, verified in the DB, not in the HTTP answer);
 *   E*  the Event Viewer stays clean.
 */
require_once('config.inc.php');
require_once('common.php');

$BASE = 'http://localhost:8082';
$LEGACY = $BASE . '/lib/ajax/stepReorder.php';
$STEPS = $BASE . '/api/tcstepsreorder/index.php';
$JAR = 'tmp/ck1673';
$PASS = 0;
$FAIL = 0;
$ABSENT = 999999;

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
$TCA11 = one($db, "SELECT id AS v FROM nodes_hierarchy WHERE node_type_id=3 AND name='A-tc-1-1'");
$VCA11 = one($db, "SELECT id AS v FROM nodes_hierarchy WHERE node_type_id=4 AND parent_id=$TCA11 ORDER BY id LIMIT 1");
// Step nodes (type 9) of that version, in their stored order.
$steps = array();
$r = $db->get_recordset("SELECT id FROM nodes_hierarchy WHERE node_type_id=9 AND parent_id=$VCA11 ORDER BY node_order, id");
foreach ($r as $row) {
    $steps[] = intval($row['id']);
}
if (count($steps) < 2) {
    die("fixture needs >=2 steps on version $VCA11, found " . count($steps) . "\n");
}

echo "fixture: A=$A B=$B VCA11=$VCA11 steps=" . implode(',', $steps) . "\n\n";

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

/** Returns array(status, rawBody, headers). */
function req($jar, $method, $url, $data = null, $extraHdr = '')
{
    $cmd = "curl -s -b " . escapeshellarg($jar) . " -H 'Origin: http://localhost:8082' " .
           "-X $method -D - -o - -w '\n%{http_code}'";
    if ($data !== null) {
        $cmd .= ' -d ' . escapeshellarg($data);
    }
    if ($extraHdr !== '') {
        $cmd .= ' ' . $extraHdr;
    }
    $cmd .= ' ' . escapeshellarg($url);
    $out = array();
    exec($cmd, $out);
    $raw = implode("\n", $out);
    $pos = strrpos($raw, "\n");
    $status = trim(substr($raw, $pos + 1));
    $head = substr($raw, 0, $pos);
    $hpos = strpos($head, "\n\n");
    $headers = ($hpos === false) ? $head : substr($head, 0, $hpos);
    $body = ($hpos === false) ? '' : substr($head, $hpos + 2);
    return array($status, $body, $headers);
}

function code($body)
{
    $j = json_decode($body, true);
    return is_array($j) ? (string)($j['code'] ?? '') : '(not json)';
}

/** The single Location: value, or '' when the answer carried none. */
function loc($headers)
{
    if (preg_match('#^Location:\s*(.+)$#mi', $headers, $m)) {
        return trim($m[1]);
    }
    return '';
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
       "got [" . $r[0] . ' ' . code($r[1]) . "] want [$xs $xc] body=" . substr($r[1], 0, 200));
}

/** The step_number rows of a version, as "id=number" — compared byte-wise. */
function stepNumbers($db, $versionId)
{
    $T = tlObjectWithDB::getDBTables(array('tcsteps'));
    $r = $db->get_recordset(
        "SELECT TS.id AS sid, TS.step_number FROM {$T['tcsteps']} TS" .
        " JOIN nodes_hierarchy NH ON NH.id = TS.id" .
        " WHERE NH.parent_id = " . intval($versionId) .
        " ORDER BY TS.id");
    $out = array();
    foreach ($r as $row) {
        $out[] = intval($row['sid']) . '=' . intval($row['step_number']);
    }
    return implode(',', $out);
}

// =============================================================================
// Baseline: the stored order BEFORE any hostile request.
$before = stepNumbers($db, $VCA11);
$errBefore = intval(one($db, "SELECT COUNT(*) AS v FROM events WHERE log_level = 1"));
// Watermark, not a count: rows already in `events` from earlier runs must not be
// re-counted as "new" on the next execution of this suite.
$eventsMark = intval(one($db, "SELECT COALESCE(MAX(id), 0) AS v FROM events"));
echo "step_number before: $before\nevents ERROR before: $errBefore   events id watermark: $eventsMark\n\n";

// =============================================================================
echo "===== L1-L6  legacy shim, verb matrix, as admin =====\n";
$J = login('admin');

$r = req($J, 'GET', "$LEGACY?tproject_id=$A&tcversion_id=$VCA11");
ok('L1 authenticated GET answers 302', $r[0] === '302', "got {$r[0]}");
ok('L1b Location points at the modern screen',
   strpos(loc($r[2]), '/gui/templates/testcases/tcStepReorder.html') === 0,
   'Location=' . loc($r[2]));

// A legacy bookmark carried stepSeq & no version id; the modern screen then
// offers its own picker, so tproject_id alone must still be forwarded.
$r = req($J, 'GET', "$LEGACY?tproject_id=$A");
ok('L2 legacy bookmark (stepSeq only) -> 302 to the modern screen',
   $r[0] === '302' && strpos(loc($r[2]), 'tproject_id=' . $A) !== false,
   "{$r[0]} Location=" . loc($r[2]));

// stepSeq must NOT be reflected into the redirect: it is caller-supplied input
// and the mutation it described is deliberately not replayed.
$r = req($J, 'GET', "$LEGACY?tproject_id=$A&tcversion_id=$VCA11&stepSeq=" . implode('%26', array_reverse($steps)));
ok('L3 stepSeq is NOT forwarded to the modern screen (no replay)',
   stripos(loc($r[2]), 'stepSeq') === false, 'Location=' . loc($r[2]));

foreach (array('POST', 'PUT', 'PATCH', 'DELETE') as $verb) {
    $r = req($J, $verb, "$LEGACY?tproject_id=$A&tcversion_id=$VCA11&stepSeq=" . implode('%26', $steps));
    expect("L4 $verb on the legacy endpoint is refused", $r, '405', 'method_not_allowed');
}

$r = req($J, 'POST', "$LEGACY?tproject_id=$A&tcversion_id=$VCA11&stepSeq=" . implode('%26', $steps),
         '{"tproject_id":' . $A . ',"stepSeq":"' . implode('&', $steps) . '"}',
         "-H 'X-Requested-With: XMLHttpRequest' -H 'Content-Type: application/json'");
expect('L5 POST with the exact CSRF headers the legacy caller used is refused', $r, '405', 'method_not_allowed');

$r = req($J, 'HEAD', "$LEGACY?tproject_id=$A");
ok('L6 HEAD is treated as a read (302, no 405)', $r[0] === '302', "got {$r[0]}");

// =============================================================================
echo "\n===== L7-L9  legacy shim, anonymous =====\n";
$anon = "$JAR-anon.txt";
@unlink($anon);
$r = req($anon, 'GET', "$LEGACY?tproject_id=$A&tcversion_id=$VCA11");
ok('L7 anonymous GET does NOT 302 to the modern screen',
   $r[0] !== '302', "got {$r[0]}");
ok('L7b anonymous GET is bounced to login (session check)',
   strpos($r[1], 'login.php?note=expired') !== false, 'body=' . substr($r[1], 0, 200));

// KNOWN ORDERING CONSEQUENCE, measured not assumed: checkSessionValid() runs
// BEFORE the 405 gate, so an anonymous caller is bounced with the legacy 200 +
// JS login page instead of the shim's JSON 405. It still writes nothing; the
// JSON contract is only observable for an authenticated caller.
$r = req($anon, 'POST', "$LEGACY?stepSeq=" . implode('%26', $steps));
ok('L8 anonymous POST is bounced to login and never reaches a 405 or a write',
   strpos($r[1], 'login.php?note=expired') !== false && stripos($r[1], '"') === false,
   "{$r[0]} body=" . substr($r[1], 0, 160));

$r = req($anon, 'GET', "$LEGACY?stepSeq=" . implode('%26', $steps));
ok('L9 anonymous GET carrying stepSeq never reaches a 302 / 200 ok',
   $r[0] !== '302' && strpos($r[1], '"1":1') === false,
   "{$r[0]} body=" . substr($r[1], 0, 200));

// =============================================================================
echo "\n===== L10-L12  legacy shim, a caller with NO rights =====\n";
$JN = login('sm1761norights');

$r = req($JN, 'GET', "$LEGACY?tproject_id=$A&tcversion_id=$VCA11");
ok('L10 <no rights> GET does not reach the write handler (302 is the redirect only)',
   $r[0] === '302' && stripos(loc($r[2]), 'stepSeq') === false, "{$r[0]} Location=" . loc($r[2]));

$r = req($JN, 'POST', "$LEGACY?tproject_id=$A&tcversion_id=$VCA11&stepSeq=" . implode('%26', $steps));
expect('L11 <no rights> POST is refused', $r, '405', 'method_not_allowed');

// The exact pre-fix attack: reverse the ids on a plain GET, as the original
// report's repro step 2 does. Pre-fix this executed
// "UPDATE tcsteps SET step_number = 1 WHERE id = ...".
$r = req($JN, 'GET', "$LEGACY?tproject_id=$A&tcase_id=$TCA11&tcversion_id=$VCA11&stepSeq=" .
         implode('%26', array_reverse($steps)));
ok('L12 the pre-fix attack answers no JSON renumbering map',
   strpos($r[1], '"' . $steps[count($steps) - 1] . '":1') === false,
   'body=' . substr($r[1], 0, 200));

// =============================================================================
echo "\n===== L13-L15  SQL-injection sink is gone =====\n";
// The legacy handler interpolated $_REQUEST["stepSeq"] verbatim into
// "WHERE id = {$step_id}" (testcase.class.php:6065). Probe the payload the
// interpolation would have accepted and require the shim to refuse it outright.
$inj = "0)%20OR%20(1=1";
$r = req($J, 'GET', "$LEGACY?stepSeq=" . $inj);
ok('L13 injected stepSeq cannot reach the modern screen',
   // The word "or" occurs inside "tcStepReorder", so the payload is matched
   // verbatim instead: the Location must carry neither the parameter name nor
   // any part of the injected value.
   stripos(loc($r[2]), 'stepSeq') === false &&
   stripos(loc($r[2]), '1=1') === false &&
   stripos(loc($r[2]), urlencode('1=1')) === false,
   'Location=' . loc($r[2]));
$r = req($J, 'POST', "$LEGACY?stepSeq=" . $inj);
expect('L14 injected stepSeq over POST is refused', $r, '405', 'method_not_allowed');

$r = req($J, 'GET', "$LEGACY?tproject_id=" . urlencode($inj));
ok('L15 injected tproject_id is dropped, not reflected',
   strpos(loc($r[2]), 'tproject_id') === false, 'Location=' . loc($r[2]));

// =============================================================================
echo "\n===== M1  the mutation is impossible: verified in the DB, not the answer =====\n";
// The benign order goes first and the REVERSING request goes LAST, on purpose: a
// working attack leaves 8=1,7=2,6=3 in tcsteps.step_number, so the check cannot be
// satisfied by the trailing benign write. Asserted on the stored rows, because an
// HTTP answer alone would happily report {"8":1,"7":2,"6":3} while nothing is written.
$benign = implode('%26', $steps);
$attack = implode('%26', array_reverse($steps));
foreach (array($J, $JN, $anon) as $jar) {
    req($jar, 'POST', "$LEGACY?tproject_id=$A&tcversion_id=$VCA11&stepSeq=$benign");
    req($jar, 'GET',  "$LEGACY?tproject_id=$A&tcversion_id=$VCA11&stepSeq=$attack");
    req($jar, 'POST', "$LEGACY?tproject_id=$A&tcversion_id=$VCA11&stepSeq=$attack");
}
$after = stepNumbers($db, $VCA11);
ok("M1 tcsteps.step_number unchanged after every hostile request",
   $after === $before, "before=[$before] after=[$after]");

// Restore the fixture order for the next run (a passing run is already a no-op).
req($J, 'POST', "$STEPS?action=reorder",
    '{"tcversion_id":' . $VCA11 . ',"order":[' . implode(',', $steps) . ']}');

echo "\n===== B1-B6  replacement BFF keeps refusing the same callers =====\n";
expect('B1 admin reads its own version',      req($J, 'GET', "$STEPS?action=init&tcversion_id=$VCA11"), '200', '');
// Per #1762 a caller-supplied id that the caller may not use answers the SAME
// opaque 404 as an id that exists nowhere - NOT the 403 the issue body claimed.
$rNor = req($JN, 'GET', "$STEPS?action=init&tcversion_id=$VCA11");
$rAbs = req($JN, 'GET', "$STEPS?action=init&tcversion_id=$ABSENT");
expect('B2 <no rights> init on a real version -> opaque 404', $rNor, '404', 'not_found');
ok('B3 <no rights> real version == absent version, byte-identical',
   $rNor[0] === $rAbs[0] && $rNor[1] === $rAbs[1],
   "\n      real: {$rNor[1]}\n      absent: {$rAbs[1]}");

$wNor = req($JN, 'POST', "$STEPS?action=move",
            '{"tcversion_id":' . $VCA11 . ',"direction":"up","index":0}');
expect('B4 <no rights> write on a real version -> opaque 404', $wNor, '404', 'not_found');

$r = req($anon, 'POST', "$STEPS?action=move", '{}',
         "-H 'X-Requested-With: XMLHttpRequest'");
ok('B5 anonymous POST past the CSRF gate -> 401 session_expired',
   $r[0] === '401' && code($r[1]) === 'session_expired', "got {$r[0]} " . code($r[1]));

$r = req($J, 'POST', "$STEPS?action=move", '{}',
         "-H 'Origin: http://evil.example' -H 'X-Requested-With: XMLHttpRequest'");
ok('B6 cross-origin POST with a forged XRW hint is refused (403)',
   $r[0] === '403', "got {$r[0]}");

// =============================================================================
echo "\n===== E1  Event Viewer =====\n";
$errAfter = intval(one($db, "SELECT COUNT(*) AS v FROM events WHERE log_level = 1"));
ok("E1 no new ERROR row in events ($errBefore -> $errAfter)",
   $errAfter === $errBefore, "delta=" . ($errAfter - $errBefore));

// The shim logs one WARNING per REFUSED write verb on purpose (the Event Viewer
// trail for "something tried to use the retired endpoint"). Every WARNING added
// by this suite must carry that source; anything else is a real regression.
$warnRows = $db->get_recordset(
    "SELECT description FROM events WHERE log_level = 2 AND id > " . $eventsMark);
$stray = array();
$newWarn = 0;
foreach ($warnRows as $w) {
    $newWarn++;
    if (strpos((string)$w['description'], 'retired legacy step-reorder') === false) {
        $stray[] = substr((string)$w['description'], 0, 120);
    }
}
ok("E2 every one of the $newWarn new WARNING rows is the deliberate 405 refusal trail",
   $newWarn > 0 && count($stray) === 0, 'stray=' . implode(' | ', $stray));

echo "\n===== $PASS passed, $FAIL failed =====\n";
exit($FAIL > 0 ? 1 : 0);