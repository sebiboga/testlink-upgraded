<?php
/**
 * Regression suite for issue #1791 - build::getCustomFieldsValues() fatal in
 * lib/plan/planView.php and lib/plan/buildView.php.
 *
 * Both legacy controllers were reachable only from a stale bookmark / a wiki
 * link (the 2.0.1 aside menu already routes both to the modernized pages:
 * common.php:2193 planView.html, common.php:2220 buildsView.html). Both died with
 * HTTP 500 "Call to undefined method build::getCustomFieldsValues()" as soon as
 * the ACTIVE test project had a design-time custom field linked - the call sits
 * behind `if ($hasCF)`, which is why the defect survived the modernization and
 * why the fixture (tmp/fixtures_1791.php) has to build exactly that state.
 *
 * Reproducing it needs two non-obvious steps, both pinned here:
 *   1. the legacy planView.php reads the project from $_SESSION['testprojectID']
 *      and calls testlinkInitPage($db,false,false), so it neither reads
 *      ?tproject_id= nor refreshes the session. The session must be pointed at
 *      the fixture project FIRST, via a page that passes $initProject = TRUE.
 *   2. the legacy buildView.php throws when tplan_id is missing/invalid, so the
 *      URL must carry a real plan id.
 *
 * Run:  php tmp/fixtures_1791.php && php tmp/verify_1791.php
 */
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

// --------------------------------------------------------------------- helpers
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

/** Returns [httpStatus, body]. */
function req($cookieJar, $path, $post = null)
{
    $ch = curl_init(BASE . $path);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $cookieJar,
        CURLOPT_COOKIEFILE     => $cookieJar,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 30,
    ));
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    }
    $body = (string)curl_exec($ch);
    $st = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
    curl_close($ch);
    return array($st, $body);
}

function login($jar, $login, $tprojectId)
{
    @unlink($jar);
    // tproject_id is passed on the login POST on purpose: common.php:initProject()
    // is what puts a project into the session, and it reads $_REQUEST.
    return req($jar, '/login.php?action=doLogin',
        "tl_login=$login&tl_password=admin&tproject_id=$tprojectId");
}

/** True for any PHP fatal / 500 body, whichever way it surfaced. */
function isFatal($status, $body)
{
    return $status >= 500
        || strpos($body, 'Call to undefined method') !== false
        || strpos($body, 'Fatal error') !== false
        || strpos($body, 'Uncaught Error') !== false;
}

define('BASE', 'http://localhost:8082');

// --------------------------------------------------------------- fixture state
$sum = trim((string)shell_exec('php ' . escapeshellarg(__DIR__ . '/fixtures_1791.php') . ' 2>/dev/null'));
preg_match('/SUMMARY pid=(\d+) planId=(\d+) buildId=(\d+) cfId=(\d+) uid=(\d+)/', $sum, $m);
if (empty($m)) {
    fwrite(STDERR, "FATAL: fixture did not run / no SUMMARY line\n$sum\n");
    exit(2);
}
list(, $pid, $planId, $buildId, $cfId, $uid) = $m;
$P = intval($pid);
$PLAN = intval($planId);
echo "fixture: project=$P plan=$PLAN build=" . intval($buildId) . " cfield=" . intval($cfId) . "\n";

$jar = __DIR__ . '/ck_1791.txt';
$jarNR = __DIR__ . '/ck_1791_norights.txt';
$eventsBefore = intval($db->get_recordset("SELECT COUNT(*) AS v FROM events WHERE log_level IN (1,2)")->v);

// A PHP fatal never reaches `events`, so the events delta below proves almost
// nothing about THIS defect. The dev server's stderr is the real witness.
$serverLog = __DIR__ . '/php_server.log';
function fatalCount($logFile)
{
    if (!is_readable($logFile)) {
        return -1;
    }
    return substr_count((string)file_get_contents($logFile), 'PHP Fatal error');
}
$fatalsBefore = fatalCount($serverLog);

// ============================================================ 1. the legacy URLs
echo "\n--- 1. the two legacy URLs that used to fatal ---\n";
login($jar, 't1791a', $P);
// Point the SESSION at the fixture project: the legacy planView.php reads
// $_SESSION['testprojectID'] and passes $initProject=FALSE, so it cannot do this itself.
req($jar, '/lib/results/resultsNavigator.php?tproject_id=' . $P);

list($st, $body) = req($jar, '/lib/plan/planView.php');
ok("planView.php answers 200, not the #1791 fatal", $st === 200 && !isFatal($st, $body), "status=$st");
ok('planView.php redirects to the modernized planView.html',
   strpos($body, '/gui/templates/plans/planView.html') !== false, substr($body, 0, 200));
ok('planView.php carries the fixture project id', strpos($body, 'tproject_id=' . $P) !== false);

list($st, $body) = req($jar, '/lib/plan/buildView.php?tplan_id=' . $PLAN);
ok("buildView.php answers 200, not the #1791 fatal", $st === 200 && !isFatal($st, $body), "status=$st");
ok('buildView.php redirects to the modernized buildsView.html',
   strpos($body, '/gui/templates/plans/buildsView.html') !== false, substr($body, 0, 200));
ok('buildView.php carries the test plan id', strpos($body, 'tplan_id=' . $PLAN) !== false);

echo "\n--- 2. the redirect target must be a real screen, not a dead link ---\n";
list($st, $body) = req($jar, '/gui/templates/plans/planView.html?tproject_id=' . $P);
ok('planView.html loads', $st === 200 && strpos($body, '<html') !== false, "status=$st");
ok('planView.html is the real page (not another redirect)',
   strpos($body, "window.location.replace") === false);

list($st, $body) = req($jar, '/gui/templates/plans/buildsView.html?tplan_id=' . $PLAN);
ok('buildsView.html loads', $st === 200 && strpos($body, '<html') !== false, "status=$st");
ok('buildsView.html is the real page (not another redirect)',
   strpos($body, "window.location.replace") === false);

echo "\n--- 3. the BFF behind those screens still works for the fixture project ---\n";
list($st, $body) = req($jar, "/api/plans/index.php/?tproject_id=$P");
ok('GET /api/plans/ -> 200', $st === 200 && strpos($body, '"status":"ok"') !== false, "status=$st");
list($st, $body) = req($jar, "/api/builds/index.php/?tplan_id=$PLAN");
ok('GET /api/builds/ -> 200', $st === 200 && strpos($body, '"status":"ok"') !== false, "status=$st");
ok('the fixture build is visible to the modernized screen',
   strpos($body, 'T1791-BUILD') !== false, substr($body, 0, 200));

echo "\n--- 4. the shim must not smuggle a write past the BFF ---\n";
list($st, $body) = req($jar, '/lib/plan/buildView.php?tplan_id=' . $PLAN, 'tplan_id=' . $PLAN);
ok('buildView.php refuses POST with 405', $st === 405, "status=$st body=" . substr($body, 0, 60));
list($st, $body) = req($jar, '/lib/plan/planView.php?tproject_id=' . $P, 'tproject_id=' . $P);
ok('planView.php refuses POST with 405', $st === 405, "status=$st body=" . substr($body, 0, 60));

echo "\n--- 5. no session must NOT reach the screen ---\n";
$anon = __DIR__ . '/ck_1791_anon.txt';
@unlink($anon);
list($st, $body) = req($anon, '/lib/plan/planView.php?tproject_id=' . $P);
// The bounce is a JavaScript redirect with HTTP 200 (common.php:redirect()), so the
// only honest assertions are: no fatal, it targets login, and it did NOT hand out
// the modernized screen.
ok('planView.php without a session goes to login, not the screen',
   !isFatal($st, $body) && strpos($body, 'login.php') !== false
   && strpos($body, 'plans/planView.html') === false, "status=$st " . substr($body, 0, 90));
list($st, $body) = req($anon, '/lib/plan/buildView.php?tplan_id=' . $PLAN);
ok('buildView.php without a session goes to login, not the screen',
   !isFatal($st, $body) && strpos($body, 'login.php') !== false
   && strpos($body, 'plans/buildsView.html') === false, "status=$st " . substr($body, 0, 90));

echo "\n--- 6. the old fatal must be gone from every legacy call site ---\n";
// A CALL is recognisable by its "->getCustomFieldsValues(" syntax; the prose in a
// comment never carries it, so this cannot be faked by adding a mention.
$calls = trim((string)shell_exec(
    "grep -rn -- '->getCustomFieldsValues(' lib/ api/ gui/ 2>/dev/null | grep -v '/tmp/'"));
ok('no CALL of the removed method is left anywhere', $calls === '', $calls);
$defs = trim((string)shell_exec(
    "grep -rn 'function getCustomFieldsValues' lib/ 2>/dev/null"));
ok('the method is defined nowhere in lib/', $defs === '', $defs);

echo "\n--- 7. input hygiene: no PHP notice/fatal from the shim ---\n";
foreach (array(
    'tproject_id=abc'            => '/lib/plan/planView.php?tproject_id=abc',
    'tproject_id[]=1'             => '/lib/plan/planView.php?tproject_id[]=1',
    'tplan_id=-1'                 => '/lib/plan/buildView.php?tplan_id=-1',
    'tplan_id[]=1'                => '/lib/plan/buildView.php?tplan_id[]=1',
    'tplan_id=999999'             => '/lib/plan/buildView.php?tplan_id=999999',
    'no params at all'            => '/lib/plan/planView.php',
) as $label => $path) {
    list($st, $body) = req($jar, $path);
    ok("$label: no fatal/500", $st < 500 && !isFatal($st, $body), "status=$st " . substr($body, 0, 80));
}
list($st, $body) = req($jar, '/lib/plan/planView.php?tproject_id=' . $P . "&tproject_id=';alert(1);//");
ok("a second tproject_id cannot inject script", strpos($body, 'alert(1)') === false);

echo "\n--- 7b. a user with NO right on the fixture project ---\n";
// The shim performs no rights check by design (the modernized page enforces
// rights), so this is where a privilege regression would actually show up: the
// shim may hand out a URL (it leaks nothing - no name, no existence), but the
// API behind it must answer 403.
login($jarNR, 'sm1759norights', $P);
list($st, $body) = req($jarNR, '/lib/plan/planView.php?tproject_id=' . $P);
ok('shim still redirects a no-rights user (it must not 500 or fatal)',
   $st === 200 && !isFatal($st, $body) && strpos($body, 'plans/planView.html') !== false, "status=$st");
list($st, $body) = req($jarNR, "/api/plans/index.php/?tproject_id=$P");
ok('api/plans/ answers 403 to a no-rights user', $st === 403, "status=$st " . substr($body, 0, 90));
list($st, $body) = req($jarNR, '/gui/templates/plans/planView.html?tproject_id=' . $P);
ok('the screen itself still loads (a static shell; every datum comes from the API)',
   $st === 200 && !isFatal($st, $body) && strpos($body, '<html') !== false, "status=$st");
ok('the shell leaks no project name of its own',
   strpos($body, 'T1791 project - issue #1791') === false);

echo "\n--- 8. Event Viewer AND the dev server's fatal log ---\n";
$fatalsAfter = fatalCount($serverLog);
if ($fatalsBefore >= 0) {
    ok('not one PHP Fatal error was raised during this run',
       $fatalsAfter === $fatalsBefore, "before=$fatalsBefore after=$fatalsAfter");
} else {
    echo "  skip dev-server fatal log (\$serverLog not readable) - is the PHP server\n";
    echo "       started with its stderr redirected to tmp/php_server.log?\n";
}
$eventsAfter = intval($db->get_recordset("SELECT COUNT(*) AS v FROM events WHERE log_level IN (1,2)")->v);
ok('no new ERROR/WARNING row in events', $eventsAfter === $eventsBefore,
   "baseline=$eventsBefore now=$eventsAfter");

echo "\n===== " . $GLOBALS['pass'] . " passed, " . $GLOBALS['fail'] . " failed =====\n";
exit($GLOBALS['fail'] > 0 ? 1 : 0);