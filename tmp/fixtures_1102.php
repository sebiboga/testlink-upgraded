<?php
/**
 * Fixture for #1102 (group-by-test-suite + grid toolbar dropped in
 * searchQuickView.html): reuses the #1101 QSDemo fixture (project, suites
 * Alpha / Alpha-A1 / Beta, 5 test cases) and renames the 5 cases so the
 * quick-search free text ("queen") matches a case in EACH of the 3 suites —
 * the modern quick field searches the test-case TITLE only
 * (api/search/index.php action=search, name=<text>), so a common word must
 * appear in the titles to exercise multi-suite grouping:
 *   QS-1  queen login checks        (Suite Alpha)
 *   QS-2  queen logout checks       (Suite Alpha)
 *   QS-3  queen password rules      (Suite Alpha/A1)
 *   QS-4  queen search cases        (Suite Beta)
 *   QS-5  queen requirement cover   (Suite Beta)
 *
 * Usage: php tmp/fixtures_1102.php   (idempotent; creates QSDemo if missing)
 */
require_once(__DIR__ . '/../config.inc.php');
require_once(__DIR__ . '/../lib/functions/common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$hasProject = intval($db->fetchOneValue(
    "SELECT COUNT(*) FROM testprojects p JOIN nodes_hierarchy h ON h.id = p.id" .
    " WHERE h.name = 'QSDemo'")) > 0;
if (!$hasProject) {
    passthru(PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/fixtures_1101.php'), $rc);
    if ($rc !== 0) { die("fixtures_1101.php failed rc=$rc\n"); }
}

$renames = array(
    'Login OK'                    => 'queen login checks',
    'Logout works'                => 'queen logout checks',
    'Password rules'              => 'queen password rules',
    'Search queen cases'          => 'queen search cases',
    'Requirement coverage check'  => 'queen requirement cover',
);
foreach ($renames as $old => $new) {
    $db->exec_query("UPDATE nodes_hierarchy SET name = '" . $new . "'" .
        " WHERE node_type_id = 3 AND name = '" . $old . "'");
    echo "rename '$old' -> '$new' (rows " . $db->affected_rows() . ")\n";
}

// nest A1 under Alpha (the #1101 fixture leaves A1 top-level) so one group
// key is a nested path ("Alpha/A1") exactly like the legacy verification in
// the issue body ("Suite Alpha/Suite A1")
$db->exec_query("UPDATE nodes_hierarchy SET parent_id =" .
    " (SELECT id FROM (SELECT id FROM nodes_hierarchy" .
    "   WHERE node_type_id = 2 AND name = 'Alpha') t)" .
    " WHERE node_type_id = 2 AND name = 'A1'");
echo "nest A1 under Alpha (rows " . $db->affected_rows() . ")\n";

$seen = $db->get_recordset(
    "SELECT NH.name AS tc, S.name AS suite_name, S.id AS suite_id" .
    " FROM nodes_hierarchy NH" .
    " JOIN nodes_hierarchy S ON S.id = NH.parent_id" .
    " WHERE NH.node_type_id = 3 AND NH.name LIKE 'queen%'" .
    " ORDER BY suite_id, tc");
echo "queen cases:\n";
foreach ((array)$seen as $r) {
    echo "  " . $r['tc'] . "  <-  " . $r['suite_name'] . "\n";
}
echo "FIXTURE_1102_OK\n";
