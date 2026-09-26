<?php
// Unit-level checks for issue #1617 -- matrix rows 5 and 6 of the ROOT CAUSE
// comment (the HTTP rows are in tmp/verify_1617.sh).
//   php tmp/unit_1617.php
require_once('config.inc.php');
require_once('common.php');
$db = new database(DB_TYPE);
doDBConnect($db);
$mgr = new tlIssueTracker($db);

function q($sql)
{
    return trim((string)shell_exec("mysql -h 127.0.0.1 -utestlink -ptestlink -N -B testlink -e " . escapeshellarg($sql)));
}

echo "M6: valid \$systems keys = " . count($mgr->getTypes()) . "\n";
echo "M6: getImplementationForType(0)    = " . var_export($mgr->getImplementationForType(0), true) . "\n";
echo "M6: getImplementationForType(999)  = " . var_export($mgr->getImplementationForType(999), true) . "\n";
echo "M6: getImplementationForType(1)    = " . var_export($mgr->getImplementationForType(1), true) . "  (valid type must be unchanged)\n";

// M5: getLinkedTo() on a project linked to a bad-type tracker.
q("DELETE FROM issuetrackers");
q("INSERT INTO issuetrackers (name,type,cfg) VALUES ('BadType',0,'')");
q("INSERT INTO issuetrackers (name,type,cfg) VALUES ('GoodType',1,'<testlink/>')");
$bad = (int)q("SELECT id FROM issuetrackers WHERE name='BadType'");
$good = (int)q("SELECT id FROM issuetrackers WHERE name='GoodType'");
$proj = (int)q("SELECT nh.id FROM nodes_hierarchy nh JOIN testprojects tp ON tp.id = nh.id");
echo "M5: project id = $proj, bad tracker id = $bad, good tracker id = $good\n";
q("DELETE FROM testproject_issuetracker");
q("INSERT INTO testproject_issuetracker (testproject_id,issuetracker_id) VALUES ($proj,$bad)");
echo "M5: getLinkedTo(bad-type)  = " . var_export($mgr->getLinkedTo($proj), true) . "  (expect NULL, no warning)\n";
q("DELETE FROM testproject_issuetracker");
q("INSERT INTO testproject_issuetracker (testproject_id,issuetracker_id) VALUES ($proj,$good)");
echo "M5: getLinkedTo(good-type) = " . json_encode($mgr->getLinkedTo($proj)) . "  (expect real data, no regression)\n";
q("DELETE FROM testproject_issuetracker");
q("DELETE FROM events");
echo "M5: event rows after unit = " . q("SELECT COUNT(*) FROM events") . "\n";
