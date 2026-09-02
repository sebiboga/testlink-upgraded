<?php
// Migration for issue #503 sub-task #826:
//  - add builds.testproject_id, backfilled from testplans.testproject_id
//  - add project index + create project-scoped rollup view latest_exec_by_build
// ADDS ONLY. No behaviour change / no unique-key swap (that lands in #834).
// Safe/idempotent. Run from repo root: php tmp/migrate_826.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);
$p = DB_TABLE_PREFIX;

function haveColumn($db,$table,$col) {
    $r = $db->get_recordset(" SHOW COLUMNS FROM `$table` LIKE '$col' ");
    return is_array($r) && count($r) > 0;
}
function haveKey($db,$table,$key) {
    $r = $db->get_recordset(" SHOW INDEX FROM `$table` WHERE Key_name = '$key' ");
    return is_array($r) && count($r) > 0;
}
function haveView($db,$view) {
    $r = $db->get_recordset(
        " SELECT COUNT(*) AS c FROM information_schema.VIEWS " .
        " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$view' ");
    return intval($r[0]['c']) > 0;
}

$changed = false;

if (!haveColumn($db,$p.'builds','testproject_id')) {
    $db->exec_query(
        " ALTER TABLE `{$p}builds` ADD COLUMN `testproject_id` int(10) unsigned NOT NULL DEFAULT '0' AFTER `id` ");
    echo "added builds.testproject_id\n";
    $changed = true;
}

// backfill from testplans via testplan_id
$db->exec_query(
    " UPDATE `{$p}builds` B " .
    " JOIN `{$p}testplans` T ON T.id = B.testplan_id " .
    " SET B.testproject_id = T.testproject_id WHERE B.testproject_id = 0 ");
echo "backfilled builds.testproject_id\n";

if (!haveColumn($db,$p.'builds','testproject_id')) {
    echo "ERROR: testproject_id column missing\n"; exit(1);
}

if (!haveKey($db,$p.'builds',$p.'testproject_id')) {
    $db->exec_query(
        " ALTER TABLE `{$p}builds` ADD KEY `{$p}testproject_id` (`testproject_id`) ");
    echo "added index {$p}testproject_id\n";
    $changed = true;
}

if (!haveView($db,$p.'latest_exec_by_build')) {
    $db->exec_query(
        " CREATE OR REPLACE VIEW `{$p}latest_exec_by_build` AS " .
        " SELECT tcversion_id, build_id, platform_id, max(id) AS id " .
        " FROM `{$p}executions` GROUP BY tcversion_id, build_id, platform_id ");
    echo "created latest_exec_by_build view\n";
    $changed = true;
} else {
    echo "latest_exec_by_build view already exists\n";
}

// verify
$chk = $db->get_recordset(" SELECT COUNT(*) AS c FROM `{$p}builds` WHERE testproject_id = 0 ");
$zeros = intval($chk[0]['c']);
echo ($zeros == 0) ? "verified: no builds with testproject_id=0\n" : "WARNING: $zeros builds with testproject_id=0\n";
echo ($changed ? "migration applied\n" : "already up to date\n");
