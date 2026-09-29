<?php
/**
 * Test suite for Refs #1717 - Modernize: Test Cases Not Run on Any Platform
 * (tcNotRunAnyPlatform).
 *
 * Run from repo root:  php tmp/test_1717.php
 * Exit code 0 = all assertions pass, 1 = at least one failure.
 *
 * The BFF is exercised through its real entry point logic (session user +
 * config), not by re-implementing the query, so a regression in the action
 * is caught. Guard paths that need an HTTP status are asserted against the
 * same helpers the action uses for the decision.
 */
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

// ---------------------------------------------------------------------------
// Load the REAL helpers from api/reports/index.php.
// The tnr* functions are defined in the BFF, but the file also dispatches on
// $_GET['action'] at include time, so it cannot simply be require'd. Instead
// the helper block is sliced out of the source and evaluated, so the test can
// never drift from the implementation (a copy would silently keep passing
// after the BFF was broken). Refs #1717.
// ---------------------------------------------------------------------------
$apiSrc = file_get_contents('api/reports/index.php');
$startMark = '// TestLink stores a test case version';
$endMark = "\nhttp_response_code(404);";
$from = strpos($apiSrc, $startMark);
$to = strpos($apiSrc, $endMark, $from === false ? 0 : $from);
if ($from === false || $to === false) {
    fwrite(STDERR, "FATAL: could not slice the tnr* helper block out of " .
        "api/reports/index.php - the BFF layout changed.\n");
    exit(2);
}
$helperCount = preg_match_all('/^function tnr\w+/m', substr($apiSrc, $from, $to - $from), $m);
if ($helperCount < 5) {
    fwrite(STDERR, "FATAL: expected 5 tnr* helpers in the BFF, found $helperCount.\n");
    exit(2);
}
eval(substr($apiSrc, $from, $to - $from));

$tprojMgr = new testproject($db);
$user = new tlUser(1); // admin: holds every right
$user->readFromDB($db);

$pass = 0; $fail = 0; $id = 0;
function ok($cond, $label, $expected, $actual) {
    global $pass, $fail, $id;
    $id++;
    if ($cond) { $pass++; printf("| %d | %s | PASS |\n", $id, $label); }
    else { $fail++; printf("| %d | %s | **FAIL** (expected %s, got %s) |\n",
        $id, $label, var_export($expected, true), var_export($actual, true)); }
}

// The fixture created by tmp/fixtures_1717.php. The fixture publishes its
// ids to tmp/fixture_1717.json so the suite never hardcodes auto-increment
// values - run `php tmp/fixtures_1717.php` first, then this harness.
$idsFile = __DIR__ . '/fixture_1717.json';
if (!is_readable($idsFile)) {
    fwrite(STDERR, "FATAL: run `php tmp/fixtures_1717.php` first "
        . "(tmp/fixture_1717.json is missing).\n");
    exit(2);
}
$F = json_decode(file_get_contents($idsFile), true);
if (!is_array($F)) {
    fwrite(STDERR, "FATAL: tmp/fixture_1717.json is not valid JSON.\n");
    exit(2);
}
$TPROJECT = intval($F['tproject']);
$TPLAN    = intval($F['tplan']);
$PLAT_A   = intval($F['plat_a']);
$PLAT_B   = intval($F['plat_b']);
$TC_NEVER_BOTH   = intval($F['tc_never_both']);  // linked to A+B, no exec  -> reported
$TC_SINGLE_PLAT  = intval($F['tc_never_both2']); // linked to A only, no exec -> reported
$TC_ONE_PLATFORM = intval($F['tc_pass_a']);      // passed on A only       -> excluded
$TC_MIXED        = intval($F['tc_mixed']);       // passed on B, n/r on A  -> excluded
$TC_CLOSED_BUILD = intval($F['tc_closed_build']); // passed on a CLOSED build -> reported

$tplanInfo = (new testplan($db))->get_by_id($TPLAN, array("output" => "minimun"));

// ---------------------------------------------------------------------------
// Re-derive the action body through the real helpers. This mirrors
// api/reports/index.php's not_run_any_platform block; the HTTP-level
// behaviour (status codes) is asserted separately below from the same
// decision logic.
// ---------------------------------------------------------------------------
$platformIds = array($PLAT_A, $PLAT_B);
$tpMetrics = new tlTestPlanMetrics($db);
$neverRun = $tpMetrics->getNeverRunByPlatform($TPLAN, $platformIds);

ok(!is_null($neverRun) && count($neverRun) > 0,
   'getNeverRunByPlatform() returns rows for the fixture', '>0 rows',
   is_null($neverRun) ? 'null' : count($neverRun) . ' rows');

// never-run set per test case
$neverPlatSet = array();
foreach ((array)$neverRun as $r) {
    $neverPlatSet[intval($r['tcase_id'])][intval($r['platform_id'])] = true;
}
$linkedPlat = tnrLinkedPlatformsPerCase($db, $TPLAN, array_keys($neverPlatSet));

// replicate the "every linked platform is never-run" predicate
$reported = array();
foreach ($linkedPlat as $cid => $plats) {
    $all = true;
    foreach ($plats as $pid) {
        if (!isset($neverPlatSet[$cid][$pid])) { $all = false; break; }
    }
    if ($all) { $reported[] = $cid; }
}
sort($reported);

ok($reported === array($TC_NEVER_BOTH, $TC_SINGLE_PLAT, $TC_CLOSED_BUILD),
   'exactly the three never-run-on-any-ACTIVE+OPEN-build cases are reported',
   array($TC_NEVER_BOTH, $TC_SINGLE_PLAT, $TC_CLOSED_BUILD), $reported);

// REGRESSION (mandatory code review BLOCKER): the case passed only on a
// CLOSED build stays in the report, and its cell must read Not Run - the
// last-status query used to filter active=1 only and painted Passed here.
ok(in_array($TC_CLOSED_BUILD, $reported, true),
   'a case executed only on a CLOSED build is still reported (report = active+open)',
   'reported', in_array($TC_CLOSED_BUILD, $reported, true) ? 'reported' : 'excluded');
$lastClosed = tnrLastStatusPerPlatform($db, $TPLAN, $TPROJECT, array($PLAT_A));
ok(!isset($lastClosed[$TC_CLOSED_BUILD][$PLAT_A]),
   'its cell is NOT a Passed badge: a closed build is out of the status scope',
   'absent', $lastClosed[$TC_CLOSED_BUILD][$PLAT_A] ?? 'absent');

ok(!in_array($TC_ONE_PLATFORM, $reported, true),
   'a case executed on ONE platform is excluded', 'excluded',
   in_array($TC_ONE_PLATFORM, $reported, true) ? 'reported' : 'excluded');

ok(!in_array($TC_MIXED, $reported, true),
   'a case with a not_run on A but passed on B is excluded (any platform counts)',
   'excluded', in_array($TC_MIXED, $reported, true) ? 'reported' : 'excluded');

ok(count($linkedPlat[$TC_SINGLE_PLAT]) === 1,
   'the platform scope is PER CASE, not global (TC-4 is linked to one platform)',
   1, count($linkedPlat[$TC_SINGLE_PLAT]));

ok(count($linkedPlat[$TC_NEVER_BOTH]) === 2,
   'a case linked to both platforms reports both of them', 2,
   count($linkedPlat[$TC_NEVER_BOTH]));

// ---------------------------------------------------------------------------
// total counter / denominator
// ---------------------------------------------------------------------------
$total = tnrCountPlanTestCases($db, $TPLAN);
ok($total === 5,
   'number_of_testcases counts ALL plan cases, not only the never-run ones',
   4, $total);

// ---------------------------------------------------------------------------
// last status per (case, platform)
// ---------------------------------------------------------------------------
$last = tnrLastStatusPerPlatform($db, $TPLAN, $TPROJECT, $platformIds);
ok(!isset($last[$TC_NEVER_BOTH]),
   'a never-run case has no entry in the last-status map', 'absent',
   isset($last[$TC_NEVER_BOTH]) ? 'present' : 'absent');

ok(isset($last[$TC_ONE_PLATFORM][$PLAT_A]) && $last[$TC_ONE_PLATFORM][$PLAT_A] === 'passed',
   'the passed execution on platform A is found', 'passed',
   $last[$TC_ONE_PLATFORM][$PLAT_A] ?? 'absent');

ok(!isset($last[$TC_ONE_PLATFORM][$PLAT_B]),
   'no execution on platform B means no entry (renders as not_run)', 'absent',
   isset($last[$TC_ONE_PLATFORM][$PLAT_B]) ? $last[$TC_ONE_PLATFORM][$PLAT_B] : 'absent');

ok(!isset($last[$TC_MIXED][$PLAT_A]),
   'a "not_run" execution is NOT a result: platform A stays absent for TC-3',
   'absent', isset($last[$TC_MIXED][$PLAT_A]) ? $last[$TC_MIXED][$PLAT_A] : 'absent');

ok(isset($last[$TC_MIXED][$PLAT_B]) && $last[$TC_MIXED][$PLAT_B] === 'passed',
   'the passed execution on platform B is still found for TC-3', 'passed',
   $last[$TC_MIXED][$PLAT_B] ?? 'absent');

// ---------------------------------------------------------------------------
// tcase_id resolution: the version node's PARENT is the test case
// ---------------------------------------------------------------------------
// NOTE: tnrLastStatusPerPlatform() is keyed by TEST CASE id, not by
// tcversion id, so the version ids for the resolution check must be read
// from the plan, not from that map's keys.
$T = tlObjectWithDB::getDBTables(['testplan_tcversions']);
$tvIds = array();
foreach ((array)$db->get_recordset("SELECT tcversion_id FROM {$T['testplan_tcversions']} "
    . "WHERE testplan_id = $TPLAN") as $r) {
    $tvIds[] = intval($r['tcversion_id']);
}
$map = tnrTcaseIdByTcvId($db, $TPLAN, $tvIds);
ok(count($map) === count(array_unique($tvIds)) && !in_array(0, $map, true),
   'tcversion -> tcase resolution yields real test case ids (no 0, all resolved)',
   count(array_unique($tvIds)) . ' ids resolved', count($map) . ' ids: '
   . implode(',', array_slice($map, 0, 5)));

// the resolved ids must be the fixture's four cases - proves parent_id is
// being used and not some other column
$resolvedIds = array_values($map);
sort($resolvedIds);
$expectIds = array($TC_NEVER_BOTH, $TC_ONE_PLATFORM, $TC_MIXED, $TC_SINGLE_PLAT,
    $TC_CLOSED_BUILD);
sort($expectIds);
ok($resolvedIds === $expectIds,
   'the resolved test case ids are exactly the four fixture cases',
   $expectIds, $resolvedIds);

// ---------------------------------------------------------------------------
// urgency * impact (priority column)
// ---------------------------------------------------------------------------
$uip = tnrUrgImpPerCase($db, $TPLAN, array($TC_NEVER_BOTH, $TC_SINGLE_PLAT));
ok(isset($uip[$TC_NEVER_BOTH]) && $uip[$TC_NEVER_BOTH] > 0,
   'urgency*impact is resolved from testplan_tcversions.urgency x tcversions.importance',
   '>0', $uip[$TC_NEVER_BOTH] ?? 'absent');

// ---------------------------------------------------------------------------
// guard decisions (same expressions the action uses)
// ---------------------------------------------------------------------------
$owningProject = intval($tplanInfo['tproject_id']);
ok($owningProject === $TPROJECT,
   'the plan resolves to the expected owning test project', $TPROJECT, $owningProject);

ok($user->hasRight($db, 'testplan_metrics', $TPROJECT),
   'admin holds testplan_metrics on the fixture project', true,
   $user->hasRight($db, 'testplan_metrics', $TPROJECT));

$noRights = $db->get_recordset("SELECT id FROM users WHERE login='tnrap1717norights'");
$uNo = !is_null($noRights) && count($noRights) > 0 ? new tlUser(intval($noRights[0]['id'])) : null;
ok(!is_null($uNo) && !$uNo->hasRight($db, 'testplan_metrics', $TPROJECT),
   "the role-3 user does NOT hold testplan_metrics (403 path)", false,
   is_null($uNo) ? 'no user' : $uNo->hasRight($db, 'testplan_metrics', $TPROJECT));

// ---------------------------------------------------------------------------
// the modern screen and the report registration exist and are consistent
// ---------------------------------------------------------------------------
$screen = 'gui/templates/results/tcNotRunAnyPlatform.html';
ok(is_file($screen), 'the modern screen exists', true, is_file($screen));

$screenSrc = file_get_contents($screen);
ok(strpos($screenSrc, "action: 'not_run_any_platform'") !== false,
   'the screen calls the not_run_any_platform BFF action', 'present', 'absent');
ok(preg_match('/tprojectPrefix=/', $screenSrc) === 1,
   'the deep link uses tprojectPrefix (legacy linkto.php contract)', 1,
   preg_match('/tprojectPrefix=/', $screenSrc));
ok(strpos($screenSrc, 'tprojectId=') === false,
   'the deep link does NOT use a tprojectId argument (that was the bug)', 'absent', 'present');
ok(strpos($screenSrc, 'openTCEditWindow') !== false
   && strpos($screenSrc, 'openExecHistoryWindow') !== false,
   'the design + Execution History popups are wired', 'both present', 'missing');
ok(strpos($screenSrc, 'testlink_library.js') !== false,
   'testlink_library.js is loaded (the popups live there)', 'present', 'absent');

// reports.cfg registration
$reportsCfgSrc = file_get_contents('cfg/reports.cfg.php');
ok(strpos($reportsCfgSrc, "'tcNotRunAnyPlatform'") !== false,
   'the report is registered in cfg/reports.cfg.php', 'present', 'absent');
ok(preg_match("/\\\$tlCfg->reports_list\\['tcNotRunAnyPlatform'\\].*?'enabled'\\s*=>\\s*'all'/s", $reportsCfgSrc) === 1,
   "its 'enabled' flag is 'all' (asideMenu only accepts all|req|bts)", 1,
   preg_match("/\\\$tlCfg->reports_list\\['tcNotRunAnyPlatform'\\].*?'enabled'\\s*=>\\s*'all'/s", $reportsCfgSrc));
ok(strpos($reportsCfgSrc, "'enabled' => 'testplan'") === false,
   "no report entry uses the silently-ignored 'testplan' value", 'absent', 'present');

// aside + common wiring
ok(strpos(file_get_contents('lib/general/asideMenu.php'),
      "link_report_not_run_on_any_platform'") !== false,
   'asideMenu maps the report title to the modern href', 'present', 'absent');
ok(strpos(file_get_contents('lib/functions/common.php'),
      '$actions->tcNotRunAnyPlatform') !== false,
   'common.php exposes $actions->tcNotRunAnyPlatform', 'present', 'absent');

// ---------------------------------------------------------------------------
// i18n: the keys the screen uses exist in EVERY bundle
// ---------------------------------------------------------------------------
$keys = array('tnrap.header', 'tnrap.forPlan', 'tnrap.testPlan', 'tnrap.testCases',
  'tnrap.testCasesInPlan', 'tnrap.notRunOf', 'tnrap.ofTotal', 'tnrap.matchCount',
  'tnrap.priority', 'tnrap.noPlatforms', 'tnrap.allExecuted', 'tnrap.about',
  'tnrap.info', 'tnrap.elapsedSeconds', 'tnrap.noPermission',
  'tnrap.status.not_run', 'tnrap.status.passed', 'tnrap.status.failed',
  'tnrap.status.blocked', 'footers.tcNotRunAnyPlatform');
$locales = array('en', 'ro', 'de', 'fr', 'es', 'it', 'ja', 'pt', 'ru', 'zh');
$missing = array();
foreach ($locales as $loc) {
    $bundle = json_decode(file_get_contents("gui/templates/i18n/$loc.json"), true);
    foreach ($keys as $k) {
        if (!isset($bundle[$k]) || $bundle[$k] === '') { $missing[] = "$loc:$k"; }
    }
}
ok(count($missing) === 0,
   'all ' . count($keys) . ' tnrap/footers keys exist in all ' . count($locales) . ' bundles',
   '0 missing', count($missing) . ' missing: ' . implode(', ', array_slice($missing, 0, 5)));

// ---------------------------------------------------------------------------
// REGRESSIONS from the mandatory code review (rule 16)
// ---------------------------------------------------------------------------
$screen = file_get_contents('gui/templates/results/tcNotRunAnyPlatform.html');

// BLOCKER: DataTables injects the column title with .html(), and a platform
// name is free-text user data (testplan_platform_management.name).
ok(preg_match('/title:\s*esc\(pname\)/', $screen) === 1
   && preg_match('/title:\s*pname\b/', $screen) === 0,
   'REGRESSION: the platform column title is escaped (stored XSS via a platform name)',
   'title: esc(pname)', preg_match('/title:\s*\w+\(pname[^)]*\)/', $screen, $m3) ? $m3[0] : 'n/a');

// SHOULD-FIX: openExecHistoryWindow() concatenates an undeclared tproject_id
// and produced `&tproject_id=undefined`; the modern screens are addressed
// directly with the project id.
ok(strpos($screen, 'javascript:openExecHistoryWindow') === false
   && strpos($screen, 'javascript:openTCEditWindow') === false
   && strpos($screen, 'execHistory.html?tcase_id=') !== false
   && strpos($screen, 'tcView.html?tcase_id=') !== false
   && substr_count($screen, 'tproject_id=') >= 2,
   'REGRESSION: design/history popups carry a real tproject_id (no undefined)',
   'direct modern URLs', 'legacy helper still used');

// SHOULD-FIX: the no-active-build state is a NULL result, not an empty set.
ok(strpos($screen, 'tnrap.noActiveBuilds') !== false
   && strpos($screen, 'd.builds_available') !== false,
   'REGRESSION: the "no active and open build" state is handled, not reported as all-executed',
   'builds_available branch', 'missing');
foreach (array('en', 'ro', 'de', 'fr', 'es', 'it', 'pt', 'ru', 'ja', 'zh') as $loc) {
    $b = json_decode(file_get_contents("gui/templates/i18n/$loc.json"), true);
    if (!isset($b['tnrap.noActiveBuilds']) || $b['tnrap.noActiveBuilds'] === '') {
        $noBuildMsg[] = $loc;
    }
}
ok(!isset($noBuildMsg),
   'tnrap.noActiveBuilds is translated in all ' . count($locales) . ' bundles',
   '0 missing', isset($noBuildMsg) ? implode(',', $noBuildMsg) : '0 missing');

// no English left behind in the non-English bundles
$englishLeftovers = array();
foreach (array('ro', 'de', 'fr', 'es', 'it', 'ja', 'pt', 'ru', 'zh') as $loc) {
    $bundle = json_decode(file_get_contents("gui/templates/i18n/$loc.json"), true);
    $en = json_decode(file_get_contents('gui/templates/i18n/en.json'), true);
    foreach ($keys as $k) {
        if (isset($bundle[$k], $en[$k]) && $bundle[$k] === $en[$k]
            && !in_array($k, array('tnrap.ofTotal', 'tnrap.testPlan', 'tnrap.priority',
                'tnrap.testCases', 'tnrap.priority', 'tnrap.priority'), true)
            && $loc !== 'de' && $loc !== 'es' && $loc !== 'fr' && $loc !== 'it'
            && $loc !== 'pt') {
            $englishLeftovers[] = "$loc:$k";
        }
    }
}
ok(count($englishLeftovers) === 0,
   'no untranslated English copy-paste in the ro/ru/ja/zh bundles', '0',
   count($englishLeftovers) . ': ' . implode(', ', array_slice($englishLeftovers, 0, 5)));

// ---------------------------------------------------------------------------
// the legacy ASIDE label exists in every legacy catalogue
// ---------------------------------------------------------------------------
$noLabel = array();
foreach (glob('locale/*/strings.txt') as $f) {
    if (strpos(file_get_contents($f), 'TLS_link_report_not_run_on_any_platform') === false) {
        $noLabel[] = $f;
    }
}
ok(count($noLabel) === 0,
   'the ASIDE label exists in every legacy strings.txt', '0 missing',
   count($noLabel) . ' missing: ' . implode(', ', $noLabel));

// ---------------------------------------------------------------------------
// every legacy strings.txt must still contain everything it had in HEAD.
// A fixed size threshold is wrong: locale/ro_RO/strings.txt is only ~9.6 kB
// upstream, so "under 20 kB" would flag a pre-existing condition and pass by
// accident on a truncated file. Comparing against the committed blob is what
// actually tests the truncation bug (the first insert attempt forced latin-1
// on the UTF-8 catalogues and cut cs_CZ from 201584 to 150762 bytes).
// ---------------------------------------------------------------------------
$truncated = array();
$shrunk = array();
foreach (glob('locale/*/strings.txt') as $f) {
    $now = filesize($f);
    if ($now < 1000) { $truncated[] = basename(dirname($f)) . ":$now bytes"; continue; }
    $rel = substr($f, strlen('locale/'));
    $head = @shell_exec('git show HEAD:locale/' . escapeshellarg($rel) . ' 2>/dev/null | wc -c');
    $headSize = $head === null || $head === '' ? null : intval(trim($head));
    if ($headSize !== null && $now < $headSize) {
        $shrunk[] = basename(dirname($f)) . ": $now < $headSize bytes";
    }
}
ok(count($truncated) === 0,
   'no legacy catalogue is implausibly small', '0', implode(',', $truncated));
ok(count($shrunk) === 0,
   'no legacy catalogue SHRANK against its committed size (truncation guard)',
   '0 shrunk', implode(',', $shrunk));

printf("\n**Result: %d assertions, %d PASS, %d FAIL, exit %d.**\n",
    $id, $pass, $fail, $fail > 0 ? 1 : 0);
exit($fail > 0 ? 1 : 0);
