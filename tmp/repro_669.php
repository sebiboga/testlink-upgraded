<?php
// Repro harness for issue #669 — invokes the REAL protected
// TestlinkXMLRPCServer::_insertResultToDB() via Reflection (constructor skipped),
// against the live MariaDB, with versionNumber = valid vs null.
// Usage: php tmp/repro_669.php <build_id> <tplan_id> <tcversion_id>
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
chdir(__DIR__ . '/../lib/api/xmlrpc/v1');
require_once './xmlrpc.class.php';

$buildId = intval($argv[1]);
$tplanId = intval($argv[2]);
$tcversionId = intval($argv[3]);

$rc = new ReflectionClass('TestlinkXMLRPCServer');
$svc = $rc->newInstanceWithoutConstructor();

function setProp($obj, $name, $val) {
    $p = new ReflectionProperty('TestlinkXMLRPCServer', $name);
    $p->setAccessible(true);
    $p->setValue($obj, $val);
}

$dbObj = new database(DB_TYPE);
doDBConnect($dbObj);
setProp($svc, 'dbObj', $dbObj);
$tcaseMgr = new testcase($dbObj);
setProp($svc, 'tcaseMgr', $tcaseMgr);
setProp($svc, 'tables', $tcaseMgr->getDBTables());
setProp($svc, 'userID', 1);
setProp($svc, 'tcVersionID', $tcversionId);

$m = new ReflectionMethod('TestlinkXMLRPCServer', '_insertResultToDB');
$m->setAccessible(true);

function execCount($dbObj) {
    $r = $dbObj->get_recordset('SELECT COUNT(*) c FROM executions');
    return intval($r[0]['c']);
}

echo "executions rows BEFORE: " . execCount($dbObj) . "\n";

// ---- Case A (control): resolved version number = 1
setProp($svc, 'args', array(
    TestlinkXMLRPCServer::$buildIDParamName => $buildId,
    TestlinkXMLRPCServer::$statusParamName => 'p',
    TestlinkXMLRPCServer::$testPlanIDParamName => $tplanId,
));
setProp($svc, 'versionNumber', 1);
$idA = $m->invoke($svc);
printf("CASE A versionNumber=1  -> returned id=%s | ErrorNo=%s | Msg=%s\n",
    var_export($idA, true), var_export($dbObj->db->ErrorNo(), true), $dbObj->db->ErrorMsg());
echo "executions rows after A: " . execCount($dbObj) . "\n";

// ---- Case B (bug): unresolved version number = null
setProp($svc, 'versionNumber', null);
$idB = $m->invoke($svc);
printf("CASE B versionNumber=NULL -> returned id=%s | ErrorNo=%s | Msg=%s\n",
    var_export($idB, true), var_export($dbObj->db->ErrorNo(), true), $dbObj->db->ErrorMsg());
echo "executions rows after B: " . execCount($dbObj) . "\n";
$r = $dbObj->get_recordset('SELECT id,testplan_id,tcversion_id,tcversion_number,status FROM executions ORDER BY id');
foreach ((array)$r as $row) { echo json_encode($row) . "\n"; }
