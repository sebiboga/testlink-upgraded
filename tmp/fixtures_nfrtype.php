<?php
// Fixture for #1470 browser testing: per-type NFR screen.
// Creates project NFRProj (requirements enabled), a low-rights viewer user,
// and NFR requirement rows of a few types in nfr_requirements (lazy table).
// Run from repo root: php tmp/fixtures_nfrtype.php
// Re-runnable: skips anything already present.
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$userId = 1; // admin

$tpid = 0;
$rs = $tprojMgr->get_by_name('NFRProj');
if ($rs) {
    $tpid = intval($rs[0]['id']);
    echo "project exists id=$tpid\n";
} else {
    $item = new stdClass();
    $item->name = 'NFRProj';
    $item->prefix = 'NFRP';
    $item->notes = 'fixture for #1470';
    $item->color = '';
    $item->active = 1;
    $item->is_public = 1;
    $tpid = intval($tprojMgr->create($item));
    echo "project=$tpid\n";
}
$tprojMgr->setActive($tpid);
$optObj = new stdClass();
$optObj->requirementsEnabled = 1;
$optObj->testPriorityEnabled = 1;
$optObj->automationEnabled = 0;
$optObj->inventoryEnabled = 0;
$optObj->freezeLinkOnNewReqVersion = 0;
$db->exec_query("UPDATE testprojects SET options='" .
    $db->prepare_string(serialize($optObj)) . "' WHERE id=$tpid");

// ensure the nfr_requirements table exists (mirror the BFF lazy schema)
$tl = tlObjectWithDB::getDBTables(array('nfr_requirements'));
$t = $tl['nfr_requirements'];
$db->exec_query(
    "CREATE TABLE IF NOT EXISTS {$t} (" .
    " id INT UNSIGNED NOT NULL AUTO_INCREMENT," .
    " testproject_id INT UNSIGNED NOT NULL DEFAULT 0," .
    " nfr_type VARCHAR(32) NOT NULL DEFAULT 'performance'," .
    " title VARCHAR(255) NOT NULL DEFAULT ''," .
    " description TEXT NULL," .
    " target_value VARCHAR(255) NOT NULL DEFAULT ''," .
    " threshold_value VARCHAR(255) NOT NULL DEFAULT ''," .
    " source_ref VARCHAR(255) NOT NULL DEFAULT ''," .
    " req_status VARCHAR(24) NOT NULL DEFAULT 'proposed'," .
    " author_id INT UNSIGNED NULL," .
    " creation_ts TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP," .
    " updated_ts TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP" .
    " ON UPDATE CURRENT_TIMESTAMP," .
    " PRIMARY KEY (id)," .
    " KEY idx_nfr_tproject (testproject_id)," .
    " KEY idx_nfr_type (nfr_type, testproject_id)" .
    " ) ENGINE=InnoDB DEFAULT CHARSET=utf8");

function insNfr($db, $t, $tpid, $type, $title, $desc, $target, $threshold, $source, $status) {
    global $userId;
    $rows = $db->get_recordset(
        "SELECT id FROM {$t} WHERE testproject_id=$tpid AND nfr_type='"
        . $db->prepare_string($type) . "' AND title='" . $db->prepare_string($title) . "'");
    if (!empty($rows)) {
        echo "nfr exists: $type / $title\n";
        return;
    }
    $db->exec_query(
        "INSERT INTO {$t} (testproject_id, nfr_type, title, description, target_value," .
        " threshold_value, source_ref, req_status, author_id) VALUES (" .
        intval($tpid) . ", '" . $db->prepare_string($type) . "', '" .
        $db->prepare_string($title) . "', '" . $db->prepare_string($desc) . "', '" .
        $db->prepare_string($target) . "', '" . $db->prepare_string($threshold) . "', '" .
        $db->prepare_string($source) . "', '" . $db->prepare_string($status) . "', " .
        intval($userId) . ")");
    echo "nfr added: $type / $title\n";
}

insNfr($db, $t, $tpid, 'performance', 'Page load < 2s p95',
    'Home and search pages must render within 2 seconds at p95.',
    '2 s p95', '3 s p95', 'Perf. Sprint 2026-Q3', 'approved');
insNfr($db, $t, $tpid, 'performance', 'API latency < 300 ms p99',
    'REST BFF endpoints answer under 300 ms at p99 under peak load.',
    '300 ms p99', '500 ms p99', 'NFR-PERF-002', 'proposed');
insNfr($db, $t, $tpid, 'security', 'OWASP ASVS L2 for auth',
    'Authentication and session handling must meet OWASP ASVS Level 2.',
    'ASVS L2 pass', 'ASVS L1 pass', 'NFR-SEC-001', 'approved');
insNfr($db, $t, $tpid, 'security', 'Audit trail for NFR writes',
    'Every NFR create/update/delete must be logged as an audit event.',
    '100% events', '95% events', 'NFR-SEC-004', 'in_scope');
insNfr($db, $t, $tpid, 'usability', 'Onboarding < 10 min',
    'New testers should create their first execution in under 10 minutes.',
    '10 min', '20 min', 'UX review', 'proposed');
insNfr($db, $t, $tpid, 'reliability', 'Availability 99.9%',
    'The application must be available 99.9% of the time over a quarter.',
    '99.9%', '99.5%', 'SLA-2026', 'approved');

// low-rights viewer user (reqs_view but no modify) for 403 testing
$db->exec_query("DELETE FROM users WHERE login='nfrviewer'");
$db->exec_query(
        "INSERT INTO users (login, password, cookie_string, first, last, email," .
        " role_id, locale, active, auth_method) VALUES (" .
        "'nfrviewer', '" . $db->prepare_string(password_hash('viewerpw', PASSWORD_BCRYPT)) .
        "', '', 'NFR', 'Viewer'," .
        " 'nfrviewer@example.test', 2, 'en_GB', 1, '')");
$uid = intval($db->insert_id('users'));
echo "viewer user=$uid\n";

echo "DONE: tproject=$tpid table={$t}\n";