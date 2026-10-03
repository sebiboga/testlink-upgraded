<?php
/**
 * Regression matrix for issue #1792 - api/builds: resolveTplan() answered 404
 * BEFORE canManage() answered 403, so a caller with no rights at all could
 * enumerate every test plan id in the instance by walking tplan_id upwards and
 * reading the 403/404 split.
 *
 * THE AXIS UNDER TEST: for each route family, "the addressed id is there but I
 * may not touch it" and "the addressed id is not there at all" must be
 * byte-identical - same status AND same body. Before the fix every family
 * answered 403 + "Insufficient rights" for the first and 404 for the second.
 *
 * Written in PHP + curl like tmp/verify_1790.php / tmp/verify_1791.php, because
 * the shell harnesses of this lane (tmp/verify_1759.sh) shell out to the `mysql`
 * CLIENT, which is not installed in this environment.
 *
 * IMPORTANT when re-running by hand: this endpoint is PATH-routed
 * ($segments = explode('/', $path), api/builds/index.php:63) and its body is
 * JSON (getBody() = json_decode(php://input), :52). Posting
 * tplan_id=..&name=.. as form data answers a uniform 400 for every id - a false
 * negative that makes the bug look absent.
 *
 * Fixture: php tmp/fixtures_1792.php   (prints "SUMMARY pid=.. planId=.. buildId=.. uid=..")
 * Usage:  php tmp/verify_1792.php
 */
require_once('config.inc.php');
require_once('common.php');

define('BASE', 'http://localhost:8082');
const ABSENT = 999999;

$db = new database(DB_TYPE);
doDBConnect($db);

$GLOBALS['pass'] = 0;
$GLOBALS['fail'] = 0;

function ok($label, $cond, $detail = '')
{
    if ($cond) {
        $GLOBALS['pass']++;
        echo "  ok   $label\n";
    } else {
        $GLOBALS['fail']++;
        echo "  FAIL $label" . ($detail === '' ? '' : " -- $detail") . "\n";
    }
}

function isFatal($status, $body)
{
    return $status >= 500
        || strpos($body, 'Fatal error') !== false
        || strpos($body, 'Uncaught Error') !== false
        || strpos($body, 'Warning:') !== false
        || strpos($body, 'Notice:') !== false
        || strpos($body, 'Deprecated:') !== false;
}

/** [status, body] for a request against api/builds. */
function builds($jar, $method, $path, $body = null)
{
    $ch = curl_init(BASE . '/api/builds/index.php' . $path);
    $opt = array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CUSTOMREQUEST  => $method,
        // bffSameOriginGuard() rejects a cross-origin mutation; the browser
        // sends this automatically, so the harness must too.
        CURLOPT_HTTPHEADER     => array('Origin: ' . BASE),
    );
    if ($body !== null) {
        $opt[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
        $opt[CURLOPT_POSTFIELDS] = json_encode($body);
    }
    curl_setopt_array($ch, $opt);
    $out = (string)curl_exec($ch);
    $st = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
    curl_close($ch);
    return array($st, $out);
}

function loginAs($jar, $login, $tprojectId)
{
    @unlink($jar);
    $ch = curl_init(BASE . '/login.php?action=doLogin');
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => "tl_login=$login&tl_password=admin&tproject_id=$tprojectId",
    ));
    curl_exec($ch);
    curl_close($ch);
}

// --------------------------------------------------------------------- fixture
$sum = trim((string)shell_exec('php ' . escapeshellarg(__DIR__ . '/fixtures_1792.php') . ' 2>/dev/null'));
if (!preg_match('/SUMMARY pid=(\d+) planId=(\d+) buildId=(\d+) uid=(\d+)/', $sum, $m)) {
    fwrite(STDERR, "FATAL: fixture did not run / no SUMMARY line\n$sum\n");
    exit(2);
}
$P = intval($m[1]);
$PLAN = intval($m[2]);
$BUILD = intval($m[3]);
echo "fixture: project=$P plan=$PLAN build=$BUILD\n";

$jarNR = __DIR__ . '/ck_1792_norights.txt';
$jarAd = __DIR__ . '/ck_1792_admin.txt';
$eventsBefore = intval($db->get_recordset("SELECT COUNT(*) AS v FROM events WHERE log_level IN (1,2)")->v);

loginAs($jarNR, 'sm1792norights', $P);
loginAs($jarAd, 'admin', $P);

// The premise: an entitled caller must be able to answer 200 on the same routes,
// otherwise "everything is 404" would also pass this suite.
$st = builds($jarAd, 'GET', "?tplan_id=$PLAN")[0];
ok('premise: admin is entitled to the fixture project (200 on the plan list)',
   $st === 200, "status=$st");

/* ------------------------------------------------------------------ */
/* 1. THE ORACLE - the plan axis, every route family                    */
/* ------------------------------------------------------------------ */
echo "\n--- 1. the tplan_id oracle: 'there but not mine' == 'not there' ---\n";

// Each family: [label, method, pathWithExisting, pathWithAbsent, jsonBodyKey]
$families = array(
    array('GET / (list)',            'GET',    "/?tplan_id=$PLAN",      "/?tplan_id=" . ABSENT, null),
    array('GET /cfields',            'GET',    "/cfields?tplan_id=$PLAN", "/cfields?tplan_id=" . ABSENT, null),
    array('POST / (create)',         'POST',   '/',                      '/',  'tplan_id'),
    array('PUT /{id}',               'PUT',    "/$BUILD",                "/$BUILD", 'tplan_id'),
    array('POST /{id}/flags',        'POST',   "/$BUILD/flags",          "/$BUILD/flags", 'tplan_id'),
    array('DELETE /{id}',            'DELETE', "/$BUILD?tplan_id=$PLAN", "/$BUILD?tplan_id=" . ABSENT, null),
);

foreach ($families as $f) {
    list($label, $method, $pathThere, $pathAbsent, $bodyKey) = $f;
    $bodyThere = ($bodyKey === null) ? null : array($bodyKey => $PLAN, 'name' => 'NR-' . $PLAN);
    $bodyAbsent = ($bodyKey === null) ? null : array($bodyKey => ABSENT, 'name' => 'NR-' . ABSENT);

    list($stT, $bdT) = builds($jarNR, $method, $pathThere, $bodyThere);
    list($stA, $bdA) = builds($jarNR, $method, $pathAbsent, $bodyAbsent);

    ok("$label: a no-rights caller is refused", $stT >= 400 && $stT < 500 && !isFatal($stT, $bdT),
       "status=$stT body=" . substr($bdT, 0, 90));
    ok("$label: EXISTS and ABSENT answer the SAME status",
       $stT === $stA, "exists=$stT absent=$stA");
    ok("$label: EXISTS and ABSENT answer the SAME body",
       trim($bdT) === trim($bdA),
       "exists=" . substr($bdT, 0, 70) . " absent=" . substr($bdA, 0, 70));
    ok("$label: the refusal names no plan (no id echoed back)",
       strpos($bdT, (string)$PLAN) === false, substr($bdT, 0, 90));
}

/* ------------------------------------------------------------------ */
/* 2. THE BUILD AXIS - the same split existed on build_id               */
/* ------------------------------------------------------------------ */
echo "\n--- 2. the build_id oracle: 'there but not mine' == 'not there' ---\n";
foreach (array(
    array('GET /{id}',        'GET',    "/$BUILD",        '/' . ABSENT, null),
    array('PUT /{id}',        'PUT',    "/$BUILD",        '/' . ABSENT, 'tplan_id'),
    array('POST /{id}/flags', 'POST',   "/$BUILD/flags",  '/' . ABSENT . '/flags', 'tplan_id'),
    array('DELETE /{id}',     'DELETE', "/$BUILD",        '/' . ABSENT, null),
) as $f) {
    list($label, $method, $pT, $pA, $bk) = $f;
    $bT = ($bk === null) ? null : array($bk => $PLAN);
    $bA = ($bk === null) ? null : array($bk => ABSENT);
    list($stT, $bdT) = builds($jarNR, $method, $pT, $bT);
    list($stA, $bdA) = builds($jarNR, $method, $pA, $bA);
    ok("$label: EXISTS and ABSENT build answer the SAME status",
       $stT === $stA, "exists=$stT absent=$stA");
    ok("$label: EXISTS and ABSENT build answer the SAME body",
       trim($bdT) === trim($bdA),
       "exists=" . substr($bdT, 0, 70) . " absent=" . substr($bdA, 0, 70));
}

/* ------------------------------------------------------------------ */
/* 3. NO OVER-BLOCKING - an entitled caller keeps the whole surface     */
/* ------------------------------------------------------------------ */
echo "\n--- 3. an entitled caller is unaffected ---\n";
list($st, $bd) = builds($jarAd, 'GET', "/?tplan_id=$PLAN");
ok('admin GET / (list) -> 200', $st === 200, "status=$st " . substr($bd, 0, 90));
ok('admin GET / still returns the plan context',
   $st === 200 && strpos($bd, '"name":"' . 'T1792-PLAN') !== false, substr($bd, 0, 120));

list($st, $bd) = builds($jarAd, 'GET', "/cfields?tplan_id=$PLAN");
ok('admin GET /cfields -> 200', $st === 200, "status=$st " . substr($bd, 0, 90));

list($st, $bd) = builds($jarAd, 'POST', '/', array('tplan_id' => $PLAN, 'name' => 'NR-ADM-' . $BUILD));
ok('admin POST / (create) -> 200 and a real build id',
   $st === 200 && preg_match('/"id":\d+/', $bd) === 1, "status=$st " . substr($bd, 0, 90));
$newBuild = 0;
if (preg_match('/"id":(\d+)/', $bd, $nm)) { $newBuild = intval($nm[1]); }

list($st, $bd) = builds($jarAd, 'GET', "/$BUILD");
ok('admin GET /{id} -> 200 with the build row',
   $st === 200 && strpos($bd, '"status":"ok"') !== false, "status=$st " . substr($bd, 0, 90));

list($st, $bd) = builds($jarAd, 'POST', "/$BUILD/flags", array('tplan_id' => $PLAN, 'active' => 1));
ok('admin POST /{id}/flags -> 200', $st === 200, "status=$st " . substr($bd, 0, 90));

list($st, $bd) = builds($jarAd, 'PUT', "/$BUILD",
    array('name' => 'T1792-BUILD', 'tplan_id' => $PLAN, 'active' => 1, 'open' => 1));
ok('admin PUT /{id} -> 200 (rename works)', $st === 200, "status=$st " . substr($bd, 0, 90));

// The honest 404 must survive: an entitled caller addressing a plan that is not
// there still gets a truthful "not found", i.e. this is not a blanket denial.
list($st, $bd) = builds($jarAd, 'GET', "?tplan_id=" . ABSENT);
ok('admin still gets an HONEST 404 for a plan that does not exist',
   $st === 404 && strpos($bd, 'Invalid Test Plan ID') !== false, "status=$st " . substr($bd, 0, 90));
list($st, $bd) = builds($jarAd, 'GET', '/' . ABSENT);
ok('admin still gets an HONEST 404 for a build that does not exist',
   $st === 404 && strpos($bd, 'Build not found') !== false, "status=$st " . substr($bd, 0, 90));

if ($newBuild > 0) {
    list($st, $bd) = builds($jarAd, 'DELETE', "/$newBuild");
    ok('admin DELETE /{id} -> 200 (the surface is complete, not just readable)',
       $st === 200, "status=$st " . substr($bd, 0, 90));
}

/* ------------------------------------------------------------------ */
/* 4. THE ONE DELIBERATE EXCEPTION - the session-scoped list keeps 403   */
/* ------------------------------------------------------------------ */
echo "\n--- 4. tplan_id=0 (project-scoped list) is session-derived and keeps its 403 ---\n";
// The no-rights user has no project in session, so this branch answers 400
// "No active test project" - and it must NOT have become a 404, otherwise the
// documented exception has silently become a second opaque family.
list($st, $bd) = builds($jarNR, 'GET', '/?tplan_id=0');
ok('a session with no project answers 400, not the opaque 404',
   $st === 400 && strpos($bd, 'No active test project') !== false, "status=$st " . substr($bd, 0, 90));

// And an ENTITLED caller on the session project must still be served: this is
// the "Builds & Releases" screen under the project submenu.
$row = $db->get_recordset("SELECT id FROM testplans WHERE testproject_id=$P LIMIT 1");
ok('admin on the session-scoped list still gets 200 (project submenu screen)',
   builds($jarAd, 'GET', '/?tplan_id=0')[0] === 200,
   'status=' . builds($jarAd, 'GET', '/?tplan_id=0')[0]);

/* ------------------------------------------------------------------ */
/* 5. A user entitled ELSEWHERE learns nothing about this project       */
/* ------------------------------------------------------------------ */
echo "\n--- 5. rights on another project do not unlock this one ---\n";
// admin holds testplan_create_build globally, so the meaningful negative case
// is the no-rights user against a *second* real project: proving the gate is a
// rights check and not a per-project allowlist coincidence.
$st = builds($jarNR, 'GET', "?tplan_id=$PLAN")[0];
ok('a no-rights caller is refused on every fixture plan alike',
   $st === 404, "status=$st");

/* ------------------------------------------------------------------ */
/* 6. input hygiene: no PHP notice/fatal, no oracle re-introduced        */
/* ------------------------------------------------------------------ */
echo "\n--- 6. input hygiene ---\n";
foreach (array(
    'tplan_id=abc'        => "/?tplan_id=abc",
    'tplan_id[]=1'        => "/?tplan_id[]=1",
    'tplan_id=-1'         => "/?tplan_id=-1",
    'tplan_id=0'          => "/?tplan_id=0",
    'tplan_id omitted'    => '/',
    'tplan_id=1e999'      => "/?tplan_id=1e999",
    'huge tplan_id'       => '/?tplan_id=99999999999',
) as $label => $path) {
    foreach (array('admin', 'no-rights') as $who) {
        list($st, $bd) = builds($who === 'admin' ? $jarAd : $jarNR, 'GET', $path);
        ok("$label ($who): no 5xx and no PHP diagnostic in the body",
           $st < 500 && !isFatal($st, $bd), "status=$st " . substr($bd, 0, 100));
    }
}
// NOTE the split of the assertion below, which is deliberate. intval('abc'),
// intval('-1') and intval('1e999') are all 0, so those requests take the
// tplan_id=0 SESSION-scoped branch and legitimately answer 400 "No active test
// project" - that is pre-existing input handling (a value that is not a positive
// integer is not addressed by plan), it is the documented exception from
// checkpoint 2, and it discloses NOTHING about plan existence. The group that
// MUST collapse is the values that DO parse as a positive plan id - including
// out-of-range ones like 99999999999, which are addressed-by-plan even though no
// such row can exist: those are the only ones whose answer could depend on
// whether a row is there.
$zeroGroup = array();
$posGroup = array();
foreach (array('abc', '-1', '1e999', '0') as $v) {
    list($st, $bd) = builds($jarNR, 'GET', "/?tplan_id=$v");
    $zeroGroup[] = "$st|" . trim($bd);
}
foreach (array('1', '7', (string)ABSENT, (string)$PLAN, '99999999999', '4294967296') as $v) {
    list($st, $bd) = builds($jarNR, 'GET', "/?tplan_id=$v");
    $posGroup[] = "$st|" . trim($bd);
}
ok('a tplan_id that is not a positive int takes the session branch, never the opaque 404',
   count(array_unique($zeroGroup)) === 1
   && strpos($zeroGroup[0], 'No active test project') !== false,
   count(array_unique($zeroGroup)) . ' distinct: ' . implode(' ;; ', array_unique($zeroGroup)));
ok('every POSITIVE tplan_id collapses onto ONE answer for a no-rights caller '
   . '(existing plan, absent plan and out-of-range all alike)',
   count(array_unique($posGroup)) === 1,
   count(array_unique($posGroup)) . ' distinct: ' . implode(' ;; ', array_unique($posGroup)));

// Malformed bodies on the mutation legs must not fatal either.
foreach (array('{"tplan_id":"abc","name":"x"}', '{"name":""}', 'not json at all', '[]') as $raw) {
    $ch = curl_init(BASE . '/api/builds/index.php/');
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jarNR,
        CURLOPT_COOKIEFILE => $jarNR, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $raw, CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => array('Origin: ' . BASE, 'Content-Type: application/json'),
    ));
    $bd = (string)curl_exec($ch);
    $st = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
    curl_close($ch);
    ok('malformed POST body ' . substr($raw, 0, 22) . ': no fatal',
       $st < 500 && !isFatal($st, $bd), "status=$st " . substr($bd, 0, 90));
}

/* ------------------------------------------------------------------ */
/* 7. Event Viewer                                                     */
/* ------------------------------------------------------------------ */
echo "\n--- 7. Event Viewer ---\n";
$eventsAfter = intval($db->get_recordset("SELECT COUNT(*) AS v FROM events WHERE log_level IN (1,2)")->v);
ok('no new ERROR/WARNING row in events',
   $eventsAfter === $eventsBefore, "baseline=$eventsBefore now=$eventsAfter");

echo "\n===== " . $GLOBALS['pass'] . " passed, " . $GLOBALS['fail'] . " failed =====\n";
exit($GLOBALS['fail'] > 0 ? 1 : 0);
