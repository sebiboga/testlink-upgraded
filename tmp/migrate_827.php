<?php
// Data migration for issue #503 sub-task #827:
//   Merge duplicate (testproject_id, name) builds that result from scoping
//   builds to the test project.
//
//   Without a merge, the new UNIQUE KEY (testproject_id, name) (added in #834)
//   would be violated by builds that share a name across test plans.
//
// USAGE (from repo root):
//   php tmp/migrate_827.php            # DRY RUN: report only, writes nothing
//   php tmp/migrate_827.php --apply    # execute the merge (IRREVERSIBLE)
//
// Merge rule:
//   - survivor = earliest creation_ts (lowest id as tiebreak)
//   - re-point executions.build_id, execution_tcsteps_wip.build_id,
//     user_assignments.build_id and cfield_build_design_values.node_id
//   - per-artifact metadata (commit_id, tag, branch, release_date,
//     closed_on_date, release_candidate): keep survivor's values, log discarded
//   - delete the merged (non-survivor) build rows
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);
$p = DB_TABLE_PREFIX;

$apply = in_array('--apply', $argv);

// find all (testproject_id, name) groups having more than one build
$groups = $db->get_recordset(
    " SELECT B.testproject_id, B.name, COUNT(*) AS n " .
    " FROM `{$p}builds` B GROUP BY B.testproject_id, B.name HAVING n > 1 " .
    " ORDER BY B.testproject_id, B.name ");

if (!is_array($groups) || count($groups) == 0) {
    echo "No duplicate builds found — nothing to do.\n";
    exit(0);
}

echo ($apply ? "APPLY mode (MERGING)\n" : "DRY RUN (no writes)\n");
echo "Found " . count($groups) . " name group(s) with duplicates.\n\n";

$metaCols = array('commit_id','tag','branch','release_date','closed_on_date','release_candidate');
$refQueries = array(
    " UPDATE `{$p}executions` SET build_id=%d WHERE build_id=%d ",
    " UPDATE `{$p}execution_tcsteps_wip` SET build_id=%d WHERE build_id=%d ",
    " UPDATE `{$p}user_assignments` SET build_id=%d WHERE build_id=%d ",
);

foreach ($groups as $grp) {
    $tp = intval($grp['testproject_id']);
    $name = $grp['name'];

    $builds = $db->get_recordset(
        " SELECT B.id, B.creation_ts, B.commit_id, B.tag, B.branch, " .
        " B.release_date, B.closed_on_date, B.release_candidate " .
        " FROM `{$p}builds` B " .
        " WHERE B.testproject_id = {$tp} AND B.name = '" . $db->prepare_string($name) . "'" .
        " ORDER BY B.creation_ts ASC, B.id ASC ");

    $survivor = intval($builds[0]['id']);
    $victims = array();
    for ($i = 1; $i < count($builds); $i++) {
        $victims[] = intval($builds[$i]['id']);
    }

    echo "GROUP: testproject_id={$tp} name=\"{$name}\" " .
         "(survivor id={$survivor}, merge ids=" . implode(',', $victims) . ")\n";

    // log metadata conflicts: survivor keeps its values, victims' discarded
    foreach ($victims as $vid) {
        foreach ($metaCols as $col) {
            $sv = $builds[0][$col];
            $vv = null;
            foreach ($builds as $b) { if (intval($b['id']) == $vid) { $vv = $b[$col]; } }
            if (is_null($vv) || $vv === '') { continue; }
            if (strcmp((string)$sv, (string)$vv) !== 0) {
                echo "   conflict $col: survivor=\"$sv\" discarded=\"$vv\" (keeping survivor)\n";
            }
        }
    }

    if ($apply) {
        // NOTE: database->exec_query() dies (and closes the connection) on a DB
        // error, which auto-rolls back the open transaction — so a failed group
        // merge never leaves partial writes on disk.
        $db->exec_query(" BEGIN ");
        foreach ($victims as $vid) {
            // cfield_build_design_values PK is (field_id,node_id): if the
            // survivor already holds a value for the same field a victim also
            // has, re-pointing would violate the PK. Survivor (earliest) wins,
            // so drop the victim's colliding rows first, then re-point the rest.
            $db->exec_query(
                " DELETE FROM `{$p}cfield_build_design_values` " .
                " WHERE node_id = {$vid} AND field_id IN " .
                " ( SELECT field_id FROM `{$p}cfield_build_design_values` " .
                "   WHERE node_id = {$survivor} ) ");
            $db->exec_query(
                " UPDATE `{$p}cfield_build_design_values` SET node_id = {$survivor} " .
                " WHERE node_id = {$vid} ");

            foreach ($refQueries as $q) {
                $db->exec_query(sprintf($q, $survivor, $vid));
            }
            $db->exec_query(" DELETE FROM `{$p}builds` WHERE id = {$vid} ");
        }
        $db->exec_query(" COMMIT ");
        echo "   merged {$survivor} <- " . implode(',', $victims) . "\n";
    }
}

// final verification (dry-run: recompute remaining duplicates; apply: should be empty)
$left = $db->get_recordset(
    " SELECT B.testproject_id, B.name, COUNT(*) AS n " .
    " FROM `{$p}builds` B GROUP BY B.testproject_id, B.name HAVING n > 1 ");
$n = is_array($left) ? count($left) : 0;
echo "\nRemaining duplicate name-groups: {$n}\n";
echo ($apply ? ($n == 0 ? "Merge complete ✓\n" : "WARNING: {$n} groups still duplicated\n")
             : "No files written (dry run). Re-run with --apply to merge.\n");
