<?php
/**
 * Harness for issue #1981 - api/blockedageing BFF contract + report semantics.
 * Written in PHP + curl like tmp/verify_1790.php / tmp/verify_1791.php.
 *
 * Fixture: php tmp/fixtures_1981.php   (prints "DONE project=.. plan=.. ...")
 * Usage:   php tmp/verify_1981.php
 */
require_once('config.inc.php');
require_once('common.php');

define('BASE', 'http://localhost:8082');

$db = new database(DB_TYPE);
doDBConnect($db);
$tables = tlObject::getDBTables();

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

function loginAs($jar, $login, $password, $tprojectId = 0)
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
        CURLOPT_POSTFIELDS     => "tl_login=$login&tl_password=$password&tproject_id=$tprojectId",
    ));
    curl_exec($ch);
    curl_close($ch);
}

function call($jar, $query, $method = 'GET', $origin = true)
{
    $ch = curl_init(BASE . '/api/blockedageing/index.php' . $query);
    $opt = array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CUSTOMREQUEST  => $method,
    );
    if ($origin) {
        $opt[CURLOPT_HTTPHEADER] = array('Origin: ' . BASE);
    }
    curl_setopt_array($ch, $opt);
    $body = curl_exec($ch);
    $status = intval(curl_getinfo($ch, CURLINFO_RESPONSE_CODE));
    curl_close($ch);
    $json = json_decode((string)$body, true);
    return array('status' => $status, 'json' => is_array($json) ? $json : null,
        'raw' => (string)$body);
}

// --- fixture ids ------------------------------------------------------------
$res = $db->get_recordset(
    "SELECT id FROM {$tables['testprojects']} WHERE prefix = 'BG' ORDER BY id DESC LIMIT 1");
$idP = intval($res[0]['id']);
$res = $db->get_recordset(
    "SELECT id FROM {$tables['testprojects']} WHERE prefix = 'BZ' ORDER BY id DESC LIMIT 1");
$idEmpty = intval($res[0]['id']);
$res = $db->get_recordset(
    "SELECT id FROM {$tables['testplans']} WHERE testproject_id = {$idP} ORDER BY id DESC LIMIT 1");
$idPlan = intval($res[0]['id']);
$res = $db->get_recordset(
    "SELECT id FROM {$tables['testplans']} WHERE testproject_id = {$idEmpty} ORDER BY id DESC LIMIT 1");
$idPlanEmpty = intval($res[0]['id']);
$builds = array();
$rs = $db->get_recordset(
    "SELECT id, name FROM {$tables['builds']} WHERE testproject_id = {$idP} ORDER BY id");
foreach ($rs as $r) { $builds[$r['name']] = intval($r['id']); }
$plats = array();
$rs = $db->get_recordset(
    "SELECT id, name FROM {$tables['platforms']} WHERE testproject_id = {$idP} ORDER BY id");
foreach ($rs as $r) { $plats[$r['name']] = intval($r['id']); }
echo "fixture: project=$idP empty=$idEmpty plan=$idPlan planEmpty=$idPlanEmpty "
   . "builds=" . implode(',', array_values($builds)) . " plats=" . implode(',', array_values($plats)) . "\n";

$jarAdmin = '/tmp/opencode/cj1981_admin.txt';
loginAs($jarAdmin, 'admin', 'admin', $idP);

// 1. init
$r = call($jarAdmin, "?action=init&tplan_id={$idPlan}");
ok('init 200', $r['status'] === 200, "status={$r['status']} raw={$r['raw']}");
ok('init context plan', $r['json'] && $r['json']['context']['tplan_name'] === 'BAG Plan');
ok('init filters builds', $r['json'] && count($r['json']['filters']['builds']) === 2);
ok('init filters platforms', $r['json'] && count($r['json']['filters']['platforms']) === 2);

// 2. report - all
$r = call($jarAdmin, "?action=report&tplan_id={$idPlan}");
ok('report 200', $r['status'] === 200, "status={$r['status']} raw={$r['raw']}");
$s = $r['json']['summary'];
$names = array();
foreach ($r['json']['rows'] as $row) { $names[] = $row['tcase_name']; }
ok('blocked = 5 (TC1,TC2,TC3,TC6,TC7 - TC4 recovered and TC5 never run excluded)',
    $s['blocked'] === 5, 'blocked=' . $s['blocked']);
ok('recovered TC4 not listed', !in_array('BG TC four recovered', $names));
ok('never-run TC5 not listed', !in_array('BG TC five never run', $names));
ok('assigned links = 7', $s['assigned_links'] === 7, 'assigned=' . $s['assigned_links']);
ok('older_30 = 1', $s['older_30'] === 1);
ok('older_7 = 4 (35d,12d,12d,10d)', $s['older_7'] === 4, 'older_7=' . $s['older_7']);
ok('max_age = 35', $s['max_age'] === 35, 'max=' . $s['max_age']);
ok('with_bugs = 2', $s['with_bugs'] === 2);
ok('testers = 1', $s['testers'] === 1);
ok('verdict at_risk', $r['json']['verdict'] === 'at_risk');
ok('oldest first ordering',
    $r['json']['rows'][0]['age_days'] >= $r['json']['rows'][count($r['json']['rows']) - 1]['age_days']);
$tc1 = null;
foreach ($r['json']['rows'] as $row) {
    if ($row['tcase_name'] === 'BG TC one blocked long') { $tc1 = $row; }
}
ok('TC1 row fields', $tc1 && $tc1['age_days'] === 35 && $tc1['bugs'] === 1
    && strpos($tc1['notes'], 'Database locked') === 0
    && $tc1['build_name'] === 'BAG Build 1' && $tc1['external_full'] === 'BG-1');
$tc6 = null;
foreach ($r['json']['rows'] as $row) {
    if ($row['tcase_name'] === 'BG TC six platform one') { $tc6 = $row; }
}
ok('TC6 platform name + bug', $tc6 && $tc6['platform_name'] === 'BAG Platform 1' && $tc6['bugs'] === 1);

// 3. build filter narrows the executions
$r = call($jarAdmin, "?action=report&tplan_id={$idPlan}&build_id=" . $builds['BAG Build 2']);
ok('build 2 report: only TC2,TC3 blocked', $r['json']['summary']['blocked'] === 2,
    'blocked=' . $r['json']['summary']['blocked']);
$r = call($jarAdmin, "?action=report&tplan_id={$idPlan}&build_id=" . $builds['BAG Build 1']);
ok('build 1 report: TC1,TC4,TC6,TC7 blocked (build scope re-computes the latest '
   . 'execution, so TC4 whose pass was on build 2 is blocked again here - '
   . 'the same re-scoping convention as the other plan reports)',
    $r['json']['summary']['blocked'] === 4, 'blocked=' . $r['json']['summary']['blocked']);

// 4. platform filter narrows the universe
$r = call($jarAdmin, "?action=report&tplan_id={$idPlan}&platform_id=" . $plats['BAG Platform 1']);
ok('platform 1 report: only TC6', $r['json']['summary']['blocked'] === 1
    && $r['json']['rows'][0]['tcase_name'] === 'BG TC six platform one',
    'blocked=' . $r['json']['summary']['blocked']);
$r = call($jarAdmin, "?action=report&tplan_id={$idPlan}&platform_id=" . $plats['BAG Platform 2']);
ok('platform 2 report: only TC7', $r['json']['summary']['blocked'] === 1
    && $r['json']['rows'][0]['tcase_name'] === 'BG TC seven platform two');

// 5. empty plan -> clear verdict
$r = call($jarAdmin, "?action=report&tplan_id={$idPlanEmpty}");
ok('empty plan: blocked 0 verdict clear', $r['json']['summary']['blocked'] === 0
    && $r['json']['verdict'] === 'clear');

// 6. error contract
$r = call($jarAdmin, "?action=report&tplan_id=999999");
ok('unknown plan 404 tplan_not_found', $r['status'] === 404 && $r['json']['code'] === 'tplan_not_found');
$r = call($jarAdmin, "?action=report&tplan_id={$idPlan}&tproject_id=999999");
ok('project mismatch 404', $r['status'] === 404 && $r['json']['code'] === 'project_mismatch');
$r = call($jarAdmin, "?action=report&tplan_id={$idPlan}&build_id=424242");
ok('foreign build 400', $r['status'] === 400 && $r['json']['code'] === 'invalid_request');
$r = call($jarAdmin, "?action=report");
ok('missing tplan 400', $r['status'] === 400 && $r['json']['code'] === 'invalid_request');
$r = call($jarAdmin, "?action=nope&tplan_id={$idPlan}");
ok('unknown action 400', $r['status'] === 400 && $r['json']['code'] === 'invalid_request');
$r = call($jarAdmin, "?action=report&tplan_id={$idPlan}", 'POST');
ok('POST 405', $r['status'] === 405 && $r['json']['code'] === 'method_not_allowed');
$ch = curl_init(BASE . "/api/blockedageing/index.php?action=report&tplan_id={$idPlan}");
curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEJAR      => $jarAdmin,
    CURLOPT_COOKIEFILE     => $jarAdmin,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_CUSTOMREQUEST  => 'POST',
    CURLOPT_HTTPHEADER     => array('Origin: http://evil.example'),
));
$bodyEvil = curl_exec($ch);
$stEvil = intval(curl_getinfo($ch, CURLINFO_RESPONSE_CODE));
curl_close($ch);
ok('foreign-origin POST rejected by CSRF guard 403', $stEvil === 403, 'status=' . $stEvil);

// 7. no rights
$jarNo = '/tmp/opencode/cj1981_norights.txt';
loginAs($jarNo, 'bage1981norights', 'bagenorights', $idP);
$r = call($jarNo, "?action=report&tplan_id={$idPlan}");
ok('no testplan_metrics 403', $r['status'] === 403 && $r['json']['code'] === 'no_right',
    'status=' . $r['status'] . ' raw=' . $r['raw']);

// 8. anonymous 401
$jarAnon = '/tmp/opencode/cj1981_anon.txt';
@unlink($jarAnon);
touch($jarAnon);
$r = call($jarAnon, "?action=report&tplan_id={$idPlan}");
ok('anonymous 401', $r['status'] === 401 && $r['json']['code'] === 'not_authenticated');

echo "\n" . ($GLOBALS['fail'] === 0
    ? "ALL {$GLOBALS['pass']} CHECKS PASS"
    : "FAILED: {$GLOBALS['fail']} of " . ($GLOBALS['pass'] + $GLOBALS['fail'])) . "\n";
exit($GLOBALS['fail'] === 0 ? 0 : 1);
