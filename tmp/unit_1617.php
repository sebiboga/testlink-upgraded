<?php
// Unit-level checks for issue #1617 -- matrix rows 5, 5b, 6, 6b of the ROOT
// CAUSE comment (the HTTP rows are in tmp/verify_1617.sh).
//   php tmp/unit_1617.php        -> exits 0 on PASS, 1 on FAIL
// Asserts, so a regression cannot produce a false PASS.
$FAILURES = 0;
function ok($cond, $label)
{
    global $FAILURES;
    if ($cond) { echo "  PASS  $label\n"; }
    else { echo "  FAIL  $label\n"; $FAILURES++; }
}
function q($sql)
{
    return trim((string)shell_exec("mysql -h 127.0.0.1 -utestlink -ptestlink -N -B testlink -e " . escapeshellarg($sql)));
}

chdir(dirname(__FILE__) . '/..');
require_once('config.inc.php');
require_once('common.php');
$db = new database(DB_TYPE);
doDBConnect($db);
$mgr = new tlIssueTracker($db);

echo "M6: getImplementationForType()\n";
$r = new ReflectionClass($mgr);
$p = $r->getProperty('systems'); $p->setAccessible(true);
$systems = $p->getValue($mgr);
$enabled = array_keys(array_filter($systems, fn($x) => $x['enabled']));
$disabled = array_keys(array_filter($systems, fn($x) => ! $x['enabled']));
echo "  systems=" . count($systems) . " enabled=" . count($enabled) . " disabled=" . count($disabled) . "\n";
ok(is_null($mgr->getImplementationForType(0)), 'unknown type 0   -> NULL');
ok(is_null($mgr->getImplementationForType(999)), 'unknown type 999 -> NULL');
ok($mgr->getImplementationForType(1) === 'bugzillaxmlrpcInterface', 'valid type 1     -> bugzillaxmlrpcInterface');
ok(count($mgr->getTypes()) === count($enabled), 'getTypes() count == enabled systems count');

echo "M5: getLinkedTo()\n";
// fixture: a test project (2.0.1 keeps the name in nodes_hierarchy.name)
if (q("SELECT COUNT(*) FROM testprojects WHERE id=9901") === '0') {
    q("INSERT INTO testprojects (id,notes,color,active,option_reqs,option_priority,option_automation,options,prefix) VALUES (9901,'issue 1617 fixture','#9BD',1,0,0,0,'','TLP1615')");
    q("INSERT INTO nodes_hierarchy (id,name,parent_id,node_type_id,node_order) VALUES (9901,'TL1615 Project',0,1,1)");
}
$proj = (int) q("SELECT tp.id FROM testprojects tp JOIN nodes_hierarchy nh ON nh.id = tp.id WHERE tp.id = 9901 LIMIT 1");
ok($proj > 0, "test project 9901 exists (got $proj)");
if ($proj === 0) { echo "ABORT: cannot continue without a project\n"; exit(1); }

$mk = function ($name, $type) {
    q("DELETE FROM issuetrackers WHERE name = " . escapeshellarg($name));
    q("INSERT INTO issuetrackers (name,type,cfg) VALUES (" . escapeshellarg($name) . ",$type,'<testlink/>')");
    return (int) q("SELECT id FROM issuetrackers WHERE name = " . escapeshellarg($name) . " LIMIT 1");
};
$bad = $mk('U1617-BadType', 0);
$good = $mk('U1617-GoodType', 1);
$dis = $mk('U1617-DisabledType', $disabled[0]);
$link = function ($id) use ($proj) {
    q("DELETE FROM testproject_issuetracker");
    q("INSERT INTO testproject_issuetracker (testproject_id,issuetracker_id) VALUES ($proj,$id)");
};
$link($bad);
q("DELETE FROM events");
ok(is_null($mgr->getLinkedTo($proj)), 'bad type      -> NULL (no bogus class name)');
ok(q("SELECT COUNT(*) FROM events") === '0', 'bad type      -> 0 event rows');
$link($good);
q("DELETE FROM events");
$g = $mgr->getLinkedTo($proj);
ok(is_array($g) && $g['api'] === 'xmlrpc', 'valid type    -> real data (api=xmlrpc)');
ok(q("SELECT COUNT(*) FROM events") === '0', 'valid type    -> 0 event rows');
// REGRESSION GUARD for the code review's M1: a valid but DISABLED type must
// still resolve. getTypes() only holds 'enabled' systems, so a guard that also
// tested $this->types would wrongly return NULL here -- and because link() in
// projectEdit.php/api/projects picks INSERT vs UPDATE from is_null($statusQuo)
// against a PRIMARY KEY (testproject_id), that turns a project save into a
// "Duplicate entry" DATABASE error page.
$link($dis);
q("DELETE FROM events");
$d = $mgr->getLinkedTo($proj);
ok(is_array($d), 'DISABLED type ' . $disabled[0] . ' -> still resolves (not NULL)');
ok(is_array($d) && $d['api'] === ($systems[$disabled[0]]['api']), 'DISABLED type -> api = ' . $systems[$disabled[0]]['api']);
ok(is_array($d) && $d['verboseType'] === '', 'DISABLED type -> empty verboseType (no warning)');
ok(q("SELECT COUNT(*) FROM events") === '0', 'DISABLED type -> 0 event rows');
q("DELETE FROM testproject_issuetracker");
q("DELETE FROM issuetrackers WHERE name LIKE 'U1617-%'");
q("DELETE FROM events");
echo $FAILURES === 0 ? "\nALL PASS\n" : "\n$FAILURES FAILURE(S)\n";
exit($FAILURES === 0 ? 0 : 1);
